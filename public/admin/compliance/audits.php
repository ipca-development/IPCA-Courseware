<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuditEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuthorityDocumentService.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceChecklistEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function aud_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_audits'] = array('type' => $type, 'message' => $msg);
}

function aud_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_audits']) || !is_array($_SESSION['_ipca_compliance_flash_audits'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_audits'];
    unset($_SESSION['_ipca_compliance_flash_audits']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_audit') {
            $id = ComplianceAuditEngine::create($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'authority' => (string)($_POST['authority'] ?? 'INTERNAL'),
                'audit_category' => (string)($_POST['audit_category'] ?? ''),
                'audit_type' => (string)($_POST['audit_type'] ?? 'INTERNAL'),
                'audit_entity' => (string)($_POST['audit_entity'] ?? ''),
                'external_ref' => (string)($_POST['external_ref'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'PLANNED'),
                'subject' => (string)($_POST['subject'] ?? ''),
                'start_date' => (string)($_POST['start_date'] ?? ''),
                'end_date' => (string)($_POST['end_date'] ?? ''),
            ), $uid);
            aud_flash('success', 'Audit created.');
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
        if ($action === 'update_audit') {
            $id = (int)($_POST['audit_id'] ?? 0);
            ComplianceAuditEngine::update($pdo, $id, array(
                'title' => (string)($_POST['title'] ?? ''),
                'authority' => (string)($_POST['authority'] ?? 'INTERNAL'),
                'audit_category' => (string)($_POST['audit_category'] ?? ''),
                'audit_type' => (string)($_POST['audit_type'] ?? 'INTERNAL'),
                'audit_entity' => (string)($_POST['audit_entity'] ?? ''),
                'external_ref' => (string)($_POST['external_ref'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'PLANNED'),
                'subject' => (string)($_POST['subject'] ?? ''),
                'start_date' => (string)($_POST['start_date'] ?? ''),
                'end_date' => (string)($_POST['end_date'] ?? ''),
            ), $uid);
            aud_flash('success', 'Audit saved.');
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
        if ($action === 'close_audit') {
            $id = (int)($_POST['audit_id'] ?? 0);
            ComplianceAuditEngine::close($pdo, $id, $uid, (string)($_POST['closed_date'] ?? ''));
            aud_flash('success', 'Audit closed and locked.');
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
        if ($action === 'save_audit_contacts') {
            $id = (int)($_POST['audit_id'] ?? 0);
            $names = $_POST['contact_name'] ?? array();
            $emails = $_POST['contact_email'] ?? array();
            $positions = $_POST['contact_position'] ?? array();
            if (!is_array($names)) {
                $names = array();
            }
            if (!is_array($emails)) {
                $emails = array();
            }
            if (!is_array($positions)) {
                $positions = array();
            }
            $contacts = array();
            $max = max(count($names), count($emails), count($positions));
            for ($i = 0; $i < $max; $i++) {
                $contacts[] = array(
                    'display_name' => (string)($names[$i] ?? ''),
                    'email' => (string)($emails[$i] ?? ''),
                    'position' => (string)($positions[$i] ?? 'AUDITOR'),
                );
            }
            ComplianceAuditEngine::saveAuditContacts($pdo, $id, $contacts, $uid);
            aud_flash('success', 'Audit contacts saved.');
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
        if ($action === 'attach_snapshot') {
            $id = (int)($_POST['audit_id'] ?? 0);
            $vid = (int)($_POST['version_id'] ?? 0);
            $r = ComplianceChecklistEngine::createSnapshotForAudit($pdo, $id, $vid, $uid);
            require_once __DIR__ . '/../../../src/compliance/ComplianceAutomationDispatch.php';
            if ($r['created']) {
                ComplianceAutomationDispatch::fire($pdo, 'compliance.audit.checklist_attached', array(
                    'audit_id' => $id,
                    'snapshot_id' => $r['snapshot_id'],
                    'items_count' => $r['items_count'],
                    'attached_by_user_id' => $uid,
                ));
                aud_flash('success', 'Checklist snapshot attached (' . $r['items_count'] . ' items).');
            } else {
                aud_flash('warn', 'A snapshot for this template already exists on this audit.');
            }
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
        if ($action === 'upload_audit_document') {
            $id = (int)($_POST['audit_id'] ?? 0);
            ComplianceAuthorityDocumentService::uploadAuditDocument($pdo, $id, $_FILES['document_file'] ?? array(), array(
                'doc_kind' => (string)($_POST['doc_kind'] ?? 'AUDIT_REPORT'),
                'received_on' => (string)($_POST['received_on'] ?? ''),
                'notes' => (string)($_POST['notes'] ?? ''),
            ), $uid);
            aud_flash('success', 'Audit document uploaded.');
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
    } catch (Throwable $e) {
        aud_flash('error', $e->getMessage());
        $id = (int)($_POST['audit_id'] ?? 0);
        if ($id > 0) {
            redirect('/admin/compliance/audits.php?id=' . $id);
        }
        redirect('/admin/compliance/audits.php');
    }
}

$flash = aud_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

cw_header('Compliance · Audits');

if ($detailId > 0) {
    $audit = ComplianceAuditEngine::getById($pdo, $detailId);
    if ($audit === null) {
        compliance_page_open(array(
            'overline' => 'Compliance',
            'title' => 'Audit not found',
            'description' => 'The audit you requested could not be located. It may have been deleted or you may not have access.',
            'flash' => $flash,
            'back' => array('href' => '/admin/compliance/audits.php', 'label' => 'All audits'),
        ));
        compliance_page_close();
    } else {
        $locked = !empty($audit['locked_at']);
        $snapshots = ComplianceChecklistEngine::listSnapshotsForAudit($pdo, $detailId);
        $auditContacts = ComplianceAuditEngine::listAuditContacts($pdo, $detailId);
        try {
            $auditDocuments = ComplianceAuthorityDocumentService::listAuditDocuments($pdo, $detailId);
        } catch (Throwable) {
            $auditDocuments = array();
        }
        $templates = ComplianceChecklistEngine::listTemplates($pdo);
        $approvedVersions = array();
        foreach ($templates as $t) {
            $cvid = isset($t['current_version_id']) ? (int)$t['current_version_id'] : 0;
            if ($cvid > 0) {
                $approvedVersions[] = array(
                    'template_id' => (int)$t['id'],
                    'template_code' => (string)$t['template_code'],
                    'template_title' => (string)$t['title'],
                    'version_id' => $cvid,
                );
            }
        }

        $statusBits = array();
        if (!empty($audit['authority'])) { $statusBits[] = (string)$audit['authority']; }
        if (!empty($audit['audit_type'])) { $statusBits[] = (string)$audit['audit_type']; }
        if (!empty($audit['status'])) { $statusBits[] = compliance_friendly_label((string)$audit['status']); }
        if ($locked) { $statusBits[] = 'Locked'; }

        compliance_page_open(array(
            'overline' => 'Audit · ' . (string)$audit['audit_code'],
            'title' => (string)$audit['title'],
            'description' => implode('  ·  ', $statusBits),
            'flash' => $flash,
            'back' => array(
                'href' => '/admin/compliance/audits.php',
                'label' => 'All audits',
                'code' => (string)$audit['audit_code'],
            ),
            'actions' => array(
                array('label' => 'Upload new Audit Document', 'modal' => 'audit-document-upload-modal', 'icon' => 'plus'),
            ),
        ));
        ?>

        <section class="cmp-card">
          <div class="cmp-card-head">
            <h2 class="cmp-card-title">Audit details</h2>
            <?php if ($locked): ?>
              <?= compliance_badge('LOCKED') ?>
            <?php endif; ?>
          </div>

          <?php if ($locked): ?>
            <dl class="cmp-dl">
              <dt>Entity</dt><dd><?= h((string)($audit['audit_entity'] ?? '—')) ?></dd>
              <dt>External ref</dt><dd><?= h((string)($audit['external_ref'] ?? '—')) ?></dd>
              <dt>Start</dt><dd><?= h((string)($audit['start_date'] ?? '—')) ?></dd>
              <dt>End</dt><dd><?= h((string)($audit['end_date'] ?? '—')) ?></dd>
              <dt>Closed</dt><dd><?= h((string)($audit['closed_date'] ?? '—')) ?></dd>
              <dt>Subject</dt><dd><?= nl2br(h((string)($audit['subject'] ?? '—'))) ?></dd>
            </dl>
            <style>
              .cmp-dl{display:grid;grid-template-columns:200px 1fr;gap:10px 20px;margin:0;font-size:14px;}
              .cmp-dl dt{color:var(--text-muted);font-weight:660;}
              .cmp-dl dd{margin:0;color:var(--text-strong);font-weight:580;}
            </style>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="action" value="update_audit">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">

              <label class="cmp-field" style="margin-bottom:14px;max-width:720px;">
                <span>Title *</span>
                <input name="title" required value="<?= h((string)$audit['title']) ?>">
              </label>

              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:14px;">
                <label class="cmp-field">
                  <span>Authority</span>
                  <select name="authority">
                    <?php foreach (ComplianceAuditEngine::authorities() as $a): ?>
                      <option value="<?= h($a) ?>" <?= ((string)$audit['authority'] === $a) ? 'selected' : '' ?>><?= h($a) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="cmp-field">
                  <span>Category</span>
                  <select name="audit_category">
                    <option value="">—</option>
                    <?php foreach (ComplianceAuditEngine::categories() as $c): ?>
                      <option value="<?= h($c) ?>" <?= ((string)($audit['audit_category'] ?? '') === $c) ? 'selected' : '' ?>><?= h($c) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="cmp-field">
                  <span>Type</span>
                  <input name="audit_type" value="<?= h((string)$audit['audit_type']) ?>">
                </label>
                <label class="cmp-field">
                  <span>Status</span>
                  <select name="status">
                    <?php foreach (ComplianceAuditEngine::statuses() as $s): ?>
                      <option value="<?= h($s) ?>" <?= ((string)$audit['status'] === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>

              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:14px;">
                <label class="cmp-field">
                  <span>Entity</span>
                  <input name="audit_entity" value="<?= h((string)($audit['audit_entity'] ?? '')) ?>">
                </label>
                <label class="cmp-field">
                  <span>External ref</span>
                  <input name="external_ref" value="<?= h((string)($audit['external_ref'] ?? '')) ?>">
                </label>
                <label class="cmp-field">
                  <span>Start date</span>
                  <input type="date" name="start_date" value="<?= h(substr((string)($audit['start_date'] ?? ''), 0, 10)) ?>">
                </label>
                <label class="cmp-field">
                  <span>End date</span>
                  <input type="date" name="end_date" value="<?= h(substr((string)($audit['end_date'] ?? ''), 0, 10)) ?>">
                </label>
              </div>

              <label class="cmp-field" style="margin-bottom:18px;max-width:720px;">
                <span>Subject / scope</span>
                <textarea name="subject" rows="4"><?= h((string)($audit['subject'] ?? '')) ?></textarea>
              </label>

              <button type="submit">Save audit</button>
            </form>

            <form method="post" style="margin-top:18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;"
              onsubmit="return confirm('Close and lock this audit?');">
              <input type="hidden" name="action" value="close_audit">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
              <label class="cmp-field">
                <span>Closed date</span>
                <input type="date" name="closed_date" value="<?= h(date('Y-m-d')) ?>">
              </label>
              <button type="submit" class="cmp-btn cmp-btn-success">Close audit</button>
            </form>
          <?php endif; ?>
        </section>

        <section class="cmp-card">
          <div class="cmp-card-head">
            <h3 class="cmp-card-title">Authority Audit Reports</h3>
            <button type="button" class="cmp-btn-secondary" data-compliance-modal-open="audit-document-upload-modal">
              Upload new Audit Document
            </button>
          </div>
          <?php if ($auditDocuments === array()): ?>
            <p style="margin:0;color:var(--text-muted);">No authority audit reports uploaded yet.</p>
          <?php else: ?>
            <div class="compliance-table-wrap">
              <table class="compliance-table">
                <thead><tr><th style="width:72px;">Preview</th><th>Document</th><th style="width:150px;">Received</th><th>Notes</th></tr></thead>
                <tbody>
                  <?php foreach ($auditDocuments as $doc): ?>
                    <tr data-href="/admin/compliance/document.php?scope=audit&id=<?= (int)$doc['id'] ?>" class="compliance-row-clickable">
                      <td>
                        <a href="/admin/compliance/document.php?scope=audit&id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener"
                          style="display:inline-flex;align-items:center;justify-content:center;width:46px;height:58px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#b91c1c;font-size:11px;font-weight:900;text-decoration:none;">
                          PDF
                        </a>
                      </td>
                      <td>
                        <a href="/admin/compliance/document.php?scope=audit&id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener"
                          style="color:#1e3c72;font-weight:800;text-decoration:none;">
                          <?= h(ComplianceAuthorityDocumentService::friendlyKind('audit', (string)$doc['doc_kind'])) ?>
                        </a>
                        <div style="font-size:12px;color:#64748b;margin-top:3px;"><?= h((string)$doc['original_name']) ?></div>
                      </td>
                      <td class="cmp-mono"><?= !empty($doc['received_on']) ? h(substr((string)$doc['received_on'], 0, 10)) : '—' ?></td>
                      <td><?= trim((string)($doc['notes'] ?? '')) !== '' ? nl2br(h((string)$doc['notes'])) : '<span style="color:#94a3b8;">—</span>' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <?php compliance_modal_open('audit-document-upload-modal', 'Upload new Audit Document'); ?>
          <form method="post" enctype="multipart/form-data" action="/admin/compliance/audits.php?id=<?= (int)$detailId ?>">
            <input type="hidden" name="action" value="upload_audit_document">
            <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
            <label class="cmp-field">
              <span>Document Type</span>
              <select name="doc_kind" required>
                <?php foreach (ComplianceAuthorityDocumentService::auditDocumentTypes() as $kind => $label): ?>
                  <option value="<?= h($kind) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="cmp-field">
              <span>Document Received on</span>
              <input type="date" name="received_on">
            </label>
            <label class="cmp-field">
              <span>Notes</span>
              <textarea name="notes" rows="3"></textarea>
            </label>
            <label style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:28px 16px;border:2px dashed #cbd5e1;border-radius:14px;background:#f8fafc;color:#475569;text-align:center;margin:12px 0;cursor:pointer;">
              <strong>Drop PDF here or click to browse</strong>
              <span style="font-size:12px;color:#64748b;">Official authority audit report PDF, max 50 MiB.</span>
              <input type="file" name="document_file" accept="application/pdf,.pdf" required style="margin-top:8px;">
            </label>
            <div class="compliance-modal__footer">
              <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
              <button type="submit">Upload document</button>
            </div>
          </form>
        <?php compliance_modal_close(); ?>

        <section class="cmp-card">
          <div class="cmp-card-head">
            <h3 class="cmp-card-title">Audit contacts</h3>
          </div>
          <p class="cmp-card-sub" style="margin:0 0 14px;">
            These contacts are used for audit correspondence. Deadline-extension drafts use the Lead Auditor as To, Auditors and Specialists as Cc.
          </p>
          <?php if ($locked): ?>
            <?php if ($auditContacts === array()): ?>
              <p style="margin:0;color:var(--text-muted);">No audit contacts recorded.</p>
            <?php else: ?>
              <div class="compliance-table-wrap">
                <table class="compliance-table">
                  <thead><tr><th>Name</th><th>E-mail</th><th>Position</th></tr></thead>
                  <tbody>
                    <?php foreach ($auditContacts as $contact): ?>
                      <tr>
                        <td><?= h((string)$contact['contact_name']) ?></td>
                        <td><?= h((string)$contact['contact_email']) ?></td>
                        <td><?= compliance_badge((string)$contact['contact_position_label']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <?php
              $contactRows = $auditContacts;
              for ($i = count($contactRows); $i < count($auditContacts) + 3; $i++) {
                  $contactRows[] = array('contact_name' => '', 'contact_email' => '', 'contact_position' => 'AUDITOR');
              }
            ?>
            <form method="post">
              <input type="hidden" name="action" value="save_audit_contacts">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
              <div class="compliance-table-wrap">
                <table class="compliance-table">
                  <thead><tr><th>Name</th><th>E-mail</th><th style="width:190px;">Position</th></tr></thead>
                  <tbody>
                    <?php foreach ($contactRows as $contact): ?>
                      <tr>
                        <td><input name="contact_name[]" value="<?= h((string)($contact['contact_name'] ?? '')) ?>" placeholder="Name" style="width:100%;"></td>
                        <td><input type="email" name="contact_email[]" value="<?= h((string)($contact['contact_email'] ?? '')) ?>" placeholder="name@example.com" style="width:100%;"></td>
                        <td>
                          <select name="contact_position[]" style="width:100%;">
                            <?php foreach (ComplianceAuditEngine::contactPositions() as $position => $label): ?>
                              <option value="<?= h($position) ?>" <?= (string)($contact['contact_position'] ?? 'AUDITOR') === $position ? 'selected' : '' ?>>
                                <?= h($label) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="submit" style="margin-top:12px;">Save audit contacts</button>
            </form>
          <?php endif; ?>
        </section>

        <section class="cmp-card">
          <div class="cmp-card-head">
            <h3 class="cmp-card-title">Checklist snapshots</h3>
          </div>

          <?php if (!$snapshots): ?>
            <div class="cmp-empty">
              <div class="cmp-empty-title">No checklist snapshots attached</div>
              <p style="margin:0;">Attach an approved checklist below to begin auditing.</p>
            </div>
          <?php else: ?>
            <div class="compliance-table-wrap">
            <table class="cmp-table compliance-table" style="margin-bottom:18px;">
              <thead><tr>
                <th>Template</th>
                <th>Version</th>
                <th>Items</th>
                <th>Status</th>
                <th>Generated</th>
                <th></th>
              </tr></thead>
              <tbody>
                <?php foreach ($snapshots as $s):
                    $items = ComplianceChecklistEngine::decodeSnapshotItems($s['items_snapshot_json'] ?? null);
                    ?>
                  <tr>
                    <td>
                      <code><?= h((string)$s['template_code']) ?></code>
                      &mdash; <?= h((string)$s['template_title']) ?>
                    </td>
                    <td>v<?= (int)$s['version_no'] ?></td>
                    <td><?= count($items) ?></td>
                    <td>
                      <?= compliance_badge((string)$s['status']) ?>
                      <?php if (!empty($s['locked_at'])): ?>
                        <span class="cmp-pill" style="margin-left:6px;background:rgba(196,118,11,0.10);color:#a66508;border-color:rgba(196,118,11,0.20);">Locked</span>
                      <?php endif; ?>
                    </td>
                    <td class="cmp-mono"><?= h((string)$s['generated_at']) ?></td>
                    <td>
                      <a href="/admin/compliance/audit_checklist.php?snapshot_id=<?= (int)$s['id'] ?>">Open</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>

          <?php if (!$locked && $approvedVersions): ?>
            <form method="post" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
              <input type="hidden" name="action" value="attach_snapshot">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
              <label class="cmp-field" style="flex:1 1 320px;">
                <span>Attach approved checklist</span>
                <select name="version_id" required>
                  <?php foreach ($approvedVersions as $v): ?>
                    <option value="<?= (int)$v['version_id'] ?>">
                      <?= h((string)$v['template_code']) ?> &mdash; <?= h((string)$v['template_title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit">Attach snapshot</button>
            </form>
          <?php elseif (!$locked): ?>
            <p style="margin:0;color:var(--text-muted);">
              No approved checklist versions available.
              <a href="/admin/compliance/procedures.php">Author one &rarr;</a>
            </p>
          <?php endif; ?>
        </section>
        <?php
        compliance_render_comms_panel($pdo, 'audit', (string)$detailId);
        compliance_page_close();
    }
} else {
    $rows = ComplianceAuditEngine::listRecent($pdo);
    $filterQ = trim((string)($_GET['q'] ?? ''));
    $filterStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
    $filterAuthority = strtoupper(trim((string)($_GET['authority'] ?? '')));
    $filterFrom = trim((string)($_GET['from'] ?? ''));
    $filterTo = trim((string)($_GET['to'] ?? ''));
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    $rows = array_values(array_filter($rows, static function (array $r) use ($filterQ, $filterStatus, $filterAuthority, $filterFrom, $filterTo): bool {
        if ($filterQ !== '') {
            $hay = strtolower((string)($r['audit_code'] ?? '') . ' ' . (string)($r['title'] ?? '') . ' ' . (string)($r['audit_entity'] ?? '') . ' ' . (string)($r['external_ref'] ?? ''));
            if (strpos($hay, strtolower($filterQ)) === false) {
                return false;
            }
        }
        if ($filterStatus !== '' && strtoupper((string)($r['status'] ?? '')) !== $filterStatus) {
            return false;
        }
        if ($filterAuthority !== '' && strtoupper((string)($r['authority'] ?? '')) !== $filterAuthority) {
            return false;
        }
        $start = substr((string)($r['start_date'] ?? ''), 0, 10);
        if ($filterFrom !== '' && $start !== '' && $start < $filterFrom) {
            return false;
        }
        if ($filterTo !== '' && $start !== '' && $start > $filterTo) {
            return false;
        }
        return true;
    }));

    usort($rows, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'start_asc') {
            return strcmp((string)($a['start_date'] ?? ''), (string)($b['start_date'] ?? ''));
        }
        if ($sort === 'start_desc') {
            return strcmp((string)($b['start_date'] ?? ''), (string)($a['start_date'] ?? ''));
        }
        if ($sort === 'title_asc') {
            return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        }
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });

    compliance_page_open(array(
        'overline' => 'Compliance',
        'title' => 'Audits',
        'description' => 'Plan and execute internal & authority audits. Attach versioned checklist snapshots and track lifecycle from PLANNED to CLOSED.',
        'actions' => array(
            array('label' => 'New audit', 'modal' => 'audit-create-modal', 'icon' => 'plus'),
        ),
        'flash' => $flash,
        'stats' => array(
            array('label' => 'Total audits', 'value' => count($rows)),
        ),
    ));
    ?>

    <section class="cmp-card">
      <form method="get" class="compliance-filterbar">
        <label class="cmp-field compliance-filterbar__search">
          <span>Search</span>
          <input type="search" name="q" value="<?= h($filterQ) ?>" placeholder="Audit code, title, entity or reference">
        </label>
        <label class="cmp-field">
          <span>Status</span>
          <select name="status">
            <option value="">All statuses</option>
            <?php foreach (ComplianceAuditEngine::statuses() as $s): ?>
              <option value="<?= h($s) ?>" <?= $filterStatus === strtoupper($s) ? 'selected' : '' ?>><?= h(compliance_friendly_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="cmp-field">
          <span>Authority</span>
          <select name="authority">
            <option value="">All authorities</option>
            <?php foreach (ComplianceAuditEngine::authorities() as $a): ?>
              <option value="<?= h($a) ?>" <?= $filterAuthority === strtoupper($a) ? 'selected' : '' ?>><?= h($a) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="cmp-field">
          <span>From</span>
          <input type="date" name="from" value="<?= h($filterFrom) ?>">
        </label>
        <label class="cmp-field">
          <span>To</span>
          <input type="date" name="to" value="<?= h($filterTo) ?>">
        </label>
        <label class="cmp-field">
          <span>Sort</span>
          <select name="sort">
            <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>Recently updated</option>
            <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>Start date newest</option>
            <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>Start date oldest</option>
            <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : '' ?>>Title A-Z</option>
          </select>
        </label>
        <div class="cmp-toolbar-actions compliance-filterbar__actions">
          <button type="submit">Apply filters</button>
          <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/audits.php">Clear</a>
        </div>
      </form>
    </section>

      <section class="cmp-card compliance-card--full" style="overflow:hidden;">
        <div class="cmp-list-head" style="margin-bottom:14px;">
          <div class="cmp-list-title"><?= compliance_ui_icon('shield') ?><span>Recent audits</span></div>
          <div class="cmp-count-pill"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?></div>
        </div>
        <div class="compliance-table-wrap">
        <style>
          .cmp-page .cmp-audit-list-table th,
          .cmp-page .cmp-audit-list-table td,
          .cmp-page .cmp-audit-list-table td:first-child,
          .cmp-page .cmp-audit-list-table .cmp-mono,
          .cmp-page .cmp-audit-list-table td:first-child a,
          .cmp-page .cmp-audit-list-table .cmp-ref-link{
            font-family:var(--font-sans,Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif) !important;
            font-size:11.5px !important;
            color:#324155 !important;
            font-weight:650;
            letter-spacing:.01em;
          }
          .cmp-audit-list-table .cmp-list-titlecell{
            max-width:520px;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
          }
          .cmp-audit-list-table .cmp-ref-link{color:#324155 !important;font-weight:720;text-decoration:none;}
        </style>
        <table class="compliance-table cmp-audit-list-table">
          <thead><tr>
            <th style="width:140px;">Reference</th>
            <th>Title</th>
            <th style="width:132px;">Authority</th>
            <th style="width:156px;">Status</th>
            <th style="width:204px;">Window</th>
          </tr></thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" style="color:var(--text-muted);">No audits yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr data-href="/admin/compliance/audits.php?id=<?= (int)$r['id'] ?>" class="compliance-row-clickable">
                <td>
                  <a class="cmp-ref-link" href="/admin/compliance/audits.php?id=<?= (int)$r['id'] ?>"><?= h((string)$r['audit_code']) ?></a>
                </td>
                <td><span class="cmp-list-titlecell"><?= h((string)$r['title']) ?></span></td>
                <td><?= compliance_badge((string)$r['authority']) ?></td>
                <td><?= compliance_badge((string)$r['status']) ?></td>
                <td>
                  <?= h((string)($r['start_date'] ?? '—')) ?> &rarr; <?= h((string)($r['end_date'] ?? '—')) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>

      <?php compliance_modal_open('audit-create-modal', 'New audit'); ?>
        <form method="post" action="/admin/compliance/audits.php" style="display:flex;flex-direction:column;gap:14px;">
          <input type="hidden" name="action" value="create_audit">

          <label class="cmp-field">
            <span>Title *</span>
            <input name="title" required>
          </label>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <label class="cmp-field">
              <span>Authority</span>
              <select name="authority">
                <?php foreach (ComplianceAuditEngine::authorities() as $a): ?>
                  <option value="<?= h($a) ?>" <?= $a === 'INTERNAL' ? 'selected' : '' ?>><?= h($a) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="cmp-field">
              <span>Type</span>
              <input name="audit_type" value="INTERNAL">
            </label>
          </div>

          <label class="cmp-field">
            <span>Entity</span>
            <input name="audit_entity" placeholder="e.g. ATO Operations">
          </label>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <label class="cmp-field">
              <span>Start</span>
              <input type="date" name="start_date">
            </label>
            <label class="cmp-field">
              <span>End</span>
              <input type="date" name="end_date">
            </label>
          </div>

          <label class="cmp-field">
            <span>Subject / scope</span>
            <textarea name="subject" rows="3"></textarea>
          </label>

          <div class="compliance-modal__footer">
            <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
            <button type="submit">Create audit</button>
          </div>
        </form>
      <?php compliance_modal_close(); ?>

    <?php
    compliance_page_close();
}

cw_footer();
