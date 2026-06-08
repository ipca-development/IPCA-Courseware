<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_adsb_status.php';
require_once __DIR__ . '/../../../src/tv_kiosk_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$screenKey = trim((string)($_GET['screen_key'] ?? 'main'));
$screenKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $screenKey) ?: 'main';

function tv_board_aircraft_type_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'aircraft_type'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function tv_board_aircraft_columns_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'aircraft_hex'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

$kiosk = tv_kiosk_config();
$gate = array(
    'label' => (string)($kiosk['gate_label'] ?? tv_adsb_default_gate()['label']),
    'lat' => (float)($kiosk['gate_lat'] ?? tv_adsb_default_gate()['lat']),
    'lon' => (float)($kiosk['gate_lon'] ?? tv_adsb_default_gate()['lon']),
    'radius_nm' => (float)($kiosk['gate_radius_nm'] ?? tv_adsb_default_gate()['radius_nm']),
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

$homeAirport = tv_adsb_normalize_home_airport((string)($_GET['home_airport'] ?? ($kiosk['home_airport'] ?? '')));

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'tv_screen_messages'");
    if ($tableCheck === false || $tableCheck->fetchColumn() === false) {
        echo json_encode(array(
            'ok' => true,
            'screen_key' => $screenKey,
            'rows' => array(),
            'server_time' => gmdate('c'),
        ), JSON_UNESCAPED_SLASHES);
        exit;
    }

    $aircraftColumnsReady = tv_board_aircraft_columns_ready($pdo);
    $typeColumnReady = tv_board_aircraft_type_ready($pdo);
    $typeSelect = $typeColumnReady ? 'aircraft_type,' : '';
    $aircraftSelect = $aircraftColumnsReady
        ? "aircraft_hex, aircraft_label, {$typeSelect} aircraft_home_airport,"
        : '';

    $voiceColumnReady = false;
    try {
        $voiceStmt = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'voice'");
        $voiceColumnReady = $voiceStmt !== false && $voiceStmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        $voiceColumnReady = false;
    }

    $voiceSelect = $voiceColumnReady ? 'voice,' : '';
    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            body,
            {$aircraftSelect}
            {$voiceSelect}
            announce_audio_enabled,
            priority
        FROM tv_screen_messages
        WHERE screen_key = ?
          AND message_type = 'aircraft'
          AND status = 'active'
          AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
          AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
        ORDER BY priority DESC, id ASC
        LIMIT 8
    ");
    $stmt->execute([$screenKey]);
    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = array();
    $announcements = array();
    $anyLive = false;
    $kioskAudioEnabled = (int)($kiosk['audio_enabled'] ?? 1) === 1;
    $defaultVoice = tv_pa_voice_or_default((string)($kiosk['default_pa_voice'] ?? ''));

    foreach ($tracks as $trackRow) {
        $track = tv_adsb_resolve_track(array(
            'hex' => (string)($trackRow['aircraft_hex'] ?? $trackRow['body'] ?? ''),
            'label' => (string)($trackRow['aircraft_label'] ?? $trackRow['title'] ?? ''),
            'type' => (string)($trackRow['aircraft_type'] ?? ''),
            'home_airport' => (string)($trackRow['aircraft_home_airport'] ?? $homeAirport),
        ));

        if ($track['hex'] === '' && $track['label'] === '') {
            continue;
        }

        $announceEnabled = $kioskAudioEnabled && (int)($trackRow['announce_audio_enabled'] ?? 0) === 1;
        $voice = $voiceColumnReady
            ? tv_pa_voice_or_default((string)($trackRow['voice'] ?? $defaultVoice))
            : $defaultVoice;

        try {
            $status = tv_adsb_build_status($track, array(
                'gate' => $gate,
                'home_airport' => $track['home_airport'] !== '' ? $track['home_airport'] : $homeAirport,
                'announce_audio_enabled' => $announceEnabled,
            ));
        } catch (Throwable $e) {
            $status = array(
                'symbol' => '?',
                'label' => $track['label'] !== '' ? $track['label'] : strtoupper($track['hex']),
                'type_display' => $track['type'] !== '' ? $track['type'] : '--',
                'status_text' => 'STATUS UNAVAILABLE',
                'aircraft_display' => '? ' . ($track['label'] !== '' ? $track['label'] : strtoupper($track['hex'])),
                'live' => false,
                'stale' => true,
            );
        }

        if (!empty($status['live'])) {
            $anyLive = true;
        }

        $aircraftLabel = tv_adsb_normalize_label((string)($status['label'] ?? $track['label']));
        if ($aircraftLabel === '') {
            continue;
        }

        $rows[] = array(
            'id' => (int)$trackRow['id'],
            'symbol' => (string)($status['symbol'] ?? '?'),
            'icon_code' => (string)($status['icon_code'] ?? 'unknown'),
            'aircraft' => $aircraftLabel,
            'aircraft_display' => (string)($status['aircraft_display'] ?? $aircraftLabel),
            'type' => (string)($status['type_display'] ?? ($track['type'] !== '' ? $track['type'] : '--')),
            'status' => (string)($status['status_text'] ?? ''),
            'status_code' => (string)($status['status_code'] ?? ''),
            'live' => (bool)($status['live'] ?? false),
            'stale' => (bool)($status['stale'] ?? false),
        );

        if ($announceEnabled && !empty($status['announcement']) && is_array($status['announcement'])) {
            $announcements[] = array(
                'id' => (int)$trackRow['id'],
                'speech' => (string)($status['announcement']['speech'] ?? ''),
                'status_code' => (string)($status['announcement']['status_code'] ?? ''),
                'label' => (string)($status['announcement']['label'] ?? ''),
                'voice' => $voice,
            );
        }
    }

    echo json_encode(array(
        'ok' => true,
        'screen_key' => $screenKey,
        'live' => $anyLive,
        'provider' => tv_adsb_provider(),
        'rows' => $rows,
        'announcements' => $announcements,
        'server_time' => gmdate('c'),
    ), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(array(
        'ok' => false,
        'error' => 'Unable to load aircraft board.',
        'rows' => array(),
    ), JSON_UNESCAPED_SLASHES);
}
