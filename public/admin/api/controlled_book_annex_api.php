<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingLepService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingDocxImportService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingAnnexService.php';

header('Content-Type: application/json; charset=utf-8');

function cp_annex_json(int $code, array $payload): void
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
$manualStructure = new ControlledPublishingManualStructureService($pdo, $foundation, $sections, $blocks);
$styleSvc = new ControlledPublishingBookStyleService($pdo);
$part0PageSvc = new ControlledPublishingPart0PageService($pdo, $blocks);
$lepSvc = new ControlledPublishingLepService($pdo);
$docxImport = new ControlledPublishingDocxImportService(
    $pdo,
    $foundation,
    $sections,
    $blocks,
    $manualStructure,
    $part0PageSvc,
    $styleSvc,
    $lepSvc
);
$annexSvc = new ControlledPublishingAnnexService($pdo, $foundation, $sections, $blocks, $docxImport);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

try {
    if ($action === 'list') {
        $versionId = (int)($_GET['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_annex_json(400, array('ok' => false, 'error' => 'version_id required'));
        }
        $annexSvc->ensureAnnexInfrastructure($versionId, $uid);
        $annexes = array();
        foreach ($annexSvc->listAnnexSections($versionId) as $row) {
            $meta = $annexSvc->decodeAnnexMeta($row);
            $suffix = $annexSvc->annexSuffixFromSection($row);
            $number = (int)($meta['number'] ?? 0);
            $annexes[] = array(
                'section_id' => (int)($row['id'] ?? 0),
                'section_key' => (string)($row['section_key'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'annex_number' => $number,
                'annex_suffix' => $suffix,
                'annex_display_number' => ControlledPublishingAnnexService::formatAnnexDisplayNumber($number, $suffix),
                'revision' => (string)($meta['revision'] ?? ''),
                'revision_date' => (string)($meta['revision_date'] ?? ''),
                'updated_by' => (string)($meta['updated_by_name'] ?? ''),
                'content_mode' => (string)($meta['content_mode'] ?? ''),
                'orientation' => (string)($meta['page_orientation'] ?? 'portrait'),
                'ocr_status' => (string)($meta['ocr_status'] ?? ''),
            );
        }
        cp_annex_json(200, array(
            'ok' => true,
            'annexes' => $annexes,
            'register' => $annexSvc->resolveRegisterPage($versionId),
        ));
    }

    if ($action === 'create') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_annex_json(400, array('ok' => false, 'error' => 'version_id required'));
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $contentMode = strtolower(trim((string)($_POST['content_mode'] ?? 'empty')));
        $orientation = strtolower(trim((string)($_POST['orientation'] ?? 'portrait')));
        $revision = trim((string)($_POST['revision'] ?? '1.0'));
        $revisionDate = trim((string)($_POST['revision_date'] ?? date('Y-m-d')));
        $annexNumber = (int)($_POST['annex_number'] ?? 0);
        $annexSuffix = trim((string)($_POST['annex_suffix'] ?? ''));

        $input = array(
            'title' => $title,
            'content_mode' => $contentMode,
            'orientation' => $orientation,
            'revision' => $revision,
            'revision_date' => $revisionDate,
            'annex_number' => $annexNumber,
            'annex_suffix' => $annexSuffix,
        );

        $tmpImage = null;
        $tmpDocx = null;
        if ($contentMode === 'image' && !empty($_FILES['image']) && is_array($_FILES['image'])) {
            $tmpImage = cp_annex_store_upload($_FILES['image'], array('jpg', 'jpeg', 'png', 'webp'));
            $input['image_path'] = $tmpImage;
        } elseif ($contentMode === 'docx' && !empty($_FILES['docx']) && is_array($_FILES['docx'])) {
            $tmpDocx = cp_annex_store_upload($_FILES['docx'], array('docx'));
            $input['docx_path'] = $tmpDocx;
        } elseif (in_array($contentMode, array('image', 'docx'), true)) {
            cp_annex_json(400, array('ok' => false, 'error' => 'Upload file required for selected content mode.'));
        }

        $result = $annexSvc->createAnnex($versionId, $input, $uid);
        if ($tmpImage !== null && is_file($tmpImage)) {
            @unlink($tmpImage);
        }
        if ($tmpDocx !== null && is_file($tmpDocx)) {
            @unlink($tmpDocx);
        }

        cp_annex_json(200, array(
            'ok' => true,
            'annex' => $result,
            'editor_url' => '/admin/compliance/controlled_book_editor.php?version_id=' . $versionId . '&section_id=' . (int)$result['section_id'],
        ));
    }

    if ($action === 'reimport') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $contentMode = strtolower(trim((string)($_POST['content_mode'] ?? '')));
        if ($versionId <= 0 || $sectionId <= 0) {
            cp_annex_json(400, array('ok' => false, 'error' => 'version_id and section_id required'));
        }

        $orientation = strtolower(trim((string)($_POST['orientation'] ?? '')));
        if ($orientation === 'portrait' || $orientation === 'landscape') {
            $annexSvc->updateAnnexMeta($sectionId, array('page_orientation' => $orientation), $uid);
        }

        $result = array();
        if ($contentMode === 'image' && !empty($_FILES['image'])) {
            $path = cp_annex_store_upload($_FILES['image'], array('jpg', 'jpeg', 'png', 'webp'));
            $result = $annexSvc->importAnnexImage($versionId, $sectionId, $path, true, $uid);
            @unlink($path);
        } elseif ($contentMode === 'docx' && !empty($_FILES['docx'])) {
            $path = cp_annex_store_upload($_FILES['docx'], array('docx'));
            $result = $annexSvc->importAnnexDocx($versionId, $sectionId, $path, true, $uid);
            @unlink($path);
        } else {
            cp_annex_json(400, array('ok' => false, 'error' => 'image or docx upload required'));
        }

        cp_annex_json(200, array('ok' => true, 'result' => $result));
    }

    if ($action === 'run_ocr') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        $sectionId = (int)($_POST['section_id'] ?? 0);
        if ($versionId <= 0 || $sectionId <= 0) {
            cp_annex_json(400, array('ok' => false, 'error' => 'version_id and section_id required'));
        }
        $result = $annexSvc->runAnnexOcr($versionId, $sectionId, $uid);
        cp_annex_json(200, array('ok' => true, 'result' => $result));
    }

    if ($action === 'regenerate_register') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_annex_json(400, array('ok' => false, 'error' => 'version_id required'));
        }
        cp_annex_json(200, array('ok' => true, 'result' => $annexSvc->regenerateRegister($versionId, $uid)));
    }

    if ($action === 'regenerate_highlights') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) {
            cp_annex_json(400, array('ok' => false, 'error' => 'version_id required'));
        }
        cp_annex_json(200, array('ok' => true, 'result' => $annexSvc->regenerateHighlights($versionId, $uid)));
    }

    cp_annex_json(400, array('ok' => false, 'error' => 'Unknown action.'));
} catch (Throwable $e) {
    cp_annex_json(400, array('ok' => false, 'error' => $e->getMessage()));
}

/**
 * @param array<string,mixed> $file
 * @param list<string> $allowedExt
 */
function cp_annex_store_upload(array $file, array $allowedExt): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $err . ').');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }
    $name = (string)($file['name'] ?? 'upload');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Invalid file type.');
    }
    $dest = sys_get_temp_dir() . '/cp_annex_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not store upload.');
    }
    return $dest;
}
