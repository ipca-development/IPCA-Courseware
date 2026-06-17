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

        case 'get_callout_presets':
            ft_form_editor_json(200, array('ok' => true, 'presets' => array(
                array('callout_type' => 'warning', 'title' => 'WARNING', 'text' => ''),
                array('callout_type' => 'caution', 'title' => 'CAUTION', 'text' => ''),
                array('callout_type' => 'info', 'title' => 'INFO', 'text' => ''),
                array('callout_type' => 'note', 'title' => 'NOTE', 'text' => ''),
            )));
            break;

        case 'get_book_styles':
            ft_form_handle_get_book_styles($service, $actorUserId);
            break;

        case 'save_book_styles':
            ft_form_handle_save_book_styles($service, $actorUserId);
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
    if ($type === 'table') return array('title' => (string)($payload['title'] ?? ''), 'headers' => $payload['headers'] ?? array('Column 1', 'Column 2'), 'rows' => $payload['rows'] ?? array(array('', '')));
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
        'allow_author_blocks' => 1,
        'is_system_managed' => 0,
        'is_generated' => 0,
        'metadata_json' => '{}',
    );
}

function ft_form_load_payload(array $ctx, array $doc, array $section, string $pageHtml): array
{
    return array(
        'ok' => true,
        'version' => array('id' => (int)$ctx['version']['id'], 'book_key' => 'FORM', 'book_title' => (string)$ctx['template']['title'], 'version_label' => (string)$ctx['version']['version_label'], 'lifecycle_status' => (string)$ctx['version']['lifecycle_status']),
        'section_id' => (int)$section['id'],
        'section' => $section,
        'sections_tree' => array_map(static fn(array $s): array => array('id' => (int)$s['id'], 'title' => (string)$s['title'], 'children' => array()), $doc['sections']),
        'blocks' => array_values(array_filter($doc['blocks'], static fn(array $b): bool => (int)($b['section_id'] ?? 1) === (int)$section['id'])),
        'page_html' => $pageHtml,
        'page_layout' => array('orientation' => 'portrait'),
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
        'page_header' => array('enabled' => false),
        'page_footer' => array('enabled' => false),
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
    return '<div class="cpb-sheet" data-section-id="' . (int)$section['id'] . '"><div class="cpb-sheet-body" data-blocks-root="1">' . $html . '</div><div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div></div>';
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
    if ($type === 'heading') return '<h2 class="cpb-heading cpb-ps-subtitle_2" data-paragraph-style="subtitle_2" data-level="2"' . $ce . '>' . h((string)($payload['text'] ?? 'Heading')) . '</h2>';
    if ($type === 'table') {
        $headers = (array)($payload['headers'] ?? array('Column 1', 'Column 2'));
        $rows = (array)($payload['rows'] ?? array(array('', '')));
        $out = '<div class="cpb-table-block cpb-table-block--align-left" data-table-align="left"><div class="cpb-table-wrap cpb-table-border-medium" data-border-width="medium" data-border-color="#94a3b8"><table class="cpb-table"><thead><tr class="cpb-table-header-row">';
        foreach ($headers as $header) $out .= '<th' . $ce . '>' . h((string)$header) . '</th>';
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $out .= '<tr>';
            foreach ($headers as $idx => $_) $out .= '<td' . $ce . '>' . h((string)($row[$idx] ?? '')) . '</td>';
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div></div>';
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
    return '<div class="cpb-paragraph cpb-ps-body" data-paragraph-style="body"' . $ce . '>' . (string)($payload['html'] ?? 'Text block') . '</div>';
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
