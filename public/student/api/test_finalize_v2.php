<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

while (ob_get_level()) { ob_end_clean(); }
ob_start();

function json_out(array $x): void {
    while (ob_get_level() > 1) { ob_end_clean(); }
    if (ob_get_level() === 1) {
        ob_clean();
    }
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function env_required(string $k): string {
    $v = getenv($k);
    if ($v === false || $v === '') {
        throw new RuntimeException('Missing env var: ' . $k);
    }
    return (string)$v;
}

function temp_base_dir(): string {
    $base = '/tmp/progress_tests_v2_finalize';
    if (!is_dir($base)) {
        if (!@mkdir($base, 0777, true)) {
            throw new RuntimeException('Cannot create temp base directory: ' . $base);
        }
    }
    if (!is_dir($base) || !is_writable($base)) {
        throw new RuntimeException('Temp base directory is not writable: ' . $base);
    }
    return $base;
}

function test_dir(int $testId): string {
    $dir = temp_base_dir() . '/' . $testId;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            throw new RuntimeException('Cannot create test temp directory: ' . $dir);
        }
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Test temp directory is not writable: ' . $dir);
    }
    return $dir;
}

function tts_write_file(string $apiKey, string $model, string $voice, string $text, string $outfile): void {
    $payload = json_encode([
        'model'  => $model,
        'voice'  => $voice,
        'format' => 'mp3',
        'input'  => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $audio = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("TTS failed (HTTP {$code}) {$err}");
    }

    if (@file_put_contents($outfile, $audio) === false) {
        throw new RuntimeException("Failed to write audio file: {$outfile}");
    }

    if (!is_file($outfile) || filesize($outfile) <= 0) {
        throw new RuntimeException("Generated TTS audio file missing or empty: {$outfile}");
    }
}

function normalize_text(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function transcribe_file(string $apiKey, string $filepath): string {
    if (!is_file($filepath)) return '';

    $cfile = curl_file_create($filepath, 'audio/webm', basename($filepath));
    $post = [
        'file'     => $cfile,
        'model'    => 'gpt-4o-mini-transcribe',
        'language' => 'en',
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($out === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Transcription failed (HTTP {$code}) {$err}");
    }

    $j = json_decode($out, true);
    return trim((string)($j['text'] ?? ''));
}

function grade_yesno(string $transcript, array $correct): array {
    $t = normalize_text($transcript);
    $isYes = (strpos($t, 'yes') !== false) || (strpos($t, 'true') !== false);
    $isNo  = (strpos($t, 'no') !== false) || (strpos($t, 'false') !== false);

    $sv = null;
    if ($isYes && !$isNo) $sv = true;
    if ($isNo && !$isYes) $sv = false;

    $cv = (bool)($correct['value'] ?? false);
    $ok = ($sv !== null && $sv === $cv) ? 1 : 0;

    return ['is_correct' => $ok, 'score_points' => $ok, 'max_points' => 1, 'feedback' => ''];
}

function grade_mcq(string $transcript, array $correct, array $options): array {
    $t = normalize_text($transcript);
    $idx = -1;

    if (preg_match('/\b(a|b|c|d)\b/', $t, $m)) {
        $map = ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3];
        $idx = $map[$m[1]];
    } elseif (preg_match('/\b(1|2|3|4)\b/', $t, $m)) {
        $idx = ((int)$m[1]) - 1;
    } else {
        $bestIdx = -1;
        $bestScore = 0;
        foreach ($options as $i => $opt) {
            $o = normalize_text((string)$opt);
            if ($o === '') continue;
            $score = 0;
            if (strpos($t, $o) !== false) {
                $score = 100;
            } else {
                $words = array_filter(explode(' ', $o));
                foreach ($words as $w) {
                    if (strlen($w) >= 4 && strpos($t, $w) !== false) $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = (int)$i;
            }
        }
        if ($bestIdx >= 0) $idx = $bestIdx;
    }

    $ci = (int)($correct['index'] ?? -1);
    $ok = ($idx === $ci) ? 1 : 0;

    return ['is_correct' => $ok, 'score_points' => $ok, 'max_points' => 1, 'feedback' => ''];
}

function grade_open_with_ai(array $item, string $transcript): array {
    $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
    $keyPoints = $correct['key_points'] ?? [];
    if (!is_array($keyPoints)) $keyPoints = [];
    $minPts = (int)($correct['min_points_to_pass'] ?? 2);
    if ($minPts < 1) $minPts = 1;

    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "score_points" => ["type" => "integer"],
            "max_points"   => ["type" => "integer"],
            "is_correct"   => ["type" => "boolean"],
            "feedback"     => ["type" => "string"]
        ],
        "required" => ["score_points", "max_points", "is_correct", "feedback"]
    ];

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role" => "system", "content" => [
                ["type" => "input_text", "text" =>
"You are grading one open-answer oral progress test response.
Be strict and fair.
Evaluate only against the supplied rubric key points.
Do not invent facts."
                ]
            ]],
            ["role" => "user", "content" => [
                ["type" => "input_text", "text" =>
"QUESTION:\n" . (string)$item['prompt'] .
"\n\nRUBRIC KEY POINTS:\n- " . implode("\n- ", $keyPoints) .
"\n\nMIN POINTS TO PASS: {$minPts}" .
"\n\nSTUDENT TRANSCRIPT:\n" . $transcript
                ]
            ]]
        ],
        "text" => [
            "format" => [
                "type"   => "json_schema",
                "name"   => "open_grade_v2",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.1
    ];

    $resp = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($resp);

    $scorePoints = (int)($j['score_points'] ?? 0);
    $maxPoints   = (int)($j['max_points'] ?? max(1, count($keyPoints)));
    if ($maxPoints <= 0) $maxPoints = max(1, count($keyPoints));
    if ($scorePoints < 0) $scorePoints = 0;
    if ($scorePoints > $maxPoints) $scorePoints = $maxPoints;

    $isCorrect = !empty($j['is_correct']) ? 1 : 0;
    if ($scorePoints >= $minPts) $isCorrect = 1;

    return [
        'is_correct'   => $isCorrect,
        'score_points' => $scorePoints,
        'max_points'   => $maxPoints,
        'feedback'     => trim((string)($j['feedback'] ?? ''))
    ];
}

function is_full_url(string $s): bool {
    return (bool)preg_match('~^https?://~i', $s);
}

function spaces_public_url_for_path(string $path): string {
    $cdn = rtrim(env_required('SPACES_CDN'), '/');
    return $cdn . '/' . ltrim($path, '/');
}

function download_to_temp(string $url, string $outfile): bool {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $code < 200 || $code >= 300) {
        @unlink($outfile);
        return false;
    }

    if (@file_put_contents($outfile, $data) === false) {
        @unlink($outfile);
        return false;
    }

    if (!is_file($outfile) || filesize($outfile) <= 0) {
        @unlink($outfile);
        return false;
    }

    return true;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function presign_spaces_put_via_internal_endpoint(string $cookieHeader, array $payload): array {
    $url = 'http://127.0.0.1/student/api/progress_test_spaces_presign.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $cookieHeader !== '' ? ('Cookie: ' . $cookieHeader) : null
        ]),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($out === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Presign request failed (HTTP {$code}) {$err} " . substr((string)$out, 0, 300));
    }

    $j = json_decode((string)$out, true);
    if (!is_array($j) || empty($j['ok']) || empty($j['url']) || empty($j['public_url'])) {
        throw new RuntimeException('Invalid presign response');
    }

    return $j;
}

function upload_file_to_presigned_put(string $putUrl, string $localFile, string $contentType): void {
    if (!is_file($localFile)) {
        throw new RuntimeException('Local file not found for upload: ' . $localFile);
    }

    $fh = fopen($localFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot open local file for upload: ' . $localFile);
    }

    $size = filesize($localFile);
    $ch = curl_init($putUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $size,
            'x-amz-acl: public-read'
        ],
        CURLOPT_TIMEOUT => 300
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($out === false || $code < 200 || $code >= 299) {
        throw new RuntimeException("Spaces upload failed (HTTP {$code}) {$err} " . substr((string)$out, 0, 300));
    }
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Forbidden']);
    }

    $data = read_json_body();
    if (!$data) json_out(['ok' => false, 'error' => 'Invalid JSON']);

    $testId = (int)($data['test_id'] ?? 0);
    if ($testId <= 0) json_out(['ok' => false, 'error' => 'Missing test_id']);

    $userId = (int)($u['id'] ?? 0);
    if ($role === 'student') {
        $own = $pdo->prepare("SELECT 1 FROM progress_tests_v2 WHERE id=? AND user_id=? LIMIT 1");
        $own->execute([$testId, $userId]);
        if (!$own->fetchColumn()) {
            http_response_code(403);
            json_out(['ok' => false, 'error' => 'Forbidden']);
        }
    }

    $tst = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id=? LIMIT 1");
    $tst->execute([$testId]);
    $test = $tst->fetch(PDO::FETCH_ASSOC);
    if (!$test) json_out(['ok' => false, 'error' => 'Test not found']);

    $cohortId = (int)($test['cohort_id'] ?? 0);
    $lessonId = (int)($test['lesson_id'] ?? 0);

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) $apiKey = getenv('CW_OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('Missing OPENAI_API_KEY');

    $ttsModel = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $ttsVoice = getenv('CW_OPENAI_TTS_VOICE') ?: 'alloy';

    $pdo->prepare("UPDATE progress_tests_v2 SET status='processing', updated_at=NOW() WHERE id=?")->execute([$testId]);

    $itemsSt = $pdo->prepare("SELECT * FROM progress_test_items_v2 WHERE test_id=? ORDER BY idx ASC");
    $itemsSt->execute([$testId]);
    $items = $itemsSt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) json_out(['ok' => false, 'error' => 'No items found']);

    $tmpDir = test_dir($testId);

    $updItem = $pdo->prepare("
      UPDATE progress_test_items_v2
      SET transcript_text=?, is_correct=?, score_points=?, max_points=?, updated_at=NOW()
      WHERE id=?
    ");

    $totalScore = 0;
    $totalMax = 0;
    $log = [];

    foreach ($items as $item) {
        $kind = (string)$item['kind'];
        $options = json_decode((string)($item['options_json'] ?? '[]'), true) ?: [];
        $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
        $audioRel = trim((string)($item['audio_path'] ?? ''));
        $transcript = trim((string)($item['transcript_text'] ?? ''));

        if ($transcript === '') {
            if ($audioRel !== '') {
                $sourceUrl = is_full_url($audioRel) ? $audioRel : spaces_public_url_for_path($audioRel);
                $localAudio = $tmpDir . '/answer_' . (int)$item['idx'] . '.webm';

                if (download_to_temp($sourceUrl, $localAudio)) {
                    $transcript = transcribe_file($apiKey, $localAudio);
                }
            }
        }

        if ($transcript === '') $transcript = '[NO AUDIO]';

        if ($transcript === '[TIMEOUT]') {
            $grade = [
                'is_correct'   => 0,
                'score_points' => 0,
                'max_points'   => ($kind === 'open') ? max(1, (int)($correct['min_points_to_pass'] ?? 2)) : 1,
                'feedback'     => 'No answer recorded.'
            ];
        } elseif ($kind === 'yesno') {
            $grade = grade_yesno($transcript, $correct);
        } elseif ($kind === 'mcq') {
            $grade = grade_mcq($transcript, $correct, $options);
        } else {
            $grade = grade_open_with_ai($item, $transcript);
        }

        $updItem->execute([
            $transcript,
            (int)$grade['is_correct'],
            (int)$grade['score_points'],
            (int)$grade['max_points'],
            (int)$item['id']
        ]);

        $totalScore += (int)$grade['score_points'];
        $totalMax   += (int)$grade['max_points'];

        $log[] = [
            'idx'          => (int)$item['idx'],
            'kind'         => $kind,
            'prompt'       => (string)$item['prompt'],
            'transcript'   => $transcript,
            'is_correct'   => (int)$grade['is_correct'],
            'score_points' => (int)$grade['score_points'],
            'max_points'   => (int)$grade['max_points'],
            'feedback'     => (string)($grade['feedback'] ?? ''),
        ];
    }

    $scorePct = ($totalMax > 0) ? (int)round(($totalScore / $totalMax) * 100) : 0;

    $nq = $pdo->prepare("
      SELECT s.page_number, e.narration_en
      FROM slides s
      JOIN slide_enrichment e ON e.slide_id = s.id
      WHERE s.lesson_id=? AND s.is_deleted=0
        AND e.narration_en IS NOT NULL AND e.narration_en <> ''
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

    $sq = $pdo->prepare("
      SELECT summary_plain
      FROM lesson_summaries
      WHERE user_id=? AND cohort_id=? AND lesson_id=?
      LIMIT 1
    ");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summaryPlain = trim((string)($sq->fetchColumn() ?: ''));
    if ($summaryPlain === '') $summaryPlain = "(No student summary.)";

    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "written_debrief" => ["type" => "string"],
            "spoken_debrief"  => ["type" => "string"],
            "weak_areas"      => ["type" => "string"]
        ],
        "required" => ["written_debrief", "spoken_debrief", "weak_areas"]
    ];

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role" => "system", "content" => [
                ["type" => "input_text", "text" =>
"You are a strict but supportive flight instructor.

SOURCE OF TRUTH:
- Lesson narration scripts are the only truth source.
- Student summary is not truth and may contain mistakes.

TASK:
1) Write a concise but useful written debrief.
2) Write a spoken debrief suitable for TTS.
3) Identify weak areas the student should review.

Do not invent facts beyond the lesson narration."
                ]
            ]],
            ["role" => "user", "content" => [
                ["type" => "input_text", "text" =>
"SCORE: {$scorePct}%\n\nLESSON NARRATION (TRUTH):\n{$truthText}\n\nSTUDENT SUMMARY:\n{$summaryPlain}\n\nTEST LOG JSON:\n" .
json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]
            ]]
        ],
        "text" => [
            "format" => [
                "type"   => "json_schema",
                "name"   => "debrief_v2",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.2
    ];

    $written = "Score {$scorePct}%.";
    $spoken  = "Your score is {$scorePct} percent. Please review your results.";
    $weak    = "Review the items you missed.";

    try {
        $resp = cw_openai_responses($payload);
        $j = cw_openai_extract_json_text($resp);
        $written = trim((string)($j['written_debrief'] ?? $written));
        $spoken  = trim((string)($j['spoken_debrief'] ?? $spoken));
        $weak    = trim((string)($j['weak_areas'] ?? $weak));
    } catch (Throwable $e) {
        // keep fallbacks
    }

    $resultAudioLocal = $tmpDir . '/result.mp3';
    $name = trim((string)($u['name'] ?? 'student'));
    if ($name === '') $name = 'student';
    $resultSpeech = "Thank you {$name}. Your score is {$scorePct} percent. {$spoken}";
    tts_write_file($apiKey, $ttsModel, $ttsVoice, $resultSpeech, $resultAudioLocal);

    $cookieHeader = '';
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $cookieHeader = (string)$_SERVER['HTTP_COOKIE'];
    }

    $resultPresign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
        'test_id' => $testId,
        'kind'    => 'result',
        'ext'     => 'mp3'
    ]);
    upload_file_to_presigned_put((string)$resultPresign['url'], $resultAudioLocal, 'audio/mpeg');
    $resultAudioUrl = (string)$resultPresign['public_url'];

    $upTest = $pdo->prepare("
      UPDATE progress_tests_v2
      SET status='completed',
          score_pct=?,
          ai_summary=?,
          weak_areas=?,
          debrief_spoken=?,
          completed_at=NOW(),
          updated_at=NOW()
      WHERE id=?
    ");
    $upTest->execute([$scorePct, $written, $weak, $spoken, $testId]);

    json_out([
        'ok'           => true,
        'test_id'      => $testId,
        'score_pct'    => $scorePct,
        'ai_summary'   => $written,
        'weak_areas'   => $weak,
        'result_audio' => $resultAudioUrl,
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_out(['ok' => false, 'error' => $e->getMessage()]);
}