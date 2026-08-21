<?php
declare(strict_types=1);

/**
 * Read-only enhancement chip rollup. Never writes banks, blueprints, or enrichment.
 */
final class TheoryLessonEnhancementStatusService
{
    public const CONTENT_EN_MIN = 24;
    public const CONTENT_ES_MIN = 12;
    public const NARRATION_EN_MIN = 32;
    public const NARRATION_ES_MIN = 12;

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
        $out = array();
        foreach ($lessonIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = $this->forLesson($id);
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function forLesson(int $lessonId): array
    {
        $slides = $this->activeSlideIds($lessonId);
        $slideCount = count($slides);
        $content = $this->contentCoverage($slides);
        $narration = $this->narrationCoverage($slides);
        $references = $this->referenceCoverage($slides);
        $video = $this->videoCoverage($lessonId, $slides);
        $questions = $this->questionCoverage($lessonId);
        $maya = $this->mayaCoverage($lessonId);

        return array(
            'lesson_id' => $lessonId,
            'slide_count' => $slideCount,
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
     * @return list<int>
     */
    private function activeSlideIds(int $lessonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM slides WHERE lesson_id = ? AND COALESCE(is_deleted, 0) = 0 ORDER BY page_number, id'
        );
        $stmt->execute(array($lessonId));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
    }

    /**
     * @param list<int> $slideIds
     * @return array<string,mixed>
     */
    private function contentCoverage(array $slideIds): array
    {
        $total = count($slideIds);
        if ($total === 0) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0, 'translation' => array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0));
        }
        $enOk = 0;
        $esOk = 0;
        $enReady = array();
        foreach ($slideIds as $sid) {
            $row = $this->slideContentRow($sid);
            $en = strlen(trim((string)($row['en'] ?? '')));
            $es = strlen(trim((string)($row['es'] ?? '')));
            $enPass = $en >= self::CONTENT_EN_MIN;
            if ($enPass) {
                $enOk++;
                $enReady[$sid] = true;
            }
            if ($enPass && $es >= self::CONTENT_ES_MIN) {
                $esOk++;
            }
        }
        $enTone = $enOk === $total ? 'ok' : ($enOk > 0 ? 'warn' : 'bad');
        $esTone = $esOk === $total ? 'ok' : ($esOk > 0 ? 'warn' : 'bad');
        return array(
            'tone' => $enTone,
            'label' => $enOk . '/' . $total,
            'ok' => $enOk,
            'total' => $total,
            'translation' => array(
                'tone' => $esTone,
                'label' => $esOk . '/' . $total,
                'ok' => $esOk,
                'total' => $total,
            ),
        );
    }

    /**
     * @param list<int> $slideIds
     * @return array<string,mixed>
     */
    private function narrationCoverage(array $slideIds): array
    {
        $total = count($slideIds);
        if ($total === 0) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0);
        }
        $ok = 0;
        foreach ($slideIds as $sid) {
            $row = $this->enrichmentRow($sid);
            $en = strlen(trim((string)($row['en'] ?? '')));
            $es = strlen(trim((string)($row['es'] ?? '')));
            if ($en >= self::NARRATION_EN_MIN && $es >= self::NARRATION_ES_MIN) {
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
     * @param list<int> $slideIds
     * @return array<string,mixed>
     */
    private function referenceCoverage(array $slideIds): array
    {
        $total = count($slideIds);
        if ($total === 0 || !$this->tableExists('slide_references')) {
            return array('tone' => 'muted', 'label' => $total === 0 ? 'N/A' : '0/' . $total, 'ok' => 0, 'total' => $total);
        }
        $ok = 0;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM slide_references WHERE slide_id = ?');
        foreach ($slideIds as $sid) {
            $stmt->execute(array($sid));
            if ((int)$stmt->fetchColumn() > 0) {
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
     * @param list<int> $slideIds
     * @return array<string,mixed>
     */
    private function videoCoverage(int $lessonId, array $slideIds): array
    {
        $ext = $this->pdo->prepare('SELECT external_lesson_id FROM lessons WHERE id = ? LIMIT 1');
        $ext->execute(array($lessonId));
        $externalId = $ext->fetchColumn();
        if ($externalId === null || $externalId === false || trim((string)$externalId) === '') {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => 0);
        }
        $total = count($slideIds);
        if ($total === 0 || !$this->tableExists('slide_hotspots')) {
            return array('tone' => 'muted', 'label' => 'N/A', 'ok' => 0, 'total' => $total);
        }
        $ok = 0;
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM slide_hotspots WHERE slide_id = ? AND COALESCE(is_deleted, 0) = 0'
        );
        foreach ($slideIds as $sid) {
            $stmt->execute(array($sid));
            if ((int)$stmt->fetchColumn() > 0) {
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
     * @return array<string,mixed>
     */
    private function questionCoverage(int $lessonId): array
    {
        if (!$this->tableExists('progress_test_lesson_banks')) {
            return array('tone' => 'muted', 'label' => 'Missing');
        }
        $stmt = $this->pdo->prepare(
            'SELECT status, content_fingerprint FROM progress_test_lesson_banks WHERE lesson_id = ? LIMIT 1'
        );
        $stmt->execute(array($lessonId));
        $row = $stmt->fetch();
        if (!is_array($row)) {
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
    private function mayaCoverage(int $lessonId): array
    {
        if (!$this->tableExists('lesson_summary_blueprints')) {
            return array('tone' => 'muted', 'label' => 'Missing');
        }
        $stmt = $this->pdo->prepare(
            'SELECT current_status FROM lesson_summary_blueprints WHERE lesson_id = ? LIMIT 1'
        );
        $stmt->execute(array($lessonId));
        $status = strtolower(trim((string)$stmt->fetchColumn()));
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
     * @return array{en:string,es:string}
     */
    private function slideContentRow(int $slideId): array
    {
        if (!$this->tableExists('slide_content')) {
            return array('en' => '', 'es' => '');
        }
        $stmt = $this->pdo->prepare(
            'SELECT locale, plain_text FROM slide_content WHERE slide_id = ?'
        );
        $stmt->execute(array($slideId));
        $en = '';
        $es = '';
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $loc = strtolower((string)($row['locale'] ?? ''));
            if ($loc === 'en') {
                $en = (string)($row['plain_text'] ?? '');
            }
            if ($loc === 'es') {
                $es = (string)($row['plain_text'] ?? '');
            }
        }
        return array('en' => $en, 'es' => $es);
    }

    /**
     * @return array{en:string,es:string}
     */
    private function enrichmentRow(int $slideId): array
    {
        if (!$this->tableExists('slide_enrichment')) {
            return array('en' => '', 'es' => '');
        }
        $stmt = $this->pdo->prepare(
            'SELECT locale, narration_text FROM slide_enrichment WHERE slide_id = ?'
        );
        $stmt->execute(array($slideId));
        $en = '';
        $es = '';
        foreach ($stmt->fetchAll() ?: array() as $row) {
            $loc = strtolower((string)($row['locale'] ?? ''));
            $text = (string)($row['narration_text'] ?? '');
            if ($loc === 'en') {
                $en = $text;
            }
            if ($loc === 'es') {
                $es = $text;
            }
        }
        if ($en === '' && $es === '') {
            $stmt = $this->pdo->prepare('SELECT * FROM slide_enrichment WHERE slide_id = ? LIMIT 1');
            $stmt->execute(array($slideId));
            $row = $stmt->fetch();
            if (is_array($row)) {
                $en = (string)($row['narration_en'] ?? $row['en_narration'] ?? '');
                $es = (string)($row['narration_es'] ?? $row['es_narration'] ?? '');
            }
        }
        return array('en' => $en, 'es' => $es);
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

    private function tableExists(string $table): bool
    {
        static $cache = array();
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
                );
                $stmt->execute(array($table));
                $cache[$table] = (bool)$stmt->fetchColumn();
                return $cache[$table];
            }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table));
            $cache[$table] = (bool)$stmt->fetchColumn();
            return $cache[$table];
        } catch (Throwable) {
            $cache[$table] = false;
            return false;
        }
    }
}
