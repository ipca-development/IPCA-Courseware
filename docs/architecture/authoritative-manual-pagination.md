# Authoritative manual pagination

## Migration target: `live-authoritative-flow-v1`

The staged WYSIWYG migration targets one continuous authoritative source whose
ordinary content flows automatically. Manual Page Breaks are explicit page-start
overrides, projected fragments remain read-only views of source objects, and
`MANUAL_BREAK_REQUIRED` is not part of ordinary authoring overflow.

Phase B adds revision-safe live draft-map infrastructure only. It does not
activate or label the target engine before automatic-flow behavior is proven in
the later projection phases. The currently deployed engine policy remains
documented below during that transition.

Canonical controlled-manual flow:

`Author edits one continuous source document → author inserts Manual Page Breaks → server validates/generates those pages (`manual_segments_v1`) → Exact Page Preview shows stored page_html → release freezes the map → iOS displays the same pages`

Ordinary page boundaries are author-controlled. The server does not invent ordinary page breaks.

## Policy: `manual_segments_v1`

Each persisted manual-break segment is one intended authoritative page.

- If the segment fits the Book Style body: emit exactly one page.
- If multiple ordinary **author-editable** blocks exceed the body: return first-class `MANUAL_BREAK_REQUIRED`. Do not persist a partial page map. Do not insert the break.
- `pagination_authority` is `generated` or `author`. Part 0 always has generated/automatic pagination authority, including author-editable sections such as 0.2 Revision System. Outside Part 0, authority is derived from the publishing model (`is_generated`, `is_system_managed`, `allow_author_blocks`, and per-block `is_system_managed`), not from section titles.
- Generated/system-owned/non-editable content auto-paginates: fill the body, continue in source order on the next page, repeat the controlled header/footer/page number, and never return `MANUAL_BREAK_REQUIRED`. Continuation pages are not persisted as Manual Page Breaks.
- `MANUAL_BREAK_REQUIRED` is valid only for author-controlled content outside Part 0 where the author can insert a Manual Page Break.
- Long-table row presentation and a single oversized author block that cannot fit an empty page may still continue. Generated and table continuation leftover space must not absorb following author-editable blocks.
- Generated TOC/LEP/table rows are measured as content fragments inside the authoritative body frame. They must not inherit page width, page height, header/footer geometry, or publication sheet min-height. Measurement width equals the contentFrame width.
- Every semantic source block appears exactly once, in source order, except presentation-only repeated table headers.
- Released page maps remain immutable. Draft maps using the previous engine version are stale.

## Runtime

The server worker is `scripts/authoritative_manual_paginator.cjs`. It requires the
Playwright package in `scripts/garmin/node_modules` and a Chromium runtime:

```bash
npm ci --prefix scripts/garmin
npx --prefix scripts/garmin playwright install chromium
```

Set `CW_PAGINATION_NODE` when the production Node executable is not available as
`node`. Set `CW_PAGINATION_CHROMIUM_EXECUTABLE` to use an explicitly managed
Chromium/Chrome binary. Otherwise the worker tries bundled Chromium first and
the installed Chrome channel second. Generation fails closed if the browser,
publication CSS, or final page validation is unavailable.

Apply `scripts/sql/2026_08_13_publishing_manual_page_breaks.sql` before enabling
cursor-based manual breaks.

## Phase B live generation

The additive `live_ensure`, `live_status`, and `live_retry` page-map API actions
use `ControlledPublishingLivePageMapService`. The original `generate` action
remains synchronous and unchanged.

Live generation is revision-safe:

1. `ipca_publishing_page_map_generation_state` has one coalescing row per
   version/layout profile. A monotonic `generation_seq` and expiring lease make
   duplicate HTTP requests and abandoned workers safe.
   While a lease is active, all edits replace one newest-pending fingerprint
   slot. They do not change the active sequence/lease or spawn Chromium.
2. The HTTP action only records `pending` and starts the CLI worker. Chromium
   runs in `scripts/controlled_publishing_page_map_worker.php`, not the request.
3. Generated pages first enter
   `ipca_publishing_reader_page_map_staging`. Readers never query this table.
4. Promotion locks the state row, CAS-checks generation sequence and lease,
   then re-reads and compares the complete current authoritative fingerprint
   (source, style, manifest, layout, manual breaks, header/footer, and engine).
5. Only a matching candidate replaces production in one transaction. Failed
   and stale candidates can update status/cleanup staging but never delete or
   replace the last valid production map.
6. A stale, failed, or timed-out active run atomically advances to the newest
   pending sequence. The scoped CLI worker drains exactly that one follow-up
   immediately, without polling; intermediate fingerprints are discarded.

Single-flight is server-authoritative. Workers must acquire a non-blocking
MySQL named lock before claiming a DB lease. Named locks are connection-owned,
so success/error paths release them and a crashed process releases its lock
when its DB connection closes; an expired lease can then be reclaimed. The
worker timeout is shorter than the lease, and generation sequence plus lease
token fence all staging and promotion.

The coalescing identity is `(book_version_id, layout_profile)`. Different book
versions have independent locks and may paginate concurrently. Different
profiles of the same version are intentionally serialized at the server-lock
layer because the legacy version metadata currently has one
`reader_page_map` approval slot, not one slot per profile. Their DB state and
staging remain profile-specific, and the waiting profile runs afterward.

Statuses are `current`, `pending`, `generating`, `stale`, `failed`, and
`retry_available`. The latter means a generation failed while a last valid map
is still available.

Apply the idempotent Phase B migration before enabling these actions:

```bash
php scripts/apply_publishing_live_page_map_generation.php
```

## Editorial workflow

Open a controlled book version in the Editor and edit the continuous source
document. Visual page spacers in the editor are advisory only.

A Manual Page Break command persists a stable `before_block_anchor`. Exact Page
Preview at `public/admin/compliance/controlled_book_page_preview.php` renders
stored `pages[].page_html` only. Viewing never regenerates or reinterprets
content. Approve the stored map before release.

Release is blocked unless the approved map matches current source, style,
manifest, layout, header/footer, and manual-break hashes. Released maps cannot
be regenerated, invalidated, or edited.

## Verification

```bash
php tests/controlled_book_editor_44df02b9_parity_gate_check.php
node tests/controlled_book_editor_phase_b_browser.mjs
php tests/authoritative_pagination_architecture_contract_check.php
php tests/controlled_publishing_live_page_map_check.php
node tests/authoritative_pagination_worker_check.js
```
