# Phase 2A — Operational reservation / leg identity

Additive canonical identity register. Existing schedule, Dispatch, workflow evidence, recording, Garmin, and session tables remain authoritative for their payloads. Phase 2A does **not** wire into scheduler UI, iOS workflow, Dispatch intake, Garmin, audio, transcript, replay, Log, or debrief.

## Canonical model

- `reservation_uuid` is the permanent reservation identity.
- `scheduler_record_id` is a compatibility alias only (org + source_system scoped). Existing scheduler UUIDs may be adopted as `reservation_uuid` only when immutable, unique, and valid.
- Reservation `1:N` legs. Legs exist **only** when `activity_domain = flight`.
- Intended workflow: one Dispatch per flight leg.
- `checked_in` is the terminal crew leg state. No server-dependent closed state.

## Tables

1. `ipca_operational_reservations`
2. `ipca_operational_reservation_legs`
3. `ipca_operational_identity_aliases`
4. `ipca_operational_identity_backfill_quarantine`

Migration: `scripts/sql/2026_08_04_cvr_operational_reservation_leg_identity.sql`  
Manual apply. Idempotent `CREATE TABLE IF NOT EXISTS`. No source-row mutation.

## activity_domain

Values: `flight`, `simulator`, `ground`, `administrative`.

Operational legs are created only when `activity_domain = flight`.

Deterministic defaults from `reservation_type` (backfill / online create):

| reservation_type | activity_domain |
|---|---|
| flight_training | flight |
| simulator_training | simulator |
| briefing, ar_briefing, ground_training, theory_lesson, theory_mock_exam, meeting, assessment | ground |
| maintenance, personal, unavailable, other | administrative |
| practical_exam | **none** — requires explicit classification; otherwise quarantine |

`simulator_training` and `other` remain reservation-only (`other` never creates a flight leg).
`ground_training` maps to `ground` and never creates a flight leg.

## Status

Reservation (coarse): `scheduled` | `active` | `completed` | `cancelled`

Derived from child legs when legs exist:

- all cancelled → `cancelled`
- all non-cancelled `checked_in` → `completed`
- any `dispatched` / `active` / mix with `checked_in` → `active`
- else → `scheduled`

Leg: `scheduled` | `dispatched` | `active` | `checked_in` | `cancelled`

## Aliases

Uniqueness: `(organization_id, source_system, alias_type, alias_value, alias_version_key)`  
where `alias_version_key = alias_version` or `''` when null.

Exactly one target: `target_type` + XOR of `reservation_uuid` / `leg_uuid`.

`linkage_method`: `online_create` | `offline_create` | `deterministic_backfill` | `manual_verified`  
`confidence_state`: `VERIFIED` | `DETERMINISTIC_BACKFILL`  
Uniqueness alone never qualifies; only direct immutable linkage.

### Retained Phase 2A alias types

`scheduler_record_id`, `schedule_slot_id`, `dispatch_uuid`, `dispatch_uuid_version` (version in `alias_version`), `workflow_flight_record_uuid`, `workflow_archive_id`, `recording_uid`, `server_recording_id`, `server_dispatch_id`

### Deferred (multi-leg / continuous)

Garmin CSV/file IDs, engine-operation/session IDs, `flight_session_uid`, derived operational FR / leg_version UUIDs.

## Quarantine

Organization-scoped. References source table + PK. Diagnostic JSON ≤ 4096 bytes. Never stores audio, Garmin CSV contents, transcripts, credentials, or unnecessary personal data. Source rows unchanged.

Resolution audit: `resolved_by_user_id`, `resolution_notes`, `updated_at_utc`, `resolved_at_utc`.

## Feature flags (default off)

- `operational_identity_backfill_enabled`
- `operational_identity_dual_read_enabled`
- `operational_identity_canonical_write_enabled`

Rollback = disable flags. Do not DROP tables or delete register/quarantine rows.

## Phase 2B dual-read

When `operational_identity_dual_read_enabled` is on, read projections may add:

- `reservation_uuid`
- `leg_uuid`
- `identity_source`: `canonical_alias` | `legacy_fallback` | `canonical_conflict` | `canonical_unavailable`

Wired read paths:

- `FlightScheduleService::payload` → schedule / `scheduled_sessions`
- `CvrFlightLogService::forDeviceAircraft` → `flight_logs`
- `CvrDataIntakeReadService::dispatchRows` → admin intake

Rules: verified/`DETERMINISTIC_BACKFILL` aliases only; org-scoped; conflicts and identity failures fall back to legacy without mutation; flag off omits the additive fields entirely.

Schedule dual-read (Phase 2C enhancement): when the resolved reservation has `activity_domain = flight` and exactly one org-scoped leg, both `reservation_uuid` and `leg_uuid` are returned. Non-flight reservations return `reservation_uuid` only. Zero or multiple legs for a flight reservation → `canonical_conflict` (legacy response) plus a technical integrity diagnostic.

## Phase 2C — online scheduler canonical writes

Scope: **new online schedule creates only** via `FlightScheduleService::saveSlot` INSERT branch, behind `operational_identity_canonical_write_enabled` (default **off**).

When the flag is on:

1. Legacy schedule INSERT includes an explicit trusted `organization_id` (never DB default; never taken as authoritative from raw POST).
2. Same DB transaction writes canonical reservation (+ one flight leg when `activity_domain = flight`) and aliases:
   - `scheduler_record_id` → reservation
   - `schedule_slot_id` → reservation
3. `linkage_method = online_create`, `confidence_state = VERIFIED`.
4. `reservation_uuid` adopts the lowercase `scheduler_record_id`.
5. Retries / updates / reschedule / cancel do **not** mint new identities. Existing legacy rows without identity stay for backfill/repair.
6. Identical retries reuse existing reservation/leg/aliases only after immutable identity verification (org, type/domain, airports, planned UTC window, timezone, provenance compatibility). Material mismatch is an immutable identity conflict: fail closed, do not overwrite, roll back the create.

### Organization ownership (integrity follow-up)

Trusted resolution order:

1. Authenticated/current organization context (`cw_current_organization_id` or session org keys) when established by the admin request.
2. Explicit mission catalog ownership when `mission_id` is present and unambiguous.
3. One unambiguous organization in the authorized mission catalog when that single-tenant rule already applies safely.

Posted `organization_id` is an optional consistency assertion only. Mismatch with the trusted value rejects the create. Never silently switch to the posted organization.

### Failure policy

If any required canonical write fails (including immutable identity conflict), the entire create transaction rolls back (no orphan legacy row). Unexpected PDO/technical failures on the create path are sanitized for the UI; correctable validation `RuntimeException` messages are preserved. Diagnostics log only sanitized identifiers and error classification.

Out of scope: iOS offline/Dispatch intake canonical writes, scheduler multi-leg UI, resource model, Garmin/session tables, audio/transcript/replay/debrief/Log redesign.

Contracts:

- `tests/cvr_phase2c_scheduler_canonical_write_contract_check.php`
- `tests/cvr_phase2c_integrity_contract_check.php`

## Phase 2D — local iOS Dispatch identity creation

Scope: **identity creation only** for locally created iOS Dispatch records. No scheduler UI, multi-leg UI, Dispatch intake redesign, or server online-create changes.

### Local Dispatch identity lifecycle

1. **Create Local Dispatch** (flag on): mint lowercase `reservation_uuid` and `leg_uuid` once, build Phase 2A-approved compatibility aliases (`dispatch_uuid` → leg; optional `scheduler_record_id` → reservation when opening from schedule), and persist them on `CVRDispatchRecord.operationalIdentity` in the same atomic `flight-workflow.json` write as the Dispatch.
2. **Flag off**: omit `operationalIdentity` entirely; legacy Dispatch creation is unchanged; no identity helper calls.
3. **Confirm Dispatch**: do not remint reservation/leg; attach `workflow_flight_record_uuid` → leg alias when identity already exists.
4. **Restart**: identities reload from JSON with the Dispatch; never regenerated by `load()` / recovery.
5. **Sync**: `UploadManager` includes additive `reservation_uuid` / `leg_uuid` / `operational_identity` in the frozen dispatch payload snapshot. Retries reuse the snapshot; sync never regenerates identities. Server intake canonical table writes remain out of this phase.

### Offline UUID rules

- UUIDs are generated on-device before any network call.
- Values are normalized lowercase and stored before upload.
- Identical retry / reopen of the same local identity reuses after immutable verification (org, type/domain, airports, planned UTC window, timezone, linkage).
- Material mismatch → immutable conflict, fail closed, no overwrite.

### Client flag

`SettingsStore.operationalIdentityCanonicalWriteEnabled` mirrors server policy key `operational_identity_canonical_write_enabled` and defaults to **false**. Offline create must not require network to evaluate the flag.

Contracts:

- `tests/cvr_phase2d_local_dispatch_identity_contract_check.php`
- `tests/cvr_phase2d_local_dispatch_identity_contract_check.swift`

## Backfill CLI

```bash
php scripts/backfill_operational_reservation_leg_identity.php
php scripts/backfill_operational_reservation_leg_identity.php --apply --organization-id=1 --limit=500
```

Default is dry-run. `--apply` requires the backfill flag.

## Services

- `src/CvrOperationalIdentityService.php`
- `src/CvrOperationalIdentityReadService.php`
- `src/CvrOperationalIdentityBackfillService.php`

## Time

Persisted instants in UTC. Organization IANA timezone retained. Typed local wall-clock `DATETIME(3)`. Separate start/end offsets and DST resolution. UTC ordering is authoritative; local order is not enforced by DB CHECK.
