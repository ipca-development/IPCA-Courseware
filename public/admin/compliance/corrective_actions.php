<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceFindingEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCapEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

/**
 * @param 'success'|'error' $type
 */
function cap_flash_set(string $type, string $message): void
{
    $_SESSION['_ipca_compliance_cap_flash'] = array(
        'type' => $type,
        'message' => $message,
    );
}

/**
 * @return array{type:string,message:string}|null
 */
function cap_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_cap_flash']) || !is_array($_SESSION['_ipca_compliance_cap_flash'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_cap_flash'];
    unset($_SESSION['_ipca_compliance_cap_flash']);
    return $f;
}

if (!empty($_SESSION['_ipca_compliance_cap_suggest']['saved_at'])
    && is_numeric($_SESSION['_ipca_compliance_cap_suggest']['saved_at'])
    && time() - (int)$_SESSION['_ipca_compliance_cap_suggest']['saved_at'] > 1800) {
    unset($_SESSION['_ipca_compliance_cap_suggest']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_cap') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            $id = ComplianceCapEngine::create($pdo, array(
                'finding_id' => $fid,
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'action_type' => (string)($_POST['action_type'] ?? 'CORRECTIVE'),
                'status' => (string)($_POST['status'] ?? 'PROPOSED'),
                'effort' => (string)($_POST['effort'] ?? ''),
                'responsible_name' => (string)($_POST['responsible_name'] ?? ''),
                'due_date' => (string)($_POST['due_date'] ?? ''),
            ), $uid);
            cap_flash_set('success', 'Corrective action created.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $id);
        }

        if ($action === 'update_cap') {
            $cid = (int)($_POST['cap_id'] ?? 0);
            if ($cid <= 0) {
                throw new RuntimeException('Invalid action.');
            }
            ComplianceCapEngine::update($pdo, $cid, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'action_type' => (string)($_POST['action_type'] ?? ''),
                'status' => (string)($_POST['status'] ?? ''),
                'effort' => (string)($_POST['effort'] ?? ''),
                'responsible_name' => (string)($_POST['responsible_name'] ?? ''),
                'due_date' => (string)($_POST['due_date'] ?? ''),
            ), $uid);
            cap_flash_set('success', 'Corrective action saved.');
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }

        if ($action === 'suggest_cap_ai') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            if ($fid <= 0) {
                throw new RuntimeException('Select a finding first.');
            }
            ComplianceCapEngine::suggestCapOptions($pdo, $fid, $uid);
            cap_flash_set('success', 'AI suggested CAP options — review and adopt one below.');
            redirect('/admin/compliance/corrective_actions.php?finding_id=' . $fid);
        }

        if ($action === 'adopt_cap_option') {
            $fid = (int)($_POST['finding_id'] ?? 0);
            $idx = (int)($_POST['option_index'] ?? -1);
            $bundle = $_SESSION['_ipca_compliance_cap_suggest'] ?? null;
            if (!is_array($bundle) || (int)($bundle['finding_id'] ?? 0) !== $fid) {
                throw new RuntimeException('Suggestion session expired — run AI suggest again.');
            }
            $options = $bundle['options'] ?? null;
            if (!is_array($options) || !isset($options[$idx]) || !is_array($options[$idx])) {
                throw new RuntimeException('Invalid option.');
            }
            $aiRunId = isset($bundle['ai_run_id']) ? (int)$bundle['ai_run_id'] : null;
            if ($aiRunId !== null && $aiRunId <= 0) {
                $aiRunId = null;
            }
            $created = ComplianceCapEngine::adoptAiCapOption($pdo, $fid, $options[$idx], $uid, $aiRunId);
            cap_flash_set(
                'success',
                'Adopted option — created ' . count($created) . ' corrective action(s).'
            );
            redirect('/admin/compliance/corrective_actions.php?finding_id=' . $fid);
        }
    } catch (Throwable $e) {
        cap_flash_set('error', $e->getMessage());
        $cid = (int)($_POST['cap_id'] ?? 0);
        if ($cid > 0) {
            redirect('/admin/compliance/corrective_actions.php?id=' . $cid);
        }
        $fid = (int)($_POST['finding_id'] ?? 0);
        if ($fid > 0) {
            redirect('/admin/compliance/corrective_actions.php?finding_id=' . $fid);
        }
        redirect('/admin/compliance/corrective_actions.php');
    }
}

$flash = cap_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterFinding = isset($_GET['finding_id']) ? (int)$_GET['finding_id'] : 0;
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

cw_header('Compliance · Corrective Actions');

$optionsType = array(
    'CORRECTIVE' => 'Corrective',
    'PREVENTIVE' => 'Preventive',
    'CONTAINMENT' => 'Containment',
    'IMMEDIATE' => 'Immediate',
);
$optionsCapStatus = array(
    'PROPOSED' => 'Proposed',
    'APPROVED' => 'Approved',
    'IN_PROGRESS' => 'In progress',
    'COMPLETED' => 'Completed',
    'VERIFIED' => 'Verified',
    'INEFFECTIVE' => 'Ineffective',
    'CANCELLED' => 'Cancelled',
);
$optionsEffort = array(
    '' => '—',
    'XS' => 'XS',
    'S' => 'S',
    'M' => 'M',
    'L' => 'L',
    'XL' => 'XL',
);

if ($flash !== null) {
    $cls = ($flash['type'] === 'success') ? 'is-ok' : 'is-danger';
    echo '<div class="queue-status ' . h($cls) . '" style="margin-bottom:16px;padding:12px 16px;border-radius:12px;">'
        . h((string)$flash['message']) . '</div>';
}

try {
    $findingsPick = ComplianceFindingEngine::listRecent($pdo, 200);
} catch (Throwable $e) {
    $findingsPick = array();
      echo '<p class="queue-status is-warn" style="padding:12px;">Could not load findings. '
        . h($e->getMessage()) . '</p>';
}

if ($detailId > 0) {
    try {
        $cap = ComplianceCapEngine::getById($pdo, $detailId);
    } catch (Throwable $e) {
        $cap = null;
        echo '<p class="queue-status is-danger">' . h($e->getMessage()) . '</p>';
    }

    if ($cap === null) {
        echo '<p>Corrective action not found.</p>';
        echo '<p><a class="nav-link" href="/admin/compliance/corrective_actions.php">← All actions</a></p>';
    } else {
        $capLocked = !empty($cap['locked_at']);
        $fidRow = (int)$cap['finding_id'];
        ?>
        <p style="margin-bottom:20px;">
          <a href="/admin/compliance/corrective_actions.php" style="color:#1e3c72;font-weight:700;">← All actions</a>
          <span style="color:#64748b;margin:0 8px;">|</span>
          <span style="font-family:ui-monospace,monospace;font-size:13px;"><?= h((string)$cap['action_code']) ?></span>
          <span style="color:#64748b;margin:0 8px;">|</span>
          <a href="/admin/compliance/findings.php?id=<?= $fidRow ?>" style="color:#0f766e;">Open finding</a>
          <span style="color:#64748b;margin:0 8px;">|</span>
          <a href="/admin/compliance/export_rca_cap_pdf.php?finding_id=<?= $fidRow ?>">Export finding PDF</a>
        </p>

        <section style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:24px;max-width:960px;">
          <h2 style="margin:0 0 8px;font-size:20px;">Corrective action</h2>
          <p style="color:#64748b;font-size:14px;margin:0 0 16px;">
            Finding <strong><?= h((string)$cap['finding_code']) ?></strong>
            — <?= h((string)$cap['finding_title']) ?>
          </p>
          <?php if ($capLocked): ?>
            <p class="queue-status is-warn">This row is locked.</p>
          <?php endif; ?>

          <form method="post" action="/admin/compliance/corrective_actions.php?id=<?= (int)$detailId ?>">
            <input type="hidden" name="action" value="update_cap">
            <input type="hidden" name="cap_id" value="<?= (int)$detailId ?>">

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Title *</span>
              <input name="title" required value="<?= h((string)$cap['title']) ?>"
                style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $capLocked ? 'disabled' : '' ?>>
            </label>

            <label style="display:block;margin-bottom:12px;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Description *</span>
              <textarea name="description" required rows="6"
                style="width:100%;max-width:720px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $capLocked ? 'disabled' : '' ?>><?= h((string)$cap['description']) ?></textarea>
            </label>

            <div style="display:flex;flex-wrap:wrap;gap:16px;">
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Type</span>
                <select name="action_type" style="padding:8px;border-radius:8px;" <?= $capLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsType as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$cap['action_type'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Status</span>
                <select name="status" style="padding:8px;border-radius:8px;" <?= $capLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsCapStatus as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)$cap['status'] === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Effort</span>
                <select name="effort" style="padding:8px;border-radius:8px;" <?= $capLocked ? 'disabled' : '' ?>>
                  <?php foreach ($optionsEffort as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= ((string)($cap['effort'] ?? '') === $k) ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Due date</span>
                <input type="date" name="due_date"
                  value="<?= !empty($cap['due_date']) ? h(substr((string)$cap['due_date'], 0, 10)) : '' ?>"
                  style="padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                  <?= $capLocked ? 'disabled' : '' ?>>
              </label>
            </div>

            <label style="display:block;margin:16px 0;">
              <span style="display:block;font-size:12px;font-weight:700;color:#64748b;">Responsible (name)</span>
              <input name="responsible_name" value="<?= h((string)($cap['responsible_name'] ?? '')) ?>"
                style="width:100%;max-width:420px;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"
                <?= $capLocked ? 'disabled' : '' ?>>
            </label>

            <?php if (!empty($cap['ai_assisted'])): ?>
              <p class="small" style="color:#64748b;font-size:13px;">Created with AI assistance (run logged in ipca_compliance_ai_runs).</p>
            <?php endif; ?>

            <?php if (!$capLocked): ?>
              <button type="submit" style="background:#1e3c72;color:#fff;border:0;padding:10px 20px;border-radius:10px;font-weight:700;cursor:pointer;">
                Save
              </button>
            <?php endif; ?>
          </form>
        </section>
        <?php
        compliance_render_comms_panel($pdo, 'corrective_action', (string)$detailId);
    }
} else {
    $statusParam = $filterStatus !== '' ? $filterStatus : null;
    $findingParam = $filterFinding > 0 ? $filterFinding : null;
    try {
        $rows = ComplianceCapEngine::listRecent($pdo, $statusParam, $findingParam, 200);
    } catch (Throwable $e) {
        $rows = array();
        echo '<p class="queue-status is-danger">Could not load actions.<br>' . h($e->getMessage()) . '</p>';
    }

    $bundle = $_SESSION['_ipca_compliance_cap_suggest'] ?? null;
    $bundleFinding = is_array($bundle) ? (int)($bundle['finding_id'] ?? 0) : 0;
    $bundleOptions = (is_array($bundle) && isset($bundle['options']) && is_array($bundle['options']))
        ? $bundle['options'] : array();

    ?>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:20px;max-width:1200px;">
      <div>
        <h2 style="margin:0 0 6px;font-size:20px;">Corrective actions</h2>
        <p style="margin:0;color:#64748b;font-size:14px;">CAP items per finding — optional AI suggestions (human adopt).</p>
      </div>
      <form method="get" action="/admin/compliance/corrective_actions.php" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <label style="margin:0;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Finding</span>
          <select name="finding_id" style="padding:8px;border-radius:8px;min-width:220px;">
            <option value="">All findings</option>
            <?php foreach ($findingsPick as $f): ?>
              <option value="<?= (int)$f['id'] ?>" <?= $filterFinding === (int)$f['id'] ? 'selected' : '' ?>>
                <?= h((string)$f['finding_code'] . ' — ' . (string)$f['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="margin:0;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Status</span>
          <select name="status" style="padding:8px;border-radius:8px;">
            <option value="">All</option>
            <?php foreach ($optionsCapStatus as $k => $lab): ?>
              <option value="<?= h($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" style="padding:8px 14px;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:700;cursor:pointer;">
          Filter
        </button>
      </form>
    </div>

    <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;max-width:1200px;">
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <thead>
            <tr style="background:#f1f5f9;text-align:left;">
              <th style="padding:12px 14px;">CAP code</th>
              <th style="padding:12px 14px;">Finding</th>
              <th style="padding:12px 14px;">Title</th>
              <th style="padding:12px 14px;">Status</th>
              <th style="padding:12px 14px;">Due</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" style="padding:20px;color:#64748b;">No matching actions.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr style="border-top:1px solid #e2e8f0;">
                <td style="padding:10px 14px;font-family:ui-monospace,monospace;font-size:12px;">
                  <a href="/admin/compliance/corrective_actions.php?id=<?= (int)$r['id'] ?>" style="color:#1e3c72;font-weight:700;">
                    <?= h((string)$r['action_code']) ?>
                  </a>
                </td>
                <td style="padding:10px 14px;font-size:12px;">
                  <a href="/admin/compliance/findings.php?id=<?= (int)$r['finding_id'] ?>" style="color:#0f766e;">
                    <?= h((string)$r['finding_code']) ?>
                  </a>
                </td>
                <td style="padding:10px 14px;"><?= h((string)$r['title']) ?></td>
                <td style="padding:10px 14px;"><?= h((string)$r['status']) ?></td>
                <td style="padding:10px 14px;color:#64748b;font-size:12px;">
                  <?= !empty($r['due_date']) ? h(substr((string)$r['due_date'], 0, 10)) : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex;flex-direction:column;gap:20px;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
          <h3 style="margin:0 0 14px;font-size:16px;">New corrective action</h3>
          <form method="post" action="/admin/compliance/corrective_actions.php">
            <input type="hidden" name="action" value="create_cap">

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Finding *</span>
              <select name="finding_id" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                <?php foreach ($findingsPick as $f): ?>
                  <option value="<?= (int)$f['id'] ?>" <?= ($filterFinding === (int)$f['id']) ? 'selected' : '' ?>>
                    <?= h((string)$f['finding_code'] . ' — ' . (string)$f['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Title *</span>
              <input name="title" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Description *</span>
              <textarea name="description" required rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;"></textarea>
            </label>

            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
              <label>
                <span style="font-size:11px;font-weight:700;color:#64748b;">Type</span>
                <select name="action_type" style="width:100%;padding:8px;border-radius:8px;">
                  <?php foreach ($optionsType as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= $k === 'CORRECTIVE' ? 'selected' : '' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span style="font-size:11px;font-weight:700;color:#64748b;">Status</span>
                <select name="status" style="width:100%;padding:8px;border-radius:8px;">
                  <option value="PROPOSED" selected>Proposed</option>
                  <?php foreach ($optionsCapStatus as $k => $lab): ?>
                    <?php if ($k === 'PROPOSED') {
                        continue;
                    } ?>
                    <option value="<?= h($k) ?>"><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Due date</span>
              <input type="date" name="due_date" style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
            </label>

            <button type="submit" style="width:100%;background:#1e3c72;color:#fff;border:0;padding:12px;border-radius:10px;font-weight:800;cursor:pointer;">
              Create action
            </button>
          </form>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px 22px;">
          <h3 style="margin:0 0 8px;font-size:16px;">AI: suggest CAP options</h3>
          <p style="margin:0 0 14px;color:#64748b;font-size:13px;line-height:1.45;">
            Generates A/B/C options (logged as <code>CAP_SUGGEST</code>). RCA improves quality but is not required.
          </p>
          <form method="post" action="/admin/compliance/corrective_actions.php">
            <input type="hidden" name="action" value="suggest_cap_ai">
            <label style="display:block;margin-bottom:10px;">
              <span style="display:block;font-size:11px;font-weight:700;color:#64748b;">Finding *</span>
              <select name="finding_id" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #cbd5e1;">
                <?php foreach ($findingsPick as $f): ?>
                  <option value="<?= (int)$f['id'] ?>" <?= ($filterFinding === (int)$f['id']) ? 'selected' : '' ?>>
                    <?= h((string)$f['finding_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" style="width:100%;background:#3730a3;color:#fff;border:0;padding:12px;border-radius:10px;font-weight:800;cursor:pointer;"
              onclick="return confirm('Request CAP options from AI?');">
              Run AI suggest
            </button>
          </form>

          <?php
          if ($bundleFinding > 0 && $bundleOptions !== array()
                && ($filterFinding <= 0 || $filterFinding === $bundleFinding)):
              ?>
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid #e2e8f0;">
              <h4 style="margin:0 0 10px;font-size:14px;">Adopt an option</h4>
              <?php foreach ($bundleOptions as $idx => $opt):
                  if (!is_array($opt)) {
                      continue;
                  }
                  $lab = (string)($opt['label'] ?? ('Option ' . $idx));
                  $eff = (string)($opt['effort'] ?? '');
                  $acts = $opt['actions'] ?? array();
                  ?>
                <div style="margin-bottom:14px;padding:12px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                  <div style="font-weight:800;color:#1e3c72;margin-bottom:8px;"><?= h($lab) ?><?php
                    echo $eff !== '' ? ' — ' . h($eff) : '';
                  ?></div>
                  <?php if (is_array($acts)): ?>
                    <ul style="margin:0;padding-left:18px;font-size:13px;color:#475569;">
                      <?php foreach ($acts as $a): ?>
                        <?php if (!is_array($a)) {
                            continue;
                        } ?>
                        <li><?= h((string)($a['description'] ?? '')) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <form method="post" action="/admin/compliance/corrective_actions.php?finding_id=<?= (int)$bundleFinding ?>"
                    style="margin-top:10px;">
                    <input type="hidden" name="action" value="adopt_cap_option">
                    <input type="hidden" name="finding_id" value="<?= (int)$bundleFinding ?>">
                    <input type="hidden" name="option_index" value="<?= (int)$idx ?>">
                    <button type="submit" style="background:#0f766e;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;">
                      Adopt &amp; create actions
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
}

cw_footer();
