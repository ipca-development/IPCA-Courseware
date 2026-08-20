<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/GarminSyncFileClassificationService.php';

function garmin_classification_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
}

function garmin_classification_fixture(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'garmin-classification-');
    if ($path === false || file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not create classification fixture.');
    }
    return $path;
}

$pdo = new PDO('sqlite::memory:');
$service = new GarminSyncFileClassificationService($pdo, dirname(__DIR__));

$validPath = garmin_classification_fixture(implode("\n", array(
    '#airframe_info,log_version="1.00",product="GDU 460",aircraft_ident="N397EA",system_id="ABC123"',
    'Date (yyyy-mm-dd),UTC Time (hh:mm:ss),Latitude (deg),Longitude (deg),GPS Ground Speed (kt)',
    'Lcl Date,UTC Time,Latitude,Longitude,GndSpd',
    '2026-08-20,16:01:02,33.63,-116.16,75',
)) . "\n");
$junkPath = garmin_classification_fixture(implode("\n", array(
    'Name,Latitude,Longitude',
    'Waypoint,33.63,-116.16',
)) . "\n");
$emptyLogPath = garmin_classification_fixture(implode("\n", array(
    '#airframe_info,product="GDU 460",aircraft_ident="N428EA"',
    'Date (yyyy-mm-dd),UTC Time (hh:mm:ss),Latitude (deg),Longitude (deg)',
    'Lcl Date,UTC Time,Latitude,Longitude',
    ',,,,',
)) . "\n");

try {
    $valid = $service->inspectPath($validPath, 'flight.csv');
    garmin_classification_assert(
        ($valid['source_kind'] ?? '') === GarminSyncFileClassificationService::FLIGHT_CSV,
        'valid Garmin flight CSV is analysis eligible'
    );
    garmin_classification_assert(
        ($valid['aircraft_registration'] ?? '') === 'N397EA',
        'aircraft registration comes from airframe metadata'
    );
    garmin_classification_assert(
        ($valid['system_identifier'] ?? '') === 'ABC123',
        'system identifier is retained as supporting evidence'
    );

    $junk = $service->inspectPath($junkPath, 'waypoints.csv');
    garmin_classification_assert(
        ($junk['source_kind'] ?? '') === GarminSyncFileClassificationService::UNSUPPORTED_CSV
        && empty($junk['analysis_eligible']),
        'GPS waypoint CSV without Garmin airframe metadata is junk'
    );

    $nonCsv = $service->inspectPath($validPath, 'garmin.img');
    garmin_classification_assert(
        ($nonCsv['source_kind'] ?? '') === GarminSyncFileClassificationService::NON_CSV,
        'non-CSV Garmin file is excluded before analysis'
    );

    $emptyLog = $service->inspectPath($emptyLogPath, 'empty.csv');
    garmin_classification_assert(
        ($emptyLog['source_kind'] ?? '') === GarminSyncFileClassificationService::UNSUPPORTED_CSV,
        'Garmin-shaped CSV without a usable sample is excluded'
    );
} finally {
    @unlink($validPath);
    @unlink($junkPath);
    @unlink($emptyLogPath);
}

$migration = file_get_contents(__DIR__ . '/../scripts/sql/2026_08_20_ipca_garmin_sync_file_classification.sql');
garmin_classification_assert(
    is_string($migration)
    && str_contains($migration, 'ipca_garmin_sync_file_classifications')
    && str_contains($migration, 'aircraft_registration')
    && str_contains($migration, 'archive_file_id'),
    'classification schema is isolated and tied to immutable archive identity'
);
