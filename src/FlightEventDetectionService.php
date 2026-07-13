<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';

final class FlightEventDetectionService
{
    /**
     * @param list<array<string,string>> $rows
     * @param array<string,mixed> $config
     * @return list<array<string,mixed>>
     */
    public function detectEvents(array $rows, array $config): array
    {
        $samples = $this->samples($rows);
        $events = array();
        $movement = $this->firstSustainedSpeed($samples, (float)($config['movement_groundspeed_kt'] ?? 3.0), (int)($config['movement_confirm_ms'] ?? 3000));
        if ($movement !== null) {
            $events[] = $this->event('FIRST_MOVEMENT', $movement, 'groundspeed_threshold', 0.75);
        }
        foreach ($this->detectTakeoffLandingPairs($samples) as $event) {
            $events[] = $event;
        }
        if ($samples) {
            $events[] = $this->event('FINAL_STOP', $samples[count($samples) - 1]['time'], 'last_valid_sample', 0.50);
        }
        usort($events, static fn($a, $b): int => strcmp((string)$a['event_time_utc'], (string)$b['event_time_utc']));
        return $events;
    }

    /**
     * @param list<array{time:DateTimeImmutable,groundspeed_kt:?float,altitude_ft:?float,vertical_speed_fpm:?float,lat:?float,lon:?float}> $samples
     * @return list<array<string,mixed>>
     */
    private function detectTakeoffLandingPairs(array $samples): array
    {
        $events = array();
        $airborne = false;
        $lastTakeoff = null;
        foreach ($samples as $index => $sample) {
            $speed = $sample['groundspeed_kt'] ?? 0.0;
            $vs = $sample['vertical_speed_fpm'] ?? 0.0;
            $alt = $sample['altitude_ft'];
            $prevAlt = $index > 0 ? $samples[$index - 1]['altitude_ft'] : null;
            $climbing = $alt !== null && $prevAlt !== null && ($alt - $prevAlt) >= 10.0;
            if (!$airborne && $speed >= 40.0 && ($vs > 100.0 || $climbing)) {
                $airborne = true;
                $lastTakeoff = $sample['time'];
                $events[] = $this->event('TAKEOFF', $sample['time'], 'speed_vertical_profile', 0.60, $sample);
                continue;
            }
            if ($airborne && $lastTakeoff !== null && $speed <= 35.0 && $vs < 100.0) {
                $airborneMs = (int)round(((float)$sample['time']->format('U.u') - (float)$lastTakeoff->format('U.u')) * 1000);
                if ($airborneMs >= 60000) {
                    $events[] = $this->event('LANDING', $sample['time'], 'speed_decay_after_airborne', 0.55, $sample);
                    $airborne = false;
                    $lastTakeoff = null;
                }
            }
        }
        return $events;
    }

    /**
     * @param list<array{time:DateTimeImmutable,groundspeed_kt:?float,altitude_ft:?float,vertical_speed_fpm:?float,lat:?float,lon:?float}> $samples
     */
    private function firstSustainedSpeed(array $samples, float $thresholdKt, int $confirmMs): ?DateTimeImmutable
    {
        $since = null;
        foreach ($samples as $sample) {
            if (($sample['groundspeed_kt'] ?? 0.0) > $thresholdKt) {
                $since ??= $sample['time'];
                $durationMs = (int)round(((float)$sample['time']->format('U.u') - (float)$since->format('U.u')) * 1000);
                if ($durationMs >= $confirmMs) {
                    return $since;
                }
            } else {
                $since = null;
            }
        }
        return null;
    }

    /**
     * @param array{time:DateTimeImmutable,groundspeed_kt:?float,altitude_ft:?float,vertical_speed_fpm:?float,lat:?float,lon:?float}|null $sample
     * @return array<string,mixed>
     */
    private function event(string $type, DateTimeImmutable $time, string $method, float $confidence, ?array $sample = null): array
    {
        return array(
            'event_type' => $type,
            'event_time_utc' => $time->format('Y-m-d H:i:s.v'),
            'detection_method' => $method,
            'confidence' => $confidence,
            'latitude' => $sample['lat'] ?? null,
            'longitude' => $sample['lon'] ?? null,
        );
    }

    /**
     * @param list<array<string,string>> $rows
     * @return list<array{time:DateTimeImmutable,groundspeed_kt:?float,altitude_ft:?float,vertical_speed_fpm:?float,lat:?float,lon:?float}>
     */
    private function samples(array $rows): array
    {
        $samples = array();
        foreach ($rows as $row) {
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            if ($time === null) {
                continue;
            }
            $samples[] = array(
                'time' => $time,
                'groundspeed_kt' => G3XFlightStreamParser::numericValue($row, 'GPS Ground Speed (kt)', 'GPS GS', 'GndSpd'),
                'altitude_ft' => G3XFlightStreamParser::numericValue($row, 'GPS Altitude (ft)', 'Baro Altitude (ft)', 'AltGPS', 'AltB'),
                'vertical_speed_fpm' => G3XFlightStreamParser::numericValue($row, 'Vertical Speed (ft/min)', 'VSpd'),
                'lat' => G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude', 'Lat'),
                'lon' => G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon'),
            );
        }
        usort($samples, static fn($a, $b): int => $a['time'] <=> $b['time']);
        return $samples;
    }
}
