<?php
declare(strict_types=1);

require_once __DIR__ . '/tv_adsb_status.php';

/**
 * Aircraft/device registry shared by scheduling and Cockpit Recorder.
 */
final class CockpitAircraftService
{
    private const TABLE = 'ipca_aircraft_devices';

    public function __construct(private PDO $pdo)
    {
    }

    public static function tablesPresent(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_aircraft_devices'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    public function requireTables(): void
    {
        if (!self::tablesPresent($this->pdo)) {
            throw new RuntimeException('Apply scripts/sql/2026_06_20_cockpit_recorder_aircraft_devices.sql first.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function publicList(): array
    {
        return array(
            'ok' => true,
            'aircraft' => array_map(
                fn(array $row): array => $this->publicPayload($row),
                $this->activeAircraft()
            ),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeAircraft(): array
    {
        $this->requireTables();
        $stmt = $this->pdo->query("
            SELECT *
            FROM " . self::TABLE . "
            WHERE active = 1
            ORDER BY registration ASC, id ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function adminAircraft(): array
    {
        $this->requireTables();
        $stmt = $this->pdo->query("
            SELECT *
            FROM " . self::TABLE . "
            ORDER BY active DESC, registration ASC, id ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function aircraftById(int $id): ?array
    {
        $this->requireTables();
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveAircraft(array $data): int
    {
        $this->requireTables();
        $id = max(0, (int)($data['id'] ?? 0));
        $registration = tv_adsb_normalize_registration((string)($data['registration'] ?? ''));
        if ($registration === '') {
            throw new RuntimeException('Aircraft registration is required.');
        }

        $displayName = trim((string)($data['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $registration;
        }

        $aircraftType = strtoupper(trim((string)($data['aircraft_type'] ?? '')));
        $aircraftType = preg_replace('/[^A-Z0-9 -]/', '', $aircraftType) ?? '';
        $adsbHex = tv_adsb_normalize_hex((string)($data['adsb_hex'] ?? ''));
        $homeAirport = strtoupper(trim((string)($data['home_airport'] ?? '')));
        $homeAirport = preg_replace('/[^A-Z0-9]/', '', $homeAirport) ?? '';
        $homeAirport = substr($homeAirport, 0, 8);
        $notes = trim((string)($data['notes'] ?? ''));
        $active = !empty($data['active']) ? 1 : 0;

        if ($id > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE " . self::TABLE . "
                SET registration = ?,
                    display_name = ?,
                    aircraft_type = ?,
                    adsb_hex = ?,
                    home_airport = ?,
                    notes = ?,
                    active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute(array($registration, $displayName, $aircraftType, $adsbHex, $homeAirport, $notes, $active, $id));
            return $id;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO " . self::TABLE . " (
                registration,
                display_name,
                aircraft_type,
                adsb_hex,
                home_airport,
                notes,
                active,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute(array($registration, $displayName, $aircraftType, $adsbHex, $homeAirport, $notes, $active));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function publicPayload(array $row): array
    {
        return array(
            'id' => (int)($row['id'] ?? 0),
            'registration' => (string)($row['registration'] ?? ''),
            'display_name' => (string)($row['display_name'] ?? ''),
            'aircraft_type' => (string)($row['aircraft_type'] ?? ''),
            'adsb_hex' => (string)($row['adsb_hex'] ?? ''),
            'home_airport' => (string)($row['home_airport'] ?? ''),
            'active' => (int)($row['active'] ?? 0) === 1,
        );
    }
}
