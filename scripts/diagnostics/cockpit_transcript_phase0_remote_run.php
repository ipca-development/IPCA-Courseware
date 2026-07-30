<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0ProbeAuth.php';

/**
 * Trigger Phase 0 probe + typed persistence on App Platform, then verify via DB.
 *
 *   php scripts/diagnostics/cockpit_transcript_phase0_remote_run.php
 *   php scripts/diagnostics/cockpit_transcript_phase0_remote_run.php --verify-only=UUID
 */
@ini_set('max_execution_time', '1200');

function remote_arg(string $name, ?string $default = null): ?string
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

$baseUrl = rtrim(remote_arg('base-url', 'https://ipca.training') ?? 'https://ipca.training', '/');
$recordingId = (int)(remote_arg('recording-id', '552') ?? '552');
$chunk = (int)(remote_arg('probe-chunk', '0') ?? '0');
$verifyOnly = remote_arg('verify-only', null);
$replayEvidence = remote_arg('replay-evidence', null);

if (is_string($verifyOnly) && trim($verifyOnly) !== '') {
    require_once __DIR__ . '/../../src/CockpitRecorderService.php';
    require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';
    $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
    $report = $service->verifyProbePersistence(trim($verifyOnly));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($report['ok']) ? 0 : 1);
}

if ($replayEvidence !== null) {
    $token = Phase0ProbeAuth::probeToken($recordingId, $chunk);
    if ($token === '') {
        fwrite(STDERR, "CW_DB_PASS missing — cannot compute probe token.\n");
        exit(1);
    }
    $url = $baseUrl . '/admin/api/cockpit_transcript_phase0_investigation.php'
        . '?action=replay_evidence'
        . '&recording_id=' . $recordingId
        . '&probe_chunk=' . $chunk;
    $reportFile = remote_arg('report', '');
    if (is_string($reportFile) && trim($reportFile) !== '') {
        $url .= '&report=' . rawurlencode(trim($reportFile));
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'X-CW-Phase0-Probe-Token: ' . $token,
        ),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "http_code={$code}\n";
    echo is_string($body) ? $body : '' . PHP_EOL;
    $json = is_string($body) ? json_decode($body, true) : null;
    $uuid = is_array($json) ? (string)($json['probe_execution_uuid'] ?? '') : '';
    if (is_array($json) && isset($json['persistence']['pass_4'])) {
        echo "\n=== Pass 4 (from replay) ===\n";
        echo json_encode($json['persistence']['pass_4'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    if ($uuid !== '') {
        require_once __DIR__ . '/../../src/CockpitRecorderService.php';
        require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';
        $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
        $verification = $service->verifyProbePersistence($uuid);
        echo "\n=== DB verification ===\n";
        echo json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $pass4Ok = !isset($json['persistence']['pass_4'])
            || !is_array($json['persistence']['pass_4'])
            || (!empty($json['persistence']['pass_4']['skipped']) || !empty($json['persistence']['pass_4']['ok']));
        exit(!empty($json['ok']) && $pass4Ok && !empty($verification['ok']) ? 0 : 1);
    }
    exit(is_array($json) && !empty($json['ok']) ? 0 : 1);
}

$token = Phase0ProbeAuth::probeToken($recordingId, $chunk);
if ($token === '') {
    fwrite(STDERR, "CW_DB_PASS missing — cannot compute probe token.\n");
    exit(1);
}

$url = $baseUrl . '/admin/api/cockpit_transcript_phase0_investigation.php'
    . '?recording_id=' . $recordingId
    . '&probe_provider=1'
    . '&probe_chunk=' . $chunk
    . '&persist=1';

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 900,
    CURLOPT_HTTPHEADER => array(
        'Accept: application/json',
        'X-CW-Phase0-Probe-Token: ' . $token,
    ),
));
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "http_code={$code}\n";
if (!is_string($body)) {
    fwrite(STDERR, "Empty response\n");
    exit(1);
}
echo $body . PHP_EOL;

$json = json_decode($body, true);
if (!is_array($json)) {
    exit(1);
}

$uuid = (string)($json['probe_execution_uuid'] ?? ($json['persistence']['probe_execution_uuid'] ?? ''));
$persistOk = is_array($json['persistence'] ?? null)
    && (($json['persistence']['typed_persistence_succeeded'] ?? false) === true);
$pass4Ok = !isset($json['persistence']['pass_4'])
    || !is_array($json['persistence']['pass_4'])
    || (!empty($json['persistence']['pass_4']['skipped']) || !empty($json['persistence']['pass_4']['ok']));
$probeOk = !empty($json['ok']);

if ($uuid !== '') {
    require_once __DIR__ . '/../../src/CockpitRecorderService.php';
    require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';
    $service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));
    $verification = $service->verifyProbePersistence($uuid);
    echo "\n=== DB verification ===\n";
    echo json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($probeOk && $persistOk && $pass4Ok && !empty($verification['ok']) ? 0 : 1);
}

exit($probeOk && $persistOk && $pass4Ok ? 0 : 1);
