<?php
declare(strict_types=1);

require_once __DIR__ . '/tv_screen_pa.php';

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
        'touch_and_go',
    );
}

function tv_adsb_pa_gate_phrase(array $gate): string
{
    $label = trim((string)($gate['label'] ?? 'SoCal Pilot Center'));
    if ($label === '') {
        return 'SoCal Pilot Center';
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

function tv_adsb_build_event_speech(array $status, array $gate, array $cache): string
{
    $label = tv_adsb_normalize_label((string)($status['label'] ?? ''));
    if ($label === '') {
        return '';
    }

    $spokenLabel = tv_pa_registration_spoken($label);
    if ($spokenLabel === '') {
        $spokenLabel = $label;
    }

    $code = (string)($status['status_code'] ?? '');
    $airport = tv_adsb_airport_name_for_speech($status, $cache);

    switch ($code) {
        case 'parked_at_spc':
            return $spokenLabel . ' is parked at ' . tv_adsb_pa_gate_phrase($gate) . '.';

        case 'taxiing_out':
            return $spokenLabel . ' is taxiing out.';

        case 'taxiing_in':
            return $spokenLabel . ' is taxiing in.';

        case 'taking_off':
            return $spokenLabel . ' is taking off from ' . $airport . '.';

        case 'landing':
            return $spokenLabel . ' is landing at ' . $airport . '.';

        case 'landed':
            return $spokenLabel . ' has landed.';

        case 'in_flight':
            return $spokenLabel . ' is in flight.';

        case 'touch_and_go':
            return $spokenLabel . ' is conducting touch and go training at ' . $airport . '.';

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

    $newCode = (string)($status['status_code'] ?? ($cache['fsm_state'] ?? ''));
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
