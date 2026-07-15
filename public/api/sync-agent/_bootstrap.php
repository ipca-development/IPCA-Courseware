<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/SyncAgentAuthService.php';
require_once __DIR__ . '/../../../src/SyncAgentGarminIngestionService.php';

header('Content-Type: application/json; charset=utf-8');

function sync_agent_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sync_agent_payload(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sync_agent_json(405, array('ok' => false, 'error' => 'Method not allowed.'));
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!$https && !str_starts_with($host, 'localhost') && !str_starts_with($host, '127.0.0.1')) {
        sync_agent_json(403, array('ok' => false, 'error' => 'HTTPS is required.'));
    }
    $raw = file_get_contents('php://input');
    $payload = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    if (!is_array($payload)) {
        sync_agent_json(400, array('ok' => false, 'error' => 'Invalid JSON payload.'));
    }
    return $payload;
}

function sync_agent_error(Throwable $e): void
{
    $message = $e->getMessage();
    $lower = strtolower($message);
    $code = str_contains($lower, 'token') ? 401 : 400;
    sync_agent_json($code, array('ok' => false, 'error' => $message));
}
