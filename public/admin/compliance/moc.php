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

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

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

compliance_page_open(array(
    'overline' => 'Compliance · Management of change',
    'title' => 'Management of change',
    'description' => 'Cross-cut view of MoC cases, change requests, drafts in flight, and release packages not yet final. Cases act as envelopes for related findings, audits, change requests and release packages.',
    'stats' => array(
        array('label' => 'Change requests', 'value' => (int)$crTotal,        'sub' => (int)$crOpen . ' open / in-flight', 'href' => '/admin/compliance/change_requests.php', 'tone' => $crOpen > 0 ? 'warn' : 'ok'),
        array('label' => 'Draft manuals',   'value' => (int)$drDraft,        'sub' => 'draft / under review', 'href' => '/admin/compliance/manual_drafts.php'),
        array('label' => 'Packages',        'value' => (int)$pkgActive,      'sub' => 'in flight', 'href' => '/admin/compliance/manual_approved.php'),
        array('label' => 'MoC cases',       'value' => count($mocCases),     'sub' => 'on file'),
    ),
    'flash' => $flash,
));
?>
  <div class="cmp-cols">
    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('list') ?>
          <span>MoC cases</span>
        </div>
        <div class="cmp-count-pill"><?= count($mocCases) ?></div>
      </div>
      <?php if ($mocCases !== array()): ?>
        <div class="compliance-table-wrap">
        <table class="compliance-table">
          <thead><tr>
            <th>Code</th>
            <th>Title</th>
            <th>Status</th>
            <th>Opened</th>
            <th>Due</th>
            <th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($mocCases as $c): ?>
              <tr>
                <td class="cmp-mono"><?= h((string)$c['case_code']) ?></td>
                <td><?= h((string)$c['title']) ?></td>
                <td>
                  <span class="cmp-pill"><?= h((string)$c['status']) ?></span>
                  <?php if (!empty($c['locked_at'])): ?>
                    <span class="cmp-pill" style="margin-left:6px;">Locked</span>
                  <?php endif; ?>
                </td>
                <td class="cmp-mono"><?= h(substr((string)($c['opened_at'] ?? ''), 0, 10)) ?></td>
                <td class="cmp-mono"><?= h(substr((string)($c['due_at'] ?? ''), 0, 10)) ?></td>
                <td>
                  <?php if (empty($c['locked_at']) && (string)$c['status'] !== 'CLOSED'): ?>
                    <form method="post" style="display:inline-flex;gap:6px;align-items:center;"
                      onsubmit="return confirm('Close this MoC case?');">
                      <input type="hidden" name="action" value="update_case_status">
                      <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="status" value="CLOSED">
                      <button type="submit" class="cmp-btn-secondary" style="height:32px;min-height:32px;padding:0 12px;font-size:12px;">Close</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php else: ?>
        <p style="color:var(--text-muted);font-size:14px;margin:0;">No MoC cases yet. Use the form on the right to create one.</p>
      <?php endif; ?>
    </section>

    <section class="cmp-card">
      <h3 style="margin:0 0 14px;">New MoC case</h3>
      <form method="post" action="/admin/compliance/moc.php">
        <input type="hidden" name="action" value="create_moc_case">
        <label class="cmp-field">
          <span class="cmp-field-label">Title *</span>
          <input name="title" required>
        </label>
        <div class="cmp-field-row">
          <label class="cmp-field">
            <span class="cmp-field-label">Status</span>
            <select name="status">
              <option value="OPEN" selected>OPEN</option>
              <option value="IN_PROGRESS">IN_PROGRESS</option>
              <option value="WAITING_AUTHORITY">WAITING_AUTHORITY</option>
            </select>
          </label>
          <label class="cmp-field">
            <span class="cmp-field-label">Severity</span>
            <select name="severity">
              <option value="">—</option>
              <option value="LOW">LOW</option>
              <option value="MEDIUM">MEDIUM</option>
              <option value="HIGH">HIGH</option>
              <option value="CRITICAL">CRITICAL</option>
            </select>
          </label>
        </div>
        <label class="cmp-field">
          <span class="cmp-field-label">Authority</span>
          <input name="authority" placeholder="BCAA / EASA / INTERNAL">
        </label>
        <label class="cmp-field">
          <span class="cmp-field-label">Due date</span>
          <input type="date" name="due_at">
        </label>
        <label class="cmp-field">
          <span class="cmp-field-label">Summary</span>
          <textarea name="summary" rows="4"></textarea>
        </label>
        <button type="submit" style="width:100%;">Create MoC case</button>
      </form>
    </section>
  </div>

  <div class="cmp-card-grid" style="margin-top:20px;">
    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('document') ?>
          <span>Recent change requests</span>
        </div>
        <div class="cmp-count-pill"><?= count($recentCr) ?></div>
      </div>
      <ul style="margin:0;padding:0;list-style:none;font-size:13px;">
        <?php foreach ($recentCr as $r): ?>
          <li style="padding:10px 0;border-bottom:1px solid var(--border-soft);display:flex;justify-content:space-between;gap:12px;">
            <span>
              <a href="/admin/compliance/change_requests.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#1f4079;text-decoration:none;"><?= h((string)$r['request_code']) ?></a>
              <span style="color:var(--text-muted);"> — <?= h((string)$r['status']) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
        <?php if ($recentCr === array()): ?>
          <li style="color:var(--text-muted);padding:12px 0;">None yet.</li>
        <?php endif; ?>
      </ul>
      <p style="margin:14px 0 0;"><a class="cmp-btn-link" href="/admin/compliance/change_requests.php" style="text-decoration:none;font-weight:700;color:#1f4079;">All requests →</a></p>
    </section>

    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('calendar') ?>
          <span>Upcoming effective dates</span>
        </div>
        <div class="cmp-count-pill"><?= count($upcomingPkgs) ?></div>
      </div>
      <div class="compliance-table-wrap">
      <table class="compliance-table">
        <?php foreach ($upcomingPkgs as $p): ?>
          <tr>
            <td class="cmp-mono"><?= h((string)$p['package_code']) ?></td>
            <td class="cmp-mono"><?= h((string)($p['effective_date'] ?? '')) ?></td>
            <td><span class="cmp-pill"><?= h((string)$p['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php if ($upcomingPkgs === array()): ?>
        <p style="color:var(--text-muted);font-size:13px;margin:8px 0 0;">No dated packages ahead.</p>
      <?php endif; ?>
      <p style="margin:14px 0 0;">
        <a href="/admin/compliance/manual_approved.php" style="font-weight:700;color:#1f4079;text-decoration:none;">Packages →</a>
        · <a href="/admin/compliance/manual_drafts.php" style="font-weight:700;color:#1f4079;text-decoration:none;">Drafts →</a>
      </p>
    </section>
  </div>
<?php
compliance_page_close();
cw_footer();
