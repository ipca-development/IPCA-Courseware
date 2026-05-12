<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuditEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceChecklistEngine.php';

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

if ($flash !== null) {
    $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger');
    echo '<div class="queue-status ' . h($cls) . '" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

if ($detailId > 0) {
    $audit = ComplianceAuditEngine::getById($pdo, $detailId);
    if ($audit === null) {
        echo '<p>Audit not found.</p>';
        echo '<p><a class="nav-link" href="/admin/compliance/audits.php" style="color:#1e3c72;font-weight:700;">← All audits</a></p>';
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
        ?>
        <p style="margin-bottom:16px;">
          <a href="/admin/compliance/audits.php" style="color:#1e3c72;font-weight:700;">← All audits</a>
          <span style="color:#64748b;margin:0 8px;">|</span>
          <span style="font-family:ui-monospace,monospace;font-size:13px;"><?= h((string)$audit['audit_code']) ?></span>
        </p>

        <section style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:960px;">
          <h2 style="margin:0 0 6px;font-size:20px;"><?= h((string)$audit['title']) ?></h2>
          <p style="margin:0 0 14px;color:#64748b;font-size:14px;">
            <?= h((string)$audit['authority']) ?>
            · <?= h((string)$audit['audit_type']) ?>
            · <?= h((string)$audit['status']) ?>
            <?php if ($locked): ?>
              <span class="queue-status is-warn" style="margin-left:8px;padding:2px 8px;border-radius:8px;">Locked</span>
            <?php endif; ?>
          </p>

          <?php if ($locked): ?>
            <dl style="display:grid;grid-template-columns:200px 1fr;gap:6px 16px;font-size:14px;">
              <dt style="color:#64748b;">Entity</dt><dd><?= h((string)($audit['audit_entity'] ?? '—')) ?></dd>
              <dt style="color:#64748b;">External ref</dt><dd><?= h((string)($audit['external_ref'] ?? '—')) ?></dd>
              <dt style="color:#64748b;">Start</dt><dd><?= h((string)($audit['start_date'] ?? '—')) ?></dd>
              <dt style="color:#64748b;">End</dt><dd><?= h((string)($audit['end_date'] ?? '—')) ?></dd>
              <dt style="color:#64748b;">Closed</dt><dd><?= h((string)($audit['closed_date'] ?? '—')) ?></dd>
              <dt style="color:#64748b;">Subject</dt><dd><?= nl2br(h((string)($audit['subject'] ?? '—'))) ?></dd>
            </dl>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="action" value="update_audit">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Title *</span>
                <input name="title" required value="<?= h((string)$audit['title']) ?>" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:10px;">
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Authority</span>
                  <select name="authority" style="padding:8px;border-radius:8px;">
                    <?php foreach (ComplianceAuditEngine::authorities() as $a): ?>
                      <option value="<?= h($a) ?>" <?= ((string)$audit['authority'] === $a) ? 'selected' : '' ?>><?= h($a) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Category</span>
                  <select name="audit_category" style="padding:8px;border-radius:8px;">
                    <option value="">—</option>
                    <?php foreach (ComplianceAuditEngine::categories() as $c): ?>
                      <option value="<?= h($c) ?>" <?= ((string)($audit['audit_category'] ?? '') === $c) ? 'selected' : '' ?>><?= h($c) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Type</span>
                  <input name="audit_type" value="<?= h((string)$audit['audit_type']) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:160px;">
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Status</span>
                  <select name="status" style="padding:8px;border-radius:8px;">
                    <?php foreach (ComplianceAuditEngine::statuses() as $s): ?>
                      <option value="<?= h($s) ?>" <?= ((string)$audit['status'] === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:10px;">
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Entity</span>
                  <input name="audit_entity" value="<?= h((string)($audit['audit_entity'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:240px;">
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">External ref</span>
                  <input name="external_ref" value="<?= h((string)($audit['external_ref'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:200px;">
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Start date</span>
                  <input type="date" name="start_date" value="<?= h(substr((string)($audit['start_date'] ?? ''), 0, 10)) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">End date</span>
                  <input type="date" name="end_date" value="<?= h(substr((string)($audit['end_date'] ?? ''), 0, 10)) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
              </div>
              <label style="display:block;margin-bottom:14px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Subject / scope</span>
                <textarea name="subject" rows="4" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"><?= h((string)($audit['subject'] ?? '')) ?></textarea>
              </label>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">Save</button>
            </form>
            <form method="post" style="margin-top:14px;display:inline-flex;gap:8px;align-items:center;"
              onsubmit="return confirm('Close and lock this audit?');">
              <input type="hidden" name="action" value="close_audit">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
              <label>
                <span style="font-size:11px;font-weight:700;color:#64748b;">Closed date</span>
                <input type="date" name="closed_date" value="<?= h(date('Y-m-d')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Close audit</button>
            </form>
          <?php endif; ?>
        </section>

        <section style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:960px;">
          <h3 style="margin:0 0 12px;font-size:18px;">Checklist snapshots</h3>
          <?php if (!$snapshots): ?>
            <p style="margin:0 0 12px;color:#64748b;font-size:14px;">No checklist snapshots attached.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:14px;">
              <thead><tr style="background:#f1f5f9;text-align:left;">
                <th style="padding:10px;">Template</th>
                <th style="padding:10px;">Version</th>
                <th style="padding:10px;">Items</th>
                <th style="padding:10px;">Status</th>
                <th style="padding:10px;">Generated</th>
                <th style="padding:10px;"></th>
              </tr></thead>
              <tbody>
                <?php foreach ($snapshots as $s):
                    $items = ComplianceChecklistEngine::decodeSnapshotItems($s['items_snapshot_json'] ?? null);
                    ?>
                  <tr style="border-top:1px solid #e2e8f0;">
                    <td style="padding:10px;">
                      <code style="font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$s['template_code']) ?></code>
                      — <?= h((string)$s['template_title']) ?>
                    </td>
                    <td style="padding:10px;">v<?= (int)$s['version_no'] ?></td>
                    <td style="padding:10px;"><?= count($items) ?></td>
                    <td style="padding:10px;"><?= h((string)$s['status']) ?>
                      <?php if (!empty($s['locked_at'])): ?>
                        <span class="queue-status is-warn" style="margin-left:6px;padding:2px 6px;border-radius:6px;">Locked</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px;color:#64748b;font-size:12px;"><?= h((string)$s['generated_at']) ?></td>
                    <td style="padding:10px;">
                      <a href="/admin/compliance/audit_checklist.php?snapshot_id=<?= (int)$s['id'] ?>" style="font-weight:700;color:#1e3c72;">Open</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <?php if (!$locked && $approvedVersions): ?>
            <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
              <input type="hidden" name="action" value="attach_snapshot">
              <input type="hidden" name="audit_id" value="<?= (int)$detailId ?>">
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Attach approved checklist</span>
                <select name="version_id" required style="padding:8px;border-radius:8px;min-width:280px;">
                  <?php foreach ($approvedVersions as $v): ?>
                    <option value="<?= (int)$v['version_id'] ?>">
                      <?= h((string)$v['template_code']) ?> — <?= h((string)$v['template_title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">Attach snapshot</button>
            </form>
          <?php elseif (!$locked): ?>
            <p style="margin:0;color:#64748b;font-size:13px;">
              No approved checklist versions available.
              <a href="/admin/compliance/procedures.php" style="color:#1e3c72;font-weight:700;">Author one →</a>
            </p>
          <?php endif; ?>
        </section>
        <?php
    }
} else {
    $rows = ComplianceAuditEngine::listRecent($pdo);
    ?>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:20px;max-width:1200px;">
      <div>
        <h2 style="margin:0 0 6px;font-size:20px;">Audits</h2>
        <p style="margin:0;color:#64748b;font-size:14px;">Plan and execute internal &amp; authority audits. Attach versioned checklist snapshots.</p>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;max-width:1200px;">
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <thead><tr style="background:#f1f5f9;text-align:left;">
            <th style="padding:12px 14px;">Code</th>
            <th style="padding:12px 14px;">Title</th>
            <th style="padding:12px 14px;">Authority</th>
            <th style="padding:12px 14px;">Status</th>
            <th style="padding:12px 14px;">Window</th>
          </tr></thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" style="padding:20px;color:#64748b;">No audits yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr style="border-top:1px solid #e2e8f0;">
                <td style="padding:10px 14px;font-family:ui-monospace,monospace;font-size:12px;">
                  <a href="/admin/compliance/audits.php?id=<?= (int)$r['id'] ?>" style="color:#1e3c72;font-weight:700;"><?= h((string)$r['audit_code']) ?></a>
                </td>
                <td style="padding:10px 14px;"><?= h((string)$r['title']) ?></td>
                <td style="padding:10px 14px;"><?= h((string)$r['authority']) ?></td>
                <td style="padding:10px 14px;"><?= h((string)$r['status']) ?></td>
                <td style="padding:10px 14px;color:#64748b;font-size:12px;">
                  <?= h((string)($r['start_date'] ?? '—')) ?> → <?= h((string)($r['end_date'] ?? '—')) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
        <h3 style="margin:0 0 14px;font-size:16px;">New audit</h3>
        <form method="post" action="/admin/compliance/audits.php">
          <input type="hidden" name="action" value="create_audit">
          <label style="display:block;margin-bottom:10px;">
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
            <input name="title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
          </label>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <label style="flex:1 1 130px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Authority</span>
              <select name="authority" style="width:100%;padding:8px;border-radius:8px;">
                <?php foreach (ComplianceAuditEngine::authorities() as $a): ?>
                  <option value="<?= h($a) ?>" <?= $a === 'INTERNAL' ? 'selected' : '' ?>><?= h($a) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label style="flex:1 1 130px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Type</span>
              <input name="audit_type" value="INTERNAL" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>
          </div>
          <label style="display:block;margin-bottom:10px;">
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Entity</span>
            <input name="audit_entity" placeholder="e.g. ATO Operations" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
          </label>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <label style="flex:1 1 130px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Start</span>
              <input type="date" name="start_date" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>
            <label style="flex:1 1 130px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">End</span>
              <input type="date" name="end_date" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>
          </div>
          <label style="display:block;margin-bottom:14px;">
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Subject / scope</span>
            <textarea name="subject" rows="3" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
          </label>
          <button type="submit" style="width:100%;background:#1e3c72;color:#fff;border:0;padding:12px;border-radius:10px;font-weight:800;cursor:pointer;">
            Create audit
          </button>
        </form>
      </div>
    </div>
    <?php
}

cw_footer();
