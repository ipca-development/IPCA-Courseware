# CVR Phase 1C Reconciliation Audit

Status: approved audit, corrected before implementation  
Scope: Dispatch metadata, flight events, recorder verification, and Flight Closure synchronization only

## Governing correction

PHP is authoritative for workflow-payload canonicalization and hashing. Swift does not independently reproduce or validate PHP canonical hashes. For reconciliation, iOS supplies the immutable identity and the same full payload used for normal intake. The reconciliation endpoint passes that payload through the exact PHP normalization, canonicalization, and retry-equivalence functions used by normal intake. PHP returns the authoritative stored canonical hash; iOS durably stores it with the receipt and canonical server identifiers.

## Executive findings

The current POST intake paths are idempotent but push-only. The server can commit a transaction and lose the response before the iPhone persists the receipt. An identical retry usually recovers the original receipt, but the iPhone cannot ask the server for an accepted item by immutable UUID without resubmitting.

Important gaps:

1. There is no device-facing reconciliation lookup for Dispatch UUID plus version or workflow component UUID.
2. Duplicate responses return the original receipt but currently generate `server_verified_at` at retry-response time rather than returning the original stored `received_at`.
3. Dispatch duplicate responses include useful canonical identifiers, while evidence responses omit the server batch identifiers and typed evidence identifiers.
4. Dispatch receipt persistence is checked and atomic. Evidence receipt persistence calls the atomic store but ignores its Boolean failure result, so events, verification, and closure have weaker server-success/local-failure handling.
5. Workflow components do not persist the authoritative server payload hash or a complete canonical-identifier set.
6. Dispatch PHP hashing is based on a normalized flat payload, not the Swift wire object. PHP must remain authoritative.
7. Evidence hashing is strict by `component_uuid` and canonical payload hash. Event and recorder-verification records also have separate typed UUID uniqueness, so recreating a lost upload component with a new component UUID can collide with the existing typed UUID.
8. Dispatch uncertainty queues only same-flight dependent evidence until `serverDispatchID` is durably stored. Other flights continue. Individual evidence uncertainty is component-scoped.
9. Closure repair can issue a new closure/component UUID. After an uncertain server commit, that can create more than one closure row for one Flight Record because uniqueness is on `closure_uuid`, not Flight Record UUID.
10. Existing unique indexes, stored payload JSON, stored payload hashes, receipts, and `received_at` fields are sufficient for reconciliation. No database migration is required.

## Current architecture

- iOS submission:
  - `APIClient.syncDispatch()` posts to `public/api/cvr/dispatch_sync.php`.
  - `APIClient.syncWorkflowEvidence()` posts to `public/api/cvr/flight_events_sync.php`.
  - `UploadManager.uploadQueuedWorkflowComponents()` independently starts eligible component tasks.
  - `CVRWorkflowStore` atomically writes and decode-verifies active workflow JSON and archive JSON.
- PHP intake:
  - `CvrDispatchIntakeService::receive()` handles Dispatch versions and receipts.
  - `CvrWorkflowEvidenceIntakeService::receive()` handles events, recorder verification, and closure.
  - Both endpoints authenticate enrolled devices and use the Phase 1B structured error contract.
- Current recovery:
  - Timeout and connectivity failures return components to queued.
  - Persisted `.uploading` state is recovered after restart or as a same-process orphan.
  - Authentication pauses only workflow synchronization and supports controlled probes.
  - No reconciliation query exists.

## Component audit

### Dispatch metadata

#### 1. Immutable client UUID and idempotency identity

- Dispatch entity UUID: `CVRDispatchRecord.id`.
- Server idempotency identity: `(dispatch_uuid, dispatch_version)`.
- Local upload-component identifier: `dispatch-<dispatch_uuid>-v<version>`.
- Immutable linkage: `workflow_flight_record_uuid`.
- Database uniqueness:
  - `ipca_cvr_dispatches.dispatch_uuid`
  - `ipca_cvr_dispatches.workflow_flight_record_uuid`
  - `(ipca_cvr_dispatch_versions.dispatch_id, dispatch_version)`
  - `ipca_cvr_dispatch_versions.receipt_uuid`

The local component identifier is stable but is not itself the server idempotency UUID. Dispatch identity must never be inferred from date, aircraft, route, crew, or meter proximity.

#### 2. Server records stored on acceptance

Within one transaction the server stores or updates:

1. `ipca_cvr_dispatches`: current Dispatch projection and canonical numeric server Dispatch ID.
2. `ipca_cvr_dispatch_versions`: immutable version, original receipt UUID, device ID, authoritative payload SHA-256, canonical payload JSON, and original `received_at`.
3. `ipca_cvr_dispatch_consents`: immutable consent evidence for a newly accepted version.
4. `ipca_audit_events`: `cvr_dispatch_received` for a new version.
5. Optional schedule-slot claim/completion side effects through existing scheduler linkage.

#### 3. Identical retry behavior

An identical `(dispatch_uuid, dispatch_version)` retry returns HTTP 200 with:

- original receipt UUID;
- server Dispatch ID;
- Dispatch UUID;
- Flight Record UUID;
- stored original payload hash;
- `DUPLICATE_ALREADY_VERIFIED`.

If the exact hash differs, `CvrDispatchIntakeService::isRetryEquivalent()` accepts a retry when the only normalized differences are:

- `modified_at`;
- `scheduler_record_id`.

Current defect: `server_verified_at` is generated with `gmdate('c')` for the retry response. It is not the original version row’s `received_at`.

#### 4. Uncertain outcome behavior

- Timeout or connection loss: component becomes queued and later re-POSTs.
- App termination while uploading: persisted `.uploading` is recovered to queued.
- Server commit plus lost response: the next identical POST normally returns the original receipt.
- Server commit plus local Dispatch receipt-persistence failure: `persistVerifiedDispatch()` returns false; the component remains automatically recoverable and queued. Same-flight evidence remains queued because the local `serverDispatchID` was not durably stored.
- Duplicate response lacking required local metadata: currently treated as a failed response rather than reconciled separately.

#### 5. Query by immutable UUID

No authenticated device endpoint can query `(dispatch_uuid, dispatch_version)`. The lookup exists only inside the POST intake service and in admin-oriented read paths.

#### 6. Current canonical determinism

PHP normalization and canonicalization are deterministic within PHP:

1. Normalize and validate the incoming Dispatch request.
2. Resolve an unambiguous scheduler record ID when applicable.
3. Remove computed `continuity_warnings`.
4. Recursively sort object keys with `SORT_STRING`; preserve list order.
5. Encode compact JSON with unescaped Unicode and slashes.
6. Compute lowercase SHA-256.

#### 7. Ignored and normalized retry fields

Fields normalized and included in the current server hash:

- `dispatch_uuid`, `flight_record_uuid`, `dispatch_version`;
- scheduled date and optional scheduled start/end timestamps;
- normalized aircraft registration and optional aircraft ID;
- mission code, departure airport, destination airport;
- ordered normalized crew entries;
- starting Hobbs/Tacho, fuel, oil percentage or quantity/unit;
- Dispatch source, creator identity, created time, modified time;
- scheduler record ID;
- consent status and Dispatch status;
- CVR unit and beacon identifiers;
- previous Flight Record ID;
- refueled/oil-serviced flags;
- ordered normalized consent entries.

Incoming fields currently dropped before hashing:

- `organization_id`;
- `fuel_unit`;
- `fuel_capacity`;
- previous ending Hobbs/Tacho/fuel/oil proximity values;
- request ID, receipt ID, retry count, attempt time, and unknown fields.

Computed `continuity_warnings` are removed before hashing. Retry-equivalence additionally ignores `modified_at` and `scheduler_record_id`.

#### 8. Swift/PHP hash relationship

Swift cannot and will not independently generate this hash. The Swift wire object differs from the PHP normalized hash object in shape, names, timestamp formatting, trimming, server resolution, ignored fields, and decimal serialization. PHP remains authoritative and returns the stored authoritative hash to iOS.

#### 9. Isolation

A Dispatch uncertainty queues only evidence dependent on that Dispatch’s durably persisted server ID. It does not block unrelated flights, recordings, Garmin uploads, or local operations.

#### 10. Minimum reconciliation change

Add an indexed lookup by Dispatch UUID plus version, authenticate device ownership, canonicalize the supplied full Dispatch payload through the same intake functions, apply the same exact-hash or `isRetryEquivalent()` rule, and return the original version receipt, original `received_at`, server Dispatch ID, immutable UUIDs, and authoritative stored hash.

### Flight event

#### 1. Immutable client UUID and idempotency identity

- Event UUID: `CVRFlightEventRecord.id`.
- Upload component UUID: `CVRUploadComponentRecord.id`.
- Server idempotency identity: `component_uuid`.
- Typed-row identity: `event_uuid`.
- Flight and Dispatch UUIDs are immutable linkage and ownership checks.

`component_uuid` and `event_uuid` are intentionally distinct. Reconciliation must query `component_uuid`; it may return `event_uuid` as a canonical identifier.

#### 2. Server records stored on acceptance

1. `ipca_cvr_workflow_evidence_batches`: batch UUID, component UUID, Flight Record UUID, Dispatch UUID, device ID, component type, authoritative payload hash/JSON, original receipt UUID, and original `received_at`.
2. `ipca_cvr_flight_events`: typed event UUID, server row ID, batch ID, Flight Record UUID, timing, recording linkage, source/confidence/method, GPS values, authoritative payload hash/JSON, and typed-row `received_at`.
3. `ipca_audit_events`: immutable evidence-received event.

#### 3. Identical retry behavior

An identical `component_uuid` with the same canonical payload hash returns the original receipt. Any device or hash mismatch is a UUID conflict.

Current response includes component, Dispatch, and Flight Record UUIDs but omits:

- server batch numeric ID;
- server batch UUID;
- typed event server row ID;
- event UUID;
- original stored `received_at`.

The returned verification time is currently generated at response time.

#### 4. Uncertain outcome behavior

Timeout, connection loss, or termination returns the component to queued or orphan recovery. The client blindly re-POSTs.

After server success, `updateUploadComponent(...serverVerified...)` invokes atomic persistence but its Boolean result is not checked. A local save failure can leave the persisted component `.uploading` until orphan recovery.

If the upload component is lost and recreated with a new `component_uuid`, insertion can collide with the existing unique `event_uuid`; this currently surfaces as a technical failure rather than receipt reconciliation.

#### 5. Query by immutable UUID

No device endpoint exists. PHP can look up the batch internally by `component_uuid`.

#### 6–7. Current canonical fields and normalization

The current canonical object is:

- `schema_version`;
- lowercased `component_uuid`;
- `component_type = flight_events`;
- lowercased `flight_record_uuid`;
- lowercased `dispatch_uuid`;
- `evidence`:
  - lowercased `event_uuid`;
  - `event_type`;
  - `timestamp_utc`;
  - `timestamp_local`;
  - `source`;
  - `confidence`;
  - `creation_method`;
  - optional `recording_session_id`;
  - optional `device_monotonic_time`;
  - optional `audio_offset`;
  - optional latitude, longitude, altitude, and ground speed;
  - optional `user_identity`;
  - optional metadata object.

PHP normalizes envelope UUIDs, component type, schema version, and the typed event UUID. Other evidence values and unknown evidence keys are retained in the hash. Unknown top-level fields are dropped. No retry-equivalent evidence fields exist.

#### 8. Swift/PHP hash relationship

PHP remains authoritative. Swift supplies the full event envelope during reconciliation and stores the authoritative hash returned by PHP.

#### 9. Isolation

An uncertain event affects only that component. Other events, verification, closure, and other flights continue, subject only to their own Dispatch dependency.

#### 10. Minimum reconciliation change

Lookup the batch by `component_uuid`, verify device/type/Dispatch/Flight linkage, canonicalize the supplied payload using `CvrWorkflowEvidenceIntakeService`’s exact normal-intake function, compare with the stored hash, and return original batch receipt/time/hash and canonical batch/event identifiers.

### Recorder verification

#### 1. Immutable client UUID and idempotency identity

- Verification UUID: `CVRRecorderVerificationRecord.id`.
- Upload component UUID: `CVRUploadComponentRecord.id`.
- Server idempotency identity: `component_uuid`.
- Typed-row identity: `verification_uuid`.

#### 2. Server records stored on acceptance

The server stores:

- one workflow evidence batch with receipt/hash/JSON and original `received_at`;
- one typed recorder-verification row with server ID, verification UUID, batch ID, Flight Record UUID, Dispatch UUID, verified timestamp, hash/JSON, and `received_at`;
- one audit event for a new batch.

#### 3–5. Retry, uncertainty, and query

Behavior matches flight events:

- exact component UUID plus hash returns the original receipt;
- conflicts are strict;
- response omits original stored time and server batch/typed identifiers;
- local success persistence is not checked;
- no query endpoint exists;
- recreating the component with a new component UUID can collide with unique `verification_uuid`.

#### 6–7. Current canonical fields and normalization

The canonical envelope contains the common evidence envelope plus:

- lowercased `verification_uuid`;
- timestamp;
- device ID;
- app version;
- user identity;
- audio route status;
- beacon status;
- GPS status;
- storage status;
- thermal status;
- battery status;
- permission status;
- file-writing test result;
- ordered warnings;
- ordered accepted nonblocking warnings.

Only envelope UUIDs/schema/type and verification UUID are normalized. No retry-equivalent fields exist.

#### 8–10. Hash authority, isolation, and minimum change

PHP remains authoritative. Verification uncertainty is component-scoped. Reconciliation uses component UUID lookup and returns original batch and typed verification identifiers, receipt, time, and hash after exact shared-PHP canonical comparison.

### Flight Closure

#### 1. Immutable client UUID and idempotency identity

- Upload component UUID: `CVRUploadComponentRecord.id`.
- Closure UUID: the same component UUID.
- Server idempotency identity: `component_uuid`.
- Typed-row identity: `closure_uuid`, equal to component UUID.
- Flight Record UUID and Dispatch UUID are immutable linkage.

#### 2. Server records stored on acceptance

The server stores:

- workflow evidence batch with receipt/hash/JSON and original `received_at`;
- typed closure row with server ID, closure UUID, batch/Flight linkage, ending meters, optional fuel/oil/remark, payload hash/JSON, and `received_at`;
- audit event;
- optional schedule completion side effect.

#### 3–5. Retry, uncertainty, and query

An exact component UUID plus hash returns the original receipt. The response does not return original stored time or server batch/closure row identifiers. No query endpoint exists.

A normal blind replay recovers the receipt. However, closure repair can remove the old local component and create a new closure/component UUID. If the old component committed before its response was lost, the new UUID can create another closure for the same Flight Record.

#### 6–7. Current canonical fields and normalization

The current canonical envelope contains:

- common evidence envelope;
- lowercased `closure_uuid`;
- status;
- `updated_at`;
- ending Hobbs;
- ending Tacho;
- optional fuel remaining;
- optional verified takeoff/landing counts;
- optional auto-detected takeoff/landing counts;
- optional maintenance remark.

PHP additionally accepts optional ending oil percentage or quantity/unit, although current iOS closure submission omits them.

Validation checks meter monotonicity against Dispatch baselines before insertion, but the hash uses the normalized envelope values rather than typed database decimal representations. No retry-equivalent fields exist.

#### 8–10. Hash authority, isolation, and minimum change

PHP remains authoritative. Closure uncertainty is component-scoped, but a new repair UUID can create same-flight duplicate closure evidence. Reconciliation must run before treating an uncertain closure as absent or issuing replacement evidence.

## Narrow reconciliation contract

### Endpoint

Add one endpoint:

`POST /api/cvr/sync_reconcile.php`

The endpoint:

- authenticates once with `DeviceAuthService`;
- accepts a bounded `items` array;
- returns one independent result per item;
- uses immutable UUID indexes only;
- does not infer identity from dates, crew names, route, airports, aircraft proximity, or meter proximity;
- catches item-level conflicts/missing/dependencies/technical failures independently;
- may fail the whole request only for authentication, malformed total request, or total endpoint failure.

### Request item: Dispatch

Required fields:

- `item_id`: local upload component ID for correlation;
- `component_type = dispatch_metadata`;
- `dispatch_uuid`;
- `dispatch_version`;
- `flight_record_uuid`;
- `payload`: the complete normal Dispatch sync payload.

### Request item: workflow evidence

Required fields:

- `item_id`: component UUID;
- `component_type`: `flight_events`, `recorder_verification`, or `flight_record_closure`;
- `component_uuid`;
- `dispatch_uuid`;
- `flight_record_uuid`;
- `payload`: the complete normal workflow-evidence sync payload.

No client-generated canonical hash is required. PHP canonicalizes the supplied payload with the same functions used by normal intake and compares the result with the stored authoritative hash. For Dispatch, the same exact-hash or `isRetryEquivalent()` rule applies.

### Per-item statuses

#### VERIFIED_MATCH

The immutable row exists, belongs to the authenticated device, has matching immutable linkage/type/version, and the supplied payload matches under normal intake equivalence.

Return:

- `status = VERIFIED_MATCH`;
- `item_id`;
- original `receipt_id`;
- original stored `received_at` or verification timestamp;
- authoritative stored `payload_sha256`;
- canonical identifiers.

Dispatch canonical identifiers:

- `server_dispatch_id`;
- `dispatch_uuid`;
- `dispatch_version`;
- `flight_record_uuid`.

Evidence canonical identifiers:

- `server_evidence_batch_id`;
- `server_batch_uuid`;
- `component_uuid`;
- `component_type`;
- typed evidence server row ID;
- typed evidence UUID (`event_uuid`, `verification_uuid`, or `closure_uuid`);
- `dispatch_uuid`;
- `flight_record_uuid`.

#### NOT_FOUND

The immutable item is absent and its prerequisite Dispatch exists when required. Return only that component to queued for normal upload using the same immutable identity and preserved payload.

#### IMMUTABLE_CONFLICT

The immutable identity exists but device ownership, component type, Dispatch UUID, Flight Record UUID, Dispatch version, or normal-intake payload equivalence differs.

The server must not overwrite either payload. iOS preserves all local evidence and isolates only that component for technical review. It is not crew Action Required.

#### DEPENDENCY_NOT_READY

The evidence item is absent and its required Dispatch/linkage is unavailable. Keep queued and automatically retryable.

#### AUTHENTICATION_REQUIRED

Authentication fails for the complete endpoint. Use the existing Phase 1B workflow-only pause/probe mechanism. Do not alter Garmin, recording, local operations, or evidence.

#### TEMPORARY_TECHNICAL_FAILURE

An unexpected per-item or endpoint technical failure. Keep affected items automatically retryable. An item-level technical failure must not stop other results.

### Suggested response envelope

```json
{
  "ok": true,
  "request_id": "optional-request-id",
  "results": [
    {
      "item_id": "dispatch-uuid-v4",
      "component_type": "dispatch_metadata",
      "status": "VERIFIED_MATCH",
      "receipt_id": "receipt-uuid",
      "received_at": "2026-08-03T17:00:00.123Z",
      "payload_sha256": "authoritative-server-hash",
      "canonical_identifiers": {
        "server_dispatch_id": "123",
        "dispatch_uuid": "uuid",
        "dispatch_version": "4",
        "flight_record_uuid": "uuid"
      },
      "retryable": false,
      "user_action_required": false,
      "error": null
    }
  ]
}
```

## Canonical field and normalization rules

These are the existing authoritative PHP rules that reconciliation must reuse, not reimplement separately.

### Dispatch

Canonical fields are the normalized `CvrDispatchIntakeService` result after scheduler resolution and removal of `continuity_warnings`:

- Dispatch UUID, Flight Record UUID, version;
- scheduled date/start/end;
- aircraft registration/ID;
- mission and route;
- ordered crew;
- starting meters, fuel, oil;
- source and creator;
- created and modified times;
- scheduler record ID;
- consent and Dispatch statuses;
- CVR/beacon identifiers;
- previous Flight Record UUID;
- refuel/oil-service flags;
- ordered consents.

Rules:

- UUIDs lowercase and trimmed.
- aircraft registration uppercase and stripped to alphanumeric;
- airports uppercase, trimmed, maximum eight characters;
- configured strings trimmed and length-limited;
- timestamps normalized through the existing PHP timestamp normalizer;
- numeric fields cast through existing PHP intake rules;
- booleans normalized through existing PHP filter rules;
- object keys recursively sorted with `SORT_STRING`;
- list order preserved;
- compact UTF-8 JSON with unescaped Unicode/slashes;
- SHA-256 lowercase hexadecimal;
- computed continuity warnings excluded;
- retry equivalence ignores only `modified_at` and `scheduler_record_id`.

### Flight event

Canonical fields:

- schema version;
- component UUID/type;
- Flight Record UUID;
- Dispatch UUID;
- complete evidence object, including event UUID and all supported optional event fields.

Rules:

- envelope UUIDs and event UUID lowercase/validated;
- schema version coerced to at least one;
- component type trimmed and allowlisted;
- evidence remains the exact JSON values supplied except typed UUID normalization;
- object keys recursively sorted;
- list order preserved;
- no retry-equivalent fields.

### Recorder verification

Canonical fields:

- common evidence envelope;
- complete verification evidence including verification UUID, timestamp, device/app/user fields, status fields, warnings, and accepted warnings.

Rules match flight events, with verification UUID normalization and no retry-equivalent fields.

### Flight Closure

Canonical fields:

- common evidence envelope;
- complete closure evidence including closure UUID, status, update time, ending meters, optional fuel/oil/counts/remark.

Rules match other evidence, with closure UUID normalization and no retry-equivalent fields. Existing closure validation remains authoritative and is not replaced by reconciliation-specific value interpretation.

### Explicitly excluded transport metadata

The canonical payload excludes:

- request IDs;
- receipt IDs;
- upload attempt timestamps;
- retry counts;
- local progress;
- local error messages;
- authentication headers;
- reconciliation request time;
- local file paths;
- server verification metadata.

## iOS reconciliation behavior

The iPhone should reconcile before normal resubmission when:

- a persisted attempted component has no complete server metadata after restart;
- timeout or connection loss leaves commit outcome unknown;
- server success was received but durable local persistence failed;
- a duplicate response lacks required local metadata;
- a server-verified component lacks receipt, canonical identifiers, original verification time, or authoritative hash;
- explicit synchronization retry is requested.

Per result:

- `VERIFIED_MATCH`: atomically persist receipt, authoritative hash, original time, and every required canonical identifier; decode-verify local persistence; then mark `serverVerified`.
- `NOT_FOUND`: clear reconciliation requirement only for that component and return it to queued normal upload.
- `IMMUTABLE_CONFLICT`: preserve evidence, isolate only that component as technical review, never crew Action Required.
- `DEPENDENCY_NOT_READY`: remain queued and automatically retryable.
- `AUTHENTICATION_REQUIRED`: use the Phase 1B workflow pause/probe.
- `TEMPORARY_TECHNICAL_FAILURE`: remain queued and automatically retryable.

Evidence verification persistence must match Dispatch persistence. No evidence component may become `serverVerified` until all required metadata has been durably saved and decoded successfully.

## Batch isolation

- Every request item is looked up and classified independently.
- A conflict, missing row, dependency, or item technical failure never aborts later items.
- The endpoint never performs inserts or updates during reconciliation.
- Matching items return their original receipts even when another item conflicts.
- One flight’s uncertain Dispatch may keep only its own evidence dependent.
- Other flights and components continue.
- Authentication may fail the whole request because ownership cannot be evaluated safely.
- A total endpoint failure may return one top-level temporary technical failure.

## Minimum persistence changes

No database migration is required.

Local component persistence must add:

- reconciliation-required marker;
- authoritative server payload SHA-256;
- complete canonical identifier map;
- original server verification/received timestamp;
- durable request payload snapshot or equivalent preserved immutable evidence reference sufficient to supply the original intake payload during reconciliation.

Existing fields retained:

- receipt ID;
- server ID;
- attempt count;
- component state;
- local evidence linkage.

## Exact implementation file list

### New PHP files

- `public/api/cvr/sync_reconcile.php`
  - authenticated bounded batch endpoint;
  - independent item results;
  - Phase 1B top-level authentication/technical envelope.
- `src/CvrWorkflowSyncReconciliationService.php`
  - indexed immutable identity lookups;
  - ownership/linkage checks;
  - calls the normal intake canonicalization/equivalence functions;
  - returns original receipts/times/hashes/identifiers.

### Modified PHP files

- `src/CvrDispatchIntakeService.php`
  - expose one shared canonical-payload function used by intake and reconciliation;
  - expose the exact existing retry-equivalence comparison;
  - return original stored `received_at` and canonical identifiers on duplicate.
- `src/CvrWorkflowEvidenceIntakeService.php`
  - expose one shared canonical-payload function used by intake and reconciliation;
  - return original batch/typed identifiers and original `received_at`.
- `src/CvrSyncException.php`
  - add `IMMUTABLE_CONFLICT` only where typed intake/reconciliation classification requires it.
- `public/api/cvr/dispatch_sync.php`
  - no behavioral redesign; continue existing wrapper and additive response fields.
- `public/api/cvr/flight_events_sync.php`
  - no behavioral redesign; continue existing wrapper and additive response fields.

### Modified iOS files

- `ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift`
  - reconciliation request/result models and authenticated batch method;
  - no canonical hash generation.
- `ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift`
  - identify uncertain components;
  - batch reconcile before ordinary POST;
  - independently apply each result;
  - preserve Garmin/audio independence and Phase 1B authentication behavior.
- `ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift`
  - atomic receipt/hash/time/identifier persistence for all component types;
  - decode verification;
  - restart recovery for incomplete server verification;
  - component-scoped reconciliation state.
- `ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift`
  - additive optional reconciliation and authoritative metadata fields.

No Swift canonicalizer file is required. Swift stores the authoritative PHP hash but does not generate it.

### Tests

- New `tests/cvr_phase1c_reconciliation_contract_check.php`
  - immutable lookups, statuses, original metadata, item isolation, no inserts, and no schema migration.
- Update `tests/cvr_dispatch_intake_contract_check.php`
  - shared canonicalization/equivalence and original duplicate time/receipt.
- Update `tests/cvr_workflow_evidence_contract_check.php`
  - shared canonicalization and original duplicate batch metadata.
- Update `tests/cvr_workflow_archive_contract_check.php`
  - durable evidence verification, restart reconciliation, and cross-flight isolation.
- Optional Swift executable fixture test for decoding reconciliation responses and applying store transitions; it must not generate PHP hashes.

## Excluded files and systems

Phase 1C does not modify:

- database schema or migrations;
- scheduler or reservation model;
- Garmin synchronization;
- audio/recording endpoints or internals;
- Log;
- transcript;
- replay;
- reconstruction;
- operational UI.

