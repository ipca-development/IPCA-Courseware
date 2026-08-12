<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schedule = file_get_contents($root . '/public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';
$release = file_get_contents($root . '/src/CvrDispatchReleaseService.php') ?: '';
$checkIn = file_get_contents($root . '/src/CvrAdminManualCheckInService.php') ?: '';
$intake = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php') ?: '';
$legReview = file_get_contents($root . '/src/CvrOperationalSessionLegReviewService.php') ?: '';
$flightLog = file_get_contents($root . '/src/CvrFlightLogService.php') ?: '';
$intakeRead = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';
$migration = file_get_contents($root . '/scripts/sql/2026_08_12_cvr_admin_operational_recovery.sql') ?: '';

$checks = array(
    'orange Dispatch exposes online Check In and Undispatch actions' =>
        str_contains($schedule, 'id="flightDispatchedCheckIn"')
        && str_contains($schedule, 'id="flightDispatchedUndispatch"')
        && str_contains($scheduleJs, 'openManualCheckInModal')
        && str_contains($scheduleJs, 'openUndispatchModal(reservation)'),
    'administrative Undispatch requires a structured reason' =>
        str_contains($schedule, 'name="reason_code"')
        && str_contains($release, 'ADMIN_REASON_CODES')
        && str_contains($release, 'releaseAdministrativelyBySchedulerRecordId'),
    'stationary recorder evidence is distinguishable from genuine flight evidence' =>
        str_contains($release, 'assertAdministrativeReleaseAllowed')
        && str_contains($release, "str_contains(\$type, 'takeoff')")
        && str_contains($release, "maximum_ground_speed_kt")
        && str_contains($release, "ipca_garmin_csv_files")
        && str_contains($release, 'tv_adsb_is_actively_airborne'),
    'administrative releases have immutable reason and evidence provenance' =>
        str_contains($migration, 'ipca_cvr_dispatch_release_events')
        && str_contains($migration, 'evidence_summary_json')
        && str_contains($release, 'recordAdministrativeRelease'),
    'released Dispatch cannot be resurrected by delayed evidence upload' =>
        str_contains($intake, "status")
        && str_contains($intake, 'This Dispatch was administratively released')
        && str_contains($intake, 'LIMIT 1 FOR UPDATE'),
    'online Check In commits closure before optional recovery evidence' =>
        str_contains($checkIn, 'CvrWorkflowEvidenceIntakeService')
        && str_contains($checkIn, "'component_type' => 'flight_record_closure'")
        && str_contains($schedule, 'Check-In is saved as soon as this form is submitted'),
    'online Check In requires shutdown meters and fuel' =>
        str_contains($schedule, 'name="engine_shutdown_local"')
        && str_contains($schedule, 'name="ending_hobbs"')
        && str_contains($schedule, 'name="ending_tacho"')
        && str_contains($schedule, 'name="fuel_remaining"')
        && str_contains($checkIn, 'roundUpToTenth'),
    'audio and Garmin recovery remain independent after Check In' =>
        str_contains($schedule, 'manual_checkin_audio')
        && str_contains($schedule, 'app_archive_json')
        && str_contains($schedule, 'manual_checkin_csv')
        && str_contains($schedule, 'Check-In remained complete throughout the upload'),
    'GPS plus Garmin can automatically verify legs' =>
        str_contains($checkIn, 'attemptAutomaticLegVerification')
        && str_contains($checkIn, "empty(\$preview['has_garmin_csv'])")
        && str_contains($checkIn, "empty(\$preview['has_cvr_gps'])")
        && str_contains($legReview, 'acceptForAdmin'),
    'manual leg verification remains available without evidence' =>
        str_contains($schedule, 'manual_checkin_leg')
        && str_contains($checkIn, 'acceptManualSingleLeg')
        && str_contains($schedule, 'Open Master Logbook'),
    'admin leg-review provenance is explicit' =>
        str_contains($migration, 'reviewed_by_user_id')
        && str_contains($migration, 'review_source')
        && str_contains($legReview, 'admin_online_checkin'),
    'voided released Dispatches are excluded from flight log projection' =>
        str_contains($flightLog, "LOWER(TRIM(COALESCE(d.status, ''))) <> 'released'")
        && str_contains($intakeRead, "<> 'released'"),
);

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== array()) {
    fwrite(STDERR, "cvr_admin_online_recovery_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'cvr_admin_online_recovery_contract_check OK (' . count($checks) . " checks)\n";
