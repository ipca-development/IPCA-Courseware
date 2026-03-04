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
        if (!$own->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    }

    $item = $pdo->prepare("SELECT * FROM progress_test_items WHERE id=? AND test_id=? LIMIT 1");
    $item->execute([$itemId, $testId]);
    $it = $item->fetch(PDO::FETCH_ASSOC);
    if (!$it) { echo json_encode(['ok'=>false,'error'=>'Item not found']); exit; }

    $kind = (string)$it['kind'];
    $correct = json_decode((string)($it['correct_json'] ?? 'null'), true) ?: [];
    $options = json_decode((string)($it['options_json'] ?? 'null'), true) ?: [];

    // normalize student payload
    $studentJson = is_array($answer) ? $answer : ['value'=>$answer];

    // If answer is spoken text, map it
    if (isset($studentJson['text']) && is_string($studentJson['text'])) {
        $t = norm($studentJson['text']);

        if ($kind === 'yesno') {
            $isYes = (strpos($t, 'yes') !== false) || (strpos($t, 'true') !== false) || (strpos($t, 'correct') !== false);
            $isNo  = (strpos($t, 'no') !== false)  || (strpos($t, 'false') !== false) || (strpos($t, 'incorrect') !== false);
            if ($isYes && !$isNo) $studentJson = ['value'=>true, 'text'=>$studentJson['text']];
            else if ($isNo && !$isYes) $studentJson = ['value'=>false, 'text'=>$studentJson['text']];
        }

        if ($kind === 'mcq') {
            // detect "1/2/3/4" or "A/B/C/D"
            if (preg_match('/\b(1|2|3|4)\b/', $t, $m)) {
                $studentJson = ['index'=>(int)$m[1]-1, 'text'=>$studentJson['text']];
            } elseif (preg_match('/\b(a|b|c|d)\b/', $t, $m)) {
                $map = ['a'=>0,'b'=>1,'c'=>2,'d'=>3];
                $studentJson = ['index'=>$map[$m[1]], 'text'=>$studentJson['text']];
            } else {
                // try fuzzy match by substring overlap
                $bestIdx = -1;
                $bestScore = 0;
                foreach ($options as $i=>$opt) {
                    $o = norm((string)$opt);
                    if ($o === '') continue;
                    $score = 0;
                    if (strpos($t, $o) !== false) $score = 100;
                    else {
                        // simple overlap
                        $words = array_filter(explode(' ', $o));
                        foreach ($words as $w) {
                            if (strlen($w) >= 4 && strpos($t, $w) !== false) $score++;
                        }
                    }
                    if ($score > $bestScore) { $bestScore=$score; $bestIdx=(int)$i; }
                }
                if ($bestIdx >= 0 && $bestScore > 0) {
                    $studentJson = ['index'=>$bestIdx, 'text'=>$studentJson['text']];
                }
            }
        }
    }

    // grade
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

    $upd = $pdo->prepare("UPDATE progress_test_items SET student_json=?, student_answer_json=?, is_correct=?, answered_at=NOW() WHERE id=?");
    $upd->execute([
        json_encode($studentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        json_encode($studentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $isCorrect,
        $itemId
    ]);

    // next
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
            'options'=> json_decode((string)$n['options_json'], true) ?: []
          ]
        ]);
        exit;
    }

    // finish score
    $tot = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq')");
    $tot->execute([$testId]);
    $totalQ = (int)$tot->fetchColumn();

    $cor = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq') AND is_correct=1");
    $cor->execute([$testId]);
    $correctQ = (int)$cor->fetchColumn();

    $scorePct = ($totalQ > 0) ? (int)round(($correctQ / $totalQ) * 100) : 0;
    $status = ($scorePct >= 85) ? 'passed' : 'failed';

    $aiSummary = "Score: {$scorePct}% (Correct {$correctQ}/{$totalQ}).";
    $weak = ($status === 'passed') ? "No weak areas detected." : "Review missed topics and retake.";

    $updT = $pdo->prepare("UPDATE progress_tests SET status=?, score_pct=?, completed_at=NOW(), ai_summary=?, weak_areas=? WHERE id=?");
    $updT->execute([$status, $scorePct, $aiSummary, $weak, $testId]);

    echo json_encode([
      'ok'=>true,
      'done'=>true,
      'score_pct'=>$scorePct,
      'status'=>$status,
      'ai_summary'=>$aiSummary,
      'weak_areas'=>$weak
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}