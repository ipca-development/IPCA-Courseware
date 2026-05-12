<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceManualControlEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCaseEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function moc_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_moc'] = array('type' => $type, 'message' => $msg);
}

function moc_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_moc']) || !is_array($_SESSION['_ipca_compliance_flash_moc'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_moc'];
    unset($_SESSION['_ipca_compliance_flash_moc']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_moc_case') {
            $id = ComplianceCaseEngine::create($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'case_type' => 'MANAGEMENT_OF_CHANGE',
                'status' => (string)($_POST['status'] ?? 'OPEN'),
                'severity' => (string)($_POST['severity'] ?? ''),
                'authority' => (string)($_POST['authority'] ?? ''),
                'summary' => (string)($_POST['summary'] ?? ''),
                'opened_at' => (string)($_POST['opened_at'] ?? ''),
                'due_at' => (string)($_POST['due_at'] ?? ''),
                'owner_user_id' => $uid > 0 ? $uid : null,
            ), $uid);
            moc_flash('success', 'Management of Change case created.');
            redirect('/admin/compliance/moc.php');
        }
        if ($action === 'update_case_status') {
            $id = (int)($_POST['case_id'] ?? 0);
            ComplianceCaseEngine::updateStatus($pdo, $id, (string)($_POST['status'] ?? 'OPEN'), $uid);
            moc_flash('success', 'Case status updated.');
            redirect('/admin/compliance/moc.php');
        }
    } catch (Throwable $e) {
        moc_flash('error', $e->getMessage());
        redirect('/admin/compliance/moc.php');
    }
}

function moc_count(PDO $pdo, string $sql): int
{
    try {
        $v = $pdo->query($sql)->fetchColumn();

        return (int)$v;
    } catch (Throwable) {
        return 0;
    }
}

$flash = moc_flash_take();

cw_header('Compliance · Management of Change');

if ($flash !== null) {
    $cls = ($flash['type'] === 'success') ? 'is-ok' : ($flash['type'] === 'warn' ? 'is-warn' : 'is-danger');
    echo '<div class="queue-status ' . h($cls) . '" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

$crTotal = moc_count($pdo, 'SELECT COUNT(*) FROM ipca_compliance_manual_change_requests');
$crOpen = moc_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_change_requests WHERE status NOT IN ('RELEASED','CANCELLED','REJECTED')"
);
$drDraft = moc_count($pdo, "SELECT COUNT(*) FROM ipca_compliance_manual_drafts WHERE status IN ('DRAFT','UNDER_REVIEW')");
$pkgActive = moc_count(
    $pdo,
    "SELECT COUNT(*) FROM ipca_compliance_manual_release_packages WHERE status NOT IN ('RELEASED','SUPERSEDED','CANCELLED')"
);

$mocCases = ComplianceCaseEngine::listByType($pdo, 'MANAGEMENT_OF_CHANGE', 50);

$upcomingPkgs = array();
try {
    $st = $pdo->query(
        "SELECT package_code, title, effective_date, status FROM ipca_compliance_manual_release_packages
         WHERE effective_date IS NOT NULL AND effective_date >= CURDATE()
         ORDER BY effective_date ASC LIMIT 15"
    );
    $upcomingPkgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Throwable) {
    $upcomingPkgs = array();
}

$recentCr = ComplianceManualControlEngine::listChangeRequests($pdo, 12);
?>
<section style="padding:8px 0 40px;">
  <h1 style="margin:0 0 8px;font-size:24px;">Management of change</h1>
  <p style="color:#64748b;margin:0 0 24px;max-width:760px;line-height:1.55;">
    Cross-cut view of MoC cases, change requests, drafts in flight, and release packages not yet final.
    Cases live in <code>ipca_compliance_cases</code> and act as envelopes for related findings, audits, change requests and release packages.
  </p>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:28px;max-width:960px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Change requests</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= (int)$crTotal ?></div>
      <div style="font-size:13px;color:#64748b;"><?= (int)$crOpen ?> open / in-flight</div>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Draft manuals</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= (int)$drDraft ?></div>
      <div style="font-size:13px;color:#64748b;">draft / under review</div>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Packages</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= (int)$pkgActive ?></div>
      <div style="font-size:13px;color:#64748b;">not released / superseded</div>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;">
      <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">MoC cases</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= count($mocCases) ?></div>
      <div style="font-size:13px;color:#64748b;">on file</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;max-width:1200px;margin-bottom:24px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
      <h2 style="margin:0 0 12px;font-size:16px;">MoC cases</h2>
      <?php if ($mocCases !== array()): ?>
        <table style="width:100%;font-size:14px;border-collapse:collapse;">
          <thead><tr style="background:#f1f5f9;text-align:left;">
            <th style="padding:8px 10px;">Code</th>
            <th style="padding:8px 10px;">Title</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Opened</th>
            <th style="padding:8px 10px;">Due</th>
            <th style="padding:8px 10px;"></th>
          </tr></thead>
          <tbody>
            <?php foreach ($mocCases as $c): ?>
              <tr style="border-top:1px solid #e2e8f0;">
                <td style="padding:8px 10px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$c['case_code']) ?></td>
                <td style="padding:8px 10px;"><?= h((string)$c['title']) ?></td>
                <td style="padding:8px 10px;">
                  <?= h((string)$c['status']) ?>
                  <?php if (!empty($c['locked_at'])): ?>
                    <span class="queue-status is-warn" style="margin-left:6px;padding:2px 6px;border-radius:6px;">Locked</span>
                  <?php endif; ?>
                </td>
                <td style="padding:8px 10px;color:#64748b;font-size:12px;"><?= h(substr((string)($c['opened_at'] ?? ''), 0, 10)) ?></td>
                <td style="padding:8px 10px;color:#64748b;font-size:12px;"><?= h(substr((string)($c['due_at'] ?? ''), 0, 10)) ?></td>
                <td style="padding:8px 10px;">
                  <?php if (empty($c['locked_at']) && (string)$c['status'] !== 'CLOSED'): ?>
                    <form method="post" style="display:inline-flex;gap:6px;align-items:center;"
                      onsubmit="return confirm('Close this MoC case?');">
                      <input type="hidden" name="action" value="update_case_status">
                      <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="status" value="CLOSED">
                      <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:6px 10px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;">Close</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="color:#64748b;font-size:14px;margin:0;">No MoC cases yet. Use the form on the right to create one.</p>
      <?php endif; ?>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
      <h3 style="margin:0 0 14px;font-size:16px;">New MoC case</h3>
      <form method="post" action="/admin/compliance/moc.php">
        <input type="hidden" name="action" value="create_moc_case">
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
          <input name="title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
          <label style="flex:1 1 100px;">
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Status</span>
            <select name="status" style="width:100%;padding:8px;border-radius:8px;">
              <option value="OPEN" selected>OPEN</option>
              <option value="IN_PROGRESS">IN_PROGRESS</option>
              <option value="WAITING_AUTHORITY">WAITING_AUTHORITY</option>
            </select>
          </label>
          <label style="flex:1 1 100px;">
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Severity</span>
            <select name="severity" style="width:100%;padding:8px;border-radius:8px;">
              <option value="">—</option>
              <option value="LOW">LOW</option>
              <option value="MEDIUM">MEDIUM</option>
              <option value="HIGH">HIGH</option>
              <option value="CRITICAL">CRITICAL</option>
            </select>
          </label>
        </div>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Authority</span>
          <input name="authority" placeholder="BCAA / EASA / INTERNAL" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Due date</span>
          <input type="date" name="due_at" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:14px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Summary</span>
          <textarea name="summary" rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
        </label>
        <button type="submit" style="width:100%;background:#1e3c72;color:#fff;border:0;padding:12px;border-radius:10px;font-weight:800;cursor:pointer;">
          Create MoC case
        </button>
      </form>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;max-width:1200px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
      <h2 style="margin:0 0 12px;font-size:16px;">Recent change requests</h2>
      <ul style="margin:0;padding:0;list-style:none;font-size:13px;">
        <?php foreach ($recentCr as $r): ?>
          <li style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:12px;">
            <span><a href="/admin/compliance/change_requests.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#1e3c72;"><?= h((string)$r['request_code']) ?></a>
              <span style="color:#64748b;"> — <?= h((string)$r['status']) ?></span></span>
          </li>
        <?php endforeach; ?>
        <?php if ($recentCr === array()): ?>
          <li style="color:#64748b;padding:12px 0;">None yet.</li>
        <?php endif; ?>
      </ul>
      <p style="margin:14px 0 0;"><a href="/admin/compliance/change_requests.php" style="font-weight:700;color:#3730a3;">All requests →</a></p>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
      <h2 style="margin:0 0 12px;font-size:16px;">Upcoming effective dates</h2>
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <?php foreach ($upcomingPkgs as $p): ?>
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:8px 0;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$p['package_code']) ?></td>
            <td style="padding:8px 0;"><?= h((string)($p['effective_date'] ?? '')) ?></td>
            <td style="padding:8px 0;color:#64748b;"><?= h((string)$p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($upcomingPkgs === array()): ?>
        <p style="color:#64748b;font-size:13px;margin:8px 0 0;">No dated packages ahead.</p>
      <?php endif; ?>
      <p style="margin:14px 0 0;">
        <a href="/admin/compliance/manual_approved.php" style="font-weight:700;color:#3730a3;">Packages →</a>
        · <a href="/admin/compliance/manual_drafts.php" style="font-weight:700;color:#3730a3;">Drafts →</a>
      </p>
    </div>
  </div>
</section>
<?php
cw_footer();
