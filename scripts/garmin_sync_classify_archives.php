<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/GarminSyncFileClassificationService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$reclassify = in_array('--all', $argv, true);
$limit = 500;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', (string)$argument, $match) === 1) {
        $limit = max(1, min(5000, (int)$match[1]));
    }
}

$summary = (new GarminSyncFileClassificationService(cw_db()))
    ->classifyPending($limit, $reclassify);

echo json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
), PHP_EOL;
