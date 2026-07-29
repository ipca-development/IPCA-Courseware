<?php
declare(strict_types=1);

/**
 * Phase 0 mandatory provider probe — run on App Platform where audio + OpenAI key exist.
 *
 *   php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --recording-id=552 --probe-chunk=0
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/AviationEvidence/Phase0InvestigationService.php';

@ini_set('max_execution_time', '900');
@ini_set('memory_limit', '768M');

function probe_cli_arg(string $name, ?string $default = null): ?string
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

$recordingId = (int)(probe_cli_arg('recording-id', '0') ?? '0');
$probeChunk = (int)(probe_cli_arg('probe-chunk', '0') ?? '0');
$saveDir = probe_cli_arg('save-dir', 'storage/cockpit_recorder/phase0_evidence') ?? 'storage/cockpit_recorder/phase0_evidence';

$service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));

if (probe_cli_arg('hallucination-search') !== null) {
    echo json_encode($service->searchHallucinationHistory(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($recordingId <= 0) {
    fwrite(STDERR, "Usage:\n  --hallucination-search\n  --recording-id=N [--probe-chunk=0]\n");
    exit(1);
}

$result = $service->runMandatoryProviderProbe($recordingId, $probeChunk, true, $saveDir);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
