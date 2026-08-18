<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';

final class SafetyAuditEventService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload */
    public function append(
        int $organizationId,
        string $aggregateType,
        int $aggregateId,
        string $eventType,
        string $actorType,
        ?int $actorUserId,
        ?string $actorReferenceHash,
        array $payload = array()
    ): int {
        $last = $this->pdo->prepare(
            'SELECT event_hash FROM ipca_safety_events
             WHERE organization_id = ? AND aggregate_type = ? AND aggregate_id = ?
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $last->execute(array($organizationId, $aggregateType, $aggregateId));
        $previous = $last->fetchColumn();
        $previous = is_string($previous) ? $previous : null;
        $uuid = SafetySupport::uuid();
        $at = SafetySupport::nowUtc();
        $payloadJson = SafetySupport::json($payload);
        $hash = hash('sha256', implode('|', array(
            (string)$organizationId, $aggregateType, (string)$aggregateId, $eventType,
            $actorType, (string)$actorUserId, (string)$actorReferenceHash,
            $payloadJson, (string)$previous, $uuid, $at,
        )));
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_events
             (organization_id, event_uuid, aggregate_type, aggregate_id, event_type, actor_type,
              actor_user_id, actor_reference_hash, payload_json, previous_event_hash, event_hash, occurred_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $organizationId, $uuid, $aggregateType, $aggregateId, $eventType, $actorType,
            $actorUserId, $actorReferenceHash, $payloadJson, $previous, $hash, $at,
        ));
        return (int)$this->pdo->lastInsertId();
    }
}
