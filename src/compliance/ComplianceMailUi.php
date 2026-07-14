<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceEmailHtmlRenderer.php';

final class ComplianceMailUi
{
    public static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $thread
     */
    public static function conversationCard(array $thread, bool $selected = false): string
    {
        $id = (int)($thread['id'] ?? 0);
        $subject = trim((string)($thread['last_subject'] ?? '')) !== ''
            ? (string)$thread['last_subject']
            : (string)($thread['subject_normalized'] ?? '(no subject)');
        $sender = (string)($thread['primary_contact_email'] ?? $thread['last_from_email'] ?? 'Unknown sender');
        $preview = self::threadPreview($thread);
        $status = (string)($thread['status'] ?? 'open');
        $priority = (string)($thread['priority'] ?? 'normal');
        $waiting = self::workflowLabel($thread);
        $time = self::shortDate((string)($thread['last_message_at'] ?? $thread['created_at'] ?? ''));
        $attCount = (int)($thread['attachment_count'] ?? 0);
        $linkCount = (int)($thread['link_count'] ?? 0);
        $unread = $status === 'open';

        return '<div class="mail-thread-card' . ($selected ? ' is-selected' : '') . '" role="button" tabindex="0" data-thread-id="' . $id . '" data-status="' . self::e($status) . '" data-priority="' . self::e($priority) . '" data-search="' . self::e(strtolower($sender . ' ' . $subject . ' ' . $preview . ' ' . $waiting)) . '">'
            . '<span class="mail-thread-select"><input type="checkbox" value="' . $id . '" aria-label="Select conversation"></span>'
            . '<span class="mail-thread-unread' . ($unread ? ' is-visible' : '') . '"></span>'
            . '<span class="mail-thread-main">'
            . '<span class="mail-thread-row"><strong class="mail-thread-sender">' . self::e($sender) . '</strong><span class="mail-thread-time">' . self::e($time) . '</span></span>'
            . '<span class="mail-thread-subject">' . self::e($subject !== '' ? $subject : '(no subject)') . '</span>'
            . '<span class="mail-thread-meta">'
            . '<span class="mail-chip wait">' . self::e($waiting) . '</span>'
            . '<span class="mail-priority p-' . self::e($priority) . '">' . self::e($priority) . '</span>'
            . ($attCount > 0 ? '<span class="mail-icon-chip" title="Attachments">Att ' . $attCount . '</span>' : '')
            . ($linkCount > 0 ? '<span class="mail-icon-chip" title="Compliance links">Links ' . $linkCount . '</span>' : '<span class="mail-icon-chip muted">Unlinked</span>')
            . '</span>'
            . '</span>'
            . '</div>';
    }

    /**
     * @param array<string,mixed> $thread
     * @param list<array<string,mixed>> $emails
     * @param list<array<string,mixed>> $links
     * @param list<array<string,mixed>> $pickerGroups
     */
    public static function threadWorkspace(PDO $pdo, array $thread, array $emails, array $links, array $pickerGroups): string
    {
        $subject = (string)($thread['subject_normalized'] ?? '(no subject)');
        $status = (string)($thread['status'] ?? 'open');
        $priority = (string)($thread['priority'] ?? 'normal');
        $waiting = self::workflowLabel($thread);
        $latestInbound = self::latestInboundId($emails);
        $linkCount = count($links);
        $complianceSummary = '<div class="mail-reader-compliance-strip">'
            . '<span class="mail-status-pill s-' . self::e($status) . '">' . self::e($waiting) . '</span>'
            . '<span class="mail-status-pill p-' . self::e($priority) . '">' . self::e(ucfirst($priority)) . ' priority</span>'
            . '<span class="mail-status-pill l-' . ($linkCount > 0 ? 'linked' : 'unlinked') . '">' . ($linkCount > 0 ? self::e('Linked ' . $linkCount) : 'Unlinked') . '</span>'
            . '</div>';

        $html = '<div class="mail-reader-shell" data-thread-id="' . (int)$thread['id'] . '">';
        $html .= '<header class="mail-reader-header">';
        $html .= '<div class="mail-reader-title-block"><div class="mail-reader-kicker">Compliance Control</div>' . $complianceSummary . '<h2>' . self::e($subject !== '' ? $subject : '(no subject)') . '</h2>';
        $html .= '<p>' . self::e(self::participantsSummary($thread, $emails)) . '</p></div>';
        $html .= '<div class="mail-reader-actions">';
        $html .= '<details class="mail-action-menu"><summary>Actions</summary><div>';
        if ($latestInbound > 0) {
            $html .= '<button type="button" data-compose-reply="' . $latestInbound . '">Reply</button>';
            $html .= '<button type="button" data-compose-reply-all="' . $latestInbound . '">Reply all</button>';
        }
        $html .= '<button type="button" data-compose-thread="' . (int)$thread['id'] . '">New message</button>';
        $html .= '</div></details>';
        $html .= '<button type="button" class="mail-action" data-sidebar-toggle aria-expanded="false">Compliance</button>';
        $html .= '</div></header>';

        $html .= '<div class="mail-reader-grid">';
        $html .= '<main class="mail-timeline">';
        if ($emails === array()) {
            $html .= '<div class="mail-empty">This conversation has no messages yet.</div>';
        } else {
            foreach ($emails as $email) {
                $attachments = ComplianceCommsCenterEngine::listAttachmentsForEmail($pdo, (int)$email['id']);
                $events = ComplianceCommsCenterEngine::listEventsForEmail($pdo, (int)$email['id']);
                $html .= self::messageCard($email, $attachments, $events);
            }
        }
        $html .= '</main>';
        $html .= self::complianceSidebar($thread, $links, $pickerGroups, $status, $priority);
        $html .= '</div></div>';

        return $html;
    }

    /**
     * @param array<string,mixed> $email
     * @param list<array<string,mixed>> $attachments
     * @param list<array<string,mixed>> $events
     */
    public static function messageCard(array $email, array $attachments, array $events): string
    {
        $direction = (string)($email['direction'] ?? 'inbound');
        $tone = $direction === 'outbound' ? 'outgoing' : 'incoming';
        $from = (string)($email['from_name'] ?? '') !== '' ? (string)$email['from_name'] : (string)($email['from_email'] ?? '');
        $fromEmail = (string)($email['from_email'] ?? '');
        $when = (string)($email['received_at'] ?? $email['sent_at'] ?? $email['created_at'] ?? '');
        $to = self::addressLine((string)($email['to_json'] ?? ''));
        $cc = self::addressLine((string)($email['cc_json'] ?? ''));
        $bcc = self::addressLine((string)($email['bcc_json'] ?? ''));
        $subject = (string)($email['subject'] ?? '(no subject)');
        $status = (string)($email['status'] ?? '');
        $body = ComplianceEmailHtmlRenderer::iframeForMessage($email, $attachments);
        $quote = self::collapsedQuote((string)($email['text_body'] ?? ''), (string)($email['stripped_text_reply'] ?? ''));

        $html = '<article class="mail-message-card is-' . self::e($tone) . '">';
        $html .= '<div class="mail-message-accent"></div><div class="mail-message-content">';
        $html .= '<header class="mail-message-header">';
        $html .= '<div class="mail-avatar">' . self::e(self::initials($from !== '' ? $from : $fromEmail)) . '</div>';
        $html .= '<div class="mail-message-meta"><div class="mail-message-topline"><strong>' . self::e($from !== '' ? $from : 'Unknown sender') . '</strong><span>' . self::e(self::longDate($when)) . '</span></div>';
        $html .= '<div class="mail-message-subject">' . self::e($subject) . '</div>';
        $html .= '<div class="mail-message-recipients">' . self::e(self::recipientSummary($to, $cc)) . '</div>';
        $html .= '<details class="mail-message-details"><summary>Details</summary><dl>';
        $html .= '<div><dt>From</dt><dd>' . self::e($fromEmail) . '</dd></div>';
        if ($to !== '') { $html .= '<div><dt>To</dt><dd>' . self::e($to) . '</dd></div>'; }
        if ($cc !== '') { $html .= '<div><dt>Cc</dt><dd>' . self::e($cc) . '</dd></div>'; }
        if ($bcc !== '') { $html .= '<div><dt>Bcc</dt><dd>' . self::e($bcc) . '</dd></div>'; }
        $html .= '<div><dt>Status</dt><dd>' . self::e(trim(ucfirst($direction) . ($status !== '' ? ' · ' . $status : ''))) . '</dd></div>';
        $html .= '</dl></details></div>';
        $html .= '<div class="mail-message-tools">';
        $html .= '<details class="mail-action-menu"><summary>Actions</summary><div>';
        $html .= '<button type="button" data-compose-reply="' . (int)$email['id'] . '">Reply</button>';
        $html .= '<button type="button" data-compose-forward="' . (int)$email['id'] . '">Forward</button>';
        $html .= '<a href="/admin/compliance/email_source.php?id=' . (int)$email['id'] . '&format=eml">Download .eml</a>';
        $html .= '<a href="/admin/compliance/email_source.php?id=' . (int)$email['id'] . '&format=source" target="_blank" rel="noopener">View source</a>';
        $html .= '<button type="button" data-print-message>Print</button>';
        $html .= '<a href="/admin/compliance/findings.php?source_email_id=' . (int)$email['id'] . '">Create Finding</a>';
        $html .= '<a href="/admin/compliance/audits.php?source_email_id=' . (int)$email['id'] . '">Create Audit</a>';
        $html .= '<a href="/admin/compliance/corrective_actions.php?source_email_id=' . (int)$email['id'] . '">Create CA</a>';
        $html .= '</div></details>';
        $html .= '</div></header>';

        if ($attachments !== array()) {
            $html .= self::attachments($attachments);
        }
        $html .= '<div class="mail-message-body">' . $body . '</div>';
        if ($quote !== '') {
            $html .= '<details class="mail-quoted"><summary>Show previous conversation</summary><div>' . nl2br(self::e($quote)) . '</div></details>';
        }
        if ($events !== array()) {
            $html .= '<details class="mail-events"><summary>Delivery timeline (' . count($events) . ')</summary>';
            foreach ($events as $event) {
                $html .= '<div><strong>' . self::e((string)$event['event_type']) . '</strong> ' . self::e(self::shortDate((string)($event['event_at'] ?? $event['created_at'] ?? ''))) . '</div>';
            }
            $html .= '</details>';
        }
        $html .= '</div></article>';
        return $html;
    }

    /**
     * @param list<array<string,mixed>> $attachments
     */
    public static function attachments(array $attachments): string
    {
        $html = '<section class="mail-attachments" aria-label="Attachments">';
        foreach ($attachments as $a) {
            $id = (int)($a['id'] ?? 0);
            $name = (string)($a['original_filename'] ?? 'attachment');
            $type = (string)($a['content_type'] ?? 'application/octet-stream');
            $url = trim((string)($a['public_url'] ?? ''));
            $openUrl = $url !== '' ? $url : '/admin/compliance/email_attachment.php?id=' . $id . '&disposition=inline';
            $downloadUrl = '/admin/compliance/email_attachment.php?id=' . $id . '&disposition=attachment';
            $isImage = str_starts_with(strtolower($type), 'image/');
            $html .= '<div class="mail-attachment">';
            $html .= '<div class="mail-attachment-icon">' . self::e(self::fileIcon($type, $name)) . '</div>';
            if ($isImage) {
                $html .= '<img class="mail-attachment-thumb" src="' . self::e($openUrl) . '" alt="">';
            }
            $html .= '<div class="mail-attachment-copy"><strong>' . self::e($name) . '</strong><span>' . self::e(self::bytes((int)($a['size_bytes'] ?? 0))) . ' · ' . self::e($type) . '</span></div>';
            $html .= '<div class="mail-attachment-actions"><a href="' . self::e($openUrl) . '" target="_blank" rel="noopener">Open</a><a href="' . self::e($downloadUrl) . '">Download</a></div>';
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    /**
     * @param array<string,mixed> $thread
     * @param list<array<string,mixed>> $links
     * @param list<array<string,mixed>> $pickerGroups
     */
    public static function complianceSidebar(array $thread, array $links, array $pickerGroups, string $status, string $priority): string
    {
        $linkable = ComplianceCommsCenterEngine::linkableObjectTypes();
        $labelIndex = self::pickerLabelIndex($pickerGroups);
        $html = '<aside class="mail-compliance-sidebar" id="mailComplianceSidebar">';
        $deadline = self::deadlineLabel($thread);
        $primaryLink = self::primaryLinkLabel($links, $labelIndex);
        $html .= '<div class="mail-sidebar-sticky">';
        $html .= '<div class="mail-sidebar-head"><div><span>Compliance Intelligence</span><strong>' . self::e(self::workflowLabel($thread)) . '</strong></div><button type="button" data-sidebar-close>Close</button></div>';
        $html .= '<div class="mail-sidebar-summary">';
        $html .= '<button type="button" class="mail-summary-badge" data-compliance-edit><span>Waiting For</span><strong>' . self::e(self::workflowLabel($thread)) . '</strong></button>';
        $html .= '<button type="button" class="mail-summary-badge" data-compliance-edit><span>Priority</span><strong>' . self::e(ucfirst($priority)) . '</strong></button>';
        $html .= '<button type="button" class="mail-summary-badge" data-compliance-edit><span>Linked</span><strong>' . self::e($primaryLink) . '</strong></button>';
        $html .= '<button type="button" class="mail-summary-badge" data-compliance-edit><span>Deadline</span><strong>' . self::e($deadline) . '</strong></button>';
        $html .= '</div>';
        $html .= '<button type="button" class="mail-edit-compliance" data-compliance-edit>Edit Compliance</button>';
        $html .= '</div>';

        $html .= '<div class="mail-sidebar-scroll">';
        $html .= '<div class="mail-sidebar-section is-read-mode"><h3>Linked Objects</h3>';
        if ($links === array()) {
            $html .= '<p class="mail-muted">No compliance objects linked yet.</p>';
        } else {
            foreach ($links as $link) {
                $type = (string)($link['linked_object_type'] ?? '');
                $id = (string)($link['linked_object_id'] ?? '');
                $label = (string)($labelIndex[$type . ':' . $id] ?? $id);
                $href = self::objectHref($type, $id);
                $html .= '<div class="mail-linked-object is-read"><span>' . self::e((string)($linkable[$type] ?? $type)) . '</span>';
                $html .= $href !== '' ? '<a href="' . self::e($href) . '">' . self::e($label) . '</a>' : '<strong>' . self::e($label) . '</strong>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        $html .= '<div class="mail-sidebar-section is-edit-mode"><h3>Status and Priority</h3>';
        $html .= '<form class="mail-inline-form" data-thread-update><input type="hidden" name="thread_id" value="' . (int)$thread['id'] . '">';
        $html .= '<label>Status<select name="status">' . self::options(array('open','waiting_internal','waiting_external','closed','archived'), $status) . '</select></label>';
        $html .= '<label>Priority<select name="priority">' . self::options(array('low','normal','high','urgent'), $priority) . '</select></label>';
        $html .= '<button type="submit">Save</button></form></div>';

        $html .= '<div class="mail-sidebar-section is-edit-mode"><h3>Linked Objects</h3>';
        if ($links === array()) {
            $html .= '<p class="mail-muted">No compliance objects linked yet.</p>';
        } else {
            foreach ($links as $link) {
                $type = (string)($link['linked_object_type'] ?? '');
                $id = (string)($link['linked_object_id'] ?? '');
                $label = (string)($labelIndex[$type . ':' . $id] ?? $id);
                $href = self::objectHref($type, $id);
                $html .= '<div class="mail-linked-object"><span>' . self::e((string)($linkable[$type] ?? $type)) . '</span>';
                $html .= $href !== '' ? '<a href="' . self::e($href) . '">' . self::e($label) . '</a>' : '<strong>' . self::e($label) . '</strong>';
                $html .= '<button type="button" data-unlink-id="' . (int)$link['id'] . '">Remove</button></div>';
            }
        }
        $html .= self::linkForm((int)$thread['id'], $pickerGroups);
        $html .= '</div>';

        $authority = (string)($thread['authority_name'] ?? '');
        $contact = (string)($thread['primary_contact_email'] ?? '');
        $html .= '<div class="mail-sidebar-section"><h3>Context</h3>';
        $html .= '<dl class="mail-context-list">';
        $html .= '<div><dt>Authority</dt><dd>' . self::e($authority !== '' ? $authority : 'Not set') . '</dd></div>';
        $html .= '<div><dt>Contact</dt><dd>' . self::e($contact !== '' ? $contact : 'Not set') . '</dd></div>';
        $html .= '<div><dt>Deadline</dt><dd>' . self::e($deadline) . '</dd></div>';
        $html .= '<div><dt>Thread</dt><dd>#' . (int)$thread['id'] . '</dd></div>';
        $html .= '</dl></div></div></aside>';
        return $html;
    }

    /**
     * @param list<array<string,mixed>> $pickerGroups
     */
    private static function linkForm(int $threadId, array $pickerGroups): string
    {
        $has = false;
        foreach ($pickerGroups as $group) {
            if (!empty($group['options'])) { $has = true; break; }
        }
        if (!$has) {
            return '';
        }
        $html = '<form class="mail-link-form" data-link-object><input type="hidden" name="thread_id" value="' . $threadId . '">';
        $html .= '<select name="object_ref" required><option value="">Link existing object...</option>';
        foreach ($pickerGroups as $group) {
            if (empty($group['options'])) { continue; }
            $html .= '<optgroup label="' . self::e((string)$group['type_label']) . '">';
            foreach ($group['options'] as $option) {
                $value = (string)$group['type'] . '|' . (string)$option['id'];
                $html .= '<option value="' . self::e($value) . '">' . self::e((string)$option['label']) . '</option>';
            }
            $html .= '</optgroup>';
        }
        $html .= '</select><select name="link_type">' . self::assocOptions(ComplianceCommsCenterEngine::linkTypes(), 'authority_communication') . '</select><button type="submit">Link</button></form>';
        return $html;
    }

    /**
     * @param list<array<string,mixed>> $emails
     */
    public static function latestInboundId(array $emails): int
    {
        foreach (array_reverse($emails) as $email) {
            if ((string)($email['direction'] ?? '') === 'inbound') {
                return (int)$email['id'];
            }
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $thread
     */
    public static function workflowLabel(array $thread): string
    {
        $status = (string)($thread['status'] ?? 'open');
        if ($status === 'waiting_external') { return 'Awaiting External Reply'; }
        if ($status === 'waiting_internal') { return 'Awaiting Internal Review'; }
        if ($status === 'closed') { return 'Closed'; }
        if ($status === 'archived') { return 'Archived'; }
        return 'Needs Review';
    }

    /**
     * @param array<string,mixed> $thread
     */
    private static function threadPreview(array $thread): string
    {
        $authority = trim((string)($thread['authority_name'] ?? ''));
        $contact = trim((string)($thread['primary_contact_email'] ?? $thread['last_from_email'] ?? ''));
        if ($authority !== '') {
            return 'Authority: ' . $authority;
        }
        if ($contact !== '') {
            return $contact;
        }
        return ((int)($thread['message_count'] ?? 0)) . ' messages';
    }

    private static function displayName(string $emailOrName): string
    {
        $value = trim($emailOrName);
        if ($value === '') { return 'Unknown'; }
        if (str_contains($value, '@')) {
            $local = (string)preg_replace('/@.*/', '', $value);
            $local = trim((string)preg_replace('/[._-]+/', ' ', $local));
            return $local !== '' ? ucwords($local) : $value;
        }
        return $value;
    }

    private static function initials(string $name): string
    {
        $name = self::displayName($name);
        $parts = preg_split('/\s+/', trim($name)) ?: array();
        $out = '';
        foreach ($parts as $part) {
            if ($part !== '') { $out .= strtoupper(substr($part, 0, 1)); }
            if (strlen($out) >= 2) { break; }
        }
        return $out !== '' ? $out : '?';
    }

    private static function addressLine(string $json): string
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) { return ''; }
        $out = array();
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['Email'])) {
                $name = trim((string)($row['Name'] ?? ''));
                $email = trim((string)$row['Email']);
                $out[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
            }
        }
        return implode(', ', $out);
    }

    private static function recipientSummary(string $to, string $cc): string
    {
        $parts = array();
        if ($to !== '') {
            $parts[] = 'To: ' . $to;
        }
        if ($cc !== '') {
            $parts[] = 'Cc: ' . $cc;
        }
        return $parts !== array() ? implode('   ', $parts) : 'No visible recipients';
    }

    /**
     * @param array<string,mixed> $thread
     * @param list<array<string,mixed>> $emails
     */
    private static function participantsSummary(array $thread, array $emails): string
    {
        $participants = array();
        foreach ($emails as $email) {
            $from = trim((string)($email['from_email'] ?? ''));
            if ($from !== '') { $participants[$from] = true; }
            foreach (array('to_json', 'cc_json') as $field) {
                $line = self::addressLine((string)($email[$field] ?? ''));
                foreach (preg_split('/,\s*/', $line) ?: array() as $token) {
                    $token = trim($token);
                    if ($token !== '') { $participants[$token] = true; }
                }
            }
        }
        if ($participants === array()) {
            $contact = (string)($thread['primary_contact_email'] ?? '');
            return $contact !== '' ? $contact : 'No participants yet';
        }
        return implode(' · ', array_slice(array_keys($participants), 0, 4));
    }

    private static function collapsedQuote(string $text, string $stripped): string
    {
        $text = trim($text);
        $stripped = trim($stripped);
        if ($stripped !== '' && $stripped !== $text) {
            return trim(str_replace($stripped, '', $text));
        }
        foreach (array('/\nOn .+ wrote:\n/is', '/\n-{2,}\s*Original Message\s*-{2,}/i', '/\nFrom:\s.+\nSent:\s.+\nTo:\s/is') as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                return trim(substr($text, (int)$m[0][1]));
            }
        }
        return '';
    }

    private static function fileIcon(string $type, string $name): string
    {
        $type = strtolower($type);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($type, 'image/')) { return 'IMG'; }
        if ($type === 'application/pdf' || $ext === 'pdf') { return 'PDF'; }
        if (in_array($ext, array('xls','xlsx','csv'), true)) { return 'XLS'; }
        if (in_array($ext, array('doc','docx'), true)) { return 'DOC'; }
        return 'FILE';
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes <= 0) { return 'Unknown size'; }
        if ($bytes < 1024) { return $bytes . ' B'; }
        if ($bytes < 1048576) { return round($bytes / 1024, 1) . ' KB'; }
        return round($bytes / 1048576, 1) . ' MB';
    }

    public static function shortDate(string $raw): string
    {
        if (trim($raw) === '') { return ''; }
        try {
            $dt = new DateTimeImmutable($raw);
            return $dt->format('M j H:i');
        } catch (Throwable) {
            return substr($raw, 0, 16);
        }
    }

    public static function longDate(string $raw): string
    {
        if (trim($raw) === '') { return ''; }
        try {
            $dt = new DateTimeImmutable($raw);
            return $dt->format('M j, Y H:i');
        } catch (Throwable) {
            return substr($raw, 0, 16);
        }
    }

    /**
     * @param list<string> $values
     */
    private static function options(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value) {
            $html .= '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::e(str_replace('_', ' ', $value)) . '</option>';
        }
        return $html;
    }

    /**
     * @param array<string,string> $values
     */
    private static function assocOptions(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value => $label) {
            $html .= '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return $html;
    }

    /**
     * @param list<array<string,mixed>> $pickerGroups
     * @return array<string,string>
     */
    private static function pickerLabelIndex(array $pickerGroups): array
    {
        $out = array();
        foreach ($pickerGroups as $group) {
            foreach (($group['options'] ?? array()) as $option) {
                $out[(string)$group['type'] . ':' . (string)$option['id']] = (string)$option['label'];
            }
        }
        return $out;
    }

    private static function objectHref(string $type, string $id): string
    {
        switch ($type) {
            case 'finding': return '/admin/compliance/findings.php?id=' . rawurlencode($id);
            case 'audit': return '/admin/compliance/audits.php?id=' . rawurlencode($id);
            case 'corrective_action': return '/admin/compliance/corrective_actions.php?id=' . rawurlencode($id);
            case 'manual_change_request': return '/admin/compliance/change_requests.php?id=' . rawurlencode($id);
            case 'meeting': return '/admin/compliance/meetings.php?id=' . rawurlencode($id);
            case 'compliance_case': return '/admin/compliance/moc.php?id=' . rawurlencode($id);
            default: return '';
        }
    }

    /**
     * @param list<array<string,mixed>> $links
     * @param array<string,string> $labelIndex
     */
    private static function primaryLinkLabel(array $links, array $labelIndex): string
    {
        if ($links === array()) {
            return 'No links';
        }
        $link = $links[0];
        $type = (string)($link['linked_object_type'] ?? '');
        $id = (string)($link['linked_object_id'] ?? '');
        $label = (string)($labelIndex[$type . ':' . $id] ?? $id);
        return $label !== '' ? $label : 'Linked object';
    }

    /**
     * @param array<string,mixed> $thread
     */
    private static function deadlineLabel(array $thread): string
    {
        foreach (array('due_at', 'deadline_at', 'target_date', 'response_due_at') as $key) {
            if (!empty($thread[$key])) {
                return self::shortDate((string)$thread[$key]);
            }
        }
        return 'No deadline';
    }
}
