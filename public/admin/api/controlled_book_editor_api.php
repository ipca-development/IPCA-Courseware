<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingRevisionService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionLayoutService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingTocService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingPageHeaderService.php';

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
$revision = new ControlledPublishingRevisionService($pdo);
$layoutSvc = new ControlledPublishingSectionLayoutService($pdo);
$styleSvc = new ControlledPublishingBookStyleService($pdo);
$tocSvc = new ControlledPublishingTocService($pdo, $blocks);
$numberSvc = new ControlledPublishingSectionNumberService($pdo, $blocks);
$pageHeaderSvc = new ControlledPublishingPageHeaderService($pdo);
$renderer->setPageHeaderService($pageHeaderSvc);

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
            cp_editor_handle_load($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc);
            break;
        case 'recompute_section_numbers':
            cp_editor_handle_recompute_section_numbers($foundation, $blocks, $renderer, $styleSvc, $numberSvc, $sections, $revision, $layoutSvc, $pageHeaderSvc);
            break;
        case 'get_book_styles':
            cp_editor_handle_get_book_styles($foundation, $styleSvc);
            break;
        case 'save_book_styles':
            cp_editor_handle_save_book_styles($foundation, $styleSvc, $uid);
            break;
        case 'regenerate_toc':
            cp_editor_handle_regenerate_toc($tocSvc, $uid);
            break;
        case 'save_section_layout':
            cp_editor_handle_save_section_layout($sections, $layoutSvc, $uid);
            break;
        case 'get_page_header':
            cp_editor_handle_get_page_header($foundation, $pageHeaderSvc, $sections);
            break;
        case 'save_page_header':
            cp_editor_handle_save_page_header($foundation, $pageHeaderSvc, $uid);
            break;
        case 'upload_header_logo':
            cp_editor_handle_upload_header_logo($foundation, $pageHeaderSvc, $uid);
            break;
        case 'save_callout_presets':
            cp_editor_handle_save_callout_presets($foundation, $pdo, $uid);
            break;
        case 'regenerate_highlights':
            cp_editor_handle_regenerate_highlights($revision, $uid);
            break;
        case 'get_callout_presets':
            cp_editor_handle_get_callout_presets($foundation);
            break;
        case 'create_block':
            cp_editor_handle_create_block($foundation, $blocks, $renderer, $styleSvc, $numberSvc, $uid);
            break;
        case 'update_block':
            cp_editor_handle_update_block($blocks, $renderer, $styleSvc, $foundation, $numberSvc, $uid);
            break;
        case 'delete_block':
            cp_editor_handle_delete_block($blocks, $uid);
            break;
        case 'move_block':
            cp_editor_handle_move_block($foundation, $blocks, $renderer, $styleSvc, $numberSvc, $uid);
            break;
        case 'create_subsection':
            cp_editor_handle_create_subsection($sections, $uid);
            break;
        case 'upload_image':
            cp_editor_handle_upload_image($foundation, $blocks, $renderer, $styleSvc, $numberSvc, $uid);
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

function cp_editor_configure_renderer(
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    array $version,
    ?ControlledPublishingSectionNumberService $numberSvc = null
): array {
    $bookStyles = $styleSvc->resolveFromVersion($version);
    $renderer->setBookStyles($bookStyles, $styleSvc);
    $numbering = array(
        'section_numbers' => array(),
        'section_number_display' => array(),
        'suggested_regulatory_refs' => array(),
        'manual_code' => (string)($version['manual_code'] ?? ''),
    );
    if ($numberSvc !== null) {
        $computed = $numberSvc->computeForVersion(
            (int)$version['id'],
            (string)($version['manual_code'] ?? '')
        );
        $renderer->setSectionNumbers(
            $computed['display'],
            $computed['suggested_regulatory_refs'],
            $numberSvc
        );
        $numbering['section_numbers'] = $computed['numbers'];
        $numbering['section_number_display'] = $computed['display'];
        $numbering['suggested_regulatory_refs'] = $computed['suggested_regulatory_refs'];
    }
    return $numbering;
}

function cp_editor_page_header_config(
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    array $version,
    array $section
): array {
    $meta = cp_editor_decode_version_meta($version);
    $legacyLayout = null;
    if (!is_array($meta['page_header'] ?? null)) {
        $legacyLayout = cp_editor_legacy_section_layout($section);
    }
    return $pageHeaderSvc->resolveFromVersion($version, $legacyLayout);
}

/**
 * @param array<string,mixed> $section
 * @return array<string,mixed>|null
 */
function cp_editor_legacy_section_layout(array $section): ?array
{
    $meta = cp_editor_decode_version_meta($section);
    $layout = is_array($meta['page_layout'] ?? null) ? $meta['page_layout'] : null;
    if ($layout === null) {
        return null;
    }
    if (isset($layout['header_left']) || isset($layout['header_center']) || isset($layout['show_running_header_footer'])) {
        return $layout;
    }
    return null;
}

function cp_editor_handle_load(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc
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

    $bookStyles = $styleSvc->resolveFromVersion($version);
    $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
    $bookStyles['page_header'] = $pageHeaderConfig['page_header'];
    $bookStyles['page_footer'] = $pageHeaderConfig['page_footer'];
    $numbering = cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
    $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
    $pageLayout = $layoutSvc->resolveLayout($section);
    $editable = (string)$version['lifecycle_status'] !== 'released' && !empty($section['allow_author_blocks']);
    $mode = $editable ? ControlledPublishingBookRenderer::MODE_EDIT : ControlledPublishingBookRenderer::MODE_READ;
    $blocksHtml = $renderer->renderBlocks($sectionBlocks, $mode);
    $pageHtml = $renderer->renderPageShell($version, $section, $blocksHtml, $mode, $pageLayout, $pageHeaderConfig);
    $prior = $revision->priorVersion($versionId);

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
        'page_layout' => $pageLayout,
        'editable' => $editable,
        'prior_version_label' => $prior ? (string)($prior['version_label'] ?? '') : null,
        'book_styles' => $bookStyles,
        'page_header' => $pageHeaderConfig['page_header'],
        'page_footer' => $pageHeaderConfig['page_footer'],
        'header_tokens' => $pageHeaderSvc->tokenCatalogForApi(),
        'section_numbers' => $numbering['section_numbers'],
        'section_number_display' => $numbering['section_number_display'],
        'suggested_regulatory_refs' => $numbering['suggested_regulatory_refs'],
        'manual_code' => $numbering['manual_code'],
    ));
}

function cp_editor_handle_recompute_section_numbers(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingSectionService $sections,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    $numbering = $numberSvc->computeForVersion($versionId, (string)($version['manual_code'] ?? ''));
    $payload = array(
        'ok' => true,
        'section_numbers' => $numbering['numbers'],
        'section_number_display' => $numbering['display'],
        'suggested_regulatory_refs' => $numbering['suggested_regulatory_refs'],
        'manual_code' => (string)($version['manual_code'] ?? ''),
    );
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            if (!empty($section['parent_section_id'])) {
                $section['allow_author_blocks'] = 1;
            }
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $editable = (string)$version['lifecycle_status'] !== 'released' && !empty($section['allow_author_blocks']);
            $mode = $editable ? ControlledPublishingBookRenderer::MODE_EDIT : ControlledPublishingBookRenderer::MODE_READ;
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, $mode);
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $payload['page_html'] = $renderer->renderPageShell($version, $section, $blocksHtml, $mode, $pageLayout, $pageHeaderConfig);
        }
    }
    cp_editor_json(200, $payload);
}

function cp_editor_handle_get_book_styles(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingBookStyleService $styleSvc
): void {
    $versionId = (int)($_GET['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    cp_editor_json(200, array(
        'ok' => true,
        'book_styles' => $styleSvc->resolveFromVersion($version),
    ));
}

function cp_editor_handle_save_book_styles(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingBookStyleService $styleSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $styles = is_array($in['book_styles'] ?? null) ? $in['book_styles'] : array();
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    $saved = $styleSvc->saveForVersion($versionId, $styles, $uid);
    cp_editor_json(200, array('ok' => true, 'book_styles' => $saved));
}

function cp_editor_handle_regenerate_toc(ControlledPublishingTocService $tocSvc, int $uid): void
{
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $result = $tocSvc->regenerateTocSection($versionId, $uid);
    cp_editor_json(200, array('ok' => true, 'result' => $result));
}

function cp_editor_handle_save_section_layout(
    ControlledPublishingSectionService $sections,
    ControlledPublishingSectionLayoutService $layoutSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    $layout = is_array($in['layout'] ?? null) ? $in['layout'] : array();
    if ($versionId <= 0 || $sectionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id and section_id required'));
    }
    $layoutSvc->saveLayout($versionId, $sectionId, $layout, $uid);
    $section = $sections->getSection($versionId, $sectionId);
    if ($section === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Section not found'));
    }
    cp_editor_json(200, array('ok' => true, 'layout' => $layoutSvc->resolveLayout($section)));
}

function cp_editor_handle_get_page_header(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingSectionService $sections
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
    $legacyLayout = null;
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            $legacyLayout = cp_editor_legacy_section_layout($section);
        }
    }
    $config = $pageHeaderSvc->resolveFromVersion($version, $legacyLayout);
    cp_editor_json(200, array(
        'ok' => true,
        'page_header' => $config['page_header'],
        'page_footer' => $config['page_footer'],
        'header_tokens' => $pageHeaderSvc->tokenCatalogForApi(),
    ));
}

function cp_editor_handle_save_page_header(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(400, array('ok' => false, 'error' => 'Released versions cannot change page header.'));
    }
    $payload = array();
    if (is_array($in['page_header'] ?? null)) {
        $payload['page_header'] = $in['page_header'];
    }
    if (is_array($in['page_footer'] ?? null)) {
        $payload['page_footer'] = $in['page_footer'];
    }
    $saved = $pageHeaderSvc->saveForVersion($versionId, $payload, $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'page_header' => $saved['page_header'],
        'page_footer' => $saved['page_footer'],
    ));
}

function cp_editor_handle_upload_header_logo(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    int $uid
): void {
    $versionId = (int)($_POST['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
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
    $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/header_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $put = cw_spaces_put_object($objectKey, $bytes, $mime);
    $url = (string)($put['cdn_url'] ?? '');
    if ($url === '') {
        cp_editor_json(500, array('ok' => false, 'error' => 'Upload succeeded but CDN URL missing'));
    }

    $alt = trim((string)($_POST['alt'] ?? 'EuroPilot Center'));
    $existing = $pageHeaderSvc->resolveFromVersion($version);
    $header = $existing['page_header'];
    $header['logo_url'] = $url;
    if ($alt !== '') {
        $header['logo_alt'] = $alt;
    }
    $saved = $pageHeaderSvc->saveForVersion($versionId, array(
        'page_header' => $header,
        'page_footer' => $existing['page_footer'],
    ), $uid);

    cp_editor_json(200, array(
        'ok' => true,
        'url' => $url,
        'page_header' => $saved['page_header'],
        'page_footer' => $saved['page_footer'],
    ));
}

function cp_editor_handle_save_callout_presets(
    ControlledPublishingFoundationService $foundation,
    PDO $pdo,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $presets = is_array($in['presets'] ?? null) ? $in['presets'] : array();
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }

    $normalized = array();
    foreach ($presets as $preset) {
        if (!is_array($preset)) {
            continue;
        }
        $type = strtolower(trim((string)($preset['callout_type'] ?? '')));
        if (!in_array($type, array('warning', 'caution', 'info'), true)) {
            continue;
        }
        $normalized[] = array(
            'callout_type' => $type,
            'title' => trim((string)($preset['title'] ?? strtoupper($type))),
            'text' => trim((string)($preset['text'] ?? '')),
        );
    }
    if ($normalized === array()) {
        $normalized = cp_editor_default_callout_presets();
    }

    $meta = cp_editor_decode_version_meta($version);
    $meta['callout_presets'] = $normalized;
    $stmt = $pdo->prepare("
        UPDATE ipca_publishing_book_versions
        SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute(array(
        ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ':id' => $versionId,
    ));

    cp_editor_json(200, array('ok' => true, 'presets' => $normalized));
}

function cp_editor_handle_regenerate_highlights(ControlledPublishingRevisionService $revision, int $uid): void
{
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $result = $revision->regenerateHighlightsSection($versionId, $uid);
    cp_editor_json(200, array('ok' => true, 'result' => $result));
}

function cp_editor_handle_get_callout_presets(ControlledPublishingFoundationService $foundation): void
{
    $versionId = (int)($_GET['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    $meta = cp_editor_decode_version_meta($version);
    $presets = is_array($meta['callout_presets'] ?? null) ? $meta['callout_presets'] : cp_editor_default_callout_presets();
    cp_editor_json(200, array('ok' => true, 'presets' => $presets));
}

/**
 * @return list<array<string,string>>
 */
function cp_editor_default_callout_presets(): array
{
    return array(
        array('callout_type' => 'warning', 'title' => 'WARNING', 'text' => ''),
        array('callout_type' => 'caution', 'title' => 'CAUTION', 'text' => ''),
        array('callout_type' => 'info', 'title' => 'INFO', 'text' => ''),
    );
}

/**
 * @param array<string,mixed> $version
 * @return array<string,mixed>
 */
function cp_editor_decode_version_meta(array $version): array
{
    $raw = $version['metadata_json'] ?? '{}';
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : array();
}

function cp_editor_handle_create_block(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
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

    $version = $foundation->getVersion($versionId);
    $numbering = array();
    if ($version !== null) {
        $numbering = cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
    }

    $insertAfterBlockId = (int)($in['insert_after_block_id'] ?? 0);
    $blockId = $blocks->createBlock(
        $versionId,
        $sectionId,
        $blockType,
        $payload,
        $uid,
        $insertAfterBlockId > 0 ? $insertAfterBlockId : null
    );
    $block = $blocks->getBlock($blockId);
    if ($block === null) {
        cp_editor_json(500, array('ok' => false, 'error' => 'Block create failed'));
    }

    cp_editor_json(200, array_merge(array(
        'ok' => true,
        'block' => $block,
        'block_html' => $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT),
    ), cp_editor_numbering_payload($numbering)));

}

function cp_editor_handle_update_block(
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionNumberService $numberSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $payload = is_array($in['payload'] ?? null) ? $in['payload'] : array();
    if ($blockId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'block_id required'));
    }

    $numbering = array();
    $row = $blocks->getBlock($blockId);
    if ($row !== null) {
        $version = $foundation->getVersion((int)($row['book_version_id'] ?? 0));
        if ($version !== null) {
            $numbering = cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
        }
    }

    $blocks->updateBlock($blockId, $payload, $uid);
    $block = $blocks->getBlock($blockId);
    if ($block === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Block not found'));
    }

    cp_editor_json(200, array_merge(array(
        'ok' => true,
        'block' => $block,
        'block_html' => $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT),
    ), cp_editor_numbering_payload($numbering)));
}

/**
 * @param array<string,mixed> $numbering
 * @return array<string,mixed>
 */
function cp_editor_numbering_payload(array $numbering): array
{
    if ($numbering === array()) {
        return array();
    }
    return array(
        'section_numbers' => $numbering['section_numbers'] ?? array(),
        'section_number_display' => $numbering['section_number_display'] ?? array(),
        'suggested_regulatory_refs' => $numbering['suggested_regulatory_refs'] ?? array(),
        'manual_code' => $numbering['manual_code'] ?? '',
    );
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
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $direction = (string)($in['direction'] ?? '');
    $sectionId = (int)($in['section_id'] ?? 0);
    if ($blockId <= 0 || !in_array($direction, array('up', 'down'), true)) {
        cp_editor_json(400, array('ok' => false, 'error' => 'block_id and direction required'));
    }

    $numbering = array();
    $row = $blocks->getBlock($blockId);
    if ($row !== null) {
        $version = $foundation->getVersion((int)($row['book_version_id'] ?? 0));
        if ($version !== null) {
            $numbering = cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
        }
    }

    $blocks->moveBlock($blockId, $direction, $uid);
    if ($sectionId <= 0) {
        $row = $blocks->getBlock($blockId);
        $sectionId = (int)($row['section_id'] ?? 0);
    }
    $sectionBlocks = $blocks->listSectionBlocks($sectionId);
    $html = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);

    cp_editor_json(200, array_merge(array(
        'ok' => true,
        'page_body_html' => $html,
    ), cp_editor_numbering_payload($numbering)));
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
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
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
    $numbering = cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);

    cp_editor_json(200, array_merge(array(
        'ok' => true,
        'url' => $url,
        'block' => $block,
        'block_html' => $block ? $renderer->renderBlock($block, ControlledPublishingBookRenderer::MODE_EDIT) : '',
    ), cp_editor_numbering_payload($numbering)));
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
