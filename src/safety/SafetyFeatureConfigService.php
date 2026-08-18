<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';

final class SafetyFeatureConfigService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return mixed */
    public function get(int $organizationId, string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare(
            'SELECT config_value FROM ipca_safety_config WHERE organization_id = ? AND config_key = ?'
        );
        $stmt->execute(array($organizationId, $key));
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) {
            return $default;
        }
        $value = json_decode($raw, true);
        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    public function requireEnabled(int $organizationId, string $feature = 'enabled'): void
    {
        if ($this->get($organizationId, $feature, false) !== true) {
            throw new SafetyException('feature_disabled', 'This safety feature is not enabled.', 403);
        }
    }

    /** @param mixed $value */
    public function set(int $organizationId, string $key, mixed $value, int $actorUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_config (organization_id, config_key, config_value, updated_by_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value),
               updated_by_user_id = VALUES(updated_by_user_id), updated_at_utc = CURRENT_TIMESTAMP(3)'
        )->execute(array($organizationId, $key, SafetySupport::json(array('value' => $value)), $actorUserId));
    }
}
