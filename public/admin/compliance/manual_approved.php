<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceManualControlEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function mr_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash'] = array('type' => $type, 'message' => $msg);
}

function mr_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash']) || !is_array($_SESSION['_ipca_compliance_flash'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash'];
    unset($_SESSION['_ipca_compliance_flash']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_pkg') {
            $id = ComplianceManualControlEngine::createPackage($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'manual_code' => (string)($_POST['manual_code'] ?? ''),
                'target_revision' => (string)($_POST['target_revision'] ?? ''),
                'effective_date' => (string)($_POST['effective_date'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'PLANNED'),
                'drafts_json' => (string)($_POST['drafts_json'] ?? '[]'),
            ), $uid);
            mr_flash('success', 'Package created.');
            redirect('/admin/compliance/manual_approved.php?id=' . $id);
        }
        if ($action === 'update_pkg') {
            $id = (int)($_POST['package_id'] ?? 0);
            ComplianceManualControlEngine::updatePackage($pdo, $id, array(
                'title' => (string)($_POST['title'] ?? ''),
                'manual_code' => (string)($_POST['manual_code'] ?? ''),
                'target_revision' => (string)($_POST['target_revision'] ?? ''),
                'effective_date' => (string)($_POST['effective_date'] ?? ''),
                'drafts_json' => (string)($_POST['drafts_json'] ?? ''),
            ), $uid);
            mr_flash('success', 'Package saved.');
            redirect('/admin/compliance/manual_approved.php?id=' . $id);
        }
        if ($action === 'pkg_status') {
            $id = (int)($_POST['package_id'] ?? 0);
            ComplianceManualControlEngine::setPackageWorkflowStatus(
                $pdo,
                $id,
                (string)($_POST['status'] ?? 'PLANNED'),
                $uid,
                trim((string)($_POST['releaser_name'] ?? (string)($user['name'] ?? '')))
            );
            mr_flash('success', 'Package status updated.');
            redirect('/admin/compliance/manual_approved.php?id=' . $id);
        }
        if ($action === 'add_approval') {
            $id = (int)($_POST['package_id'] ?? 0);
            ComplianceManualControlEngine::addPackageApproval(
                $pdo,
                $id,
                trim((string)($_POST['approver_name'] ?? (string)($user['name'] ?? ''))),
                trim((string)($_POST['approver_role'] ?? '')) ?: null,
                (string)($_POST['decision'] ?? 'APPROVED'),
                trim((string)($_POST['comments'] ?? '')) ?: null,
                $uid
            );
            mr_flash('success', 'Approval recorded.');
            redirect('/admin/compliance/manual_approved.php?id=' . $id);
        }
    } catch (Throwable $e) {
        mr_flash('error', $e->getMessage());
        $id = (int)($_POST['package_id'] ?? 0);
        if ($id > 0) {
            redirect('/admin/compliance/manual_approved.php?id=' . $id);
        }
        redirect('/admin/compliance/manual_approved.php');
    }
}

$flash = mr_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

cw_header('Compliance · Approved Manuals');

if ($flash) {
    $cls = $flash['type'] === 'success' ? 'is-ok' : 'is-danger';
    echo '<div class="queue-status ' . h($cls) . '" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

$pkgStatuses = array('PLANNED', 'DRAFTING', 'REVIEW', 'APPROVED', 'RELEASED', 'SUPERSEDED', 'CANCELLED');
$decisions = array('PENDING', 'APPROVED', 'REJECTED', 'RECUSED');

if ($detailId > 0) {
    $row = ComplianceManualControlEngine::getPackage($pdo, $detailId);
    if ($row === null) {
        echo '<p>Not found.</p>';
    } else {
        $locked = !empty($row['locked_at']);
        $apprs = ComplianceManualControlEngine::listPackageApprovals($pdo, $detailId);
        $dj = $row['drafts_json'] ?? '[]';
        if (!is_string($dj)) {
            $dj = json_encode($dj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';
        }
        ?>
        <p><a href="/admin/compliance/manual_approved.php" style="font-weight:700;color:#1e3c72;">← Packages</a></p>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:16px;max-width:960px;">
          <h1 style="margin:0 0 6px;font-size:22px;"><?= h((string)$row['package_code']) ?></h1>
          <p style="color:#64748b;margin:0;"><?= h((string)$row['status']) ?>
            <?php if ($locked): ?><span class="queue-status is-warn" style="margin-left:8px;">Locked</span><?php endif; ?>
          </p>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:16px;max-width:960px;">
          <h2 style="margin:0 0 12px;">Package</h2>
          <?php if (!$locked): ?>
            <form method="post">
              <input type="hidden" name="action" value="update_pkg">
              <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
              <label style="display:block;margin-bottom:8px;">Title *
                <input name="title" required value="<?= h((string)$row['title']) ?>" style="width:100%;max-width:480px;padding:8px;">
              </label>
              <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <label>manual_code <input name="manual_code" value="<?= h((string)($row['manual_code'] ?? '')) ?>" style="padding:8px;width:120px;"></label>
                <label>target_revision <input name="target_revision" value="<?= h((string)($row['target_revision'] ?? '')) ?>" style="padding:8px;width:120px;"></label>
                <label>effective_date <input type="date" name="effective_date" value="<?= h(substr((string)($row['effective_date'] ?? ''),0,10)) ?>" style="padding:8px;"></label>
              </div>
              <label style="display:block;margin-top:10px;">drafts_json
                <textarea name="drafts_json" rows="6" style="width:100%;font-family:monospace;font-size:12px;padding:8px;"><?= h((string)$dj) ?></textarea>
              </label>
              <button type="submit" style="margin-top:10px;background:#1e3c72;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">Save</button>
            </form>
            <form method="post" style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
              <input type="hidden" name="action" value="pkg_status">
              <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
              <label>Workflow status
                <select name="status">
                  <?php foreach ($pkgStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= ((string)$row['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Releaser name (for RELEASED)
                <input name="releaser_name" value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;">
              </label>
              <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Apply</button>
            </form>
          <?php else: ?>
            <p><strong><?= h((string)$row['title']) ?></strong></p>
            <p style="color:#64748b;font-size:14px;">Released: <?= h((string)($row['released_at'] ?? '—')) ?>
              <?= !empty($row['released_by_name']) ? (' · ' . h((string)$row['released_by_name'])) : '' ?>
            </p>
          <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;max-width:960px;">
          <h2 style="margin:0 0 12px;">Approvals</h2>
          <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr style="text-align:left;background:#f8fafc;"><th style="padding:8px;">Name</th><th style="padding:8px;">Role</th><th style="padding:8px;">Decision</th><th style="padding:8px;">At</th></tr></thead>
            <tbody>
              <?php foreach ($apprs as $a): ?>
                <tr style="border-top:1px solid #e2e8f0;">
                  <td style="padding:8px;"><?= h((string)$a['approver_name']) ?></td>
                  <td style="padding:8px;"><?= h((string)($a['approver_role'] ?? '—')) ?></td>
                  <td style="padding:8px;"><?= h((string)$a['decision']) ?></td>
                  <td style="padding:8px;"><?= h((string)($a['decided_at'] ?? '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (!$locked): ?>
            <form method="post" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
              <input type="hidden" name="action" value="add_approval">
              <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
              <label>Approver *
                <input name="approver_name" required value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;">
              </label>
              <label>Role
                <input name="approver_role" placeholder="COMPLIANCE_OFFICER" style="padding:8px;width:160px;">
              </label>
              <label>Decision
                <select name="decision"><?php foreach ($decisions as $d): ?>
                  <option value="<?= $d ?>"><?= $d ?></option>
                <?php endforeach; ?></select>
              </label>
              <label>Comments
                <input name="comments" style="padding:8px;width:200px;">
              </label>
              <button type="submit" style="background:#334155;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Record</button>
            </form>
          <?php endif; ?>
        </div>
        <?php
    }
} else {
    $rows = ComplianceManualControlEngine::listPackages($pdo);
    ?>
    <h1 style="margin:0 0 8px;">Manual release packages</h1>
    <p style="color:#64748b;margin:0 0 20px;">Phase 4+ — bundle approved drafts for authority sign-off.</p>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:720px;">
      <h2 style="margin:0 0 12px;font-size:18px;">New package</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_pkg">
        <label style="display:block;margin-bottom:8px;">Title *
          <input name="title" required style="width:100%;padding:8px;">
        </label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <label>manual_code <input name="manual_code" placeholder="OM" style="padding:8px;width:100px;"></label>
          <label>target_revision <input name="target_revision" placeholder="7.0" style="padding:8px;width:100px;"></label>
          <label>effective_date <input type="date" name="effective_date" style="padding:8px;"></label>
        </div>
        <label style="display:block;margin-top:10px;">drafts_json
          <textarea name="drafts_json" rows="4" style="width:100%;font-family:monospace;font-size:12px;">[]</textarea>
        </label>
        <button type="submit" style="margin-top:10px;background:#1e3c72;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">Create</button>
      </form>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;max-width:1100px;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#f1f5f9;text-align:left;"><th>Code</th><th>Title</th><th>Status</th><th>Effective</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr style="border-top:1px solid #e2e8f0;">
              <td style="padding:10px;font-family:monospace;font-size:12px;"><?= h((string)$r['package_code']) ?></td>
              <td style="padding:10px;"><?= h((string)$r['title']) ?></td>
              <td style="padding:10px;"><?= h((string)$r['status']) ?></td>
              <td style="padding:10px;"><?= h((string)($r['effective_date'] ?? '—')) ?></td>
              <td style="padding:10px;"><a href="/admin/compliance/manual_approved.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#1e3c72;">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

cw_footer();
