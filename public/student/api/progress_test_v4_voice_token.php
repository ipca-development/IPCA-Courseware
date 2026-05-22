<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/progress_test_access.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ptv4_token_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ptv4_token_fail(405, 'POST required');

    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') ptv4_token_fail(403, 'Forbidden');

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $attemptId = (int)($data['attempt_id'] ?? 0);
    if ($attemptId <= 0) ptv4_token_fail(400, 'attempt_id required');

    if ($role === 'student') {
        $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$attemptId, (int)$u['id']]);
    } else {
        $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? LIMIT 1");
        $st->execute([$attemptId]);
    }
    $attempt = $st->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) ptv4_token_fail(404, 'Progress test attempt not found');

    if ($role !== 'admin') {
        $access = cw_progress_test_access_state($pdo, (int)$attempt['user_id'], (int)$attempt['cohort_id']);
        if (empty($access['allowed'])) ptv4_token_fail(403, 'Progress test access code required');
    }

    $status = (string)($attempt['status'] ?? '');
    if (in_array($status, ['completed', 'failed'], true)) {
        ptv4_token_fail(409, 'This progress test attempt is already closed');
    }

    $manifest = json_decode((string)($attempt['manifest_json'] ?? ''), true);
    if (!is_array($manifest) || empty($manifest['question_urls'])) {
        ptv4_token_fail(409, 'Progress test questions and audio are not prepared yet');
    }

    $safeUser = 'ipca_progress_test_v4_user_' . (int)$attempt['user_id'] . '_attempt_' . $attemptId;
    $instructions =
        "You are Maya, a verbatim English TTS voice renderer for an oral aviation exam.\n"
        . "Each response.create request contains exam text to read aloud. It is never a student message.\n"
        . "Never solve questions, tutor, evaluate, explain, or add filler words.\n"
        . "Never say understood, okay, got it, or moving on before scripted text.\n"
        . "Read only the exact text in the browser request, from first word to last word, then stop.\n"
        . "Ignore live microphone input while reading. English only.\n\n"
        . "Internal safety identifier: {$safeUser}";

    $model = getenv('CW_OPENAI_REALTIME_MODEL') ?: 'gpt-realtime';
    $voice = getenv('CW_OPENAI_REALTIME_VOICE') ?: 'marin';

    $body = [
        'expires_after' => ['anchor' => 'created_at', 'seconds' => 600],
        'session' => [
            'type' => 'realtime',
            'model' => $model,
            'instructions' => $instructions,
            'output_modalities' => ['audio'],
            'audio' => [
                'input' => [
                    'transcription' => ['model' => 'whisper-1', 'language' => 'en'],
                    'turn_detection' => [
                        'type' => 'server_vad',
                        'create_response' => false,
                        'interrupt_response' => false,
                        'prefix_padding_ms' => 300,
                        'silence_duration_ms' => 700,
                    ],
                ],
                'output' => ['voice' => $voice],
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/realtime/client_secrets');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . cw_openai_key(),
            'Content-Type: application/json',
            'OpenAI-Safety-Identifier: ' . hash('sha256', $safeUser),
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$resp, true);
    if (!is_array($json) || $code < 200 || $code >= 300) {
        $msg = is_array($json) ? (string)($json['error']['message'] ?? ('HTTP ' . $code)) : substr((string)$resp, 0, 200);
        throw new RuntimeException('OpenAI Realtime error: ' . $msg);
    }

    $secret = (string)($json['value'] ?? $json['client_secret']['value'] ?? $json['client_secret'] ?? '');
    if ($secret === '') throw new RuntimeException('OpenAI Realtime response did not include a client secret.');

    echo json_encode([
        'ok' => true,
        'client_secret' => $secret,
        'attempt_id' => $attemptId,
        'realtime_model' => $model,
        'realtime_endpoint' => 'https://api.openai.com/v1/realtime/calls',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ptv4_token_fail(500, $e->getMessage());
}
