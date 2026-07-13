<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/GarminFlightDataSourceClassificationService.php';

$root = __DIR__ . '/fixtures/garmin';
$classifier = new GarminFlightDataSourceClassificationService();

$gps = $classifier->classifyPath($root . '/gps_only.csv');
$full = $classifier->classifyPath($root . '/full_avionics.csv');

assert_same('GPS_ONLY', $gps['data_log_type'], 'GPS-only fixture type');
assert_same(true, $gps['capabilities']['supports_gps_replay'], 'GPS-only supports GPS replay');
assert_same(false, $gps['capabilities']['supports_hobbs_calculation'], 'GPS-only is not Hobbs capable');
assert_same(false, $gps['capabilities']['supports_tacho_calculation'], 'GPS-only is not Tacho capable');
assert_same(false, $gps['capabilities']['supports_operational_flight_record'], 'GPS-only is not operational-record capable');

assert_same('FULL_AVIONICS', $full['data_log_type'], 'Full avionics fixture type');
assert_same(true, $full['capabilities']['supports_operational_flight_record'], 'Full avionics supports operational record');
assert_same(true, $full['capabilities']['supports_hobbs_calculation'], 'Full avionics supports Hobbs calculation');
assert_same(true, $full['capabilities']['supports_tacho_calculation'], 'Full avionics supports Tacho calculation');

echo "Garmin classification regression passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label} failed: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}
