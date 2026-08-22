<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$svc = new TheoryContentStudioService($pdo);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
try {
    $lesson = $svc->getLesson($lessonId);
} catch (TheoryStudioException $e) {
    http_response_code(404);
    cw_header('Lesson not found');
    echo '<p>Lesson not found.</p>';
    cw_footer();
    exit;
}
$slides = array_values(array_filter(
    $lesson['slides'] ?? array(),
    static fn(array $slide): bool => (int)($slide['is_deleted'] ?? 0) === 0
));
$protected = !empty($lesson['protected']);
$activeId = (int)($_GET['slide_id'] ?? 0);
$active = null;
foreach ($slides as $slide) {
    if ($activeId <= 0 || (int)$slide['id'] === $activeId) {
        $active = $slide;
        $activeId = (int)$slide['id'];
        break;
    }
}
$csrf = theory_studio_csrf_token();
$structured = null;
$structuredState = null;
$templates = array();
if (!$protected) {
    $structured = new TheoryStructuredSlideService($pdo);
    $templates = array_values(array_filter(
        $structured->listTemplates((int)$lesson['program_id']),
        static fn(array $template): bool => (int)($template['active_version_id'] ?? 0) > 0
    ));
    if (is_array($active) && (string)($active['source_category'] ?? '') === 'structured') {
        $structuredSlide = $structured->loadStructuredSlide((int)$active['id']);
        $templateVersion = $structuredSlide['template_version'];
        $placeholderById = array();
        $editorPlaceholders = array();
        foreach (($templateVersion['placeholders'] ?? array()) as $placeholder) {
            $style = json_decode((string)($placeholder['allowed_style_json'] ?? '{}'), true) ?: array();
            $behavior = json_decode((string)($placeholder['allowed_behavior_json'] ?? '{}'), true) ?: array();
            $key = (string)$placeholder['placeholder_key'];
            $placeholderById[(int)$placeholder['id']] = $key;
            $editorPlaceholders[] = array(
                'id' => (int)$placeholder['id'],
                'placeholder_key' => $key,
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
        $values = array();
        foreach ($editorPlaceholders as $placeholder) {
            $values[$placeholder['placeholder_key']] = $placeholder['type'] === 'text'
                ? array('type' => 'text', 'en_html' => '', 'es_html' => '')
                : array('type' => $placeholder['type'], 'media_asset_id' => null, 'cdn_url' => '', 'fit' => $placeholder['media_fit']);
        }
        foreach (($structuredSlide['text_values'] ?? array()) as $value) {
            $key = $placeholderById[(int)$value['placeholder_id']] ?? '';
            if ($key === '') {
                continue;
            }
            $content = json_decode((string)($value['content_json'] ?? '{}'), true) ?: array();
            $lang = strtolower((string)$value['lang']);
            $values[$key][$lang . '_html'] = (string)($content['html'] ?? $value['plain_text'] ?? '');
        }
        foreach (($structuredSlide['media_values'] ?? array()) as $value) {
            $key = $placeholderById[(int)$value['placeholder_id']] ?? '';
            if ($key === '') {
                continue;
            }
            $media = $pdo->prepare(
                'SELECT storage_key FROM ipca_training_media_library
                 WHERE id = ? AND deleted_at_utc IS NULL LIMIT 1'
            );
            $media->execute(array((int)$value['media_library_id']));
            $storageKey = (string)$media->fetchColumn();
            $content = json_decode((string)($value['content_json'] ?? '{}'), true) ?: array();
            $values[$key]['media_asset_id'] = (int)$value['media_library_id'];
            $values[$key]['cdn_url'] = $storageKey !== '' ? theory_studio_cover_url($storageKey) : '';
            $values[$key]['fit'] = (string)($content['fit'] ?? $values[$key]['fit']);
        }
        $structuredState = array(
            'slide_id' => (int)$structuredSlide['id'],
            'content_revision' => (int)$structuredSlide['content_revision'],
            'outline_node_id' => $structuredSlide['outline_node_id'],
            'template_version_id' => (int)$structuredSlide['template_version_id'],
            'placeholders' => $editorPlaceholders,
            'values' => $values,
        );
        $activeTemplate = $structured->getTemplate((int)$templateVersion['template_id']);
        $structuredState['template_name'] = (string)$activeTemplate['name'];
        $structuredState['version_number'] = (int)$templateVersion['version_number'];
    }
}

$templateChoices = array();
if (!$protected && $structured !== null) {
    foreach ($templates as $template) {
        $choiceVersion = $structured->getTemplateVersion((int)$template['active_version_id']);
        $choicePlaceholders = array();
        foreach (($choiceVersion['placeholders'] ?? array()) as $placeholder) {
            $choicePlaceholders[] = array(
                'type' => strtolower((string)$placeholder['content_type']),
                'x' => (int)$placeholder['x'],
                'y' => (int)$placeholder['y'],
                'width' => (int)$placeholder['w'],
                'height' => (int)$placeholder['h'],
            );
        }
        $templateChoices[] = array(
            'name' => (string)$template['name'],
            'version_id' => (int)$choiceVersion['id'],
            'version_number' => (int)$choiceVersion['version_number'],
            'placeholders' => $choicePlaceholders,
        );
    }
}

$slideTitles = array();
if (!$protected && $slides) {
    $titleStmt = $pdo->prepare(
        "SELECT s.id, COALESCE(sc.plain_text, '') AS plain_text
         FROM slides s
         LEFT JOIN slide_content sc ON sc.slide_id = s.id AND sc.lang = 'en'
         WHERE s.lesson_id = ? AND COALESCE(s.is_deleted, 0) = 0"
    );
    $titleStmt->execute(array($lessonId));
    foreach ($titleStmt->fetchAll() ?: array() as $row) {
        $plain = trim((string)$row['plain_text']);
        $slideTitles[(int)$row['id']] = $plain !== ''
            ? mb_substr((string)(preg_split('/\R/u', $plain)[0] ?? $plain), 0, 50)
            : 'Structured Slide';
    }
}

if (!$protected) {
    $structuredState ??= array(
        'slide_id' => 0,
        'content_revision' => 0,
        'outline_node_id' => null,
        'template_version_id' => 0,
        'template_name' => 'No slide selected',
        'version_number' => 0,
        'placeholders' => array(),
        'values' => array(),
        'readonly' => true,
    );
    $structuredState['lesson_id'] = $lessonId;
    $structuredState['breadcrumbs'] = array(
        array('label' => (string)$lesson['program_name'], 'href' => '/admin/theory_studio/courses.php?program_id=' . (int)$lesson['program_id']),
        array('label' => (string)$lesson['course_title'], 'href' => '/admin/theory_studio/lessons.php?course_id=' . (int)$lesson['course_id']),
        array('label' => (string)$lesson['title'], 'href' => null),
        array('label' => $active ? 'Slide ' . (int)$active['page_number'] : 'No slide', 'href' => null),
    );
    $structuredState['navigator_items'] = array_map(
        static fn(array $slide): array => array(
            'id' => (int)$slide['id'],
            'number' => (string)(int)$slide['page_number'],
            'title' => (string)($slideTitles[(int)$slide['id']] ?? 'Structured Slide'),
            'active' => (int)$slide['id'] === $activeId,
            'href' => '/admin/theory_studio/lesson_editor.php?lesson_id=' . $lessonId
                . '&slide_id=' . (int)$slide['id'],
        ),
        $slides
    );
    $structuredState['template_choices'] = $templateChoices;
}

cw_header((string)$lesson['title'] . ' · Lesson Editor');
theory_studio_emit_assets();
if (!$protected) {
    theory_structured_editor_emit_assets();
}
echo '<meta name="theory-studio-csrf" content="' . h($csrf) . '">';

if (!$protected) {
    echo TheoryStructuredEditorUi::editor('slide', $structuredState);
    cw_footer();
    exit;
}

compliance_page_open(array(
    'overline' => 'Lesson Editor',
    'title' => (string)$lesson['title'],
    'description' => (string)$lesson['program_name'] . ' → ' . (string)$lesson['course_title'] . ' · ' . count($slides) . ' slides',
));

echo TheoryStudioUi::breadcrumb(array(
    array('label' => 'Programs', 'href' => '/admin/theory_studio/programs.php'),
    array('label' => (string)$lesson['program_name'], 'href' => '/admin/theory_studio/courses.php?program_id=' . (int)$lesson['program_id']),
    array('label' => (string)$lesson['course_title'], 'href' => '/admin/theory_studio/lessons.php?course_id=' . (int)$lesson['course_id']),
    array('label' => (string)$lesson['title'], 'href' => null),
));
if ($protected) {
    echo TheoryStudioUi::liveBannerHtml();
}
?>
<div class="ts-page">
  <div class="ts-editor">
    <aside class="ts-rail" aria-label="Slides"<?= !$protected ? ' data-ts-reorder="reorder_structured_slides" data-parent-id="' . (int)$lessonId . '"' : '' ?>>
      <?php foreach ($slides as $slide): ?>
        <?php
          $thumb = theory_studio_cover_url((string)($slide['image_path'] ?? ''));
          $href = '/admin/theory_studio/lesson_editor.php?lesson_id=' . (int)$lessonId . '&slide_id=' . (int)$slide['id'];
        ?>
        <a class="ts-rail-item <?= (int)$slide['id'] === $activeId ? 'is-active' : '' ?>"
           href="<?= h($href) ?>"<?= !$protected ? ' data-id="' . (int)$slide['id'] . '" draggable="true"' : '' ?>>
          <?php if ($thumb !== ''): ?>
            <img src="<?= h($thumb) ?>" alt="Slide <?= (int)$slide['page_number'] ?>">
          <?php else: ?>
            <span class="ts-rail-placeholder">Slide <?= (int)$slide['page_number'] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$slides): ?>
        <p class="ts-meta">No slides yet.</p>
      <?php endif; ?>
      <?php if ($protected): ?>
        <p class="ts-disabled-note">Live screenshot lessons are inspect-only in Studio. Overlay edits remain on the legacy Slides page.</p>
      <?php else: ?>
        <?php if ($templates): ?>
          <form data-ts-form="create_structured_slide">
            <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">
            <label class="tse-field">Template
              <select name="template_version_id" required>
                <?php foreach ($templates as $template): ?>
                  <option value="<?= (int)$template['active_version_id'] ?>"><?= h((string)$template['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="app-btn app-btn-secondary">Add Structured Slide</button>
          </form>
        <?php else: ?>
          <a class="app-btn app-btn-secondary" href="/admin/theory_studio/templates.php?program_id=<?= (int)$lesson['program_id'] ?>">Create a Template</a>
        <?php endif; ?>
      <?php endif; ?>
    </aside>
    <section class="ts-workspace">
      <?php if ($protected && $active && trim((string)($active['image_path'] ?? '')) !== ''): ?>
        <div class="ts-slide-viewport" aria-label="Slide <?= (int)$active['page_number'] ?> preview">
          <div class="ts-slide-stage">
            <img
              class="ts-slide-content"
              src="<?= h(theory_studio_cover_url((string)$active['image_path'])) ?>"
              alt="Slide <?= (int)$active['page_number'] ?>"
            >
            <img class="ts-slide-header" src="/assets/overlay/header.png" alt="">
            <img class="ts-slide-footer" src="/assets/overlay/footer.png" alt="">
          </div>
        </div>
      <?php elseif ($protected): ?>
        <div class="ts-workspace-copy">This Live lesson has no screenshot for the selected slide.</div>
      <?php elseif ($structuredState !== null): ?>
        <?= TheoryStructuredEditorUi::editor('slide', $structuredState) ?>
        <form data-ts-form="delete_structured_slide" onsubmit="return confirm('Delete this Draft Slide?');">
          <input type="hidden" name="slide_id" value="<?= (int)$structuredState['slide_id'] ?>">
          <input type="hidden" name="content_revision" value="<?= (int)$structuredState['content_revision'] ?>">
          <button type="submit" class="app-btn app-btn-danger">Delete Draft Slide</button>
        </form>
      <?php else: ?>
        <div class="ts-workspace-copy">
          <p>Choose a Template and add a native Structured Slide. Text is projected directly into canonical EN content; images and video use managed media assets.</p>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php
compliance_page_close();
cw_footer();
