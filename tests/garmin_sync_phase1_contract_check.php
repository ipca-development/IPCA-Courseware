<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/scripts/sql/2026_08_19_ipca_garmin_sync_phase1.sql';
$authMigrationPath = $root . '/scripts/sql/2026_08_19_ipca_garmin_sync_device_auth.sql';
$servicePath = $root . '/src/GarminSyncUploadService.php';
$authServicePath = $root . '/src/GarminSyncAuthService.php';
$apiDir = $root . '/public/api/garmin-sync';
$docPath = $root . '/docs/garmin_sync_phase1_api.md';
$adminPath = $root . '/public/admin/garmin_sync_enrollment.php';

$required = array(
    $migrationPath,
    $authMigrationPath,
    $servicePath,
    $authServicePath,
    $apiDir . '/_bootstrap.php',
    $apiDir . '/enroll.php',
    $apiDir . '/known_hashes.php',
    $apiDir . '/upload_chunk.php',
    $apiDir . '/finalize.php',
    $apiDir . '/status.php',
    $adminPath,
    $docPath,
);
foreach ($required as $path) {
    if (!is_file($path)) {
        fail("missing file {$path}");
    }
}

$migration = (string)file_get_contents($migrationPath);
$authMigration = (string)file_get_contents($authMigrationPath);
$service = (string)file_get_contents($servicePath);
$authService = (string)file_get_contents($authServicePath);
$apiFiles = glob($apiDir . '/*.php') ?: array();
$api = implode("\n", array_map(static fn(string $path): string => (string)file_get_contents($path), $apiFiles));

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
    'API uses dedicated Garmin Sync bearer authentication',
    substr_count($api, 'GarminSyncAuthService') >= 5
        && !str_contains($api, 'DeviceAuthService')
        && str_contains($api, "'error_code'")
        && str_contains($api, "'retryable'")
);
check(
    'auth migration creates only three dedicated Garmin Sync tables',
    preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/i', $authMigration, $authMatches) === 3
        && $authMatches[1] === array(
            'ipca_garmin_sync_devices',
            'ipca_garmin_sync_device_enrollments',
            'ipca_garmin_sync_device_credentials',
        )
        && !preg_match('/^\s*(?:ALTER|DROP|TRUNCATE|DELETE)\b/im', $authMigration)
);
check(
    'Garmin authentication is independent from CVR authentication and storage',
    !preg_match('/DeviceAuthService|ipca_cvr_/i', $api . "\n" . $authService . "\n" . $authMigration)
        && str_contains($authService, 'GARMIN_ENROLLMENT_CODE_CONSUMED')
        && str_contains($authService, 'GARMIN_AUTH_CREDENTIAL_REVOKED')
);
check(
    'admin enrollment is protected and never issues a credential',
    str_contains((string)file_get_contents($adminPath), 'cw_require_admin()')
        && str_contains((string)file_get_contents($adminPath), 'hash_equals')
        && !str_contains((string)file_get_contents($adminPath), 'exchangeEnrollmentCode')
);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP runtime archive flow (pdo_sqlite unavailable)\n";
    exit(0);
}

require_once $servicePath;
require_once $authServicePath;

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
     );
     CREATE TABLE ipca_garmin_sync_devices (
       id INTEGER PRIMARY KEY AUTOINCREMENT, device_uuid TEXT NOT NULL UNIQUE,
       organization_id INTEGER NOT NULL, display_name TEXT NOT NULL DEFAULT "",
       active INTEGER NOT NULL DEFAULT 1, revoked_at TEXT, last_seen_at TEXT,
       created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
     );
     CREATE TABLE ipca_garmin_sync_device_enrollments (
       id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_uuid TEXT NOT NULL UNIQUE,
       organization_id INTEGER NOT NULL, code_hash TEXT NOT NULL UNIQUE,
       status TEXT NOT NULL DEFAULT "pending", expires_at TEXT NOT NULL,
       consumed_at TEXT, revoked_at TEXT, created_by INTEGER NOT NULL,
       created_at TEXT DEFAULT CURRENT_TIMESTAMP
     );
     CREATE TABLE ipca_garmin_sync_device_credentials (
       id INTEGER PRIMARY KEY AUTOINCREMENT, credential_uuid TEXT NOT NULL UNIQUE,
       device_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE,
       expires_at TEXT, revoked_at TEXT, last_used_at TEXT,
       created_at TEXT DEFAULT CURRENT_TIMESTAMP
     )'
);

$auth = new GarminSyncAuthService($pdo);
$enrollment = $auth->createEnrollmentCode(3, 41, 60);
$storedEnrollmentHash = (string)$pdo->query(
    'SELECT code_hash FROM ipca_garmin_sync_device_enrollments LIMIT 1'
)->fetchColumn();
check(
    'enrollment stores only a SHA-256 hash',
    $storedEnrollmentHash === hash('sha256', $enrollment['enrollment_code'])
        && $storedEnrollmentHash !== $enrollment['enrollment_code']
);
$credential = $auth->exchangeEnrollmentCode(
    $enrollment['enrollment_code'],
    '10000000-0000-4000-8000-000000000001',
    'Contract test device'
);
check(
    'enrollment returns one plain credential with organization-scoped device',
    $credential['credential'] !== ''
        && ($credential['device']['organization_id'] ?? 0) === 3
        && (string)$pdo->query('SELECT token_hash FROM ipca_garmin_sync_device_credentials LIMIT 1')->fetchColumn()
            === hash('sha256', $credential['credential'])
);
try {
    $auth->exchangeEnrollmentCode(
        $enrollment['enrollment_code'],
        '10000000-0000-4000-8000-000000000002'
    );
    fail('consumed enrollment code unexpectedly reused');
} catch (GarminSyncAuthException $error) {
    check('enrollment code is single-use', $error->errorCode() === 'GARMIN_ENROLLMENT_CODE_CONSUMED');
}
$authenticated = $auth->authenticateBearerToken('Bearer ' . $credential['credential']);
check(
    'credential authentication updates device and credential use',
    (int)$authenticated['id'] > 0
        && (string)$pdo->query('SELECT last_seen_at FROM ipca_garmin_sync_devices LIMIT 1')->fetchColumn() !== ''
        && (string)$pdo->query('SELECT last_used_at FROM ipca_garmin_sync_device_credentials LIMIT 1')->fetchColumn() !== ''
);
$pdo->exec("UPDATE ipca_garmin_sync_device_credentials SET revoked_at = '2026-01-01 00:00:00'");
try {
    $auth->authenticateBearerToken('Bearer ' . $credential['credential']);
    fail('revoked credential unexpectedly authenticated');
} catch (GarminSyncAuthException $error) {
    check('revoked credential is rejected', $error->errorCode() === 'GARMIN_AUTH_CREDENTIAL_REVOKED');
}

$expiredEnrollment = $auth->createEnrollmentCode(3, 41, 60);
$pdo->prepare('UPDATE ipca_garmin_sync_device_enrollments SET expires_at = ? WHERE enrollment_uuid = ?')
    ->execute(array('2000-01-01 00:00:00', $expiredEnrollment['enrollment_uuid']));
try {
    $auth->exchangeEnrollmentCode(
        $expiredEnrollment['enrollment_code'],
        '10000000-0000-4000-8000-000000000003'
    );
    fail('expired enrollment code unexpectedly exchanged');
} catch (GarminSyncAuthException $error) {
    check('expired enrollment code is rejected', $error->errorCode() === 'GARMIN_ENROLLMENT_CODE_EXPIRED');
}

$revokedEnrollment = $auth->createEnrollmentCode(3, 41, 60);
$pdo->prepare("UPDATE ipca_garmin_sync_device_enrollments SET status = 'revoked', revoked_at = ? WHERE enrollment_uuid = ?")
    ->execute(array(gmdate('Y-m-d H:i:s'), $revokedEnrollment['enrollment_uuid']));
try {
    $auth->exchangeEnrollmentCode(
        $revokedEnrollment['enrollment_code'],
        '10000000-0000-4000-8000-000000000004'
    );
    fail('revoked enrollment code unexpectedly exchanged');
} catch (GarminSyncAuthException $error) {
    check('revoked enrollment code is rejected', $error->errorCode() === 'GARMIN_ENROLLMENT_CODE_REVOKED');
}

$expiryEnrollment = $auth->createEnrollmentCode(3, 41, 60);
$expiryCredential = $auth->exchangeEnrollmentCode(
    $expiryEnrollment['enrollment_code'],
    '10000000-0000-4000-8000-000000000005'
);
$pdo->prepare('UPDATE ipca_garmin_sync_device_credentials SET expires_at = ? WHERE credential_uuid = ?')
    ->execute(array('2000-01-01 00:00:00', $expiryCredential['credential_uuid']));
try {
    $auth->authenticateBearerToken('Bearer ' . $expiryCredential['credential']);
    fail('expired credential unexpectedly authenticated');
} catch (GarminSyncAuthException $error) {
    check('expired credential is rejected', $error->errorCode() === 'GARMIN_AUTH_CREDENTIAL_EXPIRED');
}

$deviceEnrollment = $auth->createEnrollmentCode(3, 41, 60);
$deviceCredential = $auth->exchangeEnrollmentCode(
    $deviceEnrollment['enrollment_code'],
    '10000000-0000-4000-8000-000000000006'
);
$pdo->prepare('UPDATE ipca_garmin_sync_devices SET revoked_at = ? WHERE id = ?')
    ->execute(array(gmdate('Y-m-d H:i:s'), (int)$deviceCredential['device']['id']));
try {
    $auth->authenticateBearerToken('Bearer ' . $deviceCredential['credential']);
    fail('revoked device unexpectedly authenticated');
} catch (GarminSyncAuthException $error) {
    check('revoked device is rejected', $error->errorCode() === 'GARMIN_AUTH_DEVICE_REVOKED');
}

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
