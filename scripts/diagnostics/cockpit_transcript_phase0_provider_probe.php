<?php
declare(strict_types=1);

/**
 * Phase 0 mandatory provider probe — run on App Platform where audio + OpenAI key exist.
 *
 * Usage:
 *   php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --recording-id=552 --probe-chunk=0
 *   php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --recording-id=552 --probe-chunk=0 --persist=1
 *   php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --verify-persistence=UUID
 *   php scripts/diagnostics/cockpit_transcript_phase0_provider_probe.php --hallucination-search
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../src/AviationEvidence/EvidenceSchema.php';
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

function probe_cli_bool(string $name, bool $default = false): bool
{
    $value = probe_cli_arg($name, null);
    if ($value === null) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

$service = new Phase0InvestigationService($pdo, new CockpitRecorderService($pdo));

if (probe_cli_arg('hallucination-search') !== null) {
    echo json_encode($service->searchHallucinationHistory(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$verifyUuid = probe_cli_arg('verify-persistence', null);
if (is_string($verifyUuid) && trim($verifyUuid) !== '') {
    $report = $service->verifyProbePersistence(trim($verifyUuid));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($report['ok']) ? 0 : 1);
}

$recordingId = (int)(probe_cli_arg('recording-id', '0') ?? '0');
$probeChunk = (int)(probe_cli_arg('probe-chunk', '0') ?? '0');
$saveDir = probe_cli_arg('save-dir', 'storage/cockpit_recorder/phase0_evidence') ?? 'storage/cockpit_recorder/phase0_evidence';

if ($recordingId <= 0) {
    fwrite(STDERR, "Usage:\n"
        . "  --hallucination-search\n"
        . "  --recording-id=N [--probe-chunk=0] [--persist=0|1] [--persist-fallback=0|1]\n"
        . "  --verify-persistence=UUID\n");
    exit(1);
}

$persistArg = probe_cli_arg('persist', null);
if ($persistArg === null) {
    // Default CLI behavior after migration: typed persistence when schema is ready.
    $persistMode = EvidenceSchema::persistenceReady($pdo) ? 1 : 1;
} else {
    $persistMode = filter_var($persistArg, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}
$persistFallback = probe_cli_bool('persist-fallback', false);

$result = $service->runMandatoryProviderProbe(
    $recordingId,
    $probeChunk,
    true,
    $saveDir,
    $persistMode,
    $persistFallback
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$probeOk = !empty($result['ok']);
$persistOk = true;
if ($persistMode === 1) {
    $persist = is_array($result['persistence'] ?? null) ? $result['persistence'] : array();
    if (($persist['mode'] ?? '') === 'failed_schema_missing' || ($persist['mode'] ?? '') === 'failed') {
        $persistOk = false;
    } elseif (($persist['typed_persistence_succeeded'] ?? false) !== true) {
        $persistOk = false;
    }
}

exit($probeOk && $persistOk ? 0 : 1);
