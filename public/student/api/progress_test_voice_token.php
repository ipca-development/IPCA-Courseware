<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';

cw_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ptv3_token_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ptv3_token_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ptv3_token_fail(405, 'POST required');
    }

    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student' && $role !== 'admin') {
        ptv3_token_fail(403, 'Forbidden');
    }

    $data = ptv3_token_body();
    $attemptId = (int)($data['attempt_id'] ?? 0);
    if ($attemptId <= 0) {
        ptv3_token_fail(400, 'attempt_id required');
    }

    if ($role === 'student') {
        $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$attemptId, (int)$u['id']]);
    } else {
        $st = $pdo->prepare("SELECT * FROM progress_tests_v2 WHERE id = ? LIMIT 1");
        $st->execute([$attemptId]);
    }
    $attempt = $st->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) {
        ptv3_token_fail(404, 'Progress test attempt not found');
    }

    $status = (string)($attempt['status'] ?? '');
    if (in_array($status, ['completed', 'failed'], true)) {
        ptv3_token_fail(409, 'This progress test attempt is already closed');
    }

    $itemsSt = $pdo->prepare("
        SELECT id, idx, kind, prompt
        FROM progress_test_items_v2
        WHERE test_id = ?
        ORDER BY idx ASC
    ");
    $itemsSt->execute([$attemptId]);
    $items = $itemsSt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        ptv3_token_fail(409, 'No generated progress test questions are available yet');
    }

    $answeredSt = $pdo->prepare("
        SELECT item_id
        FROM progress_test_oral_item_responses
        WHERE attempt_id = ?
          AND evaluated_at IS NOT NULL
    ");
    $answeredSt->execute([$attemptId]);
    $answered = array_fill_keys(array_map('intval', $answeredSt->fetchAll(PDO::FETCH_COLUMN)), true);

    $currentItemId = (int)$items[0]['id'];
    foreach ($items as $item) {
        $id = (int)$item['id'];
        if (empty($answered[$id])) {
            $currentItemId = $id;
            break;
        }
    }

    $questionLines = [];
    foreach ($items as $item) {
        $questionLines[] = 'Question ' . (int)$item['idx'] . ' (item_id ' . (int)$item['id'] . '): ' . trim((string)$item['prompt']);
    }

    $safeUser = 'ipca_progress_test_v3_user_' . (int)($attempt['user_id'] ?? 0) . '_attempt_' . $attemptId;
    $instructions =
        "You are Maya, an IPCA AI flight instructor conducting a realtime oral progress test.\n"
        . "The backend progress_test_items_v2 questions are authoritative. Do not invent questions or answers.\n"
        . "Ask only the current question when instructed by the browser. Keep the wording natural but preserve the question content.\n"
        . "Do not score independently. Backend scoring is authoritative; speak only backend-provided scores and feedback.\n"
        . "If the browser tells you to wait, stay quiet and listen. If a clarification is requested, ask only that clarification and do not tutor.\n"
        . "Tone: supportive, honest, instructor-like, concise.\n\n"
        . "Available generated questions:\n" . implode("\n", $questionLines) . "\n\n"
        . "Internal safety identifier: {$safeUser}";

    $endpoint = 'https://api.openai.com/v1/realtime/client_secrets';
    $model = getenv('CW_OPENAI_REALTIME_MODEL') ?: 'gpt-realtime';
    $voice = getenv('CW_OPENAI_REALTIME_VOICE') ?: 'marin';

    $body = [
        'expires_after' => [
            'anchor' => 'created_at',
            'seconds' => 600,
        ],
        'session' => [
            'type' => 'realtime',
            'model' => $model,
            'instructions' => $instructions,
            'output_modalities' => ['audio'],
            'audio' => [
                'input' => [
                    'transcription' => [
                        'model' => 'whisper-1',
                    ],
                    'turn_detection' => [
                        'type' => 'server_vad',
                        'create_response' => false,
                        'interrupt_response' => true,
                        'prefix_padding_ms' => 300,
                        'silence_duration_ms' => 700,
                    ],
                ],
                'output' => [
                    'voice' => $voice,
                ],
            ],
        ],
    ];

    $ch = curl_init($endpoint);
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
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('OpenAI Realtime request failed: ' . $err);
    }
    $json = json_decode((string)$resp, true);
    if (!is_array($json) || $code < 200 || $code >= 300) {
        $msg = is_array($json) ? (string)($json['error']['message'] ?? ('HTTP ' . $code)) : substr((string)$resp, 0, 200);
        throw new RuntimeException('OpenAI Realtime error: ' . $msg);
    }

    $secret = (string)($json['value'] ?? $json['client_secret']['value'] ?? $json['client_secret'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('OpenAI Realtime response did not include a client secret.');
    }

    echo json_encode([
        'ok' => true,
        'client_secret' => $secret,
        'attempt_id' => $attemptId,
        'current_item_id' => $currentItemId,
        'realtime_model' => $model,
        'realtime_endpoint' => 'https://api.openai.com/v1/realtime/calls',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ptv3_token_fail(500, $e->getMessage());
}
