<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_anonymous_bootstrap.php';

try {
    SafetyHttp::requireMethod('POST');
    $organizationId = max(1, (int)(getenv('CW_SAFETY_ORGANIZATION_ID') ?: 1));
    $fingerprint = SafetySupport::rateLimitFingerprint((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $input = SafetyHttp::input();
    $receipt = (string)($input['receipt_code'] ?? $input['receipt_id'] ?? '');
    $secret = (string)($input['mailbox_secret'] ?? $input['secret'] ?? '');
    $action = strtolower(trim((string)($input['action'] ?? 'list')));
    if ($action === 'list') {
        $mailbox = $safetyKernel->anonymous->mailbox($organizationId, $receipt, $secret, $fingerprint);
        SafetyHttp::json(200, array_merge(array('ok' => true, 'mailbox' => $mailbox), $mailbox));
    }
    if ($action === 'post') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'update' => $safetyKernel->anonymous->postUpdate(
                $organizationId, $receipt, $secret, (string)($input['body'] ?? ''), $fingerprint
            ),
        ));
    }
    throw new SafetyException('validation_error', 'Unknown mailbox action.', 400);
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
