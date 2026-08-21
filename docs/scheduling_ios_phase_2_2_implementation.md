# Native Scheduling Phase 2.2 Implementation Report

Date: August 20, 2026

## Outcome

Phase 2.2 adds a staff-only native iPad scheduling workstation while preserving
the existing iPhone companion experience. The implementation remains strictly
read-only. No create, edit, reschedule, cancel, dispatch, or drag mutation path
was added.

The primary 13-inch iPad Today workspace now presents the operational day as
aircraft rows over a spatial time axis. Instructors can see resource assignment,
participant identity, duration, current activity, overlaps, warnings, unused
aircraft, and selected-reservation details without leaving the schedule.

## Primary Today workspace

- The visual frame follows a professional aviation operations-board hierarchy:
  permanent navy sidebar, white command header, thin metrics bar, dominant
  rectangular schedule, and compact bottom operational strip.
- Toolbar controls, reservation blocks, corner radii, spacing, and typography
  are restrained and aligned to a consistent 4/8/12/16/20/24-point rhythm.
- Aircraft is the default resource lens.
- Aircraft registrations remain pinned during horizontal scrolling.
- The time axis remains pinned during vertical scrolling.
- The default operational window is 06:00–22:00.
- Earlier or later reservations extend that window with 60 minutes of stable
  padding, rounded to 30-minute boundaries.
- Full Day, Standard, and Detailed densities preserve the same canonical window.
- Reservation x-position and width are calculated from operational-local start
  and end wall-clock values, without converting through the device timezone.
- Short reservations preserve their geometric width and use a larger invisible
  tap target.
- Blocks progressively reveal participant, instructor, mission, and route
  information as width permits.
- Claimed, completed, warning, and selected states use icon, text, border, and
  opacity cues rather than color alone.
- A minute-updating current-time line appears only on the current day.
- Half-open overlap lanes expand row height so both reservations remain visible.
- Canonical aircraft catalog rows remain visible when they have no reservations.

## Resource lenses and ordering

Aircraft, Instructor, and Student views are projections over the same
`SchedulerReservation` collection. They do not introduce alternate scheduling
models or business rules.

`OperationsRowSortingStrategy` makes ordering replaceable. The current UI uses
stable localized registration/name order. Future canonical or utilization-aware
ordering can be introduced without changing projection or timeline geometry.

## Selection and inspector

A single tap selects a reservation and opens a contextual inspector. On the
13-inch layout the inspector is a persistent right column. On narrower iPad
layouts it becomes a right-side overlay, keeping the schedule visible.

The inspector presents:

- one compact identity header with title, canonical status, and aircraft
- one operational-local date, time, and duration line
- compact property rows for aircraft type, student, instructor, training, route,
  and cohort
- conditional warning and notes sections
- a wider, context-preserving inspector mode for complete crew, route legs,
  relevant operational state, and canonical metadata

The inspector uses a `ScrollView` with deliberate inset separators; no `List`
or default row separators remain. Full Details no longer opens a centered iPad
modal. It expands the same trailing panel from approximately 320–350 points to
480–540 points where space permits. The schedule remains visible. The inspector
is modular and contains no mutation controls.

## Search, Week, filters, and navigation

- Loaded reservations are searched immediately across aircraft, participant,
  mission, title, and route.
- Selecting a reservation result navigates to its date, selects the aircraft
  lens, scrolls to the matching block, and opens the inspector.
- Server resource search remains available for operational lookup.
- Week is a seven-column operations board over the same canonical reservation
  collection and Aircraft/Instructor/Student projections as Today. Resource
  rows continue across Monday–Sunday, while each day/resource cell shows
  proportional 06:00–22:00 mini-timeline blocks.
- The current day uses a restrained blue column tint and top accent. Empty
  resources and days retain their grid space, and warning blocks use the
  existing semantic warning treatment.
- Selecting any day header or day/resource cell opens that date in Today while
  preserving the active lens and filters.
- Previous day, next day, and Today are one-tap actions.
- Resource lens switching is exposed directly in the toolbar and sidebar.
- Filters remain secondary and execute on the server. On iPad they use a
  372-point anchored workstation popover with immediate Aircraft, Person,
  Cohort, and Reservation Type selection.

## Warning read contract

Schedule range responses now add an optional per-reservation `validation`
envelope. This is backward compatible and does not change reservation fields,
authorization, overlap behavior, or mutation responses.

The range projection reuses the authoritative overlap predicate and warning
factory. It preserves:

- advisory warning semantics
- scheduled/claimed participation
- half-open time intervals
- existing warning codes and messages

Conflict lookup uses three queries per batch of up to 200 reservations
(aircraft, cohort, crew). It does not perform an N+1 query per reservation.

## Shared state, cache, and offline behavior

iPhone and iPad share authentication, transport, models, timezone handling,
capabilities, filters, connectivity, and cache services.

The atomic JSON range cache now also stores optional aircraft and person
catalogs. Cached workstation launches therefore retain unused resource rows
instead of collapsing to only resources present in reservations. Cached data is
visually marked as saved/stale and remains read-only.

## Adaptive behavior

- 13-inch landscape is the primary persistent-inspector composition.
- 11-inch landscape retains the full operations board and presents the inspector
  as a contextual right overlay.
- Portrait keeps the resource-by-time board, uses compact toolbar controls, and
  starts with the sidebar collapsed.
- The existing iPhone Today and Schedule compositions are unchanged.

## Accessibility and input

- Dynamic Type-compatible semantic fonts are used throughout.
- Resource headers and reservations expose coherent VoiceOver summaries.
- Warning counts are spoken explicitly.
- Touch targets for short blocks remain at least 44 points while visible block
  geometry stays accurate.
- Selected state is exposed through accessibility traits.
- Command-F opens operational search.
- Standard native controls retain keyboard and pointer behavior.

## Verification

Backend:

- PHP syntax checks pass for the changed scheduling services.
- `scheduler_api_foundation_check.php`: 27 checks pass, including canonical
  home-base reuse and server-computed civil-twilight boundaries.
- `scheduled_dispatch_contract_check.php`: passes.
- `cvr_multileg_schedule_parity_contract_check.php`: passes.

iOS:

- 50 XCTest cases pass on iPad Pro 13-inch (M4), iOS 18.5.
- iPhone 16 Pro, iOS 18.5 build passes.
- The suite covers projection, replaceable ordering, stable geometry, short and
  multi-hour duration frames, half-open overlaps, lane reuse, row height,
  operational-local DST behavior, search-to-timeline navigation, warning
  decoding, accessibility summaries, atomic cache resources, sparse resources,
  a 40+ reservation stress day, civil-twilight geometry across all timeline
  densities, scrolling coordinates, seasonal change, and a DST transition.

Visual review:

- 15 simulator screenshots are in
  `ipca-scheduling-ios/Screenshots/Phase2.2/`.
- The set includes 13-inch Aircraft/Instructor/Student lenses, selected and
  warning inspectors, three densities, Week, 11-inch board and inspector,
  portrait, sparse operations, offline/stale, and two iPhone regressions.
- The set is reproducible with
  `ipca-scheduling-ios/scripts/capture_phase22_screenshots.sh`.
- A focused 8-image consistency review set is in
  `ipca-scheduling-ios/Screenshots/InspectorPass/`. It covers the normal
  Aircraft board, open filters, compact and expanded inspectors, multiple crew,
  warnings, 11-inch landscape, and narrow portrait adaptation.
- The focused set is reproducible with
  `ipca-scheduling-ios/scripts/capture_inspector_consistency_screenshots.sh`.
- A separate 6-image Week review set is in
  `ipca-scheduling-ios/Screenshots/WeekPass/`, covering all three resource
  lenses, busy/empty days, warning presentation, and 11-inch landscape.
- The Week set is reproducible with
  `ipca-scheduling-ios/scripts/capture_week_workstation_screenshots.sh`.
- A 5-image daylight/civil-twilight review set is in
  `ipca-scheduling-ios/Screenshots/TwilightPass/`, covering morning, evening,
  full-day, and reservations crossing both transition periods.
- The twilight set is reproducible with
  `ipca-scheduling-ios/scripts/capture_twilight_screenshots.sh`.

## Deferred to Phase 3

- all schedule mutations
- tap-empty-space creation
- drag/drop and resize proposals
- warning confirmation workflows
- inline inspector editing
- maintenance and staff-shift timeline items
- live aircraft state
- backend-generated scheduling recommendations

The timeline item, projection, sorting, and inspector structures provide
extension points for these features while keeping the server authoritative.

