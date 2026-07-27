#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/MissionCatalogService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$path = (string)($argv[1] ?? '');
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php scripts/import_mission_catalog.php /path/to/mission_catalogue_SPC.csv\n");
    exit(1);
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Could not open CSV: {$path}\n");
    exit(1);
}

$service = new MissionCatalogService($pdo);
$imported = 0;
$skipped = 0;
$headerSeen = false;

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) === 1 && strtolower(trim((string)$row[0])) === 'scenarios') {
        continue;
    }
    if (!$headerSeen) {
        $headerSeen = isset($row[0]) && strtolower(trim((string)$row[0])) === 'program';
        continue;
    }
    if (count($row) < 6) {
        $skipped++;
        continue;
    }

    $program = (int)$row[0];
    $stage = (int)$row[1];
    $phase = (int)$row[2];
    $scenario = (int)$row[3];
    $code = strtoupper(trim((string)$row[4]));
    $description = trim((string)$row[5]);
    if ($code === '' || $description === '') {
        $skipped++;
        continue;
    }

    $service->upsertMission($code, $description, $description, array(
        'program' => $program,
        'stage' => $stage,
        'phase' => $phase,
        'scenario' => $scenario,
        'source' => 'mission_catalogue_SPC.csv',
    ));
    $imported++;
}

fclose($handle);

echo "Imported {$imported} missions";
if ($skipped > 0) {
    echo " ({$skipped} skipped)";
}
echo "\n";
