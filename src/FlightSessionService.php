<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

final class FlightSessionService
{
    public const MODEL_OPERATIONAL_V1 = 'operational_session_v1';
    public const FLAG_MODEL_ENABLED = 'operational_session_model_enabled';
    public const FLAG_DEVICE_ALLOWLIST = 'operational_session_model_device_allowlist';

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

    /** @param array<string,mixed> $device */
    public function modelEnabledForDevice(array $device): bool
    {
        $enabled = strtolower(trim((string)$this->policyValue(self::FLAG_MODEL_ENABLED)));
        if (!in_array($enabled, array('1', 'true', 'yes', 'on'), true)) {
            return false;
        }
        $unit = strtoupper(trim((string)($device['display_name'] ?? $device['device_uuid'] ?? '')));
        $tail = strtoupper((string)preg_replace(
            '/[^A-Z0-9]/',
            '',
            trim((string)($device['aircraft_registration'] ?? ''))
        ));
        $allowlist = array_values(array_filter(array_map(
            static fn(string $value): string => strtoupper(trim($value)),
            explode(',', (string)$this->policyValue(self::FLAG_DEVICE_ALLOWLIST))
        )));
        foreach ($allowlist as $allowed) {
            [$allowedUnit, $allowedTail] = array_pad(explode('@', $allowed, 2), 2, '');
            $allowedTail = strtoupper((string)preg_replace('/[^A-Z0-9]/', '', $allowedTail));
            $unitMatches = $unit !== '' && $allowedUnit !== ''
                && ($unit === $allowedUnit || str_starts_with($unit, $allowedUnit . ' '));
            $tailMatches = $allowedTail === '' || ($tail !== '' && $tail === $allowedTail);
            if ($unitMatches && $tailMatches) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create or verify an immutable reservation-linked Operational Session.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createOperationalSession(array $device, array $input): array
    {
        $sessionUuid = $this->normalizeUuid((string)($input['operational_session_uuid'] ?? ''));
        $reservationUuid = $this->normalizeUuid((string)($input['reservation_uuid'] ?? ''));
        $dispatchUuid = $this->normalizeUuid((string)($input['dispatch_uuid'] ?? ''));
        $flightUuid = $this->normalizeUuid((string)($input['workflow_flight_record_uuid'] ?? ''));
        if ($sessionUuid === null || $reservationUuid === null || $dispatchUuid === null || $flightUuid === null) {
            throw new InvalidArgumentException('Operational Session requires valid session, reservation, Dispatch and Flight Record UUIDs.');
        }
        $existing = $this->sessionByUuid($sessionUuid);
        if ($existing !== null) {
            $this->assertDeviceCanUseSession($device, $existing);
            foreach (array(
                'reservation_uuid' => $reservationUuid,
                'dispatch_uuid' => $dispatchUuid,
                'workflow_flight_record_uuid' => $flightUuid,
                'model_version' => self::MODEL_OPERATIONAL_V1,
            ) as $field => $expected) {
                if (strtolower(trim((string)($existing[$field] ?? ''))) !== strtolower($expected)) {
                    throw new RuntimeException('Operational Session immutable identity conflict.');
                }
            }
            return $existing;
        }
        if (!$this->modelEnabledForDevice($device)) {
            throw new RuntimeException('Operational Session model is not enabled for this CVR Unit.');
        }
        $organizationId = max(1, (int)($device['organization_id'] ?? 0));
        $reservation = $this->pdo->prepare(
            'SELECT organization_id FROM ipca_operational_reservations WHERE reservation_uuid = ? LIMIT 1'
        );
        $reservation->execute(array($reservationUuid));
        if ((int)($reservation->fetchColumn() ?: 0) !== $organizationId) {
            throw new RuntimeException('Operational Session reservation linkage is not available.');
        }
        $fuel = trim((string)($input['starting_fuel_quantity'] ?? ''));
        $oil = $input['starting_oil_quantity'] ?? null;
        $statement = $this->pdo->prepare(
            "INSERT INTO ipca_flight_sessions
             (session_uuid, reservation_uuid, dispatch_uuid, workflow_flight_record_uuid,
              organization_id, device_id, aircraft_id, aircraft_registration, source,
              model_version, status, dispatch_confirmed_at_utc, starting_hobbs,
              starting_tacho, starting_fuel_quantity, starting_fuel_unit,
              starting_oil_quantity, starting_oil_unit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cvr_device', ?, 'intended', ?, ?, ?, ?, ?, ?, ?)"
        );
        $statement->execute(array(
            $sessionUuid, $reservationUuid, $dispatchUuid, $flightUuid, $organizationId,
            (int)$device['id'], isset($device['aircraft_id']) ? (int)$device['aircraft_id'] : null,
            (string)($device['aircraft_registration'] ?? ''), self::MODEL_OPERATIONAL_V1,
            $this->timestamp((string)($input['dispatch_confirmed_at_utc'] ?? '')),
            $input['starting_hobbs'] ?? null, $input['starting_tacho'] ?? null,
            $fuel !== '' && is_numeric($fuel) ? (float)$fuel : null,
            substr(trim((string)($input['starting_fuel_unit'] ?? 'USG')), 0, 16),
            $oil !== null && $oil !== '' ? (float)$oil : null,
            $oil !== null && $oil !== '' ? substr(trim((string)($input['starting_oil_unit'] ?? '')), 0, 16) : null,
        ));
        return $this->sessionByUuid($sessionUuid) ?? array();
    }

    public function closeOperationalSession(string $sessionUuid, string $closedAtUtc): void
    {
        $sessionUuid = $this->normalizeUuid($sessionUuid);
        if ($sessionUuid === null) {
            throw new InvalidArgumentException('Operational Session UUID is invalid.');
        }
        $session = $this->sessionByUuid($sessionUuid);
        if (!is_array($session) || (string)($session['model_version'] ?? '') !== self::MODEL_OPERATIONAL_V1) {
            return;
        }
        $this->pdo->prepare(
            "UPDATE ipca_flight_sessions
             SET status = 'completed', avionics_off_utc = COALESCE(avionics_off_utc, ?)
             WHERE session_uuid = ? AND model_version = ?"
        )->execute(array($this->timestamp($closedAtUtc), $sessionUuid, self::MODEL_OPERATIONAL_V1));
    }

    public function cancelOperationalSession(string $sessionUuid): void
    {
        $sessionUuid = $this->normalizeUuid($sessionUuid);
        if ($sessionUuid === null) {
            return;
        }
        $this->pdo->prepare(
            "UPDATE ipca_flight_sessions SET status = 'cancelled'
             WHERE session_uuid = ? AND model_version = ? AND status = 'intended'"
        )->execute(array($sessionUuid, self::MODEL_OPERATIONAL_V1));
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

    private function policyValue(string $key): string
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT value_text FROM system_policy_values
                 WHERE policy_key = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
            );
            $statement->execute(array($key));
            $value = $statement->fetchColumn();
            if ($value !== false && $value !== null) {
                return (string)$value;
            }
            $fallback = $this->pdo->prepare(
                'SELECT default_value_text FROM system_policy_definitions WHERE policy_key = ? LIMIT 1'
            );
            $fallback->execute(array($key));
            return (string)($fallback->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
    }

    private function timestamp(string $value): string
    {
        try {
            return (new DateTimeImmutable($value !== '' ? $value : 'now'))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            return gmdate('Y-m-d H:i:s.000');
        }
    }
}
