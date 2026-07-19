<?php
declare(strict_types=1);

/**
 * Boundary for future historical geographical traffic discovery.
 *
 * Per-aircraft traces are useful only after an aircraft identifier is known.
 * Complete nearby-traffic discovery requires historical all-aircraft snapshots
 * or a verified provider-side geographical historical query.
 */
interface AdsbHistoricalTrafficDiscoveryProvider
{
    /**
     * @param array{start_epoch:float,end_epoch:float,start_iso:string,end_iso:string,start_mysql:string,end_mysql:string} $window
     * @param list<array<string,mixed>> $corridor
     * @return array{observations:list<array<string,mixed>>,requests:list<array<string,mixed>>,capability:array<string,mixed>}
     */
    public function discover(array $window, array $corridor, float $radiusNm): array;
}

final class UnsupportedAdsbHistoricalTrafficDiscoveryProvider implements AdsbHistoricalTrafficDiscoveryProvider
{
    public function __construct(
        private readonly string $provider,
        private readonly bool $datasetAccessConfigured,
        private readonly bool $traceAccessConfigured
    ) {
    }

    public function discover(array $window, array $corridor, float $radiusNm): array
    {
        $requests = array();
        foreach ($corridor as $anchor) {
            $epoch = isset($anchor['epoch']) && is_numeric($anchor['epoch']) ? (float)$anchor['epoch'] : null;
            $requests[] = array(
                'provider' => $this->provider,
                'capability' => 'historical_geographical',
                'endpoint' => null,
                'method' => 'GET',
                'requested_utc' => $epoch !== null ? gmdate('c', (int)$epoch) : null,
                'latitude' => $anchor['latitude'] ?? null,
                'longitude' => $anchor['longitude'] ?? null,
                'radius_nm' => $radiusNm,
                'http_status' => null,
                'response_headers' => array(),
                'content_type' => null,
                'provider_response_utc' => null,
                'response_body_preview' => null,
                'parsed_aircraft_count' => 0,
                'returned_identifiers' => array(),
                'request_duration_ms' => 0,
                'transport_error' => null,
                'json_parse_error' => null,
                'result_status' => 'unsupported_capability',
                'reason' => 'Complete historical nearby-traffic discovery is unavailable with the configured ADS-B provider access. Only aircraft identifiers known from supplemental sources can have historical traces retrieved.',
            );
        }

        return array(
            'observations' => array(),
            'requests' => $requests,
            'capability' => array(
                'historical_geographical_discovery_supported' => false,
                'historical_geographical_discovery_verified' => false,
                'historical_geographical_discovery_provider' => $this->provider,
                'historical_geographical_discovery_endpoint' => null,
                'historical_geographical_discovery_error' => 'No verified ADS-B Exchange historical geographical/snapshot adapter is configured.',
                'historical_dataset_access_configured' => $this->datasetAccessConfigured,
                'historical_trace_access_configured' => $this->traceAccessConfigured,
            ),
        );
    }
}
