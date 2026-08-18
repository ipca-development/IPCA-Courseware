<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/MessageService.php';
require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationPushService.php';
require_once __DIR__ . '/CommunicationSystemMessageService.php';

final class CommunicationSyncService
{
    public function __construct(
        private PDO $pdo,
        private ConversationService $conversations,
        private MessageService $messages,
        private CommunicationConfigService $config,
        private ?CommunicationPushService $push = null,
        private ?CommunicationSystemMessageService $systemMessages = null
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function pull(array $session, int $cursor): array
    {
        $this->config->requireMessaging();
        $cursor = max(0, $cursor);
        $userId = (int)$session['user']['id'];
        $limit = CommunicationSupport::SYNC_PAGE_SIZE;

        $stmt = $this->pdo->prepare("
            SELECT cl.id, cl.conversation_id, cl.change_type, cl.entity_uuid
            FROM ipca_communication_change_log cl
            INNER JOIN ipca_communication_conversation_members mem
              ON mem.conversation_id = cl.conversation_id
             AND mem.user_id = ?
            WHERE cl.id > ?
              AND (
                    mem.left_at_utc IS NULL
                    OR (cl.change_type = 'membership' AND cl.created_at_utc >= mem.left_at_utc)
                  )
            ORDER BY cl.id ASC
            LIMIT " . $limit . "
        ");
        $stmt->execute(array($userId, $cursor));
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conversationIds = array();
        $messageUuids = array();
        $receiptConversationIds = array();
        $ackMessageIds = array();
        $maxId = $cursor;
        foreach ($changes as $change) {
            $maxId = max($maxId, (int)$change['id']);
            $conversationIds[(int)$change['conversation_id']] = true;
            if ((string)$change['change_type'] === 'message' || (string)$change['change_type'] === 'reaction') {
                $messageUuids[] = (string)$change['entity_uuid'];
            }
            if ((string)$change['change_type'] === 'receipt' || (string)$change['change_type'] === 'delivery') {
                $receiptConversationIds[(int)$change['conversation_id']] = true;
            }
            if ((string)$change['change_type'] === 'acknowledgement') {
                $ackMessageIds[] = (string)$change['entity_uuid'];
            }
        }

        $conversations = array();
        foreach (array_keys($conversationIds) as $conversationId) {
            $conversations[] = $this->conversations->conversationPayload((int)$conversationId, $userId);
        }

        $messages = array();
        $messageRows = array();
        if ($messageUuids !== array()) {
            $placeholders = implode(',', array_fill(0, count($messageUuids), '?'));
            $msgStmt = $this->pdo->prepare("
                SELECT m.*, c.conversation_uuid
                FROM ipca_communication_messages m
                INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
                WHERE m.message_uuid IN (" . $placeholders . ")
                ORDER BY m.conversation_id ASC, m.seq ASC
            ");
            $msgStmt->execute($messageUuids);
            $messageRows = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($messageRows as $row) {
                $messages[] = $this->messages->publicMessage($row, $userId);
            }
            $this->messages->recordDeviceSyncs($session, $messageRows);
        }

        $reads = array();
        foreach (array_keys($receiptConversationIds) as $conversationId) {
            $memberStmt = $this->pdo->prepare("
                SELECT m.user_id, m.last_read_seq, m.last_read_at_utc,
                       m.last_delivered_seq, m.last_delivered_at_utc,
                       c.conversation_uuid, u.uuid AS user_uuid
                FROM ipca_communication_conversation_members m
                INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
                INNER JOIN users u ON u.id = m.user_id
                WHERE m.conversation_id = ? AND m.left_at_utc IS NULL
            ");
            $memberStmt->execute(array((int)$conversationId));
            foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $reads[] = array(
                    'conversation_uuid' => (string)$row['conversation_uuid'],
                    'user_uuid' => (string)$row['user_uuid'],
                    'last_read_seq' => (int)$row['last_read_seq'],
                    'last_read_at_utc' => $row['last_read_at_utc'],
                    'last_delivered_seq' => (int)($row['last_delivered_seq'] ?? 0),
                    'last_delivered_at_utc' => $row['last_delivered_at_utc'] ?? null,
                );
            }
        }

        $acks = array();
        $ackLookupIds = array();
        if ($this->systemMessages !== null) {
            foreach ($messageRows as $row) {
                if ((int)($row['requires_acknowledgement'] ?? 0) === 1) {
                    $ackLookupIds[] = (int)$row['id'];
                }
            }
            if ($ackMessageIds !== array()) {
                $placeholders = implode(',', array_fill(0, count($ackMessageIds), '?'));
                $ackStmt = $this->pdo->prepare("
                    SELECT id FROM ipca_communication_messages WHERE message_uuid IN (" . $placeholders . ")
                ");
                $ackStmt->execute(array_values(array_unique($ackMessageIds)));
                foreach ($ackStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $ackLookupIds[] = (int)$row['id'];
                }
            }
            $acks = $this->systemMessages->acksForMessageIds(array_values(array_unique($ackLookupIds)));
        }

        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare("
            UPDATE ipca_communication_devices
            SET last_sync_at_utc = ?, last_sync_cursor = ?, updated_at_utc = ?
            WHERE id = ?
        ")->execute(array($now, $maxId, $now, (int)$session['device']['id']));

        return array(
            'ok' => true,
            'cursor' => $maxId,
            'has_more' => count($changes) === $limit,
            'conversations' => $conversations,
            'messages' => $messages,
            'reads' => $reads,
            'acks' => $acks,
        );
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function bootstrap(array $session): array
    {
        $capabilities = $this->config->capabilities();
        $userId = (int)$session['user']['id'];
        $unread = 0;
        $needsAction = 0;
        if ($capabilities['messaging_enabled']) {
            $unread = $this->conversations->unreadTotal($userId);
            if ($this->systemMessages !== null) {
                $needsAction = $this->systemMessages->needsAttentionCount($userId);
            }
        }

        $appVersion = (string)($session['device']['app_version'] ?? '1.0.0');
        $updateRequired = version_compare($appVersion, (string)$capabilities['min_app_version'], '<');

        return array(
            'ok' => true,
            'protocol_version' => $capabilities['protocol_version'],
            'min_app_version' => $capabilities['min_app_version'],
            'min_ios_version' => $capabilities['min_ios_version'],
            'update_required' => $updateRequired,
            'user' => CommunicationSupport::publicUser($session['user']),
            'device' => array(
                'device_uuid' => (string)$session['device']['device_uuid'],
                'platform' => (string)$session['device']['platform'],
                'model' => (string)($session['device']['model'] ?? ''),
                'app_version' => (string)($session['device']['app_version'] ?? ''),
            ),
            'capabilities' => $capabilities,
            'unread_count' => $unread,
            'needs_action_count' => $needsAction,
            'notifications' => array(
                'push_configured' => $this->push !== null && $this->push->isConfigured(),
                'push_authorized' => $this->devicePushAuthorized((int)$session['device']['id']),
            ),
        );
    }

    private function devicePushAuthorized(int $deviceId): ?bool
    {
        $stmt = $this->pdo->prepare('SELECT push_authorized FROM ipca_communication_devices WHERE id = ? LIMIT 1');
        $stmt->execute(array($deviceId));
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        return (int)$value === 1;
    }
}
