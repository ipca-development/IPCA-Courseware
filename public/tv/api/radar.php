<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_adsb_status.php';
require_once __DIR__ . '/../../../src/tv_radar_weather.php';
require_once __DIR__ . '/../../../src/tv_kiosk_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$kiosk = tv_kiosk_config();
$gate = array(
    'label' => (string)($kiosk['gate_label'] ?? tv_adsb_default_gate()['label']),
    'lat' => (float)($kiosk['gate_lat'] ?? tv_adsb_default_gate()['lat']),
    'lon' => (float)($kiosk['gate_lon'] ?? tv_adsb_default_gate()['lon']),
    'radius_nm' => (float)($kiosk['gate_radius_nm'] ?? tv_adsb_default_gate()['radius_nm']),
    'assume_parked_off_radar' => (int)($kiosk['assume_parked_off_radar'] ?? 1),
);
if (isset($_GET['gate_lat'], $_GET['gate_lon'])) {
    $gate['lat'] = (float)$_GET['gate_lat'];
    $gate['lon'] = (float)$_GET['gate_lon'];
}
if (isset($_GET['gate_radius_nm'])) {
    $gate['radius_nm'] = max(0.05, min(2.0, (float)$_GET['gate_radius_nm']));
}

$homeAirport = tv_adsb_normalize_home_airport((string)($_GET['home_airport'] ?? ($kiosk['home_airport'] ?? 'KTRM')));
$screenKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['screen_key'] ?? 'aircraft')) ?: 'aircraft';
$airports = tv_adsb_airports();
$airport = $airports[$homeAirport] ?? $airports['KTRM'];
$centerLat = (float)$airport['lat'];
$centerLon = (float)$airport['lon'];
$rangeNm = max(0.5, min(10.0, (float)($_GET['range_nm'] ?? 2.5)));
$fieldElevFt = (float)($airport['elev_ft'] ?? 115.0);

$targets = array();
$adsbOk = false;
$adsbError = null;
$adsbLive = false;
$trackCount = 0;
$fleetTargets = array();
$areaTargets = array();

$areaMeta = array(
    'connected' => false,
    'raw_count' => 0,
    'in_range_count' => 0,
    'error' => null,
    'path' => null,
    'targets' => array(),
);

try {
    $tracks = tv_adsb_fetch_active_fleet_tracks($pdo, $screenKey, 25);

    $trackCount = count($tracks);
    $fleetTargets = tv_adsb_fetch_radar_targets($tracks, $centerLat, $centerLon, $rangeNm, array(
        'gate' => $gate,
        'home_airport' => $homeAirport,
    ));
    $areaMeta = tv_adsb_fetch_area_radar_targets_meta($centerLat, $centerLon, $rangeNm);
    $areaTargets = $areaMeta['targets'];
    $targets = tv_adsb_merge_radar_targets($fleetTargets, $areaTargets);
    $adsbOk = true;
    foreach ($targets as $target) {
        if (!empty($target['live'])) {
            $adsbLive = true;
            break;
        }
    }
    if (!$adsbLive && count($targets) > 0) {
        $adsbLive = true;
    }
} catch (Throwable $e) {
    $adsbError = $e->getMessage();
}

$weather = tv_radar_weather_build($homeAirport, $fieldElevFt);

$response = array(
    'ok' => true,
    'screen_key' => $screenKey,
    'center' => array(
        'icao' => $homeAirport,
        'name' => (string)($airport['name'] ?? $homeAirport),
        'lat' => $centerLat,
        'lon' => $centerLon,
        'elev_ft' => $fieldElevFt,
    ),
    'range_nm' => $rangeNm,
    'adsb' => array(
        'ok' => $adsbOk,
        'live' => $adsbLive,
        'error' => $adsbError,
        'count' => count($targets),
        'tracks' => $trackCount,
        'fleet_configured' => $trackCount,
        'fleet_count' => isset($fleetTargets) ? count($fleetTargets) : 0,
        'area_count' => isset($areaTargets) ? count($areaTargets) : 0,
        'area_connected' => (bool)($areaMeta['connected'] ?? false),
        'area_raw_count' => (int)($areaMeta['raw_count'] ?? 0),
        'area_in_range_count' => (int)($areaMeta['in_range_count'] ?? 0),
        'area_error' => $areaMeta['error'] ?? null,
        'area_path' => $areaMeta['path'] ?? null,
        'source' => 'fleet_and_area',
    ),
    'targets' => $targets,
    'weather' => $weather,
    'server_time' => gmdate('c'),
);

if (!empty($_GET['debug'])) {
    $response['debug'] = array(
        'provider' => tv_adsb_provider(),
        'weather_source' => (string)($weather['source'] ?? ''),
        'weather_station' => (string)($weather['station'] ?? ''),
        'visibility_source' => (string)($weather['visibility_source'] ?? ''),
        'forecast_wind_dir_deg' => $weather['forecast_wind_dir_deg'] ?? null,
        'adsb_method' => 'fleet tv_adsb_build_status + live area /lat/lon/dist/',
        'fleet_count' => isset($fleetTargets) ? count($fleetTargets) : 0,
        'area_count' => isset($areaTargets) ? count($areaTargets) : 0,
        'area_connected' => (bool)($areaMeta['connected'] ?? false),
        'area_raw_count' => (int)($areaMeta['raw_count'] ?? 0),
        'area_error' => $areaMeta['error'] ?? null,
        'area_path' => $areaMeta['path'] ?? null,
    );
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
