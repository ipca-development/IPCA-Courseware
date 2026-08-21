<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/theory_studio/TheoryStudioIsolation.php';
require_once $root . '/src/theory_studio/TheoryContentStudioService.php';
require_once $root . '/src/theory_studio/TheoryHierarchySnapshot.php';
require_once $root . '/tests/helpers/theory_studio_fixture.php';

$pdo = theory_studio_test_pdo();
$live = theory_studio_seed_live($pdo);
$beforeReads = theory_studio_operational_reads($pdo);
$snap = new TheoryHierarchySnapshot($pdo);
$beforeSnap = $snap->capture();

$svc = new TheoryContentStudioService($pdo);
$program = $svc->createProgram(array(
    'name' => 'Commercial Pilot',
    'program_key' => 'commercial_pilot',
    'revision_number' => '0.1',
));
if ((int)$program['id'] === 1 || ($program['authoring_origin'] ?? '') !== 'studio') {
    fwrite(STDERR, "FAIL: draft program was not isolated\n");
    exit(1);
}
$course = $svc->createCourse((int)$program['id'], array('title' => 'Commercial Ground', 'slug' => 'cg'));
$lessons = $svc->createLessons((int)$course['id'], array('titles' => "Lesson A\nLesson B"));
if (count($lessons) !== 2) {
    fwrite(STDERR, "FAIL: expected two draft lessons\n");
    exit(1);
}

$slideCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM slides')->fetchColumn();
try {
    $svc->addSlide((int)$lessons[0]['id']);
    fwrite(STDERR, "FAIL: add slide on draft was allowed\n");
    exit(1);
} catch (TheoryStudioException $e) {
    if ($e->errorCode !== 'STRUCTURED_SLIDES_NOT_ENABLED') {
        fwrite(STDERR, "FAIL: add slide returned {$e->errorCode}\n");
        exit(1);
    }
}
$slideCountAfter = (int)$pdo->query('SELECT COUNT(*) FROM slides')->fetchColumn();
if ($slideCountAfter !== $slideCountBefore) {
    fwrite(STDERR, "FAIL: add slide inserted a slides row\n");
    exit(1);
}

$afterLive = theory_studio_live_fingerprint($pdo);
if ($afterLive !== $live) {
    fwrite(STDERR, "FAIL: creating a draft mutated live rows\n");
    exit(1);
}

$afterReads = theory_studio_operational_reads($pdo);
if ($afterReads !== $beforeReads) {
    fwrite(STDERR, "FAIL: operational reads changed after draft create\n");
    echo json_encode(array('before' => $beforeReads, 'after' => $afterReads), JSON_PRETTY_PRINT), "\n";
    exit(1);
}

$draftId = (int)$program['id'];
foreach (array('operational_programs', 'bulk_enrich_programs', 'communication_programs') as $key) {
    $ids = array_map('intval', array_column($afterReads[$key], 'id') ?: (array)$afterReads[$key]);
    if ($key === 'operational_programs') {
        $ids = array_map('intval', array_column($afterReads[$key], 'id'));
    }
    if (in_array($draftId, $ids, true)) {
        fwrite(STDERR, "FAIL: draft program id leaked into {$key}\n");
        exit(1);
    }
}
if (in_array((int)$course['id'], array_map('intval', (array)$afterReads['written_test_courses']), true)) {
    fwrite(STDERR, "FAIL: draft course leaked into written-test selector\n");
    exit(1);
}
foreach ($lessons as $lesson) {
    if (in_array((int)$lesson['id'], array_map('intval', (array)$afterReads['student_deadline_lessons']), true)) {
        fwrite(STDERR, "FAIL: draft lesson leaked into student deadlines\n");
        exit(1);
    }
}

$cohortsBefore = (int)$pdo->query('SELECT COUNT(*) FROM cohorts')->fetchColumn();
try {
    theory_studio_require_operational_program($pdo, $draftId);
    fwrite(STDERR, "FAIL: cohort assignment of draft was allowed\n");
    exit(1);
} catch (TheoryStudioException $e) {
    if ($e->errorCode !== 'STUDIO_DRAFT_NOT_OPERATIONAL') {
        fwrite(STDERR, "FAIL: expected STUDIO_DRAFT_NOT_OPERATIONAL, got {$e->errorCode}\n");
        exit(1);
    }
}
if ((int)$pdo->query('SELECT COUNT(*) FROM cohorts')->fetchColumn() !== $cohortsBefore) {
    fwrite(STDERR, "FAIL: rejected cohort assignment still inserted a cohort\n");
    exit(1);
}

$afterSnap = $snap->capture();
if ((int)$afterSnap['programs'] !== (int)$beforeSnap['programs'] + 1) {
    fwrite(STDERR, "FAIL: expected exactly one new program row\n");
    exit(1);
}
if ((int)$afterSnap['slides_active'] !== (int)$beforeSnap['slides_active']) {
    fwrite(STDERR, "FAIL: slide count changed\n");
    exit(1);
}

echo "PASS: draft create does not leak into operational reads\n";
echo "PASS: STUDIO_DRAFT_NOT_OPERATIONAL rejects cohort assignment\n";
echo "PASS: Add Slide does not insert slides rows\n";
echo "Theory Content Studio draft leakage: PASS\n";
