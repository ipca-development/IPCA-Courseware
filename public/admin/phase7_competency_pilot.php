<?php
/**
 * Phase 7 development/admin prototype — competency pilot validation only.
 * Not production UI. No polish. Independence one-tap + expert review + assessment view.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
if (!is_file($dbPath)) {
    http_response_code(500);
    echo 'Analytics DB missing';
    exit;
}
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aid = (string)($_POST['attempt_id'] ?? '');
    if ($action === 'set_independence' && $aid !== '') {
        $state = (string)($_POST['independence_state'] ?? 'NOT_OBSERVED');
        if (!in_array($state, ['ASSISTED', 'PROMPTED', 'INDEPENDENT'], true)) {
            $state = 'NOT_OBSERVED';
        }
        $pdo->prepare('INSERT INTO pilot_independence_observation (attempt_id,independence_state,source,captured_at,analysis_version,generated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$aid, $state, 'INSTRUCTOR_ONE_TAP', gmdate('c'), 'phase7-v1', gmdate('c')]);
        // refresh competency state independence fields
        $pdo->prepare('UPDATE pilot_competency_state SET independence_state=?, independence_source=? WHERE attempt_id=?')
            ->execute([$state, 'INSTRUCTOR_ONE_TAP', $aid]);
        $msg = "Independence set to $state for $aid";
    }
    if ($action === 'set_intervention' && $aid !== '') {
        $etype = (string)($_POST['event_type'] ?? '');
        if (in_array($etype, ['DEMONSTRATION', 'PHYSICAL_INTERVENTION', 'SAFETY_TAKEOVER', 'VERBAL_CORRECTION'], true)) {
            $flight = $pdo->prepare('SELECT pilot_flight_id FROM pilot_exercise_attempt WHERE attempt_id=?');
            $flight->execute([$aid]);
            $fid = $flight->fetchColumn();
            $pdo->prepare('INSERT INTO pilot_intervention_event (attempt_id,pilot_flight_id,event_type,t_sec,reason,source,confirmation_status,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$aid, $fid, $etype, null, 'admin_prototype', 'INSTRUCTOR_QUICK_EVENT', 'CONFIRMED', 'phase7-v1', gmdate('c')]);
            $msg = "Intervention $etype recorded";
        }
    }
    if ($action === 'expert_review' && $aid !== '') {
        $verdict = (string)($_POST['verdict'] ?? 'PENDING');
        $notes = (string)($_POST['notes'] ?? '');
        if (in_array($verdict, ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT'], true)) {
            $pdo->prepare('UPDATE pilot_expert_review SET verdict=?, discrepancy_notes=?, reviewed_at=?, reviewer_role=? WHERE attempt_id=? AND verdict=?')
                ->execute([$verdict, $notes, gmdate('c'), 'examiner', $aid, 'PENDING']);
            // if no pending row updated, insert
            if ($pdo->query("SELECT changes()")->fetchColumn() == 0) {
                $pdo->prepare('INSERT INTO pilot_expert_review (attempt_id,reviewer_role,verdict,discrepancy_notes,reviewed_at,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$aid, 'examiner', $verdict, $notes, gmdate('c'), 'phase7-v1', gmdate('c')]);
            }
            $cause = (string)($_POST['cause_class'] ?? '');
            if ($verdict !== 'CORRECT' && $cause !== '') {
                $pdo->prepare('INSERT INTO pilot_disagreement (attempt_id,dimension,system_value,human_value,cause_class,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$aid, 'expert_review', 'system_state', $verdict, $cause, $notes, 'phase7-v1', gmdate('c')]);
            }
            $msg = "Expert review $verdict saved";
        }
    }
    if ($action === 'accept_ai' && $aid !== '') {
        $acc = (string)($_POST['instructor_acceptance'] ?? 'ACCEPTED');
        $pdo->prepare('UPDATE pilot_ai_assessment SET instructor_acceptance=? WHERE attempt_id=?')
            ->execute([$acc, $aid]);
        $msg = "AI assessment marked $acc";
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Phase 7 Competency Pilot (dev)</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;font-size:13px;margin:16px;max-width:1100px}
table{border-collapse:collapse;width:100%;margin:12px 0}
td,th{border:1px solid #ccc;padding:4px 6px;vertical-align:top}
.msg{background:#eef;padding:8px;margin:8px 0}
.card{border:1px solid #999;padding:10px;margin:12px 0;white-space:pre-wrap}
a{color:#06c}
button,input,select{font:inherit}
</style></head><body>
<h1>Phase 7 Competency Pilot (dev/admin only)</h1>
<p>Validates information architecture. Not production UI.</p>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($action === 'view' || isset($_GET['attempt_id'])):
    $aid = (string)($_GET['attempt_id'] ?? $_POST['attempt_id'] ?? '');
    $a = $pdo->prepare('SELECT * FROM pilot_exercise_attempt WHERE attempt_id=?');
    $a->execute([$aid]);
    $attempt = $a->fetch(PDO::FETCH_ASSOC);
    $state = $pdo->prepare('SELECT * FROM pilot_competency_state WHERE attempt_id=? ORDER BY state_id DESC LIMIT 1');
    $state->execute([$aid]);
    $st = $state->fetch(PDO::FETCH_ASSOC);
    $mets = $pdo->prepare('SELECT * FROM pilot_objective_metric WHERE attempt_id=?');
    $mets->execute([$aid]);
    $metrics = $mets->fetchAll(PDO::FETCH_ASSOC);
    $ai = $pdo->prepare('SELECT * FROM pilot_ai_assessment WHERE attempt_id=? ORDER BY ai_assessment_id DESC LIMIT 1');
    $ai->execute([$aid]);
    $airow = $ai->fetch(PDO::FETCH_ASSOC);
    $ctx = $pdo->prepare('SELECT * FROM pilot_context WHERE attempt_id=? LIMIT 1');
    $ctx->execute([$aid]);
    $crow = $ctx->fetch(PDO::FETCH_ASSOC);
    $indep = $pdo->prepare('SELECT * FROM pilot_independence_observation WHERE attempt_id=? ORDER BY observation_id DESC LIMIT 1');
    $indep->execute([$aid]);
    $irow = $indep->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) { echo 'Attempt not found'; exit; }
?>
<p><a href="?">← list</a></p>
<div class="card"><?php
echo "EXERCISE\n" . $attempt['exercise_code'] . "\n\n";
echo "EXPECTED\n" . ($attempt['expected_level'] ?? 'UNKNOWN') . "\n\n";
echo "INDEPENDENCE\n" . ($irow['independence_state'] ?? 'NOT_OBSERVED') . "\nSource: " . ($irow['source'] ?? 'DEFAULT') . "\n\n";
echo "OBJECTIVE QUALITY\n";
if (!$metrics) {
    echo "INSUFFICIENT EVIDENCE\n";
} else {
    foreach ($metrics as $m) {
        $w = ((int)$m['within_standard'] === 1) ? 'within applicable standard' : 'outside applicable standard';
        echo $m['metric'] . ":\n";
        echo "  actual=" . $m['actual_value'] . " max_dev=" . $m['max_deviation'] . " " . $m['unit'] . "\n";
        echo "  time_outside_sec=" . $m['time_outside_tolerance_sec'] . " pct_within=" . $m['pct_within_tolerance'] . "\n";
        echo "  " . $w . "\n";
    }
}
echo "\nCONSISTENCY\n" . ($st['attempt_repeatability'] ?? 'INSUFFICIENT_EVIDENCE') . "\n";
echo "longitudinal: " . ($st['longitudinal_stability'] ?? 'NOT_ENOUGH_EVIDENCE') . "\n\n";
echo "CONTEXT\n" . ($st['context_summary'] ?: 'UNKNOWN') . "\n";
if ($crow) {
    echo "crosswind_component_kt=" . $crow['crosswind_component_kt'] . " oat_c=" . $crow['oat_c'] . " DA_ft=" . $crow['density_altitude_ft'] . "\n";
}
echo "\nTREND\n" . ($st['trend'] ?? 'INSUFFICIENT_EVIDENCE') . "\n\n";
echo "EVIDENCE\nboundary=" . $attempt['boundary_source'] . " confidence=" . $attempt['detection_confidence'] . "\n";
echo "flight=" . $attempt['pilot_flight_id'] . " t=" . $attempt['t_start_sec'] . "-" . $attempt['t_end_sec'] . "\n";
if ($airow) {
    echo "\nAI ASSESSMENT (" . $airow['model'] . ")\n" . $airow['assessment_text'] . "\nacceptance=" . $airow['instructor_acceptance'] . "\n";
}
echo "\nEXPLANATION\n" . ($st['explanation'] ?? '') . "\n";
?></div>

<h3>One-tap independence</h3>
<form method="post">
<input type="hidden" name="action" value="set_independence">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($aid) ?>">
<button name="independence_state" value="ASSISTED">ASSISTED</button>
<button name="independence_state" value="PROMPTED">PROMPTED</button>
<button name="independence_state" value="INDEPENDENT">INDEPENDENT</button>
</form>

<h3>Intervention (separate from independence)</h3>
<form method="post">
<input type="hidden" name="action" value="set_intervention">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($aid) ?>">
<button name="event_type" value="DEMONSTRATION">DEMONSTRATION</button>
<button name="event_type" value="PHYSICAL_INTERVENTION">PHYSICAL_INTERVENTION</button>
<button name="event_type" value="SAFETY_TAKEOVER">SAFETY_TAKEOVER</button>
<button name="event_type" value="VERBAL_CORRECTION">VERBAL_CORRECTION</button>
</form>

<h3>AI assessment confirmation</h3>
<?php if ($airow): ?>
<form method="post">
<input type="hidden" name="action" value="accept_ai">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($aid) ?>">
<button name="instructor_acceptance" value="ACCEPTED">ACCEPT</button>
<button name="instructor_acceptance" value="CORRECTED">CORRECT</button>
<button name="instructor_acceptance" value="REJECTED">REJECT</button>
</form>
<?php endif; ?>

<h3>Expert review</h3>
<form method="post">
<input type="hidden" name="action" value="expert_review">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($aid) ?>">
<select name="verdict">
<option>CORRECT</option><option>PARTIALLY_CORRECT</option><option>INCORRECT</option>
</select>
<select name="cause_class">
<option value="">(cause if not correct)</option>
<option>incorrect exercise boundary</option>
<option>incorrect tolerance</option>
<option>missing context</option>
<option>telemetry limitation</option>
<option>human judgment dimension</option>
<option>AI interpretation error</option>
<option>instructor override</option>
<option>insufficient evidence</option>
</select>
<br>
<textarea name="notes" rows="3" cols="80" placeholder="discrepancy notes"></textarea><br>
<button type="submit">Save expert review</button>
</form>

<?php else: ?>
<p>Flights: <?= (int)$pdo->query('SELECT COUNT(*) FROM pilot_flight')->fetchColumn() ?>
 · Attempts: <?= (int)$pdo->query('SELECT COUNT(*) FROM pilot_exercise_attempt')->fetchColumn() ?>
 · Expert pending: <?= (int)$pdo->query("SELECT COUNT(*) FROM pilot_expert_review WHERE verdict='PENDING'")->fetchColumn() ?></p>
<table>
<tr><th>attempt</th><th>exercise</th><th>#</th><th>indep</th><th>metrics ok</th><th>consistency</th><th></th></tr>
<?php
$q = $pdo->query("SELECT a.attempt_id,a.exercise_code,a.attempt_number,
  (SELECT independence_state FROM pilot_independence_observation o WHERE o.attempt_id=a.attempt_id ORDER BY observation_id DESC LIMIT 1) indep,
  (SELECT COUNT(*) FROM pilot_objective_metric m WHERE m.attempt_id=a.attempt_id AND m.within_standard=1) ok,
  (SELECT COUNT(*) FROM pilot_objective_metric m WHERE m.attempt_id=a.attempt_id) nmet,
  (SELECT attempt_repeatability FROM pilot_competency_state s WHERE s.attempt_id=a.attempt_id ORDER BY state_id DESC LIMIT 1) cons
  FROM pilot_exercise_attempt a ORDER BY a.exercise_code, a.pilot_flight_id, a.attempt_number LIMIT 200");
foreach ($q as $r): ?>
<tr>
<td><?= htmlspecialchars($r['attempt_id']) ?></td>
<td><?= htmlspecialchars($r['exercise_code']) ?></td>
<td><?= (int)$r['attempt_number'] ?></td>
<td><?= htmlspecialchars((string)$r['indep']) ?></td>
<td><?= (int)$r['ok'] ?>/<?= (int)$r['nmet'] ?></td>
<td><?= htmlspecialchars((string)$r['cons']) ?></td>
<td><a href="?attempt_id=<?= urlencode($r['attempt_id']) ?>">open</a></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</body></html>
