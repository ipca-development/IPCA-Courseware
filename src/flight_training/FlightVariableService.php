<?php
declare(strict_types=1);

require_once __DIR__ . '/Iacra8710Mapper.php';

final class FlightVariableService
{
    public function __construct(private Iacra8710Mapper $iacraMapper = new Iacra8710Mapper())
    {
    }

    /**
     * @param array<string,mixed> $totals
     * @param list<array<string,mixed>> $requirementResults
     * @return array<string,mixed>
     */
    public function buildVariables(array $totals, array $requirementResults = array()): array
    {
        $iacra = $this->iacraMapper->map($totals);
        $vars = array(
            'flight.total_time' => $totals['total_flight_time'] ?? 0,
            'flight.pic_time' => $totals['pic_time'] ?? 0,
            'flight.dual_time' => $totals['dual_received_time'] ?? 0,
            'flight.solo_time' => $totals['solo_time'] ?? 0,
            'flight.cross_country_time' => $totals['cross_country_time'] ?? 0,
            'flight.night_time' => $totals['night_time'] ?? 0,
            'flight.instrument_time' => $totals['instrument_time'] ?? 0,
            'flight.basic_instrument_flying' => $totals['basic_instrument_flying_time'] ?? 0,
            'flight.day_landings' => $totals['day_landings'] ?? 0,
            'flight.night_landings' => $totals['night_landings'] ?? 0,
            'flight.instructor_time' => $totals['instructor_time'] ?? 0,
            'flight.single_engine_time' => $totals['single_engine_time'] ?? 0,
            'flight.multi_engine_time' => $totals['multi_engine_time'] ?? 0,
        );

        foreach ($iacra as $key => $value) {
            $vars['iacra.' . $key] = $value;
        }

        foreach ($requirementResults as $result) {
            $authority = strtolower(str_replace('FAA_PART_', 'faa', (string)($result['authority'] ?? '')));
            $certificate = strtolower((string)($result['certificate'] ?? ''));
            $requirementKey = strtolower((string)($result['requirement_key'] ?? ''));
            if ($authority === '' || $certificate === '' || $requirementKey === '') {
                continue;
            }
            $vars[$authority . '.' . $certificate . '.' . $requirementKey . '.status'] = $result['status'] ?? 'fail';
            $vars[$authority . '.' . $certificate . '.' . $requirementKey . '.value'] = $result['value'] ?? 0;
        }

        return $vars;
    }
}
