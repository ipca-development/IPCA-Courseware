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
function schedule_adsb_track_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * @return list<array{0:float,1:float,2:float,3:mixed,4?:mixed,5?:mixed}>
 */
function schedule_adsb_trace_rows_from_payload(array $payload): array
{
    $rows = isset($payload['trace']) && is_array($payload['trace']) ? $payload['trace'] : array();
    if ($rows === array()) {
        return array();
    }
    $base = isset($payload['timestamp']) && is_numeric($payload['timestamp']) ? (float)$payload['timestamp'] : 0.0;
    $out = array();
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row[0], $row[1], $row[2]) || !is_numeric($row[0]) || !is_numeric($row[1]) || !is_numeric($row[2])) {
            continue;
        }
        $copy = $row;
        // Historical day payloads use offsets from payload timestamp; recent traces may already be absolute.
        $offset = (float)$row[0];
        $copy[0] = ($base > 0.0 && $offset < 1_000_000_000) ? ($base + $offset) : $offset;
        $out[] = $copy;
    }
    return $out;
}

/**
 * Historical nearby traffic from the local ADS-B archive (for replay + delayed live).
 *
 * @param list<array<string,mixed>> $ownshipSamples
 * @return list<array<string,mixed>>
 */
function schedule_adsb_archive_traffic(PDO $pdo, string $ownHex, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $ownshipSamples): array
{
    $ownHex = tv_adsb_normalize_hex($ownHex);
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_adsb_traffic_samples'");
        if ($stmt === false || $stmt->fetchColumn() === false) {
            return array();
        }
    } catch (Throwable) {
        return array();
    }

    $lats = array();
    $lons = array();
    foreach ($ownshipSamples as $sample) {
        if (!is_array($sample)) {
            continue;
        }
        if (isset($sample['lat'], $sample['lon']) && is_numeric($sample['lat']) && is_numeric($sample['lon'])) {
            $lats[] = (float)$sample['lat'];
            $lons[] = (float)$sample['lon'];
        }
    }
    if ($lats === array() || $lons === array()) {
        return array();
    }

    // ~20 NM pad around the selected aircraft track.
    $padDeg = 20.0 / 60.0;
    $minLat = min($lats) - $padDeg;
    $maxLat = max($lats) + $padDeg;
    $minLon = min($lons) - $padDeg;
    $maxLon = max($lons) + $padDeg;
    $fromEpoch = (float)$fromUtc->getTimestamp();

    try {
        // Guardrail: this archive is multi-million rows. Never let traffic replay
        // stall live ADS-B for the schedule modal.
        try {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=2500');
        } catch (Throwable) {
            // ignore engines that reject max_execution_time
        }
        $stmt = $pdo->prepare("
            SELECT sample_time_utc, aircraft_hex, callsign, latitude, longitude,
                   altitude_ft, groundspeed_kt, track_deg, squawk
            FROM ipca_adsb_traffic_samples
            WHERE sample_time_utc >= ?
              AND sample_time_utc <= ?
              AND latitude BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND aircraft_hex IS NOT NULL
              AND aircraft_hex <> ''
            ORDER BY sample_time_utc ASC
            LIMIT 12000
        ");
        $stmt->execute(array(
            $fromUtc->modify('-5 minutes')->format('Y-m-d H:i:s'),
            $toUtc->modify('+2 minutes')->format('Y-m-d H:i:s'),
            $minLat,
            $maxLat,
            $minLon,
            $maxLon,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return array();
    }

    $byHex = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        $hex = tv_adsb_normalize_hex((string)($row['aircraft_hex'] ?? ''));
        if ($hex === '' || ($ownHex !== '' && $hex === $ownHex)) {
            continue;
        }
        $epoch = strtotime((string)($row['sample_time_utc'] ?? ''));
        if ($epoch === false) {
            continue;
        }
        if (!isset($byHex[$hex])) {
            $byHex[$hex] = array(
                'hex' => $hex,
                'callsign' => trim((string)($row['callsign'] ?? '')),
                'samples' => array(),
                '_last_epoch' => null,
            );
        }
        if ($byHex[$hex]['callsign'] === '' && trim((string)($row['callsign'] ?? '')) !== '') {
            $byHex[$hex]['callsign'] = trim((string)$row['callsign']);
        }
        // ~2.5s downsample keeps replay smooth without huge payloads.
        if ($byHex[$hex]['_last_epoch'] !== null && ($epoch - $byHex[$hex]['_last_epoch']) < 2.5) {
            continue;
        }
        $byHex[$hex]['_last_epoch'] = $epoch;
        if (count($byHex[$hex]['samples']) >= 1200) {
            continue;
        }
        $byHex[$hex]['samples'][] = array(
            't' => max(0.0, round($epoch - $fromEpoch, 1)),
            'epoch' => (float)$epoch,
            'lat' => round((float)$row['latitude'], 5),
            'lon' => round((float)$row['longitude'], 5),
            'altitude_ft' => isset($row['altitude_ft']) && is_numeric($row['altitude_ft']) ? (int)round((float)$row['altitude_ft']) : null,
            'groundspeed_kt' => isset($row['groundspeed_kt']) && is_numeric($row['groundspeed_kt']) ? (int)round((float)$row['groundspeed_kt']) : null,
            'track_deg' => isset($row['track_deg']) && is_numeric($row['track_deg']) ? (int)round((float)$row['track_deg']) : null,
            'squawk' => isset($row['squawk']) && trim((string)$row['squawk']) !== '' ? trim((string)$row['squawk']) : null,
        );
    }

    $out = array();
    foreach ($byHex as $item) {
        unset($item['_last_epoch']);
        if (count($item['samples']) < 2) {
            continue;
        }
        if ($item['callsign'] === '') {
            $item['callsign'] = strtoupper($item['hex']);
        }
        $out[] = $item;
    }

    // Prefer aircraft with more samples (more complete replay paths).
    usort($out, static fn(array $a, array $b): int => count($b['samples']) <=> count($a['samples']));
    return array_slice($out, 0, 36);
}

/**
 * Local KTRM ADS-B archive fallback (RapidAPI /traces often returns empty on this plan).
 *
 * @return list<array<int,mixed>>
 */
function schedule_adsb_archive_trace_rows(PDO $pdo, string $hex, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
{
    $hex = tv_adsb_normalize_hex($hex);
    if ($hex === '') {
        return array();
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ipca_adsb_traffic_samples'");
        if ($stmt === false || $stmt->fetchColumn() === false) {
            return array();
        }
    } catch (Throwable) {
        return array();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT sample_time_utc, latitude, longitude, altitude_ft, groundspeed_kt, track_deg, squawk
            FROM ipca_adsb_traffic_samples
            WHERE aircraft_hex = ?
              AND sample_time_utc >= ?
              AND sample_time_utc <= ?
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
            ORDER BY sample_time_utc ASC
            LIMIT 8000
        ");
        $stmt->execute(array(
            $hex,
            $fromUtc->modify('-10 minutes')->format('Y-m-d H:i:s'),
            $toUtc->modify('+2 minutes')->format('Y-m-d H:i:s'),
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return array();
    }

    $out = array();
    $lastEpoch = null;
    foreach (is_array($rows) ? $rows : array() as $row) {
        $epoch = strtotime((string)($row['sample_time_utc'] ?? ''));
        if ($epoch === false) {
            continue;
        }
        $lat = (float)($row['latitude'] ?? 0);
        $lon = (float)($row['longitude'] ?? 0);
        if (!is_finite($lat) || !is_finite($lon)) {
            continue;
        }
        // Archive can be denser than needed for the schedule map polyline.
        if ($lastEpoch !== null && (($epoch) - $lastEpoch) < 2) {
            continue;
        }
        $alt = $row['altitude_ft'] ?? null;
        $out[] = array(
            (float)$epoch,
            $lat,
            $lon,
            is_numeric($alt) ? (float)$alt : null,
            isset($row['groundspeed_kt']) && is_numeric($row['groundspeed_kt']) ? (float)$row['groundspeed_kt'] : null,
            isset($row['track_deg']) && is_numeric($row['track_deg']) ? (float)$row['track_deg'] : null,
            isset($row['squawk']) && trim((string)$row['squawk']) !== '' ? trim((string)$row['squawk']) : null,
        );
        $lastEpoch = $epoch;
    }
    return $out;
}

/**
 * @param list<array<int,mixed>> $rows
 * @return list<array<int,mixed>>
 */
function schedule_adsb_dedupe_trace_rows(array $rows): array
{
    usort($rows, static fn(array $a, array $b): int => ((float)($a[0] ?? 0)) <=> ((float)($b[0] ?? 0)));
    $out = array();
    $lastEpoch = null;
    foreach ($rows as $row) {
        $epoch = (float)($row[0] ?? 0);
        if ($lastEpoch !== null && abs($epoch - $lastEpoch) < 0.75) {
            // Keep the later duplicate (usually fresher live/archive tip).
            $out[count($out) - 1] = $row;
            $lastEpoch = $epoch;
            continue;
        }
        $out[] = $row;
        $lastEpoch = $epoch;
    }
    return $out;
}

/**
 * @return list<array<int,mixed>>
 */
function schedule_adsb_collect_trace_rows(PDO $pdo, string $hex, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
{
    $hex = tv_adsb_normalize_hex($hex);
    if ($hex === '') {
        return array();
    }

    $rows = array();
    $folder = substr($hex, -2);

    // Prefer recent/full live traces first (covers an active dispatched flight).
    foreach (array(
        '/traces/' . rawurlencode($folder) . '/trace_full_' . rawurlencode($hex) . '.json',
        '/traces/' . rawurlencode($folder) . '/trace_recent_' . rawurlencode($hex) . '.json',
    ) as $path) {
        try {
            $payload = tv_adsb_request($path);
            $chunk = schedule_adsb_trace_rows_from_payload($payload);
            if ($chunk !== array()) {
                foreach ($chunk as $row) {
                    $rows[] = $row;
                }
            }
        } catch (Throwable) {
            // Fall through to historical day traces / local archive.
        }
    }

    $dayStart = $fromUtc->setTime(0, 0)->modify('-1 day');
    $dayEnd = $toUtc->setTime(0, 0)->modify('+1 day');
    for ($day = $dayStart; $day <= $dayEnd; $day = $day->modify('+1 day')) {
        try {
            $payload = tv_adsb_fetch_historical_trace($hex, $day);
            $chunk = schedule_adsb_trace_rows_from_payload($payload);
            if ($chunk === array()) {
                continue;
            }
            foreach ($chunk as $row) {
                $rows[] = $row;
            }
        } catch (Throwable) {
            continue;
        }
    }

    // Local archive is the reliable path for IPCA fleet flights near KTRM.
    foreach (schedule_adsb_archive_trace_rows($pdo, $hex, $fromUtc, $toUtc) as $row) {
        $rows[] = $row;
    }

    return schedule_adsb_dedupe_trace_rows($rows);
}

/**
 * @param list<array{0:float,1:float}> $coords lat/lon pairs
 * @return list<float|null> elevations in meters MSL
 */
function schedule_adsb_fetch_elevations_open_elevation(array $coords): array
{
    $elevations = array_fill(0, count($coords), null);
    if ($coords === array()) {
        return $elevations;
    }
    $chunkSize = 80;
    for ($offset = 0; $offset < count($coords); $offset += $chunkSize) {
        $chunk = array_slice($coords, $offset, $chunkSize);
        $locations = array();
        foreach ($chunk as $pair) {
            $locations[] = array(
                'latitude' => round((float)$pair[0], 5),
                'longitude' => round((float)$pair[1], 5),
            );
        }
        $ch = curl_init('https://api.open-elevation.com/api/v1/lookup');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(array('locations' => $locations)),
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            continue;
        }
        $decoded = json_decode((string)$body, true);
        $results = is_array($decoded) && isset($decoded['results']) && is_array($decoded['results'])
            ? $decoded['results']
            : array();
        foreach ($chunk as $i => $_pair) {
            $meters = $results[$i]['elevation'] ?? null;
            $elevations[$offset + $i] = is_numeric($meters) ? (float)$meters : null;
        }
    }
    return $elevations;
}

/**
 * @param list<array{0:float,1:float}> $coords lat/lon pairs
 * @return list<float|null> elevations in meters MSL
 */
function schedule_adsb_fetch_elevations_m(array $coords): array
{
    if ($coords === array()) {
        return array();
    }

    $elevations = array_fill(0, count($coords), null);
    $chunkSize = 80;
    $gotAny = false;
    for ($offset = 0; $offset < count($coords); $offset += $chunkSize) {
        $chunk = array_slice($coords, $offset, $chunkSize);
        $lats = array();
        $lons = array();
        foreach ($chunk as $pair) {
            $lats[] = round((float)$pair[0], 5);
            $lons[] = round((float)$pair[1], 5);
        }
        $url = 'https://api.open-meteo.com/v1/elevation?latitude='
            . rawurlencode(implode(',', $lats))
            . '&longitude=' . rawurlencode(implode(',', $lons));
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            continue;
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded) || !empty($decoded['error'])) {
            continue;
        }
        $values = isset($decoded['elevation']) && is_array($decoded['elevation'])
            ? $decoded['elevation']
            : array();
        foreach ($chunk as $i => $_pair) {
            $meters = $values[$i] ?? null;
            if (is_numeric($meters)) {
                $elevations[$offset + $i] = (float)$meters;
                $gotAny = true;
            }
        }
    }

    if (!$gotAny) {
        return schedule_adsb_fetch_elevations_open_elevation($coords);
    }
    return $elevations;
}

/**
 * Tip-fill pollution: a long trailing run of identical terrain values.
 * (Natural flat valleys still vary a few feet and won't match this.)
 *
 * @param list<array<string,mixed>> $points
 */
function schedule_adsb_profile_terrain_is_degenerate(array $points): bool
{
    $n = count($points);
    if ($n < 12) {
        return false;
    }
    $vals = array();
    foreach ($points as $point) {
        if (!isset($point['terrain_ft']) || !is_numeric($point['terrain_ft'])) {
            return false;
        }
        $vals[] = (int)$point['terrain_ft'];
    }
    $last = $vals[$n - 1];
    $run = 1;
    for ($i = $n - 2; $i >= 0; $i--) {
        if ($vals[$i] !== $last) {
            break;
        }
        $run++;
    }
    if ($run < max(10, (int)floor($n * 0.40))) {
        return false;
    }
    $start = $n - $run;
    $span = abs((float)($points[$n - 1]['dist_nm'] ?? 0) - (float)($points[$start]['dist_nm'] ?? 0));
    return $span >= 15.0;
}

/**
 * Strip trailing tip-fill terrain so sticky caches remain usable.
 *
 * @param array<string,mixed> $cached
 * @return array<string,mixed>
 */
function schedule_adsb_repair_degenerate_terrain_cache(array $cached): array
{
    $points = isset($cached['points']) && is_array($cached['points']) ? $cached['points'] : array();
    if (!schedule_adsb_profile_terrain_is_degenerate($points)) {
        return $cached;
    }
    $n = count($points);
    $last = (int)$points[$n - 1]['terrain_ft'];
    $run = 1;
    for ($i = $n - 2; $i >= 0; $i--) {
        if ((int)$points[$i]['terrain_ft'] !== $last) {
            break;
        }
        $run++;
    }
    // Keep the first sample of the flat run; clear the rest.
    for ($i = ($n - $run + 1); $i < $n; $i++) {
        $points[$i]['terrain_ft'] = null;
        $points[$i]['agl_ft'] = null;
    }
    $cached['points'] = $points;
    return $cached;
}

/**
 * @param list<array<string,mixed>> $samples
 * @return array<string,mixed>
 */
function schedule_adsb_build_vertical_profile(string $hex, array $samples): array
{
    $usable = array();
    foreach ($samples as $sample) {
        if (!is_array($sample)) {
            continue;
        }
        $lat = isset($sample['lat']) && is_numeric($sample['lat']) ? (float)$sample['lat'] : null;
        $lon = isset($sample['lon']) && is_numeric($sample['lon']) ? (float)$sample['lon'] : null;
        if ($lat === null || $lon === null) {
            continue;
        }
        $usable[] = $sample;
    }
    if (count($usable) < 2) {
        return array(
            'point_count' => 0,
            'distance_nm' => 0.0,
            'source' => 'none',
            'points' => array(),
        );
    }

    $maxPoints = 72;
    $step = max(1, (int)floor(count($usable) / $maxPoints));
    $picked = array();
    for ($i = 0; $i < count($usable); $i += $step) {
        $picked[] = $usable[$i];
    }
    $last = $usable[count($usable) - 1];
    $prev = $picked[count($picked) - 1] ?? null;
    if ($prev === null
        || abs((float)$prev['lat'] - (float)$last['lat']) > 0.00005
        || abs((float)$prev['lon'] - (float)$last['lon']) > 0.00005) {
        $picked[] = $last;
    }

    $cacheDir = rtrim(sys_get_temp_dir(), '/');
    $hexKey = preg_replace('/[^a-z0-9]/i', '', strtolower($hex)) ?: 'unknown';
    $hexCacheFile = $cacheDir . '/ipca_adsb_vprofile_hex_' . $hexKey . '.json';
    $fetchLockFile = $cacheDir . '/ipca_adsb_vprofile_hex_' . $hexKey . '.fetch';
    $fingerprint = $hexKey . '|' . count($picked) . '|'
        . round((float)$picked[0]['lat'], 3) . ',' . round((float)$picked[0]['lon'], 3) . '|'
        . round((float)$picked[count($picked) - 1]['lat'], 3) . ',' . round((float)$picked[count($picked) - 1]['lon'], 3);
    $cacheFile = $cacheDir . '/ipca_adsb_vprofile_' . md5($fingerprint) . '.json';

    $loadCached = static function (string $path, int $maxAge) : ?array {
        if (!is_file($path) || (time() - (int)filemtime($path)) >= $maxAge) {
            return null;
        }
        $cached = json_decode((string)file_get_contents($path), true);
        if (!is_array($cached) || !isset($cached['points']) || !is_array($cached['points']) || count($cached['points']) < 2) {
            return null;
        }
        if (schedule_adsb_profile_terrain_is_degenerate($cached['points'])) {
            $cached = schedule_adsb_repair_degenerate_terrain_cache($cached);
            @file_put_contents($path, json_encode($cached));
        }
        $hasTerrain = false;
        foreach ($cached['points'] as $point) {
            if (isset($point['terrain_ft']) && is_numeric($point['terrain_ft'])) {
                $hasTerrain = true;
                break;
            }
        }
        return $hasTerrain ? $cached : null;
    };

    $assembleFromElevations = static function (array $pickedSamples, array $elevM): array {
        $points = array();
        $distNm = 0.0;
        $prevLat = null;
        $prevLon = null;
        $haveTerrain = false;
        foreach ($pickedSamples as $i => $sample) {
            $lat = (float)$sample['lat'];
            $lon = (float)$sample['lon'];
            if ($prevLat !== null && $prevLon !== null) {
                $distNm += tv_adsb_haversine_nm($prevLat, $prevLon, $lat, $lon);
            }
            $prevLat = $lat;
            $prevLon = $lon;
            $altFt = isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft'])
                ? (float)$sample['altitude_ft']
                : null;
            $terrainFt = null;
            if (isset($elevM[$i]) && is_numeric($elevM[$i])) {
                $terrainFt = round(((float)$elevM[$i]) * 3.28084, 0);
                $haveTerrain = true;
            }
            $agl = ($altFt !== null && $terrainFt !== null) ? round($altFt - $terrainFt, 0) : null;
            $points[] = array(
                't' => isset($sample['t']) && is_numeric($sample['t']) ? (float)$sample['t'] : null,
                'epoch' => isset($sample['epoch']) && is_numeric($sample['epoch']) ? (float)$sample['epoch'] : null,
                'dist_nm' => round($distNm, 2),
                'lat' => round($lat, 5),
                'lon' => round($lon, 5),
                'altitude_ft' => $altFt !== null ? (int)round($altFt) : null,
                'terrain_ft' => $terrainFt !== null ? (int)$terrainFt : null,
                'agl_ft' => $agl !== null ? (int)$agl : null,
                'groundspeed_kt' => isset($sample['groundspeed_kt']) && is_numeric($sample['groundspeed_kt'])
                    ? (int)round((float)$sample['groundspeed_kt'])
                    : null,
                'track_deg' => isset($sample['track_deg']) && is_numeric($sample['track_deg'])
                    ? (int)round((float)$sample['track_deg'])
                    : null,
            );
        }
        return array(
            'point_count' => count($points),
            'distance_nm' => $points !== array() ? (float)$points[count($points) - 1]['dist_nm'] : 0.0,
            'source' => $haveTerrain ? 'open-meteo' : 'altitude-only',
            'points' => $points,
            '_have_terrain' => $haveTerrain,
        );
    };

    // Exact fingerprint hit: remap altitudes only (no DEM HTTP).
    $exact = $loadCached($cacheFile, 300);
    if ($exact !== null && (($exact['source'] ?? '') === 'open-meteo')) {
        $refreshed = schedule_adsb_refresh_vertical_profile_tip($exact, $picked);
        if (($refreshed['source'] ?? '') === 'open-meteo') {
            return $refreshed;
        }
    }

    // Sticky hex profile: prefer this over hammering Open-Meteo on every live poll.
    $sticky = $loadCached($hexCacheFile, 7200);
    $fetchAllowed = !is_file($fetchLockFile) || (time() - (int)filemtime($fetchLockFile)) >= 120;
    $coords = array();
    foreach ($picked as $sample) {
        $coords[] = array((float)$sample['lat'], (float)$sample['lon']);
    }

    $payload = null;
    if ($fetchAllowed) {
        @touch($fetchLockFile);
        $elevM = schedule_adsb_fetch_elevations_m($coords);
        $assembled = $assembleFromElevations($picked, $elevM);
        $haveTerrain = !empty($assembled['_have_terrain']);
        unset($assembled['_have_terrain']);
        if ($haveTerrain && !schedule_adsb_profile_terrain_is_degenerate($assembled['points'])) {
            $payload = $assembled;
        }
    }

    if ($payload === null && $sticky !== null && (($sticky['source'] ?? '') === 'open-meteo')) {
        $payload = schedule_adsb_refresh_vertical_profile_tip($sticky, $picked);
    }

    if ($payload === null) {
        $payload = array(
            'point_count' => 0,
            'distance_nm' => 0.0,
            'source' => 'altitude-only',
            'points' => array(),
        );
        // Still return altitude profile even without DEM.
        $assembled = $assembleFromElevations($picked, array_fill(0, count($picked), null));
        unset($assembled['_have_terrain']);
        $payload = $assembled;
    }

    if (($payload['source'] ?? '') === 'open-meteo'
        && !schedule_adsb_profile_terrain_is_degenerate($payload['points'] ?? array())) {
        @file_put_contents($cacheFile, json_encode($payload));
        @file_put_contents($hexCacheFile, json_encode($payload));
    }

    return $payload;
}

/**
 * Remap a cached terrain profile onto the latest track samples without DEM HTTP.
 * Reuses nearby elevations and interpolates by distance along the prior profile.
 *
 * @param array<string,mixed> $cached
 * @param list<array<string,mixed>> $picked
 * @return array<string,mixed>
 */
function schedule_adsb_refresh_vertical_profile_tip(array $cached, array $picked): array
{
    $oldPoints = isset($cached['points']) && is_array($cached['points']) ? $cached['points'] : array();
    if ($oldPoints === array() || $picked === array()) {
        return $cached;
    }
    if (schedule_adsb_profile_terrain_is_degenerate($oldPoints)) {
        $cached = schedule_adsb_repair_degenerate_terrain_cache($cached);
        $oldPoints = $cached['points'];
    }

    $maxReuseNm = 2.0;
    $nearestTerrain = static function (float $lat, float $lon) use ($oldPoints, $maxReuseNm): ?float {
        $best = null;
        $bestDistNm = INF;
        foreach ($oldPoints as $point) {
            if (!isset($point['terrain_ft']) || !is_numeric($point['terrain_ft'])) {
                continue;
            }
            if (!isset($point['lat'], $point['lon']) || !is_numeric($point['lat']) || !is_numeric($point['lon'])) {
                continue;
            }
            $distNm = tv_adsb_haversine_nm((float)$point['lat'], (float)$point['lon'], $lat, $lon);
            if ($distNm < $bestDistNm) {
                $bestDistNm = $distNm;
                $best = (float)$point['terrain_ft'];
            }
        }
        if ($best === null || $bestDistNm > $maxReuseNm) {
            return null;
        }
        return $best;
    };

    $terrainByDistance = static function (float $distNm) use ($oldPoints): ?float {
        $withTerrain = array();
        foreach ($oldPoints as $point) {
            if (!isset($point['terrain_ft'], $point['dist_nm']) || !is_numeric($point['terrain_ft']) || !is_numeric($point['dist_nm'])) {
                continue;
            }
            $withTerrain[] = $point;
        }
        if ($withTerrain === array()) {
            return null;
        }
        $maxDist = (float)$withTerrain[count($withTerrain) - 1]['dist_nm'];
        if ($distNm > ($maxDist + 2.5)) {
            return null;
        }
        if ($distNm >= $maxDist) {
            return (float)$withTerrain[count($withTerrain) - 1]['terrain_ft'];
        }
        $before = null;
        $after = null;
        foreach ($withTerrain as $point) {
            $d = (float)$point['dist_nm'];
            if ($d <= $distNm) {
                $before = $point;
            }
            if ($d >= $distNm) {
                $after = $point;
                break;
            }
        }
        if ($before && $after && (float)$before['dist_nm'] !== (float)$after['dist_nm']) {
            $gap = (float)$after['dist_nm'] - (float)$before['dist_nm'];
            $ratio = ($distNm - (float)$before['dist_nm']) / $gap;
            return (float)$before['terrain_ft'] + (((float)$after['terrain_ft'] - (float)$before['terrain_ft']) * $ratio);
        }
        $hold = $before ?: $after;
        return $hold ? (float)$hold['terrain_ft'] : null;
    };

    $points = array();
    $distNm = 0.0;
    $prevLat = null;
    $prevLon = null;
    $haveTerrain = false;
    foreach ($picked as $sample) {
        $lat = (float)$sample['lat'];
        $lon = (float)$sample['lon'];
        if ($prevLat !== null && $prevLon !== null) {
            $distNm += tv_adsb_haversine_nm($prevLat, $prevLon, $lat, $lon);
        }
        $prevLat = $lat;
        $prevLon = $lon;
        $altFt = isset($sample['altitude_ft']) && is_numeric($sample['altitude_ft'])
            ? (float)$sample['altitude_ft']
            : null;
        $terrainFt = $nearestTerrain($lat, $lon);
        if ($terrainFt === null) {
            $terrainFt = $terrainByDistance($distNm);
        }
        if ($terrainFt !== null) {
            $haveTerrain = true;
        }
        $points[] = array(
            't' => isset($sample['t']) && is_numeric($sample['t']) ? (float)$sample['t'] : null,
            'epoch' => isset($sample['epoch']) && is_numeric($sample['epoch']) ? (float)$sample['epoch'] : null,
            'dist_nm' => round($distNm, 2),
            'lat' => round($lat, 5),
            'lon' => round($lon, 5),
            'altitude_ft' => $altFt !== null ? (int)round($altFt) : null,
            'terrain_ft' => $terrainFt !== null ? (int)round($terrainFt) : null,
            'agl_ft' => ($altFt !== null && $terrainFt !== null) ? (int)round($altFt - $terrainFt) : null,
            'groundspeed_kt' => isset($sample['groundspeed_kt']) && is_numeric($sample['groundspeed_kt'])
                ? (int)round((float)$sample['groundspeed_kt'])
                : null,
            'track_deg' => isset($sample['track_deg']) && is_numeric($sample['track_deg'])
                ? (int)round((float)$sample['track_deg'])
                : null,
        );
    }

    if (!$haveTerrain) {
        return $cached;
    }

    return array(
        'point_count' => count($points),
        'distance_nm' => $points !== array() ? (float)$points[count($points) - 1]['dist_nm'] : 0.0,
        'source' => 'open-meteo',
        'points' => $points,
    );
}

/**
 * @param list<array<int,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function schedule_adsb_normalize_samples(array $rows, float $fromEpoch, float $toEpoch): array
{
    $samples = array();
    $lastKeptEpoch = null;
    foreach ($rows as $row) {
        $epoch = (float)($row[0] ?? 0);
        if ($epoch < ($fromEpoch - 120) || $epoch > ($toEpoch + 120)) {
            continue;
        }
        $lat = (float)$row[1];
        $lon = (float)$row[2];
        if (!is_finite($lat) || !is_finite($lon)) {
            continue;
        }
        // Light downsample (~1 Hz max) for schedule modal playback.
        if ($lastKeptEpoch !== null && ($epoch - $lastKeptEpoch) < 1.0) {
            continue;
        }
        $alt = $row[3] ?? null;
        $onGround = is_string($alt) && strtolower($alt) === 'ground';
        $samples[] = array(
            't' => max(0.0, round($epoch - $fromEpoch, 1)),
            'epoch' => $epoch,
            'lat' => round($lat, 5),
            'lon' => round($lon, 5),
            'altitude_ft' => is_numeric($alt) ? (int)round((float)$alt) : null,
            'groundspeed_kt' => isset($row[4]) && is_numeric($row[4]) ? (int)round((float)$row[4]) : null,
            'track_deg' => isset($row[5]) && is_numeric($row[5]) ? (int)round((float)$row[5]) : null,
            'squawk' => isset($row[6]) && trim((string)$row[6]) !== '' ? trim((string)$row[6]) : null,
            'on_ground' => $onGround,
        );
        $lastKeptEpoch = $epoch;
        if (count($samples) >= 4000) {
            break;
        }
    }
    return $samples;
}

try {
    $aircraftId = (int)($_GET['aircraft_id'] ?? 0);
    if ($aircraftId <= 0) {
        schedule_adsb_track_json(400, array('ok' => false, 'error' => 'aircraft_id is required.'));
        exit;
    }

    $row = (new CockpitAircraftService($pdo))->aircraftById($aircraftId);
    if ($row === null) {
        schedule_adsb_track_json(404, array('ok' => false, 'error' => 'Aircraft not found.'));
        exit;
    }

    $registration = tv_adsb_normalize_registration((string)($row['registration'] ?? ''));
    $hex = tv_adsb_normalize_hex((string)($row['adsb_hex'] ?? ''));
    $label = trim((string)($row['display_name'] ?? '')) !== ''
        ? (string)$row['display_name']
        : $registration;

    $fromRaw = trim((string)($_GET['from'] ?? ''));
    $toRaw = trim((string)($_GET['to'] ?? ''));
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    try {
        $fromUtc = $fromRaw !== ''
            ? new DateTimeImmutable($fromRaw)
            : $nowUtc->modify('-6 hours');
    } catch (Throwable) {
        $fromUtc = $nowUtc->modify('-6 hours');
    }
    try {
        $toUtc = $toRaw !== ''
            ? new DateTimeImmutable($toRaw)
            : $nowUtc;
    } catch (Throwable) {
        $toUtc = $nowUtc;
    }
    if ($toUtc < $fromUtc) {
        $tmp = $fromUtc;
        $fromUtc = $toUtc;
        $toUtc = $tmp;
    }
    // Cap window to 18 hours for schedule modal responsiveness.
    if ($toUtc->getTimestamp() - $fromUtc->getTimestamp() > 18 * 3600) {
        $fromUtc = $toUtc->modify('-18 hours');
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
    $inFlight = is_array($live) && tv_adsb_is_actively_airborne($live);
    $livePayload = null;
    if ($inFlight && is_array($position)) {
        $lat = (float)$position['lat'];
        $lon = (float)$position['lon'];
        $alt = tv_adsb_altitude_ft($live);
        $gs = isset($live['gs']) && is_numeric($live['gs']) ? (float)$live['gs'] : null;
        $track = isset($live['track']) && is_numeric($live['track'])
            ? (float)$live['track']
            : (isset($live['true_heading']) && is_numeric($live['true_heading']) ? (float)$live['true_heading'] : null);
        $nearest = tv_adsb_nearest_airport($lat, $lon, 25.0);
        $bboxPad = 0.08;
        $livePayload = array(
            'lat' => round($lat, 5),
            'lon' => round($lon, 5),
            'altitude_ft' => $alt !== null ? (int)round($alt) : null,
            'groundspeed_kt' => $gs !== null ? (int)round($gs) : null,
            'track_deg' => $track !== null ? (int)round($track) : null,
            'squawk' => isset($live['squawk']) ? (string)$live['squawk'] : null,
            'callsign' => isset($live['flight']) ? trim((string)$live['flight']) : null,
            'observed_at' => tv_adsb_radar_observed_at($live),
            'nearest_airport' => $nearest,
            'map_embed_url' => 'https://www.openstreetmap.org/export/embed.html?bbox='
                . rawurlencode(sprintf('%.5f,%.5f,%.5f,%.5f', $lon - $bboxPad, $lat - $bboxPad, $lon + $bboxPad, $lat + $bboxPad))
                . '&layer=mapnik&marker=' . rawurlencode(sprintf('%.5f,%.5f', $lat, $lon)),
            'map_url' => 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat)
                . '&mlon=' . rawurlencode((string)$lon)
                . '#map=11/' . rawurlencode((string)$lat) . '/' . rawurlencode((string)$lon),
        );
    }

    // Prefer configured hex; if blank, adopt live hex so archive/traces still resolve.
    if ($hex === '' && is_array($live)) {
        $hex = tv_adsb_normalize_hex((string)($live['hex'] ?? ''));
    }

    $liveOnly = trim((string)($_GET['live_only'] ?? '0')) === '1';
    $samples = array();
    $trackSource = 'none';
    if (!$liveOnly && $hex !== '') {
        $rows = schedule_adsb_collect_trace_rows($pdo, $hex, $fromUtc, $toUtc);
        $samples = schedule_adsb_normalize_samples(
            $rows,
            (float)$fromUtc->getTimestamp(),
            (float)$toUtc->getTimestamp()
        );
        if ($samples !== array()) {
            $trackSource = 'merged';
        }
    }

    // Append live tip if airborne and newer than last sample.
    if ($livePayload !== null) {
        $liveEpoch = time();
        $observed = trim((string)($livePayload['observed_at'] ?? ''));
        if ($observed !== '') {
            $observedTs = strtotime($observed);
            if ($observedTs !== false) {
                $liveEpoch = $observedTs;
            }
        }
        $t = max(0.0, round($liveEpoch - (float)$fromUtc->getTimestamp(), 1));
        $last = $samples !== array() ? $samples[count($samples) - 1] : null;
        if ($last === null || abs((float)$last['t'] - $t) > 2.0) {
            $samples[] = array(
                't' => $t,
                'epoch' => (float)$liveEpoch,
                'lat' => $livePayload['lat'],
                'lon' => $livePayload['lon'],
                'altitude_ft' => $livePayload['altitude_ft'],
                'groundspeed_kt' => $livePayload['groundspeed_kt'],
                'track_deg' => $livePayload['track_deg'],
                'squawk' => $livePayload['squawk'],
                'on_ground' => false,
            );
        }
    }

    $nearby = array();
    if ($livePayload !== null) {
        try {
            $areaAircraft = tv_adsb_fetch_near_point((float)$livePayload['lat'], (float)$livePayload['lon'], 20.0);
            $ownHex = $hex !== '' ? $hex : tv_adsb_normalize_hex((string)(($live['hex'] ?? '')));
            $palette = array('#eab308', '#38bdf8', '#a78bfa', '#fb7185', '#34d399', '#f97316');
            $colorIndex = 0;
            foreach ($areaAircraft as $ac) {
                if (!is_array($ac)) {
                    continue;
                }
                $pos = tv_adsb_position($ac);
                if ($pos === null) {
                    continue;
                }
                $acHex = tv_adsb_normalize_hex((string)($ac['hex'] ?? ''));
                if ($ownHex !== '' && $acHex === $ownHex) {
                    continue;
                }
                if (tv_adsb_is_on_ground($ac)) {
                    continue;
                }
                $reg = tv_adsb_normalize_registration((string)($ac['r'] ?? ($ac['flight'] ?? '')));
                $callsign = trim((string)($ac['flight'] ?? ''));
                $alt = tv_adsb_altitude_ft($ac);
                $gs = isset($ac['gs']) && is_numeric($ac['gs']) ? (float)$ac['gs'] : null;
                $trk = isset($ac['track']) && is_numeric($ac['track'])
                    ? (float)$ac['track']
                    : (isset($ac['true_heading']) && is_numeric($ac['true_heading']) ? (float)$ac['true_heading'] : null);
                $vs = isset($ac['baro_rate']) && is_numeric($ac['baro_rate'])
                    ? (float)$ac['baro_rate']
                    : (isset($ac['geom_rate']) && is_numeric($ac['geom_rate']) ? (float)$ac['geom_rate'] : null);
                $nearby[] = array(
                    'hex' => $acHex,
                    'registration' => $reg !== '' ? $reg : null,
                    'callsign' => $callsign !== '' ? $callsign : null,
                    'lat' => round((float)$pos['lat'], 5),
                    'lon' => round((float)$pos['lon'], 5),
                    'altitude_ft' => $alt !== null ? (int)round($alt) : null,
                    'groundspeed_kt' => $gs !== null ? (int)round($gs) : null,
                    'track_deg' => $trk !== null ? (int)round($trk) : null,
                    'vertical_rate_fpm' => $vs !== null ? (int)round($vs) : null,
                    'squawk' => isset($ac['squawk']) && trim((string)$ac['squawk']) !== '' ? trim((string)$ac['squawk']) : null,
                    'color' => $palette[$colorIndex % count($palette)],
                );
                $colorIndex++;
                if (count($nearby) >= 24) {
                    break;
                }
            }
        } catch (Throwable) {
            $nearby = array();
        }
    }

    $duration = 0.0;
    if ($samples !== array()) {
        $duration = (float)$samples[count($samples) - 1]['t'];
    }

    $verticalProfile = $liveOnly
        ? array('point_count' => 0, 'distance_nm' => 0.0, 'source' => 'live-only', 'points' => array())
        : schedule_adsb_build_vertical_profile($hex !== '' ? $hex : $registration, $samples);
    $includeTraffic = !$liveOnly && trim((string)($_GET['include_traffic'] ?? '0')) === '1';
    $traffic = array();
    if ($includeTraffic && ($hex !== '' || $samples !== array())) {
        $traffic = schedule_adsb_archive_traffic($pdo, $hex, $fromUtc, $toUtc, $samples);
    }

    schedule_adsb_track_json(200, array(
        'ok' => true,
        'in_flight' => $inFlight,
        'message' => $inFlight ? 'Aircraft in flight' : 'Aircraft not in flight',
        'detail' => $inFlight
            ? null
            : ($fetchError !== null
                ? 'ADS-B lookup failed.'
                : (is_array($live) && $onGround
                    ? 'Aircraft is on the ground / not airborne.'
                    : 'No live ADS-B position is available.')),
        'aircraft' => array(
            'id' => $aircraftId,
            'registration' => $registration,
            'display_name' => $label,
            'adsb_hex' => $hex !== '' ? $hex : (is_array($live) ? tv_adsb_normalize_hex((string)($live['hex'] ?? '')) : ''),
        ),
        'window' => array(
            'from_utc' => $fromUtc->format('Y-m-d\TH:i:s\Z'),
            'to_utc' => $toUtc->format('Y-m-d\TH:i:s\Z'),
        ),
        'position' => $livePayload,
        'nearby' => $nearby,
        'traffic' => $traffic,
        'track' => array(
            'sample_count' => count($samples),
            'duration_s' => $duration,
            'source' => $trackSource,
            'samples' => $samples,
        ),
        'vertical_profile' => $verticalProfile,
        'fetch_error' => $fetchError,
    ));
} catch (Throwable $e) {
    schedule_adsb_track_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
