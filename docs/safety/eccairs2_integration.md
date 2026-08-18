# ECCAIRS 2 machine-to-machine integration

## Safety boundary

IPCA prepares ECCAIRS payloads only after a human records a `reportable`
assessment. Preparation does not transmit. A Safety Manager must review the
taxonomy validation result and approve the exact transport-neutral canonical
SHA-256 before it is
queued.

The legacy `OR_0001.pdf` through `OR_0034.pdf` documents are migration sources
only. They cannot enter the ECCAIRS queue and must not be submitted as current
notifications.

## Onboarding prerequisites

Obtain and record outside the repository:

- EASA/ECCAIRS organization and API user approval;
- outbound production and test IP allowlisting;
- Sandbox, UAT, and Production credentials;
- reporting and responsible entity identifiers;
- the current taxonomy/general version identifiers;
- the onboarding Swagger/OpenAPI contract;
- the official ECCAIRS taxonomy package used for mapping and XSD 1.1 validation;
- confirmation of the accepted E5X container profile for controlled fallback.

The checked-in endpoint paths reflect the published E2 API guide but remain
database-configurable because the onboarding contract is authoritative.

## Secrets

For each environment (`SANDBOX`, `UAT`, and `PRODUCTION`) configure:

- `CW_ECCAIRS_<ENV>_USERNAME`
- `CW_ECCAIRS_<ENV>_PASSWORD`
- `CW_ECCAIRS_<ENV>_CLIENT_ID`
- `CW_ECCAIRS_<ENV>_CLIENT_SECRET`

Optional controls:

- `CW_ECCAIRS_CONNECT_TIMEOUT` (default 10 seconds)
- `CW_ECCAIRS_REQUEST_TIMEOUT` (default 30 seconds)
- `CW_ECCAIRS_MAX_ATTEMPTS` (default 5)
- `CW_ECCAIRS_TAXONOMY_ARCHIVE_PATH`
- `CW_ECCAIRS_XSD11_PYTHON_PATH` (absolute Python path with `xmlschema`
  installed; defaults to `/usr/bin/python3`)
- `CW_ECCAIRS_E5X_PACKAGER_COMMAND` (absolute path to the authority-confirmed
  packaging adapter)
- `CW_ECCAIRS_E5X_PACKAGER_PROFILE` (approved profile/version identifier)

Credentials and access/refresh tokens must never be stored in the application
database, logs, source control, audit payloads, or error messages.
Access and refresh tokens are retained in process memory only. An unauthorized
API response triggers the OAuth refresh-token grant before the request is
retried.

## Activation

1. Apply `scripts/sql/2026_08_18_safety_eccairs2_integration.sql`.
2. Preflight and import the official taxonomy package:

   `php scripts/safety/eccairs_taxonomy_import.php --archive=/restricted/taxonomy.zip --dry-run --pretty`

   `php scripts/safety/eccairs_taxonomy_import.php --archive=/restricted/taxonomy.zip --organization-id=1 --user-id=<id> --activate`

3. Confirm the actual E2 URLs and endpoint paths from the onboarding contract.
4. Set reporting entity, responsible entity, taxonomy/general versions, and
   enable only Sandbox.
5. Import a human-approved mapping manifest with:

   `php scripts/safety/eccairs_mapping_import.php --manifest=/restricted/mapping.json`

6. Install the XSD 1.1 validator dependency:

   `python3 -m pip install -r scripts/safety/requirements-eccairs.txt`

7. Run contract tests, serializer tests, and a mocked adapter test.
8. Prepare and approve test occurrences in Sandbox.
9. Schedule the worker:

   `php scripts/safety/eccairs_worker.php --environment=sandbox --max-jobs=20 --reconcile`

10. Repeat in UAT and obtain written Safety Manager and privacy/security
   acceptance.
11. Enable `production_transmission_enabled` only after EASA confirms production
   access and IP allowlisting.

## Delivery semantics

The database prevents duplicate canonical versions and uses locked queue claims.
Approval binds the canonical digest. REST JSON and E5X XML are deterministic,
separately hashed artifacts derived from that approved snapshot.
REST-only reporting entity, status, and general-version fields are frozen in a
separately hashed envelope snapshot at preparation time. They are not part of
the human-approved canonical occurrence, and live connection changes cannot
silently alter a queued request.
An E2 success stores the remote E2 ID, version, status, response digest, and an
immutable status observation.

E2 does not document an IPCA-controlled idempotency key. If a transport failure
or server error leaves delivery uncertain, the worker stops automatic retries.
A Safety Manager must verify that no matching report exists in E2, record the
verification reference, and explicitly authorize a retry.

HTTP 429 responses use bounded exponential retry. Validation/client rejections
remain rejected until corrected and prepared as a new payload version.

## E5X fallback

The exporter verifies the approved canonical digest, serializes ECCAIRS XML
using names and datatypes imported from the official taxonomy package, and
validates it using the package's XSD 1.1 schemas.

The supplied taxonomy archive does **not** define the `.e5x` container format.
Therefore, validated XML generation is available, but `.e5x` packaging remains
fail-closed until the authority supplies or confirms a packaging profile.
Configure that profile through `CW_ECCAIRS_E5X_PACKAGER_COMMAND` and
`CW_ECCAIRS_E5X_PACKAGER_PROFILE`; the adapter receives `--xml` and `--output`
arguments. Only a successful adapter result creates the E5X artifact:

`php scripts/safety/eccairs_worker.php --export-uuid=<uuid> --output=/restricted/report.e5x`

The exported archive still requires controlled manual submission and evidence
of the resulting E2 identifier.

## Historical taxonomy correlation

Historical reports can be classified against an imported taxonomy package
without becoming E2 submissions. Proposed entity, attribute, and value codes are
validated against immutable imported taxonomy rows and enter a separate review
queue. A different Safety Manager must approve or reject each proposal.

There is deliberately no foreign key or workflow transition from
`ipca_safety_eccairs_historical_correlations` to the ECCAIRS submission queue.
Historical approval normalizes analytics only; it never transmits an occurrence.

## Legacy PDF extraction

Run aggregate preflight without storing content:

`php scripts/safety/legacy_pdf_extract.php --source-dir=/restricted/files --pretty`

Stage structured content only in the restricted migration table by adding
`--stage`, `--batch-uuid`, and `--organization-id`. OCR is local. Standard
output contains aggregate counts only.

Human review compares each source PDF with its staged structured payload. The
review decision is recorded with `--review-id`, `--reviewer-id`, `--decision`,
and a restricted `--notes-file`. Original-source destruction remains a separate
authorized retention action.
