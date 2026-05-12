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
?>
<style>
  .cstgs-h1{margin:0 0 6px;font-size:24px;color:#0f172a;}
  .cstgs-sub{margin:0 0 22px;color:#64748b;max-width:760px;line-height:1.55;}
  .cstgs-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;margin-bottom:20px;max-width:1100px;}
  .cstgs-h2{margin:0 0 14px;font-size:16px;color:#0f172a;}
  .cstgs-table{width:100%;border-collapse:collapse;font-size:14px;}
  .cstgs-table th{
    text-align:left;font-size:11px;color:#64748b;font-weight:800;letter-spacing:.05em;
    text-transform:uppercase;padding:6px 8px;background:#f1f5f9;
  }
  .cstgs-table td{padding:8px;border-top:1px solid #e2e8f0;vertical-align:middle;}
  .cstgs-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}
  .cstgs-btn-small{padding:6px 10px;border:0;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;}
  .cstgs-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;}
  .cstgs-pill.is-on{background:#d1fae5;color:#065f46;}
  .cstgs-pill.is-off{background:#e2e8f0;color:#475569;}
  .cstgs-flash{padding:12px 16px;border-radius:12px;margin-bottom:16px;}
  .cstgs-flash.is-ok{background:#d1fae5;color:#065f46;}
  .cstgs-flash.is-warn{background:#fef3c7;color:#92400e;}
  .cstgs-flash.is-danger{background:#fee2e2;color:#991b1b;}
  .cstgs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
  .cstgs-mini{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;}
  .cstgs-mini-k{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;}
  .cstgs-mini-v{font-size:22px;font-weight:800;color:#0f172a;}
</style>

<?php if ($flash !== null):
  $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger'); ?>
  <div class="cstgs-flash <?= h($cls) ?>"><?= h((string)$flash['message']) ?></div>
<?php endif; ?>

<section style="padding:8px 0 40px;">
  <h1 class="cstgs-h1">Compliance settings</h1>
  <p class="cstgs-sub">
    Per-org configuration for the Compliance OS: which admins have access, what data is on file,
    where artefacts are stored. Org-wide defaults live here so the operational UIs stay focused.
  </p>

  <section class="cstgs-card">
    <h2 class="cstgs-h2">Compliance admins</h2>
    <?php if (!$hasCAFlag): ?>
      <p style="color:#92400e;font-size:13px;background:#fef3c7;padding:10px 12px;border-radius:8px;">
        The <code>users.is_compliance_admin</code> column hasn't been added yet. Apply
        <code>scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql</code> to enable
        per-admin compliance gating. Until then every admin has compliance access.
      </p>
    <?php endif; ?>

    <?php if ($admins === array()): ?>
      <p style="color:#64748b;margin:0;">No admins on file.</p>
    <?php else: ?>
      <table class="cstgs-table">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Email</th><th>Compliance access</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($admins as $a):
            $flag = $hasCAFlag ? (int)($a['is_compliance_admin'] ?? 0) : 1;
          ?>
            <tr>
              <td class="cstgs-mono"><?= (int)$a['id'] ?></td>
              <td><?= h((string)($a['name'] ?? '')) ?></td>
              <td class="cstgs-mono"><?= h((string)($a['email'] ?? '')) ?></td>
              <td>
                <?php if ($flag === 1): ?>
                  <span class="cstgs-pill is-on">Yes</span>
                <?php else: ?>
                  <span class="cstgs-pill is-off">No</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($hasCAFlag && (int)$a['id'] !== $uid): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_compliance_admin">
                    <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="flag" value="<?= $flag === 1 ? '0' : '1' ?>">
                    <button type="submit" class="cstgs-btn-small"
                      style="background:<?= $flag === 1 ? '#fee2e2' : '#0f766e' ?>;color:<?= $flag === 1 ? '#991b1b' : '#fff' ?>;"
                      onclick="return confirm('<?= $flag === 1 ? 'Revoke compliance access?' : 'Grant compliance access?' ?>');">
                      <?= $flag === 1 ? 'Revoke' : 'Grant' ?>
                    </button>
                  </form>
                <?php elseif ((int)$a['id'] === $uid): ?>
                  <span style="color:#64748b;font-size:12px;">(you)</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="cstgs-card">
    <h2 class="cstgs-h2">System status</h2>
    <div class="cstgs-grid">
      <?php foreach ($counts as $k => $v): ?>
        <div class="cstgs-mini">
          <div class="cstgs-mini-k"><?= h((string)$k) ?></div>
          <div class="cstgs-mini-v"><?= (int)$v ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="cstgs-card">
    <h2 class="cstgs-h2">Storage</h2>
    <table class="cstgs-table">
      <tbody>
        <tr>
          <td style="width:280px;font-weight:700;">Manual-release PDF root</td>
          <td class="cstgs-mono"><?= h('storage/compliance/manual_releases') ?></td>
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
          <td><?= is_dir($pdfRoot) ? '<span class="cstgs-pill is-on">Yes</span>' : '<span class="cstgs-pill is-off">No</span>' ?></td>
        </tr>
      </tbody>
    </table>
  </section>

  <section class="cstgs-card">
    <h2 class="cstgs-h2">Reference</h2>
    <p style="margin:0 0 10px;color:#475569;font-size:14px;">Phase migrations and seed scripts that this module relies on:</p>
    <ul style="margin:0;padding-left:18px;color:#334155;font-size:13px;line-height:1.7;">
      <li><span class="cstgs-mono">scripts/sql/compliance_os_phase_1_initial_schema.sql</span> — full ipca_compliance_* schema</li>
      <li><span class="cstgs-mono">scripts/sql/compliance_os_phase_2_5_users_is_compliance_admin.sql</span> — per-admin compliance access flag</li>
      <li><span class="cstgs-mono">scripts/sql/seeds/legacy_compliance_tableplus_dump.sql</span> — legacy seed data</li>
      <li><span class="cstgs-mono">scripts/run_compliance_legacy_seed.sh</span> — idempotent legacy import</li>
    </ul>
  </section>
</section>
<?php
cw_footer();
