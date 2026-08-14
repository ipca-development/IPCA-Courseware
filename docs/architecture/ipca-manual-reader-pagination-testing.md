# Manual Reader Pagination Testing

## Deterministic WKWebView regression suite

The Debug app contains a launch-only self-test route that uses the production
`HTMLPaginationEngine`, `ReaderPaginationCore.js`, and generated reader content
stylesheet. It is unavailable in Release builds.

The suite covers:

- iPad landscape two-page geometry
- iPad portrait geometry
- iPhone portrait geometry
- default and large-font re-pagination
- identical-input repeatability
- semantic-location restoration after re-pagination
- separate official-document and personal-reader page identity
- heading keep-with-next behavior
- long paragraphs with source-range coverage
- short and oversized NOTE/WARNING/CAUTION blocks
- short and oversized list items
- tables, repeated headers, and oversized row continuation
- image/caption grouping and proportional scaling
- historical container normalization
- multi-page TOC rows
- cover pages
- fixed header/body/footer coordinates
- overflow, blank page, orphan heading, and exactly-once source coverage checks

Run it on a booted simulator:

```bash
xcodebuild -project ipca-manual-reader-ios/IPCAManualReader.xcodeproj \
  -scheme IPCAManualReader -sdk iphonesimulator -configuration Debug \
  CODE_SIGNING_ALLOWED=NO build

xcrun simctl install booted \
  ~/Library/Developer/Xcode/DerivedData/IPCAManualReader-*/Build/Products/Debug-iphonesimulator/IPCAManualReader.app

xcrun simctl launch --console --terminate-running-process booted \
  com.europilotcenter.IPCAManualReader --pagination-self-test
```

Success emits `PAGINATION_SELF_TEST_PASS`.

## Real-manual source regression

Exporting source is read-only and never updates manual content:

```bash
php scripts/export_reader_paginate_source.php \
  --book=OM \
  --output=/tmp/ipca-om-paginate-source.json
```

Run the same semantic engine over that source in the three target geometries:

```bash
node scripts/qa_reader_semantic_pagination.js \
  --source=/tmp/ipca-om-paginate-source.json \
  --base-url=https://your-authorized-ipca-host/
```

This browser runner verifies source normalization and deterministic structural
rules against real historical content. It is not a substitute for final
WKWebView screenshots or physical-device validation.

## Architecture checks

```bash
php tests/manual_reader_pagination_architecture_contract_check.php
node --check ipca-manual-reader-ios/IPCAManualReader/Services/ReaderPaginationCore.js
php scripts/build_manual_reader_content_css.php
```

The contract test protects the Admin Editor hashes, verifies that the native
reader does not load Admin Editor CSS, and checks that the independent location,
frame, engine-version, and exactly-once validation contracts remain present.
