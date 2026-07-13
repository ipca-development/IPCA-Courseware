<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/DeviceAuthService.php';

final class CvrDeviceEnrollmentService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{enrollment_uuid:string,enrollment_code:string}
     */
    public function createEnrollment(int $aircraftId, string $aircraftRegistration, ?int $createdBy = null, int $ttlMinutes = 60): array
    {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $uuid = AuditEventService::uuid();
        $expires = gmdate('Y-m-d H:i:s', time() + max(5, $ttlMinutes) * 60);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_cvr_device_enrollments
              (enrollment_uuid, aircraft_id, aircraft_registration, enrollment_code_hash, expires_at, created_by)
            VALUES
              (:enrollment_uuid, :aircraft_id, :aircraft_registration, :enrollment_code_hash, :expires_at, :created_by)
        ");
        $stmt->execute(array(
            ':enrollment_uuid' => $uuid,
            ':aircraft_id' => $aircraftId > 0 ? $aircraftId : null,
            ':aircraft_registration' => strtoupper(substr($aircraftRegistration, 0, 32)),
            ':enrollment_code_hash' => DeviceAuthService::hashSecret($code),
            ':expires_at' => $expires,
            ':created_by' => $createdBy,
        ));
        return array('enrollment_uuid' => $uuid, 'enrollment_code' => $code);
    }

    /**
     * @return array<string,mixed>
     */
    public function exchange(string $code, string $deviceUuid, string $displayName = '', ?string $mdmIdentifier = null): array
    {
        $code = strtoupper(trim($code));
        $deviceUuid = strtolower(trim($deviceUuid));
        if ($code === '' || !preg_match('/^[a-f0-9-]{36}$/i', $deviceUuid)) {
            throw new RuntimeException('Valid enrollment code and device UUID are required.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM ipca_cvr_device_enrollments
                WHERE enrollment_code_hash = ?
                  AND status = 'pending'
                  AND expires_at > CURRENT_TIMESTAMP(3)
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(array(DeviceAuthService::hashSecret($code)));
            $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($enrollment)) {
                throw new RuntimeException('Enrollment code is invalid, expired, or already used.');
            }

            $deviceId = $this->upsertDevice($deviceUuid, $enrollment, $displayName, $mdmIdentifier);
            $this->pdo->prepare("
                UPDATE ipca_cvr_device_enrollments
                SET status = 'consumed',
                    consumed_by_device_id = ?,
                    consumed_at = CURRENT_TIMESTAMP(3),
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($deviceId, (int)$enrollment['id']));

            $credential = (new DeviceAuthService($this->pdo))->issueCredential($deviceId, 'enrollment');
            (new AuditEventService($this->pdo))->record(
                'cvr_device_enrolled',
                'ipca_cvr_devices',
                (string)$deviceId,
                null,
                array('device_uuid' => $deviceUuid, 'aircraft_id' => $enrollment['aircraft_id'] ?? null),
                'One-time CVR device enrollment consumed.',
                'device',
                null,
                $deviceId
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return array(
            'ok' => true,
            'device_id' => $deviceUuid,
            'credential' => $credential['plain_token'],
            'credential_uuid' => $credential['credential_uuid'],
            'aircraft_id' => (int)($enrollment['aircraft_id'] ?? 0),
            'aircraft_registration' => (string)($enrollment['aircraft_registration'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $enrollment
     */
    private function upsertDevice(string $deviceUuid, array $enrollment, string $displayName, ?string $mdmIdentifier): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_cvr_devices WHERE device_uuid = ? LIMIT 1 FOR UPDATE');
        $stmt->execute(array($deviceUuid));
        $existing = (int)($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            $this->pdo->prepare("
                UPDATE ipca_cvr_devices
                SET aircraft_id = :aircraft_id,
                    aircraft_registration = :aircraft_registration,
                    display_name = :display_name,
                    mdm_device_identifier = :mdm_device_identifier,
                    active = 1,
                    revoked_at = NULL,
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id
            ")->execute(array(
                ':aircraft_id' => $enrollment['aircraft_id'] ?? null,
                ':aircraft_registration' => (string)($enrollment['aircraft_registration'] ?? ''),
                ':display_name' => substr($displayName !== '' ? $displayName : $deviceUuid, 0, 128),
                ':mdm_device_identifier' => $mdmIdentifier !== null ? substr($mdmIdentifier, 0, 128) : null,
                ':id' => $existing,
            ));
            return $existing;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_cvr_devices
              (device_uuid, aircraft_id, aircraft_registration, display_name, mdm_device_identifier)
            VALUES
              (:device_uuid, :aircraft_id, :aircraft_registration, :display_name, :mdm_device_identifier)
        ");
        $stmt->execute(array(
            ':device_uuid' => $deviceUuid,
            ':aircraft_id' => $enrollment['aircraft_id'] ?? null,
            ':aircraft_registration' => (string)($enrollment['aircraft_registration'] ?? ''),
            ':display_name' => substr($displayName !== '' ? $displayName : $deviceUuid, 0, 128),
            ':mdm_device_identifier' => $mdmIdentifier !== null ? substr($mdmIdentifier, 0, 128) : null,
        ));
        return (int)$this->pdo->lastInsertId();
    }
}
