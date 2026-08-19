<?php
declare(strict_types=1);

final class GarminSyncAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $stableCode,
        private int $status = 401
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->stableCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}

final class GarminSyncAuthService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{enrollment_code:string,enrollment_uuid:string,expires_at:string}
     */
    public function createEnrollmentCode(int $organizationId, int $createdBy, int $ttlMinutes = 60): array
    {
        if ($organizationId <= 0 || $createdBy <= 0) {
            throw new InvalidArgumentException('Organization and creating administrator are required.');
        }
        if ($ttlMinutes < 1 || $ttlMinutes > 1440) {
            throw new InvalidArgumentException('Enrollment lifetime must be between 1 and 1440 minutes.');
        }

        $plainCode = self::randomSecret(24);
        $uuid = self::uuid();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_garmin_sync_device_enrollments
               (enrollment_uuid, organization_id, code_hash, status, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $uuid,
            $organizationId,
            self::hashSecret($plainCode),
            'pending',
            $expiresAt,
            $createdBy,
        ));

        return array(
            'enrollment_code' => $plainCode,
            'enrollment_uuid' => $uuid,
            'expires_at' => $expiresAt,
        );
    }

    /**
     * Exchanges a code exactly once and returns the only plain-text copy of the credential.
     *
     * @return array{device:array<string,mixed>,credential:string,credential_uuid:string,credential_expires_at:?string}
     */
    public function exchangeEnrollmentCode(
        string $code,
        string $deviceUuid,
        string $displayName = '',
        ?int $credentialTtlDays = 365
    ): array {
        $code = trim($code);
        $deviceUuid = strtolower(trim($deviceUuid));
        if ($code === '') {
            throw new GarminSyncAuthException('Garmin Sync enrollment code is required.', 'GARMIN_ENROLLMENT_CODE_REQUIRED', 400);
        }
        if (!self::validUuid($deviceUuid)) {
            throw new GarminSyncAuthException('A valid device UUID is required.', 'GARMIN_DEVICE_UUID_INVALID', 400);
        }
        if ($credentialTtlDays !== null && ($credentialTtlDays < 1 || $credentialTtlDays > 3650)) {
            throw new InvalidArgumentException('Credential lifetime must be between 1 and 3650 days.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                'SELECT id, organization_id, status, expires_at, revoked_at
                   FROM ipca_garmin_sync_device_enrollments
                  WHERE code_hash = ?
                  LIMIT 1'
            );
            $lookup->execute(array(self::hashSecret($code)));
            $enrollment = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($enrollment)) {
                throw new GarminSyncAuthException('Garmin Sync enrollment code is invalid.', 'GARMIN_ENROLLMENT_CODE_INVALID', 401);
            }
            if ((string)$enrollment['status'] === 'revoked' || trim((string)($enrollment['revoked_at'] ?? '')) !== '') {
                throw new GarminSyncAuthException('Garmin Sync enrollment code was revoked.', 'GARMIN_ENROLLMENT_CODE_REVOKED', 401);
            }
            if ((string)$enrollment['status'] !== 'pending') {
                throw new GarminSyncAuthException('Garmin Sync enrollment code was already used.', 'GARMIN_ENROLLMENT_CODE_CONSUMED', 409);
            }
            if (strtotime((string)$enrollment['expires_at']) < time()) {
                throw new GarminSyncAuthException('Garmin Sync enrollment code expired.', 'GARMIN_ENROLLMENT_CODE_EXPIRED', 401);
            }

            $consume = $this->pdo->prepare(
                "UPDATE ipca_garmin_sync_device_enrollments
                    SET status = 'consumed', consumed_at = ?
                  WHERE id = ? AND status = 'pending'"
            );
            $consume->execute(array($now, (int)$enrollment['id']));
            if ($consume->rowCount() !== 1) {
                throw new GarminSyncAuthException('Garmin Sync enrollment code was already used.', 'GARMIN_ENROLLMENT_CODE_CONSUMED', 409);
            }

            $insertDevice = $this->pdo->prepare(
                'INSERT INTO ipca_garmin_sync_devices
                   (device_uuid, organization_id, display_name, active)
                 VALUES (?, ?, ?, 1)'
            );
            try {
                $insertDevice->execute(array(
                    $deviceUuid,
                    (int)$enrollment['organization_id'],
                    substr(trim($displayName), 0, 128),
                ));
            } catch (PDOException $error) {
                if ($this->isIntegrityViolation($error)) {
                    throw new GarminSyncAuthException('Garmin Sync device UUID is already enrolled.', 'GARMIN_DEVICE_ALREADY_ENROLLED', 409);
                }
                throw $error;
            }
            $deviceId = (int)$this->pdo->lastInsertId();

            $plainCredential = self::randomSecret(48);
            $credentialUuid = self::uuid();
            $credentialExpiresAt = $credentialTtlDays === null
                ? null
                : gmdate('Y-m-d H:i:s', time() + ($credentialTtlDays * 86400));
            $credential = $this->pdo->prepare(
                'INSERT INTO ipca_garmin_sync_device_credentials
                   (credential_uuid, device_id, token_hash, expires_at)
                 VALUES (?, ?, ?, ?)'
            );
            $credential->execute(array(
                $credentialUuid,
                $deviceId,
                self::hashSecret($plainCredential),
                $credentialExpiresAt,
            ));
            $this->pdo->commit();

            return array(
                'device' => array(
                    'id' => $deviceId,
                    'device_uuid' => $deviceUuid,
                    'organization_id' => (int)$enrollment['organization_id'],
                    'display_name' => substr(trim($displayName), 0, 128),
                    'active' => 1,
                ),
                'credential' => $plainCredential,
                'credential_uuid' => $credentialUuid,
                'credential_expires_at' => $credentialExpiresAt,
            );
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function requireDevice(): array
    {
        return $this->authenticateBearerToken($this->authorizationHeader());
    }

    /** @return array<string,mixed> */
    public function authenticateBearerToken(string $authorization): array
    {
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            throw new GarminSyncAuthException('Garmin Sync bearer credential is required.', 'GARMIN_AUTH_CREDENTIAL_REQUIRED');
        }
        $plainCredential = trim((string)$matches[1]);
        if ($plainCredential === '') {
            throw new GarminSyncAuthException('Garmin Sync bearer credential is required.', 'GARMIN_AUTH_CREDENTIAL_REQUIRED');
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.*, c.id AS credential_id, c.credential_uuid,
                    c.expires_at AS credential_expires_at,
                    c.revoked_at AS credential_revoked_at
               FROM ipca_garmin_sync_device_credentials c
               INNER JOIN ipca_garmin_sync_devices d ON d.id = c.device_id
              WHERE c.token_hash = ?
              LIMIT 1'
        );
        $stmt->execute(array(self::hashSecret($plainCredential)));
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($device)) {
            throw new GarminSyncAuthException('Garmin Sync bearer credential is invalid.', 'GARMIN_AUTH_CREDENTIAL_INVALID');
        }
        if ((int)($device['active'] ?? 0) !== 1 || trim((string)($device['revoked_at'] ?? '')) !== '') {
            throw new GarminSyncAuthException('Garmin Sync device is inactive or revoked.', 'GARMIN_AUTH_DEVICE_REVOKED');
        }
        if (trim((string)($device['credential_revoked_at'] ?? '')) !== '') {
            throw new GarminSyncAuthException('Garmin Sync bearer credential was revoked.', 'GARMIN_AUTH_CREDENTIAL_REVOKED');
        }
        $expiresAt = trim((string)($device['credential_expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            throw new GarminSyncAuthException('Garmin Sync bearer credential expired.', 'GARMIN_AUTH_CREDENTIAL_EXPIRED');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE ipca_garmin_sync_device_credentials SET last_used_at = ? WHERE id = ?')
            ->execute(array($now, (int)$device['credential_id']));
        $this->pdo->prepare('UPDATE ipca_garmin_sync_devices SET last_seen_at = ? WHERE id = ?')
            ->execute(array($now, (int)$device['id']));

        return $device;
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private static function randomSecret(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function validUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1;
    }

    private function authorizationHeader(): string
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    return trim((string)$value);
                }
            }
        }
        return $header;
    }

    private function isIntegrityViolation(PDOException $error): bool
    {
        return (string)$error->getCode() === '23000'
            || (isset($error->errorInfo[0]) && (string)$error->errorInfo[0] === '23000');
    }
}
