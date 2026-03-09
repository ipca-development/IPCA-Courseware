<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

while (ob_get_level()) { ob_end_clean(); }
ob_start();

function json_out(array $x): void {
    while (ob_get_level() > 1) { ob_end_clean(); }
    if (ob_get_level() === 1) ob_clean();
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

function ai_prompt_fetch(PDO $pdo, string $promptKey, string $fallback): string {
    try {
        $st = $pdo->prepare("
            SELECT prompt_text
            FROM ai_prompts
            WHERE prompt_key = ?
            LIMIT 1
        ");
        $st->execute([$promptKey]);
        $txt = $st->fetchColumn();
        if (is_string($txt) && trim($txt) !== '') {
            return $txt;
        }
    } catch (Throwable $e) {
        // fall back silently
    }
    return $fallback;
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
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
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

/**
 * Build keyword aliases for concept-based grading.
 * Accepts strings or maps like:
 * [
 *   "yaw",
 *   ["move nose left right", "directional control"],
 *   "stability"
 * ]
 */
function build_alias_groups(array $correct, array $fallbackKeyPoints = []): array {
    $groups = [];

    $raw = $correct['aliases'] ?? [];
    if (!is_array($raw)) $raw = [];

    foreach ($raw as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
            $groups[] = [trim($entry)];
        } elseif (is_array($entry)) {
            $grp = [];
            foreach ($entry as $v) {
                if (is_string($v) && trim($v) !== '') $grp[] = trim($v);
            }
            if ($grp) $groups[] = $grp;
        }
    }

    foreach ($fallbackKeyPoints as $kp) {
        if (!is_string($kp)) continue;
        $kp = trim($kp);
        if ($kp !== '') $groups[] = [$kp];
    }

    return $groups;
}

function text_matches_phrase(string $transcript, string $phrase): bool {
    $t = normalize_text($transcript);
    $p = normalize_text($phrase);

    if ($t === '' || $p === '') return false;
    if (strpos($t, $p) !== false) return true;

    $tWords = array_values(array_filter(explode(' ', $t)));
    $pWords = array_values(array_filter(explode(' ', $p)));
    if (!$tWords || !$pWords) return false;

    $hits = 0;
    $need = 0;
    foreach ($pWords as $w) {
        if (strlen($w) < 3) continue;
        $need++;
        foreach ($tWords as $tw) {
            if ($tw === $w || (strlen($w) >= 4 && strlen($tw) >= 4 && (strpos($tw, $w) === 0 || strpos($w, $tw) === 0))) {
                $hits++;
                break;
            }
        }
    }

    if ($need === 0) return false;
    return $hits >= max(1, $need - 1);
}

function transcript_is_contradictory_to_bool(string $transcript, bool $correctValue): bool {
    $t = normalize_text($transcript);

    $affirmativePatterns = [
        '/\byes\b/', '/\btrue\b/', '/\byep\b/', '/\byeah\b/', '/\bcorrect\b/', '/\bindeed\b/',
        '/\bit is\b/', '/\bit does\b/', '/\bthat is the case\b/', '/\bthat s the case\b/',
        '/\bin most designs\b/', '/\bthere are\b/', '/\bthere is\b/'
    ];

    $negativePatterns = [
        '/\bno\b/', '/\bfalse\b/', '/\bdoes not\b/', '/\bdo not\b/', '/\bis not\b/',
        '/\baren t\b/', '/\bare not\b/', '/\bnever\b/', '/\bthat is not the case\b/',
        '/\bthat s not the case\b/', '/\bnope\b/'
    ];

    $yesScore = 0;
    $noScore = 0;

    foreach ($affirmativePatterns as $rx) {
        if (preg_match($rx, $t)) $yesScore++;
    }
    foreach ($negativePatterns as $rx) {
        if (preg_match($rx, $t)) $noScore++;
    }

    if ($yesScore === 0 && $noScore === 0) return false;
    $semantic = ($yesScore >= $noScore);

    return $semantic !== $correctValue;
}

function grade_yesno(string $transcript, array $correct): array {
    $t = normalize_text($transcript);

    $affirmativePatterns = [
        '/\byes\b/',
        '/\btrue\b/',
        '/\byep\b/',
        '/\byeah\b/',
        '/\bcorrect\b/',
        '/\bindeed\b/',
        '/\bit is\b/',
        '/\bit does\b/',
        '/\bthat is the case\b/',
        '/\bthat s the case\b/',
        '/\bin most designs\b/',
        '/\bthere are\b/',
        '/\bthere is\b/',
    ];

    $negativePatterns = [
        '/\bno\b/',
        '/\bfalse\b/',
        '/\bdoes not\b/',
        '/\bdo not\b/',
        '/\bis not\b/',
        '/\baren t\b/',
        '/\bare not\b/',
        '/\bnever\b/',
        '/\bthat is not the case\b/',
        '/\bthat s not the case\b/',
        '/\bnope\b/',
    ];

    $yesScore = 0;
    $noScore = 0;

    foreach ($affirmativePatterns as $rx) {
        if (preg_match($rx, $t)) $yesScore++;
    }
    foreach ($negativePatterns as $rx) {
        if (preg_match($rx, $t)) $noScore++;
    }

    if (strpos($t, 'all airplanes have brakes on the main gear') !== false) $yesScore += 2;
    if (strpos($t, 'in most designs') !== false) $yesScore += 2;
    if (strpos($t, 'the airplane produces 180 horsepower') !== false) $yesScore += 2;
    if (strpos($t, 'that s correct') !== false) $yesScore += 2;
    if (strpos($t, 'that is correct') !== false) $yesScore += 2;

    $sv = null;
    if ($yesScore > 0 && $noScore === 0) {
        $sv = true;
    } elseif ($noScore > 0 && $yesScore === 0) {
        $sv = false;
    } elseif ($yesScore > $noScore) {
        $sv = true;
    } elseif ($noScore > $yesScore) {
        $sv = false;
    }

    // Fallback for implicit spoken answers when student does not literally say yes/no
    if ($sv === null) {
        if (
            preg_match('/\b(is not|are not|does not|do not|cannot|can not|never|no longer|without)\b/', $t)
        ) {
            $sv = false;
        } elseif (
            preg_match('/\b(attached|connected|located|mounted|present|included|used|provides|has brakes|have brakes|helps|assists|supports|contains|is air cooled|are air cooled)\b/', $t)
        ) {
            $sv = true;
        }
    }

    $cv = (bool)($correct['value'] ?? false);

    $aliases = build_alias_groups($correct, []);
    $aliasHits = 0;
    foreach ($aliases as $grp) {
        foreach ($grp as $phrase) {
            if (text_matches_phrase($transcript, $phrase)) {
                $aliasHits++;
                break;
            }
        }
    }

    $feedback = '';
    $score = 0;

    if ($sv !== null && $sv === $cv) {
        $score = 1;
        if ($aliases && $aliasHits === 0) {
            $feedback = 'Correct yes/no direction. Review the explanation detail for completeness.';
        }
    } else {
        if ($aliases && $aliasHits > 0 && !transcript_is_contradictory_to_bool($transcript, $cv)) {
            $score = 1;
            $feedback = 'Accepted on concept evidence despite indirect yes/no phrasing.';
        }
    }

    return [
        'is_correct'   => $score >= 1 ? 1 : 0,
        'score_points' => $score,
        'max_points'   => 1,
        'feedback'     => $feedback
    ];
}

function mcq_text_score(string $transcript, string $option): int {
    $t = normalize_text($transcript);
    $o = normalize_text($option);

    if ($t === '' || $o === '') return 0;
    if (strpos($t, $o) !== false) return 100;

    $tWords = array_values(array_filter(explode(' ', $t)));
    $oWords = array_values(array_filter(explode(' ', $o)));
    if (!$tWords || !$oWords) return 0;

    $score = 0;
    $matched = 0;

    foreach ($oWords as $w) {
        if (strlen($w) < 3) continue;

        foreach ($tWords as $tw) {
            if ($tw === $w) {
                $score += 8;
                $matched++;
                break;
            }
            if (strlen($w) >= 4 && strlen($tw) >= 4) {
                if (strpos($tw, $w) === 0 || strpos($w, $tw) === 0) {
                    $score += 6;
                    $matched++;
                    break;
                }
            }
        }
    }

    $significant = 0;
    foreach ($oWords as $w) {
        if (strlen($w) >= 4) $significant++;
    }
    if ($significant > 0 && $matched >= max(1, $significant - 1)) {
        $score += 20;
    }

    return $score;
}

function best_alias_score(string $transcript, array $groups): int {
    $best = 0;
    foreach ($groups as $grp) {
        foreach ($grp as $phrase) {
            $best = max($best, mcq_text_score($transcript, (string)$phrase));
        }
    }
    return $best;
}

function grade_mcq(string $transcript, array $correct, array $options): array {
    $t = normalize_text($transcript);
    $idx = -1;

    if (preg_match('/\b(a|b|c|d)\b/', $t, $m)) {
        $map = ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3];
        $idx = $map[$m[1]];
    } elseif (preg_match('/\b(option a|option b|option c|option d)\b/', $t, $m)) {
        $map = ['option a' => 0, 'option b' => 1, 'option c' => 2, 'option d' => 3];
        $idx = $map[$m[1]];
    } elseif (preg_match('/\b(1|2|3|4)\b/', $t, $m)) {
        $idx = ((int)$m[1]) - 1;
    } else {
        $bestIdx = -1;
        $bestScore = -1;
        $secondBest = -1;

        foreach ($options as $i => $opt) {
            $score = mcq_text_score($transcript, (string)$opt);

            if ($score > $bestScore) {
                $secondBest = $bestScore;
                $bestScore = $score;
                $bestIdx = (int)$i;
            } elseif ($score > $secondBest) {
                $secondBest = $score;
            }
        }

        if ($bestIdx >= 0 && $bestScore >= 10 && ($bestScore - $secondBest) >= 2) {
            $idx = $bestIdx;
        }
    }

    $ci = -1;
    if (isset($correct['index']) && $correct['index'] !== null) {
        $ci = (int)$correct['index'];
    }

    $groups = [];
    if (!empty($correct['answer_text'])) {
        $groups[] = [(string)$correct['answer_text']];
    }

    $alts = $correct['alternatives'] ?? [];
    if (is_array($alts)) {
        $grp = [];
        foreach ($alts as $alt) {
            if (is_string($alt) && trim($alt) !== '') $grp[] = trim($alt);
        }
        if ($grp) $groups[] = $grp;
    }

    $aliasGroups = build_alias_groups($correct, []);
    foreach ($aliasGroups as $g) $groups[] = $g;

    if ($ci < 0 && $groups) {
        $bestCorrectScore = best_alias_score($transcript, $groups);

        $ok = ($bestCorrectScore >= 10) ? 1 : 0;
        return [
            'is_correct'   => $ok,
            'score_points' => $ok,
            'max_points'   => 1,
            'feedback'     => ''
        ];
    }

    $ok = ($idx === $ci && $ci >= 0) ? 1 : 0;

    if (!$ok && $groups) {
        $bestCorrectScore = best_alias_score($transcript, $groups);
        if ($bestCorrectScore >= 10) $ok = 1;
    }

    return [
        'is_correct'   => $ok,
        'score_points' => $ok,
        'max_points'   => 1,
        'feedback'     => ''
    ];
}

function grade_open_with_ai(array $item, string $transcript): array {
    global $pdo;

    $correct = json_decode((string)($item['correct_json'] ?? '{}'), true) ?: [];
    $keyPoints = $correct['key_points'] ?? [];
    if (!is_array($keyPoints)) $keyPoints = [];

    $aliasGroups = build_alias_groups($correct, $keyPoints);

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

    $openGradeSystemFallback = <<<'TXT'
Grade like a supportive but standards-based flight instructor during an oral progress check.

Rules:
- Reward correct operational understanding even when phrasing is informal.
- Accept plain-English phrasing.
- Do not require textbook wording.
- Do not penalize grammar, accent, incomplete sentence structure, or short answers if the concept is correct.
- Do not punish over-answering unless the student clearly contradicts the correct concept.
- For open answers, passing depends on enough correct core concepts, not a full textbook recital.
- Be slightly generous on borderline oral responses when the correct concept is clearly present.
- Penalize only actual conceptual errors or missing core elements.
- Distinguish clearly between:
  1) conceptually correct but incomplete,
  2) partially correct,
  3) incorrect.
- If the student clearly states the main operational concept correctly, award meaningful partial credit even if supporting details are missing.
- If the answer is concise but operationally correct, treat it as valid.
- If the answer contains the correct main concept and no contradiction, do not score it like a failure just because it is incomplete.
- Strong partial credit should be common for operationally correct but incomplete oral answers.
TXT;

    $openGradeUserFallback = <<<'TXT'
QUESTION:
{{QUESTION}}

RUBRIC KEY POINTS:
- {{KEY_POINTS_BULLETS}}

ALIAS GROUPS / ACCEPTED CONCEPT PHRASES:
{{ALIAS_GROUPS_JSON}}

MIN POINTS TO PASS: {{MIN_POINTS_TO_PASS}}

STUDENT TRANSCRIPT:
{{TRANSCRIPT}}
TXT;

    $systemPrompt = ai_prompt_fetch($pdo, 'progress_test_open_grading_system', $openGradeSystemFallback);
    $userPromptTemplate = ai_prompt_fetch($pdo, 'progress_test_open_grading_user', $openGradeUserFallback);

    $keyPointsBullets = implode("\n- ", $keyPoints);
    $userPrompt = strtr($userPromptTemplate, [
        '{{QUESTION}}' => (string)$item['prompt'],
        '{{KEY_POINTS_BULLETS}}' => $keyPointsBullets,
        '{{ALIAS_GROUPS_JSON}}' => json_encode($aliasGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '{{MIN_POINTS_TO_PASS}}' => (string)$minPts,
        '{{TRANSCRIPT}}' => $transcript
    ]);

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role" => "system", "content" => [
                ["type" => "input_text", "text" => $systemPrompt]
            ]],
            ["role" => "user", "content" => [
                ["type" => "input_text", "text" => $userPrompt]
            ]]
        ],
        "text" => [
            "format" => [
                "type"   => "json_schema",
                "name"   => "open_grade_v3",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.1
    ];

    $resp = cw_openai_responses($payload);
    $j = cw_openai_extract_json_text($resp);

    $maxPoints = max(1, count($keyPoints));
    $scorePoints = (int)($j['score_points'] ?? 0);
    $returnedMax = (int)($j['max_points'] ?? $maxPoints);
    if ($returnedMax > 0) $maxPoints = max($maxPoints, $returnedMax);

    if ($scorePoints < 0) $scorePoints = 0;
    if ($scorePoints > $maxPoints) $scorePoints = $maxPoints;

    $aliasHits = 0;
    foreach ($aliasGroups as $grp) {
        foreach ($grp as $phrase) {
            if (text_matches_phrase($transcript, (string)$phrase)) {
                $aliasHits++;
                break;
            }
        }
    }

    if ($aliasHits >= 2) {
        $scorePoints = max($scorePoints, min($maxPoints, $minPts));
    } elseif ($aliasHits >= 1) {
        $scorePoints = max($scorePoints, min($maxPoints, max(1, (int)ceil($maxPoints / 2))));
    }

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
    $ttsVoice = getenv('CW_OPENAI_TTS_VOICE') ?: 'marin';

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
            "weak_areas"      => ["type" => "string"],
            "summary_quality" => ["type" => "string"],
            "summary_issues" => ["type" => "string"],
            "summary_corrections" => ["type" => "string"],
            "confirmed_misunderstandings" => ["type" => "string"]
        ],
        "required" => [
            "written_debrief",
            "spoken_debrief",
            "weak_areas",
            "summary_quality",
            "summary_issues",
            "summary_corrections",
            "confirmed_misunderstandings"
        ]
    ];

    $debriefSystemFallback = <<<'TXT'
You are a supportive but standards-based flight instructor.

SOURCE OF TRUTH:
- Lesson narration scripts are the only truth source.
- Student summary may contain mistakes.

TASKS:
1) Write a concise written debrief of the oral test.
2) Write a spoken debrief suitable for TTS.
3) Identify review areas from the oral answers.
4) Separately evaluate the quality of the student summary.
5) List factual mistakes, misleading wording, or important omissions in the summary.
6) Provide concise corrected summary wording.
7) Identify concepts that appear weak both in the oral answers and in the summary, because those likely indicate a genuine misunderstanding.

IMPORTANT RULES:
- Do NOT change or reinterpret the numeric score.
- Do NOT blend summary issues into the oral score.
- Be fair and slightly cautious in wording.
- Prefer phrases like 'review these areas' rather than overconfident claims like 'you misunderstood X', unless clearly wrong.
- Reward correct operational understanding even when phrasing is informal.
TXT;

    $debriefUserFallback = <<<'TXT'
SCORE: {{SCORE}}%

LESSON NARRATION (TRUTH):
{{TRUTH_TEXT}}

STUDENT SUMMARY:
{{SUMMARY_PLAIN}}

TEST LOG JSON:
{{TEST_LOG_JSON}}
TXT;

    $systemPrompt = ai_prompt_fetch($pdo, 'progress_test_debrief_system', $debriefSystemFallback);
    $userPromptTemplate = ai_prompt_fetch($pdo, 'progress_test_debrief_user', $debriefUserFallback);

    $userPrompt = strtr($userPromptTemplate, [
        '{{SCORE}}' => (string)$scorePct,
        '{{TRUTH_TEXT}}' => $truthText,
        '{{SUMMARY_PLAIN}}' => $summaryPlain,
        '{{TEST_LOG_JSON}}' => json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);

    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            ["role" => "system", "content" => [
                ["type" => "input_text", "text" => $systemPrompt]
            ]],
            ["role" => "user", "content" => [
                ["type" => "input_text", "text" => $userPrompt]
            ]]
        ],
        "text" => [
            "format" => [
                "type"   => "json_schema",
                "name"   => "debrief_v3",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.2
    ];

    $written = "You completed the progress test. Review the areas below.";
    $spoken  = "You completed the progress test. Please review the areas below.";
    $weak    = "Review the items that were incomplete or uncertain.";
    $summaryQuality = "Summary quality could not be fully assessed.";
    $summaryIssues = "No specific summary issues were extracted.";
    $summaryCorrections = "No specific summary corrections were generated.";
    $confirmedMisunderstandings = "No repeated misunderstanding pattern was confirmed.";

    try {
        $resp = cw_openai_responses($payload);
        $j = cw_openai_extract_json_text($resp);
        $written = trim((string)($j['written_debrief'] ?? $written));
        $spoken  = trim((string)($j['spoken_debrief'] ?? $spoken));
        $weak    = trim((string)($j['weak_areas'] ?? $weak));
        $summaryQuality = trim((string)($j['summary_quality'] ?? $summaryQuality));
        $summaryIssues = trim((string)($j['summary_issues'] ?? $summaryIssues));
        $summaryCorrections = trim((string)($j['summary_corrections'] ?? $summaryCorrections));
        $confirmedMisunderstandings = trim((string)($j['confirmed_misunderstandings'] ?? $confirmedMisunderstandings));
    } catch (Throwable $e) {
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
        'ok'                          => true,
        'test_id'                     => $testId,
        'score_pct'                   => $scorePct,
        'ai_summary'                  => $written,
        'weak_areas'                  => $weak,
        'summary_quality'             => $summaryQuality,
        'summary_issues'              => $summaryIssues,
        'summary_corrections'         => $summaryCorrections,
        'confirmed_misunderstandings' => $confirmedMisunderstandings,
        'result_audio'                => $resultAudioUrl,
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_out(['ok' => false, 'error' => $e->getMessage()]);
}