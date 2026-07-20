<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'service' => $root . '/src/AirportLookupService.php',
    'api' => $root . '/public/admin/api/airport_search.php',
    'page' => $root . '/public/admin/adsb_traffic_archive.php',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$checks = array(
    'airport lookup uses live OurAirports data with local cache' =>
        str_contains($files['service'], 'OURAIRPORTS_URL')
        && str_contains($files['service'], 'ourairports-data/airports.csv')
        && str_contains($files['service'], 'CACHE_TTL_SECONDS')
        && str_contains($files['service'], 'fallbackAirports'),
    'airport lookup parses ICAO/IATA/name/city/coordinates' =>
        str_contains($files['service'], 'gps_code')
        && str_contains($files['service'], 'iata_code')
        && str_contains($files['service'], 'municipality')
        && str_contains($files['service'], 'latitude_deg')
        && str_contains($files['service'], 'longitude_deg'),
    'airport search API is admin gated JSON' =>
        str_contains($files['api'], 'cw_require_admin()')
        && str_contains($files['api'], 'AirportLookupService')
        && str_contains($files['api'], "header('Content-Type: application/json"),
    'ADS-B target UI searches airports and autofills coordinates' =>
        str_contains($files['page'], 'adsbAirportSearch')
        && str_contains($files['page'], 'airport_search.php')
        && str_contains($files['page'], 'fillTargetFromAirport')
        && str_contains($files['page'], 'adsbTargetLatInput')
        && str_contains($files['page'], 'adsbTargetLonInput'),
);

$failed = array();
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$pass) {
        $failed[] = $name;
    }
}

if ($failed !== array()) {
    fwrite(STDERR, "\nFailed checks:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
