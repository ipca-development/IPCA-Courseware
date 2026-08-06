<?php
declare(strict_types=1);

require_once __DIR__ . '/AircraftFuelUpliftService.php';
require_once __DIR__ . '/AircraftOperationalConfigService.php';
require_once __DIR__ . '/AircraftFuelStateService.php';

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
        $fuelStateService = new AircraftFuelStateService($this->pdo);
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
            $upliftRows = $uplifts->listForAircraft($registration, 50, false);
            $fuelState = $fuelStateService->stateForRegistration(
                $registration,
                $aircraftId > 0 ? $aircraftId : null
            );

            $loggedAt = (string)($latest['logged_at'] ?? '');
            $fuelQty = $this->numericOrNull($fuelState['quantity_usg'] ?? null);
            $fuelSource = (string)($fuelState['source'] ?? 'none');
            if ($fuelSource === 'none') {
                $fuelSource = 'closure';
            }

            $oil = $this->oilPresentation($latest, $cfg);
            $fuelCap = (float)($fuelState['capacity'] ?? ($cfg['fuel_capacity'] ?? 13.0));
            if ($fuelCap <= 0) {
                $fuelCap = 13.0;
            }
            $fuelUnit = strtoupper(trim((string)($fuelState['unit'] ?? ($cfg['fuel_unit'] ?? 'USG')))) ?: 'USG';
            $fuelPct = $fuelQty !== null ? max(0.0, min(100.0, ($fuelQty / $fuelCap) * 100.0)) : 0.0;
            $fuelBurn = null;
            $departureFuel = $this->numericOrNull($latest['dispatch_fuel_onboard'] ?? null);
            $landingFuel = $this->numericOrNull($latest['fuel_remaining'] ?? null);
            if ($departureFuel !== null && $landingFuel !== null) {
                $fuelBurn = round(max(0.0, $departureFuel - $landingFuel), 1);
            }

            $cards[] = array(
                'aircraft_id' => $aircraftId,
                'registration' => $registration,
                'hobbs' => $this->numericOrNull($latest['ending_hobbs'] ?? null),
                'tacho' => $this->numericOrNull($latest['ending_tacho'] ?? null),
                'logged_at' => $loggedAt,
                'oil_percentage' => $oil['pct'],
                'oil_quantity' => $oil['quantity'],
                'oil_label' => $oil['label'],
                'oil_unit' => $oil['unit'],
                'oil_capacity' => (float)($cfg['oil_capacity'] ?? 0),
                'fuel_quantity' => $fuelQty,
                'fuel_pct' => $fuelPct,
                'fuel_unit' => $fuelUnit,
                'fuel_capacity' => $fuelCap,
                'fuel_burn' => $fuelBurn,
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
                    d.oil_percentage AS dispatch_oil_percentage,
                    d.oil_quantity AS dispatch_oil_quantity,
                    d.oil_unit AS dispatch_oil_unit,
                    d.fuel_onboard AS dispatch_fuel_onboard,
                    c.ending_hobbs,
                    c.ending_tacho,
                    c.fuel_remaining,
                    c.oil_percentage,
                    c.oil_quantity,
                    c.oil_unit,
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

    /**
     * @param array<string,mixed>|null $latest
     * @param array<string,mixed> $cfg
     * @return array{pct:?float,quantity:?float,label:string,unit:string}
     */
    private function oilPresentation(?array $latest, array $cfg): array
    {
        $cfgUnit = strtolower(trim((string)($cfg['oil_unit'] ?? '%')));
        $unitLabel = $this->oilUnitLabel($cfgUnit);
        if (!is_array($latest)) {
            return array('pct' => null, 'quantity' => null, 'label' => '—', 'unit' => $unitLabel);
        }

        $pct = null;
        $quantity = null;
        if (is_numeric($latest['oil_percentage'] ?? null)) {
            $pct = max(0.0, min(100.0, (float)$latest['oil_percentage']));
        } elseif (is_numeric($latest['dispatch_oil_percentage'] ?? null)) {
            $pct = max(0.0, min(100.0, (float)$latest['dispatch_oil_percentage']));
        }

        if (is_numeric($latest['oil_quantity'] ?? null)) {
            $quantity = (float)$latest['oil_quantity'];
        } elseif (is_numeric($latest['dispatch_oil_quantity'] ?? null)) {
            $quantity = (float)$latest['dispatch_oil_quantity'];
        }

        $cap = (float)($cfg['oil_capacity'] ?? 0);
        $isPercentConfig = $cfgUnit === '' || str_contains($cfgUnit, '%');
        if ($isPercentConfig) {
            if ($pct === null && $quantity !== null) {
                $pct = max(0.0, min(100.0, $quantity));
            }
            return array(
                'pct' => $pct,
                'quantity' => $pct,
                'label' => $pct !== null ? ((string)(int)round($pct)) . '%' : '—',
                'unit' => '%',
            );
        }

        // Quarts / absolute units: prefer absolute quantity; otherwise convert percent of capacity.
        if ($quantity !== null && $cap > 0 && $quantity > ($cap * 1.5) && $pct !== null) {
            // Quantity looks like a percent copy (e.g. 63 on an 8 qt tank) — discard.
            $quantity = null;
        }
        if ($quantity === null && $pct !== null && $cap > 0) {
            $quantity = round(($pct / 100.0) * $cap, 1);
        }
        if ($quantity !== null && $cap > 0) {
            $pct = max(0.0, min(100.0, ($quantity / $cap) * 100.0));
        }
        $label = '—';
        if ($quantity !== null) {
            $label = rtrim(rtrim(number_format($quantity, 1, '.', ''), '0'), '.') . ' ' . $unitLabel;
        }
        return array(
            'pct' => $pct,
            'quantity' => $quantity,
            'label' => $label,
            'unit' => $unitLabel,
        );
    }

    private function oilUnitLabel(string $unit): string
    {
        $unit = strtolower(trim($unit));
        if ($unit === '' || str_contains($unit, '%')) {
            return '%';
        }
        if (in_array($unit, array('qt', 'qts', 'quart', 'quarts', 'us qt', 'usq'), true)) {
            return 'qt';
        }
        return $unit;
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
