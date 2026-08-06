<?php
declare(strict_types=1);

require_once __DIR__ . '/AircraftFuelUpliftService.php';
require_once __DIR__ . '/AircraftOperationalConfigService.php';

/**
 * Latest Hobbs / Tacho / Oil / Fuel snapshot per active aircraft for Master Logbook cards.
 */
final class AircraftFleetStatusService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array<string,mixed>> $aircraftOptions active aircraft rows
     * @param array<string,array<string,mixed>> $unitMap registration => config
     * @return list<array<string,mixed>>
     */
    public function cardsForAircraft(array $aircraftOptions, array $unitMap): array
    {
        $uplifts = new AircraftFuelUpliftService($this->pdo);
        $cards = array();
        foreach ($aircraftOptions as $aircraft) {
            $registration = strtoupper(trim((string)($aircraft['registration'] ?? '')));
            if ($registration === '') {
                continue;
            }
            $aircraftId = (int)($aircraft['id'] ?? 0);
            $cfg = $unitMap[$registration] ?? array(
                'id' => $aircraftId,
                'fuel_unit' => 'USG',
                'fuel_capacity' => 13.0,
                'oil_unit' => '%',
                'oil_capacity' => 100.0,
            );
            $latest = $this->latestClosureState($registration);
            $latestUplift = $uplifts->latestForAircraft($registration);
            $upliftRows = $uplifts->listForAircraft($registration, 25, false);

            $loggedAt = (string)($latest['logged_at'] ?? '');
            $fuelQty = $this->numericOrNull($latest['fuel_remaining'] ?? null);
            $fuelSource = 'closure';
            if (is_array($latestUplift)) {
                $upliftAt = (string)($latestUplift['uplifted_at'] ?? '');
                $upliftFuel = $this->numericOrNull($latestUplift['fuel_after_usg'] ?? null);
                if ($upliftFuel !== null && ($loggedAt === '' || strcmp($upliftAt, $loggedAt) >= 0)) {
                    $fuelQty = $upliftFuel;
                    $fuelSource = 'uplift';
                    if ($loggedAt === '' || strcmp($upliftAt, $loggedAt) > 0) {
                        $loggedAt = $upliftAt;
                    }
                }
            }

            $oilPct = $this->oilPercent($latest, $cfg);
            $fuelCap = (float)($cfg['fuel_capacity'] ?? 13.0);
            if ($fuelCap <= 0) {
                $fuelCap = 13.0;
            }
            $fuelPct = $fuelQty !== null ? max(0.0, min(100.0, ($fuelQty / $fuelCap) * 100.0)) : 0.0;

            $cards[] = array(
                'aircraft_id' => $aircraftId,
                'registration' => $registration,
                'hobbs' => $this->numericOrNull($latest['ending_hobbs'] ?? null),
                'tacho' => $this->numericOrNull($latest['ending_tacho'] ?? null),
                'logged_at' => $loggedAt,
                'oil_percentage' => $oilPct,
                'oil_label' => $oilPct !== null ? ((string)(int)round($oilPct)) . '%' : '—',
                'fuel_quantity' => $fuelQty,
                'fuel_pct' => $fuelPct,
                'fuel_unit' => (string)($cfg['fuel_unit'] ?? 'USG'),
                'fuel_capacity' => $fuelCap,
                'fuel_source' => $fuelSource,
                'has_meters' => $latest !== null,
                'uplift_count' => count($upliftRows),
                'uplifts' => $upliftRows,
                'latest_uplift' => $latestUplift,
            );
        }
        return $cards;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestClosureState(string $registration): ?array
    {
        if (!$this->tableExists('ipca_cvr_dispatches') || !$this->tableExists('ipca_cvr_flight_closures')) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT d.aircraft_registration,
                    c.ending_hobbs,
                    c.ending_tacho,
                    c.fuel_remaining,
                    c.oil_percentage,
                    c.oil_quantity,
                    c.oil_unit,
                    COALESCE(c.on_block_utc, c.received_at, c.created_at) AS logged_at
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_flight_closures c ON c.id = (
               SELECT fc.id FROM ipca_cvr_flight_closures fc
               WHERE fc.workflow_flight_record_uuid = d.workflow_flight_record_uuid
               ORDER BY fc.received_at DESC, fc.id DESC
               LIMIT 1
             )
             WHERE UPPER(TRIM(d.aircraft_registration)) = ?
             ORDER BY COALESCE(c.on_block_utc, c.received_at, c.created_at) DESC, c.id DESC
             LIMIT 1'
        );
        $statement->execute(array($registration));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed>|null $latest
     * @param array<string,mixed> $cfg
     */
    private function oilPercent(?array $latest, array $cfg): ?float
    {
        if (!is_array($latest)) {
            return null;
        }
        if (is_numeric($latest['oil_percentage'] ?? null)) {
            return max(0.0, min(100.0, (float)$latest['oil_percentage']));
        }
        $unit = strtoupper(trim((string)($cfg['oil_unit'] ?? $latest['oil_unit'] ?? '%')));
        if (is_numeric($latest['oil_quantity'] ?? null) && str_contains($unit, '%')) {
            return max(0.0, min(100.0, (float)$latest['oil_quantity']));
        }
        if (is_numeric($latest['oil_quantity'] ?? null)) {
            $cap = (float)($cfg['oil_capacity'] ?? 0);
            if ($cap > 0) {
                return max(0.0, min(100.0, ((float)$latest['oil_quantity'] / $cap) * 100.0));
            }
        }
        return null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        $normalized = trim(str_ireplace('USG', '', (string)$value));
        return $normalized !== '' && is_numeric($normalized) ? (float)$normalized : null;
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
