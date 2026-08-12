# IPCA Manual Reader (iPad)

Native iPad e-reader for controlled OM/OMM manuals. The app consumes **frozen page maps** from the IPCA Courseware server and renders each page with the same stylesheets as the web reader (`controlled_book_editor.css` + `manual_reader.css`). **No editor layout code is modified** — pagination fidelity comes entirely from the server-side frozen HTML.

## Features (v1)

- Library shelf (released manuals only)
- One frozen page per screen — deterministic pagination (816×1056 Letter profile)
- Swipe / button page navigation with adjacent-page prefetch
- Table of contents with page numbers
- Section title search
- Reading progress sync (same API as web reader)
- Local bookmarks per manual
- Themes: Light, Sepia, Dark
- Zoom: Fit Width, Fit Page, 75%, 100%, 125%
- Page scrubber (filmstrip)

## Requirements

- iPad, iOS 17+
- Xcode 15+
- IPCA Courseware server with:
  - Released manual version
  - Approved frozen page map (`php scripts/qa_om_page_map.php`)
  - User account with manual reader access (student, instructor, chief instructor, admin)

## Open in Xcode

```bash
open ipca-manual-reader-ios/IPCAManualReader.xcodeproj
```

Set your **Development Team** in Signing & Capabilities, then run on an iPad simulator or device.

## First launch

1. Enter your IPCA server URL (e.g. `https://courseware.europilotcenter.com`)
2. Sign in with your IPCA email/password
3. Open a manual from the library

Session cookies (`CWSESS`) are stored by `URLSession` and reused for API calls.

## API endpoints used

| Endpoint | Purpose |
|----------|---------|
| `POST /student/api/manual_reader_auth_api.php?action=login` | Sign in |
| `GET  ...?action=session` | Restore session |
| `GET  /student/api/manual_reader_api.php?action=library` | Shelf |
| `GET  ...?action=page_map&book=OM` | Page index |
| `GET  ...?action=page&page_number=N` | Frozen page HTML |
| `GET  ...?action=toc_with_pages` | Contents + page numbers |
| `GET/POST ...?action=progress_*` | Reading position |
| `GET  ...?action=search_titles&q=` | Search |

## Layout fidelity principle

```
Server frozen page HTML  +  editor CSS  +  reader CSS  =  pixel-identical page
```

The app never re-paginates or reflows content locally. If a page looks wrong on iPad, fix pagination/CSS on the server and regenerate the page map — not in this app.

## Project structure

```
IPCAManualReader/
  Models/           API types, reader settings
  Services/         API client, session, page cache
  ViewModels/       Library + reader state
  Views/            SwiftUI screens, WKWebView page renderer
  Theme/            Colors and scale helpers
```

## Roadmap

- [ ] Offline page pack download
- [ ] Full-text search across page bodies
- [ ] Apple Pencil annotations (compliance review)
- [ ] TestFlight distribution
