<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceChecklistEngine.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function m4_flash(string $type, string $message): void
{
    $_SESSION['_ipca_compliance_flash'] = array('type' => $type, 'message' => $message);
}

/** @return array{type:string,message:string}|null */
function m4_flash_take(): ?array
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
        if ($action === 'create_template') {
            $id = ComplianceChecklistEngine::createTemplate($pdo, array(
                'template_code' => (string)($_POST['template_code'] ?? ''),
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'authority' => (string)($_POST['authority'] ?? ''),
                'scope_tags' => (string)($_POST['scope_tags'] ?? ''),
                'is_active' => (int)($_POST['is_active'] ?? 1),
            ), $uid);
            m4_flash('success', 'Template created.');
            redirect('/admin/compliance/procedures.php?template_id=' . $id);
        }
        if ($action === 'update_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            ComplianceChecklistEngine::updateTemplate($pdo, $tid, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'authority' => (string)($_POST['authority'] ?? ''),
                'scope_tags' => (string)($_POST['scope_tags'] ?? ''),
                'is_active' => (int)($_POST['is_active'] ?? 1),
            ), $uid);
            m4_flash('success', 'Template saved.');
            redirect('/admin/compliance/procedures.php?template_id=' . $tid);
        }
        if ($action === 'create_version') {
            $tid = (int)($_POST['template_id'] ?? 0);
            $vid = ComplianceChecklistEngine::createVersion($pdo, $tid, (string)($_POST['version_description'] ?? ''), $uid);
            m4_flash('success', 'New draft version created.');
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
        if ($action === 'set_version_status') {
            $vid = (int)($_POST['version_id'] ?? 0);
            ComplianceChecklistEngine::setVersionStatus($pdo, $vid, (string)($_POST['status'] ?? 'DRAFT'), $uid);
            m4_flash('success', 'Version status updated.');
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
        if ($action === 'approve_version') {
            $vid = (int)($_POST['version_id'] ?? 0);
            ComplianceChecklistEngine::approveVersion(
                $pdo,
                $vid,
                $uid,
                trim((string)($_POST['approver_name'] ?? (string)($user['name'] ?? '')))
            );
            m4_flash('success', 'Version approved and locked.');
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
        if ($action === 'add_item') {
            $vid = (int)($_POST['version_id'] ?? 0);
            ComplianceChecklistEngine::addItem($pdo, $vid, array(
                'item_code' => (string)($_POST['item_code'] ?? ''),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'item_type' => (string)($_POST['item_type'] ?? 'QUESTION'),
                'prompt' => (string)($_POST['prompt'] ?? ''),
                'guidance' => (string)($_POST['guidance'] ?? ''),
                'is_required' => (int)($_POST['is_required'] ?? 1),
                'options_json' => (string)($_POST['options_json'] ?? '{}'),
                'reg_refs_json' => (string)($_POST['reg_refs_json'] ?? '{}'),
                'manual_refs_json' => (string)($_POST['manual_refs_json'] ?? '{}'),
            ), $uid);
            m4_flash('success', 'Item added.');
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
        if ($action === 'update_item') {
            $iid = (int)($_POST['item_id'] ?? 0);
            $vid = (int)($_POST['version_id'] ?? 0);
            ComplianceChecklistEngine::updateItem($pdo, $iid, array(
                'item_code' => (string)($_POST['item_code'] ?? ''),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'item_type' => (string)($_POST['item_type'] ?? ''),
                'prompt' => (string)($_POST['prompt'] ?? ''),
                'guidance' => (string)($_POST['guidance'] ?? ''),
                'is_required' => (int)($_POST['is_required'] ?? 1),
                'options_json' => (string)($_POST['options_json'] ?? ''),
                'reg_refs_json' => (string)($_POST['reg_refs_json'] ?? ''),
                'manual_refs_json' => (string)($_POST['manual_refs_json'] ?? ''),
            ), $uid);
            m4_flash('success', 'Item saved.');
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
        if ($action === 'delete_item') {
            $iid = (int)($_POST['item_id'] ?? 0);
            $vid = (int)($_POST['version_id'] ?? 0);
            ComplianceChecklistEngine::deleteItem($pdo, $iid, $uid);
            m4_flash('success', 'Item removed.');
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
    } catch (Throwable $e) {
        m4_flash('error', $e->getMessage());
        $tid = (int)($_POST['template_id'] ?? 0);
        $vid = (int)($_POST['version_id'] ?? 0);
        if ($vid > 0) {
            redirect('/admin/compliance/procedures.php?version_id=' . $vid);
        }
        if ($tid > 0) {
            redirect('/admin/compliance/procedures.php?template_id=' . $tid);
        }
        redirect('/admin/compliance/procedures.php');
    }
}

$flash = m4_flash_take();
$templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;

cw_header('Compliance · Procedures');

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$itemTypes = array(
    'SECTION' => 'Section',
    'QUESTION' => 'Question',
    'MULTI_CHOICE' => 'Multi choice',
    'YES_NO' => 'Yes / No',
    'NUMERIC' => 'Numeric',
    'TEXT' => 'Text',
    'EVIDENCE_UPLOAD' => 'Evidence upload',
);

if ($versionId > 0) {
    $ver = ComplianceChecklistEngine::getVersion($pdo, $versionId);
    if ($ver === null) {
        compliance_page_open(array(
            'overline' => 'Compliance · Procedures',
            'title' => 'Version not found',
            'back' => array('href' => '/admin/compliance/procedures.php', 'label' => 'Templates'),
        ));
        echo '<section class="cmp-card"><p style="margin:0;">No row for that version id.</p></section>';
        compliance_page_close();
        cw_footer();
        return;
    }
    $tpl = ComplianceChecklistEngine::getTemplate($pdo, (int)$ver['template_id']);
    $items = ComplianceChecklistEngine::listItems($pdo, $versionId);
    $locked = !empty($ver['locked_at']);
    $st = strtoupper((string)($ver['status'] ?? ''));
    compliance_page_open(array(
        'overline' => 'Compliance · Checklist version',
        'title' => 'Version ' . (int)$ver['version_no'] . ($tpl ? ' · ' . (string)$tpl['template_code'] : ''),
        'description' => 'Status: ' . $st . ' · Items: ' . (int)$ver['items_count'] . ($locked ? ' · Locked' : ''),
        'back' => array(
            'href' => $tpl ? '/admin/compliance/procedures.php?template_id=' . (int)$tpl['id'] : '/admin/compliance/procedures.php',
            'label' => $tpl ? 'Template ' . (string)$tpl['template_code'] : 'Templates',
        ),
        'flash' => $flash,
    ));
    ?>
        <section class="cmp-card">
          <h2 style="margin:0 0 8px;">Version <?= (int)$ver['version_no'] ?></h2>
          <p style="color:var(--text-muted);margin:0 0 8px;">
            Status: <strong><?= h($st) ?></strong>
            <?php if ($locked): ?>
              <span class="cmp-pill cmp-pill-warn" style="margin-left:8px;">Locked</span>
            <?php endif; ?>
            · Items: <?= (int)$ver['items_count'] ?>
          </p>
          <?php if (trim((string)($ver['description'] ?? '')) !== ''): ?>
            <p style="margin:0 0 12px;"><?= nl2br(h((string)$ver['description'])) ?></p>
          <?php endif; ?>

          <?php if (!$locked && in_array($st, array('DRAFT', 'PENDING_APPROVAL'), true)): ?>
            <div class="cmp-toolbar" style="margin-bottom:16px;">
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="set_version_status">
                <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                <input type="hidden" name="status" value="<?= $st === 'DRAFT' ? 'PENDING_APPROVAL' : 'DRAFT' ?>">
                <button type="submit" class="cmp-btn-secondary">
                  <?= $st === 'DRAFT' ? 'Submit for approval' : 'Revert to draft' ?>
                </button>
              </form>
              <form method="post" class="cmp-toolbar" style="margin:0;" onsubmit="return confirm('Approve and lock this version?');">
                <input type="hidden" name="action" value="approve_version">
                <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                <label class="cmp-field" style="margin:0;min-width:220px;">
                  <span class="cmp-field-label">Approver</span>
                  <input name="approver_name" value="<?= h((string)($user['name'] ?? '')) ?>" required>
                </label>
                <button type="submit">Approve &amp; lock</button>
              </form>
            </div>
          <?php endif; ?>
        </section>

        <section class="cmp-card">
          <h2 style="margin:0 0 16px;">Items</h2>
          <?php if (!$locked): ?>
            <form method="post" style="margin-bottom:24px;padding:16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
              <input type="hidden" name="action" value="add_item">
              <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:800px;">
                <label><span style="font-size:11px;font-weight:700;color:#64748b;">Code</span>
                  <input name="item_code" placeholder="auto if empty" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
                <label><span style="font-size:11px;font-weight:700;color:#64748b;">Sort</span>
                  <input type="number" name="sort_order" value="0" min="0" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                </label>
                <label><span style="font-size:11px;font-weight:700;color:#64748b;">Type</span>
                  <select name="item_type" style="width:100%;padding:8px;">
                    <?php foreach ($itemTypes as $k => $lab): ?>
                      <option value="<?= h($k) ?>"><?= h($lab) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label><span style="font-size:11px;font-weight:700;color:#64748b;">Required</span>
                  <select name="is_required" style="width:100%;padding:8px;">
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                  </select>
                </label>
              </div>
              <label style="display:block;margin-top:12px;">
                <span style="font-size:11px;font-weight:700;color:#64748b;">Prompt *</span>
                <textarea name="prompt" required rows="2" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
              </label>
              <label style="display:block;margin-top:8px;">
                <span style="font-size:11px;font-weight:700;color:#64748b;">Guidance</span>
                <textarea name="guidance" rows="2" style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
              </label>
              <details style="margin-top:10px;font-size:13px;">
                <summary>JSON fields (advanced)</summary>
                <label style="display:block;margin-top:8px;">options_json
                  <textarea name="options_json" rows="2" style="width:100%;font-family:monospace;font-size:12px;">{}</textarea>
                </label>
                <label>reg_refs_json
                  <textarea name="reg_refs_json" rows="2" style="width:100%;font-family:monospace;font-size:12px;">{}</textarea>
                </label>
                <label>manual_refs_json
                  <textarea name="manual_refs_json" rows="2" style="width:100%;font-family:monospace;font-size:12px;">{}</textarea>
                </label>
              </details>
              <button type="submit" style="margin-top:12px;background:#1e3c72;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;">Add item</button>
            </form>
          <?php endif; ?>

          <?php foreach ($items as $it):
              $iid = (int)$it['id'];
              $opts = is_string($it['options_json'] ?? null) ? (string)$it['options_json'] : json_encode($it['options_json'] ?? array());
              $regs = is_string($it['reg_refs_json'] ?? null) ? (string)$it['reg_refs_json'] : json_encode($it['reg_refs_json'] ?? array());
              $mans = is_string($it['manual_refs_json'] ?? null) ? (string)$it['manual_refs_json'] : json_encode($it['manual_refs_json'] ?? array());
              ?>
            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:14px;background:#fff;">
              <?php if (!$locked): ?>
                <form method="post">
                  <input type="hidden" name="action" value="update_item">
                  <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                  <input type="hidden" name="item_id" value="<?= $iid ?>">
                  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                    <label><span style="font-size:11px;color:#64748b;">Code</span><br>
                      <input name="item_code" value="<?= h((string)($it['item_code'] ?? '')) ?>" style="padding:6px;width:120px;">
                    </label>
                    <label><span style="font-size:11px;color:#64748b;">Sort</span><br>
                      <input type="number" name="sort_order" value="<?= (int)($it['sort_order'] ?? 0) ?>" style="padding:6px;width:72px;">
                    </label>
                    <label><span style="font-size:11px;color:#64748b;">Type</span><br>
                      <select name="item_type">
                        <?php foreach ($itemTypes as $k => $lab): ?>
                          <option value="<?= h($k) ?>" <?= ((string)$it['item_type'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label><span style="font-size:11px;color:#64748b;">Req</span><br>
                      <select name="is_required">
                        <option value="1" <?= !empty($it['is_required']) ? 'selected' : '' ?>>Y</option>
                        <option value="0" <?= empty($it['is_required']) ? 'selected' : '' ?>>N</option>
                      </select>
                    </label>
                  </div>
                  <label style="display:block;margin-top:8px;">Prompt *
                    <textarea name="prompt" required rows="2" style="width:100%;max-width:700px;padding:8px;"><?= h((string)($it['prompt'] ?? '')) ?></textarea>
                  </label>
                  <label style="display:block;margin-top:6px;">Guidance
                    <textarea name="guidance" rows="2" style="width:100%;max-width:700px;padding:8px;"><?= h((string)($it['guidance'] ?? '')) ?></textarea>
                  </label>
                  <details style="margin-top:8px;"><summary>JSON</summary>
                    <textarea name="options_json" rows="2" style="width:100%;font-family:monospace;font-size:11px;"><?= h($opts) ?></textarea>
                    <textarea name="reg_refs_json" rows="2" style="width:100%;font-family:monospace;font-size:11px;"><?= h($regs) ?></textarea>
                    <textarea name="manual_refs_json" rows="2" style="width:100%;font-family:monospace;font-size:11px;"><?= h($mans) ?></textarea>
                  </details>
                  <button type="submit" style="margin-top:8px;background:#334155;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Save item</button>
                </form>
                <form method="post" style="margin-top:8px;" onsubmit="return confirm('Delete this item?');">
                  <input type="hidden" name="action" value="delete_item">
                  <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                  <input type="hidden" name="item_id" value="<?= $iid ?>">
                  <button type="submit" style="background:#fee2e2;color:#991b1b;border:0;padding:6px 12px;border-radius:8px;font-weight:700;cursor:pointer;">Delete</button>
                </form>
              <?php else: ?>
                <div style="font-size:12px;color:#64748b;"><?= h((string)($it['item_code'] ?? '')) ?> · <?= h((string)($it['item_type'] ?? '')) ?></div>
                <p style="margin:8px 0 0;font-weight:600;"><?= h((string)($it['prompt'] ?? '')) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </section>
        <?php
} elseif ($templateId > 0) {
    $tpl = ComplianceChecklistEngine::getTemplate($pdo, $templateId);
    if ($tpl === null) {
        compliance_page_open(array(
            'overline' => 'Compliance · Procedures',
            'title' => 'Template not found',
            'back' => array('href' => '/admin/compliance/procedures.php', 'label' => 'Templates'),
        ));
        echo '<section class="cmp-card"><p style="margin:0;">No row for that template id.</p></section>';
        compliance_page_close();
        cw_footer();
        return;
    }
    $vers = ComplianceChecklistEngine::listVersions($pdo, $templateId);
    compliance_page_open(array(
        'overline' => 'Compliance · Checklist template',
        'title' => (string)$tpl['template_code'] . ' · ' . (string)$tpl['title'],
        'description' => 'Template metadata and version timeline. Versions go DRAFT → PENDING_APPROVAL → APPROVED (locked).',
        'back' => array('href' => '/admin/compliance/procedures.php', 'label' => 'Templates'),
        'flash' => $flash,
    ));
    ?>
        <section class="cmp-card">
          <h2 style="margin:0 0 16px;"><?= h((string)$tpl['template_code']) ?></h2>
          <form method="post">
            <input type="hidden" name="action" value="update_template">
            <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
            <label style="display:block;margin-bottom:10px;">Title *
              <input name="title" required value="<?= h((string)$tpl['title']) ?>" style="width:100%;max-width:480px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>
            <label style="display:block;margin-bottom:10px;">Description
              <textarea name="description" rows="3" style="width:100%;max-width:640px;padding:8px;"><?= h((string)($tpl['description'] ?? '')) ?></textarea>
            </label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
              <label>Authority
                <input name="authority" value="<?= h((string)($tpl['authority'] ?? '')) ?>" style="padding:8px;width:160px;">
              </label>
              <label>Scope tags
                <input name="scope_tags" value="<?= h((string)($tpl['scope_tags'] ?? '')) ?>" style="padding:8px;width:220px;">
              </label>
              <label>Active
                <select name="is_active">
                  <option value="1" <?= !empty($tpl['is_active']) ? 'selected' : '' ?>>Yes</option>
                  <option value="0" <?= empty($tpl['is_active']) ? 'selected' : '' ?>>No</option>
                </select>
              </label>
            </div>
            <button type="submit" style="margin-top:12px;">Save template</button>
          </form>
        </section>

        <section class="cmp-card">
          <div class="cmp-list-head" style="margin-bottom:14px;">
            <div class="cmp-list-title"><?= compliance_ui_icon('list') ?><span>Versions</span></div>
            <div class="cmp-count-pill"><?= count($vers) ?></div>
          </div>
          <form method="post" class="cmp-toolbar" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="create_version">
            <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
            <label class="cmp-field" style="min-width:280px;">
              <span class="cmp-field-label">Note (optional)</span>
              <input name="version_description" placeholder="e.g. BCAA annual scope">
            </label>
            <button type="submit">+ New draft version</button>
          </form>
          <div class="compliance-table-wrap">
          <table class="compliance-table">
            <thead><tr><th>Ver</th><th>Status</th><th>Items</th><th>Updated</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($vers as $v): ?>
                <tr>
                  <td class="cmp-mono"><?= (int)$v['version_no'] ?></td>
                  <td><span class="cmp-pill"><?= h((string)$v['status']) ?></span></td>
                  <td><?= (int)$v['items_count'] ?></td>
                  <td class="cmp-mono"><?= h((string)$v['updated_at']) ?></td>
                  <td><a href="/admin/compliance/procedures.php?version_id=<?= (int)$v['id'] ?>" style="font-weight:700;color:#1f4079;text-decoration:none;">Open</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($vers === array()): ?>
                <tr><td colspan="5" style="padding:16px;color:var(--text-muted);">No versions yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
        </section>
        <?php
} else {
    $templates = ComplianceChecklistEngine::listTemplates($pdo);
    compliance_page_open(array(
        'overline' => 'Compliance · Procedures',
        'title' => 'Checklist templates',
        'description' => 'Author templates, version drafts, approve to lock. Locked versions are the only ones reusable in audits.',
        'stats' => array(
            array('label' => 'Templates', 'value' => count($templates)),
            array('label' => 'Active',    'value' => count(array_filter($templates, static fn($t) => !empty($t['is_active']))), 'tone' => 'ok'),
        ),
        'flash' => $flash,
    ));
    ?>
    <section class="cmp-card">
      <h2 style="margin:0 0 12px;">New template</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_template">
        <label style="display:block;margin-bottom:8px;">Code (optional)
          <input name="template_code" placeholder="<?= h(ComplianceChecklistEngine::generateTemplateCode($pdo)) ?>" style="width:100%;padding:8px;">
        </label>
        <label style="display:block;margin-bottom:8px;">Title *
          <input name="title" required style="width:100%;padding:8px;">
        </label>
        <label style="display:block;margin-bottom:8px;">Description
          <textarea name="description" rows="2" style="width:100%;padding:8px;"></textarea>
        </label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <label>Authority <input name="authority" placeholder="BCAA" style="padding:8px;width:120px;"></label>
          <label>Tags <input name="scope_tags" placeholder="OPS,SAFETY" style="padding:8px;width:180px;"></label>
        </div>
        <button type="submit" style="margin-top:12px;">Create</button>
      </form>
    </section>
    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title"><?= compliance_ui_icon('list') ?><span>Templates</span></div>
        <div class="cmp-count-pill"><?= count($templates) ?></div>
      </div>
      <div class="compliance-table-wrap">
      <table class="compliance-table">
        <thead><tr><th>Code</th><th>Title</th><th>Authority</th><th>Active</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($templates as $t): ?>
            <tr>
              <td class="cmp-mono"><?= h((string)$t['template_code']) ?></td>
              <td><?= h((string)$t['title']) ?></td>
              <td><?= h((string)($t['authority'] ?? '—')) ?></td>
              <td><span class="cmp-pill <?= !empty($t['is_active']) ? 'cmp-pill-ok' : '' ?>"><?= !empty($t['is_active']) ? 'Yes' : 'No' ?></span></td>
              <td><a href="/admin/compliance/procedures.php?template_id=<?= (int)$t['id'] ?>" style="font-weight:700;color:#1f4079;text-decoration:none;">Open</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($templates === array()): ?>
            <tr><td colspan="5" style="padding:20px;color:var(--text-muted);">No templates yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>
    <?php
}

compliance_page_close();
cw_footer();
