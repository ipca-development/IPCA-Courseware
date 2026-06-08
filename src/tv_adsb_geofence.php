<?php
declare(strict_types=1);

function tv_adsb_point_in_polygon(float $lat, float $lon, array $polygon): bool
{
    if (count($polygon) < 3) {
        return false;
    }

    $inside = false;
    $j = count($polygon) - 1;
    for ($i = 0; $i < count($polygon); $i++) {
        $yi = (float)$polygon[$i][0];
        $xi = (float)$polygon[$i][1];
        $yj = (float)$polygon[$j][0];
        $xj = (float)$polygon[$j][1];
        $intersects = (($yi > $lat) !== ($yj > $lat))
            && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($intersects) {
            $inside = !$inside;
        }
        $j = $i;
    }

    return $inside;
}

function tv_adsb_distance_to_polygon_nm(float $lat, float $lon, array $polygon): float
{
    if (tv_adsb_point_in_polygon($lat, $lon, $polygon)) {
        return 0.0;
    }

    $best = 999.0;
    $count = count($polygon);
    for ($i = 0; $i < $count; $i++) {
        $j = ($i + 1) % $count;
        $dist = tv_adsb_point_to_segment_nm(
            $lat,
            $lon,
            (float)$polygon[$i][0],
            (float)$polygon[$i][1],
            (float)$polygon[$j][0],
            (float)$polygon[$j][1]
        );
        if ($dist < $best) {
            $best = $dist;
        }
    }

    return $best;
}

function tv_adsb_point_to_segment_nm(
    float $lat,
    float $lon,
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2
): float {
    $dx = $lat2 - $lat1;
    $dy = $lon2 - $lon1;
    if (abs($dx) < 1e-12 && abs($dy) < 1e-12) {
        return tv_adsb_haversine_nm($lat, $lon, $lat1, $lon1);
    }

    $t = (($lat - $lat1) * $dx + ($lon - $lon1) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0.0, min(1.0, $t));
    $projLat = $lat1 + $t * $dx;
    $projLon = $lon1 + $t * $dy;
    return tv_adsb_haversine_nm($lat, $lon, $projLat, $projLon);
}

function tv_adsb_spc_parking_polygon(array $gate): array
{
    $lat = (float)($gate['lat'] ?? 33.6267);
    $lon = (float)($gate['lon'] ?? -116.1600);
    $halfLat = (float)($gate['parking_half_lat'] ?? 0.00075);
    $halfLon = (float)($gate['parking_half_lon'] ?? 0.00085);

    return array(
        array($lat + $halfLat, $lon - $halfLon),
        array($lat + $halfLat, $lon + $halfLon),
        array($lat - $halfLat, $lon + $halfLon),
        array($lat - $halfLat, $lon - $halfLon),
    );
}

function tv_adsb_airport_surface_radius(string $icao): float
{
    $icao = strtoupper(trim($icao));
    $airports = tv_adsb_airports();
    return (float)($airports[$icao]['surface_nm'] ?? 1.8);
}

function tv_adsb_airport_boundary_radius(string $icao): float
{
    $icao = strtoupper(trim($icao));
    $airports = tv_adsb_airports();
    return (float)($airports[$icao]['boundary_nm'] ?? 5.0);
}

function tv_adsb_on_airport_surface(float $lat, float $lon, ?string $icao = null): bool
{
    if ($icao !== null && $icao !== '') {
        $airports = tv_adsb_airports();
        if (!isset($airports[$icao])) {
            return false;
        }
        $airport = $airports[$icao];
        $dist = tv_adsb_haversine_nm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
        return $dist <= tv_adsb_airport_surface_radius($icao);
    }

    foreach (tv_adsb_airports() as $code => $airport) {
        $dist = tv_adsb_haversine_nm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
        if ($dist <= tv_adsb_airport_surface_radius($code)) {
            return true;
        }
    }

    return false;
}

function tv_adsb_within_airport_boundary(float $lat, float $lon, ?string $icao = null): bool
{
    if ($icao !== null && $icao !== '') {
        $airports = tv_adsb_airports();
        if (!isset($airports[$icao])) {
            return false;
        }
        $airport = $airports[$icao];
        $dist = tv_adsb_haversine_nm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
        return $dist <= tv_adsb_airport_boundary_radius($icao);
    }

    foreach (tv_adsb_airports() as $code => $airport) {
        $dist = tv_adsb_haversine_nm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
        if ($dist <= tv_adsb_airport_boundary_radius($code)) {
            return true;
        }
    }

    return false;
}

function tv_adsb_nearest_airport_boundary(float $lat, float $lon, float $maxNm = 8.0): ?array
{
    $best = null;
    $bestDist = $maxNm;

    foreach (tv_adsb_airports() as $icao => $airport) {
        $dist = tv_adsb_haversine_nm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
        if ($dist <= tv_adsb_airport_boundary_radius($icao) && $dist <= $bestDist) {
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

function tv_adsb_short_cardinal(float $bearing): string
{
    $bearing = fmod(($bearing + 360.0), 360.0);
    $labels = array('N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW');
    $idx = (int)round($bearing / 45.0) % 8;
    return $labels[$idx];
}

function tv_adsb_round_altitude_100(?float $altFt): ?int
{
    if ($altFt === null) {
        return null;
    }
    return (int)(round($altFt / 100.0) * 100);
}

function tv_adsb_position_variation_m(array $history, int $seconds = 300): float
{
    $cutoff = time() - $seconds;
    $samples = array_values(array_filter($history, static function (array $row) use ($cutoff): bool {
        return (int)($row['t'] ?? 0) >= $cutoff;
    }));

    if (count($samples) < 2) {
        return 999.0;
    }

    $maxM = 0.0;
    $first = $samples[0];
    foreach ($samples as $sample) {
        $distNm = tv_adsb_haversine_nm(
            (float)($first['lat'] ?? 0),
            (float)($first['lon'] ?? 0),
            (float)($sample['lat'] ?? 0),
            (float)($sample['lon'] ?? 0)
        );
        $maxM = max($maxM, $distNm * 1852.0);
    }

    return $maxM;
}
