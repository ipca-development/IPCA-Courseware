<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
    $session = $communicationKernel->auth->requireSession();
    $method = SafetyHttp::requireMethod('GET', 'POST');
    if ($method === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'summary')));
        if ($action === 'summary') {
            SafetyHttp::json(200, array(
                'ok' => true,
                'analytics' => $safetyKernel->analytics->summary(
                    $session,
                    (string)($_GET['from_utc'] ?? ''),
                    (string)($_GET['to_utc'] ?? '')
                ),
            ));
        }
        if ($action === 'trend_candidates') {
            SafetyHttp::json(200, array(
                'ok' => true,
                'trend' => $safetyKernel->analytics->trendCandidates(
                    $session,
                    (string)($_GET['spi_uuid'] ?? ''),
                    (int)($_GET['periods'] ?? 6)
                ),
            ));
        }
        if ($action === 'correlation_candidate') {
            SafetyHttp::json(200, array(
                'ok' => true,
                'correlation' => $safetyKernel->analytics->correlationCandidate(
                    $session,
                    strtolower(trim((string)($_GET['metric_code'] ?? '')))
                ),
            ));
        }
        throw new SafetyException('validation_error', 'Unknown analytics action.', 400);
    }

    $input = SafetyHttp::input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if ($action === 'record_exposure') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'snapshot_uuid' => $safetyKernel->analytics->recordExposure(
                $session,
                (string)($input['from_utc'] ?? ''),
                (string)($input['to_utc'] ?? ''),
                (string)($input['exposure_unit'] ?? ''),
                (float)($input['exposure_value'] ?? 0),
                (string)($input['source_system'] ?? ''),
                (string)($input['source_reference'] ?? ''),
                is_array($input['dimensions'] ?? null) ? $input['dimensions'] : array(),
                is_array($input['provenance'] ?? null) ? $input['provenance'] : array()
            ),
        ));
    }
    if ($action === 'create_spi') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'spi_uuid' => $safetyKernel->analytics->createSpi(
                $session,
                (string)($input['code'] ?? ''),
                (string)($input['name'] ?? ''),
                is_array($input['definition'] ?? null) ? $input['definition'] : array(),
                isset($input['target_value']) ? (float)$input['target_value'] : null,
                isset($input['warning_value']) ? (float)$input['warning_value'] : null
            ),
        ));
    }
    if ($action === 'compute_spi') {
        SafetyHttp::json(200, array(
            'ok' => true,
            'spi' => $safetyKernel->analytics->computeSpi(
                $session,
                (string)($input['spi_uuid'] ?? ''),
                (string)($input['from_utc'] ?? ''),
                (string)($input['to_utc'] ?? '')
            ),
        ));
    }
    if ($action === 'record_cross_domain_snapshot') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'snapshot_uuid' => $safetyKernel->analytics->recordCrossDomainSnapshot(
                $session,
                (string)($input['domain'] ?? ''),
                (string)($input['subject_type'] ?? ''),
                (string)($input['subject_reference'] ?? ''),
                (string)($input['captured_at_utc'] ?? ''),
                is_array($input['metrics'] ?? null) ? $input['metrics'] : array(),
                is_array($input['provenance'] ?? null) ? $input['provenance'] : array()
            ),
        ));
    }
    if ($action === 'link_cross_domain_snapshot') {
        SafetyHttp::json(201, array(
            'ok' => true,
            'link_uuid' => $safetyKernel->analytics->linkCrossDomainSnapshot(
                $session,
                strtolower(trim((string)($input['safety_subject_type'] ?? ''))),
                (int)($input['safety_subject_id'] ?? 0),
                (string)($input['snapshot_uuid'] ?? ''),
                (string)($input['relationship_type'] ?? ''),
                (string)($input['rationale'] ?? '')
            ),
        ));
    }
    throw new SafetyException('validation_error', 'Unknown analytics action.', 400);
} catch (SafetyException $e) {
    SafetyHttp::fail($e);
} catch (Throwable) {
    SafetyHttp::json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
