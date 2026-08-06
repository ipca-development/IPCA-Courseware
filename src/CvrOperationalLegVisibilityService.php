<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

/**
 * Soft-remove Operational Legs from Master Logbook without deleting evidence.
 */
final class CvrOperationalLegVisibilityService
{
    private bool $tableReady = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function hide(int $dispatchId, ?int $actorUserId = null, ?string $reason = null): void
    {
        if ($dispatchId <= 0) {
            throw new InvalidArgumentException('dispatch_id is required.');
        }
        $dispatch = $this->requireDispatch($dispatchId);
        $this->ensureTable();

        $flightUuid = strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_cvr_logbook_hidden_legs
             (dispatch_id, workflow_flight_record_uuid, hidden_by_user_id, reason, hidden_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
               workflow_flight_record_uuid = VALUES(workflow_flight_record_uuid),
               hidden_by_user_id = VALUES(hidden_by_user_id),
               reason = VALUES(reason),
               hidden_at = UTC_TIMESTAMP(3)'
        );
        $stmt->execute(array(
            $dispatchId,
            $flightUuid !== '' ? $flightUuid : null,
            $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            $reason !== null && trim($reason) !== '' ? substr(trim($reason), 0, 512) : null,
        ));

        (new AuditEventService($this->pdo))->record(
            'operational_leg.hide',
            'cvr_dispatch',
            (string)$dispatchId,
            array('visible' => true),
            array('visible' => false, 'workflow_flight_record_uuid' => $flightUuid),
            $reason,
            'admin',
            $actorUserId,
            null,
            null,
            1,
            'master_logbook'
        );
    }

    public function restore(int $dispatchId, ?int $actorUserId = null): void
    {
        if ($dispatchId <= 0) {
            throw new InvalidArgumentException('dispatch_id is required.');
        }
        $dispatch = $this->requireDispatch($dispatchId);
        $this->ensureTable();

        $flightUuid = strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')));
        $this->pdo->prepare('DELETE FROM ipca_cvr_logbook_hidden_legs WHERE dispatch_id = ?')
            ->execute(array($dispatchId));

        (new AuditEventService($this->pdo))->record(
            'operational_leg.restore',
            'cvr_dispatch',
            (string)$dispatchId,
            array('visible' => false),
            array('visible' => true, 'workflow_flight_record_uuid' => $flightUuid),
            null,
            'admin',
            $actorUserId,
            null,
            null,
            1,
            'master_logbook'
        );
    }

    public function isHidden(int $dispatchId): bool
    {
        if ($dispatchId <= 0 || !$this->tableExists()) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ipca_cvr_logbook_hidden_legs WHERE dispatch_id = ? LIMIT 1'
        );
        $stmt->execute(array($dispatchId));
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<int>
     */
    public function hiddenDispatchIds(): array
    {
        if (!$this->tableExists()) {
            return array();
        }
        $rows = $this->pdo->query(
            'SELECT dispatch_id FROM ipca_cvr_logbook_hidden_legs ORDER BY dispatch_id ASC'
        );
        if ($rows === false) {
            return array();
        }
        $ids = array();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['dispatch_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function ensureTable(): void
    {
        if ($this->tableReady || $this->tableExists()) {
            $this->tableReady = true;
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ipca_cvr_logbook_hidden_legs (
              dispatch_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
              workflow_flight_record_uuid VARCHAR(64) NULL,
              hidden_by_user_id INT UNSIGNED NULL,
              reason VARCHAR(512) NULL,
              hidden_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
              KEY idx_cvr_logbook_hidden_flight (workflow_flight_record_uuid),
              KEY idx_cvr_logbook_hidden_at (hidden_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->tableReady = true;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireDispatch(int $dispatchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1');
        $stmt->execute(array($dispatchId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Dispatch leg not found.');
        }
        return $row;
    }

    private function tableExists(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_cvr_logbook_hidden_legs'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
