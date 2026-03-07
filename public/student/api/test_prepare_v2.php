<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function set_prepare_progress(PDO $pdo, int $testId, int $pct, string $text): void {
    $pct = max(0, min(100, $pct));
    $st = $pdo->prepare("
        UPDATE progress_tests_v2
        SET progress_pct=?,
            status_text=?,
            updated_at=NOW()
        WHERE id=?
    ");
    $st->execute([$pct, $text, $testId]);
}

function temp_base_dir(): string {
    $dir = '/tmp/progress_tests_v2';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Temp storage directory is not writable: ' . $dir);
    }
    return $dir;
}

function make_test_dir(int $testId): string {
    $dir = temp_base_dir() . '/' . $testId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Test temp directory is not writable: ' . $dir);
    }
    return $dir;
}

function tts_generate_local(string $text, string $file): void {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) $apiKey = getenv('CW_OPENAI_API_KEY');
    if (!$apiKey) {
        throw new RuntimeException('Missing OPENAI_API_KEY');
    }

    $model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $voice = getenv('CW_OPENAI_TTS_VOICE') ?: 'marin';

    $payload = json_encode([
        'model'  => $model,
        'voice'  => $voice,
        'format' => 'mp3',
        'input'  => $text
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 180
    ]);

    $audio = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("TTS failed (HTTP {$code}) {$err}");
    }

    if (@file_put_contents($file, $audio) === false) {
        throw new RuntimeException('Failed to write temp audio file: ' . $file);
    }

    if (!is_file($file) || filesize($file) <= 0) {
        throw new RuntimeException('Generated temp audio file is missing or empty: ' . $file);
    }
}

function read_json(string $s): array {
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
}

function clamp_questions(array $questions, int $target = 10): array {
    $out = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $out[] = $q;
    }
    if (count($out) > $target) $out = array_slice($out, 0, $target);
    return $out;
}

function normalize_generated_questions(array $questions): array {
    $out = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;

        $kind = trim((string)($q['kind'] ?? 'yesno'));
        if (!in_array($kind, ['yesno', 'mcq', 'open'], true)) {
            $kind = 'open';
        }

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
            'min_points_to_pass' => null
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
            'correct' => $correct
        ];
    }
    return $out;
}

function question_schema(): array {
    return [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "questions" => [
                "type" => "array",
                "items" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "properties" => [
                        "kind" => [
                            "type" => "string",
                            "enum" => ["yesno", "mcq", "open"]
                        ],
                        "prompt" => [
                            "type" => "string"
                        ],
                        "options" => [
                            "type" => "array",
                            "items" => ["type" => "string"]
                        ],
                        "correct" => [
                            "type" => "object",
                            "additionalProperties" => false,
                            "properties" => [
                                "value" => ["type" => ["boolean", "null"]],
                                "answer_text" => ["type" => ["string", "null"]],
                                "alternatives" => [
                                    "type" => "array",
                                    "items" => ["type" => "string"]
                                ],
                                "type" => ["type" => ["string", "null"]],
                                "key_points" => [
                                    "type" => "array",
                                    "items" => ["type" => "string"]
                                ],
                                "min_points_to_pass" => ["type" => ["integer", "null"]]
                            ],
                            "required" => ["value", "answer_text", "alternatives", "type", "key_points", "min_points_to_pass"]
                        ]
                    ],
                    "required" => ["kind", "prompt", "options", "correct"]
                ]
            ]
        ],
        "required" => ["questions"]
    ];
}

function generate_oral_questions(string $truth, string $summary): array {
    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            [
                "role" => "system",
                "content" => [
                    ["type" => "input_text", "text" =>
"You are generating an oral aviation progress test.

Create exactly 10 oral-friendly questions.
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
- around 4 yesno
- around 2 mcq
- around 4 open"
                    ]
                ]
            ],
            [
                "role" => "user",
                "content" => [
                    ["type" => "input_text", "text" =>
"LESSON NARRATION:\n" . $truth . "\n\nSUMMARY:\n" . $summary
                    ]
                ]
            ]
        ],
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "ipca_questions_first_pass",
                "schema" => question_schema(),
                "strict" => true
            ]
        ]
    ];

    $r = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($r);
    $q = is_array($j['questions'] ?? null) ? $j['questions'] : [];
    return normalize_generated_questions(clamp_questions($q, 10));
}

function validate_and_rewrite_questions(string $truth, array $questions): array {
    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            [
                "role" => "system",
                "content" => [
                    ["type" => "input_text", "text" =>
"You are reviewing oral aviation test questions for quality.

You will receive 10 candidate questions.
Your job is to return a corrected final set of 10 questions.

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

Return exactly 10 final cleaned questions."
                    ]
                ]
            ],
            [
                "role" => "user",
                "content" => [
                    ["type" => "input_text", "text" =>
"LESSON NARRATION:\n" . $truth .
"\n\nCANDIDATE QUESTIONS JSON:\n" .
json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ]
        ],
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "ipca_questions_second_pass",
                "schema" => question_schema(),
                "strict" => true
            ]
        ]
    ];

    $r = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($r);
    $q = is_array($j['questions'] ?? null) ? $j['questions'] : [];
    return normalize_generated_questions(clamp_questions($q, 10));
}

function presign_spaces_put_via_internal_endpoint(string $cookieHeader, array $payload): array {
    $url = 'http://127.0.0.1/student/api/progress_test_spaces_presign.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $cookieHeader !== '' ? ('Cookie: ' . $cookieHeader) : null
        ]),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($out === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Presign request failed (HTTP {$code}) {$err} " . substr((string)$out, 0, 300));
    }

    $j = read_json((string)$out);
    if (empty($j['ok']) || empty($j['url']) || empty($j['public_url'])) {
        throw new RuntimeException('Invalid presign response');
    }

    return $j;
}

function upload_file_to_presigned_put(string $putUrl, string $localFile, string $contentType): void {
    if (!is_file($localFile)) {
        throw new RuntimeException('Local file not found for upload: ' . $localFile);
    }

    $fh = fopen($localFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot open local file for upload: ' . $localFile);
    }

    $size = filesize($localFile);
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
            'x-amz-acl: public-read'
        ],
        CURLOPT_TIMEOUT => 300
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($out === false || $code < 200 || $code >= 299) {
        throw new RuntimeException("Spaces upload failed (HTTP {$code}) {$err} " . substr((string)$out, 0, 300));
    }
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_ok(['ok' => false, 'error' => 'Forbidden']);
    }

    $data = read_json((string)file_get_contents('php://input'));
    if (!$data) {
        json_ok(['ok' => false, 'error' => 'Invalid JSON']);
    }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);

    if ($cohortId <= 0 || $lessonId <= 0) {
        json_ok(['ok' => false, 'error' => 'Missing cohort_id or lesson_id']);
    }

    $userId = (int)($u['id'] ?? 0);

    if ($role === 'student') {
        $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            http_response_code(403);
            json_ok(['ok' => false, 'error' => 'Not enrolled']);
        }
    }

    $mx = $pdo->prepare("
        SELECT MAX(attempt)
        FROM progress_tests_v2
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
    ");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $attempt = (int)$mx->fetchColumn() + 1;
    if ($attempt <= 0) $attempt = 1;

    $seed = bin2hex(random_bytes(16));

    $pdo->prepare("
        INSERT INTO progress_tests_v2
        (user_id, cohort_id, lesson_id, attempt, status, seed, started_at)
        VALUES (?,?,?,?, 'preparing', ?, NOW())
    ")->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);

    $testId = (int)$pdo->lastInsertId();
    set_prepare_progress($pdo, $testId, 3, 'Initializing progress test...');
    $testDir = make_test_dir($testId);
    set_prepare_progress($pdo, $testId, 6, 'Loading lesson content...');

    $nq = $pdo->prepare("
        SELECT e.narration_en
        FROM slides s
        JOIN slide_enrichment e ON e.slide_id = s.id
        WHERE s.lesson_id=? AND s.is_deleted=0
          AND e.narration_en IS NOT NULL AND e.narration_en <> ''
        ORDER BY s.page_number
    ");
    $nq->execute([$lessonId]);
    $nrows = $nq->fetchAll(PDO::FETCH_ASSOC);

    $truth = "";
    foreach ($nrows as $r) {
        $truth .= trim((string)$r['narration_en']) . "\n\n";
    }
    if (trim($truth) === '') {
        $truth = "(No narration scripts available.)";
    }

    $sq = $pdo->prepare("
        SELECT summary_plain
        FROM lesson_summaries
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summary = trim((string)$sq->fetchColumn());
    if ($summary === '') $summary = "(No student summary.)";

    set_prepare_progress($pdo, $testId, 15, 'Generating oral questions...');
    $q = generate_oral_questions($truth, $summary);

    set_prepare_progress($pdo, $testId, 35, 'Checking question quality...');
    if (count($q) >= 6) {
        $q2 = validate_and_rewrite_questions($truth, $q);
        if (count($q2) >= 6) {
            $q = $q2;
        }
    }

    set_prepare_progress($pdo, $testId, 50, 'Saving questions...');

    if (count($q) < 3) {
        $q = [
            [
                'kind' => 'yesno',
                'prompt' => 'Is the checklist used to reduce errors? Answer yes or no, and briefly explain.',
                'options' => [],
                'correct' => [
                    'value' => true,
                    'answer_text' => null,
                    'alternatives' => [],
                    'type' => null,
                    'key_points' => [],
                    'min_points_to_pass' => null
                ]
            ],
            [
                'kind' => 'mcq',
                'prompt' => 'Is the role of the wings to produce lift or to turn the airplane left and right?',
                'options' => ['produce lift', 'turn the airplane left and right'],
                'correct' => [
                    'value' => null,
                    'answer_text' => 'produce lift',
                    'alternatives' => ['lift', 'to produce lift'],
                    'type' => 'oral_choice',
                    'key_points' => [],
                    'min_points_to_pass' => null
                ]
            ],
            [
                'kind' => 'open',
                'prompt' => 'Explain why pilots use checklists.',
                'options' => [],
                'correct' => [
                    'value' => null,
                    'answer_text' => null,
                    'alternatives' => [],
                    'type' => 'rubric',
                    'key_points' => ['reduce errors', 'standardize', 'safety'],
                    'min_points_to_pass' => 2
                ]
            ],
        ];
    }

    $ins = $pdo->prepare("
        INSERT INTO progress_test_items_v2
        (test_id, idx, kind, prompt, options_json, correct_json)
        VALUES (?,?,?,?,?,?)
    ");

    $idx = 1;
    foreach ($q as $qq) {
        $kind = trim((string)($qq['kind'] ?? 'yesno'));
        $prompt = trim((string)($qq['prompt'] ?? ''));
        if ($prompt === '') $prompt = 'Question ' . $idx;

        $optionsArr = $qq['options'] ?? [];
        if (!is_array($optionsArr)) $optionsArr = [];

        $correctArr = $qq['correct'] ?? [];
        if (!is_array($correctArr)) {
            $correctArr = [
                'value' => null,
                'answer_text' => null,
                'alternatives' => [],
                'type' => null,
                'key_points' => [],
                'min_points_to_pass' => null
            ];
        }

        $options = json_encode($optionsArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $correct = json_encode($correctArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ins->execute([
            $testId,
            $idx,
            $kind,
            $prompt,
            $options ?: '[]',
            $correct ?: '{}'
        ]);

        $idx++;
        if ($idx > 10) break;
    }

    set_prepare_progress($pdo, $testId, 58, 'Preparing audio files...');

    $items = $pdo->prepare("
        SELECT id, idx, prompt, kind, options_json
        FROM progress_test_items_v2
        WHERE test_id=?
        ORDER BY idx
    ");
    $items->execute([$testId]);
    $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

    $name = trim((string)($u['name'] ?? 'student'));
    if ($name === '') $name = 'student';
    $nameParts = preg_split('/\s+/', $name);
    $firstName = trim((string)($nameParts[0] ?? 'student'));

    $cookieHeader = '';
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $cookieHeader = (string)$_SERVER['HTTP_COOKIE'];
    }

    $questionUrls = [];
    $itemIds = [];
    $totalAudio = 1 + count($itemRows);
    $doneAudio = 0;

    $introText = "Hello {$firstName}. I will now conduct your progress test.";
    $introLocal = $testDir . '/intro.mp3';
    tts_generate_local($introText, $introLocal);

    $introPresign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
        'test_id' => $testId,
        'kind'    => 'intro',
        'ext'     => 'mp3'
    ]);
    upload_file_to_presigned_put((string)$introPresign['url'], $introLocal, 'audio/mpeg');
    $introUrl = (string)$introPresign['public_url'];

    $doneAudio++;
    $pct = 58 + (int)floor(($doneAudio / $totalAudio) * 40);
    set_prepare_progress($pdo, $testId, $pct, 'Uploading audio ' . $doneAudio . ' of ' . $totalAudio . '...');

    foreach ($itemRows as $it) {
        $itemId = (int)$it['id'];
        $itemIds[] = $itemId;

        $kind = (string)$it['kind'];
        $spoken = "Question {$it['idx']}. " . trim((string)$it['prompt']);

        if ($kind === 'yesno') {
            $spoken .= " Please answer yes or no, and briefly explain.";
        } elseif ($kind === 'mcq') {
            $spoken .= " Please answer with the correct phrase.";
        } else {
            $spoken .= " Please answer in a short spoken explanation.";
        }

        $localFile = $testDir . '/q_' . $itemId . '.mp3';
        tts_generate_local($spoken, $localFile);

        $presign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
            'test_id' => $testId,
            'kind'    => 'question',
            'item_id' => $itemId,
            'ext'     => 'mp3'
        ]);
        upload_file_to_presigned_put((string)$presign['url'], $localFile, 'audio/mpeg');

        $questionUrls[$itemId] = (string)$presign['public_url'];

        $doneAudio++;
        $pct = 58 + (int)floor(($doneAudio / $totalAudio) * 40);
        set_prepare_progress($pdo, $testId, $pct, 'Uploading audio ' . $doneAudio . ' of ' . $totalAudio . '...');
    }

    $manifest = [
        'test_id'         => $testId,
        'total_questions' => count($itemIds),
        'item_ids'        => $itemIds,
        'intro_url'       => $introUrl,
        'question_urls'   => $questionUrls
    ];

    $pdo->prepare("
        UPDATE progress_tests_v2
        SET status='ready',
            manifest_json=?,
            progress_pct=100,
            status_text='Progress test ready.',
            updated_at=NOW()
        WHERE id=?
    ")->execute([
        json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $testId
    ]);

    json_ok([
        'ok'              => true,
        'test_id'         => $testId,
        'total_questions' => count($itemIds),
        'item_ids'        => $itemIds,
        'intro_url'       => $introUrl,
        'question_urls'   => $questionUrls
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_ok([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}