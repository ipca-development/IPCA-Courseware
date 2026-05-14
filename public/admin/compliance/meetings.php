<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceMeetingEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAuditEngine.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceCommsPanel.php';

$user = compliance_require_access($pdo);
$uid = (int)($user['id'] ?? 0);

function mtg_flash(string $type, string $msg): void
{
    $_SESSION['_ipca_compliance_flash_meetings'] = array('type' => $type, 'message' => $msg);
}

function mtg_flash_take(): ?array
{
    if (empty($_SESSION['_ipca_compliance_flash_meetings']) || !is_array($_SESSION['_ipca_compliance_flash_meetings'])) {
        return null;
    }
    $f = $_SESSION['_ipca_compliance_flash_meetings'];
    unset($_SESSION['_ipca_compliance_flash_meetings']);

    return $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $detailId = isset($_POST['meeting_id']) ? (int)$_POST['meeting_id'] : 0;
    try {
        if ($action === 'create_meeting') {
            $id = ComplianceMeetingEngine::create($pdo, array(
                'title' => (string)($_POST['title'] ?? ''),
                'meeting_type' => (string)($_POST['meeting_type'] ?? 'AUDIT_REVIEW'),
                'status' => (string)($_POST['status'] ?? 'SCHEDULED'),
                'scheduled_start' => (string)($_POST['scheduled_start'] ?? ''),
                'scheduled_end' => (string)($_POST['scheduled_end'] ?? ''),
                'location' => (string)($_POST['location'] ?? ''),
                'agenda' => (string)($_POST['agenda'] ?? ''),
                'audit_id' => (int)($_POST['audit_id'] ?? 0),
            ), $uid);
            mtg_flash('success', 'Meeting created.');
            redirect('/admin/compliance/meetings.php?id=' . $id);
        }
        if ($action === 'update_meeting') {
            ComplianceMeetingEngine::update($pdo, $detailId, array(
                'title' => (string)($_POST['title'] ?? ''),
                'meeting_type' => (string)($_POST['meeting_type'] ?? 'AUDIT_REVIEW'),
                'status' => (string)($_POST['status'] ?? 'SCHEDULED'),
                'scheduled_start' => (string)($_POST['scheduled_start'] ?? ''),
                'scheduled_end' => (string)($_POST['scheduled_end'] ?? ''),
                'location' => (string)($_POST['location'] ?? ''),
                'agenda' => (string)($_POST['agenda'] ?? ''),
            ), $uid);
            mtg_flash('success', 'Meeting saved.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId);
        }
        if ($action === 'start_meeting') {
            ComplianceMeetingEngine::start($pdo, $detailId, $uid);
            mtg_flash('success', 'Meeting started.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId);
        }
        if ($action === 'complete_meeting') {
            ComplianceMeetingEngine::complete($pdo, $detailId, $uid);
            mtg_flash('success', 'Meeting completed and locked.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId);
        }
        if ($action === 'cancel_meeting') {
            ComplianceMeetingEngine::cancel($pdo, $detailId, $uid);
            mtg_flash('success', 'Meeting cancelled.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId);
        }
        if ($action === 'add_attendee') {
            ComplianceMeetingEngine::addAttendee($pdo, $detailId, array(
                'display_name' => (string)($_POST['display_name'] ?? ''),
                'email' => (string)($_POST['email'] ?? ''),
                'organisation' => (string)($_POST['organisation'] ?? ''),
                'attendee_role' => (string)($_POST['attendee_role'] ?? ''),
            ));
            mtg_flash('success', 'Attendee added.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#attendees');
        }
        if ($action === 'toggle_attendance') {
            $aid = (int)($_POST['attendee_id'] ?? 0);
            $attended = ((string)($_POST['attended'] ?? '0')) === '1';
            ComplianceMeetingEngine::markAttendance($pdo, $aid, $attended);
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#attendees');
        }
        if ($action === 'remove_attendee') {
            $aid = (int)($_POST['attendee_id'] ?? 0);
            ComplianceMeetingEngine::removeAttendee($pdo, $aid);
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#attendees');
        }
        if ($action === 'add_decision') {
            ComplianceMeetingEngine::addDecision($pdo, $detailId, array(
                'decision_text' => (string)($_POST['decision_text'] ?? ''),
                'decision_kind' => (string)($_POST['decision_kind'] ?? ''),
                'rationale' => (string)($_POST['rationale'] ?? ''),
            ), $uid);
            mtg_flash('success', 'Decision recorded.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#decisions');
        }
        if ($action === 'delete_decision') {
            $did = (int)($_POST['decision_id'] ?? 0);
            ComplianceMeetingEngine::deleteDecision($pdo, $did);
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#decisions');
        }
        if ($action === 'add_action') {
            ComplianceMeetingEngine::addAction($pdo, $detailId, array(
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'OPEN'),
                'responsible_name' => (string)($_POST['responsible_name'] ?? ''),
                'due_date' => (string)($_POST['due_date'] ?? ''),
            ), $uid);
            mtg_flash('success', 'Action item added.');
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#actions');
        }
        if ($action === 'update_action_status') {
            $aid = (int)($_POST['action_id'] ?? 0);
            ComplianceMeetingEngine::updateActionStatus($pdo, $aid, (string)($_POST['status'] ?? 'OPEN'));
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#actions');
        }
        if ($action === 'delete_action') {
            $aid = (int)($_POST['action_id'] ?? 0);
            ComplianceMeetingEngine::deleteAction($pdo, $aid);
            redirect('/admin/compliance/meetings.php?id=' . $detailId . '#actions');
        }
    } catch (Throwable $e) {
        mtg_flash('error', $e->getMessage());
        redirect('/admin/compliance/meetings.php' . ($detailId > 0 ? '?id=' . $detailId : ''));
    }
}

$flash = mtg_flash_take();
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

cw_header('Compliance · Meetings');

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
?>
<style>
  .cmpmtg-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;}
  .cmpmtg-pill{display:inline-flex;align-items:center;padding:0 12px;height:28px;border-radius:999px;font-size:11.5px;font-weight:720;letter-spacing:.04em;background:rgba(48,124,183,0.10);color:#246ea9;border:1px solid rgba(48,124,183,0.18);}
  .cmpmtg-pill.is-live{background:rgba(185,54,54,0.12);color:#9a2424;border-color:rgba(185,54,54,0.22);}
  .cmpmtg-pill.is-done{background:rgba(32,135,90,0.12);color:#1f7a54;border-color:rgba(32,135,90,0.20);}
  .cmpmtg-pill.is-cancel{background:rgba(86,112,153,0.10);color:#405a82;border-color:rgba(86,112,153,0.18);}
  .cmpmtg-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
  @media (max-width:720px){ .cmpmtg-grid-2{grid-template-columns:1fr;} }
  .cmpmtg-btn-small{height:32px;min-height:32px;padding:0 12px !important;font-size:12px !important;}
</style>

<?php
if ($detailId > 0) {
    $m = ComplianceMeetingEngine::getById($pdo, $detailId);
    if ($m === null) {
        compliance_page_open(array(
            'overline' => 'Compliance · Meetings',
            'title' => 'Meeting not found',
            'back' => array('href' => '/admin/compliance/meetings.php', 'label' => 'All meetings'),
        ));
        echo '<section class="cmp-card"><p style="margin:0;">No row for that id.</p></section>';
        compliance_page_close();
        cw_footer();
        return;
    }

    $locked = !empty($m['locked_at']);
    $status = (string)$m['status'];
    $statusCls = $status === 'LIVE' ? 'is-live' : ($status === 'COMPLETED' ? 'is-done' : ($status === 'CANCELLED' ? 'is-cancel' : ''));

    $attendees = ComplianceMeetingEngine::listAttendees($pdo, $detailId);
    $decisions = ComplianceMeetingEngine::listDecisions($pdo, $detailId);
    $actions = ComplianceMeetingEngine::listActions($pdo, $detailId);
    compliance_page_open(array(
        'overline' => 'Compliance · Meeting',
        'title' => (string)$m['title'],
        'description' => $status . ' · ' . (string)$m['meeting_type'] . (!empty($m['scheduled_start']) ? ' · ' . substr((string)$m['scheduled_start'], 0, 16) : '') . ($locked ? ' · LOCKED' : ''),
        'back' => array('href' => '/admin/compliance/meetings.php', 'label' => 'All meetings', 'code' => (string)$m['meeting_code']),
        'flash' => $flash,
    ));
    ?>

    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('calendar') ?>
          <span>Overview</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <span class="cmpmtg-pill <?= h($statusCls) ?>"><?= h($status) ?></span>
          <?php if ($locked): ?><span class="cmpmtg-pill is-cancel">Locked</span><?php endif; ?>
        </div>
      </div>

      <?php if ($locked): ?>
        <dl style="display:grid;grid-template-columns:200px 1fr;gap:6px 16px;font-size:14px;margin:0;">
          <dt style="color:#64748b;">Type</dt><dd><?= h((string)$m['meeting_type']) ?></dd>
          <dt style="color:#64748b;">Status</dt><dd><?= h($status) ?></dd>
          <dt style="color:#64748b;">Scheduled</dt>
          <dd><?= h(substr((string)($m['scheduled_start'] ?? '—'), 0, 16)) ?>
            <?= !empty($m['scheduled_end']) ? '→ ' . h(substr((string)$m['scheduled_end'], 0, 16)) : '' ?></dd>
          <dt style="color:#64748b;">Actual</dt>
          <dd><?= h(substr((string)($m['actual_start'] ?? '—'), 0, 16)) ?>
            <?= !empty($m['actual_end']) ? '→ ' . h(substr((string)$m['actual_end'], 0, 16)) : '' ?></dd>
          <dt style="color:#64748b;">Location</dt><dd><?= h((string)($m['location'] ?? '—')) ?></dd>
          <dt style="color:#64748b;">Agenda</dt><dd><?= nl2br(h((string)($m['agenda'] ?? '—'))) ?></dd>
        </dl>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="action" value="update_meeting">
          <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
          <label style="display:block;margin-bottom:12px;">
            <span class="cmpmtg-label">Title *</span>
            <input class="cmpmtg-input" name="title" required value="<?= h((string)$m['title']) ?>">
          </label>
          <div class="cmpmtg-grid-2" style="margin-bottom:12px;">
            <label>
              <span class="cmpmtg-label">Type</span>
              <select class="cmpmtg-input" name="meeting_type">
                <?php foreach (ComplianceMeetingEngine::meetingTypes() as $t): ?>
                  <option value="<?= h($t) ?>" <?= ((string)$m['meeting_type'] === $t) ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span class="cmpmtg-label">Status</span>
              <select class="cmpmtg-input" name="status">
                <?php foreach (ComplianceMeetingEngine::statuses() as $s): ?>
                  <option value="<?= h($s) ?>" <?= ($status === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="cmpmtg-grid-2" style="margin-bottom:12px;">
            <label>
              <span class="cmpmtg-label">Scheduled start</span>
              <input class="cmpmtg-input" type="datetime-local" name="scheduled_start"
                value="<?= h(str_replace(' ', 'T', substr((string)($m['scheduled_start'] ?? ''), 0, 16))) ?>">
            </label>
            <label>
              <span class="cmpmtg-label">Scheduled end</span>
              <input class="cmpmtg-input" type="datetime-local" name="scheduled_end"
                value="<?= h(str_replace(' ', 'T', substr((string)($m['scheduled_end'] ?? ''), 0, 16))) ?>">
            </label>
          </div>
          <label style="display:block;margin-bottom:12px;">
            <span class="cmpmtg-label">Location</span>
            <input class="cmpmtg-input" name="location" value="<?= h((string)($m['location'] ?? '')) ?>">
          </label>
          <label style="display:block;margin-bottom:12px;">
            <span class="cmpmtg-label">Agenda</span>
            <textarea class="cmpmtg-input" name="agenda" rows="5"><?= h((string)($m['agenda'] ?? '')) ?></textarea>
          </label>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="cmpmtg-btn">Save</button>
            <?php if ($status !== 'LIVE' && $status !== 'COMPLETED' && $status !== 'CANCELLED'): ?>
              <button type="submit" name="action" value="start_meeting" class="cmpmtg-btn is-ok">Start meeting</button>
            <?php endif; ?>
            <?php if ($status !== 'COMPLETED' && $status !== 'CANCELLED'): ?>
              <button type="submit" name="action" value="complete_meeting" class="cmpmtg-btn"
                style="background:#0f766e;"
                onclick="return confirm('Complete & lock this meeting?');">Complete & lock</button>
              <button type="submit" name="action" value="cancel_meeting" class="cmpmtg-btn is-danger"
                onclick="return confirm('Cancel this meeting?');">Cancel</button>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <section id="attendees" class="cmp-card">
      <h2 style="margin:0 0 14px;font-size:16px;">Attendees</h2>
      <?php if ($attendees !== array()): ?>
        <div class="compliance-table-wrap">
        <table class="cmpmtg-table compliance-table" style="margin-bottom:16px;">
          <thead><tr>
            <th>Name</th><th>Role</th><th>Organisation</th><th>Email</th><th>Attended</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($attendees as $a): ?>
              <tr>
                <td><?= h((string)($a['display_name'] ?? '—')) ?></td>
                <td><?= h((string)($a['attendee_role'] ?? '—')) ?></td>
                <td><?= h((string)($a['organisation'] ?? '—')) ?></td>
                <td><?= h((string)($a['email'] ?? '—')) ?></td>
                <td>
                  <?php if (!$locked): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="toggle_attendance">
                      <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
                      <input type="hidden" name="attendee_id" value="<?= (int)$a['id'] ?>">
                      <input type="hidden" name="attended" value="<?= ((int)$a['attended'] === 1) ? '0' : '1' ?>">
                      <button type="submit" class="cmpmtg-btn-small"
                        style="background:<?= ((int)$a['attended'] === 1) ? '#0f766e' : '#e2e8f0' ?>;color:<?= ((int)$a['attended'] === 1) ? '#fff' : '#0f172a' ?>;">
                        <?= ((int)$a['attended'] === 1) ? '✓ Present' : 'Mark present' ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <?= ((int)$a['attended'] === 1) ? '✓' : '—' ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!$locked): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Remove this attendee?');">
                      <input type="hidden" name="action" value="remove_attendee">
                      <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
                      <input type="hidden" name="attendee_id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="cmpmtg-btn-small is-danger" style="background:#fee2e2;color:#991b1b;">Remove</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php else: ?>
        <p style="color:#64748b;margin:0 0 14px;">No attendees yet.</p>
      <?php endif; ?>

      <?php if (!$locked): ?>
        <details>
          <summary style="cursor:pointer;font-weight:700;color:#3730a3;">+ Add attendee</summary>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="action" value="add_attendee">
            <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
            <div class="cmpmtg-grid-2" style="margin-bottom:10px;">
              <label>
                <span class="cmpmtg-label">Name *</span>
                <input class="cmpmtg-input" name="display_name" required>
              </label>
              <label>
                <span class="cmpmtg-label">Role</span>
                <select class="cmpmtg-input" name="attendee_role">
                  <option value="">—</option>
                  <?php foreach (ComplianceMeetingEngine::attendeeRoles() as $r): ?>
                    <option value="<?= h($r) ?>"><?= h($r) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="cmpmtg-grid-2" style="margin-bottom:10px;">
              <label>
                <span class="cmpmtg-label">Email</span>
                <input class="cmpmtg-input" name="email" type="email">
              </label>
              <label>
                <span class="cmpmtg-label">Organisation</span>
                <input class="cmpmtg-input" name="organisation">
              </label>
            </div>
            <button type="submit" class="cmpmtg-btn">Add attendee</button>
          </form>
        </details>
      <?php endif; ?>
    </section>

    <section id="decisions" class="cmp-card">
      <h2 style="margin:0 0 14px;font-size:16px;">Decisions recorded</h2>
      <?php if ($decisions !== array()): ?>
        <ul style="margin:0 0 14px;padding:0;list-style:none;">
          <?php foreach ($decisions as $d): ?>
            <li style="padding:10px 0;border-bottom:1px solid #f1f5f9;">
              <div style="font-weight:700;color:#0f172a;"><?= h((string)$d['decision_text']) ?></div>
              <div style="font-size:12px;color:#64748b;margin-top:2px;">
                <?= h((string)($d['decision_kind'] ?? '—')) ?>
                · <?= h(substr((string)($d['decided_at'] ?? ''), 0, 16)) ?>
                <?php if (!$locked): ?>
                  <form method="post" style="display:inline;margin-left:8px;" onsubmit="return confirm('Delete this decision?');">
                    <input type="hidden" name="action" value="delete_decision">
                    <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
                    <input type="hidden" name="decision_id" value="<?= (int)$d['id'] ?>">
                    <button type="submit" class="cmpmtg-btn-small" style="background:#fee2e2;color:#991b1b;">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if (!empty($d['rationale'])): ?>
                <div style="color:#334155;font-size:13px;margin-top:4px;"><?= h((string)$d['rationale']) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p style="color:#64748b;margin:0 0 14px;">No decisions recorded yet.</p>
      <?php endif; ?>

      <?php if (!$locked): ?>
        <details>
          <summary style="cursor:pointer;font-weight:700;color:#3730a3;">+ Record decision</summary>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="action" value="add_decision">
            <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
            <label style="display:block;margin-bottom:10px;">
              <span class="cmpmtg-label">Decision *</span>
              <textarea class="cmpmtg-input" name="decision_text" rows="2" required></textarea>
            </label>
            <div class="cmpmtg-grid-2" style="margin-bottom:10px;">
              <label>
                <span class="cmpmtg-label">Kind</span>
                <select class="cmpmtg-input" name="decision_kind">
                  <option value="">—</option>
                  <?php foreach (ComplianceMeetingEngine::decisionKinds() as $k): ?>
                    <option value="<?= h($k) ?>"><?= h($k) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <label style="display:block;margin-bottom:10px;">
              <span class="cmpmtg-label">Rationale</span>
              <textarea class="cmpmtg-input" name="rationale" rows="2"></textarea>
            </label>
            <button type="submit" class="cmpmtg-btn">Record decision</button>
          </form>
        </details>
      <?php endif; ?>
    </section>

    <section id="actions" class="cmp-card">
      <h2 style="margin:0 0 14px;font-size:16px;">Action items</h2>
      <?php if ($actions !== array()): ?>
        <div class="compliance-table-wrap">
        <table class="cmpmtg-table compliance-table" style="margin-bottom:16px;">
          <thead><tr>
            <th>Title</th><th>Responsible</th><th>Due</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($actions as $a): ?>
              <tr>
                <td>
                  <div style="font-weight:700;"><?= h((string)$a['title']) ?></div>
                  <?php if (!empty($a['description'])): ?>
                    <div style="color:#475569;font-size:12px;"><?= h((string)$a['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h((string)($a['responsible_name'] ?? '—')) ?></td>
                <td><?= h(substr((string)($a['due_date'] ?? ''), 0, 10)) ?></td>
                <td>
                  <?php if (!$locked): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="update_action_status">
                      <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
                      <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
                      <select name="status" class="cmpmtg-input" style="padding:4px 8px;width:auto;display:inline-block;" onchange="this.form.submit()">
                        <?php foreach (ComplianceMeetingEngine::actionStatuses() as $s): ?>
                          <option value="<?= h($s) ?>" <?= ((string)$a['status'] === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  <?php else: ?>
                    <?= h((string)$a['status']) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!$locked): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this action item?');">
                      <input type="hidden" name="action" value="delete_action">
                      <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
                      <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="cmpmtg-btn-small" style="background:#fee2e2;color:#991b1b;">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php else: ?>
        <p style="color:#64748b;margin:0 0 14px;">No action items yet.</p>
      <?php endif; ?>

      <?php if (!$locked): ?>
        <details>
          <summary style="cursor:pointer;font-weight:700;color:#3730a3;">+ Add action item</summary>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="action" value="add_action">
            <input type="hidden" name="meeting_id" value="<?= (int)$detailId ?>">
            <label style="display:block;margin-bottom:10px;">
              <span class="cmpmtg-label">Title *</span>
              <input class="cmpmtg-input" name="title" required>
            </label>
            <label style="display:block;margin-bottom:10px;">
              <span class="cmpmtg-label">Description</span>
              <textarea class="cmpmtg-input" name="description" rows="2"></textarea>
            </label>
            <div class="cmpmtg-grid-2" style="margin-bottom:10px;">
              <label>
                <span class="cmpmtg-label">Responsible</span>
                <input class="cmpmtg-input" name="responsible_name">
              </label>
              <label>
                <span class="cmpmtg-label">Due date</span>
                <input class="cmpmtg-input" type="date" name="due_date">
              </label>
            </div>
            <button type="submit" class="cmpmtg-btn">Add action item</button>
          </form>
        </details>
      <?php endif; ?>
    </section>

    <?php
    compliance_render_comms_panel($pdo, 'meeting', (string)$detailId);
    compliance_page_close();
    cw_footer();
    return;
}

$meetings = ComplianceMeetingEngine::listRecent($pdo, 100);
$audits = ComplianceAuditEngine::listRecent($pdo, 100);

$mtgCounts = array('scheduled' => 0, 'live' => 0, 'completed' => 0, 'total' => count($meetings));
foreach ($meetings as $m) {
    $st = (string)$m['status'];
    if ($st === 'SCHEDULED') {
        $mtgCounts['scheduled']++;
    }
    if ($st === 'LIVE') {
        $mtgCounts['live']++;
    }
    if ($st === 'COMPLETED') {
        $mtgCounts['completed']++;
    }
}

compliance_page_open(array(
    'overline' => 'Compliance · Meetings',
    'title' => 'Meetings',
    'description' => 'Schedule, run and lock compliance meetings: audit openings/closings, management reviews, safety reviews. Each meeting captures attendees, decisions and action items, and can be linked to an audit or case.',
    'stats' => array(
        array('label' => 'Scheduled', 'value' => (int)$mtgCounts['scheduled']),
        array('label' => 'Live',      'value' => (int)$mtgCounts['live'],      'tone' => $mtgCounts['live'] > 0 ? 'crit' : 'ok'),
        array('label' => 'Completed', 'value' => (int)$mtgCounts['completed'], 'tone' => 'ok'),
        array('label' => 'Total',     'value' => (int)$mtgCounts['total']),
    ),
    'flash' => $flash,
));
?>
  <div class="cmp-cols">
    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title">
          <?= compliance_ui_icon('calendar') ?>
          <span>Recent meetings</span>
        </div>
        <div class="cmp-count-pill"><?= count($meetings) ?></div>
      </div>
      <?php if ($meetings === array()): ?>
        <p style="color:var(--text-muted);margin:0;">No meetings yet — use the form on the right to create one.</p>
      <?php else: ?>
        <div class="compliance-table-wrap">
        <table class="cmpmtg-table compliance-table">
          <thead><tr>
            <th>Code</th><th>Title</th><th>Type</th><th>Status</th><th>When</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($meetings as $m):
              $st = (string)$m['status'];
              $cls = $st === 'LIVE' ? 'is-live' : ($st === 'COMPLETED' ? 'is-done' : ($st === 'CANCELLED' ? 'is-cancel' : ''));
            ?>
              <tr>
                <td class="cmp-mono">
                  <a href="/admin/compliance/meetings.php?id=<?= (int)$m['id'] ?>" style="color:#1f4079;font-weight:700;text-decoration:none;">
                    <?= h((string)$m['meeting_code']) ?>
                  </a>
                </td>
                <td><?= h((string)$m['title']) ?></td>
                <td><?= h((string)$m['meeting_type']) ?></td>
                <td><span class="cmpmtg-pill <?= h($cls) ?>"><?= h($st) ?></span></td>
                <td class="cmp-mono"><?= h(substr((string)($m['scheduled_start'] ?? ''), 0, 16)) ?></td>
                <td>
                  <a class="cmp-btn-secondary cmp-btn-link cmpmtg-btn-small" href="/admin/compliance/meetings.php?id=<?= (int)$m['id'] ?>" style="text-decoration:none;">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="cmp-card">
      <h3 style="margin:0 0 14px;">New meeting</h3>
      <form method="post">
        <input type="hidden" name="action" value="create_meeting">
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpmtg-label">Title *</span>
          <input class="cmpmtg-input" name="title" required>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpmtg-label">Type</span>
          <select class="cmpmtg-input" name="meeting_type">
            <?php foreach (ComplianceMeetingEngine::meetingTypes() as $t): ?>
              <option value="<?= h($t) ?>" <?= ($t === 'AUDIT_REVIEW') ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpmtg-label">Linked audit (optional)</span>
          <select class="cmpmtg-input" name="audit_id">
            <option value="">—</option>
            <?php foreach ($audits as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= h((string)$a['audit_code']) ?> · <?= h((string)$a['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpmtg-label">Scheduled start</span>
          <input class="cmpmtg-input" type="datetime-local" name="scheduled_start">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpmtg-label">Scheduled end</span>
          <input class="cmpmtg-input" type="datetime-local" name="scheduled_end">
        </label>
        <label style="display:block;margin-bottom:10px;">
          <span class="cmpmtg-label">Location</span>
          <input class="cmpmtg-input" name="location">
        </label>
        <label style="display:block;margin-bottom:14px;">
          <span class="cmpmtg-label">Agenda</span>
          <textarea class="cmpmtg-input" name="agenda" rows="4"></textarea>
        </label>
        <button type="submit" style="width:100%;">Create meeting</button>
      </form>
    </section>
  </div>
<?php
compliance_page_close();
cw_footer();
