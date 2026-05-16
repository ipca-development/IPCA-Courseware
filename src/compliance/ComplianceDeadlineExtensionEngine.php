<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceApprovalEngine.php';
require_once __DIR__ . '/ComplianceApprovalTokenService.php';
require_once __DIR__ . '/ComplianceAuditEngine.php';
require_once __DIR__ . '/ComplianceCaseEvents.php';
require_once __DIR__ . '/ComplianceCommsCenterEngine.php';

final class ComplianceDeadlineExtensionEngine
{
    public const WARNING_THRESHOLD_DAYS = 10;

    /** @return array<string,bool> */
    public static function workflowTableStatus(PDO $pdo): array
    {
        return array(
            'batches' => self::tablePresent($pdo, 'ipca_compliance_corrective_action_deadline_extension_batches'),
            'items' => self::tablePresent($pdo, 'ipca_compliance_corrective_action_deadline_extension_items'),
            'tokens' => ComplianceApprovalTokenService::tablePresent($pdo),
        );
    }

    public static function rcaCapTablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_rca_cap_deadline_extensions LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function capTablePresent(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM ipca_compliance_corrective_action_deadline_extensions LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function latestWorkflowItemsByAction(PDO $pdo): array
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_corrective_action_deadline_extension_items')) {
            return array();
        }
        $rows = self::rows($pdo, "
            SELECT i.*, b.request_reference, b.status AS batch_status
              FROM ipca_compliance_corrective_action_deadline_extension_items i
              JOIN (
                    SELECT corrective_action_id, MAX(id) AS max_id
                      FROM ipca_compliance_corrective_action_deadline_extension_items
                     GROUP BY corrective_action_id
              ) latest ON latest.max_id = i.id
         LEFT JOIN ipca_compliance_corrective_action_deadline_extension_batches b ON b.id = i.batch_id
        ");
        return $rows;
    }

    /** @param list<array<string,mixed>> $items @return array<int,array<string,mixed>> */
    public static function indexItemsByAction(array $items): array
    {
        $out = array();
        foreach ($items as $item) {
            $out[(int)$item['corrective_action_id']] = $item;
        }
        return $out;
    }

    public static function cancelOrphanedPendingEmailBatches(PDO $pdo, int $userId): int
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_corrective_action_deadline_extension_batches')
            || !self::tablePresent($pdo, 'ipca_compliance_corrective_action_deadline_extension_items')) {
            return 0;
        }
        try {
            $st = $pdo->query(
                "SELECT b.id, b.audit_id, b.request_reference, b.email_draft_id
                   FROM ipca_compliance_corrective_action_deadline_extension_batches b
              LEFT JOIN ipca_compliance_email_drafts d ON d.id = b.email_draft_id
                  WHERE b.status IN ('draft','submitted','under_review')
                    AND b.outbound_email_id IS NULL
                    AND (
                        b.email_draft_id IS NULL
                        OR d.id IS NULL
                        OR d.status <> 'draft'
                    )"
            );
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            if (!is_array($rows) || $rows === array()) {
                return 0;
            }
            $ids = array_map(static fn($r): int => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare(
                "UPDATE ipca_compliance_corrective_action_deadline_extension_batches
                    SET status = 'cancelled',
                        review_notes = COALESCE(review_notes, 'Cancelled because no active outbound draft email is available for review.'),
                        locked_at = COALESCE(locked_at, NOW()),
                        updated_at = NOW()
                  WHERE id IN (" . $placeholders . ")"
            )->execute($ids);
            $pdo->prepare(
                "UPDATE ipca_compliance_corrective_action_deadline_extension_items
                    SET status = 'cancelled',
                        review_notes = COALESCE(review_notes, 'Cancelled because no active outbound draft email is available for review.'),
                        locked_at = COALESCE(locked_at, NOW()),
                        updated_at = NOW()
                  WHERE batch_id IN (" . $placeholders . ")
                    AND status IN ('draft','submitted')"
            )->execute($ids);
            if (ComplianceApprovalTokenService::tablePresent($pdo)) {
                $pdo->prepare(
                    "UPDATE ipca_compliance_public_approval_tokens
                        SET revoked_at = COALESCE(revoked_at, NOW())
                      WHERE token_type = 'deadline_extension'
                        AND batch_id IN (" . $placeholders . ")
                        AND revoked_at IS NULL"
                )->execute($ids);
            }
            foreach ($rows as $row) {
                self::logBatchEvent(
                    $pdo,
                    (int)($row['audit_id'] ?? 0),
                    (int)$row['id'],
                    'deadline_extension_cancelled',
                    $userId > 0 ? $userId : null,
                    'Deadline extension request cancelled because no active outbound draft exists: ' . (string)($row['request_reference'] ?? ('#' . (int)$row['id'])),
                    null,
                    array('email_draft_id' => isset($row['email_draft_id']) ? (int)$row['email_draft_id'] : null)
                );
            }
            return count($ids);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{state:string,label:string,days:int|null,item:array<string,mixed>|null} */
    public static function calculateDeadlineStatus(?string $deadline, ?array $latestItem = null): array
    {
        if ($latestItem !== null) {
            $status = (string)($latestItem['status'] ?? '');
            if ($status === 'submitted' || $status === 'draft') {
                return array('state' => 'extension_pending', 'label' => 'Extension Pending', 'days' => null, 'item' => $latestItem);
            }
            if ($status === 'approved') {
                return array('state' => 'extension_approved', 'label' => 'Extension Approved', 'days' => null, 'item' => $latestItem);
            }
            if ($status === 'rejected') {
                return array('state' => 'extension_rejected', 'label' => 'Extension Rejected', 'days' => null, 'item' => $latestItem);
            }
        }

        $deadline = $deadline !== null ? substr(trim($deadline), 0, 10) : '';
        if ($deadline === '') {
            return array('state' => 'none', 'label' => 'No approved deadline', 'days' => null, 'item' => null);
        }
        $today = new DateTimeImmutable('today');
        $due = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
        if (!$due) {
            return array('state' => 'none', 'label' => 'No approved deadline', 'days' => null, 'item' => null);
        }
        $days = (int)$today->diff($due)->format('%r%a');
        if ($days < 0) {
            return array('state' => 'overdue', 'label' => abs($days) . ' days overdue', 'days' => $days, 'item' => null);
        }
        if ($days <= self::WARNING_THRESHOLD_DAYS) {
            return array('state' => 'warning', 'label' => $days . ' days remaining', 'days' => $days, 'item' => null);
        }
        return array('state' => 'normal', 'label' => $days . ' days remaining', 'days' => $days, 'item' => null);
    }

    /** @param list<array<string,mixed>> $items @return array{batch_id:int,token:string,token_id:int,review_url:string} */
    public static function createExtensionBatch(PDO $pdo, array $items, array $data, int $userId): array
    {
        $status = self::workflowTableStatus($pdo);
        if (!$status['batches'] || !$status['items'] || !$status['tokens']) {
            throw new RuntimeException('Deadline extension workflow tables are not installed.');
        }
        if ($items === array()) {
            throw new InvalidArgumentException('Select at least one corrective action.');
        }
        $recipientEmail = trim((string)($data['recipient_email'] ?? ''));

        $caps = array();
        $auditId = 0;
        foreach ($items as $item) {
            $capId = (int)($item['corrective_action_id'] ?? 0);
            $requested = substr(trim((string)($item['requested_deadline'] ?? '')), 0, 10);
            $explanation = trim((string)($item['explanation'] ?? ''));
            if ($capId <= 0 || $requested === '' || $explanation === '') {
                throw new InvalidArgumentException('Every selected action needs a proposed deadline and explanation.');
            }
            $cap = self::correctiveActionContext($pdo, $capId);
            if ($cap === null) {
                throw new RuntimeException('Corrective action not found.');
            }
            if (in_array(strtoupper((string)$cap['status']), array('CLOSED','VERIFIED','CANCELLED','COMPLETED','EXECUTED'), true)) {
                throw new RuntimeException('Closed corrective actions cannot be included.');
            }
            $previous = substr((string)($cap['due_date'] ?? ''), 0, 10);
            if ($previous === '') {
                throw new RuntimeException('Corrective action ' . (string)$cap['action_code'] . ' has no approved deadline.');
            }
            if (strtotime($requested) <= strtotime($previous)) {
                throw new InvalidArgumentException('Requested deadline must be later than current approved deadline for ' . (string)$cap['action_code'] . '.');
            }
            if (self::pendingWorkflowItemExists($pdo, $capId)) {
                throw new RuntimeException('Corrective action ' . (string)$cap['action_code'] . ' already has a pending extension.');
            }
            $rowAuditId = (int)($cap['audit_id'] ?? 0);
            if ($rowAuditId <= 0) {
                throw new RuntimeException('Corrective action ' . (string)$cap['action_code'] . ' is not linked to an audit.');
            }
            if ($auditId === 0) {
                $auditId = $rowAuditId;
            } elseif ($auditId !== $rowAuditId) {
                throw new RuntimeException('All selected corrective actions must belong to the same audit.');
            }
            $cap['_requested_deadline'] = $requested;
            $cap['_explanation'] = $explanation;
            $cap['_explanation_category'] = trim((string)($item['explanation_category'] ?? ''));
            $caps[] = $cap;
        }
        $recipientFallback = filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) !== false ? $recipientEmail : '';
        $primaryRecipients = self::draftRecipientsForAudit($pdo, $auditId, $recipientFallback, $userId)['to'];
        if ($primaryRecipients === array()) {
            throw new InvalidArgumentException('A lead auditor e-mail or valid fallback reviewer e-mail is required.');
        }
        $recipientEmail = $primaryRecipients[0];

        $pdo->beginTransaction();
        try {
            $reference = self::generateBatchReference($pdo);
            $st = $pdo->prepare(
                'INSERT INTO ipca_compliance_corrective_action_deadline_extension_batches
                    (audit_id, request_reference, request_type, status, recipient_email, recipient_name,
                     summary_explanation, submitted_at, submitted_by, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)'
            );
            $st->execute(array(
                $auditId,
                $reference,
                (string)($data['request_type'] ?? 'authority') === 'internal' ? 'internal' : 'authority',
                'submitted',
                $recipientEmail,
                trim((string)($data['recipient_name'] ?? '')) !== '' ? trim((string)$data['recipient_name']) : null,
                trim((string)($data['summary_explanation'] ?? '')) !== '' ? trim((string)$data['summary_explanation']) : null,
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ));
            $batchId = (int)$pdo->lastInsertId();
            $itemSt = $pdo->prepare(
                'INSERT INTO ipca_compliance_corrective_action_deadline_extension_items
                    (batch_id, corrective_action_id, finding_id, previous_approved_deadline, requested_deadline,
                     explanation_category, explanation, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($caps as $cap) {
                $itemSt->execute(array(
                    $batchId,
                    (int)$cap['id'],
                    (int)$cap['finding_id'],
                    substr((string)$cap['due_date'], 0, 10),
                    (string)$cap['_requested_deadline'],
                    (string)$cap['_explanation_category'] !== '' ? (string)$cap['_explanation_category'] : null,
                    (string)$cap['_explanation'],
                    'submitted',
                ));
            }

            $token = ComplianceApprovalTokenService::createToken($pdo, array(
                'token_type' => 'deadline_extension',
                'batch_id' => $batchId,
                'audit_id' => $auditId,
                'recipient_email' => $recipientEmail,
                'recipient_name' => (string)($data['recipient_name'] ?? ''),
            ), $userId);

            self::logBatchEvent($pdo, $auditId, $batchId, 'deadline_extension_submitted', $userId, 'Deadline extension request submitted: ' . $reference, null, array('items' => count($caps)));
            self::logBatchEvent($pdo, $auditId, $batchId, 'deadline_extension_token_created', $userId, 'Secure review token created: ' . $reference, null, array('token_id' => $token['id']));

            $pdo->commit();
            return array(
                'batch_id' => $batchId,
                'token' => $token['token'],
                'token_id' => $token['id'],
                'review_url' => self::reviewUrl($token['token']),
            );
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public static function batchContext(PDO $pdo, int $batchId): array
    {
        $st = $pdo->prepare(
            'SELECT b.*, a.audit_code, a.title AS audit_title, a.authority, a.start_date, a.end_date
               FROM ipca_compliance_corrective_action_deadline_extension_batches b
          LEFT JOIN ipca_compliance_audits a ON a.id = b.audit_id
              WHERE b.id = ? LIMIT 1'
        );
        $st->execute(array($batchId));
        $batch = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($batch)) {
            throw new RuntimeException('Deadline extension request not found.');
        }
        $items = self::batchItems($pdo, $batchId);
        return array('batch' => $batch, 'items' => $items, 'email' => self::emailDraftForBatch($batch, $items, ''));
    }

    /** @return array<string,string> */
    public static function emailDraftForBatch(array $batch, array $items, string $reviewUrl): array
    {
        $auditRef = (string)($batch['audit_code'] ?? ('Audit #' . (int)($batch['audit_id'] ?? 0)));
        $lines = array();
        $current = array();
        $requested = array();
        foreach ($items as $item) {
            $lines[] = '- ' . (string)$item['action_code'] . ' / ' . (string)$item['finding_code']
                . ': current ' . substr((string)$item['previous_approved_deadline'], 0, 10)
                . ', requested ' . substr((string)$item['requested_deadline'], 0, 10)
                . ' — ' . (string)$item['explanation'];
            $current[] = substr((string)$item['previous_approved_deadline'], 0, 10);
            $requested[] = substr((string)$item['requested_deadline'], 0, 10);
        }
        sort($current);
        sort($requested);
        $body = "Dear " . ((string)($batch['recipient_name'] ?? '') !== '' ? (string)$batch['recipient_name'] : 'Reviewer') . ",\n\n"
            . "A deadline extension request has been submitted for corrective actions related to Audit " . $auditRef . ".\n\n"
            . "Summary:\n"
            . "- Audit: " . $auditRef . " - " . (string)($batch['audit_title'] ?? '') . "\n"
            . "- Number of corrective actions: " . count($items) . "\n"
            . "- Current earliest deadline: " . ($current[0] ?? 'n/a') . "\n"
            . "- Requested latest deadline: " . ($requested !== array() ? end($requested) : 'n/a') . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Please review the request using the secure link below:\n\n"
            . $reviewUrl . "\n\n"
            . "The review page contains the relevant audit, findings, corrective actions, and extension explanations.\n\n"
            . "Sincerely,\n\nCompliance Monitoring\n";
        return array(
            'subject' => 'Deadline Extension Request - Audit ' . $auditRef,
            'body' => $body,
        );
    }

    /** @return array{draft_id:int,subject:string,body:string,review_url:string} */
    public static function createEmailDraftForBatch(PDO $pdo, int $batchId, string $reviewUrl, int $userId): array
    {
        $context = self::batchContext($pdo, $batchId);
        $batch = $context['batch'];
        $items = $context['items'];
        $draft = self::emailDraftForBatch($batch, $items, $reviewUrl);
        $htmlBody = self::emailDraftHtmlForBatch($draft['body'], $reviewUrl);
        $recipients = self::draftRecipientsForAudit($pdo, (int)$batch['audit_id'], (string)($batch['recipient_email'] ?? ''), $userId);
        $draftId = ComplianceCommsCenterEngine::createDraft($pdo, array(
            'to' => $recipients['to'],
            'cc' => $recipients['cc'],
            'bcc' => $recipients['bcc'],
            'subject' => $draft['subject'],
            'text_body' => $draft['body'],
            'html_body' => $htmlBody,
            'created_by' => $userId > 0 ? $userId : null,
        ));
        self::storeBatchEmailDraftId($pdo, $batchId, $draftId);

        self::logBatchEvent(
            $pdo,
            (int)$batch['audit_id'],
            $batchId,
            'deadline_extension_email_draft_created',
            $userId > 0 ? $userId : null,
            'Deadline extension email draft created: ' . (string)$batch['request_reference'],
            null,
            array('draft_id' => $draftId, 'to' => $recipients['to'], 'cc' => $recipients['cc'], 'bcc' => $recipients['bcc'])
        );

        return array(
            'draft_id' => $draftId,
            'subject' => $draft['subject'],
            'body' => $draft['body'],
            'review_url' => $reviewUrl,
            'to' => implode(', ', $recipients['to']),
            'cc' => implode(', ', $recipients['cc']),
            'bcc' => implode(', ', $recipients['bcc']),
        );
    }

    /** @return array{to:list<string>,cc:list<string>,bcc:list<string>} */
    private static function draftRecipientsForAudit(PDO $pdo, int $auditId, string $fallbackTo, int $userId): array
    {
        $to = array();
        $cc = array();
        foreach (ComplianceAuditEngine::listAuditContacts($pdo, $auditId) as $contact) {
            $email = strtolower(trim((string)($contact['contact_email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $position = (string)($contact['contact_position'] ?? '');
            if ($position === 'LEAD_AUDITOR') {
                $to[] = $email;
            } elseif (in_array($position, array('AUDITOR', 'SPECIALIST'), true)) {
                $cc[] = $email;
            }
        }
        if ($to === array()) {
            $fallbackTo = strtolower(trim($fallbackTo));
            if (filter_var($fallbackTo, FILTER_VALIDATE_EMAIL) !== false) {
                $to[] = $fallbackTo;
            }
        }
        $bcc = array();
        $userEmail = self::userEmail($pdo, $userId);
        if ($userEmail !== null) {
            $bcc[] = $userEmail;
        }
        return array(
            'to' => array_values(array_unique($to)),
            'cc' => array_values(array_diff(array_unique($cc), $to)),
            'bcc' => array_values(array_diff(array_unique($bcc), $to, $cc)),
        );
    }

    private static function userEmail(PDO $pdo, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $st->execute(array($userId));
            $email = strtolower(trim((string)$st->fetchColumn()));
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function emailDraftHtmlForBatch(string $body, string $reviewUrl): string
    {
        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $link = htmlspecialchars($reviewUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<div>' . $escaped . '</div>'
            . '<p style="margin:18px 0;">'
            . '<a href="' . $link . '" style="display:inline-block;background:#1e3c72;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:10px;font-weight:700;">Open secure review page</a>'
            . '</p>';
    }

    private static function storeBatchEmailDraftId(PDO $pdo, int $batchId, int $draftId): void
    {
        try {
            $pdo->prepare(
                'UPDATE ipca_compliance_corrective_action_deadline_extension_batches
                    SET email_draft_id = ?, updated_at = NOW()
                  WHERE id = ?'
            )->execute(array($draftId, $batchId));
        } catch (Throwable) {
            // Older databases may not have the tracking column until the migration is applied.
        }
    }

    /** @return array{ok:bool,email_id:int|null,thread_id:int|null,postmark_message_id:string|null,error:string|null} */
    public static function sendEmailDraftForBatch(PDO $pdo, int $batchId, int $draftId, int $userId): array
    {
        $context = self::batchContext($pdo, $batchId);
        $batch = $context['batch'];
        $draft = ComplianceCommsCenterEngine::getDraft($pdo, $draftId);
        if ($draft === null || (int)$draft['id'] !== $draftId) {
            throw new RuntimeException('Email draft not found.');
        }
        if ((string)$draft['status'] !== 'draft') {
            throw new RuntimeException('Only drafts in status=draft can be sent.');
        }
        $to = self::draftAddresses($draft, 'to_json');
        $cc = self::draftAddresses($draft, 'cc_json');
        $bcc = self::draftAddresses($draft, 'bcc_json');
        $result = ComplianceCommsCenterEngine::sendOutbound($pdo, array(
            'thread_id' => isset($draft['thread_id']) && (int)$draft['thread_id'] > 0 ? (int)$draft['thread_id'] : null,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => (string)($draft['subject'] ?? ''),
            'text_body' => (string)($draft['text_body'] ?? ''),
            'html_body' => (string)($draft['html_body'] ?? ''),
            'created_by' => $userId > 0 ? $userId : null,
            'draft_id' => $draftId,
        ));
        if (!empty($result['ok'])) {
            $pdo->prepare(
                'UPDATE ipca_compliance_corrective_action_deadline_extension_batches
                    SET email_thread_id = ?, outbound_email_id = ?, updated_at = NOW()
                  WHERE id = ?'
            )->execute(array(
                isset($result['thread_id']) && (int)$result['thread_id'] > 0 ? (int)$result['thread_id'] : null,
                isset($result['email_id']) && (int)$result['email_id'] > 0 ? (int)$result['email_id'] : null,
                $batchId,
            ));
            self::logBatchEvent(
                $pdo,
                (int)$batch['audit_id'],
                $batchId,
                'deadline_extension_email_sent',
                $userId > 0 ? $userId : null,
                'Deadline extension email sent: ' . (string)$batch['request_reference'],
                null,
                array('draft_id' => $draftId, 'email_id' => $result['email_id'] ?? null, 'thread_id' => $result['thread_id'] ?? null)
            );
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForSubmission(PDO $pdo, int $submissionId): array
    {
        if (!self::rcaCapTablePresent($pdo) || $submissionId <= 0) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_rca_cap_deadline_extensions
              WHERE submission_id = ?
              ORDER BY extension_no ASC, id ASC'
        );
        $st->execute(array($submissionId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForCorrectiveAction(PDO $pdo, int $correctiveActionId): array
    {
        if (!self::capTablePresent($pdo) || $correctiveActionId <= 0) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT *
               FROM ipca_compliance_corrective_action_deadline_extensions
              WHERE corrective_action_id = ?
              ORDER BY extension_no ASC, id ASC'
        );
        $st->execute(array($correctiveActionId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    public static function effectiveCorrectiveActionDeadline(PDO $pdo, int $correctiveActionId, ?string $baseDueDate): ?string
    {
        $effective = $baseDueDate !== null && trim($baseDueDate) !== '' ? substr(trim($baseDueDate), 0, 10) : null;
        if (!self::capTablePresent($pdo) || $correctiveActionId <= 0) {
            return $effective;
        }
        $st = $pdo->prepare(
            "SELECT approved_deadline
               FROM ipca_compliance_corrective_action_deadline_extensions
              WHERE corrective_action_id = ?
                AND status = 'approved'
                AND approved_deadline IS NOT NULL
              ORDER BY approved_deadline DESC, id DESC
              LIMIT 1"
        );
        $st->execute(array($correctiveActionId));
        $approved = $st->fetchColumn();
        return is_string($approved) && $approved !== '' ? substr($approved, 0, 10) : $effective;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function requestCorrectiveActionExtension(PDO $pdo, int $correctiveActionId, array $data): ?int
    {
        if (!self::capTablePresent($pdo)) {
            return null;
        }
        $previous = trim((string)($data['previous_deadline'] ?? ''));
        $requested = trim((string)($data['requested_deadline'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));
        if ($previous === '' || $requested === '') {
            throw new InvalidArgumentException('Previous and requested deadlines are required.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('An extension reason is required.');
        }
        $stNo = $pdo->prepare('SELECT COALESCE(MAX(extension_no), 0) + 1 FROM ipca_compliance_corrective_action_deadline_extensions WHERE corrective_action_id = ?');
        $stNo->execute(array($correctiveActionId));
        $extensionNo = (int)$stNo->fetchColumn();
        $st = $pdo->prepare(
            'INSERT INTO ipca_compliance_corrective_action_deadline_extensions
                (corrective_action_id, extension_no, previous_deadline, requested_deadline, approved_deadline,
                 reason, status, submitted_at, reviewed_at, reviewed_by, review_notes, email_thread_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $status = strtolower(trim((string)($data['status'] ?? 'submitted')));
        if (!in_array($status, array('submitted', 'approved', 'rejected'), true)) {
            $status = 'submitted';
        }
        $reviewedBy = isset($data['reviewed_by']) && (int)$data['reviewed_by'] > 0 ? (int)$data['reviewed_by'] : null;
        $reviewNotes = trim((string)($data['review_notes'] ?? ''));
        $approvedDeadline = $status === 'approved' ? substr($requested, 0, 10) : null;
        $now = date('Y-m-d H:i:s');
        $st->execute(array(
            $correctiveActionId,
            $extensionNo,
            substr($previous, 0, 10),
            substr($requested, 0, 10),
            $approvedDeadline,
            $reason,
            $status,
            $now,
            in_array($status, array('approved', 'rejected'), true) ? $now : null,
            in_array($status, array('approved', 'rejected'), true) ? $reviewedBy : null,
            $reviewNotes !== '' ? $reviewNotes : null,
            isset($data['email_thread_id']) && (int)$data['email_thread_id'] > 0 ? (int)$data['email_thread_id'] : null,
        ));
        $id = (int)$pdo->lastInsertId();
        if ($status === 'approved') {
            $pdo->prepare(
                "UPDATE ipca_compliance_corrective_actions
                    SET due_date = ?, status = CASE WHEN UPPER(COALESCE(status,'')) IN ('CLOSED','VERIFIED','COMPLETED','EXECUTED','CANCELLED') THEN status ELSE 'EXTENDED' END, updated_at = NOW()
                  WHERE id = ?"
            )->execute(array($approvedDeadline, $correctiveActionId));
        }
        if (in_array($status, array('approved', 'rejected'), true)) {
            ComplianceApprovalEngine::record($pdo, array(
                'object_type' => 'corrective_action_deadline_extension',
                'object_id' => $id,
                'approval_type' => 'extension',
                'decision' => $status === 'approved' ? 'approved' : 'rejected',
                'reviewed_by' => $reviewedBy,
                'notes' => $reviewNotes !== '' ? $reviewNotes : $reason,
            ));
        }
        return $id;
    }

    public static function recordApprovedCorrectiveActionExtension(
        PDO $pdo,
        int $correctiveActionId,
        string $previousDeadline,
        string $approvedDeadline,
        int $reviewedBy,
        ?string $reason = null
    ): ?int {
        if (!self::capTablePresent($pdo)) {
            return null;
        }
        $previousDeadline = substr(trim($previousDeadline), 0, 10);
        $approvedDeadline = substr(trim($approvedDeadline), 0, 10);
        if ($previousDeadline === '' || $approvedDeadline === '' || $previousDeadline === $approvedDeadline) {
            return null;
        }
        $stNo = $pdo->prepare('SELECT COALESCE(MAX(extension_no), 0) + 1 FROM ipca_compliance_corrective_action_deadline_extensions WHERE corrective_action_id = ?');
        $stNo->execute(array($correctiveActionId));
        $extensionNo = (int)$stNo->fetchColumn();
        $st = $pdo->prepare(
            "INSERT INTO ipca_compliance_corrective_action_deadline_extensions
                (corrective_action_id, extension_no, previous_deadline, requested_deadline, approved_deadline,
                 reason, status, submitted_at, reviewed_at, reviewed_by, review_notes)
             VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?, ?)"
        );
        $note = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Approved through corrective-action deadline update.';
        $st->execute(array(
            $correctiveActionId,
            $extensionNo,
            $previousDeadline,
            $approvedDeadline,
            $approvedDeadline,
            $note,
            $reviewedBy > 0 ? $reviewedBy : null,
            $note,
        ));
        $id = (int)$pdo->lastInsertId();
        ComplianceApprovalEngine::record($pdo, array(
            'object_type' => 'corrective_action_deadline_extension',
            'object_id' => $id,
            'approval_type' => 'extension',
            'decision' => 'approved',
            'reviewed_by' => $reviewedBy,
            'notes' => $note,
        ));
        return $id;
    }

    /** @return array<string,mixed> */
    public static function reviewContextByToken(PDO $pdo, string $token): array
    {
        $tokenRow = ComplianceApprovalTokenService::validateToken($pdo, $token, 'deadline_extension');
        ComplianceApprovalTokenService::markViewed($pdo, (int)$tokenRow['id']);
        $context = self::batchContext($pdo, (int)$tokenRow['batch_id']);
        self::logBatchEvent($pdo, (int)$tokenRow['audit_id'], (int)$tokenRow['batch_id'], 'deadline_extension_token_viewed', null, 'Deadline extension review page viewed', null, array('token_id' => (int)$tokenRow['id']));
        $context['token'] = $tokenRow;
        return $context;
    }

    public static function decideBatchByToken(PDO $pdo, string $token, string $decision, string $reviewerName, string $reviewerEmail, string $notes): void
    {
        $tokenRow = ComplianceApprovalTokenService::validateToken($pdo, $token, 'deadline_extension');
        $decision = strtolower(trim($decision));
        if (!in_array($decision, array('approved', 'rejected'), true)) {
            throw new InvalidArgumentException('Unsupported review decision.');
        }
        $reviewerName = trim($reviewerName);
        $reviewerEmail = trim($reviewerEmail);
        if ($reviewerName === '' || filter_var($reviewerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Reviewer name and valid email are required.');
        }
        $batchId = (int)$tokenRow['batch_id'];
        $context = self::batchContext($pdo, $batchId);
        $batch = $context['batch'];
        if (!in_array((string)$batch['status'], array('submitted','under_review'), true)) {
            throw new RuntimeException('This extension request has already been reviewed or is no longer active.');
        }
        $pdo->beginTransaction();
        try {
            if ($decision === 'approved') {
                $pdo->prepare(
                    "UPDATE ipca_compliance_corrective_action_deadline_extension_batches
                        SET status = 'approved', reviewed_at = NOW(), reviewed_by_name = ?, reviewed_by_email = ?,
                            review_notes = ?, locked_at = NOW()
                      WHERE id = ?"
                )->execute(array($reviewerName, $reviewerEmail, trim($notes) !== '' ? trim($notes) : null, $batchId));
                $pdo->prepare(
                    "UPDATE ipca_compliance_corrective_action_deadline_extension_items
                        SET status = 'approved', approved_deadline = requested_deadline, reviewed_at = NOW(),
                            review_notes = ?, locked_at = NOW()
                      WHERE batch_id = ?"
                )->execute(array(trim($notes) !== '' ? trim($notes) : null, $batchId));
                foreach ($context['items'] as $item) {
                    $pdo->prepare(
                        "UPDATE ipca_compliance_corrective_actions
                            SET due_date = ?, status = CASE WHEN UPPER(COALESCE(status,'')) IN ('CLOSED','VERIFIED','COMPLETED','EXECUTED','CANCELLED') THEN status ELSE 'EXTENDED' END,
                                updated_at = NOW()
                          WHERE id = ?"
                    )->execute(array((string)$item['requested_deadline'], (int)$item['corrective_action_id']));
                    ComplianceApprovalEngine::record($pdo, array(
                        'object_type' => 'corrective_action_deadline_extension_batch',
                        'object_id' => $batchId,
                        'approval_type' => 'extension',
                        'decision' => 'approved',
                        'notes' => trim($notes) !== '' ? trim($notes) : 'Approved by ' . $reviewerName . ' via public token review.',
                    ));
                    self::logBatchEvent($pdo, (int)$batch['audit_id'], $batchId, 'corrective_action_deadline_updated', null, 'Corrective action deadline updated: ' . (string)$item['action_code'], $item, array('reviewer_name' => $reviewerName, 'reviewer_email' => $reviewerEmail));
                }
                self::logBatchEvent($pdo, (int)$batch['audit_id'], $batchId, 'deadline_extension_approved', null, 'Deadline extension request approved: ' . (string)$batch['request_reference'], $batch, array('reviewer_name' => $reviewerName, 'reviewer_email' => $reviewerEmail, 'notes' => $notes));
            } else {
                $pdo->prepare(
                    "UPDATE ipca_compliance_corrective_action_deadline_extension_batches
                        SET status = 'rejected', reviewed_at = NOW(), reviewed_by_name = ?, reviewed_by_email = ?,
                            review_notes = ?, locked_at = NOW()
                      WHERE id = ?"
                )->execute(array($reviewerName, $reviewerEmail, trim($notes) !== '' ? trim($notes) : null, $batchId));
                $pdo->prepare(
                    "UPDATE ipca_compliance_corrective_action_deadline_extension_items
                        SET status = 'rejected', reviewed_at = NOW(), review_notes = ?, locked_at = NOW()
                      WHERE batch_id = ?"
                )->execute(array(trim($notes) !== '' ? trim($notes) : null, $batchId));
                ComplianceApprovalEngine::record($pdo, array(
                    'object_type' => 'corrective_action_deadline_extension_batch',
                    'object_id' => $batchId,
                    'approval_type' => 'extension',
                    'decision' => 'rejected',
                    'notes' => trim($notes) !== '' ? trim($notes) : 'Rejected by ' . $reviewerName . ' via public token review.',
                ));
                self::logBatchEvent($pdo, (int)$batch['audit_id'], $batchId, 'deadline_extension_rejected', null, 'Deadline extension request rejected: ' . (string)$batch['request_reference'], $batch, array('reviewer_name' => $reviewerName, 'reviewer_email' => $reviewerEmail, 'notes' => $notes));
            }
            ComplianceApprovalTokenService::markUsed($pdo, (int)$tokenRow['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function batchItems(PDO $pdo, int $batchId): array
    {
        $st = $pdo->prepare(
            'SELECT i.*, c.action_code, c.title AS action_title, c.action_type, c.status AS action_status,
                    f.finding_code, f.reference AS finding_reference, f.title AS finding_title
               FROM ipca_compliance_corrective_action_deadline_extension_items i
          LEFT JOIN ipca_compliance_corrective_actions c ON c.id = i.corrective_action_id
          LEFT JOIN ipca_compliance_findings f ON f.id = i.finding_id
              WHERE i.batch_id = ?
              ORDER BY i.id ASC'
        );
        $st->execute(array($batchId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    /** @return array<string,mixed>|null */
    private static function correctiveActionContext(PDO $pdo, int $capId): ?array
    {
        $st = $pdo->prepare(
            'SELECT c.*, f.finding_code, f.reference AS finding_reference, f.title AS finding_title,
                    f.audit_id, f.case_id, a.audit_code, a.title AS audit_title
               FROM ipca_compliance_corrective_actions c
               JOIN ipca_compliance_findings f ON f.id = c.finding_id
          LEFT JOIN ipca_compliance_audits a ON a.id = f.audit_id
              WHERE c.id = ? LIMIT 1'
        );
        $st->execute(array($capId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function pendingWorkflowItemExists(PDO $pdo, int $capId): bool
    {
        if (!self::tablePresent($pdo, 'ipca_compliance_corrective_action_deadline_extension_items')) {
            return false;
        }
        $st = $pdo->prepare("SELECT id FROM ipca_compliance_corrective_action_deadline_extension_items WHERE corrective_action_id = ? AND status IN ('draft','submitted') LIMIT 1");
        $st->execute(array($capId));
        return (int)$st->fetchColumn() > 0;
    }

    private static function generateBatchReference(PDO $pdo): string
    {
        $prefix = 'DEX-' . date('Y') . '-';
        $st = $pdo->prepare('SELECT request_reference FROM ipca_compliance_corrective_action_deadline_extension_batches WHERE request_reference LIKE ? ORDER BY id DESC LIMIT 50');
        $st->execute(array($prefix . '%'));
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: array() as $ref) {
            $n = (int)substr((string)$ref, strlen($prefix));
            $max = max($max, $n);
        }
        return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    private static function draftAddresses(array $draft, string $column): array
    {
        $rows = json_decode((string)($draft[$column] ?? '[]'), true);
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['Email'])) {
                $out[] = (string)$row['Email'];
            }
        }
        return $out;
    }

    private static function reviewUrl(string $token): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/compliance/review-deadline-extension.php?token=' . rawurlencode($token);
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $meta */
    private static function logBatchEvent(PDO $pdo, int $auditId, int $batchId, string $kind, ?int $actorUserId, string $summary, ?array $before = null, ?array $meta = null): void
    {
        compliance_log_case_event($pdo, null, 'deadline_extension_batch', $batchId, $kind, $actorUserId, $summary, $before, null, array_merge(array('audit_id' => $auditId), $meta ?? array()));
    }

    /** @return list<array<string,mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        try {
            $st = $pdo->query($sql);
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            return is_array($rows) ? $rows : array();
        } catch (Throwable) {
            return array();
        }
    }

    private static function tablePresent(PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
