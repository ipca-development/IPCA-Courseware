# CVR Phase 1 API Contract

Phase 1 adds `/api/cvr/*` endpoints for dedicated aircraft iPhones. Existing `/api/recordings/*` audio upload, status, transcript, and replay endpoints remain unchanged.

## Authentication

All `/api/cvr/*` endpoints except `POST /api/cvr/enroll.php` require:

```http
Authorization: Bearer <device_credential>
```

Device credentials are issued once during enrollment and stored server-side only as SHA-256 hashes in `ipca_cvr_device_credentials`.

## Endpoints

### `POST /api/cvr/enroll.php`

Exchanges a one-time enrollment code for a device credential.

Request:

```json
{
  "enrollment_code": "AB12CD34",
  "device_uuid": "00000000-0000-4000-8000-000000000000",
  "display_name": "CVR OO-ABC",
  "mdm_device_identifier": "optional-mdm-id"
}
```

Response includes `credential` once. The app must store it securely.

### `GET /api/cvr/device_status.php`

Returns the authenticated device assignment, server time, conservative configuration hints, and recent sessions.

### `POST /api/cvr/csv_upload_chunk.php`

Uploads one Garmin CSV chunk. Accepts `multipart/form-data` with `chunk` or `file`.

Metadata may be form fields or headers:

- `upload_uuid` or `X-IPCA-CVR-CSV-Upload-ID`
- `request_uuid` or `X-IPCA-Request-ID`
- `session_uuid` or `X-IPCA-Flight-Session-ID`
- `chunk_index` or `X-IPCA-Chunk-Index`
- `total_chunks` or `X-IPCA-Total-Chunks`
- `total_size` or `X-IPCA-Total-Size`
- `original_filename` or `X-IPCA-Original-Filename`

### `GET /api/cvr/csv_upload_chunk.php`

Returns received chunk indexes for resume.

Query:

```text
upload_uuid=<uuid>
```

### `POST /api/cvr/csv_upload_finalize.php`

Assembles uploaded chunks, stores immutable Garmin CSV evidence outside the web root, computes SHA-256, writes content fingerprints, runs fast validation, attempts conservative session matching, and enqueues Phase 1 async jobs.

Request:

```json
{
  "upload_uuid": "00000000-0000-4000-8000-000000000000"
}
```

### `GET /api/cvr/csv_status.php`

Returns upload or CSV file status.

Query:

```text
upload_uuid=<uuid>
csv_file_uuid=<uuid>
```

## Protected Regression Checklist

Before deploying Phase 1:

- Confirm existing audio-only chunk upload still accepts current iPhone payloads.
- Confirm `upload_finalize.php` still finalizes audio-only packages.
- Confirm upload resume still returns received chunks.
- Confirm `status.php` and `transcript.php` retain their existing response shape.
- Confirm replay v2 still loads existing sessions.
- Confirm Student Logbook totals and existing Admin accept/reject flows are unchanged.

Run:

```sh
php scripts/phase1_cvr_regression_check.php --check-db-capabilities
php -l src/DeviceAuthService.php
php -l src/GarminCsvEvidenceService.php
php -l src/AsyncJobService.php
php -l public/api/cvr/csv_upload_finalize.php
```

After applying `scripts/sql/2026_07_12_cvr_flight_workflow_phase1_foundation.sql`, run:

```sh
php scripts/phase1_cvr_regression_check.php
```
