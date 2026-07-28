<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

@ini_set('memory_limit', '512M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');

function cockpit_g3x_finalize_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cockpit_g3x_finalize_uid(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '', $value) ?? '';
    return substr($value, 0, 96);
}

function cockpit_g3x_finalize_meta(string $sessionDir, string $fileType): ?array
{
    $path = $sessionDir . '/' . $fileType . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : null;
}

function cockpit_g3x_finalize_assemble(string $sessionDir, string $fileType, string $extension): ?string
{
    $meta = cockpit_g3x_finalize_meta($sessionDir, $fileType);
    if ($meta === null) {
        return null;
    }

    $totalChunks = (int)($meta['total_chunks'] ?? 0);
    if ($totalChunks <= 0) {
        throw new RuntimeException('Invalid ' . $fileType . ' upload metadata.');
    }

    $chunkDir = $sessionDir . '/' . $fileType;
    if (!is_dir($chunkDir)) {
        throw new RuntimeException('Missing ' . $fileType . ' chunk directory.');
    }

    $assembledDir = $sessionDir . '/assembled';
    if (!is_dir($assembledDir) && !mkdir($assembledDir, 0775, true) && !is_dir($assembledDir)) {
        throw new RuntimeException('Could not create assembly directory.');
    }

    $assembledPath = $assembledDir . '/' . $fileType . '.' . $extension;
    $out = fopen($assembledPath, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not create assembled ' . $fileType . ' file.');
    }

    try {
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $chunkDir . '/' . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . '.part';
            if (!is_file($chunkPath)) {
                throw new RuntimeException('Missing ' . $fileType . ' chunk ' . $i . ' of ' . $totalChunks . '.');
            }
            $in = fopen($chunkPath, 'rb');
            if ($in === false) {
                throw new RuntimeException('Could not read ' . $fileType . ' chunk ' . $i . '.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
    } finally {
        fclose($out);
    }

    $expectedSize = (int)($meta['total_size'] ?? 0);
    $actualSize = (int)filesize($assembledPath);
    if ($expectedSize > 0 && $actualSize !== $expectedSize) {
        throw new RuntimeException('Assembled ' . $fileType . ' size mismatch.');
    }

    return $assembledPath;
}

function cockpit_g3x_finalize_remove_tree(string $path): void
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
        if (is_dir($child)) {
            cockpit_g3x_finalize_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function cockpit_g3x_finalize_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function cockpit_g3x_finalize_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute(array($table));
    return (int)$stmt->fetchColumn() > 0;
}

function cockpit_g3x_finalize_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute(array($table, $column));
    return (int)$stmt->fetchColumn() > 0;
}

function cockpit_g3x_finalize_register_workflow_csv(
    PDO $pdo,
    string $flightRecordUid,
    string $relativePath,
    string $sha256,
    int $byteCount,
    array $meta
): ?int {
    if (!cockpit_g3x_finalize_table_exists($pdo, 'ipca_garmin_csv_files')) {
        return null;
    }

    $columns = array(
        'csv_file_uuid',
        'original_filename',
        'storage_path',
        'sha256',
        'file_size_bytes',
        'mime_type',
        'import_profile',
        'source',
        'evidence_status',
    );
    $values = array(
        ':csv_file_uuid' => cockpit_g3x_finalize_uuid(),
        ':original_filename' => (string)($meta['original_filename'] ?? 'garmin.csv'),
        ':storage_path' => $relativePath,
        ':sha256' => $sha256,
        ':file_size_bytes' => $byteCount,
        ':mime_type' => (string)($meta['mime_type'] ?? 'text/csv'),
        ':import_profile' => 'cvr_workflow_garmin_share',
        ':source' => 'cvr_app',
        ':evidence_status' => 'received',
    );
    if (cockpit_g3x_finalize_column_exists($pdo, 'ipca_garmin_csv_files', 'upload_source')) {
        $columns[] = 'upload_source';
        $values[':upload_source'] = 'cvr_app';
    }
    if (cockpit_g3x_finalize_column_exists($pdo, 'ipca_garmin_csv_files', 'workflow_flight_record_uuid')) {
        $columns[] = 'workflow_flight_record_uuid';
        $values[':workflow_flight_record_uuid'] = $flightRecordUid;
    }

    $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
    $placeholders = array_keys($values);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ipca_garmin_csv_files (' . implode(', ', $quotedColumns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($values);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
        $stmt = $pdo->prepare('SELECT id FROM ipca_garmin_csv_files WHERE sha256 = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute(array($sha256));
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId <= 0) {
            throw $e;
        }
        return $existingId;
    }
}

function cockpit_g3x_finalize_store_workflow_csv(PDO $pdo, string $flightRecordUid, string $g3xPath, array $meta): array
{
    if (!is_file($g3xPath)) {
        throw new RuntimeException('Assembled workflow Garmin CSV is missing.');
    }
    $root = CockpitRecorderService::projectRoot() . '/storage/cockpit_recorder/workflow_garmin';
    $dir = $root . '/' . $flightRecordUid;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create workflow Garmin storage.');
    }

    $receiptId = bin2hex(random_bytes(16));
    $relativePath = 'storage/cockpit_recorder/workflow_garmin/' . $flightRecordUid . '/' . $receiptId . '.csv';
    $absolutePath = CockpitRecorderService::projectRoot() . '/' . $relativePath;
    if (!copy($g3xPath, $absolutePath)) {
        throw new RuntimeException('Could not store workflow Garmin CSV.');
    }

    $sha256 = hash_file('sha256', $absolutePath) ?: '';
    $byteCount = (int)filesize($absolutePath);
    $csvFileId = cockpit_g3x_finalize_register_workflow_csv(
        $pdo,
        $flightRecordUid,
        $relativePath,
        $sha256,
        $byteCount,
        $meta
    );
    $receipt = array(
        'receipt_id' => $receiptId,
        'flight_record_id' => $flightRecordUid,
        'garmin_csv_file_id' => $csvFileId,
        'component_type' => 'garmin_csv',
        'storage_path' => $relativePath,
        'sha256' => $sha256,
        'byte_count' => $byteCount,
        'original_filename' => (string)($meta['original_filename'] ?? 'garmin.csv'),
        'server_verified_at' => gmdate('c'),
    );
    file_put_contents(
        $dir . '/' . $receiptId . '.json',
        json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    return array(
        'ok' => true,
        'receipt' => $receipt,
    );
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        cockpit_g3x_finalize_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }

    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : array();
    if (!is_array($payload)) {
        cockpit_g3x_finalize_json(400, array('ok' => false, 'error' => 'Invalid JSON payload.'));
    }

    $recordingUid = cockpit_g3x_finalize_uid((string)($payload['recording_id'] ?? ''));
    if ($recordingUid === '') {
        cockpit_g3x_finalize_json(400, array('ok' => false, 'error' => 'Recording id is required.'));
    }

    $sessionDir = CockpitRecorderService::uploadSessionRoot() . '/' . $recordingUid;
    if (!is_dir($sessionDir)) {
        cockpit_g3x_finalize_json(404, array('ok' => false, 'error' => 'Upload session not found.'));
    }

    $g3xPath = cockpit_g3x_finalize_assemble($sessionDir, 'g3x', 'csv');
    if ($g3xPath === null) {
        cockpit_g3x_finalize_json(400, array('ok' => false, 'error' => 'G3X CSV chunks are missing.'));
    }

    $importProfile = (string)($payload['import_profile'] ?? '');
    $service = new CockpitRecorderService($pdo);
    try {
        $result = $service->storeSupplementalG3X($recordingUid, $g3xPath, $importProfile);
    } catch (Throwable $e) {
        if ($importProfile !== 'cvr_workflow_garmin_share') {
            throw $e;
        }
        $result = cockpit_g3x_finalize_store_workflow_csv($pdo, $recordingUid, $g3xPath, cockpit_g3x_finalize_meta($sessionDir, 'g3x') ?? array());
    }

    cockpit_g3x_finalize_remove_tree($sessionDir);
    cockpit_g3x_finalize_json(200, $result);
} catch (Throwable $e) {
    cockpit_g3x_finalize_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
