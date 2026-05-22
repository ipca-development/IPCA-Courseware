<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/courseware_progression_v2.php';
require_once __DIR__ . '/progress_test_access.php';

function ptv4_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ptv4_require_progress_test_access(PDO $pdo, array $user, int $cohortId, int $studentUserId): void
{
    if ((string)($user['role'] ?? '') === 'admin') return;
    $access = cw_progress_test_access_state($pdo, $studentUserId, $cohortId);
    if (empty($access['allowed'])) {
        ptv4_json(['ok' => false, 'error' => 'Progress test access code required.', 'access_required' => true], 403);
    }
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
    return 'I may not have heard that correctly. Please clarify your answer in English.';
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
    $dir = '/tmp/progress_tests_v4/' . $attemptId . '/' . $itemId;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
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
    return trim(implode(' ', $parts));
}

function ptv4_fire_background_prepare(int $testId, string $cookieHeader = ''): void
{
    require_once __DIR__ . '/progress_test_prep.php';
    pt_prep_fire_background_run($testId, $cookieHeader);
}

function ptv4_ensure_prepared_attempt(PDO $pdo, array $u, int $cohortId, int $lessonId, string $cookieHeader = ''): array
{
    require_once __DIR__ . '/progress_test_prep.php';
    ptv4_mark_stale_interrupted_attempts($pdo, (int)$u['id'], $cohortId, $lessonId);
    $studentUserId = (int)$u['id'];

    $scheduled = pt_prep_schedule_progress_test(
        $pdo,
        $studentUserId,
        $cohortId,
        $lessonId,
        'v4_page',
        $cookieHeader,
        (string)($u['role'] ?? '') === 'admin' ? 'admin' : 'student',
        (int)$u['id']
    );
    if (!empty($scheduled['skipped']) && ($scheduled['reason'] ?? '') !== 'already_prepared' && empty($scheduled['scheduled'])) {
        if (($scheduled['reason'] ?? '') === 'canonical_pass_exists') {
            return ['ok' => false, 'blocked' => true, 'reason' => 'canonical_pass_already_recorded'];
        }
        if (!empty($scheduled['reason']) && $scheduled['reason'] !== 'already_prepared') {
            return ['ok' => false, 'blocked' => true, 'reason' => (string)$scheduled['reason']];
        }
    }

    $attemptId = (int)($scheduled['test_id'] ?? 0);
    if ($attemptId <= 0) {
        $existing = $pdo->prepare("
            SELECT * FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
              AND status IN ('preparing','ready','in_progress','processing')
            ORDER BY id DESC LIMIT 1
        ");
        $existing->execute([$studentUserId, $cohortId, $lessonId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $attemptId = $row ? (int)$row['id'] : 0;
    }
    if ($attemptId <= 0) {
        return ['ok' => false, 'error' => 'Could not locate progress test attempt for preparation.'];
    }

    $attempt = ptv4_load_attempt($pdo, $u, $attemptId);
    $prepared = pt_prep_attempt_is_prepared($attempt, $pdo);

    if (!$prepared) {
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
