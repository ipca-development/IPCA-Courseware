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
        && str_contains($files['archive'], 'ipca_adsb_coverage_jobs')
        && str_contains($files['archive'], 'createTilesForJob')
        && str_contains($files['archive'], "'live_adsb_recorder'"),
    'live recorder remains bounded to ADS-B Exchange near-point capability' =>
        str_contains($files['archive'], 'min(25.0, $radiusNm)')
        && str_contains($files['live_cli'], 'CW_ADSB_LIVE_RADIUS_NM')
        && str_contains($files['live_cli'], 'CW_ADSB_LIVE_BUCKET_SECONDS'),
    'live CLI supports cron and daemon use cases' =>
        str_contains($files['live_cli'], "schedule-live")
        && str_contains($files['live_cli'], "fetch-next")
        && str_contains($files['live_cli'], "run-once")
        && str_contains($files['live_cli'], "loop"),
    'live CLI does not create a parallel ADS-B archive' =>
        !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE/i', $files['live_cli'])
        && !str_contains($files['live_cli'], 'OpenSky')
        && str_contains($files['live_cli'], 'AdsbTrafficArchiveService'),
    'live ingestion keeps truthful historical safeguards' =>
        str_contains($files['archive'], 'refusing to fill historical archive bucket with a live snapshot')
        && str_contains($files['archive'], 'markHistoricalTilesProviderNotConfigured')
        && str_contains($files['archive'], 'LIVE_SNAPSHOT_GRACE_SECONDS'),
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
