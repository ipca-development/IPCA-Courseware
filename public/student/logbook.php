<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/flight_training/AdminLogbookService.php';

cw_require_student();

$user = cw_current_user($pdo) ?: array();
$studentUserId = cw_student_view_user_id($pdo, $user);
$service = new AdminLogbookService($pdo);
$format = strtolower(trim((string)($_GET['format'] ?? $_POST['format'] ?? 'faa')));
if (!in_array($format, array('easa', 'faa'), true)) {
    $format = 'faa';
}
$notice = '';
$error = '';

function sl_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sl_num(mixed $value, int $decimals = 1): string
{
    return number_format((float)$value, $decimals, '.', '');
}

function sl_int(mixed $value): string
{
    return (string)(int)$value;
}

function sl_metadata(array $entry): array
{
    $raw = json_decode((string)($entry['metadata_json'] ?? '{}'), true);
    return is_array($raw) ? $raw : array();
}

function sl_is_student_entered(array $entry): bool
{
    return !empty(sl_metadata($entry)['student_entered']);
}

function sl_source_type(array $entry): string
{
    $metadata = sl_metadata($entry);
    return (string)($metadata['source'] ?? $entry['import_profile'] ?? '');
}

function sl_entry_payload_from_post(): array
{
    $fields = array(
        'id', 'entry_date', 'departure_airport', 'departure_time', 'arrival_airport', 'arrival_time',
        'aircraft_registration', 'aircraft_type', 'single_engine_time', 'multi_engine_time',
        'pic_time', 'copilot_time', 'dual_received_time', 'solo_time', 'cross_country_time',
        'cross_country_distance_nm', 'day_time', 'night_time', 'instrument_time',
        'actual_instrument_time', 'simulated_instrument_time', 'basic_instrument_flying_time',
        'fnpt_simulator_time', 'day_landings', 'night_landings', 'towered_airport_landings',
        'instructor_name', 'total_flight_time', 'remarks', 'endorsements',
    );
    $entry = array();
    foreach ($fields as $field) {
        $entry[$field] = $_POST[$field] ?? '';
    }
    return $entry;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_entry' || $action === 'save_previous_experience') {
            $sourceType = $action === 'save_previous_experience'
                ? 'student_prior_experience'
                : (string)($_POST['source_type'] ?? 'student_external_flight');
            $service->saveStudentEnteredEntry($studentUserId, $sourceType, sl_entry_payload_from_post());
            $notice = $sourceType === 'student_prior_experience'
                ? 'Previous flight experience saved.'
                : 'Student-entered logbook record saved as unverified.';
        } elseif ($action === 'delete_entry') {
            $service->deleteStudentEnteredEntry($studentUserId, (int)($_POST['entry_id'] ?? 0));
            $notice = 'Student-entered logbook record erased.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$workspace = $service->loadStudentWorkspace($studentUserId);
$logbookId = (int)($workspace['logbook']['id'] ?? 0);
$totals = is_array($workspace['totals'] ?? null) ? $workspace['totals'] : array();
$officialEntries = is_array($workspace['entries'] ?? null) ? $workspace['entries'] : array();
$studentEntries = is_array($workspace['student_entries'] ?? null) ? $workspace['student_entries'] : array();
$previousExperience = null;
foreach ($studentEntries as $entry) {
    if (sl_source_type($entry) === 'student_prior_experience') {
        $previousExperience = $entry;
        break;
    }
}

$displayEntries = array_merge($officialEntries, $studentEntries);
usort($displayEntries, static function (array $a, array $b): int {
    $dateCmp = strcmp((string)($a['entry_date'] ?? ''), (string)($b['entry_date'] ?? ''));
    if ($dateCmp !== 0) {
        return $dateCmp;
    }
    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
});

$metricRows = $format === 'easa'
    ? array(
        array('Total Time', 'total_flight_time', 'h'),
        array('Instruction Received (DUAL)', 'dual_received_time', 'h'),
        array('Solo', 'solo_time', 'h'),
        array('PIC', 'pic_time', 'h'),
        array('XC Instruction', 'dual_cross_country_time', 'h'),
        array('XC Solo/PIC', 'pic_cross_country_time', 'h'),
        array('Basic Instrument', 'basic_instrument_flying_time', 'h'),
        array('Instrument Time', 'instrument_time', 'h'),
        array('Night Instruction', 'night_time', 'h'),
        array('Night Solo/PIC', 'solo_time', 'h'),
        array('Day Landings', 'day_landings', 'count'),
        array('Night Landings', 'night_landings', 'count'),
        array('Single Engine Class', 'single_engine_time', 'h'),
        array('Multi-Engine Class', 'multi_engine_time', 'h'),
        array('FNPT-II (sim)', 'fnpt_simulator_time', 'h'),
    )
    : array(
        array('Total Time', 'total_flight_time', 'h'),
        array('Instruction Received (DUAL)', 'dual_received_time', 'h'),
        array('Solo', 'solo_time', 'h'),
        array('PIC', 'pic_time', 'h'),
        array('XC Instruction', 'dual_cross_country_time', 'h'),
        array('XC Solo/PIC', 'pic_cross_country_time', 'h'),
        array('Instrument', 'instrument_time', 'h'),
        array('Night Instruction', 'night_time', 'h'),
        array('Night Solo/PIC', 'solo_time', 'h'),
        array('Day Landings', 'day_landings', 'count'),
        array('Night Landings', 'night_landings', 'count'),
        array('Single Engine Class', 'single_engine_time', 'h'),
        array('Multi-Engine Class', 'multi_engine_time', 'h'),
        array('AATD (sim)', 'fnpt_simulator_time', 'h'),
    );

$displayName = trim((string)($user['name'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
}
if ($displayName === '') {
    $displayName = 'My Logbook';
}

$entriesJson = json_encode($displayEntries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$previousJson = json_encode($previousExperience ?: array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

cw_header('My Logbook');
?>
<style>
.student-logbook-page{display:block}.student-logbook-page .app-section-hero{margin-bottom:20px}
.sp-back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;color:rgba(255,255,255,.86);text-decoration:none;font-size:13px;font-weight:650}.sp-back-link:hover{color:#fff}
.sp-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px}.sp-title{margin:0;font-size:34px;line-height:1.02;letter-spacing:-.04em;font-weight:760;color:#fff}.sp-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.sp-flash{padding:14px 16px;border-radius:16px;margin-bottom:18px;font-size:14px;font-weight:620}.sp-flash--success{background:rgba(32,135,90,.09);color:#1f7a54;border:1px solid rgba(32,135,90,.14)}.sp-flash--error{background:rgba(185,54,54,.09);color:#ac2f2f;border:1px solid rgba(185,54,54,.14)}
.sp-tabs-card{padding:10px}.sp-tabs{display:flex;gap:8px;flex-wrap:wrap}.sp-tab{min-height:42px;padding:0 14px}.sp-grid{display:grid;grid-template-columns:1fr;gap:18px;margin-top:18px;align-items:start}.sp-card{padding:22px}.sp-card-title{display:flex;align-items:center;gap:10px;margin:0 0 16px 0;font-size:18px;font-weight:740;letter-spacing:-.02em;color:var(--text-strong)}
.sp-actions-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.sp-btn{min-height:42px;padding:0 16px;border-radius:12px}.sp-help{color:var(--text-muted);font-size:13px;line-height:1.6}
.sl-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.sl-card-mini{padding:13px 14px;border-radius:17px;background:#fff;border:1px solid rgba(15,23,42,.08);box-shadow:0 8px 20px rgba(15,23,42,.05)}.sl-card-label{font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748b}.sl-card-value{margin-top:4px;font-size:20px;font-weight:900;color:#102845}
.sl-note{padding:14px 16px;border-radius:16px;border:1px solid rgba(196,118,11,.14);background:rgba(196,118,11,.06);color:#8f5a07;font-size:13px;line-height:1.55;margin:0 0 14px}
.alogw-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}.alogw-btn--primary{background:#1d4f89;color:#fff}.alogw-btn--secondary{background:#eef2ff;color:#1e3a8a}.alogw-btn--ghost{background:#fff;color:#334155;border:1px solid rgba(15,23,42,.12)}.alogw-btn--danger{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.alogw-panel{border:1px solid rgba(15,23,42,.08);border-radius:20px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden}.alogw-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(15,23,42,.07)}.alogw-panel-title{margin:0;font-size:15px;color:#102845}.alogw-panel-text{margin:3px 0 0;color:#64748b;font-size:12px}
.alogw-grid-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(15,23,42,.07);background:#fff}.alogw-status{font-size:12px;font-weight:900;color:#475569;background:#e2e8f0;border-radius:999px;padding:7px 10px}
.alogw-table-wrap{overflow-x:auto;overflow-y:auto;max-height:720px;background:#fff}.alogw-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;min-width:1180px;background:#fff}.alogw-table th{position:sticky;top:0;z-index:3;padding:6px 6px 6px 3px;background:#f8fafc;border-right:1px solid #e2e8f0;border-bottom:1px solid #cbd5e1;font-size:8px;line-height:1;white-space:nowrap;text-transform:uppercase;letter-spacing:0;color:#475569;text-align:center;overflow:hidden;text-overflow:ellipsis}.alogw-table td{padding:5px 3px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;vertical-align:middle;background:#fff;font-size:9px;line-height:1.1;color:#102845;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center}.alogw-table td.alogw-num{font-variant-numeric:tabular-nums}.alogw-table tr:nth-child(even) td{background:#f8fafc}.alogw-table tfoot th,.alogw-table tfoot td{position:sticky;z-index:2;box-sizing:border-box;height:24px;min-height:24px;max-height:24px;padding:5px 3px;border-right:1px solid #cbd5e1;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:8px;line-height:14px;background:#eef2ff;color:#1e3a8a}.alogw-table tfoot th{bottom:24px;border-top:1px solid #cbd5e1;border-bottom:1px solid #cbd5e1;text-transform:uppercase;font-weight:900}.alogw-table tfoot td{bottom:0;border-bottom:0;font-size:9px;font-weight:900;font-variant-numeric:tabular-nums}.alogw-row-actions{display:flex;gap:4px;justify-content:center}.alogw-mini-btn{border:0;border-radius:999px;padding:3px 6px;font-size:8px;font-weight:900;cursor:pointer}.alogw-mini-btn--edit{background:#eef2ff;color:#1e3a8a}.alogw-mini-btn--delete{background:#fff1f2;color:#be123c}.alogw-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 6px;font-size:8px;font-weight:900;text-transform:uppercase;background:#e2e8f0;color:#475569}.alogw-status-pill--accepted,.alogw-status-pill--ok,.alogw-status-pill--merged,.alogw-status-pill--split{background:#dcfce7;color:#166534}.alogw-status-pill--needs_review{background:#fef3c7;color:#92400e}
.alogw-modal{position:fixed;inset:0;z-index:9999;display:none;background:rgba(15,23,42,.62);padding:18px}.alogw-modal.is-open{display:flex;align-items:center;justify-content:center}.alogw-modal-card{width:min(1100px,96vw);height:min(760px,92vh);border-radius:22px;background:#fff;box-shadow:0 28px 80px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden}.alogw-modal-head{flex:0 0 auto;display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid rgba(15,23,42,.08)}.alogw-modal-card form{min-height:0;display:flex;flex-direction:column;flex:1 1 auto}.alogw-edit-body{flex:1 1 auto;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:10px 14px;overflow:auto;background:#fff}.alogw-edit-field{display:flex;flex-direction:column;gap:3px}.alogw-edit-field--wide{grid-column:1/-1}.alogw-edit-label{font-size:9px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;color:#64748b}.alogw-edit-input,.alogw-edit-textarea,.alogw-edit-select{border:1px solid rgba(15,23,42,.13);border-radius:9px;padding:6px 8px;font:inherit;font-size:12px;color:#102845;background:#fff}.alogw-edit-textarea{min-height:52px;resize:vertical}.alogw-edit-actions{flex:0 0 auto;display:flex;gap:8px;justify-content:flex-end;padding:10px 14px;border-top:1px solid rgba(15,23,42,.08);background:#f8fafc}
@media(max-width:900px){.sp-header{flex-direction:column}.alogw-edit-body{grid-template-columns:1fr 1fr}.sp-title{font-size:28px}}@media(max-width:620px){.alogw-edit-body{grid-template-columns:1fr}}
</style>

<div class="student-logbook-page" id="studentLogbook" data-format="<?= sl_h($format) ?>">
  <?php if ($notice !== ''): ?><div class="sp-flash sp-flash--success"><?= sl_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="sp-flash sp-flash--error"><?= sl_h($error) ?></div><?php endif; ?>

  <section class="app-section-hero">
    <a class="sp-back-link" href="/student/dashboard.php"><span>Back to Dashboard</span></a>
    <div class="hero-overline">Student · Flight Training</div>
    <div class="sp-header">
      <div>
        <h2 class="sp-title">My Logbook</h2>
        <div class="sp-meta">
          <span class="app-badge app-badge-success"><?= count($officialEntries) ?> accepted school record<?= count($officialEntries) === 1 ? '' : 's' ?></span>
          <span class="app-badge app-badge-warn"><?= count($studentEntries) ?> student-entered unverified row<?= count($studentEntries) === 1 ? '' : 's' ?></span>
        </div>
      </div>
      <div class="sp-actions-row" style="margin-top:0">
        <button class="app-btn app-btn-primary sp-btn" type="button" id="slAddRecord">Add Record</button>
        <button class="app-btn app-btn-secondary sp-btn" type="button" id="slPreviousExperience">My Previous Flight Experience</button>
        <?php if ($logbookId > 0): ?>
          <a class="app-btn app-btn-secondary sp-btn" href="/student/logbook_print.php?format=<?= urlencode($format) ?>" target="_blank" rel="noopener">Open <?= strtoupper(sl_h($format)) ?> Viewer</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="card sp-tabs-card">
    <nav class="sp-tabs" aria-label="Logbook format">
      <a class="app-tab-pill sp-tab<?= $format === 'faa' ? ' is-active' : '' ?>" href="/student/logbook.php?format=faa" data-format-tab="faa">FAA Logging</a>
      <a class="app-tab-pill sp-tab<?= $format === 'easa' ? ' is-active' : '' ?>" href="/student/logbook.php?format=easa" data-format-tab="easa">EASA Logging</a>
    </nav>
  </section>

  <div class="sp-grid">
    <section class="card sp-card">
      <h3 class="sp-card-title"><span><?= strtoupper(sl_h($format)) ?> Official Totals</span></h3>
      <div class="sl-cards">
        <?php foreach ($metricRows as [$label, $key, $unit]): ?>
          <div class="sl-card-mini">
            <div class="sl-card-label"><?= sl_h($label) ?></div>
            <div class="sl-card-value"><?= $unit === 'count' ? sl_int($totals[$key] ?? 0) : sl_num($totals[$key] ?? 0) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="alogw-panel">
      <div class="alogw-panel-head">
        <div>
          <h2 class="alogw-panel-title">Electronic Logbook</h2>
          <p class="alogw-panel-text">School records are read-only. Student-entered records are unverified and can be edited or erased by you.</p>
        </div>
        <span class="alogw-status" id="slStatus">Ready</span>
      </div>
      <div class="alogw-grid-tools">
        <button class="alogw-btn alogw-btn--primary" type="button" id="slAddRecordInline">Add Record</button>
        <button class="alogw-btn alogw-btn--secondary" type="button" id="slPreviousExperienceInline">My Previous Flight Experience</button>
        <span class="sp-help">Official school rows cannot be changed from this page.</span>
      </div>
      <div class="alogw-table-wrap">
        <table class="alogw-table">
          <thead id="slTableHead"></thead>
          <tbody id="slTableBody"></tbody>
        </table>
      </div>
    </section>
  </div>
</div>

<div class="alogw-modal" id="slEditModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title" id="slEditTitle">Add Logbook Record</h2>
        <p class="alogw-panel-text">Student-entered records remain unverified until reviewed by the school.</p>
      </div>
      <button class="alogw-btn alogw-btn--danger" type="button" data-close-modal>Cancel</button>
    </div>
    <form method="post" id="slEditForm">
      <input type="hidden" name="action" value="save_entry">
      <input type="hidden" name="format" value="<?= sl_h($format) ?>">
      <input type="hidden" name="id" value="">
      <input type="hidden" name="source_type" value="student_external_flight">
      <div class="alogw-edit-body" id="slEditFields"></div>
      <div class="alogw-edit-actions">
        <button class="app-btn app-btn-primary sp-btn" type="submit">Save Record</button>
      </div>
    </form>
  </div>
</div>

<div class="alogw-modal" id="slPreviousModal" aria-hidden="true">
  <div class="alogw-modal-card">
    <div class="alogw-modal-head">
      <div>
        <h2 class="alogw-panel-title">My Previous Flight Experience</h2>
        <p class="alogw-panel-text">Enter totals before starting flight training at IPCA. This remains student-entered and unverified.</p>
      </div>
      <button class="alogw-btn alogw-btn--danger" type="button" data-close-modal>Cancel</button>
    </div>
    <form method="post" id="slPreviousForm">
      <input type="hidden" name="action" value="save_previous_experience">
      <input type="hidden" name="format" value="<?= sl_h($format) ?>">
      <input type="hidden" name="id" value="">
      <input type="hidden" name="source_type" value="student_prior_experience">
      <input type="hidden" name="remarks" value="Previous flight experience before IPCA training">
      <div class="alogw-edit-body" id="slPreviousFields"></div>
      <div class="alogw-edit-actions">
        <button class="app-btn app-btn-primary sp-btn" type="submit">Save Previous Experience</button>
      </div>
    </form>
  </div>
</div>

<script>
window.IPCA_STUDENT_LOGBOOK_ENTRIES = <?= $entriesJson ?: '[]' ?>;
window.IPCA_STUDENT_PREVIOUS_EXPERIENCE = <?= $previousJson ?: '{}' ?>;
(function(){
  const entries = Array.isArray(window.IPCA_STUDENT_LOGBOOK_ENTRIES) ? window.IPCA_STUDENT_LOGBOOK_ENTRIES : [];
  const previous = window.IPCA_STUDENT_PREVIOUS_EXPERIENCE || {};
  const root = document.getElementById('studentLogbook');
  const currentFormat = root ? root.dataset.format : 'faa';
  const cols = [
    ['actions','Actions'],['entry_date','Date'],['departure_airport','DEP AD'],['departure_time','DEP T'],['arrival_airport','ARR AD'],['arrival_time','ARR T'],
    ['aircraft_registration','AC REG'],['aircraft_type','AC TYP'],['single_engine_time','SE'],['multi_engine_time','ME'],
    ['pic_time','PIC'],['copilot_time','Co-Pilot'],['dual_received_time','Dual'],['solo_time','Solo'],['cross_country_time','XC'],
    ['cross_country_distance_nm','XC NM'],['day_time','DAY'],['night_time','NIGHT'],['instrument_time','INSTR'],['basic_instrument_flying_time','B INSTR'],
    ['fnpt_simulator_time','FSTD'],['day_landings','LD D'],['night_landings','LD N'],['instructor_name','Instructor'],['total_flight_time','Total'],['remarks','Remarks'],['review_status','Status']
  ];
  const widths = {actions:62,entry_date:68,departure_airport:54,departure_time:42,arrival_airport:54,arrival_time:42,aircraft_registration:62,aircraft_type:58,single_engine_time:36,multi_engine_time:36,pic_time:38,copilot_time:44,dual_received_time:40,solo_time:40,cross_country_time:36,cross_country_distance_nm:46,day_time:40,night_time:44,instrument_time:44,basic_instrument_flying_time:50,fnpt_simulator_time:46,day_landings:40,night_landings:40,instructor_name:78,total_flight_time:46,remarks:120,review_status:66};
  const editFields = [
    ['entry_date','Date','date'],['departure_airport','Departure','text'],['departure_time','Departure Time','time'],['arrival_airport','Arrival','text'],['arrival_time','Arrival Time','time'],
    ['aircraft_registration','Aircraft Registration / Device','text'],['aircraft_type','Aircraft Type','text'],['single_engine_time','SE Time','number'],['multi_engine_time','ME Time','number'],
    ['pic_time','PIC','number'],['copilot_time','Co-Pilot','number'],['dual_received_time','Dual','number'],['solo_time','Solo','number'],['cross_country_time','Cross Country','number'],
    ['cross_country_distance_nm','Cross Country NM','number'],['night_time','Night','number'],['instrument_time','Instrument','number'],['actual_instrument_time','Actual Instrument','number'],
    ['simulated_instrument_time','Simulated Instrument','number'],['basic_instrument_flying_time','Basic Instrument Flying','number'],['fnpt_simulator_time', currentFormat === 'easa' ? 'FNPT-II' : 'AATD','number'],
    ['day_time','Day Time','number'],['day_landings','Day Landings','number'],['night_landings','Night Landings','number'],['towered_airport_landings','Towered Landings','number'],['instructor_name','Instructor','text'],
    ['total_flight_time','Total Time','number'],['remarks','Remarks','textarea'],['endorsements','Endorsements','textarea']
  ];
  const previousFields = [
    ['total_flight_time','Total Time','number'],['dual_received_time','Instruction Received / Dual','number'],['solo_time','Solo','number'],['pic_time','PIC','number'],
    ['cross_country_time','Cross Country Time','number'],['cross_country_distance_nm','Cross Country NM','number'],['instrument_time','Instrument','number'],['basic_instrument_flying_time','Basic Instrument','number'],
    ['night_time','Night','number'],['single_engine_time','Single Engine','number'],['multi_engine_time','Multi Engine','number'],['fnpt_simulator_time', currentFormat === 'easa' ? 'FNPT-II' : 'AATD','number'],
    ['day_landings','Day Landings','number'],['night_landings','Night Landings','number']
  ];
  const numeric = new Set(['single_engine_time','multi_engine_time','pic_time','copilot_time','dual_received_time','solo_time','cross_country_time','cross_country_distance_nm','day_time','night_time','instrument_time','actual_instrument_time','simulated_instrument_time','basic_instrument_flying_time','fnpt_simulator_time','day_landings','night_landings','towered_airport_landings','total_flight_time']);
  function esc(value){ return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch])); }
  function metadata(row){ try { return typeof row.metadata_json === 'string' ? JSON.parse(row.metadata_json || '{}') : (row.metadata_json || {}); } catch(e){ return {}; } }
  function isStudentRow(row){ return !!metadata(row).student_entered; }
  function sourceType(row){ return metadata(row).source || row.import_profile || ''; }
  function displayValue(value){ if(value === null || value === undefined || value === '') return ''; if(!Number.isNaN(Number(value)) && Number(value) === 0) return ''; return String(value); }
  function cell(row,i,key){
    if(key === 'actions') {
      if(!isStudentRow(row)) return '<td><span class="sp-help">Read only</span></td>';
      return `<td><div class="alogw-row-actions"><button class="alogw-mini-btn alogw-mini-btn--edit" type="button" data-edit="${i}">Edit</button><form method="post" onsubmit="return confirm('Erase this student-entered record?');"><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="format" value="${esc(currentFormat)}"><input type="hidden" name="entry_id" value="${esc(row.id || '')}"><button class="alogw-mini-btn alogw-mini-btn--delete" type="submit">Erase</button></form></div></td>`;
    }
    if(key === 'review_status') {
      const label = isStudentRow(row) ? 'student unverified' : String(row.review_status || 'accepted').replace('_',' ');
      const cls = isStudentRow(row) ? 'needs_review' : String(row.review_status || 'accepted');
      return `<td><span class="alogw-status-pill alogw-status-pill--${esc(cls)}">${esc(label)}</span></td>`;
    }
    const value = key === 'remarks' && sourceType(row) === 'student_prior_experience' ? 'Previous flight experience' : displayValue(row[key]);
    return `<td${numeric.has(key) ? ' class="alogw-num"' : ''} title="${esc(value)}">${esc(value)}</td>`;
  }
  function renderTable(){
    const table = document.querySelector('.alogw-table');
    table.insertAdjacentHTML('afterbegin', '<colgroup>' + cols.map(([key]) => `<col style="width:${widths[key] || 48}px">`).join('') + '</colgroup>');
    document.getElementById('slTableHead').innerHTML = '<tr>' + cols.map(([,label]) => `<th>${esc(label)}</th>`).join('') + '</tr>';
    document.getElementById('slTableBody').innerHTML = entries.map((row,i) => '<tr>' + cols.map(([key]) => cell(row,i,key)).join('') + '</tr>').join('');
    table.insertAdjacentHTML('beforeend', renderFooter());
  }
  function renderFooter(){
    const totals = {};
    numeric.forEach(key => totals[key] = 0);
    entries.forEach(row => numeric.forEach(key => { const n = Number(row[key] || 0); if(!Number.isNaN(n)) totals[key] += n; }));
    const labelRow = '<tr>' + cols.map(([,label]) => `<th>${esc(label)}</th>`).join('') + '</tr>';
    const valueRow = '<tr>' + cols.map(([key]) => `<td>${key === 'entry_date' ? 'Totals' : (numeric.has(key) ? esc((totals[key] || 0).toFixed(['day_landings','night_landings','towered_airport_landings'].includes(key) ? 0 : 2)) : '')}</td>`).join('') + '</tr>';
    return '<tfoot>' + labelRow + valueRow + '</tfoot>';
  }
  function field(row,key,label,type){
    const value = row[key] ?? '';
    if(type === 'textarea') return `<label class="alogw-edit-field alogw-edit-field--wide"><span class="alogw-edit-label">${esc(label)}</span><textarea class="alogw-edit-textarea" name="${esc(key)}">${esc(value)}</textarea></label>`;
    return `<label class="alogw-edit-field"><span class="alogw-edit-label">${esc(label)}</span><input class="alogw-edit-input" type="${esc(type)}"${type === 'number' ? ' step="0.01" min="0"' : ''} name="${esc(key)}" value="${esc(value)}"></label>`;
  }
  function openModal(id){ const modal = document.getElementById(id); modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); }
  function closeModal(modal){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); }
  function openEdit(row){
    const editable = row || {};
    document.getElementById('slEditTitle').textContent = editable.id ? 'Edit Student-Entered Record' : 'Add Logbook Record';
    document.querySelector('#slEditForm input[name=id]').value = editable.id || '';
    document.querySelector('#slEditForm input[name=source_type]').value = sourceType(editable) === 'student_prior_experience' ? 'student_prior_experience' : 'student_external_flight';
    document.getElementById('slEditFields').innerHTML = editFields.map(([key,label,type]) => field(editable,key,label,type)).join('');
    openModal('slEditModal');
  }
  function openPrevious(){
    document.querySelector('#slPreviousForm input[name=id]').value = previous.id || '';
    document.getElementById('slPreviousFields').innerHTML = previousFields.map(([key,label,type]) => field(previous,key,label,type)).join('');
    openModal('slPreviousModal');
  }
  document.getElementById('slAddRecord').addEventListener('click', () => openEdit({review_status:'needs_review'}));
  document.getElementById('slAddRecordInline').addEventListener('click', () => openEdit({review_status:'needs_review'}));
  document.getElementById('slPreviousExperience').addEventListener('click', openPrevious);
  document.getElementById('slPreviousExperienceInline').addEventListener('click', openPrevious);
  document.getElementById('slTableBody').addEventListener('click', e => {
    const btn = e.target.closest('[data-edit]');
    if(!btn) return;
    const row = entries[Number(btn.dataset.edit)] || {};
    if(!isStudentRow(row)) return;
    openEdit(row);
  });
  document.querySelectorAll('[data-close-modal]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.closest('.alogw-modal'))));
  document.querySelectorAll('.alogw-modal').forEach(modal => modal.addEventListener('click', e => { if(e.target === modal) closeModal(modal); }));
  try {
    const stored = localStorage.getItem('ipca.student.logbook.format');
    if (!location.search.includes('format=') && (stored === 'faa' || stored === 'easa') && stored !== currentFormat) {
      location.replace('/student/logbook.php?format=' + encodeURIComponent(stored));
      return;
    }
    localStorage.setItem('ipca.student.logbook.format', currentFormat);
  } catch (e) {}
  renderTable();
})();
</script>
<?php cw_footer(); ?>
