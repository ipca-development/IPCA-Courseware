<?php
declare(strict_types=1);

/**
 * Phase 0 — Cockpit transcript investigation CLI (runs where DB is reachable).
 *
 * Usage:
 *   php scripts/diagnostics/cockpit_transcript_phase0_investigation.php --find-affected
 *   php scripts/diagnostics/cockpit_transcript_phase0_investigation.php --recording-id=123
 *   php scripts/diagnostics/cockpit_transcript_phase0_investigation.php --recording-id=123 --probe-provider
 *
 * If local DB connection times out (DO firewall), use the admin API on App Platform:
 *   GET /admin/api/cockpit_transcript_phase0_investigation.php?action=find-affected
 *   GET /admin/api/cockpit_transcript_phase0_investigation.php?recording_id=123&probe_provider=1
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';

@ini_set('max_execution_time', '900');
@ini_set('memory_limit', '512M');

function phase0_cli_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? array() as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
        if ($arg === '--' . $name) {
            return '1';
        }
    }
    return $default;
}

$findAffected = phase0_cli_arg('find-affected') !== null;
$recordingId = (int)(phase0_cli_arg('recording-id', '0') ?? '0');
$probeProvider = phase0_cli_arg('probe-provider') !== null;
$probeChunk = (int)(phase0_cli_arg('probe-chunk', '-1') ?? '-1');
$writeMarkdown = phase0_cli_arg('write-markdown');
$writeJson = phase0_cli_arg('write-json');

$service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));

if ($findAffected) {
    echo json_encode(array('ok' => true, 'affected' => $service->findAffected()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($recordingId <= 0) {
    fwrite(STDERR, "Usage: --find-affected | --recording-id=N [--probe-provider]\n");
    exit(1);
}

$report = $service->investigateRecording($recordingId, $probeProvider, $probeChunk);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($writeJson !== null && $writeJson !== '') {
    $jsonPath = str_starts_with($writeJson, '/') ? $writeJson : dirname(__DIR__, 2) . '/' . ltrim($writeJson, '/');
    file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, 'Wrote ' . $jsonPath . PHP_EOL);
}

if ($writeMarkdown !== null && $writeMarkdown !== '') {
    $mdPath = str_starts_with($writeMarkdown, '/') ? $writeMarkdown : dirname(__DIR__, 2) . '/' . ltrim($writeMarkdown, '/');
    file_put_contents($mdPath, $service->toMarkdown($report));
    fwrite(STDOUT, 'Wrote ' . $mdPath . PHP_EOL);
}

exit(!empty($report['ok']) ? 0 : 1);
