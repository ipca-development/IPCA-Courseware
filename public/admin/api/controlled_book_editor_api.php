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
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingCoverPageService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingLepService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingApprovalService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingEditorNavService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingManualStructureService.php';

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
$coverPageSvc = new ControlledPublishingCoverPageService($pdo);
$lepPageSvc = new ControlledPublishingLepService($pdo);
$approvalSvc = new ControlledPublishingApprovalService($pdo, $lepPageSvc);
$part0PageSvc = new ControlledPublishingPart0PageService($pdo, $blocks);
$editorNavSvc = new ControlledPublishingEditorNavService($sections);
$manualStructureSvc = new ControlledPublishingManualStructureService($pdo, $foundation, $sections);
$renderer->setPageHeaderService($pageHeaderSvc);
$renderer->setCoverPageService($coverPageSvc);
$renderer->setLepPageService($lepPageSvc);

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
            cp_editor_handle_load($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $tocSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $editorNavSvc, $manualStructureSvc, $uid);
            break;
        case 'recompute_section_numbers':
            cp_editor_handle_recompute_section_numbers($foundation, $blocks, $renderer, $styleSvc, $numberSvc, $sections, $revision, $layoutSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc);
            break;
        case 'get_book_styles':
            cp_editor_handle_get_book_styles($foundation, $styleSvc);
            break;
        case 'save_book_styles':
            cp_editor_handle_save_book_styles($foundation, $styleSvc, $uid);
            break;
        case 'regenerate_toc':
            cp_editor_handle_regenerate_toc($foundation, $tocSvc, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $uid);
            break;
        case 'save_toc_settings':
            cp_editor_handle_save_toc_settings($foundation, $tocSvc, $uid);
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
        case 'save_cover_page':
            cp_editor_handle_save_cover_page($foundation, $coverPageSvc, $uid);
            break;
        case 'upload_cover_logo':
            cp_editor_handle_upload_cover_asset($foundation, $coverPageSvc, $uid, 'logo');
            break;
        case 'upload_cover_image':
            cp_editor_handle_upload_cover_asset($foundation, $coverPageSvc, $uid, 'cover_image');
            break;
        case 'save_lep_page':
            cp_editor_handle_save_lep_page($foundation, $lepPageSvc, $uid);
            break;
        case 'regenerate_lep_parts':
            cp_editor_handle_regenerate_lep_parts($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $uid);
            break;
        case 'sync_manual_structure':
            cp_editor_handle_sync_manual_structure($foundation, $manualStructureSvc, $uid);
            break;
        case 'sign_lep_slot':
            cp_editor_handle_sign_lep_slot($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $uid);
            break;
        case 'ensure_lep_approval':
            cp_editor_handle_ensure_lep_approval($foundation, $approvalSvc, $uid);
            break;
        case 'save_callout_presets':
            cp_editor_handle_save_callout_presets($foundation, $pdo, $uid);
            break;
        case 'regenerate_highlights':
            cp_editor_handle_regenerate_highlights($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $uid);
            break;
        case 'save_part0_page':
            cp_editor_handle_save_part0_page($foundation, $part0PageSvc, $uid);
            break;
        case 'regenerate_abbreviations':
            cp_editor_handle_regenerate_abbreviations($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $uid);
            break;
        case 'regenerate_definitions':
            cp_editor_handle_regenerate_definitions($foundation, $sections, $blocks, $renderer, $revision, $layoutSvc, $styleSvc, $numberSvc, $pageHeaderSvc, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $uid);
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

function cp_editor_is_toc_section(array $section): bool
{
    return (string)($section['section_key'] ?? '') === 'toc';
}

function cp_editor_is_cover_section(array $section): bool
{
    return (string)($section['section_key'] ?? '') === 'cover';
}

function cp_editor_is_lep_section(array $section): bool
{
    return (string)($section['section_key'] ?? '') === 'lep';
}

function cp_editor_is_part0_shell_section(array $section): bool
{
    $key = (string)($section['section_key'] ?? '');
    return in_array($key, array(
        'revision_system',
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
        'highlights',
    ), true);
}

function cp_editor_is_part0_structured_section(array $section): bool
{
    $key = (string)($section['section_key'] ?? '');
    return in_array($key, array(
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
    ), true);
}

function cp_editor_is_section_editable(array $version, array $section): bool
{
    if ((string)$version['lifecycle_status'] === 'released') {
        return false;
    }
    if (!empty($section['allow_author_blocks'])) {
        return true;
    }
    if (cp_editor_is_part0_structured_section($section)) {
        return true;
    }
    return cp_editor_is_cover_section($section) || cp_editor_is_lep_section($section);
}

/**
 * @param list<array<string,mixed>> $sectionBlocks
 */
function cp_editor_build_part0_body_html(
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingPart0PageService $part0Svc,
    array $version,
    array $section,
    string $blocksHtml,
    array $sectionBlocks,
    string $mode,
    bool $editable
): string {
    $key = (string)($section['section_key'] ?? '');
    $editMode = $mode === ControlledPublishingBookRenderer::MODE_EDIT;

    if ($key === 'amendment_list') {
        return $renderer->renderAmendmentListContent(
            $part0Svc->resolveAmendmentListFromVersion($version),
            $editMode
        );
    }
    if ($key === 'distribution_list') {
        return $renderer->renderDistributionListContent(
            $part0Svc->resolveDistributionListFromVersion($version),
            $editMode
        );
    }
    if ($key === 'abbreviations') {
        return $renderer->renderAbbreviationsIndexContent(
            $part0Svc->resolveAbbreviationsPageFromVersion($version),
            $editMode
        );
    }
    if ($key === 'definitions') {
        return $renderer->renderDefinitionsListContent(
            $part0Svc->resolveDefinitionsFromVersion($version),
            $editMode
        );
    }

    $drop = ($editable && !in_array($key, array('revision_system', 'highlights'), true))
        ? '<div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div>'
        : '';

    if ($key === 'highlights') {
        $manual = array();
        $system = array();
        foreach ($sectionBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if ((string)($block['block_type'] ?? '') === 'generated_placeholder') {
                continue;
            }
            if (!empty($block['is_system_managed'])) {
                $system[] = $block;
            } else {
                $manual[] = $block;
            }
        }
        $manualHtml = $renderer->renderBlocks($manual, $mode);
        $systemHtml = $renderer->renderBlocks($system, $mode);
        $body = '<div class="cpb-part0-highlights-manual">' . $drop . $manualHtml . '</div>';
        if ($systemHtml !== '') {
            $body .= '<div class="cpb-part0-highlights-generated" contenteditable="false">' . $systemHtml . '</div>';
        }
        return $body;
    }

    return '<div class="cpb-part0-blocks">' . $drop . $blocksHtml . '</div>';
}

/**
 * @param list<array<string,mixed>> $sectionBlocks
 */
function cp_editor_render_page_html(
    ControlledPublishingBookRenderer $renderer,
    array $version,
    array $section,
    string $blocksHtml,
    string $mode,
    array $pageLayout,
    array $pageHeaderConfig,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    array $sectionBlocks = array()
): string {
    if (cp_editor_is_cover_section($section)) {
        $coverPage = $coverPageSvc->resolveFromVersion($version);
        return $renderer->renderCoverPageShell($version, $section, $mode, $pageHeaderConfig, $coverPage);
    }
    if (cp_editor_is_lep_section($section)) {
        $lepPage = $lepPageSvc->resolveFromVersion($version);
        $approval = $approvalSvc->resolveApproval((int)$version['id']);
        return $renderer->renderLepPageShell($version, $section, $mode, $pageHeaderConfig, $lepPage, $approval);
    }
    if (cp_editor_is_part0_shell_section($section)) {
        $sectionKey = (string)($section['section_key'] ?? '');
        $headings = $part0PageSvc->resolveHeadingsForSection($sectionKey, $version);
        $editable = cp_editor_is_section_editable($version, $section);
        $bodyHtml = cp_editor_build_part0_body_html(
            $renderer,
            $part0PageSvc,
            $version,
            $section,
            $blocksHtml,
            $sectionBlocks,
            $mode,
            $editable
        );
        return $renderer->renderPart0AdminPageShell(
            $version,
            $section,
            $headings,
            $bodyHtml,
            $mode,
            $pageHeaderConfig
        );
    }
    return $renderer->renderPageShell($version, $section, $blocksHtml, $mode, $pageLayout, $pageHeaderConfig);
}

/**
 * @param array<string,mixed> $version
 * @param array<string,mixed> $section
 * @return array<string,mixed>|null
 */
function cp_editor_part0_page_payload(
    ControlledPublishingPart0PageService $part0Svc,
    array $version,
    array $section
): ?array {
    $key = (string)($section['section_key'] ?? '');
    return match ($key) {
        'amendment_list' => $part0Svc->resolveAmendmentListFromVersion($version),
        'distribution_list' => $part0Svc->resolveDistributionListFromVersion($version),
        'abbreviations' => $part0Svc->resolveAbbreviationsPageFromVersion($version),
        'definitions' => $part0Svc->resolveDefinitionsFromVersion($version),
        default => null,
    };
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
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingTocService $tocSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    ControlledPublishingEditorNavService $editorNavSvc,
    ControlledPublishingManualStructureService $manualStructureSvc,
    int $uid
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

    $structureSync = null;
    if ((string)($version['lifecycle_status'] ?? '') !== 'released') {
        $structureSync = $manualStructureSvc->ensureVersionStructure($versionId, $uid);
    }

    $tree = $editorNavSvc->buildNavTree($versionId, (string)($version['book_key'] ?? 'OM'));
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

    if ((string)($section['section_key'] ?? '') === 'highlights' && cp_editor_is_section_editable($version, $section)) {
        cp_editor_purge_highlights_placeholders($blocks, $sectionId);
    }

    if (cp_editor_is_lep_section($section) && cp_editor_is_section_editable($version, $section)) {
        $lep = $lepPageSvc->resolveFromVersion($version);
        if (($lep['effective_parts'] ?? array()) === array()) {
            $lepPageSvc->regenerateEffectiveParts($versionId, $uid);
            $version = $foundation->getVersion($versionId);
            if ($version === null) {
                cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
            }
        }
    }

    $lepApproval = null;
    $lepApprovalUrl = '';
    if (cp_editor_is_lep_section($section)) {
        $approvalResult = $approvalSvc->ensureApprovalToken($versionId, $uid);
        $lepApproval = $approvalResult['approval'];
        $lepApprovalUrl = $approvalResult['approval_url'];
    }

    $bookStyles = $styleSvc->resolveFromVersion($version);
    $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
    $bookStyles['page_header'] = $pageHeaderConfig['page_header'];
    $bookStyles['page_footer'] = $pageHeaderConfig['page_footer'];
    $numbering = cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
    $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
    $pageLayout = $layoutSvc->resolveLayout($section);
    $editable = cp_editor_is_section_editable($version, $section);
    $mode = $editable ? ControlledPublishingBookRenderer::MODE_EDIT : ControlledPublishingBookRenderer::MODE_READ;
    $blocksHtml = $renderer->renderBlocks($sectionBlocks, $mode);
    $pageHtml = cp_editor_render_page_html($renderer, $version, $section, $blocksHtml, $mode, $pageLayout, $pageHeaderConfig, $coverPageSvc, $lepPageSvc, $approvalSvc, $part0PageSvc, $sectionBlocks);
    $prior = $revision->priorVersion($versionId);
    $coverPage = $coverPageSvc->resolveFromVersion($version);
    $tocSettings = $tocSvc->resolveTocSettingsFromVersion($version);
    $lepPage = $lepPageSvc->resolveFromVersion($version);

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
        'is_cover_section' => cp_editor_is_cover_section($section),
        'is_toc_section' => cp_editor_is_toc_section($section),
        'is_lep_section' => cp_editor_is_lep_section($section),
        'is_part0_section' => cp_editor_is_part0_shell_section($section),
        'part0_section_key' => (string)($section['section_key'] ?? ''),
        'part0_structured' => cp_editor_is_part0_structured_section($section),
        'part0_page' => cp_editor_part0_page_payload($part0PageSvc, $version, $section),
        'cover_page' => $coverPage,
        'lep_page' => $lepPage,
        'lep_approval' => $lepApproval,
        'lep_approval_url' => $lepApprovalUrl,
        'toc_settings' => $tocSettings,
        'toc_settings_catalog' => $tocSvc->tocSettingsForApi($tocSettings),
        'prior_version_label' => $prior ? (string)($prior['version_label'] ?? '') : null,
        'book_styles' => $bookStyles,
        'page_header' => $pageHeaderConfig['page_header'],
        'page_footer' => $pageHeaderConfig['page_footer'],
        'header_tokens' => $pageHeaderSvc->tokenCatalogForApi(),
        'section_numbers' => $numbering['section_numbers'],
        'section_number_display' => $numbering['section_number_display'],
        'suggested_regulatory_refs' => $numbering['suggested_regulatory_refs'],
        'manual_code' => $numbering['manual_code'],
        'structure_sync' => $structureSync,
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
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc
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
            $editable = cp_editor_is_section_editable($version, $section);
            $mode = $editable ? ControlledPublishingBookRenderer::MODE_EDIT : ControlledPublishingBookRenderer::MODE_READ;
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, $mode);
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                $mode,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
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

function cp_editor_handle_regenerate_toc(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingTocService $tocSvc,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
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
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }
    if (is_array($in['toc_settings'] ?? null)) {
        $tocSvc->saveTocSettingsForVersion($versionId, $in['toc_settings'], $uid);
    }
    $result = $tocSvc->regenerateTocSection($versionId, $uid);
    $payload = array(
        'ok' => true,
        'result' => $result,
        'toc_settings' => $result['toc_settings'],
        'toc_settings_catalog' => $tocSvc->tocSettingsForApi($result['toc_settings']),
    );
    if ($sectionId <= 0) {
        $sectionId = (int)($result['section_id'] ?? 0);
    }
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                ControlledPublishingBookRenderer::MODE_EDIT,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
        }
    }
    cp_editor_json(200, $payload);
}

function cp_editor_handle_save_toc_settings(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingTocService $tocSvc,
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
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }
    $settings = is_array($in['toc_settings'] ?? null) ? $in['toc_settings'] : array();
    $saved = $tocSvc->saveTocSettingsForVersion($versionId, $settings, $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'toc_settings' => $saved,
        'toc_settings_catalog' => $tocSvc->tocSettingsForApi($saved),
    ));
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

function cp_editor_handle_save_cover_page(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingCoverPageService $coverPageSvc,
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
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $coverIn = is_array($in['cover_page'] ?? null) ? $in['cover_page'] : array();
    $payload = array();
    foreach (array('company_name', 'registration_number', 'manual_title', 'logo_alt', 'cover_image_alt') as $field) {
        if (array_key_exists($field, $coverIn)) {
            $payload[$field] = (string)$coverIn[$field];
        }
    }
    if (array_key_exists('logo_url', $coverIn)) {
        $payload['logo_url'] = (string)$coverIn['logo_url'];
    }
    if (array_key_exists('cover_image_url', $coverIn)) {
        $payload['cover_image_url'] = (string)$coverIn['cover_image_url'];
    }

    $saved = $coverPageSvc->saveForVersion($versionId, $payload, $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'cover_page' => $saved,
    ));
}

/**
 * @return array{bytes:string,mime:string,ext:string}
 */
function cp_editor_require_image_upload(): array
{
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

    require_once __DIR__ . '/../../../src/spaces.php';
    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        cp_editor_json(500, array('ok' => false, 'error' => 'Could not read upload'));
    }

    return array('bytes' => $bytes, 'mime' => $mime, 'ext' => $ext);
}

function cp_editor_handle_upload_cover_asset(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingCoverPageService $coverPageSvc,
    int $uid,
    string $assetType
): void {
    $versionId = (int)($_POST['version_id'] ?? 0);
    if ($versionId <= 0) {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id required'));
    }

    $upload = cp_editor_require_image_upload();
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $bookKey = strtolower((string)$version['book_key']);
    $versionLabel = str_replace('.', '_', (string)$version['version_label']);
    $prefix = $assetType === 'logo' ? 'cover_logo_' : 'cover_image_';
    $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/' . $prefix . bin2hex(random_bytes(12)) . '.' . $upload['ext'];
    $put = cw_spaces_put_object($objectKey, $upload['bytes'], $upload['mime']);
    $url = (string)($put['cdn_url'] ?? '');
    if ($url === '') {
        cp_editor_json(500, array('ok' => false, 'error' => 'Upload succeeded but CDN URL missing'));
    }

    $existing = $coverPageSvc->resolveFromVersion($version);
    $payload = array();
    if ($assetType === 'logo') {
        $payload['logo_url'] = $url;
        $alt = trim((string)($_POST['alt'] ?? ''));
        if ($alt !== '') {
            $payload['logo_alt'] = $alt;
        }
    } else {
        $payload['cover_image_url'] = $url;
        $alt = trim((string)($_POST['alt'] ?? ''));
        if ($alt !== '') {
            $payload['cover_image_alt'] = $alt;
        }
    }

    $saved = $coverPageSvc->saveForVersion($versionId, array_merge($existing, $payload), $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'url' => $url,
        'cover_page' => $saved,
    ));
}

function cp_editor_upload_publishing_asset(array $version, string $filenamePrefix, array $upload): string
{
    $bookKey = strtolower((string)$version['book_key']);
    $versionLabel = str_replace('.', '_', (string)$version['version_label']);
    $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/' . $filenamePrefix . bin2hex(random_bytes(12)) . '.' . $upload['ext'];
    $put = cw_spaces_put_object($objectKey, $upload['bytes'], $upload['mime']);
    $url = (string)($put['cdn_url'] ?? '');
    if ($url === '') {
        cp_editor_json(500, array('ok' => false, 'error' => 'Upload succeeded but CDN URL missing'));
    }
    return $url;
}

/**
 * @return array{bytes:string,mime:string,ext:string}
 */
function cp_editor_require_signature_upload(): array
{
    if (!empty($_FILES['signature']) && is_array($_FILES['signature'])) {
        $file = $_FILES['signature'];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            cp_editor_json(400, array('ok' => false, 'error' => 'Upload failed (code ' . $err . ')'));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            cp_editor_json(400, array('ok' => false, 'error' => 'Invalid upload'));
        }
        $mime = (string)($file['type'] ?? 'image/png');
        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'png',
        };
        require_once __DIR__ . '/../../../src/spaces.php';
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            cp_editor_json(500, array('ok' => false, 'error' => 'Could not read upload'));
        }
        return array('bytes' => $bytes, 'mime' => $mime, 'ext' => $ext);
    }

    $in = cp_editor_input();
    $dataUrl = trim((string)($in['signature_data_url'] ?? ''));
    if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
        cp_editor_json(400, array('ok' => false, 'error' => 'signature file or signature_data_url required'));
    }
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) {
        cp_editor_json(400, array('ok' => false, 'error' => 'Invalid signature image data'));
    }
    $ext = strtolower($m[1]) === 'jpeg' || strtolower($m[1]) === 'jpg' ? 'jpg' : strtolower($m[1]);
    $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
    $raw = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bytes = base64_decode($raw, true);
    if ($bytes === false) {
        cp_editor_json(400, array('ok' => false, 'error' => 'Could not decode signature image'));
    }
    require_once __DIR__ . '/../../../src/spaces.php';
    return array('bytes' => $bytes, 'mime' => $mime, 'ext' => $ext);
}

function cp_editor_handle_save_lep_page(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingLepService $lepPageSvc,
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
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $lepIn = is_array($in['lep_page'] ?? null) ? $in['lep_page'] : array();
    $payload = array();
    foreach (array('certification_text', 'on_behalf_text', 'table_title') as $field) {
        if (array_key_exists($field, $lepIn)) {
            $payload[$field] = (string)$lepIn[$field];
        }
    }
    if (array_key_exists('empty_rows', $lepIn)) {
        $payload['empty_rows'] = (int)$lepIn['empty_rows'];
    }
    if (is_array($lepIn['signatories'] ?? null)) {
        $payload['signatories'] = $lepIn['signatories'];
    }

    if (is_array($lepIn['headings'] ?? null)) {
        $payload['headings'] = $lepIn['headings'];
    }
    if (array_key_exists('table_title', $lepIn)) {
        $payload['table_title'] = (string)$lepIn['table_title'];
    }

    $saved = $lepPageSvc->saveLepPageForVersion($versionId, $payload, $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'lep_page' => $saved,
    ));
}

function cp_editor_handle_regenerate_lep_parts(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
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
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $result = $lepPageSvc->regenerateEffectiveParts($versionId, $uid);
    $payload = array(
        'ok' => true,
        'result' => $result,
        'lep_page' => $result['lep_page'],
    );

    if ($sectionId <= 0) {
        foreach ($sections->listFlatSections($versionId) as $row) {
            if ((string)$row['section_key'] === 'lep') {
                $sectionId = (int)$row['id'];
                break;
            }
        }
    }
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                ControlledPublishingBookRenderer::MODE_EDIT,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
        }
    }
    cp_editor_json(200, $payload);
}

function cp_editor_handle_sign_lep_slot(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    $slotKey = trim((string)($in['slot_key'] ?? ''));
    if ($versionId <= 0 || $slotKey === '') {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id and slot_key required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $upload = cp_editor_require_signature_upload();
    $url = cp_editor_upload_publishing_asset($version, 'lep_signature_', $upload);

    $fields = array(
        'signature_url' => $url,
    );
    foreach (array('name', 'title', 'date') as $field) {
        if (array_key_exists($field, $in)) {
            $fields[$field] = trim((string)$in[$field]);
        }
    }

    $updated = $lepPageSvc->updateSignatory($versionId, $slotKey, $fields, $uid);
    if ($updated === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Signatory slot not found'));
    }

    $payload = array(
        'ok' => true,
        'signatory' => $updated,
        'signature_url' => $url,
    );

    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                ControlledPublishingBookRenderer::MODE_EDIT,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
            $payload['lep_page'] = $lepPageSvc->resolveFromVersion($version);
        }
    }
    cp_editor_json(200, $payload);
}

function cp_editor_handle_ensure_lep_approval(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingApprovalService $approvalSvc,
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
    $result = $approvalSvc->ensureApprovalToken($versionId, $uid);
    cp_editor_json(200, array(
        'ok' => true,
        'approval' => $result['approval'],
        'approval_url' => $result['approval_url'],
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

function cp_editor_handle_save_part0_page(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
): void {
    $in = cp_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionKey = trim((string)($in['section_key'] ?? ''));
    if ($versionId <= 0 || $sectionKey === '') {
        cp_editor_json(400, array('ok' => false, 'error' => 'version_id and section_key required'));
    }
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $pageIn = is_array($in['part0_page'] ?? null) ? $in['part0_page'] : array();
    $saved = match ($sectionKey) {
        'amendment_list' => $part0PageSvc->saveAmendmentListForVersion($versionId, $pageIn, $uid),
        'distribution_list' => $part0PageSvc->saveDistributionListForVersion($versionId, $pageIn, $uid),
        'abbreviations' => $part0PageSvc->saveAbbreviationsPageForVersion($versionId, $pageIn, $uid),
        'definitions' => $part0PageSvc->saveDefinitionsForVersion($versionId, $pageIn, $uid),
        default => null,
    };
    if (is_array($in['headings'] ?? null)) {
        $part0PageSvc->saveHeadingsForSection($versionId, $sectionKey, $in['headings'], $uid);
    }
    if ($saved === null && !is_array($in['headings'] ?? null)) {
        cp_editor_json(400, array('ok' => false, 'error' => 'Unknown Part 0 section or empty payload'));
    }
    if ($saved === null) {
        $saved = cp_editor_part0_page_payload($part0PageSvc, $foundation->getVersion($versionId) ?: $version, array('section_key' => $sectionKey)) ?? array();
    }

    cp_editor_json(200, array(
        'ok' => true,
        'part0_page' => $saved,
        'section_key' => $sectionKey,
    ));
}

function cp_editor_handle_regenerate_abbreviations(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
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
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $result = $part0PageSvc->regenerateAbbreviationsIndex($versionId, $uid);
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    $payload = array(
        'ok' => true,
        'result' => $result,
        'part0_page' => $part0PageSvc->resolveAbbreviationsPageFromVersion($version),
    );
    if ($sectionId <= 0) {
        $sectionId = (int)($result['section_id'] ?? 0);
    }
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                ControlledPublishingBookRenderer::MODE_EDIT,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
        }
    }
    cp_editor_json(200, $payload);
}

function cp_editor_purge_highlights_placeholders(
    ControlledPublishingBlockService $blocks,
    int $sectionId
): void {
    global $pdo;
    $stmt = $pdo->prepare("
        DELETE FROM ipca_publishing_book_blocks
        WHERE section_id = :section_id AND block_type = 'generated_placeholder'
    ");
    $stmt->execute(array(':section_id' => $sectionId));
}

function cp_editor_handle_regenerate_definitions(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
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
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $result = $part0PageSvc->regenerateDefinitionsFromManual($versionId, $uid);
    $version = $foundation->getVersion($versionId);
    if ($version === null) {
        cp_editor_json(404, array('ok' => false, 'error' => 'Version not found'));
    }
    $payload = array(
        'ok' => true,
        'result' => $result,
        'part0_page' => $part0PageSvc->resolveDefinitionsFromVersion($version),
    );
    if ($sectionId <= 0) {
        $sectionId = (int)($result['section_id'] ?? 0);
    }
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                ControlledPublishingBookRenderer::MODE_EDIT,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
        }
    }
    cp_editor_json(200, $payload);
}

function cp_editor_handle_regenerate_highlights(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingSectionService $sections,
    ControlledPublishingBlockService $blocks,
    ControlledPublishingBookRenderer $renderer,
    ControlledPublishingRevisionService $revision,
    ControlledPublishingSectionLayoutService $layoutSvc,
    ControlledPublishingBookStyleService $styleSvc,
    ControlledPublishingSectionNumberService $numberSvc,
    ControlledPublishingPageHeaderService $pageHeaderSvc,
    ControlledPublishingCoverPageService $coverPageSvc,
    ControlledPublishingLepService $lepPageSvc,
    ControlledPublishingApprovalService $approvalSvc,
    ControlledPublishingPart0PageService $part0PageSvc,
    int $uid
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
    if ((string)$version['lifecycle_status'] === 'released') {
        cp_editor_json(403, array('ok' => false, 'error' => 'Released versions cannot be edited'));
    }

    $result = $revision->regenerateHighlightsSection($versionId, $uid);
    $payload = array('ok' => true, 'result' => $result);
    if ($sectionId <= 0) {
        $sectionId = (int)($result['section_id'] ?? 0);
    }
    if ($sectionId > 0) {
        $section = $sections->getSection($versionId, $sectionId);
        if ($section !== null) {
            cp_editor_configure_renderer($renderer, $styleSvc, $version, $numberSvc);
            $sectionBlocks = $revision->annotateChangeStatus($versionId, $blocks->listSectionBlocks($sectionId));
            $pageLayout = $layoutSvc->resolveLayout($section);
            $pageHeaderConfig = cp_editor_page_header_config($pageHeaderSvc, $version, $section);
            $blocksHtml = $renderer->renderBlocks($sectionBlocks, ControlledPublishingBookRenderer::MODE_EDIT);
            $payload['blocks'] = $sectionBlocks;
            $payload['page_html'] = cp_editor_render_page_html(
                $renderer,
                $version,
                $section,
                $blocksHtml,
                ControlledPublishingBookRenderer::MODE_EDIT,
                $pageLayout,
                $pageHeaderConfig,
                $coverPageSvc,
                $lepPageSvc,
                $approvalSvc,
                $part0PageSvc,
                $sectionBlocks
            );
        }
    }
    cp_editor_json(200, $payload);
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
    foreach (array('part_1', 'main_content') as $key) {
        foreach ($sections->listFlatSections($versionId) as $row) {
            if ((string)($row['section_key'] ?? '') === $key) {
                return (int)$row['id'];
            }
        }
    }
    $flat = $sections->listFlatSections($versionId);
    return $flat !== array() ? (int)$flat[0]['id'] : 0;
}

function cp_editor_handle_sync_manual_structure(
    ControlledPublishingFoundationService $foundation,
    ControlledPublishingManualStructureService $manualStructureSvc,
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
    if ((string)($version['lifecycle_status'] ?? '') === 'released') {
        cp_editor_json(409, array('ok' => false, 'error' => 'Released versions cannot be restructured.'));
    }

    $result = $manualStructureSvc->syncVersionStructure($versionId, $uid);
    cp_editor_json(200, array_merge(array('ok' => true), $result));
}
