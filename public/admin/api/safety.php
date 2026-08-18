<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';
require_once __DIR__ . '/../../../src/safety/SafetyKernel.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function safety_staff_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function safety_staff_input(): array
{
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : $_POST;
}

cw_require_admin();
$user = cw_current_user($pdo);
if (!is_array($user)) {
    safety_staff_json(401, array('ok' => false, 'error' => 'Sign in is required.', 'error_code' => 'unauthenticated'));
}
$session = array('user' => $user + array('organization_id' => 1));
$communicationKernel = new CommunicationKernel($pdo);
$kernel = new SafetyKernel($pdo, $communicationKernel->objectStore, $communicationKernel->push);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'dashboard')));

try {
    if ($method === 'GET') {
        $payload = match ($action) {
            'dashboard' => $kernel->staff->dashboard($session),
            'reports' => array('reports' => $kernel->staff->listReports($session, $_GET)),
            'report' => array('report' => $kernel->staff->reportDetail($session, (string)($_GET['report_uuid'] ?? ''))),
            'registers' => $kernel->staff->registers($session),
            'risk_matrix' => array('cells' => $kernel->staff->riskMatrix($session)),
            'eccairs_config' => $kernel->staff->eccairsConfiguration($session),
            'eccairs_historical_correlations' => array(
                'correlations' => $kernel->eccairs->historicalCorrelations(
                    $session,
                    (string)($_GET['status'] ?? 'pending')
                ),
            ),
            'bulletins' => array('bulletins' => $kernel->staff->listBulletins($session)),
            default => throw new SafetyException('validation_error', 'Unknown staff safety action.', 400),
        };
        safety_staff_json(200, array('ok' => true) + $payload);
    }

    if ($method !== 'POST') {
        safety_staff_json(405, array('ok' => false, 'error' => 'Method not allowed.', 'error_code' => 'method_not_allowed'));
    }
    $input = safety_staff_input();
    $expected = (string)($_SESSION['safety_staff_csrf'] ?? '');
    $provided = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_IPCA_CSRF'] ?? ''));
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        throw new SafetyException('csrf_failed', 'The safety workspace session expired. Refresh and try again.', 403);
    }
    $action = strtolower(trim((string)($input['action'] ?? $action)));
    $result = match ($action) {
        'transition' => $kernel->workflow->transition(
            $session, (string)($input['report_uuid'] ?? ''), (string)($input['target'] ?? ''),
            (string)($input['rationale'] ?? '')
        ),
        'create_occurrence' => $kernel->reportability->createOccurrence(
            $session, (int)($input['report_id'] ?? 0), (string)($input['occurrence_type'] ?? ''),
            trim((string)($input['occurred_at_utc'] ?? '')) ?: null
        ),
        'assess_reportability' => array('assessment_id' => $kernel->reportability->assess(
            $session, (int)($input['occurrence_id'] ?? 0), (string)($input['framework'] ?? ''),
            (string)($input['decision'] ?? ''), (string)($input['rationale'] ?? ''),
            trim((string)($input['deadline_at_utc'] ?? '')) ?: null
        )),
        'prepare_eccairs' => $kernel->eccairs->prepare(
            $session,
            (int)($input['occurrence_id'] ?? 0),
            (string)($input['environment'] ?? 'sandbox'),
            (string)($input['mapping_version'] ?? '')
        ),
        'approve_eccairs' => $kernel->eccairs->approve(
            $session,
            (string)($input['submission_uuid'] ?? ''),
            (string)($input['rationale'] ?? '')
        ),
        'retry_eccairs' => (function () use ($kernel, $session, $input): array {
            $kernel->eccairs->retry(
                $session,
                (string)($input['submission_uuid'] ?? ''),
                (string)($input['verification_reference'] ?? '')
            );
            return array('status' => 'queued');
        })(),
        'propose_eccairs_historical_correlation' => $kernel->eccairs->proposeHistoricalCorrelation(
            $session,
            $input
        ),
        'review_eccairs_historical_correlation' => (function () use ($kernel, $session, $input): array {
            $kernel->eccairs->reviewHistoricalCorrelation(
                $session,
                (string)($input['correlation_uuid'] ?? ''),
                (string)($input['decision'] ?? ''),
                (string)($input['rationale'] ?? '')
            );
            return array('status' => (string)($input['decision'] ?? ''));
        })(),
        'create_hazard' => $kernel->risk->createHazard(
            $session, (string)($input['title'] ?? ''), (string)($input['description'] ?? ''),
            (int)($input['source_report_id'] ?? 0) ?: null
        ),
        'assess_risk' => $kernel->risk->snapshotRisk(
            $session, (int)($input['hazard_id'] ?? 0), (int)($input['matrix_version_id'] ?? 0),
            (string)($input['phase'] ?? ''), (string)($input['likelihood'] ?? ''),
            (string)($input['severity'] ?? ''), (string)($input['rationale'] ?? '')
        ),
        'accept_risk' => (function () use ($kernel, $session, $input): array {
            $kernel->risk->acceptResidualRisk($session, (int)($input['snapshot_id'] ?? 0), (string)($input['rationale'] ?? ''));
            return array('accepted' => true);
        })(),
        'open_investigation' => $kernel->investigations->open(
            $session, (int)($input['report_id'] ?? 0), (string)($input['scope'] ?? ''),
            (int)($input['lead_user_id'] ?? 0) ?: null
        ),
        'add_factor' => array('factor_id' => $kernel->investigations->addFactor(
            $session, (int)($input['investigation_id'] ?? 0), (string)($input['factor_type'] ?? ''),
            (string)($input['statement'] ?? ''), (string)($input['causal_role'] ?? '')
        )),
        'complete_investigation' => (function () use ($kernel, $session, $input): array {
            $kernel->investigations->complete(
                $session, (int)($input['investigation_id'] ?? 0), (string)($input['conclusion'] ?? '')
            );
            return array('completed' => true);
        })(),
        'create_action' => $kernel->actions->create(
            $session, (string)($input['source_type'] ?? 'report'), (int)($input['source_id'] ?? 0),
            (string)($input['title'] ?? ''), (string)($input['description'] ?? ''),
            (int)($input['owner_user_id'] ?? 0), trim((string)($input['due_at_utc'] ?? '')) ?: null
        ),
        'add_action_evidence' => array('evidence_id' => $kernel->actions->addEvidence(
            $session, (int)($input['action_id'] ?? 0), (string)($input['note'] ?? '')
        )),
        'review_effectiveness' => array('review_id' => $kernel->actions->reviewEffectiveness(
            $session, (int)($input['action_id'] ?? 0), (string)($input['outcome'] ?? ''),
            (string)($input['method'] ?? ''), (string)($input['result'] ?? '')
        )),
        'close_action' => (function () use ($kernel, $session, $input): array {
            $kernel->actions->close(
                $session, (int)($input['action_id'] ?? 0), (int)($input['review_id'] ?? 0),
                (string)($input['rationale'] ?? '')
            );
            return array('closed' => true);
        })(),
        'send_feedback' => array('update_uuid' => $kernel->feedback->send(
            $session, (int)($input['report_id'] ?? 0), (string)($input['body'] ?? '')
        )),
        'create_bulletin' => $kernel->staff->createBulletin($session, $input),
        'publish_bulletin' => (function () use ($kernel, $session, $input): array {
            $kernel->staff->publishBulletin($session, (string)($input['bulletin_uuid'] ?? ''));
            return array('published' => true);
        })(),
        default => throw new SafetyException('validation_error', 'Unknown staff safety action.', 400),
    };
    safety_staff_json(200, array('ok' => true, 'result' => $result));
} catch (SafetyException $e) {
    safety_staff_json($e->httpStatus, array('ok' => false, 'error' => $e->getMessage(), 'error_code' => $e->errorCode));
} catch (Throwable $e) {
    safety_staff_json(500, array('ok' => false, 'error' => 'Server error', 'error_code' => 'server_error'));
}
