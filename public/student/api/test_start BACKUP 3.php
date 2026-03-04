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
    if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

    $cohortId = (int)($data['cohort_id'] ?? 0);
    $lessonId = (int)($data['lesson_id'] ?? 0);
    if ($cohortId <= 0 || $lessonId <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Missing cohort_id or lesson_id']);
        exit;
    }

    $userId = (int)$u['id'];

    // If student, must be enrolled
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id=? AND user_id=? LIMIT 1");
        $chk->execute([$cohortId,$userId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Not enrolled']);
            exit;
        }
    }

    // 1) If there is an existing in_progress test, reuse it.
    $reuse = $pdo->prepare("
      SELECT id, attempt
      FROM progress_tests
      WHERE user_id=? AND cohort_id=? AND lesson_id=? AND status='in_progress'
      ORDER BY id DESC
      LIMIT 1
    ");
    $reuse->execute([$userId,$cohortId,$lessonId]);
    $row = $reuse->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $testId = (int)$row['id'];
        // ensure it has items; if not, create them
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=?");
        $cnt->execute([$testId]);
        $has = (int)$cnt->fetchColumn();

        if ($has <= 0) {
            // fall through to create items below
        } else {
            // return first item
            $first = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? ORDER BY idx ASC LIMIT 1");
            $first->execute([$testId]);
            $it = $first->fetch(PDO::FETCH_ASSOC);
            if ($it) {
                echo json_encode([
                    'ok'=>true,
                    'test_id'=>$testId,
                    'item'=>[
                        'item_id'=>(int)$it['id'],
                        'idx'=>(int)$it['idx'],
                        'kind'=>(string)$it['kind'],
                        'prompt'=>(string)$it['prompt'],
                        'options'=> json_decode((string)($it['options_json'] ?? ''), true) ?: []
                    ]
                ]);
                exit;
            }
        }
    }

    // 2) Create NEW attempt safely: attempt = MAX(attempt)+1
    $max = $pdo->prepare("SELECT MAX(attempt) FROM progress_tests WHERE user_id=? AND cohort_id=? AND lesson_id=?");
    $max->execute([$userId,$cohortId,$lessonId]);
    $attempt = (int)($max->fetchColumn() ?: 0) + 1;

    // Insert progress_tests (unique key uq_attempt safe because attempt is max+1)
    $seed = bin2hex(random_bytes(16));

    $insT = $pdo->prepare("
      INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, started_at, seed)
      VALUES (?,?,?,?, 'in_progress', NOW(), ?)
    ");
    $insT->execute([$userId,$cohortId,$lessonId,$attempt,$seed]);
    $testId = (int)$pdo->lastInsertId();

    // 3) (Re)build items for this test (guarantee no duplicate idx)
    $pdo->prepare("DELETE FROM progress_test_items WHERE test_id=?")->execute([$testId]);

    // Simple deterministic starter set (MVP)
    // Later we’ll swap to “lesson content + summary” prompts.
    $items = [
        [
            'kind' => 'info',
            'prompt' => 'Progress test starting.',
            'options' => [],
            'correct' => ['value'=>true],
        ],
        [
            'kind' => 'yesno',
            'prompt' => 'Yes or no: The airplane has wings.',
            'options' => [],
            'correct' => ['value'=>true],
        ],
        [
            'kind' => 'mcq',
            'prompt' => 'Which is most important to keep an engine running?',
            'options' => ['Fuel system','Paint','Cup holders','Seat fabric'],
            'correct' => ['index'=>0],
        ],
    ];

    $insI = $pdo->prepare("
      INSERT INTO progress_test_items
        (test_id, idx, question_order, kind, prompt, options_json, correct_json, correct_answer_json, student_json, student_answer_json, is_correct, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NOW(), NOW())
    ");

    $idx = 1;
    foreach ($items as $q) {
        $optionsJson = !empty($q['options']) ? json_encode($q['options'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $correctJson = json_encode($q['correct'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        // satisfy NOT NULL column in your schema
        $correctAnswerJson = $correctJson;

        $insI->execute([
            $testId,
            $idx,
            $idx, // question_order mirrors idx for now
            (string)$q['kind'],
            (string)$q['prompt'],
            $optionsJson,
            $correctJson,
            $correctAnswerJson
        ]);
        $idx++;
    }

    // Return first item
    $first = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? ORDER BY idx ASC LIMIT 1");
    $first->execute([$testId]);
    $it = $first->fetch(PDO::FETCH_ASSOC);
    if (!$it) throw new RuntimeException("No items created");

    echo json_encode([
        'ok'=>true,
        'test_id'=>$testId,
        'item'=>[
            'item_id'=>(int)$it['id'],
            'idx'=>(int)$it['idx'],
            'kind'=>(string)$it['kind'],
            'prompt'=>(string)$it['prompt'],
            'options'=> json_decode((string)($it['options_json'] ?? ''), true) ?: []
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}