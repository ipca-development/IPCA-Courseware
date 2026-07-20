<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightCircleGarminMatchService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function matchAllBatches(int $limitPerBatch = 1000): array
    {
        if (!$this->tableExists('ipca_flightcircle_import_batches')) {
            return array('ok' => false, 'message' => 'FlightCircle import tables are not installed.', 'created' => 0, 'ambiguous' => 0);
        }
        $rows = $this->pdo->query("
            SELECT id
            FROM ipca_flightcircle_import_batches
            WHERE import_status = 'completed'
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $created = 0;
        $ambiguous = 0;
        $batches = 0;
        foreach ($rows as $row) {
            $result = $this->matchBatch((int)$row['id'], $limitPerBatch);
            $created += (int)($result['created'] ?? 0);
            $ambiguous += (int)($result['ambiguous'] ?? 0);
            $batches++;
        }
        return array('ok' => true, 'batches' => $batches, 'created' => $created, 'ambiguous' => $ambiguous);
    }

    /**
     * @return array<string,mixed>
     */
    public function matchBatch(int $batchId, int $limit = 500): array
    {
        if (!$this->tableExists('ipca_flightcircle_staging_records') || !$this->tableExists('ipca_garmin_historical_segments')) {
            return array('ok' => false, 'message' => 'Matching tables are not installed.');
        }
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_flightcircle_staging_records
            WHERE batch_id = ?
              AND resource_type = 'aircraft'
              AND import_disposition = 'operation_candidate'
            ORDER BY depart_local ASC, id ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $batchId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $created = 0;
        $ambiguous = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $record) {
            $candidates = $this->candidateSegments($record);
            $ranked = array();
            foreach ($candidates as $segment) {
                $ranked[] = $this->score($record, $segment);
            }
            usort($ranked, static fn(array $a, array $b): int => (int)$b['score'] <=> (int)$a['score']);
            foreach (array_slice($ranked, 0, 5) as $candidate) {
                $status = $candidate['score'] >= 85 ? 'high_confidence' : ($candidate['score'] >= 65 ? 'probable' : 'candidate');
                if ($status === 'candidate') {
                    continue;
                }
                $this->storeMatch((int)$record['id'], (int)($record['operation_id'] ?? 0), $candidate, $status);
                $created++;
            }
            if (count(array_filter($ranked, static fn(array $c): bool => (int)$c['score'] >= 65)) > 1) {
                $ambiguous++;
            }
        }
        return array('ok' => true, 'created' => $created, 'ambiguous' => $ambiguous);
    }

    /**
     * @param array<string,mixed> $record
     * @return list<array<string,mixed>>
     */
    private function candidateSegments(array $record): array
    {
        $tail = strtoupper(trim((string)($record['tail_number'] ?? '')));
        if ($tail === '') {
            return array();
        }
        $depart = strtotime((string)($record['depart_local'] ?? '')) ?: 0;
        $return = strtotime((string)($record['return_local'] ?? '')) ?: 0;
        $from = $depart > 0 ? date('Y-m-d H:i:s', $depart - 6 * 3600) : '1970-01-01 00:00:00';
        $to = $return > 0 ? date('Y-m-d H:i:s', $return + 6 * 3600) : '2999-12-31 00:00:00';
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_historical_segments
            WHERE UPPER(aircraft_registration) = ?
              AND (
                start_utc BETWEEN ? AND ?
                OR end_utc BETWEEN ? AND ?
                OR (start_utc <= ? AND end_utc >= ?)
              )
            ORDER BY start_utc ASC, id ASC
            LIMIT 25
        ");
        $stmt->execute(array($tail, $from, $to, $from, $to, $from, $to));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    private function score(array $record, array $segment): array
    {
        $score = 0;
        $reasons = array();
        $conflicts = array();
        if (strtoupper((string)$record['tail_number']) === strtoupper((string)$segment['aircraft_registration'])) {
            $score += 45;
            $reasons[] = 'same_aircraft';
        }
        $depart = strtotime((string)($record['depart_local'] ?? '')) ?: 0;
        $return = strtotime((string)($record['return_local'] ?? '')) ?: 0;
        $checkIn = strtotime((string)($record['check_in_local'] ?? '')) ?: 0;
        $start = strtotime((string)($segment['start_utc'] ?? '')) ?: 0;
        $end = strtotime((string)($segment['end_utc'] ?? '')) ?: 0;
        if ($depart > 0 && $return > 0 && $start > 0 && $end > 0) {
            if ($start >= ($depart - 3600) && $end <= ($return + 3600)) {
                $score += 25;
                $reasons[] = 'garmin_inside_flightcircle_window';
            } elseif ($this->overlaps($depart, $return, $start, $end)) {
                $score += 15;
                $reasons[] = 'time_windows_overlap';
            } else {
                $conflicts[] = 'time_window_mismatch';
            }
        }
        if ($checkIn > 0 && $end > 0 && abs($checkIn - $end) <= 2 * 3600) {
            $score += 10;
            $reasons[] = 'check_in_close_to_garmin_end';
        }
        $segmentEvidence = json_decode((string)($segment['evidence_json'] ?? '{}'), true);
        $summary = is_array($segmentEvidence['summary'] ?? null) ? $segmentEvidence['summary'] : array();
        foreach (array('hobbs', 'tacho') as $meter) {
            $fcOut = (float)($record[$meter . '_out'] ?? 0);
            $fcIn = (float)($record[$meter . '_in'] ?? 0);
            $garminOut = (float)($summary[$meter . '_out'] ?? 0);
            $garminIn = (float)($summary[$meter . '_in'] ?? 0);
            if ($fcOut > 0 && $fcIn > 0 && $garminOut > 0 && $garminIn > 0) {
                if (abs($fcOut - $garminOut) <= 0.2 && abs($fcIn - $garminIn) <= 0.2) {
                    $score += 10;
                    $reasons[] = $meter . '_compatible';
                } else {
                    $conflicts[] = $meter . '_conflict';
                }
            }
        }
        return array(
            'score' => min(100, $score),
            'segment_id' => (int)$segment['id'],
            'csv_file_id' => (int)($segment['csv_file_id'] ?? 0),
            'reasons' => $reasons,
            'conflicts' => $conflicts,
            'segment' => array(
                'classification' => (string)$segment['classification'],
                'start_utc' => (string)$segment['start_utc'],
                'end_utc' => (string)$segment['end_utc'],
            ),
        );
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function storeMatch(int $stagingRecordId, int $operationId, array $candidate, string $status): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flightcircle_garmin_matches
              (match_uuid, staging_record_id, operation_id, garmin_segment_id, csv_file_id, match_status,
               confidence_score, evidence_json, conflict_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              match_status = VALUES(match_status),
              confidence_score = VALUES(confidence_score),
              evidence_json = VALUES(evidence_json),
              conflict_json = VALUES(conflict_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            AuditEventService::uuid(),
            $stagingRecordId,
            $operationId > 0 ? $operationId : null,
            (int)$candidate['segment_id'],
            (int)$candidate['csv_file_id'] > 0 ? (int)$candidate['csv_file_id'] : null,
            $status,
            (float)$candidate['score'],
            AuditEventService::jsonEncode(array('reasons' => $candidate['reasons'], 'segment' => $candidate['segment'])),
            AuditEventService::jsonEncode(array('conflicts' => $candidate['conflicts'])),
        ));
    }

    private function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return max($aStart, $bStart) <= min($aEnd, $bEnd);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
