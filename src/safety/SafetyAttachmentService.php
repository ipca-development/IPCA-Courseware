<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyFeatureConfigService.php';
require_once dirname(__DIR__) . '/communication/CommunicationObjectStore.php';

final class SafetyAttachmentService
{
    public const MAX_BYTES = 26214400;
    private const MIME_TYPES = array(
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf', 'text/plain', 'text/csv',
    );

    public function __construct(
        private PDO $pdo,
        private SafetyAccessService $access,
        private SafetyFeatureConfigService $config,
        private CommunicationObjectStore $store
    ) {
    }

    /** @param array<string,mixed> $session */
    public function presign(
        array $session,
        string $reportUuid,
        string $filename,
        string $mimeType,
        int $byteSize,
        ?string $attachmentUuid = null
    ): array {
        $org = SafetySupport::organizationId($session);
        $this->config->requireEnabled($org, 'attachments_enabled');
        $report = $this->access->requireOwnReport($session, $reportUuid);
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        if (!in_array($mimeType, self::MIME_TYPES, true)) {
            throw new SafetyException('validation_error', 'That attachment type is not allowed.', 400);
        }
        if ($byteSize < 1 || $byteSize > self::MAX_BYTES) {
            throw new SafetyException('validation_error', 'That attachment size is not allowed.', 400);
        }
        $filename = preg_replace('/[\x00-\x1f\/\\\\]/', '', trim($filename)) ?? '';
        $filename = SafetySupport::cleanText($filename, 255, 'filename');
        $uuid = $attachmentUuid !== null && $attachmentUuid !== '' ? strtolower($attachmentUuid) : SafetySupport::uuid();
        if (!preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
            throw new SafetyException('validation_error', 'attachment_uuid must be a UUID.', 400);
        }
        $key = 'safety/' . $org . '/reports/' . $reportUuid . '/attachments/' . $uuid;
        $restricted = (string)$report['confidentiality'] === 'restricted';
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_attachments
             (organization_id, attachment_uuid, report_id, uploaded_by_user_id, uploader_reference_hash, storage_key,
              original_filename, mime_type, byte_size, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE attachment_uuid = attachment_uuid'
        )->execute(array(
            $org, $uuid, (int)$report['id'],
            $restricted ? null : (int)$session['user']['id'],
            $restricted ? (string)$report['reporter_subject_hash'] : null,
            $key,
            $filename, $mimeType, $byteSize, 'pending',
        ));
        return array(
            'attachment_uuid' => $uuid,
            'put_url' => $this->store->presignPut($key, $mimeType, 900),
            'headers' => array('Content-Type' => $mimeType),
            'expires_in' => 900,
            'status' => 'pending',
        );
    }

    /** @param array{organization_id:int,mailbox_id:int,report_id:int,report_uuid:string} $context */
    public function presignAnonymous(
        array $context,
        string $filename,
        string $mimeType,
        int $byteSize,
        ?string $attachmentUuid = null
    ): array {
        $org = $context['organization_id'];
        $this->config->requireEnabled($org, 'attachments_enabled');
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        if (!in_array($mimeType, self::MIME_TYPES, true)) {
            throw new SafetyException('validation_error', 'That attachment type is not allowed.', 400);
        }
        if ($byteSize < 1 || $byteSize > self::MAX_BYTES) {
            throw new SafetyException('validation_error', 'That attachment size is not allowed.', 400);
        }
        $filename = preg_replace('/[\x00-\x1f\/\\\\]/', '', trim($filename)) ?? '';
        $filename = SafetySupport::cleanText($filename, 255, 'filename');
        $uuid = $attachmentUuid !== null && $attachmentUuid !== ''
            ? strtolower($attachmentUuid) : SafetySupport::uuid();
        if (!preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
            throw new SafetyException('validation_error', 'attachment_uuid must be a UUID.', 400);
        }
        $key = 'safety/' . $org . '/reports/' . $context['report_uuid'] . '/attachments/' . $uuid;
        $this->pdo->prepare(
            'INSERT INTO ipca_safety_attachments
             (organization_id, attachment_uuid, report_id, anonymous_mailbox_id, storage_key,
              original_filename, mime_type, byte_size, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE attachment_uuid = attachment_uuid'
        )->execute(array(
            $org, $uuid, $context['report_id'], $context['mailbox_id'], $key,
            $filename, $mimeType, $byteSize, 'pending',
        ));
        return array(
            'attachment_uuid' => $uuid,
            'put_url' => $this->store->presignPut($key, $mimeType, 900),
            'headers' => array('Content-Type' => $mimeType),
            'expires_in' => 900,
            'status' => 'pending',
        );
    }

    /** @param array<string,mixed> $session */
    public function complete(array $session, string $attachmentUuid): array
    {
        $org = SafetySupport::organizationId($session);
        $stmt = $this->pdo->prepare(
            'SELECT a.*, r.report_uuid FROM ipca_safety_attachments a
             INNER JOIN ipca_safety_reports r ON r.id = a.report_id
             WHERE a.organization_id = ? AND a.attachment_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($org, strtolower(trim($attachmentUuid))));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SafetyException('not_found', 'Attachment not found.', 404);
        }
        $this->access->requireOwnReport($session, (string)$row['report_uuid']);
        if (!method_exists($this->store, 'head')) {
            throw new SafetyException('object_store_unavailable', 'Upload verification is unavailable.', 503);
        }
        /** @var array<string,mixed>|null $head */
        $head = $this->store->head((string)$row['storage_key']);
        if (!is_array($head)) {
            throw new SafetyException('upload_incomplete', 'Upload is not complete.', 409);
        }
        $actualSize = (int)($head['byte_size'] ?? 0);
        if ($actualSize < 1 || $actualSize > self::MAX_BYTES) {
            throw new SafetyException('validation_error', 'Uploaded attachment size is not allowed.', 400);
        }
        $this->pdo->prepare(
            "UPDATE ipca_safety_attachments SET status = 'uploaded', byte_size = ?, uploaded_at_utc = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND status = 'pending'"
        )->execute(array($actualSize, (int)$row['id']));
        return array(
            'attachment_uuid' => (string)$row['attachment_uuid'],
            'filename' => (string)$row['original_filename'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => $actualSize,
            'status' => 'uploaded',
        );
    }

    /** @param array{organization_id:int,mailbox_id:int,report_id:int,report_uuid:string} $context */
    public function completeAnonymous(array $context, string $attachmentUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_safety_attachments
             WHERE organization_id = ? AND attachment_uuid = ? AND report_id = ?
               AND anonymous_mailbox_id = ? LIMIT 1'
        );
        $stmt->execute(array(
            $context['organization_id'], strtolower(trim($attachmentUuid)),
            $context['report_id'], $context['mailbox_id'],
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new SafetyException('not_found', 'Attachment not found.', 404);
        }
        if (!method_exists($this->store, 'head')) {
            throw new SafetyException('object_store_unavailable', 'Upload verification is unavailable.', 503);
        }
        /** @var array<string,mixed>|null $head */
        $head = $this->store->head((string)$row['storage_key']);
        if (!is_array($head)) {
            throw new SafetyException('upload_incomplete', 'Upload is not complete.', 409);
        }
        $actualSize = (int)($head['byte_size'] ?? 0);
        if ($actualSize < 1 || $actualSize > self::MAX_BYTES) {
            throw new SafetyException('validation_error', 'Uploaded attachment size is not allowed.', 400);
        }
        $this->pdo->prepare(
            "UPDATE ipca_safety_attachments SET status = 'uploaded', byte_size = ?,
               uploaded_at_utc = CURRENT_TIMESTAMP(3)
             WHERE id = ? AND status = 'pending'"
        )->execute(array($actualSize, (int)$row['id']));
        return array(
            'attachment_uuid' => (string)$row['attachment_uuid'],
            'filename' => (string)$row['original_filename'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => $actualSize,
            'status' => 'uploaded',
        );
    }
}
