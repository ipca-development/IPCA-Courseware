<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$templateId = (int)($_GET['template_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);
$structured = new TheoryStructuredSlideService($pdo);

try {
    $template = $structured->getTemplate($templateId);
    $versionId = (int)($_GET['version_id'] ?? $template['active_version_id'] ?? 0);
    $version = $versionId > 0 ? $structured->getTemplateVersion($versionId) : array('placeholders' => array());
} catch (TheoryStudioException $e) {
    http_response_code($e->httpStatus);
    cw_header('Template not found');
    echo '<p>' . h($e->getMessage()) . '</p>';
    cw_footer();
    exit;
}

$placeholders = array();
foreach (($version['placeholders'] ?? array()) as $placeholder) {
    $style = json_decode((string)($placeholder['allowed_style_json'] ?? '{}'), true) ?: array();
    $behavior = json_decode((string)($placeholder['allowed_behavior_json'] ?? '{}'), true) ?: array();
    $placeholders[] = array(
        'id' => (int)$placeholder['id'],
        'placeholder_key' => (string)$placeholder['placeholder_key'],
        'type' => strtolower((string)$placeholder['content_type']),
        'semantic_role' => (string)$placeholder['semantic_role'],
        'x' => (int)$placeholder['x'],
        'y' => (int)$placeholder['y'],
        'width' => (int)$placeholder['w'],
        'height' => (int)$placeholder['h'],
        'reading_order' => (int)$placeholder['reading_order'],
        'required' => (int)$placeholder['is_required'] === 1,
        'font_family' => (string)($style['font_family'] ?? 'Arial, Helvetica, sans-serif'),
        'font_size' => (int)($style['font_size'] ?? 18),
        'font_weight' => (int)($style['font_weight'] ?? 400),
        'text_color' => (string)($style['text_color'] ?? '#0f172a'),
        'line_height' => (float)($style['line_height'] ?? 1.35),
        'alignment' => (string)($style['alignment'] ?? 'left'),
        'vertical_alignment' => (string)($style['vertical_alignment'] ?? 'top'),
        'background_color' => (string)($style['background_color'] ?? 'transparent'),
        'border_color' => (string)($style['border_color'] ?? 'transparent'),
        'border_width' => (int)($style['border_width'] ?? 0),
        'padding' => (int)($style['padding'] ?? 8),
        'overflow_behavior' => (string)($behavior['overflow'] ?? 'auto'),
        'media_fit' => (string)($behavior['media_fit'] ?? 'contain'),
        'author_can_resize' => !empty($behavior['author_can_resize']),
        'author_can_reposition' => !empty($behavior['author_can_reposition']),
        'locked' => !empty($behavior['locked']),
        'z_index' => (int)($behavior['z_index'] ?? 1),
    );
}

$readonly = !empty($template['is_system']) || $programId <= 0;
$state = array(
    'template_id' => (int)$template['id'],
    'version_id' => (int)($version['id'] ?? 0),
    'version_number' => (int)($version['version_number'] ?? 0),
    'name' => (string)$template['name'],
    'template_name' => (string)$template['name'],
    'program_id' => $programId,
    'placeholders' => $placeholders,
    'guides' => array_map(
        static fn(array $guide): array => array(
            'id' => (int)$guide['id'],
            'orientation' => (string)$guide['orientation'],
            'position' => (int)$guide['position'],
            'is_locked' => (int)($guide['is_locked'] ?? 0) === 1,
        ),
        $version['guides'] ?? array()
    ),
    'breadcrumbs' => array(
        array(
            'label' => 'Templates',
            'href' => '/admin/theory_studio/templates.php' . ($programId > 0 ? '?program_id=' . $programId : ''),
        ),
        array('label' => (string)$template['name'], 'href' => null),
        array(
            'label' => 'v' . (int)($version['version_number'] ?? 0),
            'href' => null,
        ),
    ),
    'navigator_items' => array_map(
        static fn(array $item): array => array(
            'id' => (int)$item['id'],
            'number' => 'v' . (int)$item['version_number'],
            'title' => 'Version ' . (int)$item['version_number'],
            'active' => (int)$item['id'] === (int)($version['id'] ?? 0),
            'href' => '/admin/theory_studio/template_editor.php?template_id=' . (int)$template['id']
                . '&program_id=' . $programId . '&version_id=' . (int)$item['id'],
        ),
        $template['versions'] ?? array()
    ),
    'readonly' => $readonly,
);
$csrf = theory_studio_csrf_token();

cw_header((string)$template['name'] . ' · Template Editor');
theory_studio_emit_assets();
theory_structured_editor_emit_assets();
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';
echo TheoryStructuredEditorUi::editor('template', $state);
cw_footer();
