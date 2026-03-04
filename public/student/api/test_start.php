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

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    $mode = (string)($data['mode'] ?? '');
    $pin  = trim((string)($data['pin'] ?? ''));

    if ($cohortId <= 0 || $lessonId <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing cohort_id or lesson_id']); exit; }

    $userId = (int)$u['id'];

    if ($role === 'student') {
        $en = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not enrolled']);
            exit;
        }
    }

    // --- Policy enforcement (school_ip + PIN override) ---
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
                    echo json_encode(['ok'=>false,'code'=>'NEED_PIN','error'=>'PIN required outside school network']);
                    exit;
                }
                echo json_encode(['ok'=>false,'error'=>'Not allowed from this location']);
                exit;
            }
        }
    }

    if ($polMode === 'pin') {
        if (!empty($_SESSION['cw_pin_ok']) && $_SESSION['cw_pin_ok'] === '1') {
            // ok
        } else {
            if ($mode === 'check_pin') {
                if ($pin === '' || empty($pol['pin_hash'])) { echo json_encode(['ok'=>false,'error'=>'Invalid PIN']); exit; }
                if (!password_verify($pin, (string)$pol['pin_hash'])) { echo json_encode(['ok'=>false,'error'=>'Invalid PIN']); exit; }
                $_SESSION['cw_pin_ok'] = '1';
                echo json_encode(['ok'=>true]);
                exit;
            }
            echo json_encode(['ok'=>false,'code'=>'NEED_PIN','error'=>'PIN required']);
            exit;
        }
    }

    // --- Create progress_tests row (attempt) ---
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

    // Clear any items just in case
    $pdo->prepare("DELETE FROM progress_test_items WHERE test_id=?")->execute([$testId]);

    // --- Build 10 items: 1 info + 9 scored (yesno/mcq/open) ---
    // NOTE: kind must be in ENUM('info','yesno','mcq','open')
    $items = [];

    // idx=1 informational (not scored)
    $items[] = [
        'idx'=>1, 'kind'=>'info',
        'prompt'=>'We will begin now.',
        'options'=>[],
        'correct'=>['type'=>'none']
    ];

    // Simple + scenario mix (MVP)
    $items[] = ['idx'=>2,'kind'=>'yesno','prompt'=>'A Cessna 172 is an airplane. Yes or no?','options'=>[],'correct'=>['value'=>true]];
    $items[] = ['idx'=>3,'kind'=>'mcq','prompt'=>'Which part provides lift?','options'=>['Wings','Brakes','Seat','Spinner'],'correct'=>['index'=>0]];

    // OPEN (scorable via rubric)
    $items[] = [
        'idx'=>4,'kind'=>'open',
        'prompt'=>'Explain briefly what a checklist is used for.',
        'options'=>[],
        'correct'=>[
            'type'=>'rubric',
            'key_points'=>[
                'standardizes actions',
                'reduces errors / omissions',
                'ensures safety / verification',
            ],
            'min_points_to_pass'=>2
        ]
    ];

    $items[] = ['idx'=>5,'kind'=>'yesno','prompt'=>'The horizontal stabilizer is part of the tail. Yes or no?','options'=>[],'correct'=>['value'=>true]];
    $items[] = ['idx'=>6,'kind'=>'mcq','prompt'=>'What is the fuselage?','options'=>['Main body','A type of wing','A brake part','A tire'],'correct'=>['index'=>0]];

    $items[] = [
        'idx'=>7,'kind'=>'open',
        'prompt'=>'Scenario: You notice a crack in a spinner before flight. What should you do and why?',
        'options'=>[],
        'correct'=>[
            'type'=>'rubric',
            'key_points'=>[
                'do not fly / consult maintenance',
                'treat as airworthiness / safety issue',
                'document / report',
            ],
            'min_points_to_pass'=>2
        ]
    ];

    $items[] = ['idx'=>8,'kind'=>'mcq','prompt'=>'Which control surface primarily controls roll?','options'=>['Ailerons','Rudder','Elevator','Flaps'],'correct'=>['index'=>0]];
    $items[] = ['idx'=>9,'kind'=>'yesno','prompt'=>'Flaps increase lift and drag. Yes or no?','options'=>[],'correct'=>['value'=>true]];

    $items[] = [
        'idx'=>10,'kind'=>'open',
        'prompt'=>'In your own words, why do pilots use checklists even for familiar airplanes?',
        'options'=>[],
        'correct'=>[
            'type'=>'rubric',
            'key_points'=>[
                'humans forget / complacency',
                'consistency',
                'safety barriers',
            ],
            'min_points_to_pass'=>2
        ]
    ];

    // Insert items (NOTE: correct_answer_json is NOT NULL in your schema)
    $insI = $pdo->prepare("
      INSERT INTO progress_test_items
        (test_id, idx, kind, question_order, prompt, options_json, correct_json, correct_answer_json, student_json, student_answer_json, is_correct)
      VALUES
        (?,?,?,?,?,?,?,?,?,?,NULL)
    ");

    foreach ($items as $it) {
        $kind = (string)$it['kind'];

        $options = $it['options'] ?? [];
        if (!is_array($options)) $options = [];

        $correct = $it['correct'] ?? [];
        if (!is_array($correct)) $correct = [];

        // Ensure NOT NULL correct_answer_json for ALL types
        // - yesno/mcq/open use $correct
        // - info uses {"type":"none"}
        if ($kind === 'info' && empty($correct)) {
            $correct = ['type'=>'none'];
        }

        $correctJson = json_encode($correct, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($correctJson === false) $correctJson = '{}';

        $optsJson = json_encode($options, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($optsJson === false) $optsJson = '[]';

        $insI->execute([
            $testId,
            (int)$it['idx'],
            $kind,
            (int)$it['idx'],
            (string)$it['prompt'],
            $optsJson,
            $correctJson,
            $correctJson,
            null,
            null
        ]);
    }

    // First scored item (skip info idx=1)
    $first = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? AND idx>=2 ORDER BY idx ASC LIMIT 1");
    $first->execute([$testId]);
    $f = $first->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        echo json_encode(['ok'=>false,'error'=>'No items generated']);
        exit;
    }

    // Next id for prefetch
    $next = $pdo->prepare("SELECT id FROM progress_test_items WHERE test_id=? AND idx>? ORDER BY idx ASC LIMIT 1");
    $next->execute([$testId, (int)$f['idx']]);
    $nextId = (int)($next->fetchColumn() ?: 0);

    // total questions = scored count (exclude info)
    $totalQStmt = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq','open')");
    $totalQStmt->execute([$testId]);
    $totalQ = (int)$totalQStmt->fetchColumn();

    echo json_encode([
      'ok'=>true,
      'test_id'=>$testId,
      'total_questions'=>$totalQ,
      'next_item_id'=>$nextId ?: null,
      'item'=>[
        'item_id'=>(int)$f['id'],
        'idx'=>(int)$f['idx'],
        'kind'=>(string)$f['kind'],
        'prompt'=>(string)$f['prompt'],
        'options'=> json_decode((string)$f['options_json'], true) ?: []
      ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}