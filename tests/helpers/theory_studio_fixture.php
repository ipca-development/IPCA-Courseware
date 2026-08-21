<?php
declare(strict_types=1);

function theory_studio_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    theory_studio_reset_schema_cache();
    theory_studio_install_schema($pdo);
    return $pdo;
}

function theory_studio_install_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE programs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_key TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        authoring_origin TEXT NOT NULL DEFAULT 'operational',
        cover_image_path TEXT NULL
    )");
    $pdo->exec("CREATE TABLE courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        slug TEXT NOT NULL,
        revision TEXT NOT NULL DEFAULT '1.0',
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_published INTEGER NOT NULL DEFAULT 0,
        cover_image_path TEXT NULL,
        revision_date TEXT NULL,
        UNIQUE (program_id, slug)
    )");
    $pdo->exec("CREATE TABLE lessons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id INTEGER NOT NULL,
        external_lesson_id INTEGER NULL,
        title TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        page_count INTEGER NOT NULL DEFAULT 0,
        default_template_key TEXT NULL
    )");
    $pdo->exec("CREATE TABLE slides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id INTEGER NOT NULL,
        page_number INTEGER NOT NULL,
        template_key TEXT NULL,
        image_path TEXT NULL,
        is_deleted INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE slide_content (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slide_id INTEGER NOT NULL,
        lang TEXT NOT NULL,
        plain_text TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE slide_enrichment (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slide_id INTEGER NOT NULL,
        narration_en TEXT NOT NULL DEFAULT '',
        narration_es TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE slide_references (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slide_id INTEGER NOT NULL,
        ref_type TEXT NOT NULL DEFAULT 'PHAK'
    )");
    $pdo->exec("CREATE TABLE slide_hotspots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slide_id INTEGER NOT NULL,
        is_deleted INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE progress_test_lesson_banks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id INTEGER NOT NULL UNIQUE,
        content_fingerprint TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'building'
    )");
    $pdo->exec("CREATE TABLE lesson_summary_blueprints (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id INTEGER NOT NULL UNIQUE,
        current_status TEXT NOT NULL DEFAULT 'missing'
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
        course_id INTEGER NOT NULL,
        is_enabled INTEGER NOT NULL DEFAULT 1
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
    $pdo->exec("CREATE TABLE lesson_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        cohort_id INTEGER NOT NULL,
        lesson_id INTEGER NOT NULL,
        completion_status TEXT NOT NULL DEFAULT 'not_started'
    )");
    $pdo->exec("CREATE TABLE theory_program_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL,
        revision_number TEXT NOT NULL,
        revision_date TEXT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        origin TEXT NOT NULL DEFAULT 'studio',
        cover_image_path TEXT NULL
    )");
}

/**
 * @return array<string,mixed>
 */
function theory_studio_seed_live(PDO $pdo): array
{
    $pdo->exec("INSERT INTO programs (id, program_key, name, sort_order, authoring_origin)
                VALUES (1, 'private', 'Private Pilot', 10, 'operational')");
    $pdo->exec("INSERT INTO theory_program_revisions (program_id, revision_number, status, origin)
                VALUES (1, '1.0', 'live', 'legacy')");
    $pdo->exec("INSERT INTO courses (id, program_id, title, slug, revision, sort_order, is_published)
                VALUES (10, 1, 'Private Pilot Ground', 'pp-ground', '1.0', 10, 1)");
    $pdo->exec("INSERT INTO lessons (id, course_id, external_lesson_id, title, sort_order, page_count)
                VALUES (100, 10, 501, 'Aerodynamics', 10, 1)");
    $pdo->exec("INSERT INTO slides (id, lesson_id, page_number, image_path, is_deleted)
                VALUES (1000, 100, 1, 'ks_images/Private/501/1.jpg', 0)");
    $pdo->exec("INSERT INTO slide_content (slide_id, lang, plain_text)
                VALUES (1000, 'en', 'English content that is long enough for coverage'),
                       (1000, 'es', 'Texto en espanol suficiente')");
    $pdo->exec("INSERT INTO slide_enrichment (slide_id, narration_en, narration_es)
                VALUES (1000, 'Narration english text that is definitely long enough here', 'Narracion en espanol')");
    $pdo->exec("INSERT INTO slide_references (slide_id, ref_type) VALUES (1000, 'PHAK')");
    $pdo->exec("INSERT INTO slide_hotspots (slide_id, is_deleted) VALUES (1000, 0)");
    $pdo->exec("INSERT INTO progress_test_lesson_banks (lesson_id, content_fingerprint, status)
                VALUES (100, 'abc123', 'ready')");
    $pdo->exec("INSERT INTO lesson_summary_blueprints (lesson_id, current_status)
                VALUES (100, 'active')");
    $pdo->exec("INSERT INTO cohorts (id, program_id, course_id, name) VALUES (50, 1, 10, 'Fall 2026')");
    $pdo->exec("INSERT INTO cohort_courses (cohort_id, course_id, is_enabled) VALUES (50, 10, 1)");
    $pdo->exec("INSERT INTO cohort_lesson_scope (cohort_id, lesson_id) VALUES (50, 100)");
    $pdo->exec("INSERT INTO cohort_lesson_deadlines (cohort_id, lesson_id, sort_order) VALUES (50, 100, 10)");
    $pdo->exec("INSERT INTO lesson_activity (user_id, cohort_id, lesson_id, completion_status)
                VALUES (9, 50, 100, 'in_progress')");

    return theory_studio_live_fingerprint($pdo);
}

/**
 * @return array<string,mixed>
 */
function theory_studio_live_fingerprint(PDO $pdo): array
{
    $tables = array(
        'slide_content', 'slide_enrichment',
        'slide_references', 'slide_hotspots', 'progress_test_lesson_banks',
        'lesson_summary_blueprints', 'cohorts', 'cohort_courses', 'cohort_lesson_scope',
        'cohort_lesson_deadlines', 'lesson_activity',
    );
    $out = array(
        'programs' => $pdo->query("SELECT id, program_key, name, sort_order FROM programs WHERE id = 1")->fetchAll(PDO::FETCH_ASSOC),
        'courses' => $pdo->query("SELECT id, program_id, title, slug, revision, sort_order, is_published FROM courses WHERE program_id = 1")->fetchAll(PDO::FETCH_ASSOC),
        'lessons' => $pdo->query("SELECT id, course_id, external_lesson_id, title, sort_order, page_count FROM lessons WHERE course_id = 10")->fetchAll(PDO::FETCH_ASSOC),
        'slides' => $pdo->query("SELECT id, lesson_id, page_number, image_path, is_deleted FROM slides WHERE lesson_id = 100")->fetchAll(PDO::FETCH_ASSOC),
    );
    foreach ($tables as $table) {
        $out[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function theory_studio_operational_reads(PDO $pdo): array
{
    return array(
        'operational_programs' => $pdo->query(
            'SELECT id, program_key FROM programs WHERE ' . theory_studio_operational_program_sql_for($pdo, 'programs') . ' ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC),
        'written_test_courses' => $pdo->query(
            'SELECT c.id FROM courses c LEFT JOIN programs p ON p.id = c.program_id
             WHERE p.id IS NULL OR ' . theory_studio_operational_program_sql_for($pdo, 'p') . '
             ORDER BY c.id'
        )->fetchAll(PDO::FETCH_COLUMN),
        'bulk_enrich_programs' => $pdo->query(
            'SELECT id FROM programs WHERE ' . theory_studio_operational_program_sql_for($pdo, 'programs') . ' ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN),
        'communication_programs' => $pdo->query(
            'SELECT id FROM programs WHERE ' . theory_studio_operational_program_sql_for($pdo, 'programs') . ' ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN),
        'student_deadline_lessons' => $pdo->query(
            'SELECT lesson_id FROM cohort_lesson_deadlines WHERE cohort_id = 50 ORDER BY lesson_id'
        )->fetchAll(PDO::FETCH_COLUMN),
        'schedule_enabled_for_live_cohort' => $pdo->query(
            'SELECT course_id FROM cohort_courses WHERE cohort_id = 50 AND is_enabled = 1 ORDER BY course_id'
        )->fetchAll(PDO::FETCH_COLUMN),
    );
}
