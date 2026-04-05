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
                'matched_flows' => 0,
                'matched_actions' => 0,
                'executed_actions' => 0,
                'results' => array(),
                'error' => 'Missing event key',
            );
        }

        $flows = $this->loadFlowsForEvent($pdo, $eventKey);

        $results = array();
        $matchedFlows = 0;
        $matchedActions = 0;
        $executedActions = 0;

        foreach ($flows as $flow) {
            $flowId = (int)($flow['id'] ?? 0);
            $flowName = trim((string)($flow['name'] ?? ''));
            $conditions = $this->loadFlowConditions($pdo, $flowId);

            $conditionCheck = $this->evaluateFlowConditions($conditions, $context);

            if (!$conditionCheck['matched']) {
                $results[] = array(
                    'flow_id' => $flowId,
                    'flow_name' => $flowName,
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'conditions_not_matched',
                    'condition_results' => $conditionCheck['condition_results'],
                );
                continue;
            }

            $actions = $this->loadFlowActions($pdo, $flowId);
            $matchedFlows++;
            $matchedActions += count($actions);

            if ($flowId > 0) {
                $insertFlowRun = $pdo->prepare("
                    INSERT INTO automation_flow_runs
                    (flow_id, event_key)
                    VALUES (?, ?)
                ");
                $insertFlowRun->execute(array($flowId, $eventKey));
                $flowRunId = (int)$pdo->lastInsertId();
            } else {
                $flowRunId = 0;
            }

            if (empty($actions)) {
                $results[] = array(
                    'flow_id' => $flowId,
                    'flow_name' => $flowName,
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'no_actions',
                    'condition_results' => $conditionCheck['condition_results'],
                );
                continue;
            }

            foreach ($actions as $action) {
                $actionId = (int)($action['id'] ?? 0);
                $actionKey = trim((string)($action['action_key'] ?? ''));
                $config = $this->decodeConfig((string)($action['config_json'] ?? ''));

                try {
                    if ($actionKey === 'send_email') {
                        $result = $this->runSendEmail($pdo, $flowRunId, $actionKey, $config, $context);
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
                        );
                        continue;
                    }

                    if ($actionKey === 'log_event') {
                        $result = $this->runLogEvent($pdo, $flowRunId, $actionKey, $config, $context);
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
                        );
                        continue;
                    }

                    if ($actionKey === 'notify_all_admins') {
                        $result = $this->runNotifyAllByRoles($pdo, $flowRunId, $actionKey, $config, $context, array('admin'));
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
                        );
                        continue;
                    }

                    if ($actionKey === 'notify_all_instructors') {
                        $result = $this->runNotifyAllByRoles($pdo, $flowRunId, $actionKey, $config, $context, array('instructor', 'supervisor'));
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
                        );
                        continue;
                    }

                    if ($actionKey === 'notify_all_students') {
                        $result = $this->runNotifyAllByRoles($pdo, $flowRunId, $actionKey, $config, $context, array('student'));
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
                        );
                        continue;
                    }

                    if ($actionKey === 'notify_specific_admin') {
                        $result = $this->runNotifySpecificUser($pdo, $flowRunId, $actionKey, $config, $context, array('admin'));
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
                        );
                        continue;
                    }

                    if ($actionKey === 'notify_specific_instructor') {
                        $result = $this->runNotifySpecificUser($pdo, $flowRunId, $actionKey, $config, $context, array('instructor', 'supervisor'));
                        $executedActions++;
                        $results[] = array(
                            'flow_id' => $flowId,
                            'flow_name' => $flowName,
                            'action_id' => $actionId,
                            'action_key' => $actionKey,
                            'ok' => true,
                            'result' => $result,
                            'condition_results' => $conditionCheck['condition_results'],
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
                        'flow_id' => $flowId,
                        'flow_name' => $flowName,
                        'action_id' => $actionId,
                        'action_key' => $actionKey,
                        'ok' => false,
                        'error' => 'Unsupported action',
                        'condition_results' => $conditionCheck['condition_results'],
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
                        'flow_id' => $flowId,
                        'flow_name' => $flowName,
                        'action_id' => $actionId,
                        'action_key' => $actionKey,
                        'ok' => false,
                        'error' => $e->getMessage(),
                        'condition_results' => $conditionCheck['condition_results'],
                    );
                }
            }
        }

        return array(
            'ok' => true,
            'event_key' => $eventKey,
            'matched_flows' => $matchedFlows,
            'matched_actions' => $matchedActions,
            'executed_actions' => $executedActions,
            'results' => $results,
        );
    }

    private function loadFlowsForEvent(PDO $pdo, string $eventKey): array
    {
        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                description,
                event_key,
                is_active,
                priority
            FROM automation_flows
            WHERE event_key = ?
              AND is_active = 1
            ORDER BY priority ASC, id ASC
        ");
        $stmt->execute(array($eventKey));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function loadFlowConditions(PDO $pdo, int $flowId): array
    {
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
            WHERE flow_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(array($flowId));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function loadFlowActions(PDO $pdo, int $flowId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                id,
                flow_id,
                action_key,
                config_json,
                sort_order
            FROM automation_flow_actions
            WHERE flow_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(array($flowId));

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function evaluateFlowConditions(array $conditions, array $context): array
    {
        if (empty($conditions)) {
            return array(
                'matched' => true,
                'condition_results' => array(),
            );
        }

        $conditionResults = array();

        foreach ($conditions as $condition) {
            $fieldKey = trim((string)($condition['field_key'] ?? ''));
            $operator = trim((string)($condition['operator'] ?? ''));
            $expectedText = $condition['value_text'] ?? null;
            $expectedNumber = $condition['value_number'] ?? null;
            $actualValue = array_key_exists($fieldKey, $context) ? $context[$fieldKey] : null;

            $matched = $this->evaluateSingleCondition(
                $actualValue,
                $operator,
                $expectedText,
                $expectedNumber
            );

            $conditionResults[] = array(
                'condition_id' => (int)($condition['id'] ?? 0),
                'field_key' => $fieldKey,
                'operator' => $operator,
                'expected_text' => $expectedText,
                'expected_number' => $expectedNumber,
                'actual_value' => $actualValue,
                'matched' => $matched,
            );

            if (!$matched) {
                return array(
                    'matched' => false,
                    'condition_results' => $conditionResults,
                );
            }
        }

        return array(
            'matched' => true,
            'condition_results' => $conditionResults,
        );
    }

    private function evaluateSingleCondition(mixed $actualValue, string $operator, mixed $expectedText, mixed $expectedNumber): bool
    {
        switch ($operator) {
            case '=':
                return $this->normalizeScalar($actualValue) === $this->normalizeExpected($expectedText, $expectedNumber);

            case '!=':
                return $this->normalizeScalar($actualValue) !== $this->normalizeExpected($expectedText, $expectedNumber);

            case '>':
                return $this->compareNumeric($actualValue, $expectedText, $expectedNumber, '>');

            case '>=':
                return $this->compareNumeric($actualValue, $expectedText, $expectedNumber, '>=');

            case '<':
                return $this->compareNumeric($actualValue, $expectedText, $expectedNumber, '<');

            case '<=':
                return $this->compareNumeric($actualValue, $expectedText, $expectedNumber, '<=');

            case 'contains':
                return strpos($this->normalizeString($actualValue), $this->normalizeString($this->normalizeExpected($expectedText, $expectedNumber))) !== false;

            case 'not_contains':
                return strpos($this->normalizeString($actualValue), $this->normalizeString($this->normalizeExpected($expectedText, $expectedNumber))) === false;

            case 'in':
                return $this->valueInList($actualValue, $expectedText, $expectedNumber, true);

            case 'not_in':
                return $this->valueInList($actualValue, $expectedText, $expectedNumber, false);

            case 'is_empty':
                return $this->isEmptyValue($actualValue);

            case 'is_not_empty':
                return !$this->isEmptyValue($actualValue);
        }

        return false;
    }

    private function normalizeExpected(mixed $expectedText, mixed $expectedNumber): string
    {
        if ($expectedNumber !== null && $expectedNumber !== '') {
            return $this->normalizeScalar($expectedNumber);
        }

        return $this->normalizeScalar($expectedText);
    }

    private function normalizeScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return trim((string)$value);
    }

    private function normalizeString(mixed $value): string
    {
        return strtolower($this->normalizeScalar($value));
    }

    private function compareNumeric(mixed $actualValue, mixed $expectedText, mixed $expectedNumber, string $operator): bool
    {
        $actual = $this->toFloatOrNull($actualValue);
        $expected = $this->toFloatOrNull($expectedNumber !== null && $expectedNumber !== '' ? $expectedNumber : $expectedText);

        if ($actual === null || $expected === null) {
            return false;
        }

        switch ($operator) {
            case '>':
                return $actual > $expected;
            case '>=':
                return $actual >= $expected;
            case '<':
                return $actual < $expected;
            case '<=':
                return $actual <= $expected;
        }

        return false;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }

    private function valueInList(mixed $actualValue, mixed $expectedText, mixed $expectedNumber, bool $shouldBeInList): bool
    {
        $actual = $this->normalizeScalar($actualValue);
        $rawList = $expectedText;

        if (($rawList === null || $rawList === '') && $expectedNumber !== null && $expectedNumber !== '') {
            $rawList = (string)$expectedNumber;
        }

        $list = array();
        foreach (explode(',', (string)$rawList) as $part) {
            $normalized = trim($part);
            if ($normalized !== '') {
                $list[] = $normalized;
            }
        }

        $inList = in_array($actual, $list, true);
        return $shouldBeInList ? $inList : !$inList;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return count($value) === 0;
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

		
		 require_once __DIR__ . '/courseware_progression_v2.php';
        $progression = new CoursewareProgressionV2($pdo);

        $progression->recordAutomationEmailAudit([
            'user_id' => (int)($notificationContext['user_id'] ?? 0),
            'cohort_id' => (int)($notificationContext['cohort_id'] ?? 0),
            'lesson_id' => (int)($notificationContext['lesson_id'] ?? 0),
            'progress_test_id' => isset($notificationContext['progress_test_id']) ? (int)$notificationContext['progress_test_id'] : null,
            'email_type' => (string)$notificationKey,
            'recipients_to' => [[
                'email' => $toEmail,
                'name' => $toName,
            ]],
            'subject' => (string)($sendResult['rendered_subject'] ?? $notificationKey),
            'body_html' => (string)($sendResult['rendered_html'] ?? ''),
            'body_text' => (string)($sendResult['rendered_text'] ?? ''),
            'sent_status' => !empty($sendResult['ok']) ? 'sent' : 'failed',
            'sent_at' => !empty($sendResult['ok']) ? gmdate('Y-m-d H:i:s') : null,
            'notification_template_id' => isset($sendResult['template_id']) ? (int)$sendResult['template_id'] : null,
            'notification_template_version_id' => isset($sendResult['template_version_id']) ? (int)$sendResult['template_version_id'] : null,
            'render_context' => $notificationContext,
        ]);
		
        $details = $this->encodeDetails(array(
            'notification_key' => $notificationKey,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'context' => $notificationContext,
            'actor_user_id' => $actorUserId,
            'headers' => $headers,
            'send_result' => $sendResult,
        ));

        $this->insertActionRun($pdo, $flowRunId, $actionKey, 'success', $details);

        return $sendResult;
    }

    private function runNotifyAllByRoles(PDO $pdo, int $flowRunId, string $actionKey, array $config, array $eventContext, array $roles): array
    {
        $notificationKey = trim((string)($config['notification_key'] ?? ''));
        if ($notificationKey === '') {
            throw new RuntimeException($actionKey . ' requires notification_key');
        }

        if (empty($roles)) {
            throw new RuntimeException($actionKey . ' requires at least one role');
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));

        $sql = "
            SELECT id, email, name, first_name, last_name, role
            FROM users
            WHERE role IN ($placeholders)
              AND status = 'active'
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($roles);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $service = new NotificationService($pdo);
        $results = array();

        foreach ($users as $user) {
            $toEmail = trim((string)($user['email'] ?? ''));
            if ($toEmail === '') {
                continue;
            }

            $toName = trim((string)($user['name'] ?? ''));
            if ($toName === '') {
                $toName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
            }
            if ($toName === '') {
                $toName = $toEmail;
            }

            $sendResult = $service->sendSystemNotification(
				$notificationKey,
				$toEmail,
				$toName,
				$eventContext,
				null,
				array()
			);

			// 🔥 NEW: audit log
			require_once __DIR__ . '/courseware_progression_v2.php';
			$progression = new CoursewareProgressionV2($pdo);

			$progression->recordAutomationEmailAudit([
				'user_id' => (int)($eventContext['user_id'] ?? 0),
				'cohort_id' => (int)($eventContext['cohort_id'] ?? 0),
				'lesson_id' => (int)($eventContext['lesson_id'] ?? 0),
				'progress_test_id' => isset($eventContext['progress_test_id']) ? (int)$eventContext['progress_test_id'] : null,
				'email_type' => (string)$notificationKey,
				'recipients_to' => [[
					'email' => $toEmail,
					'name' => $toName,
				]],
				'subject' => (string)($sendResult['rendered_subject'] ?? $notificationKey),
				'body_html' => (string)($sendResult['rendered_html'] ?? ''),
				'body_text' => (string)($sendResult['rendered_text'] ?? ''),
				'sent_status' => !empty($sendResult['ok']) ? 'sent' : 'failed',
				'sent_at' => !empty($sendResult['ok']) ? gmdate('Y-m-d H:i:s') : null,
				'notification_template_id' => isset($sendResult['template_id']) ? (int)$sendResult['template_id'] : null,
				'notification_template_version_id' => isset($sendResult['template_version_id']) ? (int)$sendResult['template_version_id'] : null,
				'render_context' => $eventContext,
			]);

			$results[] = $sendResult;
        }

        $details = $this->encodeDetails(array(
            'notification_key' => $notificationKey,
            'roles' => $roles,
            'recipient_count' => count($results),
            'results' => $results,
        ));

        $this->insertActionRun($pdo, $flowRunId, $actionKey, 'success', $details);

        return array(
            'notification_key' => $notificationKey,
            'recipient_count' => count($results),
            'results' => $results,
        );
    }

    private function runNotifySpecificUser(PDO $pdo, int $flowRunId, string $actionKey, array $config, array $eventContext, array $allowedRoles): array
    {
        $notificationKey = trim((string)($config['notification_key'] ?? ''));
        $recipientUserId = isset($config['recipient_user_id']) ? (int)$config['recipient_user_id'] : 0;

        if ($notificationKey === '') {
            throw new RuntimeException($actionKey . ' requires notification_key');
        }

        if ($recipientUserId <= 0) {
            throw new RuntimeException($actionKey . ' requires recipient_user_id');
        }

        $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));

        $params = array_merge(array($recipientUserId), $allowedRoles);

        $sql = "
            SELECT id, email, name, first_name, last_name, role
            FROM users
            WHERE id = ?
              AND role IN ($placeholders)
              AND status = 'active'
              AND email IS NOT NULL
              AND email <> ''
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException($actionKey . ' recipient not found or inactive');
        }

        $toEmail = trim((string)($user['email'] ?? ''));
        $toName = trim((string)($user['name'] ?? ''));
        if ($toName === '') {
            $toName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }
        if ($toName === '') {
            $toName = $toEmail;
        }

        $service = new NotificationService($pdo);
        $sendResult = $service->sendSystemNotification(
				$notificationKey,
				$toEmail,
				$toName,
				$eventContext,
				null,
				array()
			);

			// 🔥 NEW: audit log
			require_once __DIR__ . '/courseware_progression_v2.php';
			$progression = new CoursewareProgressionV2($pdo);

			$progression->recordAutomationEmailAudit([
				'user_id' => (int)($eventContext['user_id'] ?? 0),
				'cohort_id' => (int)($eventContext['cohort_id'] ?? 0),
				'lesson_id' => (int)($eventContext['lesson_id'] ?? 0),
				'progress_test_id' => isset($eventContext['progress_test_id']) ? (int)$eventContext['progress_test_id'] : null,
				'email_type' => (string)$notificationKey,
				'recipients_to' => [[
					'email' => $toEmail,
					'name' => $toName,
				]],
				'subject' => (string)($sendResult['rendered_subject'] ?? $notificationKey),
				'body_html' => (string)($sendResult['rendered_html'] ?? ''),
				'body_text' => (string)($sendResult['rendered_text'] ?? ''),
				'sent_status' => !empty($sendResult['ok']) ? 'sent' : 'failed',
				'sent_at' => !empty($sendResult['ok']) ? gmdate('Y-m-d H:i:s') : null,
				'notification_template_id' => isset($sendResult['template_id']) ? (int)$sendResult['template_id'] : null,
				'notification_template_version_id' => isset($sendResult['template_version_id']) ? (int)$sendResult['template_version_id'] : null,
				'render_context' => $eventContext,
			]);

        $details = $this->encodeDetails(array(
            'notification_key' => $notificationKey,
            'recipient_user_id' => $recipientUserId,
            'recipient_role' => (string)($user['role'] ?? ''),
            'to_email' => $toEmail,
            'to_name' => $toName,
            'send_result' => $sendResult,
        ));

        $this->insertActionRun($pdo, $flowRunId, $actionKey, 'success', $details);

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
}