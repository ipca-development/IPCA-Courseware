<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class GarminSyncRunService
{
    public function __construct(private PDO $pdo, private string $providerName = 'flygarmin_web')
    {
    }

    public function start(string $syncType, string $triggerType, ?int $triggeredBy, ?string $cursorBefore): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_sync_runs
              (run_uuid, provider_name, sync_type, trigger_type, triggered_by, status, cursor_before)
            VALUES
              (?, ?, ?, ?, ?, 'running', ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $this->providerName, $syncType, $triggerType, $triggeredBy, $cursorBefore));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,int|string|null> $counts
     */
    public function succeed(int $runId, ?string $cursorAfter, array $counts = array()): void
    {
        $allowedCounts = array(
            'entries_received', 'entries_upserted', 'entries_deleted', 'data_logs_discovered',
            'files_downloaded', 'files_validated', 'files_imported', 'files_unmatched',
            'full_avionics_count', 'gps_only_count', 'partial_avionics_count',
            'unsupported_count', 'invalid_count',
        );
        $sets = array("status = 'succeeded'", 'cursor_after = :cursor_after', 'completed_at = CURRENT_TIMESTAMP(3)', 'updated_at = CURRENT_TIMESTAMP(3)');
        $params = array(':id' => $runId, ':cursor_after' => $cursorAfter);
        foreach ($allowedCounts as $countKey) {
            if (array_key_exists($countKey, $counts)) {
                $sets[] = $countKey . ' = :' . $countKey;
                $params[':' . $countKey] = (int)$counts[$countKey];
            }
        }
        $this->pdo->prepare('UPDATE ipca_garmin_sync_runs SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    public function fail(int $runId, string $code, string $summary): void
    {
        $this->pdo->prepare("
            UPDATE ipca_garmin_sync_runs
            SET status = 'failed',
                error_code = ?,
                error_summary = ?,
                completed_at = CURRENT_TIMESTAMP(3),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(substr($code, 0, 128), $summary, $runId));
    }

    /**
     * @param array<string,mixed>|null $result
     */
    public function item(int $runId, string $type, string $identifier, string $status, ?array $result = null, ?string $errorCode = null, ?string $errorSummary = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_sync_run_items
              (sync_run_id, item_type, item_identifier, status, result_json, error_code, error_summary)
            VALUES
              (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $runId,
            substr($type, 0, 64),
            substr($identifier, 0, 128),
            substr($status, 0, 64),
            $result !== null ? AuditEventService::jsonEncode($result) : null,
            $errorCode !== null ? substr($errorCode, 0, 128) : null,
            $errorSummary,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentRuns(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_garmin_sync_runs
            WHERE provider_name = ?
            ORDER BY started_at DESC, id DESC
            LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute(array($this->providerName));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }
}
