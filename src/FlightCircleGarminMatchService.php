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
                $reasons = (array)($candidate['reasons'] ?? array());
                $hasRequiredEvidence = in_array('same_aircraft', $reasons, true)
                    && in_array('same_departure_date', $reasons, true)
                    && (
                        in_array('departure_hobbs_matches', $reasons, true)
                        || in_array('departure_hobbs_near_match', $reasons, true)
                    );
                if (!$hasRequiredEvidence) {
                    continue;
                }
                $status = $candidate['score'] >= 85 ? 'high_confidence' : ($candidate['score'] >= 65 ? 'probable' : 'candidate');
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
        $dateWindow = $this->dateWindowForRecord($record);
        $from = $dateWindow['from'];
        $to = $dateWindow['to'];
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_historical_segments
            WHERE (
                start_utc BETWEEN ? AND ?
                OR end_utc BETWEEN ? AND ?
                OR (start_utc <= ? AND end_utc >= ?)
              )
            ORDER BY start_utc ASC, id ASC
            LIMIT 200
        ");
        $stmt->execute(array($from, $to, $from, $to, $from, $to));
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
        if ($this->tailsMatch((string)$record['tail_number'], (string)$segment['aircraft_registration'])) {
            $score += 35;
            $reasons[] = 'same_aircraft';
        } else {
            $conflicts[] = 'aircraft_mismatch';
        }
        if ($this->datesMatch((string)($record['depart_local'] ?? ''), (string)($segment['start_utc'] ?? ''), (string)($segment['end_utc'] ?? ''))) {
            $score += 25;
            $reasons[] = 'same_departure_date';
        } else {
            $conflicts[] = 'date_mismatch';
        }
        $start = strtotime((string)($segment['start_utc'] ?? '')) ?: 0;
        $end = strtotime((string)($segment['end_utc'] ?? '')) ?: 0;
        $segmentEvidence = json_decode((string)($segment['evidence_json'] ?? '{}'), true);
        $summary = is_array($segmentEvidence['summary'] ?? null) ? $segmentEvidence['summary'] : array();
        $fcHobbsOut = $this->numericOrNull($record['hobbs_out'] ?? null);
        $garminHobbsOut = $this->numericOrNull($summary['hobbs_out'] ?? null);
        if ($fcHobbsOut !== null && $garminHobbsOut !== null) {
            $delta = abs($fcHobbsOut - $garminHobbsOut);
            if ($delta <= 0.15) {
                $score += 40;
                $reasons[] = 'departure_hobbs_matches';
            } elseif ($delta <= 0.3) {
                $score += 25;
                $reasons[] = 'departure_hobbs_near_match';
            } else {
                $conflicts[] = 'departure_hobbs_conflict';
            }
        }
        foreach (array('hobbs', 'tacho') as $meter) {
            $fcOut = $this->numericOrNull($record[$meter . '_out'] ?? null);
            $fcIn = $this->numericOrNull($record[$meter . '_in'] ?? null);
            $garminOut = $this->numericOrNull($summary[$meter . '_out'] ?? null);
            $garminIn = $this->numericOrNull($summary[$meter . '_in'] ?? null);
            if ($fcOut !== null && $fcIn !== null && $garminOut !== null && $garminIn !== null) {
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
                'flightcircle_time_ignored' => true,
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

    /**
     * @param array<string,mixed> $record
     * @return array{from:string,to:string}
     */
    private function dateWindowForRecord(array $record): array
    {
        $date = substr(trim((string)($record['depart_local'] ?? '')), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array('from' => '1970-01-01 00:00:00', 'to' => '2999-12-31 00:00:00');
        }
        $start = strtotime($date . ' 00:00:00') ?: 0;
        return array(
            'from' => date('Y-m-d H:i:s', $start - 12 * 3600),
            'to' => date('Y-m-d H:i:s', $start + 36 * 3600),
        );
    }

    private function tailsMatch(string $left, string $right): bool
    {
        $a = $this->normalizeTail($left);
        $b = $this->normalizeTail($right);
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || ltrim($a, 'N') === ltrim($b, 'N');
    }

    private function normalizeTail(string $tail): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]+/i', '', trim($tail)));
    }

    private function datesMatch(string $flightCircleDepart, string $garminStart, string $garminEnd): bool
    {
        $fcDate = substr(trim($flightCircleDepart), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcDate)) {
            return false;
        }
        foreach (array($garminStart, $garminEnd) as $value) {
            $ts = strtotime($value) ?: 0;
            if ($ts <= 0) {
                continue;
            }
            foreach (array(-1, 0, 1) as $offsetDays) {
                if (date('Y-m-d', $ts + ($offsetDays * 86400)) === $fcDate) {
                    return true;
                }
            }
        }
        return false;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]+/', '', trim((string)$value));
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
