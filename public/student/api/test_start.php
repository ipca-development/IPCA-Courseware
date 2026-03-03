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

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
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

    // Student must be enrolled
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $chk->execute([$cohortId, $userId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not enrolled']);
            exit;
        }
    }

    // Determine next attempt
    $st = $pdo->prepare("SELECT COALESCE(MAX(attempt),0) FROM progress_tests WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $st->execute([$userId, $cohortId, $lessonId]);
    $attempt = ((int)$st->fetchColumn()) + 1;

    // Basic attempt cap (3) for MVP (you can expand later)
    if ($role === 'student' && $attempt > 3) {
        echo json_encode(['ok'=>false,'error'=>'No attempts left']);
        exit;
    }

    // Create test row
    $seed = bin2hex(random_bytes(16));
    $ins = $pdo->prepare("
      INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, seed)
      VALUES (?,?,?,?, 'in_progress', ?)
    ");
    $ins->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
    $testId = (int)$pdo->lastInsertId();

    // Build a short 8–10 item test (mix info/yesno/mcq). For now generic.
    // Later we’ll generate from lesson slide content + summary.
    $items = [];

    $items[] = [
        'kind' => 'info',
        'prompt' => "Welcome. This progress test is scenario-based and strict.\nAnswer carefully.\n\nPress Continue to begin.",
        'options' => null,
        'correct' => null,
    ];

    $items[] = [
        'kind' => 'yesno',
        'prompt' => "Scenario: You are about to taxi from a tight ramp area with other aircraft nearby.\n\nYES or NO: Should you keep your head outside and actively scan even at low taxi speed?",
        'options' => null,
        'correct' => ['value' => true],
    ];

    $items[] = [
        'kind' => 'mcq',
        'prompt' => "Scenario: You suspect a developing crosswind on final.\nWhich action best matches good technique?",
        'options' => [
            "Ignore the wind; aim straight and correct after touchdown.",
            "Apply aileron into the wind and use rudder to maintain runway alignment.",
            "Use only rudder; keep wings level at all times.",
            "Increase flap setting to maximum regardless of conditions."
        ],
        'correct' => ['index' => 1],
    ];

    $items[] = [
        'kind' => 'yesno',
        'prompt' => "YES or NO: If you are unstable on approach, going around is an acceptable and often safer choice.",
        'options' => null,
        'correct' => ['value' => true],
    ];

    $items[] = [
        'kind' => 'mcq',
        'prompt' => "Scenario: You’re unsure about airspace requirements.\nWhat is the safest immediate action?",
        'options' => [
            "Continue; you can look it up later.",
            "Climb as high as possible and proceed.",
            "Stop, verify requirements using proper resources, and avoid entry until confirmed.",
            "Assume it is Class G unless marked otherwise."
        ],
        'correct' => ['index' => 2],
    ];

    // Insert items into DB
    $insItem = $pdo->prepare("
      INSERT INTO progress_test_items (test_id, idx, kind, prompt, options_json, correct_json)
      VALUES (?,?,?,?,?,?)
    ");

    $idx = 1;
    foreach ($items as $it) {
        $insItem->execute([
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
    $first = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? ORDER BY idx ASC LIMIT 1");
    $first->execute([$testId]);
    $n = $first->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'test_id' => $testId,
        'attempt' => $attempt,
        'item' => [
            'item_id' => (int)$n['id'],
            'idx' => (int)$n['idx'],
            'kind' => (string)$n['kind'],
            'prompt' => (string)$n['prompt'],
            'options' => json_decode((string)($n['options_json'] ?? 'null'), true) ?: []
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}