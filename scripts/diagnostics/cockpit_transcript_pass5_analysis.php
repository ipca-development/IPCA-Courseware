<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AviationEvidence/EvidencePass5Runner.php';
require_once __DIR__ . '/../../src/AviationEvidence/ProcessingRunRepository.php';

$processingRunId = (int)($argv[1] ?? 0);
$force = in_array('--force', $argv, true);

if ($processingRunId <= 0) {
    fwrite(STDERR, "Usage: php scripts/diagnostics/cockpit_transcript_pass5_analysis.php <processing_run_id> [--force]\n");
    exit(1);
}

try {
    $result = EvidencePass5Runner::fromPdo($pdo)->runForProcessingRun($processingRunId, $force);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
