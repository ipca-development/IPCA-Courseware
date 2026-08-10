<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/CvrAdminLegCorrectionService.php';
require_once __DIR__ . '/CvrFinancialDispatchService.php';
require_once __DIR__ . '/CvrOperationalBlockTimeService.php';
require_once __DIR__ . '/CvrOperationalIdentityService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/tv_adsb_status.php';

/**
 * Admin recovery: annotate one continuous Operational Leg / Check-In with multi-leg detail.
 *
 * Keeps a single Dispatch + Flight Record (replay, CSV, debrief, financials stay whole).
 * Stores leg_segments on the dispatch version payload for Master Logbook + Schedule display.
 *
 * Dummy-proof sources (highest confidence first):
 * 1) Planned reservation hops
 * 2) CSV ground stops near airports
 * 3) CVR GPS landing cycles with a later takeoff
 *
 * Final destination Hobbs/Tacho from Check-In remain authoritative; intermediate meters
 * are proposed by time proportion across Off Block → On Block.
 */
final class CvrAdminLegSplitService
{
    private const MIN_STOP_SECONDS = 240;
    private const MAX_GROUND_SPEED_KT = 25.0;
    private const AIRPORT_RADIUS_NM = 8.0;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(int $dispatchId): array
    {
        $context = $this->loadContext($dispatchId);
        $stops = $this->detectCsvGroundStops($context['garmin_path']);
        $csvEndpoints = $this->detectCsvEndpoints($context['garmin_path']);
        $cycles = $this->detectCvrLandingCycles($context['flight_uuid']);
        $planned = $context['planned_hops'];

        $routeAirports = $this->proposeRouteAirports(
            $context['departure_airport'],
            $context['arrival_airport'],
            $planned,
            $stops,
            $cycles,
            $csvEndpoints
        );
        $legs = count($routeAirports) >= 2
            ? $this->buildProposedLegs($context, $routeAirports, $cycles, $stops)
            : array();
        return array(
            'dispatch_id' => $dispatchId,
            'workflow_flight_record_uuid' => $context['flight_uuid'],
            'scheduler_record_id' => $context['scheduler_record_id'],
            'reservation_uuid' => $context['reservation_uuid'],
            'has_garmin_csv' => $context['garmin_path'] !== null,
            'planned_hops' => $planned,
            'csv_ground_stops' => $stops,
            'csv_endpoints' => $csvEndpoints,
            'cvr_landing_cycles' => $cycles,
            'route_airports' => $routeAirports,
            'proposed_legs' => $legs,
            'starting_hobbs' => $context['starting_hobbs'],
            'ending_hobbs' => $context['ending_hobbs'],
            'starting_tacho' => $context['starting_tacho'],
            'ending_tacho' => $context['ending_tacho'],
            'fuel_start' => $context['fuel_start'],
            'fuel_end' => $context['fuel_end'],
            'fuel_burn_total' => ($context['fuel_start'] !== null && $context['fuel_end'] !== null)
                ? round(max(0.0, $context['fuel_start'] - $context['fuel_end']), 1)
                : null,
            'off_block_utc' => $context['off_block_utc'],
            'on_block_utc' => $context['on_block_utc'],
            'verified_takeoff_count' => $context['verified_takeoff_count'],
            'verified_landing_count' => $context['verified_landing_count'],
            'crew' => $context['crew'],
            'notes' => $this->previewNotes($planned, $stops, $cycles, $routeAirports),
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function apply(int $dispatchId, array $input, ?int $actorUserId = null): array
    {
        if ($dispatchId <= 0) {
            throw new InvalidArgumentException('dispatch_id is required.');
        }

        $financial = (new CvrFinancialDispatchService($this->pdo))->forDispatch($dispatchId);
        if (is_array($financial) && !empty($financial['is_locked'])) {
            throw new RuntimeException('Unlock the financial dispatch before annotating legs.');
        }

        $legsInput = $input['legs'] ?? null;
        if (!is_array($legsInput) || count($legsInput) < 2) {
            $preview = $this->preview($dispatchId);
            $legsInput = $preview['proposed_legs'];
        }

        $legs = $this->normalizeLegInputs($legsInput);
        if (count($legs) < 2) {
            throw new InvalidArgumentException('At least two legs are required.');
        }

        $context = $this->loadContext($dispatchId);
        $this->assertMeterChain($legs, $context);

        $first = $legs[0];
        $last = $legs[count($legs) - 1];
        $totalTakeoffs = 0;
        $totalLandings = 0;
        foreach ($legs as $leg) {
            $totalTakeoffs += max(0, (int)$leg['takeoff_count']);
            $totalLandings += max(0, (int)$leg['landing_count']);
        }

        $segments = array();
        foreach ($legs as $index => $leg) {
            $segments[] = array(
                'sequence_number' => $index + 1,
                'departure_airport' => $leg['departure_airport'],
                'arrival_airport' => $leg['arrival_airport'],
                'off_block_utc' => $leg['off_block_utc'],
                'on_block_utc' => $this->derivedOnBlockUtc(
                    $leg['off_block_utc'],
                    $leg['starting_hobbs'],
                    $leg['ending_hobbs']
                ),
                'starting_hobbs' => $leg['starting_hobbs'],
                'ending_hobbs' => $leg['ending_hobbs'],
                'starting_tacho' => $leg['starting_tacho'],
                'ending_tacho' => $leg['ending_tacho'],
                'hobbs_delta' => round($leg['ending_hobbs'] - $leg['starting_hobbs'], 1),
                'tacho_delta' => round($leg['ending_tacho'] - $leg['starting_tacho'], 1),
                'takeoff_count' => max(0, (int)$leg['takeoff_count']),
                'landing_count' => max(0, (int)$leg['landing_count']),
                'fuel_onboard' => $leg['fuel_onboard'],
                'fuel_remaining' => $leg['fuel_remaining'],
                'fuel_burn' => ($leg['fuel_onboard'] !== null && $leg['fuel_remaining'] !== null)
                    ? round(max(0.0, (float)$leg['fuel_onboard'] - (float)$leg['fuel_remaining']), 1)
                    : null,
            );
        }

        $this->pdo->beginTransaction();
        try {
            $correction = new CvrAdminLegCorrectionService($this->pdo);
            $correction->save($dispatchId, array(
                'mission_code' => $context['mission_code'],
                'aircraft_registration' => $context['aircraft_registration'],
                'departure_airport' => $first['departure_airport'],
                'arrival_airport' => $last['arrival_airport'],
                'starting_hobbs' => $first['starting_hobbs'],
                'ending_hobbs' => $last['ending_hobbs'],
                'starting_tacho' => $first['starting_tacho'],
                'ending_tacho' => $last['ending_tacho'],
                'fuel_onboard' => $first['fuel_onboard'] !== null
                    ? (string)$first['fuel_onboard']
                    : $context['fuel_onboard'],
                'fuel_remaining' => $last['fuel_remaining'] !== null
                    ? (string)$last['fuel_remaining']
                    : $context['fuel_remaining'],
                'oil_percentage' => $context['oil_percentage'],
                'oil_quantity' => $context['oil_quantity'],
                'oil_unit' => $context['oil_unit'],
                'takeoff_count' => $totalTakeoffs,
                'landing_count' => $totalLandings,
                'crew' => $context['crew'],
                'off_block_local' => $this->utcToLocal($first['off_block_utc'], $context['timezone']),
                'timezone' => $context['timezone'],
                'maintenance_remark' => 'Annotated continuous Check-In with '
                    . count($segments)
                    . ' legs (single Operational Leg retained).',
            ), $actorUserId);

            $this->persistLegSegments($dispatchId, $segments);

            $identityRows = array();
            foreach ($segments as $segment) {
                $identityRows[] = array(
                    'dispatch_id' => $dispatchId,
                    'workflow_flight_record_uuid' => $context['flight_uuid'],
                    'leg_index' => (int)$segment['sequence_number'],
                    'departure_airport' => $segment['departure_airport'],
                    'arrival_airport' => $segment['arrival_airport'],
                );
            }
            $this->syncIdentityLegs($context, $identityRows);

            try {
                (new AuditEventService($this->pdo))->record(
                    'operational_leg.annotate_legs',
                    'ipca_cvr_dispatches',
                    (string)$dispatchId,
                    array(
                        'workflow_flight_record_uuid' => $context['flight_uuid'],
                        'route' => $first['departure_airport'] . '>' . $last['arrival_airport'],
                    ),
                    array(
                        'leg_count' => count($segments),
                        'via_airports' => self::viaAirportsFromSegments($segments),
                        'legs' => $segments,
                    ),
                    'Master Logbook annotated continuous Check-In with multi-leg detail',
                    'admin',
                    $actorUserId,
                    null,
                    null,
                    1,
                    'master_logbook'
                );
            } catch (Throwable) {
                // Audit is best-effort.
            }

            $this->pdo->commit();
            return array(
                'dispatch_id' => $dispatchId,
                'leg_count' => count($segments),
                'via_airports' => self::viaAirportsFromSegments($segments),
                'legs' => $segments,
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @return list<string>
     */
    public static function viaAirportsFromSegments(array $segments): array
    {
        if (count($segments) < 2) {
            return array();
        }
        $via = array();
        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue;
            }
            $dep = strtoupper(trim((string)($segment['departure_airport'] ?? '')));
            if ($dep !== '' && !in_array($dep, $via, true)) {
                $via[] = $dep;
            }
        }
        $finalArr = strtoupper(trim((string)($segments[count($segments) - 1]['arrival_airport'] ?? '')));
        return array_values(array_filter(
            $via,
            static fn(string $icao): bool => $icao !== '' && $icao !== $finalArr
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function loadContext(int $dispatchId): array
    {
        if ($dispatchId <= 0) {
            throw new InvalidArgumentException('dispatch_id is required.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_cvr_dispatches WHERE id = ? LIMIT 1');
        $stmt->execute(array($dispatchId));
        $dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dispatch)) {
            throw new RuntimeException('Dispatch leg not found.');
        }

        $flightUuid = strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')));
        if ($flightUuid === '') {
            throw new RuntimeException('Dispatch is missing a Flight Record UUID.');
        }

        $closureStmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_closures
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $closureStmt->execute(array($flightUuid));
        $closure = $closureStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($closure)) {
            throw new RuntimeException('Split requires a completed Check-In (flight closure).');
        }

        $adjustmentStmt = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_flight_log_adjustments
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $adjustmentStmt->execute(array($flightUuid));
        $adjustment = $adjustmentStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($adjustment)) {
            $adjustment = array();
        }

        $airports = $this->dispatchAirports($dispatch);
        $startingHobbs = $this->decimal($adjustment['starting_hobbs'] ?? $dispatch['starting_hobbs'] ?? null);
        $endingHobbs = $this->decimal($adjustment['ending_hobbs'] ?? $closure['ending_hobbs'] ?? null);
        $startingTacho = $this->decimal($adjustment['starting_tacho'] ?? $dispatch['starting_tacho'] ?? null);
        $endingTacho = $this->decimal($adjustment['ending_tacho'] ?? $closure['ending_tacho'] ?? null);
        if ($startingHobbs === null || $endingHobbs === null || $startingTacho === null || $endingTacho === null) {
            throw new RuntimeException('Split requires starting and ending Hobbs/Tacho.');
        }

        $offBlock = $this->eventTimestamp($flightUuid, 'engine_start_off_block');
        $onBlock = $this->eventTimestamp($flightUuid, 'engine_shutdown_on_block');
        if ($offBlock === null) {
            $offBlock = $this->closureTimestamp($closure, 'off_block_utc');
        }
        if ($onBlock === null) {
            $onBlock = $this->closureTimestamp($closure, 'on_block_utc');
        }
        if ($offBlock === null || $onBlock === null) {
            throw new RuntimeException('Split requires Off Block and On Block times.');
        }

        $scheduler = strtolower(trim((string)($dispatch['scheduler_record_id'] ?? '')));
        $identity = $this->resolveReservation($dispatch);
        $garmin = $this->latestGarminPath($flightUuid);
        $crew = json_decode((string)($dispatch['crew_json'] ?? '[]'), true);
        if (!is_array($crew)) {
            $crew = array();
        }
        $verifiedOps = $this->closureOperationCounts($closure, $flightUuid);
        $fuelStart = $this->fuelNumber($dispatch['fuel_onboard'] ?? null);
        $effectiveFuelRemaining = $adjustment['fuel_remaining'] ?? $closure['fuel_remaining'] ?? null;
        $fuelEnd = $this->fuelNumber($effectiveFuelRemaining);

        return array(
            'dispatch' => $dispatch,
            'dispatch_id' => $dispatchId,
            'flight_uuid' => $flightUuid,
            'dispatch_uuid' => strtolower(trim((string)($dispatch['dispatch_uuid'] ?? ''))),
            'scheduler_record_id' => $scheduler,
            'reservation_uuid' => $identity['reservation_uuid'],
            'planned_hops' => $identity['planned_hops'],
            'departure_airport' => $airports['departure'],
            'arrival_airport' => $airports['arrival'],
            'starting_hobbs' => $startingHobbs,
            'ending_hobbs' => $endingHobbs,
            'starting_tacho' => $startingTacho,
            'ending_tacho' => $endingTacho,
            'fuel_onboard' => trim((string)($dispatch['fuel_onboard'] ?? '')),
            'fuel_remaining' => trim((string)$effectiveFuelRemaining),
            'fuel_start' => $fuelStart,
            'fuel_end' => $fuelEnd,
            'verified_takeoff_count' => $verifiedOps['takeoffs'],
            'verified_landing_count' => $verifiedOps['landings'],
            'oil_percentage' => $dispatch['oil_percentage'] ?? $closure['oil_percentage'] ?? null,
            'oil_quantity' => $dispatch['oil_quantity'] ?? $closure['oil_quantity'] ?? null,
            'oil_unit' => trim((string)($dispatch['oil_unit'] ?? $closure['oil_unit'] ?? '')),
            'mission_code' => trim((string)($dispatch['mission_code'] ?? '')),
            'aircraft_registration' => strtoupper(trim((string)($dispatch['aircraft_registration'] ?? ''))),
            'aircraft_id' => (int)($dispatch['aircraft_id'] ?? 0),
            'organization_id' => max(1, (int)($dispatch['organization_id'] ?? 1)),
            'device_id' => (int)($dispatch['device_id'] ?? 0),
            'crew' => $crew,
            'off_block_utc' => $offBlock,
            'on_block_utc' => $onBlock,
            'timezone' => 'America/Los_Angeles',
            'garmin_path' => $garmin,
            'cvr_unit_identifier' => (string)($dispatch['cvr_unit_identifier'] ?? ''),
            'beacon_identifier' => (string)($dispatch['beacon_identifier'] ?? ''),
            'scheduled_date' => (string)($dispatch['scheduled_date'] ?? ''),
            'status' => (string)($dispatch['status'] ?? 'flightRecordLoggingEnabled'),
            'consent_status' => (string)($dispatch['consent_status'] ?? 'complete'),
            'dispatch_source' => (string)($dispatch['dispatch_source'] ?? 'admin_leg_split'),
        );
    }

    /**
     * @param array<string,mixed> $dispatch
     * @return array{departure:string,arrival:string}
     */
    private function dispatchAirports(array $dispatch): array
    {
        $departure = '';
        $arrival = '';
        $version = (int)($dispatch['current_version'] ?? 1);
        $stmt = $this->pdo->prepare(
            'SELECT payload_json FROM ipca_cvr_dispatch_versions
             WHERE dispatch_id = ? AND dispatch_version = ? LIMIT 1'
        );
        $stmt->execute(array((int)$dispatch['id'], $version));
        $payload = json_decode((string)($stmt->fetchColumn() ?: '{}'), true);
        if (is_array($payload)) {
            $departure = strtoupper(trim((string)($payload['planned_departure_airport'] ?? '')));
            $arrival = strtoupper(trim((string)($payload['planned_destination_airport'] ?? '')));
        }
        $adj = $this->pdo->prepare(
            'SELECT departure_airport, arrival_airport FROM ipca_cvr_flight_log_adjustments
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $adj->execute(array(strtolower(trim((string)($dispatch['workflow_flight_record_uuid'] ?? '')))));
        $row = $adj->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $departure = strtoupper(trim((string)($row['departure_airport'] ?? $departure)));
            $arrival = strtoupper(trim((string)($row['arrival_airport'] ?? $arrival)));
        }
        return array('departure' => $departure, 'arrival' => $arrival);
    }

    /**
     * @param array<string,mixed> $dispatch
     * @return array{reservation_uuid:string,planned_hops:list<array{departure:string,destination:string,leg_uuid:?string}>}
     */
    private function resolveReservation(array $dispatch): array
    {
        $hops = array();
        $reservationUuid = '';
        try {
            $identity = new CvrOperationalIdentityService($this->pdo);
            $orgId = max(1, (int)($dispatch['organization_id'] ?? 1));
            $scheduler = strtolower(trim((string)($dispatch['scheduler_record_id'] ?? '')));
            $alias = null;
            if ($scheduler !== '') {
                $alias = $identity->findAlias($orgId, 'schedule', 'scheduler_record_id', $scheduler, null);
            }
            if (is_array($alias) && strtolower((string)($alias['target_type'] ?? '')) === 'reservation') {
                $reservationUuid = strtolower(trim((string)($alias['reservation_uuid'] ?? '')));
            }
            if ($reservationUuid !== '') {
                foreach ($identity->listLegsForReservation($reservationUuid) as $leg) {
                    if (!is_array($leg)) {
                        continue;
                    }
                    $dep = strtoupper(trim((string)($leg['origin_airport'] ?? '')));
                    $dest = strtoupper(trim((string)($leg['destination_airport'] ?? '')));
                    if ($dep === '' || $dest === '') {
                        continue;
                    }
                    $hops[] = array(
                        'departure' => $dep,
                        'destination' => $dest,
                        'leg_uuid' => strtolower(trim((string)($leg['leg_uuid'] ?? ''))) ?: null,
                        'status' => (string)($leg['status'] ?? ''),
                        'sequence_number' => (int)($leg['sequence_number'] ?? 0),
                    );
                }
            }
        } catch (Throwable) {
            // Identity is additive.
        }
        return array(
            'reservation_uuid' => $reservationUuid,
            'planned_hops' => $hops,
        );
    }

    private function latestGarminPath(string $flightUuid): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT storage_path FROM ipca_garmin_csv_files
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(array($flightUuid));
        $path = trim((string)($stmt->fetchColumn() ?: ''));
        return $path !== '' && is_readable($path) ? $path : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function detectCsvGroundStops(?string $path): array
    {
        if ($path === null) {
            return array();
        }
        try {
            $parsed = G3XFlightStreamParser::parseFile($path);
        } catch (Throwable) {
            return array();
        }
        $rows = is_array($parsed['rows'] ?? null) ? $parsed['rows'] : array();
        $samples = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude', 'Lat');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon');
            $gs = G3XFlightStreamParser::numericValue($row, 'GPS Ground Speed (kt)', 'Ground Speed (kt)', 'GndSpd');
            if ($time === null || $lat === null || $lon === null) {
                continue;
            }
            $samples[] = array('t' => $time, 'lat' => $lat, 'lon' => $lon, 'gs' => $gs);
        }
        if ($samples === array()) {
            return array();
        }

        $stops = array();
        $i = 0;
        $n = count($samples);
        while ($i < $n) {
            $gs = $samples[$i]['gs'];
            if ($gs === null || $gs >= self::MAX_GROUND_SPEED_KT) {
                $i++;
                continue;
            }
            $start = $i;
            while ($i < $n && ($samples[$i]['gs'] === null || $samples[$i]['gs'] < self::MAX_GROUND_SPEED_KT)) {
                $i++;
            }
            $end = $i - 1;
            $duration = $samples[$end]['t']->getTimestamp() - $samples[$start]['t']->getTimestamp();
            if ($duration < self::MIN_STOP_SECONDS) {
                continue;
            }
            $mid = $samples[(int)(($start + $end) / 2)];
            $near = $this->nearestAirport((float)$mid['lat'], (float)$mid['lon']);
            $stops[] = array(
                'start_utc' => $samples[$start]['t']->format('Y-m-d H:i:s'),
                'end_utc' => $samples[$end]['t']->format('Y-m-d H:i:s'),
                'duration_seconds' => $duration,
                'airport' => $near['icao'] ?? null,
                'distance_nm' => $near['nm'] ?? null,
                'latitude' => round((float)$mid['lat'], 6),
                'longitude' => round((float)$mid['lon'], 6),
            );
        }
        return $stops;
    }

    /** @return array{departure:?string,arrival:?string} */
    private function detectCsvEndpoints(?string $path): array
    {
        $empty = array('departure' => null, 'arrival' => null);
        if ($path === null) {
            return $empty;
        }
        try {
            $parsed = G3XFlightStreamParser::parseFile($path);
        } catch (Throwable) {
            return $empty;
        }
        $rows = is_array($parsed['rows'] ?? null) ? $parsed['rows'] : array();
        $positions = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude', 'Lat');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude', 'Lon');
            if ($lat !== null && $lon !== null) {
                $positions[] = array('lat' => $lat, 'lon' => $lon);
            }
        }
        if ($positions === array()) {
            return $empty;
        }
        $first = $positions[0];
        $last = $positions[count($positions) - 1];
        $departure = $this->nearestAirport((float)$first['lat'], (float)$first['lon']);
        $arrival = $this->nearestAirport((float)$last['lat'], (float)$last['lon']);
        return array(
            'departure' => isset($departure['nm']) && (float)$departure['nm'] <= 5.0
                ? (string)$departure['icao']
                : null,
            'arrival' => isset($arrival['nm']) && (float)$arrival['nm'] <= 5.0
                ? (string)$arrival['icao']
                : null,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function detectCvrLandingCycles(string $flightUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_type, timestamp_utc, latitude, longitude, payload_json
             FROM ipca_cvr_flight_events
             WHERE LOWER(workflow_flight_record_uuid) = ?
             ORDER BY timestamp_utc ASC, id ASC'
        );
        $stmt->execute(array($flightUuid));
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $cycles = array();
        for ($i = 0; $i < count($events); $i++) {
            $event = $events[$i];
            if (($event['event_type'] ?? '') !== 'gps_landing_provisional') {
                continue;
            }
            $hasLaterTakeoff = false;
            for ($j = $i + 1; $j < count($events); $j++) {
                if (($events[$j]['event_type'] ?? '') === 'gps_takeoff_provisional') {
                    $hasLaterTakeoff = true;
                    break;
                }
            }
            if (!$hasLaterTakeoff) {
                continue;
            }
            $lat = isset($event['latitude']) && is_numeric($event['latitude']) ? (float)$event['latitude'] : null;
            $lon = isset($event['longitude']) && is_numeric($event['longitude']) ? (float)$event['longitude'] : null;
            $payload = json_decode((string)($event['payload_json'] ?? ''), true);
            $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : array();
            $metadata = is_array($evidence['metadata'] ?? null) ? $evidence['metadata'] : array();
            $deviceAirport = strtoupper(trim((string)($metadata['airport_identifier'] ?? '')));
            $near = $deviceAirport !== ''
                ? array('icao' => $deviceAirport, 'nm' => 0.0)
                : (($lat !== null && $lon !== null) ? $this->nearestAirport($lat, $lon) : null);
            $cycles[] = array(
                'landing_utc' => (string)$event['timestamp_utc'],
                'airport' => $near['icao'] ?? null,
                'distance_nm' => $near['nm'] ?? null,
                'latitude' => $lat,
                'longitude' => $lon,
            );
        }
        return $cycles;
    }

    /**
     * @param list<array<string,mixed>> $planned
     * @param list<array<string,mixed>> $stops
     * @param list<array<string,mixed>> $cycles
     * @return list<string>
     */
    private function proposeRouteAirports(
        string $departure,
        string $arrival,
        array $planned,
        array $stops,
        array $cycles,
        array $csvEndpoints = array()
    ): array {
        $observedDeparture = strtoupper(trim((string)($csvEndpoints['departure'] ?? '')));
        $observedArrival = strtoupper(trim((string)($csvEndpoints['arrival'] ?? '')));
        $chain = array($observedDeparture !== '' ? $observedDeparture : $departure);
        $observations = array();
        foreach ($cycles as $cycle) {
            $airport = strtoupper(trim((string)($cycle['airport'] ?? '')));
            if ($airport !== '') {
                $observations[] = array(
                    'airport' => $airport,
                    'time' => strtotime((string)($cycle['landing_utc'] ?? '')) ?: 0,
                );
            }
        }
        foreach ($stops as $stop) {
            $airport = strtoupper(trim((string)($stop['airport'] ?? '')));
            if ($airport !== '') {
                $observations[] = array(
                    'airport' => $airport,
                    'time' => strtotime((string)($stop['start_utc'] ?? '')) ?: 0,
                );
            }
        }
        usort($observations, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
        foreach ($observations as $observation) {
            $chain[] = $observation['airport'];
        }
        $chain[] = $observedArrival !== ''
            ? $observedArrival
            : ($arrival !== '' ? $arrival : ($observedDeparture !== '' ? $observedDeparture : $departure));
        $chain = $this->uniqueAdjacentAirports($chain);
        if (($observedDeparture !== '' || $observedArrival !== '' || $observations !== array())
            && count($chain) >= 2) {
            return $chain;
        }

        if (count($planned) >= 1) {
            $plannedChain = array($planned[0]['departure']);
            foreach ($planned as $hop) {
                $plannedChain[] = $hop['destination'];
            }
            return $this->uniqueAdjacentAirports($plannedChain);
        }
        return $chain;
    }

    /**
     * @param list<string> $airports
     * @return list<string>
     */
    private function uniqueAdjacentAirports(array $airports): array
    {
        $out = array();
        foreach ($airports as $airport) {
            $airport = strtoupper(trim($airport));
            if ($airport === '') {
                continue;
            }
            if ($out !== array() && $out[count($out) - 1] === $airport) {
                continue;
            }
            $out[] = $airport;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $context
     * @param list<string> $routeAirports
     * @param list<array<string,mixed>> $cycles
     * @param list<array<string,mixed>> $stops
     * @return list<array<string,mixed>>
     */
    private function buildProposedLegs(array $context, array $routeAirports, array $cycles, array $stops): array
    {
        $boundaries = array($context['off_block_utc']);
        for ($i = 1; $i < count($routeAirports) - 1; $i++) {
            $airport = $routeAirports[$i];
            $splitAt = null;
            foreach ($cycles as $cycle) {
                if (strtoupper((string)($cycle['airport'] ?? '')) === $airport) {
                    $splitAt = (string)$cycle['landing_utc'];
                    break;
                }
            }
            if ($splitAt === null) {
                foreach ($stops as $stop) {
                    if (strtoupper((string)($stop['airport'] ?? '')) === $airport) {
                        $splitAt = (string)$stop['start_utc'];
                        break;
                    }
                }
            }
            if ($splitAt === null) {
                $off = strtotime((string)$context['off_block_utc']);
                $on = strtotime((string)$context['on_block_utc']);
                $ratio = $i / (count($routeAirports) - 1);
                $splitAt = gmdate('Y-m-d H:i:s', (int)round($off + ($on - $off) * $ratio));
            }
            $boundaries[] = $splitAt;
        }
        $boundaries[] = $context['on_block_utc'];

        $offTs = strtotime((string)$context['off_block_utc']);
        $onTs = strtotime((string)$context['on_block_utc']);
        $total = max(1, $onTs - $offTs);
        $dHobbs = $context['ending_hobbs'] - $context['starting_hobbs'];
        $dTacho = $context['ending_tacho'] - $context['starting_tacho'];
        $opsByLeg = $this->operationCountsByLegWindow(
            (string)$context['flight_uuid'],
            $boundaries,
            (int)($context['verified_takeoff_count'] ?? 0),
            (int)($context['verified_landing_count'] ?? 0)
        );

        $fuelStart = $context['fuel_start'];
        $fuelEnd = $context['fuel_end'];
        $fuelBurn = ($fuelStart !== null && $fuelEnd !== null && $fuelStart >= $fuelEnd)
            ? ($fuelStart - $fuelEnd)
            : null;
        $totalHobbs = max(0.1, $dHobbs);

        $legs = array();
        $runningFuel = $fuelStart;
        for ($i = 0; $i < count($routeAirports) - 1; $i++) {
            $startTs = strtotime((string)$boundaries[$i]);
            $endTs = strtotime((string)$boundaries[$i + 1]);
            $startRatio = max(0.0, min(1.0, ($startTs - $offTs) / $total));
            $endRatio = max(0.0, min(1.0, ($endTs - $offTs) / $total));
            $startHobbs = $this->roundOne($context['starting_hobbs'] + $dHobbs * $startRatio);
            $endHobbs = $this->roundOne($context['starting_hobbs'] + $dHobbs * $endRatio);
            $startTacho = $this->roundOne($context['starting_tacho'] + $dTacho * $startRatio);
            $endTacho = $this->roundOne($context['starting_tacho'] + $dTacho * $endRatio);
            if ($i === 0) {
                $startHobbs = $context['starting_hobbs'];
                $startTacho = $context['starting_tacho'];
            }
            if ($i === count($routeAirports) - 2) {
                $endHobbs = $context['ending_hobbs'];
                $endTacho = $context['ending_tacho'];
            }
            if ($endHobbs < $startHobbs) {
                $endHobbs = $startHobbs;
            }
            if ($endTacho < $startTacho) {
                $endTacho = $startTacho;
            }
            $hobbsDelta = $this->roundOne($endHobbs - $startHobbs);
            $tachoDelta = $this->roundOne($endTacho - $startTacho);
            $ops = $opsByLeg[$i] ?? array('takeoffs' => 1, 'landings' => 1);

            $legFuelStart = $runningFuel;
            $legFuelEnd = null;
            $legFuelBurn = null;
            if ($fuelBurn !== null && $legFuelStart !== null) {
                if ($i === count($routeAirports) - 2) {
                    $legFuelEnd = $fuelEnd;
                } else {
                    $legFuelBurn = $this->roundOne($fuelBurn * ($hobbsDelta / $totalHobbs));
                    $legFuelEnd = $this->roundOne(max(0.0, $legFuelStart - $legFuelBurn));
                }
                $legFuelBurn = $this->roundOne($legFuelStart - (float)$legFuelEnd);
                $runningFuel = $legFuelEnd;
            }

            $legs[] = array(
                'leg_index' => $i + 1,
                'departure_airport' => $routeAirports[$i],
                'arrival_airport' => $routeAirports[$i + 1],
                'off_block_utc' => gmdate('Y-m-d H:i:s', $startTs),
                'on_block_utc' => gmdate('Y-m-d H:i:s', $endTs),
                'starting_hobbs' => $startHobbs,
                'ending_hobbs' => $endHobbs,
                'hobbs_delta' => $hobbsDelta,
                'starting_tacho' => $startTacho,
                'ending_tacho' => $endTacho,
                'tacho_delta' => $tachoDelta,
                'takeoff_count' => (int)$ops['takeoffs'],
                'landing_count' => (int)$ops['landings'],
                'fuel_onboard' => $legFuelStart,
                'fuel_remaining' => $legFuelEnd,
                'fuel_burn' => $legFuelBurn,
                'source' => count($context['planned_hops']) >= 2
                    ? 'planned_hops'
                    : ($cycles !== array() ? 'cvr_landing_cycles' : 'csv_ground_stops'),
            );
        }
        return $legs;
    }

    /**
     * @param list<string> $boundaries
     * @return list<array{takeoffs:int,landings:int}>
     */
    private function operationCountsByLegWindow(
        string $flightUuid,
        array $boundaries,
        int $verifiedTakeoffs,
        int $verifiedLandings
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT event_type, timestamp_utc
             FROM ipca_cvr_flight_events
             WHERE LOWER(workflow_flight_record_uuid) = ?
               AND event_type IN (
                 \'gps_takeoff_provisional\', \'gps_landing_provisional\',
                 \'manual_takeoff_adjustment\', \'manual_landing_adjustment\'
               )
             ORDER BY timestamp_utc ASC, id ASC'
        );
        $stmt->execute(array($flightUuid));
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $legCount = max(0, count($boundaries) - 1);
        $counts = array();
        for ($i = 0; $i < $legCount; $i++) {
            $counts[$i] = array('takeoffs' => 0, 'landings' => 0);
            $start = strtotime((string)$boundaries[$i]);
            $end = strtotime((string)$boundaries[$i + 1]);
            $includeEnd = $i === $legCount - 1;
            foreach ($events as $event) {
                $ts = strtotime((string)($event['timestamp_utc'] ?? ''));
                if ($ts === false) {
                    continue;
                }
                if ($ts < $start || ($includeEnd ? $ts > $end : $ts >= $end)) {
                    continue;
                }
                $type = (string)($event['event_type'] ?? '');
                if ($type === 'gps_takeoff_provisional' || $type === 'manual_takeoff_adjustment') {
                    $counts[$i]['takeoffs']++;
                }
                if ($type === 'gps_landing_provisional' || $type === 'manual_landing_adjustment') {
                    $counts[$i]['landings']++;
                }
            }
            if ($counts[$i]['takeoffs'] <= 0) {
                $counts[$i]['takeoffs'] = 1;
            }
            if ($counts[$i]['landings'] <= 0) {
                $counts[$i]['landings'] = 1;
            }
        }

        // If Check-In verified totals exist, scale/adjust so the split sums match.
        $sumTo = array_sum(array_column($counts, 'takeoffs'));
        $sumLdg = array_sum(array_column($counts, 'landings'));
        if ($verifiedTakeoffs > 0 && $sumTo > 0 && $sumTo !== $verifiedTakeoffs && $legCount > 0) {
            $counts = $this->redistributeCounts($counts, 'takeoffs', $verifiedTakeoffs);
        }
        if ($verifiedLandings > 0 && $sumLdg > 0 && $sumLdg !== $verifiedLandings && $legCount > 0) {
            $counts = $this->redistributeCounts($counts, 'landings', $verifiedLandings);
        }
        return $counts;
    }

    /**
     * @param list<array{takeoffs:int,landings:int}> $counts
     * @return list<array{takeoffs:int,landings:int}>
     */
    private function redistributeCounts(array $counts, string $key, int $targetTotal): array
    {
        $current = array_sum(array_map(static fn(array $row): int => (int)$row[$key], $counts));
        if ($current <= 0 || $targetTotal <= 0) {
            return $counts;
        }
        $allocated = 0;
        $last = count($counts) - 1;
        foreach ($counts as $i => $row) {
            if ($i === $last) {
                $counts[$i][$key] = max(0, $targetTotal - $allocated);
                break;
            }
            $share = (int)round(((int)$row[$key] / $current) * $targetTotal);
            $counts[$i][$key] = max(0, $share);
            $allocated += $counts[$i][$key];
        }
        return $counts;
    }

    /**
     * @param list<array<string,mixed>> $planned
     * @param list<array<string,mixed>> $stops
     * @param list<array<string,mixed>> $cycles
     * @param list<string> $route
     * @return list<string>
     */
    private function previewNotes(array $planned, array $stops, array $cycles, array $route): array
    {
        $notes = array();
        if (count($planned) >= 2) {
            $notes[] = 'Scheduled as ' . count($planned) . ' planned legs; suggestions align to that chain when possible.';
        } elseif (count($planned) === 1) {
            $notes[] = 'Scheduled as a single leg; intermediate stops come from flown evidence.';
        } else {
            $notes[] = 'No planned multi-leg identity found; suggestions come from CSV/GPS evidence.';
        }
        $csvAirports = array();
        foreach ($stops as $stop) {
            $airport = strtoupper(trim((string)($stop['airport'] ?? '')));
            if ($airport !== '') {
                $csvAirports[] = $airport;
            }
        }
        if ($csvAirports !== array()) {
            $notes[] = 'CSV ground stops detected at: ' . implode(', ', array_values(array_unique($csvAirports))) . '.';
        }
        if ($cycles !== array()) {
            $notes[] = 'CVR GPS recorded ' . count($cycles) . ' intermediate landing/takeoff cycle(s).';
        }
        $notes[] = 'Proposed route: ' . implode(' → ', $route) . '.';
        $notes[] = 'Hobbs/Tacho deltas and fuel burn are proportional to each leg; final meters and landing fuel stay locked to Check-In.';
        $notes[] = 'Takeoffs/landings are counted from CVR GPS cycles in each leg window (editable).';
        return $notes;
    }

    /**
     * @param list<mixed> $legsInput
     * @return list<array<string,mixed>>
     */
    private function normalizeLegInputs(array $legsInput): array
    {
        $legs = array();
        foreach ($legsInput as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dep = strtoupper(substr(trim((string)($row['departure_airport'] ?? $row['departure'] ?? '')), 0, 8));
            $arr = strtoupper(substr(trim((string)($row['arrival_airport'] ?? $row['arrival'] ?? $row['destination'] ?? '')), 0, 8));
            $startH = $this->decimal($row['starting_hobbs'] ?? null);
            $endH = $this->decimal($row['ending_hobbs'] ?? null);
            $startT = $this->decimal($row['starting_tacho'] ?? null);
            $endT = $this->decimal($row['ending_tacho'] ?? null);
            $off = trim((string)($row['off_block_utc'] ?? ''));
            if ($dep === '' || $arr === '' || $startH === null || $endH === null || $startT === null || $endT === null || $off === '') {
                throw new InvalidArgumentException('Each split leg needs airports, meters, and Off Block time.');
            }
            if ($endH < $startH || $endT < $startT) {
                throw new InvalidArgumentException('Leg ending meters cannot be below starting meters.');
            }
            $legs[] = array(
                'departure_airport' => $dep,
                'arrival_airport' => $arr,
                'starting_hobbs' => $startH,
                'ending_hobbs' => $endH,
                'starting_tacho' => $startT,
                'ending_tacho' => $endT,
                'off_block_utc' => $off,
                'takeoff_count' => max(0, (int)($row['takeoff_count'] ?? 1)),
                'landing_count' => max(0, (int)($row['landing_count'] ?? 1)),
                'fuel_onboard' => $this->fuelNumber($row['fuel_onboard'] ?? null),
                'fuel_remaining' => $this->fuelNumber($row['fuel_remaining'] ?? null),
            );
        }
        for ($i = 1; $i < count($legs); $i++) {
            if ($legs[$i]['departure_airport'] !== $legs[$i - 1]['arrival_airport']) {
                throw new InvalidArgumentException('Split legs must form a continuous airport chain.');
            }
            if (abs($legs[$i]['starting_hobbs'] - $legs[$i - 1]['ending_hobbs']) > 0.05
                || abs($legs[$i]['starting_tacho'] - $legs[$i - 1]['ending_tacho']) > 0.05) {
                throw new InvalidArgumentException('Adjacent legs must share continuous Hobbs/Tacho values.');
            }
        }
        return $legs;
    }

    /**
     * @param list<array<string,mixed>> $legs
     * @param array<string,mixed> $context
     */
    /**
     * @param list<array<string,mixed>> $segments
     */
    private function persistLegSegments(int $dispatchId, array $segments): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.current_version, v.id AS version_row_id, v.payload_json
             FROM ipca_cvr_dispatches d
             INNER JOIN ipca_cvr_dispatch_versions v
               ON v.dispatch_id = d.id AND v.dispatch_version = d.current_version
             WHERE d.id = ?
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(array($dispatchId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Dispatch version not found while saving leg segments.');
        }
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = array();
        }
        $first = $segments[0];
        $last = $segments[count($segments) - 1];
        $payload['planned_departure_airport'] = $first['departure_airport'];
        $payload['planned_destination_airport'] = $last['arrival_airport'];
        $payload['leg_segments'] = $segments;
        $payload['via_airports'] = self::viaAirportsFromSegments($segments);
        $payload['admin_leg_annotation'] = true;
        $update = $this->pdo->prepare(
            'UPDATE ipca_cvr_dispatch_versions SET payload_json = ? WHERE id = ?'
        );
        $update->execute(array(
            AuditEventService::jsonEncode($payload),
            (int)$row['version_row_id'],
        ));
    }

    private function derivedOnBlockUtc(string $offBlockUtc, float $startingHobbs, float $endingHobbs): ?string
    {
        return (new CvrOperationalBlockTimeService())->derivedOnBlockUtc(array(
            'off_block_utc' => $offBlockUtc,
            'starting_hobbs' => $startingHobbs,
            'ending_hobbs' => $endingHobbs,
        ));
    }

    /**
     * @param list<array<string,mixed>> $legs
     * @param array<string,mixed> $context
     */
    private function assertMeterChain(array $legs, array $context): void
    {
        $first = $legs[0];
        $last = $legs[count($legs) - 1];
        if (abs($first['starting_hobbs'] - $context['starting_hobbs']) > 0.05
            || abs($first['starting_tacho'] - $context['starting_tacho']) > 0.05) {
            throw new InvalidArgumentException('First leg must keep the original starting Hobbs/Tacho.');
        }
        if (abs($last['ending_hobbs'] - $context['ending_hobbs']) > 0.05
            || abs($last['ending_tacho'] - $context['ending_tacho']) > 0.05) {
            throw new InvalidArgumentException('Final leg must keep the original ending Hobbs/Tacho from Check-In.');
        }
    }

    private function countSiblingDispatches(string $schedulerRecordId, string $flightUuid): int
    {
        if ($schedulerRecordId === '') {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_cvr_dispatches
             WHERE LOWER(TRIM(scheduler_record_id)) = ?
               AND LOWER(TRIM(workflow_flight_record_uuid)) <> ?'
        );
        $stmt->execute(array($schedulerRecordId, $flightUuid));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @deprecated Physical child-dispatch splits are no longer used; kept for legacy cleanup detection.
     */
    private function countSplitChildren(string $parentFlightUuid): int
    {
        if ($parentFlightUuid === '') {
            return 0;
        }
        $needle = '"split_from_workflow_flight_record_uuid":"' . $parentFlightUuid . '"';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM ipca_cvr_dispatch_versions v
             INNER JOIN ipca_cvr_dispatches d
               ON d.id = v.dispatch_id AND v.dispatch_version = d.current_version
             WHERE v.payload_json LIKE ?'
        );
        $stmt->execute(array('%' . $needle . '%'));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $context
     * @param list<array<string,mixed>> $created
     */
    private function syncIdentityLegs(array $context, array $created): void
    {
        $reservationUuid = $context['reservation_uuid'];
        if ($reservationUuid === '') {
            return;
        }
        try {
            $identity = new CvrOperationalIdentityService($this->pdo);
            $orgId = $context['organization_id'];
            $existing = $identity->listLegsForReservation($reservationUuid);
            $byHop = array();
            foreach ($existing as $leg) {
                if (!is_array($leg)) {
                    continue;
                }
                $key = strtoupper(trim((string)($leg['origin_airport'] ?? ''))) . '>'
                    . strtoupper(trim((string)($leg['destination_airport'] ?? '')));
                $byHop[$key] = $leg;
            }

            foreach ($created as $index => $child) {
                $key = $child['departure_airport'] . '>' . $child['arrival_airport'];
                $leg = $byHop[$key] ?? null;
                if (!is_array($leg)) {
                    $leg = $identity->createFlightLeg(array(
                        'organization_id' => $orgId,
                        'reservation_uuid' => $reservationUuid,
                        'sequence_number' => $index + 1,
                        'origin_airport' => $child['departure_airport'],
                        'destination_airport' => $child['arrival_airport'],
                        'status' => 'checked_in',
                        'source' => 'manual',
                        'organization_timezone_iana' => $context['timezone'],
                    ), false);
                } else {
                    $this->pdo->prepare(
                        'UPDATE ipca_operational_reservation_legs SET status = ? WHERE leg_uuid = ?'
                    )->execute(array('checked_in', $leg['leg_uuid']));
                }
            }
            $identity->refreshReservationStatusFromLegs($reservationUuid);
        } catch (Throwable) {
            // Identity sync is best-effort relative to dispatch/closure writes.
        }
    }

    /**
     * @return array{icao:string,nm:float,name:string}|null
     */
    private function nearestAirport(float $lat, float $lon, float $maxNm = self::AIRPORT_RADIUS_NM): ?array
    {
        $best = null;
        $airports = array();
        try {
            $latitudeDelta = $maxNm / 60.0;
            $longitudeDelta = $maxNm / max(10.0, 60.0 * cos(deg2rad($lat)));
            $statement = $this->pdo->prepare(
                'SELECT icao_identifier, full_name, latitude_deg, longitude_deg
                 FROM ipca_airports
                 WHERE latitude_deg BETWEEN ? AND ?
                   AND longitude_deg BETWEEN ? AND ?
                 LIMIT 100'
            );
            $statement->execute(array(
                $lat - $latitudeDelta,
                $lat + $latitudeDelta,
                $lon - $longitudeDelta,
                $lon + $longitudeDelta,
            ));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
                $identifier = strtoupper(trim((string)($row['icao_identifier'] ?? '')));
                if ($identifier !== '') {
                    $airports[$identifier] = array(
                        'lat' => (float)$row['latitude_deg'],
                        'lon' => (float)$row['longitude_deg'],
                        'name' => (string)($row['full_name'] ?? $identifier),
                    );
                }
            }
        } catch (Throwable) {
            $airports = array();
        }
        if ($airports === array()) {
            $airports = tv_adsb_airports();
        }
        foreach ($airports as $icao => $airport) {
            if (!isset($airport['lat'], $airport['lon'])) {
                continue;
            }
            $dlat = deg2rad($lat - (float)$airport['lat']);
            $dlon = deg2rad($lon - (float)$airport['lon']);
            $a = sin($dlat / 2) ** 2
                + cos(deg2rad($lat)) * cos(deg2rad((float)$airport['lat'])) * sin($dlon / 2) ** 2;
            $nm = 3440.065 * 2 * asin(min(1, sqrt($a)));
            if ($nm > $maxNm) {
                continue;
            }
            if ($best === null || $nm < (float)$best['nm']) {
                $best = array(
                    'icao' => (string)$icao,
                    'nm' => round($nm, 2),
                    'name' => (string)($airport['name'] ?? $icao),
                );
            }
        }
        return $best;
    }

    /**
     * @param array<string,mixed> $closure
     * @return array{takeoffs:int,landings:int}
     */
    private function closureOperationCounts(array $closure, string $flightUuid): array
    {
        $payload = json_decode((string)($closure['payload_json'] ?? '{}'), true);
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : array();
        $takeoffs = $evidence['verified_takeoff_count'] ?? $payload['verified_takeoff_count'] ?? null;
        $landings = $evidence['verified_landing_count'] ?? $payload['verified_landing_count'] ?? null;
        if ($takeoffs === null || $landings === null) {
            $stmt = $this->pdo->prepare(
                "SELECT
                   SUM(CASE WHEN event_type IN ('gps_takeoff_provisional', 'manual_takeoff_adjustment') THEN 1 ELSE 0 END) AS takeoffs,
                   SUM(CASE WHEN event_type IN ('gps_landing_provisional', 'manual_landing_adjustment') THEN 1 ELSE 0 END) AS landings
                 FROM ipca_cvr_flight_events
                 WHERE LOWER(workflow_flight_record_uuid) = ?"
            );
            $stmt->execute(array(strtolower($flightUuid)));
            $events = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
            $takeoffs ??= $events['takeoffs'] ?? 0;
            $landings ??= $events['landings'] ?? 0;
        }
        return array(
            'takeoffs' => max(0, (int)$takeoffs),
            'landings' => max(0, (int)$landings),
        );
    }

    private function fuelNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float)$value, 1);
        }
        $text = trim((string)$value);
        if (preg_match('/(-?\d+(?:\.\d+)?)/', $text, $m) === 1) {
            return round((float)$m[1], 1);
        }
        return null;
    }

    private function eventTimestamp(string $flightUuid, string $eventType): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT timestamp_utc FROM ipca_cvr_flight_events
             WHERE LOWER(workflow_flight_record_uuid) = ? AND event_type = ?
             ORDER BY timestamp_utc ASC, id ASC LIMIT 1'
        );
        $stmt->execute(array($flightUuid, $eventType));
        $value = trim((string)($stmt->fetchColumn() ?: ''));
        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $closure
     */
    private function closureTimestamp(array $closure, string $key): ?string
    {
        $payload = json_decode((string)($closure['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            return null;
        }
        $value = trim((string)($payload[$key] ?? ($payload['evidence'][$key] ?? '')));
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private function utcToLocal(string $utc, string $timezone): string
    {
        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return '';
        }
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return round((float)$value, 1);
    }

    private function roundOne(float $value): float
    {
        return round($value, 1);
    }
}
