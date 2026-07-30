<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/AviationEvidence/Phase0InvestigationService.php';
require_once __DIR__ . '/../../../src/AviationEvidence/Phase0ProbeAuth.php';
require_once __DIR__ . '/../../../src/AviationEvidence/Phase0EvidenceReplayService.php';

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
    $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
    $action = trim((string)($_GET['action'] ?? ''));
    $recordingId = (int)($_GET['recording_id'] ?? $_GET['id'] ?? 0);
    $probeChunk = (int)($_GET['probe_chunk'] ?? 0);

    $currentUser = cw_current_user($pdo);
    $isAdmin = is_array($currentUser) && (string)($currentUser['role'] ?? '') === 'admin';

    if ($action === 'find-affected' || $action === 'hallucination-search') {
        if (!$isAdmin) {
            phase0_api_json(403, array('ok' => false, 'error' => 'Admin access required.'));
        }
        if ($action === 'find-affected') {
            phase0_api_json(200, array('ok' => true, 'affected' => $service->findAffected()));
        }
        phase0_api_json(200, array('ok' => true, 'hallucination_history' => $service->searchHallucinationHistory()));
    }

    $verifyUuid = trim((string)($_GET['verify_persistence'] ?? ''));
    if ($verifyUuid !== '') {
        if (!$isAdmin && !Phase0ProbeAuth::isVerifyAuthorized($verifyUuid, $recordingId, $probeChunk)) {
            phase0_api_json(403, array('ok' => false, 'error' => 'Admin or probe token required.'));
        }
        $report = $service->verifyProbePersistence($verifyUuid);
        phase0_api_json(!empty($report['ok']) ? 200 : 500, array('ok' => !empty($report['ok']), 'verification' => $report));
    }

    if ($action === 'read_evidence') {
        if ($recordingId <= 0) {
            $recordingId = 552;
        }
        if (!$isAdmin && !Phase0ProbeAuth::isAuthorized($recordingId, $probeChunk)) {
            phase0_api_json(403, array('ok' => false, 'error' => 'Admin or probe token required.'));
        }
        $basename = trim((string)($_GET['file'] ?? ''));
        if ($basename === '' || preg_match('/[^a-zA-Z0-9._-]/', $basename)) {
            phase0_api_json(400, array('ok' => false, 'error' => 'Invalid evidence file name.'));
        }
        $evidenceDir = trim((string)($_GET['evidence_dir'] ?? 'storage/cockpit_recorder/phase0_evidence'));
        $absDir = CockpitRecorderService::projectRoot() . '/' . ltrim($evidenceDir, '/');
        $path = $absDir . '/' . $basename;
        if (!is_file($path)) {
            phase0_api_json(404, array('ok' => false, 'error' => 'Evidence file not found.', 'path' => $basename));
        }
        $json = json_decode((string)file_get_contents($path), true);
        phase0_api_json(200, array(
            'ok' => true,
            'file' => $basename,
            'sha256' => hash('sha256', (string)file_get_contents($path)),
            'json' => $json,
        ));
    }

    if ($action === 'replay_evidence') {
        if ($recordingId <= 0) {
            $recordingId = 552;
        }
        if (!$isAdmin && !Phase0ProbeAuth::isAuthorized($recordingId, $probeChunk)) {
            phase0_api_json(403, array('ok' => false, 'error' => 'Admin or probe token required.'));
        }
        $evidenceDir = trim((string)($_GET['evidence_dir'] ?? 'storage/cockpit_recorder/phase0_evidence'));
        $reportBasename = trim((string)($_GET['report'] ?? ''));
        $replay = Phase0EvidenceReplayService::replayDirectory(
            $pdo,
            $evidenceDir,
            $reportBasename !== '' ? $reportBasename : null
        );
        phase0_api_json(!empty($replay['ok']) ? 200 : 500, $replay);
    }

    $probeProvider = filter_var($_GET['probe_provider'] ?? '0', FILTER_VALIDATE_BOOLEAN);

    if ($probeProvider && $recordingId > 0) {
        if (!$isAdmin && !Phase0ProbeAuth::isAuthorized($recordingId, $probeChunk)) {
            phase0_api_json(403, array('ok' => false, 'error' => 'Admin or probe token required.'));
        }

        $persistMode = filter_var($_GET['persist'] ?? '0', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $persistFallback = filter_var($_GET['persist_fallback'] ?? '0', FILTER_VALIDATE_BOOLEAN);

        $report = $service->runMandatoryProviderProbe(
            $recordingId,
            $probeChunk,
            true,
            'storage/cockpit_recorder/phase0_evidence',
            $persistMode,
            $persistFallback
        );
        if (!filter_var($_GET['include_primary_raw'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
            unset($report['primary_raw_json']);
        }

        $httpCode = 200;
        if (!empty($report['ok'])) {
            if ($persistMode === 1) {
                $persist = is_array($report['persistence'] ?? null) ? $report['persistence'] : array();
                if (($persist['typed_persistence_succeeded'] ?? false) !== true) {
                    $httpCode = 500;
                }
            }
        } else {
            $httpCode = 500;
        }

        phase0_api_json($httpCode, $report);
    }

    if (!$isAdmin) {
        phase0_api_json(403, array('ok' => false, 'error' => 'Admin access required.'));
    }

    if ($recordingId <= 0) {
        phase0_api_json(400, array(
            'ok' => false,
            'error' => 'recording_id required. Actions: find-affected, hallucination-search, probe_provider=1, verify_persistence=UUID.',
        ));
    }

    $report = $service->investigateRecording($recordingId, false, $probeChunk);
    phase0_api_json(!empty($report['ok']) ? 200 : 404, $report);
} catch (Throwable $e) {
    phase0_api_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
