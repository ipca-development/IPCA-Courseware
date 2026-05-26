<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_oral_bootstrap.php';

final class WeakAreaAggregationService
{
    private const SOURCE_WEIGHTS = [
        'faa_knowledge_test' => 0.35,
        'progress_test' => 0.30,
        'mock_oral_prior' => 0.25,
        'course_analytics' => 0.10,
    ];

    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    /**
     * @return list<array{concept:string, weight:float, sources:list<string>, area_id:?int, acs_task_code:?string}>
     */
    public function buildProfile(int $userId, int $cohortId, int $catalogId, ?int $areaId = null): array
    {
        $concepts = [];

        $this->mergeConcepts($concepts, $this->fromFaaKnowledgeTest($userId, $cohortId, $catalogId, $areaId), 'faa_knowledge_test');
        $this->mergeConcepts($concepts, $this->fromProgressTests($userId, $cohortId, $areaId), 'progress_test');
        $this->mergeConcepts($concepts, $this->fromPriorMockOral($userId, $cohortId, $areaId), 'mock_oral_prior');
        $this->mergeConcepts($concepts, $this->fromCourseAnalytics($userId, $cohortId, $areaId), 'course_analytics');

        uasort($concepts, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return array_values($concepts);
    }

    /**
     * @param array<string, array{concept:string, weight:float, sources:list<string>, area_id:?int, acs_task_code:?string}> $target
     * @param list<array{concept:string, weight:float, area_id:?int, acs_task_code:?string}> $items
     */
    private function mergeConcepts(array &$target, array $items, string $sourceKey): void
    {
        $sourceWeight = self::SOURCE_WEIGHTS[$sourceKey] ?? 0.1;
        foreach ($items as $item) {
            $concept = trim((string)($item['concept'] ?? ''));
            if ($concept === '') {
                continue;
            }
            $key = strtolower($concept);
            $delta = (float)($item['weight'] ?? 1.0) * $sourceWeight;
            if (!isset($target[$key])) {
                $target[$key] = [
                    'concept' => $concept,
                    'weight' => 0.0,
                    'sources' => [],
                    'area_id' => isset($item['area_id']) ? (int)$item['area_id'] : null,
                    'acs_task_code' => isset($item['acs_task_code']) ? (string)$item['acs_task_code'] : null,
                ];
            }
            $target[$key]['weight'] += $delta;
            if (!in_array($sourceKey, $target[$key]['sources'], true)) {
                $target[$key]['sources'][] = $sourceKey;
            }
        }
    }

    /** FAA Knowledge Test deficiencies: admin-confirmed rows only (never auto/parsed). */
    private function fromFaaKnowledgeTest(int $userId, int $cohortId, int $catalogId, ?int $areaId): array
    {
        $sql = "
            SELECT d.deficiency_label AS concept, d.area_id, d.acs_task_code, d.confidence
            FROM faa_knowledge_test_deficiencies d
            INNER JOIN faa_knowledge_test_reports r ON r.id = d.report_id
            WHERE r.user_id = ? AND r.cohort_id = ?
              AND d.review_status = 'confirmed'
              AND (r.catalog_id IS NULL OR r.catalog_id = ?)
        ";
        $params = [$userId, $cohortId, $catalogId];
        if ($areaId !== null && $areaId > 0) {
            $sql .= ' AND (d.area_id IS NULL OR d.area_id = ?)';
            $params[] = $areaId;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $conf = (float)($row['confidence'] ?? 0.8);
            if ($conf <= 0) {
                $conf = 0.8;
            }
            $rows[] = [
                'concept' => (string)$row['concept'],
                'weight' => $conf,
                'area_id' => $row['area_id'] !== null ? (int)$row['area_id'] : null,
                'acs_task_code' => $row['acs_task_code'] !== null ? (string)$row['acs_task_code'] : null,
            ];
        }
        return $rows;
    }

    /** @return list<array{concept:string, weight:float, area_id:?int, acs_task_code:?string}> */
    private function fromProgressTests(int $userId, int $cohortId, ?int $areaId): array
    {
        $lessonFilter = '';
        $params = [$userId, $cohortId];
        if ($areaId !== null && $areaId > 0) {
            $lessonFilter = ' AND pt.lesson_id IN (
                SELECT lesson_id FROM mock_oral_acs_area_lesson_map WHERE area_id = ?
            )';
            $params[] = $areaId;
        }

        $st = $this->pdo->prepare("
            SELECT oir.missing_concepts_json, pt.weak_areas, pt.completed_at
            FROM progress_test_oral_item_responses oir
            INNER JOIN progress_tests_v2 pt ON pt.id = oir.attempt_id
            WHERE pt.user_id = ? AND pt.cohort_id = ?
              AND pt.status = 'completed'
              {$lessonFilter}
            ORDER BY pt.completed_at DESC
            LIMIT 200
        ");
        $st->execute($params);

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decay = $this->recencyDecay((string)($row['completed_at'] ?? ''));
            $missing = mo_json_decode((string)($row['missing_concepts_json'] ?? ''));
            foreach ($missing as $concept) {
                $concept = trim((string)$concept);
                if ($concept === '') {
                    continue;
                }
                $rows[] = ['concept' => $concept, 'weight' => 1.0 * $decay, 'area_id' => $areaId, 'acs_task_code' => null];
            }
            $weakText = trim((string)($row['weak_areas'] ?? ''));
            if ($weakText !== '') {
                foreach (preg_split('/[,;\n]+/', $weakText) ?: [] as $part) {
                    $part = trim((string)$part);
                    if ($part !== '') {
                        $rows[] = ['concept' => $part, 'weight' => 0.7 * $decay, 'area_id' => $areaId, 'acs_task_code' => null];
                    }
                }
            }
        }
        return $rows;
    }

    /** @return list<array{concept:string, weight:float, area_id:?int, acs_task_code:?string}> */
    private function fromPriorMockOral(int $userId, int $cohortId, ?int $areaId): array
    {
        $sql = "
            SELECT sd.concept, sd.weight, sd.area_id, sd.acs_task_code, s.ended_at
            FROM mock_oral_session_deficiencies sd
            INNER JOIN mock_oral_sessions s ON s.id = sd.session_id
            WHERE s.user_id = ? AND s.cohort_id = ? AND s.status = 'completed'
              AND s.ended_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
        ";
        $params = [$userId, $cohortId];
        if ($areaId !== null && $areaId > 0) {
            $sql .= ' AND (sd.area_id IS NULL OR sd.area_id = ?)';
            $params[] = $areaId;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decay = $this->recencyDecay((string)($row['ended_at'] ?? ''));
            $rows[] = [
                'concept' => (string)$row['concept'],
                'weight' => (float)($row['weight'] ?? 1.0) * $decay,
                'area_id' => $row['area_id'] !== null ? (int)$row['area_id'] : null,
                'acs_task_code' => $row['acs_task_code'] !== null ? (string)$row['acs_task_code'] : null,
            ];
        }
        return $rows;
    }

    /** @return list<array{concept:string, weight:float, area_id:?int, acs_task_code:?string}> */
    private function fromCourseAnalytics(int $userId, int $cohortId, ?int $areaId): array
    {
        $lessonFilter = '';
        $params = [$userId, $cohortId];
        if ($areaId !== null && $areaId > 0) {
            $lessonFilter = ' AND la.lesson_id IN (
                SELECT lesson_id FROM mock_oral_acs_area_lesson_map WHERE area_id = ?
            )';
            $params[] = $areaId;
        }

        try {
            $st = $this->pdo->prepare("
                SELECT la.lesson_id, la.best_score, la.test_pass_status
                FROM lesson_activity la
                WHERE la.user_id = ? AND la.cohort_id = ?
                  AND (la.best_score IS NOT NULL AND la.best_score < 75)
                  {$lessonFilter}
                LIMIT 50
            ");
            $st->execute($params);
        } catch (Throwable $e) {
            return [];
        }

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $score = (float)($row['best_score'] ?? 0);
            $weight = max(0.1, (75 - $score) / 75);
            $rows[] = [
                'concept' => 'Lesson ' . (int)$row['lesson_id'] . ' knowledge gap (best ' . round($score) . '%)',
                'weight' => $weight,
                'area_id' => $areaId,
                'acs_task_code' => null,
            ];
        }
        return $rows;
    }

    private function recencyDecay(string $utc): float
    {
        $ts = strtotime($utc);
        if ($ts <= 0) {
            return 1.0;
        }
        $days = max(0, (time() - $ts) / 86400);
        return exp(-$days / 60.0);
    }
}
