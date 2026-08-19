<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/scripts/sql/2026_08_19_ipca_garmin_sync_phase1.sql';
$servicePath = $root . '/src/GarminSyncUploadService.php';
$apiDir = $root . '/public/api/garmin-sync';
$docPath = $root . '/docs/garmin_sync_phase1_api.md';

$required = array(
    $migrationPath,
    $servicePath,
    $apiDir . '/_bootstrap.php',
    $apiDir . '/known_hashes.php',
    $apiDir . '/upload_chunk.php',
    $apiDir . '/finalize.php',
    $apiDir . '/status.php',
    $docPath,
);
foreach ($required as $path) {
    if (!is_file($path)) {
        fail("missing file {$path}");
    }
}

$migration = (string)file_get_contents($migrationPath);
$service = (string)file_get_contents($servicePath);
$api = implode("\n", array_map(
    static fn(string $path): string => (string)file_get_contents($path),
    array_slice($required, 2, 5)
));

check(
    'schema uses only isolated Garmin Sync tables',
    preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/i', $migration, $matches) === 3
        && count(array_filter(
            $matches[1],
            static fn(string $name): bool => !str_starts_with($name, 'ipca_garmin_sync_')
        )) === 0
);
check(
    'schema enforces one immutable object per SHA-256',
    str_contains($migration, 'UNIQUE KEY uk_ipca_garmin_sync_archive_sha256 (sha256)')
        && str_contains($migration, 'expected_byte_count')
        && str_contains($migration, 'received_chunks_json')
        && str_contains($migration, 'receipt_json')
        && !preg_match('/\b(?:DROP\s+TABLE|TRUNCATE|DELETE\s+FROM)\b/i', $migration)
);
check(
    'service stays inside isolated storage and does no parsing',
    str_contains($service, "/storage/garmin_sync")
        && str_contains($service, "/upload_sessions/")
        && str_contains($service, "storage/garmin_sync/archive/")
        && !preg_match('/GarminCsv|Cockpit|Reconstruction|AnalysisService/', $service)
);
check(
    'API uses DeviceAuthService bearer authentication',
    substr_count($api, 'DeviceAuthService') >= 4
        && str_contains($api, "'error_code'")
        && str_contains($api, "'retryable'")
);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP runtime archive flow (pdo_sqlite unavailable)\n";
    exit(0);
}

require_once $servicePath;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE ipca_garmin_sync_archive_files (
       id INTEGER PRIMARY KEY AUTOINCREMENT, object_uuid TEXT NOT NULL UNIQUE,
       sha256 TEXT NOT NULL UNIQUE, byte_count INTEGER NOT NULL, storage_path TEXT NOT NULL UNIQUE,
       original_filename TEXT NOT NULL, creator_organization_id INTEGER NOT NULL,
       creator_device_id INTEGER NOT NULL, verified_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
     );
     CREATE TABLE ipca_garmin_sync_upload_sessions (
       id INTEGER PRIMARY KEY AUTOINCREMENT, upload_uuid TEXT NOT NULL UNIQUE,
       request_uuid TEXT NOT NULL, organization_id INTEGER NOT NULL, device_id INTEGER NOT NULL,
       expected_sha256 TEXT NOT NULL, expected_byte_count INTEGER NOT NULL, total_chunks INTEGER NOT NULL,
       received_chunks_json TEXT, received_byte_count INTEGER NOT NULL DEFAULT 0,
       original_filename TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "receiving",
       retry_count INTEGER NOT NULL DEFAULT 0, last_error_code TEXT, last_error_message TEXT,
       last_error_retryable INTEGER, archive_file_id INTEGER, receipt_uuid TEXT, receipt_json TEXT,
       finalized_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
       UNIQUE (organization_id, device_id, request_uuid)
     );
     CREATE TABLE ipca_garmin_sync_upload_chunks (
       id INTEGER PRIMARY KEY AUTOINCREMENT, upload_session_id INTEGER NOT NULL,
       chunk_index INTEGER NOT NULL, byte_count INTEGER NOT NULL, chunk_sha256 TEXT NOT NULL,
       storage_name TEXT NOT NULL, received_at TEXT DEFAULT CURRENT_TIMESTAMP,
       created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (upload_session_id, chunk_index)
     )'
);

$tempRoot = sys_get_temp_dir() . '/ipca-garmin-sync-contract-' . bin2hex(random_bytes(6));
$serviceRuntime = new GarminSyncUploadService($pdo, $tempRoot);
$device = array('id' => 17, 'organization_id' => 3);
$otherDevice = array('id' => 29, 'organization_id' => 4);

try {
    $first = uploadPayload($serviceRuntime, $device, uuidFor(1), 'flight.fit', 'alpha-garmin-binary');
    check('finalize emits a verified receipt', ($first['receipt']['verified'] ?? false) === true);
    check('archive uses verified hash-safe naming', is_file(
        $tempRoot . '/archive/' . gmdate('Y/m/d') . '/' . $first['receipt']['sha256'] . '.bin'
    ));

    $unknownBeforeAssociation = $serviceRuntime->knownHashes($otherDevice, array($first['receipt']['sha256']));
    check('global dedupe does not leak hashes across organizations', $unknownBeforeAssociation['known'] === array());

    $duplicate = uploadPayload($serviceRuntime, $device, uuidFor(2), 'renamed.fit', 'alpha-garmin-binary');
    check(
        'same hash returns existing verified object',
        $duplicate['status'] === 'duplicate'
            && $duplicate['receipt']['object_id'] === $first['receipt']['object_id']
    );

    $different = uploadPayload($serviceRuntime, $device, uuidFor(3), 'flight.fit', 'different-binary');
    check(
        'filename reuse with different hash creates a new object',
        $different['receipt']['object_id'] !== $first['receipt']['object_id']
    );

    try {
        uploadPayload(
            $serviceRuntime,
            $device,
            uuidFor(4),
            'wrong-hash.fit',
            'payload-that-must-not-be-archived',
            str_repeat('0', 64)
        );
        fail('wrong expected SHA-256 unexpectedly finalized');
    } catch (GarminSyncUploadException $error) {
        check('wrong expected SHA-256 is rejected', $error->errorCode() === 'SHA256_MISMATCH');
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM ipca_garmin_sync_archive_files')->fetchColumn();
    check('archive has exactly two content objects', $count === 2);

    try {
        $serviceRuntime->status($otherDevice, uuidFor(1));
        fail('other device unexpectedly read upload status');
    } catch (GarminSyncUploadException $error) {
        check('upload status is organization/device scoped', $error->errorCode() === 'UPLOAD_NOT_FOUND');
    }
} finally {
    removeTree($tempRoot);
}

/**
 * @param array<string,mixed> $device
 * @return array<string,mixed>
 */
function uploadPayload(
    GarminSyncUploadService $service,
    array $device,
    string $uploadUuid,
    string $filename,
    string $payload,
    ?string $expectedSha256 = null
): array {
    $split = max(1, intdiv(strlen($payload), 2));
    $parts = array(substr($payload, 0, $split), substr($payload, $split));
    foreach ($parts as $index => $part) {
        $tmp = tempnam(sys_get_temp_dir(), 'garmin-sync-chunk-');
        if ($tmp === false) {
            fail('could not create test chunk');
        }
        file_put_contents($tmp, $part);
        $service->receiveChunk(
            $device,
            array('tmp_name' => $tmp, 'name' => $filename, 'error' => UPLOAD_ERR_OK),
            array(
                'upload_uuid' => $uploadUuid,
                'request_uuid' => 'request-' . $uploadUuid,
                'expected_sha256' => $expectedSha256 ?? hash('sha256', $payload),
                'expected_byte_count' => strlen($payload),
                'total_chunks' => count($parts),
                'chunk_index' => $index,
                'original_filename' => $filename,
            )
        );
    }
    return $service->finalize($device, $uploadUuid);
}

function uuidFor(int $number): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $number);
}

function check(string $name, bool $passed): void
{
    echo ($passed ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$passed) {
        exit(1);
    }
}

function fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        is_dir($child) ? removeTree($child) : @unlink($child);
    }
    @rmdir($path);
}
