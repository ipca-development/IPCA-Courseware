<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_adsb_status.php';
require_once __DIR__ . '/../../../src/tv_radar_weather.php';
require_once __DIR__ . '/../../../src/tv_kiosk_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$kiosk = tv_kiosk_config();
$homeAirport = tv_adsb_normalize_home_airport((string)($_GET['home_airport'] ?? ($kiosk['home_airport'] ?? 'KTRM')));
$airports = tv_adsb_airports();
$airport = $airports[$homeAirport] ?? $airports['KTRM'];
$centerLat = (float)$airport['lat'];
$centerLon = (float)$airport['lon'];
$rangeNm = max(1.0, min(10.0, (float)($_GET['range_nm'] ?? 5.0)));
$fieldElevFt = (float)($airport['elev_ft'] ?? 115.0);

$targets = array();
$adsbOk = false;
$adsbError = null;
$adsbLive = false;

try {
    $targets = tv_adsb_fetch_radar_targets($centerLat, $centerLon, $rangeNm);
    $adsbOk = true;
    $adsbLive = count($targets) > 0;
} catch (Throwable $e) {
    $adsbError = $e->getMessage();
}

$weather = tv_radar_weather_build($homeAirport, $fieldElevFt);

$response = array(
    'ok' => true,
    'screen_key' => 'radar',
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
    ),
    'targets' => $targets,
    'weather' => $weather,
    'server_time' => gmdate('c'),
);

if (!empty($_GET['debug'])) {
    $response['debug'] = array(
        'provider' => tv_adsb_provider(),
        'weather_source' => (string)($weather['source'] ?? ''),
        'weather_url' => tv_radar_weather_station_url() !== '' ? 'custom' : 'aviationweather.gov/metar',
    );
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
