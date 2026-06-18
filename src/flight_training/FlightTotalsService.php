<?php
declare(strict_types=1);

final class FlightTotalsService
{
    private const TIME_FIELDS = array(
        'total_flight_time',
        'pic_time',
        'dual_received_time',
        'solo_time',
        'cross_country_time',
        'night_time',
        'instrument_time',
        'actual_instrument_time',
        'simulated_instrument_time',
        'basic_instrument_flying_time',
        'fnpt_simulator_time',
        'instructor_time',
        'single_engine_time',
        'multi_engine_time',
        'copilot_time',
        'dual_cross_country_time',
        'solo_cross_country_time',
        'pic_cross_country_time',
    );

    private const COUNT_FIELDS = array(
        'day_landings',
        'night_landings',
        'towered_airport_landings',
    );

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    public function calculate(array $entries): array
    {
        $totals = array();
        foreach (self::TIME_FIELDS as $field) {
            $totals[$field] = 0.0;
        }
        foreach (self::COUNT_FIELDS as $field) {
            $totals[$field] = 0;
        }
        $totals['cross_country_distance_nm'] = 0.0;
        $totals['entry_count'] = 0;

        foreach ($entries as $entry) {
            if (!$this->isTrustedEntry($entry)) {
                continue;
            }
            $totals['entry_count']++;
            foreach (self::TIME_FIELDS as $field) {
                $totals[$field] += $this->decimal($entry[$field] ?? 0);
            }
            foreach (self::COUNT_FIELDS as $field) {
                $totals[$field] += (int)($entry[$field] ?? 0);
            }
            $totals['cross_country_distance_nm'] += $this->decimal($entry['cross_country_distance_nm'] ?? 0);
            $crossCountry = $this->decimal($entry['cross_country_time'] ?? 0);
            $totals['dual_cross_country_time'] += min($this->decimal($entry['dual_received_time'] ?? 0), $crossCountry);
            $totals['solo_cross_country_time'] += min($this->decimal($entry['solo_time'] ?? 0), $crossCountry);
            $totals['pic_cross_country_time'] += min($this->decimal($entry['pic_time'] ?? 0), $crossCountry);
        }

        foreach (self::TIME_FIELDS as $field) {
            $totals[$field] = round((float)$totals[$field], 2);
        }
        $totals['cross_country_distance_nm'] = round((float)$totals['cross_country_distance_nm'], 1);

        return $totals;
    }

    /**
     * @param array<string,mixed> $totals
     * @return array<string,mixed>
     */
    public function iacra8710Summary(array $totals): array
    {
        return array(
            'total_time' => $totals['total_flight_time'] ?? 0,
            'pic' => $totals['pic_time'] ?? 0,
            'solo' => $totals['solo_time'] ?? 0,
            'cross_country' => $totals['cross_country_time'] ?? 0,
            'night' => $totals['night_time'] ?? 0,
            'instrument' => $totals['instrument_time'] ?? 0,
            'basic_instrument_flying' => $totals['basic_instrument_flying_time'] ?? 0,
            'dual_received' => $totals['dual_received_time'] ?? 0,
            'single_engine' => $totals['single_engine_time'] ?? 0,
            'multi_engine' => $totals['multi_engine_time'] ?? 0,
            'day_landings' => $totals['day_landings'] ?? 0,
            'night_landings' => $totals['night_landings'] ?? 0,
            'simulator_atd' => $totals['fnpt_simulator_time'] ?? 0,
        );
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function isTrustedEntry(array $entry): bool
    {
        $status = strtolower(trim((string)($entry['review_status'] ?? '')));
        return in_array($status, array('accepted', 'ok', 'merged', 'split'), true);
    }

    private function decimal(mixed $value): float
    {
        return round((float)$value, 2);
    }
}
