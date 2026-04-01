<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$slideId = (int)($_GET['slide_id'] ?? 0);
$lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
$prefetch = (int)($_GET['prefetch'] ?? 0);

if ($slideId <= 0) { http_response_code(400); exit('Missing slide_id'); }
if (!in_array($lang, ['en','es'], true)) $lang = 'en';

// Security: student must be in cohort that contains lesson schedule
$stmt = $pdo->prepare("
  SELECT s.id, l.id AS lesson_id, l.course_id
  FROM slides s
  JOIN lessons l ON l.id=s.lesson_id
  WHERE s.id=? LIMIT 1
");
$stmt->execute([$slideId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Slide not found'); }

$lessonId = (int)$row['lesson_id'];
$courseId = (int)$row['course_id'];

if ($role === 'student') {
    $uid = (int)$u['id'];
    $chk = $pdo->prepare("
      SELECT 1
      FROM cohort_students cs
      JOIN cohorts co ON co.id = cs.cohort_id
      JOIN cohort_lesson_deadlines d ON d.cohort_id = cs.cohort_id
      WHERE cs.user_id = ?
        AND co.course_id = ?
        AND d.lesson_id = ?
      LIMIT 1
    ");
    $chk->execute([$uid, $courseId, $lessonId]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// Pick text: narration if present else slide text
$narrStmt = $pdo->prepare("SELECT narration_en, narration_es FROM slide_enrichment WHERE slide_id=? LIMIT 1");
$narrStmt->execute([$slideId]);
$narr = $narrStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$text = '';

if ($lang === 'es') {
    $text = trim((string)($narr['narration_es'] ?? ''));
    if ($text === '') {
        $t = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='es' LIMIT 1");
        $t->execute([$slideId]);
        $text = trim((string)$t->fetchColumn());
    }
} else {
    $text = trim((string)($narr['narration_en'] ?? ''));
    if ($text === '') {
        $t = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
        $t->execute([$slideId]);
        $text = trim((string)$t->fetchColumn());
    }
}

if ($text === '') {
    http_response_code(404);
    exit('No text to narrate for this slide yet.');
}

// OpenAI settings
$apiKey = getenv('OPENAI_API_KEY') ?: (getenv('CW_OPENAI_API_KEY') ?: (getenv('CW_OPENAI_KEY') ?: ''));
if ($apiKey === '') {
    http_response_code(500);
    exit('Missing OPENAI_API_KEY env var.');
}

$model = 'gpt-4o-mini-tts';
$voice = 'coral';
$instructions = ($lang === 'es')
  ? "Habla con un tono profesional, claro, didáctico, como instructor de vuelo. Mantén un ritmo natural."
  : "Speak in a professional, clear, friendly flight-instructor tone. Natural pacing, crisp articulation.";

$sha = sha1($text . '|' . $lang . '|' . $voice . '|' . $model . '|' . $instructions);

// Cache lookup
$cache = $pdo->prepare("
  SELECT audio_mp3
  FROM slide_tts_cache
  WHERE slide_id=? AND lang=? AND voice=? AND model=? AND input_sha1=?
  LIMIT 1
");
$cache->execute([$slideId, $lang, $voice, $model, $sha]);
$blob = $cache->fetchColumn();

if ($blob !== false && $blob !== null) {
    if ($prefetch === 1) {
        http_response_code(204);
        exit;
    }
    header('Content-Type: audio/mpeg');
    header('Cache-Control: private, max-age=31536000');
    echo $blob;
    exit;
}

// Call OpenAI TTS
$payload = json_encode([
    'model' => $model,
    'voice' => $voice,
    'input' => $text,
    'instructions' => $instructions,
    'response_format' => 'mp3',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false || $http < 200 || $http >= 300) {
    http_response_code(502);
    echo "OpenAI TTS failed (HTTP $http). " . ($err ? $err : $resp);
    exit;
}

// Save cache
$ins = $pdo->prepare("
  INSERT INTO slide_tts_cache (slide_id, lang, voice, model, input_sha1, audio_mp3)
  VALUES (?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE audio_mp3=VALUES(audio_mp3)
");
$ins->execute([$slideId, $lang, $voice, $model, $sha, $resp]);

if ($prefetch === 1) {
    http_response_code(204);
    exit;
}

header('Content-Type: audio/mpeg');
header('Cache-Control: private, max-age=31536000');
echo $resp;