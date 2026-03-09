<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
if ($lang !== 'es') $lang = 'en';

$text = (string)($_GET['text'] ?? '');
$text = trim($text);

// Basic guardrails
if ($text === '') {
    http_response_code(400);
    exit('Missing text');
}
if (mb_strlen($text) > 4000) {
    // keep it reasonable for TTS latency/cost
    $text = mb_substr($text, 0, 4000);
}

// Pick a good voice (tweak to your taste)
$voice = ($lang === 'es') ? 'alloy' : 'alloy'; // you can swap later
$model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts'; // safe default if you set it
$apiKey = getenv('OPENAI_API_KEY') ?: getenv('CW_OPENAI_API_KEY');

if (!$apiKey) {
    http_response_code(500);
    exit('Missing OpenAI API key');
}

$payload = [
    'model' => $model,
    'voice' => $voice,
    'format' => 'mp3',
    'input' => $text,
];

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 60,
]);

$audio = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($audio === false || $http >= 400) {
    http_response_code(500);
    // Avoid leaking sensitive info; show minimal
    exit('TTS failed' . ($err ? (': '.$err) : ''));
}

// Stream MP3
header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audio));
header('Cache-Control: no-store');
echo $audio;