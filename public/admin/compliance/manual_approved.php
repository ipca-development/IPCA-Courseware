<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceManualControlEngine.php';
require_once __DIR__ . '/../../../src/compliance/CompliancePackagePdfService.php';

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

function mr_post_draft_ids(): array
{
    $raw = $_POST['draft_ids'] ?? array();
    if (!is_array($raw)) {
        return array();
    }
    $out = array();
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) {
            $out[$n] = $n;
        }
    }

    return array_values($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_pkg') {
            $draftsJson = ComplianceManualControlEngine::buildDraftsJsonFromIds($pdo, mr_post_draft_ids());
            $id = ComplianceManualControlEngine::createPackage($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'manual_code' => (string)($_POST['manual_code'] ?? ''),
                'target_revision' => (string)($_POST['target_revision'] ?? ''),
                'effective_date' => (string)($_POST['effective_date'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'PLANNED'),
                'drafts_json' => $draftsJson,
            ), $uid);
            mr_flash('success', 'Package created.');
            redirect('/admin/compliance/manual_approved.php?id=' . $id);
        }
        if ($action === 'update_pkg') {
            $id = (int)($_POST['package_id'] ?? 0);
            $draftsJson = ComplianceManualControlEngine::buildDraftsJsonFromIds($pdo, mr_post_draft_ids());
            ComplianceManualControlEngine::updatePackage($pdo, $id, array(
                'title' => (string)($_POST['title'] ?? ''),
                'manual_code' => (string)($_POST['manual_code'] ?? ''),
                'target_revision' => (string)($_POST['target_revision'] ?? ''),
                'effective_date' => (string)($_POST['effective_date'] ?? ''),
                'drafts_json' => $draftsJson,
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
        if ($action === 'generate_pdf') {
            $id = (int)($_POST['package_id'] ?? 0);
            $r = CompliancePackagePdfService::generateAndStore($pdo, $id);
            mr_flash('success', 'PDF generated (' . number_format($r['bytes']) . ' bytes, sha256 ' . substr($r['sha256'], 0, 12) . '…).');
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
        $selectedDraftIds = ComplianceManualControlEngine::extractDraftIds($row['drafts_json'] ?? null);
        $availableDrafts = ComplianceManualControlEngine::listReleasableDrafts($pdo);
        $availableDraftIds = array();
        foreach ($availableDrafts as $d) {
            $availableDraftIds[(int)$d['id']] = true;
        }
        $orphanIds = array();
        foreach ($selectedDraftIds as $sid) {
            if (!isset($availableDraftIds[$sid])) {
                $orphanIds[] = $sid;
            }
        }
        $orphanRows = array();
        if ($orphanIds !== array()) {
            $ph = implode(',', array_fill(0, count($orphanIds), '?'));
            $st = $pdo->prepare(
                'SELECT id, draft_code, draft_title, status FROM ipca_compliance_manual_drafts WHERE id IN (' . $ph . ')'
            );
            $st->execute($orphanIds);
            $orphanRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
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
          <h2 style="margin:0 0 12px;font-size:18px;">Package</h2>
          <?php if (!$locked): ?>
            <form method="post">
              <input type="hidden" name="action" value="update_pkg">
              <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
                <input name="title" required value="<?= h((string)$row['title']) ?>" style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_code</span>
                  <input name="manual_code" value="<?= h((string)($row['manual_code'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:140px;">
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">target_revision</span>
                  <input name="target_revision" value="<?= h((string)($row['target_revision'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:140px;">
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">effective_date</span>
                  <input type="date" name="effective_date" value="<?= h(substr((string)($row['effective_date'] ?? ''), 0, 10)) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
              </div>
              <div style="margin-bottom:14px;">
                <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;">Drafts included in this release</div>
                <?php if ($availableDrafts === array() && $selectedDraftIds === array()): ?>
                  <p style="margin:0;color:#64748b;font-size:13px;">No APPROVED / PUBLISHED drafts available.
                    <a href="/admin/compliance/manual_drafts.php" style="color:#1e3c72;font-weight:700;">Author one →</a>
                  </p>
                <?php else: ?>
                  <div style="border:1px solid #e2e8f0;border-radius:10px;max-height:260px;overflow-y:auto;background:#f8fafc;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                      <thead><tr style="background:#f1f5f9;text-align:left;position:sticky;top:0;">
                        <th style="padding:8px 10px;width:32px;"></th>
                        <th style="padding:8px 10px;">Code</th>
                        <th style="padding:8px 10px;">Title</th>
                        <th style="padding:8px 10px;">Manual</th>
                        <th style="padding:8px 10px;">Status</th>
                      </tr></thead>
                      <tbody>
                        <?php foreach ($availableDrafts as $d):
                            $did = (int)$d['id'];
                            $checked = in_array($did, $selectedDraftIds, true) ? 'checked' : '';
                            ?>
                          <tr style="border-top:1px solid #e2e8f0;background:#fff;">
                            <td style="padding:8px 10px;text-align:center;">
                              <input type="checkbox" name="draft_ids[]" value="<?= $did ?>" <?= $checked ?>>
                            </td>
                            <td style="padding:8px 10px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$d['draft_code']) ?></td>
                            <td style="padding:8px 10px;"><?= h((string)$d['draft_title']) ?></td>
                            <td style="padding:8px 10px;color:#64748b;font-size:12px;">
                              <?= h((string)($d['manual_label'] ?: ($d['manual_kind'] ?? '—'))) ?>
                            </td>
                            <td style="padding:8px 10px;"><?= h((string)$d['status']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php foreach ($orphanRows as $d):
                            $did = (int)$d['id'];
                            ?>
                          <tr style="border-top:1px solid #e2e8f0;background:#fff;color:#7c2d12;">
                            <td style="padding:8px 10px;text-align:center;">
                              <input type="checkbox" name="draft_ids[]" value="<?= $did ?>" checked>
                            </td>
                            <td style="padding:8px 10px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$d['draft_code']) ?></td>
                            <td style="padding:8px 10px;"><?= h((string)$d['draft_title']) ?></td>
                            <td style="padding:8px 10px;font-size:12px;">(no longer releasable)</td>
                            <td style="padding:8px 10px;"><?= h((string)$d['status']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <p style="margin:6px 0 0;color:#64748b;font-size:12px;">Only drafts in status APPROVED or PUBLISHED can be bundled.</p>
                <?php endif; ?>
              </div>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">Save</button>
            </form>
            <form method="post" style="margin-top:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <input type="hidden" name="action" value="pkg_status">
              <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Workflow status</span>
                <select name="status" style="padding:8px;border-radius:8px;">
                  <?php foreach ($pkgStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= ((string)$row['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Releaser name (for RELEASED)</span>
                <input name="releaser_name" value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Apply</button>
            </form>
          <?php else: ?>
            <p><strong><?= h((string)$row['title']) ?></strong></p>
            <p style="color:#64748b;font-size:14px;">Released: <?= h((string)($row['released_at'] ?? '—')) ?>
              <?= !empty($row['released_by_name']) ? (' · ' . h((string)$row['released_by_name'])) : '' ?>
            </p>
            <?php if ($selectedDraftIds !== array()):
                $ph = implode(',', array_fill(0, count($selectedDraftIds), '?'));
                $st = $pdo->prepare(
                    'SELECT id, draft_code, draft_title, status FROM ipca_compliance_manual_drafts WHERE id IN (' . $ph . ') ORDER BY draft_code ASC'
                );
                $st->execute($selectedDraftIds);
                $lockedDrafts = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
                ?>
              <div style="margin-top:14px;">
                <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;">Included drafts</div>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                  <thead><tr style="background:#f1f5f9;text-align:left;">
                    <th style="padding:8px 10px;">Code</th>
                    <th style="padding:8px 10px;">Title</th>
                    <th style="padding:8px 10px;">Status</th>
                  </tr></thead>
                  <tbody>
                    <?php foreach ($lockedDrafts as $d): ?>
                      <tr style="border-top:1px solid #e2e8f0;">
                        <td style="padding:8px 10px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$d['draft_code']) ?></td>
                        <td style="padding:8px 10px;"><?= h((string)$d['draft_title']) ?></td>
                        <td style="padding:8px 10px;"><?= h((string)$d['status']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:16px;max-width:960px;">
          <h2 style="margin:0 0 12px;font-size:18px;">PDF release file</h2>
          <?php
            $pdfRel = (string)($row['pdf_storage_relpath'] ?? '');
            $pdfSha = (string)($row['pdf_sha256'] ?? '');
          ?>
          <?php if ($pdfRel !== ''): ?>
            <p style="margin:0 0 8px;color:#334155;font-size:14px;">
              Stored at <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:6px;"><?= h($pdfRel) ?></code>
            </p>
            <p class="small" style="margin:0 0 12px;color:#64748b;font-size:12px;">
              sha256 <code style="font-family:ui-monospace,monospace;font-size:11px;"><?= h($pdfSha) ?></code>
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
              <a href="/admin/compliance/manual_release_pdf.php?package_id=<?= (int)$detailId ?>" target="_blank"
                style="background:#1e3c72;color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;font-weight:700;">View PDF</a>
              <a href="/admin/compliance/manual_release_pdf.php?package_id=<?= (int)$detailId ?>&dl=1"
                style="background:#0f766e;color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;font-weight:700;">Download PDF</a>
              <?php if (!$locked): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="generate_pdf">
                  <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
                  <button type="submit" style="background:#334155;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Regenerate</button>
                </form>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p style="margin:0 0 10px;color:#64748b;font-size:13px;">No PDF generated yet for this package.</p>
            <?php if (!$locked): ?>
              <form method="post">
                <input type="hidden" name="action" value="generate_pdf">
                <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
                <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Generate PDF</button>
              </form>
            <?php else: ?>
              <form method="post">
                <input type="hidden" name="action" value="generate_pdf">
                <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
                <button type="submit" style="background:#334155;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Generate PDF</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;max-width:960px;">
          <h2 style="margin:0 0 12px;font-size:18px;">Approvals</h2>
          <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9;text-align:left;">
              <th style="padding:8px 10px;">Name</th>
              <th style="padding:8px 10px;">Role</th>
              <th style="padding:8px 10px;">Decision</th>
              <th style="padding:8px 10px;">At</th>
              <th style="padding:8px 10px;">Comments</th>
            </tr></thead>
            <tbody>
              <?php if (!$apprs): ?>
                <tr><td colspan="5" style="padding:14px;color:#64748b;">No approvals recorded.</td></tr>
              <?php endif; ?>
              <?php foreach ($apprs as $a): ?>
                <tr style="border-top:1px solid #e2e8f0;">
                  <td style="padding:8px 10px;"><?= h((string)$a['approver_name']) ?></td>
                  <td style="padding:8px 10px;"><?= h((string)($a['approver_role'] ?? '—')) ?></td>
                  <td style="padding:8px 10px;"><?= h((string)$a['decision']) ?></td>
                  <td style="padding:8px 10px;color:#64748b;font-size:12px;"><?= h((string)($a['decided_at'] ?? '—')) ?></td>
                  <td style="padding:8px 10px;color:#475569;font-size:12px;"><?= h((string)($a['comments'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (!$locked): ?>
            <form method="post" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <input type="hidden" name="action" value="add_approval">
              <input type="hidden" name="package_id" value="<?= (int)$detailId ?>">
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Approver *</span>
                <input name="approver_name" required value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Role</span>
                <input name="approver_role" placeholder="COMPLIANCE_OFFICER" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:200px;">
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Decision</span>
                <select name="decision" style="padding:8px;border-radius:8px;">
                  <?php foreach ($decisions as $d): ?>
                    <option value="<?= $d ?>"><?= $d ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Comments</span>
                <input name="comments" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:240px;">
              </label>
              <button type="submit" style="background:#334155;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Record</button>
            </form>
          <?php endif; ?>
        </div>
        <?php
    }
} else {
    $rows = ComplianceManualControlEngine::listPackages($pdo);
    $availableDrafts = ComplianceManualControlEngine::listReleasableDrafts($pdo);
    ?>
    <h1 style="margin:0 0 8px;">Manual release packages</h1>
    <p style="color:#64748b;margin:0 0 20px;">Phase 4+ — bundle approved drafts for authority sign-off.</p>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:820px;">
      <h2 style="margin:0 0 12px;font-size:18px;">New package</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_pkg">
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
          <input name="title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
          <label>
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_code</span>
            <input name="manual_code" placeholder="OM" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:120px;">
          </label>
          <label>
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">target_revision</span>
            <input name="target_revision" placeholder="7.0" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:120px;">
          </label>
          <label>
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">effective_date</span>
            <input type="date" name="effective_date" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
          </label>
        </div>
        <div style="margin-bottom:14px;">
          <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;">Drafts to include (APPROVED / PUBLISHED only)</div>
          <?php if ($availableDrafts === array()): ?>
            <p style="margin:0;color:#64748b;font-size:13px;">No releasable drafts yet.
              <a href="/admin/compliance/manual_drafts.php" style="color:#1e3c72;font-weight:700;">Author one →</a>
            </p>
          <?php else: ?>
            <div style="border:1px solid #e2e8f0;border-radius:10px;max-height:220px;overflow-y:auto;background:#f8fafc;">
              <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f1f5f9;text-align:left;position:sticky;top:0;">
                  <th style="padding:8px 10px;width:32px;"></th>
                  <th style="padding:8px 10px;">Code</th>
                  <th style="padding:8px 10px;">Title</th>
                  <th style="padding:8px 10px;">Status</th>
                </tr></thead>
                <tbody>
                  <?php foreach ($availableDrafts as $d): ?>
                    <tr style="border-top:1px solid #e2e8f0;background:#fff;">
                      <td style="padding:8px 10px;text-align:center;">
                        <input type="checkbox" name="draft_ids[]" value="<?= (int)$d['id'] ?>">
                      </td>
                      <td style="padding:8px 10px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$d['draft_code']) ?></td>
                      <td style="padding:8px 10px;"><?= h((string)$d['draft_title']) ?></td>
                      <td style="padding:8px 10px;"><?= h((string)$d['status']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">Create</button>
      </form>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;max-width:1100px;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#f1f5f9;text-align:left;">
          <th style="padding:12px 14px;">Code</th>
          <th style="padding:12px 14px;">Title</th>
          <th style="padding:12px 14px;">Status</th>
          <th style="padding:12px 14px;">Effective</th>
          <th style="padding:12px 14px;">PDF</th>
          <th style="padding:12px 14px;"></th>
        </tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" style="padding:20px;color:#64748b;">No packages yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr style="border-top:1px solid #e2e8f0;">
              <td style="padding:10px 14px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$r['package_code']) ?></td>
              <td style="padding:10px 14px;"><?= h((string)$r['title']) ?></td>
              <td style="padding:10px 14px;"><?= h((string)$r['status']) ?></td>
              <td style="padding:10px 14px;color:#64748b;font-size:12px;"><?= h((string)($r['effective_date'] ?? '—')) ?></td>
              <td style="padding:10px 14px;font-size:12px;">
                <?php if (!empty($r['pdf_storage_relpath'])): ?>
                  <a href="/admin/compliance/manual_release_pdf.php?package_id=<?= (int)$r['id'] ?>" target="_blank" style="color:#0f766e;font-weight:700;">PDF</a>
                <?php else: ?>
                  <span style="color:#94a3b8;">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:10px 14px;"><a href="/admin/compliance/manual_approved.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#1e3c72;">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

cw_footer();
