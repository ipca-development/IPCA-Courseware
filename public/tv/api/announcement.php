<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_screen_pa.php';

$messageId = (int)($_GET['message_id'] ?? 0);
$prefetch = (int)($_GET['prefetch'] ?? 0) === 1;

if ($messageId <= 0) {
    http_response_code(400);
    exit('Missing message_id');
}

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'tv_screen_messages'");
    if ($tableCheck === false || $tableCheck->fetchColumn() === false) {
        http_response_code(503);
        exit('TV messages are not configured.');
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            message_type,
            title,
            body,
            voice_text,
            voice,
            announce_audio_enabled,
            status,
            starts_at,
            ends_at,
            updated_at
        FROM tv_screen_messages
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit('Message not found');
    }

    if ((string)($row['status'] ?? '') !== 'active') {
        http_response_code(404);
        exit('Message is not active');
    }

    if ((int)($row['announce_audio_enabled'] ?? 0) !== 1) {
        http_response_code(404);
        exit('Announcement audio is disabled');
    }

    $now = gmdate('Y-m-d H:i:s');
    if (!empty($row['starts_at']) && (string)$row['starts_at'] > $now) {
        http_response_code(404);
        exit('Message is not active yet');
    }
    if (!empty($row['ends_at']) && (string)$row['ends_at'] < $now) {
        http_response_code(404);
        exit('Message has expired');
    }

    $speech = tv_pa_build_speech($row);
    if ($speech === '') {
        http_response_code(404);
        exit('No announcement text available');
    }

    $voice = tv_pa_voice_or_default((string)($row['voice'] ?? ''));
    $messageType = (string)($row['message_type'] ?? 'standard');
    $cacheFile = tv_pa_cache_file($messageId, $speech, $voice, $messageType);

    if (is_file($cacheFile) && filesize($cacheFile) > 0) {
        if ($prefetch) {
            http_response_code(204);
            exit;
        }
        header('Content-Type: audio/mpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($cacheFile);
        exit;
    }

    $audio = tv_pa_synthesize_mp3($speech, $voice, $messageType);
    if ($audio === '') {
        http_response_code(502);
        exit('OpenAI PA synthesis failed');
    }

    if (@file_put_contents($cacheFile, $audio) === false) {
        http_response_code(500);
        exit('Unable to cache announcement audio');
    }

    tv_pa_stream_mp3($audio, $prefetch);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to load announcement audio');
}
