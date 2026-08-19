<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $session = $communicationKernel->auth->requireSession();
    SafetyHttp::requireMethod('POST');
    $input = SafetyHttp::input();
    $action = strtolower(trim((string)($input['action'] ?? 'presign')));
    if ($action === 'presign') {
        SafetyHttp::json(200, array('ok' => true, 'attachment' => $safetyKernel->attachments->presign(
            $session,
            (string)($input['report_uuid'] ?? ''),
            (string)($input['filename'] ?? ''),
            (string)($input['mime_type'] ?? ''),
            (int)($input['byte_size'] ?? 0),
            isset($input['attachment_uuid']) ? (string)$input['attachment_uuid'] : null
        )));
    }
    if ($action === 'complete') {
        SafetyHttp::json(200, array('ok' => true, 'attachment' => $safetyKernel->attachments->complete(
            $session,
            (string)($input['attachment_uuid'] ?? '')
        )));
    }
    throw new SafetyException('validation_error', 'Unknown attachment action.', 400);
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (CommunicationException $e) {
    SafetyHttp::json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    error_log('safety.attachments.error ' . $e::class . ': ' . $e->getMessage());
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
