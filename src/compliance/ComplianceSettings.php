<?php
declare(strict_types=1);

final class ComplianceSettings
{
    public const KEY_COMPLIANCE_MANAGER = 'compliance_monitoring_manager';

    public static function tablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT setting_key FROM ipca_compliance_settings LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function getJson(PDO $pdo, string $key, array $default = array()): array
    {
        if (!self::tablePresent($pdo)) {
            return $default;
        }
        $st = $pdo->prepare('SELECT setting_value_json FROM ipca_compliance_settings WHERE setting_key = ? LIMIT 1');
        $st->execute(array($key));
        $raw = $st->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function saveJson(PDO $pdo, string $key, array $value, ?int $userId): void
    {
        if (!self::tablePresent($pdo)) {
            throw new RuntimeException('Compliance settings table is not installed. Apply scripts/sql/compliance_os_phase_8_6_email_templates.sql first.');
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode compliance setting.');
        }
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_settings (setting_key, setting_value_json, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value_json = VALUES(setting_value_json), updated_by = VALUES(updated_by)'
        );
        $st->execute(array($key, $json, $userId));
    }

    /**
     * @return array{user_id:int,name:string,title:string,signature:string,email:string}
     */
    public static function complianceManager(PDO $pdo): array
    {
        $setting = self::getJson($pdo, self::KEY_COMPLIANCE_MANAGER, array());
        $userId = isset($setting['user_id']) ? (int)$setting['user_id'] : 0;
        $name = trim((string)($setting['name'] ?? ''));
        $title = trim((string)($setting['title'] ?? 'Compliance Monitoring Manager'));
        $signature = trim((string)($setting['signature'] ?? "Compliance Monitoring Manager\nEuroPilot Center B/ATO-17"));
        $email = '';

        if ($userId > 0) {
            try {
                $st = $pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
                $st->execute(array($userId));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    if ($name === '') {
                        $name = trim((string)($row['name'] ?? ''));
                    }
                    $email = trim((string)($row['email'] ?? ''));
                }
            } catch (Throwable) {
                // Keep configured fallback values.
            }
        }

        if ($name === '') {
            $name = 'Compliance Monitoring Manager';
        }
        if ($title === '') {
            $title = 'Compliance Monitoring Manager';
        }
        if ($signature === '') {
            $signature = $title . "\nEuroPilot Center B/ATO-17";
        }

        return array(
            'user_id' => $userId,
            'name' => $name,
            'title' => $title,
            'signature' => $signature,
            'email' => $email,
        );
    }
}
