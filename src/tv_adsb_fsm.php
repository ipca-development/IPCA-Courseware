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
        'touch_and_go',
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
        'touch_and_go' => 'pattern',
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
        'touch_and_go' => 'O',
        default => '?',
    };
}

function tv_adsb_fsm_touch_go_prune_events(array &$cache, int $maxAgeSeconds = 3600): array
{
    $events = isset($cache['touch_go_events']) && is_array($cache['touch_go_events'])
        ? $cache['touch_go_events']
        : array();
    $cutoff = time() - $maxAgeSeconds;
    $events = array_values(array_filter($events, static function ($timestamp) use ($cutoff): bool {
        return (int)$timestamp >= $cutoff;
    }));
    sort($events, SORT_NUMERIC);

    $deduped = array();
    $lastKept = 0;
    foreach ($events as $timestamp) {
        $timestamp = (int)$timestamp;
        if ($lastKept === 0 || ($timestamp - $lastKept) >= 90) {
            $deduped[] = $timestamp;
            $lastKept = $timestamp;
        }
    }

    $cache['touch_go_events'] = $deduped;

    return $deduped;
}

function tv_adsb_fsm_touch_go_note_surface(array &$cache, array $obs): void
{
    $nearest = $obs['nearest_airport'] ?? null;
    $dist = is_array($nearest) ? (float)($nearest['distance_nm'] ?? 99) : 99.0;
    if ($dist > 3.5) {
        return;
    }

    $cache['touch_go_last_surface_at'] = time();
}

function tv_adsb_fsm_touch_go_note_transition(string $from, string $to, array &$cache, array $obs): void
{
    if ($to !== 'taking_off' || !in_array($from, array('landed', 'landing'), true)) {
        return;
    }

    $nearest = $obs['nearest_airport'] ?? null;
    $dist = is_array($nearest) ? (float)($nearest['distance_nm'] ?? 99) : 99.0;
    if ($dist > 3.5) {
        return;
    }

    $lastSurface = (int)($cache['touch_go_last_surface_at'] ?? 0);
    if ($lastSurface <= 0 || (time() - $lastSurface) > 300) {
        return;
    }

    $events = tv_adsb_fsm_touch_go_prune_events($cache, 3600);
    $lastEvent = count($events) > 0 ? (int)$events[count($events) - 1] : 0;
    if ($lastEvent > 0 && (time() - $lastEvent) < 90) {
        return;
    }

    $events[] = time();
    $cache['touch_go_events'] = $events;
    $cache['touch_go_session_until'] = time() + 2700;
}

function tv_adsb_fsm_touch_go_active(array $cache, array $obs, string $state): bool
{
    if (in_array($state, array('parked_at_spc', 'taxiing_in', 'taxiing_out', 'off_radar', 'position_unknown'), true)) {
        return false;
    }

    $events = tv_adsb_fsm_touch_go_prune_events($cache, 3600);
    if (count($events) < 1) {
        return false;
    }

    $sessionUntil = (int)($cache['touch_go_session_until'] ?? 0);
    $lastEvent = (int)max($events);
    if ($sessionUntil < time() && (time() - $lastEvent) > 2700) {
        return false;
    }

    if (!in_array($state, array('taking_off', 'landing', 'landed', 'in_flight'), true)) {
        return false;
    }

    $nearest = $obs['nearest_airport'] ?? null;
    $airportDist = is_array($nearest) ? (float)($nearest['distance_nm'] ?? 99) : 99.0;
    $home = tv_adsb_normalize_home_airport((string)($obs['home_airport'] ?? ''));
    $homeRef = tv_adsb_airports()[$home] ?? tv_adsb_airports()['KTRM'];
    $homeDist = tv_adsb_haversine_nm(
        (float)($obs['lat'] ?? 0),
        (float)($obs['lon'] ?? 0),
        (float)$homeRef['lat'],
        (float)$homeRef['lon']
    );

    if ($airportDist > 6.0 && $homeDist > 6.0) {
        return false;
    }

    if ($state === 'in_flight') {
        $alt = $obs['alt'] ?? null;
        if ($alt !== null && (float)$alt > 4500.0) {
            return false;
        }
        if ($homeDist > 8.0) {
            return false;
        }
    }

    return true;
}

function tv_adsb_fsm_touch_go_count_from_history(array $history): int
{
    if (count($history) < 4) {
        return 0;
    }

    $count = 0;
    $phase = 'idle';
    $cycleStart = 0;
    $lastCountAt = 0;

    foreach ($history as $sample) {
        if (!is_array($sample)) {
            continue;
        }

        $timestamp = (int)($sample['t'] ?? 0);
        $gs = (float)($sample['gs'] ?? 0);
        $onSurface = (bool)($sample['on_surface'] ?? false);
        $spcDist = (float)($sample['spc_dist_nm'] ?? 99);
        $nearAirport = $onSurface || $spcDist <= 6.0;

        if (!$nearAirport) {
            $phase = 'idle';
            $cycleStart = 0;
            continue;
        }

        if ($phase === 'idle' && $gs >= 38.0) {
            $phase = 'approaching';
        }

        if ($phase === 'approaching' && $onSurface && $gs >= 12.0 && $gs <= 95.0) {
            $phase = 'rolling';
            $cycleStart = $timestamp;
            continue;
        }

        if ($phase === 'rolling' && $cycleStart > 0 && $gs >= 52.0 && ($timestamp - $cycleStart) <= 420) {
            if ($lastCountAt === 0 || ($timestamp - $lastCountAt) >= 60) {
                $count++;
                $lastCountAt = $timestamp;
            }
            $phase = 'departed';
            $cycleStart = 0;
            continue;
        }

        if ($phase === 'departed' && (!$onSurface || $gs >= 75.0)) {
            $phase = 'idle';
        }

        if ($phase === 'rolling' && $cycleStart > 0 && ($timestamp - $cycleStart) > 420) {
            $phase = 'idle';
            $cycleStart = 0;
        }
    }

    return $count;
}

function tv_adsb_fsm_touch_go_count(array &$cache, array $obs): int
{
    $events = tv_adsb_fsm_touch_go_prune_events($cache, 3600);
    $fromEvents = count($events);
    $fromHistory = tv_adsb_fsm_touch_go_count_from_history($obs['history'] ?? array());

    // FSM transitions are one count per completed touchdown→relaunch cycle.
    // History is a fallback and must not inflate the count with per-sample noise.
    if ($fromEvents > 0) {
        $count = $fromEvents;
        if ($fromHistory > $fromEvents && $fromHistory <= ($fromEvents + 1)) {
            $count = $fromHistory;
        }
    } else {
        $count = $fromHistory;
    }

    $cache['touch_go_session_count'] = $count;

    return $count;
}

function tv_adsb_fsm_touch_go_status(string $state, array $obs, array &$cache): ?string
{
    if (!tv_adsb_fsm_touch_go_active($cache, $obs, $state)) {
        return null;
    }

    $cache['touch_go_session_until'] = time() + 1800;

    $nearest = $obs['nearest_airport'] ?? null;
    $icao = is_array($nearest) ? strtoupper((string)($nearest['icao'] ?? '')) : '';
    if ($icao === '') {
        $icao = tv_adsb_normalize_home_airport((string)($obs['home_airport'] ?? 'KTRM'));
    }

    $count = tv_adsb_fsm_touch_go_count($cache, $obs);
    $suffix = $count > 0 ? ' ' . $count : '';

    return match ($state) {
        'taking_off' => 'T&G DEPART ' . $icao . $suffix,
        'landing' => 'T&G FINAL ' . $icao . $suffix,
        'landed' => 'T&G DOWN ' . $icao . $suffix,
        'in_flight' => 'T&G PATTERN ' . $icao . $suffix,
        default => 'T&G TRAINING ' . $icao . $suffix,
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

    $spcDist = (float)($obs['spc_dist_nm'] ?? 99);

    if ($onSurface && $inSpc && $gs < 2.0 && count($history) < 2) {
        return 'parked_at_spc';
    }

    if ($onSurface && ($inSpc || $spcDist <= 0.25) && $gs < 10.0) {
        $variation = count($history) < 2 ? 0.0 : tv_adsb_position_variation_m($history, 300);
        $maxVariation = $gs < 5.0 ? 80.0 : 35.0;
        if ($variation <= $maxVariation) {
            return 'parked_at_spc';
        }
    }

    if ($wasParked && !$inSpc && $onSurface && $gs > 2.0 && $spcTrend > 0.0008) {
        return 'taxiing_out';
    }

    if ($onSurface && !$inSpc && $gs > 10.0 && $gs <= 40.0 && $spcTrend < -0.0008 && $spcDist < 2.0) {
        return 'taxiing_in';
    }

    if ($inSpc && $gs > 15.0) {
        if ($onSurface && $airportDist <= 3.0) {
            return $gs >= 40.0 ? 'landing' : 'landed';
        }
        if ($airportDist <= 5.0 && $vr < -50.0) {
            return 'landing';
        }
        if (!$onSurface && $alt !== null && $alt > 500.0) {
            return 'in_flight';
        }
    }

    if ($inSpc && $gs < 10.0 && in_array($state, array('taxiing_in', 'taxiing_out', 'unknown', 'landed'), true)) {
        return 'parked_at_spc';
    }

    if ($inSpc && $gs < 1.5 && ($state === 'parked_at_spc' || ($cache['fsm_last_confirmed'] ?? '') === 'parked_at_spc')) {
        return 'parked_at_spc';
    }

    if ($inSpc && $gs < 1.0 && tv_adsb_position_variation_m($history, 300) <= 15.0
        && tv_adsb_fsm_parked_dwell_seconds($history, $inSpc) >= 120) {
        return 'parked_at_spc';
    }

    if ($inSpc && $gs < 1.0 && count($history) >= 2
        && tv_adsb_position_variation_m($history, 180) <= 15.0) {
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

function tv_adsb_fsm_apply_parked_state(array &$cache): void
{
    $cache['fsm_state'] = 'parked_at_spc';
    $cache['fsm_last_confirmed'] = 'parked_at_spc';
    $cache['fsm_state_since'] = time();
    $cache['fsm_pending_state'] = '';
    $cache['fsm_pending_count'] = 0;
    unset($cache['off_block_at'], $cache['departure_airport']);
    unset(
        $cache['touch_go_events'],
        $cache['touch_go_session_until'],
        $cache['touch_go_last_surface_at'],
        $cache['touch_go_session_count']
    );
}

function tv_adsb_fsm_apply_state(string $state, array &$cache, array $obs): void
{
    $previous = (string)($cache['fsm_state'] ?? '');
    tv_adsb_fsm_touch_go_note_transition($previous, $state, $cache, $obs);

    $cache['fsm_state'] = $state;
    $cache['fsm_last_confirmed'] = $state;
    $cache['fsm_state_since'] = time();
    $cache['fsm_pending_state'] = '';
    $cache['fsm_pending_count'] = 0;

    if (in_array($state, array('landed', 'landing'), true)) {
        tv_adsb_fsm_touch_go_note_surface($cache, $obs);
    }

    if ($state === 'taking_off') {
        $cache['fsm_takeoff_hold_until'] = time() + 60;
        $nearest = $obs['nearest_airport'] ?? null;
        if (is_array($nearest) && !empty($nearest['name'])) {
            $cache['departure_airport'] = (string)$nearest['name'];
        }
    }

    if ($state === 'taxiing_out' && empty($cache['off_block_at'])) {
        $cache['off_block_at'] = time();
    }

    if ($state === 'landed') {
        $cache['fsm_landed_hold_until'] = time() + 300;
    }

    if ($state === 'parked_at_spc') {
        unset($cache['off_block_at'], $cache['departure_airport']);
    }
}

function tv_adsb_fsm_confirm_transition(string $candidate, array &$cache, array $obs): string
{
    $current = (string)($cache['fsm_state'] ?? '');
    $gs = (float)($obs['gs'] ?? 0);
    $inSpc = (bool)($obs['in_spc_parking'] ?? false);
    $spcDist = (float)($obs['spc_dist_nm'] ?? 99);

    if ($candidate === 'parked_at_spc' && $gs < 10.0 && ($inSpc || $spcDist <= 0.05)) {
        tv_adsb_fsm_apply_parked_state($cache);
        return 'parked_at_spc';
    }

    $taxiStates = array('taxiing_in', 'taxiing_out');
    $flightStates = array('landing', 'landed', 'taking_off', 'in_flight');
    if (in_array($current, $taxiStates, true)
        && in_array($candidate, $flightStates, true)
        && $gs >= 35.0) {
        tv_adsb_fsm_apply_state($candidate, $cache, $obs);
        return $candidate;
    }

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

    if ($candidate === 'parked_at_spc' && $dwell < 120) {
        return $current !== '' ? $current : $candidate;
    }

    if ((int)$cache['fsm_pending_count'] < $required) {
        return $current !== '' ? $current : $candidate;
    }

    tv_adsb_fsm_apply_state($candidate, $cache, $obs);

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
            if ((bool)($obs['in_spc_parking'] ?? false) && (float)($obs['gs'] ?? 0) < 2.0) {
                return 'PARKED AT SPC';
            }
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

    $positionAgeS = null;
    if ($aircraft === null) {
        $synthetic = tv_adsb_synthetic_aircraft_for_off_radar($track, $cache, $gate);
        if ($synthetic === null) {
            return tv_adsb_status_row('?', 'off_radar', 'AWAITING ADS-B', array_merge($base, array(
                'icon_code' => 'unknown',
                'aircraft_display' => $label,
            )));
        }
        $aircraft = $synthetic['aircraft'];
        $positionSource = (string)$synthetic['position_source'];
        $positionAgeS = $synthetic['position_age_s'] ?? null;
        $base['stale'] = true;
        $base['live'] = false;
    }

    $position = tv_adsb_position($aircraft);
    if (!isset($positionSource)) {
        $positionSource = 'live';
    }
    if ($position === null) {
        $cachedPosition = tv_adsb_last_history_position($cache);
        if ($cachedPosition !== null && (int)($cachedPosition['age_s'] ?? 99999) <= 7776000) {
            $position = array(
                'lat' => (float)$cachedPosition['lat'],
                'lon' => (float)$cachedPosition['lon'],
            );
            $positionSource = 'cache_history';
            $positionAgeS = (int)($cachedPosition['age_s'] ?? 0);
            $base['stale'] = true;
        }
    }
    if ($position === null) {
        $lastKnown = tv_adsb_cached_last_known($cache);
        if ($lastKnown !== null) {
            $position = array(
                'lat' => (float)$lastKnown['lat'],
                'lon' => (float)$lastKnown['lon'],
            );
            $positionSource = 'last_known';
            $positionAgeS = (int)($lastKnown['age_s'] ?? 0);
            $base['stale'] = true;
            $aircraft['gs'] = 0.0;
            $aircraft['alt_baro'] = 'ground';
        }
    }
    if ($position === null) {
        $synthetic = tv_adsb_synthetic_aircraft_for_off_radar($track, $cache, $gate);
        if ($synthetic !== null) {
            $aircraft = array_merge($aircraft, $synthetic['aircraft']);
            $position = array(
                'lat' => (float)$synthetic['aircraft']['lat'],
                'lon' => (float)$synthetic['aircraft']['lon'],
            );
            $positionSource = (string)$synthetic['position_source'];
            $positionAgeS = $synthetic['position_age_s'] ?? null;
            $base['stale'] = true;
            $base['live'] = false;
        }
    }

    $hex = tv_adsb_normalize_hex((string)($aircraft['hex'] ?? ($track['hex'] ?? '')));
    $gs = tv_adsb_airspeed($aircraft);
    $gsRounded = round($gs, 0);
    $altFt = tv_adsb_altitude_ft($aircraft);
    $vr = tv_adsb_vertical_rate($aircraft);
    $seen = isset($aircraft['seen']) && is_numeric($aircraft['seen']) ? (float)$aircraft['seen'] : null;
    $live = $positionSource === 'live' && ($seen === null || $seen <= 90.0);
    if ($positionSource !== 'live') {
        $gs = 0.0;
        $gsRounded = 0;
        $base['live'] = false;
        $base['stale'] = true;
    }

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

    if ($positionSource === 'live') {
        tv_adsb_persist_last_known($cache, (float)$position['lat'], (float)$position['lon'], $gs);
        tv_adsb_record_motion_sample($cache, $aircraft, $gate, $homeAirport);
    }

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

    $obs['home_airport'] = $homeAirport;

    $candidate = tv_adsb_fsm_candidate($obs, $cache);
    $state = tv_adsb_fsm_confirm_transition($candidate, $cache, $obs);
    $fsmState = $state;
    $statusText = tv_adsb_fsm_build_display($state, $obs, $cache, $gate);
    $symbol = tv_adsb_fsm_symbol($state);
    $iconCode = tv_adsb_fsm_icon_code($state);

    $touchGoStatus = tv_adsb_fsm_touch_go_status($state, $obs, $cache);
    $touchGoCount = (int)($cache['touch_go_session_count'] ?? 0);
    if ($touchGoStatus !== null) {
        $statusText = $touchGoStatus;
        $state = 'touch_and_go';
        $symbol = 'O';
        $iconCode = 'pattern';
        $touchGoCount = (int)($cache['touch_go_session_count'] ?? 0);
    }

    if ($statusText === 'PARKED AT SPC' && $symbol === '?') {
        $symbol = 'P';
        $iconCode = 'parked';
    }

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
        'touch_go_count' => $touchGoCount,
        'position_source' => $positionSource,
        'debug' => array(
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
            'ground_speed_kt' => $gsRounded,
            'alt_ft' => tv_adsb_altitude_ft($aircraft),
            'track_deg' => isset($aircraft['track']) && is_numeric($aircraft['track'])
                ? (float)$aircraft['track']
                : (isset($aircraft['true_heading']) && is_numeric($aircraft['true_heading'])
                    ? (float)$aircraft['true_heading']
                    : (isset($aircraft['mag_heading']) && is_numeric($aircraft['mag_heading'])
                        ? (float)$aircraft['mag_heading']
                        : null)),
            'in_spc_parking' => (bool)($obs['in_spc_parking'] ?? false),
            'spc_dist_nm' => round((float)($obs['spc_dist_nm'] ?? 0), 3),
            'on_surface' => (bool)($obs['on_surface'] ?? false),
            'fsm_state' => $fsmState,
            'fsm_candidate' => $candidate,
            'touch_go_events' => count(tv_adsb_fsm_touch_go_prune_events($cache, 3600)),
            'touch_go_history' => tv_adsb_fsm_touch_go_count_from_history($history),
            'touch_go_count' => $touchGoCount,
            'touch_go_active' => $touchGoStatus !== null,
            'history_samples' => count($history),
            'position_source' => $positionSource,
            'position_age_s' => $positionAgeS,
            'gate_lat' => (float)($gate['lat'] ?? 0),
            'gate_lon' => (float)($gate['lon'] ?? 0),
            'gate_radius_nm' => (float)($gate['radius_nm'] ?? 0),
        ),
    ));
}
