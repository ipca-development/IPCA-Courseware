<?php
declare(strict_types=1);
/**
 * OpenAI TTS proxy for ALSIM AI Instructor POC.
 * Keeps the API key server-side; matches IPCA progress-test voice settings.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$text = trim((string)($body['text'] ?? ''));
if ($text === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing text';
    exit;
}

if (mb_strlen($text) > 500) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Text too long';
    exit;
}

$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
    $apiKey = getenv('CW_OPENAI_API_KEY');
}
if (!$apiKey) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing OPENAI_API_KEY';
    exit;
}

$model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
$voice = trim((string)($body['voice'] ?? ''));
if ($voice !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $voice)) {
    $voice = '';
}
if ($voice === '') {
    $voice = getenv('CW_OPENAI_TTS_VOICE') ?: 'marin';
}

$speed = (float)($body['speed'] ?? 1.0);
if ($speed < 0.80) {
    $speed = 0.80;
}
if ($speed > 1.20) {
    $speed = 1.20;
}

$payload = json_encode([
    'model'  => $model,
    'voice'  => $voice,
    'format' => 'mp3',
    'speed'  => $speed,
    'input'  => $text,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$audio = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($audio === false || $code < 200 || $code >= 300) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'TTS failed (HTTP ' . $code . ') ' . $err;
    exit;
}

header('Content-Type: audio/mpeg');
header('Cache-Control: no-store');
echo $audio;
