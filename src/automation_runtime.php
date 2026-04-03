<?php

declare(strict_types=1);

require_once __DIR__ . '/notification_service.php';

final class AutomationRuntime
{
    public function dispatchEvent(PDO $pdo, string $eventKey, array $context = array()): array
    {
        $eventKey = trim($eventKey);

        if ($eventKey === '') {
            return array(
                'ok' => false,
                'event_key' => '',
                'matched_flows' => 0,
                'executed_flows' => 0,
                'executed_actions' => 0,
                'results' => array(),
                'error' => 'Missing event key',
            );
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $flowStmt = $pdo->prepare("
            SELECT
                f.id,
                f.name,
                f.event_definition_id,
                f.is_active,
                f.priority
            FROM automation_flows f
            INNER JOIN automation_event_definitions e
                ON e.id = f.event_definition_id
            WHERE e.event_key = ?
              AND e.is_active = 1
              AND f.is_active = 1
            ORDER BY f.priority ASC, f.id ASC
        ");
        $flowStmt->execute(array($eventKey));
        $flows = $flowStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$flows) {
            return array(
                'ok' => true,
                'event_key' => $eventKey,
                'matched_flows' => 0,
                'executed_flows' => 0,
                'executed_actions' => 0,
                'results' => array(),
            );
        }

        $flowIds = array();
        foreach ($flows as $flow) {
            $flowId = (int)($flow['id'] ?? 0);
            if ($flowId > 0) {
                $flowIds[] = $flowId;
            }
        }

        $conditionsByFlowId = $this->loadConditionsByFlowId($pdo, $flowIds);
        $actionsByFlowId = $this->loadActionsByFlowId($pdo, $flowIds);

        $results = array();
        $matchedFlows = 0;
        $executedFlows = 0;
        $executedActions = 0;

        foreach ($flows as $flow) {
            $flowId = (int)($flow['id'] ?? 0);
            $flowName = trim((string)($flow['name'] ?? ''));

            if ($flowId <= 0) {
                $results[] = array(
                    'flow_id' => 0,
                    'flow_name' => $flowName,
                    'matched' => false,
                    'executed' => false,
                    'reason' => 'Invalid flow_id',
                    'flow_run_id' => null,
                    'actions' => array(),
                );
                continue;
            }

            $conditions = isset($conditionsByFlowId[$flowId]) ? $conditionsByFlowId[$flowId] : array();
            $conditionResult = $this->evaluateFlowConditions($conditions, $context);

            if (!$conditionResult['matched']) {
                $results[] = array(
                    'flow_id' => $flowId,
                    'flow_name' => $flowName,
                    'matched' => false,
                    'executed' => false,
                    'reason' => $conditionResult['reason'],
                    'flow_run_id' => null,
                    'actions' => array(),
                );
                continue;
            }

            $matchedFlows++;

            $actions = isset($actionsByFlowId[$flowId]) ? $actionsByFlowId[$flowId] : array();

            if (empty($actions)) {
                $results[] = array(
                    'flow_id' => $flowId,
                    'flow_name' => $flowName,
                    'matched' => true,
                    'executed' => false,
                    'reason' => 'No actions configured',
                    'flow_run_id' => null,
                    'actions' => array(),
                );
                continue;
            }

            $flowRunId = $this->insertFlowRun($pdo, $flowId, $eventKey, $context);
            $executedFlows++;

            $flowActionResults = array();

            foreach ($actions as $action) {
                $actionId = (int)($action['id'] ?? 0);
                $actionKey = trim((string)($action['action_key'] ?? ''));
                $config = $this->decodeConfig((string)($action['config_json'] ?? ''));

                try {
                    if ($actionKey === 'send_email') {
                        $actionResult = $this->runSendEmail($pdo, $flowRunId, $actionKey, $config, $context);
                        $executedActions++;
                        $flowActionResults[] = array(
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $actionResult,
                        );
                        continue;
                    }

                    if ($actionKey === 'log_event') {
                        $actionResult = $this->runLogEvent($pdo, $flowRunId, $actionKey, $config, $context);
                        $executedActions++;
                        $flowActionResults[] = array(
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $actionResult,
                        );
                        continue;
                    }

                    $details = $this->encodeDetails(array(
                        'message' => 'Unsupported action',
                        'flow_id' => $flowId,
                        'action_id' => $actionId,
                        'action_key' => $actionKey,
                    ));

                    $this->insertActionRun($pdo, $flowRunId, $actionKey !== '' ? $actionKey : 'unknown', 'skipped', $details);

                    $flowActionResults[] = array(
                        'action_id' => $actionId,
                        'action_key' => $actionKey,
                        'ok' => false,
                        'error' => 'Unsupported action',
                    );
                } catch (Throwable $e) {
                    $details = $this->encodeDetails(array(
                        'message' => $e->getMessage(),
                        'flow_id' => $flowId,
                        'action_id' => $actionId,
                        'action_key' => $actionKey,
                    ));

                    $this->insertActionRun($pdo, $flowRunId, $actionKey !== '' ? $actionKey : 'unknown', 'error', $details);

                    $flowActionResults[] = array(
                        'action_id' => $actionId,
                        'action_key' => $actionKey,
                        'ok' => false,
                        'error' => $e->getMessage(),
                    );
                }
            }

            $results[] = array(
                'flow_id' => $flowId,
                'flow_name' => $flowName,
                'matched' => true,
                'executed' => true,
                'reason' => $conditionResult['reason'],
                'flow_run_id' => $flowRunId,
                'actions' => $flowActionResults,
            );
        }

        return array(
            'ok' => true,
            'event_key' => $eventKey,
            'matched_flows' => $matchedFlows,
            'executed_flows' => $executedFlows,
            'executed_actions' => $executedActions,
            'results' => $results,
        );
    }

    private function insertFlowRun(PDO $pdo, int $flowId, string $eventKey, array $context): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO automation_flow_runs
            (flow_id, event_key, context_json)
            VALUES (?, ?, ?)
        ");
        $stmt->execute(array(
            $flowId,
            $eventKey,
            $this->encodeDetails($context),
        ));

        return (int)$pdo->lastInsertId();
    }

    private function loadConditionsByFlowId(PDO $pdo, array $flowIds): array
    {
        if (empty($flowIds)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($flowIds), '?'));

               $stmt = $pdo->prepare("
            SELECT DISTINCT
                a.id,
                a.flow_id,
                a.action_key,
                a.config_json,
                a.sort_order
            FROM automation_flow_actions a
            INNER JOIN automation_flows f
                ON f.id = a.flow_id
            LEFT JOIN automation_flow_conditions c
                ON c.flow_id = f.id
            WHERE f.event_key = ?
              AND f.is_active = 1
            ORDER BY a.flow_id ASC, a.sort_order ASC, a.id ASC
        ");
        $stmt->execute($flowIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = array();

        foreach ($rows as $row) {
            $flowId = (int)($row['flow_id'] ?? 0);
            if ($flowId <= 0) {
                continue;
            }

            if (!isset($out[$flowId])) {
                $out[$flowId] = array();
            }

            $out[$flowId][] = $row;
        }

        return $out;
    }

    private function loadActionsByFlowId(PDO $pdo, array $flowIds): array
    {
        if (empty($flowIds)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($flowIds), '?'));

        $stmt = $pdo->prepare("
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
        $stmt->execute($flowIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = array();

        foreach ($rows as $row) {
            $flowId = (int)($row['flow_id'] ?? 0);
            if ($flowId <= 0) {
                continue;
            }

            if (!isset($out[$flowId])) {
                $out[$flowId] = array();
            }

            $out[$flowId][] = $row;
        }

        return $out;
    }

    private function evaluateFlowConditions(array $conditions, array $context): array
    {
        if (empty($conditions)) {
            return array(
                'matched' => true,
                'reason' => 'No conditions',
            );
        }

        foreach ($conditions as $condition) {
            $fieldKey = trim((string)($condition['field_key'] ?? ''));
            $operator = strtolower(trim((string)($condition['operator'] ?? '')));
            $expectedText = $condition['value_text'] ?? null;
            $expectedNumber = $condition['value_number'] ?? null;

            $actualExists = array_key_exists($fieldKey, $context);
            $actualValue = $actualExists ? $context[$fieldKey] : null;

            $matched = $this->compareCondition(
                $operator,
                $actualExists,
                $actualValue,
                $expectedText,
                $expectedNumber
            );

            if (!$matched) {
                return array(
                    'matched' => false,
                    'reason' => 'Condition failed for field_key: ' . $fieldKey,
                );
            }
        }

        return array(
            'matched' => true,
            'reason' => 'All conditions matched',
        );
    }

    private function compareCondition(string $operator, bool $actualExists, $actualValue, $expectedText, $expectedNumber): bool
    {
        if ($operator === 'is_empty') {
            if (!$actualExists) {
                return true;
            }
            return $this->normalizeScalar($actualValue) === '';
        }

        if ($operator === 'is_not_empty') {
            if (!$actualExists) {
                return false;
            }
            return $this->normalizeScalar($actualValue) !== '';
        }

        if (!$actualExists) {
            return false;
        }

        $actualNormalized = $this->normalizeScalar($actualValue);

        if (
            $operator === '' ||
            $operator === '=' ||
            $operator === '==' ||
            $operator === 'eq' ||
            $operator === 'equals'
        ) {
            return $actualNormalized === $this->normalizeScalar($expectedText);
        }

        if (
            $operator === '!=' ||
            $operator === '<>' ||
            $operator === 'neq' ||
            $operator === 'not_equals'
        ) {
            return $actualNormalized !== $this->normalizeScalar($expectedText);
        }

        if ($operator === 'gt') {
            if (!is_numeric($actualValue) || !is_numeric($expectedNumber)) {
                return false;
            }
            return (float)$actualValue > (float)$expectedNumber;
        }

        if ($operator === 'gte') {
            if (!is_numeric($actualValue) || !is_numeric($expectedNumber)) {
                return false;
            }
            return (float)$actualValue >= (float)$expectedNumber;
        }

        if ($operator === 'lt') {
            if (!is_numeric($actualValue) || !is_numeric($expectedNumber)) {
                return false;
            }
            return (float)$actualValue < (float)$expectedNumber;
        }

        if ($operator === 'lte') {
            if (!is_numeric($actualValue) || !is_numeric($expectedNumber)) {
                return false;
            }
            return (float)$actualValue <= (float)$expectedNumber;
        }

        return false;
    }

    private function runSendEmail(PDO $pdo, int $flowRunId, string $actionKey, array $config, array $eventContext): array
    {
        $notificationKey = trim((string)($config['notification_key'] ?? ''));
        $toEmail = trim((string)($config['to_email'] ?? ''));

        if ($notificationKey === '') {
            throw new RuntimeException('send_email requires notification_key');
        }

        if ($toEmail === '') {
            throw new RuntimeException('send_email requires to_email');
        }

        $toName = isset($config['to_name']) ? (string)$config['to_name'] : '';
        $notificationContext = array();

        if (isset($config['context']) && is_array($config['context'])) {
            $notificationContext = $config['context'];
        }

        if (!empty($eventContext)) {
            $notificationContext = array_merge($eventContext, $notificationContext);
        }

        $actorUserId = null;
        if (isset($config['actor_user_id']) && $config['actor_user_id'] !== '' && $config['actor_user_id'] !== null) {
            $actorUserId = (int)$config['actor_user_id'];
        }

        $headers = array();
        if (isset($config['headers']) && is_array($config['headers'])) {
            $headers = $config['headers'];
        }

        $service = new NotificationService($pdo);
        $sendResult = $service->sendSystemNotification(
            $notificationKey,
            $toEmail,
            $toName,
            $notificationContext,
            $actorUserId,
            $headers
        );

        $resultCode = !empty($sendResult['ok']) ? 'success' : 'failed';

        $details = $this->encodeDetails(array(
            'notification_key' => $notificationKey,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'context' => $notificationContext,
            'actor_user_id' => $actorUserId,
            'headers' => $headers,
            'send_result' => $sendResult,
        ));

        $this->insertActionRun($pdo, $flowRunId, $actionKey, $resultCode, $details);

        return $sendResult;
    }

    private function runLogEvent(PDO $pdo, int $flowRunId, string $actionKey, array $config, array $eventContext): array
    {
        $detailsPayload = array(
            'config' => $config,
            'context' => $eventContext,
        );

        $details = $this->encodeDetails($detailsPayload);
        $this->insertActionRun($pdo, $flowRunId, $actionKey, 'success', $details);

        return array(
            'logged' => true,
        );
    }

    private function insertActionRun(PDO $pdo, int $flowRunId, string $actionKey, string $result, ?string $details): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO automation_action_runs
            (flow_run_id, action_key, result, details)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $flowRunId,
            $actionKey,
            $result,
            $details,
        ));
    }

    private function decodeConfig(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return array();
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function encodeDetails(array $details): string
    {
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }

    private function normalizeScalar($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? trim($json) : '';
    }
}