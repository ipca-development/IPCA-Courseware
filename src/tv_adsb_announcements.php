<?php
declare(strict_types=1);

function tv_adsb_announceable_status_codes(): array
{
    return array('landed', 'takeoff', 'in_flight', 'taxi_in');
}

function tv_adsb_pa_gate_phrase(array $gate): string
{
    $label = trim((string)($gate['label'] ?? 'SoCal Pilot Center Gate'));
    if ($label === '') {
        $label = 'SoCal Pilot Center Gate';
    }
    if (stripos($label, 'gate') === false) {
        $label .= ' Gate';
    }
    return 'the ' . $label;
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

function tv_adsb_record_departure_airport(array $status, array &$cache): void
{
    $code = (string)($status['status_code'] ?? '');
    if (!in_array($code, array('takeoff', 'taxi_out'), true)) {
        return;
    }

    $nearest = $status['nearest_airport'] ?? null;
    if (is_array($nearest) && !empty($nearest['name'])) {
        $cache['departure_airport'] = (string)$nearest['name'];
        return;
    }

    $home = tv_adsb_normalize_home_airport((string)($status['home_airport'] ?? ''));
    $airports = tv_adsb_airports();
    if (isset($airports[$home]['name'])) {
        $cache['departure_airport'] = (string)$airports[$home]['name'];
    }
}

function tv_adsb_extract_eta_from_status(array $status): string
{
    $text = (string)($status['status_text'] ?? '');
    if (preg_match('/ETA\s+(\d{1,2}:\d{2})/i', $text, $matches) === 1) {
        return (string)$matches[1];
    }
    return tv_adsb_format_local_time(time());
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
        case 'landed':
            return $label . ' Landed at ' . $airport;

        case 'takeoff':
            return $label . ' is taking off from ' . $airport;

        case 'in_flight':
            $from = trim((string)($cache['departure_airport'] ?? ''));
            if ($from === '') {
                $from = $airport;
            }
            return $label . ' is Airborne from ' . $from;

        case 'taxi_in':
            $eta = tv_adsb_extract_eta_from_status($status);
            return $label . ' is taxiing in and expected to arrive at '
                . tv_adsb_pa_gate_phrase($gate) . ' at time: ' . $eta;

        default:
            return '';
    }
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

    tv_adsb_record_departure_airport($status, $cache);

    $newCode = (string)($status['status_code'] ?? '');
    $prevCode = '';
    if (!empty($cache['last_status']) && is_array($cache['last_status'])) {
        $prevCode = (string)($cache['last_status']['status_code'] ?? '');
    }

    if ($newCode === 'parked_spc' && $prevCode !== 'parked_spc') {
        $cache['last_announced_status'] = 'parked_spc';
        unset($cache['departure_airport']);
        return null;
    }

    if (!in_array($newCode, tv_adsb_announceable_status_codes(), true)) {
        return null;
    }

    if ($prevCode === '' || $prevCode === $newCode) {
        if (!isset($cache['last_announced_status'])) {
            $cache['last_announced_status'] = $newCode;
        }
        return null;
    }

    $lastAnnounced = (string)($cache['last_announced_status'] ?? '');
    if ($lastAnnounced === $newCode) {
        return null;
    }

    $speech = tv_adsb_build_event_speech($status, $gate, $cache);
    if ($speech === '') {
        return null;
    }

    $cache['last_announced_status'] = $newCode;

    return array(
        'speech' => $speech,
        'status_code' => $newCode,
        'label' => tv_adsb_normalize_label((string)($status['label'] ?? '')),
    );
}
