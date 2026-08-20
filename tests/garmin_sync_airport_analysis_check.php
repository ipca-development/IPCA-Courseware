<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/GarminSyncAirportAnalysisService.php';

function garmin_airport_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
}

/**
 * @param list<array{lat:float,lon:float}> $positions
 */
function garmin_airport_fixture(array $positions): string
{
    $lines = array(
        '#airframe_info,product="GDU 460",aircraft_ident="N397EA"',
        'Date (yyyy-mm-dd),UTC Time (hh:mm:ss),Latitude (deg),Longitude (deg),GPS Ground Speed (kt),Indicated Airspeed (kt),RPM',
        'Lcl Date,UTC Time,Latitude,Longitude,GndSpd,IAS,E1 RPM',
    );
    $base = strtotime('2026-08-20 10:00:00 UTC');
    foreach ($positions as $index => $position) {
        $lines[] = implode(',', array(
            '2026-08-20',
            gmdate('H:i:s', $base + $index),
            $position['lat'],
            $position['lon'],
            90,
            80,
            5200,
        ));
    }
    $path = tempnam(sys_get_temp_dir(), 'garmin-airports-');
    if ($path === false || file_put_contents($path, implode("\n", $lines) . "\n") === false) {
        throw new RuntimeException('Could not create airport fixture.');
    }
    return $path;
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec(
    'CREATE TABLE ipca_airports (
       icao_identifier TEXT NOT NULL,
       full_name TEXT NOT NULL,
       latitude_deg REAL NOT NULL,
       longitude_deg REAL NOT NULL
     )'
);
$insert = $pdo->prepare(
    'INSERT INTO ipca_airports
      (icao_identifier, full_name, latitude_deg, longitude_deg)
     VALUES (?, ?, ?, ?)'
);
$insert->execute(array('KTRM', 'Jacqueline Cochran Regional', 33.6266706, -116.1596544));
$insert->execute(array('KPSP', 'Palm Springs International', 33.8296697, -116.5066942));

$positions = array();
for ($index = 0; $index < 60; $index++) {
    $positions[] = array(
        'lat' => 33.6266706 + (($index % 3) * 0.00001),
        'lon' => -116.1596544,
    );
}
for ($index = 0; $index < 60; $index++) {
    $positions[] = array(
        'lat' => 33.8296697,
        'lon' => -116.5066942 + (($index % 3) * 0.00001),
    );
}
$flightPath = garmin_airport_fixture($positions);
$unknownPath = garmin_airport_fixture(array_fill(0, 120, array('lat' => 32.0, 'lon' => -130.0)));
$service = new GarminSyncAirportAnalysisService($pdo);

try {
    $airports = $service->analyzePath($flightPath);
    garmin_airport_assert(
        ($airports['departure_airport_code'] ?? '') === 'KTRM'
        && ($airports['arrival_airport_code'] ?? '') === 'KPSP',
        'median Garmin endpoints resolve against seeded airport coordinates'
    );
    garmin_airport_assert(
        ($airports['derivation_status'] ?? '') === 'COMPLETE'
        && (float)($airports['confidence'] ?? 0) >= 0.9,
        'close endpoint matches are complete with high confidence'
    );

    $unknown = $service->analyzePath($unknownPath);
    garmin_airport_assert(
        ($unknown['derivation_status'] ?? '') === 'UNKNOWN'
        && ($unknown['departure_airport_code'] ?? null) === null
        && ($unknown['arrival_airport_code'] ?? null) === null,
        'endpoints beyond 12 NM remain unknown instead of being guessed'
    );
} finally {
    @unlink($flightPath);
    @unlink($unknownPath);
}

$migration = file_get_contents(__DIR__ . '/../scripts/sql/2026_08_20_ipca_garmin_sync_airport_analysis.sql');
garmin_airport_assert(
    is_string($migration)
    && str_contains($migration, 'ipca_garmin_sync_file_airport_analyses')
    && str_contains($migration, 'departure_airport_code')
    && str_contains($migration, 'arrival_airport_code'),
    'airport evidence uses an isolated rebuildable table'
);
