# MasterLogbookReadService Design

Date: 2026-07-21

This document describes the first read-only implementation of `MasterLogbookReadService`. It does not introduce UI, routes, navigation, schema, matching, replay generation, transcription, ADS-B enrichment, verification writes, or source-record changes.

## Scope

Added files:

- `src/MasterLogbookReadService.php`
- `tests/master_logbook_read_service_contract_check.php`
- `tests/fixtures/master_logbook_read_model.php`
- `scripts/master_logbook_read_model_diagnostic.php`

The service is an orchestration/read layer. It returns normalized PHP arrays and keeps provenance, evidence quality, processing state, verification state, finalization state, conflict state, and leg-structure state separate.

## Read Identities

Accepted event-key formats:

- `current-event:ofr:<operational_flight_record_id>`
- `historical-event:ao:<aircraft_operation_id>`
- `simulator-event:fcs:<flightcircle_staging_record_id>`
- `nonflight-event:fcs:<flightcircle_staging_record_id>`
- `unresolved-garmin:csv:<csv_file_id>`
- `unresolved-garmin-segment:ghs:<garmin_historical_segment_id>`
- `orphan-recording:rec:<cockpit_recording_id>`

Accepted leg-key formats:

- `current-leg:ofrv:<flight_record_version_id>:leg:<leg_index>`
- `historical-leg:ao:<aircraft_operation_id>:aggregate`
- `historical-garmin-leg:ao:<aircraft_operation_id>:ghs:<garmin_historical_segment_id>`
- `historical-garmin-leg:ao:<aircraft_operation_id>:csv:<csv_file_id>`
- `unresolved-garmin-leg:csv:<csv_file_id>:summary`
- `simulator-leg:fcs:<flightcircle_staging_record_id>:session`
- `nonflight-leg:fcs:<flightcircle_staging_record_id>:operation`
- `orphan-recording-leg:rec:<cockpit_recording_id>:recording`

The parser uses fixed regular expressions. It never accepts browser-supplied table names.

The `csv` historical Garmin leg identity is used for the current live database because `ipca_flightcircle_garmin_matches` contains explicit operation-to-CSV associations while many rows do not populate `garmin_segment_id`.

## Deduplication

Deduplication uses explicit `dedupe_keys`, not aircraft/date strings, Hobbs strings, row order, crew names, or fuzzy matching. A shared event association does not collapse multiple legs. This is important because a current operational record or FlightCircle operation may legitimately contain multiple legs.

The service also suppresses a FlightCircle aggregate row when explicit Garmin segment or CSV legs exist under the same FlightCircle event. The explicit Garmin legs remain separate rows under one Training Event.

Suppressed duplicates are returned in diagnostics with the suppressed event, winner event, association keys, dedupe keys, and reason.

## Evidence States

Evidence is not positive merely because a row exists. The normalized states are:

- `usable`
- `present`
- `processing`
- `failed`
- `stale`
- `superseded`
- `incomplete`
- `not_available`
- `unresolved`

List rows preserve structured state for FDM, CVR, ADS-B, replay, FlightCircle, and transcript evidence. A stale or failed replay record does not include a launch action.

## Status Dimensions

Rows preserve separate dimensions:

- `processing_status`
- `verification_status`
- `finalization_status`
- `conflict_status`
- `leg_structure_status`
- `evidence_completeness_status`

Source values that may be inferred or uncertain are represented as provenance values with:

- `raw_source_value`
- `resolved_value`
- `resolution_source`
- `confidence`
- `verification_state`
- `conflict_details`

## Performance Contract

The service is designed around paginated list requests and batched evidence attachment. Fixture mode reports:

- query count
- query time
- schema/table-discovery time
- count-query time
- row-query time
- evidence-query count and time
- row-build time
- deduplication time
- evidence-batch time
- JSON serialization time from the diagnostic script
- candidate count by source branch
- suppressed duplicate count
- unresolved count

The implementation does not parse CSV files, read source evidence files, generate replay payloads, run matching, derive flight records, transcribe audio, enrich ADS-B, or write audit records during list or detail operations.

## Live Diagnostic Findings

The live database currently returns `current_operational: 0` because the operational/session model has not been populated:

- `ipca_flight_sessions`: 0 rows
- `ipca_operational_flight_records`: 0 rows
- `ipca_operational_flight_record_versions`: 0 rows
- `ipca_operational_flight_leg_versions`: 0 rows
- `ipca_flight_sessions.current_flight_record_id`: 0 populated rows
- `ipca_operational_flight_records.current_version_id`: 0 populated rows

That means the zero current branch is live data, not a restrictive status filter, broken aircraft/session join, missing current-version join, or populated `current_flight_record_id` adapter defect.

Live historical and evidence sources are populated:

- `ipca_aircraft_operations`: 1,438 rows
- `ipca_flightcircle_garmin_matches`: 501 rows, including 412 `high_confidence`
- `ipca_garmin_historical_segments`: 403 rows
- `ipca_garmin_csv_flight_summaries`: 473 rows
- `ipca_garmin_csv_replay_payloads`: 4 rows, all `ready`
- `ipca_cockpit_recordings`: 101 rows, including 94 `ready` transcripts
- `ipca_cockpit_replay_samples`: 11 distinct recordings
- `ipca_cockpit_adsb_enrichments`: 18 rows, including 5 `ready`
- `ipca_historical_logbook_proposals`: 3,414 rows

Normal live diagnostics now read FlightCircle aggregate rows plus explicit FlightCircle-to-Garmin CSV legs. Unresolved branches remain opt-in through `include_unresolved`.

## Known Gaps Before UI

- Transcript enhancement ownership remains unresolved and is not exposed as an action.
- Squawk and safety-report ownership remains unresolved; detail output reports those integrations as `not_traced`.
- Historical reconciliation and verification writes remain out of scope.
