<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/theory_studio/TheoryStudioIsolation.php';
require_once $root . '/src/theory_studio/TheoryStructuredSlideService.php';
require_once $root . '/tests/helpers/theory_studio_fixture.php';

function structured_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$migration = (string)file_get_contents(
    $root . '/scripts/sql/2026_08_22_theory_content_studio_structured_slides.sql'
);
foreach (array(
    "DEFAULT ''legacy_screenshot''",
    'theory_slide_templates',
    'theory_slide_template_versions',
    'theory_slide_template_placeholders',
    'theory_slide_template_guides',
    'theory_course_outline_nodes',
    'theory_structured_slides',
    'theory_structured_slide_text_values',
    'theory_structured_slide_media_values',
    'ipca_training_media_library',
) as $needle) {
    structured_assert(str_contains($migration, $needle), "migration missing {$needle}");
}
structured_assert(!preg_match('/\bDROP\s+(?:TABLE|COLUMN)\b/i', $migration), 'migration is not additive');

$pdo = theory_studio_test_pdo();
theory_studio_seed_live($pdo);
$studio = new TheoryContentStudioService($pdo);
$service = new TheoryStructuredSlideService($pdo);

$system = $service->seedSystemTemplate();
structured_assert((int)$system['is_system'] === 1 && (int)$system['active_version_id'] > 0, 'system seed failed');
$sameSystem = $service->seedSystemTemplate();
structured_assert((int)$sameSystem['id'] === (int)$system['id'], 'system seed is not idempotent');

$program = $studio->createProgram(array(
    'name' => 'Structured Draft',
    'program_key' => 'structured_draft',
    'revision_number' => '0.1',
));
$course = $studio->createCourse((int)$program['id'], array('title' => 'Course', 'slug' => 'course'));
$lessons = $studio->createLessons((int)$course['id'], array('titles' => "Lesson One\nLesson Two"));
$lessonId = (int)$lessons[0]['id'];

$template = $service->createTemplate((int)$program['id'], array(
    'template_key' => 'MEDIA_AND_TEXT',
    'name' => 'Media and text',
));
$version = $service->createTemplateVersion((int)$template['id'], (int)$program['id'], array(
    'created_by_user_id' => 9,
    'guides' => array(
        array('orientation' => 'vertical', 'position' => 800, 'is_locked' => true),
        array('orientation' => 'horizontal', 'position' => 450),
    ),
    'placeholders' => array(
        array(
            'placeholder_key' => 'body',
            'content_type' => 'Text',
            'semantic_role' => 'body',
            'x' => 820, 'y' => 200, 'w' => 660, 'h' => 580,
            'reading_order' => 20,
            'allowed_content' => array('max_length' => 1200),
            'allowed_style' => array(
                'font_family' => 'Arial, Helvetica, sans-serif',
                'font_size' => 24,
                'font_weight' => 700,
                'text_color' => '#123456',
                'line_height' => 1.4,
                'alignment' => 'left',
                'vertical_alignment' => 'top',
            ),
            'allowed_behavior' => array(),
        ),
        array(
            'placeholder_key' => 'title',
            'content_type' => 'Text',
            'semantic_role' => 'heading',
            'x' => 120, 'y' => 70, 'w' => 1360, 'h' => 110,
            'reading_order' => 10,
            'required' => true,
            'allowed_content' => array('max_length' => 120),
        ),
        array(
            'placeholder_key' => 'hero',
            'content_type' => 'Image',
            'semantic_role' => 'illustration',
            'x' => 120, 'y' => 200, 'w' => 620, 'h' => 580,
            'reading_order' => 30,
        ),
        array(
            'placeholder_key' => 'clip',
            'content_type' => 'Video',
            'semantic_role' => 'demonstration',
            'x' => 120, 'y' => 200, 'w' => 620, 'h' => 580,
            'reading_order' => 40,
        ),
    ),
));
$service->activateTemplateVersion((int)$template['id'], (int)$version['id'], (int)$program['id']);
structured_assert(
    (int)$version['canvas_width'] === 1600 && (int)$version['canvas_height'] === 900,
    'template canvas is not 1600x900'
);
structured_assert(
    count($version['guides']) === 2
    && (int)$version['guides'][0]['position'] === 450
    && (int)$version['guides'][1]['position'] === 800,
    'versioned horizontal and vertical guides were not stored'
);
structured_assert((int)$version['guides'][1]['is_locked'] === 1, 'guide lock state was not stored');
$bodyStyle = json_decode((string)$version['placeholders'][1]['allowed_style_json'], true);
structured_assert(
    ($bodyStyle['font_size'] ?? 0) === 24
    && ($bodyStyle['font_weight'] ?? 0) === 700
    && ($bodyStyle['text_color'] ?? '') === '#123456',
    'Template Text Box style was not stored'
);

$versionSnapshot = json_encode($service->getTemplateVersion((int)$version['id']));
$version2 = $service->createTemplateVersion((int)$template['id'], (int)$program['id'], array(
    'placeholders' => array(array(
        'placeholder_key' => 'title',
        'content_type' => 'Text',
        'semantic_role' => 'heading',
        'x' => 100, 'y' => 100, 'w' => 1400, 'h' => 700,
        'reading_order' => 10,
    )),
));
structured_assert(
    json_encode($service->getTemplateVersion((int)$version['id'])) === $versionSnapshot
    && (int)$version2['version_number'] === 2,
    'creating a version mutated the prior version'
);

$outline = $service->createOutlineNode((int)$course['id'], array('title' => 'Aerodynamics'));
$pdo->exec("INSERT INTO ipca_training_media_library
    (id, asset_uuid, storage_key, original_filename, mime_type, created_by_user_id)
    VALUES (7, '00000000-0000-0000-0000-000000000007', 'theory/hero.jpg', 'hero.jpg', 'image/jpeg', 9)");

$slide = $service->createStructuredSlide($lessonId, (int)$version['id'], (int)$outline['id']);
structured_assert($slide['source_category'] === 'structured', 'source category was not structured');
structured_assert($slide['image_path'] === '', 'structured slide image_path was not empty');
structured_assert((int)$slide['content_revision'] === 1, 'initial revision was not one');

$saved = $service->saveStructuredSlide((int)$slide['id'], 1, array(
    'text_values' => array(
        'body' => array(
            'en' => 'Second in reading order',
            'es' => 'Segundo en orden de lectura',
        ),
        'title' => array(
            'en' => 'First in reading order',
            'es' => 'Primero en orden de lectura',
        ),
    ),
    'media_values' => array(
        'hero' => array('media_library_id' => 7, 'content_json' => array('fit' => 'cover')),
    ),
));
structured_assert((int)$saved['content_revision'] === 2, 'save did not increment content revision');
structured_assert(count($saved['text_values']) === 4, 'localized text values were not stored separately');
structured_assert(count($saved['media_values']) === 1, 'language-neutral media value was not stored');

$projection = $pdo->query(
    "SELECT plain_text, content_json FROM slide_content
     WHERE slide_id = " . (int)$slide['id'] . " AND lang = 'en'"
)->fetch();
structured_assert(
    $projection['plain_text'] === "First in reading order\n\nSecond in reading order",
    'English projection did not follow template reading order'
);
$projectionJson = json_decode((string)$projection['content_json'], true);
structured_assert(
    ($projectionJson['placeholders'][0]['placeholder_key'] ?? '') === 'title'
    && ($projectionJson['placeholders'][1]['placeholder_key'] ?? '') === 'body',
    'content_json projection order was not deterministic'
);

try {
    $service->saveStructuredSlide((int)$slide['id'], 1, array('text_values' => array()));
    structured_assert(false, 'stale save was accepted');
} catch (TheoryStudioException $e) {
    structured_assert($e->errorCode === 'CONTENT_REVISION_CONFLICT', 'stale save returned wrong error');
}

$slide2 = $service->createStructuredSlide($lessonId, (int)$version['id']);
$service->reorderStructuredSlides($lessonId, array((int)$slide2['id'], (int)$slide['id']));
$ordered = $pdo->query(
    'SELECT id FROM slides WHERE lesson_id = ' . $lessonId . ' AND is_deleted = 0 ORDER BY page_number'
)->fetchAll(PDO::FETCH_COLUMN);
structured_assert(array_map('intval', $ordered) === array((int)$slide2['id'], (int)$slide['id']), 'reorder failed');

$service->softDeleteStructuredSlide((int)$slide2['id'], 1);
structured_assert(
    (int)$pdo->query('SELECT is_deleted FROM slides WHERE id = ' . (int)$slide2['id'])->fetchColumn() === 1,
    'soft delete failed'
);

try {
    $service->createStructuredSlide(100, (int)$system['active_version_id']);
    structured_assert(false, 'structured slide was created on Live content');
} catch (TheoryStudioException $e) {
    structured_assert($e->errorCode === 'LIVE_CONTENT_PROTECTED', 'Live mutation returned wrong error');
}
try {
    $service->saveStructuredSlide(1000, 1, array());
    structured_assert(false, 'Live legacy slide mutation was accepted');
} catch (TheoryStudioException $e) {
    structured_assert($e->errorCode === 'LIVE_CONTENT_PROTECTED', 'Live slide mutation did not resolve ancestry first');
}

$legacy = $pdo->query('SELECT source_category FROM slides WHERE id = 1000')->fetchColumn();
structured_assert($legacy === 'legacy_screenshot', 'legacy slide default classification changed');

echo "PASS: immutable templates and 1600x900 placeholder definitions\n";
echo "PASS: localized text, managed media, projection, revision and ordering\n";
echo "PASS: structured-only mutations reject Live content\n";
echo "Theory Content Studio structured slide persistence: PASS\n";
