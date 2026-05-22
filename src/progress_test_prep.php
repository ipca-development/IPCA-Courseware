<?php
declare(strict_types=1);

require_once __DIR__ . '/courseware_progression_v2.php';

function pt_prep_tts_voice(): string
{
    return getenv('CW_OPENAI_TTS_VOICE') ?: (getenv('CW_OPENAI_REALTIME_VOICE') ?: 'marin');
}

function pt_prep_tts_language(): string
{
    return 'en';
}

function pt_prep_spoken_question(array $item, int $total): string
{
    $text = 'Question ' . (int)($item['idx'] ?? 0) . ' of ' . $total . '. ' . trim((string)($item['prompt'] ?? ''));
    $kind = (string)($item['kind'] ?? 'open');
    if ($kind === 'yesno') return $text . ' Please answer yes or no only.';
    if ($kind === 'mcq') return $text . ' Please answer with the correct phrase.';
    return $text . ' Please answer in a short spoken explanation.';
}

function pt_prep_question_text_hash(string $spokenText, ?string $voice = null, ?string $language = null): string
{
    $voice = $voice ?? pt_prep_tts_voice();
    $language = $language ?? pt_prep_tts_language();
    return hash('sha256', $language . '|' . $voice . '|' . trim($spokenText));
}

function pt_prep_ensure_v4_cache_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS progress_test_v4_question_audio_cache (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              text_hash CHAR(64) NOT NULL,
              voice VARCHAR(64) NOT NULL,
              language VARCHAR(16) NOT NULL DEFAULT 'en',
              spoken_text TEXT NOT NULL,
              audio_url VARCHAR(1024) NOT NULL,
              created_at DATETIME NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_ptv4_qaudio (text_hash, voice, language)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function pt_prep_question_audio_cache_get(PDO $pdo, string $spokenText, ?string $voice = null, ?string $language = null): ?array
{
    pt_prep_ensure_v4_cache_table($pdo);
    $voice = $voice ?? pt_prep_tts_voice();
    $language = $language ?? pt_prep_tts_language();
    $hash = pt_prep_question_text_hash($spokenText, $voice, $language);
    $st = $pdo->prepare("
        SELECT spoken_text, audio_url, voice, language
        FROM progress_test_v4_question_audio_cache
        WHERE text_hash = ? AND voice = ? AND language = ?
        LIMIT 1
    ");
    $st->execute([$hash, $voice, $language]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pt_prep_question_audio_cache_store(PDO $pdo, string $spokenText, string $audioUrl, ?string $voice = null, ?string $language = null): void
{
    pt_prep_ensure_v4_cache_table($pdo);
    $voice = $voice ?? pt_prep_tts_voice();
    $language = $language ?? pt_prep_tts_language();
    $hash = pt_prep_question_text_hash($spokenText, $voice, $language);
    $st = $pdo->prepare("
        INSERT INTO progress_test_v4_question_audio_cache
          (text_hash, voice, language, spoken_text, audio_url, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          spoken_text = VALUES(spoken_text),
          audio_url = VALUES(audio_url)
    ");
    $st->execute([$hash, $voice, $language, $spokenText, $audioUrl]);
}

function pt_prep_fire_background_run(int $testId, string $cookieHeader = ''): void
{
    $host = getenv('CW_APP_BASE_URL') ?: 'https://ipca.training';
    $url = rtrim($host, '/') . '/student/api/test_prepare_run_v2.php?test_id=' . urlencode((string)$testId);
    $headers = [];
    if ($cookieHeader !== '') $headers[] = 'Cookie: ' . $cookieHeader;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_CONNECTTIMEOUT_MS => 5000,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

function pt_prep_attempt_has_oral_progress(PDO $pdo, int $testId): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM progress_test_oral_item_responses WHERE attempt_id = ?");
        $st->execute([$testId]);
        if ((int)$st->fetchColumn() > 0) return true;

        $st2 = $pdo->prepare("
            SELECT COUNT(*) FROM progress_test_items_v2
            WHERE test_id = ? AND transcript_text IS NOT NULL AND TRIM(transcript_text) <> ''
        ");
        $st2->execute([$testId]);
        return ((int)$st2->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function pt_prep_attempt_is_prepared(array $attempt, PDO $pdo): bool
{
    $manifest = json_decode((string)($attempt['manifest_json'] ?? ''), true);
    if (!is_array($manifest) || empty($manifest['question_urls'])) return false;
    $st = $pdo->prepare("SELECT COUNT(*) FROM progress_test_items_v2 WHERE test_id = ?");
    $st->execute([(int)$attempt['id']]);
    return ((int)$st->fetchColumn()) > 0;
}

function pt_prep_has_canonical_pass(PDO $pdo, int $userId, int $cohortId, int $lessonId): bool
{
    try {
        $st = $pdo->prepare("
            SELECT 1 FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
              AND pass_gate_met = 1
              AND status = 'completed'
            LIMIT 1
        ");
        $st->execute([$userId, $cohortId, $lessonId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Idempotent background prep scheduler. Safe to call from summary acceptance or V4 page load.
 * Uses canonical progression engine to create attempts; never throws to callers.
 */
function pt_prep_schedule_progress_test(
    PDO $pdo,
    int $userId,
    int $cohortId,
    int $lessonId,
    string $trigger = 'manual',
    string $cookieHeader = '',
    string $actorType = 'system',
    ?int $actorUserId = null
): array {
    try {
        pt_prep_ensure_v4_cache_table($pdo);

        if (pt_prep_has_canonical_pass($pdo, $userId, $cohortId, $lessonId)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'canonical_pass_exists', 'trigger' => $trigger];
        }

        $st = $pdo->prepare("
            SELECT * FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
              AND status IN ('preparing','ready','in_progress','processing')
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$userId, $cohortId, $lessonId]);
        $attempt = $st->fetch(PDO::FETCH_ASSOC);

        if ($attempt && pt_prep_attempt_is_prepared($attempt, $pdo)) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'already_prepared',
                'trigger' => $trigger,
                'test_id' => (int)$attempt['id'],
            ];
        }

        if ($attempt && pt_prep_attempt_has_oral_progress($pdo, (int)$attempt['id'])) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'active_oral_session',
                'trigger' => $trigger,
                'test_id' => (int)$attempt['id'],
            ];
        }

        if (!$attempt) {
            $engine = new CoursewareProgressionV2($pdo);
            $created = $engine->createProgressTestAttempt($userId, $cohortId, $lessonId, $actorType, $actorUserId);
            if (!empty($created['blocked'])) {
                return [
                    'ok' => false,
                    'skipped' => true,
                    'reason' => (string)($created['reason'] ?? 'blocked'),
                    'trigger' => $trigger,
                ];
            }
            $testId = (int)$created['test_id'];
        } else {
            $testId = (int)$attempt['id'];
        }

        $pdo->prepare("
            UPDATE progress_tests_v2
            SET status = 'preparing',
                progress_pct = CASE WHEN progress_pct < 5 THEN 5 ELSE progress_pct END,
                status_text = 'Preparing oral questions and audio...',
                updated_at = NOW()
            WHERE id = ?
              AND status NOT IN ('completed','failed')
              AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
        ")->execute([$testId]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        pt_prep_fire_background_run($testId, $cookieHeader);

        return [
            'ok' => true,
            'scheduled' => true,
            'trigger' => $trigger,
            'test_id' => $testId,
        ];
    } catch (Throwable $e) {
        error_log('pt_prep_schedule_progress_test failed [' . $trigger . ']: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'trigger' => $trigger];
    }
}

function pt_prep_schedule_on_summary_accept(PDO $pdo, int $userId, int $cohortId, int $lessonId, string $cookieHeader = ''): void
{
    pt_prep_schedule_progress_test($pdo, $userId, $cohortId, $lessonId, 'summary_accepted', $cookieHeader);
}

function pt_prep_get_open_attempt(PDO $pdo, int $userId, int $cohortId, int $lessonId): ?array
{
    $st = $pdo->prepare("
        SELECT * FROM progress_tests_v2
        WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
          AND status IN ('preparing','ready','in_progress','processing')
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$userId, $cohortId, $lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pt_prep_attempt_is_resumable(PDO $pdo, int $testId): bool
{
    if (pt_prep_attempt_has_oral_progress($pdo, $testId)) return true;
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM progress_test_v4_card_sessions
            WHERE attempt_id = ?
              AND card_state IN ('asking','listening','evaluating','clarification')
        ");
        $st->execute([$testId]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function pt_prep_progress_label(array $attempt, PDO $pdo): array
{
    $pct = max(0, min(100, (int)($attempt['progress_pct'] ?? 0)));
    $text = strtolower(trim((string)($attempt['status_text'] ?? '')));

    if (pt_prep_attempt_is_prepared($attempt, $pdo)) {
        return ['label' => 'Progress Test ready', 'class' => 'ok', 'pct' => 100, 'sub' => 'Ready'];
    }

    if (strpos($text, 'quality') !== false) {
        return ['label' => 'Checking question quality…', 'class' => 'info', 'pct' => max($pct, 75), 'sub' => 'Preparing'];
    }
    if (strpos($text, 'audio') !== false) {
        return ['label' => 'Preparing Progress Test…', 'class' => 'info', 'pct' => max($pct, 55), 'sub' => 'Preparing'];
    }
    if (strpos($text, 'oral') !== false || strpos($text, 'generat') !== false) {
        return ['label' => 'Generating oral questions…', 'class' => 'info', 'pct' => max($pct, 35), 'sub' => 'Preparing'];
    }

    return ['label' => 'Preparing Progress Test…', 'class' => 'info', 'pct' => max($pct, 10), 'sub' => 'Preparing'];
}

/**
 * Course-page preparation status. Schedules background prep when eligible and not yet prepared.
 */
function pt_prep_course_status(
    PDO $pdo,
    int $userId,
    int $cohortId,
    int $lessonId,
    string $cookieHeader = '',
    string $progressTestUrl = ''
): array {
    $empty = [
        'show_bar' => false,
        'show_button' => false,
        'button_href' => '',
        'button_label' => 'Start Progress Test',
        'prepared' => false,
        'preparing' => false,
        'resume' => false,
        'label' => '',
        'sub' => 'Progress Test',
        'class' => 'neutral',
        'pct' => 0,
        'attempt_id' => null,
    ];

    if (pt_prep_has_canonical_pass($pdo, $userId, $cohortId, $lessonId)) {
        return array_merge($empty, [
            'prepared' => true,
            'label' => 'Passed',
            'class' => 'ok',
            'pct' => 100,
            'sub' => 'Complete',
        ]);
    }

    $attempt = pt_prep_get_open_attempt($pdo, $userId, $cohortId, $lessonId);
    if (!$attempt) {
        pt_prep_schedule_progress_test($pdo, $userId, $cohortId, $lessonId, 'course_page', $cookieHeader);
        $attempt = pt_prep_get_open_attempt($pdo, $userId, $cohortId, $lessonId);
    }

    if (!$attempt) {
        return $empty;
    }

    $testId = (int)$attempt['id'];
    $prepared = pt_prep_attempt_is_prepared($attempt, $pdo);
    $meta = pt_prep_progress_label($attempt, $pdo);

    if ($prepared) {
        $resume = pt_prep_attempt_is_resumable($pdo, $testId);
        return [
            'show_bar' => false,
            'show_button' => true,
            'button_href' => $progressTestUrl,
            'button_label' => $resume ? 'Resume Progress Test' : 'Start Progress Test',
            'prepared' => true,
            'preparing' => false,
            'resume' => $resume,
            'label' => $meta['label'],
            'sub' => $meta['sub'],
            'class' => $meta['class'],
            'pct' => $meta['pct'],
            'attempt_id' => $testId,
        ];
    }

    if ((string)($attempt['status'] ?? '') === 'preparing' && (int)($attempt['progress_pct'] ?? 0) < 5) {
        pt_prep_schedule_progress_test($pdo, $userId, $cohortId, $lessonId, 'course_page_retry', $cookieHeader);
        $attempt = pt_prep_get_open_attempt($pdo, $userId, $cohortId, $lessonId) ?: $attempt;
        $meta = pt_prep_progress_label($attempt, $pdo);
    }

    return [
        'show_bar' => true,
        'show_button' => false,
        'button_href' => '',
        'button_label' => 'Start Progress Test',
        'prepared' => false,
        'preparing' => true,
        'resume' => false,
        'label' => $meta['label'],
        'sub' => $meta['sub'],
        'class' => $meta['class'],
        'pct' => $meta['pct'],
        'attempt_id' => $testId,
    ];
}
