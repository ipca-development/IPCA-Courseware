<?php
declare(strict_types=1);

require_once __DIR__ . '/../_anonymous_bootstrap.php';

try {
    SafetyHttp::requireMethod('GET');
    SafetyHttp::json(200, array(
        'ok' => true,
        'occurrence_types' => $safetyKernel->occurrenceIntakeContext->occurrenceTypes(1),
    ));
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable $e) {
    error_log('safety.anonymous.types.error ' . $e::class . ': ' . $e->getMessage());
    SafetyHttp::json(500, array(
        'ok' => false,
        'error' => 'Server error',
        'error_code' => 'server_error',
    ));
}
