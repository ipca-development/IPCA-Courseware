<?php
declare(strict_types=1);

/**
 * Compliance Comms Center — Postmark Tracking webhook endpoint.
 *
 * Receives Delivery / Open / Click / Bounce / SpamComplaint payloads (one
 * RecordType per request) from Postmark and persists each event into
 * `ipca_compliance_email_events`, with a best-effort status advance on the
 * parent `ipca_compliance_emails` row.
 *
 * Verifies a shared secret carried as ?token=… OR X-Compliance-Webhook-Token
 * header — same convention as the inbound endpoint. No session, no auth gate,
 * no rendering layout. Errors are surfaced as 4xx/5xx + JSON so Postmark
 * retries appropriately.
 */

ob_start();

// /public/webhooks/postmark/ → project root is 3 levels up.
$projectRoot = dirname(__DIR__, 3);

require_once $projectRoot . '/src/db.php';
require_once $projectRoot . '/src/compliance/CompliancePostmarkConfig.php';
require_once $projectRoot . '/src/compliance/ComplianceCommsCenterEngine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * @return never
 */
function compl_events_respond(int $status, array $body): void
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

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    compl_events_respond(405, array('ok' => false, 'error' => 'method_not_allowed'));
}

$expected = CompliancePostmarkConfig::trackingWebhookSecret();
if ($expected === '') {
    error_log('[compliance-events] refused: POSTMARK_TRACKING_WEBHOOK_SECRET is not configured');
    compl_events_respond(401, array('ok' => false, 'error' => 'webhook_secret_not_configured'));
}
$providedQuery = isset($_GET['token']) ? (string)$_GET['token'] : null;
$providedHeader = $_SERVER['HTTP_X_COMPLIANCE_WEBHOOK_TOKEN'] ?? null;
if (!CompliancePostmarkConfig::verifyWebhookSecret($expected, $providedQuery, $providedHeader)) {
    compl_events_respond(401, array('ok' => false, 'error' => 'invalid_webhook_secret'));
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    compl_events_respond(400, array('ok' => false, 'error' => 'empty_request_body'));
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    compl_events_respond(400, array('ok' => false, 'error' => 'invalid_json'));
}

try {
    $pdo = cw_db();
    $result = ComplianceCommsCenterEngine::processPostmarkEvent($pdo, $payload);
    compl_events_respond(200, array(
        'ok' => true,
        'action' => $result['action'],
        'event_type' => $result['event_type'],
        'email_id' => $result['email_id'],
    ));
} catch (Throwable $e) {
    error_log('[compliance-events] persist failed: ' . $e->getMessage());
    try {
        $pdo = cw_db();
        $pmId = isset($payload['MessageID']) && is_string($payload['MessageID']) ? $payload['MessageID'] : null;
        ComplianceCommsCenterEngine::logEvent($pdo, null, $pmId, 'webhook_error', array(
            'scope' => 'tracking_webhook',
            'error' => substr($e->getMessage(), 0, 1000),
            'record_type' => (string)($payload['RecordType'] ?? ''),
        ));
    } catch (Throwable) {
        // best-effort
    }
    compl_events_respond(500, array('ok' => false, 'error' => 'persist_failed'));
}
