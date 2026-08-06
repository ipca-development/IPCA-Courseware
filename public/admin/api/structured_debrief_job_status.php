<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderDebriefQueueService.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function structured_debrief_job_status_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = cw_current_user($pdo) ?: array();
    $role = strtolower(trim((string)($user['role'] ?? '')));
    if (!in_array($role, array('admin', 'supervisor', 'instructor', 'chief_instructor'), true)) {
        structured_debrief_job_status_json(403, array('ok' => false, 'error' => 'Forbidden.'));
    }

    $rawBundleIds = trim((string)($_GET['bundle_ids'] ?? ''));
    $rawJobIds = trim((string)($_GET['job_ids'] ?? ''));
    $bundleIds = array();
    if ($rawBundleIds !== '') {
        $bundleIds = array_values(array_filter(array_map('intval', explode(',', $rawBundleIds)), static fn(int $id): bool => $id > 0));
    }
    if ($rawJobIds !== '' && $bundleIds === array()) {
        $jobIds = array_values(array_filter(array_map('intval', explode(',', $rawJobIds)), static fn(int $id): bool => $id > 0));
        if ($jobIds !== array()) {
            $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
            $statement = $pdo->prepare(
                "SELECT CAST(entity_id AS UNSIGNED) AS bundle_id
                 FROM ipca_async_jobs
                 WHERE id IN ({$placeholders})
                   AND job_type = 'generate_structured_debrief'
                   AND entity_type = 'ipca_manual_intake_bundles'"
            );
            $statement->execute($jobIds);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bundleId = (int)($row['bundle_id'] ?? 0);
                if ($bundleId > 0) {
                    $bundleIds[] = $bundleId;
                }
            }
            $bundleIds = array_values(array_unique($bundleIds));
        }
    }
    if ($bundleIds === array()) {
        structured_debrief_job_status_json(400, array('ok' => false, 'error' => 'bundle_ids are required.'));
    }

    $queue = CockpitRecorderDebriefQueueService::fromPdo($pdo);
    structured_debrief_job_status_json(200, array(
        'ok' => true,
        'jobs' => $queue->statusForBundles($bundleIds),
    ));
} catch (Throwable $e) {
    structured_debrief_job_status_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
