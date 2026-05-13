<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceManualControlEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function mcr_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash'] = array('type' => $type, 'message' => $msg);
}

function mcr_flash_take(): ?array
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
    'rl_edition' => 'Resource library edition',
    'rl_block' => 'Resource library block',
    'easa_node' => 'EASA node',
    'external_doc' => 'External document',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_cr') {
            $id = ComplianceManualControlEngine::createChangeRequest($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'manual_kind' => (string)($_POST['manual_kind'] ?? 'om_canonical'),
                'manual_ref_id' => (string)($_POST['manual_ref_id'] ?? ''),
                'manual_label' => (string)($_POST['manual_label'] ?? ''),
                'proposed_text' => (string)($_POST['proposed_text'] ?? ''),
                'rationale' => (string)($_POST['rationale'] ?? ''),
                'priority' => (string)($_POST['priority'] ?? 'NORMAL'),
                'status' => (string)($_POST['status'] ?? 'DRAFT'),
            ), $uid);
            mcr_flash('success', 'Change request created.');
            redirect('/admin/compliance/change_requests.php?id=' . $id);
        }
        if ($action === 'update_cr') {
            $id = (int)($_POST['request_id'] ?? 0);
            ComplianceManualControlEngine::updateChangeRequest($pdo, $id, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'manual_kind' => (string)($_POST['manual_kind'] ?? ''),
                'manual_ref_id' => (string)($_POST['manual_ref_id'] ?? ''),
                'manual_label' => (string)($_POST['manual_label'] ?? ''),
                'proposed_text' => (string)($_POST['proposed_text'] ?? ''),
                'rationale' => (string)($_POST['rationale'] ?? ''),
                'priority' => (string)($_POST['priority'] ?? ''),
            ), $uid);
            mcr_flash('success', 'Saved.');
            redirect('/admin/compliance/change_requests.php?id=' . $id);
        }
        if ($action === 'set_status') {
            $id = (int)($_POST['request_id'] ?? 0);
            ComplianceManualControlEngine::setChangeRequestStatus(
                $pdo,
                $id,
                (string)($_POST['status'] ?? 'DRAFT'),
                $uid,
                trim((string)($_POST['approver_name'] ?? (string)($user['name'] ?? '')))
            );
            mcr_flash('success', 'Status updated.');
            redirect('/admin/compliance/change_requests.php?id=' . $id);
        }
        if ($action === 'add_link') {
            $id = (int)($_POST['request_id'] ?? 0);
            $eid = (int)($_POST['entity_id'] ?? 0);
            ComplianceManualControlEngine::addCrLink(
                $pdo,
                $id,
                (string)($_POST['entity_type'] ?? 'finding'),
                $eid > 0 ? $eid : null,
                strlen(trim((string)($_POST['external_ref'] ?? ''))) ? trim((string)$_POST['external_ref']) : null,
                strlen(trim((string)($_POST['relation'] ?? ''))) ? trim((string)$_POST['relation']) : null,
                $uid
            );
            mcr_flash('success', 'Link added.');
            redirect('/admin/compliance/change_requests.php?id=' . $id);
        }
    } catch (Throwable $e) {
        mcr_flash('error', $e->getMessage());
        $id = (int)($_POST['request_id'] ?? 0);
        if ($id > 0) {
            redirect('/admin/compliance/change_requests.php?id=' . $id);
        }
        redirect('/admin/compliance/change_requests.php');
    }
}

$flash = mcr_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

cw_header('Compliance · Change Requests');

if ($flash) {
    $cls = $flash['type'] === 'success' ? 'is-ok' : 'is-danger';
    echo '<div class="queue-status ' . h($cls) . '" style="margin:0 0 16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

$statuses = array('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RELEASED', 'CANCELLED');

if ($detailId > 0) {
    $row = ComplianceManualControlEngine::getChangeRequest($pdo, $detailId);
    if ($row === null) {
        echo '<p>Not found.</p><p><a href="/admin/compliance/change_requests.php">← All</a></p>';
    } else {
        $locked = !empty($row['locked_at']);
        $links = ComplianceManualControlEngine::listCrLinks($pdo, $detailId);
        ?>
        <p><a href="/admin/compliance/change_requests.php" style="font-weight:700;color:#1e3c72;">← All change requests</a></p>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:20px;max-width:920px;">
          <h1 style="margin:0 0 8px;font-size:22px;"><?= h((string)$row['request_code']) ?></h1>
          <p style="color:#64748b;margin:0;">Status: <strong><?= h((string)$row['status']) ?></strong>
            · Priority: <?= h((string)$row['priority']) ?>
            <?php if ($locked): ?><span class="queue-status is-warn" style="margin-left:8px;">Locked</span><?php endif; ?>
          </p>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:20px;max-width:920px;">
          <h2 style="margin:0 0 12px;">Details</h2>
          <?php if (!$locked): ?>
            <form method="post">
              <input type="hidden" name="action" value="update_cr">
              <input type="hidden" name="request_id" value="<?= (int)$detailId ?>">
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
                <input name="title" required value="<?= h((string)$row['title']) ?>" style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Description *</span>
                <textarea name="description" required rows="4" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"><?= h((string)$row['description']) ?></textarea>
              </label>
              <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Manual kind</span>
                  <select name="manual_kind" style="padding:8px;border-radius:8px;">
                    <?php foreach ($kinds as $kv => $lab): ?>
                      <option value="<?= h($kv) ?>" <?= ((string)$row['manual_kind'] === $kv) ? 'selected' : '' ?>><?= h($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>
                  <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Priority</span>
                  <select name="priority" style="padding:8px;border-radius:8px;">
                    <?php foreach (array('LOW', 'NORMAL', 'HIGH', 'URGENT') as $p): ?>
                      <option value="<?= $p ?>" <?= ((string)$row['priority'] === $p) ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_ref_id</span>
                <input name="manual_ref_id" value="<?= h((string)($row['manual_ref_id'] ?? '')) ?>" style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_label</span>
                <input name="manual_label" value="<?= h((string)($row['manual_label'] ?? '')) ?>" style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Proposed text</span>
                <textarea name="proposed_text" rows="6" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"><?= h((string)($row['proposed_text'] ?? '')) ?></textarea>
              </label>
              <label style="display:block;margin-bottom:10px;">
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Rationale</span>
                <textarea name="rationale" rows="3" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"><?= h((string)($row['rationale'] ?? '')) ?></textarea>
              </label>
              <button type="submit" style="margin-top:6px;background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">Save</button>
            </form>
          <?php else: ?>
            <p><?= nl2br(h((string)$row['description'])) ?></p>
          <?php endif; ?>
        </div>

        <?php if (!$locked): ?>
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:20px;max-width:920px;">
            <h2 style="margin:0 0 12px;">Workflow</h2>
            <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="request_id" value="<?= (int)$detailId ?>">
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Next status</span>
                <select name="status" style="padding:8px;border-radius:8px;">
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= ((string)$row['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Approver name (if APPROVED)</span>
                <input name="approver_name" value="<?= h((string)($user['name'] ?? '')) ?>" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
              </label>
              <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;">Apply</button>
            </form>
          </div>
        <?php endif; ?>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:20px;max-width:920px;">
          <h2 style="margin:0 0 12px;font-size:18px;">Links</h2>
          <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9;text-align:left;"><th style="padding:8px 10px;">Type</th><th style="padding:8px 10px;">Entity</th><th style="padding:8px 10px;">Relation</th></tr></thead>
            <tbody>
              <?php foreach ($links as $L): ?>
                <tr style="border-top:1px solid #e2e8f0;">
                  <td style="padding:8px 10px;"><?= h((string)$L['entity_type']) ?></td>
                  <td style="padding:8px 10px;"><?= $L['entity_id'] !== null ? (int)$L['entity_id'] : h((string)($L['external_ref'] ?? '—')) ?></td>
                  <td style="padding:8px 10px;"><?= h((string)($L['relation'] ?? '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (!$locked): ?>
            <form method="post" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <input type="hidden" name="action" value="add_link">
              <input type="hidden" name="request_id" value="<?= (int)$detailId ?>">
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">entity_type</span>
                <select name="entity_type" style="padding:8px;border-radius:8px;">
                  <option value="finding">finding</option>
                  <option value="audit">audit</option>
                  <option value="corrective_action">corrective_action</option>
                  <option value="other">other</option>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">entity_id</span>
                <input type="number" name="entity_id" min="1" placeholder="id" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:100px;">
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">external_ref</span>
                <input name="external_ref" placeholder="optional URL/note" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:200px;">
              </label>
              <label>
                <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">relation</span>
                <input name="relation" placeholder="CAUSED_BY" style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;width:140px;">
              </label>
              <button type="submit" style="background:#334155;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Add link</button>
            </form>
          <?php endif; ?>
        </div>

        <p style="margin-top:16px;">
          <a href="/admin/compliance/manual_drafts.php?request_id=<?= (int)$detailId ?>" style="font-weight:700;color:#3730a3;">Open drafts for this request →</a>
        </p>
        <?php
        compliance_render_comms_panel($pdo, 'manual_change_request', (string)$detailId);
    }
} else {
    $rows = ComplianceManualControlEngine::listChangeRequests($pdo);
    ?>
    <h1 style="margin:0 0 8px;">Manual change requests</h1>
    <p style="color:#64748b;margin:0 0 20px;">Phase 4+ — governance metadata targeting canonical manual rows.</p>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:720px;">
      <h2 style="margin:0 0 12px;font-size:18px;">New request</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_cr">
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
          <input name="title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Description *</span>
          <textarea name="description" required rows="3" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
        </label>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
          <label>
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Manual kind</span>
            <select name="manual_kind" style="padding:8px;border-radius:8px;">
              <?php foreach ($kinds as $kv => $lab): ?>
                <option value="<?= h($kv) ?>"><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Priority</span>
            <select name="priority" style="padding:8px;border-radius:8px;">
              <?php foreach (array('LOW', 'NORMAL', 'HIGH', 'URGENT') as $p): ?>
                <option value="<?= $p ?>"><?= $p ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Initial status</span>
            <select name="status" style="padding:8px;border-radius:8px;">
              <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $s === 'DRAFT' ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_ref_id</span>
          <input name="manual_ref_id" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">manual_label</span>
          <input name="manual_label" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Proposed text</span>
          <textarea name="proposed_text" rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
        </label>
        <label style="display:block;margin-bottom:14px;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Rationale</span>
          <textarea name="rationale" rows="2" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
        </label>
        <button type="submit" style="margin-top:4px;background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">Create</button>
      </form>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;max-width:1100px;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#f1f5f9;text-align:left;">
          <th style="padding:12px 14px;">Code</th>
          <th style="padding:12px 14px;">Title</th>
          <th style="padding:12px 14px;">Status</th>
          <th style="padding:12px 14px;">Target</th>
          <th style="padding:12px 14px;">Updated</th>
          <th style="padding:12px 14px;"></th>
        </tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" style="padding:20px;color:#64748b;">No change requests yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr style="border-top:1px solid #e2e8f0;">
              <td style="padding:10px 14px;font-family:ui-monospace,monospace;font-size:12px;"><?= h((string)$r['request_code']) ?></td>
              <td style="padding:10px 14px;"><?= h((string)$r['title']) ?></td>
              <td style="padding:10px 14px;"><?= h((string)$r['status']) ?></td>
              <td style="padding:10px 14px;font-size:12px;color:#64748b;"><?= h((string)($r['manual_label'] ?: $r['manual_ref_id'] ?: '—')) ?></td>
              <td style="padding:10px 14px;font-size:12px;color:#64748b;"><?= h((string)$r['updated_at']) ?></td>
              <td style="padding:10px 14px;"><a href="/admin/compliance/change_requests.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#1e3c72;">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

cw_footer();
