<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
    $testDir = make_test_dir($testId);

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

    $schema = [
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
                        "prompt" => ["type" => "string"],
                        "options" => [
                            "type" => "array",
                            "items" => ["type" => "string"]
                        ],
                        "correct" => [
                            "type" => "object",
                            "additionalProperties" => false,
                            "properties" => [
                                "value" => ["type" => ["boolean", "null"]],
                                "index" => ["type" => ["integer", "null"]],
                                "type" => ["type" => ["string", "null"]],
                                "key_points" => [
                                    "type" => "array",
                                    "items" => ["type" => "string"]
                                ],
                                "min_points_to_pass" => ["type" => ["integer", "null"]]
                            ],
                            "required" => ["value", "index", "type", "key_points", "min_points_to_pass"]
                        ]
                    ],
                    "required" => ["kind", "prompt", "options", "correct"]
                ]
            ]
        ],
        "required" => ["questions"]
    ];

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            [
                "role" => "system",
                "content" => [
                    ["type" => "input_text", "text" =>
"You are generating an ORAL progress test for aviation training.

IMPORTANT:
- This is a spoken test, not a written one.
- Avoid questions that are best answered with only a single letter like A, B, C, or D.
- Avoid prompts where the student would naturally only say one letter.
- Prefer answers that are spoken naturally in short phrases or short explanations.
- Strongly prefer:
  1) yes/no questions answered with 'yes' or 'no' plus a short phrase,
  2) open questions,
  3) short spoken choice questions where the student says the actual concept, not the letter.

Examples of good oral questions:
- 'Is the role of the wings to produce lift? Answer yes or no, and briefly say why.'
- 'During landing, which touches first: the main wheels or the nose wheel?'
- 'Explain the role of the landing gear during takeoff and landing.'

Examples of bad oral questions:
- 'Which is correct: A, B, C, or D?'
- 'Answer only with the letter.'

Question mix target:
- around 4 yes/no
- around 2 spoken-choice mcq-style questions
- around 4 open questions

Rules:
- Use ONLY the lesson narration text as source of truth.
- Use summary only to detect misconceptions.
- For yesno:
  options should be []
  correct.value true/false
  correct.index null
  correct.type null
  correct.key_points []
  correct.min_points_to_pass null
- For mcq:
  Use this only for spoken-choice questions.
  The spoken answer should be the concept itself, not the letter.
  You may still store 2-4 options.
  correct.index should be 0..3
  correct.value null
  correct.type null
  correct.key_points []
  correct.min_points_to_pass null
- For open:
  options []
  correct.type rubric
  correct.key_points 3-6 strings
  correct.min_points_to_pass integer
  correct.value null
  correct.index null

Create exactly 10 questions."
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
                "name" => "ipca_questions",
                "schema" => $schema,
                "strict" => true
            ]
        ]
    ];

    $r = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($r);

    $q = is_array($j['questions'] ?? null) ? $j['questions'] : [];
    $q = clamp_questions($q, 10);

    if (count($q) < 3) {
        $q = [
            [
                'kind' => 'yesno',
                'prompt' => 'Is the checklist used to reduce errors? Answer yes or no, and briefly say why.',
                'options' => [],
                'correct' => [
                    'value' => true,
                    'index' => null,
                    'type' => null,
                    'key_points' => [],
                    'min_points_to_pass' => null
                ]
            ],
            [
                'kind' => 'mcq',
                'prompt' => 'Which is the main function of the wings: to produce lift or to provide braking?',
                'options' => ['produce lift', 'provide braking'],
                'correct' => [
                    'value' => null,
                    'index' => 0,
                    'type' => null,
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
                    'index' => null,
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
                'index' => null,
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

    $questionUrls = [];
    $itemIds = [];

    foreach ($itemRows as $it) {
        $itemId = (int)$it['id'];
        $itemIds[] = $itemId;

        $kind = (string)$it['kind'];
        $spoken = "Question {$it['idx']}. " . trim((string)$it['prompt']);

        $options = json_decode((string)($it['options_json'] ?? '[]'), true) ?: [];

        if ($kind === 'yesno') {
            // Keep it natural; prompt should already ask for a spoken yes/no response
            $spoken = rtrim($spoken, " \t\n\r\0\x0B") . ".";
        } elseif ($kind === 'mcq' && $options) {
            // Spoken-choice style: read the concepts, not letters as the expected answer
            $parts = [];
            foreach ($options as $opt) {
                $parts[] = trim((string)$opt);
            }
            if ($parts) {
                $spoken .= " Your answer should say the correct concept, not the letter. Choices are: " . implode(", or ", $parts) . ".";
            }
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