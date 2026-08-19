<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = $root . '/ipca-garmin-sync-ios';
$required = [
    'IPCA Garmin Sync.xcodeproj/project.pbxproj',
    'IPCA Garmin Sync/ExternalStorageServices.swift',
    'IPCA Garmin Sync/LocalIngestionStore.swift',
    'IPCA Garmin Sync/CopyAndSnapshotServices.swift',
    'IPCA Garmin Sync/UploadServices.swift',
    'IPCA Garmin Sync/EnrollmentServices.swift',
    'IPCA Garmin Sync/ContentView.swift',
    'IPCA Garmin SyncTests/GarminSyncIngestionTests.swift',
    'IPCA Garmin SyncTests/GarminSyncUploadTests.swift',
    'IPCA Garmin SyncTests/GarminSyncEnrollmentTests.swift',
    'HARDWARE_POC.md',
];

foreach ($required as $relative) {
    if (!is_file($app . '/' . $relative)) {
        fwrite(STDERR, "Missing required Garmin Sync file: {$relative}\n");
        exit(1);
    }
}

$sources = '';
foreach (glob($app . '/IPCA Garmin Sync/*.swift') ?: [] as $file) {
    $sources .= file_get_contents($file) ?: '';
}

$assertions = [
    'SQLite ledger' => 'CREATE TABLE IF NOT EXISTS files',
    'immutable snapshots' => 'scan_snapshot_members',
    'streaming SHA-256' => 'SHA256()',
    'partial copy' => '.partial',
    'filesystem flush' => 'fsync(',
    'security bookmark' => 'bookmarkData(',
    'chunk endpoint' => '/api/garmin-sync/upload_chunk.php',
    'finalize endpoint' => '/api/garmin-sync/finalize.php',
    'enrollment endpoint' => '/api/garmin-sync/enroll.php',
    'Bearer authentication' => 'Bearer ',
    'isolated enrollment keychain account' => 'garmin-sync-enrollment-credential',
    'stable enrollment device ID' => 'garminSync.deviceID',
    'production default server' => 'https://ipca.training',
    'stable upload ID' => 'uploadID',
    'indexed chunk resume' => 'receivedChunks',
    'multipart chunk field' => 'name=\"chunk\"',
    'server hash verification' => 'receipt.sha256 == file.sourceHash',
    'receipt persistence' => 'server_receipt_uuid',
    'full receipt audit' => 'server_receipt_json',
    'object persistence' => 'server_object_id',
    'last seen audit' => 'last_seen',
    'retry audit' => 'retry_count',
    'bounded automatic network retries' => 'maximumAttempts: 5',
    'connectivity waiting' => 'waitsForConnectivity = true',
    'long upload resource timeout' => 'timeoutIntervalForResource = 3600',
    'snapshot known count' => 'previously_known_count',
    'hash-only local identity' => 'UNIQUE(source_hash)',
    'sandbox path reconciliation' => 'reconcileLocalFilePaths',
    'pre-upload local re-verification' => 'localHash == file.sourceHash',
    'enumeration failure handling' => 'errorHandler:',
    'zero-byte rejection' => 'byteSize > 0',
    'iPhone and iPad' => 'TARGETED_DEVICE_FAMILY = "1,2"',
];
$projectText = file_get_contents($app . '/IPCA Garmin Sync.xcodeproj/project.pbxproj') ?: '';

foreach ($assertions as $label => $needle) {
    $haystack = $label === 'iPhone and iPad' ? $projectText : $sources;
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Missing architecture assertion: {$label}\n");
        exit(1);
    }
}

if (str_contains($sources, '/api/garmin-sync/uploads/')) {
    fwrite(STDERR, "Obsolete Garmin Sync REST route remains in app sources.\n");
    exit(1);
}

$settingsSource = file_get_contents($app . '/IPCA Garmin Sync/ContentView.swift') ?: '';
foreach (['SecureField(', 'Bearer credential'] as $forbidden) {
    if (str_contains($settingsSource, $forbidden)) {
        fwrite(STDERR, "Operator-facing manual credential UI remains: {$forbidden}\n");
        exit(1);
    }
}

$enrollmentSource = file_get_contents($app . '/IPCA Garmin Sync/EnrollmentServices.swift') ?: '';
if (str_contains($enrollmentSource, 'SecItemDelete')) {
    fwrite(STDERR, "Enrollment must not delete any existing Keychain item.\n");
    exit(1);
}

$viewModelSource = file_get_contents($app . '/IPCA Garmin Sync/SyncViewModel.swift') ?: '';
foreach ([
    'appendingPathComponent("IPCA Garmin Sync", isDirectory: true)',
    'appendingPathComponent("Files", isDirectory: true)',
    'appendingPathComponent("ingestion.sqlite")',
] as $preservedPath) {
    if (!str_contains($viewModelSource, $preservedPath)) {
        fwrite(STDERR, "Preserved local data path changed: {$preservedPath}\n");
        exit(1);
    }
}

foreach (['DISCOVERED', 'COPYING', 'LOCAL_VERIFIED', 'WAITING_FOR_UPLOAD', 'UPLOADING', 'SERVER_VERIFIED', 'FAILED'] as $state) {
    if (!str_contains($sources, $state)) {
        fwrite(STDERR, "Missing ingestion state: {$state}\n");
        exit(1);
    }
}

echo "Garmin Sync Phase 1 architecture check passed.\n";
