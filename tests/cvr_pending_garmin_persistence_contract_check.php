<?php
declare(strict_types=1);

/**
 * Pending Garmin CSV durable persistence + Log retry wiring contracts.
 */

$root = dirname(__DIR__);
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$persistence = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRPendingGarminPersistence.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$pbx = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit.xcodeproj/project.pbxproj') ?: '';
$flightLog = file_get_contents($root . '/tests/cvr_flight_log_contract_check.php') ?: '';

$failures = array();

function require_true(bool $ok, string $label, array &$failures): void
{
    if (!$ok) {
        $failures[] = $label;
    }
}

require_true(
    str_contains($persistence, 'struct CVRPendingGarminMetadata')
    && str_contains($persistence, 'relativeFilePath')
    && str_contains($persistence, 'pending-metadata.json')
    && str_contains($persistence, 'PendingGarminImports')
    && str_contains($persistence, 'writeMetadata')
    && str_contains($persistence, 'restorePending')
    && str_contains($persistence, 'clearMetadata'),
    'persistence helper stores relative path metadata beside PendingGarminImports',
    $failures
);

require_true(
    str_contains($persistence, '[.atomic]')
    && (str_contains($persistence, 'verified == normalized') || str_contains($persistence, 'verified == metadata'))
    && str_contains($persistence, 'floor(metadata.stagedAt.timeIntervalSince1970)'),
    'metadata write is atomic, decode-verified, and ISO8601-safe for fractional dates',
    $failures
);

require_true(
    str_contains($models, 'restorePendingGarminImport')
    && str_contains($models, 'preparePendingGarminImportForLog')
    && str_contains($models, 'clearPendingGarminAfterVerifiedSuccess')
    && str_contains($models, 'preservePendingFailure')
    && str_contains($models, 'CVRPendingGarminPersistence.writeMetadata')
    && str_contains($models, 'CVRPendingGarminPersistence.clearMetadata'),
    'FlightLogStore stages file then persists metadata and clears only after verified success',
    $failures
);

require_true(
    str_contains($models, 'The Garmin file is stored on this device. Synchronize the flight first, then retry. You will not need to select the file again.'),
    'approved Sync-first operational wording is used',
    $failures
);

require_true(
    !str_contains($models, 'foreign key')
    && !str_contains($models, 'missing row')
    && !str_contains($models, 'server Dispatch ID')
    && !str_contains($models, 'workflow linkage'),
    'crew-facing Garmin messages avoid technical database terms',
    $failures
);

require_true(
    str_contains($views, 'retryPendingGarminCSV')
    && str_contains($views, 'Upload queued flight data and retry the stored Garmin file')
    && str_contains($views, 'targetFlightRecordID == nil'),
    'SYNC NOW retries pending Garmin and restored association does not reopen picker',
    $failures
);

require_true(
    str_contains($pbx, 'CVRPendingGarminPersistence.swift'),
    'Xcode project includes pending Garmin persistence source',
    $failures
);

require_true(
    str_contains($flightLog, 'Ownership is NEVER optional')
    && str_contains($flightLog, 'retryPendingGarminCSV'),
    'existing flight log Garmin finalize contracts still reference ownership and retry',
    $failures
);

if ($failures === array()) {
    fwrite(STDOUT, "OK: CVR pending Garmin persistence contract checks passed.\n");
    exit(0);
}

fwrite(STDERR, "FAIL: CVR pending Garmin persistence contract checks\n");
foreach ($failures as $failure) {
    fwrite(STDERR, " - {$failure}\n");
}
exit(1);
