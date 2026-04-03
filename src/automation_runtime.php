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
                    $insertFlowRun->execute(array($flowId, $eventKey));
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

                if ($actionKey === 'notify_all_admins') {
                    $result = $this->runNotifyAllByRoles($pdo, $flowRunId, $actionKey, $config, $context, array('admin'));
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

                if ($actionKey === 'notify_all_instructors') {
                    $result = $this->runNotifyAllByRoles($pdo, $flowRunId, $actionKey, $config, $context, array('instructor', 'supervisor'));
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

                if ($actionKey === 'notify_all_students') {
                    $result = $this->runNotifyAllByRoles($pdo, $flowRunId, $actionKey, $config, $context, array('student'));
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

                if ($actionKey === 'notify_specific_admin') {
                    $result = $this->runNotifySpecificUser($pdo, $flowRunId, $actionKey, $config, $context, array('admin'));
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

                if ($actionKey === 'notify_specific_instructor') {
                    $result = $this->runNotifySpecificUser($pdo, $flowRunId, $actionKey, $config, $context, array('instructor', 'supervisor'));
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

            $results[] = $service->sendSystemNotification(
                $notificationKey,
                $toEmail,
                $toName,
                $eventContext,
                null,
                array()
            );
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