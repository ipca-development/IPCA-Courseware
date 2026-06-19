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
     * @param list<array<string,mixed>> $requirementAssignments
     * @return array<string,mixed>
     */
    public function buildVariables(array $totals, array $requirementResults = array(), array $requirementAssignments = array()): array
    {
        $iacra = $this->iacraMapper->map($totals);
        $vars = array(
            'flight.total_time' => $totals['total_flight_time'] ?? 0,
            'flight.pic_time' => $totals['pic_time'] ?? 0,
            'flight.dual_time' => $totals['dual_received_time'] ?? 0,
            'flight.solo_time' => $totals['solo_time'] ?? 0,
            'flight.cross_country_time' => $totals['cross_country_time'] ?? 0,
            'flight.dual_cross_country_time' => $totals['dual_cross_country_time'] ?? 0,
            'flight.solo_cross_country_time' => $totals['solo_cross_country_time'] ?? 0,
            'flight.pic_cross_country_time' => $totals['pic_cross_country_time'] ?? 0,
            'flight.night_time' => $totals['night_time'] ?? 0,
            'flight.instrument_time' => $totals['instrument_time'] ?? 0,
            'flight.basic_instrument_flying' => $totals['basic_instrument_flying_time'] ?? 0,
            'flight.day_landings' => $totals['day_landings'] ?? 0,
            'flight.night_landings' => $totals['night_landings'] ?? 0,
            'flight.instructor_time' => $totals['instructor_time'] ?? 0,
            'flight.single_engine_time' => $totals['single_engine_time'] ?? 0,
            'flight.multi_engine_time' => $totals['multi_engine_time'] ?? 0,
            'flight.fnpt_simulator_time' => $totals['fnpt_simulator_time'] ?? 0,
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

        foreach ($requirementAssignments as $assignment) {
            $authority = strtolower(str_replace('FAA_PART_', 'faa', (string)($assignment['authority'] ?? '')));
            $certificate = strtolower((string)($assignment['certificate'] ?? ''));
            $requirementKey = strtolower((string)($assignment['requirement_key'] ?? ''));
            if ($authority === '' || $certificate === '' || $requirementKey === '') {
                continue;
            }

            $prefix = $authority . '.' . $certificate . '.' . $requirementKey;
            $entries = is_array($assignment['entries'] ?? null) ? $assignment['entries'] : array();
            $summary = trim((string)($assignment['entry_summary'] ?? ''));
            if ($summary === '') {
                $summary = $this->entrySummary($entries);
            }

            $vars[$prefix . '.events'] = $summary;
            $vars[$prefix . '.entry_count'] = (int)($assignment['entry_count'] ?? count($entries));
            $vars[$prefix . '.hours'] = round((float)($assignment['total_time'] ?? $this->sumField($entries, 'total_flight_time')), 1);
            $vars[$prefix . '.distance_nm'] = round((float)($assignment['total_distance_nm'] ?? $this->sumField($entries, 'cross_country_distance_nm')), 1);
            $vars[$prefix . '.date'] = $this->firstField($entries, 'entry_date');
            $vars[$prefix . '.route'] = $this->routeSummary($entries);

            // Backward compatibility for earlier form imports that used .status
            // fields for event evidence. A tagged event is more useful to the
            // recipient than a bare "pass" string.
            if ($summary !== '') {
                $vars[$prefix . '.status'] = 'Tagged: ' . $summary;
                $vars[$prefix . '.value'] = $summary;
            }
        }

        $this->addRequirementAliases($vars);

        return $vars;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function addRequirementAliases(array &$vars): void
    {
        $aliases = array(
            'faa61.ppl.long_solo_cross_country' => 'faa61.ppl.long_150nm_solo_cross_country_flight',
            'faa61.ppl.solo_cross_country_150_nm' => 'faa61.ppl.long_150nm_solo_cross_country_flight',
            'faa61.ppl.basic_instrument_flying' => 'faa61.ppl.dual_instrument_flight_training',
            'faa61.ppl.towered_airport_landings' => 'faa61.ppl.towered_airport_takeoffs_landings',
        );
        foreach ($aliases as $oldPrefix => $newPrefix) {
            foreach (array('events', 'entry_count', 'hours', 'distance_nm', 'route', 'date', 'status', 'value') as $suffix) {
                $newKey = $newPrefix . '.' . $suffix;
                if (array_key_exists($newKey, $vars)) {
                    $vars[$oldPrefix . '.' . $suffix] = $vars[$newKey];
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function entrySummary(array $entries): string
    {
        $parts = array();
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bits = array();
            $date = trim((string)($entry['entry_date'] ?? ''));
            if ($date !== '') {
                $bits[] = $date;
            }
            $route = $this->routeForEntry($entry);
            if ($route !== '') {
                $bits[] = $route;
            }
            $hours = round((float)($entry['total_flight_time'] ?? 0), 1);
            if ($hours > 0) {
                $bits[] = number_format($hours, 1, '.', '') . 'h';
            }
            $distance = round((float)($entry['cross_country_distance_nm'] ?? 0), 1);
            if ($distance > 0) {
                $bits[] = number_format($distance, 1, '.', '') . ' NM';
            }
            if ($bits !== array()) {
                $parts[] = implode(' · ', $bits);
            }
        }
        return implode('; ', $parts);
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function routeSummary(array $entries): string
    {
        $routes = array();
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $route = $this->routeForEntry($entry);
                if ($route !== '') {
                    $routes[] = $route;
                }
            }
        }
        return implode('; ', array_values(array_unique($routes)));
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function routeForEntry(array $entry): string
    {
        $dep = trim((string)($entry['departure_airport'] ?? ''));
        $arr = trim((string)($entry['arrival_airport'] ?? ''));
        return trim($dep . ($dep !== '' || $arr !== '' ? '-' : '') . $arr, '-');
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function sumField(array $entries, string $field): float
    {
        $sum = 0.0;
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $sum += (float)($entry[$field] ?? 0);
            }
        }
        return round($sum, 1);
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function firstField(array $entries, string $field): string
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = trim((string)($entry[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
