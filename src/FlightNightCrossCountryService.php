<?php
declare(strict_types=1);

final class FlightNightCrossCountryService
{
    /**
     * @param list<array<string,mixed>> $legs
     * @return array{session_night_duration_ms:int,legs:list<array<string,mixed>>,rule_version:string}
     */
    public function allocateNight(array $legs, float $latitude, float $longitude, string $timezone = 'UTC'): array
    {
        $sessionNight = 0;
        $out = array();
        foreach ($legs as $leg) {
            $nightMs = $this->nightDurationMs(
                new DateTimeImmutable((string)$leg['allocation_start_utc'], new DateTimeZone('UTC')),
                new DateTimeImmutable((string)$leg['allocation_end_utc'], new DateTimeZone('UTC')),
                $latitude,
                $longitude,
                $timezone
            );
            $sessionNight += $nightMs;
            $leg['night_duration_ms'] = $nightMs;
            $out[] = $leg;
        }
        return array('session_night_duration_ms' => $sessionNight, 'legs' => $out, 'rule_version' => 'student_after_sunset_plus_30m_v1');
    }

    /**
     * @param list<array<string,mixed>> $legs
     * @param array<string,array{lat:float,lon:float}> $airports
     * @return array<string,mixed>
     */
    public function classifyCrossCountry(array $legs, array $airports, string $originalDeparture): array
    {
        $originalDeparture = strtoupper(trim($originalDeparture));
        $easaQualified = false;
        $faaQualified = false;
        $qualifyingAirports = array();
        foreach ($legs as $leg) {
            $arrival = strtoupper(trim((string)($leg['arrival_airport_code'] ?? '')));
            if ($arrival === '' || $arrival === $originalDeparture) {
                continue;
            }
            $easaQualified = true;
            $distanceNm = null;
            if (isset($airports[$originalDeparture], $airports[$arrival])) {
                $distanceNm = $this->distanceNm(
                    (float)$airports[$originalDeparture]['lat'],
                    (float)$airports[$originalDeparture]['lon'],
                    (float)$airports[$arrival]['lat'],
                    (float)$airports[$arrival]['lon']
                );
                if ($distanceNm > 50.0) {
                    $faaQualified = true;
                }
            }
            $qualifyingAirports[] = array('airport' => $arrival, 'distance_nm' => $distanceNm);
        }
        $classifiedLegs = array();
        foreach ($legs as $leg) {
            $duration = (int)($leg['allocated_hobbs_duration_ms'] ?? 0);
            $leg['cross_country_easa_qualified'] = $easaQualified ? 1 : 0;
            $leg['cross_country_faa_qualified'] = $faaQualified ? 1 : 0;
            $leg['allocated_cross_country_duration_ms'] = ($easaQualified || $faaQualified) ? $duration : 0;
            $classifiedLegs[] = $leg;
        }
        return array(
            'easa_qualified' => $easaQualified,
            'faa_qualified' => $faaQualified,
            'qualifying_airports' => $qualifyingAirports,
            'legs' => $classifiedLegs,
            'rule_version' => 'phase3_cross_country_v1',
        );
    }

    private function nightDurationMs(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, float $lat, float $lon, string $timezone): int
    {
        if ($endUtc <= $startUtc) {
            return 0;
        }
        $cursor = $startUtc;
        $total = 0;
        while ($cursor < $endUtc) {
            $dayEnd = (new DateTimeImmutable($cursor->format('Y-m-d 23:59:59'), new DateTimeZone('UTC')))->modify('+1 second');
            $sliceEnd = min($dayEnd, $endUtc);
            $sun = date_sun_info($cursor->getTimestamp(), $lat, $lon);
            $sunset = isset($sun['sunset']) && is_int($sun['sunset']) ? $sun['sunset'] + 1800 : null;
            $sunriseNext = date_sun_info($cursor->modify('+1 day')->getTimestamp(), $lat, $lon);
            $sunrise = isset($sunriseNext['sunrise']) && is_int($sunriseNext['sunrise']) ? $sunriseNext['sunrise'] : null;
            if ($sunset !== null && $sunrise !== null) {
                $nightStart = (new DateTimeImmutable('@' . $sunset))->setTimezone(new DateTimeZone('UTC'));
                $nightEnd = (new DateTimeImmutable('@' . $sunrise))->setTimezone(new DateTimeZone('UTC'));
                $overlapStart = max($cursor, $nightStart);
                $overlapEnd = min($sliceEnd, $nightEnd);
                if ($overlapEnd > $overlapStart) {
                    $total += (int)round(((float)$overlapEnd->format('U.u') - (float)$overlapStart->format('U.u')) * 1000);
                }
            }
            $cursor = $sliceEnd;
        }
        return $total;
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
