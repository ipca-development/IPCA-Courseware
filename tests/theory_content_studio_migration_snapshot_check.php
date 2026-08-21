<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/theory_studio/TheoryStudioIsolation.php';
require_once $root . '/src/theory_studio/TheoryHierarchySnapshot.php';
require_once $root . '/tests/helpers/theory_studio_fixture.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
theory_studio_reset_schema_cache();

$pdo->exec("CREATE TABLE programs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    program_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    program_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    revision TEXT NOT NULL DEFAULT '1.0',
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_published INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE lessons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id INTEGER NOT NULL,
    external_lesson_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    page_count INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE slides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lesson_id INTEGER NOT NULL,
    page_number INTEGER NOT NULL,
    image_path TEXT NULL,
    is_deleted INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE cohorts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    program_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    name TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE cohort_courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cohort_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL
)");
$pdo->exec("CREATE TABLE cohort_lesson_scope (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cohort_id INTEGER NOT NULL,
    lesson_id INTEGER NOT NULL
)");
$pdo->exec("CREATE TABLE cohort_lesson_deadlines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cohort_id INTEGER NOT NULL,
    lesson_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("INSERT INTO programs (id, program_key, name, sort_order) VALUES (1, 'private', 'Private Pilot', 10)");
$pdo->exec("INSERT INTO courses (id, program_id, title, slug, revision, sort_order) VALUES (10, 1, 'Ground', 'ground', '1.0', 10)");
$pdo->exec("INSERT INTO lessons (id, course_id, external_lesson_id, title, sort_order) VALUES (100, 10, 501, 'Aero', 10)");
$pdo->exec("INSERT INTO slides (id, lesson_id, page_number, image_path) VALUES (1000, 100, 1, 'ks_images/Private/501/1.jpg')");
$pdo->exec("INSERT INTO cohorts (id, program_id, course_id, name) VALUES (50, 1, 10, 'Fall')");
$pdo->exec("INSERT INTO cohort_courses (cohort_id, course_id) VALUES (50, 10)");
$pdo->exec("INSERT INTO cohort_lesson_scope (cohort_id, lesson_id) VALUES (50, 100)");
$pdo->exec("INSERT INTO cohort_lesson_deadlines (cohort_id, lesson_id, sort_order) VALUES (50, 100, 10)");

$snap = new TheoryHierarchySnapshot($pdo);
$before = $snap->capture();

$pdo->exec("ALTER TABLE programs ADD COLUMN authoring_origin TEXT NOT NULL DEFAULT 'operational'");
$pdo->exec("ALTER TABLE programs ADD COLUMN cover_image_path TEXT NULL");
$pdo->exec("ALTER TABLE courses ADD COLUMN cover_image_path TEXT NULL");
$pdo->exec("ALTER TABLE courses ADD COLUMN revision_date TEXT NULL");
$pdo->exec("CREATE TABLE theory_program_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    program_id INTEGER NOT NULL,
    revision_number TEXT NOT NULL,
    revision_date TEXT NULL,
    status TEXT NOT NULL,
    origin TEXT NOT NULL,
    cover_image_path TEXT NULL
)");
$pdo->exec("INSERT INTO theory_program_revisions (program_id, revision_number, status, origin)
            SELECT id, '1.0', 'live', 'legacy' FROM programs");

$after = $snap->capture();
if (!TheoryHierarchySnapshot::operationalEqual($before, $after)) {
    fwrite(STDERR, "FAIL: additive migration changed operational snapshot\n");
    echo json_encode(array('before' => $before, 'after' => $after), JSON_PRETTY_PRINT), "\n";
    exit(1);
}

echo "Theory Content Studio migration snapshot: PASS\n";
