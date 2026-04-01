<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

$testId = (int)($_GET['test_id'] ?? 0);
$itemId = (int)($_GET['item_id'] ?? 0);
$kind   = (string)($_GET['kind'] ?? 'item'); // intro | item | outro | debrief

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)($u['id'] ?? 0);
if ($testId <= 0) { http_response_code(400); exit('Missing test_id'); }

if ($role === 'student') {
    $own = $pdo->prepare("SELECT 1 FROM progress_tests WHERE id=? AND user_id=? LIMIT 1");
    $own->execute([$testId, $userId]);
    if (!$own->fetchColumn()) { http_response_code(403); exit('Forbidden'); }
}

$fullName = trim((string)($u['name'] ?? 'student'));
if ($fullName === '') $fullName = 'student';
$firstName = preg_split('/\s+/', $fullName)[0] ?: 'student';

$text = '';

if ($kind === 'intro') {
    $text = "Okay {$firstName}. I will now conduct your intermediate progress test to check your understanding. "
          . "I will ask you several questions. Tap once to start speaking, and tap again to stop. "
          . "When you are ready, let's begin.";
} elseif ($kind === 'outro') {
    $text = "Thank you {$firstName}. That was the last question. I will now evaluate your results and provide feedback.";
} elseif ($kind === 'debrief') {
    $stmt = $pdo->prepare("SELECT score_pct, debrief_spoken FROM progress_tests WHERE id=? LIMIT 1");
    $stmt->execute([$testId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('Test not found'); }

    $score = ($row['score_pct'] !== null) ? (int)$row['score_pct'] : 0;
    $deb = trim((string)($row['debrief_spoken'] ?? ''));

    $text = ($deb === '')
      ? "Your score is {$score} percent. Please review the feedback in your debrief notes."
      : "Your score is {$score} percent. " . $deb;

} else {
    if ($itemId <= 0) { http_response_code(400); exit('Missing item_id'); }

    $stmt = $pdo->prepare("SELECT prompt, idx, kind, options_json FROM progress_test_items WHERE id=? AND test_id=? LIMIT 1");
    $stmt->execute([$itemId, $testId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('Item not found'); }

    $idx = (int)($row['idx'] ?? 0);
    $prompt = trim((string)($row['prompt'] ?? ''));
    $qKind = (string)($row['kind'] ?? '');

    // Build spoken question format
    if ($qKind === 'yesno') {
        $pLow = strtolower($prompt);
        if (strpos($pLow, 'yes or no') === false) {
            $prompt = rtrim($prompt, " \t\n\r\0\x0B?.") . "?";
        }
        $text = "Question {$idx}. {$prompt} Yes or no?";
    } elseif ($qKind === 'mcq') {
        $opts = json_decode((string)($row['options_json'] ?? 'null'), true);
        if (!is_array($opts)) $opts = [];

        // Speak up to 4 options (A-D). If fewer, speak what exists.
        $labels = ['A','B','C','D'];
        $spoken = [];
        for ($i=0; $i < 4; $i++) {
            if (!isset($opts[$i])) continue;
            $o = trim((string)$opts[$i]);
            if ($o === '') continue;
            $spoken[] = $labels[$i] . ") " . $o;
        }

        $promptQ = rtrim($prompt, " \t\n\r\0\x0B?.") . "?";
        if ($spoken) {
            $text = "Question {$idx}. {$promptQ} Is it " . implode(", or ", $spoken) . "?";
        } else {
            // fallback
            $text = "Question {$idx}. {$promptQ} What is your answer, {$firstName}?";
        }
    } else {
        // Open answer
        $promptQ = rtrim($prompt, " \t\n\r\0\x0B?.") . "?";
        $text = "Question {$idx}. {$promptQ} What is your answer, {$firstName}?";
    }
}

// API key
$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) $apiKey = getenv('CW_OPENAI_API_KEY');
if (!$apiKey) { http_response_code(500); exit('Missing OPENAI_API_KEY'); }

// TTS model
$model = getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';

// Voice override
$voice = trim((string)($_GET['voice'] ?? ''));
if ($voice !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $voice)) $voice = '';
if ($voice === '') $voice = getenv('CW_OPENAI_TTS_VOICE') ?: 'marin';

// Speed
$speed = (float)($_GET['speed'] ?? 1.0);
if ($speed < 0.80) $speed = 0.80;
if ($speed > 1.20) $speed = 1.20;

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