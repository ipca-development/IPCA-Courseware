<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/courseware_progression_v2.php';
require_once __DIR__ . '/progress_test_access.php';
require_once __DIR__ . '/progress_test_bank.php';

function ptv4_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ptv4_user_photo_url(?string $photoPath): string
{
    $value = trim((string)$photoPath);
    if ($value === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $value)) {
        return $value;
    }

    if (strpos($value, '/') === 0) {
        return $value;
    }

    return '/' . ltrim($value, '/');
}

function ptv4_require_progress_test_access(PDO $pdo, array $user, int $cohortId, int $studentUserId, int $lessonId = 0): void
{
    if ((string)($user['role'] ?? '') === 'admin') return;
    if (cw_progress_test_v4_access_allowed($pdo, $studentUserId, $cohortId, $lessonId)) {
        return;
    }
    ptv4_json(['ok' => false, 'error' => 'Progress test access code required.', 'access_required' => true], 403);
}

function ptv4_body(): array
{
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function ptv4_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ptv4_ensure_tables(PDO $pdo): void
{
    $sqlFile = __DIR__ . '/../scripts/sql/2026_05_19_progress_test_v3_oral.sql';
    if (is_file($sqlFile)) {
        $sql = (string)file_get_contents($sqlFile);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                try { $pdo->exec($stmt); } catch (Throwable $e) {}
            }
        }
    }
    $sqlV4 = __DIR__ . '/../scripts/sql/2026_05_22_progress_test_v4_oral.sql';
    if (is_file($sqlV4)) {
        $sql = (string)file_get_contents($sqlV4);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                try { $pdo->exec($stmt); } catch (Throwable $e) {}
            }
        }
    }
    $sqlImprove = __DIR__ . '/../scripts/sql/2026_05_22_progress_test_v4_improvements.sql';
    if (is_file($sqlImprove)) {
        $sql = (string)file_get_contents($sqlImprove);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                try { $pdo->exec($stmt); } catch (Throwable $e) {}
            }
        }
    }
}

function ptv4_normalize_text(string $text): string
{
    $text = strtolower(trim($text));
    $text = (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $text = (string)preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function ptv4_yesno_lead_text(string $answer): string
{
    $answer = trim($answer);
    if ($answer === '') return '';
    if (preg_match('/^([^.!?]+[.!?])/', $answer, $m)) {
        return trim((string)$m[1]);
    }
    $words = preg_split('/\s+/', $answer, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words) || !$words) return $answer;
    return implode(' ', array_slice($words, 0, 15));
}

function ptv4_parse_yesno_direction(string $answer): ?bool
{
    $t = ptv4_normalize_text(ptv4_yesno_lead_text($answer));
    if ($t === '') return null;
    if (preg_match('/^(no|nope|false)\b/u', $t)) return false;
    if (preg_match('/^(yes|yeah|yep|true)\b/u', $t)) return true;
    $yesPos = null;
    $noPos = null;
    if (preg_match('/\b(yes|yeah|yep|true)\b/u', $t, $m, PREG_OFFSET_CAPTURE)) $yesPos = (int)$m[0][1];
    if (preg_match('/\b(no|nope|false)\b/u', $t, $m, PREG_OFFSET_CAPTURE)) $noPos = (int)$m[0][1];
    if ($yesPos !== null && ($noPos === null || $yesPos <= $noPos)) return true;
    if ($noPos !== null && ($yesPos === null || $noPos < $yesPos)) return false;
    return null;
}

function ptv4_phrase_match(string $text, string $phrase): bool
{
    $t = ptv4_normalize_text($text);
    $p = ptv4_normalize_text($phrase);
    if ($t === '' || $p === '') return false;
    if (strpos($t, $p) !== false) return true;
    $hits = 0;
    $need = 0;
    foreach (explode(' ', $p) as $w) {
        if (strlen($w) < 3) continue;
        $need++;
        foreach (explode(' ', $t) as $tw) {
            if ($tw === $w || (strlen($w) >= 4 && strlen($tw) >= 4 && (strpos($tw, $w) === 0 || strpos($w, $tw) === 0))) {
                $hits++;
                break;
            }
        }
    }
    return $need > 0 && $hits >= max(1, $need - 1);
}

function ptv4_student_feedback_text(string $feedback): string
{
    $feedback = trim($feedback);
    $feedback = (string)preg_replace('/\bthe\s+rubric\s+(requires|expects|says|asks for)\b/i', 'the expected answer $1', $feedback);
    $feedback = (string)preg_replace('/\brubric\b/i', 'expected answer', $feedback);
    return trim($feedback);
}

function ptv4_ai_prompt(PDO $pdo, string $key, string $fallback): string
{
    try {
        $st = $pdo->prepare("SELECT prompt_text FROM ai_prompts WHERE prompt_key = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$key]);
        $txt = trim((string)$st->fetchColumn());
        if ($txt !== '') return $txt;
    } catch (Throwable $e) {}
    return $fallback;
}

function ptv4_question_count(PDO $pdo): int
{
    try {
        $st = $pdo->prepare("
            SELECT value_text FROM system_policy_values
            WHERE policy_key = 'progress_test_question_count' AND is_active = 1
              AND scope_type = 'global' AND scope_id IS NULL
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute();
        $value = (int)trim((string)$st->fetchColumn());
        if ($value >= 1 && $value <= 20) return $value;
    } catch (Throwable $e) {}
    return 5;
}

function ptv4_truth_text(PDO $pdo, int $lessonId): string
{
    $nq = $pdo->prepare("
        SELECT s.page_number, e.narration_en
        FROM slides s
        JOIN slide_enrichment e ON e.slide_id = s.id
        WHERE s.lesson_id = ? AND s.is_deleted = 0
          AND e.narration_en IS NOT NULL AND e.narration_en <> ''
        ORDER BY s.page_number ASC
    ");
    $nq->execute([$lessonId]);
    $truth = '';
    foreach ($nq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $truth .= 'Slide ' . (int)$row['page_number'] . ': ' . trim((string)$row['narration_en']) . "\n\n";
    }
    return trim($truth);
}

function ptv4_spoken_question(array $item, int $total): string
{
    require_once __DIR__ . '/progress_test_prep.php';
    return pt_prep_spoken_question($item, $total);
}

function ptv4_expected_concepts(array $item): array
{
    $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
    $kind = (string)($item['kind'] ?? 'open');
    if ($kind === 'yesno') {
        return [(bool)($correct['value'] ?? false) ? 'Answer: yes' : 'Answer: no'];
    }
    if ($kind === 'mcq') {
        $out = [];
        $answerText = trim((string)($correct['answer_text'] ?? ''));
        if ($answerText !== '') $out[] = $answerText;
        foreach ((array)($correct['alternatives'] ?? []) as $alt) {
            $alt = trim((string)$alt);
            if ($alt !== '') $out[] = $alt;
        }
        return $out ?: ['Correct choice phrase'];
    }
    $points = (array)($correct['key_points'] ?? []);
    return array_values(array_filter(array_map('strval', $points)));
}

function ptv4_load_attempt(PDO $pdo, array $u, int $attemptId): array
{
    $role = (string)($u['role'] ?? '');
    if ($role === 'student') {
        $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$attemptId, (int)$u['id']]);
    } else {
        $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? LIMIT 1");
        $st->execute([$attemptId]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Progress test attempt not found.');
    return $row;
}

function ptv4_load_items(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare("SELECT * FROM progress_test_items_v2 WHERE test_id = ? ORDER BY idx ASC");
    $st->execute([$attemptId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ptv4_load_responses(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare("SELECT * FROM progress_test_oral_item_responses WHERE attempt_id = ? ORDER BY item_id ASC");
    $st->execute([$attemptId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['item_id']] = $row;
    }
    return $out;
}

function ptv4_load_card_sessions(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare("SELECT * FROM progress_test_v4_card_sessions WHERE attempt_id = ?");
    $st->execute([$attemptId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['item_id']] = $row;
    }
    return $out;
}

function ptv4_manifest(array $attempt): array
{
    $raw = trim((string)($attempt['manifest_json'] ?? ''));
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function ptv4_mark_stale_interrupted_attempts(PDO $pdo, int $userId, int $cohortId, int $lessonId): void
{
    $st = $pdo->prepare("
        UPDATE progress_tests_v2
        SET status = 'failed', formal_result_code = 'STALE_ABORTED',
            formal_result_label = 'Aborted (stale technical session)',
            counts_as_unsat = 0, pass_gate_met = 0, timing_status = 'unknown',
            status_text = 'Oral progress test expired after the 15-minute resume window.',
            updated_at = NOW()
        WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
          AND status IN ('preparing','ready','in_progress','processing')
          AND updated_at < (NOW() - INTERVAL 15 MINUTE)
          AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
}

function ptv4_log_event(PDO $pdo, int $attemptId, ?int $itemId, int $userId, string $role, string $type, string $text): void
{
    try {
        $st = $pdo->prepare("
            INSERT INTO progress_test_voice_events
              (attempt_id, item_id, user_id, role, event_type, transcript_text, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([$attemptId, $itemId ?: null, $userId, $role, $type, $text]);
    } catch (Throwable $e) {}
}

function ptv4_save_card_state(PDO $pdo, int $attemptId, int $itemId, int $userId, string $state, ?string $liveTranscript = null, ?bool $clarificationUsed = null): void
{
    $allowed = ['ready', 'asking', 'listening', 'evaluating', 'clarification', 'complete'];
    if (!in_array($state, $allowed, true)) $state = 'ready';
    $st = $pdo->prepare("
        INSERT INTO progress_test_v4_card_sessions
          (attempt_id, item_id, user_id, card_state, live_transcript, clarification_used, answer_started_at, updated_at, created_at)
        VALUES (?, ?, ?, ?, ?, 0, CASE WHEN ? = 'listening' THEN NOW() ELSE NULL END, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          card_state = VALUES(card_state),
          live_transcript = COALESCE(?, live_transcript),
          clarification_used = COALESCE(?, clarification_used),
          answer_started_at = CASE WHEN ? = 'listening' AND answer_started_at IS NULL THEN NOW() ELSE answer_started_at END,
          updated_at = NOW()
    ");
    $clarVal = $clarificationUsed === null ? null : ($clarificationUsed ? 1 : 0);
    $st->execute([$attemptId, $itemId, $userId, $state, $liveTranscript, $state, $liveTranscript, $clarVal, $state]);
}

function ptv4_default_clarification_text(): string
{
    return 'I may not have heard that correctly. Please answer again in English.';
}

function ptv4_word_count(string $text): int
{
    $norm = ptv4_normalize_text($text);
    if ($norm === '') return 0;
    return count(array_filter(explode(' ', $norm)));
}

function ptv4_transcript_looks_corrupted(string $answer): bool
{
    if (trim($answer) === '') return true;
    if (preg_match('/\b(inaudible|unintelligible|static|background noise|\[timeout)\b/i', $answer)) return true;
    if (preg_match('/^[\[\(]/', trim($answer))) return true;
    return false;
}

function ptv4_clarification_eligible(string $answer, array $eval): bool
{
    if (($eval['result'] ?? '') === 'clarify') return true;
    if (ptv4_transcript_looks_corrupted($answer)) return true;
    if (ptv4_word_count($answer) <= 3 && (int)($eval['score_pct'] ?? 0) < 70) return true;
    if (($eval['result'] ?? '') === 'partial') {
        $q = trim((string)($eval['clarification_question'] ?? ''));
        if ($q !== '') return true;
        if (ptv4_word_count($answer) <= 5) return true;
    }
    return false;
}

function ptv4_is_english_retry_eval(array $eval): bool
{
    return in_array('English-only answer required', $eval['missing_concepts'] ?? [], true)
        || stripos((string)($eval['feedback_for_student'] ?? ''), 'again in English') !== false;
}

function ptv4_is_likely_non_english(string $text): bool
{
    $t = trim($text);
    if ($t === '') return false;
    if (preg_match('/[\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{4E00}-\x{9FFF}]/u', $t)) return true;
    $norm = ptv4_normalize_text($t);
    $dutch = ['ik ', ' je ', ' het ', ' de ', ' een ', ' niet ', ' wel ', ' antwoord ', ' vraag '];
    foreach ($dutch as $w) {
        if (strpos(' ' . $norm . ' ', $w) !== false) return true;
    }
    $spanish = [' el ', ' la ', ' los ', ' las ', ' que ', ' porque ', ' respuesta '];
    foreach ($spanish as $w) {
        if (strpos(' ' . $norm . ' ', $w) !== false) return true;
    }
    return false;
}

function ptv4_evaluator_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'score_pct' => ['type' => 'integer'],
            'result' => ['type' => 'string', 'enum' => ['pass', 'partial', 'fail', 'clarify']],
            'missing_concepts' => ['type' => 'array', 'items' => ['type' => 'string']],
            'clarification_question' => ['type' => 'string'],
            'feedback_for_student' => ['type' => 'string'],
            'safety_critical_issue' => ['type' => 'boolean'],
        ],
        'required' => ['score_pct', 'result', 'missing_concepts', 'clarification_question', 'feedback_for_student', 'safety_critical_issue'],
    ];
}

function ptv4_validate_evaluator_json(array $j): array
{
    $result = (string)($j['result'] ?? 'fail');
    if (!in_array($result, ['pass', 'partial', 'fail', 'clarify'], true)) $result = 'fail';
    $score = max(0, min(100, (int)($j['score_pct'] ?? 0)));
    if ($result === 'pass') $score = max($score, 70);
    if ($result === 'fail') $score = min($score, 29);
    if ($result === 'partial') $score = max(30, min(69, $score));
    return [
        'score_pct' => $score,
        'result' => $result,
        'missing_concepts' => array_values(array_filter(array_map('strval', (array)($j['missing_concepts'] ?? [])))),
        'clarification_question' => trim((string)($j['clarification_question'] ?? '')),
        'feedback_for_student' => ptv4_student_feedback_text(trim((string)($j['feedback_for_student'] ?? ''))),
        'safety_critical_issue' => !empty($j['safety_critical_issue']),
    ];
}

function ptv4_grade_item(PDO $pdo, array $item, string $answer, string $clarificationAnswer = ''): array
{
    if (ptv4_is_likely_non_english($answer) || ($clarificationAnswer !== '' && ptv4_is_likely_non_english($clarificationAnswer))) {
        return ptv4_validate_evaluator_json([
            'score_pct' => 0,
            'result' => 'fail',
            'missing_concepts' => ['English-only answer required'],
            'clarification_question' => '',
            'feedback_for_student' => 'Please answer this question again in English.',
            'safety_critical_issue' => false,
        ]);
    }

    $kind = (string)$item['kind'];
    $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
    $options = json_decode((string)($item['options_json'] ?? '[]'), true) ?: [];
    $evaluatedAnswer = trim($answer . ($clarificationAnswer !== '' ? "\n\nClarification: " . $clarificationAnswer : ''));

    if (trim($evaluatedAnswer) === '') {
        return ptv4_validate_evaluator_json([
            'score_pct' => 0, 'result' => 'fail', 'missing_concepts' => ['No answer captured'],
            'clarification_question' => '', 'feedback_for_student' => 'No answer was captured.', 'safety_critical_issue' => false,
        ]);
    }

    if ($kind === 'yesno') {
        $student = ptv4_parse_yesno_direction($evaluatedAnswer);
        $ok = ($student !== null && $student === (bool)($correct['value'] ?? false));
        return ptv4_validate_evaluator_json([
            'score_pct' => $ok ? 100 : 0,
            'result' => $ok ? 'pass' : 'fail',
            'missing_concepts' => $ok ? [] : ['Correct yes/no direction'],
            'clarification_question' => '',
            'feedback_for_student' => $ok ? 'Correct. You gave the right yes or no answer.' : 'That is not exactly right. Review the concept and answer yes or no.',
            'safety_critical_issue' => false,
        ]);
    }

    if ($kind === 'mcq') {
        $answerText = trim((string)($correct['answer_text'] ?? ''));
        $phrases = array_values(array_filter(array_merge([$answerText], (array)($correct['alternatives'] ?? []))));
        $ok = false;
        foreach ($phrases as $phrase) {
            if (ptv4_phrase_match($evaluatedAnswer, (string)$phrase)) { $ok = true; break; }
        }
        return ptv4_validate_evaluator_json([
            'score_pct' => $ok ? 100 : 0,
            'result' => $ok ? 'pass' : 'fail',
            'missing_concepts' => $ok ? [] : ($answerText !== '' ? [$answerText] : ['Correct choice']),
            'clarification_question' => '',
            'feedback_for_student' => $ok ? 'Correct. You selected the right concept.' : 'That is not the correct choice. Review the related terminology.',
            'safety_critical_issue' => false,
        ]);
    }

    $keyPoints = array_values(array_filter(array_map('strval', (array)($correct['key_points'] ?? []))));
    $minPts = max(1, (int)($correct['min_points_to_pass'] ?? min(3, count($keyPoints))));
    $system = ptv4_ai_prompt($pdo, 'progress_test_v4_evaluator_system', <<<'TXT'
You are a strict aviation oral examiner evaluator. Return JSON only.
Grade ONLY from the question, expected concepts, and student transcript.
Do not invent facts. Do not tutor. English answers only.
Use result pass (>=70), partial (30-69, may clarify once), fail (<30), or clarify for transcript ambiguity.
TXT);
    $userPrompt = "QUESTION:\n" . (string)$item['prompt']
        . "\n\nEXPECTED CONCEPTS:\n- " . implode("\n- ", $keyPoints)
        . "\n\nMIN CONCEPTS TO PASS: {$minPts}\n\nSTUDENT TRANSCRIPT:\n{$evaluatedAnswer}";

    $j = [];
    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $userPrompt]]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'progress_test_v4_eval', 'schema' => ptv4_evaluator_schema(), 'strict' => true]],
            'temperature' => 0.1,
        ]);
        $j = cw_openai_extract_json_text($resp);
    } catch (Throwable $e) {}

    if (!$j) {
        $detected = [];
        foreach ($keyPoints as $point) {
            if (ptv4_phrase_match($evaluatedAnswer, $point)) $detected[] = $point;
        }
        $score = count($keyPoints) ? (int)round((count($detected) / max(1, count($keyPoints))) * 100) : 0;
        $j = [
            'score_pct' => $score,
            'result' => $score >= 70 ? 'pass' : ($score >= 30 ? 'partial' : 'fail'),
            'missing_concepts' => array_values(array_diff($keyPoints, $detected)),
            'clarification_question' => '',
            'feedback_for_student' => $score >= 70 ? 'Good answer.' : 'Your answer is incomplete.',
            'safety_critical_issue' => false,
        ];
    }
    return ptv4_validate_evaluator_json($j);
}

function ptv4_state_payload(PDO $pdo, array $attempt): array
{
    $attemptId = (int)$attempt['id'];
    $items = ptv4_load_items($pdo, $attemptId);
    $responses = ptv4_load_responses($pdo, $attemptId);
    $cards = ptv4_load_card_sessions($pdo, $attemptId);
    $manifest = ptv4_manifest($attempt);
    $questionUrls = is_array($manifest['question_urls'] ?? null) ? $manifest['question_urls'] : [];
    $total = count($items);
    $evaluated = 0;
    $scoreSum = 0.0;
    $current = null;
    $itemPayload = [];

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $response = $responses[$itemId] ?? null;
        $card = $cards[$itemId] ?? null;
        $isEvaluated = $response && $response['evaluated_at'] !== null;
        if ($isEvaluated) {
            $evaluated++;
            $scoreSum += (float)($response['score_pct'] ?? 0);
        } elseif ($current === null) {
            $current = $item;
        }
        $cardState = 'complete';
        if (!$isEvaluated) {
            $cardState = (string)($card['card_state'] ?? 'ready');
            if (!in_array($cardState, ['ready', 'asking', 'listening', 'evaluating', 'clarification', 'complete'], true)) {
                $cardState = 'ready';
            }
        }
        $itemPayload[] = [
            'id' => $itemId,
            'idx' => (int)$item['idx'],
            'kind' => (string)$item['kind'],
            'prompt' => (string)$item['prompt'],
            'spoken_question' => ptv4_spoken_question($item, $total),
            'question_audio_url' => (string)($questionUrls[(string)$itemId] ?? $questionUrls[$itemId] ?? ''),
            'evaluated' => $isEvaluated,
            'card_state' => $cardState,
            'live_transcript' => $card ? (string)($card['live_transcript'] ?? '') : '',
            'clarification_used' => $card ? (int)($card['clarification_used'] ?? 0) : 0,
            'score_pct' => $response ? $response['score_pct'] : null,
            'result' => $response ? (string)($response['feedback_text'] ?? '') : '',
            'feedback_text' => $response ? (string)($response['feedback_text'] ?? '') : '',
            'student_answer_text' => $response ? (string)($response['student_answer_text'] ?? '') : '',
            'clarification_question_text' => $response ? (string)($response['clarification_question_text'] ?? '') : '',
            'clarification_answer_text' => $response ? (string)($response['clarification_answer_text'] ?? '') : '',
        ];
    }

    $current = $current ?: ($items[$total - 1] ?? null);
    $scoreProgress = $evaluated > 0 ? round($scoreSum / max(1, $evaluated), 1) : null;
    $status = (string)($attempt['status'] ?? '');
    $prepared = $total > 0 && !empty($manifest['question_urls']);
    $resumeMode = $evaluated > 0 || in_array($status, ['in_progress', 'processing'], true) ? 'resumed' : 'new_or_reset';

    return [
        'attempt_id' => $attemptId,
        'status' => $status,
        'prepared' => $prepared,
        'progress_pct' => (int)($attempt['progress_pct'] ?? 0),
        'status_text' => (string)($attempt['status_text'] ?? ''),
        'attempt_status' => (string)($attempt['formal_result_label'] ?? ($attempt['status_text'] ?? '')),
        'score_pct' => $attempt['score_pct'] !== null ? (int)$attempt['score_pct'] : null,
        'pass_gate_met' => isset($attempt['pass_gate_met']) ? (int)$attempt['pass_gate_met'] : null,
        'formal_result_label' => (string)($attempt['formal_result_label'] ?? ''),
        'total_questions' => $total,
        'ready_questions' => $prepared ? $total : 0,
        'evaluated_count' => $evaluated,
        'score_progress' => $scoreProgress,
        'current_item_id' => $current ? (int)$current['id'] : 0,
        'current_idx' => $current ? (int)$current['idx'] : 0,
        'resume_mode' => $resumeMode,
        'intro_audio_url' => (string)($manifest['intro_url'] ?? ''),
        'items' => $itemPayload,
    ];
}

function ptv4_answer_chunk_dir(int $attemptId, int $itemId): string
{
    $base = getenv('CW_PROGRESS_TEST_V4_AUDIO_DIR') ?: (sys_get_temp_dir() . '/progress_tests_v4');
    $dir = rtrim($base, '/') . '/' . $attemptId . '/' . $itemId;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create answer audio directory.');
    }
    return $dir;
}

function ptv4_ensure_v4_chunk_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS progress_test_v4_answer_chunks (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              attempt_id BIGINT UNSIGNED NOT NULL,
              item_id BIGINT UNSIGNED NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              chunk_index INT UNSIGNED NOT NULL,
              storage_path VARCHAR(512) NOT NULL,
              transcript_text TEXT NULL,
              created_at DATETIME NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_ptv4_chunk (attempt_id, item_id, chunk_index),
              KEY idx_ptv4_chunks_item (attempt_id, item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function ptv4_merge_chunk_audio_files(PDO $pdo, int $attemptId, int $itemId): ?string
{
    $st = $pdo->prepare("
        SELECT storage_path FROM progress_test_v4_answer_chunks
        WHERE attempt_id = ? AND item_id = ?
        ORDER BY chunk_index ASC
    ");
    $st->execute([$attemptId, $itemId]);
    $paths = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    if (!$paths) return null;

    $dir = ptv4_answer_chunk_dir($attemptId, $itemId);
    $mergedPath = $dir . '/answer_merged.webm';
    $out = @fopen($mergedPath, 'wb');
    if (!$out) return null;

    $bytes = 0;
    foreach ($paths as $path) {
        if (!is_file($path) || filesize($path) <= 0) continue;
        $in = @fopen($path, 'rb');
        if (!$in) continue;
        stream_copy_to_stream($in, $out);
        $bytes += (int)filesize($path);
        fclose($in);
    }
    fclose($out);

    if ($bytes <= 0 || !is_file($mergedPath) || filesize($mergedPath) <= 0) {
        @unlink($mergedPath);
        return null;
    }

    return $mergedPath;
}

function ptv4_spaces_presign_answer(int $testId, int $idx, string $cookieHeader = ''): array
{
    $host = getenv('CW_APP_BASE_URL') ?: 'https://ipca.training';
    $url = rtrim($host, '/') . '/student/api/progress_test_spaces_presign.php';
    $payload = json_encode([
        'test_id' => $testId,
        'kind' => 'answer',
        'idx' => $idx,
        'ext' => 'webm',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $headers = ['Content-Type: application/json'];
    if ($cookieHeader !== '') $headers[] = 'Cookie: ' . $cookieHeader;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$resp, true);
    if (!is_array($json) || $code < 200 || $code >= 300 || empty($json['ok'])) {
        $msg = is_array($json) ? (string)($json['error'] ?? ('HTTP ' . $code)) : substr((string)$resp, 0, 200);
        throw new RuntimeException('Answer audio presign failed: ' . $msg);
    }
    return $json;
}

function ptv4_upload_file_to_presigned_put(string $putUrl, string $localPath, string $contentType = 'audio/webm'): void
{
    if (!is_file($localPath)) {
        throw new RuntimeException('Merged answer audio file not found.');
    }
    $fh = fopen($localPath, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot open merged answer audio for upload.');
    }
    $size = filesize($localPath);
    $ch = curl_init($putUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $size,
            'x-amz-acl: public-read',
        ],
        CURLOPT_TIMEOUT => 300,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($resp === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('Answer audio upload to storage failed (HTTP ' . $code . ').');
    }
}

function ptv4_persist_item_answer_audio(PDO $pdo, int $attemptId, int $itemId, string $cookieHeader = ''): ?string
{
    $itemSt = $pdo->prepare("SELECT id, idx FROM progress_test_items_v2 WHERE id = ? AND test_id = ? LIMIT 1");
    $itemSt->execute([$itemId, $attemptId]);
    $item = $itemSt->fetch(PDO::FETCH_ASSOC);
    if (!$item) return null;

    $mergedPath = ptv4_merge_chunk_audio_files($pdo, $attemptId, $itemId);
    if ($mergedPath === null) return null;

    try {
        $presign = ptv4_spaces_presign_answer($attemptId, (int)$item['idx'], $cookieHeader);
        ptv4_upload_file_to_presigned_put((string)$presign['url'], $mergedPath, 'audio/webm');
        $spacesKey = (string)$presign['key'];
        $pdo->prepare("
            UPDATE progress_test_items_v2
            SET audio_path = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$spacesKey, $itemId]);
        return $spacesKey;
    } catch (Throwable $e) {
        error_log('ptv4_persist_item_answer_audio failed: ' . $e->getMessage());
        return null;
    }
}

function ptv4_transcribe_audio_file(string $path): string
{
    if (!is_file($path) || filesize($path) <= 0) return '';
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    $post = [
        'file' => new CURLFile($path, 'audio/webm', basename($path)),
        'model' => 'whisper-1',
        'language' => 'en',
        'response_format' => 'json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cw_openai_key()],
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return '';
    $j = json_decode((string)$resp, true);
    return trim((string)($j['text'] ?? ''));
}

function ptv4_synthesize_speech_mp3(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';

    $model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $voice = getenv('CW_OPENAI_TTS_VOICE') ?: (getenv('CW_OPENAI_REALTIME_VOICE') ?: 'marin');
    $payload = json_encode([
        'model' => $model,
        'voice' => $voice,
        'format' => 'mp3',
        'input' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . cw_openai_key(),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ]);
    $audio = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        return '';
    }
    return (string)$audio;
}

function ptv4_merge_chunk_transcripts(PDO $pdo, int $attemptId, int $itemId): string
{
    $st = $pdo->prepare("
        SELECT transcript_text FROM progress_test_v4_answer_chunks
        WHERE attempt_id = ? AND item_id = ? AND transcript_text IS NOT NULL AND TRIM(transcript_text) <> ''
        ORDER BY chunk_index ASC
    ");
    $st->execute([$attemptId, $itemId]);
    $parts = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $txt) {
        $txt = trim((string)$txt);
        if ($txt !== '') $parts[] = $txt;
    }
    return ptv4_dedupe_transcript_parts($parts);
}

function ptv4_dedupe_transcript_parts(array $parts): string
{
    $out = '';
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') continue;
        if ($out === '') {
            $out = $part;
            continue;
        }
        $wordsOut = preg_split('/\s+/', $out, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordsPart = preg_split('/\s+/', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $maxOverlap = min(count($wordsOut), count($wordsPart), 8);
        $overlap = 0;
        for ($i = $maxOverlap; $i >= 1; $i--) {
            $suffix = implode(' ', array_slice($wordsOut, -$i));
            $prefix = implode(' ', array_slice($wordsPart, 0, $i));
            if (strcasecmp($suffix, $prefix) === 0) {
                $overlap = $i;
                break;
            }
        }
        if ($overlap > 0) {
            $out .= ' ' . implode(' ', array_slice($wordsPart, $overlap));
        } else {
            $out .= ' ' . $part;
        }
    }
    return trim($out);
}

function ptv4_clean_final_transcript(string $text): string
{
    $text = trim((string)preg_replace('/\s+/', ' ', $text));
    return trim($text);
}

function ptv4_ensure_chunk_transcripts(PDO $pdo, int $attemptId, int $itemId): void
{
    $st = $pdo->prepare("
        SELECT chunk_index, storage_path, transcript_text
        FROM progress_test_v4_answer_chunks
        WHERE attempt_id = ? AND item_id = ?
        ORDER BY chunk_index ASC
    ");
    $st->execute([$attemptId, $itemId]);
    $up = $pdo->prepare("
        UPDATE progress_test_v4_answer_chunks
        SET transcript_text = ?
        WHERE attempt_id = ? AND item_id = ? AND chunk_index = ?
    ");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $path = (string)($row['storage_path'] ?? '');
        if (!is_file($path) || filesize($path) <= 0) continue;
        if (trim((string)($row['transcript_text'] ?? '')) !== '') continue;
        $transcript = ptv4_transcribe_audio_file($path);
        if ($transcript !== '') {
            $up->execute([$transcript, $attemptId, $itemId, (int)$row['chunk_index']]);
        }
    }
}

function ptv4_answer_chunk_count(PDO $pdo, int $attemptId, int $itemId): int
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM progress_test_v4_answer_chunks
            WHERE attempt_id = ? AND item_id = ?
        ");
        $st->execute([$attemptId, $itemId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ptv4_answer_has_audio(PDO $pdo, int $attemptId, int $itemId): bool
{
    return ptv4_answer_chunk_count($pdo, $attemptId, $itemId) > 0;
}

function ptv4_answer_has_usable_audio(PDO $pdo, int $attemptId, int $itemId, int $recordingMs = 0): bool
{
    if ($recordingMs > 0 && $recordingMs < 400) return false;
    try {
        $st = $pdo->prepare("
            SELECT storage_path FROM progress_test_v4_answer_chunks
            WHERE attempt_id = ? AND item_id = ?
            ORDER BY chunk_index ASC
        ");
        $st->execute([$attemptId, $itemId]);
        $totalBytes = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (is_file((string)$path)) $totalBytes += (int)filesize((string)$path);
        }
        return $totalBytes >= 800;
    } catch (Throwable $e) {
        return ptv4_answer_has_audio($pdo, $attemptId, $itemId);
    }
}

function ptv4_touch_answer_chunk_count(PDO $pdo, int $attemptId, int $itemId): void
{
    try {
        $count = ptv4_answer_chunk_count($pdo, $attemptId, $itemId);
        $pdo->prepare("
            UPDATE progress_test_v4_card_sessions
            SET answer_chunk_count = ?, updated_at = NOW()
            WHERE attempt_id = ? AND item_id = ?
        ")->execute([$count, $attemptId, $itemId]);
    } catch (Throwable $e) {
    }
}

function ptv4_finalize_transcript(PDO $pdo, int $attemptId, int $itemId, int $recordingMs = 0, string $cookieHeader = ''): array
{
    $chunkCount = ptv4_answer_chunk_count($pdo, $attemptId, $itemId);
    $audioReceived = $chunkCount > 0;
    $audioPath = null;

    if ($audioReceived) {
        $audioPath = ptv4_persist_item_answer_audio($pdo, $attemptId, $itemId, $cookieHeader);
        ptv4_ensure_chunk_transcripts($pdo, $attemptId, $itemId);
    }

    $mergedPath = $audioReceived ? ptv4_merge_chunk_audio_files($pdo, $attemptId, $itemId) : null;
    $mergedTranscript = $mergedPath ? ptv4_transcribe_audio_file($mergedPath) : '';
    $chunkMerged = $audioReceived ? ptv4_merge_chunk_transcripts($pdo, $attemptId, $itemId) : '';
    $final = ptv4_clean_final_transcript($mergedTranscript !== '' ? $mergedTranscript : $chunkMerged);
    $hasAudio = ptv4_answer_has_usable_audio($pdo, $attemptId, $itemId, $recordingMs);
    $usable = $final !== '' && !ptv4_transcript_looks_corrupted($final);

    return [
        'transcript_final' => $final,
        'has_audio' => $hasAudio,
        'audio_received' => $audioReceived,
        'chunk_count' => $chunkCount,
        'audio_path' => $audioPath,
        'transcript_usable' => $usable,
        'transcription_failed' => $hasAudio && !$usable,
        'upload_failed' => $audioReceived && $audioPath === null,
    ];
}

function ptv4_fire_background_prepare(int $testId, string $cookieHeader = ''): void
{
    require_once __DIR__ . '/progress_test_prep.php';
    pt_prep_fire_background_run($testId, $cookieHeader);
}

function ptv4_attempt_has_evaluated_responses(PDO $pdo, int $attemptId): bool
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM progress_test_oral_item_responses
            WHERE attempt_id = ? AND evaluated_at IS NOT NULL
        ");
        $st->execute([$attemptId]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Roll an oral session back to prepared/ready when no evaluated answers exist yet.
 */
function ptv4_rollback_prepared_session(PDO $pdo, array $attempt, string $reason = 'client_rollback'): array
{
    $attemptId = (int)$attempt['id'];
    $status = (string)($attempt['status'] ?? '');
    if (in_array($status, ['completed', 'failed'], true)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'terminal_status'];
    }
    if (ptv4_attempt_has_evaluated_responses($pdo, $attemptId)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'has_evaluated_responses'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM progress_test_oral_item_responses WHERE attempt_id = ?')->execute([$attemptId]);
        $pdo->prepare('DELETE FROM progress_test_v4_card_sessions WHERE attempt_id = ?')->execute([$attemptId]);
        $pdo->prepare('DELETE FROM progress_test_v4_answer_chunks WHERE attempt_id = ?')->execute([$attemptId]);
        $pdo->prepare("
            UPDATE progress_test_items_v2
            SET transcript_text = NULL, is_correct = NULL, score_points = NULL, max_points = NULL, updated_at = NOW()
            WHERE test_id = ?
        ")->execute([$attemptId]);
        $pdo->prepare("
            UPDATE progress_tests_v2
            SET status = 'ready',
                score_pct = NULL,
                progress_pct = 100,
                pass_gate_met = 0,
                counts_as_unsat = 0,
                formal_result_code = NULL,
                formal_result_label = NULL,
                status_text = 'Progress test ready (session rolled back).',
                started_at = NULL,
                completed_at = NULL,
                timing_status = 'unknown',
                updated_at = NOW()
            WHERE id = ?
              AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);
        ptv4_log_event($pdo, $attemptId, null, (int)$attempt['user_id'], 'system', 'oral_session_rolled_back', $reason);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'rolled_back' => true, 'reason' => $reason];
}

function ptv4_ensure_prepared_attempt(PDO $pdo, array $u, int $cohortId, int $lessonId, string $cookieHeader = ''): array
{
    require_once __DIR__ . '/progress_test_prep.php';
    ptv4_mark_stale_interrupted_attempts($pdo, (int)$u['id'], $cohortId, $lessonId);
    $studentUserId = (int)$u['id'];

    if (pt_prep_has_canonical_pass($pdo, $studentUserId, $cohortId, $lessonId)) {
        return ['ok' => false, 'blocked' => true, 'reason' => 'canonical_pass_already_recorded'];
    }

    $attempt = pt_prep_get_open_attempt($pdo, $studentUserId, $cohortId, $lessonId);
    if (!$attempt) {
        return ['ok' => false, 'blocked' => true, 'reason' => 'not_prepared'];
    }

    $attemptId = (int)$attempt['id'];

    $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
    $prepared = pt_prep_attempt_is_prepared($attempt, $pdo);

    if (!$prepared) {
        pt_prep_schedule_progress_test(
            $pdo,
            $studentUserId,
            $cohortId,
            $lessonId,
            'v4_ensure_prepared',
            $cookieHeader,
            'student',
            $studentUserId
        );
        $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
        return ['ok' => true, 'preparing' => true, 'attempt_id' => $attemptId, 'state' => ptv4_state_payload($pdo, $attempt)];
    }

    if ((string)$attempt['status'] === 'preparing') {
        $pdo->prepare("UPDATE progress_tests_v2 SET status='ready', progress_pct=100, status_text='Progress test ready.', updated_at=NOW() WHERE id=?")->execute([$attemptId]);
        $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
    }

    return ['ok' => true, 'preparing' => false, 'attempt_id' => $attemptId, 'state' => ptv4_state_payload($pdo, $attempt)];
}

function ptv4_store_evaluation(PDO $pdo, array $attempt, array $item, array $eval, string $answer, string $spoken, string $clarificationQ = '', string $clarificationA = '', bool $finalized = true): void
{
    $attemptId = (int)$attempt['id'];
    $itemId = (int)$item['id'];
    $userId = (int)$attempt['user_id'];
    $scorePct = (float)$eval['score_pct'];
    $isCorrect = in_array($eval['result'], ['pass'], true) ? 1 : 0;
    $detected = array_values(array_diff(ptv4_expected_concepts($item), $eval['missing_concepts']));
    $feedback = (string)$eval['feedback_for_student'];

    $up = $pdo->prepare("
        INSERT INTO progress_test_oral_item_responses
            (attempt_id, item_id, user_id, question_text, maya_spoken_question_text, student_answer_text,
             clarification_question_text, clarification_answer_text, evaluated_answer_text, score_pct, is_correct,
             feedback_text, detected_concepts_json, missing_concepts_json, weak_areas_json, answered_at, evaluated_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), CASE WHEN ? THEN NOW() ELSE NULL END, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            student_answer_text = VALUES(student_answer_text),
            clarification_question_text = VALUES(clarification_question_text),
            clarification_answer_text = VALUES(clarification_answer_text),
            evaluated_answer_text = VALUES(evaluated_answer_text),
            score_pct = VALUES(score_pct),
            is_correct = VALUES(is_correct),
            feedback_text = VALUES(feedback_text),
            detected_concepts_json = VALUES(detected_concepts_json),
            missing_concepts_json = VALUES(missing_concepts_json),
            weak_areas_json = VALUES(weak_areas_json),
            answered_at = VALUES(answered_at),
            evaluated_at = CASE WHEN ? THEN NOW() ELSE evaluated_at END,
            updated_at = NOW()
    ");
    $evaluatedAnswer = trim($answer . ($clarificationA !== '' ? "\n\nClarification: " . $clarificationA : ''));
    $up->execute([
        $attemptId, $itemId, $userId, (string)$item['prompt'], $spoken, $answer,
        $clarificationQ !== '' ? $clarificationQ : null,
        $clarificationA !== '' ? $clarificationA : null,
        $evaluatedAnswer, $scorePct, $isCorrect, $feedback,
        json_encode($detected, JSON_UNESCAPED_UNICODE),
        json_encode($eval['missing_concepts'], JSON_UNESCAPED_UNICODE),
        json_encode($eval['missing_concepts'], JSON_UNESCAPED_UNICODE),
        $finalized ? 1 : 0,
        $finalized ? 1 : 0,
    ]);

    if ($finalized) {
        $pdo->prepare("
            UPDATE progress_test_items_v2
            SET transcript_text = ?, is_correct = ?, score_points = ?, max_points = 100, updated_at = NOW()
            WHERE id = ?
        ")->execute([$evaluatedAnswer, $isCorrect, (int)round($scorePct), $itemId]);
        ptv4_save_card_state($pdo, $attemptId, $itemId, $userId, 'complete', $evaluatedAnswer);

        $bankQuestionId = (int)($item['bank_question_id'] ?? 0);
        if ($bankQuestionId > 0) {
            pt_bank_record_first_attempt($pdo, $bankQuestionId, $userId, $attemptId, $scorePct);
        }
    }
}

function ptv4_evaluation_response(PDO $pdo, array $u, array $attempt, array $item, array $eval, string $answer, bool $clarificationUsed): array
{
    $attemptId = (int)$attempt['id'];
    $itemId = (int)$item['id'];
    $total = count(ptv4_load_items($pdo, $attemptId));
    $spoken = ptv4_spoken_question($item, $total);
    $nextAction = 'next_question';

    if (!$clarificationUsed && ptv4_is_english_retry_eval($eval)) {
        return [
            'ok' => true,
            'item_id' => $itemId,
            'evaluation' => $eval,
            'score_pct' => 0,
            'result' => 'fail',
            'feedback_for_student' => 'Please answer this question again in English.',
            'missing_concepts' => ['English-only answer required'],
            'clarification_allowed' => false,
            'next_action' => 'retry_english',
            'is_complete' => false,
            'state' => ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId)),
        ];
    }

    if (!$clarificationUsed && ptv4_clarification_eligible($answer, $eval)) {
        $clarificationText = trim((string)($eval['clarification_question'] ?? ''));
        if ($clarificationText === '') {
            $clarificationText = ptv4_default_clarification_text();
        }
        ptv4_store_evaluation($pdo, $attempt, $item, $eval, $answer, $spoken, $clarificationText, '', false);
        ptv4_save_card_state($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'clarification', $answer, true);
        ptv4_log_event($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'maya', 'clarification_question', $clarificationText);
        return [
            'ok' => true,
            'item_id' => $itemId,
            'evaluation' => $eval,
            'clarification_allowed' => true,
            'clarification_question' => $clarificationText,
            'next_action' => 'clarify',
            'is_complete' => false,
            'state' => ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId)),
        ];
    }

    ptv4_store_evaluation($pdo, $attempt, $item, $eval, $answer, $spoken, '', '', true);
    ptv4_log_event($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'student', 'answer_final', $answer);
    ptv4_log_event($pdo, $attemptId, $itemId, (int)$attempt['user_id'], 'maya', 'backend_feedback', (string)$eval['feedback_for_student']);

    $state = ptv4_state_payload($pdo, ptv4_load_attempt($pdo, $u, $attemptId));
    if ((int)$state['evaluated_count'] >= (int)$state['total_questions']) $nextAction = 'complete_test';

    return [
        'ok' => true,
        'item_id' => $itemId,
        'evaluation' => $eval,
        'score_pct' => $eval['score_pct'],
        'result' => $eval['result'],
        'feedback_for_student' => $eval['feedback_for_student'],
        'missing_concepts' => $eval['missing_concepts'],
        'clarification_allowed' => false,
        'next_action' => $nextAction,
        'is_complete' => true,
        'state' => $state,
    ];
}

function ptv4_log_debug_event(PDO $pdo, int $userId, string $type, string $detail = '', ?array $meta = null, ?int $attemptId = null, ?int $itemId = null, ?int $cohortId = null, ?int $lessonId = null): void
{
    try {
        $st = $pdo->prepare("
            INSERT INTO progress_test_v4_debug_events
              (attempt_id, user_id, cohort_id, lesson_id, item_id, event_type, event_detail, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([
            $attemptId ?: null,
            $userId,
            $cohortId ?: null,
            $lessonId ?: null,
            $itemId ?: null,
            $type,
            $detail,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {}
    if ($attemptId) {
        ptv4_log_event($pdo, $attemptId, $itemId, $userId, 'system', $type, mb_substr($detail, 0, 2000));
    }
}

function ptv4_save_user_feedback(PDO $pdo, array $u, array $data): array
{
    $userId = (int)($u['id'] ?? 0);
    $type = trim((string)($data['type'] ?? ''));
    if ($type === '') $type = 'Progress Test AI Modal Maya';
    $rating = $data['rating_json'] ?? $data['ratings'] ?? [];
    if (!is_array($rating)) $rating = [];
    $st = $pdo->prepare("
        INSERT INTO user_feedback (user_id, cohort_id, lesson_id, attempt_id, type, rating_json, free_text, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([
        $userId,
        (int)($data['cohort_id'] ?? 0) ?: null,
        (int)($data['lesson_id'] ?? 0) ?: null,
        (int)($data['attempt_id'] ?? 0) ?: null,
        $type,
        json_encode($rating, JSON_UNESCAPED_UNICODE),
        trim((string)($data['free_text'] ?? '')),
    ]);
    return ['ok' => true, 'feedback_id' => (int)$pdo->lastInsertId()];
}

function ptv4_badge_catalog_defaults(): array
{
    return [
        'ready_for_departure' => [
            'name' => 'Wheels Up',
            'description' => 'First successful pass on your first attempt.',
            'theme' => 'departure',
            'image_path' => '/assets/badges/01_wheels_up.png',
            'sort_order' => 1,
        ],
        'perfect_pattern' => [
            'name' => 'Perfect Pattern',
            'description' => 'Scored 100% on a first attempt for the first time.',
            'theme' => 'pattern',
            'image_path' => '/assets/badges/02_perfect_pattern.png',
            'sort_order' => 2,
        ],
        'ifr_precision_pilot' => [
            'name' => 'Elite Aviator',
            'description' => 'Three consecutive progress tests with 100% on first attempt.',
            'theme' => 'ifr',
            'image_path' => '/assets/badges/03_elite_aviator.png',
            'sort_order' => 3,
        ],
        'captain_consistency' => [
            'name' => 'Platinum Wings',
            'description' => 'Five consecutive progress tests with 100% on first attempt.',
            'theme' => 'captain',
            'image_path' => '/assets/badges/04_platinum_wings.png',
            'sort_order' => 4,
        ],
        'ipca_sky_master' => [
            'name' => 'IPCA Sky Master',
            'description' => 'Ten consecutive progress tests with 100% on first attempt.',
            'theme' => 'master',
            'image_path' => '/assets/badges/05_ipca_skymaster.png',
            'sort_order' => 5,
        ],
        'ai_contributor' => [
            'name' => "Maya's Copilot",
            'description' => 'Shared feedback to help improve Maya and IPCA training.',
            'theme' => 'contributor',
            'image_path' => '/assets/badges/06_mayas_copilot.png',
            'sort_order' => 6,
        ],
    ];
}

function ptv4_badge_catalog(?PDO $pdo = null): array
{
    static $cache = [];

    $cacheKey = $pdo ? 'db' : 'default';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $defaults = ptv4_badge_catalog_defaults();
    if (!$pdo) {
        $cache[$cacheKey] = $defaults;
        return $defaults;
    }

    try {
        $st = $pdo->query("
            SELECT badge_key, name, description, image_path, theme, sort_order
            FROM progress_test_badge_definitions
            WHERE is_active = 1
            ORDER BY sort_order ASC, badge_key ASC
        ");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$rows) {
            $cache[$cacheKey] = $defaults;
            return $defaults;
        }

        $out = [];
        foreach ($rows as $row) {
            $key = (string)($row['badge_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $fallback = $defaults[$key] ?? [];
            $out[$key] = [
                'name' => (string)($row['name'] ?? ($fallback['name'] ?? $key)),
                'description' => (string)($row['description'] ?? ($fallback['description'] ?? '')),
                'theme' => (string)($row['theme'] ?? ($fallback['theme'] ?? 'default')),
                'image_path' => (string)($row['image_path'] ?? ($fallback['image_path'] ?? '')),
                'sort_order' => (int)($row['sort_order'] ?? ($fallback['sort_order'] ?? 0)),
            ];
        }

        foreach ($defaults as $key => $def) {
            if (!isset($out[$key])) {
                $out[$key] = $def;
            }
        }

        uasort($out, static function (array $a, array $b): int {
            return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
        });

        $cache[$cacheKey] = $out;
        return $out;
    } catch (Throwable $e) {
        $cache[$cacheKey] = $defaults;
        return $defaults;
    }
}

function ptv4_load_user_badges(PDO $pdo, int $userId): array
{
    try {
        $st = $pdo->prepare("
            SELECT badge_key, earned_at, attempt_id, lesson_id, cohort_id
            FROM progress_test_user_badges
            WHERE user_id = ?
            ORDER BY earned_at ASC, id ASC
        ");
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['badge_key']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function ptv4_award_badge(PDO $pdo, int $userId, string $badgeKey, array $ctx = []): bool
{
    if (!isset(ptv4_badge_catalog($pdo)[$badgeKey])) {
        return false;
    }
    try {
        $st = $pdo->prepare("
            INSERT INTO progress_test_user_badges
              (user_id, badge_key, attempt_id, lesson_id, cohort_id, earned_at, meta_json)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $st->execute([
            $userId,
            $badgeKey,
            isset($ctx['attempt_id']) ? (int)$ctx['attempt_id'] : null,
            isset($ctx['lesson_id']) ? (int)$ctx['lesson_id'] : null,
            isset($ctx['cohort_id']) ? (int)$ctx['cohort_id'] : null,
            !empty($ctx['meta']) ? json_encode($ctx['meta'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ptv4_count_first_attempt_perfect_streak(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare("
        SELECT attempt, score_pct, status
        FROM progress_tests_v2
        WHERE user_id = ? AND status = 'completed'
        ORDER BY completed_at DESC, id DESC
        LIMIT 24
    ");
    $st->execute([$userId]);
    $streak = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)($row['attempt'] ?? 0) === 1 && (int)($row['score_pct'] ?? 0) === 100) {
            $streak++;
            continue;
        }
        break;
    }
    return $streak;
}

function ptv4_evaluate_and_award_badges(PDO $pdo, array $attempt): array
{
    $userId = (int)$attempt['user_id'];
    $attemptId = (int)$attempt['id'];
    $lessonId = (int)$attempt['lesson_id'];
    $cohortId = (int)$attempt['cohort_id'];
    $attemptNum = max(1, (int)($attempt['attempt'] ?? 1));
    $scorePct = $attempt['score_pct'] !== null ? (int)$attempt['score_pct'] : null;
    $passed = !empty($attempt['pass_gate_met']);
    $earned = ptv4_load_user_badges($pdo, $userId);
    $newlyEarned = [];
    $ctx = ['attempt_id' => $attemptId, 'lesson_id' => $lessonId, 'cohort_id' => $cohortId];

    if ($passed && $attemptNum === 1 && !isset($earned['ready_for_departure'])) {
        if (ptv4_award_badge($pdo, $userId, 'ready_for_departure', $ctx)) {
            $newlyEarned[] = 'ready_for_departure';
            $earned['ready_for_departure'] = ['badge_key' => 'ready_for_departure'];
        }
    }

    if ($scorePct === 100 && $attemptNum === 1 && !isset($earned['perfect_pattern'])) {
        if (ptv4_award_badge($pdo, $userId, 'perfect_pattern', $ctx)) {
            $newlyEarned[] = 'perfect_pattern';
            $earned['perfect_pattern'] = ['badge_key' => 'perfect_pattern'];
        }
    }

    $streak = ptv4_count_first_attempt_perfect_streak($pdo, $userId);
    $streakBadges = [
        3 => 'ifr_precision_pilot',
        5 => 'captain_consistency',
        10 => 'ipca_sky_master',
    ];
    foreach ($streakBadges as $threshold => $badgeKey) {
        if ($streak >= $threshold && !isset($earned[$badgeKey])) {
            if (ptv4_award_badge($pdo, $userId, $badgeKey, array_merge($ctx, ['meta' => ['streak' => $streak]]))) {
                $newlyEarned[] = $badgeKey;
                $earned[$badgeKey] = ['badge_key' => $badgeKey];
            }
        }
    }

    return $newlyEarned;
}

function ptv4_award_feedback_badge(PDO $pdo, int $userId, array $ctx = []): bool
{
    $earned = ptv4_load_user_badges($pdo, $userId);
    if (isset($earned['ai_contributor'])) {
        return false;
    }
    return ptv4_award_badge($pdo, $userId, 'ai_contributor', $ctx);
}

function ptv4_report_badges_payload(PDO $pdo, int $userId, array $newlyEarned = []): array
{
    $catalog = ptv4_badge_catalog($pdo);
    $earned = ptv4_load_user_badges($pdo, $userId);
    $newSet = array_fill_keys($newlyEarned, true);
    $out = [];
    foreach ($catalog as $key => $def) {
        $row = $earned[$key] ?? null;
        $out[] = [
            'badge_key' => $key,
            'name' => $def['name'],
            'description' => $def['description'],
            'theme' => $def['theme'],
            'image_path' => (string)($def['image_path'] ?? ''),
            'earned' => $row !== null,
            'newly_earned' => !empty($newSet[$key]),
            'earned_at' => $row ? (string)($row['earned_at'] ?? '') : null,
        ];
    }
    return $out;
}

function ptv4_question_performance_label(int $scorePct, int $passPct): array
{
    if ($scorePct >= $passPct) {
        return ['label' => 'Excellent Understanding', 'tone' => 'pass'];
    }
    if ($scorePct >= 50) {
        return ['label' => 'Partial Understanding', 'tone' => 'partial'];
    }
    return ['label' => 'Needs Clarification', 'tone' => 'fail'];
}

function ptv4_integrity_student_metrics(PDO $pdo, int $attemptId): array
{
    $stored = ptv4_get_stored_integrity_for_attempt($pdo, $attemptId);
    if (!$stored || empty($stored['analysis'])) {
        return [];
    }
    $a = $stored['analysis'];
    $mapLikelihood = static function ($value): int {
        $v = strtolower(trim((string)$value));
        if ($v === 'high') return 85;
        if ($v === 'medium') return 55;
        if ($v === 'low') return 25;
        return 50;
    };
    $risk = strtolower(trim((string)($a['overall_integrity_risk'] ?? '')));
    $integrityScore = $risk === 'high' ? 35 : ($risk === 'medium' ? 60 : 85);
    return [
        ['key' => 'natural_speech', 'label' => 'Natural Speech', 'score' => 100 - $mapLikelihood($a['natural_speech_likelihood'] ?? ''), 'tone' => 'neutral'],
        ['key' => 'understanding', 'label' => 'Understanding', 'score' => 78, 'tone' => 'neutral'],
        ['key' => 'correlation', 'label' => 'Correlation', 'score' => 80, 'tone' => 'neutral'],
        ['key' => 'confidence', 'label' => 'Confidence', 'score' => 74, 'tone' => 'neutral'],
        ['key' => 'engagement', 'label' => 'Engagement', 'score' => 82, 'tone' => 'neutral'],
        ['key' => 'integrity', 'label' => 'Integrity Score', 'score' => $integrityScore, 'tone' => $integrityScore >= 70 ? 'pass' : 'partial'],
    ];
}

function ptv4_report_payload(PDO $pdo, array $attempt, array $u, array $newlyEarnedBadges = []): array
{
    $attemptId = (int)$attempt['id'];
    $items = ptv4_load_items($pdo, $attemptId);
    $responses = ptv4_load_responses($pdo, $attemptId);
    $nameSt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(name), ''), email, CONCAT('User #', id)) AS name, photo_path FROM users WHERE id = ? LIMIT 1");
    $nameSt->execute([(int)$attempt['user_id']]);
    $userRow = $nameSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $studentName = trim((string)($userRow['name'] ?? ''));
    $firstName = trim(explode(' ', $studentName)[0] ?? 'Student') ?: 'Student';
    $studentPhotoUrl = ptv4_user_photo_url(isset($userRow['photo_path']) ? (string)$userRow['photo_path'] : '');
    $lessonSt = $pdo->prepare("SELECT title FROM lessons WHERE id = ? LIMIT 1");
    $lessonSt->execute([(int)$attempt['lesson_id']]);
    $lessonTitle = trim((string)$lessonSt->fetchColumn()) ?: 'Lesson';
    $questions = [];
    $passPct = 70;
    try {
        $engine = new CoursewareProgressionV2($pdo);
        $passPct = max(1, (int)$engine->getPolicy('progress_test_pass_pct', ['cohort_id' => (int)$attempt['cohort_id']]));
    } catch (Throwable $e) {
        $passPct = 70;
    }
    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $resp = $responses[$itemId] ?? null;
        $qScore = $resp ? (int)round((float)($resp['score_pct'] ?? 0)) : null;
        $perf = $qScore !== null ? ptv4_question_performance_label($qScore, $passPct) : ['label' => 'Not evaluated', 'tone' => 'neutral'];
        $questions[] = [
            'idx' => (int)$item['idx'],
            'question' => (string)$item['prompt'],
            'student_answer' => $resp ? (string)($resp['student_answer_text'] ?? '') : '',
            'score_pct' => $qScore,
            'feedback' => $resp ? (string)($resp['feedback_text'] ?? '') : '',
            'clarification_question' => $resp ? (string)($resp['clarification_question_text'] ?? '') : '',
            'clarification_answer' => $resp ? (string)($resp['clarification_answer_text'] ?? '') : '',
            'performance_label' => $perf['label'],
            'performance_tone' => $perf['tone'],
        ];
    }
    $scorePct = $attempt['score_pct'] !== null ? (int)$attempt['score_pct'] : null;
    $passed = !empty($attempt['pass_gate_met']);
    $weak = trim((string)($attempt['weak_areas'] ?? ''));
    $summary = trim((string)($attempt['ai_summary'] ?? ''));
    $answered = count(array_filter($questions, static fn($q) => trim((string)($q['student_answer'] ?? '')) !== ''));
    $totalQuestions = count($questions);
    $strongQuestions = array_values(array_filter($questions, static fn($q) => ($q['score_pct'] ?? 0) >= $passPct));
    $weakQuestions = array_values(array_filter($questions, static fn($q) => ($q['score_pct'] ?? 0) < $passPct && $q['score_pct'] !== null));

    $motivation = $passed
        ? 'Excellent work ' . $firstName . '. You showed strong understanding and good aeronautical judgment on this oral progress test.'
        : 'Good effort ' . $firstName . '. You completed your oral progress test — review the focus areas below and keep building your spoken explanations.';

    $focusSummary = $passed
        ? 'Your aviation decision-making is progressing well. Keep rehearsing brief spoken explanations and linking concepts to real preflight decisions.'
        : 'Focus your next study block on the concepts you missed, then practice answering each one aloud in complete English sentences.';

    $recommendation = $passed
        ? 'Continue building on your strengths while reviewing ' . ($weak !== '' ? $weak : 'the concepts you missed') . '. Rehearse brief spoken explanations out loud before your next lesson test.'
        : 'Focus your next study session on ' . ($weak !== '' ? $weak : 'the weak areas above') . '. Practice answering each concept aloud in complete English sentences, then retry when you feel ready.';

    $lessonNumber = 1;
    $orderedLessonsSt = $pdo->prepare("
      SELECT l.id
      FROM cohort_lesson_deadlines d
      JOIN lessons l ON l.id = d.lesson_id
      JOIN courses c ON c.id = l.course_id
      WHERE d.cohort_id = ?
      ORDER BY c.sort_order, c.id, d.sort_order, d.id
    ");
    $orderedLessonsSt->execute([(int)$attempt['cohort_id']]);
    foreach ($orderedLessonsSt->fetchAll(PDO::FETCH_COLUMN) as $i => $orderedLessonId) {
        if ((int)$orderedLessonId === (int)$attempt['lesson_id']) {
            $lessonNumber = $i + 1;
            break;
        }
    }

    return [
        'attempt_id' => $attemptId,
        'first_name' => $firstName,
        'photo_path' => $studentPhotoUrl,
        'lesson_title' => $lessonTitle,
        'lesson_number' => $lessonNumber,
        'score_pct' => $scorePct,
        'pass_threshold_pct' => $passPct,
        'passed' => $passed,
        'formal_result_label' => (string)($attempt['formal_result_label'] ?? ''),
        'motivation' => $motivation,
        'summary' => $summary,
        'recommendation' => $recommendation,
        'questions' => $questions,
        'stats' => [
            'lesson_label' => 'Lesson ' . $lessonNumber,
            'questions_answered' => $answered,
            'questions_total' => $totalQuestions,
            'average_score_pct' => $scorePct,
            'result_label' => $passed ? 'PASS' : 'NOT YET PASSED',
        ],
        'focus_areas' => [
            'title' => 'Your Next Focus Areas',
            'summary' => $focusSummary,
            'strengths' => array_slice(array_map(static fn($q) => 'Question ' . $q['idx'] . ': ' . $q['performance_label'], $strongQuestions), 0, 3),
            'weaknesses' => array_slice(array_map(static fn($q) => 'Question ' . $q['idx'] . ': ' . ($q['question'] ?: 'Review this concept'), $weakQuestions), 0, 3),
            'strategy' => $recommendation,
        ],
        'badges' => ptv4_report_badges_payload($pdo, (int)$attempt['user_id'], $newlyEarnedBadges),
        'performance_metrics' => ptv4_integrity_student_metrics($pdo, $attemptId),
    ];
}

function ptv4_normalize_integrity_analysis(array $analysis): array
{
    foreach (['official_references', 'integrity_concern_weak_points', 'integrity_concern_suggestions', 'general_understanding_notes', 'correlation_notes'] as $k) {
        if (!isset($analysis[$k]) || !is_array($analysis[$k])) {
            $analysis[$k] = isset($analysis[$k]) && $analysis[$k] !== '' ? [(string)$analysis[$k]] : [];
        }
    }
    return $analysis;
}

function ptv4_generate_integrity_review(PDO $pdo, array $attempt): array
{
    $attemptId = (int)$attempt['id'];
    $existing = $pdo->prepare("SELECT analysis_json, summary_text, model, generated_at FROM progress_test_oral_integrity_reviews WHERE attempt_id = ? LIMIT 1");
    $existing->execute([$attemptId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $analysis = json_decode((string)($row['analysis_json'] ?? '{}'), true) ?: [];
        return [
            'ok' => true,
            'cached' => true,
            'analysis' => ptv4_normalize_integrity_analysis($analysis),
            'summary_text' => (string)($row['summary_text'] ?? ''),
            'model' => (string)($row['model'] ?? ''),
            'generated_at' => (string)($row['generated_at'] ?? ''),
        ];
    }

    $items = ptv4_load_items($pdo, $attemptId);
    $responses = ptv4_load_responses($pdo, $attemptId);
    $snippets = [];
    foreach ($items as $item) {
        $resp = $responses[(int)$item['id']] ?? null;
        $t = trim((string)($resp['student_answer_text'] ?? $item['transcript_text'] ?? ''));
        if ($t !== '') {
            $snippets[] = [
                'question' => mb_substr((string)$item['prompt'], 0, 180),
                'answer_excerpt' => mb_substr($t, 0, 600),
                'score_pct' => $resp ? (int)round((float)($resp['score_pct'] ?? 0)) : null,
            ];
        }
    }
    if (!$snippets) {
        return ['ok' => false, 'error' => 'no_transcripts'];
    }

    $system = <<<'TXT'
You are an aviation oral-test integrity reviewer for instructors. Return JSON only.
Use concise likelihood language (Low/Medium/High/Not evaluated). Do not accuse the student.
Evaluate natural speech, script reading indicators, other voices/outside help, overall integrity risk,
general understanding, correlation with questions, consistency, hesitation patterns, frustration/disengagement,
motivation/effort, and any oral-test integrity concerns.
Include a short human-readable summary in summary_text.
TXT;
    $userPrompt = json_encode([
        'attempt_id' => $attemptId,
        'score_pct' => $attempt['score_pct'] ?? null,
        'items' => $snippets,
        'required_fields' => [
            'natural_speech_likelihood', 'script_reading_likelihood', 'multiple_voices_or_coaching_likelihood',
            'overall_integrity_risk', 'general_understanding', 'correlation_with_questions', 'consistency_with_question',
            'hesitation_patterns', 'frustration_or_disengagement', 'motivation_effort', 'integrity_concerns',
            'official_references', 'integrity_concern_weak_points', 'integrity_concern_suggestions', 'summary_text',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'natural_speech_likelihood' => ['type' => 'string'],
            'script_reading_likelihood' => ['type' => 'string'],
            'multiple_voices_or_coaching_likelihood' => ['type' => 'string'],
            'overall_integrity_risk' => ['type' => 'string'],
            'general_understanding' => ['type' => 'string'],
            'correlation_with_questions' => ['type' => 'string'],
            'consistency_with_question' => ['type' => 'string'],
            'hesitation_patterns' => ['type' => 'string'],
            'frustration_or_disengagement' => ['type' => 'string'],
            'motivation_effort' => ['type' => 'string'],
            'integrity_concerns' => ['type' => 'string'],
            'official_references' => ['type' => 'array', 'items' => ['type' => 'string']],
            'integrity_concern_weak_points' => ['type' => 'array', 'items' => ['type' => 'string']],
            'integrity_concern_suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            'summary_text' => ['type' => 'string'],
        ],
        'required' => [
            'natural_speech_likelihood', 'script_reading_likelihood', 'multiple_voices_or_coaching_likelihood',
            'overall_integrity_risk', 'general_understanding', 'correlation_with_questions', 'consistency_with_question',
            'hesitation_patterns', 'frustration_or_disengagement', 'motivation_effort', 'integrity_concerns',
            'official_references', 'integrity_concern_weak_points', 'integrity_concern_suggestions', 'summary_text',
        ],
    ];

    $model = cw_openai_model();
    $analysis = [];
    try {
        $resp = cw_openai_responses([
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $userPrompt]]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'ptv4_integrity_review', 'schema' => $schema, 'strict' => true]],
            'temperature' => 0.2,
            'max_output_tokens' => 900,
        ]);
        $analysis = cw_openai_extract_json_text($resp) ?: [];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $analysis = ptv4_normalize_integrity_analysis($analysis);
    $summaryText = trim((string)($analysis['summary_text'] ?? ''));
    $st = $pdo->prepare("
        INSERT INTO progress_test_oral_integrity_reviews
          (attempt_id, user_id, cohort_id, lesson_id, analysis_json, summary_text, model, generated_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $st->execute([
        $attemptId,
        (int)$attempt['user_id'],
        (int)$attempt['cohort_id'],
        (int)$attempt['lesson_id'],
        json_encode($analysis, JSON_UNESCAPED_UNICODE),
        $summaryText,
        $model,
    ]);
    ptv4_log_debug_event($pdo, (int)$attempt['user_id'], 'integrity_review_generated', $summaryText, ['attempt_id' => $attemptId], $attemptId, null, (int)$attempt['cohort_id'], (int)$attempt['lesson_id']);

    return [
        'ok' => true,
        'cached' => false,
        'analysis' => $analysis,
        'summary_text' => $summaryText,
        'model' => $model,
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ];
}

function ptv4_get_stored_integrity_for_attempt(PDO $pdo, int $attemptId): ?array
{
    $st = $pdo->prepare("SELECT analysis_json, summary_text, model, generated_at FROM progress_test_oral_integrity_reviews WHERE attempt_id = ? LIMIT 1");
    $st->execute([$attemptId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $analysis = json_decode((string)($row['analysis_json'] ?? '{}'), true) ?: [];
    return [
        'analysis' => ptv4_normalize_integrity_analysis($analysis),
        'summary_text' => (string)($row['summary_text'] ?? ''),
        'model' => (string)($row['model'] ?? ''),
        'generated_at' => (string)($row['generated_at'] ?? ''),
        'attempt_id' => $attemptId,
    ];
}
