<?php
declare(strict_types=1);

/**
 * Read-only enhancement chip rollup. Never writes banks, blueprints, or enrichment.
 * Uses live column names (`lang`, `narration_en`/`narration_es`) with SQLite-test fallbacks.
 */
final class TheoryLessonEnhancementStatusService
{
    public const CONTENT_EN_MIN = 24;
    public const CONTENT_ES_MIN = 12;
    public const NARRATION_EN_MIN = 32;
    public const NARRATION_ES_MIN = 12;

    /** @var array<string,bool> */
    private array $tableCache = array();
    /** @var array<string,bool> */
    private array $columnCache = array();

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, array<string,mixed>>
     */
    public function forLessons(array $lessonIds): array
    {
        $ids = array();
        foreach ($lessonIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === array()) {
            return array();
        }

        $slidesByLesson = $this->activeSlidesByLesson($ids);
        $slideIds = array();
        foreach ($slidesByLesson as $list) {
            foreach ($list as $sid) {
                $slideIds[] = $sid;
            }
        }

        $contentBySlide = $this->contentBySlide($slideIds);
        $narrationBySlide = $this->narrationBySlide($slideIds);
        $refCounts = $this->referenceCounts($slideIds);
        $hotspotCounts = $this->hotspotCounts($slideIds);
        $banks = $this->banksByLesson($ids);
        $maya = $this->mayaByLesson($ids);
        $externals = $this->externalIdsByLesson($ids);

        $out = array();
        foreach ($ids as $lessonId) {
            $slides = $slidesByLesson[$lessonId] ?? array();
            $out[$lessonId] = $this->assemble(
                $lessonId,
                $slides,
                $contentBySlide,
                $narrationBySlide,
                $refCounts,
                $hotspotCounts,
                $banks[$lessonId] ?? null,
                $maya[$lessonId] ?? '',
                $externals[$lessonId] ?? null
            );
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function forLesson(int $lessonId): array
    {
        $all = $this->forLessons(array($lessonId));
        return $all[$lessonId] ?? $this->emptyStatus($lessonId);
    }

    /**
     * @param list<int> $slides
     * @param array<int, array{en:string,es:string}> $contentBySlide
     * @param array<int, array{en:string,es:string}> $narrationBySlide
     * @param array<int,int> $refCounts
     * @param array<int,int> $hotspotCounts
     * @param array<string,mixed>|null $bank
     * @return array<string,mixed>
     */
    private function assemble(
        int $lessonId,
        array $slides,
        array $contentBySlide,
        array $narrationBySlide,
        array $refCounts,
        array $hotspotCounts,
        ?array $bank,
        string $mayaStatus,
        mixed $externalId
    ): array {
        $total = count($slides);
        $content = $this->contentCoverage($slides, $contentBySlide);
        $narration = $this->narrationCoverage($slides, $narrationBySlide);
        $references = $this->referenceCoverage($slides, $refCounts);
        $video = $this->videoCoverage($slides, $hotspotCounts, $externalId);
        $questions = $this->questionCoverage($bank);
        $maya = $this->mayaCoverage($mayaStatus);

        return array(
            'lesson_id' => $lessonId,
            'slide_count' => $total,
            'chips' => array(
                $this->chip('content', 'Content', $content),
                $this->chip('translation', 'Translation', $content['translation']),
                $this->chip('narration', 'Narration', $narration),
                $this->chip('references', 'References', $references),
                $this->chip('video', 'Video', $video),
                $this->chip('questions', 'Questions', $questions),
                $this->chip('maya', 'Maya', $maya),
            ),
        );
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, list<int>>
     */
    private function activeSlidesByLesson(array $lessonIds): array
    {
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, lesson_id FROM slides
             WHERE lesson_id IN ({$placeholders}) AND COALESCE(is_deleted, 0) = 0
             ORDER BY lesson_id, page_number, id"
        );
        $stmt->execute($lessonIds);
        $out = array();
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $lid = (int)$row['lesson_id'];
            $out[$lid][] = (int)$row['id'];
        }
        return $out;
    }

    /**
     * @param list<int> $slideIds
     * @return array<int, array{en:string,es:string}>
     */
    private function contentBySlide(array $slideIds): array
    {
        $out = array();
        if ($slideIds === array() || !$this->tableExists('slide_content')) {
            return $out;
        }
        $langCol = $this->firstExistingColumn('slide_content', array('lang', 'locale'));
        if ($langCol === null) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($slideIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT slide_id, {$langCol} AS loc, plain_text FROM slide_content WHERE slide_id IN ({$placeholders})"
        );
        $stmt->execute($slideIds);
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $sid = (int)$row['slide_id'];
            if (!isset($out[$sid])) {
                $out[$sid] = array('en' => '', 'es' => '');
            }
            $loc = strtolower((string)($row['loc'] ?? ''));
            if ($loc === 'en' || $loc === 'es') {
                $out[$sid][$loc] = (string)($row['plain_text'] ?? '');
            }
        }
        return $out;
    }

    /**
     * @param list<int> $slideIds
     * @return array<int, array{en:string,es:string}>
     */
    private function narrationBySlide(array $slideIds): array
    {
        $out = array();
        if ($slideIds === array() || !$this->tableExists('slide_enrichment')) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($slideIds), '?'));
        if ($this->columnExists('slide_enrichment', 'narration_en')) {
            $stmt = $this->pdo->prepare(
                "SELECT slide_id, narration_en, narration_es FROM slide_enrichment WHERE slide_id IN ({$placeholders})"
            );
            $stmt->execute($slideIds);
            foreach ($stmt->fetchAll() ?: array() as $row) {
                $out[(int)$row['slide_id']] = array(
                    'en' => (string)($row['narration_en'] ?? ''),
                    'es' => (string)($row['narration_es'] ?? ''),
                );
            }
            return $out;
        }
        $langCol = $this->firstExistingColumn('slide_enrichment', array('lang', 'locale'));
        $textCol = $this->firstExistingColumn('slide_enrichment', array('narration_text', 'narration'));
        if ($langCol === null || $textCol === null) {
            return $out;
        }
        $stmt = $this->pdo->prepare(
            "SELECT slide_id, {$langCol} AS loc, {$textCol} AS narration_text
             FROM slide_enrichment WHERE slide_id IN ({$placeholders})"
        );
        $stmt->execute($slideIds);
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $sid = (int)$row['slide_id'];
            if (!isset($out[$sid])) {
                $out[$sid] = array('en' => '', 'es' => '');
            }
            $loc = strtolower((string)($row['loc'] ?? ''));
            if ($loc === 'en' || $loc === 'es') {
                $out[$sid][$loc] = (string)($row['narration_text'] ?? '');
            }
        }
        return $out;
    }

    /**
     * @param list<int> $slideIds
     * @return array<int,int>
     */
    private function referenceCounts(array $slideIds): array
    {
        return $this->countBySlide('slide_references', $slideIds, '1=1');
    }

    /**
     * @param list<int> $slideIds
     * @return array<int,int>
     */
    private function hotspotCounts(array $slideIds): array
    {
        return $this->countBySlide('slide_hotspots', $slideIds, 'COALESCE(is_deleted, 0) = 0');
    }

    /**
     * @param list<int> $slideIds
     * @return array<int,int>
     */
    private function countBySlide(string $table, array $slideIds, string $extraWhere): array
    {
        $out = array();
        if ($slideIds === array() || !$this->tableExists($table)) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($slideIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT slide_id, COUNT(*) AS n FROM {$table}
             WHERE slide_id IN ({$placeholders}) AND {$extraWhere}
             GROUP BY slide_id"
        );
        $stmt->execute($slideIds);
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $out[(int)$row['slide_id']] = (int)$row['n'];
        }
        return $out;
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, array<string,mixed>>
     */
    private function banksByLesson(array $lessonIds): array
    {
        $out = array();
        if (!$this->tableExists('progress_test_lesson_banks')) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT lesson_id, status, content_fingerprint FROM progress_test_lesson_banks
             WHERE lesson_id IN ({$placeholders})"
        );
        $stmt->execute($lessonIds);
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $out[(int)$row['lesson_id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int,string>
     */
    private function mayaByLesson(array $lessonIds): array
    {
        $out = array();
        if (!$this->tableExists('lesson_summary_blueprints')) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT lesson_id, current_status FROM lesson_summary_blueprints
             WHERE lesson_id IN ({$placeholders})"
        );
        $stmt->execute($lessonIds);
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $out[(int)$row['lesson_id']] = strtolower(trim((string)($row['current_status'] ?? '')));
        }
        return $out;
    }

    /**
     * @param list<int> $lessonIds
     * @return array<int, mixed>
     */
    private function externalIdsByLesson(array $lessonIds): array
    {
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, external_lesson_id FROM lessons WHERE id IN ({$placeholders})"
        );
        $stmt->execute($lessonIds);
        $out = array();
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $out[(int)$row['id']] = $row['external_lesson_id'] ?? null;
        }
        return $out;
    }

    /**
     * @param list<int> $slides
     * @param array<int, array{en:string,es:string}> $contentBySlide
     * @return array<string,mixed>
     */
    private function contentCoverage(array $slides, array $contentBySlide): array
    {
        $total = count($slides);
        if ($total === 0) {
            return array(
                'tone' => 'muted',
                'label' => 'N/A',
                'ok' => 0,
                'total' => 0,
                'translation' => array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0),
            );
        }
        $enOk = 0;
        $esOk = 0;
        foreach ($slides as $sid) {
            $row = $contentBySlide[$sid] ?? array('en' => '', 'es' => '');
            $enPass = strlen(trim((string)$row['en'])) >= self::CONTENT_EN_MIN;
            if ($enPass) {
                $enOk++;
            }
            if ($enPass && strlen(trim((string)$row['es'])) >= self::CONTENT_ES_MIN) {
                $esOk++;
            }
        }
        return array(
            'tone' => $enOk === $total ? 'ok' : ($enOk > 0 ? 'warn' : 'bad'),
            'label' => $enOk . '/' . $total,
            'ok' => $enOk,
            'total' => $total,
            'translation' => array(
                'tone' => $esOk === $total ? 'ok' : ($esOk > 0 ? 'warn' : 'bad'),
                'label' => $esOk . '/' . $total,
                'ok' => $esOk,
                'total' => $total,
            ),
        );
    }

    /**
     * @param list<int> $slides
     * @param array<int, array{en:string,es:string}> $narrationBySlide
     * @return array<string,mixed>
     */
    private function narrationCoverage(array $slides, array $narrationBySlide): array
    {
        $total = count($slides);
        if ($total === 0) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0);
        }
        $ok = 0;
        foreach ($slides as $sid) {
            $row = $narrationBySlide[$sid] ?? array('en' => '', 'es' => '');
            if (strlen(trim((string)$row['en'])) >= self::NARRATION_EN_MIN
                && strlen(trim((string)$row['es'])) >= self::NARRATION_ES_MIN) {
                $ok++;
            }
        }
        return array(
            'tone' => $ok === $total ? 'ok' : ($ok > 0 ? 'warn' : 'bad'),
            'label' => $ok . '/' . $total,
            'ok' => $ok,
            'total' => $total,
        );
    }

    /**
     * @param list<int> $slides
     * @param array<int,int> $refCounts
     * @return array<string,mixed>
     */
    private function referenceCoverage(array $slides, array $refCounts): array
    {
        $total = count($slides);
        if ($total === 0) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0);
        }
        $ok = 0;
        foreach ($slides as $sid) {
            if ((int)($refCounts[$sid] ?? 0) > 0) {
                $ok++;
            }
        }
        return array(
            'tone' => $ok === $total ? 'ok' : ($ok > 0 ? 'warn' : 'bad'),
            'label' => $ok . '/' . $total,
            'ok' => $ok,
            'total' => $total,
        );
    }

    /**
     * @param list<int> $slides
     * @param array<int,int> $hotspotCounts
     * @return array<string,mixed>
     */
    private function videoCoverage(array $slides, array $hotspotCounts, mixed $externalId): array
    {
        if ($externalId === null || $externalId === false || trim((string)$externalId) === '') {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0);
        }
        $total = count($slides);
        if ($total === 0) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0);
        }
        $ok = 0;
        foreach ($slides as $sid) {
            if ((int)($hotspotCounts[$sid] ?? 0) > 0) {
                $ok++;
            }
        }
        if ($ok === 0) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => $total);
        }
        return array(
            'tone' => $ok === $total ? 'ok' : 'warn',
            'label' => $ok . '/' . $total,
            'ok' => $ok,
            'total' => $total,
        );
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function questionCoverage(?array $row): array
    {
        if ($row === null) {
            return array('tone' => 'muted', 'label' => 'Missing');
        }
        $status = (string)($row['status'] ?? '');
        if ($status === 'ready') {
            return array('tone' => 'ok', 'label' => 'Ready');
        }
        if ($status === 'stale' || $status === 'building') {
            return array('tone' => 'warn', 'label' => ucfirst($status));
        }
        return array('tone' => 'bad', 'label' => 'Missing');
    }

    /**
     * @return array<string,mixed>
     */
    private function mayaCoverage(string $status): array
    {
        if ($status === '') {
            return array('tone' => 'muted', 'label' => 'Missing');
        }
        if ($status === 'active') {
            return array('tone' => 'ok', 'label' => 'Active');
        }
        if (in_array($status, array('stale', 'draft'), true)) {
            return array('tone' => 'warn', 'label' => ucfirst($status));
        }
        if ($status === 'failed') {
            return array('tone' => 'bad', 'label' => 'Failed');
        }
        return array('tone' => 'muted', 'label' => 'Missing');
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyStatus(int $lessonId): array
    {
        $muted = array('tone' => 'muted', 'label' => 'N/A');
        return array(
            'lesson_id' => $lessonId,
            'slide_count' => 0,
            'chips' => array(
                $this->chip('content', 'Content', $muted + array('translation' => $muted)),
                $this->chip('translation', 'Translation', $muted),
                $this->chip('narration', 'Narration', $muted),
                $this->chip('references', 'References', $muted),
                $this->chip('video', 'Video', $muted),
                $this->chip('questions', 'Questions', array('tone' => 'muted', 'label' => 'Missing')),
                $this->chip('maya', 'Maya', array('tone' => 'muted', 'label' => 'Missing')),
            ),
        );
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function chip(string $key, string $title, array $state): array
    {
        $tone = (string)($state['tone'] ?? 'muted');
        $label = (string)($state['label'] ?? '');
        return array(
            'key' => $key,
            'title' => $title,
            'tone' => $tone,
            'label' => $label,
            'display' => $title . ' ' . $label,
        );
    }

    /**
     * @param list<string> $candidates
     */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }
        return null;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
                );
                $stmt->execute(array($table));
                $this->tableCache[$table] = (bool)$stmt->fetchColumn();
                return $this->tableCache[$table];
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table));
            $this->tableCache[$table] = (bool)$stmt->fetchColumn();
            return $this->tableCache[$table];
        } catch (Throwable) {
            $this->tableCache[$table] = false;
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
                $found = false;
                foreach ($stmt ? $stmt->fetchAll() : array() as $row) {
                    if ((string)($row['name'] ?? '') === $column) {
                        $found = true;
                        break;
                    }
                }
                $this->columnCache[$key] = $found;
                return $found;
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table, $column));
            $this->columnCache[$key] = (bool)$stmt->fetchColumn();
            return $this->columnCache[$key];
        } catch (Throwable) {
            $this->columnCache[$key] = false;
            return false;
        }
    }
}
