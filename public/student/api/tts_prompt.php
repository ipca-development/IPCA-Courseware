<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

$testId = (int)($_GET['test_id'] ?? 0);
$itemId = (int)($_GET['item_id'] ?? 0);
$kind   = (string)($_GET['kind'] ?? 'item');

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)($u['id'] ?? 0);
if ($testId <= 0) { http_response_code(400); exit('Missing test_id'); }

// Ownership check (student)
if ($role === 'student') {
    $own = $pdo->prepare("SELECT 1 FROM progress_tests WHERE id=? AND user_id=? LIMIT 1");
    $own->execute([$testId, $userId]);
    if (!$own->fetchColumn()) { http_response_code(403); exit('Forbidden'); }
}

// Build text
$text = '';
if ($kind === 'intro') {
    $name = (string)($u['name'] ?? 'student');
    $text = "Okay {$name}, I will take your intermediate progress test to check your understanding. "
          . "I will ask you a question. Use the buttons to answer. Here we go.";
} else {
    if ($itemId <= 0) { http_response_code(400); exit('Missing item_id'); }
    $stmt = $pdo->prepare("SELECT prompt, idx FROM progress_test_items WHERE id=? AND test_id=? LIMIT 1");
    $stmt->execute([$itemId, $testId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('Item not found'); }

    $idx = (int)($row['idx'] ?? 0);
    $prompt = trim((string)($row['prompt'] ?? ''));
    $name = (string)($u['name'] ?? 'student');

    $text = "Question {$idx}. {$prompt} What is your answer, {$name}?";
}

// OpenAI API key
$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) $apiKey = getenv('CW_OPENAI_API_KEY');
if (!$apiKey) { http_response_code(500); exit('Missing OPENAI_API_KEY'); }

// TTS model/voice (override via env if desired)
$model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
$voice = getenv('CW_OPENAI_TTS_VOICE') ?: 'alloy'; // safe default; change later per Maya/Kevin

$payload = json_encode([
    'model' => $model,
    'voice' => $voice,
    'format' => 'mp3',
    'input' => $text,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
]);

$audio = curl_exec($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err   = curl_error($ch);
curl_close($ch);

if ($audio === false || $code < 200 || $code >= 300) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TTS failed (HTTP {$code}) {$err}";
    exit;
}

header('Content-Type: audio/mpeg');
header('Cache-Control: no-store');
echo $audio;