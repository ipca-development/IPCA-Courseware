<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/CommunicationPushService.php';
require_once __DIR__ . '/CommunicationAttachmentService.php';

final class MessageService
{
    public const REACTION_EMOJIS = array('👍', '❤️', '😂', '😮', '😢', '🙏');

    /** @var array<int,string> */
    private array $userUuidCache = array();
    /** @var array<int,string> */
    private array $userNameCache = array();
    /** @var array<int,string> */
    private array $actorNameCache = array();

    public function __construct(
        private PDO $pdo,
        private ConversationService $conversations,
        private ?CommunicationPushService $push = null,
        private ?CommunicationAttachmentService $attachments = null
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,string> $attachmentUuids
     * @return array<string,mixed>
     */
    public function send(
        array $session,
        string $conversationUuid,
        string $clientId,
        string $body,
        array $attachmentUuids = array(),
        int $attempt = 0,
        ?string $replyToMessageUuid = null
    ): array {
        $clientId = CommunicationSupport::requireUuid($clientId, 'client_id');
        $body = trim($body);
        $attachmentUuids = array_values(array_filter(array_map('strval', $attachmentUuids)));
        if ($body === '' && $attachmentUuids === array()) {
            throw new CommunicationException('validation_error', 'Message cannot be empty.', 400);
        }
        if (mb_strlen($body) > CommunicationSupport::MAX_BODY_CHARS) {
            throw new CommunicationException('validation_error', 'Message is too long.', 400);
        }

        $existing = $this->findByClientId((int)$session['user']['id'], $clientId);
        if ($existing !== null) {
            CommunicationSupport::log('communication.message.idempotent_hit', array(
                'message_uuid' => (string)$existing['message_uuid'],
                'client_id' => $clientId,
                'user_id' => (int)$session['user']['id'],
            ));
            return $this->publicMessage($existing);
        }

        $conversation = $this->conversations->requireMembership($session, $conversationUuid);
        $conversationType = (string)($conversation['conversation_type'] ?? '');
        if (in_array($conversationType, array('announcement', 'system'), true)) {
            throw new CommunicationException('replies_disabled', 'This conversation does not accept replies.', 403);
        }
        $readyAttachments = array();
        if ($attachmentUuids !== array()) {
            if ($this->attachments === null) {
                throw new CommunicationException('attachments_disabled', 'Attachments are currently unavailable.', 403);
            }
            $readyAttachments = $this->attachments->requireReadyForSend($session, (int)$conversation['id'], $attachmentUuids);
        }
        $now = CommunicationSupport::nowUtc();
        $messageUuid = CommunicationSupport::uuid();
        $senderUserId = (int)$session['user']['id'];
        $senderDeviceId = (int)$session['device']['id'];
        $replyToId = $this->resolveReplyToId($replyToMessageUuid, (int)$conversation['id']);

        $this->pdo->beginTransaction();
        try {
            $dup = $this->findByClientId($senderUserId, $clientId);
            if ($dup !== null) {
                $this->pdo->rollBack();
                return $this->publicMessage($dup);
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
                  (message_uuid, conversation_id, organization_id, seq, client_id, sender_user_id, sender_device_id,
                   sender_type, body, reply_to_message_id, created_at_utc)
                VALUES (?, ?, 1, ?, ?, ?, ?, 'user', ?, ?, ?)
            ")->execute(array(
                $messageUuid,
                (int)$conversation['id'],
                $seq,
                $clientId,
                $senderUserId,
                $senderDeviceId,
                $body,
                $replyToId,
                $now,
            ));
            $messageId = (int)$this->pdo->lastInsertId();

            if ($readyAttachments !== array() && $this->attachments !== null) {
                $this->attachments->attachToMessage($messageId, $readyAttachments, $now);
            }

            $this->pdo->prepare("
                INSERT INTO ipca_communication_change_log
                  (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
                VALUES (1, ?, 'message', ?, ?)
            ")->execute(array((int)$conversation['id'], $messageUuid, $now));

            $this->pdo->prepare("
                INSERT INTO ipca_communication_message_device_syncs
                  (message_id, device_id, user_id, synced_at_utc)
                VALUES (?, ?, ?, ?)
            ")->execute(array($messageId, $senderDeviceId, $senderUserId, $now));

            $this->advanceRead((int)$conversation['id'], $senderUserId, $seq, $now);

            $this->pdo->commit();
        } catch (CommunicationException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->errorCode === 'conflict' && $attempt < 4) {
                return $this->send($session, $conversationUuid, $clientId, $body, $attachmentUuids, $attempt + 1, $replyToMessageUuid);
            }
            $dup = $this->findByClientId($senderUserId, $clientId);
            if ($dup !== null) {
                return $this->publicMessage($dup);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $dup = $this->findByClientId($senderUserId, $clientId);
            if ($dup !== null) {
                return $this->publicMessage($dup);
            }
            throw $e;
        }

        CommunicationSupport::log('communication.message.created', array(
            'message_uuid' => $messageUuid,
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
            'seq' => $seq,
            'sender_user_id' => $senderUserId,
            'sender_device_id' => $senderDeviceId,
        ));

        if ($this->push !== null) {
            try {
                $this->push->notifyNewMessage($messageId, $senderDeviceId);
            } catch (Throwable $e) {
                CommunicationSupport::log('communication.push.error', array(
                    'message_uuid' => $messageUuid,
                    'error' => $e->getMessage(),
                ));
            }
        }

        $row = $this->findByUuid($messageUuid);
        return $this->publicMessage(is_array($row) ? $row : array(
            'message_uuid' => $messageUuid,
            'conversation_id' => (int)$conversation['id'],
            'seq' => $seq,
            'client_id' => $clientId,
            'sender_user_id' => $senderUserId,
            'sender_device_id' => $senderDeviceId,
            'sender_type' => 'user',
            'body' => $body,
            'reply_to_message_id' => $replyToId,
            'created_at_utc' => $now,
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
        ), $senderUserId);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<int,array<string,mixed>>
     */
    public function page(array $session, string $conversationUuid, ?int $beforeSeq, int $limit): array
    {
        $conversation = $this->conversations->requireMembership($session, $conversationUuid);
        $limit = max(1, min(100, $limit));
        $sql = "
            SELECT m.*, c.conversation_uuid
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            WHERE m.conversation_id = ?
        ";
        $params = array((int)$conversation['id']);
        if ($beforeSeq !== null && $beforeSeq > 0) {
            $sql .= ' AND m.seq < ?';
            $params[] = $beforeSeq;
        }
        $sql .= ' ORDER BY m.seq DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_reverse($rows);
        $out = array();
        foreach ($rows as $row) {
            $out[] = $this->publicMessage($row, (int)$session['user']['id']);
        }
        $this->recordDeviceSyncs($session, $rows);
        return $out;
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function markRead(array $session, string $conversationUuid, int $lastReadSeq): array
    {
        $conversation = $this->conversations->requireMembership($session, $conversationUuid);
        $lastReadSeq = max(0, $lastReadSeq);
        $maxSeq = (int)$conversation['last_message_seq'];
        if ($lastReadSeq > $maxSeq) {
            $lastReadSeq = $maxSeq;
        }
        $now = CommunicationSupport::nowUtc();
        $advanced = $this->pdo->prepare("
            UPDATE ipca_communication_conversation_members
            SET last_read_seq = ?, last_read_at_utc = ?
            WHERE conversation_id = ? AND user_id = ? AND last_read_seq < ?
        ");
        $advanced->execute(array(
            $lastReadSeq,
            $now,
            (int)$conversation['id'],
            (int)$session['user']['id'],
            $lastReadSeq,
        ));
        $this->advanceDelivered((int)$conversation['id'], (int)$session['user']['id'], $lastReadSeq, $now, (string)$conversation['conversation_uuid']);
        if ($advanced->rowCount() > 0) {
            $this->pdo->prepare("
                INSERT INTO ipca_communication_change_log
                  (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
                VALUES (1, ?, 'receipt', ?, ?)
            ")->execute(array((int)$conversation['id'], (string)$conversation['conversation_uuid'], $now));
        }

        return array(
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
            'last_read_seq' => $lastReadSeq,
            'last_read_at_utc' => $now,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function publicMessage(array $row, ?int $viewerUserId = null): array
    {
        $conversationUuid = (string)($row['conversation_uuid'] ?? '');
        if ($conversationUuid === '' && isset($row['conversation_id'])) {
            $stmt = $this->pdo->prepare('SELECT conversation_uuid FROM ipca_communication_conversations WHERE id = ?');
            $stmt->execute(array((int)$row['conversation_id']));
            $conversationUuid = (string)$stmt->fetchColumn();
        }
        $senderUuid = null;
        $senderDisplayName = '';
        if (!empty($row['sender_system_actor_id'])) {
            $senderDisplayName = $this->actorName((int)$row['sender_system_actor_id']);
        }
        if (!empty($row['sender_user_id'])) {
            $senderUuid = $this->userUuid((int)$row['sender_user_id']);
            if ($senderDisplayName === '') {
                $senderDisplayName = $this->userName((int)$row['sender_user_id']);
            }
        }
        $attachments = array();
        if ($this->attachments !== null && !empty($row['id'])) {
            $attachments = $this->attachments->forMessage((int)$row['id']);
        }
        return array(
            'message_uuid' => (string)$row['message_uuid'],
            'conversation_uuid' => $conversationUuid,
            'seq' => (int)$row['seq'],
            'client_id' => (string)$row['client_id'],
            'sender_user_uuid' => $senderUuid,
            'sender_type' => (string)($row['sender_type'] ?? 'user'),
            'sender_display_name' => $senderDisplayName,
            'body' => (string)$row['body'],
            'created_at_utc' => $row['created_at_utc'],
            'server_received' => true,
            'attachments' => $attachments,
            'requires_acknowledgement' => (int)($row['requires_acknowledgement'] ?? 0) === 1,
            'reply_allowed' => (int)($row['reply_allowed'] ?? 1) === 1,
            'reply_to' => $this->publicReplyTo($row),
            'reactions' => !empty($row['id']) ? $this->publicReactions((int)$row['id'], $viewerUserId) : array(),
        );
    }

    /**
     * One reaction per user. Sending the same emoji again clears it.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function setReaction(array $session, string $messageUuid, string $emoji, string $reactionUuid): array
    {
        $messageUuid = CommunicationSupport::requireUuid($messageUuid, 'message_uuid');
        $reactionUuid = CommunicationSupport::requireUuid($reactionUuid, 'reaction_uuid');
        $emoji = trim($emoji);
        $message = $this->findByUuid($messageUuid);
        if ($message === null) {
            throw new CommunicationException('not_found', 'Message not found.', 404);
        }
        $this->conversations->requireMembership($session, (string)$message['conversation_uuid']);
        $userId = (int)$session['user']['id'];
        $existing = $this->pdo->prepare('SELECT * FROM ipca_communication_message_reactions WHERE message_id = ? AND user_id = ? LIMIT 1');
        $existing->execute(array((int)$message['id'], $userId));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $now = CommunicationSupport::nowUtc();
        $clear = $emoji === '' || (is_array($row) && (string)$row['emoji'] === $emoji);

        if ($clear) {
            if (is_array($row)) {
                $this->pdo->prepare('DELETE FROM ipca_communication_message_reactions WHERE id = ?')->execute(array((int)$row['id']));
                $this->pdo->prepare("
                    INSERT INTO ipca_communication_change_log
                      (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
                    VALUES (1, ?, 'reaction', ?, ?)
                ")->execute(array((int)$message['conversation_id'], $messageUuid, $now));
            }
            $fresh = $this->findByUuid($messageUuid);
            return $this->publicMessage(is_array($fresh) ? $fresh : $message, $userId);
        }

        if (!in_array($emoji, self::REACTION_EMOJIS, true)) {
            throw new CommunicationException('validation_error', 'That reaction is not available.', 400);
        }

        if (is_array($row)) {
            $this->pdo->prepare('UPDATE ipca_communication_message_reactions SET emoji = ?, device_id = ?, created_at_utc = ? WHERE id = ?')
                ->execute(array($emoji, (int)$session['device']['id'], $now, (int)$row['id']));
        } else {
            try {
                $this->pdo->prepare("
                    INSERT INTO ipca_communication_message_reactions
                      (reaction_uuid, message_id, user_id, device_id, emoji, created_at_utc)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute(array(
                    $reactionUuid,
                    (int)$message['id'],
                    $userId,
                    (int)$session['device']['id'],
                    $emoji,
                    $now,
                ));
            } catch (Throwable) {
                $existing->execute(array((int)$message['id'], $userId));
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $this->pdo->prepare('UPDATE ipca_communication_message_reactions SET emoji = ?, device_id = ?, created_at_utc = ? WHERE id = ?')
                        ->execute(array($emoji, (int)$session['device']['id'], $now, (int)$row['id']));
                } else {
                    throw new CommunicationException('conflict', 'Could not save that reaction.', 409);
                }
            }
        }

        $this->pdo->prepare("
            INSERT INTO ipca_communication_change_log
              (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
            VALUES (1, ?, 'reaction', ?, ?)
        ")->execute(array((int)$message['conversation_id'], $messageUuid, $now));

        $fresh = $this->findByUuid($messageUuid);
        return $this->publicMessage(is_array($fresh) ? $fresh : $message, $userId);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,array<string,mixed>> $messages
     */
    public function recordDeviceSyncs(array $session, array $messages): void
    {
        if ($messages === array()) {
            return;
        }
        $deviceId = (int)$session['device']['id'];
        $userId = (int)$session['user']['id'];
        $now = CommunicationSupport::nowUtc();
        $insert = $this->pdo->prepare("
            INSERT INTO ipca_communication_message_device_syncs
              (message_id, device_id, user_id, synced_at_utc)
            VALUES (?, ?, ?, ?)
        ");
        $maxSeqByConversation = array();
        $uuidByConversation = array();
        foreach ($messages as $row) {
            $messageId = (int)($row['id'] ?? 0);
            if ($messageId <= 0) {
                continue;
            }
            try {
                $insert->execute(array($messageId, $deviceId, $userId, $now));
            } catch (Throwable) {
                // Unique (message_id, device_id) means already synced.
            }
            $conversationId = (int)($row['conversation_id'] ?? 0);
            $seq = (int)($row['seq'] ?? 0);
            if ($conversationId > 0 && $seq > 0) {
                $maxSeqByConversation[$conversationId] = max($maxSeqByConversation[$conversationId] ?? 0, $seq);
                if (!isset($uuidByConversation[$conversationId])) {
                    $uuidByConversation[$conversationId] = (string)($row['conversation_uuid'] ?? '');
                }
            }
        }
        foreach ($maxSeqByConversation as $conversationId => $maxSeq) {
            $this->advanceDelivered($conversationId, $userId, $maxSeq, $now, $uuidByConversation[$conversationId] ?? '');
        }
    }

    private function resolveReplyToId(?string $replyToMessageUuid, int $conversationId): ?int
    {
        if ($replyToMessageUuid === null || trim($replyToMessageUuid) === '') {
            return null;
        }
        $parent = $this->findByUuid(CommunicationSupport::requireUuid($replyToMessageUuid, 'reply_to_message_uuid'));
        if ($parent === null || (int)$parent['conversation_id'] !== $conversationId) {
            throw new CommunicationException('not_found', 'That message is not in this conversation.', 404);
        }
        return (int)$parent['id'];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function publicReplyTo(array $row): ?array
    {
        $parentId = (int)($row['reply_to_message_id'] ?? 0);
        if ($parentId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare("
            SELECT m.message_uuid, m.body, m.sender_user_id, m.sender_system_actor_id, m.sender_type
            FROM ipca_communication_messages m
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute(array($parentId));
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($parent)) {
            return null;
        }
        $name = '';
        if (!empty($parent['sender_system_actor_id'])) {
            $name = $this->actorName((int)$parent['sender_system_actor_id']);
        } elseif (!empty($parent['sender_user_id'])) {
            $name = $this->userName((int)$parent['sender_user_id']);
        }
        $preview = trim((string)$parent['body']);
        if ($preview === '') {
            $preview = 'Photo';
        }
        if (mb_strlen($preview) > 80) {
            $preview = mb_substr($preview, 0, 80);
        }
        return array(
            'message_uuid' => (string)$parent['message_uuid'],
            'sender_display_name' => $name !== '' ? $name : 'Message',
            'body_preview' => $preview,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function publicReactions(int $messageId, ?int $viewerUserId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT emoji, user_id
            FROM ipca_communication_message_reactions
            WHERE message_id = ?
            ORDER BY created_at_utc ASC, id ASC
        ");
        $stmt->execute(array($messageId));
        $counts = array();
        $mine = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $emoji = (string)$row['emoji'];
            if (!isset($counts[$emoji])) {
                $counts[$emoji] = 0;
            }
            $counts[$emoji]++;
            if ($viewerUserId !== null && (int)$row['user_id'] === $viewerUserId) {
                $mine[$emoji] = true;
            }
        }
        $out = array();
        foreach ($counts as $emoji => $count) {
            $out[] = array(
                'emoji' => $emoji,
                'count' => $count,
                'reacted_by_me' => isset($mine[$emoji]),
            );
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByClientId(int $senderUserId, string $clientId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, c.conversation_uuid
            FROM ipca_communication_messages m
            INNER JOIN ipca_communication_conversations c ON c.id = m.conversation_id
            WHERE m.sender_user_id = ? AND m.client_id = ?
            LIMIT 1
        ");
        $stmt->execute(array($senderUserId, $clientId));
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

    private function userUuid(int $userId): ?string
    {
        if (array_key_exists($userId, $this->userUuidCache)) {
            return $this->userUuidCache[$userId];
        }
        $stmt = $this->pdo->prepare('SELECT uuid FROM users WHERE id = ?');
        $stmt->execute(array($userId));
        $value = $stmt->fetchColumn();
        $uuid = $value !== false ? (string)$value : null;
        if ($uuid !== null) {
            $this->userUuidCache[$userId] = $uuid;
        }
        return $uuid;
    }

    private function userName(int $userId): string
    {
        if (array_key_exists($userId, $this->userNameCache)) {
            return $this->userNameCache[$userId];
        }
        $stmt = $this->pdo->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute(array($userId));
        $name = trim((string)$stmt->fetchColumn());
        $this->userNameCache[$userId] = $name;
        return $name;
    }

    private function advanceRead(int $conversationId, int $userId, int $seq, string $now): void
    {
        $this->pdo->prepare("
            UPDATE ipca_communication_conversation_members
            SET last_read_seq = ?, last_read_at_utc = ?
            WHERE conversation_id = ? AND user_id = ? AND last_read_seq < ?
        ")->execute(array($seq, $now, $conversationId, $userId, $seq));
    }

    private function advanceDelivered(int $conversationId, int $userId, int $seq, string $now, string $conversationUuid): void
    {
        $updated = $this->pdo->prepare("
            UPDATE ipca_communication_conversation_members
            SET last_delivered_seq = ?, last_delivered_at_utc = ?
            WHERE conversation_id = ? AND user_id = ? AND last_delivered_seq < ?
        ");
        $updated->execute(array($seq, $now, $conversationId, $userId, $seq));
        if ($updated->rowCount() < 1) {
            return;
        }
        if ($conversationUuid === '') {
            $stmt = $this->pdo->prepare('SELECT conversation_uuid FROM ipca_communication_conversations WHERE id = ?');
            $stmt->execute(array($conversationId));
            $conversationUuid = (string)$stmt->fetchColumn();
        }
        if ($conversationUuid === '') {
            return;
        }
        $this->pdo->prepare("
            INSERT INTO ipca_communication_change_log
              (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
            VALUES (1, ?, 'delivery', ?, ?)
        ")->execute(array($conversationId, $conversationUuid, $now));
    }

    private function actorName(int $actorId): string
    {
        if (array_key_exists($actorId, $this->actorNameCache)) {
            return $this->actorNameCache[$actorId];
        }
        $stmt = $this->pdo->prepare('SELECT display_name FROM ipca_communication_system_actors WHERE id = ?');
        $stmt->execute(array($actorId));
        $name = (string)$stmt->fetchColumn();
        $this->actorNameCache[$actorId] = $name;
        return $name;
    }
}
