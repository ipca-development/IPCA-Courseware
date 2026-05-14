<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceFindingEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCapEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

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
$filterDue = isset($_GET['due']) ? trim((string)$_GET['due']) : '';
if (!in_array($filterDue, array('', 'overdue', 'due_soon', 'no_due'), true)) {
    $filterDue = '';
}

cw_header('Compliance · Corrective Actions');

$capStatsHero = array();
try {
    $capStatsHero = array(
        'open'    => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')")->fetchColumn(),
        'overdue' => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE due_date IS NOT NULL AND due_date < CURDATE() AND COALESCE(status,'') NOT IN ('CLOSED','VERIFIED','CANCELLED')")->fetchColumn(),
        'in_progress' => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE status = 'IN_PROGRESS'")->fetchColumn(),
        'verified' => (int)$pdo->query("SELECT COUNT(*) FROM ipca_compliance_corrective_actions WHERE status = 'VERIFIED'")->fetchColumn(),
    );
} catch (Throwable) {
}

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

if ($detailId > 0) {
    compliance_page_open(array(
        'overline' => 'Compliance · CAP',
        'title' => 'Corrective action',
        'description' => 'Edit the CAP details, track progress and verification, and link communications.',
        'back' => array('href' => '/admin/compliance/corrective_actions.php', 'label' => 'All corrective actions'),
        'flash' => $flash,
    ));
} else {
    compliance_page_open(array(
        'overline' => 'Compliance',
        'title' => 'Corrective actions',
        'description' => 'CAP items per finding — optional AI suggestions (human adopt). Filter by finding or status to scope the queue.',
        'actions' => array(
            array('label' => 'New corrective action', 'modal' => 'cap-create-modal', 'icon' => 'plus'),
        ),
        'stats' => array(
            array('label' => 'Open',        'value' => $capStatsHero['open']        ?? 0, 'tone' => ($capStatsHero['open']    ?? 0) > 0 ? 'warn' : 'ok'),
            array('label' => 'Overdue',     'value' => $capStatsHero['overdue']     ?? 0, 'tone' => ($capStatsHero['overdue'] ?? 0) > 0 ? 'crit' : 'ok'),
            array('label' => 'In progress', 'value' => $capStatsHero['in_progress'] ?? 0),
            array('label' => 'Verified',    'value' => $capStatsHero['verified']    ?? 0, 'tone' => 'ok'),
        ),
        'flash' => $flash,
    ));
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
        <section class="cmp-card">
          <div class="cmp-list-head" style="margin-bottom:14px;">
            <div class="cmp-list-title">
              <?= compliance_ui_icon('tools') ?>
              <span><?= h((string)$cap['action_code']) ?></span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/findings.php?id=<?= $fidRow ?>" style="text-decoration:none;">Open finding</a>
              <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/export_rca_cap_pdf.php?finding_id=<?= $fidRow ?>" style="text-decoration:none;">Export PDF</a>
            </div>
          </div>
          <p class="cmp-meta-line">
            <span>Finding <strong><?= h((string)$cap['finding_code']) ?></strong></span>
            <span><?= h((string)$cap['finding_title']) ?></span>
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
    if ($filterDue !== '') {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+7 days'));
        $rows = array_values(array_filter($rows, static function (array $r) use ($filterDue, $today, $soon): bool {
            $due = substr((string)($r['due_date'] ?? ''), 0, 10);
            if ($filterDue === 'no_due') {
                return $due === '';
            }
            if ($due === '') {
                return false;
            }
            if ($filterDue === 'overdue') {
                return $due < $today;
            }
            return $due >= $today && $due <= $soon;
        }));
    }

    $bundle = $_SESSION['_ipca_compliance_cap_suggest'] ?? null;
    $bundleFinding = is_array($bundle) ? (int)($bundle['finding_id'] ?? 0) : 0;
    $bundleOptions = (is_array($bundle) && isset($bundle['options']) && is_array($bundle['options']))
        ? $bundle['options'] : array();

    ?>
    <section class="cmp-card cmp-toolbar">
      <div class="cmp-toolbar-head">
        <div class="cmp-toolbar-title">
          <?= compliance_ui_icon('filter') ?>
          <span>Filter actions</span>
        </div>
        <div class="cmp-toolbar-meta">Scope by finding and status; latest activity first.</div>
      </div>
      <form method="get" action="/admin/compliance/corrective_actions.php" class="compliance-filterbar">
          <label class="cmp-field">
            <span>Finding</span>
            <select name="finding_id">
              <option value="">All findings</option>
              <?php foreach ($findingsPick as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $filterFinding === (int)$f['id'] ? 'selected' : '' ?>>
                  <?= h((string)$f['finding_code'] . ' — ' . (string)$f['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span>Status</span>
            <select name="status">
              <option value="">All</option>
              <?php foreach ($optionsCapStatus as $k => $lab): ?>
                <option value="<?= h($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="cmp-field">
            <span>Due state</span>
            <select name="due">
              <option value="" <?= $filterDue === '' ? 'selected' : '' ?>>Any due date</option>
              <option value="overdue" <?= $filterDue === 'overdue' ? 'selected' : '' ?>>Overdue</option>
              <option value="due_soon" <?= $filterDue === 'due_soon' ? 'selected' : '' ?>>Due within 7 days</option>
              <option value="no_due" <?= $filterDue === 'no_due' ? 'selected' : '' ?>>No due date</option>
            </select>
          </label>
        <div class="cmp-toolbar-actions" style="margin:0;">
          <button type="submit">Apply filters</button>
          <?php if ($filterStatus !== '' || $filterFinding > 0 || $filterDue !== ''): ?>
            <a class="cmp-btn-secondary cmp-btn-link" href="/admin/compliance/corrective_actions.php" style="text-decoration:none;">Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </section>

      <section class="cmp-card compliance-card--full" style="overflow:hidden;">
        <div class="compliance-table-wrap">
        <table class="compliance-table">
          <thead>
            <tr>
              <th>CAP code</th>
              <th>Finding</th>
              <th>Title</th>
              <th>Status</th>
              <th>Due</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" style="padding:20px;color:#64748b;">No matching actions.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr data-href="/admin/compliance/corrective_actions.php?id=<?= (int)$r['id'] ?>" class="compliance-row-clickable">
                <td class="cmp-mono">
                  <a href="/admin/compliance/corrective_actions.php?id=<?= (int)$r['id'] ?>" style="color:#1f4079;font-weight:700;">
                    <?= h((string)$r['action_code']) ?>
                  </a>
                </td>
                <td>
                  <a href="/admin/compliance/findings.php?id=<?= (int)$r['finding_id'] ?>" style="color:#1f4079;">
                    <?= h((string)$r['finding_code']) ?>
                  </a>
                </td>
                <td><?= h((string)$r['title']) ?></td>
                <td><?= compliance_badge((string)$r['status']) ?></td>
                <td class="cmp-mono"><?= compliance_deadline_badge(isset($r['due_date']) ? (string)$r['due_date'] : null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>

      <?php compliance_modal_open('cap-create-modal', 'New corrective action'); ?>
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

            <div class="compliance-modal__footer">
              <button type="button" class="cmp-btn-secondary" data-compliance-modal-close>Cancel</button>
              <button type="submit">Create action</button>
            </div>
          </form>
      <?php compliance_modal_close(); ?>

        <section class="cmp-card">
          <h3 style="margin:0 0 8px;">AI: suggest CAP options</h3>
          <p class="cmp-card-sub" style="margin:0 0 14px;">
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
            <button type="submit" style="width:100%;" onclick="return confirm('Request CAP options from AI?');">
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
                  <form method="post" action="/admin/compliance/corrective_actions.php?finding_id=<?= (int)$bundleFinding ?>" style="margin-top:10px;">
                    <input type="hidden" name="action" value="adopt_cap_option">
                    <input type="hidden" name="finding_id" value="<?= (int)$bundleFinding ?>">
                    <input type="hidden" name="option_index" value="<?= (int)$idx ?>">
                    <button type="submit" class="cmp-btn-success">Adopt &amp; create actions</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
    <?php
}

compliance_page_close();
cw_footer();
