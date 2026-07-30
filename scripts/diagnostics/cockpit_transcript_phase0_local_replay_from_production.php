<?php
declare(strict_types=1);

/**
 * Download Phase 0 evidence from App Platform and replay into typed DB tables locally.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0ProbeAuth.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0EvidenceReplayService.php';

function local_replay_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? array() as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

$baseUrl = rtrim(local_replay_arg('base-url', 'https://ipca.training') ?? 'https://ipca.training', '/');
$recordingId = (int)(local_replay_arg('recording-id', '552') ?? '552');
$chunk = (int)(local_replay_arg('probe-chunk', '0') ?? '0');
$prefix = local_replay_arg('prefix', 'recording_552_chunk_0_20260729_223511') ?? 'recording_552_chunk_0_20260729_223511';
$token = Phase0ProbeAuth::probeToken($recordingId, $chunk);
if ($token === '') {
    fwrite(STDERR, "CW_DB_PASS missing.\n");
    exit(1);
}

$files = array(
    $prefix . '_report.json',
    $prefix . '_production_json_raw.json',
    $prefix . '_production_verbose_json_raw.json',
    $prefix . '_whisper1_verbose_json_raw.json',
);

$tmpDir = sys_get_temp_dir() . '/ipca_phase0_replay_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);

foreach ($files as $file) {
    $url = $baseUrl . '/admin/api/cockpit_transcript_phase0_investigation.php'
        . '?action=read_evidence&recording_id=' . $recordingId
        . '&probe_chunk=' . $chunk
        . '&file=' . rawurlencode($file);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'X-CW-Phase0-Probe-Token: ' . $token,
        ),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code !== 200 || !is_string($body)) {
        fwrite(STDERR, "Failed to fetch {$file}: HTTP {$code}\n");
        exit(1);
    }
    $payload = json_decode($body, true);
    if (!is_array($payload) || !is_array($payload['json'] ?? null)) {
        fwrite(STDERR, "Invalid payload for {$file}\n");
        exit(1);
    }
    file_put_contents(
        $tmpDir . '/' . $file,
        json_encode($payload['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo "fetched {$file}\n";
}

$result = Phase0EvidenceReplayService::replayDirectory($pdo, $tmpDir, $prefix . '_report.json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$uuid = (string)($result['probe_execution_uuid'] ?? '');
if ($uuid !== '') {
    require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';
    $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
    $verification = $service->verifyProbePersistence($uuid);
    echo "\n=== DB verification ===\n";
    echo json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) && !empty($verification['ok']) ? 0 : 1);
}

exit(!empty($result['ok']) ? 0 : 1);
