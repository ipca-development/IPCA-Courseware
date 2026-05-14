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

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$draftStatuses = array('DRAFT', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'PUBLISHED', 'ARCHIVED');

if ($detailId > 0) {
    $row = ComplianceManualControlEngine::getDraft($pdo, $detailId);
    if ($row === null) {
        compliance_page_open(array(
            'overline' => 'Compliance · Manual control',
            'title' => 'Draft not found',
            'back' => array('href' => '/admin/compliance/manual_drafts.php', 'label' => 'All drafts'),
        ));
        echo '<section class="cmp-card"><p style="margin:0;">No row for that id.</p></section>';
        compliance_page_close();
        cw_footer();
        return;
    }
    $locked = !empty($row['locked_at']);
    compliance_page_open(array(
        'overline' => 'Compliance · Manual draft',
        'title' => (string)$row['draft_code'],
        'description' => 'Status: ' . (string)$row['status'] . ($locked ? ' · LOCKED' : '') . (!empty($row['request_id']) ? ' · Request #' . (int)$row['request_id'] : ''),
        'back' => array('href' => '/admin/compliance/manual_drafts.php' . ($filterRequestId > 0 ? ('?request_id=' . (int)$filterRequestId) : ''), 'label' => 'All drafts'),
        'flash' => $flash,
    ));
    ?>
        <section class="cmp-card">
          <?php if (!$locked): ?>
            <form method="post">
              <input type="hidden" name="action" value="update_draft">
              <input type="hidden" name="draft_id" value="<?= (int)$detailId ?>">
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
                <input name="draft_title" required value="<?= h((string)$row['draft_title']) ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px;">
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Manual kind</span>
                  <select name="manual_kind" style="padding:8px;border-radius:8px;">
                    <?php foreach ($kinds as $kv => $lab): ?>
                      <option value="<?= h($kv) ?>" <?= ((string)$row['manual_kind'] === $kv) ? 'selected' : '' ?>><?= h($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label style="flex:1 1 220px;">
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_ref_id</span>
                  <input name="manual_ref_id" value="<?= h((string)($row['manual_ref_id'] ?? '')) ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
                <label style="flex:1 1 220px;">
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_label</span>
                  <input name="manual_label" value="<?= h((string)($row['manual_label'] ?? '')) ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
              </div>
              <label style="display:block;margin-bottom:14px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Body *</span>
                <textarea name="draft_body" required rows="16" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;padding:10px;border-radius:8px;border:1px solid #cbd5e1;"><?= h((string)$row['draft_body']) ?></textarea>
              </label>
              <button type="submit">Save</button>
            </form>

            <div style="margin-top:24px;padding-top:20px;border-top:1px dashed rgba(15,23,42,0.10);">
              <h3 style="margin:0 0 12px;">Workflow</h3>
              <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <input type="hidden" name="action" value="set_draft_status">
                <input type="hidden" name="draft_id" value="<?= (int)$detailId ?>">
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Status</span>
                  <select name="status" style="padding:8px;border-radius:8px;">
                    <?php foreach ($draftStatuses as $s): ?>
                      <option value="<?= $s ?>" <?= ((string)$row['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Approver name (APPROVED / PUBLISHED)</span>
                  <input name="approver_name" value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
                <button type="submit" class="cmp-btn-success">Apply</button>
              </form>
            </div>
          <?php else: ?>
            <h2 style="margin:0 0 8px;"><?= h((string)$row['draft_title']) ?></h2>
            <pre style="white-space:pre-wrap;font-size:13px;line-height:1.5;background:#f6f9fd;padding:16px;border-radius:14px;border:1px solid var(--border-soft);"><?= h((string)$row['draft_body']) ?></pre>
          <?php endif; ?>
        </section>
        <?php
} else {
    $all = ComplianceManualControlEngine::listDrafts($pdo);
    $rows = $all;
    if ($filterRequestId > 0) {
        $rows = array_values(array_filter($all, static function (array $r) use ($filterRequestId): bool {
            return (int)($r['request_id'] ?? 0) === $filterRequestId;
        }));
    }
    $mdCounts = array('total' => count($rows), 'draft' => 0, 'approved' => 0, 'published' => 0);
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        if ($st === 'DRAFT' || $st === 'UNDER_REVIEW') {
            $mdCounts['draft']++;
        }
        if ($st === 'APPROVED') {
            $mdCounts['approved']++;
        }
        if ($st === 'PUBLISHED') {
            $mdCounts['published']++;
        }
    }
    compliance_page_open(array(
        'overline' => 'Compliance · Manual control',
        'title' => 'Manual drafts',
        'description' => $filterRequestId > 0
            ? 'Drafts filtered to change request #' . (int)$filterRequestId . '.'
            : 'Proposed manual wording (canonical references only). Author a draft, route it through review and publish to feed a release package.',
        'actions' => $filterRequestId > 0 ? array(
            array('label' => 'Show all drafts', 'href' => '/admin/compliance/manual_drafts.php', 'icon' => 'list'),
        ) : array(),
        'stats' => array(
            array('label' => 'Drafts / review', 'value' => (int)$mdCounts['draft']),
            array('label' => 'Approved',         'value' => (int)$mdCounts['approved'], 'tone' => 'ok'),
            array('label' => 'Published',        'value' => (int)$mdCounts['published'], 'tone' => 'ok'),
            array('label' => 'Total',            'value' => (int)$mdCounts['total']),
        ),
        'flash' => $flash,
    ));
    ?>
    <section class="cmp-card">
      <h2 style="margin:0 0 12px;">New draft</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_draft">
        <?php if ($filterRequestId > 0): ?>
          <input type="hidden" name="request_id" value="<?= (int)$filterRequestId ?>">
        <?php else: ?>
          <label style="display:block;margin-bottom:10px;">
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">request_id (optional)</span>
            <input type="number" name="request_id" min="0" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:160px;">
          </label>
        <?php endif; ?>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
          <input name="draft_title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Manual kind</span>
          <select name="manual_kind" style="padding:8px;border-radius:8px;">
            <?php foreach ($kinds as $kv => $lab): ?>
              <option value="<?= h($kv) ?>"><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_ref_id</span>
          <input name="manual_ref_id" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_label</span>
          <input name="manual_label" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:14px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Body *</span>
          <textarea name="draft_body" required rows="8" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;"></textarea>
        </label>
        <button type="submit">Create</button>
      </form>
    </section>

    <section class="cmp-card" style="overflow:hidden;">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('document') ?>
          <span>Drafts</span>
        </div>
        <div class="cmp-count-pill"><?= count($rows) ?> draft<?= count($rows) === 1 ? '' : 's' ?></div>
      </div>
      <div class="compliance-table-wrap">
      <table class="compliance-table">
        <thead><tr>
          <th>Code</th>
          <th>Title</th>
          <th>Status</th>
          <th>Req</th>
          <th>Updated</th>
          <th></th>
        </tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" style="padding:20px;color:var(--text-muted);">No drafts yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="cmp-mono"><?= h((string)$r['draft_code']) ?></td>
              <td><?= h((string)$r['draft_title']) ?></td>
              <td><span class="cmp-pill"><?= h((string)$r['status']) ?></span></td>
              <td><?= $r['request_id'] !== null ? (int)$r['request_id'] : '—' ?></td>
              <td class="cmp-mono"><?= h((string)$r['updated_at']) ?></td>
              <td><a href="/admin/compliance/manual_drafts.php?id=<?= (int)$r['id'] ?><?= $filterRequestId > 0 ? ('&request_id=' . (int)$filterRequestId) : '' ?>" style="font-weight:700;color:#1f4079;">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>
    <?php
}

compliance_page_close();
cw_footer();
