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
$from = (string)($_GET['from'] ?? date('Y-m-d'));
$to = (string)($_GET['to'] ?? date('Y-m-d', time() + 14 * 86400));
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
            $notice = 'Reservation deleted.';
            $editId = '';
        } else {
            $_POST['scheduled_date'] = (string)($_POST['scheduled_start_date'] ?? '');
            $_POST['scheduled_start_time'] = trim(
                (string)($_POST['scheduled_start_date'] ?? '') . ' ' . (string)($_POST['scheduled_start_clock'] ?? '')
            );
            $_POST['scheduled_end_time'] = trim(
                (string)($_POST['scheduled_end_date'] ?? '') . ' ' . (string)($_POST['scheduled_end_clock'] ?? '')
            );
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
            $notice = 'Reservation saved.';
            $editId = '';
        }
    }
    $slots = array_values(array_filter(
        $service->listSlots($from, $to),
        static fn(array $slot): bool => (string)($slot['status'] ?? '') !== 'cancelled'
    ));
    $aircraft = (new CockpitAircraftService($pdo))->activeAircraft();
    $missions = (new MissionCatalogService($pdo))->listMissions();
    $reservationTypes = $service->reservationTypes();
    $userStatement = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS display_name, role"
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
    $reservationTypes = $reservationTypes ?? $service->reservationTypes();
    $users = $users ?? array();
    $editing = null;
    $error = $e->getMessage();
}

$formStartDate = (string)($editing['scheduled_date'] ?? $from);
$formStartClock = isset($editing['scheduled_start_time'])
    ? substr((string)$editing['scheduled_start_time'], 11, 5)
    : '10:00';
$formEndDate = isset($editing['scheduled_end_time'])
    ? substr((string)$editing['scheduled_end_time'], 0, 10)
    : $formStartDate;
$formEndClock = isset($editing['scheduled_end_time'])
    ? substr((string)$editing['scheduled_end_time'], 11, 5)
    : '12:00';
$formCrew = is_array($editing['crew'] ?? null) ? array_values($editing['crew']) : array();

cw_header('Schedule');
?>
<style>
.schedule-page{display:grid;gap:20px;max-width:1180px}.schedule-hero,.schedule-card{background:var(--panel-bg,#fff);border:1px solid var(--border-soft,rgba(15,23,42,.06));border-radius:18px;box-shadow:var(--card-shadow,0 10px 24px rgba(15,23,42,.055))}.schedule-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:24px 26px;background:linear-gradient(135deg,#fff 0%,#f4f8fd 100%)}.schedule-hero-copy{min-width:0}.schedule-eyebrow{color:#307cb7;font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.schedule-hero h2{margin:6px 0 5px;font-size:26px;letter-spacing:-.025em}.schedule-card{padding:24px 26px}.schedule-card h3{margin:0 0 22px;font-size:19px}.reservation-form{display:grid;gap:18px}.reservation-row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:18px;align-items:center}.reservation-row--top{align-items:start}.reservation-label{font-size:14px;font-weight:750;color:var(--text-strong,#152235);padding-top:2px}.reservation-control,.reservation-control select,.reservation-control input,.reservation-control textarea{min-width:0}.reservation-control input,.reservation-control select,.reservation-control textarea,.schedule-filter input{box-sizing:border-box;width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;background:#fff;color:var(--text-strong,#152235);font:inherit;box-shadow:inset 0 1px 2px rgba(15,23,42,.03)}.reservation-control input:focus,.reservation-control select:focus,.reservation-control textarea:focus{outline:none;border-color:#4a90d0;box-shadow:0 0 0 3px rgba(48,124,183,.12)}.reservation-pair{display:grid;grid-template-columns:minmax(0,1fr) 150px;gap:10px}.crew-stack{display:grid;gap:10px}.crew-row{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(180px,.6fr);gap:10px}.crew-index{font-size:11px;font-weight:800;color:var(--text-muted,#728198);letter-spacing:.08em;text-transform:uppercase;margin:0 0 5px}.schedule-actions{display:flex;align-items:center;gap:10px;padding-top:8px;margin-left:168px}.schedule-button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;background:#307cb7;color:#fff;font-weight:750;padding:11px 17px;cursor:pointer;box-shadow:0 6px 14px rgba(48,124,183,.18);text-decoration:none}.schedule-button:hover{background:#246ea9}.schedule-button--secondary{background:#fff;color:#334155;border:1px solid #cbd5e1;box-shadow:none}.schedule-button--secondary:hover{background:#f8fafc}.schedule-button--danger{margin-left:auto;background:#fff;color:#b42318;border:1px solid #fecaca;box-shadow:none}.schedule-button--danger:hover{background:#fff1f2}.schedule-action-link{display:inline-flex;padding:7px 11px;border:1px solid #bfdbfe;border-radius:8px;color:#246ea9;font-size:13px;font-weight:750;text-decoration:none;background:#eff6ff}.schedule-table{width:100%;border-collapse:collapse}.schedule-table th,.schedule-table td{padding:12px 9px;border-bottom:1px solid #e8edf3;text-align:left;vertical-align:top}.schedule-table th{color:var(--text-muted,#728198);font-size:12px;text-transform:uppercase;letter-spacing:.06em}.schedule-muted{color:var(--text-muted,#728198);font-size:13px}.reservation-kind{display:block;margin-top:3px;color:#307cb7;font-size:12px;font-weight:700}.schedule-notice{padding:12px 14px;border:1px solid #bbf7d0;border-radius:12px;background:#ecfdf5;color:#166534}.schedule-error{padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;color:#991b1b}.schedule-filter{display:grid;grid-template-columns:180px 180px auto;gap:10px;align-items:end;margin-bottom:18px}.schedule-filter label{display:block;font-size:12px;font-weight:750;color:var(--text-muted,#728198);margin-bottom:5px}.reservation-modal{display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;padding:24px}.reservation-modal.is-open{display:flex}.reservation-modal-backdrop{position:absolute;inset:0;border:0;background:rgba(13,29,52,.64);backdrop-filter:blur(5px);cursor:default}.reservation-modal-dialog{position:relative;width:min(850px,100%);max-height:calc(100vh - 48px);overflow:auto;background:#f8fafc;border:1px solid rgba(255,255,255,.35);border-radius:20px;box-shadow:0 28px 80px rgba(13,29,52,.35)}.reservation-modal-header{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:20px 24px;background:rgba(255,255,255,.96);border-bottom:1px solid #e2e8f0;backdrop-filter:blur(10px)}.reservation-modal-title{margin:0;font-size:20px}.reservation-modal-close{width:36px;height:36px;border:0;border-radius:9px;background:#eef2f7;color:#475569;font-size:22px;cursor:pointer}.schedule-modal-card{border:0;border-radius:0;box-shadow:none;background:transparent}.schedule-modal-card h3{display:none}body.reservation-modal-open{overflow:hidden}@media(max-width:760px){.schedule-card,.schedule-hero{padding:18px}.schedule-hero{align-items:flex-start;flex-direction:column}.reservation-modal{padding:0}.reservation-modal-dialog{height:100%;max-height:100%;border-radius:0}.reservation-row{grid-template-columns:1fr;gap:7px}.reservation-pair,.crew-row{grid-template-columns:1fr}.schedule-actions{margin-left:0;flex-wrap:wrap}.schedule-button--danger{margin-left:0}.schedule-table{display:block;overflow:auto}.schedule-filter{grid-template-columns:1fr}}
</style>
<div class="schedule-page">
  <section class="schedule-hero">
    <div class="schedule-hero-copy">
      <div class="schedule-eyebrow">Flight Operations</div>
      <h2>Schedule</h2>
      <p class="schedule-muted">Reservations assigned to an aircraft become available automatically in its CVR Unit app.</p>
    </div>
    <button class="schedule-button" type="button" data-open-reservation>+ New Reservation</button>
  </section>
  <?php if ($notice !== ''): ?><div class="schedule-notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="schedule-error"><?= h($error) ?></div><?php endif; ?>

  <div class="reservation-modal<?= $editing ? ' is-open' : '' ?>" id="reservation-modal" data-editing="<?= $editing ? '1' : '0' ?>" role="dialog" aria-modal="true" aria-labelledby="reservation-modal-title">
    <button class="reservation-modal-backdrop" type="button" data-close-reservation aria-label="Close reservation"></button>
    <div class="reservation-modal-dialog">
      <header class="reservation-modal-header">
        <h3 class="reservation-modal-title" id="reservation-modal-title"><?= $editing ? 'Edit Reservation' : 'New Reservation' ?></h3>
        <button class="reservation-modal-close" type="button" data-close-reservation aria-label="Close">×</button>
      </header>
  <section class="schedule-card schedule-modal-card">
    <h3><?= $editing ? 'Edit Reservation' : 'New Reservation' ?></h3>
    <form method="post" class="reservation-form">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <?php if ($editing): ?><input type="hidden" name="scheduler_record_id" value="<?= h((string)$editing['scheduler_record_id']) ?>"><?php endif; ?>

      <div class="reservation-row">
        <div class="reservation-label">Reservation Type</div>
        <div class="reservation-control"><select name="reservation_type" required><?php foreach ($reservationTypes as $typeValue => $typeLabel): ?><option value="<?= h($typeValue) ?>" <?= (string)($editing['reservation_type'] ?? 'flight_training') === $typeValue ? 'selected' : '' ?>><?= h($typeLabel) ?></option><?php endforeach; ?></select></div>
      </div>

      <div class="reservation-row reservation-row--top">
        <div class="reservation-label">Crew</div>
        <div class="crew-stack">
        <?php for ($i = 0; $i < 3; $i++): ?>
          <?php $assigned = is_array($formCrew[$i] ?? null) ? $formCrew[$i] : array(); ?>
          <div class="crew-row">
            <div class="reservation-control"><div class="crew-index">Person <?= $i + 1 ?></div><select name="crew_user_id[]" data-crew-user="<?= $i ?>"><option value="">Optional</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>" data-name="<?= h((string)$user['display_name']) ?>" data-default-role="<?= h(in_array((string)$user['role'], array('instructor','supervisor','chief_instructor'), true) ? 'instructor' : ((string)$user['role'] === 'student' ? 'student' : '')) ?>" <?= (int)($assigned['person_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h((string)$user['display_name']) ?></option><?php endforeach; ?></select><input type="hidden" name="crew_name[]" id="crew_name_<?= $i ?>" value="<?= h((string)($assigned['person_name'] ?? '')) ?>"></div>
            <div class="reservation-control"><div class="crew-index">Role</div><select name="crew_role[]" id="crew_role_<?= $i ?>"><option value="">None</option><?php foreach (array('student' => 'Student', 'instructor' => 'Instructor', 'pic' => 'PIC', 'safetyPilot' => 'Safety Pilot', 'observer' => 'Observer') as $roleValue => $roleLabel): ?><option value="<?= h($roleValue) ?>" <?= strcasecmp((string)($assigned['role'] ?? ''), $roleValue) === 0 ? 'selected' : '' ?>><?= h($roleLabel) ?></option><?php endforeach; ?></select></div>
          </div>
        <?php endfor; ?>
        </div>
      </div>

      <div class="reservation-row"><div class="reservation-label">Aircraft</div><div class="reservation-control"><select name="aircraft_id" required><option value="">Select aircraft</option><?php foreach ($aircraft as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['aircraft']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['registration'] . (trim((string)($row['aircraft_type'] ?? '')) !== '' ? ' — ' . (string)$row['aircraft_type'] : '')) ?></option><?php endforeach; ?></select></div></div>
      <div class="reservation-row"><div class="reservation-label">Mission</div><div class="reservation-control"><select name="mission_id" required><option value="">Select mission</option><?php foreach ($missions as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['mission']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['code'] . ' — ' . (string)$row['name']) ?></option><?php endforeach; ?></select></div></div>
      <div class="reservation-row"><div class="reservation-label">Depart</div><div class="reservation-pair"><div class="reservation-control"><input type="date" name="scheduled_start_date" value="<?= h($formStartDate) ?>" required></div><div class="reservation-control"><input type="time" name="scheduled_start_clock" value="<?= h($formStartClock) ?>" required></div></div></div>
      <div class="reservation-row"><div class="reservation-label">Return</div><div class="reservation-pair"><div class="reservation-control"><input type="date" name="scheduled_end_date" value="<?= h($formEndDate) ?>" required></div><div class="reservation-control"><input type="time" name="scheduled_end_clock" value="<?= h($formEndClock) ?>" required></div></div></div>
      <div class="reservation-row"><div class="reservation-label">Route</div><div class="reservation-pair"><div class="reservation-control"><input name="planned_departure_airport" maxlength="8" placeholder="Departure" value="<?= h((string)($editing['planned_departure_airport'] ?? '')) ?>"></div><div class="reservation-control"><input name="planned_destination_airport" maxlength="8" placeholder="Destination" value="<?= h((string)($editing['planned_destination_airport'] ?? '')) ?>"></div></div></div>
      <div class="reservation-row reservation-row--top"><div class="reservation-label">Public Notes</div><div class="reservation-control"><textarea name="notes" rows="3" maxlength="1000"><?= h((string)($editing['notes'] ?? '')) ?></textarea></div></div>
      <div class="schedule-actions">
        <button class="schedule-button" type="submit"><?= $editing ? 'Update Reservation' : 'Create Reservation' ?></button>
        <button class="schedule-button schedule-button--secondary" type="button" data-close-reservation>Close</button>
        <?php if ($editing): ?><button class="schedule-button schedule-button--danger" type="submit" form="delete-reservation-form">Delete Reservation</button><?php endif; ?>
      </div>
    </form>
    <?php if ($editing): ?>
      <form method="post" id="delete-reservation-form" onsubmit="return confirm('Delete this reservation? This action cannot be undone from the schedule.')">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="scheduler_record_id" value="<?= h((string)$editing['scheduler_record_id']) ?>">
      </form>
    <?php endif; ?>
  </section>
    </div>
  </div>

  <section class="schedule-card">
    <form method="get" class="schedule-filter">
      <div><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
      <div><button class="schedule-button" type="submit">Load Reservations</button></div>
    </form>
    <table class="schedule-table"><thead><tr><th>When</th><th>Aircraft</th><th>Mission / route</th><th>Crew</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($slots as $slot): ?><tr>
      <td><?= h(date('D M j, Y', strtotime((string)$slot['scheduled_date']))) ?><br><span class="schedule-muted"><?= h(date('g:i A', strtotime((string)$slot['scheduled_start_time']))) ?>–<?= h(date('g:i A', strtotime((string)$slot['scheduled_end_time']))) ?></span><span class="reservation-kind"><?= h((string)($slot['reservation_type_label'] ?? 'Flight Training')) ?></span></td>
      <td><?= h((string)$slot['aircraft']['registration']) ?></td>
      <td><?= h((string)$slot['mission']['code']) ?><br><span class="schedule-muted"><?= h((string)$slot['planned_departure_airport']) ?> → <?= h((string)$slot['planned_destination_airport']) ?></span></td>
      <td><?php foreach ($slot['crew'] as $member): ?><?= h((string)$member['person_name']) ?> <span class="schedule-muted">(<?= h((string)$member['role']) ?>)</span><br><?php endforeach; ?></td>
      <td><?= h((string)$slot['status']) ?></td>
      <td><?php if ((string)$slot['status'] === 'scheduled'): ?>
        <a class="schedule-action-link" href="/admin/schedule.php?from=<?= h(urlencode($from)) ?>&amp;to=<?= h(urlencode($to)) ?>&amp;edit=<?= h((string)$slot['scheduler_record_id']) ?>">Edit</a>
      <?php else: ?><span class="schedule-muted" title="This reservation is locked after dispatch activation.">Locked after dispatch</span><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$slots): ?><tr><td colspan="6" class="schedule-muted">No reservations in this date range.</td></tr><?php endif; ?>
    </tbody></table>
  </section>
</div>
<script>
var reservationModal = document.getElementById('reservation-modal');
var reservationReturnUrl = <?= json_encode(
    '/admin/schedule.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

function openReservationModal() {
  reservationModal.classList.add('is-open');
  document.body.classList.add('reservation-modal-open');
  window.setTimeout(function() {
    var firstControl = reservationModal.querySelector('select, input:not([type="hidden"])');
    if (firstControl) firstControl.focus();
  }, 0);
}

function closeReservationModal() {
  if (reservationModal.dataset.editing === '1') {
    window.location.href = reservationReturnUrl;
    return;
  }
  reservationModal.classList.remove('is-open');
  document.body.classList.remove('reservation-modal-open');
}

document.querySelectorAll('[data-open-reservation]').forEach(function(button) {
  button.addEventListener('click', openReservationModal);
});
document.querySelectorAll('[data-close-reservation]').forEach(function(button) {
  button.addEventListener('click', closeReservationModal);
});
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape' && reservationModal.classList.contains('is-open')) {
    closeReservationModal();
  }
});
if (reservationModal.classList.contains('is-open')) {
  document.body.classList.add('reservation-modal-open');
}

document.querySelectorAll('[data-crew-user]').forEach(function(select) {
  function syncCrew(assignRole) {
    var option = select.options[select.selectedIndex];
    document.getElementById('crew_name_' + select.dataset.crewUser).value = option ? (option.dataset.name || '') : '';
    if (assignRole) {
      document.getElementById('crew_role_' + select.dataset.crewUser).value = option ? (option.dataset.defaultRole || '') : '';
    }
  }
  select.addEventListener('change', function() { syncCrew(true); });
  syncCrew(false);
});
</script>
<?php cw_footer(); ?>
