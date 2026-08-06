<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';

/**
 * Admin-logged fuel after refueling for Master Logbook fleet cards.
 */
final class AircraftFuelUpliftService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function schemaAvailable(): bool
    {
        return $this->tableExists('ipca_aircraft_fuel_uplifts');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForAircraft(string $registration, int $limit = 50, bool $includeDeleted = false): array
    {
        if (!$this->schemaAvailable()) {
            return array();
        }
        $registration = strtoupper(trim($registration));
        if ($registration === '') {
            return array();
        }
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM ipca_aircraft_fuel_uplifts
                WHERE aircraft_registration = ?';
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' ORDER BY uplifted_at DESC, id DESC LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array($registration));
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestForAircraft(string $registration): ?array
    {
        $rows = $this->listForAircraft($registration, 1, false);
        return $rows[0] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    public function create(
        int $aircraftId,
        string $registration,
        float $fuelAfter,
        ?string $upliftedAtLocal,
        string $notes,
        ?int $actorUserId,
        string $localTimezone = 'America/Los_Angeles'
    ): array {
        if (!$this->schemaAvailable()) {
            throw new RuntimeException('Apply scripts/sql/2026_08_06_aircraft_fuel_uplifts.sql before logging fuel uplifts.');
        }
        $registration = strtoupper(trim($registration));
        if ($aircraftId <= 0 || $registration === '') {
            throw new RuntimeException('Aircraft is required.');
        }
        if ($fuelAfter < 0 || $fuelAfter > 500) {
            throw new RuntimeException('Fuel after refueling must be a realistic USG quantity.');
        }
        $notes = trim($notes);
        if (strlen($notes) > 500) {
            throw new RuntimeException('Notes are limited to 500 characters.');
        }
        $at = $this->normalizeLocalToUtc($upliftedAtLocal, $localTimezone);
        $uuid = AuditEventService::uuid();
        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_aircraft_fuel_uplifts
             (uplift_uuid, aircraft_id, aircraft_registration, uplifted_at, fuel_after_usg, fuel_unit, notes, created_by)
             VALUES (?, ?, ?, ?, ?, \'USG\', ?, ?)'
        );
        $insert->execute(array(
            $uuid,
            $aircraftId,
            $registration,
            $at,
            round($fuelAfter, 2),
            $notes,
            $actorUserId,
        ));
        $id = (int)$this->pdo->lastInsertId();
        return $this->byId($id) ?? array('id' => $id, 'uplift_uuid' => $uuid);
    }

    public function softDelete(int $upliftId, ?int $actorUserId): void
    {
        if (!$this->schemaAvailable()) {
            throw new RuntimeException('Fuel uplift schema is unavailable.');
        }
        if ($upliftId <= 0) {
            throw new RuntimeException('Uplift id is required.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE ipca_aircraft_fuel_uplifts
             SET deleted_at = CURRENT_TIMESTAMP(3), deleted_by = ?, updated_at = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND deleted_at IS NULL'
        );
        $statement->execute(array($actorUserId, $upliftId));
        if ($statement->rowCount() <= 0) {
            throw new RuntimeException('Fuel uplift was not found or is already deleted.');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function byId(int $id): ?array
    {
        if ($id <= 0 || !$this->schemaAvailable()) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM ipca_aircraft_fuel_uplifts WHERE id = ? LIMIT 1');
        $statement->execute(array($id));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function normalizeLocalToUtc(?string $value, string $timezone): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return gmdate('Y-m-d H:i:s');
        }
        $text = str_replace('T', ' ', $text);
        try {
            $tzName = $timezone !== '' ? $timezone : 'America/Los_Angeles';
            $dt = new DateTimeImmutable($text, new DateTimeZone($tzName));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new RuntimeException('Uplift date/time is invalid.');
        }
    }

    private function tableExists(string $table): bool
    {
        static $cache = array();
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute(array($table));
        $cache[$table] = (bool)$statement->fetchColumn();
        return $cache[$table];
    }
}
