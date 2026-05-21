<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/progress_test_access.php';

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

// #region agent log
function ptv3_token_debug_log(array $data): void
{
    $dir = __DIR__ . '/../../../.cursor';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/debug-aeedb8.log',
        json_encode([
            'sessionId' => 'aeedb8',
            'runId' => 'initial',
            'hypothesisId' => (string)($data['hypothesisId'] ?? 'H10,H11'),
            'location' => (string)($data['location'] ?? 'public/student/api/progress_test_voice_token.php'),
            'message' => (string)($data['message'] ?? ''),
            'data' => is_array($data['data'] ?? null) ? $data['data'] : [],
            'timestamp' => (int)round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}
// #endregion

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
    if ($role !== 'admin') {
        $access = cw_progress_test_access_state($pdo, (int)$attempt['user_id'], (int)$attempt['cohort_id']);
        if (empty($access['allowed'])) {
            ptv3_token_fail(403, 'Progress test access code required');
        }
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
        "You are Maya, a calm text-to-speech voice for an oral progress test.\n"
        . "Always speak English only. Do not switch languages, even if the student speaks another language.\n"
        . "For every response.create request, parse the JSON object in the request instructions and speak only its text field verbatim.\n"
        . "Treat live microphone transcripts, previous student answers, and previous question context as irrelevant while speaking a response.create request.\n"
        . "Never mention meta-instructions, source labels, refusal language, limitation language, or prefatory remarks unless those words are inside the JSON text value.\n"
        . "Start immediately with the first word of the JSON text value and stop immediately after the final word. Never add a preface or follow-up sentence.\n"
        . "Do not answer, explain, tutor, grade, interpret, acknowledge, reassure, or improvise. Never add offers to help or extra commentary after the text value. If no explicit text value is provided, remain silent.\n"
        . "Tone for the spoken text: natural and concise.\n\n"
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
                        'language' => 'en',
                    ],
                    'turn_detection' => [
                        'type' => 'server_vad',
                        'create_response' => false,
                        'interrupt_response' => false,
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

    // #region agent log
    ptv3_token_debug_log([
        'message' => 'realtime client secret requested',
        'data' => [
            'attemptId' => $attemptId,
            'model' => $model,
            'voice' => $voice,
            'outputModalities' => $body['session']['output_modalities'],
            'turnDetectionType' => $body['session']['audio']['input']['turn_detection']['type'],
            'turnCreateResponse' => $body['session']['audio']['input']['turn_detection']['create_response'],
            'turnInterruptResponse' => $body['session']['audio']['input']['turn_detection']['interrupt_response'],
            'instructionsLength' => strlen($instructions),
        ],
    ]);
    // #endregion

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
