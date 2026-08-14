<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationConfigService.php';

final class CommunicationAuthService
{
    public function __construct(
        private PDO $pdo,
        private CommunicationConfigService $config
    ) {
    }

    /**
     * @param array<string,mixed> $deviceInput
     * @return array<string,mixed>
     */
    public function login(string $email, string $password, array $deviceInput): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            throw new CommunicationException('invalid_credentials', 'Invalid email or password.', 401);
        }

        $user = $this->userByEmail($email);
        if ($user === null || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            CommunicationSupport::log('communication.auth.login_failed', array('email_present' => $email !== ''));
            throw new CommunicationException('invalid_credentials', 'Invalid email or password.', 401);
        }
        if (!CommunicationSupport::userIsEligible($user)) {
            CommunicationSupport::log('communication.auth.login_ineligible', array(
                'user_id' => (int)$user['id'],
                'status' => (string)($user['status'] ?? ''),
            ));
            throw new CommunicationException('account_ineligible', CommunicationSupport::ineligibleReason($user), 403);
        }

        $this->config->requireMessaging();

        $device = $this->upsertDevice((int)$user['id'], $deviceInput);
        $token = $this->issueCredential((int)$device['id']);
        $this->touchDevice((int)$device['id']);

        CommunicationSupport::log('communication.auth.login', array(
            'user_id' => (int)$user['id'],
            'device_id' => (int)$device['id'],
            'device_uuid' => (string)$device['device_uuid'],
        ));

        return array(
            'ok' => true,
            'token' => $token['plain_token'],
            'user' => CommunicationSupport::publicUser($user),
            'device' => $this->publicDevice($device),
            'capabilities' => $this->config->capabilities(),
        );
    }

    /**
     * @return array{user:array<string,mixed>,device:array<string,mixed>,credential:array<string,mixed>}
     */
    public function requireSession(): array
    {
        return $this->authenticateToken($this->bearerToken());
    }

    /**
     * @return array{user:array<string,mixed>,device:array<string,mixed>,credential:array<string,mixed>}
     */
    public function authenticateToken(string $plain): array
    {
        $plain = trim($plain);
        if ($plain === '') {
            throw new CommunicationException('unauthenticated', 'Sign in is required.', 401);
        }
        $hash = CommunicationSupport::hashSecret($plain);
        $stmt = $this->pdo->prepare("
            SELECT
              c.id AS credential_id,
              c.credential_uuid,
              c.device_id,
              c.expires_at_utc AS credential_expires_at_utc,
              c.revoked_at_utc AS credential_revoked_at_utc,
              d.device_uuid,
              d.user_id,
              d.platform,
              d.model,
              d.os_version,
              d.app_version,
              d.revoked_at_utc AS device_revoked_at_utc,
              d.last_sync_cursor
            FROM ipca_communication_device_credentials c
            INNER JOIN ipca_communication_devices d ON d.id = c.device_id
            WHERE c.token_hash = ?
            LIMIT 1
        ");
        $stmt->execute(array($hash));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new CommunicationException('unauthenticated', 'Sign in is required.', 401);
        }
        if (trim((string)($row['credential_revoked_at_utc'] ?? '')) !== ''
            || trim((string)($row['device_revoked_at_utc'] ?? '')) !== '') {
            throw new CommunicationException('credential_revoked', 'This device is signed out.', 401);
        }
        $expires = trim((string)($row['credential_expires_at_utc'] ?? ''));
        if ($expires !== '' && strtotime($expires . ' UTC') !== false && strtotime($expires . ' UTC') < time()) {
            throw new CommunicationException('credential_expired', 'Sign in is required.', 401);
        }

        $user = $this->userById((int)$row['user_id']);
        if ($user === null) {
            throw new CommunicationException('account_ineligible', 'This account cannot use IPCA messaging.', 403);
        }
        if (!CommunicationSupport::userIsEligible($user)) {
            CommunicationSupport::log('communication.auth.blocked_ineligible', array(
                'user_id' => (int)$user['id'],
                'device_id' => (int)$row['device_id'],
                'status' => (string)($user['status'] ?? ''),
            ));
            throw new CommunicationException('account_ineligible', CommunicationSupport::ineligibleReason($user), 403);
        }

        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare('UPDATE ipca_communication_device_credentials SET last_used_at_utc = ? WHERE id = ?')
            ->execute(array($now, (int)$row['credential_id']));
        $this->touchDevice((int)$row['device_id']);

        return array(
            'user' => $user,
            'device' => array(
                'id' => (int)$row['device_id'],
                'device_uuid' => (string)$row['device_uuid'],
                'user_id' => (int)$row['user_id'],
                'platform' => (string)$row['platform'],
                'model' => (string)$row['model'],
                'os_version' => (string)$row['os_version'],
                'app_version' => (string)$row['app_version'],
                'last_sync_cursor' => (int)$row['last_sync_cursor'],
            ),
            'credential' => array(
                'id' => (int)$row['credential_id'],
                'credential_uuid' => (string)$row['credential_uuid'],
            ),
        );
    }

    /**
     * @param array<string,mixed> $session
     */
    public function logout(array $session): void
    {
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare('UPDATE ipca_communication_device_credentials SET revoked_at_utc = ? WHERE id = ? AND revoked_at_utc IS NULL')
            ->execute(array($now, (int)$session['credential']['id']));
        CommunicationSupport::log('communication.auth.logout', array(
            'user_id' => (int)$session['user']['id'],
            'device_id' => (int)$session['device']['id'],
        ));
    }

    /**
     * @param array<string,mixed> $deviceInput
     * @return array<string,mixed>
     */
    public function upsertDevice(int $userId, array $deviceInput): array
    {
        $deviceUuid = CommunicationSupport::requireUuid((string)($deviceInput['device_uuid'] ?? ''), 'device_uuid');
        $platform = strtolower(trim((string)($deviceInput['platform'] ?? '')));
        if (!in_array($platform, array('iphone', 'ipad'), true)) {
            throw new CommunicationException('validation_error', 'platform must be iphone or ipad.', 400);
        }
        $model = substr(trim((string)($deviceInput['model'] ?? '')), 0, 128);
        $osVersion = substr(trim((string)($deviceInput['os_version'] ?? '')), 0, 64);
        $appVersion = substr(trim((string)($deviceInput['app_version'] ?? '')), 0, 32);
        $now = CommunicationSupport::nowUtc();

        $existing = $this->pdo->prepare('SELECT * FROM ipca_communication_devices WHERE device_uuid = ? LIMIT 1');
        $existing->execute(array($deviceUuid));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if ((int)$row['user_id'] !== $userId) {
                throw new CommunicationException('device_conflict', 'This device is registered to another account.', 409);
            }
            $this->pdo->prepare("
                UPDATE ipca_communication_devices
                SET platform = ?, model = ?, os_version = ?, app_version = ?, revoked_at_utc = NULL, updated_at_utc = ?
                WHERE id = ?
            ")->execute(array($platform, $model, $osVersion, $appVersion, $now, (int)$row['id']));
            $existing->execute(array($deviceUuid));
            $updated = $existing->fetch(PDO::FETCH_ASSOC);
            return is_array($updated) ? $updated : $row;
        }

        $this->pdo->prepare("
            INSERT INTO ipca_communication_devices
              (device_uuid, user_id, organization_id, platform, model, os_version, app_version, created_at_utc, updated_at_utc)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)
        ")->execute(array($deviceUuid, $userId, $platform, $model, $osVersion, $appVersion, $now, $now));

        $existing->execute(array($deviceUuid));
        $created = $existing->fetch(PDO::FETCH_ASSOC);
        if (!is_array($created)) {
            throw new CommunicationException('server_error', 'Could not register this device.', 500);
        }
        CommunicationSupport::log('communication.device.enrolled', array(
            'user_id' => $userId,
            'device_id' => (int)$created['id'],
            'platform' => $platform,
        ));
        return $created;
    }

    /**
     * @return array{plain_token:string,credential_uuid:string}
     */
    public function issueCredential(int $deviceId): array
    {
        $plain = CommunicationSupport::issuePlainToken();
        $uuid = CommunicationSupport::uuid();
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare("
            INSERT INTO ipca_communication_device_credentials
              (credential_uuid, device_id, token_hash, label, created_at_utc)
            VALUES (?, ?, ?, 'session', ?)
        ")->execute(array($uuid, $deviceId, CommunicationSupport::hashSecret($plain), $now));
        return array('plain_token' => $plain, 'credential_uuid' => $uuid);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function userByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare($this->userSelect() . ' WHERE email = ? LIMIT 1');
        $stmt->execute(array($email));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function userById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare($this->userSelect() . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function userByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare($this->userSelect() . ' WHERE uuid = ? LIMIT 1');
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function usersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare($this->userSelect() . ' WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function publicDevice(array $device): array
    {
        return array(
            'device_uuid' => (string)$device['device_uuid'],
            'platform' => (string)$device['platform'],
            'model' => (string)($device['model'] ?? ''),
            'app_version' => (string)($device['app_version'] ?? ''),
            'last_seen_at_utc' => $device['last_seen_at_utc'] ?? null,
        );
    }

    private function touchDevice(int $deviceId): void
    {
        $this->pdo->prepare('UPDATE ipca_communication_devices SET last_seen_at_utc = ?, updated_at_utc = ? WHERE id = ?')
            ->execute(array(CommunicationSupport::nowUtc(), CommunicationSupport::nowUtc(), $deviceId));
    }

    private function userSelect(): string
    {
        return 'SELECT id, uuid, email, name, first_name, last_name, role, status, account_valid_until, photo_path, password_hash FROM users';
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
