# IPCA Scheduling for iPhone and iPad

Phase 2.2 is a read-only SwiftUI client for the canonical IPCA scheduler.
The iPhone remains a quick-access companion; staff receive a purpose-built
iPad scheduling workstation.

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
- cached aircraft/person catalogs for stable offline resource rows
- account details and logout
- staff-only iPad Operations workspace with pinned resource/time axes
- Aircraft, Instructor, and Student resource lenses over canonical reservations
- spatial duration geometry, stable operational windows, and three densities
- adaptive overlap lanes and additive server warning indicators
- current-time line, date navigation, selection, and contextual inspector
- operational reservation search that returns to the timeline
- compact Week overview and adaptive iPad portrait/11-inch layouts

Intentionally deferred:

- reservation create/edit/reschedule/cancel
- dispatch, undispatch, and manual check-in
- ADS-B, cockpit audio, messaging, and notifications
- offline writes

The client contains no scheduling conflict or availability rules.

## Architecture

- `SchedulingSession`: `@MainActor` launch, auth, schedule, cache, and navigation state
- `SchedulerAPIClient`: actor-isolated URLSession transport
- `SchedulerModels`: Codable representations of `docs/scheduler_api_v1.md`
- `SchedulerClock`: timezone-free operational-local parsing and presentation
- `ScheduleDiskCache`: actor-isolated range cache under Application Support
- pure presentation modules: resource projection, geometry, overlap lanes,
  navigation targets, and accessibility summaries
- SwiftUI views/components: iPhone Today/Schedule and iPad Operations canvas,
  inspector, search, Week, filters, details, and account

The production API base URL is `https://ipca.training`.

## Build and test

```bash
xcodebuild test \
  -project ipca-scheduling-ios/IPCAScheduling.xcodeproj \
  -scheme IPCAScheduling \
  -sdk iphonesimulator \
  -destination 'platform=iOS Simulator,name=iPad Pro 13-inch (M4),OS=18.5' \
  -configuration Debug \
  CODE_SIGNING_ALLOWED=NO
```

The XCTest target covers authentication, restoration, capabilities, Today
grouping, schedule ranges and filters, reservation details, PST/PDT/DST,
malformed responses, server failure, disk cache, offline restart, resource
projection, timeline geometry, half-open overlap lanes, stable sparse windows,
search navigation, accessibility summaries, canonical home-base astronomy,
civil-twilight geometry across densities and DST, and stress fixtures.

## Visual review fixtures

Production-safe fixture screens can be launched without contacting the server:

```bash
xcrun simctl launch booted com.ipca.scheduling --ui-preview today
```

Supported workstation values include `workstation-aircraft`,
`workstation-instructor`, `workstation-student`, `workstation-inspector`,
`workstation-warning`, `workstation-full-day`, `workstation-detailed`,
`workstation-week`, `workstation-offline`, `workstation-portrait`,
`workstation-sparse`, `workstation-stress`, `workstation-filters`,
`workstation-expanded`, `workstation-crew`, `workstation-narrow`,
`workstation-week-instructor`, `workstation-week-student`,
`workstation-week-warning`, `workstation-week-sparse`,
`workstation-twilight-morning`, `workstation-twilight-evening`,
`workstation-twilight-full-day`, `workstation-twilight-morning-selected`, and
`workstation-twilight-evening-selected`.

Capture the complete 15-image review set with:

```bash
ipca-scheduling-ios/scripts/capture_phase22_screenshots.sh
```

Captured images are in `Screenshots/Phase2.2/`.

Capture the focused 8-image iPad inspector/filter consistency set with:

```bash
ipca-scheduling-ios/scripts/capture_inspector_consistency_screenshots.sh
```

Captured images are in `Screenshots/InspectorPass/`.

Capture the focused 6-image Week workstation set with:

```bash
ipca-scheduling-ios/scripts/capture_week_workstation_screenshots.sh
```

Captured images are in `Screenshots/WeekPass/`.

Capture the focused 5-image daylight/civil-twilight review set with:

```bash
ipca-scheduling-ios/scripts/capture_twilight_screenshots.sh
```

Captured images are in `Screenshots/TwilightPass/`.

## Existing unrelated failure

`tests/cvr_schedule_duty_supersession_contract_check.php` has an existing
failure involving untouched CVR Swift files. Phase 2 does not modify those
files or suppress that failure.
