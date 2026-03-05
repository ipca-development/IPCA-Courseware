<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function client_ip(): string {
    $h = $_SERVER;
    $cands = [];
    if (!empty($h['HTTP_CF_CONNECTING_IP'])) $cands[] = $h['HTTP_CF_CONNECTING_IP'];
    if (!empty($h['HTTP_X_REAL_IP'])) $cands[] = $h['HTTP_X_REAL_IP'];
    if (!empty($h['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $h['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $p) $cands[] = trim($p);
    }
    if (!empty($h['REMOTE_ADDR'])) $cands[] = $h['REMOTE_ADDR'];
    foreach ($cands as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

function ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    if (strpos($cidr,'/') === false) $cidr .= '/32';

    [$net, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;

    if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($net, FILTER_VALIDATE_IP)) return false;
    if ($mask < 0 || $mask > 32) return false;

    $ipLong  = ip2long($ip);
    $netLong = ip2long($net);
    if ($ipLong === false || $netLong === false) return false;

    $wild = (1 << (32 - $mask)) - 1;
    $maskLong = ~$wild;

    return (($ipLong & $maskLong) === ($netLong & $maskLong));
}

function policy_row(PDO $pdo, int $cohortId, int $userId): array {
    // user override
    $st = $pdo->prepare("SELECT * FROM progress_test_access_policy WHERE scope_type='user' AND scope_id=? LIMIT 1");
    $st->execute([$userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;

    // cohort override
    $st = $pdo->prepare("SELECT * FROM progress_test_access_policy WHERE scope_type='cohort' AND scope_id=? LIMIT 1");
    $st->execute([$cohortId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;

    // global
    $st = $pdo->query("SELECT * FROM progress_test_access_policy WHERE scope_type='global' AND scope_id IS NULL LIMIT 1");
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: ['mode'=>'any','allowed_cidrs'=>null,'pin_hash'=>null];
}

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
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
        json_ok(['ok'=>false,'error'=>'Forbidden']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) json_ok(['ok'=>false,'error'=>'Invalid JSON']);

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    $mode = (string)($data['mode'] ?? '');
    $pin  = trim((string)($data['pin'] ?? ''));

    if ($cohortId <= 0 || $lessonId <= 0) json_ok(['ok'=>false,'error'=>'Missing cohort_id or lesson_id']);

    $userId = (int)$u['id'];

    if ($role === 'student') {
        $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            http_response_code(403);
            json_ok(['ok'=>false,'error'=>'Not enrolled']);
        }
    }

    // --------------------------
    // Policy enforcement (school_ip + PIN override)
    // --------------------------
    $pol = policy_row($pdo, $cohortId, $userId);
    $polMode = (string)($pol['mode'] ?? 'any');

    if ($polMode === 'school_ip') {
        if (!empty($_SESSION['cw_pin_ok']) && $_SESSION['cw_pin_ok'] === '1') {
            // ok
        } else {
            $cidrs = (string)($pol['allowed_cidrs'] ?? '');
            $ip = client_ip();

            $allowed = false;
            foreach (explode(',', $cidrs) as $c) {
                $c = trim($c);
                if ($c === '') continue;
                if (ip_in_cidr($ip, $c)) { $allowed = true; break; }
            }

            if (!$allowed) {
                if (!empty($pol['pin_hash'])) {
                    json_ok(['ok'=>false,'code'=>'NEED_PIN','error'=>'PIN required outside school network']);
                }
                json_ok(['ok'=>false,'error'=>'Not allowed from this location']);
            }
        }
    }

    if ($polMode === 'pin') {
        if (!empty($_SESSION['cw_pin_ok']) && $_SESSION['cw_pin_ok'] === '1') {
            // ok
        } else {
            if ($mode === 'check_pin') {
                if ($pin === '' || empty($pol['pin_hash'])) json_ok(['ok'=>false,'error'=>'Invalid PIN']);
                if (!password_verify($pin, (string)$pol['pin_hash'])) json_ok(['ok'=>false,'error'=>'Invalid PIN']);
                $_SESSION['cw_pin_ok'] = '1';
                json_ok(['ok'=>true]);
            }
            json_ok(['ok'=>false,'code'=>'NEED_PIN','error'=>'PIN required']);
        }
    }

    // --------------------------
    // Create progress_tests row (attempt)
    // --------------------------
    $mx = $pdo->prepare("SELECT MAX(attempt) FROM progress_tests WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $mx->execute([$userId, $cohortId, $lessonId]);
    $attempt = (int)$mx->fetchColumn() + 1;
    if ($attempt <= 0) $attempt = 1;

    $seed = bin2hex(random_bytes(16));

    $ins = $pdo->prepare("
      INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, seed, started_at)
      VALUES (?,?,?,?, 'in_progress', ?, NOW())
    ");
    $ins->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
    $testId = (int)$pdo->lastInsertId();

    // Ensure clean slate
    $pdo->prepare("DELETE FROM progress_test_items WHERE test_id=?")->execute([$testId]);

    // --------------------------
    // LOAD TRUTH (narration scripts)
    // --------------------------
    $nq = $pdo->prepare("
      SELECT s.page_number, e.narration_en
      FROM slides s
      JOIN slide_enrichment e ON e.slide_id = s.id
      WHERE s.lesson_id=? AND s.is_deleted=0 AND e.narration_en IS NOT NULL AND e.narration_en <> ''
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

    // --------------------------
    // LOAD student summary (claims / misconceptions only)
    // --------------------------
    $sq = $pdo->prepare("
      SELECT summary_plain
      FROM lesson_summaries
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      LIMIT 1
    ");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summaryPlain = trim((string)($sq->fetchColumn() ?: ''));
    if ($summaryPlain === '') $summaryPlain = "(No student summary.)";

    // --------------------------
    // AI: generate questions (truth-first)
    // STRICT SCHEMA: additionalProperties=false everywhere
    // correct object always contains the same keys to avoid conditional schema issues.
    // --------------------------
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
- Keep prompts concise (spoken).
- Avoid "What is your answer <name>" in prompt; the TTS layer handles that.
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
          "name"=>"ipca_progress_test_q_v1",
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
        // fail-safe minimal questions
        $questions = [
          ["kind"=>"yesno","prompt"=>"Based on the lesson, is the checklist used to reduce errors?","options"=>[],"correct"=>["value"=>true,"index"=>null,"type"=>"none","key_points"=>[],"min_points_to_pass"=>1]],
          ["kind"=>"mcq","prompt"=>"Based on the lesson, which part is primarily responsible for lift?","options"=>["Wings","Brakes","Seat","Spinner"],"correct"=>["value"=>null,"index"=>0,"type"=>"none","key_points"=>[],"min_points_to_pass"=>1]],
          ["kind"=>"open","prompt"=>"Explain why pilots use checklists.","options"=>[],"correct"=>["value"=>null,"index"=>null,"type"=>"rubric","key_points"=>["reduce errors","standardize","safety"],"min_points_to_pass"=>2]],
        ];
    }

    // --------------------------
    // Insert questions into progress_test_items
    // --------------------------
    $insI = $pdo->prepare("
      INSERT INTO progress_test_items
        (test_id, idx, kind, question_order, prompt, options_json, correct_json, correct_answer_json, student_json, student_answer_json, is_correct)
      VALUES
        (?,?,?,?,?,?,?,?,?,?,NULL)
    ");

    $idx = 1;
    foreach ($questions as $q) {
        $k = (string)($q['kind'] ?? 'yesno');
        $prompt = trim((string)($q['prompt'] ?? ''));
        if ($prompt === '') $prompt = "Question {$idx}.";

        $options = $q['options'] ?? [];
        if (!is_array($options)) $options = [];

        $correct = $q['correct'] ?? [];
        if (!is_array($correct)) $correct = ["value"=>null,"index"=>null,"type"=>"none","key_points"=>[],"min_points_to_pass"=>1];

        // Normalize to DB-safe structures
        if ($k === 'yesno') {
            $correct = [
              "value" => (bool)($correct['value'] ?? false),
              "index" => null,
              "type"  => "none",
              "key_points" => [],
              "min_points_to_pass" => 1
            ];
            $options = [];
        } elseif ($k === 'mcq') {
            $opts = [];
            foreach ($options as $o) { $o = trim((string)$o); if ($o !== '') $opts[] = $o; }
            while (count($opts) < 4) $opts[] = "Option " . (count($opts)+1);
            if (count($opts) > 4) $opts = array_slice($opts, 0, 4);
            $options = $opts;

            $ci = (int)($correct['index'] ?? 0);
            if ($ci < 0) $ci = 0;
            if ($ci > 3) $ci = 3;

            $correct = [
              "value" => null,
              "index" => $ci,
              "type"  => "none",
              "key_points" => [],
              "min_points_to_pass" => 1
            ];
        } else { // open
            $kp = $correct['key_points'] ?? [];
            if (!is_array($kp)) $kp = [];
            $kpClean = [];
            foreach ($kp as $p) { $p = trim((string)$p); if ($p !== '') $kpClean[] = $p; }
            if (!$kpClean) $kpClean = ["key point 1", "key point 2", "key point 3"];

            $minPts = (int)($correct['min_points_to_pass'] ?? 2);
            if ($minPts < 1) $minPts = 1;

            $correct = [
              "value" => null,
              "index" => null,
              "type"  => "rubric",
              "key_points" => $kpClean,
              "min_points_to_pass" => $minPts
            ];
            $options = [];
        }

        $optsJson = json_encode($options, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($optsJson === false) $optsJson = '[]';

        $corrJson = json_encode($correct, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($corrJson === false) $corrJson = '{}';

        $insI->execute([
          $testId,
          $idx,
          $k,
          $idx,
          $prompt,
          $optsJson,
          $corrJson,
          $corrJson,
          null,
          null
        ]);

        $idx++;
        if ($idx > 10) break;
    }

    // First item
    $first = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? ORDER BY idx ASC LIMIT 1");
    $first->execute([$testId]);
    $f = $first->fetch(PDO::FETCH_ASSOC);
    if (!$f) json_ok(['ok'=>false,'error'=>'No items generated']);

    // Next id for prefetch
    $next = $pdo->prepare("SELECT id FROM progress_test_items WHERE test_id=? AND idx>? ORDER BY idx ASC LIMIT 1");
    $next->execute([$testId, (int)$f['idx']]);
    $nextId = (int)($next->fetchColumn() ?: 0);

    json_ok([
      'ok'=>true,
      'test_id'=>$testId,
      'total_questions'=>10,
      'next_item_id'=>$nextId ?: null,
      'item'=>[
        'item_id'=>(int)$f['id'],
        'idx'=>(int)$f['idx'],
        'kind'=>(string)$f['kind'],
        'prompt'=>(string)$f['prompt'],
        'options'=> json_decode((string)($f['options_json'] ?? '[]'), true) ?: []
      ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}