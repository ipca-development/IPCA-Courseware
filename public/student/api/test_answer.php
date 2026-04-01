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

/**
 * Very simple rubric matcher:
 * - key point can be a short phrase
 * - we count it as matched if ALL meaningful words (len>=4) appear in student answer
 */
function rubric_score(string $studentText, array $keyPoints): int {
    $t = norm($studentText);
    if ($t === '') return 0;

    $matched = 0;
    foreach ($keyPoints as $kp) {
        $kp = norm((string)$kp);
        if ($kp === '') continue;

        $words = array_values(array_filter(explode(' ', $kp), function($w){
            return strlen($w) >= 4;
        }));

        if (!$words) {
            // If keypoint is too short, do substring
            if (strpos($t, $kp) !== false) $matched++;
            continue;
        }

        $all = true;
        foreach ($words as $w) {
            if (strpos($t, $w) === false) { $all = false; break; }
        }
        if ($all) $matched++;
    }
    return $matched;
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

    // verify ownership (student)
    if ($role === 'student') {
        $own = $pdo->prepare("SELECT 1 FROM progress_tests WHERE id=? AND user_id=? LIMIT 1");
        $own->execute([$testId, $userId]);
        if (!$own->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    }

    // Load test row (need cohort_id, lesson_id)
    $trow = $pdo->prepare("SELECT cohort_id, lesson_id FROM progress_tests WHERE id=? LIMIT 1");
    $trow->execute([$testId]);
    $testRow = $trow->fetch(PDO::FETCH_ASSOC);
    if (!$testRow) { echo json_encode(['ok'=>false,'error'=>'Test not found']); exit; }
    $cohortId = (int)$testRow['cohort_id'];
    $lessonId = (int)$testRow['lesson_id'];

    // Load item
    $item = $pdo->prepare("SELECT * FROM progress_test_items WHERE id=? AND test_id=? LIMIT 1");
    $item->execute([$itemId, $testId]);
    $it = $item->fetch(PDO::FETCH_ASSOC);
    if (!$it) { echo json_encode(['ok'=>false,'error'=>'Item not found']); exit; }

    $kind = (string)$it['kind'];

    // Prefer correct_answer_json (NOT NULL) when available; fallback to correct_json
    $correct = json_decode((string)($it['correct_answer_json'] ?? 'null'), true);
    if (!is_array($correct)) {
        $correct = json_decode((string)($it['correct_json'] ?? 'null'), true);
        if (!is_array($correct)) $correct = [];
    }

    $options = json_decode((string)($it['options_json'] ?? 'null'), true);
    if (!is_array($options)) $options = [];

    $studentJson = is_array($answer) ? $answer : ['value'=>$answer];

    // TIMEOUT handling
    if (!empty($studentJson['timeout'])) {
        $studentJson = ['timeout'=>true];
    }

    // Spoken mapping (from ASR text)
    if (isset($studentJson['text']) && is_string($studentJson['text'])) {
        $t = norm($studentJson['text']);

        if ($kind === 'yesno') {
            $isYes = (strpos($t,'yes') !== false) || (strpos($t,'true') !== false);
            $isNo  = (strpos($t,'no') !== false)  || (strpos($t,'false') !== false);
            if ($isYes && !$isNo) $studentJson['value'] = true;
            if ($isNo && !$isYes) $studentJson['value'] = false;
        }

        if ($kind === 'mcq') {
            if (preg_match('/\b(1|2|3|4)\b/', $t, $m)) {
                $studentJson['index'] = (int)$m[1] - 1;
            } elseif (preg_match('/\b(a|b|c|d)\b/', $t, $m)) {
                $map = ['a'=>0,'b'=>1,'c'=>2,'d'=>3];
                $studentJson['index'] = $map[$m[1]];
            } else {
                $bestIdx = -1; $bestScore = 0;
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

        // Open: store normalized text too
        if ($kind === 'open') {
            $studentJson['value'] = (string)$studentJson['text'];
        }
    }

    // Grade
    $isCorrect = null;

    if (!empty($studentJson['timeout'])) {
        $isCorrect = ($kind === 'info') ? 1 : 0;

    } elseif ($kind === 'info') {
        $isCorrect = 1;

    } elseif ($kind === 'yesno') {
        $cv = (bool)($correct['value'] ?? false);
        $sv = (bool)($studentJson['value'] ?? false);
        $isCorrect = ($cv === $sv) ? 1 : 0;

    } elseif ($kind === 'mcq') {
        $ci = (int)($correct['index'] ?? -1);
        $si = (int)($studentJson['index'] ?? -1);
        $isCorrect = ($ci === $si) ? 1 : 0;

    } elseif ($kind === 'open') {
        // OPEN scoring: rubric first, else fallback length threshold
        $textAnswer = '';
        if (isset($studentJson['text']) && is_string($studentJson['text'])) $textAnswer = $studentJson['text'];
        elseif (isset($studentJson['value']) && is_string($studentJson['value'])) $textAnswer = $studentJson['value'];

        $textAnswer = trim((string)$textAnswer);

        if ($textAnswer === '') {
            $isCorrect = 0;
        } else {
            if ((string)($correct['type'] ?? '') === 'rubric') {
                $keyPoints = $correct['key_points'] ?? [];
                if (!is_array($keyPoints)) $keyPoints = [];
                $minPts = (int)($correct['min_points_to_pass'] ?? 1);
                if ($minPts < 1) $minPts = 1;

                $matched = rubric_score($textAnswer, $keyPoints);
                $studentJson['rubric_matched'] = $matched;
                $studentJson['rubric_required'] = $minPts;

                $isCorrect = ($matched >= $minPts) ? 1 : 0;
            } else {
                // fallback: length based
                $len = mb_strlen($textAnswer);
                $studentJson['len'] = $len;
                $isCorrect = ($len >= 20) ? 1 : 0; // adjust if desired
            }
        }
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
        // also return next-next id for prefetch
        $next2 = $pdo->prepare("SELECT id FROM progress_test_items WHERE test_id=? AND idx>? ORDER BY idx ASC LIMIT 1");
        $next2->execute([$testId, (int)$n['idx']]);
        $next2Id = (int)($next2->fetchColumn() ?: 0);

        echo json_encode([
          'ok'=>true,
          'done'=>false,
          'next_item_id'=> $next2Id ?: null,
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

    // FINISH -> score (NOW includes OPEN)
    $tot = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq','open')");
    $tot->execute([$testId]);
    $totalQ = (int)$tot->fetchColumn();

    $cor = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items WHERE test_id=? AND kind IN ('yesno','mcq','open') AND is_correct=1");
    $cor->execute([$testId]);
    $correctQ = (int)$cor->fetchColumn();

    $scorePct = ($totalQ > 0) ? (int)round(($correctQ / $totalQ) * 100) : 0;
    $status = ($scorePct >= 85) ? 'passed' : 'failed';

    // Build test log
    $items = $pdo->prepare("
      SELECT idx, kind, prompt, options_json, correct_answer_json, student_json, is_correct
      FROM progress_test_items
      WHERE test_id=?
      ORDER BY idx ASC
    ");
    $items->execute([$testId]);
    $log = $items->fetchAll(PDO::FETCH_ASSOC);

    // Load TRUTH source (lesson narration)
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

    // Load student summary (NOT truth)
    $sq = $pdo->prepare("
      SELECT summary_plain
      FROM lesson_summaries
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      LIMIT 1
    ");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summaryPlain = trim((string)($sq->fetchColumn() ?: ''));
    if ($summaryPlain === '') $summaryPlain = "(No student summary.)";

    // AI debrief schema: includes summary corrections
    $schema = [
      "type"=>"object",
      "additionalProperties"=>false,
      "properties"=>[
        "written_debrief"=>["type"=>"string"],
        "spoken_debrief"=>["type"=>"string"],
        "weak_areas"=>["type"=>"string"],
        "summary_corrections"=>[
          "type"=>"array",
          "items"=>["type"=>"string"]
        ]
      ],
      "required"=>["written_debrief","spoken_debrief","weak_areas","summary_corrections"]
    ];

    $payload = [
      "model" => cw_openai_model(),
      "input" => [
        ["role"=>"system","content"=>[
          ["type"=>"input_text","text"=>
"You're a strict, professional flight instructor.

SOURCE OF TRUTH:
- Lesson narration scripts are the ONLY truth source.
- Student summary may be incorrect and must NEVER be treated as truth.

TASK:
1) written_debrief: detailed readable feedback (what was strong/weak) + concrete remediation.
2) spoken_debrief: 30–60 second spoken debrief to read aloud.
3) weak_areas: bullet list of weak areas.
4) summary_corrections: list of corrections where the student's summary contradicts lesson scripts.
   Format each as: 'Your summary says X. Lesson material indicates Y. Update your summary.'

IMPORTANT:
- Only call out summary errors if you can support the correction from lesson narration scripts.
- Do NOT penalize the student just because summary is wrong, unless their ANSWERS also show the same misconception."
          ]
        ]],
        ["role"=>"user","content"=>[
          ["type"=>"input_text","text"=>"SCORE: {$scorePct}%\n\nLESSON NARRATION (TRUTH):\n{$truthText}\n\nSTUDENT SUMMARY (NOT TRUTH):\n{$summaryPlain}\n\nTEST LOG JSON:\n".json_encode($log, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        ]]
      ],
      "text" => [
        "format" => [
          "type"=>"json_schema",
          "name"=>"debrief_truth_v1",
          "schema"=>$schema,
          "strict"=>true
        ]
      ],
      "temperature" => 0.2
    ];

    $written = "Score {$scorePct}% ({$correctQ}/{$totalQ}).";
    $weak = ($status==='passed') ? "No major weak areas detected." : "Review missed topics.";
    $spoken = "Your score is {$scorePct} percent. Please review your debrief notes.";
    $summaryCorrections = [];

    try {
        $resp = cw_openai_responses($payload);
        $j = cw_openai_extract_json_text($resp);
        $written = trim((string)($j['written_debrief'] ?? $written));
        $weak    = trim((string)($j['weak_areas'] ?? $weak));
        $spoken  = trim((string)($j['spoken_debrief'] ?? $spoken));
        $summaryCorrections = is_array($j['summary_corrections'] ?? null) ? $j['summary_corrections'] : [];
    } catch (Throwable $e) {}

    $corrBlock = '';
    $cleanCorr = [];
    foreach ($summaryCorrections as $c) {
        $c = trim((string)$c);
        if ($c !== '') $cleanCorr[] = $c;
    }
    if (count($cleanCorr) > 0) {
        $corrBlock = "\n\nSummary corrections:\n- " . implode("\n- ", $cleanCorr);
    }
    $weakStored = trim($weak . $corrBlock);

    $updT = $pdo->prepare("
      UPDATE progress_tests
      SET status=?, score_pct=?, completed_at=NOW(),
          ai_summary=?, weak_areas=?, debrief_spoken=?
      WHERE id=?
    ");
    $updT->execute([$status, $scorePct, $written, $weakStored, $spoken, $testId]);

    echo json_encode([
      'ok'=>true,
      'done'=>true,
      'score_pct'=>$scorePct,
      'status'=>$status,
      'ai_summary'=>$written,
      'weak_areas'=>$weakStored
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}