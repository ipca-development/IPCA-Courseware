<?php
declare(strict_types=1);

require_once __DIR__ . '/AircraftFuelUpliftService.php';
require_once __DIR__ . '/AircraftOperationalConfigService.php';

/**
 * Live aircraft fuel quantity for fleet cards and CVR Unit device sync.
 */
final class AircraftFuelStateService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   quantity_usg:?float,
     *   unit:string,
     *   capacity:float,
     *   source:string,
     *   as_of_utc:?string,
     *   aircraft_registration:string,
     *   uplift_uuid:?string
     * }
     */
    public function stateForRegistration(string $registration, ?int $aircraftId = null): array
    {
        $registration = strtoupper(trim($registration));
        $cfg = $this->configFor($aircraftId, $registration);
        $capacity = (float)($cfg['fuel_capacity'] ?? 13.0);
        if ($capacity <= 0) {
            $capacity = 13.0;
        }
        $unit = strtoupper(trim((string)($cfg['fuel_unit'] ?? 'USG'))) ?: 'USG';
        $empty = array(
            'quantity_usg' => null,
            'unit' => $unit,
            'capacity' => $capacity,
            'source' => 'none',
            'as_of_utc' => null,
            'aircraft_registration' => $registration,
            'uplift_uuid' => null,
        );
        if ($registration === '') {
            return $empty;
        }

        $closure = $this->latestClosureFuel($registration);
        $uplift = (new AircraftFuelUpliftService($this->pdo))->latestForAircraft($registration);

        $closureQty = $this->numericOrNull($closure['fuel_remaining'] ?? null);
        $closureAt = trim((string)($closure['logged_at'] ?? ''));
        $upliftQty = $this->numericOrNull($uplift['fuel_after_usg'] ?? null);
        $upliftAt = trim((string)($uplift['uplifted_at'] ?? ''));

        $useUplift = false;
        if ($upliftQty !== null) {
            if ($closureAt === '' || $closureQty === null) {
                $useUplift = true;
            } elseif ($upliftAt !== '' && strcmp($upliftAt, $closureAt) >= 0) {
                $useUplift = true;
            }
        }

        if ($useUplift) {
            return array(
                'quantity_usg' => $upliftQty,
                'unit' => $unit,
                'capacity' => $capacity,
                'source' => 'uplift',
                'as_of_utc' => $this->toUtcIso($upliftAt),
                'aircraft_registration' => $registration,
                'uplift_uuid' => isset($uplift['uplift_uuid']) ? (string)$uplift['uplift_uuid'] : null,
            );
        }
        if ($closureQty !== null) {
            return array(
                'quantity_usg' => $closureQty,
                'unit' => $unit,
                'capacity' => $capacity,
                'source' => 'closure',
                'as_of_utc' => $this->toUtcIso($closureAt),
                'aircraft_registration' => $registration,
                'uplift_uuid' => null,
            );
        }
        return $empty;
    }

    /**
     * Create an uplift from CVR Dispatch when tanks were filled / refueled.
     *
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $rawDispatchPayload optional dispatch object for fuel_capacity
     * @return array<string,mixed>|null created row or null when skipped
     */
    public function createUpliftFromDispatchIfNeeded(array $normalized, array $rawDispatchPayload = array()): ?array
    {
        $uplifts = new AircraftFuelUpliftService($this->pdo);
        if (!$uplifts->schemaAvailable()) {
            return null;
        }
        $registration = strtoupper(trim((string)($normalized['aircraft_registration'] ?? '')));
        $aircraftId = (int)($normalized['aircraft_id'] ?? 0);
        $fuelOnboard = $this->numericOrNull($normalized['fuel_onboard'] ?? null);
        if ($registration === '' || $aircraftId <= 0 || $fuelOnboard === null) {
            return null;
        }

        $cfg = $this->configFor($aircraftId, $registration);
        $capacity = (float)($cfg['fuel_capacity'] ?? 13.0);
        $payloadCap = $this->numericOrNull($rawDispatchPayload['fuel_capacity'] ?? null);
        if ($payloadCap !== null && $payloadCap > 0) {
            $capacity = $payloadCap;
        }
        if ($capacity <= 0) {
            $capacity = 13.0;
        }

        $refueled = !empty($normalized['refueled_since_previous_flight']);
        $isFull = $fuelOnboard >= ($capacity - 0.05);
        if (!$refueled && !$isFull) {
            return null;
        }

        $latest = $uplifts->latestForAircraft($registration);
        if (is_array($latest)) {
            $latestQty = $this->numericOrNull($latest['fuel_after_usg'] ?? null);
            $latestAt = trim((string)($latest['uplifted_at'] ?? ''));
            $closure = $this->latestClosureFuel($registration);
            $closureAt = trim((string)($closure['logged_at'] ?? ''));
            $noFlightSinceUplift = $closureAt === '' || ($latestAt !== '' && strcmp($latestAt, $closureAt) >= 0);
            if ($latestQty !== null && abs($latestQty - $fuelOnboard) < 0.05 && $noFlightSinceUplift) {
                return null;
            }
        }

        $note = $refueled
            ? ($isFull ? 'CVR Unit: refueled to full' : 'CVR Unit: refueled before flight')
            : 'CVR Unit: fuel set to full';
        return $uplifts->create(
            $aircraftId,
            $registration,
            $fuelOnboard,
            null,
            $note,
            null
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function configFor(?int $aircraftId, string $registration): array
    {
        $configService = new AircraftOperationalConfigService($this->pdo);
        if ($aircraftId !== null && $aircraftId > 0) {
            return $configService->configForAircraft($aircraftId);
        }
        if ($registration === '' || !$this->tableExists('ipca_aircraft_devices')) {
            return $configService->configForAircraft(null);
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM ipca_aircraft_devices WHERE UPPER(TRIM(registration)) = ? LIMIT 1'
        );
        $statement->execute(array($registration));
        $id = (int)$statement->fetchColumn();
        return $configService->configForAircraft($id > 0 ? $id : null);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestClosureFuel(string $registration): ?array
    {
        if (!$this->tableExists('ipca_cvr_dispatches') || !$this->tableExists('ipca_cvr_flight_closures')) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT c.fuel_remaining,
                    COALESCE(
                      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, \'$.evidence.on_block_utc\')), \'\'),
                      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, \'$.on_block_utc\')), \'\'),
                      c.received_at
                    ) AS logged_at
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_flight_closures c ON c.id = (
               SELECT fc.id FROM ipca_cvr_flight_closures fc
               WHERE fc.workflow_flight_record_uuid = d.workflow_flight_record_uuid
               ORDER BY fc.received_at DESC, fc.id DESC
               LIMIT 1
             )
             WHERE UPPER(TRIM(d.aircraft_registration)) = ?
             ORDER BY COALESCE(
               NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, \'$.evidence.on_block_utc\')), \'\'),
               NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.payload_json, \'$.on_block_utc\')), \'\'),
               c.received_at
             ) DESC, c.id DESC
             LIMIT 1'
        );
        $statement->execute(array($registration));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        $normalized = trim(str_ireplace(array('USG', 'QT', 'QTS'), '', (string)$value));
        return $normalized !== '' && is_numeric($normalized) ? (float)$normalized : null;
    }

    private function toUtcIso(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return $value;
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
