#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrOperationalLegTimelineService.php';

$legs = CvrOperationalLegTimelineService::apply(array(
    array(
        'off_block_utc' => '2026-08-11 15:05:39',
        'on_block_utc' => '2026-08-11 15:55:45',
        'starting_hobbs' => 866.8,
        'ending_hobbs' => 867.6,
    ),
    array(
        'off_block_utc' => '2026-08-11 15:55:45',
        'on_block_utc' => '2026-08-11 17:12:27',
        'starting_hobbs' => 867.6,
        'ending_hobbs' => 868.9,
    ),
    array(
        'off_block_utc' => '2026-08-11 17:12:27',
        'on_block_utc' => '2026-08-11 17:50:41',
        'starting_hobbs' => 868.9,
        'ending_hobbs' => 869.5,
    ),
));

$service = file_get_contents(__DIR__ . '/../src/CvrOperationalSessionLegReviewService.php') ?: '';
$adminCorrection = file_get_contents(__DIR__ . '/../src/CvrAdminLegCorrectionService.php') ?: '';
$ios = file_get_contents(__DIR__ . '/../ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$checks = array(
    'Hobbs duration always rounds upward to the next tenth' =>
        CvrOperationalLegTimelineService::roundUpToTenth(0.8) === 0.8
        && CvrOperationalLegTimelineService::roundUpToTenth(0.801) === 0.9
        && CvrOperationalLegTimelineService::roundUpToTenth(1.234) === 1.3,
    'first leg uses 0.8 Hobbs as exactly 48 minutes' =>
        ($legs[0]['off_block_utc'] ?? '') === '2026-08-11 15:05:39'
        && ($legs[0]['on_block_utc'] ?? '') === '2026-08-11 15:53:39',
    'second leg starts at prior arrival and uses 1.3 Hobbs as exactly 78 minutes' =>
        ($legs[1]['off_block_utc'] ?? '') === '2026-08-11 15:53:39'
        && ($legs[1]['on_block_utc'] ?? '') === '2026-08-11 17:11:39',
    'third leg starts at prior arrival and uses 0.6 Hobbs as exactly 36 minutes' =>
        ($legs[2]['off_block_utc'] ?? '') === '2026-08-11 17:11:39'
        && ($legs[2]['on_block_utc'] ?? '') === '2026-08-11 17:47:39',
    'pilot-entered terminal Hobbs remains authoritative' =>
        ($legs[0]['starting_hobbs'] ?? null) === 866.8
        && ($legs[2]['ending_hobbs'] ?? null) === 869.5
        && array_sum(array_column($legs, 'hobbs_delta')) === 2.7,
    'original evidence timestamps remain explicit provenance' =>
        ($legs[0]['evidence_on_block_utc'] ?? '') === '2026-08-11 15:55:45'
        && ($legs[2]['evidence_on_block_utc'] ?? '') === '2026-08-11 17:50:41',
    'accepted and repaired reviews use the same Hobbs timeline model' =>
        substr_count($service, 'CvrOperationalLegTimelineService::apply') >= 3
        && substr_count($service, 'CvrOperationalLegTimelineService::roundUpToTenth') >= 4
        && str_contains($service, 'operational_leg_timeline_model')
        && str_contains($service, 'CvrOperationalLegTimelineService::MODEL'),
    'admin meter correction recalculates the annotated timeline' =>
        str_contains($adminCorrection, 'CvrOperationalLegTimelineService::apply')
        && str_contains($adminCorrection, 'function meterTenth')
        && str_contains($adminCorrection, 'CvrOperationalLegTimelineService::roundUpToTenth'),
    'iOS never stores interpolated or edited meter values beyond one decimal' =>
        str_contains($ios, 'func interpolateMeter')
        && str_contains($ios, 'roundedUpTenth(interpolate(start, end, at: timestamp))')
        && str_contains($ios, 'legs[index][keyPath: keyPath] = roundedUpTenth(number)'),
);

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== array()) {
    fwrite(STDERR, "Operational leg timeline contract FAILED\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}
echo 'Operational leg timeline contract passed (' . count($checks) . " checks).\n";
