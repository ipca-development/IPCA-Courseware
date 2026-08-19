# IPCA Scheduling for iPhone

Phase 2 is a read-only SwiftUI client for the canonical IPCA scheduler.

## Scope

Implemented:

- Communication bearer-token login and Keychain session restoration
- scheduler bootstrap and server-derived capabilities
- personal Today view
- organization-scoped chronological Schedule view
- reservation details and server warning presentation
- staff-only aircraft/person/cohort/type filters
- operational-local timezone handling
- pull-to-refresh and five-minute foreground refresh
- atomic disk JSON cache with explicit stale/offline state
- account details and logout

Intentionally deferred:

- reservation create/edit/reschedule/cancel
- dispatch, undispatch, and manual check-in
- ADS-B, cockpit audio, messaging, and notifications
- offline writes
- iPad-specific interface

The client contains no scheduling conflict or availability rules.

## Architecture

- `SchedulingSession`: `@MainActor` launch, auth, schedule, cache, and navigation state
- `SchedulerAPIClient`: actor-isolated URLSession transport
- `SchedulerModels`: Codable representations of `docs/scheduler_api_v1.md`
- `SchedulerClock`: timezone-free operational-local parsing and presentation
- `ScheduleDiskCache`: actor-isolated range cache under Application Support
- SwiftUI views/components: Login, Today, Schedule, Filters, Details, More

The production API base URL is `https://ipca.training`.

## Build and test

```bash
xcodebuild test \
  -project ipca-scheduling-ios/IPCAScheduling.xcodeproj \
  -scheme IPCAScheduling \
  -sdk iphonesimulator \
  -destination 'platform=iOS Simulator,name=iPhone 16 Pro,OS=18.5' \
  -configuration Debug \
  CODE_SIGNING_ALLOWED=NO
```

The XCTest target covers authentication, restoration, capabilities, Today
grouping, schedule ranges and filters, reservation details, PST/PDT/DST,
malformed responses, server failure, disk cache, and offline restart.

## Visual review fixtures

Production-safe fixture screens can be launched without contacting the server:

```bash
xcrun simctl launch booted com.ipca.scheduling --ui-preview today
```

Supported values: `login`, `today`, `schedule`, `details`, and `filters`.
Captured simulator images are in `Screenshots/`.

## Existing unrelated failure

`tests/cvr_schedule_duty_supersession_contract_check.php` has an existing
failure involving untouched CVR Swift files. Phase 2 does not modify those
files or suppress that failure.
