# IPCA Scheduler API v1

This namespace is the human-mobile interface to the existing flight scheduler.
It does not own schedule truth. All reservation mutations continue through
`FlightScheduleService`.

## Authentication

Endpoints accept the existing Communication human bearer credential:

```http
Authorization: Bearer <token>
```

`POST /api/scheduler/auth.php` delegates login/logout to
`CommunicationAuthService`. It uses the same device identity, token hashing,
revocation, account-status, and account-expiry rules as the Communication app.
CVR enrollment credentials are not accepted.

## Time contract

- Operational timezone: `America/Los_Angeles`
- `start_local` and `end_local` are timezone-free operational-local timestamps.
- They are not UTC and never carry `Z`.
- Current storage remains `DATETIME(3)` local planning time.
- Spring-gap and fall-fold local values are preserved exactly, matching current
  production semantics. Clients must retain the accompanying timezone.

Example:

```json
{
  "start_local": "2026-08-20T10:00:00.000",
  "end_local": "2026-08-20T12:00:00.000",
  "operational_timezone": "America/Los_Angeles"
}
```

The explicit per-response timezone allows a future base-specific timezone to be
introduced without changing timestamp field semantics.

## Endpoints

All paths follow the repository's `.php` endpoint convention.

- `POST /api/scheduler/auth.php`
- `GET /api/scheduler/bootstrap.php`
- `GET /api/scheduler/schedule.php?start=YYYY-MM-DD&end=YYYY-MM-DD`
- `GET /api/scheduler/reservations.php?reservation_uuid=<uuid>`
- `POST /api/scheduler/reservations.php`
- `PATCH /api/scheduler/reservations.php?reservation_uuid=<uuid>`
- `DELETE /api/scheduler/reservations.php?reservation_uuid=<uuid>`
- `GET /api/scheduler/resources.php?type=aircraft|person|mission&q=`
- `GET /api/scheduler/search.php?q=`
- `POST /api/scheduler/validation.php`

Schedule ranges are inclusive and limited to 31 days.

## Bootstrap

Bootstrap is intentionally lightweight:

```json
{
  "ok": true,
  "user": {},
  "organization": {"id": 1},
  "capabilities": {
    "schedule_read": true,
    "reservation_create": true,
    "reservation_edit": true,
    "reservation_cancel": true,
    "reservation_undispatch": false,
    "manual_checkin": false,
    "dispatch": false,
    "view_training": true,
    "resource_search": true
  },
  "operational_timezone": "America/Los_Angeles",
  "scheduler": {
    "max_range_days": 31,
    "overlap_policy": "warning",
    "schedule_time_semantics": "timezone_free_operational_local",
    "recurring_reservations_supported": false,
    "comprehensive_availability_supported": false
  }
}
```

Capabilities are calculated from the existing scheduler authorization helper.
The client must not infer actions from role names. Reservation responses also
contain state-specific `authorized_actions`.

## Visibility

- Existing schedule editors receive reservations in their authenticated
  organization scope.
- Other eligible users receive only reservations where their user ID is an
  assigned crew participant.
- Detail visibility uses the same server-side rule and returns `not_found`
  rather than revealing an unrelated UUID.
- The current aircraft registry is global because the production aircraft table
  has no organization column. Mission and schedule rows are organization scoped.

## Schedule response

Date-range responses embed the labels required for a native agenda: aircraft
registration, resolved mission, cohort, crew display names, status, type, route,
evidence flags, lock state, updated timestamp, and authorized actions. A client
does not need one request per resource to render a day.

## Mutations

### Create

Create requires an `Idempotency-Key` header. The receipt stores a request hash
and a server-generated reservation UUID before invoking `FlightScheduleService`.
A retry with the same key and body returns the original UUID. The client
mutation key is never used as reservation identity.
An interrupted `processing` receipt becomes retryable after two minutes and
reuses its reserved UUID; an active concurrent retry returns
`request_in_progress`.

```http
POST /api/scheduler/reservations.php
Idempotency-Key: 8b63721c-8997-4ce3-85f8-f8ead9ea0e56
```

```json
{
  "reservation_type": "flight_training",
  "start_local": "2026-08-20T10:00:00.000",
  "end_local": "2026-08-20T12:00:00.000",
  "aircraft_id": 42,
  "mission_id": 18,
  "cohort_id": 7,
  "airport_chain": ["KPSP", "KTRM"],
  "crew": [
    {
      "user_id": 101,
      "role": "student",
      "pilot_function": "PF",
      "is_pic": false,
      "is_primary_customer": true
    },
    {
      "user_id": 202,
      "role": "instructor",
      "pilot_function": "PM",
      "is_pic": true,
      "is_primary_customer": false
    }
  ],
  "notes": ""
}
```

The server resolves participant names from `users`; client-provided names are
not trusted.

### Edit and cancel

Edit, reschedule, and cancel require the last `updated_at` returned by the
server. Native mutations compare available millisecond precision. A stale value
returns `reservation_changed`. Claimed/completed
reservations return `reservation_locked`.

Cancellation is a soft state change. It marks both the schedule slot and any
matching canonical operational reservation/legs as `cancelled`. Crew,
released dispatch links, and historical evidence are retained.

## Scheduling assessment

`validation.php` is a reservation-overlap assessment, not comprehensive
availability. It currently knows aircraft, crew, and cohort reservation
overlaps. It does not claim knowledge of maintenance, work shifts, or
student-self-booking rules.

```json
{
  "ok": true,
  "assessment_scope": {
    "reservation_overlaps": true,
    "maintenance": false,
    "staff_shifts": false,
    "student_self_booking": false
  },
  "validation": {
    "result": "allowed_with_warning",
    "warnings": [
      {
        "code": "aircraft_overlap",
        "resource_type": "aircraft",
        "resource_id": 42,
        "message": "The selected aircraft is already reserved during this time.",
        "conflicting_reservation_uuid": "..."
      }
    ]
  }
}
```

Overlap warnings remain non-blocking, matching the web scheduler.

## Errors

```json
{
  "ok": false,
  "error_code": "reservation_locked",
  "message": "This reservation can no longer be changed.",
  "retryable": false,
  "user_action_required": true,
  "request_id": "optional-client-request-id"
}
```

Current stable codes:

- `unauthenticated`
- `account_ineligible`
- `forbidden`
- `not_found`
- `invalid_request`
- `validation_failed`
- `reservation_locked`
- `reservation_changed`
- `idempotency_conflict`
- `request_in_progress`
- `server_error`

Internal exceptions and stack traces are not returned.

## Side-effect-free reads

`FlightScheduleService::listSlots()` no longer performs orphan-dispatch
reconciliation. Explicit operational tooling can call
`reconcileUnlinkedCompletedDispatchesForRange()` when reconciliation is
deliberately requested. Frequent GET refreshes therefore do not mutate
schedule state.

## Deferred

- SwiftUI scheduling application
- recurring reservations
- comprehensive availability
- maintenance or work-shift scheduling
- student self-booking
- schedule push-notification rules
- cursor/event-stream synchronization
- multi-base timezone storage migration
- human mobile undispatch/manual-check-in endpoints
