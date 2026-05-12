<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceMonitorEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function mrules_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_mrules'] = array('type' => $type, 'message' => $msg);
}

function mrules_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_mrules']) || !is_array($_SESSION['_ipca_compliance_flash_mrules'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_mrules'];
    unset($_SESSION['_ipca_compliance_flash_mrules']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_rule') {
            ComplianceMonitorEngine::createRule($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'monitor_kind' => (string)($_POST['monitor_kind'] ?? 'OTHER'),
                'alert_severity' => (string)($_POST['alert_severity'] ?? 'MEDIUM'),
                'cadence' => (string)($_POST['cadence'] ?? ''),
                'cron_expression' => (string)($_POST['cron_expression'] ?? ''),
                'event_key' => (string)($_POST['event_key'] ?? ''),
                'builtin_key' => (string)($_POST['builtin_key'] ?? ''),
                'threshold_days' => (string)($_POST['threshold_days'] ?? ''),
                'notification_keys' => (string)($_POST['notification_keys'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ), $uid);
            mrules_flash('success', 'Rule created.');
            redirect('/admin/compliance/monitoring_rules.php');
        }
        if ($action === 'update_rule') {
            ComplianceMonitorEngine::updateRule($pdo, (int)($_POST['rule_id'] ?? 0), array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'monitor_kind' => (string)($_POST['monitor_kind'] ?? 'OTHER'),
                'alert_severity' => (string)($_POST['alert_severity'] ?? 'MEDIUM'),
                'cadence' => (string)($_POST['cadence'] ?? ''),
                'cron_expression' => (string)($_POST['cron_expression'] ?? ''),
                'event_key' => (string)($_POST['event_key'] ?? ''),
                'builtin_key' => (string)($_POST['builtin_key'] ?? ''),
                'threshold_days' => (string)($_POST['threshold_days'] ?? ''),
                'notification_keys' => (string)($_POST['notification_keys'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ), $uid);
            mrules_flash('success', 'Rule saved.');
            redirect('/admin/compliance/monitoring_rules.php?id=' . (int)($_POST['rule_id'] ?? 0));
        }
        if ($action === 'toggle_rule') {
            $rid = (int)($_POST['rule_id'] ?? 0);
            $active = ((string)($_POST['active'] ?? '0')) === '1';
            ComplianceMonitorEngine::toggleRule($pdo, $rid, $active, $uid);
            redirect('/admin/compliance/monitoring_rules.php');
        }
        if ($action === 'delete_rule') {
            ComplianceMonitorEngine::deleteRule($pdo, (int)($_POST['rule_id'] ?? 0));
            mrules_flash('success', 'Rule deleted.');
            redirect('/admin/compliance/monitoring_rules.php');
        }
        if ($action === 'run_rule') {
            $r = ComplianceMonitorEngine::runRule($pdo, (int)($_POST['rule_id'] ?? 0), 'MANUAL');
            mrules_flash('success', 'Run complete: ' . (int)$r['hits'] . ' hit(s), ' . (int)$r['alerts'] . ' new alert(s).');
            redirect('/admin/compliance/monitoring_rules.php?id=' . (int)($_POST['rule_id'] ?? 0));
        }
    } catch (Throwable $e) {
        mrules_flash('error', $e->getMessage());
        redirect('/admin/compliance/monitoring_rules.php');
    }
}

$flash = mrules_flash_take();
$kindFilter = isset($_GET['kind']) ? strtoupper((string)$_GET['kind']) : '';
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$rules = ComplianceMonitorEngine::listRules($pdo, $kindFilter !== '' ? $kindFilter : null, 200);

cw_header('Compliance · Monitoring rules');
?>
<style>
  .cmprl-h1{margin:0 0 6px;font-size:24px;color:#0f172a;}
  .cmprl-sub{margin:0 0 22px;color:#64748b;max-width:760px;line-height:1.55;}
  .cmprl-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;max-width:1100px;}
  .cmprl-label{display:block;font-size:11px;font-weight:700;color:#64748b;}
  .cmprl-input{padding:8px;border:1px solid #cbd5e1;border-radius:8px;width:100%;box-sizing:border-box;}
  .cmprl-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .cmprl-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
  @media (max-width:720px){.cmprl-grid-2,.cmprl-grid-3{grid-template-columns:1fr;}}
  .cmprl-btn{background:#1e3c72;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:800;cursor:pointer;}
  .cmprl-btn-small{padding:6px 10px;border:0;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;}
  .cmprl-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cmprl-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
  }
  .cmprl-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:top;}
  .cmprl-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cmprl-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;}
  .cmprl-pill.sev-CRITICAL{background:#fee2e2;color:#991b1b;}
  .cmprl-pill.sev-HIGH{background:#ffedd5;color:#9a3412;}
  .cmprl-pill.sev-MEDIUM{background:#fef3c7;color:#92400e;}
  .cmprl-pill.sev-LOW{background:#d1fae5;color:#065f46;}
  .cmprl-flash{padding:12px 16px;border-radius:12px;margin-bottom:16px;}
  .cmprl-flash.is-ok{background:#d1fae5;color:#065f46;}
  .cmprl-flash.is-warn{background:#fef3c7;color:#92400e;}
  .cmprl-flash.is-danger{background:#fee2e2;color:#991b1b;}
  .cmprl-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:18px;}
  .cmprl-tab{
    background:#e2e8f0;color:#0f172a;padding:6px 12px;border-radius:999px;
    text-decoration:none;font-size:12px;font-weight:700;
  }
  .cmprl-tab.is-on{background:#1e3c72;color:#fff;}
</style>

<?php if ($flash !== null):
  $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger'); ?>
  <div class="cmprl-flash <?= h($cls) ?>"><?= h((string)$flash['message']) ?></div>
<?php endif; ?>

<?php
if ($detailId > 0) {
    $r = ComplianceMonitorEngine::getRule($pdo, $detailId);
    if ($r === null) {
        echo '<p>Rule not found.</p>';
        echo '<p><a href="/admin/compliance/monitoring_rules.php" style="color:#1e3c72;font-weight:700;">← All rules</a></p>';
        cw_footer();
        return;
    }
    $threshold = is_string($r['threshold_json'] ?? null) ? (json_decode((string)$r['threshold_json'], true) ?: array()) : array();
    $runs = ComplianceMonitorEngine::listRuns($pdo, $detailId, 10);
    ?>
    <p style="margin-bottom:16px;">
      <a href="/admin/compliance/monitoring_rules.php" style="color:#1e3c72;font-weight:700;text-decoration:none;">← All rules</a>
      <span style="color:#64748b;margin:0 8px;">|</span>
      <span class="cmprl-mono"><?= h((string)$r['rule_code']) ?></span>
    </p>
    <section class="cmprl-card">
      <h2 style="margin:0 0 14px;font-size:18px;"><?= h((string)$r['title']) ?></h2>
      <form method="post">
        <input type="hidden" name="action" value="update_rule">
        <input type="hidden" name="rule_id" value="<?= (int)$detailId ?>">
        <label style="display:block;margin-bottom:12px;">
          <span class="cmprl-label">Title *</span>
          <input class="cmprl-input" name="title" required value="<?= h((string)$r['title']) ?>">
        </label>
        <label style="display:block;margin-bottom:12px;">
          <span class="cmprl-label">Description</span>
          <textarea class="cmprl-input" name="description" rows="3"><?= h((string)($r['description'] ?? '')) ?></textarea>
        </label>
        <div class="cmprl-grid-3" style="margin-bottom:12px;">
          <label>
            <span class="cmprl-label">Monitor kind</span>
            <select class="cmprl-input" name="monitor_kind">
              <?php foreach (ComplianceMonitorEngine::monitorKinds() as $k): ?>
                <option value="<?= h($k) ?>" <?= ((string)$r['monitor_kind'] === $k) ? 'selected' : '' ?>><?= h($k) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="cmprl-label">Alert severity</span>
            <select class="cmprl-input" name="alert_severity">
              <?php foreach (ComplianceMonitorEngine::severities() as $s): ?>
                <option value="<?= h($s) ?>" <?= ((string)$r['alert_severity'] === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="cmprl-label">Cadence</span>
            <select class="cmprl-input" name="cadence">
              <option value="">—</option>
              <?php foreach (ComplianceMonitorEngine::cadences() as $c): ?>
                <option value="<?= h($c) ?>" <?= ((string)($r['cadence'] ?? '') === $c) ? 'selected' : '' ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="cmprl-grid-3" style="margin-bottom:12px;">
          <label>
            <span class="cmprl-label">Built-in evaluator</span>
            <select class="cmprl-input" name="builtin_key">
              <option value="">—</option>
              <?php foreach (ComplianceMonitorEngine::builtinKeys() as $b): ?>
                <option value="<?= h($b) ?>" <?= (((string)($threshold['builtin_key'] ?? '')) === $b) ? 'selected' : '' ?>><?= h($b) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="cmprl-label">Threshold days (CAP_DUE_SOON)</span>
            <input class="cmprl-input" type="number" name="threshold_days"
              value="<?= h((string)($threshold['days'] ?? '')) ?>">
          </label>
          <label>
            <span class="cmprl-label">Cron expression</span>
            <input class="cmprl-input" name="cron_expression" value="<?= h((string)($r['cron_expression'] ?? '')) ?>">
          </label>
        </div>
        <div class="cmprl-grid-2" style="margin-bottom:12px;">
          <label>
            <span class="cmprl-label">Event key (for cadence=EVENT)</span>
            <input class="cmprl-input" name="event_key" value="<?= h((string)($r['event_key'] ?? '')) ?>">
          </label>
          <label>
            <span class="cmprl-label">Notification keys (CSV)</span>
            <input class="cmprl-input" name="notification_keys" value="<?= h((string)($r['notification_keys'] ?? '')) ?>">
          </label>
        </div>
        <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:14px;">
          <input type="checkbox" name="is_active" value="1" <?= ((int)$r['is_active'] === 1) ? 'checked' : '' ?>>
          <span style="font-weight:700;color:#0f172a;">Active</span>
        </label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button type="submit" class="cmprl-btn">Save</button>
          <button type="submit" name="action" value="run_rule" class="cmprl-btn" style="background:#0f766e;">Run now</button>
          <button type="submit" name="action" value="delete_rule" class="cmprl-btn"
            style="background:#b91c1c;"
            onclick="return confirm('Delete this rule? Existing alerts will stay but rule_id will be cleared.');">Delete</button>
        </div>
      </form>
    </section>

    <?php if ($runs !== array()): ?>
      <section class="cmprl-card">
        <h2 style="margin:0 0 14px;font-size:16px;">Recent runs</h2>
        <table class="cmprl-table">
          <thead><tr><th>Started</th><th>Completed</th><th>Status</th><th>Hits</th><th>Trigger</th></tr></thead>
          <tbody>
            <?php foreach ($runs as $rr): ?>
              <tr>
                <td class="cmprl-mono"><?= h(substr((string)($rr['started_at'] ?? ''), 0, 16)) ?></td>
                <td class="cmprl-mono"><?= h(substr((string)($rr['completed_at'] ?? '—'), 0, 16)) ?></td>
                <td><?= h((string)$rr['run_status']) ?></td>
                <td><?= (int)($rr['hit_count'] ?? 0) ?></td>
                <td class="cmprl-mono"><?= h((string)($rr['trigger_source'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <?php
    cw_footer();
    return;
}
?>

<section style="padding:8px 0 40px;">
  <h1 class="cmprl-h1">Monitoring rules</h1>
  <p class="cmprl-sub">
    Define the rules that produce compliance alerts. Each rule is tagged with a
    monitor kind (CAP / FSTD / Safety / Cyber / Live / Other) and can use a
    built-in evaluator like <span class="cmprl-mono">CAP_OVERDUE</span> or
    <span class="cmprl-mono">FINDING_HIGH</span>, or an event key for automation triggers.
  </p>

  <div class="cmprl-tabs">
    <a class="cmprl-tab <?= $kindFilter === '' ? 'is-on' : '' ?>" href="/admin/compliance/monitoring_rules.php">All</a>
    <?php foreach (ComplianceMonitorEngine::monitorKinds() as $k): ?>
      <a class="cmprl-tab <?= $kindFilter === $k ? 'is-on' : '' ?>"
         href="/admin/compliance/monitoring_rules.php?kind=<?= urlencode($k) ?>"><?= h($k) ?></a>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">
    <div class="cmprl-card">
      <h2 style="margin:0 0 14px;font-size:16px;">Rules</h2>
      <?php if ($rules === array()): ?>
        <p style="color:#64748b;margin:0;">No rules yet. Define one on the right.</p>
      <?php else: ?>
        <table class="cmprl-table">
          <thead><tr>
            <th>Code</th><th>Title</th><th>Kind</th><th>Sev</th><th>Active</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($rules as $r): ?>
              <tr>
                <td class="cmprl-mono">
                  <a href="/admin/compliance/monitoring_rules.php?id=<?= (int)$r['id'] ?>"
                     style="color:#1e3c72;font-weight:700;text-decoration:none;"><?= h((string)$r['rule_code']) ?></a>
                </td>
                <td>
                  <?= h((string)$r['title']) ?>
                  <?php if (!empty($r['description'])): ?>
                    <div style="color:#475569;font-size:12px;"><?= h(mb_substr((string)$r['description'], 0, 90)) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h((string)$r['monitor_kind']) ?></td>
                <td><span class="cmprl-pill sev-<?= h((string)$r['alert_severity']) ?>"><?= h((string)$r['alert_severity']) ?></span></td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_rule">
                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="active" value="<?= ((int)$r['is_active'] === 1) ? '0' : '1' ?>">
                    <button type="submit" class="cmprl-btn-small"
                      style="background:<?= ((int)$r['is_active'] === 1) ? '#0f766e' : '#e2e8f0' ?>;color:<?= ((int)$r['is_active'] === 1) ? '#fff' : '#0f172a' ?>;">
                      <?= ((int)$r['is_active'] === 1) ? 'Active' : 'Inactive' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="run_rule">
                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="cmprl-btn-small" style="background:#1e3c72;color:#fff;">Run</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="cmprl-card">
      <h3 style="margin:0 0 14px;font-size:16px;">New rule</h3>
      <form method="post">
        <input type="hidden" name="action" value="create_rule">
        <label style="display:block;margin-bottom:10px;">
          <span class="cmprl-label">Title *</span>
          <input class="cmprl-input" name="title" required>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmprl-label">Description</span>
          <textarea class="cmprl-input" name="description" rows="2"></textarea>
        </label>
        <div class="cmprl-grid-2" style="margin-bottom:10px;">
          <label>
            <span class="cmprl-label">Monitor kind</span>
            <select class="cmprl-input" name="monitor_kind">
              <?php foreach (ComplianceMonitorEngine::monitorKinds() as $k): ?>
                <option value="<?= h($k) ?>" <?= ($k === ($kindFilter !== '' ? $kindFilter : 'CAP')) ? 'selected' : '' ?>><?= h($k) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="cmprl-label">Alert severity</span>
            <select class="cmprl-input" name="alert_severity">
              <?php foreach (ComplianceMonitorEngine::severities() as $s): ?>
                <option value="<?= h($s) ?>" <?= ($s === 'MEDIUM') ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="cmprl-grid-2" style="margin-bottom:10px;">
          <label>
            <span class="cmprl-label">Built-in</span>
            <select class="cmprl-input" name="builtin_key">
              <option value="">—</option>
              <?php foreach (ComplianceMonitorEngine::builtinKeys() as $b): ?>
                <option value="<?= h($b) ?>"><?= h($b) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="cmprl-label">Threshold days</span>
            <input class="cmprl-input" type="number" name="threshold_days" placeholder="e.g. 7">
          </label>
        </div>
        <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:14px;">
          <input type="checkbox" name="is_active" value="1" checked>
          <span style="font-weight:700;color:#0f172a;">Active immediately</span>
        </label>
        <button type="submit" class="cmprl-btn" style="width:100%;">Create rule</button>
      </form>
    </div>
  </div>
</section>
<?php
cw_footer();
