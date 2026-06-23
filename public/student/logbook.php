<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/flight_training/AdminLogbookService.php';

cw_require_student();

$user = cw_current_user($pdo) ?: array();
$studentUserId = cw_student_view_user_id($pdo, $user);
$service = new AdminLogbookService($pdo);
$format = strtolower(trim((string)($_GET['format'] ?? 'faa')));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $format = strtolower(trim((string)($_POST['format'] ?? $format)));
    if (!in_array($format, array('easa', 'faa'), true)) {
        $format = 'faa';
    }
    try {
        if ($action === 'add_entry') {
            $sourceType = (string)($_POST['source_type'] ?? 'student_external_flight');
            $service->saveStudentEnteredEntry($studentUserId, $sourceType, array(
                'entry_date' => $_POST['entry_date'] ?? null,
                'departure_airport' => $_POST['departure_airport'] ?? null,
                'arrival_airport' => $_POST['arrival_airport'] ?? null,
                'aircraft_type' => $_POST['aircraft_type'] ?? null,
                'aircraft_registration' => $_POST['aircraft_registration'] ?? null,
                'total_flight_time' => $_POST['total_flight_time'] ?? 0,
                'dual_received_time' => $_POST['dual_received_time'] ?? 0,
                'solo_time' => $_POST['solo_time'] ?? 0,
                'pic_time' => $_POST['pic_time'] ?? 0,
                'cross_country_time' => $_POST['cross_country_time'] ?? 0,
                'cross_country_distance_nm' => $_POST['cross_country_distance_nm'] ?? 0,
                'instrument_time' => $_POST['instrument_time'] ?? 0,
                'actual_instrument_time' => $_POST['actual_instrument_time'] ?? 0,
                'simulated_instrument_time' => $_POST['simulated_instrument_time'] ?? 0,
                'basic_instrument_flying_time' => $_POST['basic_instrument_flying_time'] ?? 0,
                'night_time' => $_POST['night_time'] ?? 0,
                'day_landings' => $_POST['day_landings'] ?? 0,
                'night_landings' => $_POST['night_landings'] ?? 0,
                'single_engine_time' => $_POST['single_engine_time'] ?? 0,
                'multi_engine_time' => $_POST['multi_engine_time'] ?? 0,
                'fnpt_simulator_time' => $_POST['fnpt_simulator_time'] ?? 0,
                'remarks' => $_POST['remarks'] ?? null,
            ));
            $notice = 'Student-entered logbook row saved as unverified.';
        } elseif ($action === 'delete_entry') {
            $service->deleteStudentEnteredEntry($studentUserId, (int)($_POST['entry_id'] ?? 0));
            $notice = 'Student-entered logbook row erased.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$workspace = $service->loadStudentWorkspace($studentUserId);
$logbookId = (int)($workspace['logbook']['id'] ?? 0);
$totals = is_array($workspace['totals'] ?? null) ? $workspace['totals'] : array();
$entries = is_array($workspace['entries'] ?? null) ? $workspace['entries'] : array();
$studentEntries = is_array($workspace['student_entries'] ?? null) ? $workspace['student_entries'] : array();

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

cw_header('My Logbook');
?>
<style>
.sl-page{max-width:1180px;margin:0 auto;padding:24px}.sl-hero{background:#0f172a;color:#fff;border-radius:22px;padding:28px;margin-bottom:20px}.sl-kicker{margin:0 0 6px;text-transform:uppercase;letter-spacing:.12em;font-size:12px;color:#93c5fd}.sl-title{margin:0;font-size:32px}.sl-subtitle{margin:8px 0 0;color:#cbd5e1}.sl-tabs{display:flex;gap:10px;margin:0 0 18px}.sl-tab{border:1px solid #cbd5e1;border-radius:999px;padding:10px 16px;text-decoration:none;color:#0f172a;background:#fff}.sl-tab.active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.sl-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:20px;margin-bottom:18px;box-shadow:0 10px 30px rgba(15,23,42,.06)}.sl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.sl-metric{border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#f8fafc}.sl-label{font-size:12px;color:#64748b}.sl-value{font-size:24px;font-weight:700;color:#0f172a;margin-top:4px}.sl-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.sl-button{border:0;border-radius:12px;background:#1d4ed8;color:#fff;padding:11px 14px;text-decoration:none;font-weight:700;cursor:pointer}.sl-button.secondary{background:#334155}.sl-note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:14px;padding:12px;margin-bottom:16px}.sl-alert{border-radius:14px;padding:12px;margin-bottom:16px}.sl-alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.sl-alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.sl-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.sl-field label{display:block;font-size:12px;color:#475569;margin-bottom:5px}.sl-field input,.sl-field select,.sl-field textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px}.sl-field.wide{grid-column:1/-1}.sl-table{width:100%;border-collapse:collapse}.sl-table th,.sl-table td{border-bottom:1px solid #e2e8f0;padding:10px;text-align:left;vertical-align:top}.sl-table th{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em}
</style>

<div class="sl-page">
  <header class="sl-hero">
    <p class="sl-kicker">Flight Training</p>
    <h1 class="sl-title">My Logbook</h1>
    <p class="sl-subtitle">Read-only official records. Only school records that were processed and accepted are shown in the printable logbook.</p>
  </header>

  <?php if ($notice !== ''): ?><div class="sl-alert ok"><?= sl_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="sl-alert err"><?= sl_h($error) ?></div><?php endif; ?>

  <nav class="sl-tabs" aria-label="Logbook format">
    <a class="sl-tab <?= $format === 'faa' ? 'active' : '' ?>" href="/student/logbook.php?format=faa" data-format-tab="faa">FAA Logging</a>
    <a class="sl-tab <?= $format === 'easa' ? 'active' : '' ?>" href="/student/logbook.php?format=easa" data-format-tab="easa">EASA Logging</a>
  </nav>

  <section class="sl-card">
    <h2><?= strtoupper(sl_h($format)) ?> Official Totals</h2>
    <p><?= count($entries) ?> accepted official logbook record<?= count($entries) === 1 ? '' : 's' ?> included.</p>
    <div class="sl-grid">
      <?php foreach ($metricRows as [$label, $key, $unit]): ?>
        <div class="sl-metric">
          <div class="sl-label"><?= sl_h($label) ?></div>
          <div class="sl-value"><?= $unit === 'count' ? sl_int($totals[$key] ?? 0) : sl_num($totals[$key] ?? 0) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="sl-actions">
      <?php if ($logbookId > 0): ?>
        <a class="sl-button" href="/student/logbook_print.php?format=<?= urlencode($format) ?>" target="_blank" rel="noopener">Open <?= strtoupper(sl_h($format)) ?> Logbook Viewer</a>
      <?php else: ?>
        <span class="sl-note">No official logbook has been created for this account yet.</span>
      <?php endif; ?>
    </div>
  </section>

  <section class="sl-card">
    <h2>Student-Entered Experience</h2>
    <div class="sl-note">Rows added here are unverified and outside IPCA’s control. They do not appear in the official printable logbook until reviewed and accepted by the school.</div>
    <form method="post">
      <input type="hidden" name="action" value="add_entry">
      <input type="hidden" name="format" value="<?= sl_h($format) ?>">
      <div class="sl-form-grid">
        <div class="sl-field"><label>Type</label><select name="source_type"><option value="student_prior_experience">Previous training / experience</option><option value="student_external_flight">Flight flown elsewhere</option></select></div>
        <div class="sl-field"><label>Date</label><input type="date" name="entry_date"></div>
        <div class="sl-field"><label>From</label><input name="departure_airport" maxlength="16"></div>
        <div class="sl-field"><label>To</label><input name="arrival_airport" maxlength="16"></div>
        <div class="sl-field"><label>Aircraft Type</label><input name="aircraft_type" maxlength="64"></div>
        <div class="sl-field"><label>Registration</label><input name="aircraft_registration" maxlength="32"></div>
        <div class="sl-field"><label>Total</label><input type="number" step="0.1" min="0" name="total_flight_time"></div>
        <div class="sl-field"><label>Dual</label><input type="number" step="0.1" min="0" name="dual_received_time"></div>
        <div class="sl-field"><label>Solo</label><input type="number" step="0.1" min="0" name="solo_time"></div>
        <div class="sl-field"><label>PIC</label><input type="number" step="0.1" min="0" name="pic_time"></div>
        <div class="sl-field"><label>XC Time</label><input type="number" step="0.1" min="0" name="cross_country_time"></div>
        <div class="sl-field"><label>XC NM</label><input type="number" step="0.1" min="0" name="cross_country_distance_nm"></div>
        <div class="sl-field"><label>Instrument</label><input type="number" step="0.1" min="0" name="instrument_time"></div>
        <div class="sl-field"><label>Actual IMC</label><input type="number" step="0.1" min="0" name="actual_instrument_time"></div>
        <div class="sl-field"><label>Simulated Inst.</label><input type="number" step="0.1" min="0" name="simulated_instrument_time"></div>
        <div class="sl-field"><label>Basic Instrument</label><input type="number" step="0.1" min="0" name="basic_instrument_flying_time"></div>
        <div class="sl-field"><label>Night</label><input type="number" step="0.1" min="0" name="night_time"></div>
        <div class="sl-field"><label>Day LDG</label><input type="number" step="1" min="0" name="day_landings"></div>
        <div class="sl-field"><label>Night LDG</label><input type="number" step="1" min="0" name="night_landings"></div>
        <div class="sl-field"><label>Single Engine</label><input type="number" step="0.1" min="0" name="single_engine_time"></div>
        <div class="sl-field"><label>Multi Engine</label><input type="number" step="0.1" min="0" name="multi_engine_time"></div>
        <div class="sl-field"><label><?= $format === 'easa' ? 'FNPT-II' : 'AATD' ?></label><input type="number" step="0.1" min="0" name="fnpt_simulator_time"></div>
        <div class="sl-field wide"><label>Remarks</label><textarea name="remarks" rows="2"></textarea></div>
      </div>
      <div class="sl-actions"><button class="sl-button secondary" type="submit">Save Unverified Row</button></div>
    </form>
  </section>

  <section class="sl-card">
    <h2>My Unverified Rows</h2>
    <?php if ($studentEntries === array()): ?>
      <p>No student-entered rows yet.</p>
    <?php else: ?>
      <table class="sl-table">
        <thead><tr><th>Date</th><th>Route</th><th>Aircraft</th><th>Total</th><th>Remarks</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($studentEntries as $entry): ?>
            <tr>
              <td><?= sl_h($entry['entry_date'] ?? '') ?></td>
              <td><?= sl_h(trim((string)($entry['departure_airport'] ?? '') . '-' . (string)($entry['arrival_airport'] ?? ''), '-')) ?></td>
              <td><?= sl_h(trim((string)($entry['aircraft_type'] ?? '') . ' ' . (string)($entry['aircraft_registration'] ?? ''))) ?></td>
              <td><?= sl_num($entry['total_flight_time'] ?? 0) ?> h</td>
              <td><?= sl_h($entry['remarks'] ?? '') ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Erase this student-entered row?');">
                  <input type="hidden" name="action" value="delete_entry">
                  <input type="hidden" name="entry_id" value="<?= (int)($entry['id'] ?? 0) ?>">
                  <input type="hidden" name="format" value="<?= sl_h($format) ?>">
                  <button class="sl-button secondary" type="submit">Erase</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<script>
(function(){
  var current = <?= json_encode($format) ?>;
  try {
    var stored = localStorage.getItem('ipca.student.logbook.format');
    if (!location.search.includes('format=') && (stored === 'faa' || stored === 'easa') && stored !== current) {
      location.replace('/student/logbook.php?format=' + encodeURIComponent(stored));
      return;
    }
    localStorage.setItem('ipca.student.logbook.format', current);
  } catch (e) {}
})();
</script>
<?php cw_footer(); ?>
