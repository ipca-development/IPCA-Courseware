<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }

    // Accept multipart/form-data with field name "audio"
    if (empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Missing audio file']);
        exit;
    }

    $tmp = (string)($_FILES['audio']['tmp_name'] ?? '');
    $name = (string)($_FILES['audio']['name'] ?? 'recording.webm');
    $size = (int)($_FILES['audio']['size'] ?? 0);
    $err  = (int)($_FILES['audio']['error'] ?? 0);

    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Upload failed']);
        exit;
    }

    // Size cap to keep it snappy (10MB)
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Audio too large (max 10MB)']);
        exit;
    }

    $apiKey = getenv('OPENAI_API_KEY') ?: getenv('CW_OPENAI_API_KEY');
    if (!$apiKey) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Missing OpenAI API key']);
        exit;
    }

    $model = getenv('CW_OPENAI_ASR_MODEL') ?: 'gpt-4o-mini-transcribe'; // good default
    $lang  = strtolower(trim((string)($_POST['lang'] ?? 'en')));
    if ($lang !== 'es') $lang = 'en';

    // Multipart POST to OpenAI transcription endpoint
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');

    $post = [
        'model' => $model,
        'file' => new CURLFile($tmp, 'application/octet-stream', $name),
        // "language" helps accuracy; use ISO codes
        'language' => $lang,
        'response_format' => 'json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http >= 400) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'ASR failed' . ($curlErr ? (': '.$curlErr) : '')]);
        exit;
    }

    $j = json_decode($resp, true);
    $text = trim((string)($j['text'] ?? ''));

    if ($text === '') {
        echo json_encode(['ok'=>false,'error'=>'No speech detected']);
        exit;
    }

    echo json_encode(['ok'=>true,'text'=>$text]);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}