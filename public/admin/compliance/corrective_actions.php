<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceFindingEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCapEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuthorityDocumentService.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceRcaCapSubmissionEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceDeadlineExtensionEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * @param 'success'|'error' $type
 */
function cap_flash_set(string $type, string $message): void
{
    $_SESSION['_ipca_compliance_cap_flash'] = array(
        'type' => $type,
        'message' => $message,
    );
}

/**
 * @return array{type:string,message:string}|null
 */
function cap_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_cap_flash']) || !is_array($_SESSION['_ipca_compliance_cap_flash'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_cap_flash'];
    unset($_SESSION['_ipca_compliance_cap_flash']);
    return $f;
}

function cap_latest_effectiveness(PDO $pdo, int $capId, string $capStatus): string
{
    if (in_array(strtoupper($capStatus), array('VERIFIED', 'CLOSED'), true)) {
        return 'EFFECTIVE';
    }
    try {
        $st = $pdo->prepare(
            'SELECT effectiveness
               FROM ipca_compliance_effectiveness_reviews
              WHERE corrective_action_id = ?
              ORDER BY reviewed_at DESC, id DESC
              LIMIT 1'
        );
        $st->execute(array($capId));
        $value = trim((string)$st->fetchColumn());
        return $value !== '' ? $value : 'NOT_EVALUATED';
    } catch (Throwable) {
        return 'NOT_EVALUATED';
    }
}

function cap_days_delta(?string $deadline): string
{
    $deadline = $deadline !== null ? trim($deadline) : '';
    if ($deadline === '') {
        return '—';
    }
    $today = new DateTimeImmutable('today');
    $due = DateTimeImmutable::createFromFormat('Y-m-d', substr($deadline, 0, 10));
    if (!$due) {
        return '—';
    }
    $days = (int)$today->diff($due)->format('%r%a');
    if ($days === 0) {
        return 'Due today';
    }
    return $days > 0 ? ($days . ' days left') : (abs($days) . ' days passed');
}

function cap_deadline_display(?string $date): string
{
    $date = $date !== null ? trim($date) : '';
    if ($date === '') {
        return '<span style="color:var(--text-muted);">—</span>';
    }
    try {
        $today = new DateTimeImmutable('today');
        $target = new DateTimeImmutable(substr($date, 0, 10));
        $class = $target < $today ? 'compliance-badge--deadline-expired' : 'compliance-badge--deadline-ok';
    } catch (Throwable) {
        $class = 'compliance-badge--status-muted';
    }
    return '<div class="cmp-list-deadline">'
        . '<span class="cmp-pill compliance-badge ' . $class . '">' . h(substr($date, 0, 10)) . '</span>'
        . '</div>';
}

/** @param array{state:string,label:string,days:int|null,item:array<string,mixed>|null} $status */
function cap_deadline_status_display(array $status, ?string $approvedDeadline): string
{
    $state = (string)$status['state'];
    $item = is_array($status['item'] ?? null) ? $status['item'] : null;
    if ($state === 'extension_pending' && $item !== null) {
        $batchId = (int)($item['batch_id'] ?? 0);
        $cancelForm = $batchId > 0
            ? '<form method="post" action="/admin/compliance/corrective_actions.php" style="margin-top:6px;" onsubmit="return confirm(\'Cancel this pending deadline extension request?\');">'
                . '<input type="hidden" name="action" value="cancel_deadline_extension_batch">'
                . '<input type="hidden" name="batch_id" value="' . $batchId . '">'
                . '<button type="submit" class="cmp-btn-secondary" style="height:26px;min-height:26px;padding:0 9px;font-size:11px;border-radius:8px;">Cancel request</button>'
                . '</form>'
            : '';
        return '<div class="cmp-deadline-status">'
            . '<div>Approved deadline: <span class="cmp-pill compliance-badge compliance-badge--deadline-ok">' . h(substr((string)$item['previous_approved_deadline'], 0, 10)) . '</span></div>'
            . '<div>Proposed deadline: <span class="cmp-pill cmp-pill--deadline-pending">' . h(substr((string)$item['requested_deadline'], 0, 10)) . '</span></div>'
            . '<div><span class="cmp-pill cmp-pill--deadline-pending">Extension Pending</span></div>'
            . $cancelForm
            . '</div>';
    }
    if ($state === 'extension_approved' && $item !== null) {
        return '<div class="cmp-deadline-status">'
            . '<div>Approved extended deadline: <span class="cmp-pill cmp-pill--deadline-approved">' . h(substr((string)($item['approved_deadline'] ?: $item['requested_deadline']), 0, 10)) . '</span></div>'
            . '<div><span class="cmp-pill cmp-pill--deadline-approved">Extension Approved</span></div>'
            . '</div>';
    }
    if ($state === 'extension_rejected' && $item !== null) {
        return '<div class="cmp-deadline-status">'
            . '<div>Requested deadline: <span class="cmp-pill cmp-pill--deadline-rejected">' . h(substr((string)$item['requested_deadline'], 0, 10)) . '</span></div>'
            . '<div><span class="cmp-pill cmp-pill--deadline-rejected">Extension Rejected</span></div>'
            . '</div>';
    }
    $class = $state === 'overdue' ? 'cmp-pill--deadline-rejected' : ($state === 'warning' ? 'cmp-pill--deadline-warning' : 'compliance-badge--deadline-ok');
    return '<span class="cmp-pill compliance-badge ' . $class . '">' . h((string)$status['label']) . '</span>';
}

if (!empty($_SESSION['_ipca_compliance_cap_suggest']['saved_at'])
    && is_numeric($_SESSION['_ipca_compliance_cap_suggest']['saved_at'])
    && time() - (int)$_SESSION['_ipca_compliance_cap_suggest']['saved_at'] > 1800) {
    unset($_SESSION['_ipca_compliance_cap_suggest']);
}
if (!empty($_SESSION['_ipca_compliance_cap_email_preview']['saved_at'])
    && is_numeric($_SESSION['_ipca_compliance_cap_email_preview']['saved_at'])
    && time() - (int)$_SESSION['_ipca_compliance_cap_email_preview']['saved_at'] > 3600) {
    unset($_SESSION['_ipca_compliance_cap_email_preview']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_cap') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            $id = ComplianceCapEngine::create($pdo, array(
                'finding_id' => $fid,
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'action_type' => (string)($_POST['action_type'] ?? 'CORRECTIVE'),
                'status' => (string)($_POST['status'] ?? 'PROPOSED'),
                'effort' => (string)($_POST['effort'] ?? ''),
                'responsible_name' => (string)($_POST['responsible_name'] ?? ''),
                'due_date' => (string)($_POST['due_date'] ?? ''),
            ), $uid);
            cap_flash_set('success', 'Corrective action created.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $id);
        }

        if ($action === 'update_cap') {
            $cid = (int)($_POST['cap_id'] ?? 0);
            if ($cid <= 0) {
                throw new RuntimeException('Invalid action.');
            }
            ComplianceCapEngine::update($pdo, $cid, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'action_type' => (string)($_POST['action_type'] ?? ''),
                'status' => (string)($_POST['status'] ?? ''),
                'effort' => (string)($_POST['effort'] ?? ''),
                'responsible_name' => (string)($_POST['responsible_name'] ?? ''),
                'due_date' => (string)($_POST['due_date'] ?? ''),
                'closure_date' => (string)($_POST['closure_date'] ?? ''),
                'closure_evidence_note' => (string)($_POST['closure_evidence_note'] ?? ''),
            ), $uid);
            cap_flash_set('success', 'Corrective action saved.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }

        if ($action === 'upload_cap_evidence') {
            $cid = (int)($_POST['cap_id'] ?? 0);
            if ($cid <= 0) {
                throw new RuntimeException('Invalid action.');
            }
            ComplianceCapEngine::uploadEvidenceDocument($pdo, $cid, $_FILES['evidence_file'] ?? array(), array(
                'title' => (string)($_POST['evidence_title'] ?? ''),
                'description' => (string)($_POST['evidence_description'] ?? ''),
            ), $uid);
            cap_flash_set('success', 'Corrective action evidence uploaded.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }

        if ($action === 'attach_finding_document_evidence') {
            $cid = (int)($_POST['cap_id'] ?? 0);
            $docId = (int)($_POST['finding_document_id'] ?? 0);
            if ($cid <= 0 || $docId <= 0) {
                throw new RuntimeException('Select a corrective action and finding document.');
            }
            ComplianceCapEngine::attachFindingDocumentEvidence($pdo, $cid, $docId, (string)($_POST['attach_note'] ?? ''), $uid);
            cap_flash_set('success', 'Existing finding document attached as corrective action evidence.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }

        if ($action === 'request_extension') {
            $cid = (int)($_POST['cap_id'] ?? 0);
            if ($cid <= 0) {
                throw new RuntimeException('Invalid action.');
            }
            $cap = ComplianceCapEngine::getById($pdo, $cid);
            if ($cap === null) {
                throw new RuntimeException('Corrective action not found.');
            }
            $previous = trim((string)($_POST['previous_deadline'] ?? ''));
            if ($previous === '') {
                $previous = trim((string)($cap['due_date'] ?? ''));
            }
            ComplianceDeadlineExtensionEngine::requestCorrectiveActionExtension($pdo, $cid, array(
                'previous_deadline' => $previous,
                'requested_deadline' => (string)($_POST['requested_deadline'] ?? ''),
                'status' => (string)($_POST['extension_status'] ?? 'submitted'),
                'reason' => (string)($_POST['reason'] ?? ''),
                'review_notes' => (string)($_POST['review_notes'] ?? ''),
                'reviewed_by' => $uid,
            ));
            cap_flash_set('success', 'Deadline extension recorded.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }

        if ($action === 'create_deadline_extension_batch') {
            $capIds = $_POST['cap_ids'] ?? array();
            if (!is_array($capIds)) {
                $capIds = array();
            }
            $items = array();
            foreach ($capIds as $rawId) {
                $capId = (int)$rawId;
                if ($capId <= 0) {
                    continue;
                }
                $items[] = array(
                    'corrective_action_id' => $capId,
                    'requested_deadline' => (string)($_POST['requested_deadline'][$capId] ?? ''),
                    'explanation_category' => (string)($_POST['explanation_category'][$capId] ?? ''),
                    'explanation' => (string)($_POST['explanation'][$capId] ?? ''),
                );
            }
            $result = ComplianceDeadlineExtensionEngine::createExtensionBatch($pdo, $items, array(
                'request_type' => (string)($_POST['request_type'] ?? 'authority'),
                'recipient_email' => (string)($_POST['recipient_email'] ?? ''),
                'recipient_name' => (string)($_POST['recipient_name'] ?? ''),
                'summary_explanation' => (string)($_POST['summary_explanation'] ?? ''),
            ), $uid);
            $draft = ComplianceDeadlineExtensionEngine::createEmailDraftForBatch($pdo, (int)$result['batch_id'], (string)$result['review_url'], $uid);
            $_SESSION['_ipca_compliance_cap_email_preview'] = array(
                'saved_at' => time(),
                'batch_id' => (int)$result['batch_id'],
                'draft_id' => (int)$draft['draft_id'],
                'review_url' => (string)$draft['review_url'],
                'recipient_email' => (string)($draft['to'] ?? ($_POST['recipient_email'] ?? '')),
                'cc' => (string)($draft['cc'] ?? ''),
                'bcc' => (string)($draft['bcc'] ?? ''),
                'recipient_name' => (string)($_POST['recipient_name'] ?? ''),
                'subject' => (string)$draft['subject'],
                'body' => (string)$draft['body'],
            );
            cap_flash_set('success', 'Deadline extension request created and saved to the Draft Outbox.');
            redirect('/admin/compliance/corrective_actions.php');
        }

        if ($action === 'send_deadline_extension_email_draft') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $draftId = (int)($_POST['draft_id'] ?? 0);
            if ($batchId <= 0 || $draftId <= 0) {
                throw new RuntimeException('Missing extension request draft.');
            }
            $result = ComplianceDeadlineExtensionEngine::sendEmailDraftForBatch($pdo, $batchId, $draftId, $uid);
            if (empty($result['ok'])) {
                throw new RuntimeException('Send failed: ' . (string)($result['error'] ?? 'unknown error'));
            }
            unset($_SESSION['_ipca_compliance_cap_email_preview']);
            cap_flash_set('success', 'Deadline extension e-mail sent.');
            redirect('/admin/compliance/email_thread.php?id=' . (int)($result['thread_id'] ?? 0));
        }

        if ($action === 'cancel_deadline_extension_batch') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            ComplianceDeadlineExtensionEngine::cancelPendingBatch(
                $pdo,
                $batchId,
                $uid,
                'Cancelled manually because the outbound draft email is no longer available.'
            );
            if (isset($_SESSION['_ipca_compliance_cap_email_preview']['batch_id'])
                && (int)$_SESSION['_ipca_compliance_cap_email_preview']['batch_id'] === $batchId) {
                unset($_SESSION['_ipca_compliance_cap_email_preview']);
            }
            cap_flash_set('success', 'Pending deadline extension request cancelled.');
            redirect('/admin/compliance/corrective_actions.php');
        }

        if ($action === 'suggest_cap_ai') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Select a finding first.');
            }
            ComplianceCapEngine::suggestCapOptions($pdo, $fid, $uid);
            cap_flash_set('success', 'AI suggested CAP options — review and adopt one below.');
            redirect('/admin/compliance/corrective_actions.php?finding_id=' . $fid);
        }

        if ($action === 'adopt_cap_option') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            $idx = (int)($_POST['option_index'] ?? -1);
            $bundle = $_SESSION['_ipca_compliance_cap_suggest'] ?? null;
            if (!is_array($bundle) || (int)($bundle['finding_id'] ?? 0) !== $fid) {
                throw new RuntimeException('Suggestion session expired — run AI suggest again.');
            }
            $options = $bundle['options'] ?? null;
            if (!is_array($options) || !isset($options[$idx]) || !is_array($options[$idx])) {
                throw new RuntimeException('Invalid option.');
            }
            $aiRunId = isset($bundle['ai_run_id']) ? (int)$bundle['ai_run_id'] : null;
            if ($aiRunId !== null && $aiRunId <= 0) {
                $aiRunId = null;
            }
            $created = ComplianceCapEngine::adoptAiCapOption($pdo, $fid, $options[$idx], $uid, $aiRunId);
            cap_flash_set(
                'success',
                'Adopted option — created ' . count($created) . ' corrective action(s).'
            );
            redirect('/admin/compliance/corrective_actions.php?finding_id=' . $fid);
        }
    } catch (Throwable $e) {
        cap_flash_set('error', $e->getMessage());
        $cid = (int)($_POST['cap_id'] ?? 0);
        if ($cid > 0) {
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }
        $fid = (int)($_POST['finding_id'] ?? 0);
        if ($fid > 0) {
            redirect('/admin/compliance/corrective_actions.php?finding_id=' . $fid);
        }
        redirect('/admin/compliance/corrective_actions.php');
    }
}

$flash = cap_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterFinding = isset($_GET['finding_id']) ? (int)$_GET['finding_id'] : 0;
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$filterDue = isset($_GET['due']) ? trim((string)$_GET['due']) : '';
if (!in_array($filterDue, array('', 'overdue', 'due_soon', 'no_due'), true)) {
    $filterDue = '';
}

cw_header('Compliance · Corrective Actions');

$capStatsHero = array();
try {
    $capStatsHero = array(
        'open'    => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE UPPER(COALESCE(status,'')) NOT IN ('CLOSED','VERIFIED','CANCELLED','COMPLETED','EXECUTED')")->fetchColumn(),
        'overdue' => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE due_date IS NOT NULL AND due_date < CURDATE() AND UPPER(COALESCE(status,'')) NOT IN ('CLOSED','VERIFIED','CANCELLED','COMPLETED','EXECUTED')")->fetchColumn(),
        'in_progress' => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE status = 'IN_PROGRESS'")->fetchColumn(),
        'verified' => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE status = 'VERIFIED'")->fetchColumn(),
    );
} catch (Throwable) {
}

$optionsType = array(
    'CORRECTIVE' => 'Corrective',
    'PREVENTIVE' => 'Preventive',
    'CONTAINMENT' => 'Containment',
    'IMMEDIATE' => 'Immediate',
);
$optionsCapStatus = array(
    'DRAFT' => 'Draft',
    'PROPOSED' => 'Proposed',
    'AWAITING_APPROVAL' => 'Awaiting approval',
    'APPROVED' => 'Approved',
    'IN_PROGRESS' => 'In progress',
    'AWAITING_EVIDENCE' => 'Awaiting evidence',
    'EXECUTED' => 'Executed',
    'COMPLETED' => 'Completed',
    'VERIFIED' => 'Verified',
    'INEFFECTIVE' => 'Ineffective',
    'EFFECTIVENESS_FAILED' => 'Effectiveness failed',
    'CLOSED' => 'Closed',
    'OVERDUE' => 'Overdue',
    'EXTENDED' => 'Extended',
    'CANCELLED' => 'Cancelled',
);
$optionsEffort = array(
    '' => '—',
    'XS' => 'XS',
    'S' => 'S',
    'M' => 'M',
    'L' => 'L',
    'XL' => 'XL',
);

if ($detailId > 0) {
    compliance_page_open(array(
        'overline' => 'Compliance · CAP',
        'title' => 'Corrective action',
        'description' => 'Edit the CAP details, track progress and verification, and link communications.',
        'back' => array('href' => '/admin/compliance/corrective_actions.php', 'label' => 'All corrective actions'),
        'flash' => $flash,
    ));
} else {
    compliance_page_open(array(
        'overline' => 'Compliance',
        'title' => 'Corrective actions',
        'description' => 'CAP items per finding — optional AI suggestions (human adopt). Filter by finding or status to scope the queue.',
        'actions' => array(
            array('label' => 'New corrective action', 'modal' => 'cap-create-modal', 'icon' => 'plus'),
        ),
        'stats' => array(
            array('label' => 'Open',        'value' => $capStatsHero['open']        ?? 0, 'tone' => ($capStatsHero['open']    ?? 0) > 0 ? 'warn' : 'ok'),
            array('label' => 'Overdue',     'value' => $capStatsHero['overdue']     ?? 0, 'tone' => ($capStatsHero['overdue'] ?? 0) > 0 ? 'crit' : 'ok'),
            array('label' => 'In progress', 'value' => $capStatsHero['in_progress'] ?? 0),
            array('label' => 'Verified',    'value' => $capStatsHero['verified']    ?? 0, 'tone' => 'ok'),
        ),
        'flash' => $flash,
    ));
}

try {
    $findingsPick = ComplianceFindingEngine::listRecent($pdo, 200);
} catch (Throwable $e) {
    $findingsPick = array();
      echo '<p class="queue-status is-warn" style="padding:12px;">Could not load findings. '
        . h($e->getMessage()) . '</p>';
}

if ($detailId > 0) {
    try {
        $cap = ComplianceCapEngine::getById($pdo, $detailId);
    } catch (Throwable $e) {
        $cap = null;
        echo '<p class="queue-status is-danger">' . h($e->getMessage()) . '</p>';
    }

    if ($cap === null) {
        echo '<p>Corrective action not found.</p>';
        echo '<p><a class="nav-link" href="/admin/compliance/corrective_actions.php">← All actions</a></p>';
    } else {
        $capLocked = !empty($cap['locked_at']);
        $fidRow = (int)$cap['finding_id'];
        $submissionId = isset($cap['submission_id']) ? (int)$cap['submission_id'] : 0;
        $submission = $submissionId > 0 ? ComplianceRcaCapSubmissionEngine::getById($pdo, $submissionId) : null;
        $capExtensions = ComplianceDeadlineExtensionEngine::listForCorrectiveAction($pdo, (int)$cap['id']);
        $capEvidence = ComplianceCapEngine::listEvidence($pdo, (int)$cap['id']);
        $capAuditTrail = compliance_list_entity_events($pdo, 'corrective_action', (int)$cap['id'], 100);
        try {
            $findingDocumentsForEvidence = ComplianceAuthorityDocumentService::listFindingDocuments($pdo, $fidRow);
        } catch (Throwable) {
            $findingDocumentsForEvidence = array();
        }
        $effectiveDue = ComplianceDeadlineExtensionEngine::effectiveCorrectiveActionDeadline(
            $pdo,
            (int)$cap['id'],
            isset($cap['due_date']) ? (string)$cap['due_date'] : null
        );
        ?>
        <section class="cmp-card">
          <div class="cmp-list-head" style="margin-bottom:14px;">
            <div class="cmp-list-title">
              <?= compliance_ui_icon('tools') ?>
              <span><?= h((string)$cap['action_code']) ?></span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/findings.php?id=<?= $fidRow ?>" style="text-decoration:none;">Open finding</a>
              <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/export_rca_cap_pdf.php?finding_id=<?= $fidRow ?>" style="text-decoration:none;">Export PDF</a>
              <?php if (!$capLocked): ?>
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-open="cap-extension-modal">
                  Request extension
                </button>
              <?php endif; ?>
            </div>
          </div>
          <p class="cmp-meta-line">
            <span>Finding <strong><?= h(trim((string)($cap['finding_reference'] ?? '')) !== '' ? (string)$cap['finding_reference'] : (string)$cap['finding_code']) ?></strong></span>
            <span><?= h((string)$cap['finding_title']) ?></span>
          </p>
          <?php if ($capLocked): ?>
            <p class="queue-status is-warn">This row is locked.</p>
          <?php endif; ?>

          <form method="post" action="/admin/compliance/corrective_actions.php?id=<?= (int)$detailId ?>">
            <input type="hidden" name="action" value="update_cap">
            <input type="hidden" name="cap_id" value="<?= (int)$detailId ?>">

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Title *</span>
              <input name="title" required value="<?= h((string)$cap['title']) ?>"
                style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $capLocked ? 'disabled' : '' ?>>
            </label>

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Description *</span>
              <textarea name="description" required rows="6"
                style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $capLocked ? 'disabled' : '' ?>><?= h((string)$cap['description']) ?></textarea>
            </label>

            <div style="display:flex;flex-wrap:wrap;gap:16px;">
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Type</span>
                <select name="action_type" style="padding:8px;border-radius:8px;" <?= $capLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsType as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$cap['action_type'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Status</span>
                <select name="status" style="padding:8px;border-radius:8px;" <?= $capLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsCapStatus as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$cap['status'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Effort</span>
                <select name="effort" style="padding:8px;border-radius:8px;" <?= $capLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsEffort as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)($cap['effort'] ?? '') === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Due date</span>
                <input type="date" name="due_date"
                  value="<?= !empty($cap['due_date']) ? h(substr((string)$cap['due_date'], 0, 10)) : '' ?>"
                  style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                  <?= $capLocked ? 'disabled' : '' ?>>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Closure / execution date</span>
                <input type="date" name="closure_date"
                  value="<?= !empty($cap['completed_at']) ? h(substr((string)$cap['completed_at'], 0, 10)) : '' ?>"
                  style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                  <?= $capLocked ? 'disabled' : '' ?>>
              </label>
            </div>

            <label style="display:block;margin:16px 0;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Responsible (name)</span>
              <input name="responsible_name" value="<?= h((string)($cap['responsible_name'] ?? '')) ?>"
                style="width:100%;max-width:420px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $capLocked ? 'disabled' : '' ?>>
            </label>

            <?php if (!empty($cap['ai_assisted'])): ?>
              <p class="small" style="color:#64748b;font-size:13px;">Created with AI assistance (run logged in ipca_compliance_ai_runs).</p>
            <?php endif; ?>

            <?php if (!$capLocked): ?>
              <div class="cmp-flash is-warn" style="margin:16px 0;">
                <strong>Closure evidence</strong>
                <p style="margin:6px 0 10px;">When changing this corrective action to Executed, Completed, Verified, or Closed, add the evidence note below unless an evidence record already exists.</p>
                <label class="cmp-field compliance-field--full" style="margin:0;">
                  <span>Evidence note / closure rationale</span>
                  <textarea name="closure_evidence_note" rows="3" placeholder="Describe the evidence that proves this corrective action was implemented, e.g. document reviewed, authority acceptance, training record, procedure update, or operational verification."></textarea>
                </label>
              </div>
            <?php endif; ?>

            <?php if (!$capLocked): ?>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">
                Save
              </button>
            <?php endif; ?>
          </form>
        </section>
        <section class="cmp-card">
          <div class="cmp-card-head">
            <h2 class="cmp-card-title">Corrective Action Evidence</h2>
            <?php if (!$capLocked): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-open="cap-evidence-upload-modal">
                  Upload new Evidence Document
                </button>
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-open="cap-evidence-attach-modal">
                  Attach existing Finding Document
                </button>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($capEvidence === array()): ?>
            <p style="color:#64748b;font-size:14px;margin:0;">No corrective-action evidence has been recorded yet. Add an evidence note when closing or verifying this CAP.</p>
          <?php else: ?>
            <div class="compliance-table-wrap">
              <table class="compliance-table">
                <thead><tr><th style="width:72px;">Preview</th><th>Document</th><th style="width:150px;">Received</th><th>Notes</th><th style="width:110px;">Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($capEvidence as $ev): ?>
                    <?php
                      $uploadedUrl = trim((string)($ev['storage_relpath'] ?? '')) !== ''
                          ? '/admin/compliance/cap_evidence.php?id=' . (int)$ev['id']
                          : '';
                      $externalUrl = trim((string)($ev['external_url'] ?? ''));
                      $openUrl = $uploadedUrl !== '' ? $uploadedUrl : $externalUrl;
                    ?>
                    <tr>
                      <td>
                        <?php if ($openUrl !== ''): ?>
                          <a href="<?= h($openUrl) ?>" target="_blank" rel="noopener"
                            style="display:block;width:64px;height:78px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;overflow:hidden;text-decoration:none;">
                            <object data="<?= h($openUrl) ?>#page=1&toolbar=0&navpanes=0&scrollbar=0" type="application/pdf" width="64" height="78" style="pointer-events:none;">
                              <span style="display:flex;align-items:center;justify-content:center;width:64px;height:78px;color:#b91c1c;font-size:11px;font-weight:900;">PDF</span>
                            </object>
                          </a>
                        <?php else: ?>
                          <span style="display:flex;align-items:center;justify-content:center;width:64px;height:78px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#64748b;font-size:11px;font-weight:900;">NOTE</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <strong><?= h((string)($ev['title'] ?? 'Evidence')) ?></strong>
                        <div style="font-size:12px;color:#64748b;margin-top:3px;"><?= h((string)($ev['evidence_kind'] ?? 'NOTE')) ?></div>
                      </td>
                      <td class="cmp-mono"><?= h((string)($ev['uploaded_at'] ?? '')) ?></td>
                      <td><?= trim((string)($ev['description'] ?? '')) !== '' ? nl2br(h((string)$ev['description'])) : '<span style="color:#94a3b8;">—</span>' ?></td>
                      <td>
                        <?php if ($openUrl !== ''): ?>
                          <a class="cmp-btn-secondary cmp-btn-link" href="<?= h($openUrl) ?>" target="_blank" rel="noopener" style="height:30px;min-height:30px;padding:0 10px;font-size:12px;text-decoration:none;">Open</a>
                        <?php else: ?>
                          <span style="color:#94a3b8;font-size:12px;">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
        <?php if (!$capLocked): ?>
          <?php compliance_modal_open('cap-evidence-upload-modal', 'Upload new Evidence Document'); ?>
            <form method="post" enctype="multipart/form-data" action="/admin/compliance/corrective_actions.php?id=<?= (int)$detailId ?>">
              <input type="hidden" name="action" value="upload_cap_evidence">
              <input type="hidden" name="cap_id" value="<?= (int)$detailId ?>">
              <label class="cmp-field">
                <span>Evidence title</span>
                <input name="evidence_title" placeholder="e.g. Procedure update confirmation">
              </label>
              <label class="cmp-field">
                <span>Evidence note</span>
                <textarea name="evidence_description" rows="3" placeholder="Briefly describe how this proves CAP closure."></textarea>
              </label>
              <label class="cmpdoc-dropzone" data-cmpdoc-dropzone style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:28px 16px;border:2px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#475569;text-align:center;margin:12px 0;cursor:pointer;">
                <strong>Drop PDF here or click to browse</strong>
                <span style="font-size:12px;color:#64748b;">Corrective action evidence PDF, max 50 MiB.</span>
                <input type="file" name="evidence_file" accept="application/pdf,.pdf" required style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;">
                <span data-cmpdoc-filename style="margin-top:8px;font-size:12px;font-weight:800;color:#1e3c72;">No file selected</span>
              </label>
              <div class="compliance-modal__footer">
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
                <button type="submit">Upload document</button>
              </div>
            </form>
          <?php compliance_modal_close(); ?>

          <?php compliance_modal_open('cap-evidence-attach-modal', 'Attach existing Finding Document'); ?>
            <form method="post" action="/admin/compliance/corrective_actions.php?id=<?= (int)$detailId ?>">
              <input type="hidden" name="action" value="attach_finding_document_evidence">
              <input type="hidden" name="cap_id" value="<?= (int)$detailId ?>">
              <label class="cmp-field">
                <span>Finding document</span>
                <select name="finding_document_id" required>
                  <option value="">— Select document —</option>
                  <?php foreach ($findingDocumentsForEvidence as $doc): ?>
                    <option value="<?= (int)$doc['id'] ?>">
                      <?= h('#' . (string)$doc['id'] . ' · ' . (string)$doc['doc_kind'] . ' · ' . (string)$doc['original_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php if ($findingDocumentsForEvidence === array()): ?>
                <p style="margin:0 0 12px;color:#64748b;font-size:13px;">No finding documents are available yet. Upload one on the finding page or upload a CAP evidence PDF here.</p>
              <?php endif; ?>
              <label class="cmp-field">
                <span>Attach note</span>
                <textarea name="attach_note" rows="3" placeholder="Why this finding document proves the corrective action closure."></textarea>
              </label>
              <div class="compliance-modal__footer">
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
                <button type="submit" <?= $findingDocumentsForEvidence === array() ? 'disabled' : '' ?>>Attach document</button>
              </div>
            </form>
          <?php compliance_modal_close(); ?>

          <script>
            (function () {
              function openDialog(id) {
                var dialog = document.getElementById(id);
                if (!dialog) { return; }
                if (typeof dialog.showModal === 'function') {
                  dialog.showModal();
                } else {
                  dialog.setAttribute('open', 'open');
                }
              }
              document.querySelectorAll('[data-compliance-modal-open^="cap-evidence-"]').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                  ev.preventDefault();
                  openDialog(btn.getAttribute('data-compliance-modal-open'));
                });
              });
              document.querySelectorAll('dialog[id^="cap-evidence-"] [data-compliance-modal-close]').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                  ev.preventDefault();
                  var dialog = btn.closest('dialog');
                  if (!dialog) { return; }
                  if (typeof dialog.close === 'function') {
                    dialog.close();
                  }
                  dialog.removeAttribute('open');
                });
              });
              document.querySelectorAll('dialog[id^="cap-evidence-"] [data-cmpdoc-dropzone]').forEach(function (zone) {
                var input = zone.querySelector('input[type="file"]');
                var name = zone.querySelector('[data-cmpdoc-filename]');
                if (!input) { return; }
                zone.addEventListener('click', function () { input.click(); });
                zone.addEventListener('dragover', function (ev) { ev.preventDefault(); zone.style.borderColor = '#1e3c72'; zone.style.background = '#eef4ff'; });
                zone.addEventListener('dragleave', function () { zone.style.borderColor = '#cbd5e1'; zone.style.background = '#f8fafc'; });
                zone.addEventListener('drop', function (ev) {
                  ev.preventDefault();
                  zone.style.borderColor = '#cbd5e1';
                  zone.style.background = '#f8fafc';
                  if (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files.length > 0) {
                    input.files = ev.dataTransfer.files;
                    if (name) { name.textContent = ev.dataTransfer.files[0].name; }
                  }
                });
                input.addEventListener('change', function () {
                  if (name) { name.textContent = input.files && input.files[0] ? input.files[0].name : 'No file selected'; }
                });
              });
            })();
          </script>
        <?php endif; ?>
        <section class="cmp-card">
          <h2 style="margin:0 0 8px;font-size:20px;">Lifecycle history</h2>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-bottom:14px;">
            <div style="padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
              <div style="font-size:11px;text-transform:uppercase;color:#64748b;font-weight:800;">Submission</div>
              <div style="font-weight:800;color:#0f172a;">
                <?= $submissionId > 0 ? 'Submission #' . h((string)($submission['submission_no'] ?? $submissionId)) : 'Not versioned' ?>
              </div>
              <?php if (is_array($submission)): ?>
                <div style="margin-top:4px;"><?= compliance_badge((string)$submission['status']) ?></div>
              <?php endif; ?>
            </div>
            <div style="padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
              <div style="font-size:11px;text-transform:uppercase;color:#64748b;font-weight:800;">Effective deadline</div>
              <div><?= $effectiveDue !== null ? compliance_deadline_badge($effectiveDue) : '<span style="color:#64748b;">No deadline</span>' ?></div>
              <?php if (!$capLocked): ?>
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-open="cap-extension-modal" style="margin-top:10px;">
                  Request extension
                </button>
              <?php endif; ?>
            </div>
          </div>
          <h3 style="margin:0 0 8px;font-size:16px;">Audit trail</h3>
          <?php if ($capAuditTrail === array()): ?>
            <p style="color:#64748b;font-size:14px;margin:0 0 14px;">No audit trail events have been recorded for this corrective action yet.</p>
          <?php else: ?>
            <div class="compliance-table-wrap" style="margin-bottom:16px;">
              <table class="compliance-table">
                <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Details</th></tr></thead>
                <tbody>
                  <?php foreach ($capAuditTrail as $event): ?>
                    <?php
                      $before = json_decode((string)($event['before_json'] ?? ''), true);
                      $after = json_decode((string)($event['after_json'] ?? ''), true);
                      $details = array();
                      if (is_array($before) && is_array($after)) {
                          foreach (array('status', 'title', 'action_type') as $field) {
                              $old = array_key_exists($field, $before) ? (string)$before[$field] : '';
                              $new = array_key_exists($field, $after) ? (string)$after[$field] : '';
                              if ($old !== $new) {
                                  $details[] = $field . ': ' . ($old !== '' ? $old : '—') . ' → ' . ($new !== '' ? $new : '—');
                              }
                          }
                      }
                      $actor = trim((string)($event['actor_name'] ?? ''));
                      if ($actor === '') {
                          $actor = trim((string)($event['actor_email'] ?? ''));
                      }
                    ?>
                    <tr>
                      <td class="cmp-mono"><?= h((string)($event['occurred_at'] ?? '')) ?></td>
                      <td><?= compliance_badge((string)($event['event_kind'] ?? 'event')) ?></td>
                      <td><?= $actor !== '' ? h($actor) : '<span style="color:#94a3b8;">System</span>' ?></td>
                      <td>
                        <strong><?= h((string)($event['summary'] ?? '')) ?></strong>
                        <?php if ($details !== array()): ?>
                          <div style="margin-top:4px;color:#475569;"><?= h(implode(' · ', $details)) ?></div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <h3 style="margin:0 0 8px;font-size:16px;">Deadline extensions</h3>
          <?php if ($capExtensions === array()): ?>
            <p style="color:#64748b;font-size:14px;margin:0;">No deadline extensions have been recorded for this corrective action.</p>
          <?php else: ?>
            <div class="compliance-table-wrap">
              <table class="compliance-table">
                <thead><tr><th>#</th><th>Previous</th><th>Requested</th><th>Approved</th><th>Status</th><th>Reviewed</th></tr></thead>
                <tbody>
                  <?php foreach ($capExtensions as $ext): ?>
                    <tr>
                      <td class="cmp-mono"><?= (int)$ext['extension_no'] ?></td>
                      <td class="cmp-mono"><?= h(substr((string)$ext['previous_deadline'], 0, 10)) ?></td>
                      <td class="cmp-mono"><?= h(substr((string)$ext['requested_deadline'], 0, 10)) ?></td>
                      <td class="cmp-mono"><?= !empty($ext['approved_deadline']) ? h(substr((string)$ext['approved_deadline'], 0, 10)) : '—' ?></td>
                      <td><?= compliance_badge((string)$ext['status']) ?></td>
                      <td class="cmp-mono"><?= !empty($ext['reviewed_at']) ? h((string)$ext['reviewed_at']) : '—' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
        <?php if (!$capLocked): ?>
          <?php compliance_modal_open('cap-extension-modal', 'Request deadline extension'); ?>
            <form method="post" action="/admin/compliance/corrective_actions.php?id=<?= (int)$detailId ?>">
              <input type="hidden" name="action" value="request_extension">
              <input type="hidden" name="cap_id" value="<?= (int)$detailId ?>">
              <input type="hidden" name="previous_deadline" value="<?= h($effectiveDue ?? (string)($cap['due_date'] ?? '')) ?>">

              <label class="cmp-field">
                <span class="cmp-field-label">Current approved deadline</span>
                <input value="<?= h($effectiveDue ?? (string)($cap['due_date'] ?? 'No deadline')) ?>" disabled>
              </label>

              <label class="cmp-field">
                <span class="cmp-field-label">Requested deadline *</span>
                <input type="date" name="requested_deadline" required value="<?= h($effectiveDue ?? '') ?>">
              </label>

              <label class="cmp-field">
                <span class="cmp-field-label">Decision *</span>
                <select name="extension_status" required>
                  <option value="submitted" selected>Submitted</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                </select>
              </label>

              <label class="cmp-field">
                <span class="cmp-field-label">Reason *</span>
                <textarea name="reason" rows="4" required placeholder="Explain why the deadline extension is needed."></textarea>
              </label>

              <label class="cmp-field">
                <span class="cmp-field-label">Review notes</span>
                <textarea name="review_notes" rows="3" placeholder="Required for strong governance when approving or rejecting."></textarea>
              </label>

              <div class="compliance-modal__footer">
                <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
                <button type="submit">Record extension</button>
              </div>
            </form>
          <?php compliance_modal_close(); ?>
          <script>
            (function () {
              var modal = document.getElementById('cap-extension-modal');
              if (!modal) { return; }
              document.querySelectorAll('[data-compliance-modal-open="cap-extension-modal"]').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                  ev.preventDefault();
                  if (typeof modal.showModal === 'function') {
                    modal.showModal();
                  } else {
                    modal.setAttribute('open', 'open');
                  }
                });
              });
              modal.querySelectorAll('[data-compliance-modal-close]').forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                  ev.preventDefault();
                  if (typeof modal.close === 'function') {
                    modal.close();
                  }
                  modal.removeAttribute('open');
                });
              });
            })();
          </script>
        <?php endif; ?>
        <?php
        compliance_render_comms_panel($pdo, 'corrective_action', (string)$detailId);
    }
} else {
    $statusParam = $filterStatus !== '' ? $filterStatus : null;
    $findingParam = $filterFinding > 0 ? $filterFinding : null;
    try {
        $rows = ComplianceCapEngine::listRecent($pdo, $statusParam, $findingParam, 200);
    } catch (Throwable $e) {
        $rows = array();
        echo '<p class="queue-status is-danger">Could not load actions.<br>' . h($e->getMessage()) . '</p>';
    }
    if ($filterDue !== '') {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+' . ComplianceDeadlineExtensionEngine::WARNING_THRESHOLD_DAYS . ' days'));
        $rows = array_values(array_filter($rows, static function (array $r) use ($filterDue, $today, $soon): bool {
            $due = substr((string)($r['due_date'] ?? ''), 0, 10);
            if ($filterDue === 'no_due') {
                return $due === '';
            }
            if ($due === '') {
                return false;
            }
            if ($filterDue === 'overdue') {
                return $due < $today;
            }
            return $due >= $today && $due <= $soon;
        }));
    }

    $workflowStatus = ComplianceDeadlineExtensionEngine::workflowTableStatus($pdo);
    ComplianceDeadlineExtensionEngine::cancelOrphanedPendingEmailBatches($pdo, $uid);
    $latestExtensionItems = ComplianceDeadlineExtensionEngine::indexItemsByAction(ComplianceDeadlineExtensionEngine::latestWorkflowItemsByAction($pdo));
    $deadlineStatesByAction = array();
    $extensionSelectionRows = array();
    $approachingCount = 0;
    $overdueCount = 0;
    $pendingCount = 0;
    foreach ($rows as $r) {
        $capIdForStatus = (int)$r['id'];
        $effectiveDueForStatus = ComplianceDeadlineExtensionEngine::effectiveCorrectiveActionDeadline($pdo, $capIdForStatus, isset($r['due_date']) ? (string)$r['due_date'] : null);
        $deadlineStatus = ComplianceDeadlineExtensionEngine::calculateDeadlineStatus($effectiveDueForStatus, $latestExtensionItems[$capIdForStatus] ?? null);
        $deadlineStatesByAction[$capIdForStatus] = array('effective_due' => $effectiveDueForStatus, 'status' => $deadlineStatus);
        if ($deadlineStatus['state'] === 'warning') {
            $approachingCount++;
        } elseif ($deadlineStatus['state'] === 'overdue') {
            $overdueCount++;
        } elseif ($deadlineStatus['state'] === 'extension_pending') {
            $pendingCount++;
        }
        $isClosed = in_array(strtoupper((string)($r['status'] ?? '')), array('CLOSED','VERIFIED','CANCELLED','COMPLETED','EXECUTED'), true);
        $eligible = $workflowStatus['batches'] && $workflowStatus['items'] && $workflowStatus['tokens']
            && !$isClosed
            && $effectiveDueForStatus !== null
            && in_array($deadlineStatus['state'], array('warning', 'overdue', 'extension_rejected'), true);
        $extensionSelectionRows[$capIdForStatus] = array(
            'eligible' => $eligible,
            'disabled_reason' => $deadlineStatus['state'] === 'extension_pending' ? 'Extension already pending' : ($eligible ? '' : 'Not eligible for extension request'),
            'deadline_label' => (string)$deadlineStatus['label'],
        );
    }
    $emailPreview = is_array($_SESSION['_ipca_compliance_cap_email_preview'] ?? null) ? $_SESSION['_ipca_compliance_cap_email_preview'] : null;
    if ($emailPreview !== null) {
        if (empty($emailPreview['draft_id'])) {
            unset($_SESSION['_ipca_compliance_cap_email_preview']);
            $emailPreview = null;
        }
    }
    if ($emailPreview !== null) {
        try {
            $existingDraft = ComplianceCommsCenterEngine::getDraft($pdo, (int)$emailPreview['draft_id']);
            if (!is_array($existingDraft) || (string)($existingDraft['status'] ?? '') !== 'draft') {
                unset($_SESSION['_ipca_compliance_cap_email_preview']);
                $emailPreview = null;
            } elseif (trim((string)($existingDraft['text_body'] ?? '')) === ''
                && trim((string)($existingDraft['html_body'] ?? '')) === '') {
                ComplianceCommsCenterEngine::updateDraft($pdo, (int)$emailPreview['draft_id'], array(
                    'to' => (string)($emailPreview['recipient_email'] ?? ''),
                    'cc' => (string)($emailPreview['cc'] ?? ''),
                    'bcc' => (string)($emailPreview['bcc'] ?? ''),
                    'subject' => (string)($emailPreview['subject'] ?? ''),
                    'text_body' => (string)$emailPreview['body'],
                    'html_body' => '<div>' . nl2br(h((string)$emailPreview['body'])) . '</div>',
                    'thread_id' => isset($existingDraft['thread_id']) && (int)$existingDraft['thread_id'] > 0 ? (int)$existingDraft['thread_id'] : null,
                ));
            }
        } catch (Throwable) {
            unset($_SESSION['_ipca_compliance_cap_email_preview']);
            $emailPreview = null;
        }
    }

    $bundle = $_SESSION['_ipca_compliance_cap_suggest'] ?? null;
    $bundleFinding = is_array($bundle) ? (int)($bundle['finding_id'] ?? 0) : 0;
    $bundleOptions = (is_array($bundle) && isset($bundle['options']) && is_array($bundle['options']))
        ? $bundle['options'] : array();

    ?>
    <section class="cmp-card cmp-toolbar">
      <div class="cmp-toolbar-head">
        <div class="cmp-toolbar-title">
          <?= compliance_ui_icon('filter') ?>
          <span>Filter actions</span>
        </div>
        <div class="cmp-toolbar-meta">Scope by finding and status; latest activity first.</div>
      </div>
      <form method="get" action="/admin/compliance/corrective_actions.php" class="compliance-filterbar">
          <label class="cmp-field">
            <span>Finding</span>
            <select name="finding_id">
              <option value="">All findings</option>
              <?php foreach ($findingsPick as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $filterFinding === (int)$f['id'] ? 'selected' : '' ?>>
                  <?= h((string)$f['finding_code'] . ' — ' . (string)$f['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span>Status</span>
            <select name="status">
              <option value="">All</option>
              <?php foreach ($optionsCapStatus as $k => $lab): ?>
                <option value="<?= h($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span>Due state</span>
            <select name="due">
              <option value="" <?= $filterDue === '' ? 'selected' : '' ?>>Any due date</option>
              <option value="overdue" <?= $filterDue === 'overdue' ? 'selected' : '' ?>>Overdue</option>
              <option value="due_soon" <?= $filterDue === 'due_soon' ? 'selected' : '' ?>>Due within 7 days</option>
              <option value="no_due" <?= $filterDue === 'no_due' ? 'selected' : '' ?>>No due date</option>
            </select>
          </label>
        <div class="cmp-toolbar-actions" style="margin:0;">
          <button type="submit">Apply filters</button>
          <?php if ($filterStatus !== '' || $filterFinding > 0 || $filterDue !== ''): ?>
            <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/corrective_actions.php" style="text-decoration:none;">Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <?php if ($emailPreview !== null): ?>
      <section class="cmp-card" style="border-color:#bfdbfe;background:#eff6ff;">
        <div class="cmp-list-head" style="margin-bottom:10px;">
          <div class="cmp-list-title">
            <?= compliance_ui_icon('mail') ?>
            <span>Email draft generated</span>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <span class="cmp-pill compliance-badge compliance-badge--deadline-ok">Saved to drafts</span>
            <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/email_drafts.php?status=draft" style="text-decoration:none;">Draft Outbox</a>
            <?php if (!empty($emailPreview['draft_id'])): ?>
              <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/email_compose.php?draft_id=<?= (int)$emailPreview['draft_id'] ?>" style="text-decoration:none;">Edit draft</a>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:100px 1fr;gap:8px 14px;font-size:13px;">
          <strong>To</strong><span><?= h((string)$emailPreview['recipient_email']) ?></span>
          <?php if (!empty($emailPreview['cc'])): ?><strong>Cc</strong><span><?= h((string)$emailPreview['cc']) ?></span><?php endif; ?>
          <?php if (!empty($emailPreview['bcc'])): ?><strong>Bcc</strong><span><?= h((string)$emailPreview['bcc']) ?></span><?php endif; ?>
          <strong>Subject</strong><span><?= h((string)$emailPreview['subject']) ?></span>
          <strong>Review link</strong><a href="<?= h((string)$emailPreview['review_url']) ?>" target="_blank" rel="noopener"><?= h((string)$emailPreview['review_url']) ?></a>
        </div>
        <p style="margin:12px 0 0;color:#1e3a8a;font-size:13px;font-weight:700;">
          This draft will be sent through the Compliance Comms Center template wrapper with the standard header and footer.
        </p>
        <textarea readonly rows="12" style="margin-top:12px;width:100%;box-sizing:border-box;border:1px solid #bfdbfe;border-radius:12px;padding:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;"><?= h((string)$emailPreview['body']) ?></textarea>
        <?php if (!empty($emailPreview['draft_id']) && !empty($emailPreview['batch_id'])): ?>
          <form method="post" action="/admin/compliance/corrective_actions.php" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;"
                onsubmit="return confirm('Send this deadline extension request e-mail now?');">
            <input type="hidden" name="action" value="send_deadline_extension_email_draft">
            <input type="hidden" name="batch_id" value="<?= (int)$emailPreview['batch_id'] ?>">
            <input type="hidden" name="draft_id" value="<?= (int)$emailPreview['draft_id'] ?>">
            <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/email_compose.php?draft_id=<?= (int)$emailPreview['draft_id'] ?>" style="text-decoration:none;">Review in composer</a>
            <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:800;cursor:pointer;">Send now</button>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($approachingCount > 0 || $overdueCount > 0 || $pendingCount > 0): ?>
      <?php
        if ($approachingCount > 0 && $overdueCount > 0) {
            $warningCopy = 'Some corrective actions are approaching or have passed their deadlines. Close the actions soon or request a deadline extension.';
        } elseif ($overdueCount > 0) {
            $warningCopy = 'Some corrective actions are overdue. Immediate closure or an approved deadline extension is required.';
        } else {
            $warningCopy = 'Some corrective actions are approaching their deadlines. Close the actions soon or request a deadline extension.';
        }
      ?>
      <section class="cmp-card" style="border-color:#f59e0b;background:#fffbeb;">
        <div class="cmp-list-head" style="margin-bottom:8px;">
          <div class="cmp-list-title">
            <?= compliance_ui_icon('alert') ?>
            <span>Deadline attention required</span>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($approachingCount > 0): ?><span class="cmp-pill cmp-pill--deadline-warning"><?= (int)$approachingCount ?> approaching</span><?php endif; ?>
            <?php if ($overdueCount > 0): ?><span class="cmp-pill cmp-pill--deadline-rejected"><?= (int)$overdueCount ?> overdue</span><?php endif; ?>
            <?php if ($pendingCount > 0): ?><span class="cmp-pill cmp-pill--deadline-pending"><?= (int)$pendingCount ?> pending extension</span><?php endif; ?>
          </div>
        </div>
        <p style="margin:0 0 12px;color:#92400e;font-weight:700;"><?= h($warningCopy) ?></p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button type="button" class="cmp-btn-secondary" id="capBulkOpenFromWarning">Request Extension</button>
          <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/corrective_actions.php?due=due_soon" style="text-decoration:none;">Show affected actions</a>
        </div>
      </section>
    <?php endif; ?>

    <section class="cmp-card" id="capBulkToolbar" style="display:none;border-color:#bfdbfe;background:#eff6ff;">
      <div class="cmp-list-head">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('check') ?>
          <span><span id="capSelectedCount">0</span> selected</span>
        </div>
        <button type="button" id="capBulkOpen" style="background:#1e3c72;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:800;cursor:pointer;">
          Request Deadline Extension
        </button>
      </div>
    </section>

      <section class="cmp-card compliance-card--full" style="overflow:hidden;">
        <div class="compliance-table-wrap">
        <style>
          .cmp-page .cmp-cap-list-table th,
          .cmp-page .cmp-cap-list-table td,
          .cmp-page .cmp-cap-list-table td:first-child,
          .cmp-page .cmp-cap-list-table .cmp-mono,
          .cmp-page .cmp-cap-list-table td:first-child a,
          .cmp-page .cmp-cap-list-table .cmp-ref-link{
            font-family:var(--font-sans,Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif) !important;
            font-size:11.5px !important;
            color:#324155 !important;
            font-weight:650;
            letter-spacing:.01em;
          }
          .cmp-cap-list-table .cmp-ref-link{color:#324155 !important;font-weight:720;text-decoration:none;}
          .cmp-cap-list-table .cmp-list-titlecell{
            max-width:680px;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            line-height:1.35;
          }
          .cmp-cap-list-table .cmp-list-deadline{display:flex;flex-direction:column;gap:4px;align-items:flex-start;}
          .cmp-cap-list-table .cmp-list-date{font-size:11.5px;color:#324155;font-weight:650;}
          .cmp-cap-list-table .cmp-selector-cell{width:44px;text-align:center;}
          .cmp-deadline-status{display:flex;flex-direction:column;gap:5px;align-items:flex-start;}
          .cmp-pill--deadline-warning{border-color:#f59e0b !important;background:#fffbeb !important;color:#92400e !important;}
          .cmp-pill--deadline-pending{border:1px dashed #f59e0b !important;background:#fff7ed !important;color:#92400e !important;}
          .cmp-pill--deadline-approved{border-color:#86efac !important;background:#ecfdf5 !important;color:#166534 !important;}
          .cmp-pill--deadline-rejected{border-color:#fecaca !important;background:#fef2f2 !important;color:#991b1b !important;}
        </style>
        <table class="compliance-table cmp-cap-list-table">
          <thead>
            <tr>
              <th class="cmp-selector-cell"></th>
              <th style="width:143px;">Reference</th>
              <th style="width:143px;">Finding ref</th>
              <th>Title</th>
              <th style="width:116px;">Type</th>
              <th style="width:127px;">Status</th>
              <th style="width:127px;">Effectiveness</th>
              <th style="width:165px;">Deadline</th>
              <th style="width:210px;">Deadline Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" style="padding:20px;color:#64748b;">No matching actions.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $effectiveDue = $deadlineStatesByAction[(int)$r['id']]['effective_due'] ?? ComplianceDeadlineExtensionEngine::effectiveCorrectiveActionDeadline($pdo, (int)$r['id'], isset($r['due_date']) ? (string)$r['due_date'] : null);
                $deadlineStatus = $deadlineStatesByAction[(int)$r['id']]['status'] ?? ComplianceDeadlineExtensionEngine::calculateDeadlineStatus($effectiveDue, $latestExtensionItems[(int)$r['id']] ?? null);
                $selectMeta = $extensionSelectionRows[(int)$r['id']] ?? array('eligible' => false, 'disabled_reason' => 'Not eligible for extension request');
                $eff = cap_latest_effectiveness($pdo, (int)$r['id'], (string)$r['status']);
                $findingRef = trim((string)($r['finding_reference'] ?? '')) !== '' ? (string)$r['finding_reference'] : (string)$r['finding_code'];
                ?>
              <tr data-href="/admin/compliance/corrective_actions.php?id=<?= (int)$r['id'] ?>" class="compliance-row-clickable">
                <td class="cmp-selector-cell">
                  <?php if (!empty($selectMeta['eligible'])): ?>
                    <input type="checkbox"
                      class="cap-extension-select"
                      value="<?= (int)$r['id'] ?>"
                      data-action-code="<?= h((string)$r['action_code']) ?>"
                      data-finding-reference="<?= h($findingRef) ?>"
                      data-title="<?= h((string)$r['title']) ?>"
                      data-current-deadline="<?= h((string)$effectiveDue) ?>"
                      data-deadline-label="<?= h((string)$deadlineStatus['label']) ?>">
                  <?php else: ?>
                    <span title="<?= h((string)($selectMeta['disabled_reason'] ?? 'Not eligible')) ?>" style="color:#cbd5e1;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="cmp-ref-link" href="/admin/compliance/corrective_actions.php?id=<?= (int)$r['id'] ?>">
                    <?= h((string)$r['action_code']) ?>
                  </a>
                </td>
                <td>
                  <a class="cmp-ref-link" href="/admin/compliance/findings.php?id=<?= (int)$r['finding_id'] ?>">
                    <?= h($findingRef) ?>
                  </a>
                </td>
                <td><span class="cmp-list-titlecell"><?= h((string)$r['title']) ?></span></td>
                <td><?= compliance_badge((string)$r['action_type']) ?></td>
                <td><?= compliance_badge((string)$r['status']) ?></td>
                <td><?= compliance_badge($eff) ?></td>
                <td><?= cap_deadline_display($effectiveDue) ?></td>
                <td><?= cap_deadline_status_display($deadlineStatus, $effectiveDue) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>

      <?php compliance_modal_open('cap-bulk-extension-modal', 'Request Corrective Action Deadline Extension'); ?>
        <form method="post" action="/admin/compliance/corrective_actions.php" id="capBulkExtensionForm">
          <input type="hidden" name="action" value="create_deadline_extension_batch">
          <p style="margin:0 0 14px;color:#64748b;font-size:13px;">
            Proposed deadlines remain pending until the authority/internal reviewer approves the request from the secure review link.
          </p>
          <div id="capBulkExtensionRows" style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px;"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <label class="cmp-field">
              <span class="cmp-field-label">Recipient type</span>
              <select name="request_type">
                <option value="authority">Authority / external</option>
                <option value="internal">Internal reviewer</option>
              </select>
            </label>
            <label class="cmp-field">
              <span class="cmp-field-label">Recipient name</span>
              <input name="recipient_name" placeholder="Reviewer name">
            </label>
          </div>
          <label class="cmp-field">
            <span class="cmp-field-label">Fallback reviewer email</span>
            <input type="email" name="recipient_email" placeholder="Used only when no Lead Auditor is configured">
            <small style="display:block;margin-top:4px;color:#64748b;">The draft uses the audit Lead Auditor as To, Auditors/Specialists as Cc, and you as Bcc.</small>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Collective summary explanation</span>
            <textarea name="summary_explanation" rows="3" placeholder="Optional summary for the overall request."></textarea>
          </label>
          <div class="compliance-modal__footer">
            <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
            <button type="submit">Create request and email draft</button>
          </div>
        </form>
      <?php compliance_modal_close(); ?>

      <?php compliance_modal_open('cap-create-modal', 'New corrective action'); ?>
          <form method="post" action="/admin/compliance/corrective_actions.php">
            <input type="hidden" name="action" value="create_cap">

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Finding *</span>
              <select name="finding_id" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                <?php foreach ($findingsPick as $f): ?>
                  <option value="<?= (int)$f['id'] ?>" <?= ($filterFinding === (int)$f['id']) ? 'selected' : '' ?>>
                    <?= h((string)$f['finding_code'] . ' — ' . (string)$f['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
              <input name="title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Description *</span>
              <textarea name="description" required rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
            </label>

            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
              <label>
                <span style="font-size:11px;font-weight:700;color:#64748b;">Type</span>
                <select name="action_type" style="width:100%;padding:8px;border-radius:8px;">
                  <?php foreach ($optionsType as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= $k === 'CORRECTIVE' ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="font-size:11px;font-weight:700;color:#64748b;">Status</span>
                <select name="status" style="width:100%;padding:8px;border-radius:8px;">
                  <option value="PROPOSED" selected>Proposed</option>
                  <?php foreach ($optionsCapStatus as $k => $lab): ?>
                    <?php if ($k === 'PROPOSED') {
                        continue;
                    } ?>
                    <option value="<?= h($k) ?>"><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Due date</span>
              <input type="date" name="due_date" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>

            <div class="compliance-modal__footer">
              <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
              <button type="submit">Create action</button>
            </div>
          </form>
      <?php compliance_modal_close(); ?>

      <script>
        (function () {
          var checks = Array.prototype.slice.call(document.querySelectorAll('.cap-extension-select'));
          var toolbar = document.getElementById('capBulkToolbar');
          var countEl = document.getElementById('capSelectedCount');
          var modal = document.getElementById('cap-bulk-extension-modal');
          var rowsEl = document.getElementById('capBulkExtensionRows');
          var openBtn = document.getElementById('capBulkOpen');
          var warningBtn = document.getElementById('capBulkOpenFromWarning');
          var categories = [
            'Awaiting authority input',
            'Supplier delay',
            'Resource limitation',
            'Technical issue',
            'Aircraft/FSTD downtime',
            'Manual revision pending',
            'Dependency on another corrective action',
            'External contractor delay',
            'Other'
          ];
          function selected() {
            return checks.filter(function (box) { return box.checked; });
          }
          function refreshToolbar() {
            var count = selected().length;
            if (countEl) { countEl.textContent = String(count); }
            if (toolbar) { toolbar.style.display = count > 0 ? '' : 'none'; }
          }
          function addDays(dateText, days) {
            var parts = String(dateText || '').split('-');
            if (parts.length !== 3) { return ''; }
            var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
            d.setDate(d.getDate() + days);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
          }
          function field(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            return input;
          }
          function openModalFromSelection() {
            var chosen = selected();
            if (!chosen.length) {
              alert('Select at least one eligible corrective action first.');
              return;
            }
            rowsEl.innerHTML = '';
            chosen.forEach(function (box) {
              var id = box.value;
              var current = box.getAttribute('data-current-deadline') || '';
              var card = document.createElement('div');
              card.style.cssText = 'border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:12px;';
              card.appendChild(field('cap_ids[]', id));
              card.innerHTML += ''
                + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">'
                + '<span class="cmp-pill compliance-badge">' + escapeHtml(box.getAttribute('data-action-code') || '') + '</span>'
                + '<span class="cmp-pill compliance-badge">' + escapeHtml(box.getAttribute('data-finding-reference') || '') + '</span>'
                + '<span class="cmp-pill cmp-pill--deadline-warning">' + escapeHtml(box.getAttribute('data-deadline-label') || '') + '</span>'
                + '</div>'
                + '<div style="font-weight:800;color:#0f172a;margin-bottom:8px;">' + escapeHtml(box.getAttribute('data-title') || '') + '</div>'
                + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
                + '<label class="cmp-field"><span class="cmp-field-label">Current approved deadline</span><input value="' + escapeHtml(current) + '" disabled></label>'
                + '<label class="cmp-field"><span class="cmp-field-label">Proposed new deadline *</span><input type="date" name="requested_deadline[' + id + ']" required min="' + escapeHtml(addDays(current, 1)) + '" value="' + escapeHtml(addDays(current, 14)) + '"></label>'
                + '</div>';
              var category = document.createElement('label');
              category.className = 'cmp-field';
              category.innerHTML = '<span class="cmp-field-label">Explanation category</span>';
              var select = document.createElement('select');
              select.name = 'explanation_category[' + id + ']';
              categories.forEach(function (label) {
                var opt = document.createElement('option');
                opt.value = label;
                opt.textContent = label;
                select.appendChild(opt);
              });
              category.appendChild(select);
              card.appendChild(category);
              var explanation = document.createElement('label');
              explanation.className = 'cmp-field';
              explanation.innerHTML = '<span class="cmp-field-label">Explanation *</span><textarea name="explanation[' + id + ']" rows="3" required placeholder="Explain why this corrective action needs a deadline extension."></textarea>';
              card.appendChild(explanation);
              rowsEl.appendChild(card);
            });
            if (typeof modal.showModal === 'function') {
              modal.showModal();
            } else {
              modal.setAttribute('open', 'open');
            }
          }
          function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (c) {
              return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];
            });
          }
          checks.forEach(function (box) {
            box.addEventListener('click', function (ev) { ev.stopPropagation(); });
            box.addEventListener('change', refreshToolbar);
          });
          if (openBtn) { openBtn.addEventListener('click', openModalFromSelection); }
          if (warningBtn) {
            warningBtn.addEventListener('click', function () {
              if (!selected().length) {
                checks.forEach(function (box) { box.checked = true; });
                refreshToolbar();
              }
              openModalFromSelection();
            });
          }
          if (modal) {
            modal.querySelectorAll('[data-compliance-modal-close]').forEach(function (btn) {
              btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (typeof modal.close === 'function') { modal.close(); }
                modal.removeAttribute('open');
              });
            });
          }
          refreshToolbar();
        })();
      </script>

        <section class="cmp-card">
          <h3 style="margin:0 0 8px;">AI: suggest CAP options</h3>
          <p class="cmp-card-sub" style="margin:0 0 14px;">
            Generates A/B/C options (logged as <code>CAP_SUGGEST</code>). RCA improves quality but is not required.
          </p>
          <form method="post" action="/admin/compliance/corrective_actions.php">
            <input type="hidden" name="action" value="suggest_cap_ai">
            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Finding *</span>
              <select name="finding_id" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                <?php foreach ($findingsPick as $f): ?>
                  <option value="<?= (int)$f['id'] ?>" <?= ($filterFinding === (int)$f['id']) ? 'selected' : '' ?>>
                    <?= h((string)$f['finding_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" style="width:100%;" onclick="return confirm('Request CAP options from AI?');">
              Run AI suggest
            </button>
          </form>

          <?php
          if ($bundleFinding > 0 && $bundleOptions !== array()
                && ($filterFinding <= 0 || $filterFinding === $bundleFinding)):
              ?>
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid #e2e8f0;">
              <h4 style="margin:0 0 10px;font-size:14px;">Adopt an option</h4>
              <?php foreach ($bundleOptions as $idx => $opt):
                  if (!is_array($opt)) {
                      continue;
                  }
                  $lab = (string)($opt['label'] ?? ('Option ' . $idx));
                  $eff = (string)($opt['effort'] ?? '');
                  $acts = $opt['actions'] ?? array();
                  ?>
                <div style="margin-bottom:14px;padding:12px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                  <div style="font-weight:800;color:#1e3c72;margin-bottom:8px;"><?= h($lab) ?><?php
                    echo $eff !== '' ? ' — ' . h($eff) : '';
                  ?></div>
                  <?php if (is_array($acts)): ?>
                    <ul style="margin:0;padding-left:18px;font-size:13px;color:#475569;">
                      <?php foreach ($acts as $a): ?>
                        <?php if (!is_array($a)) {
                            continue;
                        } ?>
                        <li><?= h((string)($a['description'] ?? '')) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <form method="post" action="/admin/compliance/corrective_actions.php?finding_id=<?= (int)$bundleFinding ?>" style="margin-top:10px;">
                    <input type="hidden" name="action" value="adopt_cap_option">
                    <input type="hidden" name="finding_id" value="<?= (int)$bundleFinding ?>">
                    <input type="hidden" name="option_index" value="<?= (int)$idx ?>">
                    <button type="submit" class="cmp-btn-success">Adopt &amp; create actions</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
    <?php
}

compliance_page_close();
cw_footer();
