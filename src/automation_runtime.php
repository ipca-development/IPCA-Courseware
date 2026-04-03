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
            INNER JOIN automation_event_definitions e
                ON e.id = f.event_definition_id
            WHERE e.event_key = ?
              AND e.is_active = 1
              AND f.is_active = 1
            ORDER BY a.sort_order ASC, a.id ASC
        ");
        $stmt->execute(array($eventKey));
        $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = array();
        $matchedActions = count($actions);
        $executedActions = 0;

        foreach ($actions as $action) {
            $actionId = (int)($action['id'] ?? 0);
            $flowId = (int)($action['flow_id'] ?? 0);
            $actionKey = trim((string)($action['action_key'] ?? ''));
            $config = $this->decodeConfig((string)($action['config_json'] ?? ''));

            $flowRunId = $flowId > 0 ? $flowId : $actionId;

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