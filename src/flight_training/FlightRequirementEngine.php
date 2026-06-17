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
        $results = array();
        foreach ($categories as $category) {
            $results[] = $this->evaluate($category, $totals, $assignmentCounts);
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $category
     * @param array<string,mixed> $totals
     * @param array<string,int> $assignmentCounts
     * @return array<string,mixed>
     */
    public function evaluate(array $category, array $totals, array $assignmentCounts = array()): array
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
            $value = (float)($totals['cross_country_distance_nm'] ?? 0);
            $minimum = $category['minimum_distance_nm'] !== null ? (float)$category['minimum_distance_nm'] : null;
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
}
