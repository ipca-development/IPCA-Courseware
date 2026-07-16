<?php
declare(strict_types=1);

require_once __DIR__ . '/FlightNightCrossCountryService.php';
require_once __DIR__ . '/tv_adsb_status.php';

final class CrossCountryCalculationService
{
    public const VERSION = 'phase3_cross_country_v1';

    /**
     * @param list<array<string,mixed>> $legs
     * @return array<string,mixed>
     */
    public function calculate(array $legs, ?string $originalDeparture): array
    {
        $originalDeparture = strtoupper(trim((string)$originalDeparture));
        if ($originalDeparture === '') {
            return array(
                'type' => 'cross_country',
                'status' => 'needs_review',
                'verification_status' => 'needs_review',
                'easa_qualified' => false,
                'faa_qualified' => false,
                'legs' => $legs,
                'method' => 'airport_pair_distance',
                'calculation_version' => self::VERSION,
                'source' => array('type' => 'airport_detection'),
                'confidence' => 0.0,
                'exceptions' => array('Cross-country classification requires a detected departure airport.'),
            );
        }

        $airports = array();
        foreach (tv_adsb_airports() as $icao => $airport) {
            if (isset($airport['lat'], $airport['lon'])) {
                $airports[(string)$icao] = array('lat' => (float)$airport['lat'], 'lon' => (float)$airport['lon']);
            }
        }
        $result = (new FlightNightCrossCountryService())->classifyCrossCountry($legs, $airports, $originalDeparture);
        return array(
            'type' => 'cross_country',
            'status' => 'ok',
            'verification_status' => 'system_verified',
            'easa_qualified' => (bool)$result['easa_qualified'],
            'faa_qualified' => (bool)$result['faa_qualified'],
            'qualifying_airports' => $result['qualifying_airports'],
            'legs' => $result['legs'],
            'method' => 'airport_pair_distance',
            'calculation_version' => self::VERSION,
            'source' => array('type' => 'detected_airports', 'original_departure' => $originalDeparture),
            'confidence' => 0.75,
            'exceptions' => array(),
        );
    }
}
