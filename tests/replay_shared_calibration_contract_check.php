<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/AircraftSettingsService.php') ?: '';
$api = file_get_contents($root . '/public/admin/api/cockpit_recorder_replay_calibration.php') ?: '';
$replay = file_get_contents($root . '/public/admin/cockpit_recorder_replay.php') ?: '';
$settings = file_get_contents($root . '/public/admin/aircraft_settings.php') ?: '';

$checks = array(
    'aircraft settings can normalize and save shared camera calibration' =>
        str_contains($service, 'function saveCameraCalibrationDefault')
        && str_contains($service, 'function normalizeCameraCalibration')
        && str_contains($service, 'function normalizeLayoutConfig'),
    'admin API endpoint persists replay calibration defaults' =>
        str_contains($api, 'cw_require_admin()')
        && str_contains($api, 'saveCameraCalibrationDefault')
        && str_contains($api, 'seed_only_if_missing'),
    'replay player applies server calibration and can auto-seed from admin browser' =>
        str_contains($replay, 'applyServerCameraCalibration')
        && str_contains($replay, 'maybeSeedAircraftCameraCalibrationFromLocal')
        && str_contains($replay, 'saveAircraftCalibrationDefault')
        && str_contains($replay, 'REPLAY_CAN_SAVE_CALIBRATION'),
    'aircraft settings UI preserves stored camera calibration and defaults to panel' =>
        str_contains($settings, "camera_calibration")
        && str_contains($settings, "replay_layout_mode'] ?? 'panel'"),
);

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
