<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationApnsClient.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/CommunicationConfigService.php';

final class CommunicationPushService
{
    public function __construct(
        private PDO $pdo,
        private ConversationService $conversations,
        private CommunicationConfigService $config,
        private CommunicationPushTransport $transport
    ) {
    }

    public function useTransport(CommunicationPushTransport $transport): void
    {
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        $flag = strtolower(trim($this->config->get('push_enabled', '1')));
        $enabled = in_array($flag, array('1', 'true', 'yes', 'on'), true);
        return $enabled && $this->transport->isReady();
    }

    public function notifyNewMessage(int $messageId, int $senderDeviceId): void
    {
        if ($messageId < 1) {
            return;
        }
        $stmt = $this->pdo->prepare("
            SELECT m.id, m.message_uuid, m.body, m.conversation_id, m.sender_user_id,
                   c.conversation_uuid, u.name AS sender_name
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            LEFT JOIN users u ON u.id = m.sender_user_id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute(array($messageId));
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($message)) {
            return;
        }

        $devices = $this->recipientDevices((int)$message['conversation_id'], (int)$message['sender_user_id'], $senderDeviceId);
        if ($devices === array()) {
            return;
        }

        $title = trim((string)($message['sender_name'] ?? ''));
        if ($title === '') {
            $title = 'IPCA';
        }
        $body = trim((string)$message['body']);
        if (mb_strlen($body) > 80) {
            $body = mb_substr($body, 0, 80);
        }

        foreach ($devices as $device) {
            $this->deliver(
                (int)$message['id'],
                $device,
                $title,
                $body,
                (string)$message['conversation_uuid'],
                (string)$message['message_uuid']
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recipientDevices(int $conversationId, int $senderUserId, int $senderDeviceId): array
    {
        $sql = "
            SELECT d.id, d.user_id, d.apns_token, d.push_authorized, d.revoked_at_utc
        ";
        if ($this->hasApnsEnvironmentColumn()) {
            $sql .= ', d.apns_environment';
        }
        $sql .= "
            FROM ipca_communication_conversation_members mem
            INNER JOIN ipca_communication_devices d ON d.user_id = mem.user_id
            WHERE mem.conversation_id = ?
              AND mem.left_at_utc IS NULL
              AND mem.user_id != ?
              AND mem.muted = 0
              AND d.revoked_at_utc IS NULL
              AND d.push_authorized = 1
              AND d.apns_token IS NOT NULL
              AND TRIM(d.apns_token) != ''
              AND d.id != ?
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array($conversationId, $senderUserId, $senderDeviceId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $device
     */
    private function deliver(
        int $messageId,
        array $device,
        string $title,
        string $body,
        string $conversationUuid,
        string $messageUuid
    ): void {
        $deviceId = (int)$device['id'];
        $existing = $this->pdo->prepare('SELECT id, accepted_at_utc FROM ipca_communication_push_attempts WHERE message_id = ? AND device_id = ? LIMIT 1');
        $existing->execute(array($messageId, $deviceId));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && trim((string)($row['accepted_at_utc'] ?? '')) !== '') {
            return;
        }

        $attemptId = is_array($row) ? (int)$row['id'] : 0;
        if ($attemptId < 1) {
            $now = CommunicationSupport::nowUtc();
            try {
                $this->pdo->prepare("
                    INSERT INTO ipca_communication_push_attempts
                      (push_uuid, message_id, device_id, created_at_utc)
                    VALUES (?, ?, ?, ?)
                ")->execute(array(CommunicationSupport::uuid(), $messageId, $deviceId, $now));
                $attemptId = (int)$this->pdo->lastInsertId();
            } catch (Throwable) {
                $existing->execute(array($messageId, $deviceId));
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    return;
                }
                $attemptId = (int)$row['id'];
                if (trim((string)($row['accepted_at_utc'] ?? '')) !== '') {
                    return;
                }
            }
        }

        if (!$this->isConfigured()) {
            $this->markFailed($attemptId, 'not_configured');
            return;
        }

        $badge = $this->conversations->unreadTotal((int)$device['user_id']);
        $environment = strtolower(trim((string)($device['apns_environment'] ?? 'sandbox')));
        if ($environment !== 'production') {
            $environment = 'sandbox';
        }
        $payload = array(
            'aps' => array(
                'alert' => array(
                    'title' => $title,
                    'body' => $body,
                ),
                'badge' => $badge,
                'sound' => 'default',
                'thread-id' => $conversationUuid,
            ),
            'conversation_uuid' => $conversationUuid,
            'message_uuid' => $messageUuid,
        );

        $result = $this->transport->send((string)$device['apns_token'], $environment, $payload);
        if ($result->accepted) {
            $this->pdo->prepare('UPDATE ipca_communication_push_attempts SET accepted_at_utc = ?, failed_at_utc = NULL, provider_response = ? WHERE id = ?')
                ->execute(array(CommunicationSupport::nowUtc(), substr($result->reason, 0, 255), $attemptId));
            CommunicationSupport::log('communication.push.accepted', array(
                'message_id' => $messageId,
                'device_id' => $deviceId,
                'environment' => $environment,
            ));
            return;
        }

        $this->markFailed($attemptId, $result->reason !== '' ? $result->reason : 'send_failed');
        if ($result->invalidateToken) {
            $this->pdo->prepare('UPDATE ipca_communication_devices SET apns_token = NULL, push_authorized = 0, updated_at_utc = ? WHERE id = ?')
                ->execute(array(CommunicationSupport::nowUtc(), $deviceId));
        }
        CommunicationSupport::log('communication.push.failed', array(
            'message_id' => $messageId,
            'device_id' => $deviceId,
            'http_status' => $result->httpStatus,
            'reason' => $result->reason,
        ));
    }

    private function markFailed(int $attemptId, string $reason): void
    {
        $this->pdo->prepare('UPDATE ipca_communication_push_attempts SET failed_at_utc = ?, provider_response = ? WHERE id = ? AND accepted_at_utc IS NULL')
            ->execute(array(CommunicationSupport::nowUtc(), substr($reason, 0, 255), $attemptId));
    }

    private function hasApnsEnvironmentColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $stmt = $this->pdo->query("SELECT apns_environment FROM ipca_communication_devices LIMIT 0");
            $has = $stmt !== false;
        } catch (Throwable) {
            $has = false;
        }
        return $has;
    }
}
