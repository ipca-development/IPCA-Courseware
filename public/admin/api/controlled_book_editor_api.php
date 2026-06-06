<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookRenderer.php';

header('Content-Type: application/json; charset=utf-8');

function cp_editor_json(int $code, array $payload): void
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
$renderer = new ControlledPublishingBookRenderer();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json)) {
        $action = (string)($json['action'] ?? '');
        $_POST = array_merge($_POST, $json);
    }
}

try {
    switch ($action) {
        case 'load':
            cp_editor_handle_load($foundation, $sections, $blocks, $renderer);
            break;
        case 'create_block':
            cp_editor_handle_create_block($blocks, $renderer, $uid);
            break;
        case 'update_block':
            cp_editor_handle_update_block($blocks, $renderer, $uid);
            break;
        case 'delete_block':
            cp_editor_handle_delete_block($blocks, $uid);
            break;
        case 'move_block':
            cp_editor_handle_move_block($blocks, $renderer, $uid);
            break;
        case 'create_subsection':
            cp_editor_handle_create_subsection($sections, $uid);
            break;
        case 'upload_image':
            cp_editor_handle_upload_image($foundation, $blocks, $renderer, $uid);
            break;
        default:
            cp_editor_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    cp_editor_json(400, array('ok' => false, 'error' => $e->getMessage()));
}

/**
 * @return array<string,mixed>
 */
function cp_editor_input(): array
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

function cp_editor_handle_load(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer
): void {
    $versionId = (int)($_GET['version_id'] ?? 0);
    $sectionId = (int)($_GET['section_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }

    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }

    $tree = $sections->listSectionTree($versionId);
    if ($sectionId <= 0) {
        $sectionId = cp_editor_default_section_id($sections, $versionId);
    }

    $section = $sections->getSection($versionId, $sectionId);
    if ($section === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Section not found'));
    }

    if (!empty($section['parent_section_id'])) {
        $section['allow_author_blocks'] = 1;
    }

    $sectionBlocks = $blocks->listSectionBlocks($sectionId);
    $editable = (string)$version['lifecycle_status'] !== 'released' && !empty($section['allow_author_blocks']);
    $mode = $editable ? ControlledPublishingBookRenderer::MODE_EDIT : ControlledPublishingBookRenderer::MODE_READ;
    $blocksHtml = $renderer->renderBlocks($sectionBlocks, $mode);
    $pageHtml = $renderer->renderPageShell($version, $section, $blocksHtml, $mode);

    cp_editor_json(200, array(
        'ok' => true,
        'version' => array(
            'id' => (int)$version['id'],
            'book_key' => (string)$version['book_key'],
            'book_title' => (string)($version['book_title'] ?? ''),
            'version_label' => (string)$version['version_label'],
            'lifecycle_status' => (string)$version['lifecycle_status'],
        ),
        'section_id' => $sectionId,
        'section' => $section,
        'sections_tree' => $tree,
        'blocks' => $sectionBlocks,
        'page_html' => $pageHtml,
        'editable' => $editable,
    ));
}

function cp_editor_handle_create_block(
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    $blockType = (string)($in['block_type'] ?? 'paragraph');
    $payload = is_array($in['payload'] ?? null) ? $in['payload'] : array();

    if ($versionId <= 0 || $sectionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id and section_id required'));
    }

    $blockId = $blocks->createBlock($versionId, $sectionId, $blockType, $payload, $uid);
    $block = $blocks->getBlock($blockId);
    if ($block === null) {
        cp_editor_json(500, array('ok' => false, 'error' => 'Block create failed'));
    }

    cp_editor_json(200, array(
        'ok' => true,
        'block' => $block,
        'block_html' => $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT),
    ));
}

function cp_editor_handle_update_block(
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    int $uid
): void {
    $in = cp_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $payload = is_array($in['payload'] ?? null) ? $in['payload'] : array();
    if ($blockId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'block_id required'));
    }

    $blocks->updateBlock($blockId, $payload, $uid);
    $block = $blocks->getBlock($blockId);
    if ($block === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Block not found'));
    }

    cp_editor_json(200, array(
        'ok' => true,
        'block' => $block,
        'block_html' => $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT),
    ));
}

function cp_editor_handle_delete_block(ControlledPublishingBlockService $blocks, int $uid): void
{
    $in = cp_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    if ($blockId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'block_id required'));
    }
    $blocks->deleteBlock($blockId, $uid);
    cp_editor_json(200, array('ok' => true));
}

function cp_editor_handle_move_block(
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    int $uid
): void {
    $in = cp_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $direction = (string)($in['direction'] ?? '');
    $sectionId = (int)($in['section_id'] ?? 0);
    if ($blockId <= 0 || !in_array($direction, array('up', 'down'), true)) {
        cp_editor_json(400, array('ok' => false, 'error' => 'block_id and direction required'));
    }

    $blocks->moveBlock($blockId, $direction, $uid);
    if ($sectionId <= 0) {
        $row = $blocks->getBlock($blockId);
        $sectionId = (int)($row['section_id'] ?? 0);
    }
    $sectionBlocks = $blocks->listSectionBlocks($sectionId);
    $html = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);

    cp_editor_json(200, array('ok' => true, 'page_body_html' => $html));
}

function cp_editor_handle_create_subsection(ControlledPublishingSectionService $sections, int $uid): void
{
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $parentSectionId = (int)($in['parent_section_id'] ?? 0);
    $title = (string)($in['title'] ?? '');
    if ($versionId <= 0 || $parentSectionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id and parent_section_id required'));
    }

    $sectionId = $sections->createSubsection($versionId, $parentSectionId, $title, $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'section_id' => $sectionId,
        'sections_tree' => $sections->listSectionTree($versionId),
    ));
}

function cp_editor_handle_upload_image(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    int $uid
): void {
    $versionId = (int)($_POST['version_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0);
    if ($versionId <= 0 || $sectionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id and section_id required'));
    }

    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        cp_editor_json(400, array('ok' => false, 'error' => 'image file required'));
    }
    $file = $_FILES['image'];
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        cp_editor_json(400, array('ok' => false, 'error' => 'Upload failed (code ' . $err . ')'));
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        cp_editor_json(400, array('ok' => false, 'error' => 'Invalid upload'));
    }

    $mime = (string)($file['type'] ?? '');
    $ext = match ($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        cp_editor_json(400, array('ok' => false, 'error' => 'Only JPG, PNG, or WEBP images are allowed'));
    }

    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }

    require_once __DIR__ . '/../../../src/spaces.php';
    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        cp_editor_json(500, array('ok' => false, 'error' => 'Could not read upload'));
    }

    $bookKey = strtolower((string)$version['book_key']);
    $versionLabel = str_replace('.', '_', (string)$version['version_label']);
    $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    $put = cw_spaces_put_object($objectKey, $bytes, $mime);
    $url = (string)($put['cdn_url'] ?? '');
    if ($url === '') {
        cp_editor_json(500, array('ok' => false, 'error' => 'Upload succeeded but CDN URL missing'));
    }

    $alt = trim((string)($_POST['alt'] ?? ''));
    $blockId = $blocks->createBlock($versionId, $sectionId, 'image', array(
        'url' => $url,
        'alt' => $alt,
        'width_pct' => 100,
    ), $uid);
    $block = $blocks->getBlock($blockId);

    cp_editor_json(200, array(
        'ok' => true,
        'url' => $url,
        'block' => $block,
        'block_html' => $block ? $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT) : '',
    ));
}

function cp_editor_default_section_id(ControlledPublishingSectionService $sections, int $versionId): int
{
    foreach ($sections->listFlatSections($versionId) as $row) {
        if ((string)($row['section_key'] ?? '') === 'main_content') {
            return (int)$row['id'];
        }
    }
    $flat = $sections->listFlatSections($versionId);
    return $flat !== array() ? (int)$flat[0]['id'] : 0;
}
