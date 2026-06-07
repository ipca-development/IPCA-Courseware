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
    $provider = strtolower(trim((string)(getenv('CW_ADSBEXCHANGE_PROVIDER') ?: 'rapidapi')));
    return $provider === 'gateway' ? 'gateway' : 'rapidapi';
}

function tv_adsb_api_config(): array
{
    if (tv_adsb_provider() === 'gateway') {
        return array(
            'base_url' => 'https://gateway.adsbexchange.com/api/aircraft/v2',
            'headers' => array(
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'X-Api-Key: ' . tv_adsb_api_key(),
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
        'KTRM' => array('name' => 'Thermal', 'lat' => 33.626701, 'lon' => -116.160156),
        'KBLH' => array('name' => 'Blythe', 'lat' => 33.619167, 'lon' => -114.716889),
        'KCRQ' => array('name' => 'Carlsbad', 'lat' => 33.128333, 'lon' => -117.280000),
        'KMYF' => array('name' => 'Montgomery Field', 'lat' => 32.815833, 'lon' => -117.139444),
        'KSAN' => array('name' => 'San Diego', 'lat' => 32.733556, 'lon' => -117.189667),
        'KPSP' => array('name' => 'Palm Springs', 'lat' => 33.829667, 'lon' => -116.506667),
        'KONT' => array('name' => 'Ontario', 'lat' => 34.056000, 'lon' => -117.601194),
        'KLAX' => array('name' => 'Los Angeles', 'lat' => 33.942500, 'lon' => -118.408056),
        'EBAW' => array('name' => 'Antwerp', 'lat' => 51.189444, 'lon' => 4.460278),
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
        throw new RuntimeException('ADS-B API returned HTTP ' . $code);
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

    return null;
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
        'home_airport' => $homeAirport,
    );
}

function tv_adsb_format_status(
    array $track,
    ?array $aircraft,
    array $gate,
    string $homeAirport
): array {
    $label = tv_adsb_normalize_label((string)($track['label'] ?? ''));
    if ($label === '') {
        $label = strtoupper((string)($track['hex'] ?? 'AIRCRAFT'));
    }

    $homeAirport = tv_adsb_normalize_home_airport($homeAirport);
    $gateLabel = trim((string)($gate['label'] ?? 'SPC Gate'));
    $reference = tv_adsb_airports()[$homeAirport] ?? tv_adsb_airports()['KTRM'];

    if ($aircraft === null) {
        return array(
            'hex' => (string)($track['hex'] ?? ''),
            'label' => $label,
            'home_airport' => $homeAirport,
            'status_code' => 'off_radar',
            'status_label' => 'Off Radar',
            'display' => $label . ' – Off Radar',
            'live' => false,
        );
    }

    $position = tv_adsb_position($aircraft);
    $hex = tv_adsb_normalize_hex((string)($aircraft['hex'] ?? ($track['hex'] ?? '')));
    $gs = isset($aircraft['gs']) && is_numeric($aircraft['gs']) ? round((float)$aircraft['gs'], 0) : null;
    $altFt = tv_adsb_altitude_ft($aircraft);
    $seen = isset($aircraft['seen']) && is_numeric($aircraft['seen']) ? (float)$aircraft['seen'] : null;
    $live = $seen === null || $seen <= 90.0;

    if ($position === null) {
        return array(
            'hex' => $hex,
            'label' => $label,
            'home_airport' => $homeAirport,
            'status_code' => 'position_unknown',
            'status_label' => 'Position Unknown',
            'display' => $label . ' – Position Unknown',
            'live' => $live,
            'ground_speed_kt' => $gs,
            'altitude_ft' => $altFt,
        );
    }

    $lat = $position['lat'];
    $lon = $position['lon'];
    $gateDist = tv_adsb_haversine_nm($lat, $lon, (float)$gate['lat'], (float)$gate['lon']);
    $homeDist = tv_adsb_haversine_nm($lat, $lon, (float)$reference['lat'], (float)$reference['lon']);
    $nearest = tv_adsb_nearest_airport($lat, $lon, 6.0);
    $onGround = tv_adsb_is_on_ground($aircraft);
    $taxiing = tv_adsb_is_taxiing($aircraft);

    if ($onGround && $gateDist <= (float)($gate['radius_nm'] ?? 0.18)) {
        return array(
            'hex' => $hex,
            'label' => $label,
            'home_airport' => $homeAirport,
            'status_code' => 'at_gate',
            'status_label' => 'At Gate',
            'display' => $label . ' – At the ' . $gateLabel,
            'live' => $live,
            'ground_speed_kt' => $gs,
            'altitude_ft' => $altFt,
            'distance_nm' => round($gateDist, 1),
            'nearest_airport' => $nearest,
        );
    }

    if ($taxiing && $homeDist <= 3.0) {
        return array(
            'hex' => $hex,
            'label' => $label,
            'home_airport' => $homeAirport,
            'status_code' => 'taxiing',
            'status_label' => 'Taxiing',
            'display' => $label . ' – Taxiing to RWY',
            'live' => $live,
            'ground_speed_kt' => $gs,
            'altitude_ft' => $altFt,
            'distance_nm' => round($homeDist, 1),
            'nearest_airport' => $nearest,
        );
    }

    if ($onGround && $nearest !== null) {
        $landedLabel = $label . ' – Landed in ' . $nearest['name'] . ' (' . $nearest['icao'] . ')';
        if ($nearest['icao'] === $homeAirport) {
            $landedLabel = $label . ' – On Ground at ' . $homeAirport;
        }

        return array(
            'hex' => $hex,
            'label' => $label,
            'home_airport' => $homeAirport,
            'status_code' => 'on_ground',
            'status_label' => 'On Ground',
            'display' => $landedLabel,
            'live' => $live,
            'ground_speed_kt' => $gs,
            'altitude_ft' => $altFt,
            'distance_nm' => $nearest['distance_nm'],
            'nearest_airport' => $nearest,
        );
    }

    $refLat = (float)$reference['lat'];
    $refLon = (float)$reference['lon'];
    $distanceNm = tv_adsb_haversine_nm($lat, $lon, $refLat, $refLon);
    $bearing = tv_adsb_bearing($refLat, $refLon, $lat, $lon);
    $direction = tv_adsb_cardinal($bearing);

    return array(
        'hex' => $hex,
        'label' => $label,
        'home_airport' => $homeAirport,
        'status_code' => 'in_flight',
        'status_label' => 'In Flight',
        'display' => $label . ' – In Flight (' . number_format($distanceNm, 1) . ' NM, ' . $direction . ')',
        'live' => $live,
        'ground_speed_kt' => $gs,
        'altitude_ft' => $altFt,
        'distance_nm' => round($distanceNm, 1),
        'bearing' => round($bearing, 0),
        'direction' => $direction,
        'nearest_airport' => $nearest,
    );
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
        $stale = $cache['last_status'];
        $stale['stale'] = true;
        $stale['source'] = 'cache';
        $stale['server_time'] = gmdate('c');
        return $stale;
    }

    $status = tv_adsb_format_status($track, $aircraft, $gate, $homeAirport);
    $status['source'] = $source;
    $status['server_time'] = gmdate('c');
    $status['stale'] = false;

    if ($aircraft !== null && !empty($aircraft['hex'])) {
        $cache['hex'] = tv_adsb_normalize_hex((string)$aircraft['hex']);
    }
    $cache['last_status'] = $status;
    tv_adsb_save_cache($track, $cache);

    return $status;
}
