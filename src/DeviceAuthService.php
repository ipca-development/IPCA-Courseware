<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class DeviceAuthService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function requireDevice(): array
    {
        $token = $this->bearerToken();
        if ($token === '') {
            throw new RuntimeException('Device token is required.');
        }
        $hash = self::hashSecret($token);
        $stmt = $this->pdo->prepare("
            SELECT d.*, c.id AS credential_id, c.credential_uuid, c.expires_at AS credential_expires_at, c.revoked_at AS credential_revoked_at
            FROM ipca_cvr_device_credentials c
            INNER JOIN ipca_cvr_devices d ON d.id = c.device_id
            WHERE c.token_hash = ?
            LIMIT 1
        ");
        $stmt->execute(array($hash));
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($device)) {
            throw new RuntimeException('Device token is invalid.');
        }
        if ((int)($device['active'] ?? 0) !== 1 || trim((string)($device['revoked_at'] ?? '')) !== '') {
            throw new RuntimeException('Device is revoked or inactive.');
        }
        if (trim((string)($device['credential_revoked_at'] ?? '')) !== '') {
            throw new RuntimeException('Device credential is revoked.');
        }
        $expires = trim((string)($device['credential_expires_at'] ?? ''));
        if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) < time()) {
            throw new RuntimeException('Device credential has expired.');
        }
        $this->pdo->prepare('UPDATE ipca_cvr_device_credentials SET last_used_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array((int)$device['credential_id']));
        $this->pdo->prepare('UPDATE ipca_cvr_devices SET last_seen_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array((int)$device['id']));
        return $device;
    }

    /**
     * @return array{plain_token:string,credential_uuid:string}
     */
    public function issueCredential(int $deviceId, string $label = 'primary', ?int $ttlDays = null): array
    {
        if ($deviceId <= 0) {
            throw new RuntimeException('Device id is required.');
        }
        $plain = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $uuid = AuditEventService::uuid();
        $expires = $ttlDays !== null && $ttlDays > 0
            ? gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400))
            : null;
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_cvr_device_credentials
              (credential_uuid, device_id, token_hash, label, expires_at)
            VALUES
              (:credential_uuid, :device_id, :token_hash, :label, :expires_at)
        ");
        $stmt->execute(array(
            ':credential_uuid' => $uuid,
            ':device_id' => $deviceId,
            ':token_hash' => self::hashSecret($plain),
            ':label' => substr($label, 0, 128),
            ':expires_at' => $expires,
        ));
        return array('plain_token' => $plain, 'credential_uuid' => $uuid);
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function bearerToken(): string
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower((string)$name) === 'authorization') {
                        $header = trim((string)$value);
                        break;
                    }
                }
            }
        }
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return '';
    }
}
