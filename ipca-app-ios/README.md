# IPCA (iPhone / iPad)

Native daily IPCA application. Phase 1 is the messaging foundation: sign in with IPCA.training, 1:1 and group text, durable outbox, incremental sync.

## Open

```
open ipca-app-ios/IPCA.xcodeproj
```

Set the Development Team, then run on an iPhone simulator or device. iPad is supported with split conversation navigation.

## First launch

1. Sign in with your IPCA email and password.
2. Messages opens as home.
3. Community and Training stay hidden until server flags enable them.

DEBUG builds show a server URL field. Production uses `https://courseware.europilotcenter.com`.

The session token is stored in Keychain, never UserDefaults.

## Phase 1 APIs

| Endpoint | Purpose |
|----------|---------|
| `POST /api/communication/auth.php` | Login / logout |
| `GET  /api/communication/bootstrap.php` | User, device, capabilities |
| `GET  /api/communication/sync.php?cursor=` | Incremental sync |
| `POST /api/communication/conversations.php` | Direct / group |
| `POST /api/communication/messages.php` | Idempotent send |
| `POST /api/communication/receipts.php` | Read cursor |
| `GET  /api/communication/directory.php` | People search |

Apply `scripts/sql/2026_08_13_communication_phase1.sql` on the Courseware database before pointing the app at a server.
