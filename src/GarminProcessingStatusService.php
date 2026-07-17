<?php
declare(strict_types=1);

final class GarminProcessingStatusService
{
    /** @var list<string> */
    private array $summaryJobTypes = array('GARMIN_CSV_FLIGHT_SUMMARY', 'GARMIN_TRACK_FLIGHT_SUMMARY');

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $errors = array();
        try {
            $csv = $this->csvSummaryStatus();
        } catch (Throwable $e) {
            $errors[] = 'CSV status: ' . $e->getMessage();
            $csv = array('done' => 0, 'total' => 0, 'remaining' => 0);
        }
        try {
            $linked = $this->linkedCsvTrackStatus();
        } catch (Throwable $e) {
            $errors[] = 'Linked Track status: ' . $e->getMessage();
            $linked = array('done' => 0, 'total' => 0, 'remaining' => 0);
        }
        try {
            $tracks = $this->trackSummaryStatus();
        } catch (Throwable $e) {
            $errors[] = 'Track status: ' . $e->getMessage();
            $tracks = array('done' => 0, 'total' => 0, 'remaining' => 0);
        }
        try {
            $jobs = $this->jobStatus();
        } catch (Throwable $e) {
            $errors[] = 'Job status: ' . $e->getMessage();
            $jobs = $this->emptyJobStatus();
        }
        try {
            $needsReview = $this->needsReviewRecords(10);
        } catch (Throwable $e) {
            $errors[] = 'Review status: ' . $e->getMessage();
            $needsReview = array();
        }
        $remaining = max(0, (int)$csv['remaining'] + (int)$tracks['remaining']);
        $state = 'idle';
        $message = 'Garmin processing is idle.';

        if ($errors !== array()) {
            $state = 'failed';
            $message = 'Could not load complete Garmin processing status: ' . implode(' | ', array_slice($errors, 0, 3));
        } elseif ($remaining === 0) {
            $state = 'complete';
            $message = 'All Garmin processing is complete.';
        } elseif ((int)$jobs['failed'] > 0) {
            $state = 'failed';
            $message = 'Background Garmin processing has failed jobs.';
        } elseif ((int)$jobs['running'] > 0 || (int)$jobs['claimed'] > 0) {
            $state = 'processing';
            $message = 'Garmin processing is running in the background.';
        } elseif ($remaining > 0 && ((int)$jobs['queued'] > 0 || (int)$jobs['retry_wait'] > 0)) {
            $state = 'queued';
            $message = 'Garmin processing is queued and waiting for the worker.';
        } elseif ($remaining > 0 && $needsReview !== array()) {
            $state = 'needs_review';
            $message = 'Some Garmin records need review before they can be completed.';
        } else {
            $state = 'processing_required';
            $message = 'Garmin records are ready to process.';
        }

        $total = (int)$csv['total'] + (int)$tracks['total'];
        $done = (int)$csv['done'] + (int)$tracks['done'];

        return array(
            'state' => $state,
            'message' => $message,
            'csv' => $csv,
            'tracks' => $tracks,
            'linked_csv_tracks' => $linked,
            'jobs' => $jobs,
            'needs_review' => array(
                'total' => count($needsReview),
                'sample' => $needsReview,
            ),
            'errors' => $errors,
            'total' => $total,
            'done' => $done,
            'remaining' => max(0, $total - $done),
            'percent' => $total > 0 ? min(100, (int)round(($done / $total) * 100)) : 100,
            'updated_at' => gmdate('c'),
        );
    }

    /**
     * @return array<string,int>
     */
    private function csvSummaryStatus(): array
    {
        if (!$this->tableExists('ipca_garmin_csv_files')) {
            return array('done' => 0, 'total' => 0, 'remaining' => 0);
        }
        $hasSummary = $this->tableExists('ipca_garmin_csv_flight_summaries');
        $row = $this->row("
            SELECT
              COUNT(f.id) AS total,
              SUM(CASE
                WHEN s.csv_file_id IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                ELSE 1
              END) AS done
            FROM ipca_garmin_csv_files f
            " . ($hasSummary ? "LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = f.id" : "LEFT JOIN (SELECT NULL AS csv_file_id, NULL AS summary_json) s ON 1 = 0") . "
        ");
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done'] ?? 0);
        return array('done' => $done, 'total' => $total, 'remaining' => max(0, $total - $done));
    }

    /**
     * @return array<string,int>
     */
    private function linkedCsvTrackStatus(): array
    {
        if (!$this->tableExists('ipca_garmin_flight_data_track_links') || !$this->tableExists('ipca_garmin_csv_flight_summaries')) {
            return array('done' => 0, 'total' => 0, 'remaining' => 0);
        }
        $row = $this->row("
            SELECT
              COUNT(DISTINCT l.id) AS total,
              SUM(CASE
                WHEN s.csv_file_id IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_in') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_in') IS NULL THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                ELSE 1
              END) AS done
            FROM ipca_garmin_flight_data_track_links l
            LEFT JOIN ipca_garmin_csv_flight_summaries s ON s.csv_file_id = l.garmin_csv_file_id
        ");
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done'] ?? 0);
        return array('done' => $done, 'total' => $total, 'remaining' => max(0, $total - $done));
    }

    /**
     * @return array<string,int>
     */
    private function trackSummaryStatus(): array
    {
        if (!$this->tableExists('ipca_garmin_normalized_track_artifacts')) {
            return array('done' => 0, 'total' => 0, 'remaining' => 0);
        }
        $hasTrackSummary = $this->tableExists('ipca_garmin_track_flight_summaries');
        $hasLinks = $this->tableExists('ipca_garmin_flight_data_track_links');
        $hasCsvSummary = $this->tableExists('ipca_garmin_csv_flight_summaries');
        $csvJoin = ($hasLinks && $hasCsvSummary) ? "
            LEFT JOIN ipca_garmin_flight_data_track_links track_csv_l
              ON track_csv_l.provider_name = t.provider_name
             AND track_csv_l.garmin_entry_uuid = t.garmin_entry_uuid
             AND track_csv_l.canonical_track_uuid = t.track_uuid
            LEFT JOIN ipca_garmin_csv_flight_summaries csv_s
              ON csv_s.csv_file_id = track_csv_l.garmin_csv_file_id
        " : "";
        $csvDone = ($hasLinks && $hasCsvSummary) ? "
            WHEN csv_s.csv_file_id IS NOT NULL
              AND JSON_EXTRACT(csv_s.summary_json, '$.hobbs_exact') IS NOT NULL
              AND JSON_EXTRACT(csv_s.summary_json, '$.tacho_exact') IS NOT NULL
              AND JSON_EXTRACT(csv_s.summary_json, '$.hobbs_in') IS NOT NULL
              AND JSON_EXTRACT(csv_s.summary_json, '$.tacho_in') IS NOT NULL
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) >= 0
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(csv_s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) >= 0 THEN 1
        " : "";
        $row = $this->row("
            SELECT
              COUNT(t.id) AS total,
              SUM(CASE
                {$csvDone}
                WHEN s.track_artifact_id IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL THEN 0
                WHEN JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.hobbs_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.tacho_exact.counter_start_exact')) AS DECIMAL(12,4)) < 0 THEN 0
                WHEN s.tail_number IN ('', 'Unknown tail', 'Unknown')
                  AND JSON_SEARCH(t.source_descriptors_json, 'one', 'Flight Data Log System ID:%') IS NOT NULL THEN 0
                ELSE 1
              END) AS done
            FROM ipca_garmin_normalized_track_artifacts t
            " . ($hasTrackSummary ? "LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id" : "LEFT JOIN (SELECT NULL AS track_artifact_id, NULL AS summary_json, NULL AS tail_number) s ON 1 = 0") . "
            {$csvJoin}
            WHERE t.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
              AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.raw_metadata_json, '$.trackClassification')), '') <> 'GARMIN_GPS_ONLY'
        ");
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done'] ?? 0);
        return array('done' => $done, 'total' => $total, 'remaining' => max(0, $total - $done));
    }

    /**
     * @return array<string,int|string|null>
     */
    private function jobStatus(): array
    {
        if (!$this->tableExists('ipca_async_jobs')) {
            return $this->emptyJobStatus();
        }
        $placeholders = implode(',', array_fill(0, count($this->summaryJobTypes), '?'));
        $rows = $this->rows("
            SELECT status, COUNT(*) AS total, MAX(updated_at) AS last_activity_at
            FROM ipca_async_jobs
            WHERE job_type IN ({$placeholders})
            GROUP BY status
        ", $this->summaryJobTypes);
        $status = $this->emptyJobStatus();
        foreach ($rows as $row) {
            $key = (string)($row['status'] ?? '');
            $total = (int)($row['total'] ?? 0);
            if ($key === 'pending') {
                $status['pending'] = $total;
                $status['queued'] += $total;
            } elseif ($key === 'retry_wait') {
                $status['retry_wait'] = $total;
                $status['queued'] += $total;
            } elseif (array_key_exists($key, $status)) {
                $status[$key] = $total;
            }
            if (!empty($row['last_activity_at']) && ((string)($status['last_activity_at'] ?? '') === '' || (string)$row['last_activity_at'] > (string)$status['last_activity_at'])) {
                $status['last_activity_at'] = (string)$row['last_activity_at'];
            }
        }
        $error = $this->row("
            SELECT last_error
            FROM ipca_async_jobs
            WHERE job_type IN ({$placeholders})
              AND last_error IS NOT NULL
              AND last_error <> ''
            ORDER BY updated_at DESC
            LIMIT 1
        ", $this->summaryJobTypes);
        $status['last_error'] = $error['last_error'] ?? null;
        $status['failed'] = (int)$status['failed'] + (int)($status['dead_letter'] ?? 0);
        return $status;
    }

    /**
     * @return array<string,int|string|null>
     */
    private function emptyJobStatus(): array
    {
        return array('queued' => 0, 'pending' => 0, 'retry_wait' => 0, 'claimed' => 0, 'running' => 0, 'failed' => 0, 'dead_letter' => 0, 'succeeded' => 0, 'last_activity_at' => null, 'last_error' => null);
    }

    /**
     * @return list<array<string,string>>
     */
    private function needsReviewRecords(int $limit): array
    {
        if (!$this->tableExists('ipca_garmin_normalized_track_artifacts')) {
            return array();
        }
        $limit = max(1, min(100, $limit));
        $hasTrackSummary = $this->tableExists('ipca_garmin_track_flight_summaries');
        $rows = $this->rows("
            SELECT t.track_uuid, t.garmin_entry_uuid,
                   COALESCE(s.derivation_status, 'missing_summary') AS status,
                   COALESCE(s.exception_json, '[]') AS exception_json
            FROM ipca_garmin_normalized_track_artifacts t
            " . ($hasTrackSummary ? "LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id" : "LEFT JOIN (SELECT NULL AS track_artifact_id, NULL AS derivation_status, NULL AS exception_json, NULL AS summary_json) s ON 1 = 0") . "
            WHERE t.artifact_type = 'GARMIN_TRACK_NORMALIZED_JSON'
              AND (
                s.track_artifact_id IS NULL
                OR s.derivation_status <> 'ok'
                OR JSON_EXTRACT(s.summary_json, '$.hobbs_exact') IS NULL
                OR JSON_EXTRACT(s.summary_json, '$.tacho_exact') IS NULL
              )
            ORDER BY t.last_seen_at DESC, t.id DESC
            LIMIT {$limit}
        ");
        $out = array();
        foreach ($rows as $row) {
            $exceptions = json_decode((string)($row['exception_json'] ?? '[]'), true);
            $reason = is_array($exceptions) && $exceptions !== array() ? implode('; ', array_slice(array_map('strval', $exceptions), 0, 2)) : (string)($row['status'] ?? 'Needs review');
            $out[] = array(
                'track_uuid' => (string)($row['track_uuid'] ?? ''),
                'entry_uuid' => (string)($row['garmin_entry_uuid'] ?? ''),
                'reason' => $reason,
            );
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(string $sql, array $params = array()): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params = array()): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }
}
