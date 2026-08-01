<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../src/FlightScheduleService.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/MissionCatalogService.php';

cw_require_admin();
$currentUser = cw_current_user($pdo) ?: array();
$service = new FlightScheduleService($pdo);
$notice = '';
$error = '';
$selectedDate = substr((string)($_GET['date'] ?? $_GET['from'] ?? date('Y-m-d')), 0, 10);
$from = $selectedDate;
$to = $selectedDate;
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
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'cancel') {
            $service->cancelSlot(
                (string)($_POST['scheduler_record_id'] ?? ''),
                (int)($currentUser['id'] ?? 0)
            );
            $notice = 'Reservation deleted.';
            $editId = '';
        } elseif ($action === 'reschedule') {
            $service->rescheduleSlot(
                (string)($_POST['scheduler_record_id'] ?? ''),
                (string)($_POST['scheduled_start_time'] ?? ''),
                (string)($_POST['scheduled_end_time'] ?? ''),
                (int)($currentUser['id'] ?? 0),
                (string)($_POST['expected_updated_at'] ?? '')
            );
            $notice = 'Reservation time updated.';
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
        . " AND status = 'active'"
        . ' ORDER BY display_name ASC, id ASC'
    );
    $users = $userStatement ? ($userStatement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    $staff = array_values(array_filter(
        $users,
        static fn(array $row): bool => in_array((string)($row['role'] ?? ''), array('instructor', 'supervisor', 'chief_instructor'), true)
    ));
    $cohortStatement = $pdo->query(
        "SELECT id, name FROM cohorts"
        . " WHERE end_date IS NULL OR end_date >= CURRENT_DATE"
        . ' ORDER BY name ASC, id ASC'
    );
    $cohorts = $cohortStatement ? ($cohortStatement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    $enrollmentStatement = $pdo->query(
        "SELECT cs.user_id, cs.cohort_id FROM cohort_students cs"
        . " WHERE cs.status = 'active'"
    );
    $cohortIdsByUser = array();
    foreach ($enrollmentStatement ? ($enrollmentStatement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array() as $row) {
        $cohortIdsByUser[(int)$row['user_id']][] = (int)$row['cohort_id'];
    }
    $editing = $editId !== ''
        ? current(array_filter($slots, static fn(array $slot): bool => (string)$slot['scheduler_record_id'] === $editId))
        : null;
    $editing = is_array($editing) ? $editing : null;
    if (is_array($editing) && empty($editing['editable'])) {
        $error = (string)($editing['status'] ?? '') === 'completed'
            ? 'Completed flights are locked and cannot be edited.'
            : 'This reservation is locked because Dispatch has been activated.';
        $editing = null;
        $editId = '';
    }
} catch (Throwable $e) {
    $slots = $slots ?? array();
    $aircraft = $aircraft ?? array();
    $missions = $missions ?? array();
    $reservationTypes = $reservationTypes ?? $service->reservationTypes();
    $users = $users ?? array();
    $staff = $staff ?? array();
    $cohorts = $cohorts ?? array();
    $cohortIdsByUser = $cohortIdsByUser ?? array();
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

$staffIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $staff), true);
$schedulerReservations = array();
foreach ($slots as $slot) {
    $resourceKeys = array('device:' . (int)($slot['aircraft']['id'] ?? 0));
    $inferredCohortIds = array();
    foreach ((array)($slot['crew'] ?? array()) as $member) {
        $personId = (int)($member['person_id'] ?? 0);
        if ($personId > 0 && isset($staffIds[$personId])) {
            $resourceKeys[] = 'staff:' . $personId;
        }
        foreach ($cohortIdsByUser[$personId] ?? array() as $cohortId) {
            $inferredCohortIds[$cohortId] = true;
        }
    }
    $directCohortId = (int)($slot['cohort']['id'] ?? 0);
    if ($directCohortId > 0) {
        $inferredCohortIds[$directCohortId] = true;
    }
    foreach (array_keys($inferredCohortIds) as $cohortId) {
        $resourceKeys[] = 'cohort:' . $cohortId;
    }
    $slot['resource_keys'] = array_values(array_unique($resourceKeys));
    $schedulerReservations[] = $slot;
}
$schedulerResources = array(
    array(
        'key' => 'devices',
        'label' => 'Devices',
        'items' => array_map(static fn(array $row): array => array(
            'key' => 'device:' . (int)$row['id'],
            'label' => (string)$row['registration'],
            'detail' => (string)($row['aircraft_type'] ?? ''),
        ), $aircraft),
    ),
    array(
        'key' => 'staff',
        'label' => 'Staff',
        'items' => array_map(static fn(array $row): array => array(
            'key' => 'staff:' . (int)$row['id'],
            'label' => (string)$row['display_name'],
            'detail' => compliance_friendly_label((string)$row['role']),
        ), $staff),
    ),
    array(
        'key' => 'cohorts',
        'label' => 'Cohorts',
        'items' => array_map(static fn(array $row): array => array(
            'key' => 'cohort:' . (int)$row['id'],
            'label' => (string)$row['name'],
            'detail' => 'Training cohort',
        ), $cohorts),
    ),
);

$today = date('Y-m-d');
$scheduledCount = count(array_filter($slots, static fn(array $slot): bool => (string)($slot['status'] ?? '') === 'scheduled'));
$completedCount = count(array_filter($slots, static fn(array $slot): bool => (string)($slot['status'] ?? '') === 'completed'));
$todayCount = count(array_filter($slots, static fn(array $slot): bool => (string)($slot['scheduled_date'] ?? '') === $today));
$lockedCount = count(array_filter($slots, static fn(array $slot): bool => (string)($slot['status'] ?? '') === 'claimed'));
$flash = $error !== ''
    ? array('type' => 'error', 'message' => $error)
    : ($notice !== '' ? array('type' => 'success', 'message' => $notice) : null);

cw_header('Flight Operations · Schedule');
compliance_page_open(array(
    'overline' => 'Flight Operations',
    'title' => 'Flight Schedule',
    'description' => 'Plan aircraft reservations and make scheduled flights available to the assigned CVR Unit.',
    'actions' => array(
        array('label' => '+ New Reservation', 'modal' => 'flightReservationModal', 'icon' => 'plus'),
    ),
    'stats' => array(
        array('label' => 'Reservations', 'value' => count($slots), 'sub' => date('D, M j', strtotime($selectedDate))),
        array('label' => 'Today', 'value' => $todayCount, 'sub' => 'scheduled for today'),
        array('label' => 'Available', 'value' => $scheduledCount, 'sub' => 'editable / dispatch ready', 'tone' => 'ok'),
        array('label' => 'Dispatch Locked', 'value' => $lockedCount, 'sub' => 'claimed by CVR Unit'),
        array('label' => 'Completed', 'value' => $completedCount, 'sub' => 'locked with flight evidence', 'tone' => 'ok'),
    ),
    'flash' => $flash,
));
?>
<style>
  .fltsch-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 4px 18px rgba(15,23,42,.05);padding:18px;}
  .fltsch-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:16px;flex-wrap:wrap;}
  .fltsch-filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;}
  .fltsch-filters .cmpcal-field{width:180px;}
  .cmpcal-field span{display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:4px;}
  .cmpcal-field input,.cmpcal-field select,.cmpcal-field textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;font:inherit;font-size:13px;background:#fff;}
  .cmpcal-field textarea{min-height:82px;resize:vertical;}
  .cmpcal-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
  .fltsch-field-full{grid-column:1/-1;}
  .fltsch-crew{grid-column:1/-1;border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#f8fafc;}
  .fltsch-crew-title{margin:0 0 10px;color:#0f172a;font-size:13px;font-weight:850;}
  .fltsch-crew-row{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(180px,.6fr);gap:10px;margin-top:9px;}
  .fltsch-muted{color:#64748b;font-size:12.5px;line-height:1.45;}
  .fltsch-kind{display:block;margin-top:4px;color:#284e85;font-size:11.5px;font-weight:800;}
  .fltsch-route{color:#64748b;font-size:12px;margin-top:3px;}
  .fltsch-actions{white-space:nowrap;}
  .fltsch-edit{height:34px!important;min-height:34px!important;padding:0 12px!important;border-radius:10px!important;}
  .fltsch-locked{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:760;}
  .fltsch-delete{margin-right:auto!important;border-color:#fecaca!important;color:#991b1b!important;}
  #flightReservationModal{width:min(860px,calc(100vw - 32px));}
  @media(max-width:760px){.cmpcal-form-grid,.fltsch-crew-row{grid-template-columns:1fr}.fltsch-filters,.fltsch-filters .cmpcal-field{width:100%}.fltsch-filters .compliance-btn{width:100%}.fltsch-card{padding:14px}}
</style>
<link rel="stylesheet" href="/admin/assets/flight_schedule.css?v=20260731">

<section class="fltsch-card fltsch-scheduler-card">
  <div class="fltsch-day-toolbar">
    <div class="fltsch-day-nav">
      <a class="fltsch-icon-btn" href="/admin/schedule.php?date=<?= h(date('Y-m-d', strtotime($selectedDate . ' -1 day'))) ?>" aria-label="Previous day">‹</a>
      <a class="fltsch-today-btn" href="/admin/schedule.php?date=<?= h($today) ?>">Today</a>
      <a class="fltsch-icon-btn" href="/admin/schedule.php?date=<?= h(date('Y-m-d', strtotime($selectedDate . ' +1 day'))) ?>" aria-label="Next day">›</a>
    </div>
    <form method="get" class="fltsch-date-form">
      <input type="date" name="date" value="<?= h($selectedDate) ?>" aria-label="Schedule date" onchange="this.form.submit()">
    </form>
    <div class="fltsch-day-title"><?= h(date('l, F j, Y', strtotime($selectedDate))) ?></div>
    <div class="fltsch-toolbar-note">Click to edit · drag to move · drag edges to resize · 15-minute increments</div>
  </div>
  <div class="fltsch-legend" aria-label="Schedule status legend">
    <span><i class="is-scheduled"></i> Scheduled · editable</span>
    <span><i class="is-dispatched"></i> Dispatched · locked</span>
    <span><i class="is-completed"></i> Completed · locked</span>
    <span class="fltsch-legend-evidence"><b>D</b> Dispatch Data <b>F</b> Flight Data <b>A</b> Audio <b>B</b> Briefing completed</span>
  </div>

  <div class="fltsch-scheduler-scroll" id="flightResourceScheduler">
    <div class="fltsch-time-header">
      <div class="fltsch-resource-heading">Resources</div>
      <div class="fltsch-time-axis" id="flightScheduleTimeAxis"></div>
    </div>
    <?php foreach ($schedulerResources as $group): ?>
      <div class="fltsch-resource-group">
        <div class="fltsch-group-title"><?= h(strtoupper((string)$group['label'])) ?></div>
        <?php if (!$group['items']): ?>
          <div class="fltsch-empty-resource">No <?= h(strtolower((string)$group['label'])) ?> available.</div>
        <?php endif; ?>
        <?php foreach ($group['items'] as $resource): ?>
          <div class="fltsch-resource-row">
            <div class="fltsch-resource-label">
              <strong><?= h((string)$resource['label']) ?></strong>
              <?php if ((string)$resource['detail'] !== ''): ?><span><?= h((string)$resource['detail']) ?></span><?php endif; ?>
            </div>
            <div class="fltsch-resource-timeline" data-resource-key="<?= h((string)$resource['key']) ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php compliance_modal_open('flightReservationModal', $editing ? 'Edit reservation' : 'New reservation'); ?>
  <form method="post" id="flightReservationForm">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <?php if ($editing): ?><input type="hidden" name="scheduler_record_id" value="<?= h((string)$editing['scheduler_record_id']) ?>"><?php endif; ?>
    <div class="cmpcal-form-grid">
      <label class="cmpcal-field"><span>Reservation type</span><select name="reservation_type" required><?php foreach ($reservationTypes as $typeValue => $typeLabel): ?><option value="<?= h($typeValue) ?>" <?= (string)($editing['reservation_type'] ?? 'flight_training') === $typeValue ? 'selected' : '' ?>><?= h($typeLabel) ?></option><?php endforeach; ?></select></label>
      <label class="cmpcal-field"><span>Aircraft</span><select name="aircraft_id" required><option value="">Select aircraft</option><?php foreach ($aircraft as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['aircraft']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['registration'] . (trim((string)($row['aircraft_type'] ?? '')) !== '' ? ' — ' . (string)$row['aircraft_type'] : '')) ?></option><?php endforeach; ?></select></label>

      <div class="fltsch-crew">
        <h3 class="fltsch-crew-title">Crew</h3>
        <?php for ($i = 0; $i < 3; $i++): ?>
          <?php $assigned = is_array($formCrew[$i] ?? null) ? $formCrew[$i] : array(); ?>
          <div class="fltsch-crew-row">
            <label class="cmpcal-field"><span>Person <?= $i + 1 ?></span><select name="crew_user_id[]" data-crew-user="<?= $i ?>"><option value="">Optional</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>" data-name="<?= h((string)$user['display_name']) ?>" data-default-role="<?= h(in_array((string)$user['role'], array('instructor','supervisor','chief_instructor'), true) ? 'instructor' : ((string)$user['role'] === 'student' ? 'student' : '')) ?>" <?= (int)($assigned['person_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h((string)$user['display_name']) ?></option><?php endforeach; ?></select><input type="hidden" name="crew_name[]" id="crew_name_<?= $i ?>" value="<?= h((string)($assigned['person_name'] ?? '')) ?>"></label>
            <label class="cmpcal-field"><span>Role</span><select name="crew_role[]" id="crew_role_<?= $i ?>"><option value="">None</option><?php foreach (array('student' => 'Student', 'instructor' => 'Instructor', 'pic' => 'PIC', 'safetyPilot' => 'Safety Pilot', 'observer' => 'Observer') as $roleValue => $roleLabel): ?><option value="<?= h($roleValue) ?>" <?= strcasecmp((string)($assigned['role'] ?? ''), $roleValue) === 0 ? 'selected' : '' ?>><?= h($roleLabel) ?></option><?php endforeach; ?></select></label>
          </div>
        <?php endfor; ?>
      </div>

      <label class="cmpcal-field"><span>Mission</span><select name="mission_id" required><option value="">Select mission</option><?php foreach ($missions as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['mission']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['code'] . ' — ' . (string)$row['name']) ?></option><?php endforeach; ?></select></label>
      <label class="cmpcal-field"><span>Cohort (optional)</span><select name="cohort_id" id="flightReservationCohort"><option value="">No direct cohort</option><?php foreach ($cohorts as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['cohort']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['name']) ?></option><?php endforeach; ?></select></label>
      <label class="cmpcal-field"><span>Depart date</span><input type="date" name="scheduled_start_date" value="<?= h($formStartDate) ?>" required></label>
      <label class="cmpcal-field"><span>Depart time</span><input type="time" name="scheduled_start_clock" value="<?= h($formStartClock) ?>" required></label>
      <label class="cmpcal-field"><span>Return date</span><input type="date" name="scheduled_end_date" value="<?= h($formEndDate) ?>" required></label>
      <label class="cmpcal-field"><span>Return time</span><input type="time" name="scheduled_end_clock" value="<?= h($formEndClock) ?>" required></label>
      <label class="cmpcal-field"><span>Departure airport</span><input name="planned_departure_airport" maxlength="8" value="<?= h((string)($editing['planned_departure_airport'] ?? '')) ?>"></label>
      <label class="cmpcal-field"><span>Destination airport</span><input name="planned_destination_airport" maxlength="8" value="<?= h((string)($editing['planned_destination_airport'] ?? '')) ?>"></label>
      <label class="cmpcal-field fltsch-field-full"><span>Public notes</span><textarea name="notes" maxlength="1000"><?= h((string)($editing['notes'] ?? '')) ?></textarea></label>
    </div>
    <div class="compliance-modal__footer">
      <?php if ($editing): ?><button class="compliance-btn compliance-btn--secondary fltsch-delete" type="submit" form="delete-reservation-form">Delete Reservation</button><?php endif; ?>
      <button type="button" class="compliance-btn compliance-btn--secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" class="compliance-btn compliance-btn--primary"><?= $editing ? 'Update Reservation' : 'Create Reservation' ?></button>
    </div>
  </form>
  <?php if ($editing): ?>
    <form method="post" id="delete-reservation-form" onsubmit="return confirm('Delete this reservation?')">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="scheduler_record_id" value="<?= h((string)$editing['scheduler_record_id']) ?>">
    </form>
  <?php endif; ?>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('flightScheduleChangeModal', 'Confirm schedule change'); ?>
  <form method="post" id="flightScheduleChangeForm">
    <input type="hidden" name="action" value="reschedule">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <input type="hidden" name="scheduler_record_id" id="flightChangeRecordId">
    <input type="hidden" name="scheduled_start_time" id="flightChangeStart">
    <input type="hidden" name="scheduled_end_time" id="flightChangeEnd">
    <input type="hidden" name="expected_updated_at" id="flightChangeExpectedUpdatedAt">
    <dl class="fltsch-change-details" id="flightChangeDetails"></dl>
    <div class="fltsch-change-note">The aircraft, crew, mission and cohort remain unchanged. Reservations already claimed by Dispatch cannot be moved.</div>
    <div class="compliance-modal__footer">
      <button type="button" class="compliance-btn compliance-btn--secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" class="compliance-btn compliance-btn--primary">Apply Schedule Change</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<script>
window.IPCAFlightSchedule = <?= json_encode(array(
    'date' => $selectedDate,
    'dayStartMinutes' => 5 * 60,
    'dayEndMinutes' => 22 * 60,
    'snapMinutes' => 15,
    'reservations' => $schedulerReservations,
    'editBaseUrl' => '/admin/schedule.php?date=' . rawurlencode($selectedDate) . '&edit=',
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
<?php if ($editing): ?>
(function() {
  var modal = document.getElementById('flightReservationModal');
  var returnUrl = <?= json_encode(
      '/admin/schedule.php?date=' . rawurlencode($selectedDate),
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;
  if (modal && typeof modal.showModal === 'function') modal.showModal();
  if (modal) modal.addEventListener('close', function() {
    if (!modal.dataset.submitting) window.location.href = returnUrl;
  });
  var saveForm = document.getElementById('flightReservationForm');
  var deleteForm = document.getElementById('delete-reservation-form');
  if (saveForm) saveForm.addEventListener('submit', function() { modal.dataset.submitting = '1'; });
  if (deleteForm) deleteForm.addEventListener('submit', function() { modal.dataset.submitting = '1'; });
})();
<?php endif; ?>
</script>
<script src="/admin/assets/flight_schedule.js?v=20260731"></script>
<?php compliance_page_close(); ?>
<?php cw_footer(); ?>
