<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_screen_pa.php';
require_once __DIR__ . '/../../../src/tv_kiosk_config.php';

$prefetch = (int)($_GET['prefetch'] ?? 0) === 1;
$speech = trim((string)($_GET['speech'] ?? ''));
$voice = tv_pa_voice_or_default((string)($_GET['voice'] ?? tv_kiosk_config()['default_pa_voice'] ?? ''));

if ($speech === '') {
    http_response_code(400);
    exit('Missing speech');
}

if (strlen($speech) > 4000) {
    $speech = substr($speech, 0, 4000);
}

try {
    $cacheFile = tv_pa_event_cache_file($speech, $voice, 'aircraft');

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

    $audio = tv_pa_synthesize_mp3($speech, $voice, 'aircraft');
    if ($audio === '') {
        http_response_code(502);
        exit('Unable to synthesize announcement.');
    }

    @file_put_contents($cacheFile, $audio);
    tv_pa_stream_mp3($audio, $prefetch);
} catch (Throwable $e) {
    http_response_code(502);
    exit('Unable to load aircraft announcement.');
}
