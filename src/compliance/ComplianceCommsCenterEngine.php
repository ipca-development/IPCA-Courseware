<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceAutomationDispatch.php';

/**
 * Phase 8 — Compliance Communications Center engine.
 *
 * Stage 1 surface area (inbound only):
 *   - ingestPostmarkInbound()        primary entry called from the webhook.
 *   - resolveOrCreateThread()        thread matcher: in-reply-to → references
 *                                    → mailbox-hash → subject+contact → new.
 *   - storeInboundEmail()            dedup-aware INSERT into ipca_compliance_emails.
 *   - storeAttachment()              base64 decode + sha256 + Spaces upload
 *                                    (local fallback) + DB row.
 *   - logEvent()                     ipca_compliance_email_events row.
 *
 * Stage 2 (outbound) will add sendOutbound() etc; we deliberately leave those
 * methods out until the inbound path is proven in production.
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
}
