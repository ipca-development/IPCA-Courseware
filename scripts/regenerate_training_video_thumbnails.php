<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/communication/CommunicationKernel.php';

@set_time_limit(0);

$pdo = cw_db();
$kernel = new CommunicationKernel($pdo);
$result = $kernel->trainingVideos->regenerateGeneratedThumbnails();

echo 'regenerated=' . (int)$result['regenerated'] . PHP_EOL;
echo 'skipped=' . (int)$result['skipped'] . PHP_EOL;
echo 'ok' . PHP_EOL;
