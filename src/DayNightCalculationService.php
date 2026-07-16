<?php
declare(strict_types=1);

require_once __DIR__ . '/FlightNightCrossCountryService.php';

final class DayNightCalculationService
{
    public const VERSION = 'student_after_sunset_plus_30m_v1';

    /**
     * @param list<array<string,mixed>> $legs
     * @param array<string,mixed>|null $referenceAirport
     * @return array<string,mixed>
     */
    public function calculate(array $legs, ?array $referenceAirport, string $timezone = 'UTC'): array
    {
        if ($referenceAirport === null || !isset($referenceAirport['lat'], $referenceAirport['lon'])) {
            return array(
                'type' => 'day_night',
                'status' => 'needs_review',
                'verification_status' => 'needs_review',
                'session_night_duration_ms' => null,
                'session_night_hours_exact' => null,
                'session_night_hours_display' => null,
                'legs' => $legs,
                'method' => 'airport_sun_times',
                'calculation_version' => self::VERSION,
                'source' => array('type' => 'airport_detection'),
                'confidence' => 0.0,
                'exceptions' => array('Night allocation requires a detected airport reference position.'),
            );
        }

        $result = (new FlightNightCrossCountryService())->allocateNight($legs, (float)$referenceAirport['lat'], (float)$referenceAirport['lon'], $timezone);
        $nightMs = (int)$result['session_night_duration_ms'];
        return array(
            'type' => 'day_night',
            'status' => 'ok',
            'verification_status' => 'system_verified',
            'session_night_duration_ms' => $nightMs,
            'session_night_hours_exact' => $nightMs / 3600000,
            'session_night_hours_display' => round($nightMs / 3600000, 1),
            'legs' => $result['legs'],
            'method' => 'airport_sun_times',
            'calculation_version' => self::VERSION,
            'source' => array('type' => 'detected_airport', 'airport' => $referenceAirport['icao'] ?? null, 'timezone' => $timezone),
            'confidence' => 0.75,
            'exceptions' => array(),
        );
    }
}
