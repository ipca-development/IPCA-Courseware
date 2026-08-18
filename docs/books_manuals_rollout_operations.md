# Books & Manuals rollout operations

## Installation

1. Back up the database.
2. Apply `scripts/sql/2026_08_18_books_manuals_workflow.sql`.
3. Apply `scripts/sql/2026_08_18_books_manuals_library_ux.sql`.
4. Apply `scripts/sql/2026_08_18_books_manuals_compliance_overrides.sql`.
5. Run:
   - `php tests/books_manuals_protected_boundary_check.php`
   - `php tests/books_manuals_workflow_contract_check.php`
6. Open `/admin/books_manuals/index.php` as an administrator.

The migration is additive. Existing controlled-publishing tables, editor routes and
released content remain authoritative. Existing released versions receive the same
reader-role visibility they had before the migration.

## Lifecycle rollback

- Revert Draft Review to Draft from the manual settings page.
- Revert Awaiting Approval to Draft Review from the same page.
- Approved versions are immutable; create a new revision instead of changing them.
- A legacy approval override is one-time and immutable. It requires an administrator
  rationale and preserves the source fingerprint plus all release blockers as they
  existed when the new revision was created.
- To disable the new navigation without altering data, revert the Books & Manuals
  entry in `src/nav/admin.php`. Legacy Compliance routes remain operational.

Database rollback is intentionally non-destructive: archive or export the six
`ipca_publishing_*` Books & Manuals tables before dropping them. Never drop
`ipca_publishing_books`, versions, sections, blocks, release snapshots, page maps,
or any canonical/MCCF source table.

## Annex migration gate

Generate a dry-run report:

`php scripts/migrate_books_manuals_annexes.php --dry-run --output=tmp/books-manuals-annexes.json`

Review its inventory, source hashes, target keys, validation fields and rollback
mapping. Production content is not changed by this command.

Only after explicit approval, apply that exact manifest:

`php scripts/migrate_books_manuals_annexes.php --apply --approved-manifest=tmp/books-manuals-annexes.json --actor-user-id=<admin-id> --output=tmp/books-manuals-annexes-applied.json`

Apply mode rejects modified or stale manifests. It does not delete embedded annex
sections. To roll back a migrated annex, set the standalone annex book to
`inactive` and set its link to `rolled_back`; the preserved embedded section
remains available. Keep the apply report as the rollback record.

## Release checks

Before approval or authority submission:

- MCCF integrity coverage must be complete.
- The source baseline must be frozen.
- The authoritative page map must pass the existing release check.
- The latest immutable audit snapshot must match the current source fingerprint.

If a check fails, revert to an editable phase, correct the source, regenerate only
the affected authoritative map, refresh MCCF scores, and create a new snapshot.
