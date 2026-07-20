<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'archive' => $root . '/src/AdsbTrafficArchiveService.php',
    'live_cli' => $root . '/scripts/cockpit_adsb_live_archive.php',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$checks = array(
    'live recorder schedules configurable point coverage into existing archive jobs' =>
        str_contains($files['archive'], 'scheduleRecentLivePointCoverage')
        && str_contains($files['archive'], 'scheduleRecentLiveTargetCoverage')
        && str_contains($files['archive'], 'scheduleLivePointSnapshotCoverage')
        && str_contains($files['archive'], 'scheduleLiveTargetSnapshotCoverage')
        && str_contains($files['archive'], 'ipca_adsb_coverage_jobs')
        && str_contains($files['archive'], 'createTilesForJob')
        && str_contains($files['archive'], "'live_adsb_recorder'"),
    'home airport uses high-resolution live snapshot capture' =>
        str_contains($files['archive'], 'HOME_LIVE_RESOLUTION_SECONDS = 10')
        && str_contains($files['archive'], "'priority' => 'home'")
        && str_contains($files['archive'], "'resolution_seconds' => self::HOME_LIVE_RESOLUTION_SECONDS")
        && str_contains($files['archive'], 'targetIsHighResolution')
        && str_contains($files['archive'], "return (string)(\$target['priority'] ?? '') === 'home'")
        && str_contains($files['live_cli'], "'mode' => 'home_high_resolution'")
        && str_contains($files['live_cli'], 'CW_ADSB_HOME_LIVE_INTERVAL_SECONDS')
        && str_contains($files['live_cli'], 'CW_ADSB_HOME_LIVE_CYCLES'),
    'live recorder remains bounded to ADS-B Exchange near-point capability' =>
        str_contains($files['archive'], 'min(25.0, $radiusNm)')
        && str_contains($files['live_cli'], 'CW_ADSB_LIVE_RADIUS_NM')
        && str_contains($files['live_cli'], 'CW_ADSB_LIVE_BUCKET_SECONDS'),
    'live CLI supports cron and daemon use cases' =>
        str_contains($files['live_cli'], "schedule-live")
        && str_contains($files['live_cli'], "schedule-live all")
        && str_contains($files['live_cli'], "fetch-next")
        && str_contains($files['live_cli'], "run-once")
        && str_contains($files['live_cli'], "run-snapshot")
        && str_contains($files['live_cli'], "loop")
        && str_contains($files['live_cli'], 'scheduleRecentLiveTargetCoverage'),
    'live CLI does not create a parallel ADS-B archive' =>
        !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE/i', $files['live_cli'])
        && !str_contains($files['live_cli'], 'OpenSky')
        && str_contains($files['live_cli'], 'AdsbTrafficArchiveService'),
    'live ingestion keeps truthful historical safeguards' =>
        str_contains($files['archive'], 'refusing to fill historical archive bucket with a live snapshot')
        && str_contains($files['archive'], 'markHistoricalTilesProviderNotConfigured')
        && str_contains($files['archive'], 'LIVE_SNAPSHOT_GRACE_SECONDS'),
    'live ingestion preserves provider observation age for accurate replay timing' =>
        str_contains($files['archive'], 'fetched_at_utc')
        && str_contains($files['archive'], 'providerSeenAgeSeconds')
        && str_contains($files['archive'], "'seen_pos'")
        && str_contains($files['archive'], "'seen'")
        && str_contains($files['archive'], 'providerObservedTime')
        && str_contains($files['archive'], 'provider_seen_age_seconds'),
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
