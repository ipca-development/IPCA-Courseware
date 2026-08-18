<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_anonymous_bootstrap.php';

try {
    SafetyHttp::requireMethod('POST');
    $organizationId = max(1, (int)(getenv('CW_SAFETY_ORGANIZATION_ID') ?: 1));
    $fingerprint = SafetySupport::rateLimitFingerprint((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $input = SafetyHttp::input();
    $context = $safetyKernel->anonymous->attachmentContext(
        $organizationId,
        (string)($input['receipt_code'] ?? $input['receipt_id'] ?? ''),
        (string)($input['mailbox_secret'] ?? $input['secret'] ?? ''),
        $fingerprint
    );
    $action = strtolower(trim((string)($input['action'] ?? 'presign')));
    if ($action === 'presign') {
        SafetyHttp::json(200, array('ok' => true, 'attachment' => $safetyKernel->attachments->presignAnonymous(
            $context,
            (string)($input['filename'] ?? ''),
            (string)($input['mime_type'] ?? ''),
            (int)($input['byte_size'] ?? 0),
            isset($input['attachment_uuid']) ? (string)$input['attachment_uuid'] : null
        )));
    }
    if ($action === 'complete') {
        SafetyHttp::json(200, array('ok' => true, 'attachment' => $safetyKernel->attachments->completeAnonymous(
            $context,
            (string)($input['attachment_uuid'] ?? '')
        )));
    }
    throw new SafetyException('validation_error', 'Unknown attachment action.', 400);
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    // Deliberately do not log anonymous request metadata or payloads.
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
