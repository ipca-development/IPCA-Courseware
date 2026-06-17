<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/flight_training/FormTemplateEditorService.php';

header('Content-Type: application/json; charset=utf-8');

function ft_form_editor_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ft_form_editor_input(): array
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

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new FormTemplateEditorService($pdo);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = ft_form_editor_input();
    $action = (string)($input['action'] ?? '');
}

try {
    switch ($action) {
        case 'load':
            ft_form_handle_load($service, $actorUserId);
            break;

        case 'create_block':
            ft_form_handle_create_block($service, $actorUserId);
            break;

        case 'update_block':
            ft_form_handle_update_block($service, $actorUserId);
            break;

        case 'delete_block':
            ft_form_handle_delete_block($service, $actorUserId);
            break;

        case 'move_block':
            ft_form_handle_move_block($service, $actorUserId);
            break;

        case 'recompute_section_numbers':
            ft_form_handle_recompute($service, $actorUserId);
            break;

        case 'save_section_layout':
            ft_form_handle_save_section_layout($service, $actorUserId);
            break;

        case 'get_callout_presets':
            ft_form_handle_get_callout_presets($service, $actorUserId);
            break;

        case 'save_callout_presets':
            ft_form_handle_save_callout_presets($service, $actorUserId);
            break;

        case 'get_book_styles':
            ft_form_handle_get_book_styles($service, $actorUserId);
            break;

        case 'save_book_styles':
            ft_form_handle_save_book_styles($service, $actorUserId);
            break;

        case 'save_page_header':
            ft_form_handle_save_page_header($service, $actorUserId);
            break;

        case 'upload_header_logo':
            ft_form_handle_upload_header_logo($service, $actorUserId);
            break;

        case 'upload_image':
            ft_form_handle_upload_image($service, $actorUserId);
            break;

        case 'create_subsection':
            ft_form_handle_create_subsection($service, $actorUserId);
            break;

        case 'detect_callouts':
        case 'detect_hyperlinks':
        case 'detect_annex_refs':
        case 'regenerate_toc':
        case 'sync_manual_structure':
        case 'regenerate_highlights':
            ft_form_handle_manual_only_noop($action);
            break;

        case 'save_content':
            $input = ft_form_editor_input();
            $templateId = (int)($input['template_id'] ?? 0);
            $versionId = (int)($input['template_version_id'] ?? 0);
            $document = is_array($input['document'] ?? null) ? $input['document'] : array();
            ft_form_editor_json(200, array(
                'ok' => true,
                'data' => $service->saveContent($templateId, $versionId, $document, $actorUserId),
            ));

        case 'sync_fields':
            $input = ft_form_editor_input();
            $templateId = (int)($input['template_id'] ?? 0);
            $versionId = (int)($input['template_version_id'] ?? 0);
            $document = is_array($input['document'] ?? null) ? $input['document'] : array();
            $saved = $service->saveContent($templateId, $versionId, $document, $actorUserId);
            ft_form_editor_json(200, array('ok' => true, 'fields' => $saved['fields']));

        case 'variables':
            ft_form_editor_json(200, array('ok' => true, 'variables' => FormTemplateEditorService::variableCatalog()));
            break;

        default:
            ft_form_editor_json(200, array('ok' => true, 'skipped' => true));
    }
} catch (Throwable $e) {
    ft_form_editor_json(400, array('ok' => false, 'error' => $e->getMessage()));
}

function ft_form_template_id_from_version_id(int $versionId): int
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT template_id FROM ipca_form_template_versions WHERE id = ? LIMIT 1');
    $stmt->execute(array($versionId));
    return (int)$stmt->fetchColumn();
}

function ft_form_load_context(FormTemplateEditorService $service, int $versionId, int $actorUserId): array
{
    $templateId = ft_form_template_id_from_version_id($versionId);
    if ($templateId <= 0) {
        throw new RuntimeException('Template version not found.');
    }
    $ctx = $service->loadEditor($templateId, $actorUserId);
    $doc = ft_form_document_with_sections($ctx['document'], (string)($ctx['template']['title'] ?? 'Form Template'));
    return array($templateId, $ctx, $doc);
}

function ft_form_handle_load(FormTemplateEditorService $service, int $actorUserId): void
{
    $versionId = (int)($_GET['version_id'] ?? 0);
    $sectionId = (int)($_GET['section_id'] ?? 0);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $section = ft_form_find_section($doc, $sectionId);
    $pageHtml = ft_form_render_page($ctx, $doc, $section);
    ft_form_editor_json(200, ft_form_load_payload($ctx, $doc, $section, $pageHtml));
}

function ft_form_handle_create_block(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $section = ft_form_find_section($doc, $sectionId);
    $block = ft_form_new_block((string)($in['block_type'] ?? 'paragraph'), is_array($in['payload'] ?? null) ? $in['payload'] : array(), $doc, $sectionId);
    $afterId = (int)($in['insert_after_block_id'] ?? 0);
    $inserted = false;
    foreach ($doc['blocks'] as $idx => $existing) {
        if ((int)($existing['id'] ?? 0) === $afterId) {
            array_splice($doc['blocks'], $idx + 1, 0, array($block));
            $inserted = true;
            break;
        }
    }
    if (!$inserted) {
        $doc['blocks'][] = $block;
    }
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array(
        'ok' => true,
        'block_id' => (int)$block['id'],
        'block_html' => ft_form_render_block($block, true),
    ));
}

function ft_form_handle_update_block(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $payload = is_array($in['payload'] ?? null) ? $in['payload'] : array();
    $versionId = (int)($in['version_id'] ?? 0);
    if ($versionId <= 0) {
        $versionId = ft_form_version_id_for_block($blockId);
    }
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    foreach ($doc['blocks'] as &$block) {
        if ((int)($block['id'] ?? 0) === $blockId) {
            $block['payload'] = $payload;
            break;
        }
    }
    unset($block);
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true));
}

function ft_form_handle_delete_block(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $versionId = (int)($in['version_id'] ?? 0);
    if ($versionId <= 0) {
        $versionId = ft_form_version_id_for_block($blockId);
    }
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $doc['blocks'] = array_values(array_filter($doc['blocks'], static fn(array $block): bool => (int)($block['id'] ?? 0) !== $blockId));
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true));
}

function ft_form_handle_move_block(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $blockId = (int)($in['block_id'] ?? 0);
    $direction = (string)($in['direction'] ?? '');
    $versionId = (int)($in['version_id'] ?? 0);
    if ($versionId <= 0) {
        $versionId = ft_form_version_id_for_block($blockId);
    }
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $idx = -1;
    foreach ($doc['blocks'] as $i => $block) {
        if ((int)($block['id'] ?? 0) === $blockId) {
            $idx = $i;
            break;
        }
    }
    $target = $direction === 'up' ? $idx - 1 : $idx + 1;
    if ($idx >= 0 && isset($doc['blocks'][$target])) {
        $tmp = $doc['blocks'][$target];
        $doc['blocks'][$target] = $doc['blocks'][$idx];
        $doc['blocks'][$idx] = $tmp;
    }
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true));
}

function ft_form_handle_recompute(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $section = ft_form_find_section($doc, $sectionId);
    ft_form_editor_json(200, array(
        'ok' => true,
        'page_html' => ft_form_render_page($ctx, $doc, $section),
        'section_number_display' => array(),
        'suggested_regulatory_refs' => array(),
        'manual_code' => 'FORM',
    ));
}

function ft_form_handle_save_section_layout(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $sectionId = (int)($in['section_id'] ?? 0);
    $layout = is_array($in['layout'] ?? null) ? $in['layout'] : array();
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    foreach ($doc['sections'] as &$section) {
        if ((int)($section['id'] ?? 0) === $sectionId) {
            $section['layout'] = $layout;
            break;
        }
    }
    unset($section);
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'layout' => $layout));
}

function ft_form_handle_get_callout_presets(FormTemplateEditorService $service, int $actorUserId): void
{
    $versionId = (int)($_GET['version_id'] ?? 0);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $styles = ft_form_resolve_book_styles($doc);
    ft_form_editor_json(200, array('ok' => true, 'presets' => $styles['callout_presets'] ?? array()));
}

function ft_form_handle_save_callout_presets(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $presets = is_array($in['presets'] ?? null) ? $in['presets'] : array();
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $styles = ft_form_resolve_book_styles($doc);
    $styles['callout_presets'] = $presets;
    $doc['book_styles'] = $styles;
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'presets' => $presets));
}

function ft_form_handle_get_book_styles(FormTemplateEditorService $service, int $actorUserId): void
{
    $versionId = (int)($_GET['version_id'] ?? 0);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'book_styles' => ft_form_resolve_book_styles($doc)));
}

function ft_form_handle_save_book_styles(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $bookStyles = is_array($in['book_styles'] ?? null) ? $in['book_styles'] : array();
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $doc['book_styles'] = $bookStyles;
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'book_styles' => ft_form_resolve_book_styles($doc)));
}

function ft_form_handle_save_page_header(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $pageHeader = is_array($in['page_header'] ?? null) ? $in['page_header'] : array();
    $pageFooter = is_array($in['page_footer'] ?? null) ? $in['page_footer'] : array();
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $doc['page_header'] = $pageHeader;
    $doc['page_footer'] = $pageFooter;
    $styles = ft_form_resolve_book_styles($doc);
    $styles['page_header'] = $pageHeader;
    $styles['page_footer'] = $pageFooter;
    $doc['book_styles'] = $styles;
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array(
        'ok' => true,
        'page_header' => $pageHeader,
        'page_footer' => $pageFooter,
        'header_scope' => 'main',
    ));
}

function ft_form_handle_upload_header_logo(FormTemplateEditorService $service, int $actorUserId): void
{
    $versionId = (int)($_POST['version_id'] ?? 0);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $url = ft_form_store_uploaded_image('image', 'headers');
    $header = is_array($doc['page_header'] ?? null) ? $doc['page_header'] : ft_form_default_page_header($ctx);
    $footer = is_array($doc['page_footer'] ?? null) ? $doc['page_footer'] : ft_form_default_page_footer();
    $header['logo_url'] = $url;
    $alt = trim((string)($_POST['alt'] ?? 'EuroPilot Center'));
    if ($alt !== '') {
        $header['logo_alt'] = $alt;
    }
    $doc['page_header'] = $header;
    $doc['page_footer'] = $footer;
    $styles = ft_form_resolve_book_styles($doc);
    $styles['page_header'] = $header;
    $styles['page_footer'] = $footer;
    $doc['book_styles'] = $styles;
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'url' => $url, 'page_header' => $header, 'page_footer' => $footer, 'header_scope' => 'main'));
}

function ft_form_handle_upload_image(FormTemplateEditorService $service, int $actorUserId): void
{
    $versionId = (int)($_POST['version_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 1);
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $url = ft_form_store_uploaded_image('image', 'blocks');
    $block = ft_form_new_block('image', array('url' => $url, 'alt' => '', 'width_pct' => 100, 'rotation_deg' => 0), $doc, $sectionId);
    $doc['blocks'][] = $block;
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'block_html' => ft_form_render_block($block, true)));
}

function ft_form_handle_create_subsection(FormTemplateEditorService $service, int $actorUserId): void
{
    $in = ft_form_editor_input();
    $versionId = (int)($in['version_id'] ?? 0);
    $parentId = (int)($in['parent_section_id'] ?? 0);
    $title = trim((string)($in['title'] ?? 'New section'));
    [$templateId, $ctx, $doc] = ft_form_load_context($service, $versionId, $actorUserId);
    $nextId = 1;
    foreach ($doc['sections'] as $section) {
        $nextId = max($nextId, (int)($section['id'] ?? 0) + 1);
    }
    $doc['sections'][] = array(
        'id' => $nextId,
        'section_key' => 'section_' . $nextId,
        'title' => $title !== '' ? $title : 'New section',
        'sort_order' => $nextId * 10,
        'parent_section_id' => $parentId > 0 ? $parentId : null,
        'layout' => array(),
    );
    $service->saveContent($templateId, $versionId, $doc, $actorUserId);
    ft_form_editor_json(200, array('ok' => true, 'section_id' => $nextId, 'sections_tree' => ft_form_sections_tree($doc['sections'])));
}

function ft_form_handle_manual_only_noop(string $action): void
{
    $countKey = match ($action) {
        'regenerate_toc' => 'entries_count',
        'regenerate_highlights' => 'changes_count',
        default => 'updated_count',
    };
    ft_form_editor_json(200, array('ok' => true, 'result' => array($countKey => 0, 'sections_updated' => 0)));
}

function ft_form_version_id_for_block(int $blockId): int
{
    global $pdo;
    $stmt = $pdo->query('SELECT id, content_json FROM ipca_form_template_versions');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
        $doc = ft_form_document_with_sections(json_decode((string)($row['content_json'] ?? '{}'), true) ?: array(), '');
        foreach ($doc['blocks'] as $block) {
            if ((int)($block['id'] ?? 0) === $blockId) {
                return (int)$row['id'];
            }
        }
    }
    throw new RuntimeException('Block not found.');
}

function ft_form_document_with_sections(array $document, string $title): array
{
    $sections = is_array($document['sections'] ?? null) ? $document['sections'] : array();
    if ($sections === array()) {
        $sections = array(
            array('id' => 1, 'section_key' => 'applicant_information', 'title' => 'Applicant Information', 'sort_order' => 10),
            array('id' => 2, 'section_key' => 'cfi_information', 'title' => 'CFI Information', 'sort_order' => 20),
            array('id' => 3, 'section_key' => 'knowledge_test_codes', 'title' => 'Knowledge Test Codes', 'sort_order' => 30),
            array('id' => 4, 'section_key' => 'flight_experience', 'title' => 'Flight Experience', 'sort_order' => 40),
            array('id' => 5, 'section_key' => 'endorsements_signatures', 'title' => 'Endorsements and Signatures', 'sort_order' => 50),
        );
    }
    $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : array();
    if ($blocks === array()) {
        $blocks[] = ft_form_new_block('heading', array('text' => $title !== '' ? $title : 'Form Template', 'level' => 1), array('blocks' => array()), 1);
        $blocks[] = ft_form_new_block('paragraph', array('html' => 'Add form content here.'), array('blocks' => $blocks), 1);
    }
    foreach ($blocks as &$block) {
        if (empty($block['id'])) {
            $block['id'] = ft_form_next_block_id(array('blocks' => $blocks));
        }
        if (empty($block['section_id'])) {
            $block['section_id'] = 1;
        }
    }
    unset($block);
    $document['document_type'] = 'form';
    $document['schema_version'] = 1;
    $document['title'] = (string)($document['title'] ?? $title);
    $document['page_header'] = is_array($document['page_header'] ?? null) ? $document['page_header'] : ft_form_default_page_header(array('template' => array('title' => $title)));
    $document['page_footer'] = is_array($document['page_footer'] ?? null) ? $document['page_footer'] : ft_form_default_page_footer();
    $document['sections'] = $sections;
    $document['blocks'] = $blocks;
    return $document;
}

function ft_form_new_block(string $type, array $payload, array $doc, int $sectionId = 0): array
{
    $id = ft_form_next_block_id($doc);
    $sectionId = $sectionId > 0 ? $sectionId : (int)($_POST['section_id'] ?? 0);
    if ($sectionId <= 0) {
        $raw = file_get_contents('php://input');
        $in = is_string($raw) ? json_decode($raw, true) : array();
        $sectionId = (int)($in['section_id'] ?? 1);
    }
    return array(
        'id' => $id,
        'section_id' => $sectionId,
        'block_key' => strtolower($type) . '_' . $id,
        'stable_anchor' => 'form-block-' . $id,
        'block_type' => $type,
        'payload' => ft_form_default_payload($type, $payload),
        'payload_json' => json_encode(ft_form_default_payload($type, $payload), JSON_UNESCAPED_SLASHES),
        'sort_order' => $id * 10,
        'is_system_managed' => 0,
    );
}

function ft_form_next_block_id(array $doc): int
{
    $max = 0;
    foreach ((array)($doc['blocks'] ?? array()) as $block) {
        $max = max($max, (int)($block['id'] ?? 0));
    }
    return $max + 1;
}

function ft_form_default_payload(string $type, array $payload): array
{
    if ($type === 'heading') return array('text' => (string)($payload['text'] ?? 'Heading'), 'level' => (int)($payload['level'] ?? 2), 'paragraph_style' => 'subtitle_2');
    if ($type === 'list') return array('ordered' => !empty($payload['ordered']), 'items' => $payload['items'] ?? array('List item'), 'paragraph_style' => 'body');
    if ($type === 'table') return array('title' => (string)($payload['title'] ?? ''), 'headers' => $payload['headers'] ?? array('Column 1', 'Column 2'), 'rows' => $payload['rows'] ?? array(array('', '')));
    if ($type === 'image') return array('url' => (string)($payload['url'] ?? ''), 'alt' => (string)($payload['alt'] ?? ''), 'width_pct' => (int)($payload['width_pct'] ?? 100), 'rotation_deg' => (int)($payload['rotation_deg'] ?? 0));
    if ($type === 'callout') {
        $calloutType = (string)($payload['callout_type'] ?? 'warning');
        if (!in_array($calloutType, array('warning', 'caution', 'info', 'note'), true)) {
            $calloutType = 'warning';
        }
        return array(
            'callout_type' => $calloutType,
            'title' => (string)($payload['title'] ?? strtoupper($calloutType)),
            'text' => (string)($payload['text'] ?? ''),
            'title_font_family' => (string)($payload['title_font_family'] ?? ''),
            'title_font_size' => (int)($payload['title_font_size'] ?? 0),
            'title_text_color' => (string)($payload['title_text_color'] ?? ''),
            'text_font_family' => (string)($payload['text_font_family'] ?? ''),
            'text_font_size' => (int)($payload['text_font_size'] ?? 0),
            'text_text_color' => (string)($payload['text_text_color'] ?? ''),
        );
    }
    if (in_array($type, array('field', 'checkbox', 'date', 'signature', 'initial'), true)) {
        return array(
            'field_key' => (string)($payload['field_key'] ?? $type . '_' . time()),
            'field_type' => (string)($payload['field_type'] ?? ($type === 'field' ? 'text' : $type)),
            'label' => (string)($payload['label'] ?? ucfirst($type)),
            'required' => !empty($payload['required']),
            'assigned_role' => (string)($payload['assigned_role'] ?? 'instructor'),
            'variable_key' => (string)($payload['variable_key'] ?? ''),
            'placeholder' => (string)($payload['placeholder'] ?? ''),
        );
    }
    return array('html' => (string)($payload['html'] ?? 'Text block'), 'paragraph_style' => 'body');
}

function ft_form_find_section(array $doc, int $sectionId): array
{
    foreach ($doc['sections'] as $section) {
        if ((int)$section['id'] === $sectionId) return ft_form_section_row($section);
    }
    return ft_form_section_row($doc['sections'][0]);
}

function ft_form_section_row(array $section): array
{
    return array(
        'id' => (int)$section['id'],
        'section_key' => (string)$section['section_key'],
        'stable_anchor' => (string)$section['section_key'],
        'title' => (string)$section['title'],
        'section_type' => 'content',
        'parent_section_id' => !empty($section['parent_section_id']) ? (int)$section['parent_section_id'] : null,
        'allow_author_blocks' => 1,
        'is_system_managed' => 0,
        'is_generated' => 0,
        'metadata_json' => json_encode(array('layout' => is_array($section['layout'] ?? null) ? $section['layout'] : array())),
        'layout' => is_array($section['layout'] ?? null) ? $section['layout'] : array(),
    );
}

function ft_form_sections_tree(array $sections, ?int $parentId = null): array
{
    $tree = array();
    foreach ($sections as $section) {
        $sectionParent = !empty($section['parent_section_id']) ? (int)$section['parent_section_id'] : null;
        if ($sectionParent !== $parentId) {
            continue;
        }
        $id = (int)($section['id'] ?? 0);
        $tree[] = array(
            'id' => $id,
            'title' => (string)($section['title'] ?? 'Section'),
            'section_key' => (string)($section['section_key'] ?? ''),
            'children' => ft_form_sections_tree($sections, $id),
        );
    }
    usort($tree, static function (array $a, array $b): int {
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
    return $tree;
}

function ft_form_load_payload(array $ctx, array $doc, array $section, string $pageHtml): array
{
    return array(
        'ok' => true,
        'version' => array('id' => (int)$ctx['version']['id'], 'book_key' => 'FORM', 'book_title' => (string)$ctx['template']['title'], 'version_label' => (string)$ctx['version']['version_label'], 'lifecycle_status' => (string)$ctx['version']['lifecycle_status']),
        'section_id' => (int)$section['id'],
        'section' => $section,
        'sections_tree' => ft_form_sections_tree($doc['sections']),
        'blocks' => array_values(array_filter($doc['blocks'], static fn(array $b): bool => (int)($b['section_id'] ?? 1) === (int)$section['id'])),
        'page_html' => $pageHtml,
        'page_layout' => is_array($section['layout'] ?? null) ? $section['layout'] : array('orientation' => 'portrait'),
        'editable' => (bool)$ctx['editable'],
        'is_cover_section' => false,
        'is_toc_section' => false,
        'is_lep_section' => false,
        'is_part0_section' => false,
        'is_annex_register_section' => false,
        'is_annex_highlights_section' => false,
        'is_annex_content_section' => false,
        'part0_section_key' => '',
        'part0_structured' => false,
        'part0_page' => null,
        'cover_page' => array(),
        'lep_page' => array(),
        'lep_approval_url' => '',
        'toc_settings' => array(),
        'toc_settings_catalog' => array(),
        'book_styles' => ft_form_resolve_book_styles($doc),
        'page_header' => is_array($doc['page_header'] ?? null) ? $doc['page_header'] : ft_form_default_page_header($ctx),
        'page_footer' => is_array($doc['page_footer'] ?? null) ? $doc['page_footer'] : ft_form_default_page_footer(),
        'header_tokens' => array(),
        'header_preview_tokens' => array(),
        'page_header_scope' => 'main',
        'section_number_display' => array(),
        'suggested_regulatory_refs' => array(),
        'manual_code' => 'FORM',
    );
}

function ft_form_render_page(array $ctx, array $doc, array $section): string
{
    $html = '';
    foreach ($doc['blocks'] as $block) {
        if ((int)($block['section_id'] ?? 1) === (int)$section['id']) {
            $html .= ft_form_render_block($block, (bool)$ctx['editable']);
        }
    }
    $layout = is_array($section['layout'] ?? null) ? $section['layout'] : array();
    $isLandscape = (string)($layout['orientation'] ?? '') === 'landscape';
    $sheetClass = 'cpb-sheet' . ($isLandscape ? ' cpb-sheet--landscape' : '');
    $layoutToggle = '';
    if (!empty($ctx['editable'])) {
        $hideChecked = !empty($layout['hide_header_footer']) ? ' checked' : '';
        $layoutToggle = '<div class="cpb-page-layout-toggle" contenteditable="false"><label><input type="checkbox" data-layout-toggle="hide_header_footer"' . $hideChecked . '> Hide header/footer on this section</label></div>';
    }
    $hideHeaderFooter = !empty($layout['hide_header_footer']);
    $header = !$hideHeaderFooter && is_array($doc['page_header'] ?? null) ? ft_form_render_header_band($doc['page_header'], $ctx, $section) : '';
    $footer = !$hideHeaderFooter && is_array($doc['page_footer'] ?? null) ? ft_form_render_footer_band($doc['page_footer'], $ctx, $section) : '';
    return '<div class="' . $sheetClass . '" data-section-id="' . (int)$section['id'] . '">' . $layoutToggle . $header . '<div class="cpb-sheet-body" data-blocks-root="1">' . $html . '</div><div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div>' . $footer . '</div>';
}

function ft_form_render_block(array $block, bool $editable): string
{
    $id = (int)($block['id'] ?? 0);
    $type = (string)($block['block_type'] ?? 'paragraph');
    $payload = is_array($block['payload'] ?? null) ? $block['payload'] : (json_decode((string)($block['payload_json'] ?? '{}'), true) ?: array());
    $chrome = $editable ? '<div class="cpb-block-chrome" contenteditable="false"><button type="button" class="cpb-block-btn" data-action="insert-paragraph" title="Insert paragraph below">¶+</button><button type="button" class="cpb-block-btn" data-action="move-up" title="Move up">↑</button><button type="button" class="cpb-block-btn" data-action="move-down" title="Move down">↓</button><button type="button" class="cpb-block-btn cpb-block-btn--danger" data-action="delete" title="Delete">×</button></div>' : '';
    return '<article class="cpb-block cpb-block--' . h($type) . '" data-block-id="' . $id . '" data-block-type="' . h($type) . '" data-stable-anchor="form-block-' . $id . '">' . $chrome . ft_form_render_inner($type, $payload, $editable) . '</article>';
}

function ft_form_render_inner(string $type, array $payload, bool $editable): string
{
    $ce = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
    if ($type === 'heading') {
        $styleKey = ft_form_style_key($payload, 'subtitle_2');
        $level = max(1, min(6, (int)($payload['level'] ?? 2)));
        return '<h' . $level . ' class="cpb-heading cpb-ps-' . h($styleKey) . ft_form_align_class($payload) . '" data-paragraph-style="' . h($styleKey) . '" data-level="' . $level . '"' . ft_form_text_data_attrs($payload) . ft_form_text_style_attr($payload) . $ce . '>' . h((string)($payload['text'] ?? 'Heading')) . '</h' . $level . '>';
    }
    if ($type === 'list') {
        $tag = !empty($payload['ordered']) ? 'ol' : 'ul';
        $styleKey = ft_form_style_key($payload, 'body');
        $items = (array)($payload['items'] ?? array('List item'));
        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<li>' . h((string)$item) . '</li>';
        }
        return '<' . $tag . ' class="cpb-list cpb-ps-' . h($styleKey) . ft_form_align_class($payload) . '" data-paragraph-style="' . h($styleKey) . '"' . ft_form_text_data_attrs($payload) . ft_form_text_style_attr($payload) . $ce . '>' . $itemsHtml . '</' . $tag . '>';
    }
    if ($type === 'table') {
        $headers = (array)($payload['headers'] ?? array('Column 1', 'Column 2'));
        $rows = (array)($payload['rows'] ?? array(array('', '')));
        $borderWidth = in_array((string)($payload['border_width'] ?? 'medium'), array('thin', 'medium', 'thick'), true) ? (string)$payload['border_width'] : 'medium';
        $borderColor = (string)($payload['border_color'] ?? '#94a3b8');
        $tableAlign = in_array((string)($payload['table_align'] ?? 'left'), array('left', 'center', 'right'), true) ? (string)$payload['table_align'] : 'left';
        $tableStyleKind = (string)($payload['table_style_kind'] ?? 'standard');
        $colWidths = (array)($payload['col_widths'] ?? array());
        $out = '<div class="cpb-table-block cpb-table-block--align-' . h($tableAlign) . '" data-table-align="' . h($tableAlign) . '" data-table-style-kind="' . h($tableStyleKind) . '"><div class="cpb-table-wrap cpb-table-border-' . h($borderWidth) . '" data-border-width="' . h($borderWidth) . '" data-border-color="' . h($borderColor) . '" style="--cpb-table-border-color:' . h($borderColor) . '"><table class="cpb-table" data-field="table"><colgroup>';
        foreach ($headers as $idx => $_) {
            $out .= '<col style="width:' . max(40, (int)($colWidths[$idx] ?? 120)) . 'px">';
        }
        $out .= '</colgroup><thead>';
        if (!empty($payload['has_title_row'])) {
            $title = (string)($payload['title'] ?? '');
            $out .= '<tr class="cpb-table-title-row' . (trim(strip_tags($title)) === '' ? ' is-empty' : '') . '" data-title-row="1">'
                . '<td colspan="' . max(1, count($headers)) . '"' . $ce . ft_form_table_cell_attrs(
                    (string)($payload['title_bg'] ?? ''),
                    (string)($payload['title_align'] ?? 'center'),
                    (string)($payload['title_font_family'] ?? ''),
                    (int)($payload['title_font_size'] ?? 0),
                    (string)($payload['title_text_color'] ?? '')
                ) . ' data-placeholder="Table title (spans all columns)">' . $title . '</td></tr>';
        }
        $out .= '<tr class="cpb-table-header-row">';
        $headerBg = (array)($payload['header_bg'] ?? array());
        $headerAlign = (array)($payload['header_align'] ?? array());
        $headerFontFamily = (array)($payload['header_font_family'] ?? array());
        $headerFontSize = (array)($payload['header_font_size'] ?? array());
        $headerTextColor = (array)($payload['header_text_color'] ?? array());
        $headerColspans = (array)($payload['header_colspans'] ?? array());
        foreach ($headers as $idx => $header) {
            $colspan = max(1, (int)($headerColspans[$idx] ?? 1));
            $out .= '<th colspan="' . $colspan . '"' . $ce . ft_form_table_cell_attrs(
                (string)($headerBg[$idx] ?? ''),
                (string)($headerAlign[$idx] ?? 'left'),
                (string)($headerFontFamily[$idx] ?? ''),
                (int)($headerFontSize[$idx] ?? 0),
                (string)($headerTextColor[$idx] ?? '')
            ) . ' data-col-index="' . (int)$idx . '"><span class="cpb-th-text">' . (string)$header . '</span>';
            if ($editable) {
                $out .= '<span class="cpb-col-resize" data-col-index="' . (int)$idx . '" title="Resize column"></span>';
            }
            $out .= '</th>';
        }
        $out .= '</tr></thead><tbody data-table-part="body">';
        $cellBg = (array)($payload['cell_bg'] ?? array());
        $cellAlign = (array)($payload['cell_align'] ?? array());
        $cellFontFamily = (array)($payload['cell_font_family'] ?? array());
        $cellFontSize = (array)($payload['cell_font_size'] ?? array());
        $cellTextColor = (array)($payload['cell_text_color'] ?? array());
        $rowColspans = (array)($payload['row_colspans'] ?? array());
        foreach ($rows as $rowIdx => $row) {
            $out .= '<tr>';
            foreach ((array)$row as $idx => $cellValue) {
                $colspan = max(1, (int)($rowColspans[$rowIdx][$idx] ?? 1));
                $rawCell = (string)$cellValue;
                $formulaAttr = (!$editable && str_starts_with($rawCell, '='))
                    ? ' data-formula="' . h($rawCell) . '" title="' . h($rawCell) . '"'
                    : '';
                $out .= '<td colspan="' . $colspan . '"' . $ce . ft_form_table_cell_attrs(
                    (string)($cellBg[$rowIdx][$idx] ?? ''),
                    (string)($cellAlign[$rowIdx][$idx] ?? 'left'),
                    (string)($cellFontFamily[$rowIdx][$idx] ?? ''),
                    (int)($cellFontSize[$rowIdx][$idx] ?? 0),
                    (string)($cellTextColor[$rowIdx][$idx] ?? '')
                ) . $formulaAttr . '>' . $rawCell . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
        if ($editable) {
            $out .= '<div class="cpb-table-tools" contenteditable="false">'
                . '<button type="button" class="cpb-mini-btn cpb-mini-btn--danger" data-table-action="delete-table" title="Delete table">Delete table</button>'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Align</span>'
                . '<button type="button" class="cpb-mini-btn' . ($tableAlign === 'left' ? ' is-active' : '') . '" data-table-action="table-align-left" title="Align table left">L</button>'
                . '<button type="button" class="cpb-mini-btn' . ($tableAlign === 'center' ? ' is-active' : '') . '" data-table-action="table-align-center" title="Align table center">C</button>'
                . '<button type="button" class="cpb-mini-btn' . ($tableAlign === 'right' ? ' is-active' : '') . '" data-table-action="table-align-right" title="Align table right">R</button>'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Rows</span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="move-row-up" title="Move selected row up">↑ Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="move-row-down" title="Move selected row down">↓ Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-row" title="Add row at bottom">+ Row</button>'
                . '<button type="button" class="cpb-mini-btn cpb-mini-btn--danger" data-table-action="del-row" title="Delete selected row">Delete row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-col" title="Add column at right">+ Column</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="del-col" title="Remove rightmost column">− Column</button>'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Cells</span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="merge-cells-right" title="Merge with cell to the right">Merge →</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="unmerge-cells" title="Split merged cell into columns">Unmerge</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="toggle-title">+ Title row</button>'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Border</span>'
                . '<button type="button" class="cpb-mini-btn' . ($borderWidth === 'thin' ? ' is-active' : '') . '" data-table-action="border-thin" title="Thin border">─</button>'
                . '<button type="button" class="cpb-mini-btn' . ($borderWidth === 'medium' ? ' is-active' : '') . '" data-table-action="border-medium" title="Medium border">━</button>'
                . '<button type="button" class="cpb-mini-btn' . ($borderWidth === 'thick' ? ' is-active' : '') . '" data-table-action="border-thick" title="Thick border">▬</button>'
                . '<input type="color" class="cpb-table-color" data-table-action="border-color" value="' . h($borderColor) . '" title="Border color">'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Cell fill</span>'
                . '<input type="color" class="cpb-table-color" data-table-action="cell-bg" value="#ffffff" title="Cell background (select a cell first)">'
                . '<button type="button" class="cpb-mini-btn" data-table-action="cell-bg-clear" title="Clear cell fill">Clear</button>'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Text</span>'
                . '<input type="color" class="cpb-table-color" data-table-action="cell-text-color" value="#0f172a" title="Text color (select a cell first)">'
                . '<span class="cpb-table-style-sep"></span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="copy-cells" title="Copy column or selection">Copy</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="paste-cells" title="Paste TSV column or grid">Paste</button>'
                . '<span class="cpb-table-style-sep"></span><span class="cpb-table-style-label">Calc</span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="formula-sum" title="Insert SUM formula">SUM</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="formula-avg" title="Insert AVG formula">AVG</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="formula-custom" title="Insert custom formula">fx</button>'
                . '</div>';
        }
        return $out . '</div>';
    }
    if ($type === 'image') {
        $url = trim((string)($payload['url'] ?? ''));
        $alt = (string)($payload['alt'] ?? '');
        $width = max(20, min(100, (int)($payload['width_pct'] ?? 100)));
        $rotation = (int)($payload['rotation_deg'] ?? 0);
        if ($url === '') {
            return '<div class="cpb-image cpb-image--empty" contenteditable="false">Image missing</div>';
        }
        $tools = $editable ? '<button type="button" class="cpb-image-rotate" data-image-action="rotate" title="Rotate image">↻</button><span class="cpb-image-resize" title="Resize image"></span>' : '';
        return '<figure class="cpb-image cpb-image--editable" data-width-pct="' . $width . '" data-rotation-deg="' . $rotation . '" style="width:' . $width . '%;transform:rotate(' . $rotation . 'deg);" contenteditable="false"><span class="cpb-image-frame"><img src="' . h($url) . '" alt="' . h($alt) . '">' . $tools . '</span><figcaption' . $ce . '>' . h($alt) . '</figcaption></figure>';
    }
    if ($type === 'callout') {
        $calloutType = (string)($payload['callout_type'] ?? 'warning');
        if (!in_array($calloutType, array('warning', 'caution', 'info', 'note'), true)) {
            $calloutType = 'warning';
        }
        $titleStyle = '';
        if (!empty($payload['title_font_size'])) {
            $titleStyle .= 'font-size:' . (int)$payload['title_font_size'] . 'pt;';
        }
        if (!empty($payload['title_text_color'])) {
            $titleStyle .= 'color:' . h((string)$payload['title_text_color']) . ';';
        }
        $textStyle = '';
        if (!empty($payload['text_font_size'])) {
            $textStyle .= 'font-size:' . (int)$payload['text_font_size'] . 'pt;';
        }
        if (!empty($payload['text_text_color'])) {
            $textStyle .= 'color:' . h((string)$payload['text_text_color']) . ';';
        }
        return '<div class="cpb-callout cpb-callout--' . h($calloutType) . '" data-callout-type="' . h($calloutType) . '" contenteditable="false">'
            . '<div class="cpb-callout-title" data-font-family="' . h((string)($payload['title_font_family'] ?? '')) . '" data-font-size="' . (int)($payload['title_font_size'] ?? 0) . '" style="' . h($titleStyle) . '"' . $ce . '>' . (string)($payload['title'] ?? strtoupper($calloutType)) . '</div>'
            . '<div class="cpb-callout-text" data-font-family="' . h((string)($payload['text_font_family'] ?? '')) . '" data-font-size="' . (int)($payload['text_font_size'] ?? 0) . '" style="' . h($textStyle) . '"' . $ce . '>' . (string)($payload['text'] ?? '') . '</div>'
            . '</div>';
    }
    if (in_array($type, array('field', 'checkbox', 'date', 'signature', 'initial'), true)) {
        $fieldType = (string)($payload['field_type'] ?? $type);
        $control = '<span class="cpb-form-input-line">' . h((string)($payload['placeholder'] ?? '')) . '</span>';
        if ($fieldType === 'checkbox') $control = '<span class="cpb-form-checkbox-box"></span>';
        if ($fieldType === 'signature') $control = '<span class="cpb-form-signature-box">Signature</span>';
        if ($fieldType === 'initial') $control = '<span class="cpb-form-initial-box">Initial</span>';
        return '<div class="cpb-form-field" contenteditable="false" data-form-field="1" data-field-key="' . h((string)($payload['field_key'] ?? '')) . '" data-field-type="' . h($fieldType) . '" data-label="' . h((string)($payload['label'] ?? 'Field')) . '" data-required="' . (!empty($payload['required']) ? '1' : '0') . '" data-assigned-role="' . h((string)($payload['assigned_role'] ?? 'instructor')) . '" data-variable-key="' . h((string)($payload['variable_key'] ?? '')) . '" data-placeholder="' . h((string)($payload['placeholder'] ?? '')) . '"><span class="cpb-form-field-label">' . h((string)($payload['label'] ?? 'Field')) . '</span>' . $control . '<span class="cpb-form-role">' . h((string)($payload['assigned_role'] ?? 'instructor')) . '</span></div>';
    }
    $styleKey = ft_form_style_key($payload, 'body');
    return '<div class="cpb-paragraph cpb-ps-' . h($styleKey) . ft_form_align_class($payload) . '" data-paragraph-style="' . h($styleKey) . '"' . ft_form_text_data_attrs($payload) . ft_form_text_style_attr($payload) . $ce . '>' . (string)($payload['html'] ?? 'Text block') . '</div>';
}

function ft_form_style_key(array $payload, string $default): string
{
    $key = trim((string)($payload['paragraph_style'] ?? $default));
    return $key !== '' ? $key : $default;
}

function ft_form_align_class(array $payload): string
{
    $align = trim((string)($payload['text_align'] ?? ''));
    return in_array($align, array('left', 'center', 'right', 'justify'), true) ? ' cpb-align-' . $align : '';
}

function ft_form_text_data_attrs(array $payload): string
{
    $attrs = '';
    foreach (array('font_family', 'text_align', 'text_color', 'regulatory_ref') as $key) {
        if (isset($payload[$key]) && (string)$payload[$key] !== '') {
            $attrs .= ' data-' . str_replace('_', '-', $key) . '="' . h((string)$payload[$key]) . '"';
        }
    }
    foreach (array('font_size', 'indent_level') as $key) {
        if (isset($payload[$key]) && (int)$payload[$key] > 0) {
            $attrs .= ' data-' . str_replace('_', '-', $key) . '="' . (int)$payload[$key] . '"';
        }
    }
    return $attrs;
}

function ft_form_text_style_attr(array $payload): string
{
    $style = '';
    if (!empty($payload['font_size'])) $style .= 'font-size:' . (int)$payload['font_size'] . 'pt;';
    if (!empty($payload['text_color'])) $style .= 'color:' . h((string)$payload['text_color']) . ';';
    if (!empty($payload['text_align'])) $style .= 'text-align:' . h((string)$payload['text_align']) . ';';
    return $style !== '' ? ' style="' . $style . '"' : '';
}

function ft_form_table_cell_attrs(string $bg, string $align, string $fontFamily, int $fontSize, string $textColor): string
{
    $attrs = '';
    $style = '';
    if ($bg !== '') {
        $attrs .= ' data-cell-bg="' . h($bg) . '"';
        $style .= 'background-color:' . h($bg) . ';';
    }
    if ($align !== '') {
        $attrs .= ' data-cell-align="' . h($align) . '"';
        $style .= 'text-align:' . h($align) . ';';
    }
    if ($fontFamily !== '') {
        $attrs .= ' data-font-family="' . h($fontFamily) . '"';
        $attrs .= ' class="cpb-font-' . h($fontFamily) . '"';
    }
    if ($fontSize > 0) {
        $attrs .= ' data-font-size="' . $fontSize . '"';
        $style .= 'font-size:' . $fontSize . 'pt;';
    }
    if ($textColor !== '') {
        $attrs .= ' data-text-color="' . h($textColor) . '"';
        $style .= 'color:' . h($textColor) . ';';
    }
    if ($style !== '') {
        $attrs .= ' style="' . $style . '"';
    }
    return $attrs;
}

function ft_form_default_page_header(array $ctx): array
{
    return array(
        'enabled' => false,
        'left_type' => 'logo',
        'logo_url' => '',
        'logo_alt' => 'EuroPilot Center',
        'logo_max_height' => 40,
        'row_height' => 32,
        'center_text' => (string)($ctx['template']['title'] ?? 'Form Template'),
        'center_font_family' => 'sans',
        'center_font_size' => 11,
        'center_font_bold' => true,
        'center_font_italic' => false,
        'center_font_underline' => false,
        'right_text' => "Version: {revision}\nDate: {date}",
        'right_font_family' => 'sans',
        'right_font_size' => 10,
        'right_font_bold' => true,
        'right_font_italic' => false,
        'right_font_underline' => false,
    );
}

function ft_form_default_page_footer(): array
{
    return array(
        'enabled' => false,
        'row_height' => 26,
        'left_text' => '',
        'center_text' => 'Controlled form template',
        'right_text' => '',
        'left_font_family' => 'sans',
        'center_font_family' => 'sans',
        'right_font_family' => 'sans',
        'left_font_size' => 9,
        'center_font_size' => 9,
        'right_font_size' => 9,
        'left_font_bold' => false,
        'center_font_bold' => false,
        'right_font_bold' => false,
        'left_font_italic' => false,
        'center_font_italic' => false,
        'right_font_italic' => false,
        'left_font_underline' => false,
        'center_font_underline' => false,
        'right_font_underline' => false,
    );
}

function ft_form_render_header_band(array $header, array $ctx, array $section): string
{
    if (empty($header['enabled'])) return '';
    $logo = trim((string)($header['logo_url'] ?? ''));
    $left = $logo !== ''
        ? '<img class="cpb-page-header-logo" src="' . h($logo) . '" alt="' . h((string)($header['logo_alt'] ?? '')) . '" style="max-height:' . max(16, min(120, (int)($header['logo_max_height'] ?? 40))) . 'px;">'
        : '<span class="cpb-page-header-logo-placeholder">Logo</span>';
    $center = nl2br(h(ft_form_resolve_header_tokens((string)($header['center_text'] ?? ''), $ctx, $section)));
    $right = nl2br(h(ft_form_resolve_header_tokens((string)($header['right_text'] ?? ''), $ctx, $section)));
    return '<table class="cpb-page-header-table" contenteditable="false"><tbody><tr>'
        . '<td class="cpb-page-header-left">' . $left . '</td>'
        . '<td class="cpb-page-header-center">' . $center . '</td>'
        . '<td class="cpb-page-header-right">' . $right . '</td>'
        . '</tr></tbody></table>';
}

function ft_form_render_footer_band(array $footer, array $ctx, array $section): string
{
    if (empty($footer['enabled'])) return '';
    $left = nl2br(h(ft_form_resolve_header_tokens((string)($footer['left_text'] ?? ''), $ctx, $section)));
    $center = nl2br(h(ft_form_resolve_header_tokens((string)($footer['center_text'] ?? ''), $ctx, $section)));
    $right = nl2br(h(ft_form_resolve_header_tokens((string)($footer['right_text'] ?? ''), $ctx, $section)));
    return '<table class="cpb-page-footer-table" contenteditable="false"><tbody><tr>'
        . '<td class="cpb-page-footer-left">' . $left . '</td>'
        . '<td class="cpb-page-footer-center">' . $center . '</td>'
        . '<td class="cpb-page-footer-right">' . $right . '</td>'
        . '</tr></tbody></table>';
}

function ft_form_resolve_header_tokens(string $text, array $ctx, array $section): string
{
    $replacements = array(
        '{book_title}' => (string)($ctx['template']['title'] ?? 'Form Template'),
        '{manual_code}' => 'FORM',
        '{part_title}' => (string)($section['title'] ?? ''),
        '{revision}' => (string)($ctx['version']['version_label'] ?? ''),
        '{date}' => date('Y-m-d'),
        '{page}' => '—',
    );
    return strtr($text, $replacements);
}

function ft_form_store_uploaded_image(string $field, string $bucket): string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        throw new RuntimeException('image file required');
    }
    $file = $_FILES[$field];
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $err . ')');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload');
    }
    $mime = (string)($file['type'] ?? '');
    $ext = match ($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed');
    }
    $dir = dirname(__DIR__, 3) . '/public/uploads/flight_training_forms/' . preg_replace('/[^a-z0-9_-]+/i', '_', $bucket);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Could not store upload');
    }
    return '/uploads/flight_training_forms/' . preg_replace('/[^a-z0-9_-]+/i', '_', $bucket) . '/' . $name;
}

function ft_form_resolve_book_styles(array $doc): array
{
    $stored = is_array($doc['book_styles'] ?? null) ? $doc['book_styles'] : array();
    return array_replace_recursive(ft_form_default_book_styles(), $stored);
}

function ft_form_default_book_styles(): array
{
    return array(
        'paragraph_styles' => array(),
        'table_styles' => array(),
        'callout_presets' => array(
            array('callout_type' => 'warning', 'title' => 'WARNING', 'text' => ''),
            array('callout_type' => 'caution', 'title' => 'CAUTION', 'text' => ''),
            array('callout_type' => 'info', 'title' => 'INFO', 'text' => ''),
            array('callout_type' => 'note', 'title' => 'NOTE', 'text' => ''),
        ),
        'callout_styles' => array(),
        'page_header' => array('enabled' => false),
        'page_footer' => array('enabled' => false),
    );
}
