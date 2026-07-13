<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class AsyncJobService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $dependencies
     */
    public function enqueue(
        string $jobType,
        string $entityType,
        string $entityId,
        array $payload = array(),
        ?array $dependencies = null,
        int $priority = 100,
        int $maxAttempts = 3,
        string $queueName = 'cvr'
    ): int {
        $idempotencyKey = hash('sha256', implode('|', array($queueName, $jobType, $entityType, $entityId)));
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_async_jobs
              (job_uuid, queue_name, job_type, entity_type, entity_id, idempotency_key, priority, max_attempts, dependency_json, payload_json)
            VALUES
              (:job_uuid, :queue_name, :job_type, :entity_type, :entity_id, :idempotency_key, :priority, :max_attempts, :dependency_json, :payload_json)
            ON DUPLICATE KEY UPDATE
              payload_json = VALUES(payload_json),
              dependency_json = VALUES(dependency_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            ':job_uuid' => AuditEventService::uuid(),
            ':queue_name' => substr($queueName, 0, 64),
            ':job_type' => substr($jobType, 0, 96),
            ':entity_type' => substr($entityType, 0, 96),
            ':entity_id' => substr($entityId, 0, 128),
            ':idempotency_key' => $idempotencyKey,
            ':priority' => $priority,
            ':max_attempts' => max(1, $maxAttempts),
            ':dependency_json' => $dependencies !== null ? AuditEventService::jsonEncode($dependencies) : null,
            ':payload_json' => AuditEventService::jsonEncode($payload),
        ));
        $id = (int)$this->pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $lookup = $this->pdo->prepare('SELECT id FROM ipca_async_jobs WHERE idempotency_key = ? LIMIT 1');
        $lookup->execute(array($idempotencyKey));
        return (int)($lookup->fetchColumn() ?: 0);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function claim(string $queueName, string $workerId, int $leaseSeconds = 300): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ipca_async_jobs
                WHERE queue_name = :queue_name
                  AND (
                    (status IN ('pending','retry_wait') AND available_at <= CURRENT_TIMESTAMP(3))
                    OR (status IN ('claimed','running') AND lease_expires_at < CURRENT_TIMESTAMP(3))
                  )
                ORDER BY priority ASC, available_at ASC, id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute(array(':queue_name' => $queueName));
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare("
                UPDATE ipca_async_jobs
                SET status = 'claimed',
                    claimed_by = :claimed_by,
                    claimed_at = CURRENT_TIMESTAMP(3),
                    lease_expires_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :lease_seconds SECOND),
                    heartbeat_at = CURRENT_TIMESTAMP(3),
                    attempt_count = attempt_count + 1,
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id
            ")->execute(array(
                ':claimed_by' => substr($workerId, 0, 128),
                ':lease_seconds' => max(30, $leaseSeconds),
                ':id' => (int)$job['id'],
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->jobById((int)$job['id']);
    }

    public function markRunning(int $jobId, string $workerId, int $leaseSeconds = 300): void
    {
        $this->pdo->prepare("
            UPDATE ipca_async_jobs
            SET status = 'running',
                claimed_by = ?,
                heartbeat_at = CURRENT_TIMESTAMP(3),
                lease_expires_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? SECOND),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(substr($workerId, 0, 128), max(30, $leaseSeconds), $jobId));
    }

    public function heartbeat(int $jobId, int $leaseSeconds = 300): void
    {
        $this->pdo->prepare("
            UPDATE ipca_async_jobs
            SET heartbeat_at = CURRENT_TIMESTAMP(3),
                lease_expires_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? SECOND),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(max(30, $leaseSeconds), $jobId));
    }

    /**
     * @param array<string,mixed> $result
     */
    public function succeed(int $jobId, array $result = array()): void
    {
        $this->pdo->prepare("
            UPDATE ipca_async_jobs
            SET status = 'succeeded',
                result_json = ?,
                lease_expires_at = NULL,
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array(AuditEventService::jsonEncode($result), $jobId));
    }

    public function fail(int $jobId, string $error, int $delaySeconds = 60): void
    {
        $job = $this->jobById($jobId);
        if ($job === null) {
            return;
        }
        $attempts = (int)($job['attempt_count'] ?? 0);
        $max = (int)($job['max_attempts'] ?? 3);
        $status = $attempts >= $max ? 'dead_letter' : 'retry_wait';
        $this->pdo->prepare("
            UPDATE ipca_async_jobs
            SET status = :status,
                last_error = :last_error,
                available_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :delay_seconds SECOND),
                lease_expires_at = NULL,
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = :id
        ")->execute(array(
            ':status' => $status,
            ':last_error' => $error,
            ':delay_seconds' => max(1, $delaySeconds),
            ':id' => $jobId,
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function jobById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_async_jobs WHERE id = ? LIMIT 1');
        $stmt->execute(array($jobId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
