<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();

if (trim((string)getenv('CW_SPACES_KEY')) === '') {
    $pool = '/etc/php/8.3/fpm/pool.d/www.conf';
    if (is_readable($pool)) {
        $lines = file($pool, FILE_IGNORE_NEW_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (!preg_match('/^env\[(CW_SPACES_[A-Z0-9_]+)\]\s*=\s*(.*)$/', trim($line), $m)) {
                    continue;
                }
                $key = $m[1];
                $val = trim($m[2], " \t\"'");
                if ($val === '' || getenv($key)) {
                    continue;
                }
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }
}

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/communication/CommunicationKernel.php';

@set_time_limit(0);

$store = CommunicationSpacesObjectStore::tryFromEnvironment();
if ($store === null) {
    fwrite(STDERR, "Spaces is not configured; refusing to redraw posters into memory.\n");
    exit(1);
}

$pdo = cw_db();
$kernel = new CommunicationKernel($pdo, $store);
$result = $kernel->trainingVideos->regenerateGeneratedThumbnails();

echo 'regenerated=' . (int)$result['regenerated'] . PHP_EOL;
echo 'skipped=' . (int)$result['skipped'] . PHP_EOL;
echo 'ok' . PHP_EOL;
