<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/GarminSyncPowerUpAnalysisService.php';

function garmin_activity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
}

/**
 * @param list<array{time:string,rpm:int,ground_speed:float,airspeed:float,lat:float,lon:float}> $rows
 */
function garmin_activity_fixture(array $rows): string
{
    $lines = array(
        '#airframe_info,product="GDU 460",aircraft_ident="N397EA"',
        'Date (yyyy-mm-dd),UTC Time (hh:mm:ss),Latitude (deg),Longitude (deg),GPS Ground Speed (kt),Indicated Airspeed (kt),RPM',
        'Lcl Date,UTC Time,Latitude,Longitude,GndSpd,IAS,E1 RPM',
    );
    foreach ($rows as $row) {
        $lines[] = implode(',', array(
            '2026-08-20',
            $row['time'],
            $row['lat'],
            $row['lon'],
            $row['ground_speed'],
            $row['airspeed'],
            $row['rpm'],
        ));
    }
    $path = tempnam(sys_get_temp_dir(), 'garmin-activity-');
    if ($path === false || file_put_contents($path, implode("\n", $lines) . "\n") === false) {
        throw new RuntimeException('Could not create activity fixture.');
    }
    return $path;
}

$powerRows = array();
for ($second = 0; $second < 10; $second++) {
    $powerRows[] = array(
        'time' => '10:00:' . str_pad((string)$second, 2, '0', STR_PAD_LEFT),
        'rpm' => 0,
        'ground_speed' => $second === 4 ? 29.5 : 0.2,
        'airspeed' => 12.0,
        'lat' => 33.630000 + ($second * 0.000001),
        'lon' => -116.160000,
    );
}
$groundRunRows = array();
$flightRows = array();
for ($index = 0; $index < 6; $index++) {
    $minute = str_pad((string)($index * 30), 2, '0', STR_PAD_LEFT);
    $time = $index < 2
        ? '11:00:' . $minute
        : '11:' . str_pad((string)intdiv($index * 30, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string)(($index * 30) % 60), 2, '0', STR_PAD_LEFT);
    $groundRunRows[] = array(
        'time' => $time,
        'rpm' => 2500,
        'ground_speed' => 8.0,
        'airspeed' => 15.0,
        'lat' => 33.630000,
        'lon' => -116.160000,
    );
    $flightRows[] = array(
        'time' => $time,
        'rpm' => 5200,
        'ground_speed' => 90.0,
        'airspeed' => 80.0,
        'lat' => 33.630000 + ($index * 0.05),
        'lon' => -116.160000,
    );
}

$powerPath = garmin_activity_fixture($powerRows);
$groundRunPath = garmin_activity_fixture($groundRunRows);
$flightPath = garmin_activity_fixture($flightRows);
$service = new GarminSyncPowerUpAnalysisService(new PDO('sqlite::memory:'));

try {
    $power = $service->analyzePath($powerPath);
    garmin_activity_assert(
        ($power['activity_kind'] ?? '') === GarminSyncPowerUpAnalysisService::POWER_UP,
        'stationary no-RPM log is Power-up'
    );
    garmin_activity_assert(
        (float)($power['maximum_ground_speed_kt'] ?? 0) === 29.5,
        'single GPS speed spike is retained as evidence but does not become Flight'
    );

    $groundRun = $service->analyzePath($groundRunPath);
    garmin_activity_assert(
        ($groundRun['activity_kind'] ?? '') === GarminSyncPowerUpAnalysisService::POWER_UP,
        'stationary engine run without flight-speed evidence remains Power-up'
    );

    $flight = $service->analyzePath($flightPath);
    garmin_activity_assert(
        ($flight['activity_kind'] ?? '') === GarminSyncPowerUpAnalysisService::FLIGHT,
        'sustained RPM and flight speed produce Flight'
    );
    garmin_activity_assert(
        (int)($flight['engine_sample_count'] ?? 0) === 6
        && (int)($flight['airborne_sample_count'] ?? 0) === 6,
        'Flight decision records sustained evidence sample counts'
    );
} finally {
    @unlink($powerPath);
    @unlink($groundRunPath);
    @unlink($flightPath);
}

$migration = file_get_contents(__DIR__ . '/../scripts/sql/2026_08_20_ipca_garmin_sync_activity_analysis.sql');
garmin_activity_assert(
    is_string($migration)
    && str_contains($migration, 'ipca_garmin_sync_file_activity_analyses')
    && str_contains($migration, 'activity_kind')
    && str_contains($migration, 'maximum_ground_speed_kt'),
    'activity evidence uses an isolated rebuildable table'
);
