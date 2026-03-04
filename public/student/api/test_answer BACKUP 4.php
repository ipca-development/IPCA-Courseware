<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function norm(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
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

    $testId = (int)($data['test_id'] ?? 0);
    $itemId = (int)($data['item_id'] ?? 0);
    $answer = $data['answer'] ?? null;

    if ($testId <= 0 || $itemId <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing test_id or item_id']); exit; }

    $userId = (int)$u['id'];

    if ($role === 'student') {
        $own = $pdo->prepare("SELECT 1 FROM progress_tests WHERE id=? AND user_id=? LIMIT 1");
        $own->execute([$testId, $userId]);
        if (!$own->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Forbidden']);
            exit;
        }
    }

    $item = $pdo->prepare("SELECT * FROM progress_test_items WHERE id=? AND test_id=? LIMIT 1");
    $item->execute([$itemId, $testId]);
    $it = $item->fetch(PDO::FETCH_ASSOC);
    if (!$it) { echo json_encode(['ok'=>false,'error'=>'Item not found']); exit; }

    $kind = (string)$it['kind'];
    $correct = json_decode((string)($it['correct_json'] ?? 'null'), true) ?: [];
    $options = json_decode((string)($it['options_json'] ?? 'null'), true) ?: [];

    $studentJson = is_array($answer) ? $answer : ['value'=>$answer];

    // ✅ Timeout handling
    if (!empty($studentJson['timeout'])) {
        $studentJson = ['timeout'=>true];
        $isCorrect = ($kind === 'info') ? 1 : 0;

        $sj = json_encode($studentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $upd = $pdo->prepare("
          UPDATE progress_test_items
          SET student_json=?, student_answer_json=?, is_correct=?, answered_at=NOW(), updated_at=NOW()
          WHERE id=?
        ");
        $upd->execute([$sj, $sj, $isCorrect, $itemId]);

        // Next item
        $next = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? AND idx>? ORDER BY idx ASC LIMIT 1");
        $next->execute([$testId, (int)$it['idx']]);
        $n = $next->fetch(PDO::FETCH_ASSOC);

        if ($n) {
            echo json_encode([
              'ok'=>true,
              'done'=>false,
              'item'=>[
                'item_id'=>(int)$n['id'],
                'idx'=>(int)$n['idx'],
                'kind'=>(string)$n['kind'],
                'prompt'=>(string)$n['prompt'],
                'options'=> json_decode((string)($n['options_json'] ?? 'null'), true) ?: []
              ]
            ]);
            exit;
        }

        // Finished
        // (falls through to score calculation below)
    }

    // ✅ Spoken text mapping (best effort)
    if (isset($studentJson['text']) && is_string($studentJson['text'])) {
        $t = norm($studentJson['text']);

        if ($kind === 'yesno') {
            $isYes = (strpos($t, 'yes') !== false) || (strpos($t, 'true') !== false) || (strpos($t, 'correct') !== false);
            $isNo  = (strpos($t, 'no') !== false)  || (strpos($t, 'false') !== false) || (strpos($t, 'incorrect') !== false);
            if ($isYes && !$isNo) $studentJson['value'] = true;
            if ($isNo && !$isYes) $studentJson['value'] = false;
        }

        if ($kind === 'mcq') {
            if (preg_match('/\b(1|2|3|4)\b/', $t, $m)) {
                $studentJson['index'] = (int)$m[1]-1;
            } elseif (preg_match('/\b(a|b|c|d)\b/', $t, $m)) {
                $map = ['a'=>0,'b'=>1,'c'=>2,'d'=>3];
                $studentJson['index'] = $map[$m[1]];
            } else {
                // fuzzy match
                $bestIdx = -1;
                $bestScore = 0;
                foreach ($options as $i=>$opt) {
                    $o = norm((string)$opt);
                    if ($o === '') continue;
                    $score = 0;
                    if (strpos($t, $o) !== false) $score = 100;
                    else {
                        $words = array_filter(explode(' ', $o));
                        foreach ($words as $w) {
                            if (strlen($w) >= 4 && strpos($t, $w) !== false) $score++;
                        }
                    }
                    if ($score > $bestScore) { $bestScore = $score; $bestIdx = (int)$i; }
                }
                if ($bestIdx >= 0 && $bestScore > 0) $studentJson['index'] = $bestIdx;
            }
        }
    }

    // Grade
    $isCorrect = null;
    if ($kind === 'info') {
        $isCorrect = 1;
    } elseif ($kind === 'yesno') {
        $cv = (bool)($correct['value'] ?? false);
        $sv = (bool)($studentJson['value'] ?? false);
        $isCorrect = ($cv === $sv) ? 1 : 0;
    } elseif ($kind === 'mcq') {
        $ci = (int)($correct['index'] ?? -1);
        $si = (int)($studentJson['index'] ?? -1);
        $isCorrect = ($ci === $si) ? 1 : 0;
    }

    $sj = json_encode($studentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $upd = $pdo->prepare("
      UPDATE progress_test_items
      SET student_json=?, student_answer_json=?, is_correct=?, answered_at=NOW(), updated_at=NOW()
      WHERE id=?
    ");
    $upd->execute([$sj, $sj, $isCorrect, $itemId]);

    // Next item
    $next = $pdo->prepare("SELECT id, idx, kind, prompt, options_json FROM progress_test_items WHERE test_id=? AND idx>? ORDER BY idx ASC LIMIT 1");
    $next->execute([$testId, (int)$it['idx']]);
    $n = $next->fetch(PDO::FETCH_ASSOC);

    if ($n) {
        echo json_encode([
          'ok'=>true,
          'done'=>false,
          'item'=>[
            'item_id'=>(int)$n['id'],
            'idx'=>(int)$n['idx'],
            'kind'=>(string)$n['kind'],
            'prompt'=>(string)$n['prompt'],
            'options'=> json_decode((string)($n['options_json'] ?? 'null'), true) ?: []
          ]
        ]);
        exit;
    }

    // Finished -> score
    $tot = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq')");
    $tot->execute([$testId]);
    $totalQ = (int)$tot->fetchColumn();

    $cor = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq') AND is_correct=1");
    $cor->execute([$testId]);
    $correctQ = (int)$cor->fetchColumn();

    $scorePct = ($totalQ > 0) ? (int)round(($correctQ / $totalQ) * 100) : 0;
    $status = ($scorePct >= 85) ? 'passed' : 'failed';

    // Basic AI summary (optional)
    $aiSummary = "Score {$scorePct}% ({$correctQ}/{$totalQ}).";
    $weakAreas = ($status === 'passed') ? "No major weak areas detected." : "Review missed topics and retake.";

    $updT = $pdo->prepare("UPDATE progress_tests SET status=?, score_pct=?, completed_at=NOW(), ai_summary=?, weak_areas=? WHERE id=?");
    $updT->execute([$status, $scorePct, $aiSummary, $weakAreas, $testId]);

    echo json_encode([
      'ok'=>true,
      'done'=>true,
      'score_pct'=>$scorePct,
      'status'=>$status,
      'ai_summary'=>$aiSummary,
      'weak_areas'=>$weakAreas
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}