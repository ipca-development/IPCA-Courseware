# AI Manual Change Assistant operations

## Feature and limits

- `CW_MANUAL_AI_ENABLED=0` disables every project page and API operation. Set it to `1` only for an accepted rollout cohort.
- `CW_MANUAL_AI_APPLY_ENABLED=0` is the independent controlled-write kill switch and defaults off. Set it to `1` only after read-only acceptance.
- `CW_MANUAL_AI_MANIFEST_KEY` must contain a private high-entropy key before controlled apply can be enabled; approved manifests are HMAC-SHA256 signed.
- `CW_MANUAL_AI_DAILY_JOB_LIMIT` controls per-user analysis starts (default `20`, maximum `200`).
- `CW_MANUAL_AI_CHUNK_LIMIT` limits the selected live manual blocks per project (default `15000`, maximum `50000`).
- `CW_OPENAI_EMBEDDING_MODEL` selects the embedding model (default `text-embedding-3-small`).
- Uploaded originals and extracted text are stored below `storage/manual_change_assistant/project_<id>/`, outside the public document root, with `0660` file permissions.
- The application limit is 20 sources, 20 manual versions and 20 MB per source.

## Install and worker

1. Run `php scripts/apply_ai_manual_change_assistant.php`.
2. Verify with `php tests/books_manuals_change_assistant_contract_check.php`.
3. Process queued work with `php scripts/books_manuals_change_assistant_worker.php --drain`. Web requests also attempt to launch a one-shot worker.
4. Monitor `ipca_manual_ai_jobs` for `retry` or `failed` and `storage/logs/manual_change_assistant_<project>.log` for worker diagnostics.

Jobs are idempotent for the project source and scope fingerprints, use a ten-minute lease, and retry three times with bounded exponential delay.

## Retention

- Retain project records, decisions, manifests and audit events according to the controlled-publication audit policy.
- Review source files after 90 days. Delete private source files only after the corresponding project is archived and the authority/audit retention owner has approved disposal.
- Never copy private uploads into `public/`, public media storage, logs, prompts beyond the bounded evidence window, or support tickets.
- Database deletion cascades project analysis rows but does not delete private files automatically; file disposal must be a separately logged operational action.

## Rollout gates

1. Enable the hero and persistent advisory projects for compliance administrators.
2. Validate direct terminology campaigns before relying on semantic results.
3. Accept uploaded-document extraction, citations, and consistency/conflict quality.
4. Enable controlled apply operationally only after reviewers accept stale-hash rejection, audit evidence, revision creation, Highlight of Changes, page-map refresh, and MCCF refresh.

Approved and released versions remain searchable evidence. They are never writable. Applying a finding against one returns a revision-required result; the workspace offers an explicit governed draft creation and requires re-analysis.

## Agreement validation (2026-08-18)

The supplied `Integrated Operational Support & Training Agreement Rev 1.0.txt` produced 13 deterministic normative requirements without a provider. A read-only terminology dry run against current OM 7.0, OMM 5.0, and TM_GEN 2.0 found candidate evidence in all three manuals. These counts demonstrate retrieval reach, not completeness or approval; reviewers must assess exact block citations in a deployed project.

## Rollback

Set `CW_MANUAL_AI_ENABLED=0` to remove access immediately. Existing Books & Manuals, editor, reader, lifecycle, and MCCF routes do not depend on the assistant tables. Keep the additive tables and private evidence for audit retention; do not drop them as an emergency rollback.
