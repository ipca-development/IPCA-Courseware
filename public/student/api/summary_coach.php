<?php
declare(strict_types=1);

/**
 * Maya Summary Coach API (TEST/v3 feature).
 *
 * Backs the AI-guided summary coaching experience used by:
 *   - /public/player/slide_v3.php
 *   - /public/student/lesson_summaries_v3.php
 *
 * Production summary acceptance & progression remains owned by
 * LessonSummaryService. This endpoint never marks a lesson complete on its
 * own — when Maya approves on `final_review`, we delegate canonical
 * acceptance to LessonSummaryService::checkSummary() so SSOT and the
 * progression engine remain authoritative.
 *
 * Returns JSON only. Validates session, role, cohort enrollment, and that
 * the lesson belongs to the cohort. Calls the project's OpenAI integration
 * (cw_openai_responses) with strict JSON-schema output. If the OpenAI
 * configuration is missing, returns a clear JSON error — never a fake AI
 * response, never frontend-only readiness scoring.
 */

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/lesson_summary_service.php';
require_once __DIR__ . '/../../../src/resource_library_ai.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------------
// Constants — readiness thresholds (server-authoritative).
// Matches the spec for the v3 test feature.
// ---------------------------------------------------------------------------
const MAYA_THRESH_COVERAGE              = 80;
const MAYA_THRESH_ACCURACY              = 75;
const MAYA_THRESH_OWN_WORDING           = 75;
const MAYA_THRESH_CORRELATION           = 70;
const MAYA_THRESH_INSTRUCTOR_CONFIDENCE = 75;
const MAYA_MIN_INTERACTIONS             = 3;

const MAYA_STAGE_STRUCTURE           = 'structure';
const MAYA_STAGE_EXPLAIN             = 'explain';
const MAYA_STAGE_CORRELATE           = 'correlate';
const MAYA_STAGE_OPERATIONAL_EXAMPLE = 'operational_example';
const MAYA_STAGE_READINESS           = 'readiness';
const MAYA_STAGE_FINAL_REVIEW        = 'final_review';

const MAYA_ALLOWED_STAGES = [
    MAYA_STAGE_STRUCTURE,
    MAYA_STAGE_EXPLAIN,
    MAYA_STAGE_CORRELATE,
    MAYA_STAGE_OPERATIONAL_EXAMPLE,
    MAYA_STAGE_READINESS,
    MAYA_STAGE_FINAL_REVIEW,
];

const MAYA_ALLOWED_CONTEXTS = ['player', 'lesson_summaries'];

// History storage cap (DB) and per-page slice (lazy-load).
const MAYA_HISTORY_DB_CAP = 200;
const MAYA_HISTORY_PAGE_SIZE = 25;
const MAYA_INSERTION_TYPES = [
    'structure',
    'heading',
    'mature_concept',
    'bullet',
    'reminder',
    'highlighted_note',
    'remark',
    'warning',
    'caution',
    'attention',
    'mnemonic',
    'quote',
    'rule_of_thumb',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function maya_fail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function maya_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        maya_fail(400, 'Empty request body');
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        maya_fail(400, 'Invalid JSON');
    }
    return $data;
}

function maya_clamp_int($v, int $min, int $max, int $default): int
{
    if (!is_numeric($v)) return $default;
    $n = (int)$v;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

function maya_normalize_stage($s): string
{
    $s = is_string($s) ? trim($s) : '';
    return in_array($s, MAYA_ALLOWED_STAGES, true) ? $s : MAYA_STAGE_STRUCTURE;
}

function maya_truncate(string $text, int $maxChars): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $maxChars) return $text;
        return rtrim(mb_substr($text, 0, max(0, $maxChars - 1))) . '…';
    }
    if (strlen($text) <= $maxChars) return $text;
    return rtrim(substr($text, 0, max(0, $maxChars - 1))) . '…';
}

function maya_strip_html_to_text(string $html): string
{
    $plain = preg_replace('/\s+/u', ' ', strip_tags($html));
    return trim((string)$plain);
}

/**
 * Validate that the current student has access to the given (cohort_id, lesson_id).
 * Admin role bypasses (for QA/preview).
 */
function maya_assert_lesson_access(PDO $pdo, array $u, int $cohortId, int $lessonId): void
{
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        maya_fail(403, 'Forbidden');
    }
    if ($lessonId <= 0) {
        maya_fail(400, 'Missing lesson_id');
    }
    if ($role === 'admin') {
        return;
    }
    if ($cohortId <= 0) {
        maya_fail(400, 'Missing cohort_id');
    }

    $uid = (int)($u['id'] ?? 0);

    $chk = $pdo->prepare(
        'SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1'
    );
    $chk->execute([$cohortId, $uid]);
    if (!$chk->fetchColumn()) {
        maya_fail(403, 'Not enrolled in this cohort');
    }

    $chk2 = $pdo->prepare(
        'SELECT 1 FROM cohort_lesson_deadlines WHERE cohort_id=? AND lesson_id=? LIMIT 1'
    );
    $chk2->execute([$cohortId, $lessonId]);
    if (!$chk2->fetchColumn()) {
        maya_fail(403, 'Lesson not in this cohort');
    }
}

function maya_get_lesson_title(PDO $pdo, int $lessonId): string
{
    $st = $pdo->prepare('SELECT title FROM lessons WHERE id=? LIMIT 1');
    $st->execute([$lessonId]);
    $t = $st->fetchColumn();
    return is_string($t) ? trim($t) : '';
}

/**
 * Light lesson context for Maya — title + a compact concatenation of slide
 * narration / approved AI summaries / English plain text. Mirrors the data
 * sources LessonSummaryService uses for evaluation, but capped much smaller
 * because Maya runs many small interactions and we must not flood the model.
 */
function maya_build_lesson_context(PDO $pdo, int $lessonId, int $maxChars): string
{
    $title = maya_get_lesson_title($pdo, $lessonId);
    $parts = [];
    if ($title !== '') {
        $parts[] = 'Lesson Title: ' . $title;
    }

    $st = $pdo->prepare("
        SELECT s.page_number,
               sao.summary AS ai_summary,
               se.narration_en,
               sc.plain_text
        FROM slides s
        LEFT JOIN slide_ai_outputs sao
          ON sao.slide_id = s.id AND sao.status = 'approved'
        LEFT JOIN slide_enrichment se
          ON se.slide_id = s.id
        LEFT JOIN slide_content sc
          ON sc.slide_id = s.id AND sc.lang = 'en'
        WHERE s.lesson_id = ?
          AND s.is_deleted = 0
        ORDER BY s.page_number ASC
    ");
    $st->execute([$lessonId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $page = (int)($row['page_number'] ?? 0);
        $chunks = [];
        $sum = trim((string)($row['ai_summary'] ?? ''));
        if ($sum !== '') $chunks[] = $sum;
        $narr = trim((string)($row['narration_en'] ?? ''));
        if ($narr !== '') $chunks[] = $narr;
        $plain = trim((string)($row['plain_text'] ?? ''));
        if ($plain !== '') $chunks[] = $plain;

        $pageText = trim(implode("\n", $chunks));
        if ($pageText === '') continue;
        $parts[] = 'Slide ' . $page . ":\n" . $pageText;
    }

    return maya_truncate(trim(implode("\n\n", $parts)), $maxChars);
}

function maya_lesson_slides_brief(PDO $pdo, int $lessonId): array
{
    if ($lessonId <= 0) return [];
    $st = $pdo->prepare("
        SELECT s.id, s.page_number,
               COALESCE(sao.summary, '') AS ai_summary,
               COALESCE(se.narration_en, '') AS narration_en,
               COALESCE(sc.plain_text, '') AS plain_text
        FROM slides s
        LEFT JOIN slide_ai_outputs sao
          ON sao.slide_id = s.id AND sao.status = 'approved'
        LEFT JOIN slide_enrichment se
          ON se.slide_id = s.id
        LEFT JOIN slide_content sc
          ON sc.slide_id = s.id AND sc.lang = 'en'
        WHERE s.lesson_id = ?
          AND s.is_deleted = 0
        ORDER BY s.page_number ASC
    ");
    $st->execute([$lessonId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $text = trim(implode("\n", array_filter([
            trim((string)($row['ai_summary'] ?? '')),
            trim((string)($row['narration_en'] ?? '')),
            trim((string)($row['plain_text'] ?? '')),
        ])));
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'page_number' => (int)($row['page_number'] ?? 0),
            'text' => maya_truncate($text, 900),
        ];
    }
    return $out;
}

function maya_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function maya_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function maya_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * Dynamic official reference context for Maya.
 *
 * No source names are hardcoded here. We collect slide_references for this
 * lesson, resolve them against live Resource Library editions using metadata
 * (resource_type/work_code/title/revision/extra_config_json), and retrieve
 * compact excerpts through the existing Resource Library AI block search.
 */
function maya_build_official_reference_context(PDO $pdo, int $lessonId, string $query, int $maxChars = 3200): array
{
    if ($lessonId <= 0 || !maya_table_exists($pdo, 'slide_references') || !maya_table_exists($pdo, 'resource_library_editions')) {
        return ['text' => '', 'sources' => []];
    }

    $refRows = [];
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT sr.ref_type, sr.ref_code, sr.ref_title, sr.notes
            FROM slides s
            JOIN slide_references sr ON sr.slide_id = s.id
            WHERE s.lesson_id = ?
              AND s.is_deleted = 0
            ORDER BY sr.ref_type, sr.ref_code, sr.ref_title
            LIMIT 40
        ");
        $st->execute([$lessonId]);
        $refRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $refRows = [];
    }
    if (!$refRows) return ['text' => '', 'sources' => []];

    $hasResourceType = function_exists('rl_catalog_has_resource_type_column')
        ? rl_catalog_has_resource_type_column($pdo)
        : maya_column_exists($pdo, 'resource_library_editions', 'resource_type');
    $hasExtra = maya_column_exists($pdo, 'resource_library_editions', 'extra_config_json');

    $editionSelect = 'id, title, revision_code, revision_date, status, work_code'
        . ($hasResourceType ? ', resource_type' : '')
        . ($hasExtra ? ', extra_config_json' : '');

    $sourceBlocks = [];
    $sourceMeta = [];
    $seenEditionRef = [];

    foreach ($refRows as $ref) {
        $refType = trim((string)($ref['ref_type'] ?? ''));
        $refCode = trim((string)($ref['ref_code'] ?? ''));
        $refTitle = trim((string)($ref['ref_title'] ?? ''));
        $notes = trim((string)($ref['notes'] ?? ''));
        $needles = array_values(array_filter(array_unique([$refType, $refCode, $refTitle])));
        if (!$needles) continue;

        $where = ["status = 'live'"];
        $params = [];
        $needleClauses = [];
        foreach ($needles as $n) {
            $like = '%' . maya_like_escape($n) . '%';
            $needleClauses[] = "(COALESCE(work_code,'') LIKE ? OR COALESCE(title,'') LIKE ? OR COALESCE(revision_code,'') LIKE ?"
                . ($hasResourceType ? " OR COALESCE(resource_type,'') LIKE ?" : "")
                . ($hasExtra ? " OR COALESCE(extra_config_json,'') LIKE ?" : "")
                . ")";
            $params[] = $like; $params[] = $like; $params[] = $like;
            if ($hasResourceType) $params[] = $like;
            if ($hasExtra) $params[] = $like;
        }
        $where[] = '(' . implode(' OR ', $needleClauses) . ')';

        try {
            $sql = "SELECT {$editionSelect}
                    FROM resource_library_editions
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY sort_order ASC, revision_date DESC, id DESC
                    LIMIT 4";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $editions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $editions = [];
        }

        // If no direct metadata match exists, use the reference as a search
        // query across all live editions with block content. This keeps the
        // system useful for new resource types without source-name mappings.
        if (!$editions) {
            try {
                $st = $pdo->query("SELECT {$editionSelect}
                                   FROM resource_library_editions
                                   WHERE status = 'live'
                                   ORDER BY sort_order ASC, revision_date DESC, id DESC
                                   LIMIT 12");
                $editions = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (Throwable $e) {
                $editions = [];
            }
        }

        $searchQuery = trim(implode(' ', array_filter([$refCode, $refTitle, $notes, $query])));
        foreach ($editions as $ed) {
            $editionId = (int)($ed['id'] ?? 0);
            if ($editionId <= 0) continue;
            $key = $editionId . '|' . $refType . '|' . $refCode . '|' . $refTitle;
            if (isset($seenEditionRef[$key])) continue;
            $seenEditionRef[$key] = true;

            $blocks = [];
            if (maya_table_exists($pdo, 'resource_library_blocks')) {
                $blocks = rl_ai_search_resource_blocks($pdo, $editionId, $searchQuery !== '' ? $searchQuery : $query, 2);
            }
            if (!$blocks) continue;

            $resourceType = (string)($ed['resource_type'] ?? 'resource');
            $sourceMeta[] = [
                'resource_type' => $resourceType,
                'title' => (string)($ed['title'] ?? ''),
                'revision_code' => (string)($ed['revision_code'] ?? ''),
                'work_code' => (string)($ed['work_code'] ?? ''),
                'ref_type' => $refType,
                'ref_code' => $refCode,
                'ref_title' => $refTitle,
            ];

            foreach ($blocks as $b) {
                $path = '';
                $pathJson = (string)($b['section_path_json'] ?? '');
                if ($pathJson !== '') {
                    $decoded = json_decode($pathJson, true);
                    if (is_array($decoded)) $path = implode(' > ', array_map('strval', $decoded));
                }
                $excerpt = maya_truncate(trim((string)($b['body_text'] ?? '')), 700);
                if ($excerpt === '') continue;
                $sourceBlocks[] =
                    "[OFFICIAL SOURCE]\n"
                    . "Type: " . ($resourceType !== '' ? $resourceType : 'resource') . "\n"
                    . "Title: " . trim((string)($ed['title'] ?? '')) . "\n"
                    . "Edition: " . trim((string)($ed['revision_code'] ?? '')) . "\n"
                    . "Reference: " . trim(implode(' ', array_filter([$refType, $refCode, $refTitle]))) . "\n"
                    . ($path !== '' ? "Section: {$path}\n" : '')
                    . "Excerpt: {$excerpt}";
                if (strlen(implode("\n\n", $sourceBlocks)) >= $maxChars) break 3;
            }
        }
    }

    return [
        'text' => maya_truncate(implode("\n\n", $sourceBlocks), $maxChars),
        'sources' => $sourceMeta,
    ];
}

function maya_choose_coaching_focus_from_summary(string $lessonTitle, string $summaryPlain, array $referenceContext): string
{
    $hay = strtolower($lessonTitle . ' ' . $summaryPlain . ' ' . json_encode($referenceContext['sources'] ?? []));
    $families = [
        'cockpit interpretation and IFR/procedure decision making' => ['instrument', 'approach', 'procedure', 'clearance', 'altitude', 'minimum', 'navigation', 'chart', 'fix', 'course', 'intercept'],
        'go/no-go decision making, risk management, and changing conditions' => ['weather', 'visibility', 'ceiling', 'wind', 'storm', 'icing', 'forecast', 'metar', 'taf', 'temperature'],
        'pilot responsibility, compliance, and required preflight/cockpit action' => ['regulation', 'legal', 'required', 'compliance', 'inspection', 'certificate', 'currency', 'limitation', 'responsibility'],
        'aircraft behavior, systems understanding, performance, and safety consequence' => ['system', 'engine', 'fuel', 'electrical', 'control', 'lift', 'drag', 'stall', 'performance', 'weight', 'balance', 'aerodynamic'],
        'communication, coordination, and operational procedure' => ['communication', 'radio', 'atc', 'clearance', 'tower', 'traffic', 'airport', 'runway', 'taxi', 'phraseology'],
    ];
    $scores = [];
    foreach ($families as $focus => $terms) {
        $scores[$focus] = 0;
        foreach ($terms as $term) {
            if (strpos($hay, $term) !== false) $scores[$focus]++;
        }
    }
    arsort($scores);
    $best = key($scores);
    if ($best && current($scores) > 0) {
        return $best;
    }
    return 'operational application: connect a concept to a cockpit action, flight decision, safety outcome, or cause-and-effect relationship';
}

function maya_concept_families(): array
{
    return [
        'weather_decision_making' => ['weather', 'visibility', 'ceiling', 'wind', 'storm', 'icing', 'forecast', 'metar', 'taf', 'temperature'],
        'performance_planning' => ['performance', 'takeoff', 'landing', 'climb', 'density', 'altitude', 'runway', 'distance', 'calculate', 'calculation'],
        'mass_balance' => ['mass', 'balance', 'weight', 'loading', 'load', 'cg', 'center of gravity'],
        'flight_readiness' => ['readiness', 'im safe', 'imsafe', 'fitness', 'fatigue', 'stress', 'illness', 'medication', 'personal minimum'],
        'aircraft_systems' => ['system', 'engine', 'fuel', 'electrical', 'hydraulic', 'control', 'component', 'equipment'],
        'aerodynamics_control' => ['lift', 'drag', 'stall', 'control', 'pitch', 'roll', 'yaw', 'angle of attack', 'stability'],
        'regulations_compliance' => ['regulation', 'legal', 'required', 'compliance', 'certificate', 'currency', 'inspection', 'limitation'],
        'ifr_procedures' => ['instrument', 'ifr', 'approach', 'procedure', 'clearance', 'minimum', 'fix', 'course', 'intercept'],
        'communication_ops' => ['communication', 'radio', 'atc', 'clearance', 'tower', 'traffic', 'airport', 'runway', 'taxi'],
        'preflight_inspection' => ['preflight', 'inspection', 'checklist', 'walkaround', 'verify', 'airworthy', 'maintenance'],
    ];
}

function maya_detect_concept_key(string $lessonTitle, string $summaryPlain, string $studentReply, string $coachingFocus): string
{
    $hay = strtolower($lessonTitle . ' ' . $summaryPlain . ' ' . $studentReply . ' ' . $coachingFocus);
    $bestKey = 'operational_application';
    $bestScore = 0;
    foreach (maya_concept_families() as $key => $terms) {
        $score = 0;
        foreach ($terms as $term) {
            if ($term !== '' && strpos($hay, $term) !== false) $score++;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestKey = $key;
        }
    }
    return $bestKey;
}

function maya_concept_label(string $key): string
{
    $label = str_replace('_', ' ', $key);
    return trim($label) !== '' ? $label : 'operational application';
}

function maya_reply_demonstrates_operational_understanding(string $reply): bool
{
    $r = strtolower($reply);
    if (trim($r) === '') return false;
    $opsTerms = [
        'go/no-go', 'go no go', 'decision', 'decide', 'verify', 'check', 'preflight',
        'calculate', 'recalculate', 'before flight', 'prior to flight', 'depart',
        'departure', 'safe', 'safety', 'risk', 'minimum', 'legal', 'required',
        'cockpit', 'pilot', 'action', 'because', 'therefore', 'effect', 'affect',
        'consequence', 'performance', 'confirm',
    ];
    $hits = 0;
    foreach ($opsTerms as $term) {
        if (strpos($r, $term) !== false) $hits++;
    }
    return $hits >= 2 && str_word_count($reply) >= 8;
}

function maya_update_concept_progress(array $existingFlags, string $conceptKey, string $studentReply, array $oldScores, array $newScores): array
{
    $progress = is_array($existingFlags['concept_progress'] ?? null) ? $existingFlags['concept_progress'] : [];
    $current = is_array($progress[$conceptKey] ?? null) ? $progress[$conceptKey] : [
        'followup_count' => 0,
        'operational_understanding' => false,
        'closed' => false,
    ];

    $understood = maya_reply_demonstrates_operational_understanding($studentReply);
    $oldCorrelation = (int)($oldScores['correlation'] ?? 0);
    $newCorrelation = (int)($newScores['correlation'] ?? 0);
    $correlationImproved = $newCorrelation > $oldCorrelation || $newCorrelation >= MAYA_THRESH_CORRELATION;

    if ($studentReply !== '') {
        $current['followup_count'] = (int)($current['followup_count'] ?? 0) + 1;
    }
    if ($understood) {
        $current['operational_understanding'] = true;
    }
    if ((int)$current['followup_count'] >= 2 && !empty($current['operational_understanding']) && $correlationImproved) {
        $current['closed'] = true;
    }

    $current['last_seen_at'] = maya_now_sql();
    $progress[$conceptKey] = $current;

    return $progress;
}

function maya_concept_progress_prompt(array $conceptProgress, string $activeConcept): string
{
    if (!$conceptProgress) {
        return "No concept has been saturated yet. Probe once, confirm understanding, then move on when demonstrated.";
    }
    $lines = [];
    foreach ($conceptProgress as $key => $row) {
        if (!is_array($row)) continue;
        $lines[] = sprintf(
            "- %s: followups=%d, operational_understanding=%s, closed=%s%s",
            maya_concept_label((string)$key),
            (int)($row['followup_count'] ?? 0),
            !empty($row['operational_understanding']) ? 'true' : 'false',
            !empty($row['closed']) ? 'true' : 'false',
            $key === $activeConcept ? ' (current)' : ''
        );
    }
    return implode("\n", $lines);
}

function maya_transition_question_for_closed_concept(string $conceptKey, string $summaryPlain): string
{
    $s = strtolower($summaryPlain);
    if ($conceptKey === 'performance_planning' || $conceptKey === 'mass_balance') {
        if (strpos($s, 'imsafe') !== false || strpos($s, 'readiness') !== false || strpos($s, 'personal') !== false) {
            return 'You connected the calculation to the departure decision. Let’s move to another part of flight readiness: which personal-readiness item would you verify before departure, and how could it change your go/no-go decision?';
        }
        return 'You connected the calculation to the departure decision. Let’s move to another part of this lesson: pick a different readiness item from your summary and explain the pilot action it supports before departure.';
    }
    if ($conceptKey === 'weather_decision_making') {
        return 'You connected weather to the flight decision. Let’s move to another part of readiness: what would you personally verify before deciding the aircraft and pilot are ready to depart?';
    }
    if ($conceptKey === 'aircraft_systems' || $conceptKey === 'aerodynamics_control') {
        return 'You connected that concept to aircraft behavior. Let’s move to preflight use: what would you check or verify before flight to catch a problem related to another concept in your summary?';
    }
    if ($conceptKey === 'regulations_compliance') {
        return 'You connected the rule to pilot responsibility. Let’s move from knowing the rule to using it: what cockpit or preflight action would keep the flight compliant in this lesson scenario?';
    }
    if ($conceptKey === 'ifr_procedures') {
        return 'You connected the procedure to cockpit use. Let’s move to interpretation: what would you verify on the instruments or chart before continuing with the next step?';
    }
    return 'Good, that closes the loop on that concept. Pick a different lesson area from your summary and explain the pilot action, preflight check, or decision it supports.';
}

function maya_concept_allows_summary_insertions(array $conceptProgress, string $conceptKey, string $studentReply, array $oldScores, array $newScores): bool
{
    $row = is_array($conceptProgress[$conceptKey] ?? null) ? $conceptProgress[$conceptKey] : [];
    $followups = (int)($row['followup_count'] ?? 0);
    $operational = !empty($row['operational_understanding']) || maya_reply_demonstrates_operational_understanding($studentReply);
    $closed = !empty($row['closed']);
    $oldCorrelation = (int)($oldScores['correlation'] ?? 0);
    $newCorrelation = (int)($newScores['correlation'] ?? 0);
    $correlationImproved = $newCorrelation > $oldCorrelation || $newCorrelation >= MAYA_THRESH_CORRELATION;

    return $closed || ($followups >= 2 && $operational) || ($operational && $correlationImproved);
}

function maya_section_label_from_concept_key(string $key): string
{
    $map = [
        'weather_decision_making' => 'Weather and flight decisions',
        'performance_planning' => 'Performance planning',
        'mass_balance' => 'Mass and balance',
        'flight_readiness' => 'Pilot readiness',
        'aircraft_systems' => 'Aircraft systems and equipment',
        'aerodynamics_control' => 'Aircraft control and behavior',
        'regulations_compliance' => 'Rules, limitations, and pilot responsibility',
        'ifr_procedures' => 'Procedures and cockpit verification',
        'communication_ops' => 'Communication and coordination',
        'preflight_inspection' => 'Preflight checks and airworthiness',
    ];
    return $map[$key] ?? 'Operational application';
}

function maya_section_subtopics_for_concept_key(string $key): array
{
    $map = [
        'weather_decision_making' => ['Weather item', 'Pilot decision'],
        'performance_planning' => ['Calculation/input', 'Pilot action if margins are not safe'],
        'mass_balance' => ['Loading check', 'Safe departure decision'],
        'flight_readiness' => ['Fitness check', 'Personal go/no-go decision'],
        'aircraft_systems' => ['System/equipment check', 'What the pilot verifies'],
        'aerodynamics_control' => ['Aircraft behavior', 'Operational consequence'],
        'regulations_compliance' => ['Requirement', 'Pilot responsibility'],
        'ifr_procedures' => ['Procedure/check', 'Cockpit verification'],
        'communication_ops' => ['Communication item', 'Required pilot action'],
        'preflight_inspection' => ['Inspection item', 'Airworthiness decision'],
    ];
    return $map[$key] ?? ['Item 1', 'Item 2'];
}

function maya_derive_summary_sections(string $lessonTitle, string $lessonContext, string $officialReferenceText): array
{
    $hay = strtolower($lessonTitle . ' ' . $lessonContext . ' ' . $officialReferenceText);
    $scored = [];
    foreach (maya_concept_families() as $key => $terms) {
        $score = 0;
        foreach ($terms as $term) {
            if ($term !== '' && strpos($hay, $term) !== false) $score++;
        }
        if ($score > 0) {
            $scored[$key] = $score;
        }
    }
    arsort($scored);
    $sections = [];
    foreach (array_keys($scored) as $key) {
        $label = maya_section_label_from_concept_key((string)$key);
        if (!isset($sections[$label])) {
            $sections[$label] = [
                'title' => $label,
                'concept_key' => (string)$key,
                'subtopics' => maya_section_subtopics_for_concept_key((string)$key),
            ];
        }
        if (count($sections) >= 5) break;
    }
    $sections = array_slice(array_values($sections), 0, 5);
    return count($sections) >= 3 ? $sections : [];
}

function maya_summary_structure_html(array $sections): string
{
    if (!$sections) return '';
    $html = '';
    $n = 1;
    foreach ($sections as $section) {
        $label = is_array($section) ? trim((string)($section['title'] ?? '')) : trim((string)$section);
        if ($label === '') continue;
        $html .= '<p><strong><u>' . $n . '. ' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</u></strong></p><ul>';
        $html .= '<li>Item 1</li><li>Item 2</li>';
        $html .= '</ul>';
        $n++;
    }
    return $html;
}

function maya_make_structure_insertion(array $sections): array
{
    $html = maya_summary_structure_html($sections);
    if ($html === '') return [];
    return [
        'id' => maya_generate_insertion_id(),
        'label' => 'Add structure to summary',
        'insert_mode' => 'append_html',
        'insertion_type' => 'structure',
        'html' => $html,
        'requires_student_origin' => false,
        'inserted' => false,
    ];
}

function maya_section_titles(array $sections): array
{
    $titles = [];
    foreach ($sections as $section) {
        $title = is_array($section) ? trim((string)($section['title'] ?? '')) : trim((string)$section);
        if ($title !== '') $titles[] = $title;
    }
    return $titles;
}

function maya_structure_titles_inline(array $sections): string
{
    $parts = [];
    foreach (maya_section_titles($sections) as $i => $title) {
        $parts[] = ((int)$i + 1) . ') ' . $title;
    }
    return implode(' ', $parts);
}

function maya_section_progress_from_flags(array $flags, array $fallbackSections = []): array
{
    $progress = is_array($flags['section_progress'] ?? null) ? $flags['section_progress'] : [];
    $sectionRows = is_array($progress['proposed_sections'] ?? null) ? $progress['proposed_sections'] : $fallbackSections;
    $sections = [];
    foreach ($sectionRows as $section) {
        $title = is_array($section) ? trim((string)($section['title'] ?? '')) : trim((string)$section);
        if ($title !== '') $sections[] = $title;
    }
    $completed = is_array($progress['completed_sections'] ?? null) ? array_values(array_filter(array_map('strval', $progress['completed_sections']))) : [];
    $current = trim((string)($progress['current_section'] ?? ''));
    if ($current === '' && $sections) {
        foreach ($sections as $section) {
            if (!in_array($section, $completed, true)) {
                $current = $section;
                break;
            }
        }
    }
    return [
        'proposed_sections' => $sections,
        'current_section' => $current,
        'completed_sections' => $completed,
        'summary_structure_added' => !empty($progress['summary_structure_added']),
        'current_section_index' => isset($progress['current_section_index']) ? (int)$progress['current_section_index'] : 0,
        'current_slide_id' => isset($progress['current_slide_id']) ? (int)$progress['current_slide_id'] : 0,
        'current_slide_number' => isset($progress['current_slide_number']) ? (int)$progress['current_slide_number'] : 0,
        'completed_slide_ids' => is_array($progress['completed_slide_ids'] ?? null) ? array_values(array_map('intval', $progress['completed_slide_ids'])) : [],
        'current_writing_task' => trim((string)($progress['current_writing_task'] ?? '')),
        'awaiting_chat_reply' => !empty($progress['awaiting_chat_reply']),
        'current_stage' => trim((string)($progress['current_stage'] ?? MAYA_STAGE_STRUCTURE)),
    ];
}

function maya_section_progress_prompt(array $progress): string
{
    $sections = $progress['proposed_sections'] ?? [];
    if (!$sections) {
        return "No section structure has been established yet.";
    }
    return "Proposed sections: " . implode(', ', array_map('strval', $sections)) . "\n"
        . "Current section: " . ((string)($progress['current_section'] ?? '') ?: '(not selected)') . "\n"
        . "Completed sections: " . (count($progress['completed_sections'] ?? []) ? implode(', ', array_map('strval', $progress['completed_sections'])) : '(none)') . "\n"
        . "Current writing stage: " . ((string)($progress['current_stage'] ?? '') ?: MAYA_STAGE_STRUCTURE);
}

function maya_message_already_contains_question(string $message, string $question): bool
{
    $message = trim($message);
    $question = trim($question);
    if ($message === '' || $question === '') return false;
    if (stripos($message, $question) !== false) return true;
    if (strpos($message, '?') === false) return false;

    $messageTail = mb_strtolower(mb_substr($message, -700));
    if (strpos($messageTail, 'answer here:') !== false) return true;

    preg_match_all('/[a-z0-9]{4,}/i', mb_strtolower($question), $qMatches);
    $tokens = array_values(array_unique($qMatches[0] ?? []));
    if (count($tokens) < 4) return false;

    $hits = 0;
    foreach ($tokens as $token) {
        if (strpos($messageTail, $token) !== false) $hits++;
    }
    return ($hits / max(1, count($tokens))) >= 0.58;
}

function maya_current_slide_state(PDO $pdo, int $lessonId, array $sectionProgress, array $payload = []): array
{
    $slides = maya_lesson_slides_brief($pdo, $lessonId);
    if (!$slides) return ['id' => 0, 'page_number' => 0, 'text' => '', 'concept' => 'lesson concept'];

    $requestedId = isset($payload['current_slide_id']) ? (int)$payload['current_slide_id'] : 0;
    if ($requestedId <= 0) $requestedId = (int)($sectionProgress['current_slide_id'] ?? 0);
    $completed = is_array($sectionProgress['completed_slide_ids'] ?? null) ? array_map('intval', $sectionProgress['completed_slide_ids']) : [];

    foreach ($slides as $slide) {
        if ($requestedId > 0 && (int)$slide['id'] === $requestedId) {
            $key = maya_detect_concept_key('', (string)$slide['text'], '', '');
            return $slide + ['concept' => maya_section_label_from_concept_key($key)];
        }
    }
    foreach ($slides as $slide) {
        if (!in_array((int)$slide['id'], $completed, true)) {
            $key = maya_detect_concept_key('', (string)$slide['text'], '', '');
            return $slide + ['concept' => maya_section_label_from_concept_key($key)];
        }
    }
    $slide = $slides[count($slides) - 1];
    $key = maya_detect_concept_key('', (string)$slide['text'], '', '');
    return $slide + ['concept' => maya_section_label_from_concept_key($key)];
}

function maya_default_writing_task(array $sectionProgress, array $slideState, string $stage): string
{
    $section = trim((string)($sectionProgress['current_section'] ?? ''));
    if ($section === '') $section = trim((string)($slideState['concept'] ?? 'this section'));
    $slideNo = (int)($slideState['page_number'] ?? 0);
    $concept = trim((string)($slideState['concept'] ?? 'the slide concept'));
    if ($stage === MAYA_STAGE_STRUCTURE && empty($sectionProgress['summary_structure_added'])) {
        return 'Add the proposed structure to your summary, then go through Slide ' . max(1, $slideNo) . ' before we build the first section.';
    }
    return 'Under ' . $section . ', write or refine one bullet from Slide ' . max(1, $slideNo) . ' explaining ' . $concept . ' and the pilot action or decision it supports.';
}

function maya_awaiting_chat_reply(string $mayaMessage, string $nextQuestion): bool
{
    $text = strtolower($mayaMessage . "\n" . $nextQuestion);
    if ($nextQuestion !== '' && strpos($nextQuestion, '?') !== false) return true;
    if (strpos($text, 'answer here') !== false || strpos($text, 'tell me') !== false) return true;
    if (strpos($text, 'write') !== false || strpos($text, 'add one bullet') !== false || strpos($text, 'refine') !== false) return false;
    return false;
}

function maya_decode_json_column($raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function maya_now_sql(): string
{
    return gmdate('Y-m-d H:i:s');
}

function maya_generate_insertion_id(): string
{
    try {
        return 'ins_' . bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        return 'ins_' . substr(sha1((string)microtime(true) . mt_rand()), 0, 12);
    }
}

function maya_normalize_history_role(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'student' || $role === 'user') return 'student';
    if ($role === 'system') return 'system';
    return 'maya';
}

function maya_history_message_body(array $row): string
{
    return trim((string)($row['message_body'] ?? $row['message'] ?? $row['content'] ?? ''));
}

function maya_history_message_kind(array $row): string
{
    return trim((string)($row['message_type'] ?? $row['kind'] ?? 'chat')) ?: 'chat';
}

function maya_sanitize_summary_insertions($raw, bool $allowMatureConcepts, array $allowedEarlyTypes = []): array
{
    if (!is_array($raw)) return [];
    $allowedEarlyTypes = array_fill_keys($allowedEarlyTypes, true);
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $label = trim((string)($item['label'] ?? ''));
        $html = trim((string)($item['html'] ?? ''));
        $mode = trim((string)($item['insert_mode'] ?? 'append_bullets'));
        $type = trim((string)($item['insertion_type'] ?? 'mature_concept'));
        if (!in_array($type, MAYA_INSERTION_TYPES, true)) {
            $type = 'mature_concept';
        }
        if ($type === 'mature_concept' && !$allowMatureConcepts) continue;
        if ($type !== 'mature_concept' && empty($allowedEarlyTypes[$type])) continue;
        if ($label === '' || $html === '') continue;
        // Guardrails: insertions must be short, student-origin notes only.
        if (mb_strlen(maya_strip_html_to_text($html)) > 600) continue;
        if (empty($item['requires_student_origin'])) continue;
        if (!in_array($mode, ['append_bullets', 'append_html', 'insert_html'], true)) {
            $mode = 'append_html';
        }
        $out[] = [
            'id' => trim((string)($item['id'] ?? '')) ?: maya_generate_insertion_id(),
            'label' => mb_substr($label, 0, 80),
            'insert_mode' => $mode,
            'insertion_type' => $type,
            'html' => $html,
            'requires_student_origin' => !empty($item['requires_student_origin']),
            'inserted' => !empty($item['inserted']),
        ];
        if (count($out) >= 2) break;
    }
    return $out;
}

function maya_make_history_message(
    string $role,
    string $body,
    string $stage,
    string $type = 'chat',
    array $scores = [],
    array $flags = [],
    array $insertions = []
): array {
    return [
        'role' => maya_normalize_history_role($role),
        'message_type' => $type,
        'message_body' => maya_truncate($body, 1200),
        'message' => maya_truncate($body, 1200), // backward compatible prompt field
        'stage' => $stage,
        'created_at' => maya_now_sql(),
        'score_snapshot' => $scores,
        'flags_snapshot' => $flags,
        'summary_insertions' => $insertions,
    ];
}

function maya_format_history_page(array $history, int $startIndex = 0): array
{
    $messages = [];
    foreach ($history as $offset => $row) {
        if (!is_array($row)) continue;
        $absolute = $startIndex + $offset;
        $body = maya_history_message_body($row);
        if ($body === '') continue;
        $role = maya_normalize_history_role((string)($row['role'] ?? 'maya'));
        $created = (string)($row['created_at'] ?? $row['ts'] ?? maya_now_sql());
        if (strpos($created, 'T') !== false) {
            $created = str_replace(['T', 'Z'], [' ', ''], $created);
        }
        $messages[] = [
            'id' => isset($row['id']) ? (int)$row['id'] : ($absolute + 1),
            'lazy_index' => $absolute + 1,
            'role' => $role,
            'message_type' => maya_history_message_kind($row),
            'message_body' => $body,
            'message' => $body,
            'created_at' => $created,
            'stage' => (string)($row['stage'] ?? ''),
            'score_snapshot' => is_array($row['score_snapshot'] ?? null) ? $row['score_snapshot'] : [],
            'flags_snapshot' => is_array($row['flags_snapshot'] ?? null) ? $row['flags_snapshot'] : [],
            'summary_insertions' => is_array($row['summary_insertions'] ?? null) ? $row['summary_insertions'] : [],
        ];
    }
    return $messages;
}

function maya_format_message_row(array $row): array
{
    $insertions = maya_decode_json_column($row['summary_insertions_json'] ?? null);
    $scores = maya_decode_json_column($row['score_snapshot_json'] ?? null);
    $flags = maya_decode_json_column($row['flags_snapshot_json'] ?? null);
    return [
        'id' => (int)$row['id'],
        'lazy_index' => (int)$row['lazy_index'],
        'role' => maya_normalize_history_role((string)$row['role']),
        'message_type' => (string)($row['message_type'] ?? 'chat'),
        'message_body' => (string)($row['message_body'] ?? ''),
        'message' => (string)($row['message_body'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'stage' => '',
        'score_snapshot' => is_array($scores) ? $scores : [],
        'flags_snapshot' => is_array($flags) ? $flags : [],
        'summary_insertions' => is_array($insertions) ? $insertions : [],
    ];
}

function maya_message_count(PDO $pdo, int $sessionId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM student_summary_coach_messages WHERE session_id=?');
    $st->execute([$sessionId]);
    return (int)$st->fetchColumn();
}

function maya_next_lazy_index(PDO $pdo, int $sessionId): int
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(lazy_index), 0) + 1 FROM student_summary_coach_messages WHERE session_id=?');
    $st->execute([$sessionId]);
    return (int)$st->fetchColumn();
}

function maya_insert_message(PDO $pdo, array $session, string $role, string $type, string $body, array $scores = [], array $flags = [], array $insertions = []): array
{
    $sessionId = (int)$session['id'];
    $lazy = maya_next_lazy_index($pdo, $sessionId);
    $st = $pdo->prepare("
        INSERT INTO student_summary_coach_messages
          (session_id, user_id, lesson_id, cohort_id, summary_id, role, message_type,
           message_body, summary_insertions_json, score_snapshot_json, flags_snapshot_json,
           inserted_into_summary, lazy_index, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
    ");
    $st->execute([
        $sessionId,
        (int)$session['user_id'],
        (int)$session['lesson_id'],
        isset($session['cohort_id']) && $session['cohort_id'] !== null ? (int)$session['cohort_id'] : null,
        isset($session['summary_id']) && $session['summary_id'] !== null ? (int)$session['summary_id'] : null,
        maya_normalize_history_role($role),
        $type !== '' ? $type : 'chat',
        maya_truncate($body, 3000),
        $insertions ? json_encode($insertions) : null,
        $scores ? json_encode($scores) : null,
        $flags ? json_encode($flags) : null,
        $lazy,
        maya_now_sql(),
    ]);
    $id = (int)$pdo->lastInsertId();
    $sel = $pdo->prepare('SELECT * FROM student_summary_coach_messages WHERE id=? LIMIT 1');
    $sel->execute([$id]);
    return maya_format_message_row($sel->fetch(PDO::FETCH_ASSOC) ?: []);
}

function maya_load_latest_messages(PDO $pdo, int $sessionId, int $limit = MAYA_HISTORY_PAGE_SIZE): array
{
    $limit = max(1, min(50, $limit));
    $st = $pdo->prepare("
        SELECT *
        FROM student_summary_coach_messages
        WHERE session_id=?
        ORDER BY lazy_index DESC
        LIMIT {$limit}
    ");
    $st->execute([$sessionId]);
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    return array_map('maya_format_message_row', $rows);
}

function maya_load_older_messages(PDO $pdo, int $sessionId, int $beforeLazyIndex, int $limit = MAYA_HISTORY_PAGE_SIZE): array
{
    $limit = max(1, min(50, $limit));
    $st = $pdo->prepare("
        SELECT *
        FROM student_summary_coach_messages
        WHERE session_id=? AND lazy_index < ?
        ORDER BY lazy_index DESC
        LIMIT {$limit}
    ");
    $st->execute([$sessionId, $beforeLazyIndex]);
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    return array_map('maya_format_message_row', $rows);
}

function maya_messages_have_more(PDO $pdo, int $sessionId, int $oldestLazyIndex): bool
{
    if ($oldestLazyIndex <= 1) return false;
    $st = $pdo->prepare('SELECT 1 FROM student_summary_coach_messages WHERE session_id=? AND lazy_index < ? LIMIT 1');
    $st->execute([$sessionId, $oldestLazyIndex]);
    return (bool)$st->fetchColumn();
}

/**
 * Fetch the live lesson_summaries row, if any, for adaptive coaching.
 *
 * Returns the columns Maya needs to greet the student in a context-aware
 * way (already accepted? mid-draft? empty?). Always uses the canonical
 * production table — never duplicates state.
 */
function maya_get_summary_snapshot(PDO $pdo, int $userId, int $cohortId, int $lessonId): array
{
    if ($cohortId <= 0 || $lessonId <= 0 || $userId <= 0) {
        return [
            'exists' => false,
            'review_status' => 'missing',
            'student_soft_locked' => 0,
            'summary_html' => '',
            'summary_plain' => '',
            'word_count' => 0,
        ];
    }
    $st = $pdo->prepare("
        SELECT review_status, student_soft_locked, summary_html, summary_plain
        FROM lesson_summaries
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'exists' => false,
            'review_status' => 'missing',
            'student_soft_locked' => 0,
            'summary_html' => '',
            'summary_plain' => '',
            'word_count' => 0,
        ];
    }
    $plain = trim((string)($row['summary_plain'] ?? ''));
    if ($plain === '') {
        $plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($row['summary_html'] ?? ''))));
    }
    $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);
    return [
        'exists' => true,
        'review_status' => (string)($row['review_status'] ?? 'pending'),
        'student_soft_locked' => (int)($row['student_soft_locked'] ?? 0),
        'summary_html' => (string)($row['summary_html'] ?? ''),
        'summary_plain' => $plain,
        'word_count' => $words,
    ];
}

/**
 * Choose a contextual greeting for start_session based on the canonical
 * summary state. Returns:
 *   ['maya_message' => str, 'next_question' => str, 'stage' => str|null]
 *
 * stage === null means "do not change persisted stage".
 *
 * Pure heuristics — no AI call — so the modal opens fast and free.
 * Real coaching (with scoring + AI) starts on the first Continue/reply.
 */
function maya_compose_initial_greeting(array $snap, int $existingInteractions, string $persistedStage, array $sections = []): array
{
    $status = (string)($snap['review_status'] ?? 'missing');
    $locked = (int)($snap['student_soft_locked'] ?? 0) === 1;
    $words = (int)($snap['word_count'] ?? 0);
    $sectionTitles = maya_section_titles($sections);
    $sectionText = $sectionTitles ? implode(', ', $sectionTitles) : '';

    // 1) Already accepted + soft-locked → unlock-first message.
    if ($status === 'acceptable' && $locked) {
        return [
            'maya_message' =>
                "Nice work — your summary for this lesson has already been accepted. "
                . "If you want to improve it together, click Unlock first and then come back. "
                . "Otherwise you're already signed off here.",
            'next_question' =>
                "When you're ready, unlock the summary and tell me which part you'd like to strengthen first.",
            'stage' => MAYA_STAGE_READINESS,
        ];
    }

    // 2) Pre-existing draft with real content → continue from where they left off.
    if ($words >= 80) {
        return [
            'maya_message' =>
                "Good — you already have a solid draft (~{$words} words). "
                . "I'm not going to make you restart. Let's strengthen it like an instructor would: "
                . "we'll look for what is descriptive, what needs deeper explanation, and where you can add real flight application.",
            'next_question' =>
                "Pick one section of your summary that feels mostly descriptive, and explain how that knowledge would help you make a better decision before or during a flight.",
            'stage' => $persistedStage ?: MAYA_STAGE_CORRELATE,
        ];
    }

    if ($words >= 15) {
        return [
            'maya_message' =>
                "I see you've started writing — good. Let's turn it into a clear lesson summary structure, then we’ll work one section at a time.",
            'next_question' =>
                "Which section of your draft should we strengthen first, and what pilot action should that section include?",
            'stage' => $persistedStage ?: MAYA_STAGE_STRUCTURE,
        ];
    }

    // 3) Returning student with prior coaching turns but no draft yet.
    if ($existingInteractions >= 1) {
        return [
            'maya_message' =>
                "Welcome back. Your summary editor looks empty right now — let’s first build the structure, then we’ll work through one section at a time."
                . ($sectionText !== '' ? " I suggest these sections: {$sectionText}." : ''),
            'next_question' => $sections
                ? "Add or write these headings in your summary first, then tell me which section you want to start with."
                : "What are the 3–5 main areas this lesson covered? We’ll use those as your summary sections.",
            'stage' => null,
        ];
    }

    // 4) Empty draft, fresh session → original onboarding tone.
    return [
        'maya_message' =>
            "Hi! Let’s build your summary together. First we create the structure, then we’ll work through each section like a flight briefing."
            . ($sectionText !== '' ? " Based on this lesson, I suggest: {$sectionText}." : ''),
        'next_question' => $sections
            ? "Start by adding these headings to your summary. Then we’ll begin with the first section: " . $sectionTitles[0] . "."
            : "What are the 3–5 main areas this lesson covered? We’ll use those as your summary sections.",
        'stage' => null,
    ];
}

/**
 * Load (or create-on-write) the coaching session row for this
 * (user, lesson, cohort, context) tuple.
 */
function maya_load_session(PDO $pdo, int $userId, int $lessonId, int $cohortId, ?int $summaryId, string $context): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM student_summary_coach_sessions
        WHERE user_id = ?
          AND lesson_id = ?
          AND cohort_id <=> ?
          AND context = ?
        LIMIT 1
    ");
    $st->execute([
        $userId,
        $lessonId,
        $cohortId > 0 ? $cohortId : null,
        $context,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function maya_create_session(PDO $pdo, int $userId, int $lessonId, int $cohortId, ?int $summaryId, string $context): array
{
    $st = $pdo->prepare("
        INSERT INTO student_summary_coach_sessions
            (user_id, lesson_id, cohort_id, summary_id, context, stage,
             scores_json, readiness_json, flags_json, history_json,
             last_question, interaction_count, major_paste_flag,
             ready_for_final_review, approved_by_maya, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, NOW(), NOW())
    ");

    $emptyScores = [
        'coverage' => 0,
        'accuracy' => 0,
        'own_wording' => 0,
        'correlation' => 0,
        'instructor_confidence' => 0,
    ];
    $emptyReadiness = [
        'ready_for_final_review' => false,
        'missing' => [
            'Answer Maya\'s first question',
        ],
        'minimum_interactions_met' => false,
        'unresolved_required_question' => true,
    ];
    $emptyFlags = [
        'major_paste' => false,
        'needs_deeper_question' => false,
    ];

    $firstQ = "List the main concepts from this lesson, then pick one and connect it to a cockpit action or flight decision.";

    $st->execute([
        $userId,
        $lessonId,
        $cohortId > 0 ? $cohortId : null,
        ($summaryId !== null && $summaryId > 0) ? $summaryId : null,
        $context,
        MAYA_STAGE_STRUCTURE,
        json_encode($emptyScores),
        json_encode($emptyReadiness),
        json_encode($emptyFlags),
        json_encode([]),
        $firstQ,
    ]);

    return maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId, $context) ?? [];
}

function maya_save_session(PDO $pdo, int $sessionId, array $patch): void
{
    if (!$patch) return;

    $allowed = [
        'stage', 'scores_json', 'readiness_json', 'flags_json', 'history_json',
        'last_question', 'interaction_count', 'major_paste_flag',
        'ready_for_final_review', 'approved_by_maya', 'summary_id',
    ];
    $sets = [];
    $vals = [];
    foreach ($patch as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = $k . ' = ?';
        $vals[] = $v;
    }
    if (!$sets) return;

    $vals[] = $sessionId;

    $sql = 'UPDATE student_summary_coach_sessions SET '
        . implode(', ', $sets)
        . ', updated_at = NOW() WHERE id = ? LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute($vals);
}

/**
 * Server-authoritative readiness gate. Computes the unlock decision based on
 * stored scores + counters + flags, regardless of what the model said. The
 * model's `ready_for_final_review` is treated as advisory only.
 */
function maya_compute_readiness(array $scores, int $interactionCount, bool $majorPaste, bool $unresolvedQuestion): array
{
    $missing = [];

    $coverage = (int)($scores['coverage'] ?? 0);
    $accuracy = (int)($scores['accuracy'] ?? 0);
    $ownWording = (int)($scores['own_wording'] ?? 0);
    $correlation = (int)($scores['correlation'] ?? 0);
    $instrConf = (int)($scores['instructor_confidence'] ?? 0);

    if ($coverage < MAYA_THRESH_COVERAGE) {
        $missing[] = 'Cover the remaining main lesson concepts (coverage ' . $coverage . '/' . MAYA_THRESH_COVERAGE . ')';
    }
    if ($accuracy < MAYA_THRESH_ACCURACY) {
        $missing[] = 'Tighten accuracy on the concepts you mentioned';
    }
    if ($ownWording < MAYA_THRESH_OWN_WORDING) {
        $missing[] = 'Reword more of your summary in your own words';
    }
    if ($correlation < MAYA_THRESH_CORRELATION) {
        $missing[] = 'Add one cause-and-effect relationship between concepts';
    }
    if ($instrConf < MAYA_THRESH_INSTRUCTOR_CONFIDENCE) {
        $missing[] = 'Show Maya more evidence of operational understanding';
    }

    $minMet = $interactionCount >= MAYA_MIN_INTERACTIONS;
    if (!$minMet) {
        $missing[] = 'Continue with Maya at least ' . (MAYA_MIN_INTERACTIONS - $interactionCount) . ' more time(s)';
    }
    if ($majorPaste) {
        $missing[] = 'Explain the pasted content in your own words';
    }
    if ($unresolvedQuestion) {
        $missing[] = 'Answer Maya\'s current follow-up question';
    }

    $ready =
        $coverage   >= MAYA_THRESH_COVERAGE   &&
        $accuracy   >= MAYA_THRESH_ACCURACY   &&
        $ownWording >= MAYA_THRESH_OWN_WORDING &&
        $correlation >= MAYA_THRESH_CORRELATION &&
        $instrConf  >= MAYA_THRESH_INSTRUCTOR_CONFIDENCE &&
        $minMet &&
        !$majorPaste &&
        !$unresolvedQuestion;

    return [
        'ready_for_final_review' => $ready,
        'missing' => $missing,
        'minimum_interactions_met' => $minMet,
        'unresolved_required_question' => $unresolvedQuestion,
    ];
}

// ---------------------------------------------------------------------------
// OpenAI prompt + schema builders
// ---------------------------------------------------------------------------

function maya_system_prompt(): string
{
    return
        "You are Maya, an IPCA AI flight instructor. You coach the student to "
        . "write their own lesson summary. You never write the full summary for "
        . "the student. You ask short, focused questions that require the "
        . "student to think, look up lesson material, and explain in their own "
        . "words. You focus on operational understanding, correlation, and "
        . "real-world flight application. If the student pasted text, ask them "
        . "to explain it in their own words. Keep responses concise. Return only "
        . "valid JSON.\n\n"
        . "Behaviour rules — apply strictly:\n"
        . "1. Ask exactly one clear question at a time.\n"
        . "2. Keep maya_message under 60 words. Friendly, slightly fun, never childish.\n"
        . "3. Praise specific good effort when appropriate; challenge shallow answers.\n"
        . "4. Ask operational 'how', 'what if', and 'what action changes?' questions tied to flying, not generic essay questions.\n"
        . "5. Direct the student where to look in the lesson — not to a paste-able answer.\n"
        . "6. Never provide the full lesson summary. Never provide complete copy-pasteable answers to lesson concepts.\n"
        . "7. Never accept pasted text without probing understanding. If flags.major_paste was true and the student's reply convincingly explains the pasted content in their own words, you MAY clear it; otherwise leave it true.\n"
        . "8. student_note_suggestion must be empty unless it is clearly based on the student's own response, and must not be a full answer or a complete summary paragraph. Use a short bullet or phrase the student can refine, or empty string.\n"
        . "9. summary_insertions may contain at most two short insertable snippets. Structure/heading insertions may be derived from lesson context as editable scaffolding; mature_concept insertions must be based directly on the student's own demonstrated understanding. Never generate full summary content from lesson material alone. Set insertion_type accurately.\n"
        . "10. Score rubric (0-100):\n"
        . "   - coverage: how much of the lesson's primary concepts the summary covers.\n"
        . "   - accuracy: technical correctness of what is written.\n"
        . "   - own_wording: degree to which the summary is in the student's own words (not pasted).\n"
        . "   - correlation: cause-and-effect / how concepts connect.\n"
        . "   - instructor_confidence: would you sign this student off on this topic? (operational understanding).\n"
        . "11. Stage progression: structure → explain → correlate → operational_example → readiness.\n"
        . "    Pick the most useful next stage based on what is weakest. Never jump to final_review unless input.action == 'final_review'.\n"
        . "12. ready_for_final_review is advisory only. The server enforces final thresholds. Be honest, not generous.\n"
        . "13. Avoid generic school-style questions such as 'What is the single most important idea?', 'Why is this important?', or 'Tell me more' unless the lesson genuinely centers around one core concept.\n"
        . "14. Prefer aviation coaching questions that connect one concept to cockpit action, pilot decision making, preflight action, go/no-go decisions, safety consequences, aircraft behavior, system understanding, operational use, or cause-and-effect relationships.\n"
        . "15. If the summary already contains several valid concepts, do not force the student to rank one as most important. Identify what is strong, what is descriptive only, what lacks operational understanding, what lacks correlation, and what lacks cockpit application. Then ask ONE targeted aviation coaching question.\n"
        . "16. Behave like a flight instructor during an oral discussion, not a school essay grader.\n"
        . "17. Before asking the next question, silently evaluate: technically correct concepts, shallow concepts, descriptive-only sections, missing operational understanding, missing cause/effect relationships, and missing real pilot actions.\n"
        . "18. Prefer prompts such as: What would the pilot notice? What cockpit action would change? How would this affect a go/no-go decision? What safety consequence could follow? What would you check during preflight? How would you explain this to another student before flight? What could happen if this was misunderstood?\n"
        . "19. Avoid repetitive prompting patterns.\n"
        . "20. Do not repeatedly ask the student to identify the most important idea.\n"
        . "21. Use official reference context dynamically from the Resource Library system when available. Use it to guide coaching intelligently, but do not quote large blocks and do not provide copy-ready answers.\n"
        . "22. Use the reference context to detect weak understanding, guide the student toward correct operational reasoning, ask better questions, and identify omissions.\n"
        . "23. Different official source types imply different coaching focus areas. Let the dynamic source metadata and content guide whether the question should emphasize operational procedures, regulations, IFR procedures, weather, aircraft systems, aerodynamics, communication, safety, legality, performance, or decision making.\n"
        . "24. Stay within the instructional scope of the current lesson, slide content, and linked official references.\n"
        . "25. Use official reference context to sharpen coaching, not to expand the lesson into unrelated or advanced material.\n"
        . "26. You may deepen concepts already present in the lesson or student summary, but must avoid introducing advanced theory, edge cases, or checkride-level material unless the lesson clearly covered it.\n"
        . "27. Before asking a follow-up, silently classify the question as either A) scope-safe deepening of the current lesson or B) scope-expanding new material. Prefer A. Only use B if the slide/reference context clearly supports it.\n"
        . "28. If the student mentions a concept briefly, first ask a lesson-level operational question before probing advanced technical details.\n"
        . "29. Do not make the student feel they missed hidden material that was not taught in this lesson.\n"
        . "30. When unsure, ask about pilot action, preflight verification, operational consequence, or decision-making within the lesson scope.\n"
        . "Scope-safe examples: 'Based on this lesson, what are you trying to confirm before flight by checking mass and balance and performance?' 'What would you personally verify before deciding the aircraft is ready and safe to depart?' 'How does this calculation support the go/no-go decision for this flight?'\n"
        . "Avoid advanced probes unless explicitly covered by the lesson/reference context, such as detailed handling changes from forward-CG versus aft-CG loading.\n"
        . "31. Avoid repeatedly probing the same concept once operational understanding has already been demonstrated.\n"
        . "32. Before asking a follow-up question, determine whether the student already demonstrated sufficient understanding, whether the current topic is becoming repetitive, and whether the question is too similar to prior questions.\n"
        . "33. If the student already demonstrated operational understanding, pilot action, safety reasoning, and cause/effect, acknowledge mastery, summarize briefly, and move to another weak or unexplored topic.\n"
        . "34. Behave like a real instructor: probe, confirm, close the loop, move on.\n"
        . "35. Avoid asking multiple variations of the same operational question.\n"
        . "36. Avoid asking two nearly identical questions in one response.\n"
        . "37. Ask ONE clear question at a time.\n"
        . "38. When the student demonstrates understanding, transition naturally to another lesson area rather than re-probing the same one.\n"
        . "39. Do not generate summary_insertions after every student answer.\n"
        . "40. summary_insertions should only appear after a concept has been sufficiently explored and operational understanding has been demonstrated.\n"
        . "41. A summary insertion should represent a mature, completed operational idea — not a partial thought.\n"
        . "42. First probe, clarify, deepen, and operationalize before offering insertion into the formal summary.\n"
        . "43. Avoid turning the conversation into answer harvesting.\n"
        . "44. The student should feel they are developing understanding, not collecting bullets.\n"
        . "45. summary_insertions should usually appear after concept closure, operational understanding, cause/effect reasoning, and pilot-action reasoning.\n"
        . "46. Intermediate answers should usually NOT produce insertion buttons.\n"
        . "47. Before generating a summary insertion, ask: Has the student demonstrated enough understanding that this concept is mature enough to become part of the formal summary?\n"
        . "48. Actively guide the student in building the structure of the summary, not only ask chat questions.\n"
        . "49. When the summary is empty or very short, first help create a clear section structure before deep coaching.\n"
        . "50. Tell the student which part of the summary they are working on.\n"
        . "51. Periodically give writing guidance, such as: add this under a named section, write one bullet in your own words, include the pilot action, create a heading for this topic, or place this in the right section.\n"
        . "52. Make it clear when the student should answer in chat, write directly in the summary editor, or use an Add button.\n"
        . "53. Avoid turning the experience into an endless chat session.\n"
        . "54. Manage topic progression: build structure, work one section, close that section, then move to the next section.\n"
        . "55. When a concept is mature but no insertion is offered, explicitly say: Make sure you write this in your summary in your own words.\n"
        . "56. Add buttons may support mature concepts, but you should also guide manual writing when appropriate.\n"
        . "57. The student should always understand the current writing task.\n"
        . "58. Guide the summary slide-by-slide whenever the student is building a new or incomplete summary.\n"
        . "59. Do not coach the entire lesson at once.\n"
        . "60. First help build summary structure before deep coaching.\n"
        . "61. Identify the specific concept on the current slide and give a targeted writing task.\n"
        . "62. Tell the student exactly where the content belongs in the summary.\n"
        . "63. Evaluate the actual summary editor text, not only chat replies.\n"
        . "64. Clearly distinguish: answer in chat, write in summary, refine summary, or move to next slide.\n"
        . "65. Avoid broad reflection prompts such as 'What did you learn?', 'What is this slide trying to teach you?', 'What did you understand?', 'Summarize this slide', or 'Tell me about Slide X'.\n"
        . "66. If the student is unsure, redirect them to the specific slide/concept and ask them to find the relevant pilot check or action; do not provide the answer.\n"
        . "67. Focus strongly on why it matters, why it affects safety, legality, pilot action, or decision-making, within current lesson scope.\n"
        . "68. Do not jump randomly between topics.\n"
        . "69. Close one concept before moving to another.\n"
        . "70. You may suggest highlighted notes, warnings, cautions, mnemonics, rules of thumb, or quotes when educationally useful and short.\n"
        . "71. Enter a polishing phase once content quality is strong: suggest highlights, mnemonics/rules of thumb, and clear notes sparingly.\n"
        . "72. Never make the summary visually noisy.\n"
        . "91. Choose one primary task per turn: either a summary-editor writing/refinement task OR a chat question. Do not routinely assign both.\n"
        . "92. When the task is for the editor, use the exact label <strong><u>Summary Editor</u></strong>. When the task is for chat, use the exact label <strong><u>This Chat</u></strong>.\n"
        . "93. If both labels are truly necessary for a rare transition, put them on separate paragraphs with an arrow (→) or colon separator.\n"
        . "94. The student should immediately understand whether they must write in the summary or answer in chat.\n"
        . "95. Avoid blended instruction paragraphs such as 'In your summary editor... In chat...' in one continuous sentence.\n"
        . "96. When the client trigger is idle/editor writing, evaluate the actual summary text the student wrote. Say whether it is strong enough or needs more depth, then give the next single task.\n"
        . "97. Do not repeat the previous Maya message after the student has written in the editor.\n"
        . "98. Stay inside the assigned current slide or slide series. The CURRENT SLIDE CONTEXT is the boundary unless the system explicitly assigns more slides.\n"
        . "99. If the current slide context does not cover a concept, do not ask about it. Redirect the student back to the current slide content.\n"
        . "100. Follow this loop: student studies assigned slide(s), writes in their own words, Maya evaluates the summary text, asks one specific chat question only if deeper understanding is needed, then sends the student back to refine the summary.";
}

function maya_response_schema(bool $isFinalReview): array
{
    $base = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'maya_message' => ['type' => 'string'],
            'next_question' => ['type' => 'string'],
            'stage' => [
                'type' => 'string',
                'enum' => MAYA_ALLOWED_STAGES,
            ],
            'scores' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'coverage'              => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'accuracy'              => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'own_wording'           => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'correlation'           => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'instructor_confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                ],
                'required' => ['coverage', 'accuracy', 'own_wording', 'correlation', 'instructor_confidence'],
            ],
            'readiness' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'ready_for_final_review' => ['type' => 'boolean'],
                    'missing' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'minimum_interactions_met' => ['type' => 'boolean'],
                    'unresolved_required_question' => ['type' => 'boolean'],
                ],
                'required' => [
                    'ready_for_final_review',
                    'missing',
                    'minimum_interactions_met',
                    'unresolved_required_question',
                ],
            ],
            'flags' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'major_paste' => ['type' => 'boolean'],
                    'needs_deeper_question' => ['type' => 'boolean'],
                ],
                'required' => ['major_paste', 'needs_deeper_question'],
            ],
            'student_note_suggestion' => ['type' => 'string'],
            'summary_insertions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'insert_mode' => ['type' => 'string', 'enum' => ['append_bullets', 'append_html', 'insert_html']],
                        'insertion_type' => ['type' => 'string', 'enum' => MAYA_INSERTION_TYPES],
                        'html' => ['type' => 'string'],
                        'requires_student_origin' => ['type' => 'boolean'],
                    ],
                    'required' => ['id', 'label', 'insert_mode', 'insertion_type', 'html', 'requires_student_origin'],
                ],
            ],
        ],
        'required' => [
            'maya_message',
            'next_question',
            'stage',
            'scores',
            'readiness',
            'flags',
            'student_note_suggestion',
            'summary_insertions',
        ],
    ];

    if ($isFinalReview) {
        $base['properties']['approved'] = ['type' => 'boolean'];
        $base['required'][] = 'approved';
    }

    return $base;
}

/**
 * Call OpenAI with strict JSON-schema output.
 * Returns parsed JSON array, or throws on failure.
 */
function maya_call_openai(string $systemPrompt, string $userPrompt, array $schema, string $schemaName): array
{
    // Throws RuntimeException with a clear message if CW_OPENAI_API_KEY is
    // missing — surfaced upstream as a clean JSON error.
    $resp = cw_openai_responses([
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => [['type' => 'input_text', 'text' => $systemPrompt]],
            ],
            [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => $userPrompt]],
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
                'schema' => $schema,
                'strict' => true,
            ],
        ],
        'temperature' => 0.2,
    ]);

    return cw_openai_extract_json_text($resp);
}

// ---------------------------------------------------------------------------
// Action handlers
// ---------------------------------------------------------------------------

function maya_extract_common_input(array $payload): array
{
    $action      = trim((string)($payload['action'] ?? ''));
    $lessonId    = (int)($payload['lesson_id'] ?? 0);
    $cohortId    = (int)($payload['cohort_id'] ?? 0);
    $summaryId   = isset($payload['summary_id']) ? (int)$payload['summary_id'] : 0;
    $context     = trim((string)($payload['context'] ?? 'player'));
    if (!in_array($context, MAYA_ALLOWED_CONTEXTS, true)) {
        $context = 'player';
    }

    return [$action, $lessonId, $cohortId, $summaryId, $context];
}

function maya_action_start_session(PDO $pdo, array $u, array $payload): array
{
    [$_, $lessonId, $cohortId, $summaryId, $context] = maya_extract_common_input($payload);
    maya_assert_lesson_access($pdo, $u, $cohortId, $lessonId);

    $userId = (int)$u['id'];
    $session = maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    $isNewSession = false;
    if (!$session) {
        $session = maya_create_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
        $isNewSession = true;
    } elseif ($summaryId > 0 && (int)($session['summary_id'] ?? 0) !== $summaryId) {
        maya_save_session($pdo, (int)$session['id'], ['summary_id' => $summaryId]);
        $session['summary_id'] = $summaryId;
    }

    $scores    = maya_decode_json_column($session['scores_json']    ?? null);
    $readiness = maya_decode_json_column($session['readiness_json'] ?? null);
    $flags     = maya_decode_json_column($session['flags_json']     ?? null);
    $persistedStage = (string)($session['stage'] ?? MAYA_STAGE_STRUCTURE);
    $interactionCount = (int)($session['interaction_count'] ?? 0);

    // Adaptive greeting based on canonical lesson_summaries state.
    $snap = maya_get_summary_snapshot($pdo, $userId, $cohortId, $lessonId);
    $sections = [];
    if ((int)($snap['word_count'] ?? 0) < 30) {
        $lessonTitleForStructure = maya_get_lesson_title($pdo, $lessonId);
        $lessonContextForStructure = maya_build_lesson_context($pdo, $lessonId, 1800);
        $referenceForStructure = maya_build_official_reference_context($pdo, $lessonId, $lessonTitleForStructure, 1200);
        $sections = maya_derive_summary_sections(
            $lessonTitleForStructure,
            $lessonContextForStructure,
            (string)($referenceForStructure['text'] ?? '')
        );
    }
    $greeting = maya_compose_initial_greeting($snap, $interactionCount, $persistedStage, $sections);

    $newStage = $greeting['stage'] !== null ? $greeting['stage'] : $persistedStage;
    $nextQ    = $greeting['next_question'];
    $msg      = $greeting['maya_message'];

    // Only create the initial Maya message when the session has zero message
    // rows. Reopening the modal or refreshing the page returns existing rows.
    $messageCount = maya_message_count($pdo, (int)$session['id']);
    if ($messageCount === 0) {
        $sectionProgress = maya_section_progress_from_flags($flags ?: [], $sections);
        $slideState = maya_current_slide_state($pdo, $lessonId, $sectionProgress, $payload);
        $sectionProgress['current_slide_id'] = (int)($slideState['id'] ?? 0);
        $sectionProgress['current_slide_number'] = (int)($slideState['page_number'] ?? 0);
        $sectionProgress['current_writing_task'] = maya_default_writing_task($sectionProgress, $slideState, $newStage);
        $sectionProgress['awaiting_chat_reply'] = false;
        $flags = array_merge($flags ?: [], [
            'section_progress' => $sectionProgress,
        ]);
        $initialInsertions = [];
        if ((int)($snap['word_count'] ?? 0) < 15 && $sections) {
            $structureInsertion = maya_make_structure_insertion($sections);
            if ($structureInsertion) $initialInsertions[] = $structureInsertion;
        }
        $initialBody = $msg;
        if ($nextQ !== '' && stripos($initialBody, $nextQ) === false) {
            $initialBody .= "\n\n" . $nextQ;
        }
        maya_insert_message($pdo, $session, 'maya', 'greeting', $initialBody, $scores ?: [], $flags ?: [], $initialInsertions);
        maya_save_session($pdo, (int)$session['id'], [
            'stage' => $newStage,
            'flags_json' => json_encode($flags),
            'last_question' => $nextQ,
        ]);
    } else {
        // Keep state/current question fresh for accepted-locked transitions,
        // but do not append a duplicate chat bubble.
        maya_save_session($pdo, (int)$session['id'], [
            'stage' => $newStage,
            'last_question' => $nextQ,
        ]);
    }

    $messages = maya_load_latest_messages($pdo, (int)$session['id'], MAYA_HISTORY_PAGE_SIZE);
    $freshFlags = maya_decode_json_column($session['flags_json'] ?? null);
    if (!empty($flags)) $freshFlags = $flags;
    $startProgress = maya_section_progress_from_flags($freshFlags ?: [], $sections);
    $oldestIndex = $messages ? (int)$messages[0]['lazy_index'] : 0;
    $hasMore = maya_messages_have_more($pdo, (int)$session['id'], $oldestIndex);
    $total = maya_message_count($pdo, (int)$session['id']);

    return [
        'ok' => true,
        'session_id' => (int)$session['id'],
        'is_new_session' => $isNewSession,
        'summary_state' => [
            'exists' => (bool)$snap['exists'],
            'review_status' => (string)$snap['review_status'],
            'student_soft_locked' => (int)$snap['student_soft_locked'],
            'word_count' => (int)$snap['word_count'],
        ],
        'maya_message' => $msg,
        'next_question' => $nextQ,
        'stage' => $newStage,
        'scores' => $scores ?: [
            'coverage' => 0, 'accuracy' => 0, 'own_wording' => 0,
            'correlation' => 0, 'instructor_confidence' => 0,
        ],
        'readiness' => $readiness ?: [
            'ready_for_final_review' => false,
            'missing' => ['Answer Maya\'s first question'],
            'minimum_interactions_met' => false,
            'unresolved_required_question' => true,
        ],
        'flags' => $flags ?: ['major_paste' => false, 'needs_deeper_question' => false],
        'coaching_state' => [
            'current_writing_task' => (string)($startProgress['current_writing_task'] ?? ''),
            'awaiting_chat_reply' => !empty($startProgress['awaiting_chat_reply']),
            'current_section' => (string)($startProgress['current_section'] ?? ''),
            'current_slide_id' => (int)($startProgress['current_slide_id'] ?? 0),
            'current_slide_number' => (int)($startProgress['current_slide_number'] ?? 0),
        ],
        'interaction_count' => $interactionCount,
        'major_paste_flag' => (int)($session['major_paste_flag'] ?? 0) === 1,
        'messages' => $messages,
        'history' => $messages,
        'oldest_lazy_index' => $oldestIndex,
        'history_oldest_index' => $oldestIndex,
        'history_total' => $total,
        'history_has_more' => $hasMore,
        'has_more' => $hasMore,
        'student_note_suggestion' => '',
    ];
}

/**
 * Cursor-paginated history loader for lazy scroll-up in the chat thread.
 * Request: { action:'load_history', session_id, before_lazy_index, limit }
 * Legacy lesson_id/cohort_id/context input is still accepted.
 * Response: { ok, messages:[...], has_more, oldest_lazy_index }
 */
function maya_action_load_history(PDO $pdo, array $u, array $payload): array
{
    $userId = (int)$u['id'];
    $sessionId = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
    if ($sessionId > 0) {
        $st = $pdo->prepare("SELECT * FROM student_summary_coach_sessions WHERE id=? AND user_id=? LIMIT 1");
        $st->execute([$sessionId, $userId]);
        $session = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($session) {
            maya_assert_lesson_access($pdo, $u, (int)($session['cohort_id'] ?? 0), (int)($session['lesson_id'] ?? 0));
        }
    } else {
        [$_, $lessonId, $cohortId, $summaryId, $context] = maya_extract_common_input($payload);
        maya_assert_lesson_access($pdo, $u, $cohortId, $lessonId);
        $session = maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    }
    if (!$session) {
        return [
            'ok' => true,
            'messages' => [],
            'history' => [],
            'oldest_lazy_index' => 0,
            'has_more' => false,
            'history_total' => 0,
        ];
    }

    $beforeLazy = isset($payload['before_lazy_index'])
        ? (int)$payload['before_lazy_index']
        : (isset($payload['before_index']) ? (int)$payload['before_index'] : 0);
    $limit = isset($payload['limit']) ? (int)$payload['limit'] : MAYA_HISTORY_PAGE_SIZE;
    if ($limit < 1) $limit = MAYA_HISTORY_PAGE_SIZE;
    if ($limit > 50) $limit = 50;

    $total = maya_message_count($pdo, (int)$session['id']);
    if ($beforeLazy <= 1 || $total === 0) {
        return [
            'ok' => true,
            'messages' => [],
            'history' => [],
            'oldest_lazy_index' => 0,
            'has_more' => false,
            'history_total' => $total,
        ];
    }

    $messages = maya_load_older_messages($pdo, (int)$session['id'], $beforeLazy, $limit);
    $oldest = $messages ? (int)$messages[0]['lazy_index'] : 0;
    $hasMore = maya_messages_have_more($pdo, (int)$session['id'], $oldest);

    return [
        'ok' => true,
        'messages' => $messages,
        'history' => $messages,
        'oldest_lazy_index' => $oldest,
        'has_more' => $hasMore,
        'history_oldest_index' => $oldest,
        'history_has_more' => $hasMore,
        'history_total' => $total,
    ];
}

/**
 * Shared handler for micro_checkpoint and student_reply.
 */
function maya_action_checkpoint(PDO $pdo, array $u, array $payload, bool $explicitStudentReply): array
{
    [$_, $lessonId, $cohortId, $summaryId, $context] = maya_extract_common_input($payload);
    maya_assert_lesson_access($pdo, $u, $cohortId, $lessonId);

    $userId = (int)$u['id'];
    $session = maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    if (!$session) {
        $session = maya_create_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    } elseif ($summaryId > 0 && (int)($session['summary_id'] ?? 0) !== $summaryId) {
        maya_save_session($pdo, (int)$session['id'], ['summary_id' => $summaryId]);
        $session['summary_id'] = $summaryId;
    }

    $sessionId = (int)$session['id'];

    $coachStage      = maya_normalize_stage($payload['coach_stage'] ?? ($session['stage'] ?? MAYA_STAGE_STRUCTURE));
    $currentQuestion = trim((string)($payload['current_question'] ?? ($session['last_question'] ?? '')));
    $studentReply    = trim((string)($payload['student_reply'] ?? ''));
    $summaryExcerpt  = trim((string)($payload['summary_excerpt'] ?? ''));
    $summaryHtml     = (string)($payload['summary_html'] ?? '');
    $summaryPlainFromHtml = $summaryHtml !== '' ? maya_strip_html_to_text($summaryHtml) : '';
    $clientTrigger = trim((string)($payload['client_trigger'] ?? ''));

    $localFlagsRaw = is_array($payload['local_flags'] ?? null) ? $payload['local_flags'] : [];
    $clientMajorPaste = !empty($localFlagsRaw['major_paste']);
    $wallOfText       = !empty($localFlagsRaw['wall_of_text']);
    $wordCount        = isset($localFlagsRaw['word_count'])      ? (int)$localFlagsRaw['word_count']      : 0;
    $paragraphCount   = isset($localFlagsRaw['paragraph_count']) ? (int)$localFlagsRaw['paragraph_count'] : 0;

    $clientHistory = maya_load_latest_messages($pdo, $sessionId, 10);

    $existingFlags = maya_decode_json_column($session['flags_json'] ?? null);
    if (!is_array($existingFlags)) $existingFlags = [];
    $oldScores = maya_decode_json_column($session['scores_json'] ?? null);
    if (!is_array($oldScores)) $oldScores = [];
    $hadMajorPaste = (int)($session['major_paste_flag'] ?? 0) === 1;
    if ($clientMajorPaste && !$hadMajorPaste) {
        maya_insert_message($pdo, $session, 'system', 'system', 'Large pasted text detected.');
    }
    $serverMajorPaste = $hadMajorPaste || $clientMajorPaste;

    // Compose user prompt for the model. Keep it tight: lesson title + slide
    // context + dynamic official reference context + current state + recent
    // coach history + student input. We DO NOT send full resources on every
    // checkpoint.
    $lessonTitle = maya_get_lesson_title($pdo, $lessonId);
    $lessonContext = maya_build_lesson_context($pdo, $lessonId, 2600);

    $excerptForModel = '';
    if ($summaryExcerpt !== '') {
        $excerptForModel = maya_truncate($summaryExcerpt, 1800);
    } elseif ($summaryPlainFromHtml !== '') {
        $excerptForModel = maya_truncate($summaryPlainFromHtml, 1800);
    }
    $summaryPlainForFocus = trim($summaryPlainFromHtml !== '' ? $summaryPlainFromHtml : $summaryExcerpt);
    $referenceBundle = maya_build_official_reference_context(
        $pdo,
        $lessonId,
        trim($lessonTitle . ' ' . $summaryPlainForFocus . ' ' . $studentReply),
        3200
    );
    $officialReferenceText = trim((string)($referenceBundle['text'] ?? ''));
    $coachingFocus = maya_choose_coaching_focus_from_summary($lessonTitle, $summaryPlainForFocus, $referenceBundle);
    $activeConcept = maya_detect_concept_key($lessonTitle, $summaryPlainForFocus, $studentReply, $coachingFocus);
    $conceptProgress = is_array($existingFlags['concept_progress'] ?? null) ? $existingFlags['concept_progress'] : [];
    $conceptProgressText = maya_concept_progress_prompt($conceptProgress, $activeConcept);
    $activeConceptClosed = !empty($conceptProgress[$activeConcept]['closed']);
    $derivedSections = maya_derive_summary_sections($lessonTitle, $lessonContext, $officialReferenceText);
    $sectionProgress = maya_section_progress_from_flags($existingFlags, $derivedSections);
    $slideState = maya_current_slide_state($pdo, $lessonId, $sectionProgress, $payload);
    $sectionProgress['current_slide_id'] = (int)($slideState['id'] ?? 0);
    $sectionProgress['current_slide_number'] = (int)($slideState['page_number'] ?? 0);
    $sectionProgressText = maya_section_progress_prompt($sectionProgress);

    $historyLines = [];
    $lastMayaText = '';
    foreach ($clientHistory as $h) {
        if (!is_array($h)) continue;
        $role = (string)($h['role'] ?? '');
        $msg  = trim((string)($h['message_body'] ?? $h['message'] ?? ''));
        $stg  = (string)($h['stage'] ?? '');
        if ($msg === '' || ($role !== 'maya' && $role !== 'student')) continue;
        $historyLines[] = strtoupper($role) . ($stg !== '' ? ' [' . $stg . ']' : '') . ': ' . maya_truncate($msg, 400);
        if ($role === 'maya') $lastMayaText = $msg;
    }
    $historyText = $historyLines ? implode("\n", $historyLines) : '(no prior turns)';

    $stateBlock =
        "Current stage: {$coachStage}\n"
        . "Maya's current question: " . ($currentQuestion !== '' ? $currentQuestion : '(none)') . "\n"
        . "Student reply this turn: " . ($studentReply !== '' ? $studentReply : '(none)') . "\n"
        . "Local observer flags: major_paste=" . ($serverMajorPaste ? 'true' : 'false')
        . ", wall_of_text=" . ($wallOfText ? 'true' : 'false')
        . ", word_count={$wordCount}, paragraph_count={$paragraphCount}\n"
        . "Current slide: " . ((int)($slideState['page_number'] ?? 0) ?: '(not set)') . "\n"
        . "Current slide concept hint: " . (string)($slideState['concept'] ?? 'lesson concept') . "\n"
        . "Client trigger: " . ($clientTrigger !== '' ? $clientTrigger : ($explicitStudentReply ? 'student_reply' : 'micro_checkpoint'));

    $diagnosticTask =
        "Before asking your next question, silently evaluate:\n\n"
        . "1. Which parts of the student summary are technically correct?\n"
        . "2. Which parts are merely descriptive?\n"
        . "3. Which parts need operational application?\n"
        . "4. Which parts need cause-and-effect correlation?\n"
        . "5. Which official reference concept would help strengthen understanding?\n"
        . "6. What would a real instructor ask next?\n"
        . "7. Is the next question within the current lesson scope?\n"
        . "8. Does it deepen what was taught, or does it introduce new material?\n"
        . "9. If it introduces new material, only ask it if the slide/reference context clearly supports it.\n\n"
        . "10. Has the current concept already been sufficiently demonstrated or closed?\n"
        . "11. Would another question on this same concept feel repetitive?\n"
        . "12. If the concept is closed, acknowledge it briefly and transition to another weak or unexplored lesson area.\n"
        . "13. Is the student still in structure-building mode, section-development mode, writing-guidance mode, or final-readiness mode?\n"
        . "14. What exact part of the summary should the student work on now?\n"
        . "15. Should the student answer in chat, write directly in the summary editor, or use an insertion button?\n"
        . "16. If the draft is empty or very short, can you propose 3–5 editable section headings from the lesson context?\n"
        . "17. If a concept is mature but no insertion is appropriate, did you tell the student to write it in their own words?\n"
        . "18. If client_trigger is idle, the student likely wrote in the editor. Evaluate the summary editor text directly instead of repeating the prior question.\n"
        . "19. Are you staying strictly inside the CURRENT SLIDE CONTEXT or assigned slide series?\n\n"
        . "Then give ONE primary task only: either a Summary Editor task OR a This Chat question. Do not assign both unless it is a rare transition.\n\n"
        . "Do NOT ask generic \"most important idea\" questions. Prefer lesson-scope questions about pilot action, preflight verification, operational consequence, or go/no-go decision making.";

    $userPrompt =
        "LESSON TITLE\n" . ($lessonTitle !== '' ? $lessonTitle : '(unknown)') . "\n\n"
        . "LESSON SLIDE CONTEXT\n"
        . ($lessonContext !== '' ? $lessonContext : '(no slide context indexed)') . "\n\n"
        . "OFFICIAL REFERENCE CONTEXT\n"
        . ($officialReferenceText !== '' ? $officialReferenceText : '(no live official resource excerpts resolved for this checkpoint)') . "\n\n"
        . "STUDENT SUMMARY EXCERPT (current draft):\n"
        . ($excerptForModel !== '' ? $excerptForModel : '(student has not written anything yet)') . "\n\n"
        . "CURRENT SLIDE CONTEXT\n"
        . "Scope rule: stay inside this current slide unless an assigned slide series is explicitly provided.\n"
        . "Slide number: " . ((int)($slideState['page_number'] ?? 0) ?: '(not set)') . "\n"
        . "Specific concept hint: " . (string)($slideState['concept'] ?? 'lesson concept') . "\n"
        . "Slide text excerpt: " . ((string)($slideState['text'] ?? '') !== '' ? (string)$slideState['text'] : '(no slide text available)') . "\n\n"
        . "RECENT COACH HISTORY:\n" . $historyText . "\n\n"
        . "COACHING DIAGNOSTIC TASK\n" . $diagnosticTask . "\n\n"
        . "COACHING FOCUS HINT\n" . $coachingFocus . "\n\n"
        . "CONCEPT PROGRESSION STATE\n"
        . "Current concept: " . maya_concept_label($activeConcept) . "\n"
        . "Current concept already closed: " . ($activeConceptClosed ? 'true' : 'false') . "\n"
        . $conceptProgressText . "\n\n"
        . "SECTION PROGRESSION STATE\n"
        . $sectionProgressText . "\n\n"
        . "STATE:\n" . $stateBlock . "\n\n"
        . "Coach the student per the rules. Pick the most useful next stage. "
        . "Ask like a flight instructor: operational, causal, safety-oriented, and cockpit-aware. "
        . "If the current concept is closed or saturated, do not ask another variation about it; acknowledge and move to a different lesson area. "
        . "Guide summary construction explicitly: identify the current section, tell the student whether to write in the editor or answer in chat, and avoid endless chat-only coaching. "
        . "Never ask broad slide reflection questions. Give a targeted writing task based on the current slide concept and evaluate the summary text directly. "
        . "If client_trigger is idle, evaluate what the student wrote in the summary editor and do not repeat your previous chat message. "
        . "Choose either a Summary Editor task or a This Chat question for this turn, not both. "
        . "Score honestly. Output structured JSON only.";

    try {
        $aiJson = maya_call_openai(
            maya_system_prompt(),
            $userPrompt,
            maya_response_schema(false),
            'maya_summary_coach_micro'
        );
    } catch (Throwable $e) {
        // Don't fake a result. Surface a clean error and let the UI display
        // the calm fallback message. Server state is unchanged.
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'Maya coach temporarily unavailable: ' . $e->getMessage(),
        ]);
        exit;
    }

    // Sanitize / clamp
    $mayaMessage = trim((string)($aiJson['maya_message'] ?? ''));
    if ($mayaMessage === '') {
        $mayaMessage = 'Let\'s keep building this together.';
    }
    $nextQuestion = trim((string)($aiJson['next_question'] ?? ''));
    if ($nextQuestion === '') {
        $nextQuestion = 'Pick one idea from your summary and explain how it affects a pilot action, preflight check, or go/no-go decision covered by this lesson.';
    }
    $newStage = maya_normalize_stage($aiJson['stage'] ?? $coachStage);
    if ($wordCount < 15 && $studentReply === '') {
        $newStage = MAYA_STAGE_STRUCTURE;
        if ($derivedSections) {
            $mayaMessage = 'Let’s build your summary structure first. Add these editable headings, then we’ll work through them one by one.';
            $nextQuestion = 'Start with the first section, ' . $derivedSections[0] . ': what should a pilot understand or do for that part of the lesson?';
        } else {
            $mayaMessage = 'Let’s build your summary structure first. Once we have the headings, we’ll develop each section together.';
            $nextQuestion = 'What are the 3–5 main areas this lesson covered?';
        }
    }

    $aiScores = is_array($aiJson['scores'] ?? null) ? $aiJson['scores'] : [];
    $scores = [
        'coverage'              => maya_clamp_int($aiScores['coverage']              ?? 0, 0, 100, 0),
        'accuracy'              => maya_clamp_int($aiScores['accuracy']              ?? 0, 0, 100, 0),
        'own_wording'           => maya_clamp_int($aiScores['own_wording']           ?? 0, 0, 100, 0),
        'correlation'           => maya_clamp_int($aiScores['correlation']           ?? 0, 0, 100, 0),
        'instructor_confidence' => maya_clamp_int($aiScores['instructor_confidence'] ?? 0, 0, 100, 0),
    ];

    $aiFlags = is_array($aiJson['flags'] ?? null) ? $aiJson['flags'] : [];
    $modelMajorPaste = !empty($aiFlags['major_paste']);
    $needsDeeper     = !empty($aiFlags['needs_deeper_question']);

    // Paste flag clearing rule: if server already had paste=true, only allow
    // clearing when the model explicitly says paste=false (model has been
    // instructed to do so only after the student's own-words explanation).
    if ($serverMajorPaste && !$modelMajorPaste && $studentReply !== '') {
        $persistedMajorPaste = false;
    } else {
        $persistedMajorPaste = $serverMajorPaste || $modelMajorPaste;
    }

    $aiReadiness = is_array($aiJson['readiness'] ?? null) ? $aiJson['readiness'] : [];
    $unresolved = !empty($aiReadiness['unresolved_required_question']) || $needsDeeper;
    if ($studentReply === '' && $currentQuestion !== '') {
        // No reply this turn — the current question is still unresolved.
        $unresolved = true;
    }

    $newInteractionCount = (int)($session['interaction_count'] ?? 0);
    if ($studentReply !== '' || $explicitStudentReply) {
        $newInteractionCount++;
    }

    $readinessOut = maya_compute_readiness(
        $scores,
        $newInteractionCount,
        $persistedMajorPaste,
        $unresolved
    );

    $studentNote = trim((string)($aiJson['student_note_suggestion'] ?? ''));
    // Hard cap so this can never become a paste-able full answer.
    if (mb_strlen($studentNote) > 240) {
        $studentNote = mb_substr($studentNote, 0, 240);
    }
    $rawSummaryInsertions = $aiJson['summary_insertions'] ?? [];
    $updatedConceptProgress = maya_update_concept_progress(
        $existingFlags,
        $activeConcept,
        $studentReply,
        $oldScores,
        $scores
    );
    $conceptJustClosed = !empty($updatedConceptProgress[$activeConcept]['closed']) && empty($conceptProgress[$activeConcept]['closed']);
    if ($conceptJustClosed) {
        $nextQuestion = maya_transition_question_for_closed_concept($activeConcept, $summaryPlainForFocus);
        $newStage = MAYA_STAGE_CORRELATE;
        $currentSection = trim((string)($sectionProgress['current_section'] ?? ''));
        if ($currentSection !== '' && !in_array($currentSection, $sectionProgress['completed_sections'], true)) {
            $sectionProgress['completed_sections'][] = $currentSection;
        }
        foreach ($sectionProgress['proposed_sections'] as $sectionName) {
            if (!in_array($sectionName, $sectionProgress['completed_sections'], true)) {
                $sectionProgress['current_section'] = $sectionName;
                break;
            }
        }
        $sectionProgress['current_stage'] = 'next_section';
        if (stripos($mayaMessage, 'good') === false && stripos($mayaMessage, 'yes') === false && stripos($mayaMessage, 'correct') === false) {
            $mayaMessage = 'Good — you connected the concept to a real pilot decision. ' . $mayaMessage;
        }
    }
    $allowSummaryInsertions = maya_concept_allows_summary_insertions(
        $updatedConceptProgress,
        $activeConcept,
        $studentReply,
        $oldScores,
        $scores
    );
    $allowedEarlyInsertionTypes = [];
    if ($newStage === MAYA_STAGE_STRUCTURE || $wordCount < 30) {
        $allowedEarlyInsertionTypes = ['structure', 'heading'];
    }
    if ($newStage !== MAYA_STAGE_FINAL_REVIEW) {
        $allowedEarlyInsertionTypes[] = 'reminder';
    }
    if ($allowSummaryInsertions) {
        $allowedEarlyInsertionTypes = array_merge($allowedEarlyInsertionTypes, [
            'bullet', 'highlighted_note', 'remark', 'warning', 'caution', 'attention',
            'mnemonic', 'quote', 'rule_of_thumb',
        ]);
    }
    $summaryInsertions = maya_sanitize_summary_insertions(
        $rawSummaryInsertions,
        $studentReply !== '' && $allowSummaryInsertions,
        array_values(array_unique($allowedEarlyInsertionTypes))
    );
    if ($wordCount < 15 && $derivedSections) {
        $summaryInsertions = array_values(array_filter($summaryInsertions, static function ($ins) {
            return !(is_array($ins) && ($ins['insertion_type'] ?? '') === 'structure');
        }));
        $structureInsertion = maya_make_structure_insertion($derivedSections);
        if ($structureInsertion) {
            array_unshift($summaryInsertions, $structureInsertion);
            $summaryInsertions = array_slice($summaryInsertions, 0, 2);
        }
        $headingList = maya_structure_titles_inline($derivedSections);
        if ($headingList !== '') {
            $mayaMessage = "Good call — your draft needs structure first. We’ll build it slide-by-slide.\n\n"
                . "In your <strong><u>Summary Editor</u></strong> → Use the button below to add these exact headings: "
                . $headingList . ". We will fill each section together.\n\n"
                . "In <strong><u>This Chat</u></strong> → After the structure is added, go through the first slide and come back when you’re ready to build the first section.";
            $nextQuestion = '';
        }
    }
    $awaitingChatReply = maya_awaiting_chat_reply($mayaMessage, $nextQuestion);
    $writingTask = $awaitingChatReply
        ? 'Answer Maya'
        : maya_default_writing_task($sectionProgress, $slideState, $newStage);
    if ($wordCount < 15 && $derivedSections) {
        $writingTask = 'Add the lesson structure to your summary, then go through Slide ' . max(1, (int)($slideState['page_number'] ?? 1)) . ' before building the first section.';
        $awaitingChatReply = false;
    }
    $sectionProgress['current_writing_task'] = $writingTask;
    $sectionProgress['awaiting_chat_reply'] = $awaitingChatReply;
    $persistedFlags = [
        'major_paste' => $persistedMajorPaste,
        'needs_deeper_question' => $needsDeeper,
        'concept_progress' => $updatedConceptProgress,
        'active_concept' => $activeConcept,
        'summary_insertions_allowed' => $allowSummaryInsertions,
        'section_progress' => array_merge($sectionProgress, ['current_stage' => $newStage]),
    ];
    $mayaBubbleText = $mayaMessage;
    if ($nextQuestion !== '' && !maya_message_already_contains_question($mayaBubbleText, $nextQuestion)) {
        $mayaBubbleText .= "\n\n" . $nextQuestion;
    }
    if ($clientTrigger === 'idle' && $lastMayaText !== '' && trim($mayaBubbleText) === trim($lastMayaText)) {
        $sectionName = trim((string)($sectionProgress['current_section'] ?? 'this section'));
        $slideNo = (int)($slideState['page_number'] ?? 0);
        $mayaMessage = 'I reviewed what you added in the summary editor. Keep working inside Slide ' . max(1, $slideNo) . ' and ' . $sectionName . ': make sure the bullet includes the concept, why it matters, and the pilot action.';
        $nextQuestion = '';
        $mayaBubbleText = $mayaMessage;
    }

    if ($studentReply !== '') {
        maya_insert_message($pdo, $session, 'student', 'chat', $studentReply);
    }
    $mayaHistoryMessage = maya_insert_message($pdo, $session, 'maya', 'chat', $mayaBubbleText, $scores, $persistedFlags, $summaryInsertions);

    maya_save_session($pdo, $sessionId, [
        'stage' => $newStage,
        'scores_json' => json_encode($scores),
        'readiness_json' => json_encode($readinessOut),
        'flags_json' => json_encode($persistedFlags),
        'last_question' => $nextQuestion,
        'interaction_count' => $newInteractionCount,
        'major_paste_flag' => $persistedMajorPaste ? 1 : 0,
        'ready_for_final_review' => $readinessOut['ready_for_final_review'] ? 1 : 0,
    ]);

    return [
        'ok' => true,
        'maya_message' => $mayaMessage,
        'next_question' => $nextQuestion,
        'stage' => $newStage,
        'scores' => $scores,
        'readiness' => $readinessOut,
        'flags' => $persistedFlags,
        'coaching_state' => [
            'current_writing_task' => $writingTask,
            'awaiting_chat_reply' => $awaitingChatReply,
            'current_section' => (string)($sectionProgress['current_section'] ?? ''),
            'current_slide_id' => (int)($sectionProgress['current_slide_id'] ?? 0),
            'current_slide_number' => (int)($sectionProgress['current_slide_number'] ?? 0),
        ],
        'interaction_count' => $newInteractionCount,
        'student_note_suggestion' => $studentNote,
        'summary_insertions' => $summaryInsertions,
        'message' => $mayaHistoryMessage,
    ];
}

function maya_action_readiness_check(PDO $pdo, array $u, array $payload): array
{
    [$_, $lessonId, $cohortId, $summaryId, $context] = maya_extract_common_input($payload);
    maya_assert_lesson_access($pdo, $u, $cohortId, $lessonId);

    $userId = (int)$u['id'];
    $session = maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    if (!$session) {
        // No session yet → trivially not ready.
        return [
            'ok' => true,
            'readiness' => maya_compute_readiness(
                ['coverage' => 0, 'accuracy' => 0, 'own_wording' => 0, 'correlation' => 0, 'instructor_confidence' => 0],
                0, false, true
            ),
            'scores' => ['coverage' => 0, 'accuracy' => 0, 'own_wording' => 0, 'correlation' => 0, 'instructor_confidence' => 0],
            'flags' => ['major_paste' => false, 'needs_deeper_question' => false],
            'interaction_count' => 0,
            'stage' => MAYA_STAGE_STRUCTURE,
        ];
    }

    $scores = maya_decode_json_column($session['scores_json'] ?? null);
    $flags  = maya_decode_json_column($session['flags_json']  ?? null);
    if (!is_array($scores)) $scores = [];
    if (!is_array($flags))  $flags  = [];

    $unresolved = false;
    $readinessExisting = maya_decode_json_column($session['readiness_json'] ?? null);
    if (is_array($readinessExisting) && isset($readinessExisting['unresolved_required_question'])) {
        $unresolved = (bool)$readinessExisting['unresolved_required_question'];
    }

    $readinessOut = maya_compute_readiness(
        $scores,
        (int)($session['interaction_count'] ?? 0),
        (int)($session['major_paste_flag'] ?? 0) === 1,
        $unresolved
    );

    return [
        'ok' => true,
        'readiness' => $readinessOut,
        'scores' => $scores,
        'flags' => $flags,
        'interaction_count' => (int)($session['interaction_count'] ?? 0),
        'stage' => (string)($session['stage'] ?? MAYA_STAGE_STRUCTURE),
    ];
}

function maya_action_mark_inserted(PDO $pdo, array $u, array $payload): array
{
    $messageId = isset($payload['message_id']) ? (int)$payload['message_id'] : 0;
    $insertionId = trim((string)($payload['insertion_id'] ?? ''));
    $sessionId = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
    if ($messageId <= 0 || $insertionId === '') {
        maya_fail(400, 'message_id and insertion_id required');
    }

    $userId = (int)$u['id'];
    $sql = "
        SELECT m.*, s.context
        FROM student_summary_coach_messages m
        JOIN student_summary_coach_sessions s ON s.id = m.session_id
        WHERE m.id=? AND m.user_id=?
    ";
    $vals = [$messageId, $userId];
    if ($sessionId > 0) {
        $sql .= " AND m.session_id=?";
        $vals[] = $sessionId;
    }
    $sql .= " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($vals);
    $message = $st->fetch(PDO::FETCH_ASSOC);
    if (!$message) {
        maya_fail(404, 'Coaching message not found');
    }
    maya_assert_lesson_access($pdo, $u, (int)($message['cohort_id'] ?? 0), (int)($message['lesson_id'] ?? 0));

    $insertions = maya_decode_json_column($message['summary_insertions_json'] ?? null);
    $changed = false;
    $insertedType = '';
    if (is_array($insertions)) {
        foreach ($insertions as &$ins) {
            if (!is_array($ins) || (string)($ins['id'] ?? '') !== $insertionId) continue;
            $ins['inserted'] = true;
            $insertedType = (string)($ins['insertion_type'] ?? '');
            $changed = true;
        }
        unset($ins);
    }
    if (!$changed) {
        maya_fail(404, 'Insertion not found');
    }
    $up = $pdo->prepare("
        UPDATE student_summary_coach_messages
        SET summary_insertions_json=?, inserted_into_summary=1
        WHERE id=? AND user_id=?
        LIMIT 1
    ");
    $up->execute([json_encode($insertions), $messageId, $userId]);
    if ($insertedType === 'structure') {
        $flags = maya_decode_json_column($message['flags_snapshot_json'] ?? null);
        $sessionIdForUpdate = (int)($message['session_id'] ?? 0);
        if ($sessionIdForUpdate > 0) {
            $stSession = $pdo->prepare('SELECT flags_json FROM student_summary_coach_sessions WHERE id=? AND user_id=? LIMIT 1');
            $stSession->execute([$sessionIdForUpdate, $userId]);
            $sessionFlags = maya_decode_json_column($stSession->fetchColumn() ?: '');
            $progress = maya_section_progress_from_flags($sessionFlags ?: $flags);
            $progress['summary_structure_added'] = true;
            $progress['awaiting_chat_reply'] = false;
            $progress['current_writing_task'] = 'Go through the first slide, then come back to Maya so you can build the first section in your own words.';
            $sessionFlags['section_progress'] = $progress;
            maya_save_session($pdo, $sessionIdForUpdate, ['flags_json' => json_encode($sessionFlags)]);
        }
    }
    return ['ok' => true];
}

function maya_action_final_review(PDO $pdo, array $u, array $payload): array
{
    [$_, $lessonId, $cohortId, $summaryId, $context] = maya_extract_common_input($payload);
    maya_assert_lesson_access($pdo, $u, $cohortId, $lessonId);

    $userId = (int)$u['id'];
    $session = maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    if (!$session) {
        $session = maya_create_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    }
    $sessionId = (int)$session['id'];

    // Server-side preflight: never let a student trigger expensive final review
    // unless saved coaching state already meets thresholds.
    $scores = maya_decode_json_column($session['scores_json'] ?? null);
    if (!is_array($scores)) $scores = [];
    $unresolvedSaved = false;
    $readSaved = maya_decode_json_column($session['readiness_json'] ?? null);
    if (is_array($readSaved) && isset($readSaved['unresolved_required_question'])) {
        $unresolvedSaved = (bool)$readSaved['unresolved_required_question'];
    }
    $preflight = maya_compute_readiness(
        $scores,
        (int)($session['interaction_count'] ?? 0),
        (int)($session['major_paste_flag'] ?? 0) === 1,
        $unresolvedSaved
    );

    if (!$preflight['ready_for_final_review']) {
        return [
            'ok' => true,
            'approved' => false,
            'maya_message' => 'You\'re not quite ready for final review yet. Let\'s close the gaps below before I sign this off.',
            'next_question' => trim((string)($session['last_question'] ?? '')) ?: 'Pick the weakest area below and explain it in your own words.',
            'stage' => (string)($session['stage'] ?? MAYA_STAGE_STRUCTURE),
            'scores' => $scores,
            'readiness' => $preflight,
            'flags' => maya_decode_json_column($session['flags_json'] ?? null) ?: ['major_paste' => false, 'needs_deeper_question' => false],
            'gated_by' => 'preflight',
        ];
    }

    $summaryHtml = (string)($payload['summary_html'] ?? '');
    $summaryPlain = $summaryHtml !== ''
        ? maya_strip_html_to_text($summaryHtml)
        : trim((string)($payload['summary_excerpt'] ?? ''));

    if ($summaryPlain === '') {
        return [
            'ok' => true,
            'approved' => false,
            'maya_message' => 'I need to see your full summary before I can do a final review.',
            'next_question' => 'Open the summary editor and write the full draft, then come back.',
            'stage' => (string)($session['stage'] ?? MAYA_STAGE_STRUCTURE),
            'scores' => $scores,
            'readiness' => $preflight,
            'flags' => ['major_paste' => false, 'needs_deeper_question' => false],
            'gated_by' => 'empty_summary',
        ];
    }

    $coachHistory = maya_load_latest_messages($pdo, $sessionId, 16);
    $historyLines = [];
    foreach ($coachHistory as $h) {
        if (!is_array($h)) continue;
        $role = (string)($h['role'] ?? '');
        $msg  = trim((string)($h['message_body'] ?? $h['message'] ?? ''));
        $stg  = (string)($h['stage'] ?? '');
        if ($msg === '' || ($role !== 'maya' && $role !== 'student')) continue;
        $historyLines[] = strtoupper($role) . ($stg !== '' ? ' [' . $stg . ']' : '') . ': ' . maya_truncate($msg, 400);
    }
    $historyText = $historyLines ? implode("\n", $historyLines) : '(no prior turns)';

    $lessonTitle = maya_get_lesson_title($pdo, $lessonId);
    $lessonContext = maya_build_lesson_context($pdo, $lessonId, 5000);
    $referenceBundle = maya_build_official_reference_context(
        $pdo,
        $lessonId,
        trim($lessonTitle . ' ' . $summaryPlain),
        3600
    );
    $officialReferenceText = trim((string)($referenceBundle['text'] ?? ''));
    $coachingFocus = maya_choose_coaching_focus_from_summary($lessonTitle, $summaryPlain, $referenceBundle);

    $userPrompt =
        "FINAL REVIEW.\n"
        . "LESSON TITLE\n" . ($lessonTitle !== '' ? $lessonTitle : '(unknown)') . "\n\n"
        . "LESSON SLIDE CONTEXT\n"
        . ($lessonContext !== '' ? $lessonContext : '(no lesson reference text indexed)') . "\n\n"
        . "OFFICIAL REFERENCE CONTEXT\n"
        . ($officialReferenceText !== '' ? $officialReferenceText : '(no live official resource excerpts resolved for final review)') . "\n\n"
        . "FULL STUDENT SUMMARY (plain text):\n"
        . maya_truncate($summaryPlain, 6000) . "\n\n"
        . "COACHING HISTORY (most recent):\n" . $historyText . "\n\n"
        . "COACHING FOCUS HINT\n" . $coachingFocus . "\n\n"
        . "Saved scores at preflight: " . json_encode($scores) . "\n\n"
        . "Perform a deep final review. Set approved=true ONLY if the summary "
        . "demonstrates real operational understanding, correlation between "
        . "concepts, the student's own wording, and good coverage. If not, set "
        . "approved=false and give one focused aviation-instructor question about the operational gap. "
        . "Do not ask generic most-important-idea or tell-me-more questions. Return JSON only.";

    try {
        $aiJson = maya_call_openai(
            maya_system_prompt() . "\n\nThis turn is the FINAL REVIEW. Be rigorous.",
            $userPrompt,
            maya_response_schema(true),
            'maya_summary_coach_final'
        );
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'Maya final review temporarily unavailable: ' . $e->getMessage(),
        ]);
        exit;
    }

    $approved = !empty($aiJson['approved']);
    $mayaMessage = trim((string)($aiJson['maya_message'] ?? ''));
    if ($mayaMessage === '') {
        $mayaMessage = $approved
            ? 'This summary demonstrates good operational understanding and correlation. Summary approved.'
            : 'Almost there — let\'s tighten one or two things and try again.';
    }
    $nextQuestion = trim((string)($aiJson['next_question'] ?? ''));
    if ($nextQuestion === '' && !$approved) {
        $nextQuestion = 'Pick the weakest area I called out and explain it in your own words.';
    }
    $newStage = $approved ? MAYA_STAGE_FINAL_REVIEW : maya_normalize_stage($aiJson['stage'] ?? MAYA_STAGE_OPERATIONAL_EXAMPLE);

    $aiScores = is_array($aiJson['scores'] ?? null) ? $aiJson['scores'] : [];
    $finalScores = [
        'coverage'              => maya_clamp_int($aiScores['coverage']              ?? $scores['coverage']              ?? 0, 0, 100, 0),
        'accuracy'              => maya_clamp_int($aiScores['accuracy']              ?? $scores['accuracy']              ?? 0, 0, 100, 0),
        'own_wording'           => maya_clamp_int($aiScores['own_wording']           ?? $scores['own_wording']           ?? 0, 0, 100, 0),
        'correlation'           => maya_clamp_int($aiScores['correlation']           ?? $scores['correlation']           ?? 0, 0, 100, 0),
        'instructor_confidence' => maya_clamp_int($aiScores['instructor_confidence'] ?? $scores['instructor_confidence'] ?? 0, 0, 100, 0),
    ];

    $aiFlags = is_array($aiJson['flags'] ?? null) ? $aiJson['flags'] : [];
    $finalFlags = [
        'major_paste' => !empty($aiFlags['major_paste']),
        'needs_deeper_question' => !empty($aiFlags['needs_deeper_question']),
    ];

    $unresolved = !empty($aiJson['readiness']['unresolved_required_question']);
    if (!$approved && $nextQuestion !== '') {
        $unresolved = true;
    }
    $finalReadiness = maya_compute_readiness(
        $finalScores,
        max((int)($session['interaction_count'] ?? 0), MAYA_MIN_INTERACTIONS),
        $finalFlags['major_paste'],
        $unresolved
    );

    // If the model approved AND server thresholds still hold AND the model's
    // readiness aligns, delegate canonical acceptance to the production path.
    $canonical = null;
    if ($approved && $finalReadiness['ready_for_final_review']) {
        try {
            $service = new LessonSummaryService($pdo);
            $canonical = $service->checkSummary(
                $userId,
                $cohortId,
                $lessonId,
                'student'
            );
        } catch (Throwable $e) {
            // Do not fake acceptance. Report Maya's verdict but flag canonical
            // failure so the UI does not pretend the summary was accepted.
            $canonical = ['ok' => false, 'error' => $e->getMessage()];
        }

        // If canonical evaluator did not accept it, downgrade Maya's verdict.
        if (!$canonical || !($canonical['ok'] ?? false)
            || ((string)($canonical['review_status'] ?? '') !== 'acceptable')) {
            $approved = false;
            if ($mayaMessage === '' || $approved) {
                $mayaMessage = 'I felt good about this, but the official summary check came back as needing revision. Open the canonical feedback and tighten it up.';
            } else {
                $mayaMessage .= ' Note: the official check came back as needing revision — open the canonical feedback to see why.';
            }
            $finalReadiness['ready_for_final_review'] = false;
            $finalReadiness['missing'][] = 'Canonical summary check requested revisions';
        }
    }

    // Persist Maya's final review state as individual message rows.
    maya_insert_message($pdo, $session, 'system', 'system', 'Requested Final Review', $scores, maya_decode_json_column($session['flags_json'] ?? null) ?: []);
    $mayaBubbleText = $mayaMessage;
    if (!$approved && $nextQuestion !== '' && !maya_message_already_contains_question($mayaBubbleText, $nextQuestion)) {
        $mayaBubbleText .= "\n\n" . $nextQuestion;
    }
    $mayaHistoryMessage = maya_insert_message(
        $pdo,
        $session,
        'maya',
        $approved ? 'final_approved' : 'final_revision',
        $mayaBubbleText,
        $finalScores,
        $finalFlags
    );

    maya_save_session($pdo, $sessionId, [
        'stage' => $newStage,
        'scores_json' => json_encode($finalScores),
        'readiness_json' => json_encode($finalReadiness),
        'flags_json' => json_encode($finalFlags),
        'last_question' => $approved ? '' : $nextQuestion,
        'major_paste_flag' => $finalFlags['major_paste'] ? 1 : 0,
        'ready_for_final_review' => $finalReadiness['ready_for_final_review'] ? 1 : 0,
        'approved_by_maya' => $approved ? 1 : 0,
    ]);

    return [
        'ok' => true,
        'approved' => $approved,
        'maya_message' => $mayaMessage,
        'next_question' => $approved ? '' : $nextQuestion,
        'stage' => $newStage,
        'scores' => $finalScores,
        'readiness' => $finalReadiness,
        'flags' => $finalFlags,
        'canonical_check' => $canonical,
        'message' => $mayaHistoryMessage,
    ];
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        maya_fail(405, 'POST required');
    }

    $u = cw_current_user($pdo);
    if (!$u) {
        maya_fail(401, 'Not authenticated');
    }
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        maya_fail(403, 'Forbidden');
    }

    $payload = maya_read_json_body();
    $action = trim((string)($payload['action'] ?? ''));

    switch ($action) {
        case 'start_session':
            $out = maya_action_start_session($pdo, $u, $payload);
            break;

        case 'micro_checkpoint':
            $out = maya_action_checkpoint($pdo, $u, $payload, false);
            break;

        case 'student_reply':
            $out = maya_action_checkpoint($pdo, $u, $payload, true);
            break;

        case 'readiness_check':
            $out = maya_action_readiness_check($pdo, $u, $payload);
            break;

        case 'load_history':
            $out = maya_action_load_history($pdo, $u, $payload);
            break;

        case 'mark_inserted':
            $out = maya_action_mark_inserted($pdo, $u, $payload);
            break;

        case 'final_review':
            $out = maya_action_final_review($pdo, $u, $payload);
            break;

        default:
            maya_fail(400, 'Unknown action');
            return; // unreachable, satisfies analyzers
    }

    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Maya coach error: ' . $e->getMessage(),
    ]);
}
