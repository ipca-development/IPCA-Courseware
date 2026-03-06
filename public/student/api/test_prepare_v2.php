<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function storage_base_dir(): string {
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
    $dir = storage_base_dir() . '/' . $testId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Test storage directory is not writable: ' . $dir);
    }
    $answersDir = $dir . '/answers';
    if (!is_dir($answersDir)) {
        @mkdir($answersDir, 0777, true);
    }
    return $dir;
}

function tts_generate(string $text, string $file): void {
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
        CURLOPT_TIMEOUT => 120
    ]);

    $audio = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("TTS failed (HTTP {$code}) {$err}");
    }

    if (@file_put_contents($file, $audio) === false) {
        throw new RuntimeException('Failed to write audio file: ' . $file);
    }
}

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_ok(['ok' => false, 'error' => 'Forbidden']);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
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
        VALUES (?,?,?,?, 'in_progress', ?, NOW())
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
        "properties" => [
            "questions" => [
                "type" => "array",
                "items" => [
                    "type" => "object",
                    "properties" => [
                        "kind"    => ["type" => "string"],
                        "prompt"  => ["type" => "string"],
                        "options" => ["type" => "array", "items" => ["type" => "string"]],
                        "correct" => ["type" => "object"]
                    ],
                    "required" => ["kind", "prompt", "options", "correct"],
                    "additionalProperties" => false
                ]
            ]
        ],
        "required" => ["questions"],
        "additionalProperties" => false
    ];

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            [
                "role" => "system",
                "content" => [
                    ["type" => "input_text", "text" =>
"Create exactly 10 oral flight training questions.
Use ONLY the lesson narration text.
Use summary only to detect misconceptions.
Mix yesno, mcq and open questions."
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
                'prompt' => 'Based on the lesson, is the checklist used to reduce errors?',
                'options' => [],
                'correct' => ['value' => true]
            ],
            [
                'kind' => 'mcq',
                'prompt' => 'Based on the lesson, which part is primarily responsible for lift?',
                'options' => ['Wings', 'Brakes', 'Seat', 'Spinner'],
                'correct' => ['index' => 0]
            ],
            [
                'kind' => 'open',
                'prompt' => 'Explain why pilots use checklists.',
                'options' => [],
                'correct' => ['type' => 'rubric', 'key_points' => ['reduce errors', 'standardize', 'safety'], 'min_points_to_pass' => 2]
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

        $options = json_encode($qq['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $correct = json_encode($qq['correct'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

    $name = trim((string)($u['name'] ?? 'student'));
    if ($name === '') $name = 'student';
    $parts = preg_split('/\s+/', $name);
    $firstName = trim((string)($parts[0] ?? 'student'));

    $intro = "Hello {$firstName}. I will now conduct your progress test.";
    tts_generate($intro, $testDir . "/intro.mp3");

    $items = $pdo->prepare("
        SELECT id, idx, prompt, kind, options_json
        FROM progress_test_items_v2
        WHERE test_id=?
        ORDER BY idx
    ");
    $items->execute([$testId]);

    foreach ($items as $it) {
        $kind = (string)$it['kind'];
        $spoken = "Question {$it['idx']}. " . (string)$it['prompt'];

        $options = json_decode((string)($it['options_json'] ?? '[]'), true) ?: [];

        if ($kind === 'yesno') {
            $spoken = rtrim($spoken, " \t\n\r\0\x0B?.") . ", yes or no?";
        } elseif ($kind === 'mcq' && $options) {
            $letters = ['A', 'B', 'C', 'D'];
            $parts = [];
            foreach ($options as $i => $opt) {
                if ($i > 3) break;
                $parts[] = $letters[$i] . ". " . trim((string)$opt);
            }
            if ($parts) $spoken .= " " . implode(". ", $parts) . ".";
        } else {
            $spoken .= " What is your answer, {$firstName}?";
        }

        $file = $testDir . "/q_" . $it['id'] . ".mp3";
        tts_generate($spoken, $file);
    }

    $itemIds = [];
    $items2 = $pdo->prepare("
        SELECT id
        FROM progress_test_items_v2
        WHERE test_id=?
        ORDER BY idx
    ");
    $items2->execute([$testId]);
    foreach ($items2->fetchAll(PDO::FETCH_COLUMN) as $iid) {
        $itemIds[] = (int)$iid;
    }

    $manifest = [
        'test_id' => $testId,
        'total_questions' => count($itemIds),
        'item_ids' => $itemIds
    ];
    $pdo->prepare("UPDATE progress_tests_v2 SET manifest_json=?, updated_at=NOW() WHERE id=?")
        ->execute([json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $testId]);

    json_ok([
        'ok' => true,
        'test_id' => $testId,
        'total_questions' => count($itemIds),
        'item_ids' => $itemIds
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_ok([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}