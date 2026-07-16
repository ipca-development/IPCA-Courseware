<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/tv_adsb_status.php';

final class AirportDetectionService
{
    public const VERSION = 'nearest_known_airport_v1';

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,mixed>
     */
    public function detect(array $rows, float $maxNm = 8.0): array
    {
        $samples = $this->positionSamples($rows);
        $departure = $samples ? $this->nearestAirport((float)$samples[0]['lat'], (float)$samples[0]['lon'], $maxNm) : null;
        $arrival = $samples ? $this->nearestAirport((float)$samples[count($samples) - 1]['lat'], (float)$samples[count($samples) - 1]['lon'], $maxNm) : null;
        $exceptions = array();
        if ($departure === null) {
            $exceptions[] = 'Departure airport could not be detected within ' . $maxNm . ' NM of the first Garmin position.';
        }
        if ($arrival === null) {
            $exceptions[] = 'Arrival airport could not be detected within ' . $maxNm . ' NM of the last Garmin position.';
        }

        return array(
            'type' => 'airport_detection',
            'status' => $exceptions ? 'needs_review' : 'ok',
            'verification_status' => $exceptions ? 'needs_review' : 'system_verified',
            'departure_airport_code' => $departure['icao'] ?? null,
            'arrival_airport_code' => $arrival['icao'] ?? null,
            'departure' => $departure,
            'arrival' => $arrival,
            'method' => 'nearest_known_airport',
            'calculation_version' => self::VERSION,
            'source' => array('type' => 'garmin_csv', 'fields' => array('Latitude', 'Longitude')),
            'confidence' => $exceptions ? 0.45 : 0.75,
            'exceptions' => $exceptions,
        );
    }

    /**
     * @param list<array<string,string>> $rows
     * @return list<array{time:DateTimeImmutable,lat:float,lon:float}>
     */
    private function positionSamples(array $rows): array
    {
        $samples = array();
        foreach ($rows as $row) {
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude', 'Lat');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon');
            if ($time !== null && $lat !== null && $lon !== null) {
                $samples[] = array('time' => $time, 'lat' => $lat, 'lon' => $lon);
            }
        }
        usort($samples, static fn($a, $b): int => $a['time'] <=> $b['time']);
        return $samples;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function nearestAirport(float $lat, float $lon, float $maxNm): ?array
    {
        $best = null;
        foreach (tv_adsb_airports() as $icao => $airport) {
            if (!isset($airport['lat'], $airport['lon'])) {
                continue;
            }
            $distanceNm = $this->distanceNm($lat, $lon, (float)$airport['lat'], (float)$airport['lon']);
            if ($distanceNm > $maxNm) {
                continue;
            }
            if ($best === null || $distanceNm < (float)$best['distance_nm']) {
                $best = array(
                    'icao' => (string)$icao,
                    'name' => (string)($airport['name'] ?? $icao),
                    'lat' => (float)$airport['lat'],
                    'lon' => (float)$airport['lon'],
                    'distance_nm' => round($distanceNm, 3),
                );
            }
        }
        return $best;
    }

    private function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusNm = 3440.065;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadiusNm * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
