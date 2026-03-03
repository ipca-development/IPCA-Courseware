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

    $testId = (int)($data['test_id'] ?? 0);
    $itemId = (int)($data['item_id'] ?? 0);
    $answer = $data['answer'] ?? null;

    if ($testId <= 0 || $itemId <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Missing test_id or item_id']);
        exit;
    }

    $userId = (int)$u['id'];

    // verify ownership (admin can view)
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
    if (!$it) {
        echo json_encode(['ok'=>false,'error'=>'Item not found']);
        exit;
    }

    $kind = (string)$it['kind'];
    $correct = json_decode((string)$it['correct_json'], true) ?: [];
    $studentJson = is_array($answer) ? $answer : ['value'=>$answer];

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

    $upd = $pdo->prepare("UPDATE progress_test_items SET student_json=?, is_correct=? WHERE id=?");
    $upd->execute([
        json_encode($studentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $isCorrect,
        $itemId
    ]);

    // next item
    $next = $pdo->prepare("
      SELECT id, idx, kind, prompt, options_json
      FROM progress_test_items
      WHERE test_id=? AND idx>?
      ORDER BY idx ASC
      LIMIT 1
    ");
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

    // finished -> compute score
    $tot = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq')");
    $tot->execute([$testId]);
    $totalQ = (int)$tot->fetchColumn();

    $cor = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq') AND is_correct=1");
    $cor->execute([$testId]);
    $correctQ = (int)$cor->fetchColumn();

    $scorePct = ($totalQ > 0) ? (int)round(($correctQ / $totalQ) * 100) : 0;
    $status = ($scorePct >= 85) ? 'passed' : 'failed';

    // Simple debrief (no OpenAI yet)
    $miss = $pdo->prepare("
      SELECT idx, prompt
      FROM progress_test_items
      WHERE test_id=? AND kind IN ('yesno','mcq') AND (is_correct IS NULL OR is_correct=0)
      ORDER BY idx ASC
    ");
    $miss->execute([$testId]);
    $missed = $miss->fetchAll(PDO::FETCH_ASSOC);

    $summary = "Score: {$scorePct}%\nStatus: {$status}\n\n";
    if ($status === 'passed') {
        $summary .= "Good work. You met the 85% standard.\n";
    } else {
        $summary .= "Not at standard. Review the missed items below and retake.\n";
    }

    $weak = "";
    if ($missed) {
        $weak .= "Missed items:\n";
        foreach ($missed as $m) {
            $weak .= "- Q{$m['idx']}: " . trim((string)$m['prompt']) . "\n";
        }
    } else {
        $weak .= "No weak areas detected.\n";
    }

    $updT = $pdo->prepare("UPDATE progress_tests SET status=?, score_pct=?, completed_at=NOW(), ai_summary=?, weak_areas=? WHERE id=?");
    $updT->execute([$status, $scorePct, $summary, $weak, $testId]);

    echo json_encode([
        'ok'=>true,
        'done'=>true,
        'score_pct'=>$scorePct,
        'status'=>$status,
        'ai_summary'=>$summary,
        'weak_areas'=>$weak
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}