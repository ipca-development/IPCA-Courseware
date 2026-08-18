<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_anonymous_bootstrap.php';

try {
    SafetyHttp::requireMethod('POST');
    $organizationId = max(1, (int)(getenv('CW_SAFETY_ORGANIZATION_ID') ?: 1));
    $fingerprint = SafetySupport::rateLimitFingerprint((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $submission = $safetyKernel->anonymous->submit(
        $organizationId,
        SafetyHttp::input(),
        $fingerprint,
        SafetyHttp::idempotencyKey()
    );
    SafetyHttp::json(201, array_merge(array('ok' => true, 'submission' => $submission), $submission));
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    // Deliberately do not log request metadata or payload for anonymous intake.
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
