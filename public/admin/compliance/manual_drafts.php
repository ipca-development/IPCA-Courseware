<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceManualControlEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function md_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash'] = array('type' => $type, 'message' => $msg);
}

function md_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash']) || !is_array($_SESSION['_ipca_compliance_flash'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash'];
    unset($_SESSION['_ipca_compliance_flash']);

    return $f;
}

$kinds = array(
    'om_canonical' => 'OM canonical',
    'omm_canonical' => 'OMM canonical',
    'rl_edition' => 'RL edition',
    'rl_block' => 'RL block',
    'easa_node' => 'EASA node',
    'external_doc' => 'External doc',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_draft') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $id = ComplianceManualControlEngine::createDraft($pdo, array(
                'request_id' => $rid > 0 ? $rid : null,
                'manual_kind' => (string)($_POST['manual_kind'] ?? 'om_canonical'),
                'manual_ref_id' => (string)($_POST['manual_ref_id'] ?? ''),
                'manual_label' => (string)($_POST['manual_label'] ?? ''),
                'draft_title' => (string)($_POST['draft_title'] ?? ''),
                'draft_body' => (string)($_POST['draft_body'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'DRAFT'),
            ), $uid);
            md_flash('success', 'Draft created.');
            redirect('/admin/compliance/manual_drafts.php?id=' . $id);
        }
        if ($action === 'update_draft') {
            $id = (int)($_POST['draft_id'] ?? 0);
            ComplianceManualControlEngine::updateDraft($pdo, $id, array(
                'manual_kind' => (string)($_POST['manual_kind'] ?? ''),
                'manual_ref_id' => (string)($_POST['manual_ref_id'] ?? ''),
                'manual_label' => (string)($_POST['manual_label'] ?? ''),
                'draft_title' => (string)($_POST['draft_title'] ?? ''),
                'draft_body' => (string)($_POST['draft_body'] ?? ''),
            ), $uid);
            md_flash('success', 'Saved.');
            redirect('/admin/compliance/manual_drafts.php?id=' . $id);
        }
        if ($action === 'set_draft_status') {
            $id = (int)($_POST['draft_id'] ?? 0);
            ComplianceManualControlEngine::setDraftStatus(
                $pdo,
                $id,
                (string)($_POST['status'] ?? 'DRAFT'),
                $uid,
                trim((string)($_POST['approver_name'] ?? (string)($user['name'] ?? '')))
            );
            md_flash('success', 'Draft status updated.');
            redirect('/admin/compliance/manual_drafts.php?id=' . $id);
        }
    } catch (Throwable $e) {
        md_flash('error', $e->getMessage());
        $id = (int)($_POST['draft_id'] ?? 0);
        if ($id > 0) {
            redirect('/admin/compliance/manual_drafts.php?id=' . $id);
        }
        redirect('/admin/compliance/manual_drafts.php');
    }
}

$flash = md_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterRequestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

cw_header('Compliance · Draft Manuals');

if ($flash) {
    $cls = $flash['type'] === 'success' ? 'is-ok' : 'is-danger';
    echo '<div class="queue-status ' . h($cls) . '" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

$draftStatuses = array('DRAFT', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'PUBLISHED', 'ARCHIVED');

if ($detailId > 0) {
    $row = ComplianceManualControlEngine::getDraft($pdo, $detailId);
    if ($row === null) {
        echo '<p>Not found.</p>';
    } else {
        $locked = !empty($row['locked_at']);
        ?>
        <p><a href="/admin/compliance/manual_drafts.php<?= $filterRequestId > 0 ? ('?request_id=' . (int)$filterRequestId) : '' ?>" style="font-weight:700;color:#1e3c72;">← Drafts</a></p>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:16px;max-width:960px;">
          <h1 style="margin:0 0 6px;font-size:22px;"><?= h((string)$row['draft_code']) ?></h1>
          <p style="color:#64748b;margin:0;"><?= h((string)$row['status']) ?>
            <?php if (!empty($row['request_id'])): ?>
              · Request #<?= (int)$row['request_id'] ?>
              (<a href="/admin/compliance/change_requests.php?id=<?= (int)$row['request_id'] ?>">open</a>)
            <?php endif; ?>
            <?php if ($locked): ?><span class="queue-status is-warn" style="margin-left:8px;">Locked</span><?php endif; ?>
          </p>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;max-width:960px;">
          <?php if (!$locked): ?>
            <form method="post">
              <input type="hidden" name="action" value="update_draft">
              <input type="hidden" name="draft_id" value="<?= (int)$detailId ?>">
              <label style="display:block;margin-bottom:8px;">Title *
                <input name="draft_title" required value="<?= h((string)$row['draft_title']) ?>" style="width:100%;padding:8px;">
              </label>
              <label style="display:block;margin-bottom:8px;">Manual kind
                <select name="manual_kind">
                  <?php foreach ($kinds as $kv => $lab): ?>
                    <option value="<?= h($kv) ?>" <?= ((string)$row['manual_kind'] === $kv) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label style="display:block;margin-bottom:8px;">manual_ref_id
                <input name="manual_ref_id" value="<?= h((string)($row['manual_ref_id'] ?? '')) ?>" style="width:100%;padding:8px;">
              </label>
              <label style="display:block;margin-bottom:8px;">manual_label
                <input name="manual_label" value="<?= h((string)($row['manual_label'] ?? '')) ?>" style="width:100%;padding:8px;">
              </label>
              <label style="display:block;margin-bottom:8px;">Body *
                <textarea name="draft_body" required rows="16" style="width:100%;font-family:ui-monospace,monospace;font-size:13px;padding:10px;"><?= h((string)$row['draft_body']) ?></textarea>
              </label>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">Save</button>
            </form>

            <div style="margin-top:24px;padding-top:20px;border-top:1px dashed #cbd5e1;">
              <h3 style="margin:0 0 12px;">Workflow</h3>
              <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                <input type="hidden" name="action" value="set_draft_status">
                <input type="hidden" name="draft_id" value="<?= (int)$detailId ?>">
                <label>Status
                  <select name="status">
                    <?php foreach ($draftStatuses as $s): ?>
                      <option value="<?= $s ?>" <?= ((string)$row['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Approver name (APPROVED / PUBLISHED)
                  <input name="approver_name" value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;">
                </label>
                <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Apply</button>
              </form>
            </div>
          <?php else: ?>
            <h2 style="margin:0 0 8px;"><?= h((string)$row['draft_title']) ?></h2>
            <pre style="white-space:pre-wrap;font-size:13px;line-height:1.5;background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e2e8f0;"><?= h((string)$row['draft_body']) ?></pre>
          <?php endif; ?>
        </div>
        <?php
    }
} else {
    $all = ComplianceManualControlEngine::listDrafts($pdo);
    $rows = $all;
    if ($filterRequestId > 0) {
        $rows = array_values(array_filter($all, static function (array $r) use ($filterRequestId): bool {
            return (int)($r['request_id'] ?? 0) === $filterRequestId;
        }));
    }
    ?>
    <h1 style="margin:0 0 8px;">Manual drafts</h1>
    <p style="color:#64748b;margin:0 0 20px;">Phase 4+ — proposed manual wording (canonical refs only).</p>
    <?php if ($filterRequestId > 0): ?>
      <p style="margin-bottom:16px;">Filtered to request #<?= (int)$filterRequestId ?> ·
        <a href="/admin/compliance/manual_drafts.php">Show all</a></p>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:720px;">
      <h2 style="margin:0 0 12px;font-size:18px;">New draft</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_draft">
        <?php if ($filterRequestId > 0): ?>
          <input type="hidden" name="request_id" value="<?= (int)$filterRequestId ?>">
        <?php else: ?>
          <label style="display:block;margin-bottom:8px;">request_id (optional)
            <input type="number" name="request_id" min="0" style="padding:8px;width:120px;">
          </label>
        <?php endif; ?>
        <label style="display:block;margin-bottom:8px;">Title *
          <input name="draft_title" required style="width:100%;padding:8px;">
        </label>
        <label style="display:block;margin-bottom:8px;">Manual kind
          <select name="manual_kind"><?php foreach ($kinds as $kv => $lab): ?>
            <option value="<?= h($kv) ?>"><?= h($lab) ?></option>
          <?php endforeach; ?></select>
        </label>
        <label style="display:block;margin-bottom:8px;">manual_ref_id
          <input name="manual_ref_id" style="width:100%;padding:8px;">
        </label>
        <label style="display:block;margin-bottom:8px;">manual_label
          <input name="manual_label" style="width:100%;padding:8px;">
        </label>
        <label style="display:block;margin-bottom:8px;">Body *
          <textarea name="draft_body" required rows="8" style="width:100%;padding:8px;"></textarea>
        </label>
        <button type="submit" style="margin-top:10px;background:#1e3c72;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">Create</button>
      </form>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;max-width:1100px;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#f1f5f9;text-align:left;"><th>Code</th><th>Title</th><th>Status</th><th>Req</th><th>Updated</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr style="border-top:1px solid #e2e8f0;">
              <td style="padding:10px;font-family:monospace;font-size:12px;"><?= h((string)$r['draft_code']) ?></td>
              <td style="padding:10px;"><?= h((string)$r['draft_title']) ?></td>
              <td style="padding:10px;"><?= h((string)$r['status']) ?></td>
              <td style="padding:10px;"><?= $r['request_id'] !== null ? (int)$r['request_id'] : '—' ?></td>
              <td style="padding:10px;font-size:12px;color:#64748b;"><?= h((string)$r['updated_at']) ?></td>
              <td style="padding:10px;"><a href="/admin/compliance/manual_drafts.php?id=<?= (int)$r['id'] ?><?= $filterRequestId > 0 ? ('&request_id=' . (int)$filterRequestId) : '' ?>" style="font-weight:700;color:#1e3c72;">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

cw_footer();
