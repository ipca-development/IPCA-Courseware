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

    // Student must be enrolled in cohort
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $chk->execute([$cohortId, $userId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not enrolled']);
            exit;
        }
    }

    // Determine next attempt number
    $stmt = $pdo->prepare("SELECT MAX(attempt) FROM progress_tests WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $stmt->execute([$userId, $cohortId, $lessonId]);
    $maxAttempt = (int)($stmt->fetchColumn() ?: 0);
    $attempt = $maxAttempt + 1;

    // Hard cap: 3 automatic attempts
    if ($role === 'student' && $attempt > 3) {
        echo json_encode(['ok'=>false,'error'=>'No attempts left']);
        exit;
    }

    // Create test row
    $seed = bin2hex(random_bytes(16));
    $ins = $pdo->prepare("
      INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, seed, started_at)
      VALUES (?,?,?,?, 'in_progress', ?, NOW())
    ");
    $ins->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
    $testId = (int)$pdo->lastInsertId();

    // Build a simple 10-min test (mix: info + yes/no + mcq)
    // Later we'll feed lesson content + summary; for now generic aviation knowledge prompts.
    $items = [];

    $items[] = [
        'kind' => 'info',
        'prompt' => "Progress Test started. Answer carefully — minimal hints. Ready?",
        'options' => [],
        'correct' => ['value'=>true] // irrelevant for info
    ];

    // YES/NO
    $items[] = [
        'kind' => 'yesno',
        'prompt' => "TRUE or FALSE (answer Yes for True, No for False): A stall can happen at any airspeed.",
        'options' => [],
        'correct' => ['value'=>true]
    ];

    // MCQ
    $items[] = [
        'kind' => 'mcq',
        'prompt' => "Which of these best defines angle of attack (AoA)?",
        'options' => [
            "The angle between the wing chord line and the relative wind",
            "The angle between the horizon and the airplane’s longitudinal axis",
            "The angle between the runway and the airplane’s flight path",
            "The angle between the propeller disk and the airflow"
        ],
        'correct' => ['index'=>0]
    ];

    // YES/NO
    $items[] = [
        'kind' => 'yesno',
        'prompt' => "TRUE or FALSE (Yes=True, No=False): In a coordinated turn, the inclinometer ball is centered.",
        'options' => [],
        'correct' => ['value'=>true]
    ];

    // MCQ
    $items[] = [
        'kind' => 'mcq',
        'prompt' => "On final approach in a crosswind, the primary goal is to…",
        'options' => [
            "Keep the wings level at all times",
            "Maintain runway alignment and control drift",
            "Add power to eliminate the crosswind effect",
            "Use rudder only; ailerons are ineffective"
        ],
        'correct' => ['index'=>1]
    ];

    // Insert items with idx starting at 1
    $insItem = $pdo->prepare("
      INSERT INTO progress_test_items
        (test_id, idx, kind, question_order, prompt, options_json, correct_json, student_json, is_correct, created_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NOW())
    ");

    $idx = 1;
    foreach ($items as $it) {
        $kind = (string)$it['kind'];
        $prompt = (string)$it['prompt'];
        $optionsJson = json_encode($it['options'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $correctJson = json_encode($it['correct'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $insItem->execute([
            $testId,
            $idx,
            $kind,
            $idx, // question_order mirrors idx
            $prompt,
            $optionsJson,
            $correctJson
        ]);
        $idx++;
    }

    // Return first item (idx=1)
    $stmt = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? AND idx=1 LIMIT 1");
    $stmt->execute([$testId]);
    $first = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$first) throw new RuntimeException("Failed to create first item");

    echo json_encode([
        'ok' => true,
        'test_id' => $testId,
        'attempt' => $attempt,
        'item' => [
            'item_id' => (int)$first['id'],
            'idx' => (int)$first['idx'],
            'kind' => (string)$first['kind'],
            'prompt' => (string)$first['prompt'],
            'options' => json_decode((string)$first['options_json'], true) ?: []
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}