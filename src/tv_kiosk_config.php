<?php
declare(strict_types=1);

require_once __DIR__ . '/tv_adsb_status.php';
require_once __DIR__ . '/tv_screen_pa.php';
require_once __DIR__ . '/tv_adsb_operations.php';

function tv_kiosk_config_defaults(): array
{
    $gate = tv_adsb_default_gate();
    return array(
        'screen_key' => 'main',
        'default_mode' => 'standard',
        'audio_enabled' => 1,
        'night_mode' => 0,
        'poll_ms' => 7000,
        'aircraft_poll_ms' => 15000,
        'default_pa_voice' => tv_pa_default_voice(),
        'gate_label' => (string)$gate['label'],
        'gate_lat' => (float)$gate['lat'],
        'gate_lon' => (float)$gate['lon'],
        'gate_radius_nm' => (float)$gate['radius_nm'],
        'assume_parked_off_radar' => 1,
        'home_airport' => tv_adsb_default_home_airport(),
        'fleet_aircraft' => array(),
        'kiosk_notes' => '',
    );
}

/**
 * Parse fleet list from settings textarea.
 * One aircraft per line: TAIL,HEX or TAIL,HEX,TYPE
 */
function tv_kiosk_parse_fleet_aircraft_text(string $text, string $defaultHome = 'KTRM'): array
{
    $entries = array();
    $homeAirport = tv_adsb_normalize_home_airport($defaultHome);

    foreach (preg_split('/\r\n|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,|;]/', $line)), static fn(string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            continue;
        }

        $label = '';
        $hex = '';
        $type = '';
        if (preg_match('/^[a-f0-9]{6}$/i', $parts[0])) {
            $hex = tv_adsb_normalize_hex($parts[0]);
            $label = tv_adsb_normalize_label($parts[1]);
            $type = isset($parts[2]) ? tv_adsb_normalize_type($parts[2]) : '';
        } else {
            $label = tv_adsb_normalize_label($parts[0]);
            $hex = tv_adsb_normalize_hex($parts[1]);
            $type = isset($parts[2]) ? tv_adsb_normalize_type($parts[2]) : '';
        }

        if ($hex === '' && $label === '') {
            continue;
        }

        $entries[] = array(
            'label' => $label,
            'hex' => $hex,
            'type' => $type,
            'home_airport' => $homeAirport,
            'announce_audio_enabled' => 1,
        );
    }

    return $entries;
}

function tv_kiosk_fleet_aircraft_to_text(array $fleet): string
{
    $lines = array();
    foreach ($fleet as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $label = tv_adsb_normalize_label((string)($entry['label'] ?? ''));
        $hex = tv_adsb_normalize_hex((string)($entry['hex'] ?? ''));
        $type = tv_adsb_normalize_type((string)($entry['type'] ?? ''));
        if ($hex === '' && $label === '') {
            continue;
        }
        $line = $label . ',' . $hex;
        if ($type !== '') {
            $line .= ',' . $type;
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

function tv_kiosk_normalize_fleet_aircraft(array $fleet, string $defaultHome = 'KTRM'): array
{
    $normalized = array();
    $seen = array();
    $homeAirport = tv_adsb_normalize_home_airport($defaultHome);

    foreach ($fleet as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $hex = tv_adsb_normalize_hex((string)($entry['hex'] ?? ''));
        $label = tv_adsb_normalize_label((string)($entry['label'] ?? ''));
        if ($hex === '' && $label === '') {
            continue;
        }
        $key = $hex !== '' ? $hex : strtolower($label);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = array(
            'label' => $label,
            'hex' => $hex,
            'type' => tv_adsb_normalize_type((string)($entry['type'] ?? '')),
            'home_airport' => tv_adsb_normalize_home_airport((string)($entry['home_airport'] ?? $homeAirport)),
            'announce_audio_enabled' => (int)($entry['announce_audio_enabled'] ?? 1) === 1 ? 1 : 0,
        );
    }

    return $normalized;
}

/** @return array<int, array<string, mixed>> */
function tv_kiosk_fleet_track_rows(?array $kiosk = null): array
{
    $kiosk = $kiosk ?? tv_kiosk_config();
    $fleet = tv_kiosk_normalize_fleet_aircraft(
        is_array($kiosk['fleet_aircraft'] ?? null) ? $kiosk['fleet_aircraft'] : array(),
        (string)($kiosk['home_airport'] ?? tv_adsb_default_home_airport())
    );

    $rows = array();
    foreach ($fleet as $idx => $entry) {
        $rows[] = array(
            'id' => 'fleet-' . $idx,
            'title' => (string)$entry['label'],
            'body' => (string)$entry['hex'],
            'aircraft_hex' => (string)$entry['hex'],
            'aircraft_label' => (string)$entry['label'],
            'aircraft_type' => (string)$entry['type'],
            'aircraft_home_airport' => (string)$entry['home_airport'],
            'announce_audio_enabled' => (int)$entry['announce_audio_enabled'],
            'priority' => 100 - $idx,
        );
    }

    return $rows;
}

function tv_kiosk_config_path(): string
{
    return dirname(__DIR__) . '/storage/tv_kiosk_config.json';
}

function tv_kiosk_config(): array
{
    $defaults = tv_kiosk_config_defaults();
    $path = tv_kiosk_config_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

function tv_kiosk_config_save(array $config): void
{
    $dir = dirname(tv_kiosk_config_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $payload = array_merge(tv_kiosk_config_defaults(), $config);
    if (isset($payload['fleet_aircraft']) && is_array($payload['fleet_aircraft'])) {
        $payload['fleet_aircraft'] = tv_kiosk_normalize_fleet_aircraft(
            $payload['fleet_aircraft'],
            (string)($payload['home_airport'] ?? tv_adsb_default_home_airport())
        );
    }
    @file_put_contents(
        tv_kiosk_config_path(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}
