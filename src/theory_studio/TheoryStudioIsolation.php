<?php
declare(strict_types=1);

require_once __DIR__ . '/TheoryStudioException.php';

/**
 * Behavior-neutral isolation of Studio Draft programs from operational selectors.
 * Existing Live rows use authoring_origin = operational (column default).
 */

function theory_studio_column_exists(PDO $pdo, string $table, string $column): bool
{
    $key = spl_object_id($pdo) . '.' . $table . '.' . $column;
    if (array_key_exists($key, $GLOBALS['theory_studio_column_cache'] ?? array())) {
        return (bool)$GLOBALS['theory_studio_column_cache'][$key];
    }
    $found = false;
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array() as $row) {
                if ((string)($row['name'] ?? '') === $column) {
                    $found = true;
                    break;
                }
            }
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table, $column));
            $found = (bool)$stmt->fetchColumn();
        }
    } catch (Throwable) {
        $found = false;
    }
    $GLOBALS['theory_studio_column_cache'][$key] = $found;
    return $found;
}

function theory_studio_reset_schema_cache(): void
{
    $GLOBALS['theory_studio_column_cache'] = array();
}

function theory_studio_operational_program_sql(string $alias = 'p'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    return $alias . ".authoring_origin = 'operational'";
}

function theory_studio_operational_program_sql_for(PDO $pdo, string $alias = 'p'): string
{
    if (!theory_studio_column_exists($pdo, 'programs', 'authoring_origin')) {
        return '1=1';
    }
    return theory_studio_operational_program_sql($alias);
}

function theory_studio_program_origin(PDO $pdo, int $programId): string
{
    if ($programId <= 0 || !theory_studio_column_exists($pdo, 'programs', 'authoring_origin')) {
        return 'operational';
    }
    $stmt = $pdo->prepare('SELECT authoring_origin FROM programs WHERE id = ? LIMIT 1');
    $stmt->execute(array($programId));
    $origin = strtolower(trim((string)$stmt->fetchColumn()));
    return $origin === 'studio' ? 'studio' : 'operational';
}

function theory_studio_program_is_studio(PDO $pdo, int $programId): bool
{
    return theory_studio_program_origin($pdo, $programId) === 'studio';
}

function theory_studio_program_is_operational(PDO $pdo, int $programId): bool
{
    return !theory_studio_program_is_studio($pdo, $programId);
}

/**
 * Server-side gate for cohort assignment and other operational writes.
 *
 * @throws TheoryStudioException
 */
function theory_studio_require_operational_program(PDO $pdo, int $programId): void
{
    if ($programId <= 0) {
        throw new TheoryStudioException(
            'VALIDATION',
            'A valid program is required.',
            400
        );
    }
    if (theory_studio_program_is_studio($pdo, $programId)) {
        throw new TheoryStudioException(
            'STUDIO_DRAFT_NOT_OPERATIONAL',
            'This Theory Content Studio Draft program is authoring-only. It cannot be assigned to a cohort, used for student enrollment, or selected in operational training workflows until publishing exists.',
            409
        );
    }
}

function theory_studio_require_operational_lesson(PDO $pdo, int $lessonId): void
{
    if ($lessonId <= 0) {
        throw new TheoryStudioException('VALIDATION', 'A valid lesson is required.', 400);
    }
    $stmt = $pdo->prepare(
        'SELECT c.program_id FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ? LIMIT 1'
    );
    $stmt->execute(array($lessonId));
    $programId = (int)$stmt->fetchColumn();
    theory_studio_require_operational_program($pdo, $programId);
}

/**
 * Legacy screenshot editors must never write native structured Studio slides.
 *
 * @throws TheoryStudioException
 */
function theory_studio_require_legacy_slide(PDO $pdo, int $slideId): void
{
    if ($slideId <= 0) {
        throw new TheoryStudioException('VALIDATION', 'A valid slide is required.', 400);
    }
    if (!theory_studio_column_exists($pdo, 'slides', 'source_category')) {
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT source_category FROM slides WHERE id = ? LIMIT 1'
    );
    $stmt->execute(array($slideId));
    $category = strtolower(trim((string)$stmt->fetchColumn()));
    if ($category === 'structured') {
        throw new TheoryStudioException(
            'STRUCTURED_SLIDE_REQUIRES_STUDIO',
            'This native structured Slide must be edited in Theory Content Studio.',
            409
        );
    }
}
