<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/FlightScheduleService.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/MissionCatalogService.php';

cw_require_admin();
$currentUser = cw_current_user($pdo) ?: array();
$service = new FlightScheduleService($pdo);
$notice = '';
$error = '';
$from = (string)($_GET['from'] ?? gmdate('Y-m-d'));
$to = (string)($_GET['to'] ?? gmdate('Y-m-d', time() + 14 * 86400));
$editId = strtolower(trim((string)($_GET['edit'] ?? '')));
$csrfToken = (string)($_SESSION['flight_schedule_csrf'] ?? '');
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(24));
    $_SESSION['flight_schedule_csrf'] = $csrfToken;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid request token. Refresh the Schedule page and try again.');
        }
        if ((string)($_POST['action'] ?? 'save') === 'cancel') {
            $service->cancelSlot(
                (string)($_POST['scheduler_record_id'] ?? ''),
                (int)($currentUser['id'] ?? 0)
            );
            $notice = 'Schedule slot cancelled.';
        } else {
            $crew = array();
            $userIds = is_array($_POST['crew_user_id'] ?? null) ? $_POST['crew_user_id'] : array();
            $roles = is_array($_POST['crew_role'] ?? null) ? $_POST['crew_role'] : array();
            $names = is_array($_POST['crew_name'] ?? null) ? $_POST['crew_name'] : array();
            foreach ($names as $index => $name) {
                $crew[] = array(
                    'user_id' => (int)($userIds[$index] ?? 0),
                    'person_name' => (string)$name,
                    'role' => (string)($roles[$index] ?? ''),
                );
            }
            $service->saveSlot($_POST, $crew, (int)($currentUser['id'] ?? 0));
            $notice = 'Schedule slot saved.';
        }
    }
    $slots = $service->listSlots($from, $to);
    $aircraft = (new CockpitAircraftService($pdo))->activeAircraft();
    $missions = (new MissionCatalogService($pdo))->listMissions();
    $userStatement = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS display_name"
        . " FROM users WHERE role IN ('student', 'instructor', 'supervisor', 'chief_instructor')"
        . ' ORDER BY display_name ASC, id ASC'
    );
    $users = $userStatement ? ($userStatement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    $editing = $editId !== ''
        ? current(array_filter($slots, static fn(array $slot): bool => (string)$slot['scheduler_record_id'] === $editId))
        : null;
    $editing = is_array($editing) ? $editing : null;
} catch (Throwable $e) {
    $slots = $slots ?? array();
    $aircraft = $aircraft ?? array();
    $missions = $missions ?? array();
    $users = $users ?? array();
    $editing = null;
    $error = $e->getMessage();
}

$formDate = (string)($editing['scheduled_date'] ?? $from);
$formStart = isset($editing['scheduled_start_time'])
    ? str_replace(' ', 'T', substr((string)$editing['scheduled_start_time'], 0, 16))
    : '';
$formEnd = isset($editing['scheduled_end_time'])
    ? str_replace(' ', 'T', substr((string)$editing['scheduled_end_time'], 0, 16))
    : '';
$formCrew = is_array($editing['crew'] ?? null) ? array_values($editing['crew']) : array();

cw_header('Schedule');
?>
<style>
.schedule-page{display:grid;gap:18px}.schedule-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.schedule-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.schedule-field label{display:block;font-weight:800;color:#334155;margin-bottom:5px}.schedule-field input,.schedule-field select{box-sizing:border-box;width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:9px;background:#fff}.schedule-button{border:0;border-radius:999px;background:#1d4ed8;color:#fff;font-weight:800;padding:10px 15px;cursor:pointer}.schedule-table{width:100%;border-collapse:collapse}.schedule-table th,.schedule-table td{padding:10px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.schedule-muted{color:#64748b;font-size:13px}.schedule-notice{padding:12px;border-radius:12px;background:#ecfdf5;color:#166534}.schedule-error{padding:12px;border-radius:12px;background:#fef2f2;color:#991b1b}.crew-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}@media(max-width:760px){.schedule-table{display:block;overflow:auto}}
</style>
<div class="schedule-page">
  <section class="schedule-card">
    <h2 style="margin-top:0">Flight Schedule</h2>
    <p class="schedule-muted">Create dated aircraft slots that enrolled CVR units can retrieve and atomically claim when Dispatch starts.</p>
  </section>
  <?php if ($notice !== ''): ?><div class="schedule-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="schedule-error"><?= h($error) ?></div><?php endif; ?>
  <section class="schedule-card">
    <h3 style="margin-top:0"><?= $editing ? 'Edit Schedule Slot' : 'Add Schedule Slot' ?></h3>
    <form method="post">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <?php if ($editing): ?><input type="hidden" name="scheduler_record_id" value="<?= h((string)$editing['scheduler_record_id']) ?>"><?php endif; ?>
      <div class="schedule-grid">
        <div class="schedule-field"><label>Date</label><input type="date" name="scheduled_date" value="<?= h($formDate) ?>" required></div>
        <div class="schedule-field"><label>Start</label><input type="datetime-local" name="scheduled_start_time" value="<?= h($formStart) ?>" required></div>
        <div class="schedule-field"><label>End</label><input type="datetime-local" name="scheduled_end_time" value="<?= h($formEnd) ?>" required></div>
        <div class="schedule-field"><label>Aircraft</label><select name="aircraft_id" required><option value="">Choose…</option><?php foreach ($aircraft as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['aircraft']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['registration']) ?></option><?php endforeach; ?></select></div>
        <div class="schedule-field"><label>Mission</label><select name="mission_id"><option value="">None</option><?php foreach ($missions as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['mission']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['code'] . ' — ' . (string)$row['name']) ?></option><?php endforeach; ?></select></div>
        <div class="schedule-field"><label>Mission code</label><input name="mission_code" maxlength="64" placeholder="X-Y-Z" value="<?= h((string)($editing['mission']['code'] ?? '')) ?>"></div>
        <div class="schedule-field"><label>Departure</label><input name="planned_departure_airport" maxlength="8" value="<?= h((string)($editing['planned_departure_airport'] ?? '')) ?>"></div>
        <div class="schedule-field"><label>Destination</label><input name="planned_destination_airport" maxlength="8" value="<?= h((string)($editing['planned_destination_airport'] ?? '')) ?>"></div>
        <div class="schedule-field"><label>Notes</label><input name="notes" maxlength="1000" value="<?= h((string)($editing['notes'] ?? '')) ?>"></div>
      </div>
      <h4>Crew</h4>
      <?php for ($i = 0; $i < 3; $i++): ?>
        <?php $assigned = is_array($formCrew[$i] ?? null) ? $formCrew[$i] : array(); ?>
        <div class="crew-row">
          <div class="schedule-field"><label>Person <?= $i + 1 ?></label><select name="crew_user_id[]" data-crew-user="<?= $i ?>"><option value="">Optional</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>" data-name="<?= h((string)$user['display_name']) ?>" <?= (int)($assigned['person_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h((string)$user['display_name']) ?></option><?php endforeach; ?></select><input type="hidden" name="crew_name[]" id="crew_name_<?= $i ?>" value="<?= h((string)($assigned['person_name'] ?? '')) ?>"></div>
          <div class="schedule-field"><label>Role</label><select name="crew_role[]"><option value="">None</option><?php foreach (array('student' => 'Student', 'instructor' => 'Instructor', 'pic' => 'PIC', 'safetyPilot' => 'Safety Pilot', 'observer' => 'Observer') as $roleValue => $roleLabel): ?><option value="<?= h($roleValue) ?>" <?= strcasecmp((string)($assigned['role'] ?? ''), $roleValue) === 0 ? 'selected' : '' ?>><?= h($roleLabel) ?></option><?php endforeach; ?></select></div>
        </div>
      <?php endfor; ?>
      <div style="margin-top:14px"><button class="schedule-button" type="submit">Save Schedule Slot</button><?php if ($editing): ?> <a href="/admin/schedule.php" class="schedule-muted">Cancel editing</a><?php endif; ?></div>
    </form>
  </section>
  <section class="schedule-card">
    <form method="get" class="schedule-grid" style="margin-bottom:14px">
      <div class="schedule-field"><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div>
      <div class="schedule-field"><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
      <div><button class="schedule-button" type="submit" style="margin-top:25px">Load</button></div>
    </form>
    <table class="schedule-table"><thead><tr><th>When</th><th>Aircraft</th><th>Mission / route</th><th>Crew</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($slots as $slot): ?><tr>
      <td><?= h((string)$slot['scheduled_date']) ?><br><span class="schedule-muted"><?= h(substr((string)$slot['scheduled_start_time'], 11, 5)) ?>–<?= h(substr((string)$slot['scheduled_end_time'], 11, 5)) ?></span></td>
      <td><?= h((string)$slot['aircraft']['registration']) ?></td>
      <td><?= h((string)$slot['mission']['code']) ?><br><span class="schedule-muted"><?= h((string)$slot['planned_departure_airport']) ?> → <?= h((string)$slot['planned_destination_airport']) ?></span></td>
      <td><?php foreach ($slot['crew'] as $member): ?><?= h((string)$member['person_name']) ?> <span class="schedule-muted">(<?= h((string)$member['role']) ?>)</span><br><?php endforeach; ?></td>
      <td><?= h((string)$slot['status']) ?></td>
      <td><?php if ((string)$slot['status'] === 'scheduled'): ?>
        <a href="/admin/schedule.php?from=<?= h(urlencode($from)) ?>&amp;to=<?= h(urlencode($to)) ?>&amp;edit=<?= h((string)$slot['scheduler_record_id']) ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Cancel this scheduled flight?')">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="scheduler_record_id" value="<?= h((string)$slot['scheduler_record_id']) ?>">
          <button type="submit" style="border:0;background:none;color:#b91c1c;cursor:pointer">Cancel</button>
        </form>
      <?php else: ?><span class="schedule-muted">Locked</span><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$slots): ?><tr><td colspan="6" class="schedule-muted">No slots in this date range.</td></tr><?php endif; ?>
    </tbody></table>
  </section>
</div>
<script>
document.querySelectorAll('[data-crew-user]').forEach(function(select) {
  function syncName() {
    var option = select.options[select.selectedIndex];
    document.getElementById('crew_name_' + select.dataset.crewUser).value = option ? (option.dataset.name || '') : '';
  }
  select.addEventListener('change', syncName);
  syncName();
});
</script>
<?php cw_footer(); ?>
