<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuditEngine.php';
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
          .cmp-audit-list-table th,
          .cmp-audit-list-table td,
          .cmp-audit-list-table td:first-child,
          .cmp-audit-list-table .cmp-mono{
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
            <th style="width:110px;">Authority</th>
            <th style="width:130px;">Status</th>
            <th style="width:170px;">Window</th>
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
                <td><?= h((string)$r['authority']) ?></td>
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
