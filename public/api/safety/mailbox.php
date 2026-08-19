<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $session = $communicationKernel->auth->requireSession();
    $method = SafetyHttp::requireMethod('GET', 'POST');
    if ($method === 'GET') {
        $report = $safetyKernel->intake->detailOwn($session, (string)($_GET['report_uuid'] ?? ''));
        $messages = array_map(static fn(array $update): array => array(
            'message_uuid' => (string)$update['update_uuid'],
            'body' => (string)$update['body'],
            'sender_label' => $update['direction'] === 'to_reporter' ? 'Safety Team' : 'You',
            'created_at_utc' => (string)$update['created_at_utc'],
        ), $report['updates'] ?? array());
        SafetyHttp::json(200, array(
            'ok' => true,
            'report' => $report,
            'messages' => $messages,
        ));
    }
    $input = SafetyHttp::input();
    SafetyHttp::json(201, array(
        'ok' => true,
        'update' => $safetyKernel->intake->postReporterUpdate(
            $session,
            (string)($input['report_uuid'] ?? ''),
            (string)($input['body'] ?? '')
        ),
    ));
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (CommunicationException $e) {
    SafetyHttp::json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    error_log('safety.mailbox.error ' . $e::class . ': ' . $e->getMessage());
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
