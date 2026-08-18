<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $session = $communicationKernel->auth->requireSession();
    $method = SafetyHttp::requireMethod('GET', 'POST');
    if ($method === 'GET') {
        SafetyHttp::json(200, array(
            'ok' => true,
            'run' => $safetyKernel->ai->get($session, (string)($_GET['run_uuid'] ?? '')),
        ));
    }

    $input = SafetyHttp::input();
    $action = strtolower(trim((string)($input['action'] ?? 'request')));
    if ($action === 'request') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'run' => $safetyKernel->ai->request(
                $session,
                (string)($input['use_case'] ?? ''),
                (string)($input['subject_type'] ?? ''),
                (int)($input['subject_id'] ?? 0),
                (string)($input['provider'] ?? ''),
                (string)($input['model'] ?? ''),
                (string)($input['template_version'] ?? ''),
                is_array($input['input'] ?? null) ? $input['input'] : array(),
                is_array($input['provenance'] ?? null) ? $input['provenance'] : array()
            ),
        ));
    }
    if ($action === 'complete') {
        SafetyHttp::json(200, array(
            'ok' => true,
            'run' => $safetyKernel->ai->complete(
                $session,
                (string)($input['run_uuid'] ?? ''),
                is_array($input['output'] ?? null) ? $input['output'] : array(),
                is_array($input['provider_provenance'] ?? null) ? $input['provider_provenance'] : array()
            ),
        ));
    }
    if ($action === 'review') {
        $safetyKernel->ai->review(
            $session,
            (string)($input['run_uuid'] ?? ''),
            (string)($input['decision'] ?? ''),
            (string)($input['notes'] ?? '')
        );
        SafetyHttp::json(200, array('ok' => true, 'status' => 'review_recorded'));
    }
    throw new SafetyException('validation_error', 'Unknown AI assistance action.', 400);
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
