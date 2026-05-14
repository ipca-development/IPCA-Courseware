<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/resource_library_ai.php';

final class LessonSummaryBlueprintService
{
    private PDO $pdo;

    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,bool> */
    private array $columnCache = [];

    /** @var array<string,array<string,mixed>> */
    private const UNVERIFIED_ACS = [
        'acs_private_pilot' => [
            'id' => 'acs_private_pilot',
            'label' => 'ACS Private Pilot',
            'verification_status' => 'temporary_official',
        ],
        'acs_instrument_rating' => [
            'id' => 'acs_instrument_rating',
            'label' => 'ACS Instrument Rating',
            'verification_status' => 'temporary_official',
        ],
        'acs_commercial_pilot' => [
            'id' => 'acs_commercial_pilot',
            'label' => 'ACS Commercial Pilot',
            'verification_status' => 'temporary_official',
        ],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function schemaReady(): bool
    {
        return $this->tableExists('lesson_summary_blueprints')
            && $this->tableExists('lesson_summary_blueprint_versions');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function liveResources(): array
    {
        if (!$this->tableExists('resource_library_editions')) {
            return [];
        }

        $select = 'id, title, revision_code, revision_date, status, work_code';
        $select .= $this->columnExists('resource_library_editions', 'resource_type') ? ', resource_type' : ", 'resource' AS resource_type";
        $select .= $this->columnExists('resource_library_editions', 'extra_config_json') ? ', extra_config_json' : ", NULL AS extra_config_json";

        $stmt = $this->pdo->query("
            SELECT {$select}
            FROM resource_library_editions
            WHERE status = 'live'
            ORDER BY sort_order ASC, revision_date DESC, id DESC
        ");

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function unverifiedResources(): array
    {
        return array_values(self::UNVERIFIED_ACS);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLessonsForCourse(int $courseId): array
    {
        if ($courseId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT
                l.id,
                l.external_lesson_id,
                l.title,
                l.course_id,
                c.title AS course_title,
                COUNT(CASE WHEN COALESCE(s.is_deleted, 0) = 0 THEN 1 END) AS active_slide_count
            FROM lessons l
            INNER JOIN courses c ON c.id = l.course_id
            LEFT JOIN slides s ON s.lesson_id = l.id
            WHERE l.course_id = ?
            GROUP BY l.id, l.external_lesson_id, l.title, l.course_id, c.title, l.sort_order
            ORDER BY l.sort_order ASC, l.external_lesson_id ASC, l.id ASC
        ");
        $stmt->execute([$courseId]);

        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $lessonId = (int)$row['id'];
            if ($this->schemaReady()) {
                $this->markStaleIfNeeded($lessonId);
            }
            $meta = $this->fetchBlueprintMeta($lessonId);
            $out[] = [
                'id' => $lessonId,
                'lesson_id' => $lessonId,
                'title' => (string)($row['title'] ?? ''),
                'external_lesson_id' => (string)($row['external_lesson_id'] ?? ''),
                'course_id' => (int)($row['course_id'] ?? 0),
                'course_title' => (string)($row['course_title'] ?? ''),
                'active_slide_count' => (int)($row['active_slide_count'] ?? 0),
                'blueprint_id' => (int)($meta['blueprint_id'] ?? 0),
                'active_version_id' => (int)($meta['active_version_id'] ?? 0),
                'active_version_number' => $meta['active_version_number'] !== null ? (int)$meta['active_version_number'] : null,
                'status' => (string)($meta['status'] ?? 'missing'),
                'confidence' => $meta['confidence'] !== null ? (float)$meta['confidence'] : null,
                'warning_count' => (int)($meta['warning_count'] ?? 0),
                'source_hash_short' => (string)($meta['source_hash_short'] ?? ''),
                'last_generated' => (string)($meta['last_generated'] ?? ''),
                'last_updated' => (string)($meta['last_updated'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function lessonDetail(int $lessonId): array
    {
        $this->markStaleIfNeeded($lessonId);

        $lesson = $this->fetchLesson($lessonId);
        if (!$lesson) {
            throw new RuntimeException('Lesson not found');
        }

        $bp = $this->fetchBlueprintRow($lessonId);
        $versions = [];
        if ($bp) {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM lesson_summary_blueprint_versions
                WHERE blueprint_id = ?
                ORDER BY version_number DESC
            ");
            $stmt->execute([(int)$bp['id']]);
            $versions = $stmt->fetchAll() ?: [];
        }

        $activeVersion = null;
        if ($bp && (int)($bp['active_version_id'] ?? 0) > 0) {
            $activeVersion = $this->fetchVersion((int)$bp['active_version_id']);
        }

        return [
            'lesson' => $lesson,
            'blueprint' => $bp,
            'active_version' => $this->shapeVersionForUi($activeVersion),
            'versions' => array_map(fn (array $v): array => $this->shapeVersionForUi($v), $versions),
            'active_slides' => $this->fetchLessonSourceData($lessonId)['slides'],
            'current_hash' => $this->computeSourceHash($lessonId),
        ];
    }

    /**
     * @param list<int> $verifiedResourceIds
     * @param list<string> $unverifiedResourceIds
     * @return array<string,mixed>
     */
    public function generateLesson(
        int $lessonId,
        string $generationReason,
        array $verifiedResourceIds,
        array $unverifiedResourceIds
    ): array {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Blueprint tables are missing. Run scripts/sql/2026_05_14_lesson_summary_blueprints.sql.');
        }
        $generationReason = $this->normalizeReason($generationReason);
        $blueprintId = $this->ensureBlueprintParent($lessonId);
        $sourceHash = $this->computeSourceHash($lessonId);

        try {
            $sourceData = $this->fetchLessonSourceData($lessonId);
            if (!$sourceData['lesson']) {
                throw new RuntimeException('Lesson not found');
            }
            if (count($sourceData['slides']) <= 0) {
                throw new RuntimeException('Lesson has no active slides');
            }

            $resourceContext = $this->buildOfficialResourceContext(
                $sourceData,
                $verifiedResourceIds,
                $unverifiedResourceIds
            );
            $rawBlueprint = $this->callBlueprintAi($sourceData, $resourceContext);
            $blueprint = $this->normalizeBlueprint($rawBlueprint, $sourceData, $resourceContext);
            $validationWarnings = $this->validateBlueprint($blueprint, $sourceData, $resourceContext);
            $warnings = array_values(array_merge(
                $this->warningsFromBlueprint($blueprint),
                $validationWarnings
            ));
            $blueprint['warnings'] = $warnings;

            $confidence = $this->clampConfidence((float)($blueprint['confidence'] ?? 0.0));
            $hasSevere = $this->hasSevereWarnings($warnings);
            $targetStatus = ($confidence >= 0.80 && !$hasSevere) ? 'active' : 'draft';
            $versionId = $this->insertVersion(
                $blueprintId,
                $lessonId,
                $targetStatus,
                $blueprint,
                $sourceHash,
                $confidence,
                $warnings,
                'ai',
                $generationReason
            );

            if ($targetStatus === 'active') {
                $this->activateVersion($versionId);
            } else {
                $this->setParentStatusFromVersion($blueprintId, 'draft');
            }

            return [
                'ok' => true,
                'lesson_id' => $lessonId,
                'version_id' => $versionId,
                'status' => $targetStatus,
                'confidence' => $confidence,
                'warning_count' => count($warnings),
                'warnings' => $warnings,
            ];
        } catch (Throwable $e) {
            $errorBlueprint = $this->failedBlueprint($lessonId, $e->getMessage());
            $versionId = $this->insertVersion(
                $blueprintId,
                $lessonId,
                'failed',
                $errorBlueprint,
                $sourceHash,
                null,
                [['severity' => 'severe', 'message' => $e->getMessage()]],
                'ai',
                $generationReason
            );
            $this->setParentStatusFromVersion($blueprintId, 'failed');

            return [
                'ok' => false,
                'lesson_id' => $lessonId,
                'version_id' => $versionId,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function activateVersion(int $versionId): void
    {
        $version = $this->fetchVersion($versionId);
        if (!$version) {
            throw new RuntimeException('Version not found');
        }
        if ((string)$version['status'] === 'failed') {
            throw new RuntimeException('Failed versions cannot be activated');
        }

        $blueprintId = (int)$version['blueprint_id'];
        $lessonId = (int)$version['lesson_id'];
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $parent = $this->fetchBlueprintRow($lessonId, true);
            $previousActive = $parent ? (int)($parent['active_version_id'] ?? 0) : 0;
            if ($previousActive > 0 && $previousActive !== $versionId) {
                $stmt = $this->pdo->prepare("
                    UPDATE lesson_summary_blueprint_versions
                    SET status = 'superseded', superseded_at = ?, updated_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$now, $now, $previousActive]);
            }

            $stmt = $this->pdo->prepare("
                UPDATE lesson_summary_blueprint_versions
                SET status = 'active', activated_at = COALESCE(activated_at, ?), superseded_at = NULL, updated_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$now, $now, $versionId]);

            $stmt = $this->pdo->prepare("
                UPDATE lesson_summary_blueprints
                SET active_version_id = ?, current_status = 'active', updated_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$versionId, $now, $blueprintId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Activate the newest non-failed version for a lesson.
     *
     * @return array<string,mixed>
     */
    public function activateLatestVersionForLesson(int $lessonId): array
    {
        if ($lessonId <= 0) {
            throw new RuntimeException('Lesson id is required');
        }
        if (!$this->schemaReady()) {
            throw new RuntimeException('Blueprint tables are missing. Run scripts/sql/2026_05_14_lesson_summary_blueprints.sql.');
        }

        $stmt = $this->pdo->prepare("
            SELECT v.*
            FROM lesson_summary_blueprints b
            INNER JOIN lesson_summary_blueprint_versions v ON v.blueprint_id = b.id
            WHERE b.lesson_id = ?
              AND v.status <> 'failed'
            ORDER BY v.version_number DESC
            LIMIT 1
        ");
        $stmt->execute([$lessonId]);
        $version = $stmt->fetch();
        if (!is_array($version)) {
            throw new RuntimeException('No eligible blueprint version found for activation');
        }

        $this->activateVersion((int)$version['id']);
        $activated = $this->fetchVersion((int)$version['id']);

        return [
            'lesson_id' => $lessonId,
            'version' => $this->shapeVersionForUi($activated),
        ];
    }

    /**
     * @param list<int> $lessonIds
     * @return array{activated:list<array<string,mixed>>,failed:list<array<string,mixed>>}
     */
    public function activateLatestVersionsForLessons(array $lessonIds): array
    {
        $lessonIds = array_values(array_unique(array_filter(array_map('intval', $lessonIds), static fn (int $id): bool => $id > 0)));
        $activated = [];
        $failed = [];

        foreach ($lessonIds as $lessonId) {
            try {
                $activated[] = $this->activateLatestVersionForLesson($lessonId);
            } catch (Throwable $e) {
                $failed[] = [
                    'lesson_id' => $lessonId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'activated' => $activated,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function compareVersions(int $activeVersionId, int $selectedVersionId): array
    {
        $active = $this->fetchVersion($activeVersionId);
        $selected = $this->fetchVersion($selectedVersionId);
        if (!$active || !$selected) {
            throw new RuntimeException('Both versions are required for comparison');
        }

        $aJson = $this->decodeJson((string)$active['blueprint_json']);
        $sJson = $this->decodeJson((string)$selected['blueprint_json']);
        $aSections = $this->sectionMap($aJson);
        $sSections = $this->sectionMap($sJson);

        $added = array_values(array_diff(array_keys($sSections), array_keys($aSections)));
        $removed = array_values(array_diff(array_keys($aSections), array_keys($sSections)));
        $changedTitles = [];
        $changedMappings = [];
        $changedConcepts = [];

        foreach (array_intersect(array_keys($aSections), array_keys($sSections)) as $sectionId) {
            $a = $aSections[$sectionId];
            $s = $sSections[$sectionId];
            if ((string)($a['title'] ?? '') !== (string)($s['title'] ?? '')) {
                $changedTitles[] = $sectionId;
            }
            if (json_encode($a['covered_by_slides'] ?? []) !== json_encode($s['covered_by_slides'] ?? [])) {
                $changedMappings[] = $sectionId;
            }
            if (json_encode($a['required_concepts'] ?? []) !== json_encode($s['required_concepts'] ?? [])) {
                $changedConcepts[] = $sectionId;
            }
        }

        return [
            'active_version' => $this->shapeVersionForUi($active),
            'selected_version' => $this->shapeVersionForUi($selected),
            'summary' => [
                'added_sections' => $added,
                'removed_sections' => $removed,
                'changed_section_titles' => $changedTitles,
                'changed_slide_mappings' => $changedMappings,
                'changed_required_concepts' => $changedConcepts,
            ],
            'active_structure' => array_values($aSections),
            'selected_structure' => array_values($sSections),
        ];
    }

    private function tableExists(string $table): bool
    {
        $key = strtolower($table);
        if (array_key_exists($key, $this->tableCache)) {
            return $this->tableCache[$key];
        }
        try {
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            $this->tableCache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $this->tableCache[$key] = false;
        }

        return $this->tableCache[$key];
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            $this->columnCache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $this->columnCache[$key] = false;
        }

        return $this->columnCache[$key];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchLesson(int $lessonId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.course_id, l.external_lesson_id, l.title, c.title AS course_title, p.program_key
            FROM lessons l
            INNER JOIN courses c ON c.id = l.course_id
            INNER JOIN programs p ON p.id = c.program_id
            WHERE l.id = ?
            LIMIT 1
        ");
        $stmt->execute([$lessonId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchBlueprintRow(int $lessonId, bool $forUpdate = false): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }
        $sql = 'SELECT * FROM lesson_summary_blueprints WHERE lesson_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$lessonId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchBlueprintMeta(int $lessonId): array
    {
        if (!$this->schemaReady()) {
            return [
                'blueprint_id' => 0,
                'active_version_id' => 0,
                'active_version_number' => null,
                'status' => 'missing',
                'confidence' => null,
                'warning_count' => 0,
                'source_hash_short' => '',
                'last_generated' => '',
                'last_updated' => '',
            ];
        }
        $stmt = $this->pdo->prepare("
            SELECT b.id AS blueprint_id,
                   b.active_version_id,
                   b.current_status,
                   b.updated_at AS parent_updated_at,
                   v.version_number,
                   v.confidence_score,
                   v.warnings_json,
                   v.source_enrichment_hash,
                   v.generated_at,
                   v.updated_at AS version_updated_at
            FROM lesson_summary_blueprints b
            LEFT JOIN lesson_summary_blueprint_versions v ON v.id = b.active_version_id
            WHERE b.lesson_id = ?
            LIMIT 1
        ");
        $stmt->execute([$lessonId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'blueprint_id' => 0,
                'active_version_id' => 0,
                'active_version_number' => null,
                'status' => 'missing',
                'confidence' => null,
                'warning_count' => 0,
                'source_hash_short' => '',
                'last_generated' => '',
                'last_updated' => '',
            ];
        }

        $warnings = $this->decodeJson((string)($row['warnings_json'] ?? '[]'));

        return [
            'blueprint_id' => (int)$row['blueprint_id'],
            'active_version_id' => (int)($row['active_version_id'] ?? 0),
            'active_version_number' => $row['version_number'] !== null ? (int)$row['version_number'] : null,
            'status' => (string)($row['current_status'] ?? 'missing'),
            'confidence' => $row['confidence_score'] !== null ? (float)$row['confidence_score'] : null,
            'warning_count' => is_array($warnings) ? count($warnings) : 0,
            'source_hash_short' => substr((string)($row['source_enrichment_hash'] ?? ''), 0, 10),
            'last_generated' => (string)($row['generated_at'] ?? ''),
            'last_updated' => (string)($row['version_updated_at'] ?? $row['parent_updated_at'] ?? ''),
        ];
    }

    private function ensureBlueprintParent(int $lessonId): int
    {
        $existing = $this->fetchBlueprintRow($lessonId);
        if ($existing) {
            return (int)$existing['id'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_summary_blueprints (lesson_id, current_status, created_at, updated_at)
            VALUES (?, 'missing', ?, ?)
        ");
        $stmt->execute([$lessonId, $now, $now]);

        return (int)$this->pdo->lastInsertId();
    }

    private function setParentStatusFromVersion(int $blueprintId, string $status): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE lesson_summary_blueprints
            SET current_status = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, gmdate('Y-m-d H:i:s'), $blueprintId]);
    }

    private function markStaleIfNeeded(int $lessonId): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $bp = $this->fetchBlueprintRow($lessonId);
        if (!$bp || (int)($bp['active_version_id'] ?? 0) <= 0) {
            return;
        }
        $version = $this->fetchVersion((int)$bp['active_version_id']);
        if (!$version || (string)($version['status'] ?? '') === 'failed') {
            return;
        }
        $currentHash = $this->computeSourceHash($lessonId);
        $storedHash = (string)($version['source_enrichment_hash'] ?? '');
        if ($currentHash === '' || hash_equals($storedHash, $currentHash)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE lesson_summary_blueprint_versions
            SET status = 'stale', updated_at = ?
            WHERE id = ? AND status IN ('active','draft')
        ");
        $stmt->execute([$now, (int)$version['id']]);

        $stmt = $this->pdo->prepare("
            UPDATE lesson_summary_blueprints
            SET current_status = 'stale', updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$now, (int)$bp['id']]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchLessonSourceData(int $lessonId): array
    {
        $lesson = $this->fetchLesson($lessonId);
        $slides = [];

        $slideUpdated = $this->columnExists('slides', 'updated_at') ? 's.updated_at' : "'' AS updated_at";
        $slideTitle = $this->columnExists('slides', 'title') ? 's.title AS slide_title' : "'' AS slide_title";
        $aiJoin = $this->tableExists('slide_ai_outputs')
            ? "LEFT JOIN slide_ai_outputs sao ON sao.slide_id = s.id AND sao.status = 'approved'"
            : '';
        $aiSelect = $this->tableExists('slide_ai_outputs')
            ? "COALESCE(sao.summary, '') AS ai_summary, " . ($this->columnExists('slide_ai_outputs', 'updated_at') ? "COALESCE(sao.updated_at, '')" : "''") . ' AS ai_updated_at'
            : "'' AS ai_summary, '' AS ai_updated_at";
        $contentUpdated = $this->columnExists('slide_content', 'updated_at') ? "COALESCE(sc.updated_at, '')" : "''";
        $enrichmentUpdated = $this->columnExists('slide_enrichment', 'updated_at') ? "COALESCE(se.updated_at, '')" : "''";

        $stmt = $this->pdo->prepare("
            SELECT
                s.id,
                s.page_number,
                s.image_path,
                {$slideTitle},
                {$slideUpdated},
                COALESCE(sc.plain_text, '') AS plain_text,
                COALESCE(sc.content_json, '') AS content_json,
                {$contentUpdated} AS content_updated_at,
                COALESCE(se.narration_en, '') AS narration_en,
                COALESCE(se.narration_es, '') AS narration_es,
                {$enrichmentUpdated} AS enrichment_updated_at,
                {$aiSelect}
            FROM slides s
            LEFT JOIN slide_content sc ON sc.slide_id = s.id AND sc.lang = 'en'
            LEFT JOIN slide_enrichment se ON se.slide_id = s.id
            {$aiJoin}
            WHERE s.lesson_id = ?
              AND COALESCE(s.is_deleted, 0) = 0
            ORDER BY s.page_number ASC, s.id ASC
        ");
        $stmt->execute([$lessonId]);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $slideId = (int)$row['id'];
            $refs = $this->fetchSlideReferences($slideId);
            $slides[] = [
                'id' => $slideId,
                'slide_id' => $slideId,
                'page_number' => (int)($row['page_number'] ?? 0),
                'slide_title' => (string)($row['slide_title'] ?? ''),
                'image_path' => (string)($row['image_path'] ?? ''),
                'plain_text' => (string)($row['plain_text'] ?? ''),
                'content_json' => (string)($row['content_json'] ?? ''),
                'narration_en' => (string)($row['narration_en'] ?? ''),
                'narration_es' => (string)($row['narration_es'] ?? ''),
                'ai_summary' => (string)($row['ai_summary'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'content_updated_at' => (string)($row['content_updated_at'] ?? ''),
                'enrichment_updated_at' => (string)($row['enrichment_updated_at'] ?? ''),
                'ai_updated_at' => (string)($row['ai_updated_at'] ?? ''),
                'references' => $refs,
            ];
        }

        return [
            'lesson' => $lesson,
            'slides' => $slides,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchSlideReferences(int $slideId): array
    {
        if (!$this->tableExists('slide_references')) {
            return [];
        }
        $updated = $this->columnExists('slide_references', 'updated_at') ? ', updated_at' : ", '' AS updated_at";
        $stmt = $this->pdo->prepare("
            SELECT id, ref_type, ref_code, ref_title, confidence, notes {$updated}
            FROM slide_references
            WHERE slide_id = ?
            ORDER BY ref_type ASC, ref_code ASC, id ASC
        ");
        $stmt->execute([$slideId]);

        return $stmt->fetchAll() ?: [];
    }

    private function computeSourceHash(int $lessonId): string
    {
        $data = $this->fetchLessonSourceData($lessonId);
        $hashData = [
            'lesson' => [
                'id' => (int)($data['lesson']['id'] ?? $lessonId),
                'title' => (string)($data['lesson']['title'] ?? ''),
            ],
            'slides' => [],
        ];
        foreach ($data['slides'] as $slide) {
            $hashData['slides'][] = [
                'id' => (int)$slide['id'],
                'page_number' => (int)$slide['page_number'],
                'slide_title' => (string)$slide['slide_title'],
                'plain_text' => (string)$slide['plain_text'],
                'content_json' => (string)$slide['content_json'],
                'narration_en' => (string)$slide['narration_en'],
                'ai_summary' => (string)$slide['ai_summary'],
                'references' => $slide['references'],
                'updated_at' => [
                    (string)$slide['updated_at'],
                    (string)$slide['content_updated_at'],
                    (string)$slide['enrichment_updated_at'],
                    (string)$slide['ai_updated_at'],
                ],
            ];
        }

        return hash('sha256', json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $sourceData
     * @param list<int> $verifiedResourceIds
     * @param list<string> $unverifiedResourceIds
     * @return array<string,mixed>
     */
    private function buildOfficialResourceContext(array $sourceData, array $verifiedResourceIds, array $unverifiedResourceIds): array
    {
        $verifiedResourceIds = array_values(array_unique(array_filter(array_map('intval', $verifiedResourceIds))));
        $unverifiedResourceIds = array_values(array_intersect(
            array_map('strval', $unverifiedResourceIds),
            array_keys(self::UNVERIFIED_ACS)
        ));

        $lesson = $sourceData['lesson'] ?? [];
        $queryParts = [(string)($lesson['title'] ?? '')];
        foreach ($sourceData['slides'] as $slide) {
            $queryParts[] = (string)($slide['slide_title'] ?? '');
            $queryParts[] = (string)($slide['plain_text'] ?? '');
            $queryParts[] = (string)($slide['ai_summary'] ?? '');
            $queryParts[] = (string)($slide['narration_en'] ?? '');
        }
        $query = $this->truncateText(trim(implode("\n", array_filter($queryParts))), 6000);

        $resources = [];
        $candidates = [];
        if ($verifiedResourceIds !== []) {
            $placeholders = implode(',', array_fill(0, count($verifiedResourceIds), '?'));
            $select = 'id, title, revision_code, revision_date, status, work_code';
            $select .= $this->columnExists('resource_library_editions', 'resource_type') ? ', resource_type' : ", 'resource' AS resource_type";
            $select .= $this->columnExists('resource_library_editions', 'extra_config_json') ? ', extra_config_json' : ", NULL AS extra_config_json";
            $stmt = $this->pdo->prepare("
                SELECT {$select}
                FROM resource_library_editions
                WHERE status = 'live'
                  AND id IN ({$placeholders})
                ORDER BY sort_order ASC, revision_date DESC, id DESC
            ");
            $stmt->execute($verifiedResourceIds);
            $resources = $stmt->fetchAll() ?: [];
        }

        foreach ($resources as $resource) {
            $editionId = (int)$resource['id'];
            $label = $this->resourceLabel($resource);
            $blocks = $this->searchResourceBlocks($editionId, $query, 5);
            foreach ($blocks as $block) {
                $path = $this->sectionPath((string)($block['section_path_json'] ?? ''));
                $candidates[] = [
                    'verification_status' => 'verified',
                    'source_type' => $label,
                    'resource_id' => (int)($block['id'] ?? 0),
                    'edition_id' => $editionId,
                    'reference_code' => trim((string)($block['chapter'] ?? '') . ' ' . (string)($block['block_local_id'] ?? '')),
                    'reference_title' => (string)($resource['title'] ?? ''),
                    'reference_path' => $path,
                    'excerpt' => $this->truncateText((string)($block['body_text'] ?? ''), 900),
                    'confidence' => 0.72,
                ];
            }

            $paragraphs = $this->searchCrawlerParagraphs($editionId, $query, 5);
            foreach ($paragraphs as $p) {
                $parts = array_values(array_filter([
                    (string)($p['chapter_number'] ?? ''),
                    (string)($p['section_number'] ?? ''),
                    (string)($p['paragraph_number'] ?? ''),
                ]));
                $candidates[] = [
                    'verification_status' => 'verified',
                    'source_type' => $label,
                    'resource_id' => (int)($p['id'] ?? 0),
                    'edition_id' => $editionId,
                    'reference_code' => implode(' / ', $parts),
                    'reference_title' => (string)($p['display_title'] ?? $p['page_title'] ?? $resource['title'] ?? ''),
                    'reference_path' => (string)($p['canonical_url'] ?? ''),
                    'excerpt' => $this->truncateText((string)($p['body_text'] ?? ''), 900),
                    'confidence' => 0.72,
                ];
            }

        }

        $acsSources = [];
        foreach ($unverifiedResourceIds as $id) {
            $acsSources[] = self::UNVERIFIED_ACS[$id];
        }

        if ($acsSources !== []) {
            foreach ($sourceData['slides'] as $slide) {
                foreach (($slide['references'] ?? []) as $ref) {
                    if (strtoupper(trim((string)($ref['ref_type'] ?? ''))) !== 'ACS') {
                        continue;
                    }
                    $code = trim((string)($ref['ref_code'] ?? ''));
                    $title = trim((string)($ref['ref_title'] ?? ''));
                    if ($code === '' && $title === '') {
                        continue;
                    }
                    foreach ($acsSources as $acsSource) {
                        $candidates[] = [
                            'verification_status' => 'temporary_official',
                            'source_type' => 'ACS',
                            'resource_id' => (int)($ref['id'] ?? 0),
                            'edition_id' => 0,
                            'reference_code' => $code,
                            'reference_title' => $title !== '' ? $title : (string)$acsSource['label'],
                            'reference_path' => (string)$acsSource['label'],
                            'excerpt' => trim((string)($ref['notes'] ?? '')),
                            'confidence' => $this->clampConfidence((float)($ref['confidence'] ?? 0.75)),
                        ];
                    }
                }
            }
        }

        return [
            'verified_resources' => array_map(fn (array $r): array => [
                'id' => (int)$r['id'],
                'label' => $this->resourceLabel($r),
                'title' => (string)($r['title'] ?? ''),
                'revision_code' => (string)($r['revision_code'] ?? ''),
                'resource_type' => (string)($r['resource_type'] ?? ''),
            ], $resources),
            'acs_official_sources' => $acsSources,
            'reference_candidates' => $candidates,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchResourceBlocks(int $editionId, string $query, int $limit): array
    {
        if (!$this->tableExists('resource_library_blocks') || $editionId <= 0 || trim($query) === '') {
            return [];
        }
        $limit = max(1, min(12, $limit));
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, block_key, chapter, block_local_id, body_text, section_path_json, sort_index, block_type, `level`
                FROM resource_library_blocks
                WHERE edition_id = ?
                  AND MATCH(body_text) AGAINST (? IN NATURAL LANGUAGE MODE)
                ORDER BY MATCH(body_text) AGAINST (? IN NATURAL LANGUAGE MODE) DESC, sort_index ASC
                LIMIT ?
            ");
            $stmt->execute([$editionId, $query, $query, $limit]);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows !== []) {
                return $rows;
            }
        } catch (Throwable) {
            // Fall through to shared LIKE/FULLTEXT fallback.
        }

        $rows = rl_ai_search_resource_blocks($this->pdo, $editionId, $query, $limit);
        foreach ($rows as $i => $row) {
            $rows[$i]['id'] = 0;
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchCrawlerParagraphs(int $editionId, string $query, int $limit): array
    {
        if (!$this->tableExists('resource_library_aim_paragraphs') || $editionId <= 0 || trim($query) === '') {
            return [];
        }
        $limit = max(1, min(12, $limit));
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, chapter_number, section_number, paragraph_number, display_title, page_title,
                       canonical_url, body_text
                FROM resource_library_aim_paragraphs
                WHERE edition_id = ?
                  AND citation_status = 'active'
                  AND MATCH(body_text) AGAINST (? IN NATURAL LANGUAGE MODE)
                ORDER BY MATCH(body_text) AGAINST (? IN NATURAL LANGUAGE MODE) DESC, id ASC
                LIMIT ?
            ");
            $stmt->execute([$editionId, $query, $query, $limit]);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows !== []) {
                return $rows;
            }
        } catch (Throwable) {
            // Try LIKE below.
        }

        $tokens = preg_split('/\s+/u', strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query) ?: '')) ?: [];
        $tokens = array_values(array_slice(array_filter(array_unique($tokens), static fn (string $t): bool => strlen($t) >= 4), 0, 5));
        if ($tokens === []) {
            return [];
        }

        $clauses = [];
        $params = [$editionId];
        foreach ($tokens as $token) {
            $clauses[] = 'body_text LIKE ?';
            $params[] = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $token) . '%';
        }
        $params[] = $limit;

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, chapter_number, section_number, paragraph_number, display_title, page_title,
                       canonical_url, body_text
                FROM resource_library_aim_paragraphs
                WHERE edition_id = ?
                  AND citation_status = 'active'
                  AND (" . implode(' OR ', $clauses) . ")
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->execute($params);

            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $sourceData
     * @param array<string,mixed> $resourceContext
     * @return array<string,mixed>
     */
    private function callBlueprintAi(array $sourceData, array $resourceContext): array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'lesson_id' => ['type' => 'integer'],
                'lesson_title' => ['type' => 'string'],
                'summary_structure' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'section_id' => ['type' => 'string'],
                            'order' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'requires_student_section' => ['type' => 'boolean'],
                            'is_intro_context' => ['type' => 'boolean'],
                            'covered_by_slides' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'required_concepts' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'operational_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'allowed_coaching_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'not_required' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'do_not_ask' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'minimum_completion_check' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'student_summary_scaffold' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'heading' => ['type' => 'string'],
                                    'placeholder_bullets' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string', 'enum' => ['Item 1', 'Item 2']],
                                    ],
                                ],
                                'required' => ['heading', 'placeholder_bullets'],
                            ],
                            'completion_requirements' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'requires_why_reasoning' => ['type' => 'boolean'],
                                    'requires_pilot_action' => ['type' => 'boolean'],
                                    'minimum_student_bullets' => ['type' => 'integer'],
                                ],
                                'required' => ['requires_why_reasoning', 'requires_pilot_action', 'minimum_student_bullets'],
                            ],
                            'section_completion_behavior' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'when_complete' => ['type' => 'string'],
                                    'do_not_reopen_unless' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['when_complete', 'do_not_reopen_unless'],
                            ],
                            'official_references' => [
                                'type' => 'array',
                                'items' => $this->referenceSchema(),
                            ],
                        ],
                        'required' => [
                            'section_id',
                            'order',
                            'title',
                            'requires_student_section',
                            'is_intro_context',
                            'covered_by_slides',
                            'required_concepts',
                            'operational_focus',
                            'allowed_coaching_focus',
                            'not_required',
                            'do_not_ask',
                            'minimum_completion_check',
                            'student_summary_scaffold',
                            'completion_requirements',
                            'section_completion_behavior',
                            'official_references',
                        ],
                    ],
                ],
                'coaching_sequence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'step' => ['type' => 'integer'],
                            'section_id' => ['type' => 'string'],
                            'slide_group' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'instruction_to_student' => ['type' => 'string'],
                            'coach_mode' => ['type' => 'string'],
                            'slide_group_guidance' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'watch_instruction' => ['type' => 'string'],
                                    'why_grouped' => ['type' => 'string'],
                                    'ready_to_write_when' => ['type' => 'string'],
                                ],
                                'required' => ['watch_instruction', 'why_grouped', 'ready_to_write_when'],
                            ],
                        ],
                        'required' => [
                            'step',
                            'section_id',
                            'slide_group',
                            'instruction_to_student',
                            'coach_mode',
                            'slide_group_guidance',
                        ],
                    ],
                ],
                'slide_coverage_map' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'slide_id' => ['type' => 'integer'],
                            'slide_number' => ['type' => 'integer'],
                            'section_id' => ['type' => 'string'],
                            'concepts' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'is_support_slide' => ['type' => 'boolean'],
                            'requires_summary_work' => ['type' => 'boolean'],
                            'official_references' => [
                                'type' => 'array',
                                'items' => $this->referenceSchema(),
                            ],
                        ],
                        'required' => [
                            'slide_id',
                            'slide_number',
                            'section_id',
                            'concepts',
                            'is_support_slide',
                            'requires_summary_work',
                            'official_references',
                        ],
                    ],
                ],
                'not_required_global' => ['type' => 'array', 'items' => ['type' => 'string']],
                'common_misconceptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'student_personalization_allowed' => ['type' => 'array', 'items' => ['type' => 'string']],
                'global_do_not_ask' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'number'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'lesson_id',
                'lesson_title',
                'summary_structure',
                'coaching_sequence',
                'slide_coverage_map',
                'not_required_global',
                'common_misconceptions',
                'student_personalization_allowed',
                'global_do_not_ask',
                'confidence',
                'warnings',
            ],
        ];

        $payload = [
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "Generate a canonical lesson summary blueprint.\n\n"
                                . "Do NOT generate:\n"
                                . "- the student summary\n"
                                . "- ideal student summary\n"
                                . "- polished wording\n"
                                . "- canonical bullet text\n\n"
                                . "Do NOT write the student summary.\n"
                                . "Do NOT generate exact bullet text.\n"
                                . "Do NOT create polished final wording.\n"
                                . "Do NOT normalize student phrasing.\n\n"
                                . "Generate:\n"
                                . "- fixed section structure\n"
                                . "- coaching sequence\n"
                                . "- slide groups\n"
                                . "- required concepts\n"
                                . "- slide grouping\n"
                                . "- operational focus\n"
                                . "- lesson boundaries\n"
                                . "- minimum completion checks\n"
                                . "- do-not-ask boundaries\n"
                                . "- allowed coaching focus\n"
                                . "- generic student scaffold\n"
                                . "- personalization allowed\n"
                                . "- intro/support slide distinction\n"
                                . "- common misconceptions\n"
                                . "- not-required areas\n"
                                . "- official reference mappings\n\n"
                                . "The goal:\n"
                                . "same structure for every student,\n"
                                . "personal wording for each student.\n\n"
                                . "The structure must be evidence-based from:\n"
                                . "- slides\n"
                                . "- enrichment\n"
                                . "- selected official Resource Library context\n"
                                . "- selected ACS reference candidates when ACS is enabled\n\n"
                                . "Do NOT invent generic aviation headings.\n"
                                . "Every section must map to supporting slides.\n"
                                . "The first formal student section should normally be the first teachable concept, not the lesson title/objective.\n"
                                . "Do not create a required student section for intro-only or support-only slides unless the lesson truly requires it.\n"
                                . "Student scaffold placeholder_bullets may contain only \"Item 1\" and \"Item 2\"; never include topical hints or prefilled content.\n"
                                . "Generate the map Maya uses to coach students into writing their own summary. "
                                . "Maya must not rename sections, move concepts between sections, reopen completed topics without cause, ask beyond the current slide group, add advanced theory, or ask broad unsupported questions. "
                                . "Use selected Resource Library candidates for official_references when their indexed content supports the lesson. "
                                . "Use selected ACS reference candidates as official references when they support the lesson. "
                                . "Do not use stale non-ACS slide metadata references to infer Resource Library mappings.",
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => json_encode([
                                'lesson' => $sourceData['lesson'],
                                'active_slides' => $this->trimSlidesForPrompt($sourceData['slides']),
                                'resource_context' => $resourceContext,
                                'strict_requirements' => [
                                    'section_titles_must_be_derived_from_slide_or_resource_evidence' => true,
                                    'no_student_wording_or_complete_summary_text' => true,
                                    'include_deep_reference_paths_when_candidates_support_them' => true,
                                    'resource_library_search_is_based_on_lesson_content_not_stale_slide_references' => true,
                                    'acs_slide_references_are_official_when_selected' => true,
                                    'coaching_sequence_required' => true,
                                    'intro_support_slides_can_be_requires_student_section_false' => true,
                                    'student_scaffold_placeholders_only_item_1_item_2' => true,
                                    'do_not_ask_and_allowed_focus_prevent_maya_drift' => true,
                                ],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'lesson_summary_blueprint_v2',
                    'schema' => $schema,
                    'strict' => true,
                ],
            ],
            'temperature' => 0.2,
        ];

        $resp = cw_openai_responses($payload, 240);

        return cw_openai_extract_json_text($resp);
    }

    /**
     * @return array<string,mixed>
     */
    private function referenceSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'source_type' => ['type' => 'string'],
                'resource_id' => ['type' => 'integer'],
                'edition_id' => ['type' => 'integer'],
                'reference_code' => ['type' => 'string'],
                'reference_title' => ['type' => 'string'],
                'reference_path' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
            'required' => [
                'source_type',
                'resource_id',
                'edition_id',
                'reference_code',
                'reference_title',
                'reference_path',
                'confidence',
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $slides
     * @return list<array<string,mixed>>
     */
    private function trimSlidesForPrompt(array $slides): array
    {
        $out = [];
        foreach ($slides as $slide) {
            $out[] = [
                'slide_id' => (int)$slide['id'],
                'slide_number' => (int)$slide['page_number'],
                'slide_title' => (string)$slide['slide_title'],
                'plain_text' => $this->truncateText((string)$slide['plain_text'], 1100),
                'ai_summary' => $this->truncateText((string)$slide['ai_summary'], 900),
                'narration_en' => $this->truncateText((string)$slide['narration_en'], 1100),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $sourceData
     * @param array<string,mixed> $resourceContext
     * @return array<string,mixed>
     */
    private function normalizeBlueprint(array $raw, array $sourceData, array $resourceContext): array
    {
        $lesson = $sourceData['lesson'] ?? [];
        $slides = $sourceData['slides'] ?? [];
        $validSlideIds = [];
        $pageById = [];
        foreach ($slides as $slide) {
            $validSlideIds[(int)$slide['id']] = true;
            $pageById[(int)$slide['id']] = (int)$slide['page_number'];
        }

        $sections = [];
        $usedIds = [];
        foreach (($raw['summary_structure'] ?? []) as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string)($section['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $sid = $this->stableSectionId((string)($section['section_id'] ?? ''), $title, $usedIds);
            $covered = [];
            foreach (($section['covered_by_slides'] ?? []) as $slideNoOrId) {
                $n = (int)$slideNoOrId;
                foreach ($pageById as $slideId => $page) {
                    if ($n === $slideId || $n === $page) {
                        $covered[] = $page;
                    }
                }
            }
            $covered = array_values(array_unique($covered));
            sort($covered);
            $isIntroContext = (bool)($section['is_intro_context'] ?? $this->looksLikeIntroContext($title, $covered, $slides));
            $requiresStudentSection = array_key_exists('requires_student_section', $section)
                ? (bool)$section['requires_student_section']
                : !$isIntroContext;
            $minimumBullets = $requiresStudentSection
                ? max(1, (int)($section['completion_requirements']['minimum_student_bullets'] ?? 2))
                : 0;
            $notRequired = $this->stringList($section['not_required'] ?? []);
            $requiredConcepts = $this->stringList($section['required_concepts'] ?? []);
            $sections[] = [
                'section_id' => $sid,
                'order' => (int)($section['order'] ?? (count($sections) + 1)),
                'title' => $title,
                'requires_student_section' => $requiresStudentSection,
                'is_intro_context' => $isIntroContext,
                'covered_by_slides' => $covered,
                'required_concepts' => $requiredConcepts,
                'operational_focus' => $this->stringList($section['operational_focus'] ?? []),
                'allowed_coaching_focus' => $this->normalizeAllowedCoachingFocus($section['allowed_coaching_focus'] ?? [], $requiredConcepts),
                'not_required' => $notRequired,
                'do_not_ask' => $this->normalizeDoNotAsk($section['do_not_ask'] ?? [], $notRequired),
                'minimum_completion_check' => $this->normalizeMinimumCompletionChecks(
                    $section['minimum_completion_check'] ?? [],
                    $requiredConcepts,
                    $requiresStudentSection
                ),
                'student_summary_scaffold' => $this->normalizeStudentSummaryScaffold($section['student_summary_scaffold'] ?? [], $title, $requiresStudentSection),
                'completion_requirements' => [
                    'requires_why_reasoning' => (bool)($section['completion_requirements']['requires_why_reasoning'] ?? ($requiresStudentSection && count($requiredConcepts) > 1)),
                    'requires_pilot_action' => (bool)($section['completion_requirements']['requires_pilot_action'] ?? false),
                    'minimum_student_bullets' => $minimumBullets,
                ],
                'section_completion_behavior' => $this->normalizeSectionCompletionBehavior($section['section_completion_behavior'] ?? [], $requiresStudentSection),
                'official_references' => $this->normalizeReferences($section['official_references'] ?? [], $resourceContext),
            ];
        }

        usort($sections, static fn (array $a, array $b): int => ((int)$a['order']) <=> ((int)$b['order']));
        foreach ($sections as $i => $section) {
            $sections[$i]['order'] = $i + 1;
        }

        $knownSections = array_fill_keys(array_map(static fn (array $s): string => (string)$s['section_id'], $sections), true);
        $coverage = [];
        foreach (($raw['slide_coverage_map'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slideId = (int)($item['slide_id'] ?? 0);
            if (!isset($validSlideIds[$slideId])) {
                continue;
            }
            $sid = (string)($item['section_id'] ?? '');
            if (!isset($knownSections[$sid]) && $sections !== []) {
                $sid = (string)$sections[0]['section_id'];
            }
            $coverage[$slideId] = [
                'slide_id' => $slideId,
                'slide_number' => $pageById[$slideId] ?? (int)($item['slide_number'] ?? 0),
                'section_id' => $sid,
                'concepts' => $this->stringList($item['concepts'] ?? []),
                'is_support_slide' => (bool)($item['is_support_slide'] ?? false),
                'requires_summary_work' => (bool)($item['requires_summary_work'] ?? true),
                'official_references' => $this->normalizeReferences($item['official_references'] ?? [], $resourceContext),
            ];
        }

        foreach ($slides as $slide) {
            $slideId = (int)$slide['id'];
            if (isset($coverage[$slideId])) {
                continue;
            }
            $firstSection = $sections[0]['section_id'] ?? 'lesson_structure';
            $coverage[$slideId] = [
                'slide_id' => $slideId,
                'slide_number' => (int)$slide['page_number'],
                'section_id' => (string)$firstSection,
                'concepts' => [],
                'is_support_slide' => false,
                'requires_summary_work' => true,
                'official_references' => [],
            ];
        }
        usort($coverage, static fn (array $a, array $b): int => ((int)$a['slide_number']) <=> ((int)$b['slide_number']));
        $coachingSequence = $this->normalizeCoachingSequence($raw['coaching_sequence'] ?? [], $sections);

        return [
            'lesson_id' => (int)($lesson['id'] ?? $raw['lesson_id'] ?? 0),
            'lesson_title' => (string)($lesson['title'] ?? $raw['lesson_title'] ?? ''),
            'summary_structure' => $sections,
            'coaching_sequence' => $coachingSequence,
            'slide_coverage_map' => array_values($coverage),
            'not_required_global' => $this->stringList($raw['not_required_global'] ?? []),
            'common_misconceptions' => $this->stringList($raw['common_misconceptions'] ?? []),
            'student_personalization_allowed' => $this->normalizeStudentPersonalizationAllowed($raw['student_personalization_allowed'] ?? []),
            'global_do_not_ask' => $this->stringList($raw['global_do_not_ask'] ?? []),
            'confidence' => $this->clampConfidence((float)($raw['confidence'] ?? 0.0)),
            'warnings' => $this->stringList($raw['warnings'] ?? []),
        ];
    }

    /**
     * @param list<int> $coveredSlides
     * @param list<array<string,mixed>> $slides
     */
    private function looksLikeIntroContext(string $title, array $coveredSlides, array $slides): bool
    {
        $hay = strtolower($title);
        foreach (['objective', 'intro', 'introduction', 'welcome', 'lesson scope', 'wrap', 'closing', 'review'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return count($coveredSlides) === 1 && in_array($coveredSlides[0], [1], true) && count($slides) > 1;
    }

    /**
     * @param mixed $value
     * @param list<string> $requiredConcepts
     * @return list<string>
     */
    private function normalizeAllowedCoachingFocus(mixed $value, array $requiredConcepts): array
    {
        $items = $this->stringList($value);
        if ($items !== []) {
            return $items;
        }

        $defaults = ['identification', 'basic function', 'correct terminology'];
        $joined = strtolower(implode(' ', $requiredConcepts));
        if (str_contains($joined, 'preflight') || str_contains($joined, 'check')) {
            $defaults[] = 'preflight familiarization';
        }
        if (str_contains($joined, 'why') || str_contains($joined, 'because') || str_contains($joined, 'support')) {
            $defaults[] = 'simple cause and effect';
        }

        return array_values(array_unique($defaults));
    }

    /**
     * @param mixed $value
     * @param list<string> $notRequired
     * @return list<string>
     */
    private function normalizeDoNotAsk(mixed $value, array $notRequired): array
    {
        $items = $this->stringList($value);
        if ($items !== []) {
            return $items;
        }

        $out = [];
        foreach ($notRequired as $boundary) {
            $out[] = 'Do not ask about ' . lcfirst(rtrim($boundary, '.')) . '.';
        }

        return $out !== [] ? $out : ['Do not ask beyond the slides assigned to this section.'];
    }

    /**
     * @param mixed $value
     * @param list<string> $requiredConcepts
     * @return list<string>
     */
    private function normalizeMinimumCompletionChecks(mixed $value, array $requiredConcepts, bool $requiresStudentSection): array
    {
        $items = $this->stringList($value);
        if (!$requiresStudentSection) {
            return $items;
        }
        if ($items !== []) {
            return $items;
        }

        $out = [];
        foreach (array_slice($requiredConcepts, 0, 5) as $concept) {
            $out[] = 'Student covers the required concept: ' . rtrim($concept, '.') . '.';
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return array{heading:string,placeholder_bullets:list<string>}
     */
    private function normalizeStudentSummaryScaffold(mixed $value, string $title, bool $requiresStudentSection): array
    {
        $bullets = [];
        if ($requiresStudentSection) {
            $bullets = ['Item 1', 'Item 2'];
        }

        return [
            'heading' => $title,
            'placeholder_bullets' => $bullets,
        ];
    }

    /**
     * @param mixed $value
     * @return array{when_complete:string,do_not_reopen_unless:list<string>}
     */
    private function normalizeSectionCompletionBehavior(mixed $value, bool $requiresStudentSection): array
    {
        $whenComplete = '';
        $doNotReopenUnless = [];
        if (is_array($value)) {
            $whenComplete = trim((string)($value['when_complete'] ?? ''));
            $doNotReopenUnless = $this->stringList($value['do_not_reopen_unless'] ?? []);
        }
        if ($whenComplete === '') {
            $whenComplete = $requiresStudentSection
                ? 'Acknowledge completion and move to next coaching sequence step.'
                : 'Acknowledge context and move to next coaching sequence step.';
        }
        if ($doNotReopenUnless === []) {
            $doNotReopenUnless = ['student asks', 'technical error found', 'required concept missing'];
        }

        return [
            'when_complete' => $whenComplete,
            'do_not_reopen_unless' => $doNotReopenUnless,
        ];
    }

    /**
     * @param mixed $value
     * @param list<array<string,mixed>> $sections
     * @return list<array<string,mixed>>
     */
    private function normalizeCoachingSequence(mixed $value, array $sections): array
    {
        $byId = [];
        foreach ($sections as $section) {
            $byId[(string)$section['section_id']] = $section;
        }

        $sequence = [];
        if (is_array($value)) {
            foreach ($value as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $sectionId = (string)($step['section_id'] ?? '');
                if ($sectionId === '' || !isset($byId[$sectionId])) {
                    continue;
                }
                $section = $byId[$sectionId];
                $slideGroup = $this->normalizeSlideGroup($step['slide_group'] ?? [], $section['covered_by_slides'] ?? []);
                $sequence[] = $this->coachingStep(
                    count($sequence) + 1,
                    $section,
                    $slideGroup,
                    (string)($step['instruction_to_student'] ?? ''),
                    (string)($step['coach_mode'] ?? ''),
                    is_array($step['slide_group_guidance'] ?? null) ? $step['slide_group_guidance'] : []
                );
            }
        }

        if ($sequence !== []) {
            return $sequence;
        }

        foreach ($sections as $section) {
            if (empty($section['requires_student_section'])) {
                continue;
            }
            $sequence[] = $this->coachingStep(
                count($sequence) + 1,
                $section,
                $this->normalizeSlideGroup([], $section['covered_by_slides'] ?? []),
                '',
                '',
                []
            );
        }

        return $sequence;
    }

    /**
     * @param mixed $raw
     * @param mixed $fallback
     * @return list<int>
     */
    private function normalizeSlideGroup(mixed $raw, mixed $fallback): array
    {
        $source = is_array($raw) && $raw !== [] ? $raw : (is_array($fallback) ? $fallback : []);
        $out = array_values(array_unique(array_filter(array_map('intval', $source), static fn (int $v): bool => $v > 0)));
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $section
     * @param list<int> $slideGroup
     * @param array<string,mixed> $guidance
     * @return array<string,mixed>
     */
    private function coachingStep(int $step, array $section, array $slideGroup, string $instruction, string $coachMode, array $guidance): array
    {
        $title = (string)($section['title'] ?? '');
        $slideText = $this->slideGroupText($slideGroup);
        if ($instruction === '') {
            $instruction = 'Go through ' . $slideText . ' first. Then we will build the ' . $title . ' section.';
        }
        if ($coachMode === '') {
            $coachMode = 'guided_writing';
        }

        $watchInstruction = trim((string)($guidance['watch_instruction'] ?? ''));
        if ($watchInstruction === '') {
            $watchInstruction = 'Go through ' . $slideText . ' before writing this section.';
        }
        $whyGrouped = trim((string)($guidance['why_grouped'] ?? ''));
        if ($whyGrouped === '') {
            $whyGrouped = 'These slides support the canonical ' . $title . ' section.';
        }
        $readyWhen = trim((string)($guidance['ready_to_write_when'] ?? ''));
        if ($readyWhen === '') {
            $readyWhen = 'Student has viewed the assigned slide group or opens Maya while working on this section.';
        }

        return [
            'step' => $step,
            'section_id' => (string)$section['section_id'],
            'slide_group' => $slideGroup,
            'instruction_to_student' => $instruction,
            'coach_mode' => $coachMode,
            'slide_group_guidance' => [
                'watch_instruction' => $watchInstruction,
                'why_grouped' => $whyGrouped,
                'ready_to_write_when' => $readyWhen,
            ],
        ];
    }

    /**
     * @param list<int> $slideGroup
     */
    private function slideGroupText(array $slideGroup): string
    {
        if ($slideGroup === []) {
            return 'the assigned slides';
        }
        if (count($slideGroup) === 1) {
            return 'slide ' . (string)$slideGroup[0];
        }

        return 'slides ' . (string)min($slideGroup) . '-' . (string)max($slideGroup);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStudentPersonalizationAllowed(mixed $value): array
    {
        $items = $this->stringList($value);
        if ($items !== []) {
            return $items;
        }

        return [
            'own wording',
            'personal memory note',
            'simple mnemonic',
            'highlighted reminder',
            'basic example from the training airplane',
        ];
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $sourceData
     * @param array<string,mixed> $resourceContext
     * @return list<array<string,string>>
     */
    private function validateBlueprint(array $blueprint, array $sourceData, array $resourceContext): array
    {
        $warnings = [];
        $slides = $sourceData['slides'] ?? [];
        $slidePages = array_map(static fn (array $s): int => (int)$s['page_number'], $slides);
        $allPages = array_fill_keys($slidePages, true);

        $genericTitles = ['overview', 'introduction', 'summary', 'conclusion', 'aviation basics', 'general concepts', 'key concepts'];
        foreach (($blueprint['summary_structure'] ?? []) as $section) {
            $title = strtolower(trim((string)($section['title'] ?? '')));
            if ($title === '' || in_array($title, $genericTitles, true)) {
                $warnings[] = ['severity' => 'severe', 'message' => 'Section title appears generic or unsupported: ' . ($title !== '' ? $title : '(blank)')];
            }
            $covered = $section['covered_by_slides'] ?? [];
            if (!is_array($covered) || $covered === []) {
                $warnings[] = ['severity' => 'severe', 'message' => 'Section has no supporting slides: ' . (string)($section['title'] ?? '')];
            }
            foreach ($covered as $page) {
                if (!isset($allPages[(int)$page])) {
                    $warnings[] = ['severity' => 'severe', 'message' => 'Section maps to a non-active slide page: ' . (string)$page];
                }
            }
            $requiresStudentSection = !empty($section['requires_student_section']);
            if ($requiresStudentSection) {
                $scaffold = $section['student_summary_scaffold'] ?? [];
                $bullets = is_array($scaffold) && is_array($scaffold['placeholder_bullets'] ?? null)
                    ? array_values($scaffold['placeholder_bullets'])
                    : [];
                if (($scaffold['heading'] ?? '') !== ($section['title'] ?? '')) {
                    $warnings[] = ['severity' => 'severe', 'message' => 'Student scaffold heading must match section title: ' . (string)($section['title'] ?? '')];
                }
                if ($bullets !== ['Item 1', 'Item 2']) {
                    $warnings[] = ['severity' => 'severe', 'message' => 'Student scaffold placeholders must be exactly Item 1 and Item 2 for required section: ' . (string)($section['title'] ?? '')];
                }
                if (($section['minimum_completion_check'] ?? []) === []) {
                    $warnings[] = ['severity' => 'severe', 'message' => 'Required section has no minimum completion checks: ' . (string)($section['title'] ?? '')];
                }
            }
            if (($section['do_not_ask'] ?? []) === []) {
                $warnings[] = ['severity' => 'warning', 'message' => 'Section has no do-not-ask boundaries: ' . (string)($section['title'] ?? '')];
            }
            if (($section['allowed_coaching_focus'] ?? []) === []) {
                $warnings[] = ['severity' => 'warning', 'message' => 'Section has no allowed coaching focus: ' . (string)($section['title'] ?? '')];
            }
        }

        if (($blueprint['summary_structure'] ?? []) === []) {
            $warnings[] = ['severity' => 'severe', 'message' => 'Blueprint has no summary sections'];
        }
        if (($blueprint['coaching_sequence'] ?? []) === []) {
            $warnings[] = ['severity' => 'severe', 'message' => 'Blueprint has no coaching sequence'];
        }
        if (($blueprint['student_personalization_allowed'] ?? []) === []) {
            $warnings[] = ['severity' => 'warning', 'message' => 'Blueprint has no global student personalization allowances'];
        }

        if (($resourceContext['verified_resources'] ?? []) !== [] && !$this->blueprintHasReferences($blueprint)) {
            $warnings[] = ['severity' => 'warning', 'message' => 'Verified resources were selected but no official references were mapped'];
        }

        $json = strtolower(json_encode($blueprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        foreach (['ideal student summary', 'polished summary', 'canonical bullet text', 'final summary', 'what did you learn'] as $phrase) {
            if (str_contains($json, $phrase)) {
                $warnings[] = ['severity' => 'severe', 'message' => 'Blueprint appears to include prohibited summary wording: ' . $phrase];
            }
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $blueprint
     */
    private function blueprintHasReferences(array $blueprint): bool
    {
        foreach (($blueprint['summary_structure'] ?? []) as $section) {
            if (!empty($section['official_references'])) {
                return true;
            }
        }
        foreach (($blueprint['slide_coverage_map'] ?? []) as $slide) {
            if (!empty($slide['official_references'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $refs
     * @param array<string,mixed> $resourceContext
     * @return list<array<string,mixed>>
     */
    private function normalizeReferences(mixed $refs, array $resourceContext): array
    {
        $out = [];
        if (!is_array($refs)) {
            return $out;
        }
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $sourceType = trim((string)($ref['source_type'] ?? ''));
            $title = trim((string)($ref['reference_title'] ?? ''));
            $code = trim((string)($ref['reference_code'] ?? ''));
            if ($sourceType === '' && $title === '' && $code === '') {
                continue;
            }
            $out[] = [
                'source_type' => $sourceType !== '' ? $sourceType : 'Resource Library',
                'resource_id' => (int)($ref['resource_id'] ?? 0),
                'edition_id' => (int)($ref['edition_id'] ?? 0),
                'reference_code' => $code,
                'reference_title' => $title,
                'reference_path' => trim((string)($ref['reference_path'] ?? '')),
                'confidence' => $this->clampConfidence((float)($ref['confidence'] ?? 0.0)),
            ];
        }

        if ($out === [] && !empty($resourceContext['reference_candidates'][0]) && is_array($resourceContext['reference_candidates'][0])) {
            $c = $resourceContext['reference_candidates'][0];
            $out[] = [
                'source_type' => (string)($c['source_type'] ?? 'Resource Library'),
                'resource_id' => (int)($c['resource_id'] ?? 0),
                'edition_id' => (int)($c['edition_id'] ?? 0),
                'reference_code' => (string)($c['reference_code'] ?? ''),
                'reference_title' => (string)($c['reference_title'] ?? ''),
                'reference_path' => (string)($c['reference_path'] ?? ''),
                'confidence' => $this->clampConfidence((float)($c['confidence'] ?? 0.35)),
            ];
        }

        return array_slice($out, 0, 8);
    }

    /**
     * @param array<string,mixed> $blueprint
     * @return list<array<string,string>>
     */
    private function warningsFromBlueprint(array $blueprint): array
    {
        $warnings = [];
        foreach (($blueprint['warnings'] ?? []) as $warning) {
            if (is_array($warning)) {
                $msg = trim((string)($warning['message'] ?? json_encode($warning)));
                $severity = trim((string)($warning['severity'] ?? 'warning'));
            } else {
                $msg = trim((string)$warning);
                $severity = 'warning';
            }
            if ($msg !== '') {
                $warnings[] = ['severity' => $severity !== '' ? $severity : 'warning', 'message' => $msg];
            }
        }

        return $warnings;
    }

    /**
     * @param list<array<string,string>|string> $warnings
     */
    private function hasSevereWarnings(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            $text = is_array($warning)
                ? strtolower((string)($warning['severity'] ?? '') . ' ' . (string)($warning['message'] ?? ''))
                : strtolower((string)$warning);
            foreach (['severe', 'unsupported', 'invented', 'generic', 'no supporting slides', 'prohibited'] as $needle) {
                if (str_contains($text, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $blueprint
     * @param list<array<string,string>|string> $warnings
     */
    private function insertVersion(
        int $blueprintId,
        int $lessonId,
        string $status,
        array $blueprint,
        string $sourceHash,
        ?float $confidence,
        array $warnings,
        string $generatedBy,
        string $reason
    ): int {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM lesson_summary_blueprint_versions WHERE blueprint_id = ?');
        $stmt->execute([$blueprintId]);
        $versionNumber = (int)$stmt->fetchColumn();
        $now = gmdate('Y-m-d H:i:s');
        $json = json_encode($blueprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $warningsJson = json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $warningsJson === false) {
            throw new RuntimeException('Failed to encode blueprint JSON');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_summary_blueprint_versions (
                blueprint_id, lesson_id, version_number, status, blueprint_json, source_enrichment_hash,
                confidence_score, warnings_json, generated_by, generation_reason, generated_at,
                activated_at, created_at, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $blueprintId,
            $lessonId,
            $versionNumber,
            $status,
            $json,
            $sourceHash,
            $confidence,
            $warningsJson,
            $generatedBy,
            $reason,
            $now,
            $status === 'active' ? $now : null,
            $now,
            $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchVersion(int $versionId): ?array
    {
        if ($versionId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_summary_blueprint_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed>|null $version
     * @return array<string,mixed>|null
     */
    private function shapeVersionForUi(?array $version): ?array
    {
        if (!$version) {
            return null;
        }
        $json = $this->decodeJson((string)($version['blueprint_json'] ?? '{}'));
        $warnings = $this->decodeJson((string)($version['warnings_json'] ?? '[]'));

        return [
            'id' => (int)$version['id'],
            'blueprint_id' => (int)$version['blueprint_id'],
            'lesson_id' => (int)$version['lesson_id'],
            'version_number' => (int)$version['version_number'],
            'status' => (string)$version['status'],
            'source_enrichment_hash' => (string)$version['source_enrichment_hash'],
            'source_hash_short' => substr((string)$version['source_enrichment_hash'], 0, 10),
            'confidence_score' => $version['confidence_score'] !== null ? (float)$version['confidence_score'] : null,
            'warnings' => is_array($warnings) ? $warnings : [],
            'warning_count' => is_array($warnings) ? count($warnings) : 0,
            'generated_by' => (string)$version['generated_by'],
            'generation_reason' => (string)($version['generation_reason'] ?? ''),
            'generated_at' => (string)$version['generated_at'],
            'activated_at' => (string)($version['activated_at'] ?? ''),
            'superseded_at' => (string)($version['superseded_at'] ?? ''),
            'created_at' => (string)$version['created_at'],
            'updated_at' => (string)$version['updated_at'],
            'blueprint_json' => $json,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failedBlueprint(int $lessonId, string $message): array
    {
        $lesson = $this->fetchLesson($lessonId);

        return [
            'lesson_id' => $lessonId,
            'lesson_title' => (string)($lesson['title'] ?? ''),
            'summary_structure' => [],
            'coaching_sequence' => [],
            'slide_coverage_map' => [],
            'not_required_global' => [],
            'common_misconceptions' => [],
            'student_personalization_allowed' => [
                'own wording',
                'personal memory note',
                'simple mnemonic',
                'highlighted reminder',
                'basic example from the training airplane',
            ],
            'global_do_not_ask' => [],
            'confidence' => 0.0,
            'warnings' => [
                ['severity' => 'severe', 'message' => $message],
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return array<mixed>
     */
    private function decodeJson(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $s = trim((string)$item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,true> $used
     */
    private function stableSectionId(string $proposed, string $title, array &$used): string
    {
        $base = strtolower(trim($proposed));
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = strtolower(trim($title));
            $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: 'section';
            $base = trim($base, '_');
        }
        if ($base === '') {
            $base = 'section';
        }

        $candidate = $base;
        $i = 2;
        while (isset($used[$candidate])) {
            $candidate = $base . '_' . $i;
            $i++;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    private function resourceLabel(array $resource): string
    {
        $work = trim((string)($resource['work_code'] ?? ''));
        $title = trim((string)($resource['title'] ?? ''));
        $type = trim((string)($resource['resource_type'] ?? ''));
        if ($work !== '') {
            return $work;
        }
        if ($title !== '') {
            return $title;
        }

        return $type !== '' ? $type : 'Resource Library';
    }

    private function sectionPath(string $json): string
    {
        $decoded = $this->decodeJson($json);
        if ($decoded === []) {
            return '';
        }

        return implode(' > ', array_map('strval', $decoded));
    }

    private function truncateText(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($text === '' || $maxChars <= 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars - 1)) . '...' : $text;
        }

        return strlen($text) > $maxChars ? rtrim(substr($text, 0, $maxChars - 1)) . '...' : $text;
    }

    private function clampConfidence(float $confidence): float
    {
        if (!is_finite($confidence)) {
            return 0.0;
        }
        if ($confidence > 1.0) {
            $confidence = $confidence / 100.0;
        }

        return max(0.0, min(1.0, $confidence));
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'manual_regenerate';
        }
        $reason = preg_replace('/[^a-z0-9_:-]+/i', '_', $reason) ?: 'manual_regenerate';

        return substr($reason, 0, 64);
    }

    /**
     * @param array<string,mixed> $json
     * @return array<string,array<string,mixed>>
     */
    private function sectionMap(array $json): array
    {
        $out = [];
        foreach (($json['summary_structure'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $id = (string)($section['section_id'] ?? '');
            if ($id === '') {
                $id = (string)($section['title'] ?? '');
            }
            if ($id !== '') {
                $out[$id] = $section;
            }
        }

        return $out;
    }
}
