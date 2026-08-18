<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $session = $communicationKernel->auth->requireSession();
    SafetyHttp::requireMethod('POST');
    $input = SafetyHttp::input();
    SafetyHttp::json(200, array(
        'ok' => true,
        'identity' => $safetyKernel->reporterVault->reveal(
            $session,
            (string)($input['report_uuid'] ?? ''),
            (string)($input['reason'] ?? '')
        ),
    ));
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
