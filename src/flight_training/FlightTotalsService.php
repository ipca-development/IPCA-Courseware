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
        'instructor_time',
        'single_engine_time',
        'multi_engine_time',
        'copilot_time',
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
            if ((string)($entry['review_status'] ?? '') === 'deleted') {
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
            'simulator_atd' => null,
        );
    }

    private function decimal(mixed $value): float
    {
        return round((float)$value, 2);
    }
}
