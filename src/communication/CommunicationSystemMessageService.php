<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationAuthService.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/MessageService.php';
require_once __DIR__ . '/CommunicationPushService.php';

final class CommunicationSystemMessageService
{
    public function __construct(
        private PDO $pdo,
        private CommunicationConfigService $config,
        private CommunicationAuthService $auth,
        private ConversationService $conversations,
        private MessageService $messages,
        private ?CommunicationPushService $push = null
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,string> $recipientUserUuids empty = all eligible members
     * @return array<string,mixed>
     */
    public function publishFromSession(
        array $session,
        string $actorKey,
        string $body,
        array $recipientUserUuids = array(),
        bool $requiresAcknowledgement = false,
        bool $replyAllowed = false,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $sourceEventId = null
    ): array {
        $this->config->requireSystemMessages();
        if (!$this->auth->userIsStaff($session['user'])) {
            throw new CommunicationException('forbidden', 'Only IPCA staff can send official messages.', 403);
        }
        $userIds = $this->resolveRecipientIds($recipientUserUuids);
        return $this->publish(
            $actorKey,
            $body,
            $userIds,
            $requiresAcknowledgement,
            $replyAllowed,
            $sourceType ?: 'manual',
            $sourceId ?: 'staff:' . (int)$session['user']['id'],
            $sourceEventId ?: CommunicationSupport::uuid()
        );
    }

    /**
     * Event→message. Duplicate (source_type, source_id, source_event_id) returns the original.
     *
     * @param array<int,int> $recipientUserIds empty = all eligible users (broadcast)
     * @return array<string,mixed>
     */
    public function publish(
        string $actorKey,
        string $body,
        array $recipientUserIds,
        bool $requiresAcknowledgement = false,
        bool $replyAllowed = false,
        string $sourceType = 'event',
        string $sourceId = '',
        string $sourceEventId = ''
    ): array {
        $this->config->requireSystemMessages();
        $body = trim($body);
        if ($body === '') {
            throw new CommunicationException('validation_error', 'Message cannot be empty.', 400);
        }
        if (mb_strlen($body) > CommunicationSupport::MAX_BODY_CHARS) {
            throw new CommunicationException('validation_error', 'Message is too long.', 400);
        }
        $actor = $this->actorByKey($actorKey);
        $sourceType = trim($sourceType) !== '' ? trim($sourceType) : 'event';
        $sourceId = trim($sourceId) !== '' ? trim($sourceId) : 'none';
        $sourceEventId = trim($sourceEventId) !== '' ? trim($sourceEventId) : CommunicationSupport::uuid();

        $existing = $this->findByEvent($sourceType, $sourceId, $sourceEventId);
        if ($existing !== null) {
            return $this->messages->publicMessage($existing);
        }

        $broadcast = $recipientUserIds === array();
        $userIds = $broadcast ? $this->conversations->eligibleUserIds() : array_values(array_unique(array_map('intval', $recipientUserIds)));
        $userIds = array_values(array_filter($userIds, static fn(int $id): bool => $id > 0));
        if ($userIds === array()) {
            throw new CommunicationException('validation_error', 'No recipients for that message.', 400);
        }

        $conversation = $broadcast
            ? $this->conversations->ensureSystemConversation($actor, null)
            : $this->conversations->ensureSystemConversation($actor, $userIds[0]);
        if (!$broadcast) {
            foreach ($userIds as $userId) {
                $this->conversations->addActiveMember((int)$conversation['id'], $userId);
            }
        } else {
            foreach ($userIds as $userId) {
                $this->conversations->addActiveMember((int)$conversation['id'], $userId);
            }
        }

        $now = CommunicationSupport::nowUtc();
        $messageUuid = CommunicationSupport::uuid();
        $clientId = CommunicationSupport::uuid();

        $this->pdo->beginTransaction();
        try {
            $dup = $this->findByEvent($sourceType, $sourceId, $sourceEventId);
            if ($dup !== null) {
                $this->pdo->rollBack();
                return $this->messages->publicMessage($dup);
            }

            $lock = $this->pdo->prepare('SELECT id, last_message_seq FROM ipca_communication_conversations WHERE id = ?');
            $lock->execute(array((int)$conversation['id']));
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new CommunicationException('not_found', 'Conversation not found.', 404);
            }
            $seq = (int)$locked['last_message_seq'] + 1;
            $updated = $this->pdo->prepare("
                UPDATE ipca_communication_conversations
                SET last_message_seq = ?, last_message_at_utc = ?, updated_at_utc = ?
                WHERE id = ? AND last_message_seq = ?
            ");
            $updated->execute(array($seq, $now, $now, (int)$conversation['id'], (int)$locked['last_message_seq']));
            if ($updated->rowCount() !== 1) {
                throw new CommunicationException('conflict', 'Please try again.', 409);
            }

            $this->pdo->prepare("
                INSERT INTO ipca_communication_messages
                  (message_uuid, conversation_id, organization_id, seq, client_id, sender_system_actor_id,
                   sender_type, body, requires_acknowledgement, reply_allowed, source_type, source_id, source_event_id, created_at_utc)
                VALUES (?, ?, 1, ?, ?, ?, 'system', ?, ?, ?, ?, ?, ?, ?)
            ")->execute(array(
                $messageUuid,
                (int)$conversation['id'],
                $seq,
                $clientId,
                (int)$actor['id'],
                $body,
                $requiresAcknowledgement ? 1 : 0,
                $replyAllowed ? 1 : 0,
                $sourceType,
                $sourceId,
                $sourceEventId,
                $now,
            ));
            $messageId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("
                INSERT INTO ipca_communication_change_log
                  (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
                VALUES (1, ?, 'message', ?, ?)
            ")->execute(array((int)$conversation['id'], $messageUuid, $now));

            $this->pdo->commit();
        } catch (CommunicationException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $dup = $this->findByEvent($sourceType, $sourceId, $sourceEventId);
            if ($dup !== null) {
                return $this->messages->publicMessage($dup);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $dup = $this->findByEvent($sourceType, $sourceId, $sourceEventId);
            if ($dup !== null) {
                return $this->messages->publicMessage($dup);
            }
            throw $e;
        }

        CommunicationSupport::log('communication.system_message.created', array(
            'message_uuid' => $messageUuid,
            'actor_key' => $actorKey,
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
            'seq' => $seq,
            'requires_acknowledgement' => $requiresAcknowledgement ? 1 : 0,
            'source_type' => $sourceType,
            'source_event_id' => $sourceEventId,
        ));

        if ($this->push !== null) {
            try {
                $this->push->notifyNewMessage($messageId, 0);
            } catch (Throwable $e) {
                CommunicationSupport::log('communication.push.error', array(
                    'message_uuid' => $messageUuid,
                    'error' => $e->getMessage(),
                ));
            }
        }

        $row = $this->findByUuid($messageUuid);
        return $this->messages->publicMessage(is_array($row) ? $row : array(
            'id' => $messageId,
            'message_uuid' => $messageUuid,
            'conversation_id' => (int)$conversation['id'],
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
            'seq' => $seq,
            'client_id' => $clientId,
            'sender_system_actor_id' => (int)$actor['id'],
            'sender_type' => 'system',
            'body' => $body,
            'requires_acknowledgement' => $requiresAcknowledgement ? 1 : 0,
            'reply_allowed' => $replyAllowed ? 1 : 0,
            'created_at_utc' => $now,
        ));
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function acknowledge(array $session, string $messageUuid, string $acknowledgementUuid): array
    {
        $this->config->requireSystemMessages();
        $messageUuid = CommunicationSupport::requireUuid($messageUuid, 'message_uuid');
        $acknowledgementUuid = CommunicationSupport::requireUuid($acknowledgementUuid, 'acknowledgement_uuid');
        $stmt = $this->pdo->prepare("
            SELECT m.*, c.conversation_uuid
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            WHERE m.message_uuid = ?
            LIMIT 1
        ");
        $stmt->execute(array($messageUuid));
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($message)) {
            throw new CommunicationException('not_found', 'Message not found.', 404);
        }
        if ((int)($message['requires_acknowledgement'] ?? 0) !== 1) {
            throw new CommunicationException('validation_error', 'That message does not require acknowledgement.', 400);
        }
        $this->conversations->requireMembership($session, (string)$message['conversation_uuid']);

        $userId = (int)$session['user']['id'];
        $existing = $this->pdo->prepare('SELECT * FROM ipca_communication_acknowledgements WHERE message_id = ? AND user_id = ? LIMIT 1');
        $existing->execute(array((int)$message['id'], $userId));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $this->publicAck($row, (string)$message['conversation_uuid'], $messageUuid, true);
        }

        $now = CommunicationSupport::nowUtc();
        try {
            $this->pdo->prepare("
                INSERT INTO ipca_communication_acknowledgements
                  (acknowledgement_uuid, message_id, user_id, device_id, acknowledged_at_utc)
                VALUES (?, ?, ?, ?, ?)
            ")->execute(array(
                $acknowledgementUuid,
                (int)$message['id'],
                $userId,
                (int)$session['device']['id'],
                $now,
            ));
        } catch (Throwable) {
            $existing->execute(array((int)$message['id'], $userId));
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $this->publicAck($row, (string)$message['conversation_uuid'], $messageUuid, true);
            }
            throw new CommunicationException('conflict', 'Could not record acknowledgement.', 409);
        }

        $this->pdo->prepare("
            INSERT INTO ipca_communication_change_log
              (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
            VALUES (1, ?, 'acknowledgement', ?, ?)
        ")->execute(array((int)$message['conversation_id'], $messageUuid, $now));

        $existing->execute(array((int)$message['id'], $userId));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        return $this->publicAck(is_array($row) ? $row : array(
            'acknowledgement_uuid' => $acknowledgementUuid,
            'user_id' => $userId,
            'acknowledged_at_utc' => $now,
        ), (string)$message['conversation_uuid'], $messageUuid, false);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<int,array<string,mixed>>
     */
    public function needsAttention(array $session): array
    {
        $this->config->requireMessaging();
        $userId = (int)$session['user']['id'];
        if (!$this->config->enabled('system_messages_enabled')) {
            return array();
        }
        $stmt = $this->pdo->prepare("
            SELECT m.message_uuid, m.body, m.created_at_utc, m.seq,
                   c.conversation_uuid, c.title, sa.display_name AS actor_name, sa.actor_key
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            INNER JOIN ipca_communication_conversation_members mem
              ON mem.conversation_id = m.conversation_id AND mem.user_id = ? AND mem.left_at_utc IS NULL
            LEFT JOIN ipca_communication_system_actors sa ON sa.id = m.sender_system_actor_id
            LEFT JOIN ipca_communication_acknowledgements a
              ON a.message_id = m.id AND a.user_id = ?
            WHERE m.requires_acknowledgement = 1
              AND m.sender_type = 'system'
              AND a.id IS NULL
            ORDER BY m.created_at_utc DESC, m.id DESC
            LIMIT 100
        ");
        $stmt->execute(array($userId, $userId));
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = array(
                'kind' => 'acknowledgement',
                'message_uuid' => (string)$row['message_uuid'],
                'conversation_uuid' => (string)$row['conversation_uuid'],
                'title' => (string)($row['actor_name'] ?: $row['title'] ?: 'IPCA'),
                'body' => (string)$row['body'],
                'created_at_utc' => $row['created_at_utc'],
                'source' => 'communication',
            );
        }
        return $out;
    }

    public function needsAttentionCount(int $userId): int
    {
        if (!$this->config->enabled('system_messages_enabled')) {
            return 0;
        }
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversation_members mem
              ON mem.conversation_id = m.conversation_id AND mem.user_id = ? AND mem.left_at_utc IS NULL
            LEFT JOIN ipca_communication_acknowledgements a
              ON a.message_id = m.id AND a.user_id = ?
            WHERE m.requires_acknowledgement = 1
              AND m.sender_type = 'system'
              AND a.id IS NULL
        ");
        $stmt->execute(array($userId, $userId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $session
     * @return array<int,array<string,mixed>>
     */
    public function evidence(array $session, int $limit = 50): array
    {
        $this->config->requireSystemMessages();
        if (!$this->auth->userIsStaff($session['user'])) {
            throw new CommunicationException('forbidden', 'Only IPCA staff can view acknowledgement evidence.', 403);
        }
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->query("
            SELECT m.message_uuid, m.body, m.created_at_utc, m.requires_acknowledgement,
                   c.conversation_uuid, c.title, sa.actor_key, sa.display_name,
                   (SELECT COUNT(*) FROM ipca_communication_conversation_members mem
                     WHERE mem.conversation_id = m.conversation_id AND mem.left_at_utc IS NULL) AS member_count,
                   (SELECT COUNT(*) FROM ipca_communication_acknowledgements a WHERE a.message_id = m.id) AS acknowledged_count
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            LEFT JOIN ipca_communication_system_actors sa ON sa.id = m.sender_system_actor_id
            WHERE m.sender_type = 'system'
            ORDER BY m.id DESC
            LIMIT " . $limit . "
        ");
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = array(
                'message_uuid' => (string)$row['message_uuid'],
                'conversation_uuid' => (string)$row['conversation_uuid'],
                'actor_key' => (string)($row['actor_key'] ?? ''),
                'actor_name' => (string)($row['display_name'] ?? $row['title'] ?? 'IPCA'),
                'body' => (string)$row['body'],
                'created_at_utc' => $row['created_at_utc'],
                'requires_acknowledgement' => (int)$row['requires_acknowledgement'] === 1,
                'member_count' => (int)$row['member_count'],
                'acknowledged_count' => (int)$row['acknowledged_count'],
            );
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function acksForMessageIds(array $messageIds): array
    {
        if ($messageIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT a.acknowledgement_uuid, a.acknowledged_at_utc, a.message_id, a.user_id,
                   m.message_uuid, c.conversation_uuid, u.uuid AS user_uuid
            FROM ipca_communication_acknowledgements a
            INNER JOIN ipca_communication_messages m ON m.id = a.message_id
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.message_id IN (" . $placeholders . ")
        ");
        $stmt->execute(array_values($messageIds));
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = array(
                'acknowledgement_uuid' => (string)$row['acknowledgement_uuid'],
                'message_uuid' => (string)$row['message_uuid'],
                'conversation_uuid' => (string)$row['conversation_uuid'],
                'user_uuid' => (string)$row['user_uuid'],
                'acknowledged_at_utc' => $row['acknowledged_at_utc'],
            );
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function actorByKey(string $actorKey): array
    {
        $actorKey = strtolower(trim($actorKey));
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_communication_system_actors WHERE actor_key = ? AND is_active = 1 LIMIT 1');
        $stmt->execute(array($actorKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new CommunicationException('not_found', 'Unknown official sender.', 404);
        }
        return $row;
    }

    /**
     * @param array<int,string> $uuids
     * @return array<int,int>
     */
    private function resolveRecipientIds(array $uuids): array
    {
        $ids = array();
        foreach ($uuids as $uuid) {
            $uuid = CommunicationSupport::requireUuid((string)$uuid, 'recipient_user_uuid');
            $user = $this->auth->userByUuid($uuid);
            if ($user === null || !CommunicationSupport::userIsEligible($user)) {
                throw new CommunicationException('not_found', 'A recipient is not available.', 404);
            }
            $ids[] = (int)$user['id'];
        }
        return $ids;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByEvent(string $sourceType, string $sourceId, string $sourceEventId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, c.conversation_uuid
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            WHERE m.source_type = ? AND m.source_id = ? AND m.source_event_id = ?
            LIMIT 1
        ");
        $stmt->execute(array($sourceType, $sourceId, $sourceEventId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByUuid(string $messageUuid): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, c.conversation_uuid
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            WHERE m.message_uuid = ?
            LIMIT 1
        ");
        $stmt->execute(array($messageUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicAck(array $row, string $conversationUuid, string $messageUuid, bool $already): array
    {
        return array(
            'acknowledgement_uuid' => (string)$row['acknowledgement_uuid'],
            'message_uuid' => $messageUuid,
            'conversation_uuid' => $conversationUuid,
            'acknowledged_at_utc' => $row['acknowledged_at_utc'],
            'already_acknowledged' => $already,
        );
    }
}
