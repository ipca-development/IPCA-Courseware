<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/safety/SafetyKernel.php';

cw_require_login();
$user = cw_current_user($pdo);
if (!is_array($user) || strtolower((string)($user['role'] ?? '')) !== 'student') {
    http_response_code(403);
    cw_header('Safety Reporting');
    echo '<div class="card" style="padding:24px">Safety reports are private to the signed-in reporter and cannot be opened in interface preview mode.</div>';
    cw_footer();
    exit;
}
$session = array('user' => $user + array('organization_id' => 1));
$kernel = new SafetyKernel($pdo);
if (empty($_SESSION['student_safety_csrf'])) {
    $_SESSION['student_safety_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['student_safety_csrf'];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new SafetyException('csrf_failed', 'Your session expired. Refresh and try again.', 403);
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'submit_report') {
            $flightSelection = (string)($_POST['flight_selection'] ?? '');
            if ($flightSelection === 'no_reservation') {
                $_POST['flight_link_choice'] = 'no_reservation';
                unset($_POST['schedule_slot_id']);
            } elseif (preg_match('/^slot:(\d+)$/', $flightSelection, $match) === 1) {
                $_POST['flight_link_choice'] = 'scheduled_flight';
                $_POST['schedule_slot_id'] = (int)$match[1];
            } else {
                throw new SafetyException('flight_link_choice_invalid', 'Select a flight or choose no reservation.', 400);
            }
            $created = $kernel->intake->create($session, $_POST, bin2hex(random_bytes(16)));
            $kernel->intake->submitOwn($session, (string)$created['report_uuid']);
            $flash = array('type' => 'ok', 'message' => 'Your safety report was submitted. The Safety team can now review it.');
        } elseif ($action === 'feedback') {
            $kernel->intake->postReporterUpdate(
                $session,
                (string)($_POST['report_uuid'] ?? ''),
                (string)($_POST['body'] ?? '')
            );
            $flash = array('type' => 'ok', 'message' => 'Your message was added to the confidential report thread.');
        }
    } catch (SafetyException $e) {
        $flash = array('type' => 'error', 'message' => $e->getMessage());
    }
}

$reports = array();
$selected = null;
$occurrenceTypes = array();
$flightCandidates = array();
try {
    $occurrenceTypes = $kernel->occurrenceIntakeContext->occurrenceTypes(1);
    $flightCandidates = $kernel->occurrenceIntakeContext->flightCandidates($session);
    $reports = $kernel->intake->listOwn($session);
    $selectedUuid = trim((string)($_GET['report_uuid'] ?? ''));
    if ($selectedUuid !== '') {
        $selected = $kernel->intake->detailOwn($session, $selectedUuid);
    }
} catch (SafetyException $e) {
    $flash = array('type' => 'error', 'message' => $e->getMessage());
}

function student_safety_status(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

cw_header('Safety Reporting');
?>
<style>
.ss{display:grid;gap:18px}.ss-hero{padding:25px 27px;border-radius:20px;background:linear-gradient(135deg,#12345b,#2e6eaa);color:#fff}.ss-hero h2{font-size:29px;margin:7px 0}.ss-hero p{color:#dceafa;max-width:800px;line-height:1.55;margin:0}.ss-over{font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:800;opacity:.75}
.ss-grid{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.15fr);gap:18px}.ss-card{background:#fff;border:1px solid #dce5ef;border-radius:17px;padding:21px;box-shadow:0 8px 24px rgba(15,35,60,.06)}.ss-card h3{margin:0 0 5px;color:#17263a}.ss-sub{font-size:13px;color:#65768b;line-height:1.5}
.ss-form{display:grid;gap:11px;margin-top:15px}.ss-form label{display:grid;gap:5px;font-size:12px;font-weight:750;color:#45566c}.ss-form label[hidden]{display:none}.ss-input,.ss-textarea,.ss-select{box-sizing:border-box;width:100%;border:1px solid #cfdbe8;border-radius:10px;padding:10px 11px;font:inherit}.ss-textarea{min-height:105px;resize:vertical}.ss-btn{border:0;border-radius:10px;background:#163a65;color:#fff;padding:11px 14px;font-weight:780;cursor:pointer;text-decoration:none;display:inline-block}.ss-btn.secondary{background:#edf3f9;color:#163a65}
.ss-flight{display:flex!important;grid-template-columns:auto 1fr!important;align-items:start;gap:10px!important;padding:11px;border:1px solid #dce5ef;border-radius:11px}.ss-flight input{margin-top:3px}.ss-flight span{display:grid;gap:3px}.ss-flight small{font-weight:500;color:#65768b}
.ss-report{display:block;text-decoration:none;color:inherit;border:1px solid #e0e8f1;border-radius:13px;padding:13px;margin-top:10px}.ss-report:hover{background:#f7faff}.ss-head{display:flex;justify-content:space-between;gap:12px}.ss-pill{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;background:#e5effa;color:#284f77;border-radius:999px;padding:5px 8px;white-space:nowrap}.ss-message{margin-top:10px;padding:12px;border-radius:12px;background:#edf4fb}.ss-message.from_reporter{background:#fff7e8}.ss-flash{padding:12px 14px;border-radius:11px}.ss-flash.ok{background:#dff5e9;color:#195b38}.ss-flash.error{background:#fee9e6;color:#8c2c25}.ss-privacy{padding:13px;border-radius:12px;background:#f3f7fb;color:#43556c;font-size:13px;line-height:1.55}
@media(max-width:850px){.ss-grid{grid-template-columns:1fr}}
</style>

<div class="ss">
  <section class="ss-hero">
    <div class="ss-over">Confidential Safety Channel</div>
    <h2>Report, track and stay informed</h2>
    <p>Submit a safety concern directly to the Safety team, follow its progress and exchange feedback in one private report thread. For anonymous reporting, use the anonymous channel without signing in.</p>
  </section>
  <?php if ($flash): ?><div class="ss-flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="ss-grid">
    <div class="ss">
      <section class="ss-card">
        <h3>Submit a safety report</h3>
        <div class="ss-sub">Provide factual detail. You can describe immediate actions already taken.</div>
        <form method="post" class="ss-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="submit_report">
          <input type="hidden" name="event_at_utc" value="<?= h(gmdate('Y-m-d\TH:i:s\Z')) ?>">
          <strong>Flight</strong>
          <?php foreach (array_slice($flightCandidates, 0, 4) as $flight): ?>
            <label class="ss-flight">
              <input type="radio" name="flight_selection" value="slot:<?= (int)$flight['schedule_slot_id'] ?>" required>
              <span>
                <strong><?= h((string)$flight['aircraft_registration']) ?> · <?= h((string)($flight['mission_code'] ?: $flight['mission_name'])) ?></strong>
                <small><?= h(substr((string)$flight['scheduled_start_time'], 0, 16)) ?>–<?= h(substr((string)$flight['scheduled_end_time'], 11, 5)) ?> · <?= h((string)$flight['departure_airport']) ?> → <?= h((string)$flight['destination_airport']) ?></small>
              </span>
            </label>
          <?php endforeach; ?>
          <label class="ss-flight"><input type="radio" name="flight_selection" value="no_reservation" required><span>Not related to a scheduled flight</span></label>
          <label>Occurrence type<select class="ss-select" name="occurrence_type_id" required><option value="">Select type</option><?php foreach ($occurrenceTypes as $type): ?><option value="<?= (int)$type['id'] ?>"><?= h((string)$type['label']) ?></option><?php endforeach; ?></select></label>
          <?php if (!$occurrenceTypes): ?><div class="ss-flash error">No active occurrence types are configured. Contact the Safety Manager.</div><?php endif; ?>
          <label>What happened?<textarea class="ss-textarea" name="narrative" maxlength="50000" required></textarea></label>
          <label>Injury<select class="ss-select" name="injury_state" required><option value="">Select</option><option value="no">No</option><option value="yes">Yes</option><option value="unknown">Unknown</option></select></label>
          <label data-detail-for="injury_state" hidden>Injury details<textarea class="ss-textarea" name="injury_details" maxlength="12000"></textarea></label>
          <label>Damage<select class="ss-select" name="damage_state" required><option value="">Select</option><option value="no">No</option><option value="yes">Yes</option><option value="unknown">Unknown</option></select></label>
          <label data-detail-for="damage_state" hidden>Damage details<textarea class="ss-textarea" name="damage_details" maxlength="12000"></textarea></label>
          <label>Weather relevant<select class="ss-select" name="weather_relevance" required><option value="">Select</option><option value="no">No</option><option value="yes">Yes</option><option value="unsure">Unsure</option></select></label>
          <label data-detail-for="weather_relevance" hidden>Weather details<textarea class="ss-textarea" name="weather_details" maxlength="12000"></textarea></label>
          <label>Location<input class="ss-input" name="location_text" maxlength="255"></label>
          <label>Aircraft registration, if relevant<input class="ss-input" name="aircraft_registration" maxlength="32"></label>
          <label>Phase of flight, if applicable<input class="ss-input" name="phase_of_flight" maxlength="64"></label>
          <label>Immediate action taken<textarea class="ss-textarea" name="immediate_action" maxlength="12000"></textarea></label>
          <label>Confidentiality<select class="ss-select" name="confidentiality"><option value="standard">Standard Safety team access</option><option value="restricted">Restricted handling</option></select></label>
          <button class="ss-btn" type="submit">Submit safety report</button>
        </form>
      </section>
      <div class="ss-privacy"><strong>Privacy:</strong> standard reports are linked to your account so the Safety team can respond. Restricted reports keep that link in the encrypted, access-audited reporter vault. Anonymous reports use a separate receipt-based mailbox and are never linked to this history.</div>
    </div>

    <section class="ss-card">
      <?php if ($selected): ?>
        <div class="ss-head"><div><a class="ss-btn secondary" href="/student/safety.php">← Report history</a><h3 style="margin-top:14px"><?= h((string)($selected['report_number'] ?: 'Submitted report')) ?></h3></div><span class="ss-pill"><?= h(student_safety_status((string)$selected['status'])) ?></span></div>
        <h4><?= h((string)$selected['title']) ?></h4>
        <div style="white-space:pre-wrap;line-height:1.6;color:#33465d"><?= h((string)$selected['narrative']) ?></div>
        <div class="ss-sub" style="margin-top:10px">Submitted <?= h(substr((string)$selected['submitted_at_utc'], 0, 16)) ?> · Updated <?= h(substr((string)$selected['updated_at_utc'], 0, 16)) ?></div>
        <h4 style="margin-top:22px">Feedback thread</h4>
        <?php if (empty($selected['updates'])): ?><div class="ss-sub">No messages yet. You can add context for the Safety team below.</div><?php endif; ?>
        <?php foreach (($selected['updates'] ?? array()) as $update): ?>
          <div class="ss-message <?= h((string)$update['direction']) ?>">
            <strong><?= $update['direction'] === 'to_reporter' ? 'Safety team' : 'You' ?></strong>
            <div style="white-space:pre-wrap;margin-top:4px"><?= h((string)$update['body']) ?></div>
            <div class="ss-sub"><?= h(substr((string)$update['created_at_utc'], 0, 16)) ?></div>
          </div>
        <?php endforeach; ?>
        <form method="post" class="ss-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="feedback">
          <input type="hidden" name="report_uuid" value="<?= h((string)$selected['report_uuid']) ?>">
          <label>Add information or reply<textarea class="ss-textarea" name="body" maxlength="12000" required></textarea></label>
          <button class="ss-btn">Send message</button>
        </form>
      <?php else: ?>
        <h3>My report history</h3>
        <div class="ss-sub">Only reports submitted from your signed-in account appear here.</div>
        <?php if (!$reports): ?><div class="ss-sub" style="margin-top:15px">You have not submitted an authenticated safety report.</div><?php endif; ?>
        <?php foreach ($reports as $report): ?>
          <a class="ss-report" href="/student/safety.php?report_uuid=<?= urlencode((string)$report['report_uuid']) ?>">
            <div class="ss-head"><strong><?= h((string)($report['report_number'] ?: $report['title'])) ?></strong><span class="ss-pill"><?= h(student_safety_status((string)$report['status'])) ?></span></div>
            <div style="margin-top:6px"><?= h((string)$report['title']) ?></div>
            <div class="ss-sub">Updated <?= h(substr((string)$report['updated_at_utc'], 0, 16)) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
document.querySelectorAll('[data-detail-for]').forEach(label => {
  const select = document.querySelector(`[name="${label.dataset.detailFor}"]`);
  if (!select) return;
  const sync = () => {
    label.hidden = label.dataset.detailFor === 'weather_relevance'
      ? !['yes', 'unsure'].includes(select.value)
      : select.value !== 'yes';
  };
  select.addEventListener('change', sync);
  sync();
});
</script>
<?php cw_footer(); ?>
