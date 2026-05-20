<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/courseware_progression_v2.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ptv3_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ptv3_body(): array
{
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function ptv3_normalize_text(string $text): string
{
    $text = strtolower(trim($text));
    $text = (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $text = (string)preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function ptv3_truth_text(PDO $pdo, int $lessonId): string
{
    $nq = $pdo->prepare("
        SELECT s.page_number, e.narration_en
        FROM slides s
        JOIN slide_enrichment e ON e.slide_id = s.id
        WHERE s.lesson_id = ?
          AND s.is_deleted = 0
          AND e.narration_en IS NOT NULL
          AND e.narration_en <> ''
        ORDER BY s.page_number ASC
    ");
    $nq->execute([$lessonId]);
    $truth = '';
    foreach ($nq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $truth .= 'Slide ' . (int)$row['page_number'] . ': ' . trim((string)$row['narration_en']) . "\n\n";
    }
    return trim($truth);
}

function ptv3_question_has_grounding(string $prompt, string $truth): bool
{
    $p = ptv3_normalize_text($prompt);
    $t = ptv3_normalize_text($truth);
    if ($p === '' || $t === '') return false;

    $blocked = [
        'mona lisa', 'painted the mona lisa', 'leonardo da vinci', 'capital of france',
        'largest planet', 'world war', 'shakespeare', 'hamlet', 'einstein',
    ];
    foreach ($blocked as $phrase) {
        if (strpos($p, $phrase) !== false && strpos($t, $phrase) === false) return false;
    }

    $words = array_values(array_unique(array_filter(explode(' ', $p), static function (string $word): bool {
        return strlen($word) >= 5 && !in_array($word, [
            'question', 'answer', 'briefly', 'explain', 'correct', 'phrase', 'which',
            'what', 'where', 'when', 'using', 'student', 'spoken', 'short',
        ], true);
    })));
    if (!$words) return false;

    $hits = 0;
    foreach ($words as $word) {
        if (strpos($t, $word) !== false) $hits++;
    }
    return $hits >= max(1, min(2, (int)ceil(count($words) * 0.2)));
}

function ptv3_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ptv3_ensure_oral_tables(PDO $pdo): void
{
    if (!ptv3_table_exists($pdo, 'progress_test_oral_item_responses')) {
        $pdo->exec("
            CREATE TABLE progress_test_oral_item_responses (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              attempt_id BIGINT UNSIGNED NOT NULL,
              item_id BIGINT UNSIGNED NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              question_text TEXT NOT NULL,
              maya_spoken_question_text TEXT NOT NULL,
              student_answer_text TEXT NOT NULL,
              clarification_question_text TEXT NULL,
              clarification_answer_text TEXT NULL,
              evaluated_answer_text TEXT NOT NULL,
              score_pct DECIMAL(5,2) NULL,
              is_correct TINYINT(1) NULL,
              feedback_text TEXT NULL,
              detected_concepts_json LONGTEXT NULL,
              missing_concepts_json LONGTEXT NULL,
              weak_areas_json LONGTEXT NULL,
              answered_at DATETIME NULL,
              evaluated_at DATETIME NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_progress_test_oral_attempt_item (attempt_id, item_id),
              KEY idx_progress_test_oral_user_attempt (user_id, attempt_id),
              KEY idx_progress_test_oral_item (item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (!ptv3_table_exists($pdo, 'progress_test_voice_events')) {
        $pdo->exec("
            CREATE TABLE progress_test_voice_events (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              attempt_id BIGINT UNSIGNED NOT NULL,
              item_id BIGINT UNSIGNED NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              role ENUM('maya','student','system') NOT NULL,
              event_type VARCHAR(64) NOT NULL,
              transcript_text TEXT NULL,
              created_at DATETIME NOT NULL,
              PRIMARY KEY (id),
              KEY idx_progress_test_voice_events_attempt (attempt_id, created_at),
              KEY idx_progress_test_voice_events_item (item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

function ptv3_read_json_text(array $resp): array
{
    return cw_openai_extract_json_text($resp);
}

function ptv3_ai_prompt(PDO $pdo, string $key, string $fallback): string
{
    try {
        $st = $pdo->prepare("SELECT prompt_text FROM ai_prompts WHERE prompt_key = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$key]);
        $txt = trim((string)$st->fetchColumn());
        if ($txt !== '') return $txt;
    } catch (Throwable $e) {
    }
    return $fallback;
}

function ptv3_question_count(PDO $pdo): int
{
    try {
        $st = $pdo->prepare("
            SELECT value_text
            FROM system_policy_values
            WHERE policy_key = 'progress_test_question_count'
              AND is_active = 1
              AND scope_type = 'global'
              AND scope_id IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute();
        $value = (int)trim((string)$st->fetchColumn());
        if ($value >= 1 && $value <= 20) return $value;
    } catch (Throwable $e) {
    }
    return 5;
}

function ptv3_question_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['yesno', 'mcq', 'open']],
                        'prompt' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'correct' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'value' => ['type' => ['boolean', 'null']],
                                'answer_text' => ['type' => ['string', 'null']],
                                'alternatives' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'type' => ['type' => ['string', 'null']],
                                'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'min_points_to_pass' => ['type' => ['integer', 'null']],
                            ],
                            'required' => ['value', 'answer_text', 'alternatives', 'type', 'key_points', 'min_points_to_pass'],
                        ],
                    ],
                    'required' => ['kind', 'prompt', 'options', 'correct'],
                ],
            ],
        ],
        'required' => ['questions'],
    ];
}

function ptv3_normalize_questions(array $questions, int $target): array
{
    $out = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $kind = (string)($q['kind'] ?? 'open');
        if (!in_array($kind, ['yesno', 'mcq', 'open'], true)) $kind = 'open';
        $prompt = trim((string)($q['prompt'] ?? ''));
        if ($prompt === '') continue;
        $options = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
        $correct = is_array($q['correct'] ?? null) ? $q['correct'] : [];
        $correct = array_merge([
            'value' => null,
            'answer_text' => null,
            'alternatives' => [],
            'type' => null,
            'key_points' => [],
            'min_points_to_pass' => null,
        ], $correct);
        if (!is_array($correct['alternatives'])) $correct['alternatives'] = [];
        if (!is_array($correct['key_points'])) $correct['key_points'] = [];

        if ($kind === 'yesno') {
            $options = [];
            $correct['value'] = (bool)$correct['value'];
            $correct['answer_text'] = null;
            $correct['alternatives'] = [];
            $correct['type'] = null;
            $correct['key_points'] = [];
            $correct['min_points_to_pass'] = null;
        } elseif ($kind === 'mcq') {
            $options = array_slice($options, 0, 2);
            $correct['value'] = null;
            $correct['type'] = 'oral_choice';
            $correct['key_points'] = [];
            $correct['min_points_to_pass'] = null;
        } else {
            $options = [];
            $correct['value'] = null;
            $correct['answer_text'] = null;
            $correct['alternatives'] = [];
            $correct['type'] = 'rubric';
            $correct['key_points'] = array_slice($correct['key_points'], 0, 6);
            $correct['min_points_to_pass'] = max(1, (int)($correct['min_points_to_pass'] ?? min(3, count($correct['key_points']))));
        }

        $out[] = ['kind' => $kind, 'prompt' => $prompt, 'options' => $options, 'correct' => $correct];
        if (count($out) >= $target) break;
    }
    return $out;
}

function ptv3_generate_questions(PDO $pdo, int $lessonId, int $userId, int $cohortId): array
{
    $truth = ptv3_truth_text($pdo, $lessonId);
    if ($truth === '') {
        throw new RuntimeException('Cannot generate progress test questions: lesson narration is missing.');
    }

    $sq = $pdo->prepare("SELECT summary_plain FROM lesson_summaries WHERE user_id = ? AND cohort_id = ? AND lesson_id = ? LIMIT 1");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summary = trim((string)$sq->fetchColumn());
    if ($summary === '') $summary = '(No student summary.)';

    $target = ptv3_question_count($pdo);
    $system = ptv3_ai_prompt($pdo, 'progress_test_generate_questions_system', <<<'TXT'
You are generating an oral aviation progress test.
Create exactly {{QUESTION_COUNT}} oral-friendly questions.
Use ONLY the lesson narration text as truth. Use summary only to detect misconceptions.
Do not create A/B/C/D questions, do not leak answers, and do not ask trick questions.
Use only kinds: yesno, mcq, open.
For mcq use exactly two spoken options and expect the student to answer with the actual phrase, not a letter.
For open questions include 3 to 6 short rubric key_points and min_points_to_pass.
TXT);
    $system = str_replace('{{QUESTION_COUNT}}', (string)$target, $system);

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => "LESSON NARRATION:\n{$truth}\n\nSUMMARY:\n{$summary}"]]],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'progress_test_v3_questions',
                'schema' => ptv3_question_schema(),
                'strict' => true,
            ],
        ],
    ];

    $resp = cw_openai_responses($payload);
    $json = ptv3_read_json_text($resp);
    $questions = ptv3_normalize_questions(is_array($json['questions'] ?? null) ? $json['questions'] : [], $target);
    $questions = array_values(array_filter($questions, static function (array $q) use ($truth): bool {
        return ptv3_question_has_grounding((string)($q['prompt'] ?? ''), $truth);
    }));
    if (!$questions) {
        throw new RuntimeException('Question generation returned no lesson-grounded questions.');
    }
    return $questions;
}

function ptv3_spoken_question(array $item, int $total): string
{
    $text = 'Question ' . (int)$item['idx'] . ' of ' . $total . '. ' . trim((string)$item['prompt']);
    if ((string)$item['kind'] === 'yesno') return $text . ' Please answer yes or no, and briefly explain.';
    if ((string)$item['kind'] === 'mcq') return $text . ' Please answer with the correct phrase.';
    return $text . ' Please answer in a short spoken explanation.';
}

function ptv3_load_attempt(PDO $pdo, array $u, int $attemptId): array
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

function ptv3_load_items(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare("SELECT * FROM progress_test_items_v2 WHERE test_id = ? ORDER BY idx ASC");
    $st->execute([$attemptId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ptv3_items_are_lesson_grounded(array $items, string $truth): bool
{
    if (!$items || trim($truth) === '') return false;
    foreach ($items as $item) {
        if (!ptv3_question_has_grounding((string)($item['prompt'] ?? ''), $truth)) {
            return false;
        }
    }
    return true;
}

function ptv3_mark_stale_interrupted_attempts(PDO $pdo, int $userId, int $cohortId, int $lessonId): void
{
    $st = $pdo->prepare("
        UPDATE progress_tests_v2
        SET status = 'failed',
            formal_result_code = 'STALE_ABORTED',
            formal_result_label = 'Aborted (stale technical session)',
            counts_as_unsat = 0,
            pass_gate_met = 0,
            timing_status = 'unknown',
            status_text = 'Realtime oral test was interrupted and expired after the 15-minute resume window.',
            updated_at = NOW()
        WHERE user_id = ?
          AND cohort_id = ?
          AND lesson_id = ?
          AND status IN ('preparing','ready','in_progress','processing')
          AND updated_at < (NOW() - INTERVAL 15 MINUTE)
          AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
}

function ptv3_load_responses(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare("SELECT * FROM progress_test_oral_item_responses WHERE attempt_id = ? ORDER BY item_id ASC");
    $st->execute([$attemptId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['item_id']] = $row;
    }
    return $out;
}

function ptv3_state_payload(PDO $pdo, array $attempt): array
{
    $attemptId = (int)$attempt['id'];
    $items = ptv3_load_items($pdo, $attemptId);
    $responses = ptv3_load_responses($pdo, $attemptId);
    $total = count($items);
    $evaluated = 0;
    $scoreSum = 0.0;
    $current = null;
    $itemPayload = [];

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $response = $responses[$itemId] ?? null;
        $isEvaluated = $response && $response['evaluated_at'] !== null;
        if ($isEvaluated) {
            $evaluated++;
            $scoreSum += (float)($response['score_pct'] ?? 0);
        } elseif ($current === null) {
            $current = $item;
        }
        $itemPayload[] = [
            'id' => $itemId,
            'idx' => (int)$item['idx'],
            'kind' => (string)$item['kind'],
            'prompt' => (string)$item['prompt'],
            'spoken_question' => ptv3_spoken_question($item, $total),
            'evaluated' => $isEvaluated,
            'score_pct' => $response ? $response['score_pct'] : null,
            'feedback_text' => $response ? (string)($response['feedback_text'] ?? '') : '',
            'student_answer_text' => $response ? (string)($response['student_answer_text'] ?? '') : '',
            'clarification_question_text' => $response ? (string)($response['clarification_question_text'] ?? '') : '',
            'clarification_answer_text' => $response ? (string)($response['clarification_answer_text'] ?? '') : '',
        ];
    }

    $current = $current ?: ($items[$total - 1] ?? null);
    $scoreProgress = $evaluated > 0 ? round($scoreSum / max(1, $evaluated), 1) : null;
    $status = (string)($attempt['status'] ?? '');
    $resumeMode = $evaluated > 0 || in_array($status, ['in_progress', 'processing'], true) ? 'resumed' : 'new_or_reset';

    return [
        'attempt_id' => $attemptId,
        'status' => $status,
        'attempt_status' => (string)($attempt['formal_result_label'] ?? ($attempt['status_text'] ?? '')),
        'score_pct' => $attempt['score_pct'] !== null ? (int)$attempt['score_pct'] : null,
        'pass_gate_met' => isset($attempt['pass_gate_met']) ? (int)$attempt['pass_gate_met'] : null,
        'formal_result_label' => (string)($attempt['formal_result_label'] ?? ''),
        'total_questions' => $total,
        'ready_questions' => $total,
        'evaluated_count' => $evaluated,
        'score_progress' => $scoreProgress,
        'current_item_id' => $current ? (int)$current['id'] : 0,
        'current_idx' => $current ? (int)$current['idx'] : 0,
        'resume_mode' => $resumeMode,
        'items' => $itemPayload,
    ];
}

function ptv3_phrase_match(string $text, string $phrase): bool
{
    $t = ptv3_normalize_text($text);
    $p = ptv3_normalize_text($phrase);
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

function ptv3_grade_item(PDO $pdo, array $item, string $answer): array
{
    $kind = (string)$item['kind'];
    $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
    $options = json_decode((string)($item['options_json'] ?? '[]'), true) ?: [];
    $detected = [];
    $missing = [];

    if (trim($answer) === '') {
        return ['score_pct' => 0, 'is_correct' => 0, 'feedback' => 'No answer was captured.', 'detected' => [], 'missing' => [], 'weak' => ['No answer captured.']];
    }

    if ($kind === 'yesno') {
        $t = ptv3_normalize_text($answer);
        $yes = preg_match('/\b(yes|true|correct|it is|it does|there is|there are)\b/', $t) ? 1 : 0;
        $no = preg_match('/\b(no|false|not|never|does not|do not|is not|are not)\b/', $t) ? 1 : 0;
        $student = null;
        if ($yes > $no) $student = true;
        if ($no > $yes) $student = false;
        $ok = ($student !== null && $student === (bool)($correct['value'] ?? false)) ? 1 : 0;
        return [
            'score_pct' => $ok ? 100 : 0,
            'is_correct' => $ok,
            'feedback' => $ok ? 'Correct direction. Keep the explanation concise and operational.' : 'That is not exactly right. Review the concept and the yes/no direction.',
            'detected' => $ok ? ['Correct yes/no direction'] : [],
            'missing' => $ok ? [] : ['Correct yes/no direction'],
            'weak' => $ok ? [] : ['Question concept'],
        ];
    }

    if ($kind === 'mcq') {
        $answerText = trim((string)($correct['answer_text'] ?? ''));
        $alts = is_array($correct['alternatives'] ?? null) ? $correct['alternatives'] : [];
        $phrases = array_values(array_filter(array_merge([$answerText], $alts), static fn($v): bool => trim((string)$v) !== ''));
        $ok = 0;
        foreach ($phrases as $phrase) {
            if (ptv3_phrase_match($answer, (string)$phrase)) {
                $ok = 1;
                $detected[] = (string)$phrase;
                break;
            }
        }
        if (!$ok && is_array($options)) {
            foreach ($options as $option) {
                if ($answerText !== '' && ptv3_phrase_match((string)$option, $answerText) && ptv3_phrase_match($answer, (string)$option)) {
                    $ok = 1;
                    $detected[] = $answerText;
                    break;
                }
            }
        }
        return [
            'score_pct' => $ok ? 100 : 0,
            'is_correct' => $ok,
            'feedback' => $ok ? 'Correct. You selected the right concept.' : 'That is not the correct choice. Review the related terminology before re-attempting.',
            'detected' => $detected,
            'missing' => $ok ? [] : ($answerText !== '' ? [$answerText] : ['Correct choice']),
            'weak' => $ok ? [] : ['Oral choice terminology'],
        ];
    }

    $keyPoints = is_array($correct['key_points'] ?? null) ? $correct['key_points'] : [];
    $minPts = max(1, (int)($correct['min_points_to_pass'] ?? min(3, count($keyPoints))));
    foreach ($keyPoints as $point) {
        if (ptv3_phrase_match($answer, (string)$point)) {
            $detected[] = (string)$point;
        } else {
            $missing[] = (string)$point;
        }
    }

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'score_points' => ['type' => 'integer'],
            'max_points' => ['type' => 'integer'],
            'is_correct' => ['type' => 'boolean'],
            'feedback' => ['type' => 'string'],
            'detected_concepts' => ['type' => 'array', 'items' => ['type' => 'string']],
            'missing_concepts' => ['type' => 'array', 'items' => ['type' => 'string']],
            'weak_areas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['score_points', 'max_points', 'is_correct', 'feedback', 'detected_concepts', 'missing_concepts', 'weak_areas'],
    ];

    $system = ptv3_ai_prompt($pdo, 'progress_test_open_grading_system', 'Grade like a supportive but standards-based flight instructor. Use only the internal scoring criteria and transcript. Do not invent facts. Reward correct operational understanding and give partial credit for incomplete but correct answers. Student-facing feedback must never use the word rubric.');
    $userPrompt = "QUESTION:\n" . (string)$item['prompt']
        . "\n\nRUBRIC KEY POINTS:\n- " . implode("\n- ", array_map('strval', $keyPoints))
        . "\n\nMIN POINTS TO PASS: {$minPts}\n\nSTUDENT TRANSCRIPT:\n{$answer}";

    $j = [];
    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $userPrompt]]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'progress_test_v3_open_grade', 'schema' => $schema, 'strict' => true]],
            'temperature' => 0.1,
        ]);
        $j = cw_openai_extract_json_text($resp);
    } catch (Throwable $e) {
    }

    $max = max(1, (int)($j['max_points'] ?? count($keyPoints) ?: $minPts));
    $points = max(0, min($max, (int)($j['score_points'] ?? count($detected))));
    $scorePct = (int)round(($points / $max) * 100);
    $isCorrect = (!empty($j['is_correct']) || $points >= $minPts) ? 1 : 0;
    $feedback = trim((string)($j['feedback'] ?? ''));
    if ($feedback === '') {
        $feedback = $isCorrect ? 'Good answer. You covered the required concept well enough.' : 'Good start, but the answer is incomplete. Review the missing required concepts.';
    }
    $feedback = ptv3_student_feedback_text($feedback);

    return [
        'score_pct' => $scorePct,
        'is_correct' => $isCorrect,
        'feedback' => $feedback,
        'detected' => is_array($j['detected_concepts'] ?? null) ? $j['detected_concepts'] : $detected,
        'missing' => is_array($j['missing_concepts'] ?? null) ? $j['missing_concepts'] : $missing,
        'weak' => is_array($j['weak_areas'] ?? null) ? $j['weak_areas'] : $missing,
    ];
}

function ptv3_student_feedback_text(string $feedback): string
{
    $feedback = trim($feedback);
    $feedback = (string)preg_replace('/\bthe\s+rubric\s+(requires|expects|says|asks for)\b/i', 'the expected answer $1', $feedback);
    $feedback = (string)preg_replace('/\brubric\b/i', 'expected answer', $feedback);
    $feedback = (string)preg_replace('/\baccording to the expected answer\b/i', 'for this question', $feedback);
    return trim($feedback);
}

function ptv3_log_event(PDO $pdo, int $attemptId, ?int $itemId, int $userId, string $role, string $type, string $text): void
{
    try {
        $st = $pdo->prepare("
            INSERT INTO progress_test_voice_events
              (attempt_id, item_id, user_id, role, event_type, transcript_text, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([$attemptId, $itemId ?: null, $userId, $role, $type, $text]);
    } catch (Throwable $e) {
    }
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        ptv3_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    ptv3_ensure_oral_tables($pdo);

    $data = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : ptv3_body();
    $action = (string)($data['action'] ?? 'get_state');
    $actorUserId = (int)($u['id'] ?? 0);

    if ($action === 'start_oral_test') {
        $cohortId = (int)($data['cohort_id'] ?? 0);
        $lessonId = (int)($data['lesson_id'] ?? 0);
        if ($cohortId <= 0 || $lessonId <= 0) ptv3_json(['ok' => false, 'error' => 'Missing cohort_id or lesson_id'], 400);

        $studentUserId = $role === 'admin' ? (int)($data['user_id'] ?? $actorUserId) : $actorUserId;
        if ($role === 'student') {
            $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
            $en->execute([$cohortId, $studentUserId]);
            if (!$en->fetchColumn()) ptv3_json(['ok' => false, 'error' => 'Not actively enrolled'], 403);
        }

        ptv3_mark_stale_interrupted_attempts($pdo, $studentUserId, $cohortId, $lessonId);

        $existing = $pdo->prepare("
            SELECT *
            FROM progress_tests_v2
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
              AND status IN ('preparing','ready','in_progress')
            ORDER BY id DESC
            LIMIT 1
        ");
        $existing->execute([$studentUserId, $cohortId, $lessonId]);
        $attempt = $existing->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            $engine = new CoursewareProgressionV2($pdo);
            $created = $engine->createProgressTestAttempt($studentUserId, $cohortId, $lessonId, $role === 'admin' ? 'admin' : 'student', $actorUserId);
            if (!empty($created['blocked'])) {
                ptv3_json(['ok' => false, 'blocked' => true, 'reason' => (string)($created['reason'] ?? 'blocked'), 'error' => 'Progress test start blocked.'], 409);
            }
            $attempt = ptv3_load_attempt($pdo, $u, (int)$created['test_id']);
        }

        $attemptId = (int)$attempt['id'];
        $items = ptv3_load_items($pdo, $attemptId);
        $truth = ptv3_truth_text($pdo, (int)$attempt['lesson_id']);
        if ($items && !ptv3_items_are_lesson_grounded($items, $truth)) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM progress_test_oral_item_responses WHERE attempt_id = ?")->execute([$attemptId]);
                $pdo->prepare("DELETE FROM progress_test_voice_events WHERE attempt_id = ?")->execute([$attemptId]);
                $pdo->prepare("DELETE FROM progress_test_items_v2 WHERE test_id = ?")->execute([$attemptId]);
                $pdo->prepare("
                    UPDATE progress_tests_v2
                    SET status = 'preparing',
                        progress_pct = 20,
                        score_pct = NULL,
                        status_text = 'Discarded ungrounded oral questions; regenerating from lesson narration.',
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$attemptId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $items = [];
            ptv3_log_event($pdo, $attemptId, null, (int)$attempt['user_id'], 'system', 'ungrounded_questions_discarded', 'Progress Test V3 discarded non-lesson-grounded questions before start.');
        }
        if (!$items) {
            $pdo->prepare("UPDATE progress_tests_v2 SET status = 'preparing', progress_pct = 20, status_text = 'Generating oral questions...', updated_at = NOW() WHERE id = ?")->execute([$attemptId]);
            $questions = ptv3_generate_questions($pdo, (int)$attempt['lesson_id'], (int)$attempt['user_id'], (int)$attempt['cohort_id']);
            $ins = $pdo->prepare("
                INSERT INTO progress_test_items_v2 (test_id, idx, kind, prompt, options_json, correct_json)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $idx = 1;
            foreach ($questions as $q) {
                $ins->execute([
                    $attemptId,
                    $idx++,
                    (string)$q['kind'],
                    (string)$q['prompt'],
                    json_encode($q['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    json_encode($q['correct'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        $nextStatus = (int)count(ptv3_load_responses($pdo, $attemptId)) > 0 || in_array((string)($attempt['status'] ?? ''), ['in_progress', 'processing'], true)
            ? 'in_progress'
            : 'ready';
        $statusText = $nextStatus === 'in_progress'
            ? 'Realtime oral progress test interrupted; ready to resume.'
            : 'Ready to start realtime oral progress test.';

        $pdo->prepare("
            UPDATE progress_tests_v2
            SET status = ?,
                progress_pct = 100,
                status_text = ?,
                updated_at = NOW()
            WHERE id = ?
              AND status != 'completed'
        ")->execute([$nextStatus, $statusText, $attemptId]);

        $attempt = ptv3_load_attempt($pdo, $u, $attemptId);
        ptv3_json(['ok' => true, 'state' => ptv3_state_payload($pdo, $attempt)]);
    }

    $attemptId = (int)($data['attempt_id'] ?? 0);
    if ($attemptId <= 0) ptv3_json(['ok' => false, 'error' => 'attempt_id required'], 400);
    $attempt = ptv3_load_attempt($pdo, $u, $attemptId);
    $attemptUserId = (int)$attempt['user_id'];

    if ($action === 'get_state') {
        ptv3_json(['ok' => true, 'state' => ptv3_state_payload($pdo, $attempt)]);
    }

    if ($action === 'save_transcript_segment') {
        $itemId = (int)($data['item_id'] ?? 0);
        $roleIn = (string)($data['role'] ?? 'student');
        $eventType = (string)($data['event_type'] ?? 'answer');
        $text = trim((string)($data['transcript_text'] ?? ''));
        if ($itemId <= 0 || $text === '') ptv3_json(['ok' => false, 'error' => 'item_id and transcript_text required'], 400);
        if (!in_array($roleIn, ['maya', 'student', 'system'], true)) $roleIn = 'student';
        ptv3_log_event($pdo, $attemptId, $itemId, $attemptUserId, $roleIn, $eventType, $text);
        $pdo->prepare("
            UPDATE progress_tests_v2
            SET status = CASE WHEN status IN ('preparing','ready') THEN 'in_progress' ELSE status END,
                status_text = 'Realtime oral progress test in progress.',
                updated_at = NOW()
            WHERE id = ?
              AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);
        ptv3_json(['ok' => true]);
    }

    if ($action === 'evaluate_item_answer') {
        $itemId = (int)($data['item_id'] ?? 0);
        $answer = trim((string)($data['student_answer_text'] ?? ''));
        $clarificationQuestion = trim((string)($data['clarification_question_text'] ?? ''));
        $clarificationAnswer = trim((string)($data['clarification_answer_text'] ?? ''));
        if ($itemId <= 0 || $answer === '') ptv3_json(['ok' => false, 'error' => 'item_id and student_answer_text required'], 400);

        $itemSt = $pdo->prepare("SELECT * FROM progress_test_items_v2 WHERE id = ? AND test_id = ? LIMIT 1");
        $itemSt->execute([$itemId, $attemptId]);
        $item = $itemSt->fetch(PDO::FETCH_ASSOC);
        if (!$item) ptv3_json(['ok' => false, 'error' => 'Question item not found'], 404);
        $pdo->prepare("
            UPDATE progress_tests_v2
            SET status = 'in_progress',
                status_text = 'Realtime oral progress test in progress.',
                updated_at = NOW()
            WHERE id = ?
              AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);

        $total = count(ptv3_load_items($pdo, $attemptId));
        $spoken = ptv3_spoken_question($item, $total);
        $evaluatedAnswer = trim($answer . ($clarificationAnswer !== '' ? "\n\nClarification answer: " . $clarificationAnswer : ''));
        $grade = ptv3_grade_item($pdo, $item, $evaluatedAnswer);
        $scorePct = (float)$grade['score_pct'];
        $isComplete = $scorePct >= 70 || !empty($grade['is_correct']);

        $existingSt = $pdo->prepare("SELECT clarification_question_text FROM progress_test_oral_item_responses WHERE attempt_id = ? AND item_id = ? LIMIT 1");
        $existingSt->execute([$attemptId, $itemId]);
        $existingClarification = trim((string)$existingSt->fetchColumn());
        $clarificationAllowed = !$isComplete && $scorePct >= 30 && $scorePct < 70 && $existingClarification === '' && $clarificationQuestion === '';
        $nextAction = $clarificationAllowed ? 'clarify' : 'next_question';

        $feedback = ptv3_student_feedback_text((string)$grade['feedback']);
        if (!$clarificationAllowed) {
            $up = $pdo->prepare("
                INSERT INTO progress_test_oral_item_responses
                    (attempt_id, item_id, user_id, question_text, maya_spoken_question_text, student_answer_text,
                     clarification_question_text, clarification_answer_text, evaluated_answer_text, score_pct, is_correct,
                     feedback_text, detected_concepts_json, missing_concepts_json, weak_areas_json, answered_at, evaluated_at, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())
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
                    evaluated_at = VALUES(evaluated_at),
                    updated_at = NOW()
            ");
            $up->execute([
                $attemptId,
                $itemId,
                $attemptUserId,
                (string)$item['prompt'],
                $spoken,
                $answer,
                $clarificationQuestion !== '' ? $clarificationQuestion : null,
                $clarificationAnswer !== '' ? $clarificationAnswer : null,
                $evaluatedAnswer,
                $scorePct,
                (int)$grade['is_correct'],
                $feedback,
                json_encode($grade['detected'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($grade['missing'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($grade['weak'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $itemUpd = $pdo->prepare("
                UPDATE progress_test_items_v2
                SET transcript_text = ?,
                    is_correct = ?,
                    score_points = ?,
                    max_points = 100,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $itemUpd->execute([$evaluatedAnswer, (int)$grade['is_correct'], (int)round($scorePct), $itemId]);
            ptv3_log_event($pdo, $attemptId, $itemId, $attemptUserId, 'student', 'answer_final', $evaluatedAnswer);
            ptv3_log_event($pdo, $attemptId, $itemId, $attemptUserId, 'maya', 'backend_feedback', $feedback);
        } else {
            $clarificationText = 'You scored ' . (int)round($scorePct) . ' percent so far. This answer is partly correct, but not complete. Please add one or two more important points from the question.';
            $feedback = 'Partial answer. One clarification is allowed before scoring this question.';
            $up = $pdo->prepare("
                INSERT INTO progress_test_oral_item_responses
                    (attempt_id, item_id, user_id, question_text, maya_spoken_question_text, student_answer_text,
                     clarification_question_text, evaluated_answer_text, feedback_text, answered_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    student_answer_text = VALUES(student_answer_text),
                    clarification_question_text = VALUES(clarification_question_text),
                    evaluated_answer_text = VALUES(evaluated_answer_text),
                    feedback_text = VALUES(feedback_text),
                    answered_at = VALUES(answered_at),
                    updated_at = NOW()
            ");
            $up->execute([$attemptId, $itemId, $attemptUserId, (string)$item['prompt'], $spoken, $answer, $clarificationText, $answer, $feedback]);
            ptv3_log_event($pdo, $attemptId, $itemId, $attemptUserId, 'maya', 'clarification_question', $clarificationText);
            $feedback = $clarificationText;
        }

        $state = ptv3_state_payload($pdo, ptv3_load_attempt($pdo, $u, $attemptId));
        if (!$clarificationAllowed && (int)$state['evaluated_count'] >= (int)$state['total_questions']) {
            $nextAction = 'complete_test';
        }

        ptv3_json([
            'ok' => true,
            'item_id' => $itemId,
            'score_pct' => $scorePct,
            'is_correct' => !empty($grade['is_correct']),
            'is_complete' => !$clarificationAllowed,
            'feedback_for_student' => $feedback,
            'detected_concepts' => $grade['detected'],
            'missing_concepts' => $grade['missing'],
            'weak_areas' => $grade['weak'],
            'clarification_allowed' => $clarificationAllowed,
            'next_action' => $nextAction,
            'state' => $state,
        ]);
    }

    if ($action === 'advance_item') {
        ptv3_json(['ok' => true, 'state' => ptv3_state_payload($pdo, $attempt)]);
    }

    if ($action === 'abort_voice_session_without_penalty') {
        $pdo->prepare("
            UPDATE progress_tests_v2
            SET status = 'in_progress',
                status_text = 'Voice disconnected. You can resume the realtime oral test within 15 minutes.',
                updated_at = NOW()
            WHERE id = ?
              AND status NOT IN ('completed','failed')
        ")->execute([$attemptId]);
        ptv3_log_event($pdo, $attemptId, null, $attemptUserId, 'system', 'voice_disconnected', 'Voice session ended without penalty.');
        ptv3_json(['ok' => true, 'state' => ptv3_state_payload($pdo, ptv3_load_attempt($pdo, $u, $attemptId))]);
    }

    if ($action === 'end_oral_test_without_penalty') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM progress_test_oral_item_responses WHERE attempt_id = ?")->execute([$attemptId]);
            $pdo->prepare("
                UPDATE progress_test_items_v2
                SET transcript_text = NULL,
                    is_correct = NULL,
                    score_points = NULL,
                    max_points = NULL,
                    updated_at = NOW()
                WHERE test_id = ?
            ")->execute([$attemptId]);
            $pdo->prepare("
                UPDATE progress_tests_v2
                SET status = 'failed',
                    score_pct = NULL,
                    progress_pct = 0,
                    status_text = 'Realtime oral test ended without penalty due to student/technical abort.',
                    formal_result_code = 'STALE_ABORTED',
                    formal_result_label = 'Aborted (technical/no penalty)',
                    counts_as_unsat = 0,
                    pass_gate_met = 0,
                    timing_status = 'unknown',
                    completed_at = NULL,
                    updated_at = NOW()
                WHERE id = ?
                  AND status NOT IN ('completed','failed')
            ")->execute([$attemptId]);
            ptv3_log_event($pdo, $attemptId, null, $attemptUserId, 'system', 'oral_test_ended_without_penalty', 'Student ended realtime oral test; partial oral responses were reset.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        ptv3_json(['ok' => true, 'state' => ptv3_state_payload($pdo, ptv3_load_attempt($pdo, $u, $attemptId))]);
    }

    if ($action === 'complete_test') {
        $state = ptv3_state_payload($pdo, $attempt);
        if ((int)$state['total_questions'] <= 0 || (int)$state['evaluated_count'] < (int)$state['total_questions']) {
            ptv3_json(['ok' => false, 'error' => 'All items must be evaluated before completion.'], 409);
        }

        $responses = ptv3_load_responses($pdo, $attemptId);
        $scoreTotal = 0.0;
        $weak = [];
        $strong = [];
        foreach ($responses as $response) {
            $score = (float)($response['score_pct'] ?? 0);
            $scoreTotal += $score;
            $weakAreas = json_decode((string)($response['weak_areas_json'] ?? '[]'), true);
            $detected = json_decode((string)($response['detected_concepts_json'] ?? '[]'), true);
            if ($score < 70 && is_array($weakAreas)) $weak = array_merge($weak, array_map('strval', $weakAreas));
            if ($score >= 70 && is_array($detected)) $strong = array_merge($strong, array_map('strval', $detected));
        }
        $scorePct = (int)round($scoreTotal / max(1, count($responses)));
        $weak = array_values(array_unique(array_filter($weak)));
        $strong = array_values(array_unique(array_filter($strong)));
        $weakText = $weak ? implode(', ', array_slice($weak, 0, 5)) : 'No major weak areas identified.';
        $strongText = $strong ? implode(', ', array_slice($strong, 0, 4)) : 'You handled several concepts adequately.';

        $engine = new CoursewareProgressionV2($pdo);
        $finalize = $engine->finalizeAssessedProgressTest($attemptId, [
            'score_pct' => $scorePct,
            'ai_summary' => 'Answered via realtime oral mode. Score: ' . $scorePct . '%. Weak areas: ' . $weakText,
            'weak_areas' => $weakText,
            'debrief_spoken' => 'Answered via realtime oral mode. Score: ' . $scorePct . ' percent.',
            'summary_quality' => 'Not reassessed in realtime oral mode.',
            'summary_issues' => '',
            'summary_corrections' => '',
            'confirmed_misunderstandings' => $weakText,
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $attempt = ptv3_load_attempt($pdo, $u, $attemptId);
        $label = (string)($attempt['formal_result_label'] ?? '');
        $passed = !empty($attempt['pass_gate_met']);
        $summary = $passed
            ? 'You passed with ' . $scorePct . '%. Your strongest areas were ' . $strongText . '. Review ' . $weakText . ' before moving on.'
            : 'You did not pass this attempt. The main weak areas were ' . $weakText . '. Study those before re-attempting.';

        ptv3_json([
            'ok' => true,
            'score_pct' => $scorePct,
            'pass_gate_met' => $passed ? 1 : 0,
            'formal_result_label' => $label,
            'strongest_areas' => $strong,
            'weak_areas' => $weak,
            'summary' => $summary,
            'state' => ptv3_state_payload($pdo, $attempt),
            'automation_result' => $finalize['automation_result'] ?? null,
        ]);
    }

    ptv3_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    ptv3_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
