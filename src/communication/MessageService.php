<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/ConversationService.php';

final class MessageService
{
    /** @var array<int,string> */
    private array $userUuidCache = array();

    public function __construct(
        private PDO $pdo,
        private ConversationService $conversations
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function send(array $session, string $conversationUuid, string $clientId, string $body, int $attempt = 0): array
    {
        $clientId = CommunicationSupport::requireUuid($clientId, 'client_id');
        $body = trim($body);
        if ($body === '') {
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
        $now = CommunicationSupport::nowUtc();
        $messageUuid = CommunicationSupport::uuid();
        $senderUserId = (int)$session['user']['id'];
        $senderDeviceId = (int)$session['device']['id'];

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
                   sender_type, body, created_at_utc)
                VALUES (?, ?, 1, ?, ?, ?, ?, 'user', ?, ?)
            ")->execute(array(
                $messageUuid,
                (int)$conversation['id'],
                $seq,
                $clientId,
                $senderUserId,
                $senderDeviceId,
                $body,
                $now,
            ));
            $messageId = (int)$this->pdo->lastInsertId();

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
                return $this->send($session, $conversationUuid, $clientId, $body, $attempt + 1);
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
            'created_at_utc' => $now,
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
        ));
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
            $out[] = $this->publicMessage($row);
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
        $this->pdo->prepare("
            UPDATE ipca_communication_conversation_members
            SET last_read_seq = ?, last_read_at_utc = ?
            WHERE conversation_id = ? AND user_id = ? AND last_read_seq < ?
        ")->execute(array(
            $lastReadSeq,
            $now,
            (int)$conversation['id'],
            (int)$session['user']['id'],
            $lastReadSeq,
        ));
        $this->pdo->prepare("
            INSERT INTO ipca_communication_change_log
              (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
            VALUES (1, ?, 'receipt', ?, ?)
        ")->execute(array((int)$conversation['id'], (string)$conversation['conversation_uuid'], $now));

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
    public function publicMessage(array $row): array
    {
        $conversationUuid = (string)($row['conversation_uuid'] ?? '');
        if ($conversationUuid === '' && isset($row['conversation_id'])) {
            $stmt = $this->pdo->prepare('SELECT conversation_uuid FROM ipca_communication_conversations WHERE id = ?');
            $stmt->execute(array((int)$row['conversation_id']));
            $conversationUuid = (string)$stmt->fetchColumn();
        }
        $senderUuid = null;
        if (!empty($row['sender_user_id'])) {
            $senderUuid = $this->userUuid((int)$row['sender_user_id']);
        }
        return array(
            'message_uuid' => (string)$row['message_uuid'],
            'conversation_uuid' => $conversationUuid,
            'seq' => (int)$row['seq'],
            'client_id' => (string)$row['client_id'],
            'sender_user_uuid' => $senderUuid,
            'sender_type' => (string)($row['sender_type'] ?? 'user'),
            'body' => (string)$row['body'],
            'created_at_utc' => $row['created_at_utc'],
            'server_received' => true,
        );
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
        }
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

    private function advanceRead(int $conversationId, int $userId, int $seq, string $now): void
    {
        $this->pdo->prepare("
            UPDATE ipca_communication_conversation_members
            SET last_read_seq = ?, last_read_at_utc = ?
            WHERE conversation_id = ? AND user_id = ? AND last_read_seq < ?
        ")->execute(array($seq, $now, $conversationId, $userId, $seq));
    }
}
