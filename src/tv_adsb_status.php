<?php
declare(strict_types=1);

function tv_adsb_api_key(): string
{
    $key = trim((string)(
        getenv('CW_ADSBEXCHANGE_API_KEY')
        ?: getenv('CW_RAPIDAPI_KEY')
        ?: getenv('RAPIDAPI_KEY')
        ?: getenv('ADSBEXCHANGE_API_KEY')
        ?: ''
    ));
    if ($key === '') {
        throw new RuntimeException('CW_ADSBEXCHANGE_API_KEY is not configured.');
    }
    return $key;
}

function tv_adsb_provider(): string
{
    $provider = strtolower(trim((string)(getenv('CW_ADSBEXCHANGE_PROVIDER') ?: '')));
    return $provider === 'rapidapi' ? 'rapidapi' : 'gateway';
}

function tv_adsb_api_config(): array
{
    if (tv_adsb_provider() === 'gateway') {
        return array(
            'base_url' => 'https://gateway.adsbexchange.com/api/aircraft/v2',
            'headers' => array(
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'x-api-key: ' . tv_adsb_api_key(),
                'api-auth: ' . tv_adsb_api_key(),
            ),
        );
    }

    $host = trim((string)(getenv('CW_ADSBEXCHANGE_RAPIDAPI_HOST') ?: 'adsbexchange-com1.p.rapidapi.com'));
    $base = trim((string)(getenv('CW_ADSBEXCHANGE_RAPIDAPI_BASE') ?: 'https://adsbexchange-com1.p.rapidapi.com/v2'));

    return array(
        'base_url' => rtrim($base, '/'),
        'headers' => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'x-rapidapi-host: ' . $host,
            'x-rapidapi-key: ' . tv_adsb_api_key(),
        ),
    );
}

function tv_adsb_default_gate(): array
{
    return array(
        'label' => 'SPC Gate',
        'lat' => (float)(getenv('CW_TV_ADSB_GATE_LAT') ?: 33.6267),
        'lon' => (float)(getenv('CW_TV_ADSB_GATE_LON') ?: -116.1600),
        'radius_nm' => (float)(getenv('CW_TV_ADSB_GATE_RADIUS_NM') ?: 0.18),
    );
}

function tv_adsb_default_home_airport(): string
{
    return strtoupper(trim((string)(getenv('CW_TV_ADSB_HOME_AIRPORT') ?: 'KTRM')));
}

function tv_adsb_airports(): array
{
    return array(
        'KTRM' => array('name' => 'Thermal', 'lat' => 33.626701, 'lon' => -116.160156, 'elev_ft' => -115, 'surface_nm' => 1.8, 'boundary_nm' => 5.0),
        'KPSP' => array('name' => 'Palm Springs', 'lat' => 33.829667, 'lon' => -116.506667, 'elev_ft' => 477, 'surface_nm' => 2.0, 'boundary_nm' => 6.0),
        'KHMT' => array('name' => 'Hemet Ryan', 'lat' => 33.734000, 'lon' => -117.023000, 'elev_ft' => 1517, 'surface_nm' => 1.5, 'boundary_nm' => 5.0),
        'KUDD' => array('name' => 'Bermuda Dunes', 'lat' => 33.748333, 'lon' => -116.274722, 'elev_ft' => 73, 'surface_nm' => 1.5, 'boundary_nm' => 5.0),
        'KBNG' => array('name' => 'Banning', 'lat' => 33.922611, 'lon' => -116.850694, 'elev_ft' => 2219, 'surface_nm' => 1.2, 'boundary_nm' => 4.0),
        'KRAL' => array('name' => 'Riverside Muni', 'lat' => 33.951894, 'lon' => -117.445111, 'elev_ft' => 818, 'surface_nm' => 1.6, 'boundary_nm' => 5.0),
        'KSBD' => array('name' => 'San Bernardino', 'lat' => 34.095356, 'lon' => -117.234872, 'elev_ft' => 1159, 'surface_nm' => 1.8, 'boundary_nm' => 5.0),
        'KBLH' => array('name' => 'Blythe', 'lat' => 33.619167, 'lon' => -114.716889, 'elev_ft' => 399, 'surface_nm' => 1.5, 'boundary_nm' => 5.0),
        'KCRQ' => array('name' => 'Carlsbad', 'lat' => 33.128333, 'lon' => -117.280000, 'elev_ft' => 329, 'surface_nm' => 1.6, 'boundary_nm' => 5.0),
        'KMYF' => array('name' => 'Montgomery Field', 'lat' => 32.815833, 'lon' => -117.139444, 'elev_ft' => 427, 'surface_nm' => 1.6, 'boundary_nm' => 5.0),
        'KSAN' => array('name' => 'San Diego', 'lat' => 32.733556, 'lon' => -117.189667, 'elev_ft' => 17, 'surface_nm' => 2.5, 'boundary_nm' => 8.0),
        'KONT' => array('name' => 'Ontario', 'lat' => 34.056000, 'lon' => -117.601194, 'elev_ft' => 944, 'surface_nm' => 2.2, 'boundary_nm' => 6.0),
        'KLAX' => array('name' => 'Los Angeles', 'lat' => 33.942500, 'lon' => -118.408056, 'elev_ft' => 125, 'surface_nm' => 3.0, 'boundary_nm' => 10.0),
        'EBAW' => array('name' => 'Antwerp', 'lat' => 51.189444, 'lon' => 4.460278, 'elev_ft' => 39, 'surface_nm' => 2.0, 'boundary_nm' => 6.0),
    );
}

function tv_adsb_normalize_hex(string $hex): string
{
    $hex = strtolower(trim($hex));
    $hex = preg_replace('/[^a-f0-9]/', '', $hex) ?? '';
    return strlen($hex) === 6 ? $hex : '';
}

function tv_adsb_normalize_label(string $label): string
{
    $label = strtoupper(trim($label));
    $label = preg_replace('/[^A-Z0-9-]/', '', $label) ?? '';
    return $label;
}

function tv_adsb_normalize_registration(string $registration): string
{
    return tv_adsb_normalize_label($registration);
}

function tv_adsb_normalize_home_airport(?string $icao): string
{
    $icao = strtoupper(trim((string)$icao));
    if ($icao === '' || !array_key_exists($icao, tv_adsb_airports())) {
        return tv_adsb_default_home_airport();
    }
    return $icao;
}

function tv_adsb_track_key(array $track): string
{
    $hex = tv_adsb_normalize_hex((string)($track['hex'] ?? ''));
    if ($hex !== '') {
        return 'hex_' . $hex;
    }
    $label = tv_adsb_normalize_label((string)($track['label'] ?? ''));
    return 'label_' . sha1($label);
}

function tv_adsb_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/tv_adsb';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function tv_adsb_cache_path(array $track): string
{
    return tv_adsb_cache_dir() . '/track_' . sha1(tv_adsb_track_key($track)) . '.json';
}

function tv_adsb_load_cache(array $track): array
{
    $path = tv_adsb_cache_path($track);
    if (!is_file($path)) {
        return array();
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function tv_adsb_save_cache(array $track, array $payload): void
{
    $payload['cached_at'] = gmdate('c');
    @file_put_contents(tv_adsb_cache_path($track), json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function tv_adsb_request(string $path): array
{
    $config = tv_adsb_api_config();
    $url = rtrim((string)$config['base_url'], '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HTTPHEADER => (array)$config['headers'],
    ));

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('ADS-B request failed: ' . $err);
    }

    if ($code === 404) {
        return array();
    }

    if ($code < 200 || $code >= 300) {
        $detail = '';
        $decoded = json_decode((string)$body, true);
        if (is_array($decoded)) {
            $message = $decoded['msg'] ?? $decoded['message'] ?? $decoded['error'] ?? null;
            if (is_scalar($message) && trim((string)$message) !== '') {
                $detail = ': ' . trim((string)$message);
            }
        } elseif (is_string($body) && trim($body) !== '') {
            $detail = ': ' . substr(trim($body), 0, 180);
        }
        throw new RuntimeException('ADS-B API returned HTTP ' . $code . $detail);
    }

    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : array();
}

function tv_adsb_extract_aircraft(array $payload): ?array
{
    if (isset($payload['ac']) && is_array($payload['ac']) && count($payload['ac']) > 0) {
        $first = $payload['ac'][0];
        return is_array($first) ? $first : null;
    }

    if (isset($payload['hex']) || isset($payload['r']) || isset($payload['lat'])) {
        return $payload;
    }

    return null;
}

function tv_adsb_fetch_by_hex(string $hex): ?array
{
    $hex = tv_adsb_normalize_hex($hex);
    if ($hex === '') {
        return null;
    }

    $suffix = tv_adsb_provider() === 'rapidapi' ? '/' : ',';
    $payload = tv_adsb_request('/hex/' . rawurlencode($hex) . $suffix);
    return tv_adsb_extract_aircraft($payload);
}

function tv_adsb_fetch_by_registration(string $registration): ?array
{
    $registration = tv_adsb_normalize_registration($registration);
    if ($registration === '') {
        return null;
    }

    $suffix = tv_adsb_provider() === 'rapidapi' ? '/' : '';
    $payload = tv_adsb_request('/registration/' . rawurlencode($registration) . $suffix);
    return tv_adsb_extract_aircraft($payload);
}

function tv_adsb_extract_aircraft_list(array $payload): array
{
    if (isset($payload['ac']) && is_array($payload['ac'])) {
        return $payload['ac'];
    }
    if (isset($payload['aircraft']) && is_array($payload['aircraft'])) {
        return $payload['aircraft'];
    }
    if ($payload !== array() && array_keys($payload) === range(0, count($payload) - 1)) {
        $first = $payload[0] ?? null;
        if (is_array($first) && (isset($first['hex']) || isset($first['lat']))) {
            return $payload;
        }
    }
    return array();
}

/**
 * @return array<string,mixed>
 */
function tv_adsb_fetch_historical_trace(string $hex, DateTimeImmutable $day): array
{
    $hex = tv_adsb_normalize_hex($hex);
    if ($hex === '') {
        return array();
    }

    $folder = substr($hex, -2);
    $path = '/traces-hist/'
        . $day->format('Y') . '/'
        . $day->format('m') . '/'
        . $day->format('d')
        . '/traces/' . rawurlencode($folder)
        . '/trace_full_' . rawurlencode($hex) . '.json';

    return tv_adsb_request($path);
}

/**
 * @return list<array<string,mixed>>
 */
function tv_adsb_fetch_airport_operations(string $icao, int $timeFrom, int $timeTo): array
{
    $icao = strtoupper(trim($icao));
    if ($icao === '') {
        return array();
    }

    $timeFrom = max(0, $timeFrom);
    $timeTo = max($timeFrom, $timeTo);
    $path = '/operations/airport/' . rawurlencode($icao)
        . '?time_from=' . rawurlencode((string)$timeFrom)
        . '&time_to=' . rawurlencode((string)$timeTo);

    try {
        $payload = tv_adsb_request($path);
    } catch (Throwable) {
        return array();
    }

    if (!isset($payload['items']) || !is_array($payload['items'])) {
        return array();
    }

    $items = array();
    foreach ($payload['items'] as $item) {
        if (is_array($item)) {
            $items[] = $item;
        }
    }

    return $items;
}

/**
 * @param list<array<string,mixed>> $samples
 * @return array<string,mixed>|null
 */
function tv_adsb_interpolate_trace_at_epoch(array $samples, float $epoch): ?array
{
    if ($samples === array()) {
        return null;
    }

    $before = null;
    $after = null;
    foreach ($samples as $sample) {
        if (!isset($sample['epoch']) || !is_numeric($sample['epoch'])) {
            continue;
        }
        $sampleEpoch = (float)$sample['epoch'];
        if ($sampleEpoch <= $epoch) {
            $before = $sample;
            continue;
        }
        $after = $sample;
        break;
    }

    if ($before === null && $after === null) {
        return null;
    }
    if ($before === null) {
        return abs((float)$after['epoch'] - $epoch) <= 45.0 ? $after : null;
    }
    if ($after === null) {
        return abs($epoch - (float)$before['epoch']) <= 45.0 ? $before : null;
    }

    $beforeEpoch = (float)$before['epoch'];
    $afterEpoch = (float)$after['epoch'];
    $gap = $afterEpoch - $beforeEpoch;
    if ($gap <= 0.0 || $gap > 120.0) {
        $nearest = abs($epoch - $beforeEpoch) <= abs($afterEpoch - $epoch) ? $before : $after;
        return abs($epoch - (float)$nearest['epoch']) <= 45.0 ? $nearest : null;
    }

    $ratio = max(0.0, min(1.0, ($epoch - $beforeEpoch) / $gap));
    $lerp = static function (mixed $a, mixed $b) use ($ratio): ?float {
        if (!is_numeric($a) || !is_numeric($b)) {
            return is_numeric($a) ? (float)$a : (is_numeric($b) ? (float)$b : null);
        }
        return (float)$a + ((float)$b - (float)$a) * $ratio;
    };
    $lerpAngle = static function (mixed $a, mixed $b) use ($ratio): ?float {
        if (!is_numeric($a) || !is_numeric($b)) {
            return is_numeric($a) ? (float)$a : (is_numeric($b) ? (float)$b : null);
        }
        $from = (float)$a;
        $to = (float)$b;
        $delta = fmod($to - $from + 540.0, 360.0) - 180.0;
        return fmod($from + $delta * $ratio + 360.0, 360.0);
    };

    return array(
        'epoch' => $epoch,
        'latitude' => $lerp($before['latitude'] ?? null, $after['latitude'] ?? null),
        'longitude' => $lerp($before['longitude'] ?? null, $after['longitude'] ?? null),
        'baro_altitude_ft' => $lerp($before['baro_altitude_ft'] ?? null, $after['baro_altitude_ft'] ?? null),
        'groundspeed_kt' => $lerp($before['groundspeed_kt'] ?? null, $after['groundspeed_kt'] ?? null),
        'track_deg' => $lerpAngle($before['track_deg'] ?? null, $after['track_deg'] ?? null),
        'callsign' => (string)($before['callsign'] ?? $after['callsign'] ?? ''),
    );
}

function tv_adsb_fetch_near_point(float $lat, float $lon, float $distNm): array
{
    return tv_adsb_fetch_near_point_result($lat, $lon, $distNm)['aircraft'];
}

function tv_adsb_fetch_near_point_result(float $lat, float $lon, float $distNm): array
{
    $distNm = max(0.5, min(25.0, $distNm));
    $apiDist = max(1, (int)ceil($distNm));
    $latStr = rawurlencode((string)round($lat, 6));
    $lonStr = rawurlencode((string)round($lon, 6));
    $distStr = rawurlencode((string)$apiDist);

    $paths = tv_adsb_provider() === 'rapidapi'
        ? array(
            '/lat/' . $latStr . '/lon/' . $lonStr . '/dist/' . $distStr . '/',
            '/lat/' . $latStr . '/lon/' . $lonStr . '/dist/' . $distStr,
        )
        : array(
            '/lat/' . $latStr . '/lon/' . $lonStr . '/dist/' . $distStr,
            '/lat/' . $latStr . '/lon/' . $lonStr . '/dist/' . $distStr . '/',
        );

    $result = array(
        'aircraft' => array(),
        'connected' => false,
        'raw_count' => 0,
        'error' => null,
        'path' => null,
        'api_dist_nm' => $apiDist,
    );

    foreach ($paths as $path) {
        try {
            $payload = tv_adsb_request($path);
            $list = tv_adsb_extract_aircraft_list($payload);
            $result['connected'] = true;
            $result['raw_count'] = count($list);
            $result['path'] = $path;
            $result['aircraft'] = $list;
            $result['error'] = null;
            return $result;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }
    }

    return $result;
}

function tv_adsb_radar_observed_at(array $aircraft = array()): string
{
    $now = time();
    if (isset($aircraft['seen_pos']) && is_numeric($aircraft['seen_pos'])) {
        return gmdate('c', $now - max(0, (int)round((float)$aircraft['seen_pos'])));
    }
    if (isset($aircraft['seen']) && is_numeric($aircraft['seen'])) {
        return gmdate('c', $now - max(0, (int)round((float)$aircraft['seen'])));
    }
    return gmdate('c');
}

function tv_adsb_fetch_area_radar_targets(float $centerLat, float $centerLon, float $rangeNm = 2.5): array
{
    $meta = tv_adsb_fetch_area_radar_targets_meta($centerLat, $centerLon, $rangeNm);
    return $meta['targets'];
}

function tv_adsb_fetch_area_radar_targets_meta(float $centerLat, float $centerLon, float $rangeNm = 2.5): array
{
    $fetch = tv_adsb_fetch_near_point_result($centerLat, $centerLon, $rangeNm);
    $targets = array();
    $seen = array();

    foreach ($fetch['aircraft'] as $aircraft) {
        if (!is_array($aircraft)) {
            continue;
        }
        $formatted = tv_adsb_format_area_radar_target($aircraft, $centerLat, $centerLon, $rangeNm);
        if ($formatted === null) {
            continue;
        }
        $key = $formatted['hex'] !== '' ? $formatted['hex'] : ($formatted['label'] . '|' . $formatted['lat'] . '|' . $formatted['lon']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $targets[] = $formatted;
    }

    usort($targets, static function (array $a, array $b): int {
        return ($a['dist_nm'] <=> $b['dist_nm']);
    });

    return array(
        'targets' => $targets,
        'connected' => (bool)$fetch['connected'],
        'raw_count' => (int)$fetch['raw_count'],
        'in_range_count' => count($targets),
        'error' => $fetch['error'],
        'path' => $fetch['path'],
        'api_dist_nm' => $fetch['api_dist_nm'],
    );
}
function tv_adsb_format_area_radar_target(array $aircraft, float $centerLat, float $centerLon, float $maxNm = 2.5): ?array
{
    if (!isset($aircraft['lat'], $aircraft['lon']) || !is_numeric($aircraft['lat']) || !is_numeric($aircraft['lon'])) {
        return null;
    }

    $lat = (float)$aircraft['lat'];
    $lon = (float)$aircraft['lon'];
    $dist = tv_adsb_haversine_nm($lat, $lon, $centerLat, $centerLon);
    if ($dist > $maxNm) {
        return null;
    }

    $trackDeg = null;
    if (isset($aircraft['track']) && is_numeric($aircraft['track'])) {
        $trackDeg = (float)$aircraft['track'];
    } elseif (isset($aircraft['true_heading']) && is_numeric($aircraft['true_heading'])) {
        $trackDeg = (float)$aircraft['true_heading'];
    } elseif (isset($aircraft['mag_heading']) && is_numeric($aircraft['mag_heading'])) {
        $trackDeg = (float)$aircraft['mag_heading'];
    }

    $gs = isset($aircraft['gs']) && is_numeric($aircraft['gs']) ? (float)$aircraft['gs'] : null;
    $label = tv_adsb_normalize_label((string)($aircraft['r'] ?? ($aircraft['flight'] ?? '')));
    $hex = tv_adsb_normalize_hex((string)($aircraft['hex'] ?? ''));

    return array(
        'hex' => $hex,
        'label' => $label !== '' ? $label : ($hex !== '' ? strtoupper($hex) : 'UNKNOWN'),
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
        'gs' => $gs,
        'alt_ft' => tv_adsb_altitude_ft($aircraft),
        'on_ground' => tv_adsb_is_on_ground($aircraft),
        'track_deg' => $trackDeg,
        'dist_nm' => round($dist, 1),
        'icao_type' => strtoupper(trim((string)($aircraft['t'] ?? ''))),
        'status_code' => tv_adsb_is_on_ground($aircraft) ? 'on_surface' : 'in_flight',
        'live' => true,
        'position_source' => 'live',
        'target_source' => 'area',
        'observed_at' => tv_adsb_radar_observed_at($aircraft),
    );
}

function tv_adsb_merge_radar_targets(array $fleetTargets, array $areaTargets): array
{
    $merged = array();
    $index = array();

    foreach ($fleetTargets as $target) {
        if (!is_array($target)) {
            continue;
        }
        $target['target_source'] = (string)($target['target_source'] ?? 'fleet');
        $key = !empty($target['hex']) ? $target['hex'] : ($target['label'] ?? '');
        if ($key === '') {
            $merged[] = $target;
            continue;
        }
        $index[$key] = count($merged);
        $merged[] = $target;
    }

    foreach ($areaTargets as $target) {
        if (!is_array($target)) {
            continue;
        }
        $key = !empty($target['hex']) ? $target['hex'] : '';
        if ($key !== '' && isset($index[$key])) {
            continue;
        }
        $labelKey = (string)($target['label'] ?? '');
        if ($key === '' && $labelKey !== '') {
            foreach ($merged as $existing) {
                if (strcasecmp((string)($existing['label'] ?? ''), $labelKey) === 0) {
                    continue 2;
                }
            }
        }
        $target['target_source'] = 'area';
        $merged[] = $target;
    }

    usort($merged, static function (array $a, array $b): int {
        return ($a['dist_nm'] <=> $b['dist_nm']);
    });

    return $merged;
}

function tv_adsb_build_radar_target_from_track(
    array $track,
    float $centerLat,
    float $centerLon,
    float $rangeNm,
    array $gate,
    string $homeAirport
): ?array {
    try {
        $status = tv_adsb_build_status($track, array(
            'gate' => $gate,
            'home_airport' => $homeAirport,
            'announce_audio_enabled' => false,
        ));
    } catch (Throwable $e) {
        return null;
    }

    $debug = is_array($status['debug'] ?? null) ? $status['debug'] : array();
    if (!isset($debug['lat'], $debug['lon'])) {
        return null;
    }

    $lat = (float)$debug['lat'];
    $lon = (float)$debug['lon'];
    $dist = tv_adsb_haversine_nm($lat, $lon, $centerLat, $centerLon);
    if ($dist > $rangeNm) {
        return null;
    }

    $label = tv_adsb_normalize_label((string)($status['label'] ?? $track['label'] ?? ''));
    $hex = tv_adsb_normalize_hex((string)($track['hex'] ?? ''));
    $gs = isset($debug['ground_speed_kt']) && is_numeric($debug['ground_speed_kt'])
        ? (float)$debug['ground_speed_kt']
        : null;
    $altFt = isset($debug['alt_ft']) && is_numeric($debug['alt_ft']) ? (float)$debug['alt_ft'] : null;
    $trackDeg = isset($debug['track_deg']) && is_numeric($debug['track_deg']) ? (float)$debug['track_deg'] : null;

    return array(
        'hex' => $hex,
        'label' => $label !== '' ? $label : ($hex !== '' ? strtoupper($hex) : 'UNKNOWN'),
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
        'gs' => $gs,
        'alt_ft' => $altFt,
        'on_ground' => (bool)($debug['on_surface'] ?? false),
        'track_deg' => $trackDeg,
        'dist_nm' => round($dist, 1),
        'status_code' => (string)($status['status_code'] ?? ''),
        'live' => (bool)($status['live'] ?? false),
        'position_source' => (string)($status['position_source'] ?? ($debug['position_source'] ?? '')),
        'target_source' => 'fleet',
        'observed_at' => gmdate('c'),
    );
}

function tv_adsb_fleet_track_messages_ready(PDO $pdo): bool
{
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'tv_screen_messages'");
        if ($tableCheck === false || $tableCheck->fetchColumn() === false) {
            return false;
        }
        $columnCheck = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'aircraft_hex'");
        return $columnCheck !== false && $columnCheck->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function tv_adsb_fetch_active_fleet_tracks(PDO $pdo, string $screenKey = '', int $limit = 25): array
{
    $limit = max(1, min(50, $limit));
    $configTracks = tv_kiosk_fleet_track_rows();

    if (!tv_adsb_fleet_track_messages_ready($pdo)) {
        return array_slice($configTracks, 0, $limit);
    }

    $screenKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $screenKey) ?: '';

    $typeCheck = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'aircraft_type'");
    $hasTypeColumn = $typeCheck !== false && $typeCheck->fetchColumn() !== false;
    $typeSelect = $hasTypeColumn ? 'aircraft_type,' : '';
    $aircraftSelect = "aircraft_hex, aircraft_label, {$typeSelect} aircraft_home_airport,";
    $voiceSelect = '';
    try {
        $voiceCheck = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'voice'");
        if ($voiceCheck !== false && $voiceCheck->fetchColumn() !== false) {
            $voiceSelect = 'voice, announce_audio_enabled,';
        } else {
            $voiceSelect = 'announce_audio_enabled,';
        }
    } catch (Throwable $e) {
        $voiceSelect = 'announce_audio_enabled,';
    }

    $fetch = static function (PDO $pdo, ?string $forScreenKey, int $limit, string $aircraftSelect, string $voiceSelect): array {
        if ($forScreenKey !== null && $forScreenKey !== '') {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    title,
                    body,
                    {$aircraftSelect}
                    {$voiceSelect}
                    priority
                FROM tv_screen_messages
                WHERE screen_key = ?
                  AND message_type = 'aircraft'
                  AND status = 'active'
                  AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
                  AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
                ORDER BY priority DESC, id ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$forScreenKey]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        }

        $stmt = $pdo->query("
            SELECT
                id,
                title,
                body,
                {$aircraftSelect}
                {$voiceSelect}
                priority
            FROM tv_screen_messages
            WHERE message_type = 'aircraft'
              AND status = 'active'
              AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP())
              AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
            ORDER BY priority DESC, id ASC
            LIMIT {$limit}
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    };

    $rows = $screenKey !== '' ? $fetch($pdo, $screenKey, $limit, $aircraftSelect, $voiceSelect) : array();
    if ($rows === array()) {
        $rows = $fetch($pdo, null, $limit, $aircraftSelect, $voiceSelect);
    }

    if ($configTracks !== array()) {
        return array_slice(tv_adsb_merge_fleet_track_rows($configTracks, $rows), 0, $limit);
    }

    return array_slice($rows, 0, $limit);
}

function tv_adsb_merge_fleet_track_rows(array $primary, array $secondary): array
{
    $merged = array();
    $seen = array();

    foreach (array($primary, $secondary) as $group) {
        foreach ($group as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hex = tv_adsb_normalize_hex((string)($row['aircraft_hex'] ?? $row['body'] ?? ''));
            $label = tv_adsb_normalize_label((string)($row['aircraft_label'] ?? $row['title'] ?? ''));
            $key = $hex !== '' ? $hex : strtolower($label);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $row;
        }
    }

    return $merged;
}

function tv_adsb_fetch_radar_targets(
    array $tracks,
    float $centerLat,
    float $centerLon,
    float $rangeNm = 2.5,
    array $options = array()
): array {
    $gate = array_merge(tv_adsb_default_gate(), (array)($options['gate'] ?? array()));
    $homeAirport = tv_adsb_normalize_home_airport((string)($options['home_airport'] ?? ''));
    $targets = array();
    $seen = array();

    foreach ($tracks as $trackInput) {
        if (!is_array($trackInput)) {
            continue;
        }
        $track = tv_adsb_resolve_track($trackInput);
        if ($track['hex'] === '' && $track['label'] === '') {
            continue;
        }

        $target = tv_adsb_build_radar_target_from_track(
            $track,
            $centerLat,
            $centerLon,
            $rangeNm,
            $gate,
            $track['home_airport'] !== '' ? $track['home_airport'] : $homeAirport
        );
        if ($target === null) {
            continue;
        }

        $key = $target['hex'] !== '' ? $target['hex'] : $target['label'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $targets[] = $target;
    }

    usort($targets, static function (array $a, array $b): int {
        return ($a['dist_nm'] <=> $b['dist_nm']);
    });

    return $targets;
}

function tv_adsb_haversine_nm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusNm = 3440.065;
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);

    $a = sin($deltaLat / 2) ** 2
        + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusNm * $c;
}

function tv_adsb_cardinal(float $bearing): string
{
    $bearing = fmod(($bearing + 360.0), 360.0);
    $labels = array('North', 'North-East', 'East', 'South-East', 'South', 'South-West', 'West', 'North-West');
    $idx = (int)round($bearing / 45.0) % 8;
    return $labels[$idx];
}

function tv_adsb_bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLon = deg2rad($lon2 - $lon1);
    $y = sin($deltaLon) * cos($lat2Rad);
    $x = cos($lat1Rad) * sin($lat2Rad) - sin($lat1Rad) * cos($lat2Rad) * cos($deltaLon);
    return rad2deg(atan2($y, $x));
}

function tv_adsb_position(array $aircraft): ?array
{
    $lat = $aircraft['lat'] ?? null;
    $lon = $aircraft['lon'] ?? null;
    if (is_numeric($lat) && is_numeric($lon)) {
        return array('lat' => (float)$lat, 'lon' => (float)$lon);
    }

    $last = $aircraft['lastPosition'] ?? null;
    if (is_array($last) && is_numeric($last['lat'] ?? null) && is_numeric($last['lon'] ?? null)) {
        return array('lat' => (float)$last['lat'], 'lon' => (float)$last['lon']);
    }

    foreach (array('lastpos', 'lastPos', 'position') as $key) {
        $candidate = $aircraft[$key] ?? null;
        if (!is_array($candidate)) {
            continue;
        }
        if (is_numeric($candidate['lat'] ?? null) && is_numeric($candidate['lon'] ?? null)) {
            return array('lat' => (float)$candidate['lat'], 'lon' => (float)$candidate['lon']);
        }
    }

    return null;
}

function tv_adsb_last_history_position(array $cache): ?array
{
    $history = isset($cache['history']) && is_array($cache['history']) ? $cache['history'] : array();
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $sample = $history[$i];
        if (!is_array($sample)) {
            continue;
        }
        if (is_numeric($sample['lat'] ?? null) && is_numeric($sample['lon'] ?? null)) {
            return array(
                'lat' => (float)$sample['lat'],
                'lon' => (float)$sample['lon'],
                'age_s' => max(0, time() - (int)($sample['t'] ?? time())),
            );
        }
    }

    return null;
}

function tv_adsb_assume_parked_off_radar(array $gate): bool
{
    return (int)($gate['assume_parked_off_radar'] ?? 1) === 1;
}

function tv_adsb_persist_last_known(array &$cache, float $lat, float $lon, float $gs = 0.0): void
{
    $cache['last_known_lat'] = $lat;
    $cache['last_known_lon'] = $lon;
    $cache['last_known_gs'] = $gs;
    $cache['last_known_at'] = time();
}

function tv_adsb_cached_last_known(array $cache, int $maxAgeSeconds = 7776000): ?array
{
    if (!isset($cache['last_known_lat'], $cache['last_known_lon'])) {
        return null;
    }

    $age = max(0, time() - (int)($cache['last_known_at'] ?? 0));
    if ($age > $maxAgeSeconds) {
        return null;
    }

    return array(
        'lat' => (float)$cache['last_known_lat'],
        'lon' => (float)$cache['last_known_lon'],
        'gs' => (float)($cache['last_known_gs'] ?? 0),
        'age_s' => $age,
    );
}

function tv_adsb_hydrate_last_known_from_cache(array &$cache): void
{
    if (isset($cache['last_known_lat'], $cache['last_known_lon'])) {
        return;
    }

    $debug = $cache['last_status']['debug'] ?? null;
    if (!is_array($debug) || !isset($debug['lat'], $debug['lon'])) {
        return;
    }

    tv_adsb_persist_last_known(
        $cache,
        (float)$debug['lat'],
        (float)$debug['lon'],
        (float)($debug['ground_speed_kt'] ?? 0)
    );
}

function tv_adsb_synthetic_aircraft_for_off_radar(array $track, array $cache, array $gate): ?array
{
    $lastKnown = tv_adsb_cached_last_known($cache);
    if ($lastKnown === null) {
        $history = tv_adsb_last_history_position($cache);
        if ($history !== null) {
            $lastKnown = array(
                'lat' => (float)$history['lat'],
                'lon' => (float)$history['lon'],
                'gs' => 0.0,
                'age_s' => (int)($history['age_s'] ?? 0),
            );
        }
    }

    if ($lastKnown !== null) {
        return array(
            'aircraft' => array(
                'hex' => (string)($track['hex'] ?? ''),
                'r' => (string)($track['label'] ?? ''),
                'lat' => $lastKnown['lat'],
                'lon' => $lastKnown['lon'],
                'gs' => 0.0,
                'alt_baro' => 'ground',
            ),
            'position_source' => 'last_known',
            'position_age_s' => (int)($lastKnown['age_s'] ?? 0),
        );
    }

    if ((string)($track['hex'] ?? '') === '' || !tv_adsb_assume_parked_off_radar($gate)) {
        return null;
    }

    return array(
        'aircraft' => array(
            'hex' => (string)$track['hex'],
            'r' => (string)($track['label'] ?? ''),
            'lat' => (float)($gate['lat'] ?? tv_adsb_default_gate()['lat']),
            'lon' => (float)($gate['lon'] ?? tv_adsb_default_gate()['lon']),
            'gs' => 0.0,
            'alt_baro' => 'ground',
        ),
        'position_source' => 'assumed_ramp',
        'position_age_s' => null,
    );
}

function tv_adsb_altitude_ft(array $aircraft): ?float
{
    $alt = $aircraft['alt_baro'] ?? $aircraft['alt_geom'] ?? null;
    if ($alt === 'ground') {
        return 0.0;
    }
    if (is_numeric($alt)) {
        return (float)$alt;
    }
    return null;
}

function tv_adsb_is_on_ground(array $aircraft): bool
{
    $alt = $aircraft['alt_baro'] ?? null;
    if ($alt === 'ground') {
        return true;
    }

    $altFt = tv_adsb_altitude_ft($aircraft);
    $gs = (float)($aircraft['gs'] ?? 0);
    if ($altFt !== null && $altFt <= 50 && $gs < 40) {
        return true;
    }

    return false;
}

function tv_adsb_is_taxiing(array $aircraft): bool
{
    if (!tv_adsb_is_on_ground($aircraft)) {
        return false;
    }
    $gs = (float)($aircraft['gs'] ?? 0);
    return $gs >= 5.0 && $gs <= 60.0;
}

function tv_adsb_nearest_airport(float $lat, float $lon, float $maxNm = 5.0): ?array
{
    $best = null;
    $bestDist = $maxNm;

    foreach (tv_adsb_airports() as $icao => $airport) {
        $dist = tv_adsb_haversine_nm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
        if ($dist <= $bestDist) {
            $bestDist = $dist;
            $best = array(
                'icao' => $icao,
                'name' => (string)$airport['name'],
                'distance_nm' => round($dist, 1),
            );
        }
    }

    return $best;
}

require_once __DIR__ . '/tv_adsb_geofence.php';
require_once __DIR__ . '/tv_adsb_fsm.php';
require_once __DIR__ . '/tv_adsb_operations.php';
require_once __DIR__ . '/tv_adsb_announcements.php';

function tv_adsb_resolve_track(array $input): array
{
    $hex = tv_adsb_normalize_hex((string)($input['hex'] ?? ($input['aircraft_hex'] ?? ($input['body'] ?? ''))));
    $label = tv_adsb_normalize_label((string)($input['label'] ?? ($input['aircraft_label'] ?? ($input['title'] ?? ''))));
    $homeAirport = tv_adsb_normalize_home_airport((string)($input['home_airport'] ?? ($input['aircraft_home_airport'] ?? '')));

    if ($hex === '' && $label !== '') {
        $hex = tv_adsb_normalize_hex((string)($input['body'] ?? ''));
    }

    return array(
        'hex' => $hex,
        'label' => $label,
        'type' => tv_adsb_normalize_type((string)($input['type'] ?? ($input['aircraft_type'] ?? ''))),
        'home_airport' => $homeAirport,
    );
}

function tv_adsb_format_status(
    array $track,
    ?array $aircraft,
    array $gate,
    string $homeAirport,
    array &$cache = array()
): array {
    $track['type'] = tv_adsb_normalize_type((string)($track['type'] ?? ''));
    return tv_adsb_classify_operations($track, $aircraft, $gate, $homeAirport, $cache);
}

function tv_adsb_build_status(array $trackInput, array $options = array()): array
{
    $track = tv_adsb_resolve_track($trackInput);
    if ($track['hex'] === '' && $track['label'] === '') {
        throw new InvalidArgumentException('Aircraft hex or label is required.');
    }

    $gate = array_merge(tv_adsb_default_gate(), (array)($options['gate'] ?? array()));
    $homeAirport = tv_adsb_normalize_home_airport((string)($options['home_airport'] ?? $track['home_airport']));
    $track['home_airport'] = $homeAirport;
    $cache = tv_adsb_load_cache($track);
    tv_adsb_hydrate_last_known_from_cache($cache);

    $aircraft = null;
    $source = 'cache';

    try {
        if ($track['hex'] !== '') {
            $aircraft = tv_adsb_fetch_by_hex($track['hex']);
            if ($aircraft !== null) {
                $source = 'live';
            }
        }

        if ($aircraft === null && $track['label'] !== '') {
            $aircraft = tv_adsb_fetch_by_registration($track['label']);
            if ($aircraft !== null) {
                $source = 'live';
            }
        }

        if ($aircraft === null) {
            $fallbackHex = tv_adsb_normalize_hex((string)($cache['hex'] ?? ''));
            if ($fallbackHex !== '') {
                $aircraft = tv_adsb_fetch_by_hex($fallbackHex);
                if ($aircraft !== null) {
                    $source = 'last_known';
                }
            }
        }
    } catch (Throwable $e) {
        if (!empty($cache['last_status']) && is_array($cache['last_status'])) {
            $stale = $cache['last_status'];
            $stale['stale'] = true;
            $stale['error'] = $e->getMessage();
            $stale['source'] = 'cache';
            $stale['server_time'] = gmdate('c');
            return $stale;
        }
        throw $e;
    }

    if ($aircraft === null && !empty($cache['last_status']) && is_array($cache['last_status'])) {
        $lastCode = (string)($cache['last_status']['status_code'] ?? '');
        $retryOffRadar = tv_adsb_assume_parked_off_radar($gate)
            && $track['hex'] !== ''
            && in_array($lastCode, array('off_radar', 'position_unknown', 'unknown'), true);
        if (!$retryOffRadar) {
            $stale = $cache['last_status'];
            $stale['stale'] = true;
            $stale['source'] = 'cache';
            $stale['server_time'] = gmdate('c');
            return $stale;
        }
    }

    $status = tv_adsb_format_status($track, $aircraft, $gate, $homeAirport, $cache);
    $status['source'] = $source;
    $status['server_time'] = gmdate('c');
    $positionSource = (string)($status['position_source'] ?? 'live');
    $status['stale'] = $positionSource !== 'live' || (bool)($status['stale'] ?? false);
    $status['live'] = $positionSource === 'live' && (bool)($status['live'] ?? false);

    $announceEnabled = (bool)($options['announce_audio_enabled'] ?? false);
    $announcement = tv_adsb_maybe_announcement($status, $cache, $gate, $announceEnabled);
    if ($announcement !== null) {
        $status['announcement'] = $announcement;
    }

    if ($aircraft !== null && !empty($aircraft['hex'])) {
        $cache['hex'] = tv_adsb_normalize_hex((string)$aircraft['hex']);
    }
    $cache['last_status'] = $status;
    tv_adsb_save_cache($track, $cache);

    return $status;
}
