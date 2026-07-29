<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CvrIntakeDisplayService.php';
require_once __DIR__ . '/../src/ManualReconstructionBundleService.php';
require_once __DIR__ . '/../src/CvrDataIntakeReadService.php';

$displaySource = file_get_contents(__DIR__ . '/../src/CvrIntakeDisplayService.php');
if ($displaySource === false
    || !str_contains($displaySource, 'dispatchOptionLabel')
    || !str_contains($displaySource, ' LT')) {
    fwrite(STDERR, "CvrIntakeDisplayService contract failed.\n");
    exit(1);
}

$bundleSource = file_get_contents(__DIR__ . '/../src/ManualReconstructionBundleService.php');
if ($bundleSource === false
    || !str_contains($bundleSource, 'supersedeBundleSources')
    || !str_contains($bundleSource, "'admin_manual'")) {
    fwrite(STDERR, "ManualReconstructionBundleService contract failed.\n");
    exit(1);
}

$intakeSource = file_get_contents(__DIR__ . '/../src/CvrDataIntakeReadService.php');
if ($intakeSource === false
    || !str_contains($intakeSource, 'off_block_utc')
    || !str_contains($intakeSource, "'admin_manual'")) {
    fwrite(STDERR, "CvrDataIntakeReadService contract failed.\n");
    exit(1);
}

$uploadSource = file_get_contents(__DIR__ . '/../src/CvrIntakeAdminUploadService.php');
if ($uploadSource === false
    || !str_contains($uploadSource, 'uploadGarminCsv')
    || !str_contains($uploadSource, 'uploadAudio')
    || !str_contains($uploadSource, "'admin_manual'")) {
    fwrite(STDERR, "CvrIntakeAdminUploadService contract failed.\n");
    exit(1);
}

$pageSource = file_get_contents(__DIR__ . '/../public/admin/master_logbook_intake.php');
if ($pageSource === false
    || !str_contains($pageSource, 'upload_manual_garmin_csv')
    || !str_contains($pageSource, 'upload_manual_audio')
    || !str_contains($pageSource, 'supersede_reconstruction_bundle')
    || !str_contains($pageSource, 'dispatchOptionLabel')) {
    fwrite(STDERR, "master_logbook_intake contract failed.\n");
    exit(1);
}

echo "cvr_intake_admin_contract_check: ok\n";
