# Scheduling Daylight/Twilight Data Gap

Date: August 20, 2026

## Status

Resolved. The business data was not missing. The mobile scheduler API had not
exposed the existing canonical online-operations home-base context.

The production source is `storage/tv_kiosk_config.json`, read through
`tv_kiosk_config()`. Its installation-wide `home_airport` is the authoritative
home airport used by the online aircraft operations display. Production
currently stores:

- `home_airport`: `KTRM`
- `gate_label`: `SPC Gate`
- `gate_lat`: `33.6267`
- `gate_lon`: `-116.16`

`public/admin/tv_screens/index.php` is the existing administrator surface that
writes this configuration. `public/tv/flipboard.php`,
`public/tv/api/aircraft_board.php`, and the ADS-B status services already read
it for online operational display and home-airport logic.

The legacy web schedule page did not itself render or expose this context. The
new scheduler API adapter now reads the same existing source; no scheduling-base
table, aircraft-derived location, device location, or second configuration was
created.

## Current scheduler contract

The scheduler bootstrap and schedule responses provide:

- organization ID
- operational timezone (`America/Los_Angeles` in the current implementation)
- timezone-free operational-local reservation timestamps
- aircraft resources with an optional `home_airport`

They now additionally provide:

- `operational_home_base` in bootstrap and schedule-range responses
- `astronomy_days` in schedule-range responses
- canonical airport row ID when `ipca_airports` contains the home airport
- display name and airport coordinates from the existing `AirportDataService`
- the existing scheduler IANA timezone
- explicit server-computed civil-twilight and sunrise/sunset boundaries

The home-base configuration is installation-wide today. The authenticated
scheduler organization ID is included in the API representation so the context
is explicit without inventing a duplicate organization/base persistence model.

## Why aircraft home airport is insufficient

Deriving the visualization from aircraft resources would be ambiguous and
incorrect because:

- an empty day may have no reservation aircraft from which to infer a location
- Instructor and Student lenses are not defined by a specific aircraft
- a schedule can include aircraft with different home airports
- an aircraft's home airport does not necessarily identify the operational
  location governing the displayed schedule
- resource filtering could change the inferred location and therefore move the
  astronomical boundaries for the same date

The iPad's physical location is also unsuitable because it may differ from the
operational scheduling base.

## Implemented additive contract

The preferred contract is server-computed, per-day astronomical boundaries
using the canonical home base. The schedule response now provides:

```json
{
  "operational_home_base": {
    "id": 1,
    "organization_id": 1,
    "display_name": "Jacqueline Cochran Regional Airport",
    "airport_identifier": "KTRM",
    "latitude": 33.6267,
    "longitude": -116.1597,
    "operational_timezone": "America/Los_Angeles",
    "source": "tv_kiosk_config"
  },
  "astronomy_days": [{
    "date": "2026-08-19",
    "morning_civil_twilight_begin": "2026-08-19T05:43:55.000",
    "sunrise": "2026-08-19T06:09:53.000",
    "sunset": "2026-08-19T19:26:30.000",
    "evening_civil_twilight_end": "2026-08-19T19:52:28.000",
    "operational_timezone": "America/Los_Angeles",
    "location_id": 1,
    "airport_identifier": "KTRM",
    "calculation_method": "php_date_sun_info_civil_twilight_v1"
  }]
}
```

`SchedulerOperationalContextService` resolves airport metadata through the
existing `AirportDataService`/`ipca_airports` dataset. If that optional dataset
is unavailable, it retains the canonical identifier and uses the coordinates
already stored in the same online-operations configuration.

For each operational date, PHP `date_sun_info()` calculates astronomical events
at the canonical coordinates. The implementation uses the function's actual
`civil_twilight_begin`, `sunrise`, `sunset`, and `civil_twilight_end` values and
converts the returned instants to timezone-free scheduler-local strings in
`America/Los_Angeles`. No fixed-minute approximation is used.

## FAA semantics

The future client must treat:

- end of evening civil twilight as the start of FAA loggable night
- beginning of morning civil twilight as the end of FAA loggable night
- sunrise and sunset as solid visual markers only
- civil-twilight boundaries as dashed FAA logging-night markers

The separate passenger-currency period beginning one hour after sunset and
ending one hour before sunrise is outside this visualization.

## Client implementation

The iPad daily timeline now:

1. Shades the period before beginning morning civil twilight.
2. Draws a dashed morning civil-twilight boundary.
3. Transitions to daylight through a time-proportional gradient ending at a
   solid sunrise marker.
4. Draws a solid sunset marker and a time-proportional evening gradient.
5. Draws a dashed end-evening-civil-twilight marker and shades the remaining
   FAA logging-night period.

The layer spans empty resource rows and remains behind grid lines, reservations,
selection, warnings, and the current-time line. Week view is unchanged.

