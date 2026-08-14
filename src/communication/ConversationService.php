<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationAuthService.php';
require_once __DIR__ . '/CommunicationConfigService.php';

final class ConversationService
{
    public function __construct(
        private PDO $pdo,
        private CommunicationAuthService $auth,
        private CommunicationConfigService $config
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function createDirect(array $session, string $peerUserUuid): array
    {
        $this->config->requireMessaging();
        $peerUserUuid = CommunicationSupport::requireUuid($peerUserUuid, 'peer_user_uuid');
        $self = $session['user'];
        $peer = $this->auth->userByUuid($peerUserUuid);
        if ($peer === null || !CommunicationSupport::userIsEligible($peer)) {
            throw new CommunicationException('not_found', 'That person is not available.', 404);
        }
        if ((int)$peer['id'] === (int)$self['id']) {
            throw new CommunicationException('validation_error', 'You cannot message yourself.', 400);
        }

        $pairKey = CommunicationSupport::directPairKey((int)$self['id'], (int)$peer['id']);
        $existing = $this->pdo->prepare('SELECT * FROM ipca_communication_conversations WHERE organization_id = 1 AND direct_pair_key = ? LIMIT 1');
        $existing->execute(array($pairKey));
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $this->ensureMember((int)$row['id'], (int)$self['id']);
            $this->ensureMember((int)$row['id'], (int)$peer['id']);
            return $this->conversationPayload((int)$row['id'], (int)$self['id']);
        }

        $now = CommunicationSupport::nowUtc();
        $uuid = CommunicationSupport::uuid();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                INSERT INTO ipca_communication_conversations
                  (conversation_uuid, organization_id, conversation_type, title, direct_pair_key, created_by_user_id, created_at_utc, updated_at_utc)
                VALUES (?, 1, 'direct', '', ?, ?, ?, ?)
            ")->execute(array($uuid, $pairKey, (int)$self['id'], $now, $now));
            $conversationId = (int)$this->pdo->lastInsertId();
            $this->insertMember($conversationId, (int)$self['id'], $now);
            $this->insertMember($conversationId, (int)$peer['id'], $now);
            $this->appendChange($conversationId, 'conversation', $uuid, $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $existing->execute(array($pairKey));
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $this->conversationPayload((int)$row['id'], (int)$self['id']);
            }
            throw $e;
        }

        CommunicationSupport::log('communication.conversation.direct_created', array(
            'conversation_uuid' => $uuid,
            'user_id' => (int)$self['id'],
            'peer_user_id' => (int)$peer['id'],
        ));
        return $this->conversationPayload($conversationId, (int)$self['id']);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,string> $memberUuids
     * @return array<string,mixed>
     */
    public function createGroup(array $session, string $title, array $memberUuids): array
    {
        $this->config->requireGroups();
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new CommunicationException('validation_error', 'Group name is required.', 400);
        }

        $selfId = (int)$session['user']['id'];
        $userIds = array($selfId);
        foreach ($memberUuids as $uuid) {
            $uuid = CommunicationSupport::requireUuid((string)$uuid, 'member_user_uuid');
            $user = $this->auth->userByUuid($uuid);
            if ($user === null || !CommunicationSupport::userIsEligible($user)) {
                throw new CommunicationException('not_found', 'A selected person is not available.', 404);
            }
            $userIds[] = (int)$user['id'];
        }
        $userIds = array_values(array_unique($userIds));
        if (count($userIds) < 2) {
            throw new CommunicationException('validation_error', 'A group needs at least one other person.', 400);
        }
        if (count($userIds) > 40) {
            throw new CommunicationException('validation_error', 'This group is too large.', 400);
        }

        $now = CommunicationSupport::nowUtc();
        $uuid = CommunicationSupport::uuid();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                INSERT INTO ipca_communication_conversations
                  (conversation_uuid, organization_id, conversation_type, title, created_by_user_id, created_at_utc, updated_at_utc)
                VALUES (?, 1, 'group', ?, ?, ?, ?)
            ")->execute(array($uuid, $title, $selfId, $now, $now));
            $conversationId = (int)$this->pdo->lastInsertId();
            foreach ($userIds as $userId) {
                $this->insertMember($conversationId, $userId, $now, $userId === $selfId ? 'poster' : 'member');
            }
            $this->appendChange($conversationId, 'conversation', $uuid, $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        CommunicationSupport::log('communication.conversation.group_created', array(
            'conversation_uuid' => $uuid,
            'user_id' => $selfId,
            'member_count' => count($userIds),
        ));
        return $this->conversationPayload($conversationId, $selfId);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<int,array<string,mixed>>
     */
    public function listForUser(array $session): array
    {
        $this->config->requireMessaging();
        $userId = (int)$session['user']['id'];
        $stmt = $this->pdo->prepare("
            SELECT c.id
            FROM ipca_communication_conversations c
            INNER JOIN ipca_communication_conversation_members m
              ON m.conversation_id = c.id AND m.user_id = ? AND m.left_at_utc IS NULL
            ORDER BY CASE WHEN c.last_message_at_utc IS NULL THEN 1 ELSE 0 END,
                     c.last_message_at_utc DESC,
                     c.id DESC
        ");
        $stmt->execute(array($userId));
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->conversationPayload((int)$row['id'], $userId);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function getForUser(array $session, string $conversationUuid): array
    {
        $this->config->requireMessaging();
        $conversation = $this->requireMembership($session, $conversationUuid);
        return $this->conversationPayload((int)$conversation['id'], (int)$session['user']['id']);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function requireMembership(array $session, string $conversationUuid): array
    {
        $this->config->requireMessaging();
        $conversationUuid = CommunicationSupport::requireUuid($conversationUuid, 'conversation_uuid');
        $stmt = $this->pdo->prepare("
            SELECT c.*, m.last_read_seq AS viewer_last_read_seq, m.member_role AS viewer_member_role
            FROM ipca_communication_conversations c
            INNER JOIN ipca_communication_conversation_members m
              ON m.conversation_id = c.id AND m.user_id = ? AND m.left_at_utc IS NULL
            WHERE c.conversation_uuid = ?
            LIMIT 1
        ");
        $stmt->execute(array((int)$session['user']['id'], $conversationUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new CommunicationException('not_a_member', 'You do not have access to this conversation.', 403);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $session
     * @return array<int,array<string,mixed>>
     */
    public function directory(array $session, string $query): array
    {
        $this->config->requireMessaging();
        $query = trim($query);
        $userId = (int)$session['user']['id'];
        $sql = $this->authUserSelect() . "
            WHERE id != ?
              AND status = 'active'
              AND role IN ('student','instructor','supervisor','chief_instructor','admin')
        ";
        $params = array($userId);
        if ($query !== '') {
            $sql .= ' AND (name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY name ASC LIMIT ' . CommunicationSupport::DIRECTORY_LIMIT;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!CommunicationSupport::isUuid((string)($row['uuid'] ?? ''))) {
                continue;
            }
            $out[] = CommunicationSupport::publicUser($row);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function conversationPayload(int $conversationId, int $viewerUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_communication_conversations WHERE id = ? LIMIT 1');
        $stmt->execute(array($conversationId));
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($conversation)) {
            throw new CommunicationException('not_found', 'Conversation not found.', 404);
        }
        $membersStmt = $this->pdo->prepare("
            SELECT * FROM ipca_communication_conversation_members
            WHERE conversation_id = ? AND left_at_utc IS NULL
            ORDER BY id ASC
        ");
        $membersStmt->execute(array($conversationId));
        $memberRows = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
        $users = $this->auth->usersByIds(array_map(static fn(array $row): int => (int)$row['user_id'], $memberRows));
        $members = array();
        $viewerRead = 0;
        foreach ($memberRows as $row) {
            $user = $users[(int)$row['user_id']] ?? null;
            if ($user === null) {
                continue;
            }
            if ((int)$row['user_id'] === $viewerUserId) {
                $viewerRead = (int)$row['last_read_seq'];
            }
            $members[] = array(
                'user' => CommunicationSupport::publicUser($user),
                'member_role' => (string)$row['member_role'],
                'last_read_seq' => (int)$row['last_read_seq'],
                'last_read_at_utc' => $row['last_read_at_utc'],
            );
        }

        $preview = $this->lastMessagePreview($conversationId);
        $unread = $this->unreadCount($conversationId, $viewerUserId, $viewerRead);

        return array(
            'conversation_uuid' => (string)$conversation['conversation_uuid'],
            'conversation_type' => (string)$conversation['conversation_type'],
            'title' => (string)$conversation['title'],
            'last_message_seq' => (int)$conversation['last_message_seq'],
            'last_message_at_utc' => $conversation['last_message_at_utc'],
            'created_at_utc' => $conversation['created_at_utc'],
            'members' => $members,
            'preview' => $preview,
            'unread_count' => $unread,
            'viewer_last_read_seq' => $viewerRead,
        );
    }

    public function unreadTotal(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_communication_messages msg
            INNER JOIN ipca_communication_conversation_members mem
              ON mem.conversation_id = msg.conversation_id
             AND mem.user_id = ?
             AND mem.left_at_utc IS NULL
            WHERE msg.seq > mem.last_read_seq
              AND (msg.sender_user_id IS NULL OR msg.sender_user_id != ?)
        ");
        $stmt->execute(array($userId, $userId));
        return (int)$stmt->fetchColumn();
    }

    private function unreadCount(int $conversationId, int $userId, int $lastReadSeq): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_communication_messages
            WHERE conversation_id = ?
              AND seq > ?
              AND (sender_user_id IS NULL OR sender_user_id != ?)
        ");
        $stmt->execute(array($conversationId, $lastReadSeq, $userId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastMessagePreview(int $conversationId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT message_uuid, seq, body, sender_user_id, created_at_utc
            FROM ipca_communication_messages
            WHERE conversation_id = ?
            ORDER BY seq DESC
            LIMIT 1
        ");
        $stmt->execute(array($conversationId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $body = (string)$row['body'];
        if (mb_strlen($body) > 80) {
            $body = mb_substr($body, 0, 80);
        }
        return array(
            'message_uuid' => (string)$row['message_uuid'],
            'seq' => (int)$row['seq'],
            'body' => $body,
            'sender_user_id' => $row['sender_user_id'] !== null ? (int)$row['sender_user_id'] : null,
            'created_at_utc' => $row['created_at_utc'],
        );
    }

    private function ensureMember(int $conversationId, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT id, left_at_utc FROM ipca_communication_conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute(array($conversationId, $userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $this->insertMember($conversationId, $userId, CommunicationSupport::nowUtc());
            return;
        }
        if (trim((string)($row['left_at_utc'] ?? '')) !== '') {
            $this->pdo->prepare('UPDATE ipca_communication_conversation_members SET left_at_utc = NULL WHERE id = ?')
                ->execute(array((int)$row['id']));
        }
    }

    private function insertMember(int $conversationId, int $userId, string $now, string $role = 'member'): void
    {
        $this->pdo->prepare("
            INSERT INTO ipca_communication_conversation_members
              (conversation_id, user_id, member_role, joined_at_utc)
            VALUES (?, ?, ?, ?)
        ")->execute(array($conversationId, $userId, $role, $now));
    }

    private function appendChange(int $conversationId, string $type, string $entityUuid, string $now): void
    {
        $this->pdo->prepare("
            INSERT INTO ipca_communication_change_log
              (organization_id, conversation_id, change_type, entity_uuid, created_at_utc)
            VALUES (1, ?, ?, ?, ?)
        ")->execute(array($conversationId, $type, $entityUuid, $now));
    }

    private function authUserSelect(): string
    {
        return 'SELECT id, uuid, email, name, first_name, last_name, role, status, account_valid_until, photo_path, password_hash FROM users';
    }
}
