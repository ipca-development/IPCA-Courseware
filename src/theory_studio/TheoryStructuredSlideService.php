<?php
declare(strict_types=1);

require_once __DIR__ . '/TheoryContentStudioService.php';

/**
 * Persistence boundary for authoring structured slides.
 *
 * Template versions and their placeholder rows are append-only. A slide stores
 * values only; all geometry is read through its pinned template_version_id.
 */
final class TheoryStructuredSlideService
{
    private TheoryContentStudioService $studio;

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->studio = new TheoryContentStudioService($pdo);
    }

    /** @return list<array<string,mixed>> */
    public function listTemplates(?int $programId = null): array
    {
        $sql = 'SELECT * FROM theory_slide_templates WHERE deleted_at IS NULL';
        $args = array();
        if ($programId !== null) {
            $sql .= ' AND (is_system = 1 OR owning_program_id = ?)';
            $args[] = $programId;
        }
        $sql .= ' ORDER BY is_system DESC, name, id';
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll() ?: array();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTemplate(int $programId, array $input): array
    {
        $this->studio->assertProgramMutable($programId);
        $key = strtoupper(trim((string)($input['template_key'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,95}$/', $key) || $name === '') {
            throw new TheoryStudioException('VALIDATION', 'A valid template key and name are required.', 400);
        }
        $st = $this->pdo->prepare(
            'INSERT INTO theory_slide_templates
                (template_key, name, description, owning_program_id, is_system)
             VALUES (?, ?, ?, ?, 0)'
        );
        try {
            $st->execute(array($key, $name, trim((string)($input['description'] ?? '')), $programId));
        } catch (PDOException $e) {
            throw new TheoryStudioException('DUPLICATE_KEY', 'That template key is already in use.', 409);
        }
        return $this->getTemplate((int)$this->pdo->lastInsertId());
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateTemplate(int $templateId, int $programId, array $input): array
    {
        $template = $this->requireMutableTemplate($templateId, $programId);
        $name = trim((string)($input['name'] ?? $template['name']));
        if ($name === '') {
            throw new TheoryStudioException('VALIDATION', 'Template name is required.', 400);
        }
        $st = $this->pdo->prepare(
            'UPDATE theory_slide_templates SET name = ?, description = ? WHERE id = ?'
        );
        $st->execute(array($name, trim((string)($input['description'] ?? $template['description'] ?? '')), $templateId));
        return $this->getTemplate($templateId);
    }

    public function softDeleteTemplate(int $templateId, int $programId): void
    {
        $this->requireMutableTemplate($templateId, $programId);
        $used = $this->pdo->prepare(
            'SELECT COUNT(*) FROM theory_structured_slides s
             JOIN theory_slide_template_versions v ON v.id = s.template_version_id
             WHERE v.template_id = ?'
        );
        $used->execute(array($templateId));
        if ((int)$used->fetchColumn() > 0) {
            throw new TheoryStudioException('TEMPLATE_IN_USE', 'A template used by a slide cannot be deleted.', 409);
        }
        $this->pdo->prepare('UPDATE theory_slide_templates SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute(array($templateId));
    }

    /**
     * Creates an immutable version and all of its immutable placeholder definitions.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createTemplateVersion(int $templateId, int $programId, array $input): array
    {
        $this->requireMutableTemplate($templateId, $programId);
        $placeholders = $input['placeholders'] ?? null;
        if (!is_array($placeholders) || $placeholders === array()) {
            throw new TheoryStudioException('VALIDATION', 'At least one placeholder is required.', 400);
        }
        $max = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_number), 0) FROM theory_slide_template_versions WHERE template_id = ?'
        );
        $max->execute(array($templateId));
        $versionNumber = (int)$max->fetchColumn() + 1;
        $normalized = $this->normalizePlaceholders($placeholders);
        $normalizedGuides = $this->normalizeGuides(
            is_array($input['guides'] ?? null) ? $input['guides'] : array()
        );

        $this->pdo->beginTransaction();
        try {
            $version = $this->pdo->prepare(
                'INSERT INTO theory_slide_template_versions
                    (template_id, version_number, canvas_width, canvas_height, created_by_user_id)
                 VALUES (?, ?, 1600, 900, ?)'
            );
            $version->execute(array($templateId, $versionNumber, $input['created_by_user_id'] ?? null));
            $versionId = (int)$this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO theory_slide_template_placeholders
                    (template_version_id, placeholder_key, content_type, semantic_role,
                     x, y, w, h, reading_order, is_required,
                     allowed_content_json, allowed_style_json, allowed_behavior_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalized as $p) {
                $insert->execute(array(
                    $versionId, $p['placeholder_key'], $p['content_type'], $p['semantic_role'],
                    $p['x'], $p['y'], $p['w'], $p['h'], $p['reading_order'], $p['is_required'],
                    $p['allowed_content_json'], $p['allowed_style_json'], $p['allowed_behavior_json'],
                ));
            }
            $guideInsert = $this->pdo->prepare(
                'INSERT INTO theory_slide_template_guides
                    (template_version_id, orientation, position, is_locked)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($normalizedGuides as $guide) {
                $guideInsert->execute(array(
                    $versionId,
                    $guide['orientation'],
                    $guide['position'],
                    $guide['is_locked'],
                ));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->getTemplateVersion($versionId);
    }

    /** @return array<string,mixed> */
    public function activateTemplateVersion(int $templateId, int $versionId, int $programId): array
    {
        $this->requireMutableTemplate($templateId, $programId);
        $version = $this->getTemplateVersion($versionId);
        if ((int)$version['template_id'] !== $templateId) {
            throw new TheoryStudioException('VALIDATION', 'Template version does not belong to that template.', 400);
        }
        $this->pdo->prepare('UPDATE theory_slide_templates SET active_version_id = ? WHERE id = ?')
            ->execute(array($versionId, $templateId));
        return $this->getTemplate($templateId);
    }

    /** @return array<string,mixed> */
    public function getTemplate(int $templateId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM theory_slide_templates WHERE id = ? LIMIT 1');
        $st->execute(array($templateId));
        $row = $st->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Template not found.', 404);
        }
        $versions = $this->pdo->prepare(
            'SELECT id, template_id, version_number, canvas_width, canvas_height, created_by_user_id, created_at
             FROM theory_slide_template_versions WHERE template_id = ? ORDER BY version_number, id'
        );
        $versions->execute(array($templateId));
        $row['versions'] = $versions->fetchAll() ?: array();
        return $row;
    }

    /** @return array<string,mixed> */
    public function getTemplateVersion(int $versionId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM theory_slide_template_versions WHERE id = ? LIMIT 1');
        $st->execute(array($versionId));
        $row = $st->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Template version not found.', 404);
        }
        $ph = $this->pdo->prepare(
            'SELECT * FROM theory_slide_template_placeholders
             WHERE template_version_id = ? ORDER BY reading_order, id'
        );
        $ph->execute(array($versionId));
        $row['placeholders'] = $ph->fetchAll() ?: array();
        $guides = $this->pdo->prepare(
            'SELECT id, template_version_id, orientation, position, is_locked
             FROM theory_slide_template_guides
             WHERE template_version_id = ? ORDER BY orientation, position, id'
        );
        $guides->execute(array($versionId));
        $row['guides'] = $guides->fetchAll() ?: array();
        return $row;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createOutlineNode(int $courseId, array $input): array
    {
        $course = $this->studio->requireCourse($courseId);
        $this->studio->assertProgramMutable((int)$course['program_id']);
        $parentId = isset($input['parent_node_id']) ? (int)$input['parent_node_id'] : null;
        if ($parentId !== null) {
            $parent = $this->requireOutlineNode($parentId);
            if ((int)$parent['course_id'] !== $courseId) {
                throw new TheoryStudioException('VALIDATION', 'Outline parent belongs to another course.', 400);
            }
        }
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new TheoryStudioException('VALIDATION', 'Outline node title is required.', 400);
        }
        $max = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) FROM theory_course_outline_nodes
             WHERE course_id = ? AND ' . ($parentId === null ? 'parent_node_id IS NULL' : 'parent_node_id = ?')
        );
        $max->execute($parentId === null ? array($courseId) : array($courseId, $parentId));
        $st = $this->pdo->prepare(
            'INSERT INTO theory_course_outline_nodes
                (course_id, parent_node_id, node_type, title, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $courseId, $parentId, trim((string)($input['node_type'] ?? 'topic')),
            $title, (int)$max->fetchColumn() + 10,
        ));
        return $this->requireOutlineNode((int)$this->pdo->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function createStructuredSlide(
        int $lessonId,
        int $templateVersionId,
        ?int $outlineNodeId = null
    ): array {
        $ancestry = $this->requireLessonAncestry($lessonId);
        $this->studio->assertProgramMutable((int)$ancestry['program_id']);
        $version = $this->getTemplateVersion($templateVersionId);
        $template = $this->getTemplate((int)$version['template_id']);
        if ($template['deleted_at'] !== null) {
            throw new TheoryStudioException('VALIDATION', 'Deleted templates cannot create slides.', 400);
        }
        if ($outlineNodeId !== null) {
            $node = $this->requireOutlineNode($outlineNodeId);
            if ((int)$node['course_id'] !== (int)$ancestry['course_id'] || (int)$node['is_deleted'] !== 0) {
                throw new TheoryStudioException('VALIDATION', 'Outline node does not belong to the slide course.', 400);
            }
        }
        $max = $this->pdo->prepare(
            'SELECT COALESCE(MAX(page_number), 0) FROM slides WHERE lesson_id = ? AND COALESCE(is_deleted, 0) = 0'
        );
        $max->execute(array($lessonId));
        $page = (int)$max->fetchColumn() + 1;

        $this->pdo->beginTransaction();
        try {
            $slide = $this->pdo->prepare(
                "INSERT INTO slides
                    (lesson_id, page_number, template_key, image_path, is_deleted, source_category)
                 VALUES (?, ?, ?, '', 0, 'structured')"
            );
            $slide->execute(array($lessonId, $page, (string)$template['template_key']));
            $slideId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO theory_structured_slides
                    (slide_id, template_version_id, outline_node_id, content_revision)
                 VALUES (?, ?, ?, 1)'
            )->execute(array($slideId, $templateVersionId, $outlineNodeId));
            $this->writeEnglishProjection($slideId, $templateVersionId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->loadStructuredSlide($slideId);
    }

    /** @return array<string,mixed> */
    public function loadStructuredSlide(int $slideId): array
    {
        $st = $this->pdo->prepare(
            "SELECT s.id, s.lesson_id, s.page_number, s.template_key, s.image_path,
                    s.source_category, s.is_deleted, ss.template_version_id,
                    ss.outline_node_id, ss.content_revision
             FROM slides s
             JOIN theory_structured_slides ss ON ss.slide_id = s.id
             WHERE s.id = ? AND s.source_category = 'structured' LIMIT 1"
        );
        $st->execute(array($slideId));
        $row = $st->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('STRUCTURED_ONLY', 'The requested slide is not a structured slide.', 409);
        }
        $row['template_version'] = $this->getTemplateVersion((int)$row['template_version_id']);
        $text = $this->pdo->prepare(
            'SELECT placeholder_id, lang, plain_text, content_json
             FROM theory_structured_slide_text_values WHERE slide_id = ?
             ORDER BY placeholder_id, lang'
        );
        $text->execute(array($slideId));
        $row['text_values'] = $text->fetchAll() ?: array();
        $media = $this->pdo->prepare(
            'SELECT placeholder_id, media_library_id, content_json
             FROM theory_structured_slide_media_values WHERE slide_id = ? ORDER BY placeholder_id'
        );
        $media->execute(array($slideId));
        $row['media_values'] = $media->fetchAll() ?: array();
        return $row;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveStructuredSlide(int $slideId, int $expectedRevision, array $input): array
    {
        $ancestry = $this->requireSlideAncestry($slideId);
        $this->studio->assertProgramMutable((int)$ancestry['program_id']);
        $current = $this->loadStructuredSlide($slideId);
        if ((int)$current['is_deleted'] !== 0) {
            throw new TheoryStudioException('NOT_FOUND', 'Structured slide is deleted.', 404);
        }
        if ((int)$current['content_revision'] !== $expectedRevision) {
            throw new TheoryStudioException(
                'CONTENT_REVISION_CONFLICT',
                'The slide changed after it was loaded. Reload before saving.',
                409
            );
        }
        $versionId = (int)$current['template_version_id'];
        $placeholders = $this->placeholderMap($versionId);
        $textValues = $this->normalizeTextValues($input['text_values'] ?? array(), $placeholders);
        $mediaValues = $this->normalizeMediaValues($input['media_values'] ?? array(), $placeholders);
        $this->assertRequiredValues($placeholders, $textValues, $mediaValues);
        $outlineNodeId = array_key_exists('outline_node_id', $input) && $input['outline_node_id'] !== null
            ? (int)$input['outline_node_id']
            : ($input['outline_node_id'] ?? $current['outline_node_id']);
        if ($outlineNodeId !== null) {
            $node = $this->requireOutlineNode((int)$outlineNodeId);
            if ((int)$node['course_id'] !== (int)$ancestry['course_id'] || (int)$node['is_deleted'] !== 0) {
                throw new TheoryStudioException('VALIDATION', 'Outline node does not belong to the slide course.', 400);
            }
        }

        $this->pdo->beginTransaction();
        try {
            $bump = $this->pdo->prepare(
                'UPDATE theory_structured_slides
                 SET outline_node_id = ?, content_revision = content_revision + 1
                 WHERE slide_id = ? AND content_revision = ?'
            );
            $bump->execute(array($outlineNodeId, $slideId, $expectedRevision));
            if ($bump->rowCount() !== 1) {
                throw new TheoryStudioException(
                    'CONTENT_REVISION_CONFLICT',
                    'The slide changed after it was loaded. Reload before saving.',
                    409
                );
            }
            $this->pdo->prepare('DELETE FROM theory_structured_slide_text_values WHERE slide_id = ?')
                ->execute(array($slideId));
            $this->pdo->prepare('DELETE FROM theory_structured_slide_media_values WHERE slide_id = ?')
                ->execute(array($slideId));
            $textInsert = $this->pdo->prepare(
                'INSERT INTO theory_structured_slide_text_values
                    (slide_id, placeholder_id, lang, plain_text, content_json)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($textValues as $value) {
                $textInsert->execute(array(
                    $slideId, $value['placeholder_id'], $value['lang'],
                    $value['plain_text'], $value['content_json'],
                ));
            }
            $mediaInsert = $this->pdo->prepare(
                'INSERT INTO theory_structured_slide_media_values
                    (slide_id, placeholder_id, media_library_id, content_json)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($mediaValues as $value) {
                $this->requireMedia((int)$value['media_library_id']);
                $mediaInsert->execute(array(
                    $slideId, $value['placeholder_id'], $value['media_library_id'], $value['content_json'],
                ));
            }
            $this->writeEnglishProjection($slideId, $versionId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->loadStructuredSlide($slideId);
    }

    /** @param list<int> $orderedSlideIds */
    public function reorderStructuredSlides(int $lessonId, array $orderedSlideIds): void
    {
        $ancestry = $this->requireLessonAncestry($lessonId);
        $this->studio->assertProgramMutable((int)$ancestry['program_id']);
        $st = $this->pdo->prepare(
            "SELECT s.id FROM slides s
             JOIN theory_structured_slides ss ON ss.slide_id = s.id
             WHERE s.lesson_id = ? AND s.source_category = 'structured' AND COALESCE(s.is_deleted, 0) = 0
             ORDER BY s.page_number, s.id"
        );
        $st->execute(array($lessonId));
        $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: array());
        $ordered = array_map('intval', $orderedSlideIds);
        $a = $existing;
        $b = $ordered;
        sort($a);
        sort($b);
        if ($a !== $b || count($ordered) !== count(array_unique($ordered))) {
            throw new TheoryStudioException(
                'VALIDATION',
                'The slide order must include every active structured slide in the lesson.',
                400
            );
        }
        $nonStructured = $this->pdo->prepare(
            "SELECT COUNT(*) FROM slides
             WHERE lesson_id = ? AND COALESCE(is_deleted, 0) = 0 AND source_category <> 'structured'"
        );
        $nonStructured->execute(array($lessonId));
        if ((int)$nonStructured->fetchColumn() !== 0) {
            throw new TheoryStudioException('STRUCTURED_ONLY', 'Mixed legacy and structured slide ordering is not supported.', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE slides SET page_number = ? WHERE id = ? AND lesson_id = ?');
            foreach ($ordered as $index => $id) {
                $update->execute(array($index + 1, $id, $lessonId));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function softDeleteStructuredSlide(int $slideId, int $expectedRevision): void
    {
        $ancestry = $this->requireSlideAncestry($slideId);
        $this->studio->assertProgramMutable((int)$ancestry['program_id']);
        $slide = $this->loadStructuredSlide($slideId);
        $this->pdo->beginTransaction();
        try {
            $bump = $this->pdo->prepare(
                'UPDATE theory_structured_slides SET content_revision = content_revision + 1
                 WHERE slide_id = ? AND content_revision = ?'
            );
            $bump->execute(array($slideId, $expectedRevision));
            if ($bump->rowCount() !== 1) {
                throw new TheoryStudioException('CONTENT_REVISION_CONFLICT', 'The slide changed after it was loaded.', 409);
            }
            $this->pdo->prepare("UPDATE slides SET is_deleted = 1 WHERE id = ? AND source_category = 'structured'")
                ->execute(array($slideId));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Safely creates the default system template on empty SQLite/test databases. @return array<string,mixed> */
    public function seedSystemTemplate(): array
    {
        $find = $this->pdo->prepare('SELECT id FROM theory_slide_templates WHERE template_key = ? LIMIT 1');
        $find->execute(array('SYSTEM_TITLE_BODY'));
        $id = (int)$find->fetchColumn();
        if ($id > 0) {
            return $this->getTemplate($id);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO theory_slide_templates
                    (template_key, name, description, owning_program_id, is_system)
                 VALUES ('SYSTEM_TITLE_BODY', 'Title and body',
                         'System 1600x900 title and body template', NULL, 1)"
            )->execute();
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO theory_slide_template_versions
                    (template_id, version_number, canvas_width, canvas_height)
                 VALUES (?, 1, 1600, 900)'
            )->execute(array($id));
            $versionId = (int)$this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO theory_slide_template_placeholders
                    (template_version_id, placeholder_key, content_type, semantic_role,
                     x, y, w, h, reading_order, is_required,
                     allowed_content_json, allowed_style_json, allowed_behavior_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $empty = '{}';
            $insert->execute(array($versionId, 'title', 'Text', 'heading', 120, 90, 1360, 150, 10, 1, '{"max_length":180}', $empty, $empty));
            $insert->execute(array($versionId, 'body', 'Text', 'body', 120, 280, 1360, 500, 20, 0, $empty, $empty, $empty));
            $this->pdo->prepare('UPDATE theory_slide_templates SET active_version_id = ? WHERE id = ?')
                ->execute(array($versionId, $id));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->getTemplate($id);
    }

    /** @return array<string,mixed> */
    private function requireMutableTemplate(int $templateId, int $programId): array
    {
        $template = $this->getTemplate($templateId);
        $this->studio->assertProgramMutable($programId);
        if ((int)$template['is_system'] === 1 || (int)($template['owning_program_id'] ?? 0) !== $programId) {
            throw new TheoryStudioException('LIVE_CONTENT_PROTECTED', 'This template is not mutable in that program.', 409);
        }
        if ($template['deleted_at'] !== null) {
            throw new TheoryStudioException('NOT_FOUND', 'Template not found.', 404);
        }
        return $template;
    }

    /** @return array<string,mixed> */
    private function requireLessonAncestry(int $lessonId): array
    {
        $st = $this->pdo->prepare(
            'SELECT l.id AS lesson_id, l.course_id, c.program_id
             FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ? LIMIT 1'
        );
        $st->execute(array($lessonId));
        $row = $st->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Lesson not found.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireSlideAncestry(int $slideId): array
    {
        $st = $this->pdo->prepare(
            'SELECT s.id AS slide_id, s.lesson_id, l.course_id, c.program_id
             FROM slides s
             JOIN lessons l ON l.id = s.lesson_id
             JOIN courses c ON c.id = l.course_id
             WHERE s.id = ? LIMIT 1'
        );
        $st->execute(array($slideId));
        $row = $st->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Slide not found.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireOutlineNode(int $nodeId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM theory_course_outline_nodes WHERE id = ? LIMIT 1');
        $st->execute(array($nodeId));
        $row = $st->fetch();
        if (!is_array($row)) {
            throw new TheoryStudioException('NOT_FOUND', 'Outline node not found.', 404);
        }
        return $row;
    }

    private function requireMedia(int $mediaId): void
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM ipca_training_media_library WHERE id = ? AND deleted_at_utc IS NULL LIMIT 1'
        );
        $st->execute(array($mediaId));
        if ((int)$st->fetchColumn() <= 0) {
            throw new TheoryStudioException('VALIDATION', 'Managed media asset not found.', 400);
        }
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizePlaceholders(array $rows): array
    {
        $out = array();
        $keys = array();
        $orders = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new TheoryStudioException('VALIDATION', 'Invalid placeholder definition.', 400);
            }
            $key = trim((string)($row['placeholder_key'] ?? ''));
            $type = ucfirst(strtolower(trim((string)($row['content_type'] ?? ''))));
            $role = trim((string)($row['semantic_role'] ?? ''));
            $x = (int)($row['x'] ?? -1);
            $y = (int)($row['y'] ?? -1);
            $w = (int)($row['w'] ?? 0);
            $h = (int)($row['h'] ?? 0);
            $order = (int)($row['reading_order'] ?? 0);
            if (!preg_match('/^[a-z][a-z0-9_]{0,95}$/', $key)
                || !in_array($type, array('Text', 'Image', 'Video'), true)
                || $role === '' || $x < 0 || $y < 0 || $w <= 0 || $h <= 0
                || $x + $w > 1600 || $y + $h > 900 || $order <= 0
                || isset($keys[$key]) || isset($orders[$order])
            ) {
                throw new TheoryStudioException('VALIDATION', 'Invalid or duplicate placeholder definition.', 400);
            }
            $keys[$key] = true;
            $orders[$order] = true;
            $out[] = array(
                'placeholder_key' => $key,
                'content_type' => $type,
                'semantic_role' => $role,
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'reading_order' => $order,
                'is_required' => !empty($row['required']) || !empty($row['is_required']) ? 1 : 0,
                'allowed_content_json' => $this->canonicalJson($row['allowed_content'] ?? $row['allowed_content_json'] ?? array()),
                'allowed_style_json' => $this->canonicalJson($row['allowed_style'] ?? $row['allowed_style_json'] ?? array()),
                'allowed_behavior_json' => $this->canonicalJson($row['allowed_behavior'] ?? $row['allowed_behavior_json'] ?? array()),
            );
        }
        return $out;
    }

    /**
     * @param array<mixed> $rows
     * @return list<array{orientation:string,position:int,is_locked:int}>
     */
    private function normalizeGuides(array $rows): array
    {
        $out = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new TheoryStudioException('VALIDATION', 'Invalid Template guide.', 400);
            }
            $orientation = strtolower(trim((string)($row['orientation'] ?? '')));
            $position = (int)($row['position'] ?? -1);
            $limit = $orientation === 'vertical'
                ? 1600
                : ($orientation === 'horizontal' ? 900 : -1);
            $key = $orientation . ':' . $position;
            if ($limit < 0 || $position < 0 || $position > $limit || isset($seen[$key])) {
                throw new TheoryStudioException(
                    'VALIDATION',
                    'Invalid or duplicate Template guide.',
                    400
                );
            }
            $seen[$key] = true;
            $out[] = array(
                'orientation' => $orientation,
                'position' => $position,
                'is_locked' => !empty($row['is_locked']) ? 1 : 0,
            );
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function placeholderMap(int $versionId): array
    {
        $version = $this->getTemplateVersion($versionId);
        $out = array();
        foreach ($version['placeholders'] as $placeholder) {
            $out[(string)$placeholder['placeholder_key']] = $placeholder;
            $out[(string)$placeholder['id']] = $placeholder;
        }
        return $out;
    }

    /**
     * @param mixed $input
     * @param array<string,array<string,mixed>> $placeholders
     * @return list<array<string,mixed>>
     */
    private function normalizeTextValues(mixed $input, array $placeholders): array
    {
        if (!is_array($input)) {
            throw new TheoryStudioException('VALIDATION', 'Text values must be an array.', 400);
        }
        $out = array();
        foreach ($input as $outerKey => $value) {
            $rows = is_array($value) && array_key_exists('placeholder_id', $value)
                ? array($value)
                : array_map(
                    static fn(mixed $text, mixed $lang): array => array(
                        'placeholder_key' => (string)$outerKey, 'lang' => (string)$lang, 'plain_text' => $text,
                    ),
                    is_array($value) ? $value : array('en' => $value),
                    array_keys(is_array($value) ? $value : array('en' => $value))
                );
            foreach ($rows as $row) {
                $lookup = (string)($row['placeholder_id'] ?? $row['placeholder_key'] ?? $outerKey);
                $placeholder = $placeholders[$lookup] ?? null;
                $lang = strtolower(trim((string)($row['lang'] ?? 'en')));
                if (!is_array($placeholder) || $placeholder['content_type'] !== 'Text'
                    || !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $lang)
                ) {
                    throw new TheoryStudioException('VALIDATION', 'Invalid localized text value.', 400);
                }
                $plain = (string)($row['plain_text'] ?? '');
                $out[] = array(
                    'placeholder_id' => (int)$placeholder['id'],
                    'lang' => $lang,
                    'plain_text' => $plain,
                    'content_json' => $this->canonicalJson($row['content_json'] ?? array('text' => $plain)),
                );
            }
        }
        return $out;
    }

    /**
     * @param mixed $input
     * @param array<string,array<string,mixed>> $placeholders
     * @return list<array<string,mixed>>
     */
    private function normalizeMediaValues(mixed $input, array $placeholders): array
    {
        if (!is_array($input)) {
            throw new TheoryStudioException('VALIDATION', 'Media values must be an array.', 400);
        }
        $out = array();
        foreach ($input as $outerKey => $value) {
            $row = is_array($value) ? $value : array('media_library_id' => $value);
            $lookup = (string)($row['placeholder_id'] ?? $row['placeholder_key'] ?? $outerKey);
            $placeholder = $placeholders[$lookup] ?? null;
            if (!is_array($placeholder) || !in_array($placeholder['content_type'], array('Image', 'Video'), true)) {
                throw new TheoryStudioException('VALIDATION', 'Invalid managed media value.', 400);
            }
            $out[] = array(
                'placeholder_id' => (int)$placeholder['id'],
                'media_library_id' => (int)($row['media_library_id'] ?? 0),
                'content_json' => $this->canonicalJson($row['content_json'] ?? array()),
            );
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $placeholders
     * @param list<array<string,mixed>> $textValues
     * @param list<array<string,mixed>> $mediaValues
     */
    private function assertRequiredValues(array $placeholders, array $textValues, array $mediaValues): void
    {
        $textPresent = array();
        foreach ($textValues as $value) {
            if (trim((string)$value['plain_text']) !== '') {
                $textPresent[(int)$value['placeholder_id']] = true;
            }
        }
        $mediaPresent = array();
        foreach ($mediaValues as $value) {
            if ((int)$value['media_library_id'] > 0) {
                $mediaPresent[(int)$value['placeholder_id']] = true;
            }
        }
        $seen = array();
        foreach ($placeholders as $placeholder) {
            $id = (int)$placeholder['id'];
            if (isset($seen[$id]) || (int)$placeholder['is_required'] !== 1) {
                continue;
            }
            $seen[$id] = true;
            $present = $placeholder['content_type'] === 'Text'
                ? isset($textPresent[$id])
                : isset($mediaPresent[$id]);
            if (!$present) {
                throw new TheoryStudioException(
                    'VALIDATION',
                    'Required placeholder ' . $placeholder['placeholder_key'] . ' has no value.',
                    400
                );
            }
        }
    }

    private function writeEnglishProjection(int $slideId, int $versionId): void
    {
        $st = $this->pdo->prepare(
            "SELECT p.placeholder_key, p.semantic_role, p.reading_order,
                    COALESCE(v.plain_text, '') AS plain_text, COALESCE(v.content_json, '{}') AS value_json
             FROM theory_slide_template_placeholders p
             LEFT JOIN theory_structured_slide_text_values v
               ON v.placeholder_id = p.id AND v.slide_id = ? AND v.lang = 'en'
             WHERE p.template_version_id = ? AND p.content_type = 'Text'
             ORDER BY p.reading_order, p.id"
        );
        $st->execute(array($slideId, $versionId));
        $projection = array();
        $plain = array();
        foreach ($st->fetchAll() ?: array() as $row) {
            $text = (string)$row['plain_text'];
            $projection[] = array(
                'placeholder_key' => (string)$row['placeholder_key'],
                'semantic_role' => (string)$row['semantic_role'],
                'reading_order' => (int)$row['reading_order'],
                'plain_text' => $text,
                'content' => json_decode((string)$row['value_json'], true) ?: array(),
            );
            if ($text !== '') {
                $plain[] = $text;
            }
        }
        $json = $this->canonicalJson(array('schema' => 'theory_structured_slide_projection_v1', 'placeholders' => $projection));
        $exists = $this->pdo->prepare("SELECT id FROM slide_content WHERE slide_id = ? AND lang = 'en' LIMIT 1");
        $exists->execute(array($slideId));
        $id = (int)$exists->fetchColumn();
        if ($id > 0) {
            $this->pdo->prepare('UPDATE slide_content SET plain_text = ?, content_json = ? WHERE id = ?')
                ->execute(array(implode("\n\n", $plain), $json, $id));
        } else {
            $this->pdo->prepare(
                "INSERT INTO slide_content (slide_id, lang, plain_text, content_json) VALUES (?, 'en', ?, ?)"
            )->execute(array($slideId, implode("\n\n", $plain), $json));
        }
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new TheoryStudioException('VALIDATION', 'Invalid JSON value.', 400);
            }
            $value = $decoded;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new TheoryStudioException('VALIDATION', 'Value cannot be encoded as JSON.', 400);
        }
        return $json;
    }
}
