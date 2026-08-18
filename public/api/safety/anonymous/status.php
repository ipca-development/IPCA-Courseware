<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_anonymous_bootstrap.php';

try {
    SafetyHttp::requireMethod('POST');
    $organizationId = max(1, (int)(getenv('CW_SAFETY_ORGANIZATION_ID') ?: 1));
    $fingerprint = SafetySupport::rateLimitFingerprint((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $input = SafetyHttp::input();
    $report = $safetyKernel->anonymous->status(
        $organizationId,
        (string)($input['receipt_code'] ?? $input['receipt_id'] ?? ''),
        (string)($input['mailbox_secret'] ?? $input['secret'] ?? ''),
        $fingerprint
    );
    SafetyHttp::json(200, array_merge(array('ok' => true, 'report' => $report), $report));
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
