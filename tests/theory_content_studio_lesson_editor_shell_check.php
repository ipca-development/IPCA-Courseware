<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$editor = (string)file_get_contents($root . '/public/admin/theory_studio/lesson_editor.php');
$api = (string)file_get_contents($root . '/public/admin/theory_studio/api.php');
$banner = (string)file_get_contents($root . '/src/theory_studio/TheoryContentStudioService.php');

$checks = array(
    'Add Slide is disabled in the editor shell' =>
        str_contains($editor, 'disabled>Add Slide</button>'),
    'Import Slides is disabled' =>
        str_contains($editor, 'disabled>Import Slides</button>'),
    'Phase 2 copy is present' =>
        str_contains($editor, 'Structured Slide Editor coming in Phase 2'),
    'Live banner copy is exact' =>
        str_contains($banner, 'This revision is Live and currently in use. Theory Content Studio Phase 1 provides read-only access to existing Live training content. Create Draft from Live will be introduced with revision isolation.'),
    'API add_slide goes through the service' =>
        str_contains($api, "action === 'add_slide'") && str_contains($api, 'addSlide'),
);

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
echo "Theory Content Studio lesson editor shell: PASS\n";
