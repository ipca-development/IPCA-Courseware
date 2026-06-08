<?php
declare(strict_types=1);

function tv_adsb_fsm_states(): array
{
    return array(
        'taking_off',
        'landing',
        'landed',
        'taxiing_out',
        'taxiing_in',
        'parked_at_spc',
        'in_flight',
        'off_radar',
        'position_unknown',
        'unknown',
    );
}

function tv_adsb_fsm_icon_code(string $state): string
{
    return match ($state) {
        'parked_at_spc' => 'parked',
        'taxiing_out', 'taxiing_in', 'taxiing' => 'taxi',
        'taking_off' => 'takeoff',
        'landing', 'landed' => 'arrival',
        'in_flight' => 'flight',
        default => 'unknown',
    };
}

function tv_adsb_fsm_symbol(string $state): string
{
    return match ($state) {
        'parked_at_spc' => 'P',
        'taxiing_out', 'taxiing_in', 'taxiing' => 'T',
        'taking_off' => 'U',
        'landing', 'landed' => 'L',
        'in_flight' => 'F',
        default => '?',
    };
}

function tv_adsb_record_motion_sample(
    array &$cache,
    ?array $aircraft,
    array $gate,
    string $homeAirport
): void {
    if ($aircraft === null) {
        return;
    }

    $position = tv_adsb_position($aircraft);
    if ($position === null) {
        return;
    }

    $lat = $position['lat'];
    $lon = $position['lon'];
    $spcPolygon = tv_adsb_spc_parking_polygon($gate);
    $home = tv_adsb_airports()[tv_adsb_normalize_home_airport($homeAirport)] ?? tv_adsb_airports()['KTRM'];
    $spcDist = tv_adsb_distance_to_polygon_nm($lat, $lon, $spcPolygon);

    $sample = array(
        't' => time(),
        'lat' => $lat,
        'lon' => $lon,
        'gs' => tv_adsb_airspeed($aircraft),
        'alt' => tv_adsb_altitude_ft($aircraft),
        'vr' => tv_adsb_vertical_rate($aircraft),
        'spc_dist_nm' => $spcDist,
        'in_spc_parking' => tv_adsb_point_in_polygon($lat, $lon, $spcPolygon),
        'on_surface' => tv_adsb_on_airport_surface($lat, $lon),
    );

    $history = isset($cache['history']) && is_array($cache['history']) ? $cache['history'] : array();
    $history[] = $sample;
    if (count($history) > 100) {
        $history = array_slice($history, -100);
    }
    $cache['history'] = $history;
}

function tv_adsb_motion_trend(string $field, array $history, int $samples = 4): float
{
    if (count($history) < $samples) {
        return 0.0;
    }
    $recent = array_slice($history, -$samples);
    $first = (float)($recent[0][$field] ?? 0);
    $last = (float)($recent[count($recent) - 1][$field] ?? 0);
    return $last - $first;
}

function tv_adsb_fsm_candidate(array $obs, array &$cache): string
{
    $state = (string)($cache['fsm_state'] ?? '');
    $now = time();
    $holdTakeoffUntil = (int)($cache['fsm_takeoff_hold_until'] ?? 0);
    $holdLandedUntil = (int)($cache['fsm_landed_hold_until'] ?? 0);

    if ($holdTakeoffUntil > $now && $state === 'taking_off') {
        return 'taking_off';
    }

    if ($holdLandedUntil > $now && in_array($state, array('landed', 'landing'), true)) {
        return $state === 'landing' ? 'landing' : 'landed';
    }

    $gs = (float)($obs['gs'] ?? 0);
    $alt = $obs['alt'];
    $vr = (float)($obs['vr'] ?? 0);
    $nearest = $obs['nearest_airport'] ?? null;
    $airportDist = is_array($nearest) ? (float)($nearest['distance_nm'] ?? 99) : 99.0;
    $airportIcao = is_array($nearest) ? (string)($nearest['icao'] ?? '') : '';
    $airportElev = $airportIcao !== '' ? tv_adsb_airport_elevation($airportIcao) : 0;
    $onSurface = (bool)($obs['on_surface'] ?? false);
    $inSpc = (bool)($obs['in_spc_parking'] ?? false);
    $spcTrend = (float)($obs['spc_trend'] ?? 0);
    $history = $obs['history'] ?? array();
    $wasParked = ($cache['fsm_state'] ?? '') === 'parked_at_spc'
        || ($cache['fsm_last_confirmed'] ?? '') === 'parked_at_spc';

    if ($onSurface && $airportDist <= 3.0 && $gs > 30.0 && ($vr > 80.0 || ($alt !== null && $airportElev > 0 && $alt > ($airportElev + 80)))) {
        return 'taking_off';
    }

    if ($airportDist <= 5.0 && $gs >= 30.0 && $gs <= 100.0 && $vr < -80.0
        && ($alt !== null && $airportElev > 0 && $alt <= ($airportElev + 1800))) {
        return 'landing';
    }

    if ($onSurface && $airportDist <= 2.5 && $gs < 30.0
        && ($alt === null || $airportElev <= 0 || abs($alt - $airportElev) <= 100.0)) {
        return 'landed';
    }

    if ($wasParked && !$inSpc && $onSurface && $gs > 2.0 && $spcTrend > 0.0008) {
        return 'taxiing_out';
    }

    if ($onSurface && $gs > 2.0 && $spcTrend < -0.0008 && (float)($obs['spc_dist_nm'] ?? 99) < 2.0) {
        return 'taxiing_in';
    }

    if ($inSpc && $gs < 1.0 && tv_adsb_position_variation_m($history, 300) <= 10.0
        && tv_adsb_fsm_parked_dwell_seconds($history, $inSpc) >= 300) {
        return 'parked_at_spc';
    }

    if (!$onSurface && $alt !== null && $alt > 500.0 && $gs > 40.0) {
        return 'in_flight';
    }

    if ($state !== '') {
        return $state;
    }

    return 'unknown';
}

function tv_adsb_fsm_parked_dwell_seconds(array $history, bool $inSpc): int
{
    if (!$inSpc || count($history) < 2) {
        return 0;
    }

    $dwell = 0;
    $samples = array_reverse($history);
    foreach ($samples as $sample) {
        if (empty($sample['in_spc_parking']) || (float)($sample['gs'] ?? 99) >= 1.0) {
            break;
        }
        $dwell = time() - (int)($sample['t'] ?? time());
    }

    return max(0, $dwell);
}

function tv_adsb_fsm_confirm_transition(string $candidate, array &$cache, array $obs): string
{
    $current = (string)($cache['fsm_state'] ?? '');
    if ($candidate === $current) {
        $cache['fsm_pending_state'] = '';
        $cache['fsm_pending_count'] = 0;
        return $current;
    }

    $pending = (string)($cache['fsm_pending_state'] ?? '');
    if ($pending !== $candidate) {
        $cache['fsm_pending_state'] = $candidate;
        $cache['fsm_pending_count'] = 1;
        $cache['fsm_pending_since'] = time();
        return $current !== '' ? $current : $candidate;
    }

    $cache['fsm_pending_count'] = (int)($cache['fsm_pending_count'] ?? 0) + 1;
    $required = tv_adsb_fsm_required_confirmations($candidate);
    $since = (int)($cache['fsm_pending_since'] ?? time());
    $dwell = time() - $since;

    if ($candidate === 'parked_at_spc' && $dwell < 300) {
        return $current !== '' ? $current : $candidate;
    }

    if ((int)$cache['fsm_pending_count'] < $required) {
        return $current !== '' ? $current : $candidate;
    }

    $cache['fsm_state'] = $candidate;
    $cache['fsm_last_confirmed'] = $candidate;
    $cache['fsm_state_since'] = time();
    $cache['fsm_pending_state'] = '';
    $cache['fsm_pending_count'] = 0;

    if ($candidate === 'taking_off') {
        $cache['fsm_takeoff_hold_until'] = time() + 60;
        $nearest = $obs['nearest_airport'] ?? null;
        if (is_array($nearest) && !empty($nearest['name'])) {
            $cache['departure_airport'] = (string)$nearest['name'];
        }
    }

    if ($candidate === 'taxiing_out' && empty($cache['off_block_at'])) {
        $cache['off_block_at'] = time();
    }

    if ($candidate === 'landed') {
        $cache['fsm_landed_hold_until'] = time() + 300;
    }

    if ($candidate === 'parked_at_spc') {
        unset($cache['off_block_at']);
        unset($cache['departure_airport']);
    }

    return $candidate;
}

function tv_adsb_fsm_required_confirmations(string $state): int
{
    return match ($state) {
        'landed', 'landing' => 2,
        'parked_at_spc' => 2,
        default => 1,
    };
}

function tv_adsb_fsm_build_display(string $state, array $obs, array &$cache, array $gate): string
{
    $nearest = $obs['nearest_airport'] ?? null;
    $airportIcao = is_array($nearest) ? strtoupper((string)($nearest['icao'] ?? '')) : '';
    $airportName = is_array($nearest) ? strtoupper((string)($nearest['name'] ?? 'AIRPORT')) : 'AIRPORT';
    $airportShort = $airportIcao !== '' ? $airportIcao : $airportName;
    $spcDist = (float)($obs['spc_dist_nm'] ?? 0);
    $gs = (float)($obs['gs'] ?? 0);

    switch ($state) {
        case 'parked_at_spc':
            return 'PARKED AT SPC';

        case 'taxiing_out':
            $offBlock = (int)($cache['off_block_at'] ?? time());
            return 'TAXI OUT OFF BLOCK ' . tv_adsb_format_local_time($offBlock);

        case 'taxiing_in':
            $etaMin = $gs > 2.0 ? ($spcDist / $gs) * 60.0 : 0.0;
            return 'TAXI IN ETA ' . tv_adsb_format_local_time(time() + (int)round($etaMin * 60));

        case 'taking_off':
            return 'TAKING OFF FROM ' . $airportShort;

        case 'landing':
            return 'LANDING ' . $airportShort;

        case 'landed':
            return 'LANDED AT ' . $airportShort;

        case 'in_flight':
            $altRounded = tv_adsb_round_altitude_100($obs['alt']);
            $altText = $altRounded !== null ? (string)$altRounded : '----';
            return 'IN FLIGHT ' . number_format((float)($obs['distance_spc_nm'] ?? 0), 1)
                . ' NM ' . strtoupper((string)($obs['direction_spc'] ?? ''))
                . ' ' . $altText . ' FT';

        case 'off_radar':
            return 'AWAITING ADS-B';

        case 'position_unknown':
            return 'AWAITING POSITION';

        default:
            if ((float)($obs['gs'] ?? 0) > 0.0 || (bool)($obs['on_surface'] ?? false)) {
                return 'TRACKING';
            }
            return 'AWAITING ADS-B';
    }
}

function tv_adsb_fsm_tick(
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

    $base = array(
        'hex' => (string)($track['hex'] ?? ''),
        'label' => $label,
        'type' => $type,
        'home_airport' => $homeAirport,
        'live' => false,
        'stale' => false,
    );

    if ($aircraft === null) {
        return tv_adsb_status_row('?', 'off_radar', 'AWAITING ADS-B', array_merge($base, array(
            'icon_code' => 'unknown',
            'aircraft_display' => $label,
        )));
    }

    $position = tv_adsb_position($aircraft);
    $hex = tv_adsb_normalize_hex((string)($aircraft['hex'] ?? ($track['hex'] ?? '')));
    $gs = tv_adsb_airspeed($aircraft);
    $gsRounded = round($gs, 0);
    $altFt = tv_adsb_altitude_ft($aircraft);
    $vr = tv_adsb_vertical_rate($aircraft);
    $seen = isset($aircraft['seen']) && is_numeric($aircraft['seen']) ? (float)$aircraft['seen'] : null;
    $live = $seen === null || $seen <= 90.0;

    $base['hex'] = $hex;
    $base['live'] = $live;
    $base['ground_speed_kt'] = $gsRounded;
    $base['altitude_ft'] = $altFt;

    if ($position === null) {
        return tv_adsb_status_row('?', 'position_unknown', 'AWAITING POSITION', array_merge($base, array(
            'icon_code' => 'unknown',
            'aircraft_display' => $label,
        )));
    }

    tv_adsb_record_motion_sample($cache, $aircraft, $gate, $homeAirport);

    $lat = $position['lat'];
    $lon = $position['lon'];
    $reference = tv_adsb_airports()[$homeAirport] ?? tv_adsb_airports()['KTRM'];
    $spcPolygon = tv_adsb_spc_parking_polygon($gate);
    $history = isset($cache['history']) && is_array($cache['history']) ? $cache['history'] : array();
    $nearest = tv_adsb_nearest_airport_boundary($lat, $lon, 8.0);

    $obs = array(
        'gs' => $gs,
        'alt' => $altFt,
        'vr' => $vr,
        'lat' => $lat,
        'lon' => $lon,
        'in_spc_parking' => tv_adsb_point_in_polygon($lat, $lon, $spcPolygon),
        'spc_dist_nm' => tv_adsb_distance_to_polygon_nm($lat, $lon, $spcPolygon),
        'spc_trend' => tv_adsb_motion_trend('spc_dist_nm', $history, 4),
        'on_surface' => tv_adsb_on_airport_surface($lat, $lon),
        'nearest_airport' => $nearest,
        'history' => $history,
        'distance_spc_nm' => tv_adsb_haversine_nm($lat, $lon, (float)$reference['lat'], (float)$reference['lon']),
        'direction_spc' => tv_adsb_short_cardinal(tv_adsb_bearing(
            (float)$reference['lat'],
            (float)$reference['lon'],
            $lat,
            $lon
        )),
    );

    $candidate = tv_adsb_fsm_candidate($obs, $cache);
    $state = tv_adsb_fsm_confirm_transition($candidate, $cache, $obs);
    $statusText = tv_adsb_fsm_build_display($state, $obs, $cache, $gate);
    $symbol = tv_adsb_fsm_symbol($state);
    $iconCode = tv_adsb_fsm_icon_code($state);

    return array_merge($base, array(
        'symbol' => $symbol,
        'icon_code' => $iconCode,
        'status_code' => $state,
        'status_text' => $statusText,
        'status_label' => $statusText,
        'display' => $label . ' | ' . ($type !== '' ? $type : '--') . ' | ' . $statusText,
        'aircraft_display' => $label,
        'type_display' => $type !== '' ? $type : '--',
        'distance_nm' => round((float)$obs['distance_spc_nm'], 1),
        'direction' => (string)$obs['direction_spc'],
        'nearest_airport' => $nearest,
    ));
}
