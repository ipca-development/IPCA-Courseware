<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../src/FlightScheduleService.php';
require_once __DIR__ . '/../../src/CockpitAircraftService.php';
require_once __DIR__ . '/../../src/MissionCatalogService.php';

if (!isset($flightScheduleContext) || !is_array($flightScheduleContext)) {
    cw_require_admin();
    $flightScheduleContext = array(
        'audience' => 'admin',
        'base_path' => '/admin/schedule.php',
        'actor_type' => 'admin',
    );
} else {
    cw_require_flight_schedule_editor();
}
$scheduleBasePath = (string)($flightScheduleContext['base_path'] ?? '/admin/schedule.php');
if ($scheduleBasePath === '') {
    $scheduleBasePath = '/admin/schedule.php';
}
$scheduleActorType = (string)($flightScheduleContext['actor_type'] ?? 'admin');
if ($scheduleActorType === '') {
    $scheduleActorType = 'admin';
}
$currentUser = cw_current_user($pdo) ?: array();
$service = new FlightScheduleService($pdo);
$notice = '';
$error = '';
$undispatchCandidate = null;
$selectedDate = substr((string)($_GET['date'] ?? $_GET['from'] ?? ''), 0, 10);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    // Operational "today" is Pacific — server UTC midnight must not advance the schedule day early.
    $selectedDate = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
}
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
        } elseif ($action === 'undispatch') {
            require_once __DIR__ . '/../../src/CvrDispatchReleaseService.php';
            (new CvrDispatchReleaseService($pdo))->releaseBySchedulerRecordId(
                (string)($_POST['scheduler_record_id'] ?? ''),
                (int)($currentUser['id'] ?? 0),
                $scheduleActorType
            );
            $notice = 'Dispatch released. The reservation is available again.';
            $editId = '';
        } elseif ($action === 'reschedule') {
            $service->rescheduleSlot(
                (string)($_POST['scheduler_record_id'] ?? ''),
                (string)($_POST['scheduled_start_time'] ?? ''),
                (string)($_POST['scheduled_end_time'] ?? ''),
                (int)($currentUser['id'] ?? 0),
                (string)($_POST['expected_updated_at'] ?? ''),
                (int)($_POST['aircraft_id'] ?? 0) ?: null
            );
            $notice = 'Reservation updated.';
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
            $picResponsibilities = is_array($_POST['crew_is_pic'] ?? null)
                ? $_POST['crew_is_pic']
                : array();
            $names = is_array($_POST['crew_name'] ?? null) ? $_POST['crew_name'] : array();
            foreach ($names as $index => $name) {
                $hasPerson = (int)($userIds[$index] ?? 0) > 0 && trim((string)$name) !== '';
                $role = match ($index) {
                    0 => 'student',
                    2 => in_array((string)($roles[$index] ?? ''), array('supervising_instructor', 'observer'), true)
                        ? (string)$roles[$index]
                        : '',
                    default => in_array(
                        (string)($roles[$index] ?? ''),
                        array('instructor', 'examiner', 'pilot_monitoring', 'safety_pilot'),
                        true
                    ) ? (string)$roles[$index] : '',
                };
                $crew[] = array(
                    'user_id' => (int)($userIds[$index] ?? 0),
                    'person_name' => (string)$name,
                    'role' => $role,
                    'pilot_function' => $hasPerson ? ($index === 0 ? 'PF' : ($index === 1 ? 'PM' : 'NONE')) : 'NONE',
                    'is_pic' => $hasPerson && $index < 2
                        && (string)($picResponsibilities[$index] ?? '0') === '1',
                    'is_primary_customer' => $hasPerson && $index === 0,
                );
            }
            if ((int)($crew[0]['user_id'] ?? 0) <= 0) {
                throw new RuntimeException('Select the Customer who is responsible for this reservation.');
            }
            foreach (array(1 => 'Person 2', 2 => 'Person 3') as $index => $label) {
                if ((int)($crew[$index]['user_id'] ?? 0) > 0 && (string)($crew[$index]['role'] ?? '') === '') {
                    throw new RuntimeException($label . ' requires a capacity.');
                }
            }
            if ((string)($_POST['reservation_type'] ?? 'flight_training') === 'flight_training'
                && empty($crew[0]['is_pic']) && empty($crew[1]['is_pic'])) {
                throw new RuntimeException('Select at least one pilot who logs PIC.');
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
    $missions = (new MissionCatalogService($pdo))->listMissionsForSchedule();
    $reservationTypes = $service->reservationTypes();
    $userStatement = $pdo->query(
        "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS display_name, role"
        . " FROM users WHERE status = 'active'"
        . ' ORDER BY display_name ASC, id ASC'
    );
    $users = $userStatement ? ($userStatement->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    $students = array_values(array_filter(
        $users,
        static fn(array $row): bool => (string)($row['role'] ?? '') === 'student'
    ));
    $operationalUsers = array_values(array_filter(
        $users,
        static fn(array $row): bool => strtolower((string)($row['role'] ?? '')) !== 'admin'
    ));
    $accountRoleLabels = array(
        'instructor' => 'Instructor',
        'supervisor' => 'Instructor',
        'chief_instructor' => 'Instructor',
        'other_instructor' => 'Instructor',
    );
    $operationalUserLabel = static function (array $user) use ($accountRoleLabels): string {
        $name = (string)($user['display_name'] ?? '');
        $roleLabel = $accountRoleLabels[strtolower((string)($user['role'] ?? ''))] ?? '';
        return $roleLabel !== '' ? $name . ' (' . $roleLabel . ')' : $name;
    };
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
    $undispatchCandidate = null;
    if (is_array($editing) && empty($editing['editable'])) {
        if (!empty($editing['can_undispatch'])) {
            $undispatchCandidate = $editing;
            $editing = null;
            $editId = '';
        } else {
            $error = (string)($editing['status'] ?? '') === 'completed'
                ? 'Completed flights are locked and cannot be edited.'
                : 'This reservation is locked because Dispatch has been activated.';
            $editing = null;
            $editId = '';
        }
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
$formAirportChain = array();
if (is_array($editing) && is_array($editing['airport_chain'] ?? null)) {
    foreach ($editing['airport_chain'] as $code) {
        $code = strtoupper(trim((string)$code));
        if ($code !== '') {
            $formAirportChain[] = $code;
        }
    }
}
if (count($formAirportChain) < 2 && is_array($editing)) {
    $dep = strtoupper(trim((string)($editing['planned_departure_airport'] ?? '')));
    $arr = strtoupper(trim((string)($editing['planned_destination_airport'] ?? '')));
    $formAirportChain = array_values(array_filter(array($dep, $arr), static fn(string $c): bool => $c !== ''));
}
if (count($formAirportChain) < 2) {
    $formAirportChain = array('', '');
}

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

$today = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
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
  .fltsch-crew-row{display:grid;grid-template-columns:minmax(220px,1.35fr) minmax(220px,1fr) minmax(140px,.55fr);gap:10px;margin-top:12px;align-items:end;}
  .fltsch-pic-check{min-height:38px;display:flex;align-items:center;gap:14px;padding:0 2px;color:#0f172a;font-size:13px;font-weight:800;}
  .fltsch-pic-check input[type="checkbox"]{width:18px;height:18px;margin:0 4px 0 0;accent-color:#173f70;}
  .fltsch-muted{color:#64748b;font-size:12.5px;line-height:1.45;}
  .fltsch-kind{display:block;margin-top:4px;color:#284e85;font-size:11.5px;font-weight:800;}
  .fltsch-route{color:#64748b;font-size:12px;margin-top:3px;}
  .fltsch-actions{white-space:nowrap;}
  .fltsch-edit{height:34px!important;min-height:34px!important;padding:0 12px!important;border-radius:10px!important;}
  .fltsch-locked{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:760;}
  .fltsch-delete{margin-right:auto!important;border-color:#fecaca!important;color:#991b1b!important;}
  #flightReservationModal{width:min(860px,calc(100vw - 32px));}
  .fltsch-adsb-panel{margin-top:4px;border:1px solid rgba(15,23,42,.12);border-radius:14px;background:#f8fafc;overflow:hidden}
  .fltsch-adsb-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid rgba(15,23,42,.08)}
  .fltsch-adsb-head strong{font-size:13px;color:#0f172a}
  .fltsch-adsb-refresh{height:30px!important;min-height:30px!important;padding:0 10px!important;border-radius:8px!important;font-size:12px!important}
  .fltsch-adsb-body{padding:12px}
  .fltsch-adsb-status{margin:0 0 8px;font-size:14px;font-weight:800;color:#0f172a}
  .fltsch-adsb-status.is-airborne{color:#166534}
  .fltsch-adsb-status.is-ground{color:#92400e}
  .fltsch-adsb-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 12px;margin:0;font-size:12px;color:#334155}
  .fltsch-adsb-meta div{display:flex;justify-content:space-between;gap:8px}
  .fltsch-adsb-meta dt{color:#64748b;font-weight:700}
  .fltsch-adsb-meta dd{margin:0;font-weight:700;text-align:right}
  .fltsch-adsb-map{margin-top:10px;border:1px solid rgba(15,23,42,.1);border-radius:10px;overflow:hidden;background:#e2e8f0}
  .fltsch-adsb-map iframe{display:block;width:100%;height:180px;border:0}
  .fltsch-adsb-map-link{display:inline-block;margin-top:8px;font-size:12px;font-weight:700;color:#1d4ed8;text-decoration:none}
  .fltsch-live-track{display:grid;gap:12px}
  .fltsch-live-track .legs-track-map-wrap{position:relative}
  .fltsch-live-track .legs-track-map{height:440px;border:1px solid #dbe3ee;border-radius:12px;overflow:hidden;background:#dbe4ee}
  .fltsch-live-track .legs-track-map.is-adsb-style{background:#c9d4e0}
  .legs-track-map-controls{position:absolute;top:10px;right:10px;z-index:500;display:grid;gap:6px;justify-items:stretch;min-width:148px}
  .legs-track-center-btn,.legs-track-rings-btn{border:1px solid #cbd5e1;border-radius:9px;background:rgba(255,255,255,.94);color:#0f172a;padding:7px 10px;font-size:12px;font-weight:850;cursor:pointer;box-shadow:0 2px 8px rgba(15,23,42,.18);text-align:left}
  .legs-track-center-btn[aria-pressed="true"]{border-color:#f59e0b;background:#fffbeb;color:#92400e}
  .legs-track-center-btn[aria-pressed="false"],.legs-track-rings-btn[aria-pressed="false"]{opacity:.92}
  .legs-track-rings-btn[aria-pressed="true"]{border-color:#db2777;background:#fdf2f8;color:#9d174d}
  .legs-track-range-label{background:transparent;border:0}
  .legs-track-range-label span{display:inline-block;padding:1px 5px;border-radius:999px;background:rgba(15,23,42,.72);color:#fce7f3;font:800 10px/1.2 ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;white-space:nowrap;box-shadow:0 1px 3px rgba(15,23,42,.35)}
  .fltsch-live-track .legs-track-status{margin:0 0 8px;font-size:12px;font-weight:700;color:#64748b;line-height:1.4}
  .fltsch-live-track .legs-track-status[data-tone="ok"]{color:#166534}
  .fltsch-live-track .legs-track-status[data-tone="error"]{color:#991b1b}
  .fltsch-live-track .legs-track-status[data-tone="loading"]{color:#1d4ed8}
  .fltsch-live-track .legs-track-player{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;margin-top:10px}
  .fltsch-live-track .legs-track-play{border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#0f172a;padding:8px 12px;font-size:12px;font-weight:850;cursor:pointer;min-width:72px}
  .fltsch-live-track .legs-track-play:disabled{opacity:.45;cursor:not-allowed}
  .fltsch-live-track .legs-track-timeline{width:100%;accent-color:#f59e0b}
  .fltsch-live-track .legs-track-times{display:grid;gap:2px;justify-items:end;font-variant-numeric:tabular-nums;font-size:12px;font-weight:800;color:#334155;min-width:52px}
  .fltsch-live-track .legs-track-times span{color:#64748b;font-weight:700}
  .legs-track-profile{margin-top:12px;border:1px solid #dbe3ee;border-radius:12px;overflow:hidden;background:linear-gradient(180deg,#0f172a 0%,#1e293b 100%);color:#e2e8f0}
  .legs-track-profile-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:8px 12px 0;font-size:12px}
  .legs-track-profile-head strong{font-size:12px;font-weight:850;letter-spacing:.02em;color:#f8fafc}
  .legs-track-profile-meta{margin:0;color:#94a3b8;font-weight:700;font-variant-numeric:tabular-nums}
  .legs-track-profile-svg{display:block;width:100%;height:148px}
  .legs-track-marker{background:transparent;border:0}
  .legs-track-marker span{display:block;border-radius:999px;background:var(--mk,#334155);box-shadow:0 0 0 2px #fff,0 2px 6px rgba(15,23,42,.28)}
  .legs-track-marker.is-ownship span{border-radius:4px;transform:rotate(45deg)}
  .legs-track-marker em{display:block;margin-top:2px;font-style:normal;font-size:9px;font-weight:800;color:#0f172a;text-shadow:0 0 3px #fff;white-space:nowrap;text-align:center}
  .fltsch-live-status{display:inline-flex;align-items:center;gap:6px;color:#047857;font-size:12px;font-weight:850;white-space:nowrap}
  .fltsch-live-status::before{content:"";width:8px;height:8px;border-radius:999px;background:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.14)}
  .fltsch-live-status[data-state="updating"]{color:#1d4ed8}
  .fltsch-live-status[data-state="updating"]::before{background:#3b82f6}
  .fltsch-live-status[data-state="warning"]{color:#b45309}
  .fltsch-live-status[data-state="warning"]::before{background:#f59e0b}
  .legs-track-adsb-wrap{position:relative;width:28px;height:28px}
  .legs-track-plane{display:block;transform:rotate(var(--hdg, 0deg));filter:drop-shadow(0 1px 2px rgba(15,23,42,.45));transform-origin:50% 55%}
  .legs-track-telem{position:absolute;left:30px;top:-2px;padding:3px 7px;border-radius:4px;background:rgba(30,41,59,.88);color:#f8fafc;font:700 11px/1.25 ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;white-space:nowrap;box-shadow:0 2px 8px rgba(15,23,42,.28);pointer-events:none}
  .adsb-aircraft-symbol{position:relative;display:grid;place-items:center;transform-origin:center}
  .adsb-aircraft-symbol svg{filter:drop-shadow(0 1px 2px rgba(15,23,42,.38))}
  .adsb-aircraft-symbol-large-jet,.adsb-aircraft-symbol-military{width:42px;height:42px}
  .adsb-aircraft-symbol-business-jet{width:36px;height:36px}
  .adsb-aircraft-symbol-small-prop,.adsb-aircraft-symbol-helicopter{width:30px;height:30px}
  .adsb-aircraft-symbol-plane{display:block;transform-origin:center}
  .adsb-aircraft-label{position:absolute;left:30px;top:14px;white-space:nowrap;background:rgba(38,38,38,.72);border:0;border-radius:0;padding:2px 5px 3px;color:#fff;font-size:10px;font-weight:900;line-height:1.05;letter-spacing:-.02em;text-align:left;text-shadow:0 1px 1px #000,1px 0 1px #000,0 -1px 1px #000,-1px 0 1px #000;box-shadow:none;pointer-events:none}
  .adsb-aircraft-label strong{display:block;font-size:10px;font-weight:900}
  .adsb-aircraft-label span{display:block;font-size:10px;font-weight:900}
  .adsb-aircraft-symbol-large-jet .adsb-aircraft-label,.adsb-aircraft-symbol-military .adsb-aircraft-label{left:34px;top:18px}
  .adsb-aircraft-symbol-business-jet .adsb-aircraft-label{left:32px;top:16px}
  @media(max-width:760px){.cmpcal-form-grid,.fltsch-crew-row{grid-template-columns:1fr}.fltsch-filters,.fltsch-filters .cmpcal-field{width:100%}.fltsch-filters .compliance-btn{width:100%}.fltsch-card{padding:14px}.fltsch-adsb-meta{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="/admin/assets/flight_schedule.css?v=20260809.03">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" crossorigin="">

<section class="fltsch-card fltsch-scheduler-card">
  <div class="fltsch-day-toolbar">
    <div class="fltsch-day-nav">
      <a class="fltsch-icon-btn" href="<?= h($scheduleBasePath) ?>?date=<?= h(date('Y-m-d', strtotime($selectedDate . ' -1 day'))) ?>" aria-label="Previous day">‹</a>
      <a class="fltsch-today-btn" href="<?= h($scheduleBasePath) ?>?date=<?= h($today) ?>">Today</a>
      <a class="fltsch-icon-btn" href="<?= h($scheduleBasePath) ?>?date=<?= h(date('Y-m-d', strtotime($selectedDate . ' +1 day'))) ?>" aria-label="Next day">›</a>
    </div>
    <form method="get" class="fltsch-date-form">
      <input type="date" name="date" value="<?= h($selectedDate) ?>" aria-label="Schedule date" onchange="this.form.submit()">
    </form>
    <div class="fltsch-day-title"><?= h(date('l, F j, Y', strtotime($selectedDate))) ?></div>
    <div class="fltsch-toolbar-note">Hover for details · click reservation to open · click aircraft tail for live ADS-B · drag to move · drag edges to resize · 15-minute increments</div>
    <div class="fltsch-live-status" id="flightScheduleLiveStatus" data-state="live">LIVE · connecting…</div>
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
          <?php
            $resourceKey = (string)$resource['key'];
            $isAircraftTail = str_starts_with($resourceKey, 'device:');
            $aircraftId = $isAircraftTail ? (int)substr($resourceKey, 7) : 0;
          ?>
          <div class="fltsch-resource-row">
            <div
              class="fltsch-resource-label<?= $isAircraftTail ? ' is-aircraft-tail' : '' ?>"
              <?php if ($isAircraftTail && $aircraftId > 0): ?>
                data-aircraft-id="<?= $aircraftId ?>"
                data-aircraft-registration="<?= h((string)$resource['label']) ?>"
                role="button"
                tabindex="0"
                title="Open live ADS-B"
                aria-label="Open live ADS-B for <?= h((string)$resource['label']) ?>"
              <?php endif; ?>
            >
              <strong>
                <span class="fltsch-tail-reg"><?= h((string)$resource['label']) ?></span>
                <?php if ($isAircraftTail): ?><span class="fltsch-inflight-pill" hidden>IN-FLIGHT</span><?php endif; ?>
              </strong>
              <?php if ((string)$resource['detail'] !== ''): ?><span><?= h((string)$resource['detail']) ?></span><?php endif; ?>
            </div>
            <div class="fltsch-resource-timeline" data-resource-key="<?= h($resourceKey) ?>"></div>
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
      <label class="cmpcal-field"><span>Aircraft</span><select name="aircraft_id" id="flightReservationAircraft" required><option value="">Select aircraft</option><?php foreach ($aircraft as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['aircraft']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['registration'] . (trim((string)($row['aircraft_type'] ?? '')) !== '' ? ' — ' . (string)$row['aircraft_type'] : '')) ?></option><?php endforeach; ?></select></label>

      <div class="fltsch-crew">
        <h3 class="fltsch-crew-title">People and responsibility</h3>
        <?php $customer = is_array($formCrew[0] ?? null) ? $formCrew[0] : array(); ?>
        <div class="fltsch-crew-row">
          <label class="cmpcal-field"><span>Customer</span><select name="crew_user_id[0]" data-crew-user="0" required><option value="">Select Customer</option><?php foreach ($students as $user): ?><option value="<?= (int)$user['id'] ?>" data-name="<?= h((string)$user['display_name']) ?>" <?= (int)($customer['person_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h((string)$user['display_name']) ?></option><?php endforeach; ?></select><input type="hidden" name="crew_name[0]" id="crew_name_0" value="<?= h((string)($customer['person_name'] ?? '')) ?>"><input type="hidden" name="crew_role[0]" value="student"></label>
          <div aria-hidden="true"></div>
          <label class="fltsch-pic-check"><input type="hidden" name="crew_is_pic[0]" value="0"><input type="checkbox" name="crew_is_pic[0]" value="1" <?= !empty($customer['is_pic']) ? 'checked' : '' ?>><span>PIC</span></label>
        </div>

        <?php $personTwo = is_array($formCrew[1] ?? null) ? $formCrew[1] : array(); ?>
        <div class="fltsch-crew-row">
          <label class="cmpcal-field"><span>Person 2 (optional)</span><select name="crew_user_id[1]" data-crew-user="1"><option value="">No second pilot</option><?php foreach ($operationalUsers as $user): ?><option value="<?= (int)$user['id'] ?>" data-name="<?= h((string)$user['display_name']) ?>" <?= (int)($personTwo['person_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h($operationalUserLabel($user)) ?></option><?php endforeach; ?></select><input type="hidden" name="crew_name[1]" id="crew_name_1" value="<?= h((string)($personTwo['person_name'] ?? '')) ?>"></label>
          <label class="cmpcal-field"><span>Role</span><select name="crew_role[1]" id="crew_role_1"><option value="">Select role</option><?php foreach (array('instructor' => 'Instructor', 'pilot_monitoring' => 'Pilot Monitoring', 'safety_pilot' => 'Safety Pilot', 'examiner' => 'Examiner') as $roleValue => $roleLabel): ?><option value="<?= h($roleValue) ?>" <?= strcasecmp((string)($personTwo['role'] ?? ''), $roleValue) === 0 ? 'selected' : '' ?>><?= h($roleLabel) ?></option><?php endforeach; ?></select></label>
          <label class="fltsch-pic-check"><input type="hidden" name="crew_is_pic[1]" value="0"><input type="checkbox" name="crew_is_pic[1]" value="1" <?= !empty($personTwo['is_pic']) ? 'checked' : '' ?>><span>PIC</span></label>
        </div>

        <?php $personThree = is_array($formCrew[2] ?? null) ? $formCrew[2] : array(); ?>
        <div class="fltsch-crew-row">
          <label class="cmpcal-field"><span>Person 3 (optional)</span><select name="crew_user_id[2]" data-crew-user="2"><option value="">No supervisor or observer</option><?php foreach ($operationalUsers as $user): ?><option value="<?= (int)$user['id'] ?>" data-name="<?= h((string)$user['display_name']) ?>" <?= (int)($personThree['person_id'] ?? 0) === (int)$user['id'] ? 'selected' : '' ?>><?= h($operationalUserLabel($user)) ?></option><?php endforeach; ?></select><input type="hidden" name="crew_name[2]" id="crew_name_2" value="<?= h((string)($personThree['person_name'] ?? '')) ?>"></label>
          <label class="cmpcal-field"><span>Role</span><select name="crew_role[2]" id="crew_role_2"><option value="">Select role</option><option value="supervising_instructor" <?= (string)($personThree['role'] ?? '') === 'supervising_instructor' ? 'selected' : '' ?>>Supervising Instructor</option><option value="observer" <?= (string)($personThree['role'] ?? '') === 'observer' ? 'selected' : '' ?>>Observer</option></select></label>
        </div>
      </div>

      <?php
        $editingReservationType = (string)($editing['reservation_type'] ?? 'flight_training');
        $missionFieldRequired = MissionCatalogService::reservationTypeRequiresMission($editingReservationType);
      ?>
      <label class="cmpcal-field" id="flightReservationMissionField"<?= $missionFieldRequired ? '' : ' style="display:none"' ?>><span>Mission</span><select name="mission_id" id="flightReservationMission"<?= $missionFieldRequired ? ' required' : '' ?>><option value="">Select mission</option><?php foreach ($missions as $row): ?><?php
        $missionCategory = (string)($row['schedule_category'] ?? '');
        if ($missionCategory === '') {
            continue;
        }
      ?><option value="<?= (int)$row['id'] ?>" data-schedule-category="<?= h($missionCategory) ?>" <?= (int)($editing['mission']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['code'] . ' — ' . (string)$row['name']) ?></option><?php endforeach; ?></select></label>
      <label class="cmpcal-field"><span>Cohort (optional)</span><select name="cohort_id" id="flightReservationCohort"><option value="">No direct cohort</option><?php foreach ($cohorts as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)($editing['cohort']['id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>><?= h((string)$row['name']) ?></option><?php endforeach; ?></select></label>
      <label class="cmpcal-field"><span>Depart date</span><input type="date" name="scheduled_start_date" value="<?= h($formStartDate) ?>" required></label>
      <label class="cmpcal-field"><span>Depart time (24h)</span><input type="text" name="scheduled_start_clock" value="<?= h($formStartClock) ?>" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="14:30" inputmode="numeric" maxlength="5" autocomplete="off" title="24-hour time as HH:MM" lang="en-GB"></label>
      <label class="cmpcal-field"><span>Return date</span><input type="date" name="scheduled_end_date" value="<?= h($formEndDate) ?>" required></label>
      <label class="cmpcal-field"><span>Return time (24h)</span><input type="text" name="scheduled_end_clock" value="<?= h($formEndClock) ?>" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="16:00" inputmode="numeric" maxlength="5" autocomplete="off" title="24-hour time as HH:MM" lang="en-GB"></label>
      <div class="fltsch-crew fltsch-field-full" id="flightAirportChain">
        <h3 class="fltsch-crew-title">Route legs</h3>
        <p class="fltsch-muted" style="margin:0 0 10px">Same crew for all legs. Add legs for a continuous airport chain (arrival of leg N = departure of leg N+1).</p>
        <div id="flightAirportChainRows">
          <?php foreach ($formAirportChain as $index => $code): ?>
            <label class="cmpcal-field" style="margin-top:8px">
              <span><?= $index === 0 ? 'Departure airport' : ($index === count($formAirportChain) - 1 ? 'Final destination' : 'Via / next airport') ?></span>
              <input name="airport_chain[]" maxlength="8" value="<?= h($code) ?>" data-airport-chain-index="<?= $index ?>" <?= $index < 2 ? 'required' : '' ?>>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="compliance-btn compliance-btn--secondary" id="flightAddLegBtn">Add leg</button>
          <button type="button" class="compliance-btn compliance-btn--secondary" id="flightRemoveLegBtn">Remove last leg</button>
        </div>
        <input type="hidden" name="planned_departure_airport" id="flightLegacyDeparture" value="<?= h((string)($formAirportChain[0] ?? '')) ?>">
        <input type="hidden" name="planned_destination_airport" id="flightLegacyDestination" value="<?= h((string)($formAirportChain[count($formAirportChain) - 1] ?? '')) ?>">
      </div>
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

<?php compliance_modal_open('flightUndispatchModal', 'Undispatch reservation'); ?>
  <form method="post" id="flightUndispatchForm">
    <input type="hidden" name="action" value="undispatch">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <input type="hidden" name="scheduler_record_id" value="<?= h((string)($undispatchCandidate['scheduler_record_id'] ?? '')) ?>">
    <p class="fltsch-muted">
      This reservation was claimed by Dispatch on the CVR Unit, but no flight events, audio, or Check-In evidence exist yet.
      Undispatch releases the lock so the reservation appears again on the schedule and the device.
    </p>
    <dl class="fltsch-change-details">
      <div><dt>Aircraft</dt><dd id="flightUndispatchAircraft"><?= h((string)($undispatchCandidate['aircraft']['registration'] ?? '—')) ?></dd></div>
      <div><dt>Mission</dt><dd id="flightUndispatchMission"><?= h((string)($undispatchCandidate['mission']['code'] ?? '—')) ?></dd></div>
      <div><dt>Dispatch</dt><dd id="flightUndispatchDispatch"><?= h((string)($undispatchCandidate['claimed_dispatch_uuid'] ?? '—')) ?></dd></div>
    </dl>
    <div class="compliance-modal__footer">
      <button type="button" class="compliance-btn compliance-btn--secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" class="compliance-btn compliance-btn--primary">Undispatch</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('flightScheduleChangeModal', 'Confirm schedule change'); ?>
  <form method="post" id="flightScheduleChangeForm">
    <input type="hidden" name="action" value="reschedule">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <input type="hidden" name="scheduler_record_id" id="flightChangeRecordId">
    <input type="hidden" name="scheduled_start_time" id="flightChangeStart">
    <input type="hidden" name="scheduled_end_time" id="flightChangeEnd">
    <input type="hidden" name="aircraft_id" id="flightChangeAircraftId">
    <input type="hidden" name="expected_updated_at" id="flightChangeExpectedUpdatedAt">
    <dl class="fltsch-change-details" id="flightChangeDetails"></dl>
    <div class="fltsch-change-note">Crew, mission and cohort remain unchanged. Drag onto another aircraft row to move the reservation. Claimed Dispatch reservations cannot be moved.</div>
    <div class="compliance-modal__footer">
      <button type="button" class="compliance-btn compliance-btn--secondary" data-compliance-modal-close>Cancel</button>
      <button type="submit" class="compliance-btn compliance-btn--primary">Apply Schedule Change</button>
    </div>
  </form>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('flightCompletedModal', 'Completed flight'); ?>
  <div id="flightCompletedModalBody"></div>
  <div class="compliance-modal__footer">
    <button type="button" class="compliance-btn compliance-btn--secondary" data-compliance-modal-close>Close</button>
  </div>
<?php compliance_modal_close(); ?>

<?php compliance_modal_open('flightDispatchedModal', 'Live ADS-B'); ?>
  <div class="fltsch-live-track" id="flightDispatchedTrackRoot">
    <div id="flightDispatchedSummary"></div>
    <div class="fltsch-adsb-panel">
      <div class="fltsch-adsb-head">
        <strong>Live ADS-B</strong>
        <button type="button" class="compliance-btn compliance-btn--secondary fltsch-adsb-refresh" id="flightDispatchedAdsbRefresh">Refresh</button>
      </div>
      <div class="fltsch-adsb-body" id="flightDispatchedAdsbBody">
        <p class="fltsch-muted" style="margin:0">Checking aircraft position…</p>
      </div>
    </div>
    <div>
      <p class="legs-track-status" id="legs-track-status" data-tone="muted">Loading ADS-B track history…</p>
      <div class="legs-track-map-wrap">
        <div class="legs-track-map" id="legs-track-map" role="img" aria-label="ADS-B track map with history replay"></div>
        <div class="legs-track-map-controls">
          <button type="button" class="legs-track-center-btn" id="legs-track-center" aria-pressed="true" title="Keep map centered on the tracked airplane">Center on airplane</button>
          <button type="button" class="legs-track-rings-btn" id="legs-track-rings" aria-pressed="false" title="Show 2.5–20 NM range rings around the tracked airplane">Range rings</button>
        </div>
      </div>
      <div class="legs-track-profile" id="legs-track-profile" hidden>
        <div class="legs-track-profile-head">
          <strong>Vertical profile</strong>
          <p class="legs-track-profile-meta" id="legs-track-profile-meta">Altitude · terrain clearance</p>
        </div>
        <svg class="legs-track-profile-svg" id="legs-track-profile-svg" viewBox="0 0 720 148" preserveAspectRatio="none" role="img" aria-label="Altitude and terrain clearance profile"></svg>
      </div>
      <div class="legs-track-player">
        <button type="button" class="legs-track-play" id="legs-track-play" disabled aria-pressed="false">Play</button>
        <input class="legs-track-timeline" id="legs-track-timeline" type="range" min="0" max="1" step="0.1" value="0" disabled aria-label="Track time scrubber">
        <div class="legs-track-times">
          <strong id="legs-track-current">00:00</strong>
          <span id="legs-track-end">00:00</span>
        </div>
      </div>
    </div>
  </div>
  <div class="compliance-modal__footer">
    <button type="button" class="compliance-btn compliance-btn--secondary" data-compliance-modal-close>Close</button>
    <button type="button" class="compliance-btn compliance-btn--primary" id="flightDispatchedUndispatchBtn" hidden>Undispatch</button>
  </div>
<?php compliance_modal_close(); ?>

<script>
window.IPCAFlightSchedule = <?= json_encode(array(
    'date' => $selectedDate,
    'dayStartMinutes' => 5 * 60,
    'dayEndMinutes' => 22 * 60,
    'snapMinutes' => 15,
    'reservations' => $schedulerReservations,
    'editBaseUrl' => $scheduleBasePath . '?date=' . rawurlencode($selectedDate) . '&edit=',
    'liveReservationsUrl' => '/admin/api/schedule_reservations.php',
    'liveRefreshMilliseconds' => 5000,
    'adsbApiUrl' => '/admin/api/schedule_aircraft_adsb.php',
    'adsbTrackApiUrl' => '/admin/api/schedule_aircraft_adsb_track.php',
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.querySelectorAll('[data-crew-user]').forEach(function(select) {
  function syncCrew() {
    var option = select.options[select.selectedIndex];
    document.getElementById('crew_name_' + select.dataset.crewUser).value = option ? (option.dataset.name || '') : '';
  }
  select.addEventListener('change', syncCrew);
  syncCrew();
});
(function() {
  var form = document.getElementById('flightReservationForm');
  if (!form) return;
  var typeSelect = form.querySelector('[name="reservation_type"]');
  var missionSelect = document.getElementById('flightReservationMission');
  var missionField = document.getElementById('flightReservationMissionField');
  if (!typeSelect || !missionSelect || !missionField) return;

  var missionOptions = Array.prototype.slice.call(missionSelect.querySelectorAll('option')).filter(function(option) {
    return option.value !== '';
  });

  function syncMissionOptions() {
    var reservationType = String(typeSelect.value || '');
    var needsMission = reservationType === 'flight_training'
      || reservationType === 'briefing'
      || reservationType === 'simulator_training';
    missionField.style.display = needsMission ? '' : 'none';
    missionSelect.required = needsMission;
    if (!needsMission) {
      missionSelect.value = '';
      return;
    }
    missionOptions.forEach(function(option) {
      var show = (option.getAttribute('data-schedule-category') || '') === reservationType;
      option.hidden = !show;
      option.disabled = !show;
    });
    var selected = missionSelect.options[missionSelect.selectedIndex];
    if (!selected || selected.value === '' || selected.disabled || selected.hidden) {
      missionSelect.value = '';
    }
  }

  typeSelect.addEventListener('change', syncMissionOptions);
  syncMissionOptions();
})();
(function() {
  var rows = document.getElementById('flightAirportChainRows');
  var addBtn = document.getElementById('flightAddLegBtn');
  var removeBtn = document.getElementById('flightRemoveLegBtn');
  var legacyDep = document.getElementById('flightLegacyDeparture');
  var legacyArr = document.getElementById('flightLegacyDestination');
  if (!rows || !addBtn || !removeBtn) return;

  function inputs() {
    return Array.prototype.slice.call(rows.querySelectorAll('input[name="airport_chain[]"]'));
  }

  function relabel() {
    var fields = inputs();
    fields.forEach(function(input, index) {
      var span = input.parentElement && input.parentElement.querySelector('span');
      if (!span) return;
      if (index === 0) span.textContent = 'Departure airport';
      else if (index === fields.length - 1) span.textContent = 'Final destination';
      else span.textContent = 'Via / next airport';
      input.required = index < 2;
    });
    if (legacyDep && fields[0]) legacyDep.value = fields[0].value || '';
    if (legacyArr && fields.length) legacyArr.value = fields[fields.length - 1].value || '';
  }

  rows.addEventListener('input', relabel);

  addBtn.addEventListener('click', function() {
    var label = document.createElement('label');
    label.className = 'cmpcal-field';
    label.style.marginTop = '8px';
    label.innerHTML = '<span>Via / next airport</span><input name="airport_chain[]" maxlength="8" value="">';
    rows.appendChild(label);
    relabel();
  });

  removeBtn.addEventListener('click', function() {
    var fields = inputs();
    if (fields.length <= 2) return;
    var last = fields[fields.length - 1];
    if (last && last.parentElement) last.parentElement.remove();
    relabel();
  });

  var saveForm = document.getElementById('flightReservationForm');
  if (saveForm) {
    saveForm.addEventListener('submit', function() { relabel(); });
  }
  relabel();
})();
<?php if ($editing): ?>
(function() {
  var modal = document.getElementById('flightReservationModal');
  var returnUrl = <?= json_encode(
      $scheduleBasePath . '?date=' . rawurlencode($selectedDate),
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
<?php if (is_array($undispatchCandidate)): ?>
(function() {
  var modal = document.getElementById('flightUndispatchModal');
  var returnUrl = <?= json_encode(
      $scheduleBasePath . '?date=' . rawurlencode($selectedDate),
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;
  if (modal && typeof modal.showModal === 'function') modal.showModal();
  if (modal) modal.addEventListener('close', function() {
    if (!modal.dataset.submitting) window.location.href = returnUrl;
  });
  var form = document.getElementById('flightUndispatchForm');
  if (form) form.addEventListener('submit', function() { modal.dataset.submitting = '1'; });
})();
<?php endif; ?>
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js" crossorigin=""></script>
<script src="https://unpkg.com/@maplibre/maplibre-gl-leaflet@0.0.22/leaflet-maplibre-gl.js" crossorigin=""></script>
<script src="/admin/assets/leg_track_chart.js?v=20260808.21"></script>
<script src="/admin/assets/flight_schedule.js?v=20260809.02"></script>
<?php compliance_page_close(); ?>
<?php cw_footer(); ?>
