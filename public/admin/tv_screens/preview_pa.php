<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/tv_screen_pa.php';

cw_require_admin();

$voice = tv_pa_voice_or_default((string)($_GET['voice'] ?? ''));
$messageType = strtolower(trim((string)($_GET['message_type'] ?? 'standard')));
if (!in_array($messageType, array('standard', 'urgent', 'schedule', 'night'), true)) {
    $messageType = 'standard';
}

$text = trim((string)($_GET['text'] ?? ''));
if ($text === '') {
    $text = 'Attention please. This is a terminal public-address system check. All personnel stand by for operational instructions.';
}
if (strlen($text) > 4000) {
    $text = substr($text, 0, 4000);
}

try {
    $audio = tv_pa_synthesize_mp3($text, $voice, $messageType);
    if ($audio === '') {
        http_response_code(502);
        exit('OpenAI PA synthesis failed.');
    }

    header('Content-Type: audio/mpeg');
    header('Cache-Control: no-store');
    echo $audio;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to preview PA voice.');
}
