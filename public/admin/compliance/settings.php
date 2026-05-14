<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function cstgs_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_settings'] = array('type' => $type, 'message' => $msg);
}

function cstgs_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_settings']) || !is_array($_SESSION['_ipca_compliance_flash_settings'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_settings'];
    unset($_SESSION['_ipca_compliance_flash_settings']);

    return $f;
}

// Detect whether the optional is_compliance_admin column exists (phase 2.5 migration).
$hasCAFlag = false;
try {
    $pdo->query('SELECT is_compliance_admin FROM users LIMIT 0');
    $hasCAFlag = true;
} catch (Throwable) {
    $hasCAFlag = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'toggle_compliance_admin') {
            if (!$hasCAFlag) {
                throw new RuntimeException('is_compliance_admin column not present — run scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql.');
            }
            $targetId = (int)($_POST['user_id'] ?? 0);
            $flag = ((string)($_POST['flag'] ?? '0')) === '1' ? 1 : 0;
            if ($targetId <= 0) {
                throw new InvalidArgumentException('Invalid user id.');
            }
            // Defensive: do not let the current user disable their own flag if they are sole compliance admin.
            $pdo->prepare('UPDATE users SET is_compliance_admin = ? WHERE id = ? AND role = \'admin\'')
                ->execute(array($flag, $targetId));
            cstgs_flash('success', $flag === 1 ? 'Compliance access granted.' : 'Compliance access revoked.');
        }
    } catch (Throwable $e) {
        cstgs_flash('error', $e->getMessage());
    }
    redirect('/admin/compliance/settings.php');
}

$flash = cstgs_flash_take();

// Load admin users (limit defensive).
$admins = array();
try {
    $sql = $hasCAFlag
        ? 'SELECT id, name, email, is_compliance_admin FROM users WHERE role = \'admin\' ORDER BY id ASC LIMIT 500'
        : 'SELECT id, name, email FROM users WHERE role = \'admin\' ORDER BY id ASC LIMIT 500';
    $admins = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable) {
    $admins = array();
}

// Compliance row counts for "system health" panel.
function cstgs_count(PDO $pdo, string $table): int
{
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

$counts = array(
    'Audits' => cstgs_count($pdo, 'ipca_compliance_audits'),
    'Findings' => cstgs_count($pdo, 'ipca_compliance_findings'),
    'Corrective actions' => cstgs_count($pdo, 'ipca_compliance_corrective_actions'),
    'Checklist templates' => cstgs_count($pdo, 'ipca_compliance_checklist_templates'),
    'Manual change requests' => cstgs_count($pdo, 'ipca_compliance_manual_change_requests'),
    'Manual drafts' => cstgs_count($pdo, 'ipca_compliance_manual_drafts'),
    'Release packages' => cstgs_count($pdo, 'ipca_compliance_manual_release_packages'),
    'Meetings' => cstgs_count($pdo, 'ipca_compliance_meetings'),
    'Inbound emails' => cstgs_count($pdo, 'ipca_compliance_inbound_emails'),
    'Monitor rules' => cstgs_count($pdo, 'ipca_compliance_monitor_rules'),
    'Open alerts' => 0,
);
try {
    $counts['Open alerts'] = (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_alerts WHERE status='OPEN'")->fetchColumn();
} catch (Throwable) {
    // ignore
}

// Storage usage for release package PDFs.
$pdfRoot = dirname(__DIR__, 3) . '/storage/compliance/manual_releases';
$pdfCount = 0;
$pdfBytes = 0;
if (is_dir($pdfRoot)) {
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pdfRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof SplFileInfo && $f->isFile() && strtolower($f->getExtension()) === 'pdf') {
                $pdfCount++;
                $pdfBytes += (int)$f->getSize();
            }
        }
    } catch (Throwable) {
        // ignore
    }
}

cw_header('Compliance · Settings');

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

compliance_page_open(array(
    'overline' => 'Compliance · Settings',
    'title' => 'Compliance settings',
    'description' => 'Per-org configuration for the Compliance OS: which admins have access, what data is on file, where artefacts are stored. Org-wide defaults live here so the operational UIs stay focused.',
    'stats' => array(
        array('label' => 'Admins on file', 'value' => count($admins)),
        array('label' => 'PDFs on disk',   'value' => (int)$pdfCount, 'sub' => number_format($pdfBytes / 1024 / 1024, 1) . ' MB'),
        array('label' => 'Open alerts',    'value' => (int)$counts['Open alerts'], 'tone' => $counts['Open alerts'] > 0 ? 'warn' : 'ok', 'href' => '/admin/compliance/monitoring_rules.php'),
        array('label' => 'Audits',         'value' => (int)$counts['Audits']),
    ),
    'flash' => $flash,
));
?>

  <section class="cmp-card">
    <div class="cmp-list-head" style="margin-bottom:14px;">
      <div class="cmp-list-title">
        <?= compliance_ui_icon('list') ?>
        <span>Compliance admins</span>
      </div>
      <div class="cmp-count-pill"><?= count($admins) ?></div>
    </div>
    <?php if (!$hasCAFlag): ?>
      <p style="color:#7a5419;font-size:13px;background:rgba(232,167,72,0.16);padding:10px 12px;border-radius:8px;border:1px solid rgba(232,167,72,0.32);">
        The <code>users.is_compliance_admin</code> column hasn't been added yet. Apply
        <code>scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql</code> to enable
        per-admin compliance gating. Until then every admin has compliance access.
      </p>
    <?php endif; ?>

    <?php if ($admins === array()): ?>
      <p style="color:var(--text-muted);margin:0;">No admins on file.</p>
    <?php else: ?>
      <div class="compliance-table-wrap">
      <table class="compliance-table">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Email</th><th>Compliance access</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($admins as $a):
            $flag = $hasCAFlag ? (int)($a['is_compliance_admin'] ?? 0) : 1;
          ?>
            <tr>
              <td class="cmp-mono"><?= (int)$a['id'] ?></td>
              <td><?= h((string)($a['name'] ?? '')) ?></td>
              <td class="cmp-mono"><?= h((string)($a['email'] ?? '')) ?></td>
              <td>
                <?php if ($flag === 1): ?>
                  <span class="cmp-pill cmp-pill-ok">Yes</span>
                <?php else: ?>
                  <span class="cmp-pill">No</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($hasCAFlag && (int)$a['id'] !== $uid): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_compliance_admin">
                    <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="flag" value="<?= $flag === 1 ? '0' : '1' ?>">
                    <button type="submit" class="<?= $flag === 1 ? 'cmp-btn-secondary' : '' ?>" style="height:32px;min-height:32px;padding:0 12px;font-size:12px;"
                      onclick="return confirm('<?= $flag === 1 ? 'Revoke compliance access?' : 'Grant compliance access?' ?>');">
                      <?= $flag === 1 ? 'Revoke' : 'Grant' ?>
                    </button>
                  </form>
                <?php elseif ((int)$a['id'] === $uid): ?>
                  <span style="color:var(--text-muted);font-size:12px;">(you)</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="cmp-card">
    <h2 style="margin:0 0 14px;">System status</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;">
      <?php foreach ($counts as $k => $v): ?>
        <div style="background:rgba(48,124,183,0.06);border:1px solid rgba(48,124,183,0.18);border-radius:12px;padding:14px 16px;">
          <div style="font-size:11px;font-weight:720;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;"><?= h((string)$k) ?></div>
          <div style="font-size:24px;font-weight:720;color:#1f4079;margin-top:4px;"><?= (int)$v ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="cmp-card">
    <h2 style="margin:0 0 14px;">Storage</h2>
    <div class="compliance-table-wrap">
    <table class="compliance-table">
      <tbody>
        <tr>
          <td style="width:280px;font-weight:700;">Manual-release PDF root</td>
          <td class="cmp-mono"><?= h('storage/compliance/manual_releases') ?></td>
        </tr>
        <tr>
          <td style="font-weight:700;">PDFs on disk</td>
          <td><?= (int)$pdfCount ?></td>
        </tr>
        <tr>
          <td style="font-weight:700;">Total size</td>
          <td><?= h(number_format($pdfBytes / 1024 / 1024, 2)) ?> MB</td>
        </tr>
        <tr>
          <td style="font-weight:700;">Directory exists</td>
          <td><?= is_dir($pdfRoot) ? '<span class="cmp-pill cmp-pill-ok">Yes</span>' : '<span class="cmp-pill">No</span>' ?></td>
        </tr>
      </tbody>
    </table>
    </div>
  </section>

  <section class="cmp-card">
    <h2 style="margin:0 0 14px;">Reference</h2>
    <p style="margin:0 0 10px;color:var(--text-muted);font-size:14px;">Phase migrations and seed scripts that this module relies on:</p>
    <ul style="margin:0;padding-left:18px;color:#334155;font-size:13px;line-height:1.7;">
      <li><span class="cmp-mono">scripts/sql/compliance_os_phase_1_initial_schema.sql</span> — full ipca_compliance_* schema</li>
      <li><span class="cmp-mono">scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql</span> — per-admin compliance access flag</li>
      <li><span class="cmp-mono">scripts/sql/seeds/legacy_compliance_tableplus_dump.sql</span> — legacy seed data</li>
      <li><span class="cmp-mono">scripts/run_compliance_legacy_seed.sh</span> — idempotent legacy import</li>
    </ul>
  </section>
<?php
compliance_page_close();
cw_footer();
