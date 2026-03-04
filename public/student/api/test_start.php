<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid JSON']);
        exit;
    }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    if ($cohortId <= 0 || $lessonId <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Missing cohort_id or lesson_id']);
        exit;
    }

    $userId = (int)$u['id'];

    // Student must be enrolled + lesson in schedule
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $chk->execute([$cohortId, $userId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not enrolled']);
            exit;
        }

        $chk2 = $pdo->prepare("SELECT 1 FROM cohort_lesson_deadlines WHERE cohort_id=? AND lesson_id=? LIMIT 1");
        $chk2->execute([$cohortId, $lessonId]);
        if (!$chk2->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Lesson not in cohort']);
            exit;
        }
    }

    // Helpers
    $firstItemForTest = function(int $testId) use ($pdo) {
        $q = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? ORDER BY idx ASC LIMIT 1");
        $q->execute([$testId]);
        $it = $q->fetch(PDO::FETCH_ASSOC);
        if (!$it) throw new RuntimeException('No items found for test');

        return [
            'item_id' => (int)$it['id'],
            'idx' => (int)$it['idx'],
            'kind' => (string)$it['kind'],
            'prompt' => (string)$it['prompt'],
            'options' => json_decode((string)($it['options_json'] ?? 'null'), true) ?: []
        ];
    };

    $pdo->beginTransaction();

    // 1) Resume existing in_progress test if present
    $resume = $pdo->prepare("
      SELECT id
      FROM progress_tests
      WHERE user_id=? AND cohort_id=? AND lesson_id=? AND status='in_progress'
      ORDER BY id DESC
      LIMIT 1
    ");
    $resume->execute([$userId, $cohortId, $lessonId]);
    $existingTestId = (int)($resume->fetchColumn() ?: 0);

    if ($existingTestId > 0) {
        // If already has items, just return first
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=?");
        $cnt->execute([$existingTestId]);
        if ((int)$cnt->fetchColumn() > 0) {
            $item = $firstItemForTest($existingTestId);
            $pdo->commit();
            echo json_encode(['ok'=>true,'test_id'=>$existingTestId,'item'=>$item]);
            exit;
        }
        // else rebuild below using same test_id
        $testId = $existingTestId;
    } else {
        // 2) Create new attempt safely
        $mx = $pdo->prepare("
          SELECT COALESCE(MAX(attempt),0)
          FROM progress_tests
          WHERE user_id=? AND cohort_id=? AND lesson_id=?
          FOR UPDATE
        ");
        $mx->execute([$userId, $cohortId, $lessonId]);
        $attempt = (int)$mx->fetchColumn() + 1;

        if ($role === 'student' && $attempt > 3) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'No attempts left']);
            exit;
        }

        $seed = bin2hex(random_bytes(16));
        $ins = $pdo->prepare("
          INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, seed, started_at)
          VALUES (?,?,?,?, 'in_progress', ?, NOW())
        ");
        $ins->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
        $testId = (int)$pdo->lastInsertId();
    }

    // 3) Load lesson narration scripts (EN)
    $nq = $pdo->prepare("
      SELECT s.page_number, e.narration_en
      FROM slides s
      LEFT JOIN slide_enrichment e ON e.slide_id = s.id
      WHERE s.lesson_id=? AND s.is_deleted=0
      ORDER BY s.page_number ASC
    ");
    $nq->execute([$lessonId]);
    $rows = $nq->fetchAll(PDO::FETCH_ASSOC);

    $lessonNarr = [];
    foreach ($rows as $r) {
        $pg = (int)($r['page_number'] ?? 0);
        $tx = trim((string)($r['narration_en'] ?? ''));
        if ($tx !== '') {
            $lessonNarr[] = "Slide {$pg}: {$tx}";
        }
    }
    $lessonNarrText = implode("\n\n", $lessonNarr);

    // 4) Load student summary (EN plain)
    $sumQ = $pdo->prepare("
      SELECT summary_plain
      FROM lesson_summaries
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      LIMIT 1
    ");
    $sumQ->execute([$userId, $cohortId, $lessonId]);
    $summaryPlain = trim((string)($sumQ->fetchColumn() ?: ''));

    // If there is no narration yet (should not happen), fail gracefully
    if ($lessonNarrText === '') {
        $lessonNarrText = "(No narration scripts found yet for this lesson.)";
    }
    if ($summaryPlain === '') {
        $summaryPlain = "(Student summary not provided yet.)";
    }

    // 5) Generate questions with AI (strict schema)
    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "questions" => [
                "type" => "array",
                "minItems" => 6,
                "maxItems" => 10,
                "items" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "properties" => [
                        "kind" => ["type"=>"string", "enum"=>["yesno","mcq"]],
                        "prompt" => ["type"=>"string"],
                        "options" => [
                            "type"=>"array",
                            "items"=>["type"=>"string"]
                        ],
                        "correct" => [
                            "type"=>"object",
                            "additionalProperties"=>false,
                            "properties" => [
                                "value" => ["type"=>"boolean"],
                                "index" => ["type"=>"integer"]
                            ],
                            "required" => ["value","index"]
                        ]
                    ],
                    "required" => ["kind","prompt","options","correct"]
                ]
            ]
        ],
        "required" => ["questions"]
    ];

    $instructions = <<<TXT
You are generating an oral-style Progress Test for a flight training lesson.

You MUST ONLY use the provided lesson narration scripts and the student's own lesson summary.
Do NOT introduce any outside facts, even if you know them.

Goals:
- Focus on true understanding and scenario reasoning.
- Be strict and specific.
- Keep it doable in 10 minutes total.
- Generate 6 to 10 questions.

Question types:
- yesno: options must be [] and correct.value must be true/false (use correct.index = -1)
- mcq: provide EXACTLY 4 options. correct.index is 0-3, correct.value must be true (set it true always for mcq)

Output must match the schema exactly.
TXT;

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role"=>"system","content"=>[
                ["type"=>"input_text","text"=>$instructions]
            ]],
            ["role"=>"user","content"=>[
                ["type"=>"input_text","text"=>"LESSON NARRATION SCRIPTS (EN):\n".$lessonNarrText],
                ["type"=>"input_text","text"=>"STUDENT SUMMARY (EN):\n".$summaryPlain],
                ["type"=>"input_text","text"=>"Generate the Progress Test questions now."]
            ]]
        ],
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "progress_test_questions_v1",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.2
    ];

    $ai = cw_openai_responses($payload);
    $out = cw_openai_extract_json_text($ai);

    $questions = is_array($out['questions'] ?? null) ? $out['questions'] : [];
    if (count($questions) < 1) {
        throw new RuntimeException("AI returned no questions");
    }

    // 6) Build items table for this test
    $pdo->prepare("DELETE FROM progress_test_items WHERE test_id=?")->execute([$testId]);

    $insItem = $pdo->prepare("
      INSERT INTO progress_test_items
        (test_id, idx, question_order, kind, prompt, options_json, correct_json, correct_answer_json,
         student_json, student_answer_json, is_correct, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NOW(), NOW())
    ");

    $idx = 1;
    foreach ($questions as $q) {
        $kind = (string)($q['kind'] ?? '');
        $prompt = trim((string)($q['prompt'] ?? ''));

        if ($prompt === '') continue;

        $options = $q['options'] ?? [];
        if (!is_array($options)) $options = [];

        $correct = $q['correct'] ?? ['value'=>false,'index'=>-1];
        if (!is_array($correct)) $correct = ['value'=>false,'index'=>-1];

        if ($kind === 'yesno') {
            $optionsJson = null;
            $correct['index'] = -1;
        } else {
            // mcq → enforce 4 options
            $options = array_values($options);
            while (count($options) < 4) $options[] = "Option";
            if (count($options) > 4) $options = array_slice($options, 0, 4);
            $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $correct['value'] = true;
            if (!isset($correct['index'])) $correct['index'] = 0;
        }

        $correctJson = json_encode($correct, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($correctJson === false) $correctJson = '{}';

        // Your schema requires correct_answer_json in some setups
        $correctAnswerJson = $correctJson;

        $insItem->execute([
            $testId,
            $idx,
            $idx,
            $kind,
            $prompt,
            $optionsJson,
            $correctJson,
            $correctAnswerJson
        ]);

        $idx++;
        if ($idx > 10) break; // hard cap safety
    }

    $item = $firstItemForTest($testId);

    $pdo->commit();

    echo json_encode(['ok'=>true,'test_id'=>$testId,'item'=>$item]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}