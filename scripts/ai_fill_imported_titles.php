<?php
declare(strict_types=1);

/**
 * After SQL (or import_lab) seed with placeholder titles ("Lab 20001", "Lesson 20002"),
 * fill course and lesson titles from slide page 001 via OpenAI — same prompts as
 * public/admin/import_lab.php (ai_detect_course_title / ai_detect_lesson_title).
 *
 * Env: CW_OPENAI_API_KEY, CW_OPENAI_MODEL (optional), CW_CDN_BASE (optional)
 * DB: CW_DB_* (see src/db.php)
 *
 * Usage:
 *   php scripts/ai_fill_imported_titles.php --program=instrument
 *   php scripts/ai_fill_imported_titles.php --program=instrument --dry-run --limit=3
 *   php scripts/ai_fill_imported_titles.php --program=instrument --courses-only
 *   php scripts/ai_fill_imported_titles.php --program=instrument --lessons-only
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/openai.php';

$program = 'instrument';
$dryRun = false;
$coursesOnly = false;
$lessonsOnly = false;
$limit = 0;

foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--program=')) {
        $program = substr($arg, strlen('--program='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--courses-only') {
        $coursesOnly = true;
    } elseif ($arg === '--lessons-only') {
        $lessonsOnly = true;
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, strlen('--limit=')));
    }
}

$CDN_BASE = rtrim(getenv('CW_CDN_BASE') ?: '', '/');
if ($CDN_BASE === '') {
    $CDN_BASE = 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com';
}

$pdo = cw_db();

function ai_detect_course_title(string $imageUrl): string
{
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'course_title' => ['type' => 'string'],
        ],
        'required' => ['course_title'],
    ];

    $instructions = <<<TXT
Extract the COURSE TITLE from this screenshot.

The COURSE TITLE is the big centered title of the module (example: "Getting To Know Your Airplane").
Ignore:
- "Learning Your Airplane" (phase/group title)
- "MAIN MENU / HOW TO USE THIS COURSE / SAVE & EXIT"
- breadcrumb paths with "/"
- footer controls / page numbers
Return only the course title text.
TXT;

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $instructions]]],
            ['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => 'Extract the course title.'],
                ['type' => 'input_image', 'image_url' => $imageUrl],
            ]],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'course_title_v1',
                'schema' => $schema,
                'strict' => true,
            ],
        ],
        'temperature' => 0.2,
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);

    return trim((string)($json['course_title'] ?? ''));
}

function ai_detect_lesson_title(string $imageUrl): string
{
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'title' => ['type' => 'string'],
        ],
        'required' => ['title'],
    ];

    $instructions = <<<TXT
Extract the lesson title from this training slide screenshot.
Return ONLY the real instructional lesson title.
Ignore UI chrome like:
- MAIN MENU / HOW TO USE THIS COURSE / SAVE & EXIT
- breadcrumb paths with "/"
- footer controls / page numbers
Return a short title (max ~80 chars).
TXT;

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $instructions]]],
            ['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => 'Extract lesson title.'],
                ['type' => 'input_image', 'image_url' => $imageUrl],
            ]],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'lesson_title_v1',
                'schema' => $schema,
                'strict' => true,
            ],
        ],
        'temperature' => 0.2,
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);

    return trim((string)($json['title'] ?? ''));
}

$stmtProg = $pdo->prepare('SELECT id FROM programs WHERE program_key = ? LIMIT 1');
$stmtProg->execute([$program]);
$programId = (int)$stmtProg->fetchColumn();
if ($programId <= 0) {
    fwrite(STDERR, "Unknown program_key: {$program}\n");
    exit(1);
}

$n = 0;

if (!$lessonsOnly) {
    $q = $pdo->prepare("
        SELECT c.id, c.title, c.slug, c.external_lab_id
        FROM courses c
        WHERE c.program_id = ?
          AND c.title REGEXP '^Lab [0-9]+$'
        ORDER BY c.sort_order, c.id
    ");
    $q->execute([$programId]);
    $courses = $q->fetchAll(PDO::FETCH_ASSOC);

    $seedStmt = $pdo->prepare('
        SELECT external_lesson_id FROM lessons
        WHERE course_id = ?
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ');

    foreach ($courses as $row) {
        if ($limit > 0 && $n >= $limit) {
            break;
        }
        $courseId = (int)$row['id'];
        $seedStmt->execute([$courseId]);
        $seedLessonId = (int)$seedStmt->fetchColumn();
        if ($seedLessonId <= 0) {
            fwrite(STDOUT, "Course {$courseId}: no lessons, skip\n");
            continue;
        }

        $path = image_path_for($program, $seedLessonId, 1);
        $url = cdn_url($CDN_BASE, $path);

        fwrite(STDOUT, "Course {$courseId} (lab {$row['external_lab_id']}): {$url}\n");

        try {
            $t = ai_detect_course_title($url);
        } catch (Throwable $e) {
            fwrite(STDERR, "  AI error: " . $e->getMessage() . "\n");
            continue;
        }

        if ($t === '') {
            fwrite(STDOUT, "  empty title, skip update\n");
            continue;
        }

        $newSlug = slugify($t);
        fwrite(STDOUT, "  -> " . $t . " (slug {$newSlug})\n");

        if (!$dryRun) {
            $up = $pdo->prepare('UPDATE courses SET title = ?, slug = ? WHERE id = ? AND program_id = ?');
            $up->execute([$t, $newSlug, $courseId, $programId]);
        }

        $n++;
        usleep(200000);
    }
}

if (!$coursesOnly) {
    $ql = $pdo->prepare("
        SELECT l.id, l.course_id, l.external_lesson_id, l.title, l.page_count
        FROM lessons l
        INNER JOIN courses c ON c.id = l.course_id
        WHERE c.program_id = ?
          AND l.title = CONCAT('Lesson ', l.external_lesson_id)
        ORDER BY c.sort_order, l.sort_order, l.id
    ");
    $ql->execute([$programId]);
    $lessons = $ql->fetchAll(PDO::FETCH_ASSOC);

    $lc = 0;
    foreach ($lessons as $row) {
        if ($limit > 0 && $lc >= $limit) {
            break;
        }
        $lessonRowId = (int)$row['id'];
        $extId = (int)$row['external_lesson_id'];
        $pageCount = (int)$row['page_count'];

        if ($pageCount <= 0) {
            fwrite(STDOUT, "Lesson row {$lessonRowId} (ext {$extId}): page_count 0, skip AI\n");
            continue;
        }

        $path = image_path_for($program, $extId, 1);
        $url = cdn_url($CDN_BASE, $path);

        fwrite(STDOUT, "Lesson {$lessonRowId} (ext {$extId}): {$url}\n");

        try {
            $t = ai_detect_lesson_title($url);
        } catch (Throwable $e) {
            fwrite(STDERR, "  AI error: " . $e->getMessage() . "\n");
            continue;
        }

        if ($t === '') {
            fwrite(STDOUT, "  empty title, skip\n");
            continue;
        }

        fwrite(STDOUT, "  -> {$t}\n");

        if (!$dryRun) {
            $up = $pdo->prepare('UPDATE lessons SET title = ? WHERE id = ?');
            $up->execute([$t, $lessonRowId]);
        }

        $lc++;
        usleep(200000);
    }
}

fwrite(STDOUT, $dryRun ? "Dry run complete.\n" : "Done.\n");
