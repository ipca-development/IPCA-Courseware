<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$coordinator = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRUnitCoordinator.swift') ?: '';
$upload = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';

$checks = array(
    'archive model retains all evidence categories' =>
        str_contains($models, 'struct CVRWorkflowArchiveRecord')
        && str_contains($models, 'var flightEvents: [CVRFlightEventRecord]')
        && str_contains($models, 'var recorderVerifications: [CVRRecorderVerificationRecord]')
        && str_contains($models, 'var uploadComponents: [CVRUploadComponentRecord]'),
    'archive is written and verified before active reset' =>
        strpos($store, 'guard archiveActiveWorkflow() else { return }')
        < strpos($store, '$0.activeDispatch = nil')
        && str_contains($store, 'verification.map(\\.id) == records.map(\\.id)'),
    'next flight supports retained pending uploads' =>
        str_contains($store, 'archives.flatMap(\\.uploadComponents)')
        && str_contains($models, 'case uploadPending'),
    'audio session links and backfills event offsets' =>
        str_contains($coordinator, 'workflow?.linkRecordingSession')
        && str_contains($store, 'timestampUTC.timeIntervalSince(startedAt)'),
    'each event gets a stable upload component' =>
        str_contains($store, 'private func eventUploadComponent')
        && str_contains($store, 'localFilePath: "\\(prefix):\\(evidenceID)"'),
    'server verified requires a real receipt' =>
        str_contains($store, 'Server verification receipt is missing.')
        && str_contains($upload, 'syncWorkflowEvidence'),
    'interrupted active and archived uploads recover to queue' =>
        str_contains($store, 'recoverInterruptedActiveUploads')
        && str_contains($store, 'Upload was interrupted and has been queued for recovery.'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed workflow archive checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: CVR workflow archive contract checks passed.' . PHP_EOL;
