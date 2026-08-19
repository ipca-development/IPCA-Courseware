<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangePlanService.php';
require_once __DIR__ . '/../../../src/publishing/BooksManualsChangeArchitectService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @param array<string,mixed> $payload */
function architect_api_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string,mixed> */
function architect_api_input(): array
{
    $input = array_merge($_GET, $_POST);
    if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Request body must be valid JSON.');
        }
        $input = array_merge($input, $decoded);
    }
    return $input;
}

function architect_api_csrf(): string
{
    if (!isset($_SESSION['books_manuals_ai_csrf'])
        || !is_string($_SESSION['books_manuals_ai_csrf'])
        || strlen($_SESSION['books_manuals_ai_csrf']) < 32) {
        $_SESSION['books_manuals_ai_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['books_manuals_ai_csrf'];
}

/** @param array<string,mixed> $input */
function architect_api_require_csrf(array $input): void
{
    $provided = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(architect_api_csrf(), $provided)) {
        architect_api_json(403, array('ok' => false, 'error' => 'Invalid CSRF token.'));
    }
}

$user = compliance_require_access($pdo);
$userId = (int)($user['id'] ?? 0);
$plans = new BooksManualsChangePlanService($pdo);
$architect = new BooksManualsChangeArchitectService($pdo, $plans);

try {
    if (!$plans->tablesPresent()) {
        throw new RuntimeException('The Manual Change Architect tables are unavailable.');
    }
    $input = architect_api_input();
    $action = trim((string)($input['action'] ?? 'plan'));
    if ($action === 'impact_decision') {
        architect_api_require_csrf($input);
    }
    switch ($action) {
        case 'plan':
            $planId = (int)($input['plan_id'] ?? 0);
            architect_api_json(200, array(
                'ok' => true,
                'plan' => $architect->getCompleteCheckpointReport($planId),
                'csrf_token' => architect_api_csrf(),
            ));

        case 'impact_decision':
            architect_api_json(200, array(
                'ok' => true,
                'result' => $plans->recordImpactDecision(
                    (int)($input['plan_id'] ?? 0),
                    (int)($input['impact_id'] ?? 0),
                    (string)($input['decision'] ?? ''),
                    (string)($input['note'] ?? ''),
                    $userId
                ),
                'csrf_token' => architect_api_csrf(),
            ));

        default:
            throw new InvalidArgumentException('Unknown Manual Change Architect action.');
    }
} catch (InvalidArgumentException $e) {
    architect_api_json(400, array('ok' => false, 'error' => $e->getMessage()));
} catch (RuntimeException $e) {
    architect_api_json(409, array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
    error_log('Manual Change Architect API: ' . $e->getMessage());
    architect_api_json(500, array('ok' => false, 'error' => 'Internal server error.'));
}
