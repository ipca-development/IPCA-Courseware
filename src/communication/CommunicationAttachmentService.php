<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/CommunicationObjectStore.php';

final class CommunicationAttachmentService
{
    public const MAX_BYTES = 26214400;
    public const PUT_EXPIRES = 900;
    public const GET_EXPIRES = 300;

    /** @var array<int,string> */
    public const ALLOWED_MIME = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/pdf',
    );

    public function __construct(
        private PDO $pdo,
        private ConversationService $conversations,
        private CommunicationConfigService $config,
        private CommunicationObjectStore $store
    ) {
    }

    public function store(): CommunicationObjectStore
    {
        return $this->store;
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function presignPut(
        array $session,
        string $conversationUuid,
        string $filename,
        string $mimeType,
        int $byteSize,
        ?string $attachmentUuid = null
    ): array {
        $this->config->requireAttachments();
        $conversation = $this->conversations->requireMembership($session, $conversationUuid);
        $mimeType = $this->normalizeMime($mimeType);
        $filename = $this->sanitizeFilename($filename);
        if ($byteSize < 1 || $byteSize > self::MAX_BYTES) {
            throw new CommunicationException('validation_error', 'That file is too large to send.', 400);
        }
        if ($attachmentUuid !== null && $attachmentUuid !== '') {
            $attachmentUuid = CommunicationSupport::requireUuid($attachmentUuid, 'attachment_uuid');
        } else {
            $attachmentUuid = CommunicationSupport::uuid();
        }

        $existing = $this->findByUuid($attachmentUuid);
        if ($existing !== null) {
            if ((int)$existing['uploaded_by_user_id'] !== (int)$session['user']['id']
                || (int)$existing['conversation_id'] !== (int)$conversation['id']) {
                throw new CommunicationException('conflict', 'That attachment already exists.', 409);
            }
            if ((string)$existing['status'] === 'uploaded' || (string)$existing['status'] === 'attached') {
                return $this->publicPresign($existing, $mimeType);
            }
            return $this->publicPresign($existing, (string)$existing['mime_type']);
        }

        $now = CommunicationSupport::nowUtc();
        $storageKey = 'communication/1/c/' . (string)$conversation['conversation_uuid'] . '/a/' . $attachmentUuid;
        $this->pdo->prepare("
            INSERT INTO ipca_communication_attachments
              (attachment_uuid, conversation_id, organization_id, uploaded_by_user_id, uploaded_by_device_id,
               storage_key, original_filename, mime_type, byte_size, status, created_at_utc)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ")->execute(array(
            $attachmentUuid,
            (int)$conversation['id'],
            (int)$session['user']['id'],
            (int)$session['device']['id'],
            $storageKey,
            $filename,
            $mimeType,
            $byteSize,
            $now,
        ));

        $row = $this->findByUuid($attachmentUuid);
        return $this->publicPresign(is_array($row) ? $row : array(
            'attachment_uuid' => $attachmentUuid,
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'status' => 'pending',
        ), $mimeType);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function complete(array $session, string $attachmentUuid): array
    {
        $this->config->requireAttachments();
        $attachmentUuid = CommunicationSupport::requireUuid($attachmentUuid, 'attachment_uuid');
        $row = $this->findByUuid($attachmentUuid);
        if ($row === null || (int)$row['uploaded_by_user_id'] !== (int)$session['user']['id']) {
            throw new CommunicationException('not_found', 'Attachment not found.', 404);
        }
        $this->conversations->requireMembership($session, $this->conversationUuid((int)$row['conversation_id']));

        if ((string)$row['status'] === 'uploaded' || (string)$row['status'] === 'attached') {
            return $this->publicAttachment($row);
        }

        $head = $this->store->head((string)$row['storage_key']);
        if ($head === null) {
            throw new CommunicationException('upload_incomplete', 'Upload is not finished yet. Try again.', 409);
        }
        $size = (int)$head['byte_size'];
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new CommunicationException('validation_error', 'That file is too large to send.', 400);
        }
        $contentType = $this->normalizeMime((string)($head['content_type'] ?: $row['mime_type']));
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare("
            UPDATE ipca_communication_attachments
            SET status = 'uploaded', uploaded_at_utc = ?, byte_size = ?, mime_type = ?
            WHERE id = ? AND status = 'pending'
        ")->execute(array($now, $size, $contentType, (int)$row['id']));

        $updated = $this->findByUuid($attachmentUuid);
        return $this->publicAttachment(is_array($updated) ? $updated : $row);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function download(array $session, string $attachmentUuid): array
    {
        $this->config->requireAttachments();
        $attachmentUuid = CommunicationSupport::requireUuid($attachmentUuid, 'attachment_uuid');
        $row = $this->findByUuid($attachmentUuid);
        if ($row === null) {
            throw new CommunicationException('not_found', 'Attachment not found.', 404);
        }
        $this->conversations->requireMembership($session, $this->conversationUuid((int)$row['conversation_id']));
        $status = (string)$row['status'];
        if ($status !== 'uploaded' && $status !== 'attached') {
            throw new CommunicationException('not_found', 'Attachment is not ready.', 404);
        }
        return array(
            'attachment_uuid' => (string)$row['attachment_uuid'],
            'filename' => (string)$row['original_filename'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'get_url' => $this->store->presignGet((string)$row['storage_key'], self::GET_EXPIRES),
            'expires_in' => self::GET_EXPIRES,
        );
    }

    /**
     * @param array<int,string> $attachmentUuids
     * @return array<int,array<string,mixed>>
     */
    public function requireReadyForSend(array $session, int $conversationId, array $attachmentUuids): array
    {
        $this->config->requireAttachments();
        $unique = array();
        foreach ($attachmentUuids as $uuid) {
            $unique[] = CommunicationSupport::requireUuid((string)$uuid, 'attachment_uuid');
        }
        $unique = array_values(array_unique($unique));
        if ($unique === array()) {
            return array();
        }
        if (count($unique) > 8) {
            throw new CommunicationException('validation_error', 'Too many attachments.', 400);
        }

        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_communication_attachments
            WHERE attachment_uuid IN (" . $placeholders . ")
        ");
        $stmt->execute($unique);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($unique)) {
            throw new CommunicationException('validation_error', 'One of those attachments is missing.', 400);
        }
        $byUuid = array();
        foreach ($rows as $row) {
            if ((int)$row['conversation_id'] !== $conversationId) {
                throw new CommunicationException('validation_error', 'Attachment does not belong to this conversation.', 400);
            }
            if ((int)$row['uploaded_by_user_id'] !== (int)$session['user']['id']) {
                throw new CommunicationException('forbidden', 'You can only send your own attachments.', 403);
            }
            if ((string)$row['status'] !== 'uploaded') {
                throw new CommunicationException('upload_incomplete', 'Wait until the upload finishes before sending.', 409);
            }
            $byUuid[(string)$row['attachment_uuid']] = $row;
        }
        $ordered = array();
        foreach ($unique as $uuid) {
            $ordered[] = $byUuid[$uuid];
        }
        return $ordered;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function attachToMessage(int $messageId, array $rows, string $now): void
    {
        $insert = $this->pdo->prepare("
            INSERT INTO ipca_communication_message_attachments (message_id, attachment_id, sort_order)
            VALUES (?, ?, ?)
        ");
        $mark = $this->pdo->prepare("
            UPDATE ipca_communication_attachments SET status = 'attached' WHERE id = ? AND status = 'uploaded'
        ");
        foreach ($rows as $index => $row) {
            $insert->execute(array($messageId, (int)$row['id'], $index));
            $mark->execute(array((int)$row['id']));
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forMessage(int $messageId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.attachment_uuid, a.original_filename, a.mime_type, a.byte_size
            FROM ipca_communication_message_attachments ma
            INNER JOIN ipca_communication_attachments a ON a.id = ma.attachment_id
            WHERE ma.message_id = ?
            ORDER BY ma.sort_order ASC, ma.id ASC
        ");
        $stmt->execute(array($messageId));
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = array(
                'attachment_uuid' => (string)$row['attachment_uuid'],
                'filename' => (string)$row['original_filename'],
                'mime_type' => (string)$row['mime_type'],
                'byte_size' => (int)$row['byte_size'],
            );
        }
        return $out;
    }

    public function previewLabel(?string $body, ?string $mimeType = null, ?string $filename = null): string
    {
        $body = trim((string)$body);
        if ($body !== '') {
            if (mb_strlen($body) > 80) {
                return mb_substr($body, 0, 80);
            }
            return $body;
        }
        $mimeType = strtolower(trim((string)$mimeType));
        if (str_starts_with($mimeType, 'image/')) {
            return 'Photo';
        }
        if ($mimeType === 'application/pdf') {
            return 'PDF';
        }
        $filename = trim((string)$filename);
        return $filename !== '' ? $filename : 'Attachment';
    }

    public function previewLabelForMessage(int $messageId, string $body): string
    {
        if (trim($body) !== '') {
            return $this->previewLabel($body);
        }
        $attachments = $this->forMessage($messageId);
        $first = $attachments[0] ?? null;
        if ($first === null) {
            return '';
        }
        return $this->previewLabel('', (string)$first['mime_type'], (string)$first['filename']);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByUuid(string $attachmentUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_communication_attachments WHERE attachment_uuid = ? LIMIT 1');
        $stmt->execute(array($attachmentUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicPresign(array $row, string $mimeType): array
    {
        return array(
            'attachment_uuid' => (string)$row['attachment_uuid'],
            'put_url' => $this->store->presignPut((string)$row['storage_key'], $mimeType, self::PUT_EXPIRES),
            'headers' => array('Content-Type' => $mimeType),
            'expires_in' => self::PUT_EXPIRES,
            'status' => (string)($row['status'] ?? 'pending'),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicAttachment(array $row): array
    {
        return array(
            'attachment_uuid' => (string)$row['attachment_uuid'],
            'filename' => (string)$row['original_filename'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'status' => (string)$row['status'],
        );
    }

    private function conversationUuid(int $conversationId): string
    {
        $stmt = $this->pdo->prepare('SELECT conversation_uuid FROM ipca_communication_conversations WHERE id = ?');
        $stmt->execute(array($conversationId));
        return (string)$stmt->fetchColumn();
    }

    private function normalizeMime(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            throw new CommunicationException('validation_error', 'That file type cannot be sent.', 400);
        }
        return $mimeType;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(array('\\', '/'), '', trim($filename));
        $filename = preg_replace('/[\x00-\x1F]/', '', $filename) ?? '';
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'attachment';
        }
        if (strlen($filename) > 255) {
            $filename = substr($filename, 0, 255);
        }
        return $filename;
    }
}
