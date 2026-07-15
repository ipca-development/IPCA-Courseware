# IPCA Sync Agent

`IPCA Sync Agent` is a native macOS menu-bar application for the always-online IPCA Desktop Mac. It connects to Garmin FlyGarmin through visible local Google Chrome Stable, downloads original Garmin source files, and uploads them idempotently to IPCA.training.

## Build

Open:

```text
ipca-sync-agent-macos/IPCASyncAgent.xcodeproj
```

In Xcode:

1. Select the `IPCASyncAgent` scheme.
2. Confirm automatic signing uses team `W9RY547Y4P`.
3. Build.
4. Copy `IPCA Sync Agent.app` to `/Applications`.

## First Run

1. Double-click `IPCA Sync Agent.app`.
2. Paste the manually issued IPCA Sync Agent device token once.
3. Click `Save Token`.
4. Click `Validate Token`.
5. Click `Connect Garmin`.
6. Complete Garmin login, MFA, and any human verification in the visible Chrome window.
7. Wait until the Garmin Logbook is visible.
8. Click `I’m on the Garmin Logbook`.
9. Click `Sync Now`.
10. Enable `Launch at Login`.

## Local Storage

- Garmin Chrome profile:
  `~/Library/Application Support/IPCA Sync Agent/GarminChromeProfile`
- Durable queue:
  `~/Library/Application Support/IPCA Sync Agent/sync-agent.sqlite`
- Garmin artifacts:
  `~/Library/Application Support/IPCA Sync Agent/Artifacts/Garmin/`
- Logs:
  `~/Library/Logs/IPCA Sync Agent/`

## Safety Rules

- The app never reads the user’s normal Chrome profile.
- The app never uploads Garmin cookies, credentials, MFA values, browser profile data, localStorage, sessionStorage, or challenge tokens.
- The app does not use stealth plugins, browser fingerprint spoofing, user-agent spoofing, or CAPTCHA bypass.
- Downloaded Garmin source files are retained locally and uploaded with idempotency keys.

## Server Token

Version one expects a manually issued scoped bearer token created by IPCA.training administration. The token must include these scopes:

- `sync_agent.status`
- `sync_agent.garmin_entries`
- `sync_agent.garmin_source`
- `sync_agent.garmin_sync_complete`

The Mac stores the token in Keychain only.
