<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function storage_base_dir(): string {
    return dirname(__DIR__, 3) . '/storage/progress_tests_v2';
}

function tts_write_file(string $apiKey, string $model, string $voice, string $text, string $outfile): void {
    $payload = json_encode([
        'model' => $model,
        'voice' => $voice,
        'format' => 'mp3',
        'input' => $text,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

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
        'file' => $cfile,
        'model' => 'gpt-4o-mini-transcribe',
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

    return ['is_correct'=>$ok, 'score_points'=>$ok, 'max_points'=>1];
}

function grade_mcq(string $transcript, array $correct, array $options): array {
    $t = normalize_text($transcript);
    $idx = -1;

    if (preg_match('/\b(a|b|c|d)\b/', $t, $m)) {
        $map = ['a'=>0,'b'=>1,'c'=>2,'d'=>3];
        $idx = $map[$m[1]];
    } elseif (preg_match('/\b(1|2|3|4)\b/', $t, $m)) {
        $idx = ((int)$m[1]) - 1;
    } else {
        $bestIdx = -1; $bestScore = 0;
        foreach ($options as $i=>$opt) {
            $o = normalize_text((string)$opt);
            if ($o === '') continue;
            $score = 0;
            if (strpos($t, $o) !== false) $score = 100;
            else {
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

    return ['is_correct'=>$ok, 'score_points'=>$ok, 'max_points'=>1];
}

function grade_open_with_ai(array $item, string $transcript): array {
    global $pdo;

    $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
    $keyPoints = $correct['key_points'] ?? [];
    if (!is_array($keyPoints)) $keyPoints = [];
    $minPts = (int)($correct['min_points_to_pass'] ?? 2);
    if ($minPts < 1) $minPts = 1;

    $schema = [
      "type" => "object",
      "additionalProperties" => false,
      "properties" => [
        "score_points" => ["type"=>"integer"],
        "max_points"   => ["type"=>"integer"],
        "is_correct"   => ["type"=>"boolean"],
        "feedback"     => ["type"=>"string"]
      ],
      "required" => ["score_points","max_points","is_correct","feedback"]
    ];

    $payload = [
      "model" => cw_openai_model(),
      "input" => [
        ["role"=>"system","content"=>[
          ["type"=>"input_text","text"=>
"You are grading one open-answer oral progress test response.
Be strict and fair.
Evaluate only against the supplied rubric key points.
Do not invent facts."
          ]
        ]],
        ["role"=>"user","content"=>[
          ["type"=>"input_text","text"=>
"QUESTION:\n" . (string)$item['prompt'] . "\n\nRUBRIC KEY POINTS:\n- " . implode("\n- ", $keyPoints) . "\n\nMIN POINTS TO PASS: {$minPts}\n\nSTUDENT TRANSCRIPT:\n" . $transcript
          ]
        ]]
      ],
      "text" => [
        "format" => [
          "type" => "json_schema",
          "name" => "open_grade_v2",
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
        'is_correct' => $isCorrect,
        'score_points' => $scorePoints,
        'max_points' => $maxPoints,
        'feedback' => trim((string)($j['feedback'] ?? ''))
    ];
}