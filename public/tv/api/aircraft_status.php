<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_adsb_status.php';
require_once __DIR__ . '/../../../src/tv_kiosk_config.php';
require_once __DIR__ . '/../../../src/tv_screen_pa.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$track = tv_adsb_resolve_track(array(
    'hex' => (string)($_GET['hex'] ?? ($_GET['aircraft_hex'] ?? '')),
    'label' => (string)($_GET['label'] ?? ($_GET['aircraft_label'] ?? ($_GET['registration'] ?? ''))),
    'type' => (string)($_GET['type'] ?? ($_GET['aircraft_type'] ?? '')),
    'home_airport' => (string)($_GET['home_airport'] ?? ($_GET['aircraft_home_airport'] ?? '')),
    'body' => (string)($_GET['body'] ?? ''),
    'title' => (string)($_GET['title'] ?? ''),
));

if ($track['hex'] === '' && $track['label'] === '') {
    http_response_code(400);
    echo json_encode(array(
        'ok' => false,
        'error' => 'hex or label is required',
    ), JSON_UNESCAPED_SLASHES);
    exit;
}

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
if (!empty($_GET['gate_label'])) {
    $gate['label'] = trim((string)$_GET['gate_label']);
}

$homeAirport = tv_adsb_normalize_home_airport((string)($_GET['home_airport'] ?? ($_GET['aircraft_home_airport'] ?? ($kiosk['home_airport'] ?? ''))));
$announceEnabled = (int)($kiosk['audio_enabled'] ?? 1) === 1
    && (int)($_GET['announce_audio_enabled'] ?? 0) === 1;
$voice = tv_pa_voice_or_default((string)($_GET['voice'] ?? ($kiosk['default_pa_voice'] ?? '')));

try {
    $status = tv_adsb_build_status($track, array(
        'gate' => $gate,
        'home_airport' => $homeAirport,
        'announce_audio_enabled' => $announceEnabled,
    ));

    $response = array(
        'ok' => true,
        'hex' => (string)($status['hex'] ?? $track['hex']),
        'label' => (string)($status['label'] ?? $track['label']),
        'home_airport' => (string)($status['home_airport'] ?? $homeAirport),
        'display' => (string)($status['display'] ?? ''),
        'symbol' => (string)($status['symbol'] ?? ''),
        'aircraft_display' => (string)($status['aircraft_display'] ?? ''),
        'type_display' => (string)($status['type_display'] ?? ''),
        'status_text' => (string)($status['status_text'] ?? ''),
        'status_code' => (string)($status['status_code'] ?? ''),
        'status_label' => (string)($status['status_label'] ?? ''),
        'live' => (bool)($status['live'] ?? false),
        'stale' => (bool)($status['stale'] ?? false),
        'source' => (string)($status['source'] ?? ''),
        'provider' => tv_adsb_provider(),
        'ground_speed_kt' => $status['ground_speed_kt'] ?? null,
        'altitude_ft' => $status['altitude_ft'] ?? null,
        'distance_nm' => $status['distance_nm'] ?? null,
        'direction' => $status['direction'] ?? null,
        'nearest_airport' => $status['nearest_airport'] ?? null,
        'server_time' => (string)($status['server_time'] ?? gmdate('c')),
    );

    if ($announceEnabled && !empty($status['announcement']) && is_array($status['announcement'])) {
        $response['announcement'] = array(
            'speech' => (string)($status['announcement']['speech'] ?? ''),
            'status_code' => (string)($status['announcement']['status_code'] ?? ''),
            'label' => (string)($status['announcement']['label'] ?? ''),
            'voice' => $voice,
        );
    }

    if ((int)($_GET['debug'] ?? 0) === 1 && !empty($status['debug']) && is_array($status['debug'])) {
        $response['debug'] = $status['debug'];
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(array(
        'ok' => false,
        'error' => 'Unable to load aircraft status.',
        'hex' => $track['hex'],
        'label' => $track['label'],
    ), JSON_UNESCAPED_SLASHES);
}
