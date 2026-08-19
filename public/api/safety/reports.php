<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $session = $communicationKernel->auth->requireSession();
    $method = SafetyHttp::requireMethod('GET', 'POST', 'PATCH');
    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'list')));
        if ($action === 'flight_candidates') {
            SafetyHttp::json(200, array(
                'ok' => true,
                'flight_candidates' => $safetyKernel->occurrenceIntakeContext->flightCandidates(
                    $session,
                    trim((string)($_GET['event_at_utc'] ?? '')) ?: null
                ),
            ));
        }
        $reportUuid = trim((string)($_GET['report_uuid'] ?? ''));
        SafetyHttp::json(200, $reportUuid === ''
            ? array('ok' => true, 'reports' => $safetyKernel->intake->listOwn($session))
            : array('ok' => true, 'report' => $safetyKernel->intake->detailOwn($session, $reportUuid)));
    }

    $input = SafetyHttp::input();
    $action = strtolower(trim((string)($input['action'] ?? ($method === 'PATCH' ? 'update' : 'create'))));
    if ($action === 'create') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'report' => $safetyKernel->intake->create($session, $input, SafetyHttp::idempotencyKey()),
        ));
    }
    $reportUuid = (string)($input['report_uuid'] ?? '');
    if ($action === 'update') {
        SafetyHttp::json(200, array(
            'ok' => true,
            'report' => $safetyKernel->intake->updateOwn($session, $reportUuid, $input),
        ));
    }
    if ($action === 'submit') {
        SafetyHttp::json(200, array(
            'ok' => true,
            'report' => $safetyKernel->intake->submitOwn($session, $reportUuid),
        ));
    }
    throw new SafetyException('validation_error', 'Unknown report action.', 400);
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (CommunicationException $e) {
    SafetyHttp::json($e->httpStatus, array(
        'ok' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->errorCode,
    ));
} catch (Throwable $e) {
    error_log('safety.reports.error ' . $e::class . ': ' . $e->getMessage());
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
