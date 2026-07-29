<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/AviationEvidence/Phase0InvestigationService.php';

header('Content-Type: application/json; charset=utf-8');

@ini_set('max_execution_time', '900');
@ini_set('memory_limit', '768M');

function phase0_api_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $currentUser = cw_current_user($pdo);
    if (!is_array($currentUser) || (string)($currentUser['role'] ?? '') !== 'admin') {
        phase0_api_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
    $action = trim((string)($_GET['action'] ?? ''));

    if ($action === 'find-affected') {
        phase0_api_json(200, array('ok' => true, 'affected' => $service->findAffected()));
    }

    if ($action === 'hallucination-search') {
        phase0_api_json(200, array('ok' => true, 'hallucination_history' => $service->searchHallucinationHistory()));
    }

    $recordingId = (int)($_GET['recording_id'] ?? $_GET['id'] ?? 0);
    $probeProvider = filter_var($_GET['probe_provider'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $probeChunk = (int)($_GET['probe_chunk'] ?? 0);

    if ($probeProvider && $recordingId > 0) {
        $report = $service->runMandatoryProviderProbe($recordingId, $probeChunk, true);
        if (!filter_var($_GET['include_primary_raw'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
            unset($report['primary_raw_json']);
        }
        phase0_api_json(!empty($report['ok']) ? 200 : 500, $report);
    }

    if ($recordingId <= 0) {
        phase0_api_json(400, array(
            'ok' => false,
            'error' => 'recording_id required. Actions: find-affected, hallucination-search, or probe_provider=1.',
        ));
    }

    $report = $service->investigateRecording($recordingId, false, $probeChunk);
    phase0_api_json(!empty($report['ok']) ? 200 : 404, $report);
} catch (Throwable $e) {
    phase0_api_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
