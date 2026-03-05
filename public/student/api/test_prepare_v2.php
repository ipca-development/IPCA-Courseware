<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        throw new RuntimeException("Cannot create directory: " . $dir);
    }
}

function storage_base_dir(): string {
    return dirname(__DIR__, 3) . '/storage/progress_tests_v2';
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

function tts_write_file(string $apiKey, string $model, string $voice, string $text, string $outfile): void {
    $payload = json_encode([
        'model' => $model,
        'voice' => $voice,
        'format' => 'mp3',
        'input' => $text,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $audio = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("TTS failed (HTTP {$code}) {$err}");
    }

    if (@file_put_contents($outfile, $audio) === false) {
        throw new RuntimeException("Failed to write audio file: {$outfile}");
    }
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_out(['ok'=>false,'error'=>'Forbidden']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) json_out(['ok'=>false,'error'=>'Invalid JSON']);

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);

    if ($cohortId <= 0 || $lessonId <= 0) {
        json_out(['ok'=>false,'error'=>'Missing cohort_id or lesson_id']);
    }

    $userId = (int)$u['id'];

    if ($role === 'student') {
        $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            http_response_code(403);
            json_out(['ok'=>false,'error'=>'Not enrolled']);
        }
    }

    $mx = $pdo->prepare("SELECT MAX(attempt) FROM progress_tests_v2 WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $attempt = (int)$mx->fetchColumn() + 1;
    if ($attempt <= 0) $attempt = 1;

    $seed = bin2hex(random_bytes(16));

    $ins = $pdo->prepare("
      INSERT INTO progress_tests_v2
      (user_id, cohort_id, lesson_id, attempt, status, seed, started_at)
      VALUES (?,?,?,?, 'preparing', ?, NOW())
    ");
    $ins->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
    $testId = (int)$pdo->lastInsertId();

    // Truth source
    $nq = $pdo->prepare("
      SELECT s.page_number, e.narration_en
      FROM slides s
      JOIN slide_enrichment e ON e.slide_id = s.id
      WHERE s.lesson_id=? AND s.is_deleted=0
        AND e.narration_en IS NOT NULL AND e.narration_en <> ''
      ORDER BY s.page_number ASC
    ");
    $nq->execute([$lessonId]);
    $nrows = $nq->fetchAll(PDO::FETCH_ASSOC);

    $truthBlocks = [];
    foreach ($nrows as $r) {
        $pg = (int)($r['page_number'] ?? 0);
        $tx = trim((string)($r['narration_en'] ?? ''));
        if ($tx !== '') $truthBlocks[] = "Slide {$pg}: {$tx}";
    }
    $truthText = implode("\n\n", $truthBlocks);
    if ($truthText === '') $truthText = "(No narration scripts available.)";

    $sq = $pdo->prepare("
      SELECT summary_plain
      FROM lesson_summaries
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      LIMIT 1
    ");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summaryPlain = trim((string)($sq->fetchColumn() ?: ''));
    if ($summaryPlain === '') $summaryPlain = "(No student summary.)";

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
              "kind"   => ["type"=>"string","enum"=>["yesno","mcq","open"]],
              "prompt" => ["type"=>"string"],
              "options"=> ["type"=>"array","items"=>["type"=>"string"]],
              "correct"=> [
                "type"=>"object",
                "additionalProperties"=>false,
                "properties"=>[
                  "value"=>["type"=>["boolean","null"]],
                  "index"=>["type"=>["integer","null"]],
                  "type"=>["type"=>"string","enum"=>["rubric","none"]],
                  "key_points"=>["type"=>"array","items"=>["type"=>"string"]],
                  "min_points_to_pass"=>["type"=>"integer"]
                ],
                "required"=>["value","index","type","key_points","min_points_to_pass"]
              ]
            ],
            "required" => ["kind","prompt","options","correct"]
          ]
        ]
      ],
      "required" => ["questions"]
    ];

    $instructions = <<<TXT
You generate a strict oral progress test for a flight training student.

SOURCE OF TRUTH HIERARCHY:
1) LESSON NARRATION is the ONLY truth source.
2) STUDENT SUMMARY is NOT truth. Use it only to detect misconceptions to probe.

GOAL:
Generate exactly 10 questions, all derived ONLY from the LESSON NARRATION.
Questions must be scenario-based, realistic, and test understanding.

TYPES:
- yesno: options must be [] ; correct.value must be true/false ; correct.index must be null ; correct.type="none" ; key_points=[]
- mcq: exactly 4 options ; correct.index 0..3 ; correct.value null ; correct.type="none" ; key_points=[]
- open: options must be [] ; correct.type="rubric" ; correct.key_points (3-6 strings) ; correct.min_points_to_pass usually 2 ; correct.value null ; correct.index null

RULES:
- Do NOT introduce new facts not supported by narration.
- If summary contains a misconception, ask a question that probes it against narration.
- Keep prompts concise and spoken-friendly.
- Return JSON matching schema EXACTLY.
TXT;

    $payload = [
      "model" => cw_openai_model(),
      "input" => [
        ["role"=>"system","content"=>[
          ["type"=>"input_text","text"=>$instructions]
        ]],
        ["role"=>"user","content"=>[
          ["type"=>"input_text","text"=>
"LESSON NARRATION (TRUTH):\n{$truthText}\n\nSTUDENT SUMMARY (NOT TRUTH):\n{$summaryPlain}\n\nGenerate the 10 questions now."
          ]
        ]]
      ],
      "text" => [
        "format" => [
          "type"=>"json_schema",
          "name"=>"ipca_progress_test_q_v2",
          "schema"=>$schema,
          "strict"=>true
        ]
      ],
      "temperature" => 0.2
    ];

    $resp = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($resp);

    $questions = is_array($j['questions'] ?? null) ? $j['questions'] : [];
    $questions = clamp_questions($questions, 10);

    if (count($questions) < 3) {
        $questions = [
          ["kind"=>"yesno","prompt"=>"Based on the lesson, is the checklist used to reduce errors?","options"=>[],"correct"=>["value"=>true,"index"=>null,"type"=>"none","key_points"=>[],"min_points_to_pass"=>1]],
          ["kind"=>"mcq","prompt"=>"Based on the lesson, which part is primarily responsible for lift?","options"=>["Wings","Brakes","Seat","Spinner"],"correct"=>["value"=>null,"index"=>0,"type"=>"none","key_points"=>[],"min_points_to_pass"=>1]],
          ["kind"=>"open","prompt"=>"Explain why pilots use checklists.","options"=>[],"correct"=>["value"=>null,"index"=>null,"type"=>"rubric","key_points"=>["reduce errors","standardize","safety"],"min_points_to_pass"=>2]],
        ];
    }

    $insI = $pdo->prepare("
      INSERT INTO progress_test_items_v2
      (test_id, idx, kind, prompt, options_json, correct_json)
      VALUES (?,?,?,?,?,?)
    ");

    $items = [];
    $idx = 1;
    foreach ($questions as $q) {
        $kind = (string)($q['kind'] ?? 'yesno');
        $prompt = trim((string)($q['prompt'] ?? ''));
        if ($prompt === '') $prompt = "Question {$idx}.";

        $options = $q['options'] ?? [];
        if (!is_array($options)) $options = [];

        $correct = $q['correct'] ?? [];
        if (!is_array($correct)) $correct = ["value"=>null,"index"=>null,"type"=>"none","key_points"=>[],"min_points_to_pass"=>1];

        $optsJson = json_encode($options, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $corrJson = json_encode($correct, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $insI->execute([$testId, $idx, $kind, $prompt, $optsJson ?: '[]', $corrJson ?: '{}']);

        $items[] = [
            'idx' => $idx,
            'kind' => $kind,
            'prompt' => $prompt,
            'options' => $options,
        ];
        $idx++;
        if ($idx > 10) break;
    }

    $baseDir = storage_base_dir() . '/' . $testId;
    $answersDir = $baseDir . '/answers';
    ensure_dir(storage_base_dir());
    ensure_dir($baseDir);
    ensure_dir($answersDir);

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) $apiKey = getenv('CW_OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('Missing OPENAI_API_KEY');

    $ttsModel = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $ttsVoice = getenv('CW_OPENAI_TTS_VOICE') ?: 'alloy';

    $name = trim((string)($u['name'] ?? 'student'));
    if ($name === '') $name = 'student';
    $parts = preg_split('/\s+/', $name);
    $firstName = trim((string)($parts[0] ?? 'student'));

    $introText = "Okay {$firstName}. I will now conduct your intermediate progress test to check your understanding. I will ask you several questions. When you are ready, press the push to talk button to begin answering after each question.";

    tts_write_file($apiKey, $ttsModel, $ttsVoice, $introText, $baseDir . '/intro.mp3');

    $manifest = [
        'test_id' => $testId,
        'intro' => '/student/api/test_audio_v2.php?test_id=' . $testId . '&kind=intro',
        'items' => [],
        'status' => 'ready'
    ];

    foreach ($items as $it) {
        $qIdx = (int)$it['idx'];
        $kind = (string)$it['kind'];
        $prompt = (string)$it['prompt'];
        $options = is_array($it['options']) ? $it['options'] : [];

        $spoken = "Question {$qIdx}. {$prompt}";
        if ($kind === 'yesno') {
            $spoken = rtrim($spoken, " \t\n\r\0\x0B?.") . ", yes or no?";
        } elseif ($kind === 'mcq') {
            $letters = ['A','B','C','D'];
            $partsSpoken = [];
            foreach ($options as $i => $opt) {
                if ($i > 3) break;
                $partsSpoken[] = $letters[$i] . ". " . trim((string)$opt);
            }
            if ($partsSpoken) $spoken .= " " . implode(". ", $partsSpoken) . ".";
        } else {
            $spoken .= " What is your answer, {$firstName}?";
        }

        $fname = 'q' . str_pad((string)$qIdx, 2, '0', STR_PAD_LEFT) . '.mp3';
        tts_write_file($apiKey, $ttsModel, $ttsVoice, $spoken, $baseDir . '/' . $fname);

        $manifest['items'][] = [
            'idx' => $qIdx,
            'kind' => $kind,
            'audio' => '/student/api/test_audio_v2.php?test_id=' . $testId . '&kind=item&idx=' . $qIdx,
        ];
    }

    @file_put_contents($baseDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

    $up = $pdo->prepare("UPDATE progress_tests_v2 SET status='ready', manifest_json=? WHERE id=?");
    $up->execute([json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $testId]);

    json_out([
        'ok' => true,
        'test_id' => $testId,
        'manifest' => $manifest
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_out(['ok'=>false,'error'=>$e->getMessage()]);
}