<?php
declare(strict_types=1);

final class OperationalLegAllocationService
{
    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    public function allocateLegs(
        string $engineStartUtc,
        string $engineStopUtc,
        array $events,
        ?string $departureAirport = null,
        ?string $arrivalAirport = null
    ): array {
        $start = $this->time($engineStartUtc);
        $stop = $this->time($engineStopUtc);
        if ($stop <= $start) {
            throw new RuntimeException('Engine stop must be after engine start.');
        }

        $boundaries = array($start);
        $landingEvents = array_values(array_filter($events, static fn($event): bool => ($event['event_type'] ?? '') === 'LANDING'));
        $takeoffEvents = array_values(array_filter($events, static fn($event): bool => ($event['event_type'] ?? '') === 'TAKEOFF'));
        foreach ($landingEvents as $landing) {
            $landingTime = $this->time((string)$landing['event_time_utc']);
            if ($landingTime <= $start || $landingTime >= $stop) {
                continue;
            }
            $hasSubsequentTakeoff = false;
            foreach ($takeoffEvents as $takeoff) {
                if ($this->time((string)$takeoff['event_time_utc']) > $landingTime) {
                    $hasSubsequentTakeoff = true;
                    break;
                }
            }
            $landingAirport = trim((string)($landing['airport_code'] ?? ''));
            $isDifferentAirport = $departureAirport !== null && $landingAirport !== '' && strtoupper($landingAirport) !== strtoupper($departureAirport);
            if ($hasSubsequentTakeoff && ($isDifferentAirport || $landingAirport === '')) {
                $boundaries[] = $landingTime;
            }
        }
        $boundaries[] = $stop;
        usort($boundaries, static fn($a, $b): int => $a <=> $b);
        $boundaries = $this->uniqueTimes($boundaries);

        $legs = array();
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $legStart = $boundaries[$i];
            $legEnd = $boundaries[$i + 1];
            $duration = $this->diffMs($legStart, $legEnd);
            if ($duration <= 0) {
                continue;
            }
            $legEvents = $this->eventsBetween($events, $legStart, $legEnd, $i === count($boundaries) - 2);
            $legs[] = array(
                'leg_index' => count($legs) + 1,
                'allocation_start_utc' => $legStart->format('Y-m-d H:i:s.v'),
                'allocation_end_utc' => $legEnd->format('Y-m-d H:i:s.v'),
                'allocated_hobbs_duration_ms' => $duration,
                'departure_airport_code' => $i === 0 ? $departureAirport : null,
                'arrival_airport_code' => $i === count($boundaries) - 2 ? $arrivalAirport : null,
                'takeoff_utc' => $this->firstEventTime($legEvents, 'TAKEOFF'),
                'landing_utc' => $this->lastEventTime($legEvents, 'LANDING'),
                'first_movement_utc' => $this->firstEventTime($legEvents, 'FIRST_MOVEMENT'),
                'final_stop_utc' => $i === count($boundaries) - 2 ? $engineStopUtc : null,
                'landing_event_count' => count(array_filter($legEvents, static fn($event): bool => ($event['event_type'] ?? '') === 'LANDING')),
            );
        }

        $sum = array_sum(array_map(static fn($leg): int => (int)$leg['allocated_hobbs_duration_ms'], $legs));
        $sessionDuration = $this->diffMs($start, $stop);
        if (abs($sum - $sessionDuration) > 1) {
            throw new RuntimeException('Leg allocation does not reconcile to session Hobbs duration.');
        }
        return $legs;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function eventsBetween(array $events, DateTimeImmutable $start, DateTimeImmutable $end, bool $includeEnd): array
    {
        return array_values(array_filter($events, function (array $event) use ($start, $end, $includeEnd): bool {
            $time = $this->time((string)$event['event_time_utc']);
            return $time >= $start && ($includeEnd ? $time <= $end : $time < $end);
        }));
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function firstEventTime(array $events, string $type): ?string
    {
        foreach ($events as $event) {
            if (($event['event_type'] ?? '') === $type) {
                return (string)$event['event_time_utc'];
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function lastEventTime(array $events, string $type): ?string
    {
        $last = null;
        foreach ($events as $event) {
            if (($event['event_type'] ?? '') === $type) {
                $last = (string)$event['event_time_utc'];
            }
        }
        return $last;
    }

    /**
     * @param list<DateTimeImmutable> $times
     * @return list<DateTimeImmutable>
     */
    private function uniqueTimes(array $times): array
    {
        $unique = array();
        foreach ($times as $time) {
            $key = $time->format('U.u');
            $unique[$key] = $time;
        }
        return array_values($unique);
    }

    private function time(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    private function diffMs(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int)round(((float)$end->format('U.u') - (float)$start->format('U.u')) * 1000);
    }
}
