<?php
declare(strict_types=1);

require_once __DIR__ . '/FlightTotalsService.php';

final class FlightRequirementEngine
{
    public function __construct(private FlightTotalsService $totalsService = new FlightTotalsService())
    {
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param list<array<string,mixed>> $categories
     * @param list<array<string,mixed>> $assignments
     * @return list<array<string,mixed>>
     */
    public function evaluateAll(array $entries, array $categories, array $assignments = array()): array
    {
        $totals = $this->totalsService->calculate($entries);
        $assignmentCounts = $this->assignmentCounts($assignments);
        $assignmentDistances = $this->assignmentDistances($assignments);
        $assignmentSums = $this->assignmentSums($assignments);
        $results = array();
        foreach ($categories as $category) {
            $results[] = $this->evaluate($category, $entries, $totals, $assignmentCounts, $assignmentDistances, $assignmentSums);
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $category
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed> $totals
     * @param array<string,int> $assignmentCounts
     * @param array<string,float> $assignmentDistances
     * @param array<string,array<string,float>> $assignmentSums
     * @return array<string,mixed>
     */
    public function evaluate(array $category, array $entries, array $totals, array $assignmentCounts = array(), array $assignmentDistances = array(), array $assignmentSums = array()): array
    {
        $rules = $this->decodeRules($category['automatic_rules_json'] ?? null);
        $requirementKey = (string)($category['requirement_key'] ?? '');
        $metric = (string)($rules['metric'] ?? '');
        $type = (string)($rules['type'] ?? '');
        $value = 0.0;
        $minimum = null;
        $warnings = array();
        $missing = array();
        $evidenceSource = 'manual';
        $requiresTagging = true;
        $evidenceLabel = 'Manual tag required';

        if ($type === 'selected_entries_distance' || ($category['minimum_distance_nm'] !== null && $metric === '')) {
            $value = (float)($assignmentDistances[$requirementKey] ?? 0);
            $minimum = $category['minimum_distance_nm'] !== null ? (float)$category['minimum_distance_nm'] : null;
            $evidenceSource = 'tagged';
            $evidenceLabel = 'Tagged logbook records';
            if ($value <= 0) {
                $warnings[] = 'Selected logbook entry distance required.';
            }
        } elseif ($type === 'selected_entries_sum' || $this->inferredSelectedEntryMetric($requirementKey) !== '') {
            $field = $metric !== '' ? $metric : (string)($rules['field'] ?? '');
            if ($field === '') {
                $field = $this->inferredSelectedEntryMetric($requirementKey);
            }
            $value = $field !== '' ? (float)($assignmentSums[$requirementKey][$field] ?? 0) : 0.0;
            $minimum = $this->minimumForMetric($category, $field);
            if ($minimum === null) {
                $minimum = 1.0;
            }
            if ($field === '') {
                $warnings[] = 'Selected logbook entry metric required.';
            }
            $evidenceSource = 'tagged';
            $evidenceLabel = 'Tagged logbook records';
        } elseif ($type === 'filtered_sum') {
            $filters = is_array($rules['filters'] ?? null) ? $rules['filters'] : array();
            $value = $metric !== '' ? $this->filteredSum($entries, $metric, $filters) : 0.0;
            $minimum = $this->minimumForMetric($category, $metric);
            $evidenceSource = 'auto_calculated';
            $requiresTagging = false;
            $evidenceLabel = 'Calculated from accepted logbook rows';
            if ($metric === '') {
                $warnings[] = 'Filtered requirement metric required.';
            }
        } elseif ($type === 'credited_total_time') {
            $credit = $this->creditedTotalTime($totals, $rules);
            $value = (float)$credit['credited_total_time'];
            $minimum = $this->minimumForMetric($category, 'total_flight_time');
            $evidenceSource = 'auto_calculated';
            $requiresTagging = false;
            $evidenceLabel = 'Calculated with capped AATD/BATD credit';
        } elseif ($metric !== '') {
            $value = (float)($totals[$metric] ?? 0);
            $minimum = $this->minimumForMetric($category, $metric);
            $evidenceSource = 'auto_calculated';
            $requiresTagging = false;
            $evidenceLabel = 'Calculated from accepted logbook rows';
        } else {
            $value = (float)($assignmentCounts[$requirementKey] ?? 0);
            $minimum = $category['minimum_count'] !== null ? (float)$category['minimum_count'] : 1.0;
            $warnings[] = 'Manual assignment required.';
        }

        $pass = $minimum === null ? $value > 0 : $value >= $minimum;
        if (!$pass) {
            $missing[] = array(
                'label' => (string)($category['label'] ?? $requirementKey),
                'required' => $minimum,
                'actual' => $value,
            );
        }

        return array(
            'requirement_category_id' => (int)($category['id'] ?? 0),
            'authority' => (string)($category['authority'] ?? ''),
            'certificate' => (string)($category['certificate'] ?? ''),
            'requirement_key' => $requirementKey,
            'label' => (string)($category['label'] ?? ''),
            'status' => $pass ? 'pass' : 'fail',
            'value' => $value,
            'minimum' => $minimum,
            'warnings' => $warnings,
            'missing_items' => $missing,
            'evidence_source' => $evidenceSource,
            'evidence_label' => $evidenceLabel,
            'requires_tagging' => $requiresTagging,
            'rule_type' => $type !== '' ? $type : ($metric !== '' ? 'total_metric' : 'manual_assignment'),
            'metric' => $metric,
            'breakdown' => isset($credit) && is_array($credit) ? $credit : array(),
        );
    }

    /**
     * @param array<string,mixed> $totals
     * @param array<string,mixed> $rules
     * @return array<string,mixed>
     */
    private function creditedTotalTime(array $totals, array $rules): array
    {
        $simMetric = (string)($rules['sim_metric'] ?? 'fnpt_simulator_time');
        $cap = (float)($rules['sim_credit_cap'] ?? 2.5);
        $totalLogged = (float)($totals['total_flight_time'] ?? 0);
        $simLogged = max(0.0, (float)($totals[$simMetric] ?? 0));
        $airplaneTime = max(0.0, $totalLogged - $simLogged);
        $simCredited = min($simLogged, $cap);
        $creditedTotal = $airplaneTime + $simCredited;

        return array(
            'airplane_time' => round($airplaneTime, 2),
            'sim_logged_time' => round($simLogged, 2),
            'sim_credit_cap' => round($cap, 2),
            'sim_credited_time' => round($simCredited, 2),
            'sim_excess_time' => round(max(0.0, $simLogged - $simCredited), 2),
            'credited_total_time' => round($creditedTotal, 2),
        );
    }

    /**
     * @param array<string,mixed> $category
     */
    private function minimumForMetric(array $category, string $metric): ?float
    {
        if (in_array($metric, array('day_landings', 'night_landings', 'towered_airport_landings'), true)) {
            return $category['minimum_count'] !== null ? (float)$category['minimum_count'] : null;
        }
        if ($metric === 'cross_country_distance_nm') {
            return $category['minimum_distance_nm'] !== null ? (float)$category['minimum_distance_nm'] : null;
        }
        if ($category['minimum_time'] !== null) {
            return (float)$category['minimum_time'];
        }
        return $category['minimum_count'] !== null ? (float)$category['minimum_count'] : null;
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param list<array<string,mixed>> $filters
     */
    private function filteredSum(array $entries, string $metric, array $filters): float
    {
        $sum = 0.0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !$this->isTrustedEntry($entry) || !$this->matchesFilters($entry, $filters)) {
                continue;
            }
            $sum += $this->entryMetricValue($entry, $metric);
        }
        return round($sum, in_array($metric, array('day_landings', 'night_landings', 'towered_airport_landings'), true) ? 0 : 2);
    }

    /**
     * @param array<string,mixed> $entry
     * @param list<array<string,mixed>> $filters
     */
    private function matchesFilters(array $entry, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $field = (string)($filter['field'] ?? '');
            $operator = (string)($filter['operator'] ?? 'gt');
            $expected = (float)($filter['value'] ?? 0);
            $actual = $this->entryMetricValue($entry, $field);
            if ($operator === 'gte' && $actual < $expected) {
                return false;
            }
            if ($operator === 'gt' && $actual <= $expected) {
                return false;
            }
            if ($operator === 'eq' && abs($actual - $expected) > 0.0001) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function isTrustedEntry(array $entry): bool
    {
        $status = strtolower(trim((string)($entry['review_status'] ?? '')));
        return in_array($status, array('accepted', 'ok', 'merged', 'split'), true);
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return array<string,int>
     */
    private function assignmentCounts(array $assignments): array
    {
        $counts = array();
        foreach ($assignments as $assignment) {
            $key = (string)($assignment['requirement_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return array<string,float>
     */
    private function assignmentDistances(array $assignments): array
    {
        $distances = array();
        $seen = array();
        foreach ($assignments as $assignment) {
            $key = (string)($assignment['requirement_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $distance = (float)($assignment['total_distance_nm'] ?? 0);
            if (is_array($assignment['entries'] ?? null)) {
                $distance = 0.0;
                foreach ($assignment['entries'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $entryId = (int)($entry['id'] ?? 0);
                    $dedupeKey = $key . ':' . $entryId;
                    if ($entryId > 0 && isset($seen[$dedupeKey])) {
                        continue;
                    }
                    if ($entryId > 0) {
                        $seen[$dedupeKey] = true;
                    }
                    $distance += (float)($entry['cross_country_distance_nm'] ?? 0);
                }
            }
            $distances[$key] = ($distances[$key] ?? 0.0) + round($distance, 1);
        }
        return $distances;
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return array<string,array<string,float>>
     */
    private function assignmentSums(array $assignments): array
    {
        $sums = array();
        $seen = array();
        $fields = array(
            'day_landings',
            'night_landings',
            'towered_airport_landings',
            'total_flight_time',
            'dual_received_time',
            'solo_time',
            'cross_country_time',
            'cross_country_distance_nm',
            'night_time',
            'instrument_time',
            'basic_instrument_flying_time',
        );
        foreach ($assignments as $assignment) {
            $key = (string)($assignment['requirement_key'] ?? '');
            if ($key === '' || !is_array($assignment['entries'] ?? null)) {
                continue;
            }
            foreach ($assignment['entries'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryId = (int)($entry['id'] ?? 0);
                $dedupeKey = $key . ':' . $entryId;
                if ($entryId > 0 && isset($seen[$dedupeKey])) {
                    continue;
                }
                if ($entryId > 0) {
                    $seen[$dedupeKey] = true;
                }
                foreach ($fields as $field) {
                    $sums[$key][$field] = ($sums[$key][$field] ?? 0.0) + $this->entryMetricValue($entry, $field);
                }
            }
        }
        return $sums;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function entryMetricValue(array $entry, string $field): float
    {
        $value = (float)($entry[$field] ?? 0);
        if ($field === 'towered_airport_landings' && $value <= 0) {
            return (float)($entry['day_landings'] ?? 0) + (float)($entry['night_landings'] ?? 0);
        }
        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeRules(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || trim($json) === '') {
            return array();
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function inferredSelectedEntryMetric(string $requirementKey): string
    {
        $key = strtolower($requirementKey);
        if (str_contains($key, 'night_takeoffs_landings')) {
            return 'night_landings';
        }
        if (str_contains($key, 'towered_airport_takeoffs_landings') || str_contains($key, 'towered_airport_landings')) {
            return 'towered_airport_landings';
        }
        return '';
    }
}
