# Legacy SMS migration reconciliation

Status: **preflight only; migration is blocked**  
Artifact classification: aggregate metadata only; no report narratives, attachment
names, SQL values, source paths, or credentials

## Scope and safety boundary

This reconciliation covers the supplied legacy Safety Management System SQL dump
and uses the supplied PHP package only as an optional attachment-search root.
The repository must not contain a copy of either source.

The legacy PHP package contains an exposed database credential. That credential
was not copied, read by the verifier, or included in any artifact. It must be
revoked/rotated in the external database and secret-management environment.
Repository work cannot complete that rotation, so credential rotation remains
an external release blocker.

Safety-report narratives and attachment names are sensitive. Any later import
must process them only in an approved restricted environment. Logs, CI output,
exceptions, fixtures, and review artifacts must remain aggregate-only.

## Repeatable read-only check

Run:

```sh
php scripts/safety/legacy_sms_preflight.php \
  --dump=/absolute/path/to/legacy.sql \
  --legacy-root=/absolute/path/to/legacy/php/package \
  --pretty
```

The verifier:

- parses static MySQL `INSERT ... VALUES` statements without executing SQL;
- opens no database connection and performs no writes;
- emits one aggregate JSON document to standard output;
- does not emit source paths, field values, report text, attachment names, or
  credentials;
- uses `--legacy-root` only to test whether referenced PDF candidates exist.

The source fingerprint used for this reconciliation is:
`a28a4256facfdf325c97cdba7485c5906cf02533779583030659dfbe3eb8d5a9`
(201,265 bytes). A different fingerprint requires a fresh review.

## Reconciliation result

The checked-in aggregate baseline is
`docs/safety/legacy_sms_preflight_baseline.json`.

Key findings:

- Expected entity counts reconcile for 246 hazards, 9 categories, 48
  undesirable events, 123 reports, and 139 hazard relationships.
- The report relationship table contains **152 rows, not the expected 153**.
  This discrepancy must be resolved against an authoritative source before
  import.
- ID-zero relationships are present as expected: 19 hazard relationships and
  18 report relationships. They are orphaned and must be quarantined, not
  converted to a real foreign key.
- Duplicate relationship excess is 4 hazard rows and 8 report rows.
- Eight nonblank report date values are malformed; blank dates are reported
  separately.
- Eight report records contain 13 mojibake indicators.
- Legacy report 96 is flagged blank, with 6 blank payload fields.
- Thirty reports reference PDFs, and none of those references resolve in the
  supplied package.
- 126 hazards are default or incomplete. This includes 126 blank descriptions,
  125 blank defence fields, and 125 records retaining the legacy form's default
  risk profile.

## Migration mapping

The mapping is intentionally backend-neutral so it can feed the canonical
schema selected by the backend migration.

- `haz_cat.cat_id` → category legacy key; `cat_name` → category name.
- `haz.haz_id` → hazard legacy key; `haz_cat` → category legacy key;
  `haz_name`/`haz_desc` → hazard title/description.
- `haz_likely`, `haz_sev`, and `haz_res` → inherent likelihood, severity, and
  risk code.
- `haz_def`, `haz_d_likely`, `haz_d_sev`, and `haz_d_res` → controls/defences
  and residual likelihood, severity, and risk code.
- `haz_ref` and `haz_com` → restricted legacy reference/comment fields. Do not
  place their values in migration logs.
- `ud.ud_id` → undesirable-event legacy key; `ud_name` → event name;
  `ud_issued`/`ud_updated` → legacy Unix timestamps; `ud_cictt`, `ud_state`,
  and `ud_risk` → classification/state/risk attributes.
- `ocr.or_id` → report legacy key; title, dates, description, actions, and
  mitigation → restricted report fields; `or_file` → restricted attachment
  reference.
- `map_ud_haz` → undesirable-event/hazard many-to-many relationship.
- `map_ud_or` → undesirable-event/report many-to-many relationship.

Canonical rows should retain a non-secret `legacy_source = legacy_sms1` and
the source table/key in dedicated migration metadata. Legacy numeric keys
should not be reused as canonical primary keys.

## Import gates and cleanup policy

Before an import can be approved:

1. Rotate/revoke the exposed credential externally and record confirmation
   outside this repository.
2. Reconcile the expected 153 report relationships against the 152 rows in the
   supplied dump.
3. Quarantine all ID-zero relationships with aggregate reason counts.
4. Deduplicate relationships by their two legacy foreign keys before applying
   canonical unique constraints.
5. Resolve or explicitly waive each malformed date; do not silently coerce it.
6. Repair text encoding from a preserved restricted source and verify the
   aggregate mojibake count reaches zero.
7. Review report 96 and default/incomplete hazards with the safety owner.
8. Recover referenced PDFs from an authoritative store, verify checksums, and
   use opaque canonical attachment identifiers.
9. Run the verifier again and bind any approved import manifest to the source
   SHA-256 and aggregate result.

The canonical foundation migration now includes restricted
`ipca_safety_legacy_staging` and `ipca_safety_import_provenance` tables. Loading
and promotion tooling is implemented in
`scripts/safety/legacy_sms_import.php`, but production promotion remains blocked
until every gate above is approved.
The source dump must never be executed directly against the application
database; an approved importer must parse it as data, stage rows with hashes and
validation state, and record immutable source-to-target provenance.

Run the importer in three explicit phases:

1. Read-only parser preflight:
   `php scripts/safety/legacy_sms_import.php --mode=preflight --dump=/restricted/legacy.sql --pretty`
2. Restricted staging:
   `php scripts/safety/legacy_sms_import.php --mode=stage --dump=/restricted/legacy.sql`
3. Human review manifest followed by selective promotion:
   `php scripts/safety/legacy_sms_import.php --mode=review --manifest=/restricted/review.json --user-id=<id>`
   and
   `php scripts/safety/legacy_sms_import.php --mode=promote --batch=<uuid> --user-id=<id>`.

For the supplied dump, the importer parses all 717 rows. Its narrow structural
promotion check marks 716 rows validated and quarantines one blank report row. This
does not waive the broader reconciliation gates above: ID-zero relationships,
duplicates, the missing relationship, mojibake, default hazards, PDF matching,
and retention approval remain human decisions. Promotion currently creates only
approved archived reports, corresponding unassessed historical occurrence
rows, and hazards; records source-to-target provenance,
marks imported reports restricted, and records that zero ECCAIRS transmissions
were queued.

## Recovered candidate PDF set

A later restricted source set contains 34 sequentially named occurrence PDFs.
Aggregate-only local extraction found:

- 22 text-based documents and 12 requiring local OCR;
- no documents blank after extraction;
- one eight-page bundle requiring page segmentation during human review;
- 31 documents with a candidate title and 29 with a candidate narrative;
- 32 documents containing potential personal-data indicators.

The SQL dump still contains 30 attachment references that do not resolve by
their stored names, even when this source directory is supplied to the
preflight verifier. Therefore, the 34 files are recovered candidates, not
automatically proven matches for those 30 references.
No extractable internal report number corroborates the sequential filename, so
the text-based files have medium mapping confidence and OCR files have low
mapping confidence until matched against an authoritative register.

Use `scripts/safety/legacy_pdf_extract.php` to stage structured data with source
and payload hashes. A human reviewer must establish each source-to-report match
and approve the extraction before canonical promotion. These documents are
historical migration sources only and cannot enter the ECCAIRS submission
queue.
