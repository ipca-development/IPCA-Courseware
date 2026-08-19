<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$anonymousPaths = array(
    '/public/api/safety/_anonymous_bootstrap.php',
    '/public/api/safety/anonymous/submit.php',
    '/public/api/safety/anonymous/status.php',
    '/public/api/safety/anonymous/mailbox.php',
    '/public/api/safety/anonymous/attachments.php',
);
$anonymous = '';
foreach ($anonymousPaths as $path) {
    $anonymous .= file_get_contents($root . $path) ?: '';
}
$authenticated = file_get_contents($root . '/public/api/safety/reports.php') ?: '';
$support = file_get_contents($root . '/src/safety/SafetySupport.php') ?: '';
$anonymousService = file_get_contents($root . '/src/safety/SafetyAnonymousService.php') ?: '';
$workflow = file_get_contents($root . '/src/safety/SafetyWorkflowService.php') ?: '';
$intake = file_get_contents($root . '/src/safety/SafetyIntakeService.php') ?: '';
$vault = file_get_contents($root . '/src/safety/SafetyReporterVaultService.php') ?: '';
$capabilities = file_get_contents($root . '/src/communication/CommunicationConfigService.php') ?: '';
$http = file_get_contents($root . '/src/safety/SafetyHttp.php') ?: '';

$checks = array(
    'anonymous routes never require Communication session' =>
        !str_contains($anonymous, 'requireSession(')
        && !str_contains($anonymous, 'CommunicationKernel.php'),
    'anonymous routes do not inspect device or user agent metadata' =>
        !str_contains($anonymous, 'HTTP_USER_AGENT')
        && !str_contains($anonymous, 'device_uuid')
        && !str_contains($anonymous, 'device_id'),
    'anonymous network limiter transforms transient address before service call' =>
        str_contains($anonymous, 'rateLimitFingerprint(')
        && str_contains($support, "hash_hmac('sha256'")
        && str_contains($support, 'CW_SAFETY_RATE_LIMIT_KEY'),
    'anonymous failures do not log request context' =>
        !str_contains($anonymous, 'error_log(')
        && !str_contains($anonymous, 'CommunicationSupport::log'),
    'anonymous mailbox secret uses one-way password hashing' =>
        str_contains($support, 'PASSWORD_ARGON2ID')
        && str_contains($anonymousService, 'password_verify(')
        && !str_contains($anonymousService, 'secret_hash = ?')
        && str_contains($anonymousService, 'failed_attempts = failed_attempts + 1')
        && str_contains($anonymousService, 'locked_until_utc'),
    'anonymous response gives qualified privacy notice' =>
        str_contains($anonymousService, 'perfect anonymity cannot be guaranteed'),
    'anonymous submission requires idempotency key' =>
        str_contains($anonymous, 'SafetyHttp::idempotencyKey()')
        && str_contains($anonymousService, 'ipca_safety_idempotency_keys'),
    'authenticated report API requires bearer session' =>
        str_contains($authenticated, '$communicationKernel->auth->requireSession()'),
    'HTTP responses are no-store and use standard errors' =>
        str_contains($http, "header('Cache-Control: no-store, private')")
        && str_contains($http, "'error_code' => \$e->errorCode"),
    'workflow defines explicit transitions and closure gates' =>
        str_contains($workflow, 'private const TRANSITIONS')
        && str_contains($workflow, "'workflow_gate_failed'")
        && str_contains($workflow, "status <> 'closed'")
        && str_contains($workflow, 'report.acknowledged')
        && str_contains($workflow, 'ipca_safety_reportability_assessments')
        && str_contains($workflow, "phase = 'residual'")
        && str_contains($workflow, 'ipca_safety_action_closures')
        && str_contains($workflow, "direction = 'to_reporter'")
        && str_contains($workflow, 'ipca_safety_report_closures'),
    'mobile and backend report payload contracts are compatible' =>
        str_contains($intake, "\$input['description']")
        && str_contains($intake, "\$input['occurred_at_utc']")
        && str_contains($intake, "'reference'")
        && str_contains($anonymousService, "'receipt_id'")
        && str_contains($anonymousService, "'receipt_secret'"),
    'ISO-8601 event times are normalized before MySQL INSERT' =>
        str_contains($support, 'function nullableUtc')
        && str_contains($support, "format('Y-m-d H:i:s.v')")
        && str_contains($intake, 'SafetySupport::nullableUtc(')
        && str_contains($anonymousService, 'SafetySupport::nullableUtc('),
    'communication bootstrap advertises safety capabilities' =>
        str_contains($capabilities, "'safety_reporting_enabled'")
        && str_contains($capabilities, "'anonymous_reporting_enabled'"),
    'restricted reporter identity is encrypted and access-audited' =>
        str_contains($support, 'sodium_crypto_secretbox(')
        && str_contains($intake, 'ipca_safety_reporter_vault')
        && str_contains($vault, "requirePermission(\$session, 'vault.read')")
        && str_contains($vault, "'reporter_vault.accessed'"),
    'event history is appended for anonymous intake' =>
        str_contains($anonymousService, "'report.anonymously_submitted'")
        && !preg_match('/UPDATE\s+ipca_safety_events/i', $anonymousService),
    'anonymous attachments are mailbox-scoped and identity-free' =>
        str_contains($anonymous, 'attachmentContext(')
        && str_contains($anonymous, 'presignAnonymous(')
        && !str_contains($anonymous, 'uploaded_by_user_id'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed safety API/privacy checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: safety API and privacy contract checks passed.' . PHP_EOL;
