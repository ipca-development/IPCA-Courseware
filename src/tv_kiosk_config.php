<?php
declare(strict_types=1);

require_once __DIR__ . '/tv_adsb_status.php';
require_once __DIR__ . '/tv_screen_pa.php';

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
        'kiosk_notes' => '',
    );
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
    @file_put_contents(
        tv_kiosk_config_path(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}
