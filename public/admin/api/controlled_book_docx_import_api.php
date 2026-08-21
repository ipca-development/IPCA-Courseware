<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingLepService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingDocxImportService.php';

header('Content-Type: application/json; charset=utf-8');

function cp_docx_import_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cp_docx_import_job_token(string $token): string
{
    $token = strtolower(trim($token));
    return preg_match('/^[a-f0-9]{32,64}$/', $token) === 1 ? $token : '';
}

function cp_docx_import_job_path(int $uid, string $token): string
{
    $root = dirname(__DIR__, 3) . '/storage/docx_import_jobs/' . max(0, $uid);
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Could not initialize DOCX import progress storage.');
    }
    return $root . '/' . $token . '.json';
}

/** @param array<string,mixed> $status */
function cp_docx_import_write_status(int $uid, string $token, array $status): void
{
    if ($token === '') {
        return;
    }
    $path = cp_docx_import_job_path($uid, $token);
    $status['job_token'] = $token;
    $status['updated_at'] = gmdate('c');
    $json = json_encode(
        $status,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not update DOCX import progress.');
    }
}

/** @return array<string,mixed>|null */
function cp_docx_import_read_status(int $uid, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $path = cp_docx_import_job_path($uid, $token);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

$foundation = new ControlledPublishingFoundationService($pdo);
$blocks = new ControlledPublishingBlockService($pdo);
$sections = new ControlledPublishingSectionService($pdo);
$styleSvc = new ControlledPublishingBookStyleService($pdo);
$part0PageSvc = new ControlledPublishingPart0PageService($pdo, $blocks);
$lepSvc = new ControlledPublishingLepService($pdo);
$manualStructureSvc = new ControlledPublishingManualStructureService($pdo, $foundation, $sections, $blocks);
$importSvc = new ControlledPublishingDocxImportService(
    $pdo,
    $foundation,
    $sections,
    $blocks,
    $manualStructureSvc,
    $part0PageSvc,
    $styleSvc,
    $lepSvc
);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$jobToken = '';

try {
    if ($action === 'import_status') {
        $jobToken = cp_docx_import_job_token((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        if ($jobToken === '') {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'A valid import token is required.'));
        }
        $status = cp_docx_import_read_status($uid, $jobToken);
        if ($status === null) {
            cp_docx_import_json(404, array(
                'ok' => false,
                'pending' => true,
                'error' => 'The server has not received the import yet.',
            ));
        }
        cp_docx_import_json(200, array('ok' => true, 'status' => $status));
    }

    if ($action === 'preview_docx_import') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'version_id required'));
        }
        $partFiles = cp_docx_import_collect_uploads();
        if ($partFiles === array()) {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'Upload at least one Part DOCX file.'));
        }
        $preview = $importSvc->preview($versionId, $partFiles);
        cp_docx_import_json(200, array('ok' => true, 'preview' => $preview));
    }

    if ($action === 'apply_docx_import') {
        $jobToken = cp_docx_import_job_token((string)($_POST['job_token'] ?? ''));
        if ($jobToken === '') {
            $jobToken = bin2hex(random_bytes(24));
        }
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'version_id required'));
        }
        $force = !isset($_POST['force']) || (string)$_POST['force'] !== '0';
        $partFiles = cp_docx_import_collect_uploads();
        if ($partFiles === array()) {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'Upload at least one Part DOCX file.'));
        }
        cp_docx_import_write_status($uid, $jobToken, array(
            'status' => 'running',
            'phase' => 'received',
            'percent' => 10,
            'message' => 'Upload received. Starting the server import…',
            'version_id' => $versionId,
        ));
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        $progress = static function (
            int $percent,
            string $phase,
            string $message
        ) use ($uid, $jobToken, $versionId): void {
            cp_docx_import_write_status($uid, $jobToken, array(
                'status' => 'running',
                'phase' => $phase,
                'percent' => $percent,
                'message' => $message,
                'version_id' => $versionId,
            ));
        };
        $result = $importSvc->apply($versionId, $partFiles, $force, $uid, $progress);
        cp_docx_import_write_status($uid, $jobToken, array(
            'status' => 'completed',
            'phase' => 'completed',
            'percent' => 100,
            'message' => 'Import complete. The manual is ready in the editor.',
            'version_id' => $versionId,
            'result' => $result,
        ));
        cp_docx_import_json(200, array(
            'ok' => true,
            'job_token' => $jobToken,
            'result' => $result,
        ));
    }

    cp_docx_import_json(400, array('ok' => false, 'error' => 'Unknown action.'));
} catch (Throwable $e) {
    if ($jobToken !== '') {
        try {
            cp_docx_import_write_status($uid, $jobToken, array(
                'status' => 'failed',
                'phase' => 'failed',
                'percent' => 100,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ));
        } catch (Throwable $statusError) {
            error_log('DOCX import status write failed: ' . $statusError->getMessage());
        }
    }
    $code = $e instanceof PDOException ? 500 : 400;
    cp_docx_import_json($code, array(
        'ok' => false,
        'job_token' => $jobToken !== '' ? $jobToken : null,
        'error' => $e->getMessage(),
        'error_type' => (new ReflectionClass($e))->getShortName(),
    ));
}

/**
 * @return array<int,string> manual_part => temp file path
 */
function cp_docx_import_collect_uploads(): array
{
    $files = array();

    foreach ($_FILES as $field => $upload) {
        if (!is_array($upload)) {
            continue;
        }
        if (is_array($upload['name'] ?? null)) {
            foreach ($upload['name'] as $idx => $name) {
                $part = cp_docx_import_detect_part((string)$field, (string)$name);
                if ($part < 0) {
                    continue;
                }
                $err = (int)($upload['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
                $tmp = (string)($upload['tmp_name'][$idx] ?? '');
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(
                        cp_docx_import_upload_error_message((string)$name, $err)
                    );
                }
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('The uploaded DOCX file could not be verified: ' . $name);
                }
                $files[$part] = $tmp;
            }
            continue;
        }

        $name = (string)($upload['name'] ?? '');
        $part = cp_docx_import_detect_part((string)$field, $name);
        if ($part < 0) {
            continue;
        }
        $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException(cp_docx_import_upload_error_message($name, $err));
        }
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The uploaded DOCX file could not be verified: ' . $name);
        }
        $files[$part] = $tmp;
    }

    ksort($files);
    return $files;
}

function cp_docx_import_upload_error_message(string $name, int $error): string
{
    $label = trim($name) !== '' ? trim($name) : 'DOCX file';
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            "{$label} exceeds the server upload-size limit.",
        UPLOAD_ERR_PARTIAL => "{$label} was only partially uploaded. Select it again and retry.",
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded DOCX file.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the DOCX upload.',
        default => "{$label} could not be uploaded (error {$error}).",
    };
}

function cp_docx_import_detect_part(string $field, string $filename): int
{
    if (preg_match('/part[_-]?(\d+)/i', $field, $m) === 1) {
        return (int)$m[1];
    }
    return ControlledPublishingDocxReader::detectManualPartFromFilename($filename);
}
