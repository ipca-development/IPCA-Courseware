<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/MissionCatalogService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $service = new MissionCatalogService($pdo);
    $rows = $service->listMissions();
    $missions = array();
    foreach ($rows as $row) {
        if ((string)($row['status'] ?? 'active') !== 'active') {
            continue;
        }
        $exercise = array();
        $rawExercise = (string)($row['exercise_json'] ?? '');
        if ($rawExercise !== '') {
            $decoded = json_decode($rawExercise, true);
            $exercise = is_array($decoded) ? $decoded : array();
        }
        $missions[] = array(
            'program' => (int)($exercise['program'] ?? 0),
            'stage' => (int)($exercise['stage'] ?? 0),
            'phase' => (int)($exercise['phase'] ?? 0),
            'scenario' => (int)($exercise['scenario'] ?? 0),
            'missionCode' => (string)($row['code'] ?? ''),
            'missionDescription' => trim((string)($row['description'] ?? '')) !== ''
                ? (string)$row['description']
                : (string)($row['name'] ?? ''),
        );
    }

    usort($missions, static function (array $a, array $b): int {
        foreach (array('program', 'stage', 'phase', 'scenario') as $key) {
            $cmp = ((int)$a[$key]) <=> ((int)$b[$key]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return strnatcasecmp((string)$a['missionCode'], (string)$b['missionCode']);
    });

    echo json_encode(array(
        'ok' => true,
        'missions' => $missions,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array(
        'ok' => false,
        'error' => $e->getMessage(),
        'missions' => array(),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
