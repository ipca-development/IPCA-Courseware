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
$filterQ = trim((string)($_GET['q'] ?? ''));
$filterActive = trim((string)($_GET['active'] ?? ''));
$filterSeverity = strtoupper(trim((string)($_GET['severity'] ?? '')));
$rules = array_values(array_filter($rules, static function (array $r) use ($filterQ, $filterActive, $filterSeverity): bool {
    if ($filterQ !== '') {
        $hay = strtolower((string)($r['rule_code'] ?? '') . ' ' . (string)($r['title'] ?? '') . ' ' . (string)($r['description'] ?? ''));
        if (strpos($hay, strtolower($filterQ)) === false) { return false; }
    }
    if ($filterActive === '1' && (int)($r['is_active'] ?? 0) !== 1) { return false; }
    if ($filterActive === '0' && (int)($r['is_active'] ?? 0) === 1) { return false; }
    if ($filterSeverity !== '' && strtoupper((string)($r['alert_severity'] ?? '')) !== $filterSeverity) { return false; }
    return true;
}));

cw_header('Compliance · Monitoring rules');

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
?>
<style>
  .cmprl-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
  .cmprl-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
  @media (max-width:720px){.cmprl-grid-2,.cmprl-grid-3{grid-template-columns:1fr;}}
  .cmprl-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
  .cmprl-tab{background:rgba(48,124,183,0.08);color:#1f4079;padding:8px 14px;border-radius:999px;text-decoration:none;font-size:12.5px;font-weight:720;letter-spacing:.04em;border:1px solid rgba(48,124,183,0.18);}
  .cmprl-tab.is-on{background:linear-gradient(120deg,#1f4079 0%,#307cb7 100%);color:#fff;border-color:transparent;}
</style>

<?php
if ($detailId > 0) {
    $r = ComplianceMonitorEngine::getRule($pdo, $detailId);
    if ($r === null) {
        compliance_page_open(array(
            'overline' => 'Compliance · Monitoring rules',
            'title' => 'Rule not found',
            'back' => array('href' => '/admin/compliance/monitoring_rules.php', 'label' => 'All rules'),
        ));
        echo '<section class="cmp-card"><p style="margin:0;">No row for that rule id.</p></section>';
        compliance_page_close();
        cw_footer();
        return;
    }
    $threshold = is_string($r['threshold_json'] ?? null) ? (json_decode((string)$r['threshold_json'], true) ?: array()) : array();
    $runs = ComplianceMonitorEngine::listRuns($pdo, $detailId, 10);
    compliance_page_open(array(
        'overline' => 'Compliance · Monitoring rule',
        'title' => (string)$r['title'],
        'description' => 'Monitor kind: ' . (string)$r['monitor_kind'] . ' · Cadence: ' . (string)($r['cadence'] ?? '—') . ' · ' . ((int)$r['is_active'] === 1 ? 'Active' : 'Inactive'),
        'back' => array('href' => '/admin/compliance/monitoring_rules.php', 'label' => 'All rules', 'code' => (string)$r['rule_code']),
        'flash' => $flash,
    ));
    ?>
    <section class="cmp-card">
      <h2 style="margin:0 0 14px;"><?= h((string)$r['title']) ?></h2>
      <form method="post">
        <input type="hidden" name="action" value="update_rule">
        <input type="hidden" name="rule_id" value="<?= (int)$detailId ?>">
        <label class="cmp-field">
          <span class="cmp-field-label">Title *</span>
          <input name="title" required value="<?= h((string)$r['title']) ?>">
        </label>
        <label class="cmp-field">
          <span class="cmp-field-label">Description</span>
          <textarea name="description" rows="3"><?= h((string)($r['description'] ?? '')) ?></textarea>
        </label>
        <div class="cmprl-grid-3" style="margin-bottom:12px;">
          <label class="cmp-field">
            <span class="cmp-field-label">Monitor kind</span>
            <select name="monitor_kind">
              <?php foreach (ComplianceMonitorEngine::monitorKinds() as $k): ?>
                <option value="<?= h($k) ?>" <?= ((string)$r['monitor_kind'] === $k) ? 'selected' : '' ?>><?= h($k) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Alert severity</span>
            <select name="alert_severity">
              <?php foreach (ComplianceMonitorEngine::severities() as $s): ?>
                <option value="<?= h($s) ?>" <?= ((string)$r['alert_severity'] === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Cadence</span>
            <select name="cadence">
              <option value="">—</option>
              <?php foreach (ComplianceMonitorEngine::cadences() as $c): ?>
                <option value="<?= h($c) ?>" <?= ((string)($r['cadence'] ?? '') === $c) ? 'selected' : '' ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="cmprl-grid-3" style="margin-bottom:12px;">
          <label class="cmp-field">
            <span class="cmp-field-label">Built-in evaluator</span>
            <select name="builtin_key">
              <option value="">—</option>
              <?php foreach (ComplianceMonitorEngine::builtinKeys() as $b): ?>
                <option value="<?= h($b) ?>" <?= (((string)($threshold['builtin_key'] ?? '')) === $b) ? 'selected' : '' ?>><?= h($b) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Threshold days (CAP_DUE_SOON)</span>
            <input type="number" name="threshold_days" value="<?= h((string)($threshold['days'] ?? '')) ?>">
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Cron expression</span>
            <input name="cron_expression" value="<?= h((string)($r['cron_expression'] ?? '')) ?>">
          </label>
        </div>
        <div class="cmprl-grid-2" style="margin-bottom:12px;">
          <label class="cmp-field">
            <span class="cmp-field-label">Event key (for cadence=EVENT)</span>
            <input name="event_key" value="<?= h((string)($r['event_key'] ?? '')) ?>">
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Notification keys (CSV)</span>
            <input name="notification_keys" value="<?= h((string)($r['notification_keys'] ?? '')) ?>">
          </label>
        </div>
        <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:14px;">
          <input type="checkbox" name="is_active" value="1" <?= ((int)$r['is_active'] === 1) ? 'checked' : '' ?>>
          <span style="font-weight:700;color:#0f172a;">Active</span>
        </label>
        <div class="cmp-toolbar" style="margin:0;">
          <button type="submit">Save</button>
          <button type="submit" name="action" value="run_rule" class="cmp-btn-secondary">Run now</button>
          <button type="submit" name="action" value="delete_rule" class="cmp-btn-secondary"
            onclick="return confirm('Delete this rule? Existing alerts will stay but rule_id will be cleared.');">Delete</button>
        </div>
      </form>
    </section>

    <?php if ($runs !== array()): ?>
      <section class="cmp-card">
        <div class="cmp-list-head" style="margin-bottom:14px;">
          <div class="cmp-list-title"><?= compliance_ui_icon('pulse') ?><span>Recent runs</span></div>
          <div class="cmp-count-pill"><?= count($runs) ?></div>
        </div>
        <div class="compliance-table-wrap">
        <table class="compliance-table">
          <thead><tr><th>Started</th><th>Completed</th><th>Status</th><th>Hits</th><th>Trigger</th></tr></thead>
          <tbody>
            <?php foreach ($runs as $rr): ?>
              <tr>
                <td class="cmp-mono"><?= h(substr((string)($rr['started_at'] ?? ''), 0, 16)) ?></td>
                <td class="cmp-mono"><?= h(substr((string)($rr['completed_at'] ?? '—'), 0, 16)) ?></td>
                <td><span class="cmp-pill"><?= h((string)$rr['run_status']) ?></span></td>
                <td><?= (int)($rr['hit_count'] ?? 0) ?></td>
                <td class="cmp-mono"><?= h((string)($rr['trigger_source'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>
    <?php endif; ?>

    <?php
    compliance_page_close();
    cw_footer();
    return;
}
?>

<?php
$activeRules = count(array_filter($rules, static fn($r) => (int)$r['is_active'] === 1));

compliance_page_open(array(
    'overline' => 'Compliance · Monitoring',
    'title' => 'Monitoring rules',
    'description' => 'Define the rules that produce compliance alerts. Each rule is tagged with a monitor kind (CAP / FSTD / Safety / Cyber / Live / Other) and can use a built-in evaluator or an event key for automation triggers.',
    'actions' => array(
        array('label' => 'New monitoring rule', 'modal' => 'rule-create-modal', 'icon' => 'plus'),
    ),
    'stats' => array(
        array('label' => 'Total rules', 'value' => count($rules)),
        array('label' => 'Active',      'value' => $activeRules, 'tone' => 'ok'),
        array('label' => 'Inactive',    'value' => count($rules) - $activeRules),
    ),
    'flash' => $flash,
));
?>

  <div class="cmprl-tabs">
    <a class="cmprl-tab <?= $kindFilter === '' ? 'is-on' : '' ?>" href="/admin/compliance/monitoring_rules.php">All</a>
    <?php foreach (ComplianceMonitorEngine::monitorKinds() as $k): ?>
      <a class="cmprl-tab <?= $kindFilter === $k ? 'is-on' : '' ?>"
         href="/admin/compliance/monitoring_rules.php?kind=<?= urlencode($k) ?>"><?= h($k) ?></a>
    <?php endforeach; ?>
  </div>

  <section class="cmp-card">
    <form method="get" class="compliance-filterbar">
      <?php if ($kindFilter !== ''): ?><input type="hidden" name="kind" value="<?= h($kindFilter) ?>"><?php endif; ?>
      <label class="cmp-field compliance-filterbar__search"><span>Search</span><input type="search" name="q" value="<?= h($filterQ) ?>" placeholder="Rule code, title or description"></label>
      <label class="cmp-field"><span>State</span><select name="active"><option value="" <?= $filterActive === '' ? 'selected' : '' ?>>All states</option><option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>Active</option><option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>Inactive</option></select></label>
      <label class="cmp-field"><span>Severity</span><select name="severity"><option value="">All severities</option><?php foreach (ComplianceMonitorEngine::severities() as $s): ?><option value="<?= h($s) ?>" <?= $filterSeverity === strtoupper($s) ? 'selected' : '' ?>><?= h(compliance_friendly_label($s)) ?></option><?php endforeach; ?></select></label>
      <div class="cmp-toolbar-actions" style="margin:0;"><button type="submit">Apply filters</button><a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/monitoring_rules.php">Clear</a></div>
    </form>
  </section>
    <section class="cmp-card compliance-card--full">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title"><?= compliance_ui_icon('pulse') ?><span>Rules</span></div>
        <div class="cmp-count-pill"><?= count($rules) ?></div>
      </div>
      <?php if ($rules === array()): ?>
        <p style="color:var(--text-muted);margin:0;">No rules match this filter.</p>
      <?php else: ?>
        <div class="compliance-table-wrap">
        <table class="compliance-table">
          <thead><tr>
            <th>Code</th><th>Title</th><th>Kind</th><th>Sev</th><th>Active</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($rules as $r):
              $sev = (string)$r['alert_severity'];
            ?>
              <tr data-href="/admin/compliance/monitoring_rules.php?id=<?= (int)$r['id'] ?>" class="compliance-row-clickable">
                <td class="cmp-mono">
                  <a href="/admin/compliance/monitoring_rules.php?id=<?= (int)$r['id'] ?>"
                     style="color:#1f4079;font-weight:700;text-decoration:none;"><?= h((string)$r['rule_code']) ?></a>
                </td>
                <td>
                  <?= h((string)$r['title']) ?>
                  <?php if (!empty($r['description'])): ?>
                    <div style="color:var(--text-muted);font-size:12px;"><?= h(mb_substr((string)$r['description'], 0, 90)) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="cmp-pill"><?= h(compliance_friendly_label((string)$r['monitor_kind'])) ?></span></td>
                <td><?= compliance_badge($sev, 'severity') ?></td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_rule">
                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="active" value="<?= ((int)$r['is_active'] === 1) ? '0' : '1' ?>">
                    <button type="submit" class="<?= ((int)$r['is_active'] === 1) ? '' : 'cmp-btn-secondary' ?>" style="height:32px;min-height:32px;padding:0 12px;font-size:12px;">
                      <?= ((int)$r['is_active'] === 1) ? 'Active' : 'Inactive' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="run_rule">
                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" style="height:32px;min-height:32px;padding:0 12px;font-size:12px;">Run</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <?php compliance_modal_open('rule-create-modal', 'New monitoring rule'); ?>
      <form method="post">
        <input type="hidden" name="action" value="create_rule">
        <label class="cmp-field">
          <span class="cmp-field-label">Title *</span>
          <input name="title" required>
        </label>
        <label class="cmp-field">
          <span class="cmp-field-label">Description</span>
          <textarea name="description" rows="2"></textarea>
        </label>
        <div class="cmprl-grid-2" style="margin-bottom:10px;">
          <label class="cmp-field">
            <span class="cmp-field-label">Monitor kind</span>
            <select name="monitor_kind">
              <?php foreach (ComplianceMonitorEngine::monitorKinds() as $k): ?>
                <option value="<?= h($k) ?>" <?= ($k === ($kindFilter !== '' ? $kindFilter : 'CAP')) ? 'selected' : '' ?>><?= h($k) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Alert severity</span>
            <select name="alert_severity">
              <?php foreach (ComplianceMonitorEngine::severities() as $s): ?>
                <option value="<?= h($s) ?>" <?= ($s === 'MEDIUM') ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="cmprl-grid-2" style="margin-bottom:10px;">
          <label class="cmp-field">
            <span class="cmp-field-label">Built-in</span>
            <select name="builtin_key">
              <option value="">—</option>
              <?php foreach (ComplianceMonitorEngine::builtinKeys() as $b): ?>
                <option value="<?= h($b) ?>"><?= h($b) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Threshold days</span>
            <input type="number" name="threshold_days" placeholder="e.g. 7">
          </label>
        </div>
        <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:14px;">
          <input type="checkbox" name="is_active" value="1" checked>
          <span style="font-weight:700;color:#0f172a;">Active immediately</span>
        </label>
        <div class="compliance-modal__footer"><button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button><button type="submit">Create rule</button></div>
      </form>
    <?php compliance_modal_close(); ?>
<?php
compliance_page_close();
cw_footer();
