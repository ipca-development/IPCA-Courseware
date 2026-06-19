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
            $results[] = $this->evaluate($category, $totals, $assignmentCounts, $assignmentDistances, $assignmentSums);
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $category
     * @param array<string,mixed> $totals
     * @param array<string,int> $assignmentCounts
     * @param array<string,float> $assignmentDistances
     * @param array<string,array<string,float>> $assignmentSums
     * @return array<string,mixed>
     */
    public function evaluate(array $category, array $totals, array $assignmentCounts = array(), array $assignmentDistances = array(), array $assignmentSums = array()): array
    {
        $rules = $this->decodeRules($category['automatic_rules_json'] ?? null);
        $requirementKey = (string)($category['requirement_key'] ?? '');
        $metric = (string)($rules['metric'] ?? '');
        $type = (string)($rules['type'] ?? '');
        $value = 0.0;
        $minimum = null;
        $warnings = array();
        $missing = array();

        if ($metric !== '') {
            $value = (float)($totals[$metric] ?? 0);
            $minimum = $category['minimum_time'] !== null ? (float)$category['minimum_time'] : null;
            if ($minimum === null && $category['minimum_count'] !== null) {
                $minimum = (float)$category['minimum_count'];
            }
        } elseif ($type === 'selected_entries_distance') {
            $value = (float)($assignmentDistances[$requirementKey] ?? 0);
            $minimum = $category['minimum_distance_nm'] !== null ? (float)$category['minimum_distance_nm'] : null;
            if ($value <= 0) {
                $warnings[] = 'Selected logbook entry distance required.';
            }
        } elseif ($type === 'selected_entries_sum' || $this->inferredSelectedEntryMetric($requirementKey) !== '') {
            $field = $metric !== '' ? $metric : (string)($rules['field'] ?? '');
            if ($field === '') {
                $field = $this->inferredSelectedEntryMetric($requirementKey);
            }
            $value = $field !== '' ? (float)($assignmentSums[$requirementKey][$field] ?? 0) : 0.0;
            $minimum = $category['minimum_count'] !== null ? (float)$category['minimum_count'] : null;
            if ($minimum === null && $category['minimum_time'] !== null) {
                $minimum = (float)$category['minimum_time'];
            }
            if ($minimum === null) {
                $minimum = 1.0;
            }
            if ($field === '') {
                $warnings[] = 'Selected logbook entry metric required.';
            }
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
        );
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
