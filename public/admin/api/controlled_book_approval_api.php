<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingPageHeaderService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingCoverPageService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingLepService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingApprovalService.php';
require_once __DIR__ . '/../../../src/spaces.php';

header('Content-Type: application/json; charset=utf-8');

function cp_approval_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cp_approval_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return $_POST;
}

/**
 * @return array{bytes:string,mime:string,ext:string}
 */
function cp_approval_require_signature_upload(): array
{
    if (!empty($_FILES['signature']) && is_array($_FILES['signature'])) {
        $file = $_FILES['signature'];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            cp_approval_json(400, array('ok' => false, 'error' => 'Upload failed (code ' . $err . ')'));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            cp_approval_json(400, array('ok' => false, 'error' => 'Invalid upload'));
        }
        $mime = (string)($file['type'] ?? 'image/png');
        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'png',
        };
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            cp_approval_json(500, array('ok' => false, 'error' => 'Could not read upload'));
        }
        return array('bytes' => $bytes, 'mime' => $mime, 'ext' => $ext);
    }

    $in = cp_approval_input();
    $dataUrl = trim((string)($in['signature_data_url'] ?? ''));
    if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
        cp_approval_json(400, array('ok' => false, 'error' => 'signature file or signature_data_url required'));
    }
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) {
        cp_approval_json(400, array('ok' => false, 'error' => 'Invalid signature image data'));
    }
    $ext = strtolower($m[1]) === 'jpeg' || strtolower($m[1]) === 'jpg' ? 'jpg' : strtolower($m[1]);
    $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
    $raw = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bytes = base64_decode($raw, true);
    if ($bytes === false) {
        cp_approval_json(400, array('ok' => false, 'error' => 'Could not decode signature image'));
    }
    return array('bytes' => $bytes, 'mime' => $mime, 'ext' => $ext);
}

function cp_approval_upload_asset(array $version, string $filenamePrefix, array $upload): string
{
    $bookKey = strtolower((string)$version['book_key']);
    $versionLabel = str_replace('.', '_', (string)$version['version_label']);
    $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/' . $filenamePrefix . bin2hex(random_bytes(12)) . '.' . $upload['ext'];
    $put = cw_spaces_put_object($objectKey, $upload['bytes'], $upload['mime']);
    $url = (string)($put['cdn_url'] ?? '');
    if ($url === '') {
        cp_approval_json(500, array('ok' => false, 'error' => 'Upload succeeded but CDN URL missing'));
    }
    return $url;
}

$foundation = new ControlledPublishingFoundationService($pdo);
$sections = new ControlledPublishingSectionService($pdo);
$blocks = new ControlledPublishingBlockService($pdo);
$renderer = new ControlledPublishingBookRenderer();
$styleSvc = new ControlledPublishingBookStyleService($pdo);
$numberSvc = new ControlledPublishingSectionNumberService($pdo, $blocks);
$pageHeaderSvc = new ControlledPublishingPageHeaderService($pdo);
$coverPageSvc = new ControlledPublishingCoverPageService($pdo);
$lepPageSvc = new ControlledPublishingLepService($pdo);
$approvalSvc = new ControlledPublishingApprovalService($pdo, $lepPageSvc);
$renderer->setPageHeaderService($pageHeaderSvc);
$renderer->setLepPageService($lepPageSvc);
$renderer->setCoverPageService($coverPageSvc);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = cp_approval_input();
    $action = (string)($json['action'] ?? '');
}

try {
    switch ($action) {
        case 'load':
            cp_approval_handle_load($sections, $blocks, $renderer, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc);
            break;
        case 'submit':
            cp_approval_handle_submit($sections, $blocks, $renderer, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc);
            break;
        default:
            cp_approval_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    cp_approval_json(400, array('ok' => false, 'error' => $e->getMessage()));
}

function cp_approval_require_token_context(
    ControlledPublishingApprovalService $approvalSvc,
    int $versionId,
    string $token
): array {
    if ($versionId <= 0 || trim($token) === '') {
        cp_approval_json(400, array('ok' => false, 'error' => 'version_id and token required'));
    }
    $version = $approvalSvc->getVersionByApprovalToken($token);
    if ($version === null || (int)($version['id'] ?? 0) !== $versionId) {
        cp_approval_json(403, array('ok' => false, 'error' => 'Invalid approval token.'));
    }
    $approval = $approvalSvc->resolveApproval($versionId);
    if ($approval['token'] === '' || !hash_equals($approval['token'], $token)) {
        cp_approval_json(403, array('ok' => false, 'error' => 'Invalid approval token.'));
    }
    if ($approval['token_expires_at'] !== '' && strtotime($approval['token_expires_at']) < time()) {
        cp_approval_json(403, array('ok' => false, 'error' => 'Approval token has expired.'));
    }
    return array($version, $approval);
}

function cp_approval_find_lep_section(ControlledPublishingSectionService $sections, int $versionId): ?array
{
    foreach ($sections->listFlatSections($versionId) as $row) {
        if ((string)$row['section_key'] === 'lep') {
            return $row;
        }
    }
    return null;
}

function cp_approval_render_lep_html(
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingBlockService $blocks,
    array $version,
    array $section
): string {
    $bookStyles = $styleSvc->resolveFromVersion($version);
    $renderer->setBookStyles($bookStyles, $styleSvc);
    $computed = $numberSvc->computeForVersion((int)$version['id'], (string)($version['manual_code'] ?? ''));
    $renderer->setSectionNumbers($computed['display'], $computed['suggested_regulatory_refs'], $numberSvc);
    $pageHeaderConfig = $pageHeaderSvc->resolveFromVersion($version);
    $sectionBlocks = $blocks->listSectionBlocks((int)$section['id']);
    $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_READ);
    $lepPage = $lepPageSvc->resolveFromVersion($version);
    $approval = $approvalSvc->resolveApproval((int)$version['id']);
    return $renderer->renderLepPageShell(
        $version,
        $section,
        ControlledPublishingBookRenderer::MODE_READ,
        $pageHeaderConfig,
        $lepPage,
        $approval
    );
}

function cp_approval_handle_load(
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc
): void {
    $versionId = (int)($_GET['version_id'] ?? 0);
    $token = trim((string)($_GET['token'] ?? ''));
    [$version, $approval] = cp_approval_require_token_context($approvalSvc, $versionId, $token);

    $section = cp_approval_find_lep_section($sections, $versionId);
    if ($section === null) {
        cp_approval_json(404, array('ok' => false, 'error' => 'LEP section not found'));
    }

    $pageHtml = cp_approval_render_lep_html(
        $renderer,
        $styleSvc,
        $numberSvc,
        $pageHeaderSvc,
        $coverPageSvc,
        $lepPageSvc,
        $approvalSvc,
        $blocks,
        $version,
        $section
    );

    $lepPage = $lepPageSvc->resolveFromVersion($version);
    $authoritySigned = $approval['authority_signed_at'] !== '';
    foreach ($lepPage['signatories'] as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        if ((string)($slot['signer_type'] ?? '') === 'authority' && trim((string)($slot['signature_url'] ?? '')) !== '') {
            $authoritySigned = true;
            break;
        }
    }

    cp_approval_json(200, array(
        'ok' => true,
        'version' => array(
            'id' => (int)$version['id'],
            'book_key' => (string)$version['book_key'],
            'book_title' => (string)($version['book_title'] ?? ''),
            'version_label' => (string)$version['version_label'],
            'lifecycle_status' => (string)$version['lifecycle_status'],
        ),
        'lep_page' => $lepPage,
        'page_html' => $pageHtml,
        'authority_signed' => $authoritySigned,
        'approval' => $approval,
    ));
}

function cp_approval_handle_submit(
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc
): void {
    $in = cp_approval_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $token = trim((string)($in['token'] ?? ''));
    [$version, $approval] = cp_approval_require_token_context($approvalSvc, $versionId, $token);

    if ($approval['authority_signed_at'] !== '') {
        cp_approval_json(400, array('ok' => false, 'error' => 'Authority signature already recorded.'));
    }

    $name = trim((string)($in['name'] ?? ''));
    $title = trim((string)($in['title'] ?? 'Competent Authority'));
    if ($name === '') {
        cp_approval_json(400, array('ok' => false, 'error' => 'Name is required'));
    }

    $upload = cp_approval_require_signature_upload();
    $url = cp_approval_upload_asset($version, 'lep_authority_signature_', $upload);

    $result = $approvalSvc->applyAuthoritySignature($versionId, $token, $name, $title, $url, null);

    $section = cp_approval_find_lep_section($sections, $versionId);
    $pageHtml = '';
    if ($section !== null) {
        $version = $approvalSvc->getVersionByApprovalToken($token) ?? $version;
        $pageHtml = cp_approval_render_lep_html(
            $renderer,
            $styleSvc,
            $numberSvc,
            $pageHeaderSvc,
            $coverPageSvc,
            $lepPageSvc,
            $approvalSvc,
            $blocks,
            $version,
            $section
        );
    }

    cp_approval_json(200, array(
        'ok' => true,
        'authority' => $result['authority'],
        'lep_page' => $result['lep_page'],
        'page_html' => $pageHtml,
    ));
}
