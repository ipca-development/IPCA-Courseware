<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$queue = file_get_contents($root . '/src/CockpitRecorderDebriefQueueService.php') ?: '';
$debriefService = file_get_contents($root . '/src/FlightDebriefService.php') ?: '';
$worker = file_get_contents($root . '/scripts/run_structured_flight_debrief.php') ?: '';
$statusApi = file_get_contents($root . '/public/admin/api/structured_debrief_job_status.php') ?: '';
$manualApi = file_get_contents($root . '/public/admin/api/manual_bundle_debrief.php') ?: '';
$intake = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';
$readService = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';
$bundleService = file_get_contents($root . '/src/ManualReconstructionBundleService.php') ?: '';

$checks = array(
    'queue service writes progress into payload_json' =>
        str_contains($queue, 'function updateJobProgress')
        && str_contains($queue, "\$payload['progress'] = \$progress")
        && str_contains($queue, "\$payload['progress_message']")
        && str_contains($queue, "'progress' => 5")
        && str_contains($queue, "'progress_message' => 'Queued'"),
    'worker reports staged progress through FlightDebriefService callback' =>
        str_contains($worker, 'updateJobProgress($jobId, 10, \'Starting generation\')')
        && str_contains($worker, 'generateStructuredDebrief(')
        && str_contains($worker, 'updateJobProgress($jobId, $progress, $message)')
        && str_contains($worker, 'updateJobProgress($jobId, 100, \'Ready\')'),
    'generateStructuredDebrief accepts progress callback and stages' =>
        str_contains($debriefService, '?callable $onProgress = null')
        && str_contains($debriefService, "\$report(20, 'Preparing evidence')")
        && str_contains($debriefService, "\$report(45, 'Calling AI model')")
        && str_contains($debriefService, "\$report(95, 'Saving draft')"),
    'status API exists with Master Logbook role gate' =>
        str_contains($statusApi, 'structured_debrief_job_status_json')
        && str_contains($statusApi, "'admin', 'supervisor', 'instructor', 'chief_instructor'")
        && str_contains($statusApi, 'statusForBundles')
        && str_contains($statusApi, 'bundle_ids'),
    'manual debrief spawn seeds queued progress' =>
        str_contains($manualApi, "'progress' => 5")
        && str_contains($manualApi, "'progress_message' => 'Queued'"),
    'legs enrichment includes debrief job progress fields' =>
        str_contains($readService, "'debrief_job_progress'")
        && str_contains($readService, "'debrief_job_message'")
        && str_contains($readService, 'statusForBundles')
        && str_contains($bundleService, 'debrief_job_payload')
        && str_contains($bundleService, 'debrief_job_progress'),
    'Master Logbook shows live debrief progress without refresh' =>
        str_contains($intake, 'data-debrief-bundle-id')
        && str_contains($intake, 'data-debrief-progress')
        && str_contains($intake, 'initDebriefProgressPoller')
        && str_contains($intake, '/admin/api/structured_debrief_job_status.php')
        && str_contains($intake, 'setInterval(poll, anyRunning ? 2000 : 5000)')
        && str_contains($intake, 'Progress updates live below.')
        && !str_contains($intake, 'Refresh this tab to see completion status.'),
    'server-only change does not require iOS updates' =>
        !str_contains($queue, 'ipca-cvr-unit')
        && !str_contains($statusApi, 'UploadManager')
        && !str_contains($worker, 'APIClient.swift'),
);

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "cvr_debrief_progress_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "cvr_debrief_progress_contract_check OK (" . count($checks) . " checks)\n";
exit(0);
