# Master Logbook Current-State Architecture Audit

Date: 2026-07-21

Scope: audit-only review of current Garmin Sync Agent, Garmin CSV ingestion, FlightCircle historical import, Cockpit Recorder/CVR ingestion, transcript generation, ADS-B enrichment, Cockpit Replay, flight-record/logbook tables, and related admin interfaces.

Constraints followed: no application code, schema, route, service, worker, API, frontend, or database behavior was modified for this audit. This document is additive repository documentation.

Line citations refer to the repository state at the time of audit.

## Executive Findings

Confirmed:

- The repository already contains two partially overlapping operational models:
  - CVR/Garmin operational flight-record model: `ipca_flight_sessions`, `ipca_operational_flight_records`, `ipca_operational_flight_record_versions`, `ipca_operational_flight_leg_versions`, and related version tables in `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:171-724`.
  - FlightCircle migration ledger model: `ipca_aircraft_operations`, `ipca_meter_readings`, `ipca_crew_assignments`, `ipca_fuel_transactions`, and source-evidence links in `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:30-180`.
- The current Cockpit Replay is tightly coupled to `ipca_cockpit_recordings`, `ipca_cockpit_replay_samples`, G3X-derived replay samples, and player-side JavaScript in `public/admin/cockpit_recorder_replay.php`. It should remain untouched during the first Master Logbook redesign.
- Garmin Sync Agent ingestion is operationally separate from historical Garmin backfill. The macOS app downloads FlyGarmin logbook, source CSV, and track JSON artifacts; the server receives them through `/api/sync-agent/*` endpoints and stores immutable source evidence.
- FlightCircle import creates a source-neutral aircraft operation candidate, meter readings, fuel facts, crew assignments, and logbook proposals, but matching is currently Garmin-centric for selected rows. This conflicts with the desired historical ledger approach where FlightCircle should be the authoritative historical operational ledger and Garmin should attach as evidence.
- A true canonical "one row per flight leg grouped by training event" admin page does not yet exist. The closest existing pieces are the versioned operational flight record/leg tables and `public/admin/flight_records.php`, but that page is not a Master Logbook and does not unify FlightCircle, CVR, ADS-B, schedules, squawks, safety reports, and manual entries.
- Follow-up repository tracing confirmed that legacy `/api/recordings/*` upload/status/transcript endpoints do not enforce the newer CVR bearer-device authentication in the endpoint files themselves; the newer `/api/cvr/*` endpoints are the device-authenticated surface. Treat the legacy recording endpoints as frozen compatibility boundaries and do not redesign authentication as part of the initial Master Logbook work.
- Follow-up database tracing confirmed `ipca_historical_logbook_proposals` exists for FlightCircle/historical proposals, but no historical equivalent of `FlightRecordLogbookIntegrationService::acceptProposalToOfficialLogbook()` was found. Historical proposal acceptance into official `ipca_admin_logbook_entries` remains a required future bridge.
- Follow-up UI tracing identified weak/orphaned surfaces: `public/admin/cockpit_recorder_standalone_upload.php`, `public/admin/api/garmin_cloud_action.php`, `public/api/cvr/csv_upload_*.php`, `public/admin/cvr_transcript_test.php`, `public/admin/cvr_audio_preview.php`, and `public/admin/cockpit_recorder_replay.V1.0.php`. These should be documented/demoted, not deleted, until dependency checks and written approval.

Recommendation:

- Preserve all ingestion and replay paths unchanged.
- Add an additive canonical Master Logbook read model or bridge over existing `ipca_flight_sessions` / `ipca_operational_flight_*` and `ipca_aircraft_operations` / `ipca_meter_readings`.
- Build the first Master Logbook page as read-only, then add verification/finalization workflows only after protected regression tests exist.
- Do not delete or rename existing source-management pages until the Master Logbook has been validated against live historical and current aircraft records.

## 1. Current User-Facing Pages

### Garmin Sync Agent and Garmin Files

#### `public/admin/flight_log_garmin_connection.php`

- Route: `/admin/flight_log_garmin_connection.php`.
- Purpose: main Garmin Sync Agent admin page. It currently includes sync tokens/status, Garmin original source/files, normalized track artifacts, historical Garmin CSV backfill, FlightCircle migration, FlightCircle stored-row browser, all Garmin imports, and Garmin flight list.
- Data sources:
  - `ipca_sync_agent_tokens`.
  - `ipca_garmin_provider_states`.
  - `ipca_garmin_sync_runs`.
  - `ipca_garmin_flight_data_sources`.
  - `ipca_garmin_csv_files`.
  - `ipca_garmin_csv_flight_summaries`.
  - `ipca_garmin_normalized_track_artifacts`.
  - `ipca_garmin_track_flight_summaries`.
  - `ipca_garmin_flight_artifact_states`.
  - `ipca_garmin_historical_backfill_batches`.
  - `ipca_garmin_historical_backfill_files`.
  - `ipca_garmin_historical_segments`.
  - `ipca_flightcircle_*`.
- Services called:
  - `GarminCsvFlightSummaryService`, `GarminTrackFlightSummaryService`, `GarminProcessingStatusService`, `GarminCsvReplayPayloadService`, `GarminHistoricalBackfillService`, `FlightCircleHistoricalImportService` at `public/admin/flight_log_garmin_connection.php:33-40` and instantiated at `public/admin/flight_log_garmin_connection.php:303-307`.
- Actions available:
  - Upload historical Garmin CSVs through `/admin/api/garmin_historical_backfill_upload.php`.
  - Bulk process / reprocess / mark avionics-only through `/admin/api/garmin_historical_backfill_action.php`.
  - Import FlightCircle CSV through `/admin/api/flightcircle_historical_import.php`.
  - Create/map FlightCircle identities through `/admin/api/flightcircle_identity_action.php`.
  - Enrich selected Garmin imports with FlightCircle via `/admin/api/garmin_historical_backfill_action.php`.
  - Open replay via `/admin/cockpit_recorder_replay.php?standalone=...` when a replay payload exists.
  - Create Garmin replay payload through `/admin/api/cockpit_recorder_garmin_replay_action.php`.
- Mutation behavior:
  - Displays many records.
  - Creates historical backfill batches/files, FlightCircle import batches/staging records, matches, identity mappings, replay payloads.
  - Updates processing/review states.
  - Does not delete source evidence from the UI path found.
- Active use: yes. It is in admin navigation as "Garmin Sync Agent" in `src/nav/admin.php:134-139`.
- Duplicates another interface: yes. It overlaps with `public/admin/flight_records.php`, `public/admin/cockpit_recorder.php`, replay launch controls, and FlightCircle source-management. It is currently a source-management wall rather than a canonical Master Logbook.

#### `public/admin/api/garmin_historical_backfill_upload.php`

- Route: `/admin/api/garmin_historical_backfill_upload.php`.
- Purpose: sequential historical Garmin CSV upload endpoint.
- Data sources/tables: `ipca_garmin_historical_backfill_batches`, `ipca_garmin_historical_backfill_files`, `ipca_garmin_csv_files`, source evidence tables depending on service path.
- Service: `GarminHistoricalBackfillService`.
- Actions: creates batch/files and stores uploaded CSV evidence.
- Mutation behavior: creates and updates upload/backfill records.
- Active use: yes, form target in `public/admin/flight_log_garmin_connection.php:1094`.

#### `public/admin/api/garmin_historical_backfill_status.php`

- Route: `/admin/api/garmin_historical_backfill_status.php`.
- Purpose: status polling for historical backfill batches.
- Data source: `GarminHistoricalBackfillService::status`.
- Mutation behavior: display/status only.
- Active use: yes, used by upload/process UI.

#### `public/admin/api/garmin_historical_backfill_action.php`

- Route: `/admin/api/garmin_historical_backfill_action.php`.
- Purpose: bulk process, reprocess, mark avionics-only, and FlightCircle match/enrichment actions.
- Services: `GarminHistoricalBackfillService`, `FlightCircleGarminMatchService`, `AsyncJobService` at `public/admin/api/garmin_historical_backfill_action.php:4-8`.
- Actions:
  - `process_queued_inline`.
  - `process_selected_inline`.
  - `match_flightcircle`.
  - `reprocess`.
  - `mark_avionics_only`.
- Mutation behavior:
  - Creates/updates historical segments and summaries through service calls.
  - Creates match rows in `ipca_flightcircle_garmin_matches`.
  - Updates file/segment classifications when marking avionics-only at `public/admin/api/garmin_historical_backfill_action.php:65-80`.
- Active use: yes, bulk buttons in `public/admin/flight_log_garmin_connection.php:1134-1135` and `public/admin/flight_log_garmin_connection.php:1490-1491`.

#### `public/admin/api/garmin_historical_backfill_report.php`

- Route: `/admin/api/garmin_historical_backfill_report.php`.
- Purpose: CSV report download.
- Mutation behavior: display/export only.
- Active use: linked in `public/admin/flight_log_garmin_connection.php:1024` and `public/admin/flight_log_garmin_connection.php:1075`.

#### `public/admin/api/garmin_flights_bulk_action.php`

- Route: `/admin/api/garmin_flights_bulk_action.php`.
- Purpose: bulk actions for Garmin flight artifacts/list.
- Mutation behavior: likely updates Garmin artifact state or related records.
- Active use: form action in `public/admin/flight_log_garmin_connection.php:1670`.

#### `public/admin/api/garmin_flight_modal.php`

- Route: `/admin/api/garmin_flight_modal.php`.
- Purpose: lazy-load Garmin flight detail modal HTML on demand.
- Data sources: normalized track artifacts, track summaries, raw artifact metadata, replay payload state.
- Mutation behavior: display only.
- Active use: yes, introduced to avoid rendering all modals synchronously; called by JavaScript in `public/admin/flight_log_garmin_connection.php`.

#### `public/admin/api/garmin_processing_status.php`

- Route: `/admin/api/garmin_processing_status.php`.
- Purpose: dashboard/status polling for Garmin processing.
- Service: `GarminProcessingStatusService`.
- Mutation behavior: display/status only.
- Active use: yes, status panels depend on it.

#### `public/api/sync-agent/garmin_entries.php`

- Route: `/api/sync-agent/garmin_entries.php`.
- Purpose: macOS Sync Agent posts discovered FlyGarmin logbook entries.
- Auth: `SyncAgentAuthService::requireToken('sync_agent.garmin_entries')` at `public/api/sync-agent/garmin_entries.php:8`.
- Service: `SyncAgentGarminIngestionService::upsertEntries` at `public/api/sync-agent/garmin_entries.php:9`.
- Mutation behavior: creates/updates Garmin logbook/source metadata through `GarminFlightDataSourceService`.
- Active use: protected ingestion endpoint.

#### `public/api/sync-agent/garmin_source.php`

- Route: `/api/sync-agent/garmin_source.php`.
- Purpose: macOS Sync Agent posts original Garmin CSV source files and normalized track JSON artifacts.
- Auth: `SyncAgentAuthService::requireToken('sync_agent.garmin_source')` at `public/api/sync-agent/garmin_source.php:8`.
- Service: `SyncAgentGarminIngestionService::ingestSource` at `public/api/sync-agent/garmin_source.php:9`.
- Mutation behavior: creates immutable stored files, `ipca_garmin_csv_files`, `ipca_garmin_normalized_track_artifacts`, upload acknowledgments, async jobs, summaries.
- Active use: critical protected ingestion endpoint.

#### `public/api/sync-agent/garmin_sync_complete.php` and `public/api/sync-agent/status.php`

- Routes:
  - `/api/sync-agent/garmin_sync_complete.php`.
  - `/api/sync-agent/status.php`.
- Purpose: sync completion/status endpoints.
- Mutation behavior: status/completion acknowledgments.
- Active use: expected by macOS Sync Agent.

### FlightCircle

#### FlightCircle section inside `public/admin/flight_log_garmin_connection.php`

- Route: `/admin/flight_log_garmin_connection.php#flightcircle-stored-flights-browser`.
- Purpose: import FlightCircle CSVs, show active dataset validation, identity suggestions, and browse stored FlightCircle rows.
- Data sources:
  - `ipca_flightcircle_import_batches`.
  - `ipca_flightcircle_raw_files`.
  - `ipca_flightcircle_raw_rows`.
  - `ipca_flightcircle_staging_records`.
  - `ipca_flightcircle_user_mappings`.
  - `ipca_aircraft_operations`.
  - `ipca_meter_readings`.
  - `ipca_crew_assignments`.
- Actions:
  - Upload/replace active dataset.
  - Create/map users.
  - Filter/sort/paginate staging rows.
  - Enrich Garmin imports with FlightCircle matches.
- Mutation behavior: creates FlightCircle staging and source-neutral operation candidates; creates/updates identity mappings and matches.
- Active use: yes.
- Duplicates another interface: yes, because it is a source-management workflow on the Garmin Sync page rather than a secondary source tab under a Master Logbook.

#### `public/admin/api/flightcircle_historical_import.php`

- Route: `/admin/api/flightcircle_historical_import.php`.
- Purpose: upload FlightCircle historical CSV.
- Auth: admin required at `public/admin/api/flightcircle_historical_import.php:7`.
- Service: `FlightCircleHistoricalImportService::importUploadedFile` at `public/admin/api/flightcircle_historical_import.php:35`.
- Mutation behavior: creates batch, raw file, raw rows, staging records, operation candidates, meter readings, fuel facts, crew assignments, identity suggestions, and logbook proposals depending on parsed rows.
- Active use: yes.

#### `public/admin/api/flightcircle_historical_status.php`

- Route: `/admin/api/flightcircle_historical_status.php`.
- Purpose: FlightCircle import status and validation.
- Service: `FlightCircleHistoricalImportService::status`.
- Mutation behavior: display/status only.
- Active use: yes.

#### `public/admin/api/flightcircle_staging_rows.php`

- Route: `/admin/api/flightcircle_staging_rows.php`.
- Purpose: API-backed filtered/paginated FlightCircle staging rows.
- Data source: `ipca_flightcircle_staging_records`.
- Mutation behavior: display only.
- Active use: yes, JavaScript fetch in `public/admin/flight_log_garmin_connection.php:1993-1999`.

#### `public/admin/api/flightcircle_identity_action.php`

- Route: `/admin/api/flightcircle_identity_action.php`.
- Purpose: create/map user identities from FlightCircle source names.
- Service: `FlightCircleHistoricalImportService`.
- Mutation behavior: creates users or maps `ipca_flightcircle_user_mappings`; updates crew assignments.
- Active use: yes.

### Cockpit Recorder, CVR, Transcripts, and Replay

#### `public/admin/cockpit_recorder.php`

- Route: `/admin/cockpit_recorder.php`.
- Purpose: Cockpit Recorder admin list and controls for recordings, reconstruction, Garmin candidates/options, ADS-B enrichment, transcript/status controls, and replay launch.
- Services:
  - `CockpitRecorderService`.
  - `CockpitReconstructionService`.
  - `CockpitAdsbEnrichmentService`.
  - `GarminCsvFlightSummaryService`.
  - `GarminCsvReplayPayloadService`.
  - Required at `public/admin/cockpit_recorder.php:4-11`.
- Data sources:
  - `ipca_cockpit_recordings`.
  - `ipca_cockpit_reconstruction_jobs`.
  - `ipca_cockpit_adsb_enrichments`.
  - `ipca_flight_sessions`.
  - `ipca_garmin_source_groups`.
  - `ipca_garmin_flight_data_sources`.
  - `ipca_garmin_csv_files`.
- Actions:
  - Upload/inspect recordings.
  - Start reconstruction.
  - Poll reconstruction status.
  - Cancel reconstruction.
  - Attach/select Garmin evidence.
  - Enrich ADS-B.
  - Open replay.
- Mutation behavior: creates/updates recording, reconstruction, ADS-B, and association records through APIs and services.
- Active use: yes, linked from Garmin Sync page at `public/admin/flight_log_garmin_connection.php:1007`.
- Protected: yes.

#### `public/admin/cockpit_recorder_replay.php`

- Route: `/admin/cockpit_recorder_replay.php`.
- Purpose: Cockpit Replay player.
- Required query modes:
  - `id=<recording id or uid>` for cockpit recording replay.
  - `standalone=<replay key>` for standalone Garmin replay payloads.
- Data sources:
  - `ipca_cockpit_recordings`.
  - `ipca_cockpit_replay_samples`.
  - `ipca_aircraft_instrument_profiles`.
  - aircraft/replay profile/settings tables when present.
  - standalone Garmin replay payloads via APIs/services.
- Services: `CockpitRecorderService` required at `public/admin/cockpit_recorder_replay.php:4-6`; direct DB queries are used for profiles at `public/admin/cockpit_recorder_replay.php:150-152`.
- Actions:
  - Launch/render replay.
  - Client-side player interactions, camera/instrument settings, audio sync.
- Mutation behavior: mostly display/rendering; may persist client settings locally; server code should be treated as replay-critical.
- Active use: yes.
- Duplicates another interface: no, this is the replay implementation, not a source-management table.

#### `public/admin/cockpit_recorder_audio.php`, `public/admin/cockpit_recorder_gps.php`, `public/admin/cockpit_recorder_ahrs.php`, `public/admin/cockpit_recorder_g3x.php`, `public/admin/cockpit_recorder_adsb.php`, `public/admin/cockpit_recorder_events.php`

- Routes: matching `/admin/<file>`.
- Purpose: evidence-specific views/downloads/diagnostics for recorder audio, GPS, AHRS, G3X, ADS-B, and event evidence.
- Data source: `ipca_cockpit_recordings` plus storage paths under `storage/cockpit_recorder/*`.
- Mutation behavior: mostly display/download; G3X upload API can mutate recording evidence.
- Active use: yes where linked from recorder admin/player.
- Duplicates another interface: source evidence views overlap with the desired future "CVR Recordings" secondary tab.

#### `public/admin/cvr_audio_preview.php` and `public/admin/cvr_transcript_test.php`

- Routes:
  - `/admin/cvr_audio_preview.php`.
  - `/admin/cvr_transcript_test.php`.
- Purpose: CVR audio and transcript testing/preview.
- Data source: cockpit recordings/transcript fields.
- Mutation behavior: test/transcription actions may update transcript status/text depending endpoint used.
- Active use: unknown; likely diagnostic/admin tooling.

#### `public/admin/api/cockpit_recorder_transcribe.php`

- Route: `/admin/api/cockpit_recorder_transcribe.php`.
- Purpose: admin-triggered transcription.
- Service: `CockpitRecorderService`.
- Mutation behavior: updates `ipca_cockpit_recordings.transcription_*`, `transcript_text`, and `ipca_cockpit_recording_transcription_chunks`.
- Active use: yes.
- Protected: yes.

#### `public/admin/api/cockpit_recorder_reconstruction_status.php`, `public/admin/api/cockpit_recorder_reconstruct.php`, `public/admin/api/cockpit_recorder_bulk_action.php`

- Routes:
  - `/admin/api/cockpit_recorder_reconstruction_status.php`.
  - `/admin/api/cockpit_recorder_reconstruct.php`.
  - `/admin/api/cockpit_recorder_bulk_action.php`.
- Purpose: reconstruction launch/status/bulk controls.
- Services: `CockpitReconstructionService`, `CockpitRecorderService`.
- Mutation behavior: creates jobs, updates reconstruction status, writes replay samples, phases, events.
- Active use: yes. Status polling JavaScript references `/admin/api/cockpit_recorder_reconstruction_status.php` in `public/admin/cockpit_recorder.php:1369-1378`.
- Protected: yes.

#### `public/api/recordings/upload.php`

- Route: `/api/recordings/upload.php`.
- Purpose: legacy/simple multipart recording upload.
- Auth finding: no endpoint-level bearer-device or admin auth check was found in this file; it is a protected compatibility surface because iOS/CVR clients rely on its request/response shape.
- Inputs: `audio` file plus optional `ahrs` and `gps`; metadata fields include `recording_id`, `started_at`, `duration`, `input_device`, `aircraft_id`, `language`, `import_profile` at `public/api/recordings/upload.php:31-39`.
- Service: `CockpitRecorderService::storeUploadedRecording` at `public/api/recordings/upload.php:41-44`.
- Mutation behavior: creates/updates `ipca_cockpit_recordings` and evidence storage.
- Active use: protected compatibility endpoint.

#### `public/api/recordings/upload_chunk.php`

- Route: `/api/recordings/upload_chunk.php`.
- Purpose: resumable chunk upload for audio/AHRS/GPS/G3X/beacon/events.
- Auth finding: no endpoint-level bearer-device or admin auth check was found in this file; retry/resume behavior must remain unchanged.
- File types: `audio`, `ahrs`, `gps`, `g3x`, `beacon`, `events` at `public/api/recordings/upload_chunk.php:32-36`.
- Idempotency/retry: GET reports received chunks; POST stores chunk if not already present; size checks and disk-space checks are included at `public/api/recordings/upload_chunk.php:81-110` and `public/api/recordings/upload_chunk.php:166-176`.
- Storage: `storage/cockpit_recorder/upload_sessions/<recordingUid>/<fileType>/`.
- Mutation behavior: writes chunk files and JSON upload metadata, not DB final records.
- Active use: critical protected iPhone upload endpoint.

#### `public/api/recordings/upload_finalize.php`

- Route: `/api/recordings/upload_finalize.php`.
- Purpose: assemble chunked upload and create the Cockpit Recorder DB record.
- Auth finding: no endpoint-level bearer-device or admin auth check was found in this file; it finalizes the legacy recording package flow and must stay compatible.
- Assembly: validates all chunks and assembled size at `public/api/recordings/upload_finalize.php:37-89`.
- Optional evidence: audio required; AHRS/GPS/G3X/beacon/events optional at `public/api/recordings/upload_finalize.php:140-148`.
- Service: `CockpitRecorderService::storeAssembledRecording` at `public/api/recordings/upload_finalize.php:171-182`.
- Cleanup: removes upload session after successful store at `public/api/recordings/upload_finalize.php:184`.
- Mutation behavior: creates/updates `ipca_cockpit_recordings`, storage paths, and evidence metadata.
- Active use: critical protected iPhone upload endpoint.

#### `public/api/recordings/g3x_finalize.php`

- Route: `/api/recordings/g3x_finalize.php`.
- Purpose: finalize/upload supplemental G3X evidence for a recording.
- Service: `CockpitRecorderService::storeSupplementalG3X`.
- Mutation behavior: updates G3X storage path/metadata and marks reconstruction stale.
- Active use: protected.

#### `public/api/recordings/transcript.php`, `public/api/recordings/replay.php`, `public/api/recordings/status.php`, `public/api/recordings/index.php`, `public/api/recordings/aircraft.php`

- Routes under `/api/recordings/*`.
- Purpose: mobile/app-facing status, transcript, replay, recordings list, and aircraft lookup.
- Mutation behavior: status/list mostly display; transcript/replay may depend on processed records.
- Active use: protected app API surface.

#### `public/api/cvr/enroll.php`, `public/api/cvr/device_status.php`, `public/api/cvr/csv_upload_chunk.php`, `public/api/cvr/csv_upload_finalize.php`, `public/api/cvr/csv_status.php`

- Routes under `/api/cvr/*`.
- Purpose: CVR device enrollment/status and chunked Garmin CSV upload flow tied to CVR device/session model.
- Data sources:
  - `ipca_cvr_devices`.
  - `ipca_cvr_device_credentials`.
  - `ipca_cvr_device_enrollments`.
  - `ipca_garmin_csv_upload_requests`.
  - `ipca_garmin_csv_files`.
- Mutation behavior: creates/updates device credentials, upload requests, CSV files.
- Active use: protected app/device API surface.

### Flight Records and Training Logbooks

#### `public/admin/flight_records.php`

- Route: `/admin/flight_records.php`.
- Purpose: operational flight records overview based on existing `ipca_operational_flight_records` model.
- Service: `FlightRecordViewService` at `public/admin/flight_records.php:4-11`.
- Data source:
  - `ipca_operational_flight_records`.
  - `ipca_operational_flight_record_versions`.
  - `ipca_flight_sessions`.
  - student view joins `ipca_flight_record_logbook_proposals`.
- Actions: preview/rederive from existing Garmin file through API; details modal; map rendering.
- Mutation behavior: page display; rederive action mutates new versions via `/admin/api/flight_record_rederive.php`.
- Active use: yes, in admin navigation at `src/nav/admin.php:124-130`.
- Duplicates another interface: overlaps with intended Master Logbook, but currently session/record-centric, not all evidence source neutral.

#### `public/admin/api/flight_record_preview.php`

- Route: `/admin/api/flight_record_preview.php`.
- Purpose: preview operational flight record derivation from Garmin CSV.
- Service: `FlightRecordDerivationService::previewFromCsvFile`.
- Mutation behavior: display/calculation only.
- Active use: flight records / Garmin pages.

#### `public/admin/api/flight_record_rederive.php`

- Route: `/admin/api/flight_record_rederive.php`.
- Purpose: rederive operational flight record from existing Garmin CSV.
- Service: `FlightRecordDerivationService::deriveFromCsvFile`.
- Mutation behavior: creates a new version, leg versions, calculation versions, event versions; updates `ipca_flight_sessions.current_flight_record_id`.
- Active use: flight records page.

#### `public/admin/flight_record_logbook_proposals.php`

- Route: `/admin/flight_record_logbook_proposals.php`.
- Purpose: review/accept proposed logbook entries derived from Flight Records.
- Service: `FlightRecordLogbookIntegrationService` at `public/admin/flight_record_logbook_proposals.php:4-17`.
- Data sources:
  - `ipca_flight_record_logbook_proposals`.
  - `ipca_logbook_proposal_groups`.
  - `ipca_operational_flight_record_versions`.
  - `ipca_operational_flight_leg_versions`.
  - `ipca_admin_logbooks`.
  - `ipca_admin_logbook_entries`.
- Actions: accept proposal to official admin logbook.
- Mutation behavior: creates official logbook entry and accepted proposal link; updates proposal/group status.
- Active use: yes.

#### `public/admin/flight_training/logbooks/view.php` and `public/admin/flight_training/logbooks/print.php`

- Routes:
  - `/admin/flight_training/logbooks/view.php`.
  - `/admin/flight_training/logbooks/print.php`.
- Purpose: official admin/student training logbook pages.
- Service: `AdminLogbookService`.
- Data source:
  - `ipca_admin_logbooks`.
  - `ipca_admin_logbook_pages`.
  - `ipca_admin_logbook_entries`.
  - `ipca_admin_logbook_entry_audit`.
- Actions: create/update/accept/delete logbook entries depending page controls.
- Mutation behavior: official logbook writes.
- Active use: yes, in navigation at `src/nav/admin.php:117-120`.
- Duplicates another interface: overlaps with future Master Logbook logbook-proposal/verification layer but should remain official logbook storage until replacement is approved.

#### Training Forms, Requirements, Missions, Debriefs

- `public/admin/flight_training/forms/index.php`, `/send.php`: training form workflows.
- `public/admin/flight_training/requirements/index.php`: flight requirements.
- `public/admin/missions.php`: mission catalog UI.
- `public/admin/flight_debriefs.php`: flight debrief evidence.
- Data sources include `ipca_missions`, `ipca_mission_versions`, `ipca_flight_mission_assignments`, `ipca_flight_requirement_*`, and debrief evidence tables.
- Mutation behavior: training records and mission/requirement management.
- Active use: yes where present in navigation.
- Relevance: should feed/support Master Logbook mission assignment, briefing, remarks, and verification, but must not be collapsed into Garmin/CVR source logs.

### ADS-B and Traffic

#### `public/admin/adsb_traffic_archive.php`

- Route: `/admin/adsb_traffic_archive.php`.
- Purpose: ADS-B traffic archive management/diagnostics.
- Data sources:
  - `ipca_adsb_coverage_jobs`.
  - `ipca_adsb_coverage_tiles`.
  - `ipca_adsb_raw_payloads`.
  - `ipca_adsb_traffic_samples`.
  - `ipca_adsb_flight_traffic_links`.
  - `ipca_adsb_hazard_events`.
- Actions: fetch/archive/enrich depending API.
- Mutation behavior: creates/updates ADS-B archive records.
- Active use: yes for ADS-B enrichment tooling.

#### ADS-B APIs

- Routes:
  - `/admin/api/adsb_archive_dashboard.php`.
  - `/admin/api/adsb_archive_action.php`.
  - `/admin/api/cockpit_recorder_adsb_enrich.php`.
  - `/admin/api/cockpit_recorder_adsb_diagnostic.php`.
  - `/admin/api/flight_record_adsb_fetch.php`.
  - `/api/flight_records/traffic.php`.
- Purpose: archive dashboard/actions, recorder ADS-B enrich/diagnostics, flight-record traffic fetch/player traffic.
- Mutation behavior: archive/enrichment APIs create/update ADS-B records; traffic API displays/serves data.
- Active use: protected replay/enrichment path.

## 2. Current Database Model

### Core Aircraft and Users

#### `users`

- Primary key: `id`.
- Record meaning: application user/student/instructor/supervisor/admin.
- Code creates/updates/deletes: general user admin plus FlightCircle identity creation in `FlightCircleHistoricalImportService`.
- Evidence/final status: operational identity source of truth for app users.
- Overlap: FlightCircle user names are staged separately in `ipca_flightcircle_user_mappings`.

#### `ipca_aircraft_devices`

- Primary key: `id`; unique `registration`.
- Important fields: `registration`, `display_name`, `aircraft_type`, `adsb_hex`, `home_airport`, `active`.
- Created by migration `scripts/sql/2026_06_20_cockpit_recorder_aircraft_devices.sql:4-19`.
- Record meaning: aircraft/device registry shared by scheduling and Cockpit Recorder.
- Code creates/updates: `CockpitAircraftService`, aircraft settings/admin pages.
- Evidence/final status: aircraft registry/source of truth for aircraft identity where present.
- Overlap: many legacy/source tables also store aircraft registration as denormalized text.

### Cockpit Recorder and CVR

#### `ipca_cockpit_recordings`

- Primary key: `id`; important external identifier `recording_uid`.
- Created by `scripts/sql/2026_06_17_cockpit_recorder_poc.sql`; extended by aircraft, GPS/AHRS/G3X/reconstruction/transcription migrations.
- Record meaning: stored Cockpit Recorder audio package plus evidence file paths and processing statuses.
- Code creates/updates:
  - `CockpitRecorderService::storeUploadedRecording`, `storeAssembledRecording`, and `storeRecordingFromLocalPaths` at `src/CockpitRecorderService.php:86-132` and `src/CockpitRecorderService.php:166-279`.
  - Transcription methods update transcript status/text at `src/CockpitRecorderService.php:631-671`.
  - Reconstruction updates status via `CockpitReconstructionService`.
- Code deletes: soft-delete migration exists (`scripts/sql/2026_07_16_cockpit_recorder_soft_delete.sql`); hard deletes were not fully traced in this audit.
- Classification: raw evidence plus processing status container.
- Source of truth: yes for CVR package identity, audio path, transcript text, and replay launch by recording.
- Overlap: `ipca_flight_sessions` is the normalized evidence-session layer; both store aircraft/time/session-ish facts.

#### `ipca_cockpit_recording_transcription_chunks`

- Primary key: `id`.
- Record meaning: chunked transcription units for long recordings.
- Created by `scripts/sql/2026_06_22_cockpit_recorder_transcription_chunks.sql`.
- Code creates/updates: `CockpitRecorderService` chunked transcription methods; see chunk references at `src/CockpitRecorderService.php:693-833` and storage at `src/CockpitRecorderService.php:2127-2159`.
- Classification: processed evidence.
- Source of truth: yes for chunk-level transcription state; final transcript is on `ipca_cockpit_recordings.transcript_text`.

#### `ipca_cvr_devices`, `ipca_cvr_device_credentials`, `ipca_cvr_device_enrollments`

- Primary keys: `id`; UUID columns are unique.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:61-126`.
- Record meaning:
  - CVR aircraft iPhone devices.
  - Hashed bearer credentials.
  - One-time enrollments.
- Code creates/updates: `/api/cvr/enroll.php`, device status APIs, `DeviceAuthService`.
- Classification: operational device/auth configuration.
- Source of truth: yes for CVR device authentication and association.

#### `ipca_audio_evidence_links`

- Primary key: `id`; unique `link_uuid`.
- FKs: `session_id` to `ipca_flight_sessions`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:244-265`.
- Record meaning: audio coverage link for a normalized flight session.
- Classification: evidence association.
- Source of truth: supporting evidence link, not raw audio storage.

### Replay and Reconstruction

#### `ipca_cockpit_reconstruction_jobs`

- Primary key: `id`; FK `recording_id` to `ipca_cockpit_recordings`.
- Created at `scripts/sql/2026_06_23_cockpit_recorder_reconstruction_foundation.sql:87-105`.
- Record meaning: reconstruction job status/progress.
- Code creates/updates: `CockpitReconstructionService::reconstruct` creates jobs and reports progress at `src/CockpitReconstructionService.php:216-239`.
- Classification: processing activity.
- Source of truth: yes for reconstruction progress.

#### `ipca_cockpit_flight_samples`

- Primary key: `id`; FK `recording_id`.
- Created at `scripts/sql/2026_06_23_cockpit_recorder_reconstruction_foundation.sql:107-143`; extended by replay value migrations.
- Record meaning: canonical merged reconstruction samples from GPS/AHRS/G3X/ADS-B.
- Code creates/updates: `CockpitReconstructionService`.
- Classification: processed evidence.
- Source of truth: processed reconstruction samples for analysis, not final operational logbook.

#### `ipca_cockpit_replay_samples`

- Primary key: `id`; FK `recording_id`.
- Created/extended by multiple replay sample migrations.
- Record meaning: replay-ready timeline samples used by Cockpit Replay player.
- Code creates/updates: `CockpitReconstructionService`, `CockpitReplayPipeline`.
- Classification: processed replay evidence.
- Source of truth: yes for current Cockpit Replay rendering.
- Protected: do not alter identifiers or schema in Master Logbook phase 1.
- Follow-up finding: the replay table is expected by runtime services, but one migration file named for replay samples was reported by repository tracing as containing unrelated written-test DDL while later migrations alter replay-sample columns. Before any replay-adjacent schema work, verify the live schema and migration history; do not attempt to "fix" replay migrations inside the Master Logbook phase.

#### `ipca_cockpit_flight_phases`, `ipca_cockpit_timeline_events`

- Primary keys: `id`; FK `recording_id`.
- Created at `scripts/sql/2026_06_23_cockpit_recorder_reconstruction_foundation.sql:145-182`.
- Record meaning: derived phases/events for reconstruction and replay.
- Classification: processed evidence.
- Source of truth: current reconstruction event layer, not final logbook.

#### `ipca_cockpit_adsb_enrichments`, `ipca_cockpit_adsb_ownship_samples`, `ipca_cockpit_adsb_traffic_samples`, `ipca_cockpit_adsb_traffic_aircraft_samples`

- Primary keys: `id`; FK `recording_id`.
- Created at `scripts/sql/2026_06_23_cockpit_recorder_reconstruction_foundation.sql:184-253` and `scripts/sql/2026_07_18_cockpit_recorder_adsb_traffic_pipeline.sql`.
- Record meaning: ADS-B enrichment status and samples for replay/traffic.
- Code creates/updates: `CockpitAdsbEnrichmentService`, `OpenSkyTrafficArchiveBackfillService`, `LocalTrafficArchiveRepository`.
- Classification: processed/archived evidence.
- Source of truth: yes for replay traffic enrichment, not operational flight record core.

### Garmin Source Evidence

#### `ipca_garmin_provider_states`

- Primary key: `id`.
- Created by `scripts/sql/2026_07_13_garmin_cloud_provider_foundation.sql:5-42`.
- Record meaning: provider cursor/session state.
- Code creates/updates: Garmin provider state services.
- Classification: integration state.

#### `ipca_garmin_logbook_entries`, `ipca_garmin_logbook_entry_versions`

- Primary keys: `id`; unique Garmin identifiers.
- Created at `scripts/sql/2026_07_13_garmin_cloud_provider_foundation.sql:43-86`.
- Record meaning: FlyGarmin logbook entry metadata and versions.
- Code creates/updates: `GarminFlightDataSourceService`, Sync Agent ingestion.
- Classification: raw/normalized external source metadata.
- Source of truth: Garmin source metadata only, not operational flight log.

#### `ipca_garmin_flight_data_sources`

- Primary key: `id`.
- Created at `scripts/sql/2026_07_13_garmin_cloud_provider_foundation.sql:87-162`.
- Record meaning: individual Garmin source/log data file descriptors and status.
- Code creates/updates:
  - `GarminFlightDataSourceService`.
  - `SyncAgentGarminIngestionService::ingestSource` updates source download/import/validation fields at `src/SyncAgentGarminIngestionService.php:100-127`.
- Classification: raw source metadata/evidence pointer.
- Source of truth: source file status and Garmin identifiers.

#### `ipca_garmin_csv_files`

- Primary key: `id`; unique `csv_file_uuid`; hash/source indexes.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:296-343`; extended by Garmin system identifier migration at `scripts/sql/2026_07_16_garmin_system_identifier_mapping.sql:7-80`.
- Record meaning: stored Garmin CSV file evidence.
- Code creates/updates:
  - `SyncAgentGarminIngestionService::ensureCsvFile` after storing immutable source at `src/SyncAgentGarminIngestionService.php:91-130`.
  - `GarminCsvEvidenceService`.
  - `GarminHistoricalBackfillService`.
  - CVR CSV upload APIs.
- Classification: raw evidence plus normalized metadata.
- Source of truth: yes for Garmin CSV evidence identity/path/hash; no for final operational leg.
- Overlap: historical backfill files link to CSV files; FlyGarmin sources also link to CSV files.

#### `ipca_garmin_csv_fingerprints`, `ipca_garmin_csv_validation_results`, `ipca_garmin_csv_session_matches`, `ipca_garmin_csv_supersession_links`

- Primary keys: `id`.
- FKs: `csv_file_id` to `ipca_garmin_csv_files`; session matches also FK to `ipca_flight_sessions`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:344-431`.
- Record meaning:
  - Deterministic content fingerprints.
  - Validation results.
  - CSV-to-session match decisions.
  - Duplicate/supersession relationships.
- Code creates/updates:
  - `GarminCsvValidationService`.
  - `GarminCsvSessionMatchService`.
  - `GarminCsvEvidenceService`.
- Classification: processed evidence and association.
- Source of truth: no for final operations; yes for evidence quality/matching state.

#### `ipca_garmin_csv_flight_summaries`, `ipca_garmin_track_flight_summaries`, `ipca_garmin_flight_artifact_states`

- Primary keys: `id`.
- Created at `scripts/sql/2026_07_16_garmin_csv_flight_summaries.sql:1-60`.
- Record meaning:
  - Derived CSV flight summaries.
  - Derived normalized track summaries.
  - Artifact UI state/hidden state.
- Code creates/updates:
  - `GarminCsvFlightSummaryService`.
  - `GarminTrackFlightSummaryService`.
  - Garmin admin APIs.
- Classification: processed evidence.
- Source of truth: summaries for display and matching; not final logbook.

#### `ipca_garmin_normalized_track_artifacts`

- Primary key: `id`; unique track identifiers.
- Created by `scripts/sql/2026_07_15_garmin_normalized_track_artifacts.sql`.
- Code creates/updates: `SyncAgentGarminIngestionService::ingestNormalizedTrackJson` inserts/updates at `src/SyncAgentGarminIngestionService.php:140-247`.
- Record meaning: stored FlyGarmin normalized track JSON artifacts.
- Classification: raw/normalized Garmin evidence.
- Source of truth: yes for Garmin track artifact identity and replay-supporting data.

#### `ipca_garmin_csv_replay_payloads`

- Primary key: `id`.
- Created by `scripts/sql/2026_07_17_garmin_csv_replay_payloads.sql`.
- Record meaning: standalone replay payloads derived from Garmin CSV.
- Code creates/updates: `GarminCsvReplayPayloadService`, replay action API.
- Classification: processed replay evidence.
- Source of truth: yes for standalone Garmin replay launch keys.

### Historical Garmin Backfill and FlightCircle

#### `ipca_source_evidence`

- Primary key: `id`; unique `evidence_uuid`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:6-28`.
- Record meaning: source-neutral immutable/derived evidence references.
- Code creates/updates: FlightCircle/Garmin historical services where evidence IDs are used.
- Classification: raw evidence reference.
- Source of truth: evidence catalog only.

#### `ipca_garmin_historical_backfill_batches`, `ipca_garmin_historical_backfill_files`, `ipca_garmin_historical_segments`, `ipca_garmin_historical_review_decisions`

- Primary keys: `id`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:214-312`.
- Record meaning:
  - Bulk upload batches.
  - Individual uploaded files with duplicate/classification status.
  - Operational segments classified from historical Garmin evidence.
  - Review/audit decisions.
- Code creates/updates: `GarminHistoricalBackfillService`; action API at `public/admin/api/garmin_historical_backfill_action.php`.
- Classification: raw and processed evidence, not final canonical operational data.
- Source of truth: no; supports historical enrichment/review.

#### `ipca_flightcircle_import_batches`, `ipca_flightcircle_raw_files`, `ipca_flightcircle_raw_rows`, `ipca_flightcircle_staging_records`, `ipca_flightcircle_user_mappings`, `ipca_flightcircle_garmin_matches`

- Primary keys: `id`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:314-490`.
- Record meaning:
  - Import batch metadata with `active_dataset`.
  - Immutable raw file.
  - Raw CSV rows.
  - Normalized staging rows.
  - Identity suggestions/mappings.
  - Garmin match candidates/outcomes.
- Code creates/updates:
  - `FlightCircleHistoricalImportService::importUploadedFile`.
  - `FlightCircleGarminMatchService`.
  - identity action API.
- Classification:
  - Raw evidence: raw files/rows.
  - Normalized source staging: staging records.
  - Proposed associations: mappings and matches.
- Source of truth: FlightCircle staging is historical source evidence; the intended source-neutral operation candidate is `ipca_aircraft_operations`.

### Canonical/Operational Models

#### `ipca_flight_sessions`

- Primary key: `id`; unique `session_uuid`.
- FKs: `device_id` to `ipca_cvr_devices`, `aircraft_id` to `ipca_aircraft_devices`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:171-200`.
- Record meaning: evidence flight session, normally one avionics on/off cycle.
- Code creates/updates: `FlightSessionService`, CVR/Garmin match services; `FlightRecordDerivationService` updates `current_flight_record_id` and durations at `src/FlightRecordDerivationService.php:190-191`.
- Classification: evidence session / proposed operational session.
- Source of truth: currently acts as session anchor for CVR/Garmin-derived operational records.
- Overlap: overlaps with FlightCircle `ipca_aircraft_operations` dispatch sessions.

#### `ipca_operational_flight_records`

- Primary key: `id`; unique `flight_record_uuid`.
- FK: `session_id` to `ipca_flight_sessions`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:433-448`.
- Record meaning: stable operational flight record identity for a session.
- Code creates/updates: `OperationalFlightRecordVersionService`, `FlightRecordDerivationService`.
- Classification: proposed/final operational data depending status/version finalization.
- Source of truth: currently the closest canonical flight-record identity for CVR/Garmin-derived sessions.
- Gap: session-scoped, not explicitly "training event" or FlightCircle dispatch scoped.

#### `ipca_operational_flight_record_versions`

- Primary key: `id`; unique `(flight_record_id, version_number)`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:450-483`.
- Record meaning: immutable version snapshot of operational calculations.
- Code creates: `FlightRecordDerivationService::deriveFromCsvFile` via `OperationalFlightRecordVersionService` at `src/FlightRecordDerivationService.php:150-181`.
- Classification: proposed/finalized operational snapshot.
- Source of truth: current version is authoritative for derived fields when approved/finalized.

#### `ipca_operational_flight_leg_versions`

- Primary key: `id`; FK `flight_record_version_id`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:485-522`.
- Record meaning: versioned leg allocations with timing, airports, fuel, night/cross-country flags, and landing counts.
- Code creates: `FlightRecordDerivationService::deriveFromCsvFile` loops over preview legs at `src/FlightRecordDerivationService.php:183-185`.
- Classification: proposed/final operational leg version.
- Source of truth: closest existing flight-leg model.
- Gap: not yet exposed as one canonical Master Logbook row per leg; not linked to FlightCircle operation candidates except indirectly through future associations.

#### `ipca_flight_airport_event_versions`, `ipca_flight_crew_member_versions`, `ipca_operational_calculation_versions`, `ipca_flight_manual_input_versions`

- Primary keys: `id`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:524-623`.
- Record meaning:
  - Versioned airport/operational events.
  - Versioned crew snapshots.
  - Calculation provenance.
  - Manual fallback inputs.
- Code creates/updates: `FlightRecordDerivationService` and related services.
- Classification: proposed/final operational version data.
- Source of truth: yes for current operational flight-record version details when current/finalized.

#### `ipca_aircraft_operations`

- Primary key: `id`; unique `operation_uuid`; unique `(source_mode, source_identity_hash)`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:30-68`.
- Record meaning: source-neutral aircraft dispatch/operation, including historical migration candidates.
- Code creates: `FlightCircleHistoricalImportService::createOperationCandidate` at `src/FlightCircleHistoricalImportService.php:574-620`.
- Classification: proposed operational data.
- Source of truth: intended historical ledger candidate, not yet unified with `ipca_operational_flight_records`.
- Overlap: duplicates session/flight-record concept for historical FlightCircle operations.

#### `ipca_aircraft_operation_evidence_links`

- Primary key: `id`.
- Record meaning: links source-neutral operations to FlightCircle, Garmin, recorder, manual, or future evidence.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:70-85`.
- Code creates: `FlightCircleHistoricalImportService::linkOperationEvidence`; matches may also link indirectly.
- Classification: evidence association.
- Source of truth: association table.

#### `ipca_meter_readings`

- Primary key: `id`; unique `meter_reading_uuid`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:87-115`.
- Record meaning: source-neutral Hobbs/Tach/TTAF readings.
- Code creates: `FlightCircleHistoricalImportService::createMeterReadings` at `src/FlightCircleHistoricalImportService.php:626-654`.
- Classification: proposed/final ledger depending review.
- Source of truth: intended source-neutral meter ledger, but current Garmin operational record versions also store Hobbs/Tach values.
- Overlap: `ipca_operational_flight_record_versions.hobbs_*`, `tacho_*` and `ipca_flight_sessions.exact_*_duration_ms`.

#### `ipca_fuel_transactions`

- Primary key: `id`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:117-136`.
- Code creates: `FlightCircleHistoricalImportService::createFuelFacts` at `src/FlightCircleHistoricalImportService.php:660-681`.
- Classification: proposed operational evidence/fact.
- Source of truth: not yet final.

#### `ipca_crew_assignments`

- Primary key: `id`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:138-161`.
- Code creates/updates:
  - FlightCircle import creates assignments at `src/FlightCircleHistoricalImportService.php:687-714`.
  - identity mapping updates assignments at `src/FlightCircleHistoricalImportService.php:848-850`.
- Classification: proposed crew assignment.
- Source of truth: intended source-neutral crew assignment for historical migration; separate from versioned `ipca_flight_crew_member_versions`.
- Overlap: `ipca_flight_crew_member_versions`.

#### `ipca_operational_events`

- Primary key: `id`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:163-180`.
- Record meaning: source-neutral operation events such as dispatch, return, engine, taxi, takeoff, landing, verification.
- Code creates: not clearly found beyond schema in this audit.
- Classification: proposed source-neutral event table.
- Source of truth: not yet active.

#### `ipca_migration_cutovers`

- Primary key: `id`.
- Created at `scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql:182-212`.
- Record meaning: aircraft/organization migration cutover approvals and meter continuity checks.
- Code creates/updates: no complete workflow found in this audit.
- Classification: proposed finalization/approval data.
- Source of truth: not active yet.

### Official Logbook and Proposals

#### `ipca_admin_logbooks`, `ipca_admin_logbook_pages`, `ipca_admin_logbook_entries`, `ipca_admin_logbook_entry_audit`

- Primary keys: `id`.
- Created at `scripts/sql/2026_06_17_admin_logbook_requirements_foundation.sql:6-120`; CSV/import extensions at `scripts/sql/2026_06_17_admin_logbook_csv_import_foundation.sql`.
- Record meaning: official admin/student logbook structure and audit.
- Code creates/updates/deletes: `AdminLogbookService`; `FlightRecordLogbookIntegrationService` writes accepted flight-record proposals into official logbook at `src/FlightRecordLogbookIntegrationService.php:23-35`.
- Classification: finalized/manual official logbook data depending review status.
- Source of truth: yes for official training logbook entries today.
- Overlap: proposed entries in `ipca_flight_record_logbook_proposals`; FlightCircle import has separate historical logbook proposal logic.

#### `ipca_logbook_proposal_groups`, `ipca_flight_record_logbook_proposals`, `ipca_logbook_proposal_category_mappings`, `ipca_accepted_logbook_proposal_links`

- Primary keys: `id`.
- Created at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:625-724`.
- Record meaning: proposed logbook entries derived from flight records and accepted proposal links.
- Code creates/updates: `FlightRecordLogbookProposalService` creates proposals at `src/FlightRecordLogbookProposalService.php:16-56` and accepts them at `src/FlightRecordLogbookProposalService.php:58-114`.
- Classification: proposed operational/logbook data until accepted.
- Source of truth: no until accepted; official logbook is `ipca_admin_logbook_entries`.

#### `ipca_historical_logbook_proposals`

- Primary key: `id`.
- Created by the historical Garmin/FlightCircle migration path.
- Record meaning: proposed student/instructor/simulator logbook values derived from FlightCircle historical operations.
- Code creates/updates: `FlightCircleHistoricalImportService` creates historical proposals while promoting FlightCircle staging records.
- Classification: proposed historical logbook data.
- Source of truth: no until accepted into official `ipca_admin_logbook_entries`.
- Gap: no repository equivalent was found for accepting these historical proposals into official logbook entries with the same safeguards used by `FlightRecordLogbookIntegrationService` for live flight-record proposals. This should be a dedicated additive bridge before historical proposals are operationally accepted.

### Missions, Squawks, Safety Reports

#### `ipca_missions`, `ipca_mission_versions`, `ipca_mission_aliases`, `ipca_mission_import_batches`, `ipca_flight_mission_assignments`

- Created at `scripts/sql/2026_07_12_mission_catalog_foundation.sql:3-70`.
- Record meaning: mission catalog and flight mission assignments.
- Classification: operational/training metadata.
- Source of truth: mission catalog where used.
- Master Logbook relevance: mission code should reference this layer, not Garmin/FlightCircle notes directly.

#### Squawks and safety reports

- Confirmed tables were not fully traced by name in this audit. Likely candidates may exist under compliance/safety modules, but a definitive squawk/safety-report ownership map requires additional targeted inspection.
- Master Logbook recommendation: model them as associated operational events/references, not columns inside Garmin/CVR/FlightCircle source tables.

### Does a True Canonical Training-Event and Flight-Leg Model Already Exist?

Confirmed:

- A canonical-ish flight-leg model exists: `ipca_operational_flight_leg_versions`.
- A session/event model exists: `ipca_flight_sessions`.
- A source-neutral operation/dispatch model exists: `ipca_aircraft_operations`.

Not confirmed:

- There is no single integrated canonical training-event table that groups dispatch/session/legs/crew/mission/evidence across FlightCircle, Garmin, CVR, ADS-B, schedules, manual entries, squawks, and safety reports.
- There is no one Master Logbook page that uses these tables as a unified row-per-leg operational interface.

Conclusion:

- The repository has enough pieces to support an additive Master Logbook, but they are not unified. The safest path is a bridge/read model, not replacing ingestion tables.

## 3. Cockpit Recorder Upload Flow

### End-to-End Flow

1. iPhone app records audio and optional AHRS/GPS/G3X/beacon/events evidence.
2. App uploads either:
   - Simple multipart to `/api/recordings/upload.php`.
   - Chunked upload to `/api/recordings/upload_chunk.php` followed by `/api/recordings/upload_finalize.php`.
3. Server stores temporary chunks under `storage/cockpit_recorder/upload_sessions`.
4. Finalize endpoint assembles files, validates chunk completeness/size, then calls `CockpitRecorderService::storeAssembledRecording`.
5. `CockpitRecorderService` stores final files under:
   - `storage/cockpit_recorder/audio`.
   - `storage/cockpit_recorder/ahrs`.
   - `storage/cockpit_recorder/gps`.
   - `storage/cockpit_recorder/g3x`.
   - `storage/cockpit_recorder/beacon`.
   - `storage/cockpit_recorder/events`.
   These roots are defined at `src/CockpitRecorderService.php:25-58`.
6. DB record is inserted/updated in `ipca_cockpit_recordings` with `recording_uid`, times, file metadata, upload status, and transcription status at `src/CockpitRecorderService.php:209-279`.
7. Optional evidence files are stored by `storeLocalAHRS`, `storeLocalGPS`, `storeLocalG3X`, beacon, and events methods. G3X storage marks reconstruction stale at `src/CockpitRecorderService.php:138-153`.
8. Transcription can be triggered by admin/API. `CockpitRecorderService` updates `transcription_status`, progress, chunks, final `transcript_text`, and errors.
9. Reconstruction can be launched by admin/API. `CockpitReconstructionService::reconstruct` loads recording evidence, requires G3X for replay reconstruction, builds canonical samples, phases/events, replay samples, and updates status.
10. ADS-B enrichment may run later and attach ownship/traffic samples.
11. Replay launches from `/admin/cockpit_recorder_replay.php?id=<recording>` and reads the processed samples and recording/audio evidence.

### Upload Endpoints

- `/api/recordings/upload.php`: simple multipart upload. No endpoint-level bearer-device or admin auth check was found in the endpoint file.
- `/api/recordings/upload_chunk.php`: chunk upload/status. Uses recording id and file type headers; no endpoint-level bearer-device or admin auth check was found in the endpoint file.
- `/api/recordings/upload_finalize.php`: assemble and store chunks. No endpoint-level bearer-device or admin auth check was found in the endpoint file.
- `/api/cvr/*`: newer device-authenticated CVR device endpoints. `DeviceAuthService::requireDevice()` protects the device endpoints except enrollment.

Security note:

- The legacy `/api/recordings/*` upload flow should be treated as an existing compatibility boundary. Any later hardening must be a separate compatibility project with iOS/CVR client testing, not part of the initial Master Logbook redesign.

### Local and Server Identifiers

- `recording_uid`: normalized from app `recording_id`, max 96 safe chars at `src/CockpitRecorderService.php:180-183`.
- DB `ipca_cockpit_recordings.id`: internal numeric primary key.
- `flight_session_uid`: optional metadata on finalize at `public/api/recordings/upload_finalize.php:163`.
- `flight_segment_index`, `previous_segment_uid`: optional segment metadata at `public/api/recordings/upload_finalize.php:164-166`.

### Chunk and Package Handling

- Allowed file types: `audio`, `ahrs`, `gps`, `g3x`, `beacon`, `events` at `public/api/recordings/upload_chunk.php:32-36`.
- GET chunk endpoint returns received chunk indices and total metadata at `public/api/recordings/upload_chunk.php:81-110`.
- POST validates chunk index, total chunks, total size, per-chunk max 8 MB, optional chunk-size match, and duplicate already-present chunks at `public/api/recordings/upload_chunk.php:117-176`.
- Finalize assembles chunks in order and validates assembled size at `public/api/recordings/upload_finalize.php:37-89`.

### Background Jobs and Workers

- Transcription can spawn `scripts/run_cockpit_recorder_transcription.php` from `CockpitRecorderService` at `src/CockpitRecorderService.php:857-870`.
- Reconstruction jobs use `ipca_cockpit_reconstruction_jobs`; the status API and admin polling read these.
- Async generic worker `scripts/run_async_jobs.php` is mostly Garmin/flight-record focused and does not directly run cockpit reconstruction in the inspected file.

### Transcript Creation and Enhancement

- Raw transcription uses OpenAI audio transcription endpoint in `CockpitRecorderService` at `src/CockpitRecorderService.php:1997-2029`.
- Chunked transcription is supported by `ipca_cockpit_recording_transcription_chunks`; chunk processing and final assembly references are at `src/CockpitRecorderService.php:693-833` and `src/CockpitRecorderService.php:2044-2095`.
- Transcript enhancement actions were not fully traced in this audit. `public/admin/api/cockpit_recorder_transcribe.php` is the confirmed transcript-generation endpoint.

### Aircraft, Training-Event, and Replay Association

- Aircraft snapshot columns were added to `ipca_cockpit_recordings` by `scripts/sql/2026_06_20_cockpit_recorder_aircraft_devices.sql:21-99`.
- Normalized session association uses `ipca_flight_sessions` and `ipca_flight_session_segments`, but not every recording necessarily has a complete operational flight record.
- Replay association is currently by `recording_id`/`recording_uid` and processed samples, not by future Master Logbook IDs.

### Error and Retry Behavior

- Chunk uploads are idempotent for already-present chunks of matching size at `public/api/recordings/upload_chunk.php:166-176`.
- Missing chunk or size mismatch aborts finalization at `public/api/recordings/upload_finalize.php:66-86`.
- Transcription errors update status/error fields.
- Async jobs use retry/dead-letter behavior in `AsyncJobService::fail` at `src/AsyncJobService.php:145-168`.

### Protected Files and Functions for Recorder Flow

- `public/api/recordings/upload.php`.
- `public/api/recordings/upload_chunk.php`.
- `public/api/recordings/upload_finalize.php`.
- `public/api/recordings/g3x_finalize.php`.
- `public/api/recordings/status.php`.
- `public/api/recordings/transcript.php`.
- `public/api/recordings/replay.php`.
- `public/api/cvr/*`.
- `src/CockpitRecorderService.php`.
- `src/CockpitReconstructionService.php`.
- `src/CockpitReplayPipeline.php`.
- `src/CockpitAdsbEnrichmentService.php`.
- `public/admin/cockpit_recorder.php`.
- `public/admin/cockpit_recorder_replay.php`.

## 4. Garmin Synchronization Flow

### macOS App Files

- `ipca-sync-agent-macos/IPCASyncAgent/Services/BrowserServices.swift`: Chrome profile, FlyGarmin browser session, cookie/header extraction, DevTools target management.
- `ipca-sync-agent-macos/IPCASyncAgent/Services/GarminProvider.swift`: FlyGarmin routes, logbook discovery, source download, track JSON download, diagnostics.
- `ipca-sync-agent-macos/IPCASyncAgent/Services/SyncServices.swift`: sync orchestration and server upload queue.
- `ipca-sync-agent-macos/IPCASyncAgent/Services/LocalStores.swift`: local SQLite queue and Garmin backfill tracking.
- `ipca-sync-agent-macos/IPCASyncAgent/Models/SyncModels.swift`: sync item/artifact models.
- `ipca-sync-agent-macos/IPCASyncAgent/Views/ContentView.swift`: UI/status.

### FlyGarmin Endpoints Used

Confirmed in `GarminProvider.swift`:

- Base logbook route: `https://fly.garmin.com/fly-garmin/api/logbook/`.
- Track JSON route: `/fly-garmin/api/logbook/tracks/<trackUUID>/` at `ipca-sync-agent-macos/IPCASyncAgent/Services/GarminProvider.swift:71`.
- Original flight data route: `/fly-garmin/api/logbook/v1/flight-data/<flightDataLogUUID>/` at `ipca-sync-agent-macos/IPCASyncAgent/Services/GarminProvider.swift:78-82`.

### Authentication and Session Handling

- The macOS app uses a visible human-operated Chrome/FlyGarmin login and a dedicated Chrome profile.
- Browser DevTools/cookie logic appears in `BrowserServices.swift`, including FlyGarmin host/path filtering at `ipca-sync-agent-macos/IPCASyncAgent/Services/BrowserServices.swift:323-425`.
- Server auth uses sync-agent tokens:
  - `/api/sync-agent/garmin_entries.php` requires scope `sync_agent.garmin_entries`.
  - `/api/sync-agent/garmin_source.php` requires scope `sync_agent.garmin_source`.
- HTTPS is enforced by sync-agent bootstrap except localhost at `public/api/sync-agent/_bootstrap.php:22-25`.

### Download and Duplicate Prevention

- macOS app downloads:
  - original source CSV/data logs.
  - normalized track JSON.
- Local SQLite tracks upload queue and backfill state in `LocalStores.swift`, including `garmin_backfill_tracks` and `garmin_backfill_runs` at `ipca-sync-agent-macos/IPCASyncAgent/Services/LocalStores.swift:566-577`.
- Server duplicate prevention:
  - idempotency key acknowledgement in `SyncAgentGarminIngestionService::ingestSource` at `src/SyncAgentGarminIngestionService.php:52-56`.
  - SHA-256 validation at `src/SyncAgentGarminIngestionService.php:63-73` and `src/SyncAgentGarminIngestionService.php:145-155`.
  - `ipca_sync_agent_upload_acknowledgments`.
  - unique source/hash indexes on `ipca_garmin_csv_files`.

### Original Evidence Preservation

- `SyncAgentGarminIngestionService::storeImmutable` stores original source bytes before creating CSV metadata at `src/SyncAgentGarminIngestionService.php:91-94`.
- Normalized track JSON is stored by `storeImmutableTrack` and then inserted into `ipca_garmin_normalized_track_artifacts` at `src/SyncAgentGarminIngestionService.php:187-221`.

### Server Ingestion and Processing

1. `garmin_entries.php` accepts logbook entries and calls `upsertEntries`.
2. `garmin_source.php` accepts either original source or `GARMIN_TRACK_NORMALIZED_JSON`.
3. Original source ingestion:
   - validates base64/SHA.
   - stores immutable source.
   - classifies CSV.
   - ensures `ipca_garmin_csv_files`.
   - validates CSV.
   - selects source group.
   - enqueues jobs.
   - derives CSV summary immediately.
   - records upload acknowledgment.
4. Normalized track ingestion:
   - validates JSON.
   - stores immutable track.
   - inserts/updates `ipca_garmin_normalized_track_artifacts`.
   - enqueues `GARMIN_TRACK_FLIGHT_SUMMARY`.
5. Async worker `scripts/run_async_jobs.php` handles:
   - `GARMIN_SOURCE_GROUP_MATCH`.
   - `GARMIN_SOURCE_ROLE_SELECTION`.
   - `GARMIN_HISTORICAL_FILE_PROCESS`.
   - `GARMIN_TRACK_FLIGHT_SUMMARY`.
   - `GARMIN_CSV_SESSION_MATCH`.
   - `GARMIN_CSV_FLIGHT_SUMMARY`.
   - `FLIGHT_RECORD_DERIVATION`.
   See `scripts/run_async_jobs.php:58-127`.

### CSV Parsing, Hobbs/Tacho, Flight Detection, Track Generation

- Parser: `G3XFlightStreamParser`.
- Hobbs: `HobbsCalculationService`.
- Tacho: `TachoCalculationService`.
- Airport detection: `AirportDetectionService`.
- Flight event detection: `FlightEventDetectionService`.
- Derivation path: `FlightRecordDerivationService::previewFromCsvFile` parses CSV and computes Hobbs/Tacho/airport/events/legs/day-night/cross-country at `src/FlightRecordDerivationService.php:26-144`.
- Flight summary path: `GarminCsvFlightSummaryService`.
- Track summary path: `GarminTrackFlightSummaryService`.

### Replay Preparation

- Cockpit Replay from recorder uses `CockpitReconstructionService` and `CockpitReplayPipeline`.
- Standalone Garmin CSV replay uses `GarminCsvReplayPayloadService` and `ipca_garmin_csv_replay_payloads`; launch from Garmin page uses `/admin/cockpit_recorder_replay.php?standalone=<key>` at `public/admin/flight_log_garmin_connection.php:1756`.

### Protected Garmin Components

- macOS app files under `ipca-sync-agent-macos/IPCASyncAgent`.
- `public/api/sync-agent/_bootstrap.php`.
- `public/api/sync-agent/garmin_entries.php`.
- `public/api/sync-agent/garmin_source.php`.
- `src/SyncAgentGarminIngestionService.php`.
- `src/GarminFlightDataSourceService.php`.
- `src/GarminCsvValidationService.php`.
- `src/GarminCsvFlightSummaryService.php`.
- `src/GarminTrackFlightSummaryService.php`.
- `src/GarminCsvReplayPayloadService.php`.
- `src/G3XFlightStreamParser.php`.
- `src/HobbsCalculationService.php`.
- `src/TachoCalculationService.php`.
- `src/AirportDetectionService.php`.
- `src/FlightEventDetectionService.php`.
- `scripts/run_async_jobs.php`.

## 5. Cockpit Replay Dependency Map

### Replay Ingestion Dependencies

- Raw recorder evidence:
  - `ipca_cockpit_recordings`.
  - audio file path under `storage/cockpit_recorder/audio`.
  - optional AHRS/GPS/G3X/beacon/events storage paths.
- Reconstruction:
  - `CockpitReconstructionService`.
  - `CockpitReplayPipeline`.
  - `G3XFlightStreamParser`.
  - `ipca_cockpit_reconstruction_jobs`.
  - `ipca_cockpit_flight_samples`.
  - `ipca_cockpit_flight_phases`.
  - `ipca_cockpit_timeline_events`.
  - `ipca_cockpit_replay_samples`.
- ADS-B:
  - `CockpitAdsbEnrichmentService`.
  - `ipca_cockpit_adsb_enrichments`.
  - `ipca_cockpit_adsb_ownship_samples`.
  - `ipca_cockpit_adsb_traffic_samples`.
  - `ipca_cockpit_adsb_traffic_aircraft_samples`.

### Replay Launch Dependencies

- Entry page: `public/admin/cockpit_recorder_replay.php`.
- Routes:
  - `/admin/cockpit_recorder_replay.php?id=<recording id or uid>`.
  - `/admin/cockpit_recorder_replay.php?standalone=<garmin replay key>`.
- Recording lookup: `CockpitRecorderService::recordingByAnyId` called at `public/admin/cockpit_recorder_replay.php:138-143`.
- Aircraft/instrument profile lookup: `ipca_aircraft_instrument_profiles` query at `public/admin/cockpit_recorder_replay.php:150-152`.
- Standalone launch: `ipca_garmin_csv_replay_payloads` through Garmin replay payload service/API.

### Replay Rendering Dependencies

- Player file: `public/admin/cockpit_recorder_replay.php`.
- Frontend instrument/settings functions begin around `public/admin/cockpit_recorder_replay.php:2507` and continue through extensive JS rendering logic.
- Required data:
  - replay samples.
  - audio path.
  - aircraft model/profile.
  - alert catalog/trim/profile settings where present.
  - ADS-B traffic samples for traffic overlay.
- Cesium rendering dependencies:
  - player JS and Cesium assets loaded by replay page.
  - traffic APIs such as `/api/flight_records/traffic.php`.
  - track/replay APIs for sample payloads.
- Camera/interpolation/attitude/audio sync:
  - `CockpitReplayPipeline` creates fixed-rate G3X-only replay samples at `src/CockpitReplayPipeline.php:56-96`.
  - Rendering uses player JS state, camera settings, instrument update functions, and audio element timing.

### Untouched During Admin Redesign

These can remain completely untouched while building Master Logbook:

- `public/admin/cockpit_recorder_replay.php`.
- `src/CockpitReplayPipeline.php`.
- `src/CockpitReconstructionService.php` except read-only adapter calls.
- `ipca_cockpit_replay_samples` schema/data.
- `ipca_cockpit_recordings` IDs and storage paths.
- `/admin/api/cockpit_recorder_*` reconstruction and ADS-B APIs.
- `/api/recordings/*` app APIs.
- `/api/flight_records/traffic.php`.

## 6. FlightCircle Import and Historical Matching

### Import Implementation

- Page: FlightCircle panel in `/admin/flight_log_garmin_connection.php`.
- Upload endpoint: `/admin/api/flightcircle_historical_import.php`.
- Service: `FlightCircleHistoricalImportService`.
- Upload call: `importUploadedFile($tmpName, $filename, userId, replaceActiveDataset)` at `public/admin/api/flightcircle_historical_import.php:35`.
- Batch table: `ipca_flightcircle_import_batches`.
- Raw file table: `ipca_flightcircle_raw_files`.
- Raw row table: `ipca_flightcircle_raw_rows`.
- Staging table: `ipca_flightcircle_staging_records`.

### Imported Fields

`FlightCircleHistoricalImportService::normalizeRow` maps:

- Tail/resource identifier.
- User.
- Instructor.
- First/middle/last name.
- Reservation type.
- Rules.
- Route.
- Depart/return/check-in local datetimes.
- Hours.
- Hobbs out/in/total.
- Tach out/in/total.
- TTAF out/in/total.
- Fuel remaining/added.
- Notes.
- Informational warnings for mission codes and route.

See `src/FlightCircleHistoricalImportService.php:462-500`.

### Resource Classification

- Aircraft/simulator/ignored resources are classified in `FlightCircleHistoricalImportService`.
- Known authoritative tails and simulator handling were added during prior work.
- Active dataset validation/tail counts are handled by service status methods.

### Promotion to Source-Neutral Candidates

For each operation candidate:

- `ipca_aircraft_operations` candidate is created at `src/FlightCircleHistoricalImportService.php:574-620`.
- `ipca_meter_readings` are created at `src/FlightCircleHistoricalImportService.php:626-654`.
- Fuel facts are created at `src/FlightCircleHistoricalImportService.php:660-681`.
- Crew assignments are created at `src/FlightCircleHistoricalImportService.php:687-714`.
- Logbook proposals are created by FlightCircle import methods beginning at `src/FlightCircleHistoricalImportService.php:720`.

### Crew Matching

- Source names are preserved in `source_person_text` and `source_role_text`.
- Existing users are matched by name via service helper.
- Unmatched names create `ipca_flightcircle_user_mappings` suggestions.
- Instructor user creation maps to `supervisor` in the current app user role model.

### Garmin Association Logic

There are two matching directions:

- Legacy/batch FlightCircle-to-Garmin: `matchBatch` scans FlightCircle staging records and searches `ipca_garmin_historical_segments` by tail, then scores Hobbs/Tacho compatibility at `src/FlightCircleGarminMatchService.php:383-519`.
- Current selected Garmin-to-FlightCircle enrichment: `matchSelectedGarminBackfillFiles` and `matchSelectedGarminCsvFiles` turn selected Garmin rows into segments and search active FlightCircle rows by tail and Hobbs-Out at `src/FlightCircleGarminMatchService.php:53-174`.

### Hobbs/Tacho Matching Details

- Current Garmin-centric matching:
  - Requires Garmin Hobbs-Out.
  - Searches active FlightCircle batches.
  - Uses normalized tail candidates/aliases.
  - Accepts Hobbs-Out within 0.30 and range containment when Garmin leg falls inside a FlightCircle operation at `src/FlightCircleGarminMatchService.php:252-310`.
  - Scores same tail plus Hobbs/range evidence at `src/FlightCircleGarminMatchService.php:303-307`.
- Date/time are not used as required evidence. `score` explicitly records `flightcircle_date_time_used_for_matching` as false at `src/FlightCircleGarminMatchService.php:512-517`.

### Duplicate Detection

- FlightCircle import batches use file SHA and raw row identity/source hashes.
- Garmin CSV files use SHA/source identifiers and supersession links.
- Historical Garmin files preserve duplicates with `exact_duplicate_status` and `semantic_duplicate_status`.
- Matches use `ON DUPLICATE KEY UPDATE` in `FlightCircleGarminMatchService::storeGarminOutcome` at `src/FlightCircleGarminMatchService.php:318-361`.

### Gap and Conflict Handling

- FlightCircle import records meter readings with `continuity_status = 'unchecked'`.
- `ipca_migration_cutovers` exists for formal cutover checks but no complete active workflow was found.
- Matching writes `conflict_json` in `ipca_flightcircle_garmin_matches`.
- Manual resolution tools are partial: identity mapping and Garmin match statuses exist; a full ledger reconciliation UI was not confirmed.

### Why Hobbs/Tacho Matching Did Not Work Reliably

Confirmed causes from code and prior observed behavior:

- The earlier flow often started with FlightCircle rows and searched only imported Garmin historical segments. If Garmin imports were missing, duplicates were hidden, aircraft misclassified, or rows came from Sync Agent CSV summaries instead of historical segments, FlightCircle rows had no candidate.
- The `candidateSegments` method still has a hard `LIMIT 1000` in the legacy FlightCircle-to-Garmin path at `src/FlightCircleGarminMatchService.php:449-455`, despite selected Garmin-to-FlightCircle matching being broader.
- Tail normalization and authoritative tail classification were added later. Misclassification such as `N397EA` being non-aircraft would exclude it from older FlightCircle candidate scans.
- Date/time were originally considered and FlightCircle time/date can be unreliable; current code removed date as a required match key.
- Garmin Hobbs values may represent a leg inside a larger FlightCircle dispatch. The current range-containment logic tries to address this, but it is applied in Garmin-centric selected matching, not necessarily as a complete FlightCircle-ledger reconciliation.
- Pure one-to-one matching fails when one FlightCircle dispatch contains multiple Garmin legs, when a return leg lacks a matching row, or when duplicate Garmin sources represent the same operation.

### Directionality Assessment

Confirmed:

- Current selected enrichment is Garmin-centric: `matchSelectedGarminBackfillFiles` and `matchSelectedGarminCsvFiles` iterate selected Garmin records and search FlightCircle candidates.
- The desired historical approach should be FlightCircle-ledger-centric: every valid FlightCircle aircraft operation becomes the historical dispatch ledger, and Garmin evidence is attached to operations/legs where available.

Conclusion:

- The current implementation partially violates the desired architecture by treating selected Garmin rows as the starting point for enrichment. This is acceptable as a temporary UI action, but not as the canonical historical migration algorithm.

## 7. Current Source-of-Truth Analysis

| Field | Current Storage | Current Authority | Competing Values | Behavior / Audit |
|---|---|---|---|---|
| Date | `ipca_flight_sessions.avionics_on_utc`; `ipca_operational_flight_leg_versions.*_utc`; `ipca_flightcircle_staging_records.depart_local`; `ipca_admin_logbook_entries.entry_date` | No single authority | Garmin UTC, FlightCircle local, manual logbook | Calculated/imported/manual; audit via version tables/logbook audit varies |
| Tail number | `ipca_aircraft_devices.registration`; denormalized `aircraft_registration` on many tables | `ipca_aircraft_devices` where mapped | Garmin aircraft ident, FlightCircle tail, recorder aircraft snapshot | Imported and normalized; changes partly audited |
| Pilot 1 | `ipca_crew_assignments`, `ipca_flight_crew_member_versions`, `ipca_admin_logbook_entries`, FlightCircle user text | No single authority | FlightCircle User, manual logbook, future schedule | Mapping review required; not fully audited in one place |
| Pilot 1 role | `ipca_crew_assignments.resolved_role`, `ipca_flight_crew_member_versions.logging_functions_json`, logbook fields | No single authority | User may be student/renter/PIC/solo/dual | Inferred/imported/manual; role review required |
| Pilot 2 | Same as Pilot 1; FlightCircle Instructor text | No single authority | Instructor/user mapping/manual | Mapping review required |
| Pilot 2 role | Same as Pilot 1 role | No single authority | Instructor may not always be PIC | Inferred/manual; review needed |
| Departure airport | `ipca_operational_flight_leg_versions.departure_airport_code`; Garmin summaries; FlightCircle route text; logbook entry | Garmin-derived when valid | FlightCircle route informational, manual corrections | Calculated/imported; manual correction support exists via version/manual input tables |
| Arrival airport | Same as departure | Garmin-derived when valid | FlightCircle route informational | Same |
| Departure time | `allocation_start_utc`, `takeoff_utc`, `administrative_departure_utc`; FlightCircle local | Garmin/reconstruction for actual, FlightCircle for scheduled/historical dispatch | Timezone/local mismatch | Calculated/imported; audit via versions |
| Arrival time | Same as departure | Garmin/reconstruction for actual | FlightCircle local | Same |
| Hobbs out | `ipca_meter_readings.reading_out`; `ipca_operational_flight_record_versions.hobbs_start_hours`; Garmin summary JSON | Historical: FlightCircle verified; current: independent/Garmin/manual | Garmin vs FlightCircle vs manual | Versioned/calculated/imported; continuity not fully unified |
| Hobbs in | Same as Hobbs out | Same | Same | Same |
| Tacho out | `ipca_meter_readings`; `ipca_operational_flight_record_versions.tacho_start_hours` | Same | Same | Same |
| Tacho in | Same as Tacho out | Same | Same | Same |
| Fuel out | `ipca_fuel_transactions`; `ipca_operational_flight_record_versions.fuel_start_usg`; leg fuel fields | No single authority | Garmin fuel quantity, FlightCircle fuel remaining/added, manual | Imported/calculated; needs review |
| Fuel in | Same as fuel out | No single authority | Same | Same |
| Landings | `ipca_operational_flight_record_versions.landing_event_count`; `ipca_operational_flight_leg_versions.landing_event_count`; logbook entry | Garmin/reconstruction when valid | Manual logbook | Calculated; manual override possible |
| Day/night | `ipca_operational_flight_leg_versions.night_duration_ms`; logbook fields | Calculated from leg/time/location when valid | Manual logbook | Calculated; proposal review |
| IFR/VFR | FlightCircle `rules_text`; logbook fields | No reliable authority | FlightCircle rules, manual | Imported informational unless verified |
| Actual instrument | logbook entries/proposals | Manual/review | No Garmin direct authority confirmed | Review required |
| Simulated instrument | logbook entries/proposals | Manual/review | Training context | Review required |
| Basic instrument | logbook entries/proposals | Manual/review | Training context | Review required |
| Cross-country | `ipca_operational_flight_leg_versions.cross_country_*`; logbook entries | Calculated proposal then reviewed | Manual | Calculated/imported; proposal review |
| Single/multi engine | aircraft type/logbook entries | Aircraft registry/manual | Garmin source does not decide category | Manual/config-driven |
| Dual | logbook entries/proposals; crew roles | Review/manual | FlightCircle Instructor presence not enough | Needs rules and review |
| PIC | logbook entries/proposals; crew roles | Review/manual | User field not always PIC | Needs explicit verification |
| Solo | logbook entries/proposals | Review/manual | Instructor absence not enough | Needs explicit verification |
| Mission code | `ipca_missions`, `ipca_flight_mission_assignments`, FlightCircle notes | Mission catalog/manual | FlightCircle notes informational | Should not be blindly inferred |
| Pre/post briefing | training forms/logbook/mission records | Training workflow/manual | FlightCircle resources Classroom ignored | Not unified |
| Squawks | not fully traced | Unknown | Possible maintenance/safety modules | Open question |
| Remarks | FlightCircle notes, logbook remarks, debriefs | Manual/final logbook | Source notes informational | Audit depends table |
| Safety reports | compliance/safety modules likely | Unknown | Not fully traced | Open question |
| Garmin/FDM availability | `ipca_garmin_csv_files`, summaries, evidence links | Garmin evidence tables | duplicates/source groups | Source truth for availability |
| CVR availability | `ipca_cockpit_recordings`, `ipca_audio_evidence_links` | Recorder evidence | session links | Source truth for availability |
| ADS-B availability | `ipca_cockpit_adsb_enrichments`, ADS-B archive links | ADS-B enrichment tables | archive/replay samples | Source truth for availability |
| Replay availability | `ipca_cockpit_replay_samples`, `ipca_garmin_csv_replay_payloads`, recording statuses | Replay processing tables | recorder replay vs standalone Garmin replay | Source truth for launchability |
| Verification status | scattered: operation review, version readiness, proposal status, validation results | No single authority | multiple status vocabularies | Needs canonical status model |
| Finalization status | `ipca_operational_flight_record_versions.status/finalized_at`, `ipca_admin_logbook_entries.review_status`, cutover status | No single authority | historical operation review vs flight record finalization | Needs unified audit/finalization |

## 8. Risk Analysis

Critical:

- Changing or renaming `ipca_cockpit_recordings.id` or `recording_uid` would break recorder lookup, transcript lookup, replay launch, storage path association, and reconstruction.
- Changing `ipca_cockpit_replay_samples` structure or semantics would break Cockpit Replay rendering and ADS-B overlays.
- Changing `/api/recordings/upload_chunk.php` or `/api/recordings/upload_finalize.php` contracts would break iPhone uploads and retry behavior.
- Changing `/api/sync-agent/garmin_source.php` or sync-agent token scopes would break Garmin synchronization.
- Changing `ipca_garmin_csv_files.id`, `sha256`, storage path semantics, or source UUID mappings would break duplicate prevention, summaries, flight derivation, and replay payloads.
- Changing replay URLs (`/admin/cockpit_recorder_replay.php?id=...` or `?standalone=...`) would break launch links and existing records.
- Reprocessing old files into finalized records without guards could overwrite reviewed historical/logbook data.

High:

- Reusing existing status fields for new Master Logbook states would confuse processing state with verification/finalization state.
- Reusing `ipca_flight_sessions` as the only canonical event would lose FlightCircle dispatch semantics for multi-leg operations.
- Reusing `ipca_aircraft_operations` as the only canonical leg model would lose existing Garmin/CVR flight-record version logic.
- Cascading deletes from source evidence tables could remove derived operational versions and proposal links.
- Changing Garmin association logic could detach CSVs from sessions or source groups.
- Changing transcript ownership or moving `transcript_text` could break transcript display and enhancement.
- Changing API response shapes for status/polling endpoints could break existing JavaScript selectors.
- Changing modal IDs/lazy-loading behavior on `flight_log_garmin_connection.php` could reintroduce page-load or blank-page issues.
- Timezone conversion changes could corrupt local departure/arrival display and FlightCircle comparisons.

Medium:

- Adding Master Logbook filters/pagination without preserving URL state could repeat existing UI confusion.
- Treating zero-Hobbs Garmin rows as deleted instead of hidden would lose audit evidence.
- Showing FlightCircle rows as operational flights in the primary UI would perpetuate source-log confusion.
- Duplicate UI components could cause admins to act on source rows instead of canonical legs.
- Bulk actions on mixed historical and Sync Agent rows can create inconsistent matches if IDs are not separated.
- Permission checks may diverge between old pages and the new Master Logbook.

Low:

- Read-only adapters over protected services are low risk if they do not write or alter response expectations.
- Secondary tabs that link to existing pages are low risk.
- Documentation-only route labels are low risk.

## 9. Protected Components List

| Component | Why Protected | Depends On It | Adapter Allowed? | Callable from Master Logbook? | Initial Touch? |
|---|---|---|---|---|---|
| `public/api/recordings/upload.php` | Simple recorder upload compatibility | iPhone/app uploads | Read-only no; write endpoint should not be wrapped initially | No | Untouched |
| `public/api/recordings/upload_chunk.php` | Resumable chunk protocol | iPhone uploads/retry | No | No | Untouched |
| `public/api/recordings/upload_finalize.php` | Package assembly and DB creation | iPhone uploads | No | No | Untouched |
| `public/api/recordings/g3x_finalize.php` | Supplemental G3X evidence | recorder/G3X workflow | No | No | Untouched |
| `public/api/cvr/*` | Device enrollment/auth/CSV flow | CVR app devices | Read-only status adapter only | Status only | Untouched |
| `src/CockpitRecorderService.php` | Audio/evidence storage, transcript state | recorder admin, APIs, replay | Yes, read-only facade for availability/status | Yes for evidence availability | Untouched internally |
| `src/CockpitReconstructionService.php` | Builds replay samples/phases/events | replay/admin reconstruction | Yes, read-only status queries | Yes for availability/status | Untouched |
| `src/CockpitReplayPipeline.php` | Fixed-rate replay timeline | reconstruction/replay | No direct adapter needed | No | Untouched |
| `public/admin/cockpit_recorder_replay.php` | Actual player | all replay launches | Link only | Yes, Launch Replay URL | Untouched |
| `public/admin/api/cockpit_recorder_*` | reconstruction/status/enrichment APIs | cockpit admin UI | Status read only | maybe status | Untouched |
| `src/CockpitAdsbEnrichmentService.php` | ADS-B enrichment | replay traffic | Read-only availability adapter | Yes | Untouched |
| `src/LocalTrafficArchiveRepository.php` | local ADS-B/archive data | ADS-B replay enrichment | Read-only adapter | Yes | Untouched |
| `public/api/sync-agent/*` | Garmin Sync Agent API | macOS app | No | No | Untouched |
| `src/SyncAgentGarminIngestionService.php` | Garmin server ingestion | sync agent | Read-only no; ingestion no | No | Untouched |
| `ipca-sync-agent-macos/**` | working macOS sync app | Garmin sync | No | No | Untouched |
| `src/G3XFlightStreamParser.php` | CSV parsing | summaries, records, replay | No | No | Untouched |
| `src/GarminCsvFlightSummaryService.php` | derived Garmin summaries | Garmin page/matching | Read-only query adapter | Yes for evidence summary | Untouched |
| `src/GarminTrackFlightSummaryService.php` | normalized track summaries | Garmin page | Read-only query adapter | Yes | Untouched |
| `src/HobbsCalculationService.php` | Hobbs extraction | flight derivation | No initially | Indirect only | Untouched |
| `src/TachoCalculationService.php` | Tacho extraction | flight derivation | No initially | Indirect only | Untouched |
| `src/FlightRecordDerivationService.php` | creates operational record versions | flight records jobs/API | Read-only preview only | Later, controlled | Untouched initially |
| `scripts/run_async_jobs.php` | async Garmin/record derivation worker | queued processing | No | No | Untouched |
| `src/FlightCircleHistoricalImportService.php` | FlightCircle importer | source migration | Read-only status adapter | Source tab only | Untouched initially |
| `src/FlightCircleGarminMatchService.php` | current match outcomes | enrichment UI | Read-only match status adapter | Evidence status only | Untouched initially |
| `public/admin/flight_log_garmin_connection.php` | current source-management wall | Garmin/FlightCircle admin | Link as secondary tab | Not as primary | Avoid large edits initially |

## 10. Proposed Safe Architecture

The safest architecture is additive and compatible with the existing implementation.

Core principles:

- Keep raw ingestion systems intact.
- Keep replay processing intact.
- Keep original source records intact.
- Use existing operational flight-record version tables where they safely represent a flight leg.
- Use existing FlightCircle `ipca_aircraft_operations`/`ipca_meter_readings` as historical dispatch ledger candidates, but do not make them the only leg model.
- Add a small canonical bridge/read model only where existing tables cannot safely represent training events, evidence associations, and final Master Logbook rows.
- Build Master Logbook as a read/verification interface over canonical/bridge records.
- Move source-management UI into secondary tabs without deleting existing pages.

Compatibility:

- Compatible if implemented as new tables/adapters/pages.
- Not compatible if implemented by renaming or repurposing `ipca_cockpit_recordings`, `ipca_garmin_csv_files`, `ipca_flight_sessions`, or replay sample tables.

Recommended target layering:

1. Source evidence layer:
   - Cockpit Recorder/CVR.
   - Garmin CSV/files/tracks.
   - FlightCircle raw/staging.
   - ADS-B archive.
   - schedules/manual/training forms.
2. Evidence processing layer:
   - summaries, replay samples, transcripts, ADS-B enrichment, matches.
3. Canonical operation/event layer:
   - training event/dispatch session.
   - flight legs.
   - crew/mission/fuel/meter/squawk/safety references.
4. Verification/finalization layer:
   - review states, amendments, audit events.
5. Master Logbook UI:
   - one row per canonical leg, grouped by event.

## 11. Proposed Canonical Data Model

Do not apply migrations yet. The following is the smallest safe model after further validation.

### Reuse Where Possible

- Reuse `ipca_operational_flight_records` and `ipca_operational_flight_leg_versions` for Garmin/CVR-derived legs.
- Reuse `ipca_aircraft_operations` for FlightCircle historical dispatch/session candidates.
- Reuse `ipca_meter_readings`, `ipca_fuel_transactions`, `ipca_crew_assignments`, and `ipca_aircraft_operation_evidence_links` for historical ledger facts and evidence.
- Reuse `ipca_admin_logbook_entries` for official logbook entries.
- Reuse `ipca_flight_record_logbook_proposals` for proposed logbook entries derived from operational flight records, but expand/bridge for FlightCircle historical operations.

### Likely New Tables

#### `ipca_master_training_events`

- Needed because no single training-event/dispatch grouping table currently unifies FlightCircle dispatches, CVR sessions, Garmin legs, schedules, manual events, and training forms.
- Existing reuse: `ipca_aircraft_operations` can represent dispatch, but it is currently migration-biased and does not safely own all current/future event grouping without compatibility work.
- Migration risk: low if additive.
- Backfill: create from `ipca_aircraft_operations` for FlightCircle historical records and from `ipca_flight_sessions`/current records for Garmin/CVR records.
- Replay compatibility: no impact; stores links only.

#### `ipca_master_flight_legs`

- Needed if `ipca_operational_flight_leg_versions` cannot be used as the canonical current row because it is version-specific and tied to `ipca_operational_flight_record_versions`.
- Existing reuse option: create a view/read model selecting current `ipca_operational_flight_leg_versions` plus FlightCircle-only synthetic legs. Prefer read model first.
- Migration risk: medium if new finalized leg IDs are introduced; low if read model.
- Backfill: derive from current flight-record leg versions and FlightCircle operations.
- Replay compatibility: no impact if it stores launch associations only.

#### `ipca_master_evidence_links`

- Needed to link master events/legs to:
  - `ipca_cockpit_recordings`.
  - `ipca_garmin_csv_files`.
  - `ipca_garmin_normalized_track_artifacts`.
  - `ipca_flightcircle_staging_records`.
  - ADS-B archive records.
  - transcripts.
  - manual/schedule records.
- Existing reuse: `ipca_aircraft_operation_evidence_links` exists for operations only; it may be expanded or mirrored.
- Migration risk: low if additive.
- Backfill: from existing FlightCircle links, Garmin matches, session CSV matches, recording/session relationships.

#### `ipca_master_verification_events`

- Needed for verification/finalization/amendment/merge/split/source reassociation audit if `ipca_audit_events` is too generic for UI queries.
- Existing reuse: `ipca_audit_events` already exists at `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql:5-29`; prefer reuse unless query/performance needs require a specialized table.
- Migration risk: low if using existing audit events.

### Fields Needed in Canonical Layer

- Training event: aircraft, date/time window, source mode, dispatch/session status, mission, schedule context, briefing info, verification/finalization status.
- Flight leg: event ID, leg index, departure/arrival time and airport, Hobbs/Tacho out/in/delta, landings, day/night, route/track availability, replay availability.
- Crew assignment: person, source text, role, logbook function, confidence, review status.
- Fuel values: departure/arrival/added/used, unit, source, review status.
- Evidence association: source system/table/id, relationship type, confidence, conflicts, availability flags.
- Verification/finalization: status, finalized by/at, correction/amendment reason, source precedence.
- Replay association: recording ID or standalone replay key, replay type, availability status.

## 12. Proposed Main Admin Interface

Primary page: `/admin/master_logbook.php` (new file; candidate only).

Primary table:

- One row per canonical/current leg.
- Grouped visually by training event/dispatch where multiple legs belong together.
- Columns:
  - Date.
  - Tail number.
  - Pilot 1 and role.
  - Pilot 2 and role.
  - Departure local time.
  - Departure airport.
  - Departure Hobbs.
  - Departure tacho.
  - Hobbs duration.
  - Tacho duration.
  - Arrival airport.
  - Arrival local time.
  - Arrival Hobbs.
  - Arrival tacho.
  - Landings.
  - Fuel departure.
  - Fuel arrival.
  - Mission code.
  - FDM / NO FDM.
  - CVR / NO CVR.
  - ADSB / NO ADSB.
  - REPLAY / NO REPLAY.
  - Verification/finalization status.
  - Flight Details action.

Flight Details modal:

- Event summary and all legs in event.
- Crew with source and resolved roles.
- Operational classifications.
- GPS map/track summary.
- Raw transcript.
- Enhanced transcript and action.
- Garmin/FDM evidence.
- CVR evidence.
- ADS-B evidence.
- FlightCircle evidence.
- Hobbs/Tacho evidence.
- Squawks, remarks, safety references.
- Verification history.
- Launch Replay action.

Secondary tabs:

- Garmin/FDM Files: link or embedded read-only view from existing Garmin page.
- CVR Recordings: link or embedded read-only view from existing recorder page.
- Bulk Imports: historical Garmin batches.
- FlightCircle Import: active dataset/source rows.
- Unresolved Records: unmatched Garmin, unmapped crew, meter gaps, ambiguous duplicate/source conflicts.
- Processing Activity: async jobs/reconstruction statuses if still necessary.

Do not initially:

- Replace `flight_log_garmin_connection.php`.
- Delete recorder/Garmin/FlightCircle pages.
- Change replay player route.
- Add write actions that alter finalized records.

## 13. Migration and Rollout Plan

### Phase 0: Current-State Audit and Database Backup

- Files affected: documentation only.
- Tables affected: none.
- APIs affected: none.
- Tests: none beyond syntax/document review.
- Rollback: delete audit doc.
- Acceptance: audit reviewed and approved.
- Untouched: all runtime components.

### Phase 1: Protected-Component Regression Tests

- Files likely affected: new tests only, e.g. `tests/master_logbook_protected_components_check.php`.
- Tables affected: none in production; test fixtures only.
- APIs affected: none.
- Tests required:
  - Recorder upload contract.
  - Garmin sync contract.
  - Replay launch contract.
  - FlightCircle import smoke.
  - Async job smoke.
- Rollback: remove tests.
- Acceptance: tests pass before any UI work.
- Untouched: ingestion/replay services.

### Phase 2: Canonical Read Model or Additive Tables

- Files likely affected:
  - new migration only if needed.
  - new read service, e.g. `src/MasterLogbookReadService.php`.
- Tables likely affected:
  - new bridge/read-model tables if approved.
  - no existing table changes in first pass.
- APIs affected: new read-only API only.
- Tests: compare row counts against source data; ensure no writes to protected tables.
- Rollback: ignore/drop new tables after backup.
- Acceptance: read model can list current operational legs and FlightCircle operation candidates.
- Untouched: recorder/Garmin/replay.

### Phase 3: Populate Canonical Records Without Modifying Source Records

- Files affected:
  - new backfill script/service.
- Tables affected:
  - new canonical/bridge tables only.
- APIs affected: none or admin-only dry-run endpoint.
- Tests: idempotency, no source-table mutation, duplicate handling.
- Rollback: truncate new bridge tables.
- Acceptance: every source row maps to zero/one/many explicit evidence links.
- Untouched: source ingestion.

### Phase 4: Master Logbook Read-Only Page

- Files affected:
  - `public/admin/master_logbook.php` (new).
  - optional `public/admin/api/master_logbook_rows.php` (new).
  - navigation addition only after approved.
- Tables affected: none.
- APIs affected: new read-only API.
- Tests: permissions, pagination, filters, performance, no mutations.
- Rollback: remove nav link/new files.
- Acceptance: admin sees one row per leg grouped by event.
- Untouched: old pages remain available.

### Phase 5: Verification and Finalization Workflow

- Files affected:
  - new verification APIs/services.
- Tables affected:
  - audit/verification tables or existing `ipca_audit_events`.
- APIs affected: new Master Logbook write APIs.
- Tests: audit entries, finalized record lock, amendment process.
- Rollback: disable write buttons; preserve audit.
- Acceptance: finalized records cannot be silently changed.
- Untouched: ingestion processing still writes source records only.

### Phase 6: Manual Event Creation

- Files affected: Master Logbook service/UI.
- Tables affected: canonical event/leg/evidence/audit tables.
- APIs affected: new manual-create endpoints.
- Tests: manual event creates same canonical/evidence structure.
- Rollback: mark manual records void through audit, not delete.
- Acceptance: manual events appear in same table and do not break replay.
- Untouched: protected source ingestion.

### Phase 7: Deterministic FlightCircle Backfill and Hobbs Chain

- Files affected:
  - new FlightCircle-ledger reconciliation service.
  - possibly read-only adapters around existing FlightCircle service.
- Tables affected:
  - canonical/bridge associations, meter continuity review records.
- APIs affected: new review endpoints.
- Tests: Hobbs/Tach continuity, duplicate handling, multi-leg dispatch containment.
- Rollback: clear new reconciliation outputs.
- Acceptance: FlightCircle is ledger-first; Garmin attaches as evidence.
- Untouched: existing `FlightCircleHistoricalImportService` until replacement is approved.

### Phase 8: Move Source Management Into Secondary Tabs

- Files affected:
  - Master Logbook page/tab links.
  - possibly small wrapper pages.
- Tables affected: none.
- APIs affected: none or read-only.
- Tests: old pages still load.
- Rollback: restore nav order.
- Acceptance: primary admin workflow starts from Master Logbook, not source files.
- Untouched: old source pages.

### Phase 9: Validate With Real Flights and Historical Records

- Files affected: tests/reports only.
- Tables affected: canonical/bridge only.
- APIs affected: none.
- Tests: real aircraft/tail/date ranges, replay launch, logbook proposals.
- Rollback: do not finalize questionable records.
- Acceptance: signed-off sample per aircraft.

### Phase 10: Deprecate Redundant Interfaces After Written Approval

- Files affected: navigation/redirects/documentation.
- Tables affected: none.
- APIs affected: none.
- Tests: deep links preserved.
- Rollback: restore nav links.
- Acceptance: written approval and no active dependency.
- Untouched: source ingestion endpoints and replay player.

## 14. Regression Test Plan

### Repository Commands

Run before and after each implementation phase:

```bash
php -l public/admin/flight_log_garmin_connection.php
php -l public/admin/cockpit_recorder.php
php -l public/admin/cockpit_recorder_replay.php
php -l public/api/recordings/upload_chunk.php
php -l public/api/recordings/upload_finalize.php
php -l public/api/sync-agent/garmin_source.php
php scripts/phase1_cvr_regression_check.php
php tests/historical_garmin_flightcircle_migration_check.php
```

If a DB-backed test needs live DB credentials, run only after backup and against staging first.

### CVR and Cockpit Recorder Tests

- New CVR recording upload:
  - Use iPhone app or test fixture to upload audio via chunked endpoint.
  - Verify `ipca_cockpit_recordings` row, storage path, file size, status.
- Interrupted CVR upload and retry:
  - Upload first N chunks, call GET chunk status, resume, finalize.
  - Verify no duplicate chunks and correct assembled size.
- Audio package completion:
  - Verify audio/AHRS/GPS/G3X/beacon/events optional handling.
- Raw transcript generation:
  - Trigger `/admin/api/cockpit_recorder_transcribe.php`.
  - Verify `transcription_status`, chunk rows, `transcript_text`.
- Transcript enhancement:
  - Use existing enhancement action if present; verify original raw transcript remains.
- Reconstruction:
  - Trigger reconstruction.
  - Poll `/admin/api/cockpit_recorder_reconstruction_status.php`.
  - Verify replay samples/phases/events are created.

### Garmin Tests

- New Garmin synchronization:
  - Run macOS Sync Agent.
  - Verify `garmin_entries` and `garmin_source` acknowledgments.
  - Verify `ipca_garmin_flight_data_sources`, `ipca_garmin_csv_files`, `ipca_garmin_normalized_track_artifacts`.
- Duplicate Garmin synchronization:
  - Re-run same sync.
  - Verify idempotent acknowledgment and no duplicate operational records.
- Original source evidence storage:
  - Verify SHA-256 matches stored file and DB.
- Garmin CSV parsing:
  - Run summary job and check `ipca_garmin_csv_flight_summaries`.
- Hobbs/Tacho extraction:
  - Compare summary JSON with expected meter values.
- Multi-leg detection:
  - Verify `ipca_operational_flight_leg_versions` count and times for known multi-leg CSV.
- GPS-track creation:
  - Verify normalized track artifact and track summary.

### Replay Tests

- Replay launch:
  - Open `/admin/cockpit_recorder_replay.php?id=<recording_uid>`.
  - Verify player loads samples and audio.
- Replay audio synchronization:
  - Check audio current time tracks sample timeline.
- Replay attitude rendering:
  - Verify pitch/roll/heading update with expected samples.
- ADS-B enrichment:
  - Run recorder ADS-B enrich API.
  - Verify traffic overlay/API returns samples.
- Existing historical replay:
  - Open known old recording and standalone Garmin replay.

### Historical/Bulk Tests

- Existing manually uploaded Garmin file:
  - Process and verify summary/flight record preview.
- Existing bulk-imported Garmin file:
  - Process selected and verify historical segment/summary.
- FlightCircle import:
  - Import CSV to staging.
  - Verify active dataset, counts, raw rows, staging rows, operation candidates, meter readings.
- FlightCircle matching:
  - Run both selected Garmin enrichment and FlightCircle-ledger dry-run after Phase 7.
  - Verify no date/time dependency.
- Existing finalized data:
  - Attempt reprocessing source evidence.
  - Verify finalized canonical/logbook records are not silently overwritten.

### Admin UI Tests

- Permission checks:
  - Non-admin cannot access source-management or Master Logbook admin actions.
- Pagination/page-load performance:
  - Verify primary page uses server-side pagination/lazy modals and does not render all source rows at once.
- URL/filter persistence:
  - Refresh during action/polling preserves filter state.

## 15. Implementation Recommendation

Preserve unchanged:

- Cockpit Recorder upload endpoints and `CockpitRecorderService`.
- Transcript generation/chunking behavior.
- Garmin Sync Agent macOS app and `/api/sync-agent/*` endpoints.
- Garmin CSV parsing, validation, summary, and derivation services.
- Cockpit Replay page, pipeline, replay samples, audio sync, Cesium rendering, and ADS-B replay enrichment.
- Existing FlightCircle import until a deterministic ledger-first replacement is implemented.

Wrap/adapt:

- Build read-only availability adapters for:
  - CVR evidence.
  - Garmin/FDM evidence.
  - ADS-B evidence.
  - Replay availability.
  - FlightCircle evidence.
  - current operational flight-record legs.

Add:

- New Master Logbook read service.
- New Master Logbook page/API.
- Optional additive canonical bridge tables for training events, current leg rows, and evidence links if existing tables cannot safely serve the UI.
- Verification/finalization/audit workflow after read-only validation.

Eventually deprecate:

- Source-management as primary workflow on `flight_log_garmin_connection.php`.
- Duplicate Garmin/FlightCircle operational views after Master Logbook is validated.
- Any source-row table presented as "the flight log".

Must not delete:

- Any raw Garmin, FlightCircle, CVR, ADS-B, transcript, replay, or official logbook data.
- Existing source-management pages before written approval.
- Existing replay URLs.

First implementation phase:

- Create protected-component regression tests and a read-only `MasterLogbookReadService` prototype.
- Do not add write actions yet.

Files to change first:

- New docs/tests/service/page files only:
  - `tests/master_logbook_protected_components_check.php`.
  - `src/MasterLogbookReadService.php`.
  - `public/admin/master_logbook.php`.
  - optional `public/admin/api/master_logbook_rows.php`.

Files not to change first:

- `public/admin/cockpit_recorder_replay.php`.
- `src/CockpitReplayPipeline.php`.
- `src/CockpitReconstructionService.php`.
- `src/CockpitRecorderService.php`.
- `src/G3XFlightStreamParser.php`.
- `src/SyncAgentGarminIngestionService.php`.
- `/api/recordings/*`.
- `/api/sync-agent/*`.

Database migrations:

- Required only if the read model cannot be built safely from existing tables.
- Prefer additive tables/views; no renames, no column repurposing, no FK changes to protected source tables.

Backward compatibility:

- Keep old IDs and routes.
- Store evidence links to old source records.
- Use adapters/read models.
- Do not reprocess into finalized records.
- Gate all mutations behind explicit review/audit.

## Appendix A. Current Architecture Diagram

```text
iPhone Cockpit Recorder
  -> /api/recordings/upload_chunk.php
  -> /api/recordings/upload_finalize.php
  -> ipca_cockpit_recordings + storage/cockpit_recorder/*
  -> transcription chunks/OpenAI -> transcript_text
  -> CockpitReconstructionService -> ipca_cockpit_replay_samples
  -> Cockpit Replay (/admin/cockpit_recorder_replay.php)
  -> ADS-B enrichment -> cockpit ADS-B sample tables

macOS Garmin Sync Agent
  -> FlyGarmin Chrome session
  -> Garmin logbook/source/track downloads
  -> /api/sync-agent/garmin_entries.php
  -> /api/sync-agent/garmin_source.php
  -> ipca_garmin_* source/CSV/track tables
  -> async summaries/session match/flight record derivation
  -> ipca_operational_flight_records + leg versions

FlightCircle CSV
  -> /admin/api/flightcircle_historical_import.php
  -> ipca_flightcircle_* raw/staging
  -> ipca_aircraft_operations + meter/crew/fuel candidates
  -> optional Garmin matching

Admin UI today
  -> Garmin Sync Agent page (source-management wall)
  -> Cockpit Recorder page
  -> Flight Records page
  -> Logbook proposals and official logbooks
  -> Replay page
```

## Appendix B. Proposed Architecture Diagram

```text
Protected Source Systems (unchanged)
  Garmin Sync / CVR Upload / FlightCircle Import / ADS-B / Manual / Schedule

Evidence Tables (unchanged)
  ipca_cockpit_recordings
  ipca_garmin_csv_files
  ipca_garmin_normalized_track_artifacts
  ipca_flightcircle_staging_records
  ADS-B archive tables
  transcript/replay tables

Canonical Bridge (additive)
  master training events
  master flight legs or current-leg read model
  master evidence links
  verification/finalization/audit

Master Logbook
  one row per flight leg
  grouped by training event
  evidence badges and detail modal
  launch existing replay URL

Secondary Source Tabs
  Garmin/FDM Files
  CVR Recordings
  Bulk Imports
  FlightCircle Import
  Unresolved Records
  Processing Activity
```

## Appendix C. Table Ownership Matrix

| Table | Owner | Role |
|---|---|---|
| `ipca_cockpit_recordings` | Cockpit Recorder | Raw CVR package and transcript container |
| `ipca_cockpit_recording_transcription_chunks` | Cockpit Recorder | Chunked transcript evidence |
| `ipca_cockpit_replay_samples` | Cockpit Replay | Replay rendering source |
| `ipca_cockpit_*adsb*` | ADS-B/replay enrichment | Replay traffic evidence |
| `ipca_garmin_csv_files` | Garmin ingestion | Raw Garmin CSV evidence |
| `ipca_garmin_normalized_track_artifacts` | Garmin Sync Agent | Raw/normalized Garmin track evidence |
| `ipca_garmin_csv_flight_summaries` | Garmin processing | Derived flight summary |
| `ipca_garmin_track_flight_summaries` | Garmin processing | Derived track summary |
| `ipca_flight_sessions` | CVR/Garmin workflow | Evidence flight session anchor |
| `ipca_operational_flight_records` | Flight Record workflow | Stable current flight record identity |
| `ipca_operational_flight_record_versions` | Flight Record workflow | Versioned operational snapshot |
| `ipca_operational_flight_leg_versions` | Flight Record workflow | Versioned flight leg facts |
| `ipca_flight_record_logbook_proposals` | Logbook integration | Proposed logbook entries |
| `ipca_admin_logbook_entries` | Official logbook | Final/manual logbook rows |
| `ipca_flightcircle_*` | FlightCircle import | Raw/staged historical source |
| `ipca_aircraft_operations` | Historical migration | Dispatch/operation candidate |
| `ipca_meter_readings` | Historical migration/ledger | Meter facts |
| `ipca_crew_assignments` | Historical migration/crew | Source-neutral crew candidates |
| `ipca_aircraft_operation_evidence_links` | Historical migration | Evidence associations |
| `ipca_migration_cutovers` | Historical migration | Cutover approval scaffold |
| `ipca_audit_events` | Shared audit | Immutable audit events |

## Appendix D. Endpoint Dependency Matrix

| Endpoint | Depends On | Mutates |
|---|---|---|
| `/api/recordings/upload.php` | `CockpitRecorderService` | `ipca_cockpit_recordings`, storage |
| `/api/recordings/upload_chunk.php` | filesystem upload sessions | chunk files/metadata |
| `/api/recordings/upload_finalize.php` | `CockpitRecorderService` | recordings/storage |
| `/api/recordings/g3x_finalize.php` | `CockpitRecorderService` | G3X evidence and stale reconstruction |
| `/admin/api/cockpit_recorder_transcribe.php` | `CockpitRecorderService` | transcript fields/chunks |
| `/admin/api/cockpit_recorder_reconstruct.php` | `CockpitReconstructionService` | reconstruction/replay tables |
| `/admin/api/cockpit_recorder_adsb_enrich.php` | `CockpitAdsbEnrichmentService` | ADS-B enrichment tables |
| `/api/sync-agent/garmin_entries.php` | `SyncAgentAuthService`, `SyncAgentGarminIngestionService` | Garmin logbook/source metadata |
| `/api/sync-agent/garmin_source.php` | same | CSV files, track artifacts, jobs |
| `/admin/api/garmin_historical_backfill_upload.php` | `GarminHistoricalBackfillService` | backfill batches/files |
| `/admin/api/garmin_historical_backfill_action.php` | `GarminHistoricalBackfillService`, `FlightCircleGarminMatchService` | segments, matches, classifications |
| `/admin/api/flightcircle_historical_import.php` | `FlightCircleHistoricalImportService` | FlightCircle raw/staging/operations |
| `/admin/api/flightcircle_identity_action.php` | `FlightCircleHistoricalImportService` | users/mappings/crew |
| `/admin/api/flight_record_rederive.php` | `FlightRecordDerivationService` | flight record versions/legs/events |
| `/api/flight_records/traffic.php` | ADS-B/replay traffic data | display only |

## Appendix E. Protected File List

- `public/admin/cockpit_recorder_replay.php`.
- `public/admin/cockpit_recorder.php`.
- `public/api/recordings/upload.php`.
- `public/api/recordings/upload_chunk.php`.
- `public/api/recordings/upload_finalize.php`.
- `public/api/recordings/g3x_finalize.php`.
- `public/api/recordings/replay.php`.
- `public/api/recordings/transcript.php`.
- `public/api/cvr/enroll.php`.
- `public/api/cvr/device_status.php`.
- `public/api/cvr/csv_upload_chunk.php`.
- `public/api/cvr/csv_upload_finalize.php`.
- `public/api/sync-agent/_bootstrap.php`.
- `public/api/sync-agent/garmin_entries.php`.
- `public/api/sync-agent/garmin_source.php`.
- `src/CockpitRecorderService.php`.
- `src/CockpitReconstructionService.php`.
- `src/CockpitReplayPipeline.php`.
- `src/CockpitAdsbEnrichmentService.php`.
- `src/SyncAgentGarminIngestionService.php`.
- `src/G3XFlightStreamParser.php`.
- `src/GarminCsvFlightSummaryService.php`.
- `src/GarminTrackFlightSummaryService.php`.
- `src/GarminCsvReplayPayloadService.php`.
- `src/HobbsCalculationService.php`.
- `src/TachoCalculationService.php`.
- `src/AirportDetectionService.php`.
- `src/FlightEventDetectionService.php`.
- `scripts/run_async_jobs.php`.
- `ipca-sync-agent-macos/IPCASyncAgent/**`.

## Appendix F. Candidate Files for New Master Logbook

- `public/admin/master_logbook.php` (new primary admin page).
- `public/admin/api/master_logbook_rows.php` (new read-only rows API).
- `public/admin/api/master_logbook_detail.php` (new read-only detail modal API).
- `src/MasterLogbookReadService.php` (new aggregation/read service).
- `src/MasterLogbookEvidenceService.php` (new evidence availability/read adapter).
- `src/MasterLogbookVerificationService.php` (later write workflow, not phase 1).
- `tests/master_logbook_protected_components_check.php` (new regression guard).

## Appendix G. Duplicate or Obsolete UI Components

Not obsolete yet, but candidates to demote after validation:

- Garmin source/file wall on `public/admin/flight_log_garmin_connection.php`.
- FlightCircle stored rows browser inside Garmin page.
- Historical Garmin backfill list as a primary operational overview.
- Garmin flight list as operational log.
- Flight Records page if Master Logbook fully supersedes it.
- Standalone logbook proposal page if Master Logbook detail modal owns proposal review.
- `public/admin/cockpit_recorder_replay.V1.0.php` appears to be a legacy parallel replay viewer and should remain available until replay deep links and fallback needs are checked.
- `public/admin/cockpit_recorder_standalone_upload.php` appears weakly linked/orphaned, but it still supports standalone Garmin replay generation and must not be removed without checking usage.
- `public/admin/api/garmin_cloud_action.php` appears to be an unwired Garmin cloud/server-worker API surface, while the macOS Sync Agent path is actively wired. Keep it documented as a secondary/inactive surface until cloud-worker strategy is decided.
- `public/api/cvr/csv_upload_chunk.php`, `public/api/cvr/csv_upload_finalize.php`, and `public/api/cvr/csv_status.php` are documented device-authenticated CSV upload APIs, but current native app wiring should be confirmed before relying on them for the Master Logbook.
- `public/admin/cvr_transcript_test.php` and `public/admin/cvr_audio_preview.php` appear to be transcript test/preview tooling, not the production transcript path.

Do not delete any of these until written approval and regression coverage.

## Appendix H. Open Questions

- Deployment-level protections, if any, around legacy unauthenticated `/api/recordings/*` upload endpoints were not inspected. The endpoint files themselves do not enforce bearer-device auth.
- Transcript enhancement beyond raw transcription was not fully traced; exact endpoint/service should be confirmed before Master Logbook exposes enhancement actions.
- Squawk and safety-report table ownership was not fully traced by this audit and needs targeted inspection.
- Schedule/manual dispatch source tables were not fully traced.
- Whether `ipca_aircraft_operations` should become the canonical training-event table or remain historical migration dispatch candidates requires design approval.
- Whether `ipca_operational_flight_leg_versions` can serve as canonical current legs, or whether a stable current-leg table/view is needed, requires validation against finalized/amended record behavior.
- Whether historical FlightCircle proposals should be accepted through a new `HistoricalLogbookIntegrationService` or normalized first into the live `ipca_flight_record_logbook_proposals` path requires design approval.
- The apparent replay-sample migration filename/content mismatch should be checked against the live database migration history before any replay-related migrations are planned.
- Live cron/systemd worker configuration for `scripts/run_async_jobs.php` and reconstruction/transcription workers was not inspected from repository files alone.
