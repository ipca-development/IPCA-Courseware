<?php
declare(strict_types=1);

function ml_fixture_value(mixed $raw, mixed $resolved, string $source, float $confidence, string $verificationState, array $conflicts = array()): array
{
    return array(
        'raw_source_value' => $raw,
        'resolved_value' => $resolved,
        'resolution_source' => $source,
        'confidence' => $confidence,
        'verification_state' => $verificationState,
        'conflict_details' => $conflicts,
    );
}

function ml_fixture_empty(mixed $raw = null, string $state = 'unresolved'): array
{
    return ml_fixture_value($raw, null, 'not_resolved', 0.0, $state);
}

function ml_fixture_evidence(string $state, int $count = 0, ?string $sourceKey = null, ?string $sourceStatus = null, float $confidence = 0.0, array $diagnostics = array()): array
{
    return array(
        'state' => $state,
        'source_count' => $count,
        'primary_source_key' => $sourceKey,
        'source_status' => $sourceStatus,
        'confidence' => $confidence,
        'diagnostics' => $diagnostics,
    );
}

function ml_fixture_transcript(string $state, bool $raw, bool $enhanced, string $processingStatus, array $diagnostics = array()): array
{
    return array(
        'state' => $state,
        'raw_available' => $raw,
        'enhanced_available' => $enhanced,
        'processing_status' => $processingStatus,
        'diagnostics' => $diagnostics,
    );
}

function ml_current_leg(int $recordId, int $versionId, int $legIndex, string $date, string $aircraft, string $departure, string $arrival, string $finalization = 'draft', array $extra = array()): array
{
    $eventKey = 'current-event:ofr:' . $recordId;
    $legKey = 'current-leg:ofrv:' . $versionId . ':leg:' . $legIndex;
    return array_replace_recursive(array(
        'event_key' => $eventKey,
        'leg_key' => $legKey,
        'source_mode' => 'current_operational',
        'source_branch' => 'current_operational',
        'source_record_keys' => array(
            'operational_flight_record' => 'ofr:' . $recordId,
            'flight_record_version' => 'ofrv:' . $versionId,
            'session' => 'session:' . $recordId,
        ),
        'association_keys' => array('ofr:' . $recordId, 'session:' . $recordId),
        'dedupe_keys' => array($legKey),
        'anchor_rank' => $finalization === 'finalized' ? 100 : 90,
        'leg_structure_type' => 'confirmed_leg',
        'leg_structure_status' => 'confirmed',
        'date' => substr($date, 0, 10),
        'date_sort' => $date,
        'aircraft' => ml_fixture_value($aircraft, $aircraft, 'ipca_flight_sessions.aircraft_registration', 1.0, 'system'),
        'pilot_1' => ml_fixture_value('Student ' . $recordId, 'Student ' . $recordId, 'ipca_flight_crew_member_versions', 0.8, 'needs_review'),
        'pilot_1_role' => ml_fixture_value('student', null, 'ipca_flight_crew_member_versions.logging_functions_json', 0.4, 'unresolved'),
        'pilot_2' => ml_fixture_value('Instructor ' . $recordId, 'Instructor ' . $recordId, 'ipca_flight_crew_member_versions', 0.8, 'needs_review'),
        'pilot_2_role' => ml_fixture_value('instructor', null, 'ipca_flight_crew_member_versions.logging_functions_json', 0.4, 'unresolved'),
        'departure_local_time' => ml_fixture_value($date, $date, 'ipca_operational_flight_leg_versions.allocation_start_utc', 0.9, 'system'),
        'departure_airport' => ml_fixture_value($departure, $departure, 'ipca_operational_flight_leg_versions.departure_airport_code', 0.85, 'needs_review'),
        'departure_hobbs' => ml_fixture_value('100.0', '100.0', 'ipca_operational_flight_record_versions.hobbs_start_hours', 0.9, 'system'),
        'departure_tacho' => ml_fixture_value('50.0', '50.0', 'ipca_operational_flight_record_versions.tacho_start_hours', 0.9, 'system'),
        'hobbs_duration' => ml_fixture_value(3600000, 1.0, 'ipca_operational_flight_leg_versions.allocated_hobbs_duration_ms', 0.9, 'system'),
        'tacho_duration' => ml_fixture_value(3300000, 0.917, 'ipca_operational_flight_leg_versions.allocated_tacho_duration_ms', 0.9, 'system'),
        'arrival_airport' => ml_fixture_value($arrival, $arrival, 'ipca_operational_flight_leg_versions.arrival_airport_code', 0.85, 'needs_review'),
        'arrival_local_time' => ml_fixture_value(substr($date, 0, 11) . '11:00:00', substr($date, 0, 11) . '11:00:00', 'ipca_operational_flight_leg_versions.allocation_end_utc', 0.9, 'system'),
        'arrival_hobbs' => ml_fixture_value('101.0', '101.0', 'ipca_operational_flight_record_versions.hobbs_end_hours', 0.9, 'system'),
        'arrival_tacho' => ml_fixture_value('50.9', '50.9', 'ipca_operational_flight_record_versions.tacho_end_hours', 0.9, 'system'),
        'landings' => ml_fixture_value(1, 1, 'ipca_operational_flight_leg_versions.landing_event_count', 0.75, 'needs_review'),
        'fuel_out' => ml_fixture_value('42.0', '42.0', 'ipca_operational_flight_leg_versions.fuel_start_usg', 0.7, 'needs_review'),
        'fuel_in' => ml_fixture_value('31.0', '31.0', 'ipca_operational_flight_leg_versions.fuel_end_usg', 0.7, 'needs_review'),
        'mission' => ml_fixture_value('Mission text', null, 'ipca_flight_mission_assignments', 0.5, 'unresolved'),
        'processing_status' => 'ready',
        'verification_status' => 'needs_review',
        'finalization_status' => $finalization,
        'conflict_status' => 'none',
        'evidence_completeness_status' => 'partial',
    ), $extra);
}

function ml_historical_dispatch(int $operationId, string $date, string $aircraft, string $type = 'aggregate_dispatch', array $extra = array()): array
{
    return array_replace_recursive(array(
        'event_key' => 'historical-event:ao:' . $operationId,
        'leg_key' => 'historical-leg:ao:' . $operationId . ':aggregate',
        'source_mode' => 'historical_flightcircle',
        'source_branch' => 'historical_flightcircle',
        'source_record_keys' => array('aircraft_operation' => 'ao:' . $operationId, 'flightcircle_staging_record' => 'fcs:' . $operationId),
        'association_keys' => array('ao:' . $operationId, 'fcs:' . $operationId),
        'dedupe_keys' => array('historical-leg:ao:' . $operationId . ':aggregate'),
        'anchor_rank' => 80,
        'leg_structure_type' => $type,
        'leg_structure_status' => $type === 'unresolved_leg_structure' ? 'unresolved' : 'aggregate',
        'date' => substr($date, 0, 10),
        'date_sort' => $date,
        'aircraft' => ml_fixture_value($aircraft, $aircraft, 'ipca_aircraft_operations.aircraft_registration', 0.8, 'needs_review'),
        'pilot_1' => ml_fixture_value('FlightCircle User ' . $operationId, null, 'ipca_aircraft_operations.user_text', 0.3, 'unresolved'),
        'pilot_1_role' => ml_fixture_value('User', null, 'ipca_aircraft_operations.user_text', 0.0, 'unresolved'),
        'pilot_2' => ml_fixture_value('FlightCircle Instructor ' . $operationId, null, 'ipca_aircraft_operations.instructor_text', 0.3, 'unresolved'),
        'pilot_2_role' => ml_fixture_value('Instructor', null, 'ipca_aircraft_operations.instructor_text', 0.0, 'unresolved'),
        'departure_local_time' => ml_fixture_value($date, $date, 'ipca_aircraft_operations.scheduled_start_local', 0.5, 'needs_review'),
        'departure_airport' => ml_fixture_value('TRM-LOCAL', null, 'ipca_flightcircle_staging_records.route_text', 0.2, 'unresolved'),
        'departure_hobbs' => ml_fixture_value('200.0', '200.0', 'ipca_meter_readings.reading_out', 0.75, 'needs_review'),
        'departure_tacho' => ml_fixture_value('140.0', '140.0', 'ipca_meter_readings.reading_out', 0.75, 'needs_review'),
        'hobbs_duration' => ml_fixture_value('1.2', '1.2', 'ipca_meter_readings.reading_delta', 0.75, 'needs_review'),
        'tacho_duration' => ml_fixture_value('1.0', '1.0', 'ipca_meter_readings.reading_delta', 0.75, 'needs_review'),
        'arrival_airport' => ml_fixture_value('TRM-LOCAL', null, 'ipca_flightcircle_staging_records.route_text', 0.2, 'unresolved'),
        'arrival_local_time' => ml_fixture_value(substr($date, 0, 11) . '12:12:00', substr($date, 0, 11) . '12:12:00', 'ipca_aircraft_operations.scheduled_end_local', 0.5, 'needs_review'),
        'arrival_hobbs' => ml_fixture_value('201.2', '201.2', 'ipca_meter_readings.reading_in', 0.75, 'needs_review'),
        'arrival_tacho' => ml_fixture_value('141.0', '141.0', 'ipca_meter_readings.reading_in', 0.75, 'needs_review'),
        'landings' => ml_fixture_value(null, null, 'not_resolved', 0.0, 'unresolved'),
        'fuel_out' => ml_fixture_value('30.0', '30.0', 'ipca_fuel_transactions', 0.5, 'needs_review'),
        'fuel_in' => ml_fixture_value(null, null, 'ipca_fuel_transactions', 0.0, 'unresolved'),
        'mission' => ml_fixture_value('FC note with M1 maybe', null, 'ipca_aircraft_operations.mission_notes', 0.1, 'unresolved'),
        'processing_status' => 'ready',
        'verification_status' => 'needs_review',
        'finalization_status' => 'proposed',
        'conflict_status' => 'none',
        'evidence_completeness_status' => 'partial',
    ), $extra);
}

return array(
    'scenario_labels' => array(
        'current_garmin_with_cvr',
        'current_multi_leg',
        'flightcircle_only_historical',
        'flightcircle_enriched_multiple_garmin_legs',
        'unmatched_garmin',
        'simulator_session',
        'non_flight_resource',
        'duplicate_garmin_source',
        'ambiguous_unlinked_association',
        'recording_with_replay_transcript_adsb',
        'cvr_no_usable_fdm',
        'fdm_no_cvr',
        'stale_failed_replay',
        'finalized_official_logbook',
        'unaccepted_logbook_proposal',
        'flightcircle_total_hobbs_unresolved_leg_structure',
        'conflicting_meter_values',
        'unresolved_crew_roles',
    ),
    'branches' => array(
        'current_operational' => array(
            ml_current_leg(101, 1001, 1, '2026-07-01 10:00:00', 'N397EA', 'KTRM', 'KTRM', 'finalized', array(
                'verification_status' => 'verified',
                'source_record_keys' => array('operational_flight_record' => 'ofr:101', 'official_logbook_entry' => 'logbook_entry:9001'),
            )),
            ml_current_leg(102, 1002, 1, '2026-07-02 09:00:00', 'N482EA', 'KTRM', 'KPSP'),
            ml_current_leg(102, 1002, 2, '2026-07-02 10:10:00', 'N482EA', 'KPSP', 'KTRM'),
            ml_current_leg(103, 1003, 1, '2026-07-03 09:00:00', 'N397EA', 'KTRM', 'KTRM'),
            ml_current_leg(104, 1004, 1, '2026-07-04 09:00:00', 'N482EA', 'KTRM', 'KTRM'),
            ml_current_leg(105, 1005, 1, '2026-07-05 09:00:00', 'N397EA', 'KTRM', 'KTRM'),
            ml_current_leg(106, 1006, 1, '2026-07-06 09:00:00', 'N397EA', 'KTRM', 'KTRM', 'proposed'),
            ml_current_leg(107, 1007, 1, '2026-07-07 09:00:00', 'N482EA', 'KTRM', 'KTRM'),
        ),
        'historical_flightcircle' => array(
            ml_historical_dispatch(301, '2025-01-01 09:00:00', 'N397EA'),
            ml_historical_dispatch(302, '2025-01-02 09:00:00', 'N482EA'),
            ml_historical_dispatch(303, '2025-01-03 09:00:00', 'N397EA'),
            ml_historical_dispatch(304, '2025-01-04 09:00:00', 'N482EA', 'unresolved_leg_structure'),
            ml_historical_dispatch(307, '2025-01-07 09:00:00', 'N397EA', 'aggregate_dispatch', array(
                'conflict_status' => 'warning',
                'departure_hobbs' => ml_fixture_value(array('flightcircle' => '350.0', 'garmin' => '350.4'), '350.0', 'ipca_meter_readings.reading_out', 0.45, 'needs_review', array('meter_delta_exceeds_tolerance')),
            )),
            ml_historical_dispatch(308, '2025-01-08 09:00:00', 'N482EA', 'aggregate_dispatch', array(
                'pilot_1' => ml_fixture_value('J Smith', null, 'ipca_aircraft_operations.user_text', 0.1, 'unresolved'),
                'pilot_2' => ml_fixture_value('C Doe', null, 'ipca_aircraft_operations.instructor_text', 0.1, 'unresolved'),
            )),
        ),
        'historical_garmin_legs' => array(
            ml_current_leg(0, 0, 1, '2025-01-02 09:12:00', 'N482EA', 'KTRM', 'KPSP', 'draft', array(
                'event_key' => 'historical-event:ao:302',
                'leg_key' => 'historical-garmin-leg:ao:302:ghs:501',
                'source_mode' => 'historical_flightcircle',
                'source_branch' => 'historical_garmin_legs',
                'source_record_keys' => array('aircraft_operation' => 'ao:302', 'garmin_historical_segment' => 'ghs:501', 'csv_file' => 'csv:501'),
                'association_keys' => array('ao:302', 'fcs:302', 'ghs:501', 'csv:501'),
                'dedupe_keys' => array('historical-garmin-leg:ao:302:ghs:501'),
                'anchor_rank' => 85,
                'finalization_status' => 'proposed',
            )),
            ml_current_leg(0, 0, 2, '2025-01-02 10:20:00', 'N482EA', 'KPSP', 'KTRM', 'draft', array(
                'event_key' => 'historical-event:ao:302',
                'leg_key' => 'historical-garmin-leg:ao:302:ghs:502',
                'source_mode' => 'historical_flightcircle',
                'source_branch' => 'historical_garmin_legs',
                'source_record_keys' => array('aircraft_operation' => 'ao:302', 'garmin_historical_segment' => 'ghs:502', 'csv_file' => 'csv:502'),
                'association_keys' => array('ao:302', 'fcs:302', 'ghs:502', 'csv:502'),
                'dedupe_keys' => array('historical-garmin-leg:ao:302:ghs:502'),
                'anchor_rank' => 85,
                'finalization_status' => 'proposed',
            )),
        ),
        'unresolved_garmin' => array(
            ml_current_leg(0, 0, 1, '2025-01-05 09:00:00', 'N397EA', 'KTRM', 'KTRM', 'draft', array(
                'event_key' => 'unresolved-garmin:csv:401',
                'leg_key' => 'unresolved-garmin-leg:csv:401:summary',
                'source_mode' => 'unresolved_garmin',
                'source_branch' => 'unresolved_garmin',
                'source_record_keys' => array('csv_file' => 'csv:401'),
                'association_keys' => array('unlinked:csv:401'),
                'dedupe_keys' => array('unlinked:csv:401'),
                'leg_structure_type' => 'inferred_leg',
                'leg_structure_status' => 'inferred',
                'verification_status' => 'unreviewed',
            )),
            ml_current_leg(0, 0, 1, '2025-01-03 09:03:00', 'N397EA', 'KTRM', 'KTRM', 'draft', array(
                'event_key' => 'unresolved-garmin:csv:402',
                'leg_key' => 'unresolved-garmin-leg:csv:402:summary',
                'source_mode' => 'unresolved_garmin',
                'source_branch' => 'unresolved_garmin',
                'source_record_keys' => array('csv_file' => 'csv:402'),
                'association_keys' => array('ambiguous:ao:303', 'csv:402'),
                'dedupe_keys' => array('ambiguous:ao:303:csv:402'),
                'leg_structure_type' => 'inferred_leg',
                'leg_structure_status' => 'inferred',
                'conflict_status' => 'warning',
            )),
            ml_current_leg(101, 1001, 1, '2026-07-01 10:00:00', 'N397EA', 'KTRM', 'KTRM', 'draft', array(
                'event_key' => 'unresolved-garmin:csv:101',
                'leg_key' => 'unresolved-garmin-leg:csv:101:summary',
                'source_mode' => 'unresolved_garmin',
                'source_branch' => 'unresolved_garmin',
                'source_record_keys' => array('csv_file' => 'csv:101', 'associated_current_record' => 'ofr:101'),
                'association_keys' => array('ofr:101', 'session:101', 'csv:101'),
                'dedupe_keys' => array('current-leg:ofrv:1001:leg:1'),
                'anchor_rank' => 10,
            )),
            ml_current_leg(107, 1007, 1, '2026-07-07 09:00:00', 'N482EA', 'KTRM', 'KTRM', 'draft', array(
                'event_key' => 'unresolved-garmin:csv:407',
                'leg_key' => 'unresolved-garmin-leg:csv:407:summary',
                'source_mode' => 'unresolved_garmin',
                'source_branch' => 'unresolved_garmin',
                'source_record_keys' => array('csv_file' => 'csv:407', 'superseded_by' => 'csv:107'),
                'association_keys' => array('ofr:107', 'session:107', 'csv:407'),
                'dedupe_keys' => array('current-leg:ofrv:1007:leg:1'),
                'anchor_rank' => 10,
            )),
        ),
        'simulator' => array(
            ml_historical_dispatch(601, '2025-01-06 09:00:00', 'AL172M2', 'simulator_session', array(
                'event_key' => 'simulator-event:fcs:601',
                'leg_key' => 'simulator-leg:fcs:601:session',
                'source_mode' => 'simulator',
                'source_branch' => 'simulator',
                'source_record_keys' => array('flightcircle_staging_record' => 'fcs:601'),
                'association_keys' => array('fcs:601'),
                'dedupe_keys' => array('simulator-leg:fcs:601:session'),
                'leg_structure_status' => 'not_applicable',
            )),
        ),
        'non_flight' => array(
            ml_historical_dispatch(701, '2025-01-07 13:00:00', 'CLASSROOM I', 'non_flight_operation', array(
                'event_key' => 'nonflight-event:fcs:701',
                'leg_key' => 'nonflight-leg:fcs:701:operation',
                'source_mode' => 'non_flight',
                'source_branch' => 'non_flight',
                'source_record_keys' => array('flightcircle_staging_record' => 'fcs:701'),
                'association_keys' => array('fcs:701'),
                'dedupe_keys' => array('nonflight-leg:fcs:701:operation'),
                'leg_structure_status' => 'not_applicable',
            )),
        ),
        'orphan_recording' => array(
            ml_current_leg(0, 0, 1, '2026-07-08 09:00:00', 'N397EA', 'KTRM', 'KTRM', 'draft', array(
                'event_key' => 'orphan-recording:rec:801',
                'leg_key' => 'orphan-recording-leg:rec:801:recording',
                'source_mode' => 'orphan_recording',
                'source_branch' => 'orphan_recording',
                'source_record_keys' => array('cockpit_recording' => 'rec:801'),
                'association_keys' => array('unlinked:rec:801'),
                'dedupe_keys' => array('unlinked:rec:801'),
                'leg_structure_type' => 'unresolved_leg_structure',
                'leg_structure_status' => 'unresolved',
            )),
        ),
    ),
    'evidence' => array(
        'current-event:ofr:101' => array(
            'fdm' => ml_fixture_evidence('usable', 1, 'csv:101', 'validated', 0.95),
            'cvr' => ml_fixture_evidence('usable', 1, 'rec:101', 'uploaded', 0.95, array('audio_duration_seconds' => 3600)),
            'adsb' => ml_fixture_evidence('usable', 1, 'adsb:101', 'complete', 0.9, array('sample_count' => 2000)),
            'replay' => ml_fixture_evidence('usable', 1, 'rec:101', 'ready', 0.95, array('sample_count' => 12000)) + array('replay_type' => 'cockpit_recording', 'launch_url' => '/admin/cockpit_recorder_replay.php?id=rec-101'),
            'transcript' => ml_fixture_transcript('usable', true, false, 'ready'),
            'official_logbook' => ml_fixture_evidence('present', 1, 'logbook_entry:9001', 'accepted', 1.0),
        ),
        'current-event:ofr:102' => array(
            'fdm' => ml_fixture_evidence('usable', 1, 'csv:102', 'validated', 0.95),
            'cvr' => ml_fixture_evidence('not_available'),
            'adsb' => ml_fixture_evidence('not_available'),
            'replay' => ml_fixture_evidence('usable', 1, 'standalone:102', 'ready', 0.9) + array('replay_type' => 'standalone_garmin', 'launch_url' => '/admin/cockpit_recorder_replay.php?standalone=garmin-102'),
        ),
        'current-event:ofr:103' => array(
            'fdm' => ml_fixture_evidence('not_available', 0, null, 'missing'),
            'cvr' => ml_fixture_evidence('usable', 1, 'rec:103', 'uploaded', 0.95),
            'replay' => ml_fixture_evidence('not_available'),
        ),
        'current-event:ofr:104' => array(
            'fdm' => ml_fixture_evidence('usable', 1, 'csv:104', 'validated', 0.95),
            'cvr' => ml_fixture_evidence('not_available'),
            'replay' => ml_fixture_evidence('usable', 1, 'standalone:104', 'ready', 0.9) + array('replay_type' => 'standalone_garmin', 'launch_url' => '/admin/cockpit_recorder_replay.php?standalone=garmin-104'),
        ),
        'current-event:ofr:105' => array(
            'fdm' => ml_fixture_evidence('usable', 1, 'csv:105', 'validated', 0.95),
            'cvr' => ml_fixture_evidence('present', 1, 'rec:105', 'uploaded', 0.7),
            'replay' => ml_fixture_evidence('stale', 1, 'standalone:105', 'failed', 0.0, array('last_error' => 'builder_version_stale')) + array('replay_type' => 'standalone_garmin', 'launch_url' => null),
        ),
        'current-event:ofr:106' => array(
            'fdm' => ml_fixture_evidence('usable', 1, 'csv:106', 'validated', 0.95),
            'proposal' => ml_fixture_evidence('present', 1, 'proposal:106', 'open', 0.8),
        ),
        'current-event:ofr:107' => array(
            'fdm' => ml_fixture_evidence('usable', 2, 'csv:107', 'validated', 0.95, array('suppressed_superseded_source' => 'csv:407')),
        ),
        'historical-event:ao:301' => array('flightcircle' => ml_fixture_evidence('present', 1, 'ao:301', 'needs_review', 0.75)),
        'historical-event:ao:302' => array(
            'flightcircle' => ml_fixture_evidence('present', 1, 'ao:302', 'needs_review', 0.75),
            'fdm' => ml_fixture_evidence('present', 2, 'ghs:501', 'matched', 0.85),
        ),
        'historical-event:ao:303' => array('flightcircle' => ml_fixture_evidence('present', 1, 'ao:303', 'ambiguous_match', 0.5)),
        'historical-event:ao:304' => array('flightcircle' => ml_fixture_evidence('present', 1, 'ao:304', 'needs_review', 0.6)),
        'historical-event:ao:307' => array('flightcircle' => ml_fixture_evidence('present', 1, 'ao:307', 'meter_conflict', 0.4)),
        'historical-event:ao:308' => array('flightcircle' => ml_fixture_evidence('present', 1, 'ao:308', 'crew_unresolved', 0.4)),
        'unresolved-garmin:csv:401' => array('fdm' => ml_fixture_evidence('present', 1, 'csv:401', 'unmatched', 0.5)),
        'unresolved-garmin:csv:402' => array('fdm' => ml_fixture_evidence('present', 1, 'csv:402', 'ambiguous', 0.4)),
        'unresolved-garmin:csv:407' => array('fdm' => ml_fixture_evidence('superseded', 1, 'csv:407', 'superseded', 0.1)),
        'simulator-event:fcs:601' => array('flightcircle' => ml_fixture_evidence('present', 1, 'fcs:601', 'simulator', 0.8)),
        'nonflight-event:fcs:701' => array('flightcircle' => ml_fixture_evidence('present', 1, 'fcs:701', 'ignored_resource', 0.8)),
        'orphan-recording:rec:801' => array('cvr' => ml_fixture_evidence('present', 1, 'rec:801', 'uploaded_unmatched', 0.5)),
    ),
);
