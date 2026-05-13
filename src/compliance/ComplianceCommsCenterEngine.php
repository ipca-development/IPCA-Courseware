<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';
require_once __DIR__ . '/CompliancePostmarkConfig.php';

/**
 * Phase 8 — Compliance Communications Center engine.
 *
 * Stage 1 — inbound:
 *   - ingestPostmarkInbound()        primary entry called from the inbound webhook.
 *   - resolveOrCreateThread()        thread matcher (in-reply-to / references /
 *                                    mailbox-hash / subject+contact / new).
 *   - storeInboundEmail()            dedup-aware INSERT into ipca_compliance_emails.
 *   - storeAttachment()              base64 decode + sha256 + Spaces upload
 *                                    (local fallback) + DB row.
 *   - logEvent()                     ipca_compliance_email_events row.
 *
 * Stage 2 — outbound + tracking + linking:
 *   - createDraft() / updateDraft() / getDraft() / listDrafts() / cancelDraft()
 *   - sendOutbound()                 POSTs to Postmark /email, persists outbound row,
 *                                    fires automation event.
 *   - processPostmarkEvent()         Delivery / Open / Click / Bounce / SpamComplaint
 *                                    → event row + parent email status update.
 *   - linkObject() / unlinkObject() / linkableObjectTypes()
 */
final class ComplianceCommsCenterEngine
{
    public const MAX_ATTACHMENT_BYTES = 50 * 1024 * 1024; // 50 MiB hard ceiling per attachment.

    /** Hosts/extensions we refuse to persist as attachments. */
    private const DANGEROUS_EXTENSIONS = array(
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'vbs', 'js', 'jse',
        'ws', 'wsf', 'wsh', 'ps1', 'jar',
    );

    /**
     * Ingest a Postmark Inbound payload (already JSON-decoded into an assoc
     * array). Returns a small diagnostic for the webhook to log/return.
     *
     * @param array<string,mixed> $payload
     * @return array{
     *   action:string,              // 'created' | 'duplicate' | 'error'
     *   email_id:int|null,
     *   thread_id:int|null,
     *   attachments_stored:int,
     *   attachments_failed:int,
     *   notes:list<string>
     * }
     */
    public static function ingestPostmarkInbound(PDO $pdo, array $payload): array
    {
        $notes = array();
        $postmarkMessageId = self::nullableStr((string)($payload['MessageID'] ?? ''));

        // ---- DEDUP guard --------------------------------------------------------
        if ($postmarkMessageId !== null) {
            $st = $pdo->prepare('SELECT id, thread_id FROM ipca_compliance_emails WHERE postmark_message_id = ? LIMIT 1');
            $st->execute(array($postmarkMessageId));
            $existing = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                self::logEvent($pdo, (int)$existing['id'], $postmarkMessageId, 'inbound', array(
                    'duplicate_of' => (int)$existing['id'],
                    'received_at' => date('c'),
                ));

                return array(
                    'action' => 'duplicate',
                    'email_id' => (int)$existing['id'],
                    'thread_id' => isset($existing['thread_id']) ? (int)$existing['thread_id'] : null,
                    'attachments_stored' => 0,
                    'attachments_failed' => 0,
                    'notes' => array('duplicate Postmark MessageID — no new row inserted'),
                );
            }
        }

        // ---- Resolve / create thread -------------------------------------------
        $headers = self::flattenHeaders($payload['Headers'] ?? null);
        $messageIdHeader = self::extractHeader($headers, 'Message-ID');
        $inReplyToHeader = self::extractHeader($headers, 'In-Reply-To');
        $referencesHeader = self::extractHeader($headers, 'References');
        $mailboxHash = self::nullableStr((string)($payload['MailboxHash'] ?? ''));
        $fromEmail = strtolower((string)($payload['FromFull']['Email'] ?? $payload['From'] ?? ''));
        $fromName = self::nullableStr((string)($payload['FromFull']['Name'] ?? ''));
        $subject = self::nullableStr((string)($payload['Subject'] ?? ''));
        $subjectNorm = $subject !== null ? self::normalizeSubject($subject) : null;

        $thread = self::resolveOrCreateThread(
            $pdo,
            $mailboxHash,
            $inReplyToHeader,
            $referencesHeader,
            $subjectNorm,
            $fromEmail !== '' ? $fromEmail : null
        );

        // ---- Insert the email row ----------------------------------------------
        $emailId = self::storeInboundEmail($pdo, $thread['id'], $payload, array(
            'message_id_header' => $messageIdHeader,
            'in_reply_to_header' => $inReplyToHeader,
            'references_header' => $referencesHeader,
            'mailbox_hash' => $mailboxHash,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'subject_normalized' => $subjectNorm,
            'headers' => $headers,
        ));

        // Roll the thread forward so the inbox sort order is correct.
        $pdo->prepare(
            'UPDATE ipca_compliance_email_threads
                SET last_message_at = COALESCE(?, last_message_at),
                    primary_contact_email = COALESCE(primary_contact_email, ?),
                    subject_normalized = COALESCE(subject_normalized, ?)
              WHERE id = ?'
        )->execute(array(
            self::postmarkDateToMysql((string)($payload['Date'] ?? '')),
            $fromEmail !== '' ? $fromEmail : null,
            $subjectNorm,
            (int)$thread['id'],
        ));

        // ---- Attachments --------------------------------------------------------
        $att = isset($payload['Attachments']) && is_array($payload['Attachments']) ? $payload['Attachments'] : array();
        $stored = 0;
        $failed = 0;
        foreach ($att as $idx => $a) {
            if (!is_array($a)) {
                continue;
            }
            try {
                self::storeAttachment($pdo, $emailId, $idx, $a);
                $stored++;
            } catch (Throwable $e) {
                $failed++;
                $notes[] = 'attachment[' . (int)$idx . '] failed: ' . substr($e->getMessage(), 0, 200);
                self::logEvent($pdo, $emailId, $postmarkMessageId, 'webhook_error', array(
                    'scope' => 'attachment',
                    'index' => (int)$idx,
                    'name' => (string)($a['Name'] ?? ''),
                    'error' => substr($e->getMessage(), 0, 500),
                ));
            }
        }

        self::logEvent($pdo, $emailId, $postmarkMessageId, 'inbound', array(
            'attachments_stored' => $stored,
            'attachments_failed' => $failed,
            'from' => $fromEmail,
            'subject' => $subject,
            'thread_id' => (int)$thread['id'],
        ));

        // Non-fatal automation event dispatch.
        ComplianceAutomationDispatch::fire($pdo, 'compliance.inbox.email_received', array(
            'email_id' => $emailId,
            'thread_id' => (int)$thread['id'],
            'from_email' => $fromEmail,
            'subject' => $subject,
            'attachments_count' => $stored,
            'spam_score' => $payload['SpamScore'] ?? null,
            'postmark_message_id' => $postmarkMessageId,
            'source' => 'postmark_inbound_webhook',
        ));

        return array(
            'action' => 'created',
            'email_id' => $emailId,
            'thread_id' => (int)$thread['id'],
            'attachments_stored' => $stored,
            'attachments_failed' => $failed,
            'notes' => $notes,
        );
    }

    // ------------------------------------------------------------------
    // Thread resolution
    // ------------------------------------------------------------------

    /**
     * @return array{id:int, created:bool, match:string}
     */
    public static function resolveOrCreateThread(
        PDO $pdo,
        ?string $mailboxHash,
        ?string $inReplyToHeader,
        ?string $referencesHeader,
        ?string $subjectNormalized,
        ?string $fromEmail
    ): array {
        // 1. Direct In-Reply-To pointer to an existing email's Message-Id.
        if ($inReplyToHeader !== null && $inReplyToHeader !== '') {
            $st = $pdo->prepare(
                'SELECT thread_id FROM ipca_compliance_emails
                  WHERE message_id_header = ? AND thread_id IS NOT NULL
                  ORDER BY id DESC LIMIT 1'
            );
            $st->execute(array($inReplyToHeader));
            $tid = (int)$st->fetchColumn();
            if ($tid > 0) {
                return array('id' => $tid, 'created' => false, 'match' => 'in_reply_to');
            }
        }

        // 2. References chain — try each id from newest backwards.
        if ($referencesHeader !== null && $referencesHeader !== '') {
            $ids = self::extractMessageIds($referencesHeader);
            foreach (array_reverse($ids) as $ref) {
                $st = $pdo->prepare(
                    'SELECT thread_id FROM ipca_compliance_emails
                      WHERE message_id_header = ? AND thread_id IS NOT NULL
                      ORDER BY id DESC LIMIT 1'
                );
                $st->execute(array($ref));
                $tid = (int)$st->fetchColumn();
                if ($tid > 0) {
                    return array('id' => $tid, 'created' => false, 'match' => 'references');
                }
            }
        }

        // 3. Mailbox hash (Postmark "+hash" addressing) is a strong signal.
        if ($mailboxHash !== null && $mailboxHash !== '') {
            $st = $pdo->prepare(
                'SELECT id FROM ipca_compliance_email_threads
                  WHERE thread_key = ?
                  ORDER BY id DESC LIMIT 1'
            );
            $st->execute(array('mh:' . $mailboxHash));
            $tid = (int)$st->fetchColumn();
            if ($tid > 0) {
                return array('id' => $tid, 'created' => false, 'match' => 'mailbox_hash');
            }
        }

        // 4. Subject-normalized + contact fallback, but only within 90 days so
        //    distinct authority correspondence doesn't pile into one thread.
        if ($subjectNormalized !== null && $subjectNormalized !== '' && $fromEmail !== null && $fromEmail !== '') {
            $st = $pdo->prepare(
                'SELECT id FROM ipca_compliance_email_threads
                  WHERE subject_normalized = ?
                    AND primary_contact_email = ?
                    AND COALESCE(last_message_at, created_at) > DATE_SUB(NOW(), INTERVAL 90 DAY)
                    AND status <> ?
                  ORDER BY id DESC LIMIT 1'
            );
            $st->execute(array($subjectNormalized, $fromEmail, 'archived'));
            $tid = (int)$st->fetchColumn();
            if ($tid > 0) {
                return array('id' => $tid, 'created' => false, 'match' => 'subject_contact');
            }
        }

        // 5. New thread.
        $threadKey = null;
        if ($mailboxHash !== null && $mailboxHash !== '') {
            $threadKey = 'mh:' . $mailboxHash;
        } elseif ($inReplyToHeader !== null && $inReplyToHeader !== '') {
            $threadKey = 'mid:' . substr($inReplyToHeader, 0, 180);
        } elseif ($subjectNormalized !== null && $fromEmail !== null) {
            $threadKey = 'sc:' . substr(sha1(strtolower($subjectNormalized) . '|' . $fromEmail), 0, 160);
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_threads
                (thread_key, subject_normalized, primary_contact_email, status, priority, last_message_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute(array(
            $threadKey,
            $subjectNormalized,
            $fromEmail !== null && $fromEmail !== '' ? $fromEmail : null,
            'open',
            'normal',
        ));

        return array(
            'id' => (int)$pdo->lastInsertId(),
            'created' => true,
            'match' => 'new',
        );
    }

    // ------------------------------------------------------------------
    // Email row insert
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload  Original Postmark payload.
     * @param array<string,mixed> $parsed   Pre-parsed convenience fields.
     */
    public static function storeInboundEmail(PDO $pdo, int $threadId, array $payload, array $parsed): int
    {
        $postmarkMessageId = self::nullableStr((string)($payload['MessageID'] ?? ''));
        $recordType = self::nullableStr((string)($payload['RecordType'] ?? 'Inbound'));

        $toList = self::extractAddressList($payload['ToFull'] ?? null, $payload['To'] ?? null);
        $ccList = self::extractAddressList($payload['CcFull'] ?? null, $payload['Cc'] ?? null);
        $bccList = self::extractAddressList($payload['BccFull'] ?? null, $payload['Bcc'] ?? null);
        $replyTo = self::extractAddressList($payload['ReplyToFull'] ?? null, $payload['ReplyTo'] ?? null);

        $receivedAt = self::postmarkDateToMysql((string)($payload['Date'] ?? '')) ?? date('Y-m-d H:i:s');
        $spamScore = isset($payload['SpamScore']) && is_numeric($payload['SpamScore'])
            ? (float)$payload['SpamScore'] : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_emails (
                thread_id, direction, postmark_message_id, postmark_record_type,
                message_id_header, in_reply_to_header, references_header,
                from_email, from_name,
                to_json, cc_json, bcc_json, reply_to_json,
                subject, text_body, html_body, stripped_text_reply,
                headers_json, raw_payload_json,
                received_at, status, spam_score, mailbox_hash
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $threadId,
            'inbound',
            $postmarkMessageId,
            $recordType,
            self::nullableStr((string)($parsed['message_id_header'] ?? '')),
            self::nullableStr((string)($parsed['in_reply_to_header'] ?? '')),
            self::nullableStr((string)($parsed['references_header'] ?? '')),
            substr((string)($parsed['from_email'] ?? ''), 0, 255),
            self::nullableStr((string)($parsed['from_name'] ?? '')),
            self::jsonEncode($toList),
            self::jsonEncode($ccList),
            self::jsonEncode($bccList),
            self::jsonEncode($replyTo),
            self::nullableStr((string)($parsed['subject'] ?? ''), 500),
            self::nullableStr((string)($payload['TextBody'] ?? ''), null),
            self::nullableStr((string)($payload['HtmlBody'] ?? ''), null),
            self::nullableStr((string)($payload['StrippedTextReply'] ?? ''), null),
            self::jsonEncode($parsed['headers'] ?? null),
            self::jsonEncode($payload),
            $receivedAt,
            'received',
            $spamScore,
            self::nullableStr((string)($parsed['mailbox_hash'] ?? '')),
        ));

        return (int)$pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Attachment handling
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $attachment Postmark attachment block.
     * @return array{id:int,storage_disk:string,storage_key:string,sha256:string|null}
     */
    public static function storeAttachment(PDO $pdo, int $emailId, int $index, array $attachment): array
    {
        $name = (string)($attachment['Name'] ?? '');
        $contentType = self::nullableStr((string)($attachment['ContentType'] ?? ''), 191);
        $contentB64 = (string)($attachment['Content'] ?? '');
        $contentId = self::nullableStr((string)($attachment['ContentID'] ?? ''));
        $isInline = $contentId !== null ? 1 : 0;

        if ($contentB64 === '') {
            throw new RuntimeException('attachment has no Content payload');
        }

        $bytes = base64_decode($contentB64, true);
        if ($bytes === false) {
            throw new RuntimeException('attachment Content was not valid base64');
        }
        $size = strlen($bytes);
        if ($size > self::MAX_ATTACHMENT_BYTES) {
            throw new RuntimeException('attachment exceeds maximum size (' . $size . ' bytes)');
        }

        $sha256 = hash('sha256', $bytes);
        $safeName = self::sanitizeFilename($name);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            throw new RuntimeException('attachment extension blocked by policy: ' . $ext);
        }

        $yyyy = date('Y');
        $mm = date('m');
        $relKey = 'compliance/emails/' . $yyyy . '/' . $mm . '/' . $emailId . '/' . substr($sha256, 0, 12) . '-' . $safeName;

        $disk = 'spaces';
        $publicUrl = null;
        try {
            $up = self::uploadToSpaces($relKey, $bytes, $contentType ?? 'application/octet-stream');
            $publicUrl = (string)($up['cdn_url'] ?? '');
        } catch (Throwable $e) {
            // Fall back to local storage so evidence is never lost.
            $disk = 'local';
            $localPath = self::storageRoot() . '/' . $relKey;
            $dir = dirname($localPath);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('could not create local attachment dir: ' . $dir);
            }
            if (file_put_contents($localPath, $bytes) === false) {
                throw new RuntimeException('could not write local attachment file');
            }
            $publicUrl = null;
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_attachments (
                email_id, original_filename, content_type, size_bytes,
                content_id, postmark_attachment_index, storage_disk,
                storage_key, public_url, sha256, is_inline
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $emailId,
            substr($safeName, 0, 255),
            $contentType,
            $size,
            $contentId,
            $index,
            $disk,
            $relKey,
            $publicUrl,
            $sha256,
            $isInline,
        ));

        return array(
            'id' => (int)$pdo->lastInsertId(),
            'storage_disk' => $disk,
            'storage_key' => $relKey,
            'sha256' => $sha256,
        );
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     */
    public static function logEvent(PDO $pdo, ?int $emailId, ?string $postmarkMessageId, string $eventType, array $payload, ?string $recipient = null, ?string $eventAt = null): int
    {
        $allowed = array('delivery', 'open', 'click', 'bounce', 'spam_complaint', 'inbound', 'outbound_send', 'webhook_error');
        if (!in_array($eventType, $allowed, true)) {
            $eventType = 'webhook_error';
        }
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_events
                (email_id, postmark_message_id, event_type, event_at, recipient_email, event_payload_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute(array(
            $emailId !== null && $emailId > 0 ? $emailId : null,
            $postmarkMessageId,
            $eventType,
            $eventAt ?? date('Y-m-d H:i:s'),
            $recipient,
            self::jsonEncode($payload),
        ));

        return (int)$pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // List helpers used by the Inbox UI
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public static function listThreads(PDO $pdo, array $filters = array(), int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT t.*,
                  (SELECT COUNT(*) FROM ipca_compliance_emails  e WHERE e.thread_id = t.id) AS message_count,
                  (SELECT COUNT(*) FROM ipca_compliance_emails  e
                     JOIN ipca_compliance_email_attachments a ON a.email_id = e.id
                    WHERE e.thread_id = t.id) AS attachment_count,
                  (SELECT COUNT(*) FROM ipca_compliance_email_obj_links l WHERE l.thread_id = t.id) AS link_count,
                  (SELECT e.from_email FROM ipca_compliance_emails e
                    WHERE e.thread_id = t.id ORDER BY e.id DESC LIMIT 1) AS last_from_email,
                  (SELECT e.subject FROM ipca_compliance_emails e
                    WHERE e.thread_id = t.id ORDER BY e.id DESC LIMIT 1) AS last_subject
                  FROM ipca_compliance_email_threads t
                 WHERE 1=1";
        $args = array();
        if (!empty($filters['status'])) {
            $sql .= ' AND t.status = ?';
            $args[] = (string)$filters['status'];
        }
        if (!empty($filters['priority'])) {
            $sql .= ' AND t.priority = ?';
            $args[] = (string)$filters['priority'];
        }
        if (!empty($filters['has_attachments'])) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM ipca_compliance_emails e
                 JOIN ipca_compliance_email_attachments a ON a.email_id = e.id
                WHERE e.thread_id = t.id)';
        }
        if (isset($filters['linked'])) {
            if ((bool)$filters['linked']) {
                $sql .= ' AND EXISTS (SELECT 1 FROM ipca_compliance_email_obj_links l WHERE l.thread_id = t.id)';
            } else {
                $sql .= ' AND NOT EXISTS (SELECT 1 FROM ipca_compliance_email_obj_links l WHERE l.thread_id = t.id)';
            }
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (t.subject_normalized LIKE ? OR t.primary_contact_email LIKE ? OR t.authority_name LIKE ?)';
            $q = '%' . (string)$filters['q'] . '%';
            $args[] = $q;
            $args[] = $q;
            $args[] = $q;
        }
        $sql .= ' ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC LIMIT ' . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array{open:int,waiting_internal:int,waiting_external:int,closed:int,archived:int,unlinked:int,has_attachments:int}
     */
    public static function threadStats(PDO $pdo): array
    {
        $out = array(
            'open' => 0, 'waiting_internal' => 0, 'waiting_external' => 0,
            'closed' => 0, 'archived' => 0, 'unlinked' => 0, 'has_attachments' => 0,
        );
        try {
            $st = $pdo->query(
                'SELECT status, COUNT(*) AS n FROM ipca_compliance_email_threads GROUP BY status'
            );
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $k = (string)($r['status'] ?? '');
                if (array_key_exists($k, $out)) {
                    $out[$k] = (int)$r['n'];
                }
            }
            $out['unlinked'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM ipca_compliance_email_threads t
                  WHERE NOT EXISTS (
                    SELECT 1 FROM ipca_compliance_email_obj_links l WHERE l.thread_id = t.id
                  )'
            )->fetchColumn();
            $out['has_attachments'] = (int)$pdo->query(
                'SELECT COUNT(DISTINCT t.id) FROM ipca_compliance_email_threads t
                   JOIN ipca_compliance_emails e ON e.thread_id = t.id
                   JOIN ipca_compliance_email_attachments a ON a.email_id = e.id'
            )->fetchColumn();
        } catch (Throwable) {
            // tables may not be migrated yet — return zeroes
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getThread(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_email_threads WHERE id = ? LIMIT 1');
        $st->execute(array($id));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listEmailsForThread(PDO $pdo, int $threadId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_emails
              WHERE thread_id = ?
              ORDER BY COALESCE(received_at, sent_at, created_at) ASC, id ASC'
        );
        $st->execute(array($threadId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listAttachmentsForEmail(PDO $pdo, int $emailId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_email_attachments
              WHERE email_id = ?
              ORDER BY id ASC'
        );
        $st->execute(array($emailId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listEventsForEmail(PDO $pdo, int $emailId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_email_events
              WHERE email_id = ?
              ORDER BY COALESCE(event_at, created_at) DESC, id DESC'
        );
        $st->execute(array($emailId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listObjectLinksForThread(PDO $pdo, int $threadId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_email_obj_links
              WHERE thread_id = ?
              ORDER BY id DESC'
        );
        $st->execute(array($threadId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array{id:int|null, received_at:string|null, postmark_message_id:string|null, from_email:string|null, subject:string|null}
     */
    public static function latestInbound(PDO $pdo): array
    {
        try {
            $st = $pdo->query(
                "SELECT id, received_at, postmark_message_id, from_email, subject
                   FROM ipca_compliance_emails
                  WHERE direction = 'inbound'
                  ORDER BY id DESC LIMIT 1"
            );
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return array(
                    'id' => (int)$row['id'],
                    'received_at' => $row['received_at'] ?? null,
                    'postmark_message_id' => $row['postmark_message_id'] ?? null,
                    'from_email' => $row['from_email'] ?? null,
                    'subject' => $row['subject'] ?? null,
                );
            }
        } catch (Throwable) {
            // table missing — return empty
        }

        return array('id' => null, 'received_at' => null, 'postmark_message_id' => null, 'from_email' => null, 'subject' => null);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function normalizeSubject(string $subject): string
    {
        $s = trim($subject);
        $s = (string)preg_replace('/^(?:\s*(?:re|fw|fwd|aw|sv|rv)\s*:\s*)+/i', '', $s);
        $s = (string)preg_replace('/\s+/', ' ', $s);

        return substr($s, 0, 255);
    }

    public static function sanitizeFilename(string $name): string
    {
        $base = basename(trim($name));
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'attachment-' . bin2hex(random_bytes(4));
        }
        $base = (string)preg_replace('/[^A-Za-z0-9._\-]+/', '_', $base);
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'attachment-' . bin2hex(random_bytes(4));
        }

        return substr($base, 0, 180);
    }

    /**
     * @param mixed $rawHeaders Postmark headers payload (list of {Name, Value}) or null.
     * @return array<int,array{Name:string,Value:string}>
     */
    private static function flattenHeaders($rawHeaders): array
    {
        if (!is_array($rawHeaders)) {
            return array();
        }
        $out = array();
        foreach ($rawHeaders as $h) {
            if (!is_array($h)) {
                continue;
            }
            $name = (string)($h['Name'] ?? '');
            $value = (string)($h['Value'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = array('Name' => $name, 'Value' => $value);
        }

        return $out;
    }

    /**
     * @param array<int,array{Name:string,Value:string}> $headers
     */
    private static function extractHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $h) {
            if (strtolower((string)$h['Name']) === $lower) {
                $v = trim((string)$h['Value']);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    /**
     * Pull `<msg-id@host>` tokens out of a References header (or anything similar).
     *
     * @return list<string>
     */
    private static function extractMessageIds(string $blob): array
    {
        $out = array();
        if (preg_match_all('/<[^<>\s]+@[^<>\s]+>/', $blob, $m)) {
            foreach ($m[0] as $id) {
                $out[] = (string)$id;
            }
        }

        return $out;
    }

    /**
     * @param mixed $fullList Postmark `ToFull` etc. (list of {Email, Name, MailboxHash})
     * @param mixed $rawList  Comma-joined fallback string from `To`/`Cc`/`Bcc`.
     * @return list<array{Email:string,Name:string,MailboxHash:?string}>|null
     */
    private static function extractAddressList($fullList, $rawList): ?array
    {
        if (is_array($fullList)) {
            $out = array();
            foreach ($fullList as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $email = (string)($f['Email'] ?? '');
                if ($email === '') {
                    continue;
                }
                $out[] = array(
                    'Email' => $email,
                    'Name' => (string)($f['Name'] ?? ''),
                    'MailboxHash' => isset($f['MailboxHash']) ? (string)$f['MailboxHash'] : null,
                );
            }

            return $out !== array() ? $out : null;
        }
        $raw = trim((string)$rawList);
        if ($raw === '') {
            return null;
        }
        $out = array();
        foreach (preg_split('/\s*,\s*/', $raw) as $token) {
            $token = trim((string)$token);
            if ($token === '') {
                continue;
            }
            $out[] = array('Email' => $token, 'Name' => '', 'MailboxHash' => null);
        }

        return $out !== array() ? $out : null;
    }

    private static function postmarkDateToMysql(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($raw);

            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function nullableStr(string $v, ?int $max = null): ?string
    {
        $t = trim($v);
        if ($t === '') {
            return null;
        }
        if ($max !== null) {
            return substr($t, 0, $max);
        }

        return $t;
    }

    /**
     * @param mixed $value
     */
    private static function jsonEncode($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $j = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $j === false ? null : $j;
    }

    private static function uploadToSpaces(string $key, string $bytes, string $contentType): array
    {
        $spacesHelper = __DIR__ . '/../spaces.php';
        if (!is_file($spacesHelper)) {
            throw new RuntimeException('spaces helper not available');
        }
        require_once $spacesHelper;
        if (!function_exists('cw_spaces_put_object')) {
            throw new RuntimeException('cw_spaces_put_object() missing');
        }

        return cw_spaces_put_object($key, $bytes, $contentType);
    }

    public static function storageRoot(): string
    {
        return dirname(__DIR__, 2) . '/storage';
    }

    // ==================================================================
    //                       STAGE 2 — OUTBOUND
    // ==================================================================

    /** Postmark REST endpoint for transactional sends. */
    public const POSTMARK_SEND_URL = 'https://api.postmarkapp.com/email';

    /**
     * Object types an email/thread can be linked to. Keep aligned with the
     * `linked_object_type` strings the rest of the compliance schema uses.
     *
     * @return array<string,string> machine_value => display label
     */
    public static function linkableObjectTypes(): array
    {
        return array(
            'compliance_case' => 'Case / MoC',
            'finding' => 'Finding',
            'audit' => 'Audit',
            'corrective_action' => 'Corrective Action',
            'manual_change_request' => 'Manual Change Request',
            'meeting' => 'Meeting',
            'regulatory_change' => 'Regulatory Change',
            'authority_report' => 'Authority Report',
        );
    }

    /**
     * Link types — the *role* an email plays for the linked object.
     *
     * @return array<string,string>
     */
    public static function linkTypes(): array
    {
        return array(
            'evidence' => 'Evidence',
            'authority_communication' => 'Authority Communication',
            'source' => 'Source',
            'follow_up' => 'Follow-up',
            'context' => 'Context',
        );
    }

    // ------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------

    /**
     * Create a new outbound draft. The recipients/subject/body fields are
     * validated and normalised here so the caller (a controller) can stay
     * tiny.
     *
     * @param array<string,mixed> $opts
     *   - thread_id      int|null      attach to an existing thread, else NULL
     *   - to             string|array  CSV or list of addresses (required)
     *   - cc, bcc        string|array  optional
     *   - subject        string        required, max 500 chars
     *   - text_body      string|null
     *   - html_body      string|null
     *   - created_by     int|null
     */
    public static function createDraft(PDO $pdo, array $opts): int
    {
        $to = self::normalizeAddressInput($opts['to'] ?? null);
        if ($to === array()) {
            throw new InvalidArgumentException('At least one To address is required.');
        }
        $cc = self::normalizeAddressInput($opts['cc'] ?? null);
        $bcc = self::normalizeAddressInput($opts['bcc'] ?? null);
        $subject = self::nullableStr((string)($opts['subject'] ?? ''), 500);
        if ($subject === null) {
            throw new InvalidArgumentException('Subject is required.');
        }
        $threadId = isset($opts['thread_id']) && (int)$opts['thread_id'] > 0 ? (int)$opts['thread_id'] : null;
        $textBody = self::nullableStr((string)($opts['text_body'] ?? ''));
        $htmlBody = self::nullableStr((string)($opts['html_body'] ?? ''));
        if ($textBody === null && $htmlBody === null) {
            throw new InvalidArgumentException('Message body is required (text or HTML).');
        }
        $createdBy = isset($opts['created_by']) && (int)$opts['created_by'] > 0 ? (int)$opts['created_by'] : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_drafts
                (thread_id, to_json, cc_json, bcc_json, subject, text_body, html_body, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $threadId,
            self::jsonEncode(self::addressListForJson($to)),
            $cc === array() ? null : self::jsonEncode(self::addressListForJson($cc)),
            $bcc === array() ? null : self::jsonEncode(self::addressListForJson($bcc)),
            $subject,
            $textBody,
            $htmlBody,
            'draft',
            $createdBy,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $opts same keys as createDraft()
     */
    public static function updateDraft(PDO $pdo, int $draftId, array $opts): void
    {
        $draft = self::getDraft($pdo, $draftId);
        if ($draft === null) {
            throw new RuntimeException('Draft not found.');
        }
        if ((string)$draft['status'] !== 'draft') {
            throw new RuntimeException('Only drafts in status=draft can be edited.');
        }

        $to = self::normalizeAddressInput($opts['to'] ?? null);
        if ($to === array()) {
            throw new InvalidArgumentException('At least one To address is required.');
        }
        $cc = self::normalizeAddressInput($opts['cc'] ?? null);
        $bcc = self::normalizeAddressInput($opts['bcc'] ?? null);
        $subject = self::nullableStr((string)($opts['subject'] ?? ''), 500);
        if ($subject === null) {
            throw new InvalidArgumentException('Subject is required.');
        }
        $textBody = self::nullableStr((string)($opts['text_body'] ?? ''));
        $htmlBody = self::nullableStr((string)($opts['html_body'] ?? ''));
        if ($textBody === null && $htmlBody === null) {
            throw new InvalidArgumentException('Message body is required (text or HTML).');
        }

        $pdo->prepare(
            'UPDATE ipca_compliance_email_drafts
                SET to_json = ?, cc_json = ?, bcc_json = ?,
                    subject = ?, text_body = ?, html_body = ?
              WHERE id = ?'
        )->execute(array(
            self::jsonEncode(self::addressListForJson($to)),
            $cc === array() ? null : self::jsonEncode(self::addressListForJson($cc)),
            $bcc === array() ? null : self::jsonEncode(self::addressListForJson($bcc)),
            $subject,
            $textBody,
            $htmlBody,
            $draftId,
        ));
    }

    public static function getDraft(PDO $pdo, int $draftId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ipca_compliance_email_drafts WHERE id = ? LIMIT 1');
        $st->execute(array($draftId));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function listDrafts(PDO $pdo, array $filters = array(), int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT d.*, t.subject_normalized AS thread_subject, t.primary_contact_email AS thread_contact
                  FROM ipca_compliance_email_drafts d
             LEFT JOIN ipca_compliance_email_threads t ON t.id = d.thread_id
                 WHERE 1=1';
        $args = array();
        if (!empty($filters['status'])) {
            $sql .= ' AND d.status = ?';
            $args[] = (string)$filters['status'];
        }
        if (!empty($filters['created_by'])) {
            $sql .= ' AND d.created_by = ?';
            $args[] = (int)$filters['created_by'];
        }
        $sql .= ' ORDER BY d.updated_at DESC, d.id DESC LIMIT ' . $limit;

        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function cancelDraft(PDO $pdo, int $draftId, ?int $uid): void
    {
        $draft = self::getDraft($pdo, $draftId);
        if ($draft === null) {
            throw new RuntimeException('Draft not found.');
        }
        if ((string)$draft['status'] === 'sent') {
            throw new RuntimeException('Cannot cancel a draft that has already been sent.');
        }
        $pdo->prepare('UPDATE ipca_compliance_email_drafts SET status = ? WHERE id = ?')
            ->execute(array('cancelled', $draftId));
    }

    // ------------------------------------------------------------------
    // Outbound send
    // ------------------------------------------------------------------

    /**
     * Send an outbound email via Postmark and persist it as an
     * ipca_compliance_emails row with direction='outbound', status='sent'.
     *
     * Inputs mirror createDraft(); additionally accepts:
     *   - draft_id           int|null   if present, the draft is marked sent
     *                                   and sent_email_id is wired up.
     *   - reply_to_email_id  int|null   if present, In-Reply-To / References
     *                                   are derived from that inbound email.
     *
     * @param array<string,mixed> $opts
     * @return array{ok:bool, email_id:int|null, thread_id:int|null,
     *               postmark_message_id:string|null, error:string|null}
     */
    public static function sendOutbound(PDO $pdo, array $opts): array
    {
        $serverToken = CompliancePostmarkConfig::serverToken();
        if ($serverToken === '') {
            return self::sendErr('POSTMARK_SERVER_TOKEN is not configured on this host.');
        }
        $fromAddress = CompliancePostmarkConfig::complianceFromAddress();
        if ($fromAddress === '') {
            return self::sendErr('COMPLIANCE_POSTMARK_FROM / COMPLIANCE_INBOX_ADDRESS is not configured.');
        }
        $stream = CompliancePostmarkConfig::outboundStream();

        // Normalise inputs.
        $to = self::normalizeAddressInput($opts['to'] ?? null);
        if ($to === array()) {
            return self::sendErr('At least one To address is required.');
        }
        $cc = self::normalizeAddressInput($opts['cc'] ?? null);
        $bcc = self::normalizeAddressInput($opts['bcc'] ?? null);
        $subject = self::nullableStr((string)($opts['subject'] ?? ''), 500);
        if ($subject === null) {
            return self::sendErr('Subject is required.');
        }
        $textBody = self::nullableStr((string)($opts['text_body'] ?? ''));
        $htmlBody = self::nullableStr((string)($opts['html_body'] ?? ''));
        if ($textBody === null && $htmlBody === null) {
            return self::sendErr('Message body is required (text or HTML).');
        }
        if ($htmlBody !== null) {
            $htmlBody = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', '', $htmlBody);
        }

        $createdBy = isset($opts['created_by']) && (int)$opts['created_by'] > 0 ? (int)$opts['created_by'] : null;
        $draftId = isset($opts['draft_id']) && (int)$opts['draft_id'] > 0 ? (int)$opts['draft_id'] : null;
        $replyToEmailId = isset($opts['reply_to_email_id']) && (int)$opts['reply_to_email_id'] > 0 ? (int)$opts['reply_to_email_id'] : null;
        $threadId = isset($opts['thread_id']) && (int)$opts['thread_id'] > 0 ? (int)$opts['thread_id'] : null;

        // Derive threading headers from the reply-target inbound email.
        $inReplyTo = null;
        $references = null;
        if ($replyToEmailId !== null) {
            $st = $pdo->prepare(
                'SELECT thread_id, message_id_header, references_header
                   FROM ipca_compliance_emails WHERE id = ? LIMIT 1'
            );
            $st->execute(array($replyToEmailId));
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($src)) {
                $inReplyTo = self::nullableStr((string)($src['message_id_header'] ?? ''));
                $refsPrev = (string)($src['references_header'] ?? '');
                $references = trim(($refsPrev !== '' ? $refsPrev . ' ' : '') . ($inReplyTo ?? ''));
                if ($references === '') {
                    $references = null;
                }
                if ($threadId === null && isset($src['thread_id']) && (int)$src['thread_id'] > 0) {
                    $threadId = (int)$src['thread_id'];
                }
            }
        }

        // Ensure the email lives on a thread.
        if ($threadId === null) {
            $subjectNorm = self::normalizeSubject($subject);
            $primaryRecipient = strtolower((string)($to[0] ?? ''));
            $thread = self::resolveOrCreateThread(
                $pdo,
                null,
                $inReplyTo,
                $references,
                $subjectNorm !== '' ? $subjectNorm : null,
                $primaryRecipient !== '' ? $primaryRecipient : null
            );
            $threadId = (int)$thread['id'];
        }

        // Generate our own RFC822 Message-Id so future inbound replies thread back.
        $host = self::messageIdHost($fromAddress);
        $ourMsgId = '<co-' . bin2hex(random_bytes(16)) . '@' . $host . '>';

        // Build Postmark payload.
        $headers = array(
            array('Name' => 'Message-ID', 'Value' => $ourMsgId),
        );
        if ($inReplyTo !== null && $inReplyTo !== '') {
            $headers[] = array('Name' => 'In-Reply-To', 'Value' => $inReplyTo);
        }
        if ($references !== null && $references !== '') {
            $headers[] = array('Name' => 'References', 'Value' => $references);
        }

        $body = array(
            'From' => $fromAddress,
            'To' => implode(', ', $to),
            'Subject' => $subject,
            'MessageStream' => $stream,
            'TrackOpens' => false,
            'TrackLinks' => 'None',
            'Headers' => $headers,
        );
        if ($cc !== array()) {
            $body['Cc'] = implode(', ', $cc);
        }
        if ($bcc !== array()) {
            $body['Bcc'] = implode(', ', $bcc);
        }
        if ($textBody !== null) {
            $body['TextBody'] = $textBody;
        }
        if ($htmlBody !== null) {
            $body['HtmlBody'] = $htmlBody;
        }

        // Pull staged draft attachments into the Postmark payload.
        $draftAttachments = array();
        if ($draftId !== null) {
            $draftAttachments = self::listDraftAttachments($pdo, $draftId);
            if ($draftAttachments !== array()) {
                try {
                    $body['Attachments'] = self::draftAttachmentsForPostmark($draftAttachments);
                } catch (Throwable $e) {
                    return self::sendErr('Attachment preparation failed: ' . $e->getMessage());
                }
            }
        }

        // POST to Postmark.
        try {
            $apiResponse = self::postmarkSendEmail($serverToken, $body);
        } catch (Throwable $e) {
            // Log a failure event but do NOT throw — the caller wants a structured result.
            self::logEvent($pdo, null, null, 'webhook_error', array(
                'scope' => 'outbound_send',
                'error' => substr($e->getMessage(), 0, 1000),
                'to' => $to,
                'subject' => $subject,
                'thread_id' => $threadId,
            ));

            return self::sendErr('Postmark API call failed: ' . $e->getMessage());
        }

        $postmarkMessageId = isset($apiResponse['MessageID']) ? (string)$apiResponse['MessageID'] : null;
        $submittedAt = isset($apiResponse['SubmittedAt']) ? self::postmarkDateToMysql((string)$apiResponse['SubmittedAt']) : null;
        $errorCode = isset($apiResponse['ErrorCode']) ? (int)$apiResponse['ErrorCode'] : 0;
        if ($errorCode !== 0) {
            self::logEvent($pdo, null, $postmarkMessageId, 'webhook_error', array(
                'scope' => 'outbound_send',
                'error_code' => $errorCode,
                'error_message' => (string)($apiResponse['Message'] ?? ''),
                'to' => $to,
            ));

            return self::sendErr('Postmark refused the send: code ' . $errorCode . ' — ' . (string)($apiResponse['Message'] ?? ''));
        }

        // Persist the outbound row.
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_emails (
                thread_id, direction, postmark_message_id, postmark_record_type,
                message_id_header, in_reply_to_header, references_header,
                from_email, from_name,
                to_json, cc_json, bcc_json,
                subject, text_body, html_body,
                headers_json, raw_payload_json,
                sent_at, status, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $threadId,
            'outbound',
            $postmarkMessageId,
            'Outbound',
            $ourMsgId,
            $inReplyTo,
            $references,
            $fromAddress,
            null,
            self::jsonEncode(self::addressListForJson($to)),
            $cc === array() ? null : self::jsonEncode(self::addressListForJson($cc)),
            $bcc === array() ? null : self::jsonEncode(self::addressListForJson($bcc)),
            $subject,
            $textBody,
            $htmlBody,
            self::jsonEncode($headers),
            self::jsonEncode($apiResponse),
            $submittedAt ?? date('Y-m-d H:i:s'),
            'sent',
            $createdBy,
        ));
        $emailId = (int)$pdo->lastInsertId();

        // Roll the thread forward.
        $pdo->prepare(
            "UPDATE ipca_compliance_email_threads
                SET last_message_at = NOW(),
                    status = CASE
                        WHEN status IN ('open','waiting_internal') THEN 'waiting_external'
                        ELSE status
                    END
              WHERE id = ?"
        )->execute(array($threadId));

        // Mark the draft sent if applicable, and carry its staged attachments
        // onto the outbound email row so the thread view shows them.
        if ($draftId !== null) {
            $pdo->prepare(
                "UPDATE ipca_compliance_email_drafts
                    SET status = 'sent', sent_email_id = ?, approved_by = ?, approved_at = NOW()
                  WHERE id = ?"
            )->execute(array($emailId, $createdBy, $draftId));
            $idx = 0;
            foreach ($draftAttachments as $da) {
                try {
                    self::carryDraftAttachmentToEmail($pdo, $emailId, $idx++, $da);
                } catch (Throwable $e) {
                    self::logEvent($pdo, $emailId, $postmarkMessageId, 'webhook_error', array(
                        'scope' => 'outbound_attachment_carry',
                        'draft_attachment_id' => (int)($da['id'] ?? 0),
                        'error' => substr($e->getMessage(), 0, 500),
                    ));
                }
            }
        }

        self::logEvent($pdo, $emailId, $postmarkMessageId, 'outbound_send', array(
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'thread_id' => $threadId,
            'in_reply_to' => $inReplyTo,
            'has_html' => $htmlBody !== null,
        ));

        ComplianceAutomationDispatch::fire($pdo, 'compliance.inbox.email_sent', array(
            'email_id' => $emailId,
            'thread_id' => $threadId,
            'to' => $to,
            'subject' => $subject,
            'postmark_message_id' => $postmarkMessageId,
            'is_reply' => $inReplyTo !== null,
        ));

        return array(
            'ok' => true,
            'email_id' => $emailId,
            'thread_id' => $threadId,
            'postmark_message_id' => $postmarkMessageId,
            'error' => null,
        );
    }

    /**
     * @param array<string,mixed> $body Postmark email payload.
     * @return array<string,mixed> Postmark JSON response.
     */
    private static function postmarkSendEmail(string $serverToken, array $body): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl extension is required to send via Postmark.');
        }
        $ch = curl_init(self::POSTMARK_SEND_URL);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        $payload = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $serverToken,
            ),
            CURLOPT_POSTFIELDS => $payload,
        ));
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Postmark HTTP transport error: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Postmark returned non-JSON (HTTP ' . $httpCode . '): ' . substr((string)$raw, 0, 240));
        }
        // Postmark returns ErrorCode=0 on success; non-2xx + ErrorCode!=0 means rejection.
        if ($httpCode >= 400 && (int)($decoded['ErrorCode'] ?? 0) === 0) {
            $decoded['ErrorCode'] = $httpCode;
        }

        return $decoded;
    }

    // ------------------------------------------------------------------
    // Postmark tracking webhook (delivery / open / click / bounce / spam)
    // ------------------------------------------------------------------

    /**
     * Apply a Postmark tracking webhook payload.
     *
     * @param array<string,mixed> $payload  RecordType-keyed Postmark webhook body.
     * @return array{action:string, event_type:string, email_id:int|null}
     */
    public static function processPostmarkEvent(PDO $pdo, array $payload): array
    {
        $recordType = (string)($payload['RecordType'] ?? '');
        $eventType = self::recordTypeToEventType($recordType);
        if ($eventType === null) {
            self::logEvent($pdo, null, null, 'webhook_error', array(
                'scope' => 'tracking_webhook',
                'error' => 'unsupported_record_type',
                'record_type' => $recordType,
            ));

            return array('action' => 'ignored', 'event_type' => $recordType, 'email_id' => null);
        }

        $postmarkMessageId = self::nullableStr((string)($payload['MessageID'] ?? ''));
        $emailId = null;
        if ($postmarkMessageId !== null) {
            $st = $pdo->prepare('SELECT id FROM ipca_compliance_emails WHERE postmark_message_id = ? LIMIT 1');
            $st->execute(array($postmarkMessageId));
            $found = (int)$st->fetchColumn();
            if ($found > 0) {
                $emailId = $found;
            }
        }

        $recipient = self::nullableStr((string)($payload['Recipient'] ?? $payload['Email'] ?? ''));
        $eventAt = self::postmarkDateToMysql((string)($payload['ReceivedAt'] ?? $payload['DeliveredAt'] ?? $payload['BouncedAt'] ?? ''));

        self::logEvent($pdo, $emailId, $postmarkMessageId, $eventType, $payload, $recipient, $eventAt);

        if ($emailId !== null) {
            self::advanceEmailStatus($pdo, $emailId, $eventType);
        }

        if ($eventType === 'bounce' || $eventType === 'spam_complaint') {
            ComplianceAutomationDispatch::fire($pdo, 'compliance.inbox.email_bounced', array(
                'email_id' => $emailId,
                'postmark_message_id' => $postmarkMessageId,
                'event_type' => $eventType,
                'recipient' => $recipient,
                'bounce_type' => (string)($payload['Type'] ?? ''),
                'description' => substr((string)($payload['Description'] ?? ''), 0, 500),
            ));
        }

        return array(
            'action' => $emailId !== null ? 'applied' : 'unmatched',
            'event_type' => $eventType,
            'email_id' => $emailId,
        );
    }

    /**
     * Advance an email's status to reflect a new event. Status only moves
     * forward (sent → delivered → opened → clicked); bounced is terminal.
     */
    private static function advanceEmailStatus(PDO $pdo, int $emailId, string $eventType): void
    {
        $rank = array(
            'queued' => 0,
            'sent' => 1,
            'delivered' => 2,
            'opened' => 3,
            'clicked' => 4,
            'bounced' => 9,
            'failed' => 9,
            'archived' => 99,
            'received' => 0,
        );
        $st = $pdo->prepare('SELECT status FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
        $st->execute(array($emailId));
        $current = (string)$st->fetchColumn();
        if ($current === '') {
            return;
        }
        $target = null;
        switch ($eventType) {
            case 'delivery':
                $target = 'delivered';
                break;
            case 'open':
                $target = 'opened';
                break;
            case 'click':
                $target = 'clicked';
                break;
            case 'bounce':
                $target = 'bounced';
                break;
            case 'spam_complaint':
                $target = null;
                break;
        }
        if ($target === null) {
            return;
        }
        // Bounced overrides everything; otherwise only upgrade.
        if ($target !== 'bounced') {
            $curR = $rank[$current] ?? 0;
            $tgtR = $rank[$target] ?? 0;
            if ($tgtR <= $curR) {
                return;
            }
        }
        $pdo->prepare('UPDATE ipca_compliance_emails SET status = ? WHERE id = ?')
            ->execute(array($target, $emailId));
    }

    private static function recordTypeToEventType(string $recordType): ?string
    {
        switch ($recordType) {
            case 'Delivery':
                return 'delivery';
            case 'Open':
                return 'open';
            case 'Click':
                return 'click';
            case 'Bounce':
                return 'bounce';
            case 'SpamComplaint':
                return 'spam_complaint';
            default:
                return null;
        }
    }

    // ------------------------------------------------------------------
    // Object linking
    // ------------------------------------------------------------------

    /**
     * Link an email and/or thread to a compliance object.
     *
     * @param array<string,mixed> $opts
     *   - email_id           int|null
     *   - thread_id          int|null
     *   - linked_object_type string  (must be in linkableObjectTypes())
     *   - linked_object_id   string
     *   - link_type          string  (must be in linkTypes())
     *   - created_by         int|null
     */
    public static function linkObject(PDO $pdo, array $opts): int
    {
        $type = (string)($opts['linked_object_type'] ?? '');
        $id = trim((string)($opts['linked_object_id'] ?? ''));
        $linkType = (string)($opts['link_type'] ?? 'context');
        $emailId = isset($opts['email_id']) && (int)$opts['email_id'] > 0 ? (int)$opts['email_id'] : null;
        $threadId = isset($opts['thread_id']) && (int)$opts['thread_id'] > 0 ? (int)$opts['thread_id'] : null;
        $createdBy = isset($opts['created_by']) && (int)$opts['created_by'] > 0 ? (int)$opts['created_by'] : null;

        if ($emailId === null && $threadId === null) {
            throw new InvalidArgumentException('email_id or thread_id is required.');
        }
        if (!array_key_exists($type, self::linkableObjectTypes())) {
            throw new InvalidArgumentException('Unsupported linked_object_type: ' . $type);
        }
        if ($id === '') {
            throw new InvalidArgumentException('linked_object_id is required.');
        }
        if (!array_key_exists($linkType, self::linkTypes())) {
            $linkType = 'context';
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_obj_links
                (email_id, thread_id, linked_object_type, linked_object_id, link_type, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array($emailId, $threadId, $type, $id, $linkType, $createdBy));

        return (int)$pdo->lastInsertId();
    }

    public static function unlinkObject(PDO $pdo, int $linkId): void
    {
        $pdo->prepare('DELETE FROM ipca_compliance_email_obj_links WHERE id = ?')
            ->execute(array($linkId));
    }

    // ------------------------------------------------------------------
    // Stage 2 helpers
    // ------------------------------------------------------------------

    /**
     * @param mixed $input  CSV string OR list of strings OR list of {Email} dicts.
     * @return list<string> trimmed, lower-cased, deduped, basic-validated email addresses.
     */
    private static function normalizeAddressInput($input): array
    {
        if ($input === null) {
            return array();
        }
        $candidates = array();
        if (is_array($input)) {
            foreach ($input as $row) {
                if (is_string($row)) {
                    $candidates[] = $row;
                } elseif (is_array($row) && isset($row['Email'])) {
                    $candidates[] = (string)$row['Email'];
                }
            }
        } else {
            $candidates = preg_split('/[,;\s]+/', (string)$input) ?: array();
        }
        $out = array();
        foreach ($candidates as $c) {
            $email = strtolower(trim((string)$c));
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if (!in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $addresses
     * @return list<array{Email:string,Name:string,MailboxHash:null}>
     */
    private static function addressListForJson(array $addresses): array
    {
        $out = array();
        foreach ($addresses as $a) {
            $out[] = array('Email' => $a, 'Name' => '', 'MailboxHash' => null);
        }

        return $out;
    }

    /**
     * Derive a sensible hostname for our outbound Message-Id from the
     * configured "from" address (e.g. compliance@ipca.training → ipca.training).
     */
    private static function messageIdHost(string $fromAddress): string
    {
        $at = strrpos($fromAddress, '@');
        if ($at === false) {
            return 'ipca.training';
        }
        $host = substr($fromAddress, $at + 1);

        return $host !== '' ? $host : 'ipca.training';
    }

    /**
     * @return array{ok:false, email_id:null, thread_id:null, postmark_message_id:null, error:string}
     */
    private static function sendErr(string $msg): array
    {
        return array(
            'ok' => false,
            'email_id' => null,
            'thread_id' => null,
            'postmark_message_id' => null,
            'error' => $msg,
        );
    }

    // ==================================================================
    //                    STAGE 2+: ATTACHMENTS
    // ==================================================================

    /**
     * Store an uploaded file as a draft attachment. The actual bytes go to
     * Spaces (with local fallback, mirroring inbound attachments). Returns
     * the new draft_attachment row id.
     *
     * @param array{name:string,type?:string,tmp_name:string,size:int,error:int} $upload
     *        Typical $_FILES['attachment'] element. The caller is responsible
     *        for the $_FILES envelope check; we validate the $upload itself.
     * @param int $createdBy
     */
    public static function attachToDraft(PDO $pdo, int $draftId, array $upload, ?int $createdBy = null): int
    {
        $draft = self::getDraft($pdo, $draftId);
        if ($draft === null) {
            throw new RuntimeException('Draft not found.');
        }
        if ((string)$draft['status'] !== 'draft') {
            throw new RuntimeException('Only drafts in status=draft can receive attachments.');
        }

        $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error code ' . $err . '.');
        }
        $name = (string)($upload['name'] ?? '');
        $tmpName = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Upload payload missing or not from POST.');
        }
        if ($size <= 0) {
            throw new RuntimeException('Attachment is empty.');
        }
        if ($size > self::MAX_ATTACHMENT_BYTES) {
            throw new RuntimeException('Attachment exceeds maximum size (' . $size . ' bytes).');
        }
        $safeName = self::sanitizeFilename($name);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            throw new RuntimeException('Attachment extension blocked by policy: ' . $ext);
        }

        $bytes = file_get_contents($tmpName);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Failed to read upload from disk.');
        }
        $sha256 = hash('sha256', $bytes);
        $contentType = self::nullableStr((string)($upload['type'] ?? ''), 191);

        $yyyy = date('Y');
        $mm = date('m');
        $relKey = 'compliance/drafts/' . $yyyy . '/' . $mm . '/' . $draftId . '/' . substr($sha256, 0, 12) . '-' . $safeName;

        $disk = 'spaces';
        $publicUrl = null;
        try {
            $up = self::uploadToSpaces($relKey, $bytes, $contentType ?? 'application/octet-stream');
            $publicUrl = (string)($up['cdn_url'] ?? '');
        } catch (Throwable) {
            $disk = 'local';
            $localPath = self::storageRoot() . '/' . $relKey;
            $dir = dirname($localPath);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create local attachment dir: ' . $dir);
            }
            if (file_put_contents($localPath, $bytes) === false) {
                throw new RuntimeException('Could not write local attachment file.');
            }
            $publicUrl = null;
        }

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_draft_attachments
                (draft_id, original_filename, content_type, size_bytes,
                 storage_disk, storage_key, public_url, sha256, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $draftId,
            substr($safeName, 0, 255),
            $contentType,
            $size,
            $disk,
            $relKey,
            $publicUrl,
            $sha256,
            $createdBy !== null && $createdBy > 0 ? $createdBy : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listDraftAttachments(PDO $pdo, int $draftId): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_email_draft_attachments
              WHERE draft_id = ? ORDER BY id ASC'
        );
        $st->execute(array($draftId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    public static function removeDraftAttachment(PDO $pdo, int $attId): void
    {
        // We deliberately do NOT delete the underlying Spaces/local file. If
        // the same content (sha256) is later attached to another draft we
        // reuse it; the orphaned bytes are negligible and we never want to
        // accidentally void evidence on a parallel send.
        $pdo->prepare('DELETE FROM ipca_compliance_email_draft_attachments WHERE id = ?')
            ->execute(array($attId));
    }

    /**
     * Read draft-attachment bytes back from whichever disk they live on.
     * Returns null if the disk path / Spaces object can't be found.
     */
    private static function readDraftAttachmentBytes(array $attRow): ?string
    {
        $disk = (string)($attRow['storage_disk'] ?? 'spaces');
        $key = (string)($attRow['storage_key'] ?? '');
        if ($key === '') {
            return null;
        }
        if ($disk === 'local') {
            $path = self::storageRoot() . '/' . $key;
            if (!is_file($path)) {
                return null;
            }
            $bytes = @file_get_contents($path);

            return $bytes === false ? null : $bytes;
        }
        // Spaces fetch via the existing helper if available.
        $spacesHelper = __DIR__ . '/../spaces.php';
        if (is_file($spacesHelper)) {
            require_once $spacesHelper;
            if (function_exists('cw_spaces_get_object')) {
                try {
                    $resp = cw_spaces_get_object($key);
                    if (is_array($resp) && isset($resp['body'])) {
                        return (string)$resp['body'];
                    }
                    if (is_string($resp)) {
                        return $resp;
                    }
                } catch (Throwable) {
                    // fall through to URL fetch
                }
            }
        }
        // Last-ditch: HTTP fetch via the public_url if the CDN gave us one.
        $url = (string)($attRow['public_url'] ?? '');
        if ($url !== '' && function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_CONNECTTIMEOUT => 8,
                ));
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($raw !== false && $code >= 200 && $code < 300) {
                    return (string)$raw;
                }
            }
        }

        return null;
    }

    /**
     * Convert a draft attachment to an outbound-email attachment row,
     * reusing the same storage location (no re-upload). Returns the new
     * email_attachment row id.
     */
    private static function carryDraftAttachmentToEmail(PDO $pdo, int $emailId, int $index, array $draftAtt): int
    {
        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_email_attachments (
                email_id, original_filename, content_type, size_bytes,
                postmark_attachment_index, storage_disk,
                storage_key, public_url, sha256, is_inline
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $emailId,
            substr((string)$draftAtt['original_filename'], 0, 255),
            isset($draftAtt['content_type']) ? (string)$draftAtt['content_type'] : null,
            isset($draftAtt['size_bytes']) ? (int)$draftAtt['size_bytes'] : null,
            $index,
            (string)$draftAtt['storage_disk'],
            (string)$draftAtt['storage_key'],
            isset($draftAtt['public_url']) ? (string)$draftAtt['public_url'] : null,
            isset($draftAtt['sha256']) ? (string)$draftAtt['sha256'] : null,
            0,
        ));

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param list<array<string,mixed>> $draftAttachments
     * @return list<array{Name:string,Content:string,ContentType:string}>
     */
    private static function draftAttachmentsForPostmark(array $draftAttachments): array
    {
        $out = array();
        foreach ($draftAttachments as $att) {
            $bytes = self::readDraftAttachmentBytes($att);
            if ($bytes === null) {
                throw new RuntimeException(
                    'Could not read attachment "' . (string)$att['original_filename'] . '" back from storage.'
                );
            }
            $out[] = array(
                'Name' => (string)$att['original_filename'],
                'Content' => base64_encode($bytes),
                'ContentType' => (string)($att['content_type'] ?? 'application/octet-stream'),
            );
        }

        return $out;
    }

    // ==================================================================
    //                    STAGE 2+: SEARCH
    // ==================================================================

    /**
     * Full-text search across email subject + body. Falls back to a LIKE
     * scan if the FULLTEXT index isn't applied yet (so the migration is
     * optional from a runtime perspective).
     *
     * @return list<array<string,mixed>> ipca_compliance_emails rows joined
     *         with their thread.
     */
    public static function searchEmails(PDO $pdo, string $query, int $limit = 100): array
    {
        $q = trim($query);
        if ($q === '') {
            return array();
        }
        $limit = max(1, min(500, $limit));

        // Try FULLTEXT first (boolean mode so a multi-word query works as
        // an implicit AND-style match).
        try {
            $sql = 'SELECT e.id, e.thread_id, e.direction, e.status, e.from_email,
                           e.subject, e.received_at, e.sent_at, e.postmark_message_id,
                           t.subject_normalized AS thread_subject,
                           t.primary_contact_email AS thread_contact,
                           t.status AS thread_status,
                           MATCH(e.subject, e.text_body) AGAINST (? IN BOOLEAN MODE) AS relevance
                      FROM ipca_compliance_emails e
                 LEFT JOIN ipca_compliance_email_threads t ON t.id = e.thread_id
                     WHERE MATCH(e.subject, e.text_body) AGAINST (? IN BOOLEAN MODE)
                  ORDER BY relevance DESC, COALESCE(e.received_at, e.sent_at) DESC
                     LIMIT ' . $limit;
            $boolQuery = self::buildFulltextBooleanQuery($q);
            $st = $pdo->prepare($sql);
            $st->execute(array($boolQuery, $boolQuery));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows) && $rows !== array()) {
                return $rows;
            }
        } catch (Throwable) {
            // FULLTEXT index missing — fall through to LIKE scan.
        }

        // LIKE fallback.
        try {
            $like = '%' . $q . '%';
            $sql = 'SELECT e.id, e.thread_id, e.direction, e.status, e.from_email,
                           e.subject, e.received_at, e.sent_at, e.postmark_message_id,
                           t.subject_normalized AS thread_subject,
                           t.primary_contact_email AS thread_contact,
                           t.status AS thread_status,
                           NULL AS relevance
                      FROM ipca_compliance_emails e
                 LEFT JOIN ipca_compliance_email_threads t ON t.id = e.thread_id
                     WHERE e.subject LIKE ? OR e.text_body LIKE ? OR e.from_email LIKE ?
                  ORDER BY COALESCE(e.received_at, e.sent_at) DESC
                     LIMIT ' . $limit;
            $st = $pdo->prepare($sql);
            $st->execute(array($like, $like, $like));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : array();
        } catch (Throwable) {
            return array();
        }
    }

    private static function buildFulltextBooleanQuery(string $q): string
    {
        // Tokenise, strip operators MySQL reserves, require each token with a
        // '+' prefix and append '*' for prefix matching. Keeps the syntax
        // safe even if the user types Postmark MessageIDs or hyphenated words.
        $clean = (string)preg_replace('/[+\-<>~()@*"]/u', ' ', $q);
        $tokens = preg_split('/\s+/u', trim($clean));
        if ($tokens === false) {
            return $q;
        }
        $tokens = array_filter($tokens, static fn($t) => $t !== '' && mb_strlen($t) >= 2);
        if ($tokens === array()) {
            return $q;
        }
        $out = array();
        foreach ($tokens as $tok) {
            $out[] = '+' . $tok . '*';
        }

        return implode(' ', $out);
    }

    // ==================================================================
    //                STAGE 2+: BULK THREAD OPERATIONS
    // ==================================================================

    /**
     * @param list<int> $threadIds
     */
    public static function bulkUpdateThreadStatus(PDO $pdo, array $threadIds, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $threadIds), static fn($i) => $i > 0));
        if ($ids === array()) {
            return 0;
        }
        if (!in_array($status, array('open','waiting_internal','waiting_external','closed','archived'), true)) {
            throw new InvalidArgumentException('Unsupported status: ' . $status);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE ipca_compliance_email_threads SET status = ? WHERE id IN (' . $placeholders . ')';
        $st = $pdo->prepare($sql);
        $args = array_merge(array($status), $ids);
        $st->execute($args);

        return (int)$st->rowCount();
    }

    /**
     * @param list<int> $threadIds
     */
    public static function bulkUpdateThreadPriority(PDO $pdo, array $threadIds, string $priority): int
    {
        $ids = array_values(array_filter(array_map('intval', $threadIds), static fn($i) => $i > 0));
        if ($ids === array()) {
            return 0;
        }
        if (!in_array($priority, array('low','normal','high','urgent'), true)) {
            throw new InvalidArgumentException('Unsupported priority: ' . $priority);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE ipca_compliance_email_threads SET priority = ? WHERE id IN (' . $placeholders . ')';
        $st = $pdo->prepare($sql);
        $args = array_merge(array($priority), $ids);
        $st->execute($args);

        return (int)$st->rowCount();
    }

    // ==================================================================
    //          STAGE 2+: COMMS PER COMPLIANCE OBJECT
    // ==================================================================

    /**
     * List the inbound/outbound emails linked to a given compliance object,
     * either directly (via email_id) OR via a thread the object is linked to.
     *
     * @return list<array<string,mixed>>
     */
    public static function listEmailsForObject(PDO $pdo, string $linkedObjectType, string $linkedObjectId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT DISTINCT e.id, e.thread_id, e.direction, e.status, e.from_email,
                       e.subject, e.received_at, e.sent_at, e.postmark_message_id,
                       t.subject_normalized AS thread_subject,
                       t.primary_contact_email AS thread_contact,
                       t.status AS thread_status,
                       (
                         SELECT GROUP_CONCAT(DISTINCT l2.link_type ORDER BY l2.link_type SEPARATOR ',')
                           FROM ipca_compliance_email_obj_links l2
                          WHERE l2.linked_object_type = ?
                            AND l2.linked_object_id = ?
                            AND (l2.email_id = e.id OR (l2.email_id IS NULL AND l2.thread_id = e.thread_id))
                       ) AS link_roles
                  FROM ipca_compliance_emails e
             LEFT JOIN ipca_compliance_email_threads t ON t.id = e.thread_id
                 WHERE EXISTS (
                    SELECT 1
                      FROM ipca_compliance_email_obj_links l
                     WHERE l.linked_object_type = ?
                       AND l.linked_object_id = ?
                       AND (l.email_id = e.id OR (l.email_id IS NULL AND l.thread_id = e.thread_id))
                 )
              ORDER BY COALESCE(e.received_at, e.sent_at, e.created_at) DESC
                 LIMIT " . $limit;
        $st = $pdo->prepare($sql);
        $st->execute(array($linkedObjectType, $linkedObjectId, $linkedObjectType, $linkedObjectId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Build a human-friendly grouped option list of every compliance object
     * the operator might want to link this email/thread to. Used to back the
     * combined picker on the thread view's "Linked compliance objects" form.
     *
     * Returns:
     *   list<array{
     *     type:string,          // matches linkableObjectTypes() machine value
     *     type_label:string,    // group <optgroup> label
     *     options:list<array{
     *       id:string,          // string-encoded id (used as linked_object_id)
     *       label:string,       // human label e.g. "FND-014 — Bird strike (OPEN)"
     *     }>
     *   }>
     *
     * Each query is wrapped in try/catch so a missing table degrades to an
     * empty group without breaking the picker.
     */
    public static function listLinkablePickerOptions(PDO $pdo, int $limitPerType = 200): array
    {
        $limitPerType = max(1, min(500, $limitPerType));
        $groups = array();

        // ---- Findings ------------------------------------------------------
        try {
            $st = $pdo->query(
                "SELECT id, finding_code, title, status
                   FROM ipca_compliance_findings
                  ORDER BY updated_at DESC, id DESC
                  LIMIT " . $limitPerType
            );
            $opts = array();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $r) {
                $opts[] = array(
                    'id' => (string)(int)$r['id'],
                    'label' => self::pickerLabel((string)$r['finding_code'], (string)$r['title'], (string)$r['status']),
                );
            }
            if ($opts !== array()) {
                $groups[] = array('type' => 'finding', 'type_label' => 'Findings', 'options' => $opts);
            }
        } catch (Throwable) { /* table absent */ }

        // ---- Audits --------------------------------------------------------
        try {
            $st = $pdo->query(
                "SELECT id, audit_code, title, status
                   FROM ipca_compliance_audits
                  ORDER BY updated_at DESC, id DESC
                  LIMIT " . $limitPerType
            );
            $opts = array();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $r) {
                $opts[] = array(
                    'id' => (string)(int)$r['id'],
                    'label' => self::pickerLabel((string)$r['audit_code'], (string)$r['title'], (string)$r['status']),
                );
            }
            if ($opts !== array()) {
                $groups[] = array('type' => 'audit', 'type_label' => 'Audits', 'options' => $opts);
            }
        } catch (Throwable) { /* table absent */ }

        // ---- Corrective actions -------------------------------------------
        try {
            $st = $pdo->query(
                "SELECT id, action_code, title, status
                   FROM ipca_compliance_corrective_actions
                  ORDER BY updated_at DESC, id DESC
                  LIMIT " . $limitPerType
            );
            $opts = array();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $r) {
                $opts[] = array(
                    'id' => (string)(int)$r['id'],
                    'label' => self::pickerLabel((string)$r['action_code'], (string)$r['title'], (string)$r['status']),
                );
            }
            if ($opts !== array()) {
                $groups[] = array('type' => 'corrective_action', 'type_label' => 'Corrective Actions', 'options' => $opts);
            }
        } catch (Throwable) { /* table absent */ }

        // ---- Manual change requests ---------------------------------------
        try {
            $st = $pdo->query(
                "SELECT id, request_code, title, status
                   FROM ipca_compliance_manual_change_requests
                  ORDER BY updated_at DESC, id DESC
                  LIMIT " . $limitPerType
            );
            $opts = array();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $r) {
                $opts[] = array(
                    'id' => (string)(int)$r['id'],
                    'label' => self::pickerLabel((string)$r['request_code'], (string)$r['title'], (string)$r['status']),
                );
            }
            if ($opts !== array()) {
                $groups[] = array('type' => 'manual_change_request', 'type_label' => 'Manual Change Requests', 'options' => $opts);
            }
        } catch (Throwable) { /* table absent */ }

        // ---- Compliance cases (MoC and similar) ---------------------------
        try {
            $st = $pdo->query(
                "SELECT id, case_code, title, status, case_type
                   FROM ipca_compliance_cases
                  ORDER BY updated_at DESC, id DESC
                  LIMIT " . $limitPerType
            );
            $opts = array();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $r) {
                $caseType = (string)($r['case_type'] ?? '');
                $statusLabel = trim($caseType . ' / ' . (string)$r['status'], ' /');
                $opts[] = array(
                    'id' => (string)(int)$r['id'],
                    'label' => self::pickerLabel((string)$r['case_code'], (string)$r['title'], $statusLabel),
                );
            }
            if ($opts !== array()) {
                $groups[] = array('type' => 'compliance_case', 'type_label' => 'Cases / MoC', 'options' => $opts);
            }
        } catch (Throwable) { /* table absent */ }

        // ---- Meetings ------------------------------------------------------
        try {
            $st = $pdo->query(
                "SELECT id, title, scheduled_start, status
                   FROM ipca_compliance_meetings
                  ORDER BY scheduled_start DESC, id DESC
                  LIMIT " . $limitPerType
            );
            $opts = array();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $r) {
                $when = trim((string)($r['scheduled_start'] ?? ''));
                $code = $when !== '' ? substr($when, 0, 10) : ('MTG-' . (int)$r['id']);
                $opts[] = array(
                    'id' => (string)(int)$r['id'],
                    'label' => self::pickerLabel($code, (string)$r['title'], (string)($r['status'] ?? '')),
                );
            }
            if ($opts !== array()) {
                $groups[] = array('type' => 'meeting', 'type_label' => 'Meetings', 'options' => $opts);
            }
        } catch (Throwable) { /* table absent */ }

        return $groups;
    }

    /**
     * Threads list for the per-object "Attach existing thread" picker.
     *
     * @return list<array{id:int,label:string}>
     */
    public static function listThreadsForPicker(PDO $pdo, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $st = $pdo->query(
                "SELECT t.id, t.subject_normalized, t.primary_contact_email, t.status,
                        t.last_message_at,
                        (SELECT e.subject FROM ipca_compliance_emails e
                          WHERE e.thread_id = t.id ORDER BY e.id DESC LIMIT 1) AS last_subject
                   FROM ipca_compliance_email_threads t
                  ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC
                  LIMIT " . $limit
            );
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
        } catch (Throwable) {
            return array();
        }
        $out = array();
        foreach ($rows as $r) {
            $tid = (int)$r['id'];
            $subject = trim((string)($r['last_subject'] ?? '')) !== ''
                ? (string)$r['last_subject']
                : (string)($r['subject_normalized'] ?? '(no subject)');
            $contact = (string)($r['primary_contact_email'] ?? '');
            $when = substr((string)($r['last_message_at'] ?? ''), 0, 10);
            $label = '#' . $tid . ' · ' . self::truncate($subject, 60);
            if ($contact !== '') {
                $label .= ' — ' . $contact;
            }
            if ($when !== '') {
                $label .= ' (' . $when . ')';
            }
            $out[] = array('id' => $tid, 'label' => $label);
        }

        return $out;
    }

    private static function pickerLabel(string $code, string $title, string $status): string
    {
        $code = trim($code);
        $title = self::truncate(trim($title), 72);
        $parts = array();
        if ($code !== '') {
            $parts[] = $code;
        }
        if ($title !== '') {
            $parts[] = $title;
        }
        $main = implode(' — ', $parts);
        $status = trim($status);
        if ($status !== '') {
            $main .= ' (' . $status . ')';
        }

        return $main !== '' ? $main : '(unnamed)';
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1) . '…';
    }

    /**
     * Lightweight stat for a list-page badge ("3 comms"). One COUNT scan.
     */
    public static function countEmailsForObject(PDO $pdo, string $linkedObjectType, string $linkedObjectId): int
    {
        $sql = "SELECT COUNT(DISTINCT e.id)
                  FROM ipca_compliance_emails e
                  JOIN ipca_compliance_email_obj_links l
                    ON (l.email_id = e.id OR (l.email_id IS NULL AND l.thread_id = e.thread_id))
                 WHERE l.linked_object_type = ? AND l.linked_object_id = ?";
        try {
            $st = $pdo->prepare($sql);
            $st->execute(array($linkedObjectType, $linkedObjectId));

            return (int)$st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
