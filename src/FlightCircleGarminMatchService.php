<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightCircleGarminMatchService
{
    private const AUTHORITATIVE_AIRCRAFT_TAILS = array('N397EA', 'N392EA', 'N482EA', 'N428EA', 'N446CS', 'N153PC', 'N641TH');
    private const AIRCRAFT_TAIL_ALIASES = array(
        'N482EA' => array('N428EA'),
        'N428EA' => array('N482EA'),
    );

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function matchAllBatches(int $limitPerBatch = 0): array
    {
        if (!$this->tableExists('ipca_flightcircle_import_batches')) {
            return array('ok' => false, 'message' => 'FlightCircle import tables are not installed.', 'created' => 0, 'ambiguous' => 0);
        }
        $rows = $this->datasetBatchesForMatching();
        $created = 0;
        $ambiguous = 0;
        $batches = 0;
        $scanned = 0;
        $diagnostics = array();
        foreach ($rows as $row) {
            $result = $this->matchBatch((int)$row['id'], $limitPerBatch);
            $created += (int)($result['created'] ?? 0);
            $ambiguous += (int)($result['ambiguous'] ?? 0);
            $scanned += (int)($result['scanned'] ?? 0);
            foreach ((array)($result['no_match_diagnostics'] ?? array()) as $diagnostic) {
                if (count($diagnostics) < 25 && is_array($diagnostic)) {
                    $diagnostics[] = $diagnostic;
                }
            }
            $batches++;
        }
        return array('ok' => true, 'batches' => $batches, 'scanned' => $scanned, 'created' => $created, 'ambiguous' => $ambiguous, 'no_match_diagnostics' => $diagnostics);
    }

    /**
     * Match selected Garmin historical rows to the active FlightCircle ledger.
     *
     * @param list<int> $backfillFileIds
     * @return array<string,mixed>
     */
    public function matchSelectedGarminBackfillFiles(array $backfillFileIds): array
    {
        $backfillFileIds = array_values(array_unique(array_filter(array_map('intval', $backfillFileIds))));
        if ($backfillFileIds === array()) {
            return array('ok' => true, 'scanned' => 0, 'created' => 0, 'ambiguous' => 0, 'no_match_diagnostics' => array());
        }
        if (!$this->tableExists('ipca_garmin_historical_segments') || !$this->tableExists('ipca_flightcircle_staging_records')) {
            return array('ok' => false, 'message' => 'Matching tables are not installed.', 'scanned' => 0, 'created' => 0, 'ambiguous' => 0);
        }
        $placeholders = implode(',', array_fill(0, count($backfillFileIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_historical_segments
            WHERE backfill_file_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $stmt->execute($backfillFileIds);

        $created = 0;
        $ambiguous = 0;
        $scanned = 0;
        $diagnostics = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $segment) {
            $scanned++;
            $result = $this->matchGarminSegmentToFlightCircle($segment);
            $created += (int)($result['created'] ?? 0);
            $ambiguous += (int)($result['ambiguous'] ?? 0);
            if (count($diagnostics) < 25 && is_array($result['diagnostic'] ?? null)) {
                $diagnostics[] = $result['diagnostic'];
            }
        }
        return array('ok' => true, 'scanned' => $scanned, 'created' => $created, 'ambiguous' => $ambiguous, 'no_match_diagnostics' => $diagnostics);
    }

    /**
     * @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    private function matchGarminSegmentToFlightCircle(array $segment): array
    {
        $this->clearUndecidedGarminOutcomes((int)($segment['id'] ?? 0));
        $summary = $this->summaryFromSegment($segment);
        $garminTail = (string)($segment['aircraft_registration'] ?? '');
        $garminHobbsOut = $this->numericOrNull($summary['hobbs_out'] ?? null);
        if ($garminHobbsOut === null) {
            $this->storeGarminOutcome(null, $segment, 'no_flightcircle_candidate', 0.0, array('missing_garmin_hobbs_out'), array());
            return array('created' => 1, 'ambiguous' => 0, 'diagnostic' => $this->garminDiagnostic($segment, 'Garmin Hobbs-Out missing'));
        }

        $sameTailCandidates = $this->flightCircleCandidatesForGarmin($garminTail, $garminHobbsOut, true);
        if ($sameTailCandidates !== array()) {
            $ambiguous = count($sameTailCandidates) > 1 ? 1 : 0;
            foreach (array_slice($sameTailCandidates, 0, 5) as $candidate) {
                $score = (float)$candidate['score'];
                $status = $ambiguous ? 'ambiguous' : ($score >= 90.0 ? 'high_confidence' : 'probable');
                $this->storeGarminOutcome($candidate, $segment, $status, $score, array('tail_hobbs_out_match'), array());
            }
            return array('created' => min(5, count($sameTailCandidates)), 'ambiguous' => $ambiguous);
        }

        $aliasCandidates = $this->flightCircleCandidatesForGarmin($garminTail, $garminHobbsOut, false);
        if ($aliasCandidates !== array()) {
            foreach (array_slice($aliasCandidates, 0, 5) as $candidate) {
                $this->storeGarminOutcome($candidate, $segment, 'needs_tail_alias', (float)$candidate['score'], array('hobbs_out_match_tail_alias_needed'), array('tail_alias_required'));
            }
            return array('created' => min(5, count($aliasCandidates)), 'ambiguous' => count($aliasCandidates) > 1 ? 1 : 0, 'diagnostic' => $this->garminDiagnostic($segment, 'Hobbs-Out matched FlightCircle but aircraft tail needs alias/review'));
        }

        $this->storeGarminOutcome(null, $segment, 'no_flightcircle_candidate', 0.0, array('no_tail_hobbs_out_match'), array('no_active_flightcircle_candidate'));
        return array('created' => 1, 'ambiguous' => 0, 'diagnostic' => $this->garminDiagnostic($segment, 'No active FlightCircle row found for Garmin tail and Hobbs-Out'));
    }

    private function clearUndecidedGarminOutcomes(int $garminSegmentId): void
    {
        if ($garminSegmentId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare("
            DELETE FROM ipca_flightcircle_garmin_matches
            WHERE garmin_segment_id = ?
              AND decided_at IS NULL
        ");
        $stmt->execute(array($garminSegmentId));
    }

    /**
     * @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    private function summaryFromSegment(array $segment): array
    {
        $evidence = json_decode((string)($segment['evidence_json'] ?? '{}'), true);
        $summary = is_array($evidence['summary'] ?? null) ? $evidence['summary'] : array();
        return is_array($summary) ? $summary : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function flightCircleCandidatesForGarmin(string $garminTail, float $garminHobbsOut, bool $sameTailOnly): array
    {
        $batchIds = array_map(static fn(array $row): int => (int)$row['id'], $this->datasetBatchesForMatching());
        $batchIds = array_values(array_filter($batchIds));
        if ($batchIds === array()) {
            return array();
        }
        $tailCandidates = $this->tailCandidates($garminTail);
        $batchPlaceholders = implode(',', array_fill(0, count($batchIds), '?'));
        $tailSql = '';
        $params = $batchIds;
        if ($sameTailOnly) {
            $tailSql = " AND UPPER(REPLACE(REPLACE(tail_number, '-', ''), ' ', '')) IN (" . implode(',', array_fill(0, count($tailCandidates), '?')) . ')';
            array_push($params, ...$tailCandidates);
        }
        $params[] = $garminHobbsOut;
        $stmt = $this->pdo->prepare("
            SELECT *,
                   ABS(hobbs_out - ?) AS hobbs_delta
            FROM ipca_flightcircle_staging_records
            WHERE batch_id IN ({$batchPlaceholders})
              AND tail_number IS NOT NULL
              AND hobbs_out IS NOT NULL
              {$tailSql}
              AND ABS(hobbs_out - ?) <= 0.30
            ORDER BY ABS(hobbs_out - ?) ASC, id ASC
            LIMIT 10
        ");
        $executeParams = $batchIds;
        if ($sameTailOnly) {
            array_push($executeParams, ...$tailCandidates);
        }
        array_unshift($executeParams, $garminHobbsOut);
        $executeParams[] = $garminHobbsOut;
        $executeParams[] = $garminHobbsOut;
        $stmt->execute($executeParams);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $delta = (float)($row['hobbs_delta'] ?? 99);
            $tailMatches = in_array($this->normalizeTail((string)($row['tail_number'] ?? '')), $tailCandidates, true);
            $row['score'] = ($tailMatches ? 60.0 : 25.0) + ($delta <= 0.15 ? 35.0 : 25.0);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $flightCircleRecord
     * @param array<string,mixed> $segment
     * @param list<string> $reasons
     * @param list<string> $conflicts
     */
    private function storeGarminOutcome(?array $flightCircleRecord, array $segment, string $status, float $score, array $reasons, array $conflicts): void
    {
        $stagingRecordId = $flightCircleRecord !== null ? (int)$flightCircleRecord['id'] : 0;
        $operationId = $flightCircleRecord !== null ? (int)($flightCircleRecord['operation_id'] ?? 0) : 0;
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
            (int)$segment['id'],
            (int)($segment['csv_file_id'] ?? 0) > 0 ? (int)$segment['csv_file_id'] : null,
            $status,
            $score,
            AuditEventService::jsonEncode(array(
                'matching_direction' => 'garmin_to_flightcircle',
                'reasons' => $reasons,
                'garmin_segment' => array(
                    'id' => (int)$segment['id'],
                    'tail' => (string)($segment['aircraft_registration'] ?? ''),
                    'hobbs_out' => $this->summaryFromSegment($segment)['hobbs_out'] ?? null,
                ),
                'flightcircle_record' => $flightCircleRecord !== null ? array(
                    'id' => (int)$flightCircleRecord['id'],
                    'tail' => (string)($flightCircleRecord['tail_number'] ?? ''),
                    'hobbs_out' => $flightCircleRecord['hobbs_out'] ?? null,
                    'hobbs_in' => $flightCircleRecord['hobbs_in'] ?? null,
                    'user_text' => (string)($flightCircleRecord['user_text'] ?? ''),
                    'instructor_text' => (string)($flightCircleRecord['instructor_text'] ?? ''),
                ) : null,
            )),
            AuditEventService::jsonEncode(array('conflicts' => $conflicts)),
        ));
    }

    /**
     * @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    private function garminDiagnostic(array $segment, string $reason): array
    {
        $summary = $this->summaryFromSegment($segment);
        return array(
            'garmin_segment_id' => (int)($segment['id'] ?? 0),
            'csv_file_id' => (int)($segment['csv_file_id'] ?? 0),
            'tail' => (string)($segment['aircraft_registration'] ?? ''),
            'hobbs_out' => $summary['hobbs_out'] ?? null,
            'reason' => $reason,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function matchBatch(int $batchId, int $limit = 500): array
    {
        if (!$this->tableExists('ipca_flightcircle_staging_records') || !$this->tableExists('ipca_garmin_historical_segments')) {
            return array('ok' => false, 'message' => 'Matching tables are not installed.');
        }
        $limitSql = $limit > 0 ? ' LIMIT ' . max(1, min(20000, $limit)) : '';
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_flightcircle_staging_records
            WHERE batch_id = ?
              AND resource_type = 'aircraft'
              AND import_disposition = 'operation_candidate'
            ORDER BY depart_local ASC, id ASC
            {$limitSql}
        ");
        $stmt->bindValue(1, $batchId, PDO::PARAM_INT);
        $stmt->execute();
        $created = 0;
        $ambiguous = 0;
        $scanned = 0;
        $diagnostics = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $record) {
            $scanned++;
            $candidates = $this->candidateSegments($record);
            $ranked = array();
            foreach ($candidates as $segment) {
                $ranked[] = $this->score($record, $segment);
            }
            usort($ranked, static fn(array $a, array $b): int => (int)$b['score'] <=> (int)$a['score']);
            $storedForRecord = false;
            foreach (array_slice($ranked, 0, 5) as $candidate) {
                $reasons = (array)($candidate['reasons'] ?? array());
                $hasRequiredEvidence = in_array('same_aircraft', $reasons, true)
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
                $storedForRecord = true;
            }
            if (count(array_filter($ranked, static fn(array $c): bool => (int)$c['score'] >= 65)) > 1) {
                $ambiguous++;
            }
            if (!$storedForRecord && count($diagnostics) < 25) {
                $diagnostics[] = $this->noMatchDiagnostic($record, $ranked[0] ?? null);
            }
        }
        return array('ok' => true, 'scanned' => $scanned, 'created' => $created, 'ambiguous' => $ambiguous, 'no_match_diagnostics' => $diagnostics);
    }

    /**
     * @param array<string,mixed> $record
     * @return list<array<string,mixed>>
     */
    private function candidateSegments(array $record): array
    {
        $tail = $this->normalizeTail((string)($record['tail_number'] ?? ''));
        if ($tail === '') {
            return array();
        }
        $tailWithoutN = ltrim($tail, 'N');
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_historical_segments
            WHERE UPPER(REPLACE(REPLACE(aircraft_registration, '-', ''), ' ', '')) IN (?, ?)
            ORDER BY start_utc ASC, id ASC
            LIMIT 1000
        ");
        $stmt->execute(array($tail, $tailWithoutN));
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
                'flightcircle_date_time_used_for_matching' => false,
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

    /**
     * @return list<array<string,mixed>>
     */
    private function datasetBatchesForMatching(): array
    {
        if ($this->tableHasColumn('ipca_flightcircle_import_batches', 'active_dataset')) {
            $active = $this->pdo->query("
                SELECT id
                FROM ipca_flightcircle_import_batches
                WHERE active_dataset = 1
                  AND import_status = 'completed'
                ORDER BY completed_at DESC, id DESC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: array();
            if ($active !== array()) {
                return $active;
            }
            $latest = $this->pdo->query("
                SELECT id
                FROM ipca_flightcircle_import_batches
                WHERE import_status = 'completed'
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ")->fetchAll(PDO::FETCH_ASSOC) ?: array();
            return $latest;
        }
        return $this->pdo->query("
            SELECT id
            FROM ipca_flightcircle_import_batches
            WHERE import_status = 'completed'
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed>|null $bestCandidate
     * @return array<string,mixed>
     */
    private function noMatchDiagnostic(array $record, ?array $bestCandidate): array
    {
        $reasons = array();
        if (trim((string)($record['tail_number'] ?? '')) === '') {
            $reasons[] = 'FlightCircle tail missing';
        }
        if ($this->numericOrNull($record['hobbs_out'] ?? null) === null) {
            $reasons[] = 'FlightCircle Hobbs-Out missing';
        }
        if ($bestCandidate === null) {
            $reasons[] = 'No Garmin segment found for this FlightCircle tail';
        } else {
            foreach ((array)($bestCandidate['conflicts'] ?? array()) as $conflict) {
                $reasons[] = str_replace('_', ' ', (string)$conflict);
            }
        }
        return array(
            'flightcircle_record_id' => (int)($record['id'] ?? 0),
            'date' => substr((string)($record['depart_local'] ?? ''), 0, 10),
            'tail' => (string)($record['tail_number'] ?? ''),
            'hobbs_out' => $record['hobbs_out'] ?? null,
            'user_text' => (string)($record['user_text'] ?? ''),
            'instructor_text' => (string)($record['instructor_text'] ?? ''),
            'best_score' => $bestCandidate !== null ? (int)($bestCandidate['score'] ?? 0) : 0,
            'reason' => implode('; ', array_values(array_unique(array_filter($reasons)))) ?: 'No tail/Hobbs-Out Garmin match',
        );
    }

    private function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return max($aStart, $bStart) <= min($aEnd, $bEnd);
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

    /**
     * @return list<string>
     */
    private function tailCandidates(string $tail): array
    {
        $normalized = $this->normalizeTail($tail);
        if ($normalized === '') {
            return array();
        }
        $candidates = array($normalized, ltrim($normalized, 'N'));
        foreach (self::AIRCRAFT_TAIL_ALIASES[$normalized] ?? array() as $alias) {
            $alias = $this->normalizeTail($alias);
            if ($alias !== '') {
                $candidates[] = $alias;
                $candidates[] = ltrim($alias, 'N');
            }
        }
        return array_values(array_unique(array_filter($candidates)));
    }

    private function normalizeTail(string $tail): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]+/i', '', trim($tail)));
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

    private function tableHasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute(array($table, $column));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
