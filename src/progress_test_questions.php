<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';

function ptq_clamp_questions(array $questions, int $target): array
{
    $out = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $out[] = $q;
    }
    if (count($out) > $target) $out = array_slice($out, 0, $target);
    return $out;
}

function ptq_get_question_count(PDO $pdo): int
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
        $val = (int)trim((string)$st->fetchColumn());
        if ($val >= 1 && $val <= 20) return $val;
    } catch (Throwable $e) {
    }
    return 5;
}

function ptq_normalize_questions(array $questions): array
{
    $out = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;

        $kind = trim((string)($q['kind'] ?? 'yesno'));
        if (!in_array($kind, ['yesno', 'mcq', 'open'], true)) $kind = 'open';

        $prompt = trim((string)($q['prompt'] ?? ''));
        if ($prompt === '') continue;

        $options = $q['options'] ?? [];
        if (!is_array($options)) $options = [];

        $correct = $q['correct'] ?? [];
        if (!is_array($correct)) $correct = [];

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
            $correct['answer_text'] = null;
            $correct['alternatives'] = [];
            $correct['type'] = null;
            $correct['key_points'] = [];
            $correct['min_points_to_pass'] = null;
            $correct['value'] = (bool)$correct['value'];
        } elseif ($kind === 'mcq') {
            if (count($options) > 2) $options = array_slice($options, 0, 2);
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
            if (count($correct['key_points']) > 6) {
                $correct['key_points'] = array_slice($correct['key_points'], 0, 6);
            }
            if ((int)$correct['min_points_to_pass'] < 1) {
                $correct['min_points_to_pass'] = max(1, min(3, count($correct['key_points'])));
            } else {
                $correct['min_points_to_pass'] = (int)$correct['min_points_to_pass'];
            }
        }

        $out[] = [
            'kind' => $kind,
            'prompt' => $prompt,
            'options' => array_values($options),
            'correct' => $correct,
        ];
    }
    return $out;
}

function ptq_question_schema(): array
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

function ptq_ai_prompt_fetch(PDO $pdo, string $promptKey, string $fallback): string
{
    try {
        $st = $pdo->prepare('SELECT prompt_text FROM ai_prompts WHERE prompt_key = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$promptKey]);
        $txt = $st->fetchColumn();
        if (is_string($txt) && trim($txt) !== '') return trim($txt);
    } catch (Throwable $e) {
    }
    return $fallback;
}

function ptq_generate_oral_questions(PDO $pdo, string $truth, string $summary, ?int $targetCount = null): array
{
    $target = $targetCount ?? ptq_get_question_count($pdo);

    $systemFallback = <<<'TXT'
You are generating an oral aviation progress test.

Create exactly {{QUESTION_COUNT}} oral-friendly questions.
Use ONLY the lesson narration text as truth.
Use summary only to detect misconceptions.

CRITICAL RULES:
- Do NOT create classic A/B/C/D letter-answer questions.
- Do NOT create questions where the student would answer only with one letter.
- Do NOT leak the answer inside the wording.
- Do NOT create ambiguous yes/no questions.
- Do NOT ask trick questions.
- Keep them natural for spoken answers.

Use only these kinds:
1. yesno
2. mcq
3. open

FORMAT RULES:
- yesno:
  answer should be yes/no and ideally a short explanation
  options = []
  correct.value = true/false
  correct.answer_text = null
  correct.alternatives = []
  correct.type = null
  correct.key_points = []
  correct.min_points_to_pass = null

- mcq:
  exactly 2 spoken options
  student should answer with the actual concept/term, not a letter
  this is an oral-choice question, not an A/B/C/D question
  correct.value = null
  correct.answer_text = preferred exact spoken answer
  correct.alternatives = close valid spoken variants
  correct.type = oral_choice
  correct.key_points = []
  correct.min_points_to_pass = null

- open:
  options = []
  correct.value = null
  correct.answer_text = null
  correct.alternatives = []
  correct.type = rubric
  correct.key_points = 3 to 6 short rubric items
  correct.min_points_to_pass = integer

TARGET MIX:
- balanced mix of yesno, mcq, and open
- prefer more open questions for higher counts
TXT;

    $systemPrompt = ptq_ai_prompt_fetch($pdo, 'progress_test_generate_questions_system', $systemFallback);
    $systemPrompt = str_replace('{{QUESTION_COUNT}}', (string)$target, $systemPrompt);

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => "LESSON NARRATION:\n{$truth}\n\nSUMMARY:\n{$summary}"]]],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'ipca_questions_first_pass',
                'schema' => ptq_question_schema(),
                'strict' => true,
            ],
        ],
    ];

    $r = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($r);
    $q = is_array($j['questions'] ?? null) ? $j['questions'] : [];

    return ptq_normalize_questions(ptq_clamp_questions($q, $target));
}

function ptq_validate_and_rewrite_questions(PDO $pdo, string $truth, array $questions, ?int $targetCount = null): array
{
    $target = $targetCount ?? ptq_get_question_count($pdo);

    $systemFallback = <<<'TXT'
You are reviewing oral aviation test questions for quality.

You will receive candidate questions.
Your job is to return a corrected final set of {{QUESTION_COUNT}} questions.

REVIEW RULES:
- Remove ambiguity.
- Remove answer leakage.
- Remove leading wording.
- Remove questions that already contain the answer.
- Remove awkward oral phrasing.
- Remove questions where multiple interpretations could unfairly score the student wrong.
- Keep the question answerable from the lesson narration only.
- Keep each question concise and orally natural.
- Preserve the same schema.
- If a question is good, keep it.
- If a question is weak, rewrite it.
- Do not invent facts outside the narration.

IMPORTANT:
- Never include the answer in the prompt.
- Never use A/B/C/D style.
- Prefer precise spoken questions.
- For nuanced concepts, prefer open or mcq over weak yes/no.

Return exactly {{QUESTION_COUNT}} final cleaned questions.
TXT;

    $systemPrompt = ptq_ai_prompt_fetch($pdo, 'progress_test_validate_questions_system', $systemFallback);
    $systemPrompt = str_replace('{{QUESTION_COUNT}}', (string)$target, $systemPrompt);

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' =>
                "LESSON NARRATION:\n{$truth}\n\nCANDIDATE QUESTIONS JSON:\n" .
                json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]]],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'ipca_questions_second_pass',
                'schema' => ptq_question_schema(),
                'strict' => true,
            ],
        ],
    ];

    $r = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($r);
    $q = is_array($j['questions'] ?? null) ? $j['questions'] : [];

    return ptq_normalize_questions(ptq_clamp_questions($q, $target));
}

function ptq_generate_single_question(PDO $pdo, string $truth, array $excludePrompts, ?string $kindPreference = null): array
{
    $excludeBlock = '';
    if ($excludePrompts) {
        $excludeBlock = "\n\nDo NOT repeat or closely paraphrase these existing prompts:\n- " .
            implode("\n- ", array_slice($excludePrompts, 0, 40));
    }
    $kindHint = $kindPreference ? "\nPrefer kind: {$kindPreference}." : '';

    $system = <<<'TXT'
Generate exactly ONE new oral aviation progress test question from the lesson narration.
Return JSON with a single-item questions array. Same schema as batch generation (yesno, mcq, open).
Never leak the answer in the prompt. Oral-friendly wording only.
TXT;

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system . $kindHint]]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => "LESSON NARRATION:\n{$truth}{$excludeBlock}"]]],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'ipca_question_single',
                'schema' => ptq_question_schema(),
                'strict' => true,
            ],
        ],
    ];

    $r = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($r);
    $q = is_array($j['questions'] ?? null) ? $j['questions'] : [];
    $norm = ptq_normalize_questions($q);
    if (!$norm) {
        throw new RuntimeException('Single question generation returned no valid question.');
    }
    return $norm[0];
}

function ptq_score_validation(array $question): array
{
    $score = 85;
    $flags = [];
    $prompt = strtolower((string)($question['prompt'] ?? ''));
    $kind = (string)($question['kind'] ?? '');

    if (strlen($prompt) < 12) {
        $score -= 25;
        $flags[] = 'too_short';
    }
    if (preg_match('/\b(option|choice)\s+[abcd]\b/i', $prompt)) {
        $score -= 40;
        $flags[] = 'letter_choice';
    }
    if ($kind === 'mcq') {
        $opts = (array)($question['options'] ?? []);
        if (count($opts) !== 2) {
            $score -= 20;
            $flags[] = 'bad_options';
        }
    }
    if ($kind === 'open') {
        $kp = (array)(($question['correct'] ?? [])['key_points'] ?? []);
        if (count($kp) < 2) {
            $score -= 15;
            $flags[] = 'weak_rubric';
        }
    }

    $score = max(0, min(100, $score));
    return ['validation_score' => $score, 'validation_flags' => $flags];
}

function ptq_kind_label(string $kind): string
{
    return match ($kind) {
        'yesno' => 'Yes/No Question',
        'mcq' => 'Choice Question',
        'open' => 'Open Question',
        default => 'Question',
    };
}
