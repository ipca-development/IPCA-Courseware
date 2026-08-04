<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$local = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVROperationalIdentityLocal.swift') ?: '';
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$settings = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/SettingsStore.swift') ?: '';
$upload = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$docs = file_get_contents($root . '/docs/cvr_phase2a_operational_identity.md') ?: '';
$pbx = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit.xcodeproj/project.pbxproj') ?: '';

$checks = array();

$checks['local identity helper and offline_create exist'] =
    str_contains($local, 'enum CVROperationalIdentityLocal')
    && str_contains($local, 'createOfflineBundle')
    && str_contains($local, 'reuseOrConflict')
    && str_contains($local, 'offline_create')
    && str_contains($local, 'dispatch_uuid')
    && !str_contains($local, 'garmin_csv_file_uuid');

$checks['dispatch model carries optional operationalIdentity'] =
    str_contains($models, 'var operationalIdentity: CVRLocalOperationalIdentity?');

$checks['canonical write flag defaults off in SettingsStore'] =
    str_contains($settings, 'operationalIdentityCanonicalWriteEnabled')
    && str_contains($settings, 'as? Bool ?? false')
    && str_contains($local, 'operational_identity_canonical_write_enabled');

$checks['create path gates identity mint on flag'] =
    str_contains($store, 'canonicalWriteEnabled: Bool = false')
    && str_contains($store, 'if canonicalWriteEnabled')
    && str_contains($store, 'CVROperationalIdentityLocal.createOfflineBundle')
    && str_contains($store, 'Unable to create the Dispatch. Please try again.');

$checks['views pass settings flag into create'] =
    substr_count($views, 'canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled') >= 2;

$checks['sync payload includes identity without regenerating'] =
    str_contains($upload, 'operational_identity')
    && str_contains($upload, 'reservation_uuid')
    && str_contains($upload, 'leg_uuid')
    && !preg_match('/operationalIdentity\s*=\s*CVROperationalIdentityLocal\.createOfflineBundle/', $upload);

$checks['confirm attaches FR alias without reminting reservation/leg'] =
    str_contains($store, 'appendingWorkflowFlightRecordAlias')
    && !preg_match('/verifyDispatchAndCreateFlightRecord[\s\S]{0,800}createOfflineBundle/', $store);

$checks['xcode project includes local identity source'] =
    str_contains($pbx, 'CVROperationalIdentityLocal.swift');

$checks['nil planned times omitted from payload dictionary'] =
    str_contains($local, 'if let plannedStartAtUTC = identity.plannedStartAtUTC')
    && str_contains($local, 'if let plannedEndAtUTC = identity.plannedEndAtUTC')
    && !str_contains($local, 'planned_start_at_utc": identity.plannedStartAtUTC as Any')
    && !str_contains($local, 'planned_end_at_utc": identity.plannedEndAtUTC as Any');

$checks['docs describe Phase 2D local Dispatch identity'] =
    str_contains($docs, 'Phase 2D')
    && str_contains($docs, 'offline_create')
    && str_contains($docs, 'reservation_uuid')
    && str_contains($docs, 'leg_uuid');

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed === array()) {
    fwrite(STDOUT, "PASS cvr_phase2d_local_dispatch_identity_contract_check (" . count($checks) . " checks)\n");
    exit(0);
}

fwrite(STDERR, "FAIL cvr_phase2d_local_dispatch_identity_contract_check\n");
foreach ($failed as $name) {
    fwrite(STDERR, " - {$name}\n");
}
exit(1);
