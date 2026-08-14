# Authoritative manual pagination

Canonical controlled-manual flow:

`Author edits one continuous source document → author inserts Manual Page Breaks → server validates/generates those pages (`manual_segments_v1`) → Exact Page Preview shows stored page_html → release freezes the map → iOS displays the same pages`

Ordinary page boundaries are author-controlled. The server does not invent ordinary page breaks.

## Policy: `manual_segments_v1`

Each persisted manual-break segment is one intended authoritative page.

- If the segment fits the Book Style body: emit exactly one page.
- If multiple ordinary blocks exceed the body: return first-class `MANUAL_BREAK_REQUIRED`. Do not persist a partial page map. Do not insert the break.
- Automatic continuation is allowed only for generated TOC, generated LEP/List of Effective Pages or Parts, long-table row presentation, and a single oversized source block that cannot fit an empty page.
- TOC, LEP, and long-table continuation pages are scoped to that object. Following ordinary blocks must not flow onto them.
- Generated LEP remains one logical object: fill the body, continue rows/items in order on the next page, and repeat the controlled header/footer. Do not require or persist Manual Page Breaks inside LEP. Ordinary Part 0 content outside LEP stays author-controlled.
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
`node`. Generation fails closed if the browser, publication CSS, or final page
validation is unavailable.

Apply `scripts/sql/2026_08_13_publishing_manual_page_breaks.sql` before enabling
cursor-based manual breaks.

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
php tests/authoritative_pagination_architecture_contract_check.php
node tests/authoritative_pagination_worker_check.js
```
