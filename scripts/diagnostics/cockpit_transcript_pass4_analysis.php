<?php
declare(strict_types=1);

/**
 * Run Pass 4A/4B speech-quality and repetition analysis on a processing run.
 *
 *   php scripts/diagnostics/cockpit_transcript_pass4_analysis.php --processing-run-id=5
 *   php scripts/diagnostics/cockpit_transcript_pass4_analysis.php --probe-execution-uuid=UUID
 *   php scripts/diagnostics/cockpit_transcript_pass4_analysis.php --processing-run-id=5 --force
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AviationEvidence/EvidencePass4Runner.php';

function pass4_arg(string $name, ?string $default = null): ?string
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

$processingRunId = (int)(pass4_arg('processing-run-id', '0') ?? '0');
$probeUuid = trim((string)(pass4_arg('probe-execution-uuid', '') ?? ''));
$force = pass4_arg('force', null) !== null;

if ($processingRunId <= 0 && $probeUuid !== '') {
    $stmt = $pdo->prepare(
        'SELECT processing_run_id FROM ipca_evidence_provider_runs WHERE probe_execution_uuid = ? LIMIT 1'
    );
    $stmt->execute(array($probeUuid));
    $processingRunId = (int)$stmt->fetchColumn();
}

if ($processingRunId <= 0) {
    fwrite(STDERR, "Usage: --processing-run-id=N or --probe-execution-uuid=UUID [--force]\n");
    exit(1);
}

try {
    $runner = EvidencePass4Runner::fromPdo($pdo);
    $result = $runner->runForProcessingRun($processingRunId, $force);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
