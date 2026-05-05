<?php
declare(strict_types=1);

/**
 * Merge PHAK chapter JSON files (each file: JSON array of blocks) into one array.
 *
 * Usage:
 *   php scripts/merge_phak_chapter_json.php /path/to/out.json [/path/to/chapters/dir]
 *
 * Default dir: /Users/kayvereeken/Downloads/PHAK JSON
 */

$outPath = $argv[1] ?? '';
if ($outPath === '') {
    fwrite(STDERR, "Usage: php merge_phak_chapter_json.php /path/to/output.json [chapters_dir]\n");
    exit(1);
}

$dir = rtrim($argv[2] ?? '/Users/kayvereeken/Downloads/PHAK JSON', '/');
$names = [
    'chapter_01_introduction_to_flying.json',
    'chapter_02_aeronautical_decision_making.json',
    'chapter_03_aircraft_construction.json',
    'chapter_04_principles_of_flight.json',
    'chapter_05_aerodynamics_of_flight.json',
    'chapter_06_flight_controls.json',
    'chapter_07_aircraft_systems.json',
    'chapter_08_flight_instruments.json',
    'chapter_09_flight_manuals_and_other_documents.json',
    'chapter_10_weight_and_balance.json',
    'chapter_11_aircraft_performance.json',
    'chapter_12_weather_theory.json',
    'chapter_13_aviation_weather_services.json',
    'chapter_14_airport_operations.json',
    'chapter_15_airspace.json',
    'chapter_16_navigation.json',
    'chapter_17_aeromedical_factors.json',
];

$merged = [];
foreach ($names as $name) {
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Could not read: {$path}\n");
        exit(1);
    }
    try {
        $chunk = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "Invalid JSON in {$path}: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (!is_array($chunk)) {
        fwrite(STDERR, "Root must be array in {$path}\n");
        exit(1);
    }
    foreach ($chunk as $row) {
        $merged[] = $row;
    }
    fwrite(STDERR, $name . ': +' . count($chunk) . " blocks\n");
}

$json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

if (file_put_contents($outPath, $json) === false) {
    fwrite(STDERR, "Could not write: {$outPath}\n");
    exit(1);
}

$bytes = strlen($json);
fwrite(STDERR, "Wrote {$outPath} (" . number_format($bytes) . " bytes), " . count($merged) . " total blocks.\n");
