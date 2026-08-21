<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/theory_studio/TheoryStudioIsolation.php';
require_once $root . '/src/theory_studio/TheoryLessonEnhancementStatusService.php';
require_once $root . '/tests/helpers/theory_studio_fixture.php';

$pdo = theory_studio_test_pdo();
theory_studio_seed_live($pdo);
$svc = new TheoryLessonEnhancementStatusService($pdo);
$status = $svc->forLesson(100);

$labels = array();
foreach ($status['chips'] as $chip) {
    $labels[$chip['key']] = $chip;
    if (preg_match('/[a-f0-9]{20,}/i', (string)$chip['display']) || str_contains(strtolower((string)$chip['display']), 'hash')) {
        fwrite(STDERR, "FAIL: chip leaked a hash: {$chip['display']}\n");
        exit(1);
    }
}

$expect = array(
    'content' => 'ok',
    'translation' => 'ok',
    'narration' => 'ok',
    'references' => 'ok',
    'questions' => 'ok',
    'maya' => 'ok',
);
foreach ($expect as $key => $tone) {
    if (($labels[$key]['tone'] ?? '') !== $tone) {
        fwrite(STDERR, "FAIL: {$key} tone " . ($labels[$key]['tone'] ?? 'missing') . " !== {$tone}\n");
        exit(1);
    }
}
if (($labels['video']['tone'] ?? '') === '') {
    fwrite(STDERR, "FAIL: video chip missing\n");
    exit(1);
}

$src = (string)file_get_contents($root . '/src/theory_studio/TheoryLessonEnhancementStatusService.php');
if (!str_contains($src, "'lang'") || !str_contains($src, 'narration_en')) {
    fwrite(STDERR, "FAIL: enhancement status must read live lang / narration_en columns\n");
    exit(1);
}
if (preg_match("/SELECT locale, plain_text FROM slide_content/", $src)) {
    fwrite(STDERR, "FAIL: slide_content query still requires locale (production column is lang)\n");
    exit(1);
}

echo "Theory Content Studio enhancement status: PASS\n";
