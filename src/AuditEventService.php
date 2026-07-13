<?php
declare(strict_types=1);

final class AuditEventService
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $actorType = 'system',
        ?int $actorUserId = null,
        ?int $actorDeviceId = null,
        ?string $requestUuid = null,
        int $organizationId = 1,
        string $source = 'system'
    ): void {
        if (!$this->tableExists()) {
            return;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_audit_events
              (audit_uuid, request_uuid, organization_id, actor_type, actor_user_id, actor_device_id,
               action, entity_type, entity_id, before_json, after_json, reason, source, ip_address, user_agent)
            VALUES
              (:audit_uuid, :request_uuid, :organization_id, :actor_type, :actor_user_id, :actor_device_id,
               :action, :entity_type, :entity_id, :before_json, :after_json, :reason, :source, :ip_address, :user_agent)
        ");
        $stmt->execute(array(
            ':audit_uuid' => self::uuid(),
            ':request_uuid' => $requestUuid,
            ':organization_id' => $organizationId,
            ':actor_type' => $actorType,
            ':actor_user_id' => $actorUserId,
            ':actor_device_id' => $actorDeviceId,
            ':action' => substr($action, 0, 96),
            ':entity_type' => substr($entityType, 0, 96),
            ':entity_id' => substr($entityId, 0, 128),
            ':before_json' => $before !== null ? self::jsonEncode($before) : null,
            ':after_json' => $after !== null ? self::jsonEncode($after) : null,
            ':reason' => $reason !== null ? substr($reason, 0, 512) : null,
            ':source' => substr($source, 0, 64),
            ':ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ));
    }

    private function tableExists(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_audit_events'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
