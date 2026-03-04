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

    $pdo->beginTransaction();

    // 1) Resume existing in_progress test (prevents duplicates and double click issues)
    $resume = $pdo->prepare("
        SELECT id
        FROM progress_tests
        WHERE user_id=? AND cohort_id=? AND lesson_id=? AND status='in_progress'
        ORDER BY id DESC
        LIMIT 1
    ");
    $resume->execute([$userId, $cohortId, $lessonId]);
    $testId = (int)($resume->fetchColumn() ?: 0);

    $getFirstUnanswered = function(int $testId) use ($pdo) {
        $q = $pdo->prepare("
          SELECT id, idx, kind, prompt, options_json
          FROM progress_test_items
          WHERE test_id=? AND (student_json IS NULL OR student_json='null')
          ORDER BY idx ASC
          LIMIT 1
        ");
        $q->execute([$testId]);
        $it = $q->fetch(PDO::FETCH_ASSOC);

        if (!$it) {
            // if none unanswered, return first
            $q2 = $pdo->prepare("
              SELECT id, idx, kind, prompt, options_json
              FROM progress_test_items
              WHERE test_id=?
              ORDER BY idx ASC
              LIMIT 1
            ");
            $q2->execute([$testId]);
            $it = $q2->fetch(PDO::FETCH_ASSOC);
        }

        if (!$it) throw new RuntimeException('No items found for test');

        return [
            'item_id' => (int)$it['id'],
            'idx' => (int)$it['idx'],
            'kind' => (string)$it['kind'],
            'prompt' => (string)$it['prompt'],
            'options' => json_decode((string)($it['options_json'] ?? 'null'), true) ?: []
        ];
    };

    if ($testId > 0) {
        // If items exist, just resume
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=?");
        $cnt->execute([$testId]);
        if ((int)$cnt->fetchColumn() > 0) {
            $item = $getFirstUnanswered($testId);
            $pdo->commit();
            echo json_encode(['ok'=>true,'test_id'=>$testId,'item'=>$item]);
            exit;
        }
        // else fall through: build items for this existing testId
    } else {
        // 2) Create new test row safely (lock + retry)
        $attempt = 0;

        // lock rows for this user/cohort/lesson to make MAX(attempt) stable
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

        // retry insert if uniqueness trips for any reason
        for ($tries = 0; $tries < 3; $tries++) {
            try {
                $ins = $pdo->prepare("
                  INSERT INTO progress_tests (user_id, cohort_id, lesson_id, attempt, status, seed, started_at)
                  VALUES (?,?,?,?, 'in_progress', ?, NOW())
                ");
                $ins->execute([$userId, $cohortId, $lessonId, $attempt, $seed]);
                $testId = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                // duplicate attempt - bump and retry
                if (strpos($e->getMessage(), 'uq_attempt') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }

        if ($testId <= 0) {
            throw new RuntimeException('Failed to create test (attempt collision)');
        }
    }

    // 3) Build items (always rebuild items for this testId safely)
    $pdo->prepare("DELETE FROM progress_test_items WHERE test_id=?")->execute([$testId]);

    $items = [];

    $items[] = ['kind'=>'info','prompt'=>"Progress Test started.\n\nTap Continue to begin.", 'options'=>[], 'correct'=>['value'=>true]];

    $items[] = ['kind'=>'yesno','prompt'=>"TRUE or FALSE (Yes=True, No=False): A stall can happen at any airspeed.", 'options'=>[], 'correct'=>['value'=>true]];

    $items[] = ['kind'=>'mcq','prompt'=>"Which best defines angle of attack (AoA)?", 'options'=>[
        "The angle between the wing chord line and the relative wind",
        "The angle between the horizon and the airplane’s longitudinal axis",
        "The angle between the runway and the airplane’s flight path",
        "The angle between the propeller disk and the airflow"
    ], 'correct'=>['index'=>0]];

    $items[] = ['kind'=>'yesno','prompt'=>"TRUE or FALSE (Yes=True, No=False): In a coordinated turn, the inclinometer ball is centered.", 'options'=>[], 'correct'=>['value'=>true]];

    $items[] = ['kind'=>'mcq','prompt'=>"On final approach in a crosswind, the primary goal is to…", 'options'=>[
        "Keep the wings level at all times",
        "Maintain runway alignment and control drift",
        "Add power to eliminate the crosswind effect",
        "Use rudder only; ailerons are ineffective"
    ], 'correct'=>['index'=>1]];

    $items[] = ['kind'=>'info','prompt'=>"End of test.\n\nTap Continue to finish and receive your score + debrief.", 'options'=>[], 'correct'=>['value'=>true]];

    $insItem = $pdo->prepare("
      INSERT INTO progress_test_items
        (test_id, idx, kind, question_order, prompt, options_json, correct_json, correct_answer_json, student_json, student_answer_json, is_correct, created_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NOW())
    ");

    $idx = 1;
    foreach ($items as $it) {
        $optionsJson = json_encode($it['options'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($optionsJson === false) $optionsJson = '[]';

        $correctJson = json_encode($it['correct'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($correctJson === false) $correctJson = '{}';

        $insItem->execute([
            $testId,
            $idx,
            $it['kind'],
            $idx,
            $it['prompt'],
            $optionsJson,
            $correctJson,
            $correctJson // correct_answer_json required NOT NULL
        ]);
        $idx++;
    }

    $item = $getFirstUnanswered($testId);

    $pdo->commit();
    echo json_encode(['ok'=>true,'test_id'=>$testId,'item'=>$item]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}