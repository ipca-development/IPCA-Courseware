<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

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

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
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

    // Students must be enrolled in the cohort
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $chk->execute([$cohortId, $userId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not enrolled']);
            exit;
        }

        // Cohort must include this lesson
        $chk2 = $pdo->prepare("SELECT 1 FROM cohort_lesson_deadlines WHERE cohort_id=? AND lesson_id=? LIMIT 1");
        $chk2->execute([$cohortId, $lessonId]);
        if (!$chk2->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Lesson not in cohort']);
            exit;
        }
    }

    // Enforce attempt limit (3 for now)
    $max = $pdo->prepare("SELECT MAX(attempt) FROM progress_tests WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $max->execute([$userId, $cohortId, $lessonId]);
    $maxAttempt = (int)($max->fetchColumn() ?: 0);
    $attempt = $maxAttempt + 1;
    if ($role === 'student' && $attempt > 3) {
        echo json_encode(['ok'=>false,'error'=>'No attempts left']);
        exit;
    }

    // If there is an in_progress test, resume it instead of creating a new one
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
        // Find first unanswered item
        $q = $pdo->prepare("
          SELECT id, idx, kind, prompt, options_json
          FROM progress_test_items
          WHERE test_id=? AND (student_json IS NULL)
          ORDER BY idx ASC
          LIMIT 1
        ");
        $q->execute([$existingTestId]);
        $it = $q->fetch(PDO::FETCH_ASSOC);

        // If everything answered already, force finish via answer endpoint logic (client can refresh)
        if (!$it) {
            echo json_encode(['ok'=>false,'error'=>'Test already completed, refresh the page.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'test_id' => $existingTestId,
            'item' => [
                'item_id' => (int)$it['id'],
                'idx' => (int)$it['idx'],
                'kind' => (string)$it['kind'],
                'prompt' => (string)$it['prompt'],
                'options' => json_decode((string)$it['options_json'], true) ?: []
            ]
        ]);
        exit;
    }

    // Create new test row
    $seed = bin2hex(random_bytes(16));

    $insT = $pdo->prepare("
      INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, seed)
      VALUES (?,?,?,?, 'in_progress', ?)
    ");
    $insT->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
    $testId = (int)$pdo->lastInsertId();

    // Build a simple deterministic set of questions (MVP).
    // Later we will replace with lesson-content + summary driven AI questions.
    $items = [];

    $items[] = [
        'kind' => 'info',
        'prompt' => "Progress Test started.\n\nAnswer carefully. Minimal hints.\n\nTap Continue to begin.",
        'options' => null,
        'correct' => null
    ];

    // Deterministic pseudo-random based on seed
    $rng = hexdec(substr($seed, 0, 8));

    $yesno = [
        ["A stall is caused by exceeding the critical angle of attack, not by a specific airspeed.", true],
        ["VFR requires a flight plan to be filed before takeoff.", false],
        ["Load factor increases stall speed.", true],
    ];

    $mcq = [
        [
            "Which control primarily changes pitch?",
            ["Rudder", "Elevator", "Aileron", "Trim wheel only"],
            1
        ],
        [
            "Best immediate action after an engine failure after takeoff (sufficient altitude permitting)?",
            ["Turn back immediately", "Maintain best glide and pick a suitable landing area ahead", "Climb to pattern altitude", "Continue straight and increase power"],
            1
        ],
    ];

    // Shuffle-ish selection
    $ynPick = $yesno[$rng % count($yesno)];
    $ynPick2 = $yesno[($rng + 1) % count($yesno)];
    $ynPick3 = $yesno[($rng + 2) % count($yesno)];

    $items[] = ['kind'=>'yesno','prompt'=>$ynPick[0],'options'=>null,'correct'=>['value'=>$ynPick[1]]];
    $items[] = ['kind'=>'yesno','prompt'=>$ynPick2[0],'options'=>null,'correct'=>['value'=>$ynPick2[1]]];
    $items[] = ['kind'=>'yesno','prompt'=>$ynPick3[0],'options'=>null,'correct'=>['value'=>$ynPick3[1]]];

    // Add MCQs
    foreach ($mcq as $q) {
        $items[] = ['kind'=>'mcq','prompt'=>$q[0],'options'=>$q[1],'correct'=>['index'=>$q[2]]];
    }

    $items[] = [
        'kind' => 'info',
        'prompt' => "Last question complete.\n\nTap Continue to finish and receive your score + debrief.",
        'options' => null,
        'correct' => null
    ];

    // Persist items
    $insI = $pdo->prepare("
      INSERT INTO progress_test_items (test_id, idx, kind, prompt, options_json, correct_json)
      VALUES (?,?,?,?,?,?)
    ");

    $idx = 1;
    foreach ($items as $it) {
        $insI->execute([
            $testId,
            $idx,
            $it['kind'],
            $it['prompt'],
            $it['options'] === null ? null : json_encode($it['options'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $it['correct'] === null ? null : json_encode($it['correct'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $idx++;
    }

    // Return first item
    $first = $pdo->prepare("
      SELECT id, idx, kind, prompt, options_json
      FROM progress_test_items
      WHERE test_id=?
      ORDER BY idx ASC
      LIMIT 1
    ");
    $first->execute([$testId]);
    $it = $first->fetch(PDO::FETCH_ASSOC);
    if (!$it) throw new RuntimeException('Failed to create items');

    echo json_encode([
        'ok' => true,
        'test_id' => $testId,
        'item' => [
            'item_id' => (int)$it['id'],
            'idx' => (int)$it['idx'],
            'kind' => (string)$it['kind'],
            'prompt' => (string)$it['prompt'],
            'options' => json_decode((string)$it['options_json'], true) ?: []
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}