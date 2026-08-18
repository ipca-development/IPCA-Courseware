<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/BooksManualsChangeAssistantJobService.php';

$once = false;
$drain = false;
$projectId = null;
foreach (array_slice($argv ?? array(), 1) as $argument) {
    if ($argument === '--once') {
        $once = true;
    } elseif ($argument === '--drain') {
        $drain = true;
    } elseif (str_starts_with($argument, '--project-id=')) {
        $projectId = (int)substr($argument, strlen('--project-id='));
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}
if (!$once && !$drain) {
    fwrite(STDERR, "Usage: php scripts/books_manuals_change_assistant_worker.php --once|--drain [--project-id=N]\n");
    exit(2);
}
if ($projectId !== null && $projectId <= 0) {
    fwrite(STDERR, "--project-id must be a positive integer.\n");
    exit(2);
}

$service = new BooksManualsChangeAssistantJobService(cw_db());
$processed = 0;
do {
    $result = $service->processOne($projectId);
    if (!($result['processed'] ?? false)) {
        break;
    }
    $processed++;
    echo 'job=' . (int)($result['job_id'] ?? 0)
        . ' status=' . (string)($result['status'] ?? 'unknown') . PHP_EOL;
    if (($result['status'] ?? '') === 'failed') {
        fwrite(STDERR, (string)($result['error'] ?? 'Analysis failed.') . PHP_EOL);
        if ($once) {
            exit(1);
        }
    }
} while ($drain);

echo "processed={$processed}\n";
exit(0);
