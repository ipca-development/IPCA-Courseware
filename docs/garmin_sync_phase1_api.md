# IPCA Garmin Sync Phase 1 API

Phase 1 accepts opaque Garmin binaries into an isolated, append-only archive. It does not parse files, associate flights, invoke CVR services, or start downstream analysis.

## Authentication and errors

All endpoints require the existing device credential as `Authorization: Bearer <token>`. Upload status is restricted to the owning organization and device. Known-hash results are restricted to objects for which the authenticated organization already has a verified receipt.

Errors are JSON:

```json
{"ok":false,"error":"Human-readable message.","error_code":"STABLE_CODE","retryable":false}
```

## Upload flow

1. Optionally call `POST /api/garmin-sync/known_hashes.php` with `{"sha256_list":["<64 hex>"]}`.
2. Send each chunk to `POST /api/garmin-sync/upload_chunk.php` as multipart form data. The binary field is `chunk`; required metadata is `upload_uuid`, `request_uuid`, `expected_sha256`, `expected_byte_count`, `chunk_index` (zero-based), `total_chunks`, and `original_filename`. Metadata may also use the documented `X-IPCA-*` headers in the endpoint.
3. Resume with `GET /api/garmin-sync/upload_chunk.php?upload_uuid=<uuid>`.
4. Verify and archive with `POST /api/garmin-sync/finalize.php` and `{"upload_uuid":"<uuid>"}`.
5. Query `GET /api/garmin-sync/status.php?upload_uuid=<uuid>`.

A successful finalize response includes an immutable receipt:

```json
{
  "ok": true,
  "status": "verified",
  "receipt": {
    "receipt_uuid": "uuid",
    "object_id": "uuid",
    "sha256": "64 lowercase hex characters",
    "byte_count": 123,
    "verified": true,
    "duplicate": false,
    "verified_at": "ISO-8601 timestamp"
  }
}
```

Calling finalize again is idempotent. Uploading the same verified bytes under another filename returns the existing object with `status: "duplicate"`. Reusing a filename for different bytes creates a different object.

## Storage and identity

Temporary chunks exist only below `storage/garmin_sync/upload_sessions/<upload_uuid>`. Verified objects exist only below `storage/garmin_sync/archive/Y/m/d/<sha256>.bin`.

SHA-256 is deliberately a global content-identity key so one historical binary occupies one archive object. Global deduplication does not grant access: organization/device-owned upload sessions and receipts are the authorization boundary, and known-hash queries do not disclose another organization's archive membership.
