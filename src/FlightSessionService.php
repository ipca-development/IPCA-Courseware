<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightSessionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function sessionForDevice(array $device, ?string $sessionUuid = null): array
    {
        $sessionUuid = $this->normalizeUuid($sessionUuid);
        if ($sessionUuid !== null) {
            $existing = $this->sessionByUuid($sessionUuid);
            if ($existing !== null) {
                $this->assertDeviceCanUseSession($device, $existing);
                return $existing;
            }
        } else {
            $sessionUuid = AuditEventService::uuid();
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_sessions
              (session_uuid, organization_id, device_id, aircraft_id, aircraft_registration, status)
            VALUES
              (:session_uuid, :organization_id, :device_id, :aircraft_id, :aircraft_registration, 'open')
        ");
        $stmt->execute(array(
            ':session_uuid' => $sessionUuid,
            ':organization_id' => (int)($device['organization_id'] ?? 1),
            ':device_id' => (int)$device['id'],
            ':aircraft_id' => isset($device['aircraft_id']) ? (int)$device['aircraft_id'] : null,
            ':aircraft_registration' => (string)($device['aircraft_registration'] ?? ''),
        ));
        return $this->sessionById((int)$this->pdo->lastInsertId()) ?? array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function sessionByUuid(string $sessionUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_flight_sessions WHERE session_uuid = ? LIMIT 1');
        $stmt->execute(array($sessionUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentSessionsForDevice(int $deviceId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT session_uuid, aircraft_registration, status, avionics_on_utc, avionics_off_utc, created_at, updated_at
            FROM ipca_flight_sessions
            WHERE device_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute(array($deviceId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $session
     */
    private function assertDeviceCanUseSession(array $device, array $session): void
    {
        $deviceAircraft = (int)($device['aircraft_id'] ?? 0);
        $sessionAircraft = (int)($session['aircraft_id'] ?? 0);
        if ($deviceAircraft > 0 && $sessionAircraft > 0 && $deviceAircraft !== $sessionAircraft) {
            throw new RuntimeException('Device cannot attach evidence to a different aircraft.');
        }
        $sessionDevice = (int)($session['device_id'] ?? 0);
        if ($sessionDevice > 0 && $sessionDevice !== (int)$device['id']) {
            throw new RuntimeException('Device cannot attach evidence to another device session.');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sessionById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_flight_sessions WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function normalizeUuid(?string $uuid): ?string
    {
        $uuid = strtolower(trim((string)$uuid));
        return preg_match('/^[a-f0-9-]{36}$/', $uuid) === 1 ? $uuid : null;
    }
}
