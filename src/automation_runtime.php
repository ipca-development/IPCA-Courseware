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
            'event_key' => $eventKey,
            'matched_actions' => 0,
            'executed_actions' => 0,
            'results' => array(),
            'error' => 'Missing event key',
        );
    }

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
    $stmt->execute(array($eventKey));
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array();
    $matchedActions = count($actions);
    $executedActions = 0;
    $flowRunIdsByFlowId = array();

    foreach ($actions as $action) {
        $actionId = (int)($action['id'] ?? 0);
        $flowId = (int)($action['flow_id'] ?? 0);
        $actionKey = trim((string)($action['action_key'] ?? ''));
        $config = $this->decodeConfig((string)($action['config_json'] ?? ''));

        if ($flowId > 0) {
            if (!isset($flowRunIdsByFlowId[$flowId])) {
                
				
				$insertFlowRun = $pdo->prepare("
					INSERT INTO automation_flow_runs
					(flow_id, event_key)
					VALUES (?, ?)
				");
				$insertFlowRun->execute(array(
					$flowId,
					$eventKey,
				));
				
				
                $flowRunIdsByFlowId[$flowId] = (int)$pdo->lastInsertId();
            }

            $flowRunId = (int)$flowRunIdsByFlowId[$flowId];
        } else {
            $flowRunId = $actionId;
        }

        try {
            if ($actionKey === 'send_email') {
                $result = $this->runSendEmail($pdo, $flowRunId, $actionKey, $config, $context);
                $executedActions++;
                $results[] = array(
                    'action_id' => $actionId,
                    'flow_id' => $flowId,
                    'action_key' => $actionKey,
                    'ok' => true,
                    'result' => $result,
                );
                continue;
            }

            if ($actionKey === 'log_event') {
                $result = $this->runLogEvent($pdo, $flowRunId, $actionKey, $config, $context);
                $executedActions++;
                $results[] = array(
                    'action_id' => $actionId,
                    'flow_id' => $flowId,
                    'action_key' => $actionKey,
                    'ok' => true,
                    'result' => $result,
                );
                continue;
            }

            $details = $this->encodeDetails(array(
                'message' => 'Unsupported action',
                'action_id' => $actionId,
                'flow_id' => $flowId,
                'action_key' => $actionKey,
            ));

            $this->insertActionRun($pdo, $flowRunId, $actionKey, 'skipped', $details);

            $results[] = array(
                'action_id' => $actionId,
                'flow_id' => $flowId,
                'action_key' => $actionKey,
                'ok' => false,
                'error' => 'Unsupported action',
            );
        } catch (Throwable $e) {
            $details = $this->encodeDetails(array(
                'message' => $e->getMessage(),
                'action_id' => $actionId,
                'flow_id' => $flowId,
                'action_key' => $actionKey,
            ));

            $this->insertActionRun($pdo, $flowRunId, $actionKey, 'error', $details);

            $results[] = array(
                'action_id' => $actionId,
                'flow_id' => $flowId,
                'action_key' => $actionKey,
                'ok' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    return array(
        'ok' => true,
        'event_key' => $eventKey,
        'matched_actions' => $matchedActions,
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
        $toName = trim((string)($config['to_name'] ?? ''));

        if (
            preg_match('/^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/', $toEmail, $matches)
            && array_key_exists($matches[1], $eventContext)
        ) {
            $toEmail = trim((string)$eventContext[$matches[1]]);
        }

        if (
            preg_match('/^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/', $toName, $matches)
            && array_key_exists($matches[1], $eventContext)
        ) {
            $toName = trim((string)$eventContext[$matches[1]]);
        }

        if ($notificationKey === '') {
            throw new RuntimeException('send_email requires notification_key');
        }

        if ($toEmail === '') {
            throw new RuntimeException('send_email requires to_email');
        }
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