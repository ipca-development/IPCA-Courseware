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
    if ($aircraft === null) {
        return;
    }

    $position = tv_adsb_position($aircraft);
    if ($position === null) {
        return;
    }

    $home = tv_adsb_airports()[tv_adsb_normalize_home_airport($homeAirport)] ?? tv_adsb_airports()['KTRM'];
    $sample = array(
        't' => time(),
        'lat' => $position['lat'],
        'lon' => $position['lon'],
        'gs' => tv_adsb_airspeed($aircraft),
        'alt' => tv_adsb_altitude_ft($aircraft),
        'vr' => tv_adsb_vertical_rate($aircraft),
        'gate_nm' => tv_adsb_haversine_nm($position['lat'], $position['lon'], (float)$gate['lat'], (float)$gate['lon']),
        'home_nm' => tv_adsb_haversine_nm($position['lat'], $position['lon'], (float)$home['lat'], (float)$home['lon']),
    );

    $history = isset($cache['history']) && is_array($cache['history']) ? $cache['history'] : array();
    $history[] = $sample;
    if (count($history) > 80) {
        $history = array_slice($history, -80);
    }
    $cache['history'] = $history;
}

function tv_adsb_history_trend(string $field, array $history, int $minSamples = 3): float
{
    if (count($history) < $minSamples) {
        return 0.0;
    }
    $recent = array_slice($history, -$minSamples);
    $first = (float)($recent[0][$field] ?? 0);
    $last = (float)($recent[count($recent) - 1][$field] ?? 0);
    return $last - $first;
}

function tv_adsb_is_static_at_gate(array $history, float $gateRadiusNm, int $seconds = 300): bool
{
    if (count($history) < 4) {
        return false;
    }

    $cutoff = time() - $seconds;
    $samples = array_values(array_filter($history, static function (array $row) use ($cutoff): bool {
        return (int)($row['t'] ?? 0) >= $cutoff;
    }));

    if (count($samples) < 4) {
        return false;
    }

    foreach ($samples as $sample) {
        if ((float)($sample['gate_nm'] ?? 99) > $gateRadiusNm) {
            return false;
        }
        if ((float)($sample['gs'] ?? 99) >= 5.0) {
            return false;
        }
    }

    return true;
}

function tv_adsb_detect_off_block(array &$cache, array $history, float $gateRadiusNm): ?int
{
    if (!empty($cache['off_block_at'])) {
        return (int)$cache['off_block_at'];
    }

    $wasAtGate = false;
    foreach ($history as $sample) {
        $atGate = (float)($sample['gate_nm'] ?? 99) <= $gateRadiusNm && (float)($sample['gs'] ?? 0) < 5.0;
        $movingAway = (float)($sample['gate_nm'] ?? 0) > $gateRadiusNm && (float)($sample['gs'] ?? 0) >= 5.0;
        if ($atGate) {
            $wasAtGate = true;
        }
        if ($wasAtGate && $movingAway) {
            $cache['off_block_at'] = (int)($sample['t'] ?? time());
            return (int)$cache['off_block_at'];
        }
    }

    return null;
}

function tv_adsb_status_row(
    string $symbol,
    string $statusCode,
    string $statusText,
    array $base
): array {
    $label = tv_adsb_normalize_label((string)($base['label'] ?? ''));
    $type = tv_adsb_normalize_type((string)($base['type'] ?? ''));

    return array_merge($base, array(
        'symbol' => $symbol,
        'status_code' => $statusCode,
        'status_text' => $statusText,
        'status_label' => $statusText,
        'display' => $label . ' | ' . ($type !== '' ? $type : '--') . ' | ' . $statusText,
        'aircraft_display' => trim($symbol . ' ' . $label),
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
    $label = tv_adsb_normalize_label((string)($track['label'] ?? ''));
    if ($label === '') {
        $label = strtoupper((string)($track['hex'] ?? 'AIRCRAFT'));
    }

    $type = tv_adsb_normalize_type((string)($track['type'] ?? ''));
    $homeAirport = tv_adsb_normalize_home_airport($homeAirport);
    $gateRadius = (float)($gate['radius_nm'] ?? 0.18);
    $history = isset($cache['history']) && is_array($cache['history']) ? $cache['history'] : array();

    $base = array(
        'hex' => (string)($track['hex'] ?? ''),
        'label' => $label,
        'type' => $type,
        'home_airport' => $homeAirport,
        'live' => false,
        'stale' => false,
    );

    if ($aircraft === null) {
        return tv_adsb_status_row('?', 'off_radar', 'OFF RADAR', $base);
    }

    $position = tv_adsb_position($aircraft);
    $hex = tv_adsb_normalize_hex((string)($aircraft['hex'] ?? ($track['hex'] ?? '')));
    $ias = tv_adsb_airspeed($aircraft);
    $gs = isset($aircraft['gs']) && is_numeric($aircraft['gs']) ? round((float)$aircraft['gs'], 0) : null;
    $altFt = tv_adsb_altitude_ft($aircraft);
    $vr = tv_adsb_vertical_rate($aircraft);
    $seen = isset($aircraft['seen']) && is_numeric($aircraft['seen']) ? (float)$aircraft['seen'] : null;
    $live = $seen === null || $seen <= 90.0;

    $base['hex'] = $hex;
    $base['live'] = $live;
    $base['ground_speed_kt'] = $gs;
    $base['altitude_ft'] = $altFt;

    if ($position === null) {
        return tv_adsb_status_row('?', 'position_unknown', 'POSITION UNKNOWN', $base);
    }

    $lat = $position['lat'];
    $lon = $position['lon'];
    $reference = tv_adsb_airports()[$homeAirport] ?? tv_adsb_airports()['KTRM'];
    $gateDist = tv_adsb_haversine_nm($lat, $lon, (float)$gate['lat'], (float)$gate['lon']);
    $homeDist = tv_adsb_haversine_nm($lat, $lon, (float)$reference['lat'], (float)$reference['lon']);
    $nearest = tv_adsb_nearest_airport($lat, $lon, 8.0);
    $onGround = tv_adsb_is_on_ground($aircraft);
    $gateTrend = tv_adsb_history_trend('gate_nm', $history, 4);

    $base['distance_nm'] = round($homeDist, 1);
    $base['nearest_airport'] = $nearest;

    if (tv_adsb_is_static_at_gate($history, $gateRadius, 300) || ($onGround && $gateDist <= $gateRadius && $ias < 5.0)) {
        return tv_adsb_status_row('P', 'parked_spc', 'PARKED AT SPC', $base);
    }

    if ($onGround && $ias >= 5.0 && $ias <= 35.0 && $homeDist <= 4.0) {
        if ($gateTrend > 0.03) {
            $offBlock = tv_adsb_detect_off_block($cache, $history, $gateRadius);
            $timeText = $offBlock ? tv_adsb_format_local_time($offBlock) : tv_adsb_format_local_time(time());
            return tv_adsb_status_row('T', 'taxi_out', 'TAXIING OUT - OFF BLOCK ' . $timeText, $base);
        }
        if ($gateTrend < -0.03) {
            $etaMin = $ias > 0 ? ($gateDist / $ias) * 60.0 : 0.0;
            $etaText = tv_adsb_format_local_time(time() + (int)round($etaMin * 60));
            return tv_adsb_status_row('T', 'taxi_in', 'TAXIING IN - ETA ' . $etaText, $base);
        }
        if ($ias >= 5.0 && $ias <= 35.0) {
            return tv_adsb_status_row('T', 'taxiing', 'TAXIING', $base);
        }
    }

    if ($nearest !== null) {
        $airportDist = (float)$nearest['distance_nm'];
        $airportName = (string)$nearest['name'];
        $airportIcao = (string)$nearest['icao'];
        $airportElev = tv_adsb_airport_elevation($airportIcao);

        if ($onGround && $airportDist <= 2.0 && $ias < 30.0) {
            if ($altFt === null || $airportElev <= 0 || abs($altFt - $airportElev) <= 600) {
                return tv_adsb_status_row('G', 'landed', 'LANDED AT ' . $airportName, $base);
            }
        }

        if ($airportDist <= 6.0 && $ias >= 30.0 && $ias <= 80.0 && ($vr < -80.0 || ($altFt !== null && $airportElev > 0 && $altFt <= ($airportElev + 1200)))) {
            return tv_adsb_status_row('L', 'landing', 'LANDING AT ' . $airportName, $base);
        }

        if ($airportDist <= 3.0 && $ias > 30.0 && ($altFt === null || $airportElev <= 0 || $altFt <= ($airportElev + 1000))) {
            return tv_adsb_status_row('U', 'takeoff', 'TAKING OFF FROM ' . $airportName, $base);
        }
    }

    if (!$onGround || ($altFt !== null && $altFt > 300)) {
        $refLat = (float)$reference['lat'];
        $refLon = (float)$reference['lon'];
        $distanceNm = tv_adsb_haversine_nm($lat, $lon, $refLat, $refLon);
        $bearing = tv_adsb_bearing($refLat, $refLon, $lat, $lon);
        $direction = tv_adsb_cardinal($bearing);
        $altText = $altFt !== null ? number_format($altFt, 0) . ' FT' : 'UNKNOWN FT';
        return tv_adsb_status_row(
            'F',
            'in_flight',
            'IN FLIGHT, ' . number_format($distanceNm, 1) . ' NM ' . strtoupper($direction) . ' AT ' . $altText,
            array_merge($base, array(
                'distance_nm' => round($distanceNm, 1),
                'direction' => $direction,
            ))
        );
    }

    if ($nearest !== null) {
        return tv_adsb_status_row('G', 'on_ground', 'ON GROUND AT ' . $nearest['name'], $base);
    }

    return tv_adsb_status_row('?', 'unknown', 'STATUS UNKNOWN', $base);
}
