<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/progress_test_prep.php';
require_once __DIR__ . '/../../../src/progress_test_questions.php';
require_once __DIR__ . '/../../../src/progress_test_bank.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_ok(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(string $s): array {
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
}

function set_prepare_progress(PDO $pdo, int $testId, int $pct, string $text): void {
    $pct = max(0, min(100, $pct));
    // Q3 / BUG B guard:
    // Refuse to write progress updates onto a row that has been moved to a terminal state
    // (STALE_ABORTED, completed, or failed). Without this guard, a long-running background
    // test-prep can resurrect a row that the engine has authoritatively staled, producing
    // the lesson-931-style anomaly (sibling rows in inconsistent states).
    $st = $pdo->prepare("
        UPDATE progress_tests_v2
        SET progress_pct=?,
            status_text=?,
            updated_at=NOW()
        WHERE id=?
          AND status NOT IN ('completed','failed')
          AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
    ");
    $st->execute([$pct, $text, $testId]);

    if ($st->rowCount() === 0) {
        log_bg_skipped_terminal_state($pdo, $testId, 'set_prepare_progress', [
            'attempted_progress_pct' => $pct,
            'attempted_status_text' => $text,
        ]);
    }
}

/**
 * Q3 / BUG B audit helper.
 * Logs a training_progression_events row whenever the background test-prep refuses to write
 * because the row is already in a terminal state. Best-effort; never throws.
 */
function log_bg_skipped_terminal_state(PDO $pdo, int $testId, string $callSite, array $extra = []): void {
    try {
        $info = $pdo->prepare("
            SELECT id, user_id, cohort_id, lesson_id, status, formal_result_code
            FROM progress_tests_v2
            WHERE id = ?
            LIMIT 1
        ");
        $info->execute([$testId]);
        $row = $info->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return;
        }

        $payload = array_merge([
            'progress_test_id' => (int)$row['id'],
            'call_site' => $callSite,
            'observed_status' => (string)$row['status'],
            'observed_formal_result_code' => (string)($row['formal_result_code'] ?? ''),
        ], $extra);

        $ins = $pdo->prepare("
            INSERT INTO training_progression_events
            (user_id, cohort_id, lesson_id, progress_test_id, event_type, event_code, event_status,
             actor_type, actor_user_id, event_time, payload_json, legal_note)
            VALUES
            (:user_id, :cohort_id, :lesson_id, :progress_test_id, 'progress_test', 'bg_skipped_terminal_state', 'info',
             'system', NULL, UTC_TIMESTAMP(), :payload_json,
             'test_prepare_run_v2 refused to overwrite a terminal-state row to preserve engine stale-abort decisions (Q3 / BUG B).')
        ");
        $ins->execute([
            ':user_id' => (int)$row['user_id'],
            ':cohort_id' => (int)$row['cohort_id'],
            ':lesson_id' => (int)$row['lesson_id'],
            ':progress_test_id' => (int)$row['id'],
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        error_log('log_bg_skipped_terminal_state failed: ' . $e->getMessage());
    }
}

function temp_base_dir(): string {
    $dir = '/tmp/progress_tests_v2';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Temp storage directory is not writable: ' . $dir);
    }
    return $dir;
}

function make_test_dir(int $testId): string {
    $dir = temp_base_dir() . '/' . $testId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Test temp directory is not writable: ' . $dir);
    }
    return $dir;
}

function tts_generate_local(string $text, string $file): void {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) $apiKey = getenv('CW_OPENAI_API_KEY');
    if (!$apiKey) {
        throw new RuntimeException('Missing OPENAI_API_KEY');
    }

    $model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $voice = getenv('CW_OPENAI_TTS_VOICE') ?: 'marin';

    $payload = json_encode([
        'model'  => $model,
        'voice'  => $voice,
        'format' => 'mp3',
        'input'  => $text
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 180
    ]);

    $audio = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("TTS failed (HTTP {$code}) {$err}");
    }

    if (@file_put_contents($file, $audio) === false) {
        throw new RuntimeException('Failed to write temp audio file: ' . $file);
    }

    if (!is_file($file) || filesize($file) <= 0) {
        throw new RuntimeException('Generated temp audio file is missing or empty: ' . $file);
    }
}

function clamp_questions(array $questions, int $target): array {
    return ptq_clamp_questions($questions, $target);
}

function get_progress_test_question_count(PDO $pdo): int {
    return ptq_get_question_count($pdo);
}

function normalize_generated_questions(array $questions): array {
    return ptq_normalize_questions($questions);
}

function question_schema(): array {
    return ptq_question_schema();
}

function ai_prompt_fetch(PDO $pdo, string $promptKey, string $fallback): string {
    return ptq_ai_prompt_fetch($pdo, $promptKey, $fallback);
}

function generate_oral_questions(PDO $pdo, string $truth, string $summary): array {
    return ptq_generate_oral_questions($pdo, $truth, $summary);
}

function validate_and_rewrite_questions(PDO $pdo, string $truth, array $questions): array {
    return ptq_validate_and_rewrite_questions($pdo, $truth, $questions);
}

function presign_spaces_put_via_internal_endpoint(string $cookieHeader, array $payload): array {
    $url = 'https://ipca.training/student/api/progress_test_spaces_presign.php';

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

    $j = read_json((string)$out);
    if (empty($j['ok']) || empty($j['url']) || empty($j['public_url'])) {
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
        json_ok(['ok' => false, 'error' => 'Forbidden']);
    }

    $userId = (int)($u['id'] ?? 0);

    $testId = (int)($_GET['test_id'] ?? 0);
    $cohortId = 0;
    $lessonId = 0;

    if ($testId > 0) {
        $testSt = $pdo->prepare("
            SELECT *
            FROM progress_tests_v2
            WHERE id=?
            LIMIT 1
        ");
        $testSt->execute([$testId]);
        $test = $testSt->fetch(PDO::FETCH_ASSOC);

        if (!$test) {
            json_ok(['ok' => false, 'error' => 'Test not found']);
        }

        $cohortId = (int)($test['cohort_id'] ?? 0);
        $lessonId = (int)($test['lesson_id'] ?? 0);
        $testUserId = (int)($test['user_id'] ?? 0);

        if ($role === 'student' && $testUserId !== $userId) {
            http_response_code(403);
            json_ok(['ok' => false, 'error' => 'Forbidden']);
        }
    } else {
        $data = read_json((string)file_get_contents('php://input'));
        if (!$data) {
            json_ok(['ok' => false, 'error' => 'Invalid JSON']);
        }

        $testId   = (int)($data['test_id'] ?? 0);
        $cohortId = (int)($data['cohort_id'] ?? 0);
        $lessonId = (int)($data['lesson_id'] ?? 0);

        if ($testId <= 0 || $cohortId <= 0 || $lessonId <= 0) {
            json_ok(['ok' => false, 'error' => 'Missing test_id, cohort_id or lesson_id']);
        }
    }

    if ($role === 'student') {
        $en = $pdo->prepare("
            SELECT 1
            FROM cohort_students
            WHERE cohort_id=? AND user_id=?
            LIMIT 1
        ");
        $en->execute([$cohortId, $userId]);
        if (!$en->fetchColumn()) {
            http_response_code(403);
            json_ok(['ok' => false, 'error' => 'Not enrolled']);
        }
    }

    $testSt = $pdo->prepare("
        SELECT *
        FROM progress_tests_v2
        WHERE id=? AND user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $testSt->execute([$testId, $userId, $cohortId, $lessonId]);
    $test = $testSt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        json_ok(['ok' => false, 'error' => 'Test not found']);
    }

    if ((string)($test['status'] ?? '') === 'ready') {
        $manifest = read_json((string)($test['manifest_json'] ?? ''));
        json_ok([
            'ok'              => true,
            'test_id'         => $testId,
            'total_questions' => (int)($manifest['total_questions'] ?? 0),
            'item_ids'        => is_array($manifest['item_ids'] ?? null) ? $manifest['item_ids'] : [],
            'intro_url'       => (string)($manifest['intro_url'] ?? ''),
            'question_urls'   => is_array($manifest['question_urls'] ?? null) ? $manifest['question_urls'] : []
        ]);
    }

    set_prepare_progress($pdo, $testId, 3, 'Initializing progress test...');
    $testDir = make_test_dir($testId);
    set_prepare_progress($pdo, $testId, 6, 'Loading lesson content...');

    $nq = $pdo->prepare("
        SELECT e.narration_en
        FROM slides s
        JOIN slide_enrichment e ON e.slide_id = s.id
        WHERE s.lesson_id=? AND s.is_deleted=0
          AND e.narration_en IS NOT NULL AND e.narration_en <> ''
        ORDER BY s.page_number
    ");
    $nq->execute([$lessonId]);
    $nrows = $nq->fetchAll(PDO::FETCH_ASSOC);

    $truth = "";
    foreach ($nrows as $r) {
        $truth .= trim((string)$r['narration_en']) . "\n\n";
    }
    if (trim($truth) === '') {
        $truth = "(No narration scripts available.)";
    }

    $sq = $pdo->prepare("
        SELECT summary_plain
        FROM lesson_summaries
        WHERE user_id=? AND cohort_id=? AND lesson_id=?
        LIMIT 1
    ");
    $sq->execute([$userId, $cohortId, $lessonId]);
    $summary = trim((string)$sq->fetchColumn());
    if ($summary === '') $summary = "(No student summary.)";

    $target = get_progress_test_question_count($pdo);
    $bankRows = [];
    $usedBank = false;

    set_prepare_progress($pdo, $testId, 12, 'Checking lesson question bank...');
    $bankReady = pt_bank_is_ready_for_prep($pdo, $lessonId);
    if ($bankReady['ready']) {
        try {
            $bankRows = pt_bank_sample_for_attempt($pdo, $lessonId, $userId, $target);
            if (count($bankRows) >= $target) {
                $usedBank = true;
                set_prepare_progress($pdo, $testId, 45, 'Sampling questions from lesson bank...');
            }
        } catch (Throwable $e) {
            $bankRows = [];
        }
    }

    if (!$usedBank) {
        if (!$bankReady['ready']) {
            set_prepare_progress($pdo, $testId, 15, 'Question bank not ready — generating questions...');
        }

        set_prepare_progress($pdo, $testId, 15, 'Generating oral questions...');
        $q = generate_oral_questions($pdo, $truth, $summary);

        if (count($q) < (int)floor($target * 0.5)) {
            set_prepare_progress($pdo, $testId, 30, 'Low question count detected, applying fallback...');
        }

        set_prepare_progress($pdo, $testId, 35, 'Checking question quality...');

        if (count($q) >= 3) {
            $q2 = validate_and_rewrite_questions($pdo, $truth, $q);

            if (count($q2) >= count($q)) {
                $q = $q2;
            }
        }

        set_prepare_progress($pdo, $testId, 50, 'Saving questions...');

        if (!is_array($q) || count($q) < 1) {
            throw new RuntimeException('AI returned zero valid questions.');
        }

        if (count($q) < $target) {
            $fallback = [
                [
                    'kind' => 'yesno',
                    'prompt' => 'Is the checklist used to reduce errors? Answer yes or no, and briefly explain.',
                    'options' => [],
                    'correct' => [
                        'value' => true,
                        'answer_text' => null,
                        'alternatives' => [],
                        'type' => null,
                        'key_points' => [],
                        'min_points_to_pass' => null
                    ]
                ],
                [
                    'kind' => 'mcq',
                    'prompt' => 'Is the role of the wings to produce lift or to turn the airplane left and right?',
                    'options' => ['produce lift', 'turn the airplane left and right'],
                    'correct' => [
                        'value' => null,
                        'answer_text' => 'produce lift',
                        'alternatives' => ['lift', 'to produce lift'],
                        'type' => 'oral_choice',
                        'key_points' => [],
                        'min_points_to_pass' => null
                    ]
                ],
                [
                    'kind' => 'open',
                    'prompt' => 'Explain why pilots use checklists.',
                    'options' => [],
                    'correct' => [
                        'value' => null,
                        'answer_text' => null,
                        'alternatives' => [],
                        'type' => 'rubric',
                        'key_points' => ['reduce errors', 'standardize', 'safety'],
                        'min_points_to_pass' => 2
                    ]
                ],
            ];

            $i = 0;
            while (count($q) < $target) {
                $q[] = $fallback[$i % count($fallback)];
                $i++;
            }

            $q = normalize_generated_questions($q);
        }
    } else {
        set_prepare_progress($pdo, $testId, 50, 'Saving bank questions...');
    }

    $pdo->prepare("DELETE FROM progress_test_items_v2 WHERE test_id=?")->execute([$testId]);

    $ins = $pdo->prepare("
        INSERT INTO progress_test_items_v2
        (test_id, idx, kind, prompt, options_json, correct_json, bank_question_id)
        VALUES (?,?,?,?,?,?,?)
    ");

    $idx = 1;
    if ($usedBank) {
        foreach ($bankRows as $bq) {
            $kind = trim((string)($bq['kind'] ?? 'open'));
            $prompt = trim((string)($bq['prompt'] ?? ''));
            if ($prompt === '') $prompt = 'Question ' . $idx;
            $options = (string)($bq['options_json'] ?? '[]');
            $correct = (string)($bq['correct_json'] ?? '{}');
            $bankQid = (int)($bq['id'] ?? 0);

            $ins->execute([$testId, $idx, $kind, $prompt, $options ?: '[]', $correct ?: '{}', $bankQid > 0 ? $bankQid : null]);
            $idx++;
            if ($idx > $target) break;
        }
    } else {
        foreach ($q as $qq) {
            $kind = trim((string)($qq['kind'] ?? 'yesno'));
            $prompt = trim((string)($qq['prompt'] ?? ''));
            if ($prompt === '') $prompt = 'Question ' . $idx;

            $optionsArr = $qq['options'] ?? [];
            if (!is_array($optionsArr)) $optionsArr = [];

            $correctArr = $qq['correct'] ?? [];
            if (!is_array($correctArr)) {
                $correctArr = [
                    'value' => null,
                    'answer_text' => null,
                    'alternatives' => [],
                    'type' => null,
                    'key_points' => [],
                    'min_points_to_pass' => null
                ];
            }

            $options = json_encode($optionsArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $correct = json_encode($correctArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ins->execute([
                $testId,
                $idx,
                $kind,
                $prompt,
                $options ?: '[]',
                $correct ?: '{}',
                null
            ]);

            $idx++;
            if ($idx > $target) break;
        }
    }

    set_prepare_progress($pdo, $testId, 58, 'Preparing audio files...');

    $items = $pdo->prepare("
        SELECT id, idx, prompt, kind, options_json, bank_question_id
        FROM progress_test_items_v2
        WHERE test_id=?
        ORDER BY idx
    ");
    $items->execute([$testId]);
    $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

    $name = trim((string)($u['name'] ?? 'student'));
    if ($name === '') $name = 'student';
    $nameParts = preg_split('/\s+/', $name);
    $firstName = trim((string)($nameParts[0] ?? 'student'));

    $cookieHeader = '';
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $cookieHeader = (string)$_SERVER['HTTP_COOKIE'];
    }

    $questionUrls = [];
    $itemIds = [];
    $totalQuestions = count($itemRows);
    $totalAudio = 1 + count($itemRows);
    $doneAudio = 0;

    if ($usedBank) {
        set_prepare_progress($pdo, $testId, 58, 'Using cached bank audio...');
        try {
            $introUrl = pt_bank_ensure_generic_intro($pdo);
        } catch (Throwable $e) {
            $introText = pt_bank_generic_intro_text();
            $introLocal = $testDir . '/intro.mp3';
            tts_generate_local($introText, $introLocal);
            $introPresign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
                'test_id' => $testId,
                'kind'    => 'intro',
                'ext'     => 'mp3'
            ]);
            upload_file_to_presigned_put((string)$introPresign['url'], $introLocal, 'audio/mpeg');
            $introUrl = (string)$introPresign['public_url'];
        }

        $bankAudioById = [];
        foreach ($bankRows as $bq) {
            $bankAudioById[(int)$bq['id']] = trim((string)($bq['audio_url'] ?? ''));
        }

        $doneAudio = 1;
        foreach ($itemRows as $it) {
            $itemId = (int)$it['id'];
            $itemIds[] = $itemId;
            $bankQid = (int)($it['bank_question_id'] ?? 0);
            $url = $bankQid > 0 ? ($bankAudioById[$bankQid] ?? '') : '';
            if ($url === '') {
                $spoken = pt_prep_spoken_question($it, $totalQuestions);
                $cached = pt_prep_question_audio_cache_get($pdo, $spoken);
                if ($cached && trim((string)$cached['audio_url']) !== '') {
                    $url = (string)$cached['audio_url'];
                } else {
                    $localFile = $testDir . '/q_' . $itemId . '.mp3';
                    tts_generate_local($spoken, $localFile);
                    $presign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
                        'test_id' => $testId,
                        'kind'    => 'question',
                        'item_id' => $itemId,
                        'ext'     => 'mp3'
                    ]);
                    upload_file_to_presigned_put((string)$presign['url'], $localFile, 'audio/mpeg');
                    $url = (string)$presign['public_url'];
                    pt_prep_question_audio_cache_store($pdo, $spoken, $url);
                }
            }
            $questionUrls[$itemId] = $url;
            $doneAudio++;
            $pct = 58 + (int)floor(($doneAudio / $totalAudio) * 40);
            set_prepare_progress($pdo, $testId, $pct, 'Prepared audio ' . $doneAudio . ' of ' . $totalAudio . '...');
        }
    } else {
    $introText = "Hello {$firstName}. Click Ready when you want to start your progress test.";
    $introLocal = $testDir . '/intro.mp3';
    tts_generate_local($introText, $introLocal);

    $introPresign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
        'test_id' => $testId,
        'kind'    => 'intro',
        'ext'     => 'mp3'
    ]);
    upload_file_to_presigned_put((string)$introPresign['url'], $introLocal, 'audio/mpeg');
    $introUrl = (string)$introPresign['public_url'];

    $doneAudio++;
    $pct = 58 + (int)floor(($doneAudio / $totalAudio) * 40);
    set_prepare_progress($pdo, $testId, $pct, 'Uploading audio ' . $doneAudio . ' of ' . $totalAudio . '...');

    foreach ($itemRows as $it) {
        $itemId = (int)$it['id'];
        $itemIds[] = $itemId;

        $spoken = pt_prep_spoken_question($it, $totalQuestions);
        $cached = pt_prep_question_audio_cache_get($pdo, $spoken);

        if ($cached && trim((string)$cached['audio_url']) !== '') {
            $questionUrls[$itemId] = (string)$cached['audio_url'];
            $doneAudio++;
            $pct = 58 + (int)floor(($doneAudio / $totalAudio) * 40);
            set_prepare_progress($pdo, $testId, $pct, 'Using cached audio ' . $doneAudio . ' of ' . $totalAudio . '...');
            continue;
        }

        $localFile = $testDir . '/q_' . $itemId . '.mp3';
        tts_generate_local($spoken, $localFile);

        $presign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
            'test_id' => $testId,
            'kind'    => 'question',
            'item_id' => $itemId,
            'ext'     => 'mp3'
        ]);
        upload_file_to_presigned_put((string)$presign['url'], $localFile, 'audio/mpeg');

        $publicUrl = (string)$presign['public_url'];
        $questionUrls[$itemId] = $publicUrl;
        pt_prep_question_audio_cache_store($pdo, $spoken, $publicUrl);

        $doneAudio++;
        $pct = 58 + (int)floor(($doneAudio / $totalAudio) * 40);
        set_prepare_progress($pdo, $testId, $pct, 'Uploading audio ' . $doneAudio . ' of ' . $totalAudio . '...');
    }
    }

    $manifest = [
        'test_id'         => $testId,
        'total_questions' => count($itemIds),
        'item_ids'        => $itemIds,
        'intro_url'       => $introUrl,
        'question_urls'   => $questionUrls
    ];

    // Q3 / BUG B guard:
    // Only transition to 'ready' if the row is still in a non-terminal state. If the engine
    // (or the TCC repair button) has staled this attempt during the prep window, we must NOT
    // resurrect it. The lesson-931 anomaly was caused by this UPDATE running unconditionally
    // after the engine had already marked the row STALE_ABORTED in a sibling code path.
    $finalize = $pdo->prepare("
        UPDATE progress_tests_v2
        SET status='ready',
            manifest_json=?,
            progress_pct=100,
            status_text='Progress test ready.',
            updated_at=NOW()
        WHERE id=?
          AND status NOT IN ('completed','failed')
          AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
    ");
    $finalize->execute([
        json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $testId
    ]);

    if ($finalize->rowCount() === 0) {
        log_bg_skipped_terminal_state($pdo, $testId, 'final_ready_update', [
            'manifest_total_questions' => count($itemIds),
        ]);

        http_response_code(409);
        json_ok([
            'ok'      => false,
            'error'   => 'attempt_terminal_state_during_prep',
            'message' => 'This progress test attempt was finalized or staled while it was being prepared. Please refresh and start a new attempt.',
            'test_id' => $testId,
        ]);
    }

    json_ok([
        'ok'              => true,
        'test_id'         => $testId,
        'total_questions' => count($itemIds),
        'item_ids'        => $itemIds,
        'intro_url'       => $introUrl,
        'question_urls'   => $questionUrls
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_ok([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}