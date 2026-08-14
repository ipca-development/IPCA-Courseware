# Authoritative manual pagination

Canonical controlled-manual flow:

`Admin Manual Editor (continuous Print Layout) → Book Style → server browser pagination → approved frozen map → web/iOS`

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
the Editor's paginated Pages mode and manual breaks.

## Editorial workflow

Open a controlled book version in the Editor and edit the continuous document
directly in its stacked physical-page presentation. Automatic page breaks are
editor-only layout artifacts. A cursor-based Page Break command persists a
stable break before the following source block. Browser pagination is refreshed
after edits and the validated server map is approved before release.

Release is blocked unless the approved map matches current source, style,
manifest, layout, header/footer, and manual-break hashes. Released maps cannot
be regenerated, invalidated, or edited.

## Verification

```bash
php tests/authoritative_pagination_architecture_contract_check.php
node tests/authoritative_pagination_worker_check.js
node scripts/qa_publication_geometry_parity.js
```
