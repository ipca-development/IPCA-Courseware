<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/automation_catalog.php';

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function af_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(array(
        'ok' => false,
        'error' => $message,
    ));
    exit;
}

function af_json_ok(array $payload = array()): void
{
    echo json_encode(array_merge(array('ok' => true), $payload));
    exit;
}

function af_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        af_json_error('Invalid JSON payload');
    }

    return $decoded;
}

function af_string_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function af_decimal_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function af_normalize_conditions(array $conditions): array
{
    $out = array();

    foreach ($conditions as $row) {
        if (!is_array($row)) {
            continue;
        }

        $fieldKey = trim((string)($row['field_key'] ?? ''));
        $operator = trim((string)($row['operator'] ?? ''));
        $valueText = af_string_or_null($row['value_text'] ?? null);
        $valueNumber = af_decimal_or_null($row['value_number'] ?? null);

        if ($fieldKey === '' || $operator === '') {
            continue;
        }

        $out[] = array(
            'field_key' => $fieldKey,
            'operator' => $operator,
            'value_text' => $valueText,
            'value_number' => $valueNumber,
            'sort_order' => count($out) + 1,
        );
    }

    return $out;
}

function af_normalize_actions(array $actions): array
{
    $out = array();

    foreach ($actions as $row) {
        if (!is_array($row)) {
            continue;
        }

        $actionKey = trim((string)($row['action_key'] ?? ''));
        $config = isset($row['config_json']) && is_array($row['config_json']) ? $row['config_json'] : array();

        if ($actionKey === '') {
            continue;
        }

        $out[] = array(
            'action_key' => $actionKey,
            'config_json' => json_encode($config),
            'sort_order' => count($out) + 1,
        );
    }

    return $out;
}

function af_flow_detail(PDO $pdo, int $flowId): ?array
{
    $flow = automation_flow_detail($pdo, $flowId);
    if (!$flow) {
        return null;
    }

    $flow['id'] = (int)($flow['id'] ?? 0);
    $flow['name'] = (string)($flow['name'] ?? '');
    $flow['description'] = (string)($flow['description'] ?? '');
    $flow['event_key'] = (string)($flow['event_key'] ?? '');
    $flow['is_active'] = (int)!empty($flow['is_active']);
    $flow['priority'] = (int)($flow['priority'] ?? 100);

    $safeConditions = array();
    foreach ((array)($flow['conditions'] ?? array()) as $row) {
        $safeConditions[] = array(
            'id' => (int)($row['id'] ?? 0),
            'flow_id' => (int)($row['flow_id'] ?? 0),
            'field_key' => (string)($row['field_key'] ?? ''),
            'operator' => (string)($row['operator'] ?? ''),
            'value_text' => (string)($row['value_text'] ?? ''),
            'value_number' => ($row['value_number'] === null || $row['value_number'] === '') ? null : (float)$row['value_number'],
            'sort_order' => (int)($row['sort_order'] ?? 0),
        );
    }
    $flow['conditions'] = $safeConditions;

    $safeActions = array();
    foreach ((array)($flow['actions'] ?? array()) as $row) {
        $safeActions[] = array(
            'id' => (int)($row['id'] ?? 0),
            'flow_id' => (int)($row['flow_id'] ?? 0),
            'action_key' => (string)($row['action_key'] ?? ''),
            'config_json' => (string)($row['config_json'] ?? ''),
            'sort_order' => (int)($row['sort_order'] ?? 0),
        );
    }
    $flow['actions'] = $safeActions;

    return $flow;
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $mode = trim((string)($_GET['mode'] ?? 'list'));

        if ($mode === 'catalog') {
            af_json_ok(array(
                'categories' => automation_category_rows($pdo, true),
                'event_groups' => automation_event_grouped_options($pdo, true),
                'condition_fields' => automation_condition_field_options(),
                'operators' => automation_operator_options(),
                'actions' => automation_action_options(),
            ));
        }

        if ($mode === 'detail') {
            $flowId = (int)($_GET['id'] ?? 0);
            if ($flowId <= 0) {
                af_json_error('Missing flow id');
            }

            $detail = af_flow_detail($pdo, $flowId);
            if (!$detail) {
                af_json_error('Flow not found', 404);
            }

            af_json_ok(array('flow' => $detail));
        }

        af_json_ok(array(
            'flows' => automation_flow_rows($pdo),
        ));
    }

    $payload = af_request_json();
    $action = trim((string)($payload['action'] ?? ''));

    if ($method === 'POST' && $action === 'save_flow') {
        $flowId = (int)($payload['id'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $eventKey = trim((string)($payload['event_key'] ?? ''));
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $priority = (int)($payload['priority'] ?? 100);
        $conditions = af_normalize_conditions(isset($payload['conditions']) && is_array($payload['conditions']) ? $payload['conditions'] : array());
        $actions = af_normalize_actions(isset($payload['actions']) && is_array($payload['actions']) ? $payload['actions'] : array());

        if ($name === '') {
            af_json_error('Flow name is required');
        }
        if ($eventKey === '') {
            af_json_error('Event key is required');
        }

        $validEvents = automation_event_label_map($pdo, false);
        if (!isset($validEvents[$eventKey])) {
            af_json_error('Unknown event key');
        }

        $pdo->beginTransaction();

        if ($flowId > 0) {
            $stmt = $pdo->prepare("
                UPDATE automation_flows
                SET
                    name = ?,
                    description = ?,
                    event_key = ?,
                    is_active = ?,
                    priority = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute(array(
                $name,
                $description !== '' ? $description : null,
                $eventKey,
                $isActive,
                $priority,
                $flowId,
            ));

            $deleteCond = $pdo->prepare("DELETE FROM automation_flow_conditions WHERE flow_id = ?");
            $deleteCond->execute(array($flowId));

            $deleteAct = $pdo->prepare("DELETE FROM automation_flow_actions WHERE flow_id = ?");
            $deleteAct->execute(array($flowId));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO automation_flows
                (
                    name,
                    description,
                    event_key,
                    is_active,
                    priority,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute(array(
                $name,
                $description !== '' ? $description : null,
                $eventKey,
                $isActive,
                $priority,
            ));

            $flowId = (int)$pdo->lastInsertId();
        }

        if (!empty($conditions)) {
            $stmtCond = $pdo->prepare("
                INSERT INTO automation_flow_conditions
                (
                    flow_id,
                    field_key,
                    operator,
                    value_text,
                    value_number,
                    sort_order
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($conditions as $row) {
                $stmtCond->execute(array(
                    $flowId,
                    $row['field_key'],
                    $row['operator'],
                    $row['value_text'],
                    $row['value_number'],
                    $row['sort_order'],
                ));
            }
        }

        if (!empty($actions)) {
            $stmtAct = $pdo->prepare("
                INSERT INTO automation_flow_actions
                (
                    flow_id,
                    action_key,
                    config_json,
                    sort_order
                )
                VALUES (?, ?, ?, ?)
            ");

            foreach ($actions as $row) {
                $stmtAct->execute(array(
                    $flowId,
                    $row['action_key'],
                    $row['config_json'],
                    $row['sort_order'],
                ));
            }
        }

        $pdo->commit();

        $detail = af_flow_detail($pdo, $flowId);
        af_json_ok(array(
            'message' => 'Flow saved',
            'flow' => $detail,
        ));
    }

    if ($method === 'POST' && $action === 'delete_flow') {
        $flowId = (int)($payload['id'] ?? 0);
        if ($flowId <= 0) {
            af_json_error('Missing flow id');
        }

        $stmt = $pdo->prepare("DELETE FROM automation_flows WHERE id = ?");
        $stmt->execute(array($flowId));

        af_json_ok(array(
            'message' => 'Flow deleted',
        ));
    }

    if ($method === 'POST' && $action === 'toggle_flow') {
        $flowId = (int)($payload['id'] ?? 0);
        $isActive = !empty($payload['is_active']) ? 1 : 0;

        if ($flowId <= 0) {
            af_json_error('Missing flow id');
        }

        $stmt = $pdo->prepare("
            UPDATE automation_flows
            SET
                is_active = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute(array($isActive, $flowId));

        af_json_ok(array(
            'message' => 'Flow updated',
        ));
    }

    af_json_error('Unsupported request', 405);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    af_json_error($e->getMessage(), 500);
}