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

try {
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
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'version_id required'));
        }
        $force = !isset($_POST['force']) || (string)$_POST['force'] !== '0';
        $partFiles = cp_docx_import_collect_uploads();
        if ($partFiles === array()) {
            cp_docx_import_json(400, array('ok' => false, 'error' => 'Upload at least one Part DOCX file.'));
        }
        $result = $importSvc->apply($versionId, $partFiles, $force, $uid);
        cp_docx_import_json(200, array('ok' => true, 'result' => $result));
    }

    cp_docx_import_json(400, array('ok' => false, 'error' => 'Unknown action.'));
} catch (Throwable $e) {
    cp_docx_import_json(400, array('ok' => false, 'error' => $e->getMessage()));
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
                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
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
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $files[$part] = $tmp;
    }

    ksort($files);
    return $files;
}

function cp_docx_import_detect_part(string $field, string $filename): int
{
    if (preg_match('/part[_-]?(\d+)/i', $field, $m) === 1) {
        return (int)$m[1];
    }
    return ControlledPublishingDocxReader::detectManualPartFromFilename($filename);
}
