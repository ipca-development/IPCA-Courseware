<?php
declare(strict_types=1);

require_once __DIR__ . '/progress_test_questions.php';
require_once __DIR__ . '/progress_test_prep.php';

const PT_BANK_MIN_POOL = 5;
const PT_BANK_MAX_POOL = 20;
const PT_BANK_QUALITY_MIN_SAMPLES = 8;
const PT_BANK_TOO_EASY_AVG = 90.0;
const PT_BANK_TOO_HARD_AVG = 35.0;
const PT_BANK_BAD_VALIDATION = 60;

function pt_bank_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS progress_test_lesson_banks (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              lesson_id INT NOT NULL,
              content_fingerprint CHAR(64) NOT NULL,
              recommended_pool_size TINYINT UNSIGNED NOT NULL DEFAULT 5,
              status ENUM('building','ready','stale') NOT NULL DEFAULT 'building',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_pt_lesson_bank (lesson_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS progress_test_bank_questions (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              bank_id BIGINT UNSIGNED NOT NULL,
              lesson_id INT NOT NULL,
              sort_idx INT NOT NULL DEFAULT 0,
              kind VARCHAR(16) NOT NULL,
              prompt TEXT NOT NULL,
              options_json JSON NOT NULL,
              correct_json JSON NOT NULL,
              prompt_hash CHAR(64) NOT NULL,
              audio_url VARCHAR(1024) NULL,
              validation_score SMALLINT UNSIGNED NULL,
              validation_flags JSON NULL,
              status ENUM('active','retired') NOT NULL DEFAULT 'active',
              retired_at DATETIME NULL,
              retired_reason VARCHAR(64) NULL,
              first_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
              avg_first_score_pct DECIMAL(5,2) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_pt_bq_bank_status (bank_id, status),
              KEY idx_pt_bq_lesson_status (lesson_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS progress_test_bank_question_usage (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              bank_question_id BIGINT UNSIGNED NOT NULL,
              user_id INT NOT NULL,
              attempt_id INT NOT NULL,
              first_score_pct DECIMAL(5,2) NOT NULL,
              first_evaluated_at DATETIME NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_pt_bqu_user_question (user_id, bank_question_id),
              KEY idx_pt_bqu_question (bank_question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $cols = $pdo->query("SHOW COLUMNS FROM progress_test_items_v2 LIKE 'bank_question_id'")->fetch();
        if (!$cols) {
            $pdo->exec('ALTER TABLE progress_test_items_v2 ADD COLUMN bank_question_id BIGINT UNSIGNED NULL AFTER correct_json');
        }
    } catch (Throwable $e) {
        error_log('pt_bank_ensure_tables: ' . $e->getMessage());
    }
}

function pt_bank_word_count(string $text): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') return 0;
    return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

function pt_bank_lesson_content_stats(PDO $pdo, int $lessonId): array
{
    $st = $pdo->prepare("
        SELECT e.narration_en
        FROM slides s
        JOIN slide_enrichment e ON e.slide_id = s.id
        WHERE s.lesson_id = ? AND COALESCE(s.is_deleted, 0) = 0
          AND e.narration_en IS NOT NULL AND TRIM(e.narration_en) <> ''
        ORDER BY s.page_number
    ");
    $st->execute([$lessonId]);
    $words = 0;
    $slides = 0;
    $truth = '';
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slides++;
        $n = trim((string)$row['narration_en']);
        $words += pt_bank_word_count($n);
        $truth .= $n . "\n\n";
    }
    return [
        'slide_count' => $slides,
        'word_count' => $words,
        'truth_text' => trim($truth) !== '' ? trim($truth) : '(No narration scripts available.)',
    ];
}

function pt_bank_content_fingerprint(string $truthText): string
{
    return hash('sha256', trim($truthText));
}

function pt_bank_recommended_pool_size(int $wordCount, int $slideCount): int
{
    $byWords = (int)round($wordCount / 400);
    $bySlides = (int)round($slideCount * 1.5);
    $target = max($byWords, $bySlides);
    return max(PT_BANK_MIN_POOL, min(PT_BANK_MAX_POOL, $target));
}

function pt_bank_prompt_hash(string $kind, string $prompt): string
{
    return hash('sha256', strtolower(trim($kind)) . '|' . trim($prompt));
}

function pt_bank_spoken_prompt(array $question): string
{
    $text = trim((string)($question['prompt'] ?? ''));
    $kind = (string)($question['kind'] ?? 'open');
    if ($kind === 'yesno') return $text . ' Please answer yes or no only.';
    if ($kind === 'mcq') return $text . ' Please answer with the correct phrase.';
    return $text . ' Please answer in a short spoken explanation.';
}

function pt_bank_quality_label(array $row): array
{
    $validationScore = isset($row['validation_score']) ? (int)$row['validation_score'] : null;
    $flags = [];
    if (!empty($row['validation_flags'])) {
        $decoded = is_array($row['validation_flags']) ? $row['validation_flags'] : json_decode((string)$row['validation_flags'], true);
        if (is_array($decoded)) $flags = $decoded;
    }

    if ($validationScore !== null && $validationScore < PT_BANK_BAD_VALIDATION) {
        return ['key' => 'bad_quality', 'label' => 'Bad quality', 'tone' => 'bad'];
    }
    foreach (['ambiguous', 'leakage', 'letter_choice'] as $badFlag) {
        if (in_array($badFlag, $flags, true)) {
            return ['key' => 'bad_quality', 'label' => 'Bad quality', 'tone' => 'bad'];
        }
    }

    $n = (int)($row['first_attempt_count'] ?? 0);
    $avg = isset($row['avg_first_score_pct']) ? (float)$row['avg_first_score_pct'] : null;

    if ($n < PT_BANK_QUALITY_MIN_SAMPLES) {
        return ['key' => 'low_data', 'label' => 'Low data', 'tone' => 'new'];
    }
    if ($avg !== null && $avg >= PT_BANK_TOO_EASY_AVG) {
        return ['key' => 'too_easy', 'label' => 'Too easy', 'tone' => 'easy'];
    }
    if ($avg !== null && $avg <= PT_BANK_TOO_HARD_AVG) {
        return ['key' => 'too_hard', 'label' => 'Too hard', 'tone' => 'hard'];
    }
    return ['key' => 'balanced', 'label' => 'Balanced', 'tone' => 'ok'];
}

function pt_bank_get_or_create_bank(PDO $pdo, int $lessonId): array
{
    pt_bank_ensure_tables($pdo);
    $stats = pt_bank_lesson_content_stats($pdo, $lessonId);
    $fingerprint = pt_bank_content_fingerprint($stats['truth_text']);
    $recommended = pt_bank_recommended_pool_size($stats['word_count'], $stats['slide_count']);

    $st = $pdo->prepare('SELECT * FROM progress_test_lesson_banks WHERE lesson_id = ? LIMIT 1');
    $st->execute([$lessonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $ins = $pdo->prepare("
            INSERT INTO progress_test_lesson_banks
              (lesson_id, content_fingerprint, recommended_pool_size, status, created_at, updated_at)
            VALUES (?, ?, ?, 'building', NOW(), NOW())
        ");
        $ins->execute([$lessonId, $fingerprint, $recommended]);
        $id = (int)$pdo->lastInsertId();
        $st->execute([$lessonId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id, 'lesson_id' => $lessonId];
    } else {
        $status = (string)$row['status'];
        if ((string)$row['content_fingerprint'] !== $fingerprint && $status !== 'building') {
            $pdo->prepare("
                UPDATE progress_test_lesson_banks
                SET content_fingerprint = ?, recommended_pool_size = ?, status = 'stale', updated_at = NOW()
                WHERE id = ?
            ")->execute([$fingerprint, $recommended, (int)$row['id']]);
            $row['content_fingerprint'] = $fingerprint;
            $row['recommended_pool_size'] = $recommended;
            $row['status'] = 'stale';
        } else {
            $pdo->prepare('UPDATE progress_test_lesson_banks SET recommended_pool_size = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$recommended, (int)$row['id']]);
            $row['recommended_pool_size'] = $recommended;
        }
    }

    $row['stats'] = $stats;
    $row['fingerprint_ok'] = ((string)$row['content_fingerprint'] === $fingerprint);
    return $row;
}

function pt_bank_count_active(PDO $pdo, int $bankId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM progress_test_bank_questions WHERE bank_id = ? AND status = 'active'");
    $st->execute([$bankId]);
    return (int)$st->fetchColumn();
}

function pt_bank_list_questions(PDO $pdo, int $lessonId, bool $includeRetired = false): array
{
    pt_bank_ensure_tables($pdo);
    $bank = pt_bank_get_or_create_bank($pdo, $lessonId);
    $bankId = (int)$bank['id'];

    $sql = "
        SELECT *
        FROM progress_test_bank_questions
        WHERE bank_id = ?
    ";
    if (!$includeRetired) $sql .= " AND status = 'active' ";
    $sql .= ' ORDER BY sort_idx ASC, id ASC';

    $st = $pdo->prepare($sql);
    $st->execute([$bankId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $options = json_decode((string)$row['options_json'], true) ?: [];
        $quality = pt_bank_quality_label($row);
        $rows[] = [
            'id' => (int)$row['id'],
            'kind' => (string)$row['kind'],
            'kind_label' => ptq_kind_label((string)$row['kind']),
            'prompt' => (string)$row['prompt'],
            'options' => $options,
            'audio_url' => (string)($row['audio_url'] ?? ''),
            'validation_score' => $row['validation_score'] !== null ? (int)$row['validation_score'] : null,
            'validation_flags' => json_decode((string)($row['validation_flags'] ?? '[]'), true) ?: [],
            'status' => (string)$row['status'],
            'first_attempt_count' => (int)$row['first_attempt_count'],
            'avg_first_score_pct' => $row['avg_first_score_pct'] !== null ? round((float)$row['avg_first_score_pct'], 1) : null,
            'quality' => $quality,
            'suggested_bad' => in_array($quality['key'], ['bad_quality', 'too_easy', 'too_hard'], true),
        ];
    }

    return [
        'bank' => [
            'id' => $bankId,
            'lesson_id' => $lessonId,
            'content_fingerprint' => (string)$bank['content_fingerprint'],
            'recommended_pool_size' => (int)$bank['recommended_pool_size'],
            'status' => (string)$bank['status'],
            'active_count' => pt_bank_count_active($pdo, $bankId),
            'fingerprint_ok' => (bool)$bank['fingerprint_ok'],
        ],
        'questions' => $rows,
    ];
}

function pt_bank_insert_question(PDO $pdo, int $bankId, int $lessonId, array $question, ?string $audioUrl = null): int
{
    $kind = (string)$question['kind'];
    $prompt = (string)$question['prompt'];
    $validation = ptq_score_validation($question);

    $maxIdx = $pdo->prepare('SELECT COALESCE(MAX(sort_idx), 0) FROM progress_test_bank_questions WHERE bank_id = ?');
    $maxIdx->execute([$bankId]);
    $sortIdx = (int)$maxIdx->fetchColumn() + 1;

    $ins = $pdo->prepare("
        INSERT INTO progress_test_bank_questions
          (bank_id, lesson_id, sort_idx, kind, prompt, options_json, correct_json, prompt_hash,
           audio_url, validation_score, validation_flags, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
    ");
    $ins->execute([
        $bankId,
        $lessonId,
        $sortIdx,
        $kind,
        $prompt,
        json_encode($question['options'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($question['correct'] ?? [], JSON_UNESCAPED_UNICODE),
        pt_bank_prompt_hash($kind, $prompt),
        $audioUrl,
        (int)$validation['validation_score'],
        json_encode($validation['validation_flags'], JSON_UNESCAPED_UNICODE),
    ]);

    return (int)$pdo->lastInsertId();
}

function pt_bank_retire_questions(PDO $pdo, int $lessonId, array $questionIds, string $reason = 'admin_bulk'): array
{
    pt_bank_ensure_tables($pdo);
    $bank = pt_bank_get_or_create_bank($pdo, $lessonId);
    $bankId = (int)$bank['id'];
    $activeBefore = pt_bank_count_active($pdo, $bankId);

    $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
    if (!$questionIds) {
        throw new RuntimeException('No questions selected.');
    }

    $wouldRetire = count($questionIds);
    if ($activeBefore - $wouldRetire < PT_BANK_MIN_POOL) {
        throw new RuntimeException(
            'Cannot retire selection: only ' . ($activeBefore - $wouldRetire) . ' would remain (minimum ' . PT_BANK_MIN_POOL . ').'
        );
    }

    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $params = array_merge([$reason], $questionIds, [$bankId, $lessonId]);
    $st = $pdo->prepare("
        UPDATE progress_test_bank_questions
        SET status = 'retired', retired_at = NOW(), retired_reason = ?, updated_at = NOW()
        WHERE id IN ({$placeholders}) AND bank_id = ? AND lesson_id = ? AND status = 'active'
    ");
    $st->execute($params);
    $retired = $st->rowCount();

    $activeAfter = pt_bank_count_active($pdo, $bankId);

    if ($activeAfter < (int)$bank['recommended_pool_size']) {
        $pdo->prepare("UPDATE progress_test_lesson_banks SET status = 'stale', updated_at = NOW() WHERE id = ?")
            ->execute([$bankId]);
    }

    return [
        'retired' => $retired,
        'active_before' => $activeBefore,
        'active_after' => $activeAfter,
        'recommended_pool_size' => (int)$bank['recommended_pool_size'],
    ];
}

function pt_bank_existing_prompts(PDO $pdo, int $bankId): array
{
    $st = $pdo->prepare('SELECT prompt FROM progress_test_bank_questions WHERE bank_id = ?');
    $st->execute([$bankId]);
    return array_values(array_filter(array_map(static fn($r) => trim((string)$r['prompt']), $st->fetchAll(PDO::FETCH_ASSOC))));
}

function pt_bank_tts_generate_local(string $text, string $file): void
{
    $apiKey = getenv('OPENAI_API_KEY') ?: getenv('CW_OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('Missing OPENAI_API_KEY');

    $model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
    $voice = pt_prep_tts_voice();

    $payload = json_encode([
        'model' => $model,
        'voice' => $voice,
        'format' => 'mp3',
        'input' => $text,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 180,
    ]);
    $audio = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("TTS failed (HTTP {$code}) {$err}");
    }
    if (@file_put_contents($file, $audio) === false) {
        throw new RuntimeException('Failed to write audio file.');
    }
}

function pt_bank_env_spaces(): array
{
    $keys = ['CW_SPACES_KEY' => 'SPACES_KEY', 'CW_SPACES_SECRET' => 'SPACES_SECRET', 'CW_SPACES_BUCKET' => 'SPACES_BUCKET'];
    $out = [];
    foreach ($keys as $a => $b) {
        $v = getenv($a);
        if ($v === false || $v === '') $v = getenv($b);
        $out[$a] = trim((string)$v);
    }
    $out['CW_SPACES_REGION'] = trim((string)(getenv('CW_SPACES_REGION') ?: getenv('SPACES_REGION') ?: 'nyc3'));
    $out['CW_SPACES_CDN_BASE'] = rtrim(trim((string)(getenv('CW_SPACES_CDN_BASE') ?: getenv('SPACES_CDN') ?: 'https://ipca-media.nyc3.cdn.digitaloceanspaces.com')), '/');
    if ($out['CW_SPACES_KEY'] === '' || $out['CW_SPACES_SECRET'] === '' || $out['CW_SPACES_BUCKET'] === '') {
        throw new RuntimeException('Missing Spaces credentials for bank audio upload.');
    }
    return $out;
}

function pt_bank_upload_audio(int $lessonId, int $questionId, string $localFile): string
{
    if (!is_file($localFile)) throw new RuntimeException('Audio file missing.');

    $cfg = pt_bank_env_spaces();
    $bucket = $cfg['CW_SPACES_BUCKET'];
    $region = $cfg['CW_SPACES_REGION'];
    $key = 'progress_test_bank/' . $lessonId . '/q_' . $questionId . '.mp3';
    $host = $bucket . '.' . $region . '.digitaloceanspaces.com';
    $amzdate = gmdate('Ymd\THis\Z');
    $datestamp = gmdate('Ymd');
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $expiresSec = 900;
    $credential = $cfg['CW_SPACES_KEY'] . '/' . $datestamp . '/' . $region . '/' . $service . '/aws4_request';

    $canonicalUri = '/' . str_replace('%2F', '/', rawurlencode($key));
    $query = [
        'X-Amz-Algorithm' => $algorithm,
        'X-Amz-Credential' => $credential,
        'X-Amz-Date' => $amzdate,
        'X-Amz-Expires' => (string)$expiresSec,
        'X-Amz-SignedHeaders' => 'host;x-amz-acl',
    ];
    ksort($query, SORT_STRING);
    $pairs = [];
    foreach ($query as $k => $v) $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    $canonicalQuery = implode('&', $pairs);
    $canonicalHeaders = "host:{$host}\nx-amz-acl:public-read\n";
    $signedHeaders = 'host;x-amz-acl';
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $canonicalRequest = implode("\n", ['PUT', $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);
    $toSign = implode("\n", [$algorithm, $amzdate, "{$datestamp}/{$region}/s3/aws4_request", hash('sha256', $canonicalRequest)]);

    $kDate = hash_hmac('sha256', $datestamp, 'AWS4' . $cfg['CW_SPACES_SECRET'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $signKey = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $toSign, $signKey);

    $putUrl = "https://{$host}{$canonicalUri}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    $size = filesize($localFile);
    $fh = fopen($localFile, 'rb');
    if (!$fh) throw new RuntimeException('Cannot open audio for upload.');

    $ch = curl_init($putUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: audio/mpeg',
            'Content-Length: ' . $size,
            'x-amz-acl: public-read',
        ],
        CURLOPT_TIMEOUT => 300,
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($out === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Bank audio upload failed (HTTP {$code})");
    }

    return $cfg['CW_SPACES_CDN_BASE'] . '/' . str_replace('%2F', '/', rawurlencode($key));
}

function pt_bank_generate_audio_for_question(PDO $pdo, int $lessonId, int $questionId, array $question): string
{
    $dir = sys_get_temp_dir() . '/pt_bank_' . $lessonId;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $local = $dir . '/q_' . $questionId . '.mp3';

    $spoken = pt_bank_spoken_prompt($question);
    $cached = pt_prep_question_audio_cache_get($pdo, $spoken);
    if ($cached && trim((string)$cached['audio_url']) !== '') {
        $url = (string)$cached['audio_url'];
        $pdo->prepare('UPDATE progress_test_bank_questions SET audio_url = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$url, $questionId]);
        return $url;
    }

    pt_bank_tts_generate_local($spoken, $local);
    $url = pt_bank_upload_audio($lessonId, $questionId, $local);
    pt_prep_question_audio_cache_store($pdo, $spoken, $url);
    $pdo->prepare('UPDATE progress_test_bank_questions SET audio_url = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$url, $questionId]);
    @unlink($local);
    return $url;
}

function pt_bank_replace_one(PDO $pdo, int $lessonId, ?string $kindPreference = null): array
{
    pt_bank_ensure_tables($pdo);
    $bank = pt_bank_get_or_create_bank($pdo, $lessonId);
    $bankId = (int)$bank['id'];
    $active = pt_bank_count_active($pdo, $bankId);

    if ($active >= PT_BANK_MAX_POOL) {
        throw new RuntimeException('Bank is at maximum size (' . PT_BANK_MAX_POOL . '). Retire a question first.');
    }

    $stats = pt_bank_lesson_content_stats($pdo, $lessonId);
    $exclude = pt_bank_existing_prompts($pdo, $bankId);

    $candidate = ptq_generate_single_question($pdo, $stats['truth_text'], $exclude, $kindPreference);
    $validated = ptq_validate_and_rewrite_questions($pdo, $stats['truth_text'], [$candidate], 1);
    $question = $validated[0] ?? $candidate;
    $validation = ptq_score_validation($question);

    if ((int)$validation['validation_score'] < PT_BANK_BAD_VALIDATION) {
        throw new RuntimeException('Replacement question failed quality check (score ' . (int)$validation['validation_score'] . '). Try again.');
    }

    $qid = pt_bank_insert_question($pdo, $bankId, $lessonId, $question);
    $audioUrl = pt_bank_generate_audio_for_question($pdo, $lessonId, $qid, $question);

    $activeAfter = pt_bank_count_active($pdo, $bankId);
    $status = 'ready';
    if ($activeAfter < (int)$bank['recommended_pool_size']) $status = 'stale';
    $pdo->prepare("UPDATE progress_test_lesson_banks SET status = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$status, $bankId]);

    return [
        'question_id' => $qid,
        'audio_url' => $audioUrl,
        'active_count' => $activeAfter,
        'recommended_pool_size' => (int)$bank['recommended_pool_size'],
    ];
}

function pt_bank_record_first_attempt(PDO $pdo, int $bankQuestionId, int $userId, int $attemptId, float $scorePct): void
{
    if ($bankQuestionId <= 0) return;
    pt_bank_ensure_tables($pdo);

    try {
        $ins = $pdo->prepare("
            INSERT INTO progress_test_bank_question_usage
              (bank_question_id, user_id, attempt_id, first_score_pct, first_evaluated_at, created_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $ins->execute([$bankQuestionId, $userId, $attemptId, round($scorePct, 2)]);
    } catch (Throwable $e) {
        return;
    }

    $agg = $pdo->prepare("
        SELECT COUNT(*) AS cnt, AVG(first_score_pct) AS avg_score
        FROM progress_test_bank_question_usage
        WHERE bank_question_id = ?
    ");
    $agg->execute([$bankQuestionId]);
    $row = $agg->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'avg_score' => null];

    $pdo->prepare("
        UPDATE progress_test_bank_questions
        SET first_attempt_count = ?, avg_first_score_pct = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([(int)$row['cnt'], $row['avg_score'] !== null ? round((float)$row['avg_score'], 2) : null, $bankQuestionId]);
}

function pt_bank_is_ready_for_prep(PDO $pdo, int $lessonId): array
{
    pt_bank_ensure_tables($pdo);
    $bank = pt_bank_get_or_create_bank($pdo, $lessonId);
    $bankId = (int)$bank['id'];
    $active = pt_bank_count_active($pdo, $bankId);
    $ok = ($active >= PT_BANK_MIN_POOL)
        && ((string)$bank['status'] === 'ready' || $active >= (int)$bank['recommended_pool_size'])
        && (bool)$bank['fingerprint_ok'];

    return [
        'ready' => $ok,
        'active_count' => $active,
        'recommended_pool_size' => (int)$bank['recommended_pool_size'],
        'status' => (string)$bank['status'],
        'bank_id' => $bankId,
    ];
}

function pt_bank_sample_for_attempt(PDO $pdo, int $lessonId, int $userId, int $count): array
{
    $ready = pt_bank_is_ready_for_prep($pdo, $lessonId);
    if (!$ready['ready']) {
        throw new RuntimeException('Progress test question bank is not ready for this lesson.');
    }

    $bankId = (int)$ready['bank_id'];
    $st = $pdo->prepare("
        SELECT q.*
        FROM progress_test_bank_questions q
        WHERE q.bank_id = ? AND q.status = 'active' AND q.audio_url IS NOT NULL AND TRIM(q.audio_url) <> ''
        ORDER BY q.sort_idx ASC, q.id ASC
    ");
    $st->execute([$bankId]);
    $pool = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($pool) < $count) {
        throw new RuntimeException('Not enough bank questions with audio.');
    }

    $recent = $pdo->prepare("
        SELECT bank_question_id
        FROM progress_test_bank_question_usage
        WHERE user_id = ?
          AND bank_question_id IN (SELECT id FROM progress_test_bank_questions WHERE bank_id = ?)
        ORDER BY first_evaluated_at DESC
        LIMIT 20
    ");
    $recent->execute([$userId, $bankId]);
    $recentIds = array_map('intval', $recent->fetchAll(PDO::FETCH_COLUMN));

    $byKind = ['yesno' => [], 'mcq' => [], 'open' => []];
    foreach ($pool as $row) {
        $id = (int)$row['id'];
        if (in_array($id, $recentIds, true)) continue;
        $k = (string)$row['kind'];
        if (!isset($byKind[$k])) $byKind[$k] = [];
        $byKind[$k][] = $row;
    }

    $selected = [];
    $kinds = ['open', 'mcq', 'yesno'];
    while (count($selected) < $count) {
        $picked = false;
        foreach ($kinds as $kind) {
            if (count($selected) >= $count) break;
            if (!empty($byKind[$kind])) {
                $selected[] = array_shift($byKind[$kind]);
                $picked = true;
            }
        }
        if (!$picked) break;
    }

    if (count($selected) < $count) {
        foreach ($pool as $row) {
            if (count($selected) >= $count) break;
            $id = (int)$row['id'];
            $already = false;
            foreach ($selected as $s) {
                if ((int)$s['id'] === $id) { $already = true; break; }
            }
            if (!$already) $selected[] = $row;
        }
    }

    shuffle($selected);
    $selected = array_slice($selected, 0, $count);
    usort($selected, static fn($a, $b) => (int)$a['sort_idx'] <=> (int)$b['sort_idx']);

    return $selected;
}

function pt_bank_lessons_coverage(PDO $pdo, int $programId, int $courseId, int $lessonId): array
{
    pt_bank_ensure_tables($pdo);

    $sql = "
        SELECT l.id, l.title, l.external_lesson_id, c.title AS course_title
        FROM lessons l
        INNER JOIN courses c ON c.id = l.course_id
        WHERE 1=1
    ";
    $params = [];
    if ($lessonId > 0) {
        $sql .= ' AND l.id = ?';
        $params[] = $lessonId;
    } elseif ($courseId > 0) {
        $sql .= ' AND l.course_id = ?';
        $params[] = $courseId;
    } elseif ($programId > 0) {
        $sql .= ' AND c.program_id = ?';
        $params[] = $programId;
    } else {
        return [];
    }
    $sql .= ' ORDER BY c.sort_order, c.id, l.sort_order, l.external_lesson_id';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $lessons = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lid = (int)$row['id'];
        $bank = pt_bank_get_or_create_bank($pdo, $lid);
        $active = pt_bank_count_active($pdo, (int)$bank['id']);
        $recommended = (int)$bank['recommended_pool_size'];
        $tone = 'ok';
        if ($active < PT_BANK_MIN_POOL) $tone = 'bad';
        elseif ($active < $recommended || (string)$bank['status'] === 'stale') $tone = 'warn';

        $lessons[] = [
            'lesson_id' => $lid,
            'lesson_title' => (string)$row['title'],
            'external_lesson_id' => (int)$row['external_lesson_id'],
            'course_title' => (string)$row['course_title'],
            'active_count' => $active,
            'recommended_pool_size' => $recommended,
            'bank_status' => (string)$bank['status'],
            'fingerprint_ok' => (bool)$bank['fingerprint_ok'],
            'tone' => $tone,
            'ready_for_students' => pt_bank_is_ready_for_prep($pdo, $lid)['ready'],
        ];
    }
    return $lessons;
}

function pt_bank_generic_intro_text(): string
{
    return 'Hello. Click Ready when you want to start your progress test.';
}

function pt_bank_generic_intro_url(PDO $pdo): ?string
{
    $spoken = pt_bank_generic_intro_text();
    $cached = pt_prep_question_audio_cache_get($pdo, $spoken);
    return ($cached && trim((string)$cached['audio_url']) !== '') ? (string)$cached['audio_url'] : null;
}

function pt_bank_ensure_generic_intro(PDO $pdo): string
{
    $spoken = pt_bank_generic_intro_text();
    $cached = pt_prep_question_audio_cache_get($pdo, $spoken);
    if ($cached && trim((string)$cached['audio_url']) !== '') {
        return (string)$cached['audio_url'];
    }

    $dir = sys_get_temp_dir() . '/pt_bank_intro';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $local = $dir . '/intro.mp3';
    pt_bank_tts_generate_local($spoken, $local);

    $cfg = pt_bank_env_spaces();
    $key = 'progress_test_bank/generic_intro.mp3';
    $host = $cfg['CW_SPACES_BUCKET'] . '.' . $cfg['CW_SPACES_REGION'] . '.digitaloceanspaces.com';
    $amzdate = gmdate('Ymd\THis\Z');
    $datestamp = gmdate('Ymd');
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $expiresSec = 900;
    $credential = $cfg['CW_SPACES_KEY'] . '/' . $datestamp . '/' . $cfg['CW_SPACES_REGION'] . '/' . $service . '/aws4_request';
    $canonicalUri = '/' . rawurlencode($key);
    $query = [
        'X-Amz-Algorithm' => $algorithm,
        'X-Amz-Credential' => $credential,
        'X-Amz-Date' => $amzdate,
        'X-Amz-Expires' => (string)$expiresSec,
        'X-Amz-SignedHeaders' => 'host;x-amz-acl',
    ];
    ksort($query, SORT_STRING);
    $pairs = [];
    foreach ($query as $k => $v) $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    $canonicalQuery = implode('&', $pairs);
    $canonicalHeaders = "host:{$host}\nx-amz-acl:public-read\n";
    $signedHeaders = 'host;x-amz-acl';
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $canonicalRequest = implode("\n", ['PUT', $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);
    $toSign = implode("\n", [$algorithm, $amzdate, "{$datestamp}/{$cfg['CW_SPACES_REGION']}/s3/aws4_request", hash('sha256', $canonicalRequest)]);
    $kDate = hash_hmac('sha256', $datestamp, 'AWS4' . $cfg['CW_SPACES_SECRET'], true);
    $kRegion = hash_hmac('sha256', $cfg['CW_SPACES_REGION'], $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $signKey = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $toSign, $signKey);
    $putUrl = "https://{$host}{$canonicalUri}?{$canonicalQuery}&X-Amz-Signature={$signature}";

    $size = filesize($local);
    $fh = fopen($local, 'rb');
    $ch = curl_init($putUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: audio/mpeg', 'Content-Length: ' . $size, 'x-amz-acl: public-read'],
        CURLOPT_TIMEOUT => 300,
    ]);
    curl_exec($ch);
    curl_close($ch);
    fclose($fh);

    $url = $cfg['CW_SPACES_CDN_BASE'] . '/' . $key;
    pt_prep_question_audio_cache_store($pdo, $spoken, $url);
    @unlink($local);
    return $url;
}
