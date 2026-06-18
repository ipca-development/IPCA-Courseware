<?php
declare(strict_types=1);

final class Iacra8710Mapper
{
    /**
     * @param array<string,mixed> $totals
     * @return array<string,mixed>
     */
    public function map(array $totals): array
    {
        return array(
            'total_time' => $totals['total_flight_time'] ?? 0,
            'pic_time' => $totals['pic_time'] ?? 0,
            'solo_time' => $totals['solo_time'] ?? 0,
            'cross_country_time' => $totals['cross_country_time'] ?? 0,
            'night_time' => $totals['night_time'] ?? 0,
            'instrument_time' => $totals['instrument_time'] ?? 0,
            'basic_instrument_flying' => $totals['basic_instrument_flying_time'] ?? 0,
            'dual_received' => $totals['dual_received_time'] ?? 0,
            'single_engine' => $totals['single_engine_time'] ?? 0,
            'multi_engine' => $totals['multi_engine_time'] ?? 0,
            'day_landings' => $totals['day_landings'] ?? 0,
            'night_landings' => $totals['night_landings'] ?? 0,
            'simulator_atd' => $totals['fnpt_simulator_time'] ?? 0,
        );
    }
}
