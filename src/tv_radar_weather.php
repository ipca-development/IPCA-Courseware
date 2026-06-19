<?php
declare(strict_types=1);

/**
 * Radar weather — Tempest/WeatherFlow station (tempo_asos) with METAR fallback.
 */

function tv_tempest_config(): array
{
    return array(
        'access_token' => trim((string)(getenv('CW_TEMPEST_ACCESS_TOKEN') ?: '70bd2c96-f363-4d3d-8ae1-ef5724a63e94')),
        'station_id' => trim((string)(getenv('CW_TEMPEST_STATION_ID') ?: '161239')),
        'alt_cal_inhg' => (float)(getenv('CW_TEMPEST_ALT_CAL_INHG') ?: -0.03),
    );
}

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
    $isaTempC = 15.0 - (2.0 * ($fieldElevFt / 1000.0));
    $da = $pressureAlt + 120.0 * ($tempC - $isaTempC);
    return (int)round($da / 100.0) * 100;
}

function tv_radar_weather_wind_dir_tens(float $dirDeg): int
{
    $dir = (int)round($dirDeg / 10.0) * 10;
    if ($dir <= 0) {
        $dir = 360;
    }
    if ($dir > 360) {
        $dir = 360;
    }
    return $dir;
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

function tv_radar_weather_ktrm_runways(): array
{
    return array(
        array('id' => '17', 'heading_deg' => 180.0),
        array('id' => '35', 'heading_deg' => 360.0),
        array('id' => '12', 'heading_deg' => 135.0),
        array('id' => '30', 'heading_deg' => 315.0),
    );
}

function tv_radar_weather_nbm_grid_url(): string
{
    return trim((string)(getenv('CW_NBM_GRID_URL') ?: 'https://api.weather.gov/gridpoints/SGX/100,48'));
}

function tv_radar_weather_iso_duration_seconds(string $dur): int
{
    $days = 0;
    $hours = 0;
    $mins = 0;
    $secs = 0;
    $parts = explode('T', $dur);
    $datePart = $parts[0];
    $timePart = count($parts) > 1 ? $parts[1] : '';

    if (preg_match('/P(\d+)D/', $datePart, $m)) {
        $days = (int)$m[1];
    }
    if ($timePart !== '') {
        if (preg_match('/(\d+)H/', $timePart, $m)) {
            $hours = (int)$m[1];
        }
        if (preg_match('/(\d+)M/', $timePart, $m)) {
            $mins = (int)$m[1];
        }
        if (preg_match('/(\d+)S/', $timePart, $m)) {
            $secs = (int)$m[1];
        }
    }

    return ($days * 86400) + ($hours * 3600) + ($mins * 60) + $secs;
}

function tv_radar_weather_grid_value_at_time($values, int $targetUnix)
{
    if (!is_array($values)) {
        return null;
    }

    $bestPast = null;
    $bestPastStart = null;

    foreach ($values as $item) {
        if (!is_array($item) || !isset($item['validTime'])) {
            continue;
        }
        $parts = explode('/', (string)$item['validTime']);
        if (count($parts) < 2) {
            continue;
        }
        $start = strtotime($parts[0]);
        if ($start === false) {
            continue;
        }
        $end = $start + tv_radar_weather_iso_duration_seconds($parts[1]);
        if ($targetUnix >= $start && $targetUnix < $end) {
            return $item['value'] ?? null;
        }
        if ($start <= $targetUnix && ($bestPastStart === null || $start > $bestPastStart)) {
            $bestPastStart = $start;
            $bestPast = $item['value'] ?? null;
        }
    }

    return $bestPast;
}

function tv_radar_weather_unit_to_knots($value, string $unitCode): ?float
{
    if ($value === null || !is_numeric($value)) {
        return null;
    }
    $v = (float)$value;
    if (strpos($unitCode, 'km_h-1') !== false) {
        return $v * 0.5399568;
    }
    if (strpos($unitCode, 'm_s-1') !== false) {
        return $v * 1.943844;
    }
    return $v;
}

function tv_radar_weather_fetch_json(string $url, int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/geo+json, application/json',
            'User-Agent: IPCA-TV-Radar/1.0',
        ),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Weather request failed: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Weather request returned HTTP ' . $code);
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Weather request returned invalid JSON.');
    }

    return $decoded;
}

function tv_radar_weather_nbm_forecast_2h(): array
{
    $grid = tv_radar_weather_fetch_json(tv_radar_weather_nbm_grid_url(), 10);
    if (!isset($grid['properties']) || !is_array($grid['properties'])) {
        return array('ok' => false);
    }

    $props = $grid['properties'];
    $wdVals = $props['windDirection']['values'] ?? null;
    $wsVals = $props['windSpeed']['values'] ?? null;
    $wsUom = (string)($props['windSpeed']['uom'] ?? '');
    $target = time() + 7200;

    $wd = tv_radar_weather_grid_value_at_time($wdVals, $target);
    $ws = tv_radar_weather_grid_value_at_time($wsVals, $target);

    return array(
        'ok' => true,
        'forecast_wind_dir_deg' => $wd !== null ? (int)round((float)$wd, 0) : null,
        'forecast_wind_kt' => $ws !== null ? (int)round(tv_radar_weather_unit_to_knots($ws, $wsUom) ?? 0, 0) : null,
    );
}

function tv_radar_weather_fetch_metar_visibility(string $station): ?string
{
    $station = strtoupper(trim($station)) ?: 'KTRM';
    $url = 'https://aviationweather.gov/api/data/metar?ids=' . rawurlencode($station) . '&format=json';
    $decoded = tv_radar_weather_fetch_json($url, 8);
    if (!count($decoded) || !is_array($decoded[0])) {
        return null;
    }
    $vis = isset($decoded[0]['visib']) ? trim((string)$decoded[0]['visib']) : '';
    return $vis !== '' ? $vis : null;
}

function tv_radar_weather_enrich_payload(array $payload, string $station = 'KTRM'): array
{
    try {
        $visibility = tv_radar_weather_fetch_metar_visibility($station);
        if ($visibility !== null) {
            $payload['visibility_sm'] = $visibility;
            $payload['visibility_source'] = 'metar_' . strtolower($station);
        }
    } catch (Throwable $e) {
        $payload['visibility_error'] = $e->getMessage();
    }

    try {
        $forecast = tv_radar_weather_nbm_forecast_2h();
        if (!empty($forecast['ok'])) {
            $payload['forecast_wind_dir_deg'] = $forecast['forecast_wind_dir_deg'];
            $payload['forecast_wind_kt'] = $forecast['forecast_wind_kt'];
        }
    } catch (Throwable $e) {
        $payload['forecast_error'] = $e->getMessage();
    }

    return $payload;
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

function tv_radar_weather_fetch_tempest(float $fieldElevFt = 115.0): array
{
    $config = tv_tempest_config();
    if ($config['access_token'] === '' || $config['station_id'] === '') {
        throw new RuntimeException('Tempest station credentials are not configured.');
    }

    $url = 'https://swd.weatherflow.com/swd/rest/observations/station/'
        . rawurlencode($config['station_id'])
        . '?token=' . rawurlencode($config['access_token']);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'User-Agent: IPCA-TV-Radar/1.0',
        ),
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Tempest request failed: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Tempest API returned HTTP ' . $code);
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded) || !isset($decoded['obs'][0]) || !is_array($decoded['obs'][0])) {
        throw new RuntimeException('Tempest observation unavailable.');
    }

    $obs = $decoded['obs'][0];
    $timestamp = isset($obs['timestamp']) ? (int)$obs['timestamp'] : time();

    $windDirRaw = isset($obs['wind_direction']) ? (float)$obs['wind_direction'] : null;
    $windDir = $windDirRaw !== null ? tv_radar_weather_wind_dir_tens($windDirRaw) : null;
    $windKt = isset($obs['wind_avg']) ? (int)round((float)$obs['wind_avg'] * 1.94384, 0) : null;
    $gustKt = isset($obs['wind_gust']) ? (int)round((float)$obs['wind_gust'] * 1.94384, 0) : null;

    $tempC = isset($obs['air_temperature']) ? (float)$obs['air_temperature'] : null;
    $dewpointC = isset($obs['dew_point']) ? (float)$obs['dew_point'] : null;

    $humidity = null;
    if (isset($obs['relative_humidity'])) {
        $humidity = (int)round((float)$obs['relative_humidity'], 0);
    } elseif ($tempC !== null && $dewpointC !== null) {
        $humidity = tv_radar_weather_relative_humidity($tempC, $dewpointC);
    }

    $altimeter = null;
    if (isset($obs['sea_level_pressure']) && is_numeric($obs['sea_level_pressure'])) {
        $altimeter = round(((float)$obs['sea_level_pressure']) / 33.8639 + (float)$config['alt_cal_inhg'], 2);
    }

    $precip = '';
    if (isset($obs['precipitation_type']) && (int)$obs['precipitation_type'] > 0) {
        $precip = 'PRECIP';
    }

    $runways = tv_radar_weather_ktrm_runways();
    $runwayComponents = tv_radar_weather_runway_components(
        $windDir !== null ? (float)$windDir : null,
        $windKt !== null ? (float)$windKt : null,
        $runways
    );

    return array(
        'ok' => true,
        'source' => 'tempest',
        'station' => 'KTRM',
        'station_id' => $config['station_id'],
        'wind_dir_deg' => $windDir,
        'wind_dir_raw_deg' => $windDirRaw !== null ? round($windDirRaw, 0) : null,
        'wind_kt' => $windKt,
        'gust_kt' => $gustKt,
        'temp_c' => $tempC,
        'dewpoint_c' => $dewpointC,
        'altimeter_inhg' => $altimeter,
        'visibility_sm' => null,
        'sky' => 'CLR',
        'humidity_pct' => $humidity,
        'density_alt_ft' => tv_radar_weather_density_altitude_ft($tempC, $altimeter, $fieldElevFt),
        'precipitation' => $precip,
        'remarks' => 'Tempest station ' . $config['station_id'],
        'updated_at' => gmdate('c', $timestamp),
        'recorded_at_local' => date('H:i', $timestamp) . ' PT',
        'runway_components' => $runwayComponents,
        'favored_runway' => $runwayComponents[0]['id'] ?? null,
    );
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

    $runways = tv_radar_weather_ktrm_runways();
    $runwayComponents = tv_radar_weather_runway_components($windDir, $windKt, $runways);

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
        'runway_components' => $runwayComponents,
        'favored_runway' => $runwayComponents[0]['id'] ?? null,
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
            $payload = tv_radar_weather_enrich_payload($payload, $station);
            tv_radar_weather_save_cache($station, $payload);
            return $payload;
        }

        $payload = tv_radar_weather_fetch_tempest($fieldElevFt);
        $payload = tv_radar_weather_enrich_payload($payload, $station);
        tv_radar_weather_save_cache($station, $payload);
        return $payload;
    } catch (Throwable $tempestError) {
        try {
            $payload = tv_radar_weather_fetch_metar($station, $fieldElevFt);
            $payload = tv_radar_weather_enrich_payload($payload, $station);
            $payload['tempest_error'] = $tempestError->getMessage();
            tv_radar_weather_save_cache($station, $payload);
            return $payload;
        } catch (Throwable $metarError) {
            if (!empty($cache['ok'])) {
                $stale = $cache;
                $stale['stale'] = true;
                $stale['error'] = $tempestError->getMessage();
                return $stale;
            }

            return array(
                'ok' => false,
                'source' => 'unavailable',
                'station' => $station,
                'error' => $tempestError->getMessage(),
            );
        }
    }
}
