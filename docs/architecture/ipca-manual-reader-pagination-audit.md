# IPCA Manual Reader Pagination Audit

## Scope

The reader pagination architecture is a read-only consumer of existing controlled
manual content. It must not mutate authoring payloads, block ordering, revision
history, or Admin Manual Editor behavior.

## Current data path

1. The Admin Manual Editor stores ordered sections and typed blocks in
   `ipca_publishing_book_sections` and `ipca_publishing_book_blocks`.
2. `ControlledPublishingFoundationService` manages version lifecycle, source
   baselines, and release identity in `ipca_publishing_book_versions` and the
   related source-set/baseline tables.
3. `ControlledPublishingBookRenderer` renders existing block payloads.
4. `ControlledPublishingPaginationService::buildPaginateSource()` exposes
   rendered section units, read-only header/footer templates, layout metadata,
   stable section anchors, and navigation metadata.
5. `manual_reader_api.php?action=paginate_source` exposes that source to
   authorized reader users. Official frozen references remain in
   `ipca_publishing_reader_page_maps`.
6. `ManualDownloadManager` stores the paginate source, frozen fallback pages,
   TOC, styles, and assets in `OfflineManualPackage`.
7. `HTMLPaginationEngine` normalizes and paginates the source in a hidden
   WKWebView. `ReaderViewModel` owns the generated personal page map.
8. `ManualPageWebView`, `BookPageCurlView`, and `ReaderView` display one portrait
   page or a paired landscape spread.
9. `ReaderViewModel`, `ManualReaderSessionStore`, and the reader APIs provide
   TOC, search, bookmarks, settings, and official progress persistence.

## Authority boundaries

- Stored block payloads and ordering are authoritative manual content.
- Version/baseline/release rows are authoritative controlled-publication state.
- Official frozen page metadata is the authoritative official-document
  reference and migration fallback.
- The versioned reader normalizer and pagination engine are authoritative only
  for personal reader-page presentation.
- AI QA is advisory and cannot mutate content, pagination, or publication state.

## Protected Admin Editor boundary

The following baseline files are immutable for this reader project:

| File | SHA-256 baseline |
| --- | --- |
| `public/admin/compliance/controlled_book_editor.php` | `459a755e2aef5802f7bbc9c9679619252596a7705a9629bb09a5f8cf998219b7` |
| `public/assets/controlled_book_editor.js` | `d4c1837ffd706e2477af4a75c2f8cade0d37c7fb370835137ec1e82b0b273f33` |
| `public/assets/controlled_book_editor.css` | `66220ae05054673ee9814a0b75da0eefc609148a208fed6dfe523f8241eacdeb` |
| `public/admin/api/controlled_book_editor_api.php` | `86ac508039fb617d467f22c6b81101475d548740b57d07963a16fe2d137b2df2` |
| `src/publishing/ControlledPublishingBlockService.php` | `d63175aa9e0292b9137f27a38eeb913337e6af8ab5e6086cdf4207385474f265` |
| `src/document/StructuredDocumentPayload.php` | `71bcbba2711d9158d952c8042af6439dc8df1697318da877f6e2225eca80a805` |
| `src/publishing/ControlledPublishingBookRenderer.php` | `31b2bfbcb20f982c1ec0db326ab138112fec433cba0123cc62ee1f8d5a09d18e` |

No reader implementation step may change these files. A regression check verifies
the hashes before completion.

## Safe extension points

- `paginate_source` response metadata, without changing stored blocks.
- Reader-only Swift models, normalizer, paginator, cache, and presentation.
- Reader API access for isolated preview grants.
- Separate pagination QA services, tables, APIs, workers, and admin screens.
- DEBUG/QA-only iOS capture and diagnostics hooks.

## Required pagination invariant

Every normalized source semantic fragment must be represented exactly once and
in original source order across generated personal reader pages. Split text
fragments use non-overlapping contiguous source ranges whose union covers the
whole source fragment. Repeated table headers are explicitly marked as
reader-generated presentation copies and excluded from source coverage.

Pagination fails if coverage contains a gap, overlap, duplicate, reordering, or
unknown source fragment.
