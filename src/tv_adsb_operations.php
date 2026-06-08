<?php
declare(strict_types=1);

function tv_adsb_display_timezone(): string
{
    return trim((string)(getenv('CW_TV_ADSB_TIMEZONE') ?: 'America/Los_Angeles'));
}

function tv_adsb_format_local_time(int $timestamp): string
{
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone(new DateTimeZone(tv_adsb_display_timezone()));
    return $dt->format('H:i');
}

function tv_adsb_normalize_type(string $type): string
{
    $type = strtoupper(trim($type));
    $type = preg_replace('/[^A-Z0-9]/', '', $type) ?? '';
    return $type;
}

function tv_adsb_airspeed(array $aircraft): float
{
    if (isset($aircraft['ias']) && is_numeric($aircraft['ias'])) {
        return (float)$aircraft['ias'];
    }
    if (isset($aircraft['gs']) && is_numeric($aircraft['gs'])) {
        return (float)$aircraft['gs'];
    }
    return 0.0;
}

function tv_adsb_vertical_rate(array $aircraft): float
{
    if (isset($aircraft['baro_rate']) && is_numeric($aircraft['baro_rate'])) {
        return (float)$aircraft['baro_rate'];
    }
    if (isset($aircraft['geom_rate']) && is_numeric($aircraft['geom_rate'])) {
        return (float)$aircraft['geom_rate'];
    }
    return 0.0;
}

function tv_adsb_airport_elevation(string $icao): int
{
    $icao = strtoupper(trim($icao));
    $airports = tv_adsb_airports();
    return (int)($airports[$icao]['elev_ft'] ?? 0);
}

function tv_adsb_record_history(array &$cache, ?array $aircraft, array $gate, string $homeAirport): void
{
    tv_adsb_record_motion_sample($cache, $aircraft, $gate, $homeAirport);
}

function tv_adsb_status_row(
    string $symbol,
    string $statusCode,
    string $statusText,
    array $base
): array {
    $label = tv_adsb_normalize_label((string)($base['label'] ?? ''));
    $type = tv_adsb_normalize_type((string)($base['type'] ?? ''));
    $iconCode = (string)($base['icon_code'] ?? tv_adsb_fsm_icon_code($statusCode));

    return array_merge($base, array(
        'symbol' => $symbol,
        'icon_code' => $iconCode,
        'status_code' => $statusCode,
        'status_text' => $statusText,
        'status_label' => $statusText,
        'display' => $label . ' | ' . ($type !== '' ? $type : '--') . ' | ' . $statusText,
        'aircraft_display' => $label,
        'type_display' => $type !== '' ? $type : '--',
    ));
}

function tv_adsb_classify_operations(
    array $track,
    ?array $aircraft,
    array $gate,
    string $homeAirport,
    array &$cache
): array {
    return tv_adsb_fsm_tick($track, $aircraft, $gate, $homeAirport, $cache);
}
