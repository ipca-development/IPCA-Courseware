<?php
declare(strict_types=1);

/**
 * Compliance Comms Center — Postmark Inbound webhook endpoint.
 *
 * Verifies a shared secret (?token=… or X-Compliance-Webhook-Token header),
 * decodes the Postmark JSON payload, and persists it via
 * ComplianceCommsCenterEngine::ingestPostmarkInbound().
 *
 * Behaviour:
 *   - 401 + JSON error: missing or bad webhook secret.
 *   - 400 + JSON error: payload was not decodable JSON.
 *   - 200 + JSON ack:   payload accepted (including duplicate replays).
 *   - 500 + JSON error: only when the payload was valid but something else
 *                       inside the platform failed. Postmark will retry.
 *
 * No session, no auth gate, no rendering layout. Logs go through PHP's
 * default error_log() and into ipca_compliance_email_events.
 */

// Make sure unexpected output (stray notices, etc.) never leaks to Postmark.
ob_start();

// Resolve project root: /public/webhooks/postmark/ → 3 levels up.
$projectRoot = dirname(__DIR__, 3);

require_once $projectRoot . '/src/db.php';
require_once $projectRoot . '/src/compliance/CompliancePostmarkConfig.php';
require_once $projectRoot . '/src/compliance/ComplianceCommsCenterEngine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * @return never
 */
function compl_inbound_respond(int $status, array $body): void
{
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
}

// 1. Only POST is allowed.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    compl_inbound_respond(405, array('ok' => false, 'error' => 'method_not_allowed'));
}

// 2. Verify the shared secret (query token OR custom header).
$expected = CompliancePostmarkConfig::inboundWebhookSecret();
if ($expected === '') {
    error_log('[compliance-inbound] refused: POSTMARK_INBOUND_WEBHOOK_SECRET is not configured');
    compl_inbound_respond(401, array('ok' => false, 'error' => 'webhook_secret_not_configured'));
}
$providedQuery = isset($_GET['token']) ? (string)$_GET['token'] : null;
$providedHeader = $_SERVER['HTTP_X_COMPLIANCE_WEBHOOK_TOKEN'] ?? null;
if (!CompliancePostmarkConfig::verifyWebhookSecret($expected, $providedQuery, $providedHeader)) {
    compl_inbound_respond(401, array('ok' => false, 'error' => 'invalid_webhook_secret'));
}

// 3. Read and decode the body.
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    compl_inbound_respond(400, array('ok' => false, 'error' => 'empty_request_body'));
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    compl_inbound_respond(400, array('ok' => false, 'error' => 'invalid_json'));
}

// 4. Persist via the engine. Any failure is logged AND surfaced as 500 so
//    Postmark retries (it backs off automatically). The engine itself is
//    idempotent on Postmark MessageID so retries are safe.
try {
    $pdo = cw_db();
    $result = ComplianceCommsCenterEngine::ingestPostmarkInbound($pdo, $payload);
    compl_inbound_respond(200, array(
        'ok' => true,
        'action' => $result['action'],
        'email_id' => $result['email_id'],
        'thread_id' => $result['thread_id'],
        'attachments_stored' => $result['attachments_stored'],
        'attachments_failed' => $result['attachments_failed'],
        'notes' => $result['notes'],
    ));
} catch (Throwable $e) {
    error_log('[compliance-inbound] persist failed: ' . $e->getMessage());
    // Best-effort: persist a webhook_error event so the platform owner can see
    // the failure inside the inbox without grepping PHP logs.
    try {
        $pdo = cw_db();
        require_once $projectRoot . '/src/compliance/ComplianceCommsCenterEngine.php';
        $pmId = isset($payload['MessageID']) && is_string($payload['MessageID']) ? $payload['MessageID'] : null;
        ComplianceCommsCenterEngine::logEvent($pdo, null, $pmId, 'webhook_error', array(
            'scope' => 'inbound_ingest',
            'error' => substr($e->getMessage(), 0, 1000),
            'raw_bytes' => strlen($raw),
        ));
    } catch (Throwable) {
        // event-log failure is non-fatal here.
    }
    compl_inbound_respond(500, array('ok' => false, 'error' => 'persist_failed'));
}
