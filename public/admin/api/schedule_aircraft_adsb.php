<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../../src/tv_adsb_status.php';

cw_require_flight_schedule_editor();
header('Content-Type: application/json; charset=utf-8');

/**
 * @param array<string,mixed> $payload
 */
function schedule_adsb_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

try {
    $aircraftId = (int)($_GET['aircraft_id'] ?? 0);
    if ($aircraftId <= 0) {
        schedule_adsb_json(400, array('ok' => false, 'error' => 'aircraft_id is required.'));
        exit;
    }

    $row = (new CockpitAircraftService($pdo))->aircraftById($aircraftId);
    if ($row === null) {
        schedule_adsb_json(404, array('ok' => false, 'error' => 'Aircraft not found.'));
        exit;
    }

    $registration = tv_adsb_normalize_registration((string)($row['registration'] ?? ''));
    $hex = tv_adsb_normalize_hex((string)($row['adsb_hex'] ?? ''));
    $label = trim((string)($row['display_name'] ?? '')) !== ''
        ? (string)$row['display_name']
        : $registration;

    if ($hex === '' && $registration === '') {
        schedule_adsb_json(200, array(
            'ok' => true,
            'in_flight' => false,
            'message' => 'Aircraft not in flight',
            'detail' => 'No ADS-B hex or registration is configured for this aircraft.',
            'aircraft' => array(
                'id' => $aircraftId,
                'registration' => $registration,
                'display_name' => $label,
                'adsb_hex' => $hex,
            ),
        ));
        exit;
    }

    $live = null;
    $fetchError = null;
    try {
        if ($hex !== '') {
            $live = tv_adsb_fetch_by_hex($hex);
        }
        if ($live === null && $registration !== '') {
            $live = tv_adsb_fetch_by_registration($registration);
        }
    } catch (Throwable $e) {
        $fetchError = $e->getMessage();
    }

    $position = is_array($live) ? tv_adsb_position($live) : null;
    $onGround = is_array($live) ? tv_adsb_is_on_ground($live) : true;
    $inFlight = is_array($live) && $position !== null && !$onGround;

    if (!$inFlight) {
        $detail = 'No live ADS-B position is available.';
        if ($fetchError !== null) {
            $detail = 'ADS-B lookup failed.';
        } elseif (is_array($live) && $onGround) {
            $detail = 'Aircraft is on the ground / not airborne.';
        }
        schedule_adsb_json(200, array(
            'ok' => true,
            'in_flight' => false,
            'message' => 'Aircraft not in flight',
            'detail' => $detail,
            'aircraft' => array(
                'id' => $aircraftId,
                'registration' => $registration,
                'display_name' => $label,
                'adsb_hex' => $hex,
            ),
            'fetch_error' => $fetchError,
        ));
        exit;
    }

    $lat = (float)$position['lat'];
    $lon = (float)$position['lon'];
    $alt = tv_adsb_altitude_ft($live);
    $gs = isset($live['gs']) && is_numeric($live['gs']) ? (float)$live['gs'] : null;
    $track = isset($live['track']) && is_numeric($live['track'])
        ? (float)$live['track']
        : (isset($live['true_heading']) && is_numeric($live['true_heading']) ? (float)$live['true_heading'] : null);
    $nearest = tv_adsb_nearest_airport($lat, $lon, 25.0);
    $bboxPad = 0.08;
    $mapEmbed = 'https://www.openstreetmap.org/export/embed.html?bbox='
        . rawurlencode(sprintf('%.5f,%.5f,%.5f,%.5f', $lon - $bboxPad, $lat - $bboxPad, $lon + $bboxPad, $lat + $bboxPad))
        . '&layer=mapnik&marker=' . rawurlencode(sprintf('%.5f,%.5f', $lat, $lon));
    $mapLink = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat)
        . '&mlon=' . rawurlencode((string)$lon)
        . '#map=11/' . rawurlencode((string)$lat) . '/' . rawurlencode((string)$lon);

    schedule_adsb_json(200, array(
        'ok' => true,
        'in_flight' => true,
        'message' => 'Aircraft in flight',
        'detail' => null,
        'aircraft' => array(
            'id' => $aircraftId,
            'registration' => $registration,
            'display_name' => $label,
            'adsb_hex' => $hex !== '' ? $hex : tv_adsb_normalize_hex((string)($live['hex'] ?? '')),
        ),
        'position' => array(
            'lat' => round($lat, 5),
            'lon' => round($lon, 5),
            'altitude_ft' => $alt !== null ? (int)round($alt) : null,
            'groundspeed_kt' => $gs !== null ? (int)round($gs) : null,
            'track_deg' => $track !== null ? (int)round($track) : null,
            'squawk' => isset($live['squawk']) ? (string)$live['squawk'] : null,
            'callsign' => isset($live['flight']) ? trim((string)$live['flight']) : null,
            'observed_at' => tv_adsb_radar_observed_at($live),
        ),
        'nearest_airport' => $nearest,
        'map_embed_url' => $mapEmbed,
        'map_url' => $mapLink,
        'fetch_error' => null,
    ));
} catch (Throwable $e) {
    schedule_adsb_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
