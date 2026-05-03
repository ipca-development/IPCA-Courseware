#!/usr/bin/env php
<?php
/**
 * Hard-delete courses and typical dependent rows (lessons, slides, slide_*).
 *
 * Usage (from repo root, with CW_DB_* env set):
 *   php scripts/delete_courses_by_ids.php 23 24
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();

$courseIds = array_values(array_filter(array_map('intval', array_slice($argv, 1)), static fn (int $x): bool => $x > 0));
if ($courseIds === []) {
    fwrite(STDERR, "Usage: php scripts/delete_courses_by_ids.php <course_id> [course_id ...]\n");
    exit(1);
}

$phC = implode(',', array_fill(0, count($courseIds), '?'));

$stmt = $pdo->prepare("SELECT id FROM lessons WHERE course_id IN ($phC)");
$stmt->execute($courseIds);
$lessonIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
if ($lessonIds === []) {
    $pdo->prepare("DELETE FROM cohort_courses WHERE course_id IN ($phC)")->execute($courseIds);
    $pdo->prepare("DELETE FROM courses WHERE id IN ($phC)")->execute($courseIds);
    echo "Deleted courses " . implode(', ', $courseIds) . " (no lessons).\n";
    exit(0);
}

$phL = implode(',', array_fill(0, count($lessonIds), '?'));

$stmt = $pdo->prepare("SELECT id FROM slides WHERE lesson_id IN ($phL)");
$stmt->execute($lessonIds);
$slideIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$deleteSlideChildren = static function (PDO $pdo, string $table, array $slideIds): void {
    if ($slideIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($slideIds), '?'));
    try {
        $pdo->prepare("DELETE FROM `{$table}` WHERE slide_id IN ($ph)")->execute($slideIds);
    } catch (Throwable $e) {
        // Table may not exist in older schemas; ignore.
    }
};

$pdo->beginTransaction();
try {
    if ($slideIds !== []) {
        foreach ([
            'slide_content',
            'slide_enrichment',
            'slide_references',
            'slide_hotspots',
            'slide_events',
            'slide_ai_outputs',
        ] as $tbl) {
            $deleteSlideChildren($pdo, $tbl, $slideIds);
        }
        $phS = implode(',', array_fill(0, count($slideIds), '?'));
        $pdo->prepare("DELETE FROM slides WHERE id IN ($phS)")->execute($slideIds);
    }

    foreach ([
        'cohort_lesson_deadlines',
        'cohort_lesson_scope',
    ] as $tbl) {
        try {
            $pdo->prepare("DELETE FROM `{$tbl}` WHERE lesson_id IN ($phL)")->execute($lessonIds);
        } catch (Throwable $e) {
        }
    }

    $pdo->prepare("DELETE FROM lessons WHERE id IN ($phL)")->execute($lessonIds);
    $pdo->prepare("DELETE FROM cohort_courses WHERE course_id IN ($phC)")->execute($courseIds);
    $pdo->prepare("DELETE FROM courses WHERE id IN ($phC)")->execute($courseIds);

    $pdo->commit();
    echo 'Deleted courses ' . implode(', ', $courseIds) . ' (lessons ' . count($lessonIds) . ', slides ' . count($slideIds) . ").\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Delete failed: ' . $e->getMessage() . "\n");
    exit(1);
}
