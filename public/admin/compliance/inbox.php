<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceInboxEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuditEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceFindingEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function inbox_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_inbox'] = array('type' => $type, 'message' => $msg);
}

function inbox_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_inbox']) || !is_array($_SESSION['_ipca_compliance_flash_inbox'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_inbox'];
    unset($_SESSION['_ipca_compliance_flash_inbox']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $detailId = isset($_POST['email_id']) ? (int)$_POST['email_id'] : 0;
    try {
        if ($action === 'create_email') {
            $id = ComplianceInboxEngine::manualCreate($pdo, array(
                'from_email' => (string)($_POST['from_email'] ?? ''),
                'from_name' => (string)($_POST['from_name'] ?? ''),
                'to_email' => (string)($_POST['to_email'] ?? ''),
                'subject' => (string)($_POST['subject'] ?? ''),
                'body_text' => (string)($_POST['body_text'] ?? ''),
                'received_at' => (string)($_POST['received_at'] ?? ''),
                'classification' => (string)($_POST['classification'] ?? ''),
            ), $uid);
            inbox_flash('success', 'Email captured.');
            redirect('/admin/compliance/inbox.php?id=' . $id);
        }
        if ($action === 'set_triage') {
            ComplianceInboxEngine::setTriage($pdo, $detailId, (string)($_POST['triage_state'] ?? 'NEW'), $uid);
            redirect('/admin/compliance/inbox.php?id=' . $detailId);
        }
        if ($action === 'set_classification') {
            ComplianceInboxEngine::setClassification($pdo, $detailId, (string)($_POST['classification'] ?? ''));
            redirect('/admin/compliance/inbox.php?id=' . $detailId);
        }
        if ($action === 'attach_audit') {
            $aid = (int)($_POST['audit_id'] ?? 0);
            ComplianceInboxEngine::attachToAudit($pdo, $detailId, $aid > 0 ? $aid : null);
            inbox_flash('success', 'Email attached to audit.');
            redirect('/admin/compliance/inbox.php?id=' . $detailId);
        }
        if ($action === 'attach_finding') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            ComplianceInboxEngine::attachToFinding($pdo, $detailId, $fid > 0 ? $fid : null);
            inbox_flash('success', 'Email attached to finding.');
            redirect('/admin/compliance/inbox.php?id=' . $detailId);
        }
        if ($action === 'add_link') {
            ComplianceInboxEngine::addLink(
                $pdo,
                $detailId,
                (string)($_POST['entity_type'] ?? ''),
                (int)($_POST['entity_id'] ?? 0),
                (string)($_POST['relation'] ?? ''),
                $uid
            );
            inbox_flash('success', 'Link recorded.');
            redirect('/admin/compliance/inbox.php?id=' . $detailId);
        }
        if ($action === 'remove_link') {
            ComplianceInboxEngine::removeLink($pdo, (int)($_POST['link_id'] ?? 0));
            redirect('/admin/compliance/inbox.php?id=' . $detailId);
        }
    } catch (Throwable $e) {
        inbox_flash('error', $e->getMessage());
        redirect('/admin/compliance/inbox.php' . ($detailId > 0 ? '?id=' . $detailId : ''));
    }
}

$flash = inbox_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterTriage = isset($_GET['triage']) ? (string)$_GET['triage'] : '';
$stats = ComplianceInboxEngine::triageStats($pdo);

cw_header('Compliance · Inbox');
?>
<style>
  .cmpibx-h1{margin:0 0 6px;font-size:24px;color:#0f172a;}
  .cmpibx-sub{margin:0 0 22px;color:#64748b;max-width:760px;line-height:1.55;}
  .cmpibx-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;}
  .cmpibx-label{display:block;font-size:11px;font-weight:700;color:#64748b;}
  .cmpibx-input{padding:8px;border:1px solid #cbd5e1;border-radius:8px;width:100%;box-sizing:border-box;}
  .cmpibx-btn{background:#1e3c72;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:800;cursor:pointer;}
  .cmpibx-btn-small{padding:6px 10px;border:0;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;}
  .cmpibx-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cmpibx-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
  }
  .cmpibx-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmpibx-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmpibx-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;}
  .cmpibx-pill.t-NEW{background:#fee2e2;color:#991b1b;}
  .cmpibx-pill.t-IN_REVIEW{background:#fef3c7;color:#92400e;}
  .cmpibx-pill.t-ACTIONED{background:#d1fae5;color:#065f46;}
  .cmpibx-pill.t-ARCHIVED{background:#e2e8f0;color:#475569;}
  .cmpibx-pill.t-IGNORED{background:#e2e8f0;color:#475569;}
  .cmpibx-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:18px;}
  .cmpibx-tab{
    background:#e2e8f0;color:#0f172a;padding:6px 12px;border-radius:999px;
    text-decoration:none;font-size:12px;font-weight:700;
  }
  .cmpibx-tab.is-on{background:#1e3c72;color:#fff;}
  .cmpibx-flash{padding:12px 16px;border-radius:12px;margin-bottom:16px;}
  .cmpibx-flash.is-ok{background:#d1fae5;color:#065f46;}
  .cmpibx-flash.is-warn{background:#fef3c7;color:#92400e;}
  .cmpibx-flash.is-danger{background:#fee2e2;color:#991b1b;}
</style>

<?php if ($flash !== null):
  $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger'); ?>
  <div class="cmpibx-flash <?= h($cls) ?>"><?= h((string)$flash['message']) ?></div>
<?php endif; ?>

<?php
if ($detailId > 0) {
    $e = ComplianceInboxEngine::getById($pdo, $detailId);
    if ($e === null) {
        echo '<p>Email not found.</p>';
        echo '<p><a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;">← Inbox</a></p>';
        cw_footer();
        return;
    }
    $links = ComplianceInboxEngine::listLinks($pdo, $detailId);
    $audits = ComplianceAuditEngine::listRecent($pdo, 200);
    $findings = ComplianceFindingEngine::listRecent($pdo, 200);
    ?>
    <p style="margin-bottom:16px;">
      <a href="/admin/compliance/inbox.php" style="color:#1e3c72;font-weight:700;text-decoration:none;">← Inbox</a>
    </p>

    <section class="cmpibx-card" style="max-width:1100px;">
      <h2 style="margin:0 0 6px;font-size:20px;"><?= h((string)$e['subject']) ?></h2>
      <p style="margin:0 0 14px;color:#64748b;font-size:14px;">
        <span class="cmpibx-pill t-<?= h((string)$e['triage_state']) ?>"><?= h((string)$e['triage_state']) ?></span>
        · <?= h((string)$e['from_email']) ?>
        <?php if (!empty($e['from_name'])): ?> (<?= h((string)$e['from_name']) ?>)<?php endif; ?>
        · <span class="cmpibx-mono"><?= h(substr((string)($e['received_at'] ?? ''), 0, 16)) ?></span>
        <?php if (!empty($e['classification'])): ?>
          · <span style="font-weight:700;"><?= h((string)$e['classification']) ?></span>
        <?php endif; ?>
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <form method="post">
          <input type="hidden" name="action" value="set_triage">
          <input type="hidden" name="email_id" value="<?= (int)$detailId ?>">
          <label class="cmpibx-label">Triage</label>
          <select class="cmpibx-input" name="triage_state" onchange="this.form.submit()">
            <?php foreach (ComplianceInboxEngine::triageStates() as $s): ?>
              <option value="<?= h($s) ?>" <?= ((string)$e['triage_state'] === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="set_classification">
          <input type="hidden" name="email_id" value="<?= (int)$detailId ?>">
          <label class="cmpibx-label">Classification</label>
          <select class="cmpibx-input" name="classification" onchange="this.form.submit()">
            <option value="">—</option>
            <?php foreach (ComplianceInboxEngine::classifications() as $c): ?>
              <option value="<?= h($c) ?>" <?= ((string)($e['classification'] ?? '') === $c) ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:14px;color:#334155;white-space:pre-wrap;">
        <?= h((string)($e['body_text'] ?? '')) ?>
      </div>
    </section>

    <section class="cmpibx-card" style="max-width:1100px;">
      <h2 style="margin:0 0 14px;font-size:16px;">Attach to compliance entities</h2>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">
        <form method="post">
          <input type="hidden" name="action" value="attach_audit">
          <input type="hidden" name="email_id" value="<?= (int)$detailId ?>">
          <label class="cmpibx-label">Linked audit</label>
          <div style="display:flex;gap:6px;">
            <select class="cmpibx-input" name="audit_id">
              <option value="">— Detach —</option>
              <?php foreach ($audits as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= ((int)($e['audit_id'] ?? 0) === (int)$a['id']) ? 'selected' : '' ?>>
                  <?= h((string)$a['audit_code']) ?> · <?= h((string)$a['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="cmpibx-btn-small" style="background:#1e3c72;color:#fff;">Save</button>
          </div>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="attach_finding">
          <input type="hidden" name="email_id" value="<?= (int)$detailId ?>">
          <label class="cmpibx-label">Linked finding</label>
          <div style="display:flex;gap:6px;">
            <select class="cmpibx-input" name="finding_id">
              <option value="">— Detach —</option>
              <?php foreach ($findings as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= ((int)($e['finding_id'] ?? 0) === (int)$f['id']) ? 'selected' : '' ?>>
                  <?= h((string)$f['finding_code']) ?> · <?= h((string)$f['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="cmpibx-btn-small" style="background:#1e3c72;color:#fff;">Save</button>
          </div>
        </form>
      </div>

      <h3 style="margin:18px 0 10px;font-size:14px;color:#0f172a;">Other links</h3>
      <?php if ($links !== array()): ?>
        <table class="cmpibx-table" style="margin-bottom:14px;">
          <thead><tr><th>Entity</th><th>ID</th><th>Relation</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($links as $l): ?>
              <tr>
                <td><?= h((string)$l['entity_type']) ?></td>
                <td class="cmpibx-mono"><?= (int)$l['entity_id'] ?></td>
                <td><?= h((string)($l['relation'] ?? '—')) ?></td>
                <td>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Remove this link?');">
                    <input type="hidden" name="action" value="remove_link">
                    <input type="hidden" name="email_id" value="<?= (int)$detailId ?>">
                    <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                    <button type="submit" class="cmpibx-btn-small" style="background:#fee2e2;color:#991b1b;">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <details>
        <summary style="cursor:pointer;font-weight:700;color:#3730a3;">+ Add link</summary>
        <form method="post" style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;">
          <input type="hidden" name="action" value="add_link">
          <input type="hidden" name="email_id" value="<?= (int)$detailId ?>">
          <label>
            <span class="cmpibx-label">Entity type</span>
            <select class="cmpibx-input" name="entity_type">
              <option value="corrective_action">corrective_action</option>
              <option value="meeting">meeting</option>
              <option value="manual_change_request">manual_change_request</option>
              <option value="case">case</option>
            </select>
          </label>
          <label>
            <span class="cmpibx-label">Entity ID</span>
            <input class="cmpibx-input" name="entity_id" type="number" required>
          </label>
          <label>
            <span class="cmpibx-label">Relation</span>
            <select class="cmpibx-input" name="relation">
              <option value="">—</option>
              <option value="EVIDENCE">EVIDENCE</option>
              <option value="NOTIFICATION">NOTIFICATION</option>
              <option value="RESPONSE">RESPONSE</option>
              <option value="REFERENCE">REFERENCE</option>
            </select>
          </label>
          <button type="submit" class="cmpibx-btn">Add</button>
        </form>
      </details>
    </section>

    <?php
    cw_footer();
    return;
}

$emails = ComplianceInboxEngine::listRecent($pdo, $filterTriage !== '' ? $filterTriage : null, 100);
?>
<section style="padding:8px 0 40px;max-width:1200px;">
  <h1 class="cmpibx-h1">Compliance inbox</h1>
  <p class="cmpibx-sub">
    Authority correspondence, supplier mail and other inbound messages that need a triage.
    Capture mail manually below; in production this list also fills automatically from the inbound
    email worker.
  </p>

  <div class="cmpibx-tabs">
    <a class="cmpibx-tab <?= $filterTriage === '' ? 'is-on' : '' ?>" href="/admin/compliance/inbox.php">All</a>
    <?php foreach (ComplianceInboxEngine::triageStates() as $s): ?>
      <a class="cmpibx-tab <?= $filterTriage === $s ? 'is-on' : '' ?>"
         href="/admin/compliance/inbox.php?triage=<?= urlencode($s) ?>">
        <?= h($s) ?> (<?= (int)($stats[$s] ?? 0) ?>)
      </a>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">
    <div class="cmpibx-card">
      <h2 style="margin:0 0 14px;font-size:16px;">Inbox</h2>
      <?php if ($emails === array()): ?>
        <p style="color:#64748b;margin:0;">Nothing in scope. Capture mail on the right or change the filter.</p>
      <?php else: ?>
        <table class="cmpibx-table">
          <thead><tr>
            <th>Triage</th><th>Subject</th><th>From</th><th>Class</th><th>Received</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($emails as $em): ?>
              <tr>
                <td><span class="cmpibx-pill t-<?= h((string)$em['triage_state']) ?>"><?= h((string)$em['triage_state']) ?></span></td>
                <td>
                  <a href="/admin/compliance/inbox.php?id=<?= (int)$em['id'] ?>" style="color:#1e3c72;font-weight:700;text-decoration:none;">
                    <?= h((string)$em['subject']) ?>
                  </a>
                </td>
                <td><?= h((string)$em['from_email']) ?></td>
                <td><?= h((string)($em['classification'] ?? '—')) ?></td>
                <td class="cmpibx-mono"><?= h(substr((string)($em['received_at'] ?? ''), 0, 16)) ?></td>
                <td><a href="/admin/compliance/inbox.php?id=<?= (int)$em['id'] ?>" class="cmpibx-btn-small" style="background:#e2e8f0;color:#0f172a;text-decoration:none;">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="cmpibx-card">
      <h3 style="margin:0 0 14px;font-size:16px;">Capture email</h3>
      <form method="post">
        <input type="hidden" name="action" value="create_email">
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpibx-label">From *</span>
          <input class="cmpibx-input" name="from_email" required type="email">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpibx-label">From name</span>
          <input class="cmpibx-input" name="from_name">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpibx-label">Subject *</span>
          <input class="cmpibx-input" name="subject" required>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpibx-label">Body</span>
          <textarea class="cmpibx-input" name="body_text" rows="5"></textarea>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpibx-label">Received</span>
          <input class="cmpibx-input" type="datetime-local" name="received_at">
        </label>
        <label style="display:block;margin-bottom:14px;">
          <span class="cmpibx-label">Classification</span>
          <select class="cmpibx-input" name="classification">
            <option value="">—</option>
            <?php foreach (ComplianceInboxEngine::classifications() as $c): ?>
              <option value="<?= h($c) ?>"><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="cmpibx-btn" style="width:100%;">Capture email</button>
      </form>
    </div>
  </div>
</section>
<?php
cw_footer();
