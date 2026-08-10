<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

function require_contains(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (!str_contains($contents, $needle)) {
        $failures[] = "{$label}: expected `{$needle}` in {$path}";
    }
}

function require_not_contains(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (str_contains($contents, $needle)) {
        $failures[] = "{$label}: unexpected `{$needle}` in {$path}";
    }
}

require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'cw_require_flight_schedule_editor',
    'track API staff auth',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'tv_adsb_fetch_historical_trace',
    'track API historical traces',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    "'samples'",
    'track API returns samples',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'flightDispatchedModal',
    'dispatched modal markup',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'adsbTrackApiUrl',
    'schedule config includes track API',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'openDispatchedModal',
    'JS opens dispatched modal',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'openAircraftAdsbModal',
    'JS opens ADS-B modal from aircraft tail',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'is-aircraft-tail',
    'aircraft resource labels are ADS-B click targets',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'fltsch-inflight-pill',
    'IN-FLIGHT pill markup on aircraft registration',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'refreshAircraftInFlightPills',
    'JS polls live ADS-B for IN-FLIGHT pills',
    $failures
);
require_contains(
    $root . '/src/tv_adsb_status.php',
    'function tv_adsb_is_actively_airborne',
    'schedule airborne state requires positive recent movement evidence',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb.php',
    'tv_adsb_is_actively_airborne($live)',
    'schedule status uses fail-safe airborne classifier',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'tv_adsb_is_actively_airborne($live)',
    'schedule track uses fail-safe airborne classifier',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'setAircraftInFlightState(label, false);',
    'failed ADS-B poll clears stale IN-FLIGHT pill',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.css',
    'position: absolute',
    'live status is removed from toolbar layout flow',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.css',
    'font-variant-numeric: tabular-nums',
    'live update time uses stable-width numerals',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.css',
    'contain: layout paint',
    'live status layout is isolated from toolbar',
    $failures
);
require_not_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    "if (reservation.status === 'claimed') {\n      openDispatchedModal(reservation);",
    'claimed reservation click no longer opens ADS-B modal',
    $failures
);
require_not_contains(
    $root . '/public/admin/schedule.php',
    'flightDispatchedUndispatchBtn',
    'live ADS-B modal does not expose undispatch action',
    $failures
);
require_not_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    "document.getElementById('flightDispatchedUndispatchBtn')",
    'live ADS-B JavaScript does not wire undispatch action',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'loadOwnshipSamples',
    'track chart accepts ADS-B samples',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'startLiveFollow',
    'track chart live follow mode',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'drawOwnshipFullTrace',
    'track chart draws full ownship trace',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'centerOnAircraft',
    'track chart centers on tracked aircraft',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'adsb-aircraft-symbol',
    'archive-style aircraft symbols',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'airplaneIcon',
    'airplane icon markers',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'startLiveFollow',
    'schedule wires live follow',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'includeMap: false',
    'schedule telemetry omits mini embed map',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'adsb-aircraft-symbol',
    'schedule CSS for aircraft symbols',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    "'nearby'",
    'track API returns nearby traffic',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'schedule_adsb_archive_trace_rows',
    'track API falls back to local ADS-B archive',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'ipca_adsb_traffic_samples',
    'track API reads archive sample table',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'schedule_adsb_build_vertical_profile',
    'track API builds vertical profile',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'schedule_adsb_refresh_vertical_profile_tip',
    'track API keeps sticky terrain across live updates',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'vertical_profile',
    'track API returns vertical profile payload',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'renderVerticalProfile',
    'track chart renders vertical profile',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'legs-track-profile-svg',
    'schedule modal has vertical profile markup',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'legs-track-center',
    'schedule modal has center-on-airplane control',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'enterReplayMode',
    'track chart supports historic replay while live',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'setFollowCentered',
    'track chart toggles map follow centering',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'LIVE_DISPLAY_DELAY_S',
    'live map uses delayed smooth interpolation',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'schedule_adsb_archive_traffic',
    'track API loads historical nearby traffic for replay',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'trafficHistoryLoaded',
    'track chart keeps historical traffic for replay',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'interpolateProfileCursor',
    'vertical profile cursor follows replay time',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'gap <= 120',
    'replay interpolation coasts across sparse ownship gaps',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'SQ ',
    'aircraft label shows transponder squawk',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    "'squawk'",
    'track API returns squawk for selected aircraft',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'schedule_adsb_profile_terrain_is_degenerate',
    'track API rejects flatlined sticky terrain caches',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    '$maxReuseNm = 2.0',
    'terrain tip refresh only reuses nearby elevations',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'schedule_adsb_fetch_elevations_open_elevation',
    'terrain fetch falls back when Open-Meteo is rate limited',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'fetchLockFile',
    'terrain DEM fetches are throttled per aircraft',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'RANGE_RINGS_NM',
    'track chart supports range rings around tracked airplane',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'OWNSHIP_COLOR',
    'tracked airplane uses a distinct ownship color',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'legs-track-rings',
    'schedule modal has range rings toggle',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'OPENFREEMAP_LIBERTY',
    'track chart uses OpenFreeMap Liberty basemap',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    'maplibre-gl-leaflet',
    'schedule loads MapLibre Leaflet bridge for OpenFreeMap',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'bringLayerGroupToFront',
    'track chart raises front-capable child layers safely',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'addBasemapLayer',
    'OpenFreeMap failure falls back to raster OSM',
    $failures
);
require_not_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'trackLayer.bringToFront()',
    'Leaflet LayerGroup must not receive bringToFront',
    $failures
);
require_not_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'dynamicLayer.bringToFront()',
    'Leaflet dynamic LayerGroup must not receive bringToFront',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    "silent ? '&live_only=1' : ''",
    'background polling uses live-only ADS-B mode',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    '$liveOnly',
    'track API supports a lightweight live polling path',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb_track.php',
    'if (!$liveOnly && $hex !==',
    'live polling skips historical trace reconstruction',
    $failures
);
require_contains(
    $root . '/public/admin/assets/flight_schedule.js',
    'if (!silent && adsbBody)',
    'background poll cannot overwrite visible ADS-B status',
    $failures
);
require_contains(
    $root . '/public/admin/assets/leg_track_chart.js',
    'updateVerticalProfileLiveTip',
    'live ADS-B polls extend the altitude profile',
    $failures
);

if ($failures !== array()) {
    fwrite(STDERR, "schedule_dispatched_adsb_track_contract_check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "schedule_dispatched_adsb_track_contract_check OK\n");
