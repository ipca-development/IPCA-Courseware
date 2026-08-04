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

Deterministic defaults from `reservation_type` (backfill):

| reservation_type | activity_domain |
|---|---|
| flight_training | flight |
| simulator_training | simulator |
| briefing, ar_briefing, theory_lesson, theory_mock_exam, meeting, assessment | ground |
| maintenance, personal, unavailable | administrative |
| practical_exam | **none** — requires explicit classification; otherwise quarantine |

`simulator_training` remains reservation-only.

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
