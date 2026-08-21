<?php
declare(strict_types=1);

final class TheoryHierarchySnapshot
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Read-only operational hierarchy fingerprint. Ignores theory_program_revisions
     * and authoring_origin so additive metadata does not change the snapshot.
     *
     * @return array<string,mixed>
     */
    public function capture(): array
    {
        return array(
            'programs' => $this->count('programs'),
            'courses' => $this->count('courses'),
            'lessons' => $this->count('lessons'),
            'slides_active' => $this->scalar(
                'SELECT COUNT(*) FROM slides WHERE COALESCE(is_deleted, 0) = 0'
            ),
            'slides_deleted' => $this->scalar(
                'SELECT COUNT(*) FROM slides WHERE COALESCE(is_deleted, 0) = 1'
            ),
            'cohorts' => $this->countIf('cohorts'),
            'cohort_courses' => $this->countIf('cohort_courses'),
            'cohort_lesson_scope' => $this->countIf('cohort_lesson_scope'),
            'cohort_lesson_deadlines' => $this->countIf('cohort_lesson_deadlines'),
            'lesson_activity' => $this->countIf('lesson_activity'),
            'per_program_courses' => $this->rows(
                'SELECT program_id, COUNT(*) AS n FROM courses GROUP BY program_id ORDER BY program_id'
            ),
            'per_course_lessons' => $this->rows(
                'SELECT course_id, COUNT(*) AS n FROM lessons GROUP BY course_id ORDER BY course_id'
            ),
            'per_lesson_slides' => $this->rows(
                'SELECT lesson_id, COUNT(*) AS n FROM slides GROUP BY lesson_id ORDER BY lesson_id'
            ),
            'hierarchy_tuples' => $this->rows(
                'SELECT p.id AS program_id, c.id AS course_id, l.id AS lesson_id, s.id AS slide_id
                 FROM programs p
                 JOIN courses c ON c.program_id = p.id
                 JOIN lessons l ON l.course_id = c.id
                 JOIN slides s ON s.lesson_id = l.id
                 ORDER BY p.id, c.id, l.id, s.id'
            ),
            'deadline_tuples' => $this->tableExists('cohort_lesson_deadlines')
                ? $this->rows(
                    'SELECT cohort_id, lesson_id, sort_order FROM cohort_lesson_deadlines
                     ORDER BY cohort_id, lesson_id, sort_order'
                )
                : array(),
        );
    }

    public static function operationalEqual(array $before, array $after): bool
    {
        return json_encode($before) === json_encode($after);
    }

    private function count(string $table): int
    {
        return $this->scalar('SELECT COUNT(*) FROM ' . $table);
    }

    private function countIf(string $table): int
    {
        return $this->tableExists($table) ? $this->count($table) : 0;
    }

    private function scalar(string $sql): int
    {
        try {
            $stmt = $this->pdo->query($sql);
            return (int)($stmt ? $stmt->fetchColumn() : 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
            return is_array($rows) ? $rows : array();
        } catch (Throwable) {
            return array();
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
                );
                $stmt->execute(array($table));
                return (bool)$stmt->fetchColumn();
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table));
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
