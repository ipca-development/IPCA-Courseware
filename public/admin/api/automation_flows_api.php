<?php
declare(strict_types=1);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/automation_catalog.php';

header('Content-Type: application/json; charset=utf-8');

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Forbidden'));
    exit;
}

function af_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return array();
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function af_response(array $data): void
{
    echo json_encode($data);
    exit;
}

function af_normalize_conditions(array $conditions): array
{
    $validFields = automation_condition_field_options();
    $validOps = automation_condition_operator_options();
    $numericFields = automation_numeric_fields();

    $out = array();
    $sort = 0;

    foreach ($conditions as $row) {
        if (!is_array($row)) {
            continue;
        }

        $fieldKey = trim((string)($row['field_key'] ?? ''));
        $operator = trim((string)($row['operator'] ?? ''));
        $valueRaw = isset($row['value']) ? $row['value'] : '';

        if ($fieldKey === '' || !isset($validFields[$fieldKey])) {
            continue;
        }
        if ($operator === '' || !isset($validOps[$operator])) {
            continue;
        }

        $valueText = null;
        $valueNumber = null;

        if (in_array($fieldKey, $numericFields, true)) {
            if ($valueRaw === '' || $valueRaw === null) {
                continue;
            }
            $valueNumber = (float)$valueRaw;
        } else {
            $valueText = trim((string)$valueRaw);
            if ($valueText === '') {
                continue;
            }
        }

        $out[] = array(
            'field_key' => $fieldKey,
            'operator' => $operator,
            'value_text' => $valueText,
            'value_number' => $valueNumber,
            'sort_order' => $sort
        );
        $sort++;
    }

    return $out;
}

function af_normalize_actions(array $actions): array
{
    $validActions = automation_action_options();
    $out = array();
    $sort = 0;

    foreach ($actions as $row) {
        if (!is_array($row)) {
            continue;
        }

        $actionKey = trim((string)($row['action_key'] ?? ''));
        if ($actionKey === '' || !isset($validActions[$actionKey])) {
            continue;
        }

        $config = array();

        if ($actionKey === 'send_notification') {
            $notificationKey = trim((string)($row['notification_key'] ?? ''));
            if ($notificationKey === '') {
                continue;
            }
            $config['notification_key'] = $notificationKey;
        }

        if ($actionKey === 'grant_extra_attempts') {
            $extraAttempts = (int)($row['extra_attempts'] ?? 0);
            $config['extra_attempts'] = max(0, $extraAttempts);
        }

        if ($actionKey === 'apply_deadline_extension') {
            $hours = (int)($row['extension_hours'] ?? 0);
            $config['extension_hours'] = max(0, $hours);
        }

        if ($actionKey === 'create_required_action') {
            $requiredActionType = trim((string)($row['required_action_type'] ?? ''));
            if ($requiredActionType !== '') {
                $config['required_action_type'] = $requiredActionType;
            }
        }

        $out[] = array(
            'action_key' => $actionKey,
            'config_json' => json_encode($config),
            'sort_order' => $sort
        );
        $sort++;
    }

    return $out;
}

$input = af_json_input();
$action = trim((string)($input['action'] ?? ''));

if ($action === 'save_flow') {
    $flowId = (int)($input['flow_id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $eventKey = trim((string)($input['event_key'] ?? ''));
    $isActive = !empty($input['is_active']) ? 1 : 0;
    $priority = (int)($input['priority'] ?? 100);

    $validEvents = automation_event_options();
    if ($name === '') {
        af_response(array('ok' => false, 'error' => 'Flow name is required.'));
    }
    if ($eventKey === '' || !isset($validEvents[$eventKey])) {
        af_response(array('ok' => false, 'error' => 'Valid event is required.'));
    }

    $conditions = af_normalize_conditions((array)($input['conditions'] ?? array()));
    $actions = af_normalize_actions((array)($input['actions'] ?? array()));

    try {
        $pdo->beginTransaction();

        if ($flowId > 0) {
            $stmt = $pdo->prepare("
                UPDATE automation_flows
                SET name = ?, description = ?, event_key = ?, is_active = ?, priority = ?
                WHERE id = ?
            ");
            $stmt->execute(array($name, $description, $eventKey, $isActive, $priority, $flowId));

            $pdo->prepare("DELETE FROM automation_flow_conditions WHERE flow_id = ?")->execute(array($flowId));
            $pdo->prepare("DELETE FROM automation_flow_actions WHERE flow_id = ?")->execute(array($flowId));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO automation_flows
                (name, description, event_key, is_active, priority)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute(array($name, $description, $eventKey, $isActive, $priority));
            $flowId = (int)$pdo->lastInsertId();
        }

        if (!empty($conditions)) {
            $stmt = $pdo->prepare("
                INSERT INTO automation_flow_conditions
                (flow_id, field_key, operator, value_text, value_number, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($conditions as $row) {
                $stmt->execute(array(
                    $flowId,
                    $row['field_key'],
                    $row['operator'],
                    $row['value_text'],
                    $row['value_number'],
                    $row['sort_order']
                ));
            }
        }

        if (!empty($actions)) {
            $stmt = $pdo->prepare("
                INSERT INTO automation_flow_actions
                (flow_id, action_key, config_json, sort_order)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($actions as $row) {
                $stmt->execute(array(
                    $flowId,
                    $row['action_key'],
                    $row['config_json'],
                    $row['sort_order']
                ));
            }
        }

        $pdo->commit();
        af_response(array('ok' => true, 'flow_id' => $flowId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        af_response(array('ok' => false, 'error' => $e->getMessage()));
    }
}

if ($action === 'delete_flow') {
    $flowId = (int)($input['flow_id'] ?? 0);
    if ($flowId <= 0) {
        af_response(array('ok' => false, 'error' => 'Missing flow_id.'));
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM automation_flows WHERE id = ?");
        $stmt->execute(array($flowId));
        af_response(array('ok' => true));
    } catch (Throwable $e) {
        af_response(array('ok' => false, 'error' => $e->getMessage()));
    }
}

if ($action === 'toggle_flow') {
    $flowId = (int)($input['flow_id'] ?? 0);
    $isActive = !empty($input['is_active']) ? 1 : 0;

    if ($flowId <= 0) {
        af_response(array('ok' => false, 'error' => 'Missing flow_id.'));
    }

    try {
        $stmt = $pdo->prepare("UPDATE automation_flows SET is_active = ? WHERE id = ?");
        $stmt->execute(array($isActive, $flowId));
        af_response(array('ok' => true));
    } catch (Throwable $e) {
        af_response(array('ok' => false, 'error' => $e->getMessage()));
    }
}

if ($action === 'test_flow') {
    $flowId = (int)($input['flow_id'] ?? 0);
    $context = (array)($input['context'] ?? array());

    if ($flowId <= 0) {
        af_response(array('ok' => false, 'error' => 'Missing flow_id.'));
    }

    $fStmt = $pdo->prepare("SELECT * FROM automation_flows WHERE id = ? LIMIT 1");
    $fStmt->execute(array($flowId));
    $flow = $fStmt->fetch(PDO::FETCH_ASSOC);

    if (!$flow) {
        af_response(array('ok' => false, 'error' => 'Flow not found.'));
    }

    $cStmt = $pdo->prepare("
        SELECT * FROM automation_flow_conditions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $cStmt->execute(array($flowId));
    $conditions = $cStmt->fetchAll(PDO::FETCH_ASSOC);

    $aStmt = $pdo->prepare("
        SELECT * FROM automation_flow_actions
        WHERE flow_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $aStmt->execute(array($flowId));
    $actions = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    $matched = true;
    $checks = array();

    foreach ($conditions as $cond) {
        $fieldKey = (string)$cond['field_key'];
        $operator = (string)$cond['operator'];
        $expected = $cond['value_text'] !== null ? $cond['value_text'] : $cond['value_number'];
        $actual = array_key_exists($fieldKey, $context) ? $context[$fieldKey] : null;
        $pass = false;

        switch ($operator) {
            case '=':
                $pass = ($actual == $expected);
                break;
            case '!=':
                $pass = ($actual != $expected);
                break;
            case '>=':
                $pass = ($actual >= $expected);
                break;
            case '<=':
                $pass = ($actual <= $expected);
                break;
            case '>':
                $pass = ($actual > $expected);
                break;
            case '<':
                $pass = ($actual < $expected);
                break;
        }

        $checks[] = array(
            'field_key' => $fieldKey,
            'operator' => $operator,
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $pass
        );

        if (!$pass) {
            $matched = false;
        }
    }

    af_response(array(
        'ok' => true,
        'matched' => $matched,
        'checks' => $checks,
        'actions' => $actions
    ));
}

af_response(array('ok' => false, 'error' => 'Invalid action.'));