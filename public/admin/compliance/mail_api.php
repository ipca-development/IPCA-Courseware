<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePostmarkConfig.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsCenterEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceMailUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function mail_api_json(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mail_api_selected_object_refs(array $pickerGroups): array
{
    $valid = array();
    foreach ($pickerGroups as $group) {
        foreach (($group['options'] ?? array()) as $opt) {
            $type = (string)($group['type'] ?? '');
            $id = (string)($opt['id'] ?? '');
            if ($type !== '' && $id !== '') {
                $valid[$type . '|' . $id] = array('type' => $type, 'id' => $id);
            }
        }
    }
    $posted = isset($_POST['object_refs']) && is_array($_POST['object_refs']) ? $_POST['object_refs'] : array();
    $selected = array();
    foreach ($posted as $raw) {
        $key = (string)$raw;
        if (isset($valid[$key])) {
            $selected[$key] = $valid[$key];
        }
    }
    return $selected;
}

function mail_api_sync_thread_object_refs(PDO $pdo, int $threadId, array $selectedRefs, ?int $uid): void
{
    if ($selectedRefs === array() || $threadId <= 0) {
        return;
    }
    $existing = array();
    foreach (ComplianceCommsCenterEngine::listObjectLinksForThread($pdo, $threadId) as $link) {
        if (!empty($link['email_id'])) {
            continue;
        }
        $existing[(string)$link['linked_object_type'] . '|' . (string)$link['linked_object_id']] = true;
    }
    foreach ($selectedRefs as $key => $ref) {
        if (isset($existing[$key])) {
            continue;
        }
        ComplianceCommsCenterEngine::linkObject($pdo, array(
            'thread_id' => $threadId,
            'linked_object_type' => $ref['type'],
            'linked_object_id' => $ref['id'],
            'link_type' => 'authority_communication',
            'created_by' => $uid,
        ));
    }
}

function mail_api_primary_recipient(string $to): ?string
{
    foreach (preg_split('/[,;\s]+/', $to) ?: array() as $part) {
        $email = strtolower(trim((string)$part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }
    return null;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'thread') {
        $threadId = (int)($_GET['id'] ?? 0);
        if ($threadId <= 0) {
            mail_api_json(array('ok' => false, 'error' => 'Missing thread id.'), 400);
        }
        $thread = ComplianceCommsCenterEngine::getThread($pdo, $threadId);
        if ($thread === null) {
            mail_api_json(array('ok' => false, 'error' => 'Thread not found.'), 404);
        }
        $emails = ComplianceCommsCenterEngine::listEmailsForThread($pdo, $threadId);
        $links = ComplianceCommsCenterEngine::listObjectLinksForThread($pdo, $threadId);
        $pickerGroups = ComplianceCommsCenterEngine::listLinkablePickerOptions($pdo, 200);
        mail_api_json(array(
            'ok' => true,
            'thread_id' => $threadId,
            'title' => (string)($thread['subject_normalized'] ?? '(no subject)'),
            'html' => ComplianceMailUi::threadWorkspace($pdo, $thread, $emails, $links, $pickerGroups),
        ));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        $folder = (string)($_GET['folder'] ?? 'inbox');
        $q = trim((string)($_GET['q'] ?? ''));
        $filters = array();
        if ($folder === 'waiting_external') { $filters['status'] = 'waiting_external'; }
        if ($folder === 'waiting_internal') { $filters['status'] = 'waiting_internal'; }
        if ($folder === 'closed') { $filters['status'] = 'closed'; }
        if ($folder === 'archive') { $filters['status'] = 'archived'; }
        if ($folder === 'sent') { $filters['has_outbound'] = true; }
        if ($q !== '') { $filters['q'] = $q; }
        if ($folder === 'drafts') {
            $drafts = ComplianceCommsCenterEngine::listDrafts($pdo, array('status' => 'draft'), 200);
            $html = '';
            foreach ($drafts as $draft) {
                $threadId = (int)($draft['thread_id'] ?? 0);
                $subject = (string)($draft['subject'] ?? '(draft)');
                $html .= '<button type="button" class="mail-thread-card is-draft" ' . ($threadId > 0 ? 'data-thread-id="' . $threadId . '"' : 'data-draft-id="' . (int)$draft['id'] . '"') . '>'
                    . '<span class="mail-thread-main"><span class="mail-thread-row"><strong class="mail-thread-sender">Draft</strong><span class="mail-thread-time">' . ComplianceMailUi::e(ComplianceMailUi::shortDate((string)($draft['updated_at'] ?? ''))) . '</span></span>'
                    . '<span class="mail-thread-subject">' . ComplianceMailUi::e($subject) . '</span><span class="mail-thread-preview">Draft not sent</span></span></button>';
            }
            mail_api_json(array('ok' => true, 'html' => $html, 'first_id' => 0));
        }
        $threads = ComplianceCommsCenterEngine::listThreads($pdo, $filters, 200);
        $html = '';
        $first = 0;
        foreach ($threads as $idx => $thread) {
            if ($idx === 0) { $first = (int)$thread['id']; }
            $html .= ComplianceMailUi::conversationCard($thread, $idx === 0);
        }
        mail_api_json(array('ok' => true, 'html' => $html, 'first_id' => $first));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'compose_prefill') {
        $replyId = (int)($_GET['reply_to_email_id'] ?? 0);
        $forwardId = (int)($_GET['forward_email_id'] ?? 0);
        $threadId = (int)($_GET['thread_id'] ?? 0);
        $prefill = array('to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'html_body' => '', 'text_body' => '', 'thread_id' => $threadId, 'reply_to_email_id' => $replyId);
        if ($replyId > 0) {
            $st = $pdo->prepare('SELECT id, thread_id, from_email, subject, text_body, stripped_text_reply FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
            $st->execute(array($replyId));
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($src)) {
                $prefill['to'] = (string)($src['from_email'] ?? '');
                $prefill['thread_id'] = (int)($src['thread_id'] ?? 0);
                $subject = ComplianceCommsCenterEngine::normalizeSubject((string)($src['subject'] ?? ''));
                $prefill['subject'] = $subject !== '' ? 'Re: ' . $subject : '';
                $quote = trim((string)($src['stripped_text_reply'] ?? $src['text_body'] ?? ''));
                $prefill['text_body'] = $quote !== '' ? "\n\nOn " . date('Y-m-d') . ', ' . (string)$src['from_email'] . " wrote:\n> " . str_replace("\n", "\n> ", $quote) : '';
            }
        } elseif ($forwardId > 0) {
            $st = $pdo->prepare('SELECT id, thread_id, from_email, subject, text_body, html_body, received_at, sent_at FROM ipca_compliance_emails WHERE id = ? LIMIT 1');
            $st->execute(array($forwardId));
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($src)) {
                $prefill['thread_id'] = (int)($src['thread_id'] ?? 0);
                $subject = ComplianceCommsCenterEngine::normalizeSubject((string)($src['subject'] ?? ''));
                $prefill['subject'] = $subject !== '' ? 'Fwd: ' . $subject : '';
                $body = trim((string)($src['text_body'] ?? ''));
                if ($body === '') {
                    $body = trim(strip_tags((string)($src['html_body'] ?? '')));
                }
                $prefill['text_body'] = "\n\nForwarded message\nFrom: " . (string)($src['from_email'] ?? '') . "\nDate: " . (string)($src['received_at'] ?? $src['sent_at'] ?? '') . "\nSubject: " . (string)($src['subject'] ?? '') . "\n\n" . $body;
            }
        } elseif ($threadId > 0) {
            $thread = ComplianceCommsCenterEngine::getThread($pdo, $threadId);
            if (is_array($thread)) {
                $prefill['to'] = (string)($thread['primary_contact_email'] ?? '');
                $prefill['subject'] = (string)($thread['subject_normalized'] ?? '');
            }
        }
        mail_api_json(array('ok' => true, 'prefill' => $prefill, 'from' => CompliancePostmarkConfig::publicSummary()));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_thread') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        if ($threadId <= 0) {
            mail_api_json(array('ok' => false, 'error' => 'Missing thread id.'), 400);
        }
        if (isset($_POST['status'])) {
            ComplianceCommsCenterEngine::bulkUpdateThreadStatus($pdo, array($threadId), (string)$_POST['status']);
        }
        if (isset($_POST['priority'])) {
            ComplianceCommsCenterEngine::bulkUpdateThreadPriority($pdo, array($threadId), (string)$_POST['priority']);
        }
        mail_api_json(array('ok' => true));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'link_object') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $raw = (string)($_POST['object_ref'] ?? '');
        $parts = explode('|', $raw, 2);
        if ($threadId <= 0 || count($parts) !== 2) {
            mail_api_json(array('ok' => false, 'error' => 'Missing link target.'), 400);
        }
        ComplianceCommsCenterEngine::linkObject($pdo, array(
            'thread_id' => $threadId,
            'linked_object_type' => $parts[0],
            'linked_object_id' => $parts[1],
            'link_type' => (string)($_POST['link_type'] ?? 'context'),
            'created_by' => $uid > 0 ? $uid : null,
        ));
        mail_api_json(array('ok' => true));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'unlink_object') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId <= 0) {
            mail_api_json(array('ok' => false, 'error' => 'Missing link id.'), 400);
        }
        ComplianceCommsCenterEngine::unlinkObject($pdo, $linkId);
        mail_api_json(array('ok' => true));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save_draft' || $action === 'send_now')) {
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $replyToEmailId = (int)($_POST['reply_to_email_id'] ?? 0);
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $pickerGroups = ComplianceCommsCenterEngine::listLinkablePickerOptions($pdo);
        $selectedRefs = mail_api_selected_object_refs($pickerGroups);
        $opts = array(
            'to' => (string)($_POST['to'] ?? ''),
            'cc' => (string)($_POST['cc'] ?? ''),
            'bcc' => (string)($_POST['bcc'] ?? ''),
            'subject' => (string)($_POST['subject'] ?? ''),
            'text_body' => (string)($_POST['text_body'] ?? ''),
            'html_body' => (string)($_POST['html_body'] ?? ''),
            'thread_id' => $threadId > 0 ? $threadId : null,
            'created_by' => $uid > 0 ? $uid : null,
            'template_style' => (string)($_POST['template_style'] ?? 'standard'),
        );
        if ($draftId > 0) {
            ComplianceCommsCenterEngine::updateDraft($pdo, $draftId, $opts);
            $effectiveDraftId = $draftId;
        } else {
            $effectiveDraftId = ComplianceCommsCenterEngine::createDraft($pdo, $opts);
        }
        $effectiveThreadId = isset($opts['thread_id']) && (int)$opts['thread_id'] > 0 ? (int)$opts['thread_id'] : 0;
        if ($selectedRefs !== array() && $effectiveThreadId <= 0) {
            $thread = ComplianceCommsCenterEngine::resolveOrCreateThread(
                $pdo,
                null,
                null,
                null,
                ComplianceCommsCenterEngine::normalizeSubject((string)$opts['subject']),
                mail_api_primary_recipient((string)$opts['to'])
            );
            $effectiveThreadId = (int)$thread['id'];
            $opts['thread_id'] = $effectiveThreadId;
            ComplianceCommsCenterEngine::updateDraft($pdo, $effectiveDraftId, $opts);
        }
        mail_api_sync_thread_object_refs($pdo, $effectiveThreadId, $selectedRefs, $uid > 0 ? $uid : null);

        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ((int)$_FILES['attachments']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                ComplianceCommsCenterEngine::attachToDraft($pdo, $effectiveDraftId, array(
                    'name' => (string)$_FILES['attachments']['name'][$i],
                    'type' => (string)($_FILES['attachments']['type'][$i] ?? ''),
                    'tmp_name' => (string)$_FILES['attachments']['tmp_name'][$i],
                    'size' => (int)($_FILES['attachments']['size'][$i] ?? 0),
                    'error' => (int)$_FILES['attachments']['error'][$i],
                ), $uid > 0 ? $uid : null);
            }
        }

        if ($action === 'save_draft') {
            mail_api_json(array('ok' => true, 'draft_id' => $effectiveDraftId, 'thread_id' => $effectiveThreadId));
        }

        $sendOpts = $opts;
        $sendOpts['draft_id'] = $effectiveDraftId;
        if ($replyToEmailId > 0) {
            $sendOpts['reply_to_email_id'] = $replyToEmailId;
        }
        $result = ComplianceCommsCenterEngine::sendOutbound($pdo, $sendOpts);
        if (empty($result['ok'])) {
            mail_api_json(array('ok' => false, 'error' => (string)($result['error'] ?? 'Send failed.'), 'draft_id' => $effectiveDraftId), 400);
        }
        mail_api_json(array('ok' => true, 'draft_id' => $effectiveDraftId, 'thread_id' => (int)($result['thread_id'] ?? 0), 'email_id' => (int)($result['email_id'] ?? 0)));
    }
} catch (Throwable $e) {
    mail_api_json(array('ok' => false, 'error' => $e->getMessage()), 500);
}

mail_api_json(array('ok' => false, 'error' => 'Unknown mail action.'), 404);
