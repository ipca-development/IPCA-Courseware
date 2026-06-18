<?php
declare(strict_types=1);

function tv_radar_weather_station_url(): string
{
    return trim((string)(getenv('CW_TV_WEATHER_URL') ?: ''));
}

function tv_radar_weather_cache_path(string $station): string
{
    $station = preg_replace('/[^A-Z0-9_-]/', '', strtoupper($station)) ?: 'KTRM';
    $dir = dirname(__DIR__) . '/storage/tv_weather_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . $station . '.json';
}

function tv_radar_weather_load_cache(string $station): array
{
    $path = tv_radar_weather_cache_path($station);
    if (!is_file($path)) {
        return array();
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : array();
}

function tv_radar_weather_save_cache(string $station, array $payload): void
{
    $payload['cached_at'] = gmdate('c');
    @file_put_contents(tv_radar_weather_cache_path($station), json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function tv_radar_weather_relative_humidity(?float $tempC, ?float $dewpointC): ?int
{
    if ($tempC === null || $dewpointC === null) {
        return null;
    }
    $a = 17.625 * $dewpointC / (243.04 + $dewpointC);
    $b = 17.625 * $tempC / (243.04 + $tempC);
    $rh = 100.0 * exp($a - $b);
    return (int)round(max(0.0, min(100.0, $rh)));
}

function tv_radar_weather_density_altitude_ft(?float $tempC, ?float $altimeterInHg, float $fieldElevFt): ?int
{
    if ($tempC === null || $altimeterInHg === null) {
        return null;
    }
    $pressureAlt = $fieldElevFt + (29.92 - $altimeterInHg) * 1000.0;
    $isaTempC = 15.0 - (2.0 * ($pressureAlt / 1000.0));
    $da = $pressureAlt + 120.0 * ($tempC - $isaTempC);
    return (int)round($da);
}

function tv_radar_weather_format_sky(array $clouds): string
{
    if (!count($clouds)) {
        return 'CLR';
    }
    $parts = array();
    foreach ($clouds as $layer) {
        if (!is_array($layer)) {
            continue;
        }
        $cover = strtoupper(trim((string)($layer['cover'] ?? '')));
        $base = isset($layer['base']) && is_numeric($layer['base']) ? (int)$layer['base'] : null;
        if ($cover === '') {
            continue;
        }
        $parts[] = $base !== null ? $cover . ' ' . str_pad((string)$base, 3, '0', STR_PAD_LEFT) : $cover;
    }
    return count($parts) ? implode(' / ', $parts) : 'CLR';
}

function tv_radar_weather_runway_components(?float $windDirDeg, ?float $windKt, array $runways): array
{
    if ($windDirDeg === null || $windKt === null) {
        return array();
    }

    $results = array();
    foreach ($runways as $runway) {
        if (!is_array($runway)) {
            continue;
        }
        $id = (string)($runway['id'] ?? '');
        $heading = isset($runway['heading_deg']) ? (float)$runway['heading_deg'] : null;
        if ($id === '' || $heading === null) {
            continue;
        }
        $angle = deg2rad($windDirDeg - $heading);
        $headwind = -$windKt * cos($angle);
        $crosswind = abs($windKt * sin($angle));
        $results[] = array(
            'id' => $id,
            'heading_deg' => $heading,
            'headwind_kt' => round($headwind, 1),
            'crosswind_kt' => round($crosswind, 1),
        );
    }

    usort($results, static function (array $a, array $b): int {
        return ($a['crosswind_kt'] <=> $b['crosswind_kt']);
    });

    return $results;
}

function tv_radar_weather_from_custom_url(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Weather station request failed: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Weather station returned HTTP ' . $code);
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Weather station returned invalid JSON.');
    }

    if (isset($decoded['ok']) && $decoded['ok'] === false) {
        throw new RuntimeException((string)($decoded['error'] ?? 'Weather station unavailable'));
    }

    return $decoded;
}

function tv_radar_weather_fetch_metar(string $station, float $fieldElevFt = 115.0): array
{
    $station = strtoupper(trim($station));
    if ($station === '') {
        $station = 'KTRM';
    }

    $url = 'https://aviationweather.gov/api/data/metar?ids=' . rawurlencode($station) . '&format=json';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('METAR request failed: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('METAR API returned HTTP ' . $code);
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded) || count($decoded) === 0 || !is_array($decoded[0])) {
        throw new RuntimeException('METAR data unavailable for ' . $station);
    }

    $metar = $decoded[0];
    $windDir = null;
    if (isset($metar['wdir']) && is_numeric($metar['wdir'])) {
        $windDir = (float)$metar['wdir'];
    }
    $windKt = isset($metar['wspd']) && is_numeric($metar['wspd']) ? (float)$metar['wspd'] : null;
    $gustKt = isset($metar['wgst']) && is_numeric($metar['wgst']) ? (float)$metar['wgst'] : null;
    $tempC = isset($metar['temp']) && is_numeric($metar['temp']) ? (float)$metar['temp'] : null;
    $dewpointC = isset($metar['dewp']) && is_numeric($metar['dewp']) ? (float)$metar['dewp'] : null;
    $altimeter = isset($metar['altim']) && is_numeric($metar['altim']) ? round(((float)$metar['altim']) / 33.8639, 2) : null;
    $visibility = isset($metar['visib']) ? trim((string)$metar['visib']) : null;
    $sky = tv_radar_weather_format_sky((array)($metar['clouds'] ?? array()));
    $remarks = trim((string)($metar['rawOb'] ?? ''));
    $updatedAt = null;
    if (isset($metar['obsTime']) && is_numeric($metar['obsTime'])) {
        $updatedAt = gmdate('c', (int)$metar['obsTime']);
    }

    $runways = array(
        array('id' => '17', 'heading_deg' => 180.0),
        array('id' => '35', 'heading_deg' => 360.0),
        array('id' => '12', 'heading_deg' => 135.0),
        array('id' => '30', 'heading_deg' => 315.0),
    );

    return array(
        'ok' => true,
        'source' => 'metar',
        'station' => $station,
        'wind_dir_deg' => $windDir,
        'wind_kt' => $windKt,
        'gust_kt' => $gustKt,
        'temp_c' => $tempC,
        'dewpoint_c' => $dewpointC,
        'altimeter_inhg' => $altimeter,
        'visibility_sm' => $visibility,
        'sky' => $sky,
        'humidity_pct' => tv_radar_weather_relative_humidity($tempC, $dewpointC),
        'density_alt_ft' => tv_radar_weather_density_altitude_ft($tempC, $altimeter, $fieldElevFt),
        'precipitation' => trim((string)($metar['wxString'] ?? '')),
        'remarks' => $remarks,
        'updated_at' => $updatedAt,
        'runway_components' => tv_radar_weather_runway_components($windDir, $windKt, $runways),
        'favored_runway' => tv_radar_weather_runway_components($windDir, $windKt, $runways)[0]['id'] ?? null,
    );
}

function tv_radar_weather_build(string $station = 'KTRM', float $fieldElevFt = 115.0): array
{
    $station = strtoupper(trim($station)) ?: 'KTRM';
    $cache = tv_radar_weather_load_cache($station);

    try {
        $customUrl = tv_radar_weather_station_url();
        if ($customUrl !== '') {
            $payload = tv_radar_weather_from_custom_url($customUrl);
            $payload['ok'] = true;
            $payload['source'] = (string)($payload['source'] ?? 'station');
            $payload['station'] = (string)($payload['station'] ?? $station);
            tv_radar_weather_save_cache($station, $payload);
            return $payload;
        }

        $payload = tv_radar_weather_fetch_metar($station, $fieldElevFt);
        tv_radar_weather_save_cache($station, $payload);
        return $payload;
    } catch (Throwable $e) {
        if (!empty($cache['ok'])) {
            $stale = $cache;
            $stale['stale'] = true;
            $stale['error'] = $e->getMessage();
            return $stale;
        }

        return array(
            'ok' => false,
            'source' => 'unavailable',
            'station' => $station,
            'error' => $e->getMessage(),
        );
    }
}
