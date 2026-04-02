<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function runAutomationFlows(PDO $pdo, string $eventKey, array $context = array()): array
{
    $flows = loadFlowsForEvent($pdo, $eventKey);
    $results = array();

    foreach ($flows as $flow) {
        $flowId = (int)($flow['id'] ?? 0);
        if ($flowId <= 0) {
            continue;
        }

        $conditions = isset($flow['conditions']) && is_array($flow['conditions']) ? $flow['conditions'] : array();
        $actions = isset($flow['actions']) && is_array($flow['actions']) ? $flow['actions'] : array();

        if (!conditionsMatch($conditions, $context)) {
            $results[] = array(
                'flow_id' => $flowId,
                'event_key' => $eventKey,
                'status' => 'skipped_conditions',
            );
            continue;
        }

        if (alreadyExecuted($pdo, $flowId, $eventKey, $context)) {
            $results[] = array(
                'flow_id' => $flowId,
                'event_key' => $eventKey,
                'status' => 'skipped_already_executed',
            );
            continue;
        }

        $executionResult = executeFlow($pdo, $flow, $eventKey, $context, $actions);
        $results[] = $executionResult;
    }

    return $results;
}

function loadFlowsForEvent(PDO $pdo, string $eventKey): array
{
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.name,
            f.description,
            f.event_key,
            f.is_active,
            f.priority
        FROM automation_flows f
        WHERE f.is_active = 1
          AND f.event_key = ?
        ORDER BY f.priority ASC, f.id ASC
    ");
    $stmt->execute(array($eventKey));
    $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$flows) {
        return array();
    }

    $flowIds = array();
    foreach ($flows as $flow) {
        $flowId = (int)($flow['id'] ?? 0);
        if ($flowId > 0) {
            $flowIds[] = $flowId;
        }
    }

    if (empty($flowIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($flowIds), '?'));

    $condStmt = $pdo->prepare("
        SELECT
            id,
            flow_id,
            field_key,
            operator,
            value_text,
            value_number,
            sort_order
        FROM automation_flow_conditions
        WHERE flow_id IN ($placeholders)
        ORDER BY flow_id ASC, sort_order ASC, id ASC
    ");
    $condStmt->execute($flowIds);
    $conditionRows = $condStmt->fetchAll(PDO::FETCH_ASSOC);

    $actionStmt = $pdo->prepare("
        SELECT
            id,
            flow_id,
            action_key,
            config_json,
            sort_order
        FROM automation_flow_actions
        WHERE flow_id IN ($placeholders)
        ORDER BY flow_id ASC, sort_order ASC, id ASC
    ");
    $actionStmt->execute($flowIds);
    $actionRows = $actionStmt->fetchAll(PDO::FETCH_ASSOC);

    $conditionsByFlow = array();
    foreach ($conditionRows as $row) {
        $fid = (int)($row['flow_id'] ?? 0);
        if (!isset($conditionsByFlow[$fid])) {
            $conditionsByFlow[$fid] = array();
        }
        $conditionsByFlow[$fid][] = $row;
    }

    $actionsByFlow = array();
    foreach ($actionRows as $row) {
        $fid = (int)($row['flow_id'] ?? 0);
        if (!isset($actionsByFlow[$fid])) {
            $actionsByFlow[$fid] = array();
        }
        $actionsByFlow[$fid][] = $row;
    }

    foreach ($flows as &$flow) {
        $fid = (int)($flow['id'] ?? 0);
        $flow['conditions'] = $conditionsByFlow[$fid] ?? array();
        $flow['actions'] = $actionsByFlow[$fid] ?? array();
    }
    unset($flow);

    return $flows;
}

function conditionsMatch(array $conditions, array $context): bool
{
    foreach ($conditions as $condition) {
        $fieldKey = trim((string)($condition['field_key'] ?? ''));
        $operator = trim((string)($condition['operator'] ?? ''));
        $expectedText = $condition['value_text'] ?? null;
        $expectedNumber = $condition['value_number'] ?? null;
        $actual = $context[$fieldKey] ?? null;

        switch ($operator) {
            case 'equals':
                if ((string)$actual !== (string)$expectedText) {
                    return false;
                }
                break;

            case 'not_equals':
                if ((string)$actual === (string)$expectedText) {
                    return false;
                }
                break;

            case 'contains':
                if (strpos((string)$actual, (string)$expectedText) === false) {
                    return false;
                }
                break;

            case 'not_contains':
                if (strpos((string)$actual, (string)$expectedText) !== false) {
                    return false;
                }
                break;

            case 'gt':
                if (!is_numeric($actual) || (float)$actual <= (float)$expectedNumber) {
                    return false;
                }
                break;

            case 'gte':
                if (!is_numeric($actual) || (float)$actual < (float)$expectedNumber) {
                    return false;
                }
                break;

            case 'lt':
                if (!is_numeric($actual) || (float)$actual >= (float)$expectedNumber) {
                    return false;
                }
                break;

            case 'lte':
                if (!is_numeric($actual) || (float)$actual > (float)$expectedNumber) {
                    return false;
                }
                break;

            case 'is_empty':
                if ($actual !== null && $actual !== '') {
                    return false;
                }
                break;

            case 'is_not_empty':
                if ($actual === null || $actual === '') {
                    return false;
                }
                break;

            default:
                return false;
        }
    }

    return true;
}

function alreadyExecuted(PDO $pdo, int $flowId, string $eventKey, array $context = array()): bool
{
    $userId = isset($context['user_id']) ? (int)$context['user_id'] : 0;
    $lessonId = isset($context['lesson_id']) ? (int)$context['lesson_id'] : 0;

    $stmt = $pdo->prepare("
        SELECT id
        FROM automation_flow_executions
        WHERE flow_id = ?
          AND user_id <=> ?
          AND lesson_id <=> ?
          AND event_type = ?
        LIMIT 1
    ");
    $stmt->execute(array(
        $flowId,
        $userId > 0 ? $userId : null,
        $lessonId > 0 ? $lessonId : null,
        $eventKey,
    ));

    return (bool)$stmt->fetchColumn();
}

function markExecuted(PDO $pdo, int $flowId, string $eventKey, array $context = array()): int
{
    $userId = isset($context['user_id']) ? (int)$context['user_id'] : 0;
    $lessonId = isset($context['lesson_id']) ? (int)$context['lesson_id'] : 0;

    $stmt = $pdo->prepare("
        INSERT INTO automation_flow_executions
        (
            flow_id,
            user_id,
            lesson_id,
            event_type,
            executed_at
        )
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute(array(
        $flowId,
        $userId > 0 ? $userId : null,
        $lessonId > 0 ? $lessonId : null,
        $eventKey,
    ));

    return (int)$pdo->lastInsertId();
}

function executeFlow(PDO $pdo, array $flow, string $eventKey, array $context = array(), ?array $actions = null): array
{
    $flowId = (int)($flow['id'] ?? 0);
    $actions = is_array($actions) ? $actions : (isset($flow['actions']) && is_array($flow['actions']) ? $flow['actions'] : array());

    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($contextJson === false) {
        $contextJson = '{}';
    }

    $flowRunId = null;
    $actionResults = array();
    $hasImplementedAction = false;

    try {
        $pdo->beginTransaction();

        $stmtRun = $pdo->prepare("
            INSERT INTO automation_flow_runs
            (
                flow_id,
                event_key,
                context_json,
                result,
                created_at
            )
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmtRun->execute(array(
            $flowId,
            $eventKey,
            $contextJson,
            'started',
        ));

        $flowRunId = (int)$pdo->lastInsertId();

        $stmtActionRun = $pdo->prepare("
            INSERT INTO automation_action_runs
            (
                flow_run_id,
                action_key,
                result,
                details,
                created_at
            )
            VALUES (?, ?, ?, ?, NOW())
        ");

        foreach ($actions as $action) {
            $actionKey = trim((string)($action['action_key'] ?? ''));
            if ($actionKey === '') {
                continue;
            }

            $details = 'Action wiring not implemented';
            $result = 'not_implemented';

            $stmtActionRun->execute(array(
                $flowRunId,
                $actionKey,
                $result,
                $details,
            ));

            $actionResults[] = array(
                'action_key' => $actionKey,
                'result' => $result,
                'details' => $details,
            );
        }

        $finalResult = $hasImplementedAction ? 'completed' : 'not_executed';

        $stmtUpdateRun = $pdo->prepare("
            UPDATE automation_flow_runs
            SET result = ?
            WHERE id = ?
        ");
        $stmtUpdateRun->execute(array(
            $finalResult,
            $flowRunId,
        ));

        if ($hasImplementedAction) {
            markExecuted($pdo, $flowId, $eventKey, $context);
        }

        $pdo->commit();

        return array(
            'flow_id' => $flowId,
            'event_key' => $eventKey,
            'status' => $hasImplementedAction ? 'executed' : 'not_executed',
            'flow_run_id' => $flowRunId,
            'actions' => $actionResults,
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array(
            'flow_id' => $flowId,
            'event_key' => $eventKey,
            'status' => 'error',
            'flow_run_id' => $flowRunId,
            'error' => $e->getMessage(),
            'actions' => $actionResults,
        );
    }
}