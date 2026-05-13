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
const MAYA_HISTORY_PAGE_SIZE = 30;

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

function maya_decode_json_column($raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
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
function maya_compose_initial_greeting(array $snap, int $existingInteractions, string $persistedStage): array
{
    $status = (string)($snap['review_status'] ?? 'missing');
    $locked = (int)($snap['student_soft_locked'] ?? 0) === 1;
    $words = (int)($snap['word_count'] ?? 0);

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
                "Good — looks like you've already written a solid draft (~{$words} words). "
                . "Let's pick up where you left off. Tell me, in your own words, the most "
                . "important idea you've written so far and why it matters during a real flight.",
            'next_question' =>
                "What's the single most important idea in your current summary, and why does it matter in the cockpit?",
            'stage' => $persistedStage ?: MAYA_STAGE_EXPLAIN,
        ];
    }

    if ($words >= 15) {
        return [
            'maya_message' =>
                "I see you've started writing — that's a great start. Let's build on it together. "
                . "Don't worry about polishing yet; just tell me the main ideas of this lesson "
                . "in your own words.",
            'next_question' =>
                "What are the 3–5 main ideas you want this summary to cover?",
            'stage' => $persistedStage ?: MAYA_STAGE_STRUCTURE,
        ];
    }

    // 3) Returning student with prior coaching turns but no draft yet.
    if ($existingInteractions >= 1) {
        return [
            'maya_message' =>
                "Welcome back. Your summary editor looks empty right now — let's start "
                . "by capturing the main ideas of this lesson in your own words.",
            'next_question' => "What are the 3–5 most important ideas from this lesson?",
            'stage' => null,
        ];
    }

    // 4) Empty draft, fresh session → original onboarding tone.
    return [
        'maya_message' =>
            "Hi! Let's build your summary together. Start by writing the main ideas of "
            . "this lesson in your own words.",
        'next_question' => "What are the 3–5 most important ideas from this lesson?",
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

    $firstQ = "What are the 3–5 most important ideas from this lesson?";

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
        . "4. Ask 'why', 'how', 'what if', and 'how would this affect a real flight?'\n"
        . "5. Direct the student where to look in the lesson — not to a paste-able answer.\n"
        . "6. Never provide the full lesson summary. Never provide complete copy-pasteable answers to lesson concepts.\n"
        . "7. Never accept pasted text without probing understanding. If flags.major_paste was true and the student's reply convincingly explains the pasted content in their own words, you MAY clear it; otherwise leave it true.\n"
        . "8. student_note_suggestion must be empty unless it is clearly based on the student's own response, and must not be a full answer or a complete summary paragraph. Use a short bullet or phrase the student can refine, or empty string.\n"
        . "9. Score rubric (0-100):\n"
        . "   - coverage: how much of the lesson's primary concepts the summary covers.\n"
        . "   - accuracy: technical correctness of what is written.\n"
        . "   - own_wording: degree to which the summary is in the student's own words (not pasted).\n"
        . "   - correlation: cause-and-effect / how concepts connect.\n"
        . "   - instructor_confidence: would you sign this student off on this topic? (operational understanding).\n"
        . "10. Stage progression: structure → explain → correlate → operational_example → readiness.\n"
        . "    Pick the most useful next stage based on what is weakest. Never jump to final_review unless input.action == 'final_review'.\n"
        . "11. ready_for_final_review is advisory only. The server enforces final thresholds. Be honest, not generous.";
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
        ],
        'required' => [
            'maya_message',
            'next_question',
            'stage',
            'scores',
            'readiness',
            'flags',
            'student_note_suggestion',
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
    $history   = maya_decode_json_column($session['history_json']   ?? null);
    if (!is_array($history)) $history = [];

    $persistedStage = (string)($session['stage'] ?? MAYA_STAGE_STRUCTURE);
    $interactionCount = (int)($session['interaction_count'] ?? 0);

    // Adaptive greeting based on canonical lesson_summaries state.
    $snap = maya_get_summary_snapshot($pdo, $userId, $cohortId, $lessonId);
    $greeting = maya_compose_initial_greeting($snap, $interactionCount, $persistedStage);

    $newStage = $greeting['stage'] !== null ? $greeting['stage'] : $persistedStage;
    $nextQ    = $greeting['next_question'];
    $msg      = $greeting['maya_message'];

    // Append the greeting to persisted history so it shows in the chat
    // when the modal reopens. We mark this Maya turn with a `kind` so the
    // frontend can recognise greetings vs. coaching turns if it cares.
    $history[] = [
        'role' => 'maya',
        'message' => $msg,
        'stage' => $newStage,
        'kind' => 'greeting',
        'ts' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if (count($history) > MAYA_HISTORY_DB_CAP) {
        $history = array_slice($history, -MAYA_HISTORY_DB_CAP);
    }

    maya_save_session($pdo, (int)$session['id'], [
        'stage' => $newStage,
        'last_question' => $nextQ,
        'history_json' => json_encode($history),
    ]);

    // Page the most recent slice for the chat thread.
    $total = count($history);
    $pageStart = max(0, $total - MAYA_HISTORY_PAGE_SIZE);
    $page = array_slice($history, $pageStart);
    // Tag with absolute index so the client can request older-than-this.
    $oldestIndex = $total > 0 ? $pageStart : 0;
    $hasMore = $pageStart > 0;

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
        'interaction_count' => $interactionCount,
        'major_paste_flag' => (int)($session['major_paste_flag'] ?? 0) === 1,
        'history' => array_values($page),
        'history_oldest_index' => $oldestIndex,
        'history_total' => $total,
        'history_has_more' => $hasMore,
        'student_note_suggestion' => '',
    ];
}

/**
 * Cursor-paginated history loader for lazy scroll-up in the chat thread.
 * Request: { action:'load_history', lesson_id, cohort_id, context, before_index }
 * Response: { ok, history:[...], history_oldest_index, history_has_more }
 */
function maya_action_load_history(PDO $pdo, array $u, array $payload): array
{
    [$_, $lessonId, $cohortId, $summaryId, $context] = maya_extract_common_input($payload);
    maya_assert_lesson_access($pdo, $u, $cohortId, $lessonId);

    $userId = (int)$u['id'];
    $session = maya_load_session($pdo, $userId, $lessonId, $cohortId, $summaryId > 0 ? $summaryId : null, $context);
    if (!$session) {
        return [
            'ok' => true,
            'history' => [],
            'history_oldest_index' => 0,
            'history_has_more' => false,
            'history_total' => 0,
        ];
    }

    $beforeIndex = isset($payload['before_index']) ? (int)$payload['before_index'] : 0;
    if ($beforeIndex < 0) $beforeIndex = 0;

    $history = maya_decode_json_column($session['history_json'] ?? null);
    if (!is_array($history)) $history = [];
    $total = count($history);

    if ($beforeIndex <= 0 || $total === 0) {
        return [
            'ok' => true,
            'history' => [],
            'history_oldest_index' => 0,
            'history_has_more' => false,
            'history_total' => $total,
        ];
    }

    $end = min($beforeIndex, $total);
    $start = max(0, $end - MAYA_HISTORY_PAGE_SIZE);
    $page = array_slice($history, $start, $end - $start);

    return [
        'ok' => true,
        'history' => array_values($page),
        'history_oldest_index' => $start,
        'history_has_more' => $start > 0,
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

    $localFlagsRaw = is_array($payload['local_flags'] ?? null) ? $payload['local_flags'] : [];
    $clientMajorPaste = !empty($localFlagsRaw['major_paste']);
    $wallOfText       = !empty($localFlagsRaw['wall_of_text']);
    $wordCount        = isset($localFlagsRaw['word_count'])      ? (int)$localFlagsRaw['word_count']      : 0;
    $paragraphCount   = isset($localFlagsRaw['paragraph_count']) ? (int)$localFlagsRaw['paragraph_count'] : 0;

    $clientHistory = is_array($payload['coach_history'] ?? null) ? $payload['coach_history'] : [];
    // Trim history to the most recent 10 entries to bound prompt size.
    if (count($clientHistory) > 10) {
        $clientHistory = array_slice($clientHistory, -10);
    }

    $existingFlags = maya_decode_json_column($session['flags_json'] ?? null);
    if (!is_array($existingFlags)) $existingFlags = [];
    $serverMajorPaste = ((int)($session['major_paste_flag'] ?? 0) === 1) || $clientMajorPaste;

    // Compose user prompt for the model. Keep it tight: lesson title + small
    // lesson context excerpt + current state + recent coach history + student
    // input. We DO NOT send the full lesson on every checkpoint.
    $lessonTitle = maya_get_lesson_title($pdo, $lessonId);
    $lessonContext = maya_build_lesson_context($pdo, $lessonId, 4500);

    $excerptForModel = '';
    if ($summaryExcerpt !== '') {
        $excerptForModel = maya_truncate($summaryExcerpt, 1800);
    } elseif ($summaryPlainFromHtml !== '') {
        $excerptForModel = maya_truncate($summaryPlainFromHtml, 1800);
    }

    $historyLines = [];
    foreach ($clientHistory as $h) {
        if (!is_array($h)) continue;
        $role = (string)($h['role'] ?? '');
        $msg  = trim((string)($h['message'] ?? ''));
        $stg  = (string)($h['stage'] ?? '');
        if ($msg === '' || ($role !== 'maya' && $role !== 'student')) continue;
        $historyLines[] = strtoupper($role) . ($stg !== '' ? ' [' . $stg . ']' : '') . ': ' . maya_truncate($msg, 400);
    }
    $historyText = $historyLines ? implode("\n", $historyLines) : '(no prior turns)';

    $stateBlock =
        "Current stage: {$coachStage}\n"
        . "Maya's current question: " . ($currentQuestion !== '' ? $currentQuestion : '(none)') . "\n"
        . "Student reply this turn: " . ($studentReply !== '' ? $studentReply : '(none)') . "\n"
        . "Local observer flags: major_paste=" . ($serverMajorPaste ? 'true' : 'false')
        . ", wall_of_text=" . ($wallOfText ? 'true' : 'false')
        . ", word_count={$wordCount}, paragraph_count={$paragraphCount}\n"
        . "Trigger: " . ($explicitStudentReply ? 'student_reply' : 'micro_checkpoint');

    $userPrompt =
        "LESSON TITLE:\n" . ($lessonTitle !== '' ? $lessonTitle : '(unknown)') . "\n\n"
        . "LESSON REFERENCE CONTEXT (excerpt — do not paste this back to the student):\n"
        . ($lessonContext !== '' ? $lessonContext : '(no lesson reference text indexed)') . "\n\n"
        . "STUDENT SUMMARY EXCERPT (current draft):\n"
        . ($excerptForModel !== '' ? $excerptForModel : '(student has not written anything yet)') . "\n\n"
        . "RECENT COACH HISTORY:\n" . $historyText . "\n\n"
        . "STATE:\n" . $stateBlock . "\n\n"
        . "Coach the student per the rules. Pick the most useful next stage. "
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
        $nextQuestion = 'What\'s the most important idea you have written so far, and why does it matter in flight?';
    }
    $newStage = maya_normalize_stage($aiJson['stage'] ?? $coachStage);

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

    $appendedHistory = maya_decode_json_column($session['history_json'] ?? null);
    if (!is_array($appendedHistory)) $appendedHistory = [];
    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    if ($studentReply !== '') {
        $appendedHistory[] = [
            'role' => 'student',
            'message' => maya_truncate($studentReply, 800),
            'stage' => $coachStage,
            'kind' => 'turn',
            'ts' => $nowIso,
        ];
    }
    $appendedHistory[] = [
        'role' => 'maya',
        'message' => maya_truncate($mayaMessage, 800),
        'stage' => $newStage,
        'kind' => 'turn',
        'ts' => $nowIso,
    ];
    if (count($appendedHistory) > MAYA_HISTORY_DB_CAP) {
        $appendedHistory = array_slice($appendedHistory, -MAYA_HISTORY_DB_CAP);
    }

    $persistedFlags = [
        'major_paste' => $persistedMajorPaste,
        'needs_deeper_question' => $needsDeeper,
    ];

    maya_save_session($pdo, $sessionId, [
        'stage' => $newStage,
        'scores_json' => json_encode($scores),
        'readiness_json' => json_encode($readinessOut),
        'flags_json' => json_encode($persistedFlags),
        'history_json' => json_encode($appendedHistory),
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
        'interaction_count' => $newInteractionCount,
        'student_note_suggestion' => $studentNote,
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

    $coachHistory = is_array($payload['coach_history'] ?? null) ? $payload['coach_history'] : [];
    if (!$coachHistory) {
        $coachHistory = maya_decode_json_column($session['history_json'] ?? null);
    }
    if (count($coachHistory) > 16) {
        $coachHistory = array_slice($coachHistory, -16);
    }
    $historyLines = [];
    foreach ($coachHistory as $h) {
        if (!is_array($h)) continue;
        $role = (string)($h['role'] ?? '');
        $msg  = trim((string)($h['message'] ?? ''));
        $stg  = (string)($h['stage'] ?? '');
        if ($msg === '' || ($role !== 'maya' && $role !== 'student')) continue;
        $historyLines[] = strtoupper($role) . ($stg !== '' ? ' [' . $stg . ']' : '') . ': ' . maya_truncate($msg, 400);
    }
    $historyText = $historyLines ? implode("\n", $historyLines) : '(no prior turns)';

    $lessonTitle = maya_get_lesson_title($pdo, $lessonId);
    $lessonContext = maya_build_lesson_context($pdo, $lessonId, 8000);

    $userPrompt =
        "FINAL REVIEW.\n"
        . "LESSON TITLE:\n" . ($lessonTitle !== '' ? $lessonTitle : '(unknown)') . "\n\n"
        . "LESSON REFERENCE CONTENT:\n"
        . ($lessonContext !== '' ? $lessonContext : '(no lesson reference text indexed)') . "\n\n"
        . "FULL STUDENT SUMMARY (plain text):\n"
        . maya_truncate($summaryPlain, 6000) . "\n\n"
        . "COACHING HISTORY (most recent):\n" . $historyText . "\n\n"
        . "Saved scores at preflight: " . json_encode($scores) . "\n\n"
        . "Perform a deep final review. Set approved=true ONLY if the summary "
        . "demonstrates real operational understanding, correlation between "
        . "concepts, the student's own wording, and good coverage. If not, set "
        . "approved=false and give one focused next question. Return JSON only.";

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

    // Persist Maya's final review state.
    $appendedHistory = maya_decode_json_column($session['history_json'] ?? null);
    if (!is_array($appendedHistory)) $appendedHistory = [];
    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    $appendedHistory[] = [
        'role' => 'student',
        'message' => '[Requested Final Review]',
        'stage' => 'readiness',
        'kind' => 'system',
        'ts' => $nowIso,
    ];
    $appendedHistory[] = [
        'role' => 'maya',
        'message' => maya_truncate($mayaMessage, 800),
        'stage' => $newStage,
        'kind' => $approved ? 'final_approved' : 'final_revision',
        'ts' => $nowIso,
    ];
    if (count($appendedHistory) > MAYA_HISTORY_DB_CAP) {
        $appendedHistory = array_slice($appendedHistory, -MAYA_HISTORY_DB_CAP);
    }

    maya_save_session($pdo, $sessionId, [
        'stage' => $newStage,
        'scores_json' => json_encode($finalScores),
        'readiness_json' => json_encode($finalReadiness),
        'flags_json' => json_encode($finalFlags),
        'history_json' => json_encode($appendedHistory),
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
