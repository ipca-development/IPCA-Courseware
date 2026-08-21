<?php
declare(strict_types=1);

require_once __DIR__ . '/TheoryStudioException.php';
require_once __DIR__ . '/TheoryStudioIsolation.php';

final class TheoryContentStudioService
{
    public const LIVE_BANNER = 'This revision is Live and currently in use. Theory Content Studio Phase 1 provides read-only access to existing Live training content. Create Draft from Live will be introduced with revision isolation.';

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPrograms(): array
    {
        $coverSelect = theory_studio_column_exists($this->pdo, 'programs', 'cover_image_path')
            ? 'p.cover_image_path'
            : 'NULL AS cover_image_path';
        $originSelect = theory_studio_column_exists($this->pdo, 'programs', 'authoring_origin')
            ? 'p.authoring_origin'
            : "'operational' AS authoring_origin";
        $sql = "
            SELECT
              p.id,
              p.program_key,
              p.name,
              p.sort_order,
              {$originSelect},
              {$coverSelect},
              r.id AS revision_id,
              r.revision_number,
              r.revision_date,
              r.status AS revision_status,
              r.origin AS revision_origin,
              r.cover_image_path AS revision_cover_image_path,
              (
                SELECT COUNT(*) FROM courses c WHERE c.program_id = p.id
              ) AS course_count
            FROM programs p
            LEFT JOIN theory_program_revisions r
              ON r.id = (
                SELECT MAX(x.id) FROM theory_program_revisions x WHERE x.program_id = p.id
              )
            ORDER BY p.sort_order ASC, p.id ASC
        ";
        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll() : array();
        $out = array();
        foreach ($rows as $row) {
            $out[] = $this->presentProgram($row);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function getProgram(int $programId): array
    {
        foreach ($this->listPrograms() as $row) {
            if ((int)$row['id'] === $programId) {
                return $row;
            }
        }
        throw new TheoryStudioException('NOT_FOUND', 'Program not found.', 404);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createProgram(array $input): array
    {
        if (!theory_studio_column_exists($this->pdo, 'programs', 'authoring_origin')) {
            throw new TheoryStudioException(
                'SCHEMA_NOT_READY',
                'Apply the Theory Content Studio Phase 1 migration before creating programs.',
                500
            );
        }
        $name = trim((string)($input['name'] ?? ''));
        $key = strtolower(trim((string)($input['program_key'] ?? '')));
        $revisionNumber = trim((string)($input['revision_number'] ?? '1.0'));
        $revisionDate = trim((string)($input['revision_date'] ?? ''));
        $cover = $this->sanitizeCoverPath((string)($input['cover_image_path'] ?? ''));
        if ($name === '') {
            throw new TheoryStudioException('VALIDATION', 'Program name is required.', 400);
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key)) {
            throw new TheoryStudioException(
                'VALIDATION',
                'Program key must start with a letter and use only lowercase letters, numbers, and underscores.',
                400
            );
        }
        if ($revisionNumber === '' || strlen($revisionNumber) > 32) {
            throw new TheoryStudioException('VALIDATION', 'Revision number is required.', 400);
        }
        $dup = $this->pdo->prepare('SELECT id FROM programs WHERE program_key = ? LIMIT 1');
        $dup->execute(array($key));
        if ((int)$dup->fetchColumn() > 0) {
            throw new TheoryStudioException('DUPLICATE_KEY', 'That program key is already in use.', 409);
        }
        $maxSort = (int)$this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM programs')->fetchColumn();
        $this->pdo->beginTransaction();
        try {
            $hasCover = theory_studio_column_exists($this->pdo, 'programs', 'cover_image_path');
            if ($hasCover) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO programs (program_key, name, sort_order, authoring_origin, cover_image_path)
                     VALUES (?, ?, ?, \'studio\', ?)'
                );
                $ins->execute(array($key, $name, $maxSort + 10, $cover !== '' ? $cover : null));
            } else {
                $ins = $this->pdo->prepare(
                    'INSERT INTO programs (program_key, name, sort_order, authoring_origin)
                     VALUES (?, ?, ?, \'studio\')'
                );
                $ins->execute(array($key, $name, $maxSort + 10));
            }
            $programId = (int)$this->pdo->lastInsertId();
            $rev = $this->pdo->prepare(
                'INSERT INTO theory_program_revisions
                    (program_id, revision_number, revision_date, status, origin, cover_image_path)
                 VALUES (?, ?, ?, \'draft\', \'studio\', ?)'
            );
            $rev->execute(array(
                $programId,
                $revisionNumber,
                $revisionDate !== '' ? $revisionDate : null,
                $cover !== '' ? $cover : null,
            ));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->getProgram($programId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCourses(int $programId): array
    {
        $this->getProgram($programId);
        $coverSelect = theory_studio_column_exists($this->pdo, 'courses', 'cover_image_path')
            ? 'c.cover_image_path'
            : 'NULL AS cover_image_path';
        $dateSelect = theory_studio_column_exists($this->pdo, 'courses', 'revision_date')
            ? 'c.revision_date'
            : 'NULL AS revision_date';
        $stmt = $this->pdo->prepare("
            SELECT
              c.id,
              c.program_id,
              c.title,
              c.slug,
              c.revision,
              c.sort_order,
              {$coverSelect},
              {$dateSelect},
              (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count
            FROM courses c
            WHERE c.program_id = ?
            ORDER BY c.sort_order ASC, c.id ASC
        ");
        $stmt->execute(array($programId));
        return $stmt->fetchAll() ?: array();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createCourse(int $programId, array $input): array
    {
        $this->assertProgramMutable($programId);
        $title = trim((string)($input['title'] ?? ''));
        $slug = trim((string)($input['slug'] ?? ''));
        $revision = trim((string)($input['revision'] ?? '1.0'));
        $revisionDate = trim((string)($input['revision_date'] ?? ''));
        $cover = $this->sanitizeCoverPath((string)($input['cover_image_path'] ?? ''));
        if ($title === '') {
            throw new TheoryStudioException('VALIDATION', 'Course title is required.', 400);
        }
        if ($slug === '') {
            $slug = $this->slugify($title);
        }
        if ($slug === '') {
            throw new TheoryStudioException('VALIDATION', 'Course slug is required.', 400);
        }
        $dup = $this->pdo->prepare('SELECT id FROM courses WHERE program_id = ? AND slug = ? LIMIT 1');
        $dup->execute(array($programId, $slug));
        if ((int)$dup->fetchColumn() > 0) {
            throw new TheoryStudioException('DUPLICATE_KEY', 'A course with that slug already exists in this program.', 409);
        }
        $maxSort = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM courses WHERE program_id = ?');
        $maxSort->execute(array($programId));
        $sort = (int)$maxSort->fetchColumn() + 10;
        $hasCover = theory_studio_column_exists($this->pdo, 'courses', 'cover_image_path');
        $hasDate = theory_studio_column_exists($this->pdo, 'courses', 'revision_date');
        $cols = array('program_id', 'title', 'slug', 'revision', 'sort_order', 'is_published');
        $vals = array($programId, $title, $slug, $revision !== '' ? $revision : '1.0', $sort, 0);
        if ($hasCover) {
            $cols[] = 'cover_image_path';
            $vals[] = $cover !== '' ? $cover : null;
        }
        if ($hasDate) {
            $cols[] = 'revision_date';
            $vals[] = $revisionDate !== '' ? $revisionDate : null;
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $ins = $this->pdo->prepare(
            'INSERT INTO courses (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')'
        );
        $ins->execute($vals);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($this->listCourses($programId) as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return array('id' => $id, 'program_id' => $programId, 'title' => $title);
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorderCourses(int $programId, array $orderedIds): void
    {
        $this->assertProgramMutable($programId);
        $existing = array();
        foreach ($this->listCourses($programId) as $row) {
            $existing[] = (int)$row['id'];
        }
        $this->assertSameIdSet($existing, $orderedIds, 'courses');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE courses SET sort_order = ? WHERE id = ? AND program_id = ?'
            );
            $order = 10;
            foreach ($orderedIds as $id) {
                $stmt->execute(array($order, (int)$id, $programId));
                $order += 10;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLessons(int $courseId): array
    {
        $course = $this->requireCourse($courseId);
        $stmt = $this->pdo->prepare("
            SELECT
              l.id,
              l.course_id,
              l.title,
              l.sort_order,
              l.page_count,
              l.external_lesson_id,
              (SELECT COUNT(*) FROM slides s WHERE s.lesson_id = l.id AND COALESCE(s.is_deleted, 0) = 0) AS slide_count
            FROM lessons l
            WHERE l.course_id = ?
            ORDER BY l.sort_order ASC, l.id ASC
        ");
        $stmt->execute(array($courseId));
        $rows = $stmt->fetchAll() ?: array();
        foreach ($rows as &$row) {
            $row['program_id'] = (int)$course['program_id'];
            $row['sequence'] = (int)$row['sort_order'];
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function createLessons(int $courseId, array $input): array
    {
        $course = $this->requireCourse($courseId);
        $this->assertProgramMutable((int)$course['program_id']);
        $titles = array();
        $bulk = (string)($input['titles'] ?? '');
        if ($bulk !== '') {
            foreach (preg_split('/\R/u', $bulk) ?: array() as $line) {
                $title = trim((string)$line);
                if ($title !== '') {
                    $titles[] = $title;
                }
            }
        } else {
            $title = trim((string)($input['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        if ($titles === array()) {
            throw new TheoryStudioException('VALIDATION', 'At least one lesson title is required.', 400);
        }
        $max = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lessons WHERE course_id = ?');
        $max->execute(array($courseId));
        $sort = (int)$max->fetchColumn();
        $created = array();
        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare(
                'INSERT INTO lessons (course_id, external_lesson_id, title, sort_order, page_count, default_template_key)
                 VALUES (?, NULL, ?, ?, 0, ?)'
            );
            foreach ($titles as $title) {
                $sort += 10;
                $ins->execute(array($courseId, $title, $sort, 'MEDIA_LEFT_TEXT_RIGHT'));
                $created[] = (int)$this->pdo->lastInsertId();
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $out = array();
        foreach ($this->listLessons($courseId) as $row) {
            if (in_array((int)$row['id'], $created, true)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorderLessons(int $courseId, array $orderedIds): void
    {
        $course = $this->requireCourse($courseId);
        $this->assertProgramMutable((int)$course['program_id']);
        $existing = array();
        foreach ($this->listLessons($courseId) as $row) {
            $existing[] = (int)$row['id'];
        }
        $this->assertSameIdSet($existing, $orderedIds, 'lessons');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE lessons SET sort_order = ? WHERE id = ? AND course_id = ?'
            );
            $order = 10;
            foreach ($orderedIds as $id) {
                $stmt->execute(array($order, (int)$id, $courseId));
                $order += 10;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getLesson(int $lessonId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT l.*, c.program_id, c.title AS course_title, p.name AS program_name, p.program_key
            FROM lessons l
            JOIN courses c ON c.id = l.course_id
            JOIN programs p ON p.id = c.program_id
            WHERE l.id = ?
            LIMIT 1
        ");
        $stmt->execute(array($lessonId));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Lesson not found.', 404);
        }
        $row['protected'] = $this->programIsProtected((int)$row['program_id']);
        $row['slides'] = $this->listSlides($lessonId);
        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSlides(int $lessonId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, lesson_id, page_number, image_path, template_key, COALESCE(is_deleted, 0) AS is_deleted
            FROM slides
            WHERE lesson_id = ?
            ORDER BY page_number ASC, id ASC
        ");
        $stmt->execute(array($lessonId));
        return $stmt->fetchAll() ?: array();
    }

    public function addSlide(int $lessonId): never
    {
        $lesson = $this->getLesson($lessonId);
        $this->assertProgramMutable((int)$lesson['program_id']);
        throw new TheoryStudioException(
            'STRUCTURED_SLIDES_NOT_ENABLED',
            'Structured Slide Editor coming in Phase 2. Theory Content Studio will not insert screenshot-shaped placeholder slides into the production slides table.',
            409
        );
    }

    public function publish(int $programId): never
    {
        $this->assertProgramMutable($programId);
        throw new TheoryStudioException(
            'PUBLISHING_DISABLED',
            'Publishing is unavailable until Create Draft from Live, validation, and cohort version attachment exist.',
            409
        );
    }

    public function mutateProtectedProgram(int $programId): never
    {
        $this->assertProgramMutable($programId);
        throw new TheoryStudioException('VALIDATION', 'Unsupported mutation.', 400);
    }

    /**
     * @return array<string,mixed>
     */
    public function requireCourse(int $courseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
        $stmt->execute(array($courseId));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Course not found.', 404);
        }
        return $row;
    }

    public function programIsProtected(int $programId): bool
    {
        if ($programId <= 0) {
            return true;
        }
        if (!theory_studio_program_is_studio($this->pdo, $programId)) {
            return true;
        }
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM cohorts WHERE program_id = ?');
            $st->execute(array($programId));
            if ((int)$st->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable) {
            // cohorts table always exists in production; tests include it.
        }
        return false;
    }

    public function assertProgramMutable(int $programId): void
    {
        $this->getProgram($programId);
        if ($this->programIsProtected($programId)) {
            throw new TheoryStudioException(
                'LIVE_CONTENT_PROTECTED',
                'This content is Live and currently in use. Theory Content Studio cannot modify existing production training until isolated Draft revisions are supported.',
                409
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentProgram(array $row): array
    {
        $origin = strtolower(trim((string)($row['authoring_origin'] ?? 'operational')));
        $status = strtolower(trim((string)($row['revision_status'] ?? '')));
        if ($status === '') {
            $status = $origin === 'studio' ? 'draft' : 'live';
        }
        $protected = $origin !== 'studio' || $this->programIsProtected((int)$row['id']);
        $cover = trim((string)($row['revision_cover_image_path'] ?? ''));
        if ($cover === '') {
            $cover = trim((string)($row['cover_image_path'] ?? ''));
        }
        return array(
            'id' => (int)$row['id'],
            'program_key' => (string)($row['program_key'] ?? ''),
            'name' => (string)($row['name'] ?? $row['program_key'] ?? ''),
            'authoring_origin' => $origin,
            'revision_id' => (int)($row['revision_id'] ?? 0),
            'revision_number' => (string)($row['revision_number'] ?? '1.0'),
            'revision_date' => (string)($row['revision_date'] ?? ''),
            'status' => $status,
            'cover_image_path' => $cover,
            'course_count' => (int)($row['course_count'] ?? 0),
            'protected' => $protected,
            'in_use' => $protected && $status === 'live',
        );
    }

    /**
     * @param list<int> $existing
     * @param list<int> $ordered
     */
    private function assertSameIdSet(array $existing, array $ordered, string $label): void
    {
        $a = array_map('intval', $existing);
        $b = array_map('intval', $ordered);
        sort($a);
        $sortedB = $b;
        sort($sortedB);
        if ($a !== $sortedB || count($b) !== count(array_unique($b))) {
            throw new TheoryStudioException(
                'VALIDATION',
                'The ' . $label . ' order must include every item in this parent and nothing else.',
                400
            );
        }
    }

    private function sanitizeCoverPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, ':')) {
            throw new TheoryStudioException('VALIDATION', 'Invalid media path.', 400);
        }
        if (!preg_match('#^theory_studio/covers/[a-zA-Z0-9._-]+$#', $path)) {
            throw new TheoryStudioException('VALIDATION', 'Invalid media path.', 400);
        }
        return $path;
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }
}
