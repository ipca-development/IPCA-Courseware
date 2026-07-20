<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'archive' => $root . '/src/AdsbTrafficArchiveService.php',
    'page' => $root . '/public/admin/adsb_traffic_archive.php',
    'api' => $root . '/public/admin/api/adsb_archive_dashboard.php',
    'action' => $root . '/public/admin/api/adsb_archive_action.php',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$checks = array(
    'dashboard API is admin-gated and read-only JSON' =>
        str_contains($files['api'], 'cw_require_admin()')
        && str_contains($files['api'], "header('Content-Type: application/json")
        && str_contains($files['api'], 'dashboardData')
        && !preg_match('/INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE/i', $files['api']),
    'archive service exposes growth stats, targets, and target timeline' =>
        str_contains($files['archive'], 'dashboardData')
        && str_contains($files['archive'], 'archiveGrowthStats')
        && str_contains($files['archive'], 'archiveTargets')
        && str_contains($files['archive'], 'targetTimeline'),
    'target discovery uses geographic definitions with KTRM fallback' =>
        str_contains($files['archive'], 'ipca_adsb_geographic_definitions')
        && str_contains($files['archive'], "'id' => 'ktrm_live'")
        && str_contains($files['archive'], "'source' => 'default'"),
    'admin can create point-radius live target definitions' =>
        str_contains($files['archive'], 'createLivePointTarget')
        && str_contains($files['action'], 'create_live_target')
        && str_contains($files['page'], 'Add Target Airport')
        && str_contains($files['page'], 'target_radius_nm'),
    'dashboard queries local archive tables only' =>
        str_contains($files['archive'], 'ipca_adsb_traffic_samples')
        && str_contains($files['archive'], 'ipca_adsb_raw_payloads')
        && !str_contains($files['archive'], 'OpenSkyTrinoClient')
        && !str_contains($files['archive'], 'tv_adsb_request('),
    'admin UI includes realtime growth and target map scrubber controls' =>
        str_contains($files['page'], 'Realtime Archive Growth')
        && str_contains($files['page'], 'adsbGrowthChart')
        && str_contains($files['page'], 'adsbRecordingStatus')
        && str_contains($files['page'], 'adsbTargetMap')
        && str_contains($files['page'], 'adsbTargetMaps')
        && str_contains($files['page'], 'adsbMapStatus')
        && str_contains($files['page'], 'adsbTimeline')
        && str_contains($files['page'], 'adsbNewestButton')
        && str_contains($files['page'], 'adsb_archive_dashboard.php'),
    'admin UI renders Leaflet map without provider-side calls' =>
        str_contains($files['page'], 'leaflet@1.9.4')
        && str_contains($files['page'], 'L.map')
        && !str_contains($files['page'], 'tv_adsb_request(')
        && !str_contains($files['page'], 'OpenSky'),
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
