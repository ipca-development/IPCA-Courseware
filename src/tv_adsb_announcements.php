<?php
declare(strict_types=1);

function tv_adsb_announceable_status_codes(): array
{
    return array(
        'parked_at_spc',
        'taxiing_out',
        'taxiing_in',
        'taking_off',
        'landing',
        'landed',
        'in_flight',
    );
}

function tv_adsb_pa_gate_phrase(array $gate): string
{
    $label = trim((string)($gate['label'] ?? 'SoCal Pilot Center'));
    if ($label === '') {
        $label = 'SoCal Pilot Center';
    }
    if (stripos($label, 'gate') === false && stripos($label, 'center') === false) {
        $label .= ' Gate';
    }
    return $label;
}

function tv_adsb_airport_name_for_speech(array $status, array $cache): string
{
    $nearest = $status['nearest_airport'] ?? null;
    if (is_array($nearest) && !empty($nearest['name'])) {
        return (string)$nearest['name'];
    }

    $departure = trim((string)($cache['departure_airport'] ?? ''));
    if ($departure !== '') {
        return $departure;
    }

    $home = tv_adsb_normalize_home_airport((string)($status['home_airport'] ?? ''));
    $airports = tv_adsb_airports();
    if (isset($airports[$home]['name'])) {
        return (string)$airports[$home]['name'];
    }

    return 'the airport';
}

function tv_adsb_extract_eta_from_status(array $status): string
{
    $text = (string)($status['status_text'] ?? '');
    if (preg_match('/ETA\s+(\d{1,2}:\d{2})/i', $text, $matches) === 1) {
        return (string)$matches[1];
    }
    return tv_adsb_format_local_time(time());
}

function tv_adsb_speech_altitude(?float $altFt): string
{
    $rounded = tv_adsb_round_altitude_100($altFt);
    if ($rounded === null) {
        return 'unknown altitude';
    }
    return number_format($rounded) . ' feet';
}

function tv_adsb_build_event_speech(array $status, array $gate, array $cache): string
{
    $label = tv_adsb_normalize_label((string)($status['label'] ?? ''));
    if ($label === '') {
        return '';
    }

    $code = (string)($status['status_code'] ?? '');
    $airport = tv_adsb_airport_name_for_speech($status, $cache);

    switch ($code) {
        case 'parked_at_spc':
            return $label . ' is parked at ' . tv_adsb_pa_gate_phrase($gate) . '.';

        case 'taxiing_out':
            return $label . ' is taxiing out.';

        case 'taxiing_in':
            $eta = tv_adsb_extract_eta_from_status($status);
            return $label . ' is taxiing in and expected to arrive at '
                . tv_adsb_pa_gate_phrase($gate) . ' at ' . $eta . '.';

        case 'taking_off':
            return $label . ' is taking off from ' . $airport . '.';

        case 'landing':
            return $label . ' is landing at ' . $airport . '.';

        case 'landed':
            return $label . ' has landed.';

        case 'in_flight':
            $direction = strtolower((string)($status['direction'] ?? 'southwest'));
            $distance = number_format((float)($status['distance_nm'] ?? 0), 1);
            $altSpeech = tv_adsb_speech_altitude(isset($status['altitude_ft']) ? (float)$status['altitude_ft'] : null);
            return $label . ' is in flight, ' . $distance . ' nautical miles ' . $direction . ' at ' . $altSpeech . '.';

        default:
            return '';
    }
}

function tv_adsb_announcement_cooldown_ok(array &$cache, int $seconds = 30): bool
{
    $last = (int)($cache['last_announcement_at'] ?? 0);
    return (time() - $last) >= $seconds;
}

function tv_adsb_maybe_announcement(
    array $status,
    array &$cache,
    array $gate,
    bool $enabled = true
): ?array {
    if (!$enabled) {
        return null;
    }

    $newCode = (string)($cache['fsm_state'] ?? ($status['status_code'] ?? ''));
    if (!in_array($newCode, tv_adsb_announceable_status_codes(), true)) {
        return null;
    }

    $lastAnnounced = (string)($cache['fsm_prev_announced_state'] ?? '');
    if ($lastAnnounced === '') {
        $cache['fsm_prev_announced_state'] = $newCode;
        return null;
    }

    if ($lastAnnounced === $newCode) {
        return null;
    }

    if (!tv_adsb_announcement_cooldown_ok($cache, 30)) {
        return null;
    }

    $speech = tv_adsb_build_event_speech($status, $gate, $cache);
    if ($speech === '') {
        return null;
    }

    $cache['fsm_prev_announced_state'] = $newCode;
    $cache['last_announcement_at'] = time();

    return array(
        'speech' => $speech,
        'status_code' => $newCode,
        'label' => tv_adsb_normalize_label((string)($status['label'] ?? '')),
    );
}
