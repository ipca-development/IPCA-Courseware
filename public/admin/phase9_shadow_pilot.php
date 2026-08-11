<?php
/**
 * Phase 9 shadow pilot admin — development only.
 * Does NOT alter official debrief / grades / scheduling.
 * Feature flags remain OFF; this UI is for pilot operators/examiners.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/RuntimeSecrets.php';
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
if (!is_file($dbPath)) {
    http_response_code(500);
    echo 'Run analytics/etl/phase9_01_shadow_pipeline.py first';
    exit;
}
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$view = $_GET['view'] ?? 'home';
$msg = '';
$t0 = (int)(microtime(true) * 1000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clinic_verdict') {
        $aid = (string)($_POST['attempt_id'] ?? '');
        $reviewer = (string)($_POST['reviewer_id'] ?? 'examiner_A');
        $verdict = (string)($_POST['verdict'] ?? '');
        $codes = $_POST['reason_codes'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }
        if (in_array($verdict, ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'INSUFFICIENT_EVIDENCE'], true)) {
            $pdo->prepare('UPDATE phase8_examiner_review SET verdict=?, reason_codes_json=?, narrative_notes=?, reviewed_at=? WHERE attempt_id=? AND reviewer_id=? AND verdict=?')
                ->execute([$verdict, json_encode(array_values($codes)), (string)($_POST['notes'] ?? ''), gmdate('c'), $aid, $reviewer, 'PENDING']);
            $msg = "Clinic verdict saved (does not change official grades).";
        }
    }
    if ($action === 'maneuver_disposition') {
        $ex = (string)($_POST['canonical_exercise_id'] ?? '');
        $disp = (string)($_POST['disposition'] ?? '');
        if (in_array($disp, ['APPROVED', 'APPROVED_WITH_CHANGES', 'MORE_VALIDATION_REQUIRED', 'NOT_READY'], true) && $ex !== '') {
            $pdo->prepare('UPDATE maneuver_disposition SET disposition=?, rationale=? WHERE canonical_exercise_id=?')
                ->execute([$disp, (string)($_POST['rationale'] ?? ''), $ex]);
            $msg = "Maneuver disposition updated for $ex";
        }
    }
    if ($action === 'shadow_review') {
        $sid = (string)($_POST['shadow_session_id'] ?? '');
        $after = (string)($_POST['instructor_after_system_summary'] ?? '');
        $elapsed = (int)(microtime(true) * 1000) - (int)($_POST['opened_at_ms'] ?? $t0);
        $pdo->prepare('UPDATE shadow_comparison SET instructor_after_system_summary=? WHERE shadow_session_id=?')
            ->execute([$after, $sid]);
        $pdo->prepare('INSERT INTO shadow_workload_event (shadow_session_id,instructor_id,event_type,elapsed_ms,payload_json,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$sid, 'pilot_instructor', 'review_to_save', $elapsed, json_encode(['action' => 'shadow_review']), 'phase9-v1', gmdate('c')]);
        $pdo->prepare('UPDATE shadow_session SET evidence_state=? WHERE shadow_session_id=?')
            ->execute(['INSTRUCTOR_REVIEWED', $sid]);
        $msg = "Shadow review stored. Official debrief untouched.";
    }
    if ($action === 'correction') {
        $assessmentId = (string)($_POST['assessment_id'] ?? '');
        $reason = (string)($_POST['reason_code'] ?? 'OTHER');
        $pdo->prepare('INSERT INTO shadow_instructor_correction (assessment_id,system_value_json,instructor_value_json,reason_code,narrative,final_human_confirmed_json,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $assessmentId,
                (string)($_POST['system_value_json'] ?? '{}'),
                (string)($_POST['instructor_value_json'] ?? '{}'),
                $reason,
                (string)($_POST['narrative'] ?? ''),
                (string)($_POST['instructor_value_json'] ?? '{}'),
                'phase9-v1',
                gmdate('c'),
            ]);
        $msg = 'Correction stored (system assessment retained).';
    }
}

header('Content-Type: text/html; charset=utf-8');
$openai = RuntimeSecrets::peekStatus('OPENAI_API_KEY');
$dbpass = RuntimeSecrets::peekStatus('CW_DB_PASS');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Phase 9 Shadow Pilot (dev)</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;font-size:13px;margin:16px;max-width:1100px}
table{border-collapse:collapse;width:100%}
td,th{border:1px solid #bbb;padding:4px 6px;vertical-align:top}
.msg{background:#eef;padding:8px}.nav a{margin-right:10px}
.card{border:1px solid #666;padding:10px;margin:10px 0;white-space:pre-wrap}
.bad{color:#900}.warn{color:#860}.ok{color:#060}
button,select,textarea,input{font:inherit}
</style></head><body>
<div class="nav">
<a href="?view=home">Home</a>
<a href="?view=cohort">Shadow cohort</a>
<a href="?view=clinic">Examiner clinic</a>
<a href="?view=dispositions">Maneuver dispositions</a>
<a href="?view=gates">Readiness gates</a>
<a href="?view=claims">Claims</a>
<a href="?view=boundaries">Boundary queue</a>
</div>
<h1>Phase 9 Shadow Production Pilot</h1>
<p class="warn">SHADOW MODE — official training/debrief/grades/scheduling remain authoritative. No student auto-send.</p>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($view === 'home'): ?>
<pre>OPENAI <?= htmlspecialchars(json_encode($openai)) ?>

DB_PASS <?= htmlspecialchars(json_encode($dbpass)) ?>
</pre>
<?php
$meta = $pdo->query('SELECT notes FROM phase9_meta ORDER BY generated_at DESC LIMIT 1')->fetchColumn();
$n = $pdo->query('SELECT COUNT(*) FROM shadow_session')->fetchColumn();
$mode = $pdo->query('SELECT cohort_mode, COUNT(*) c FROM shadow_session GROUP BY 1')->fetchAll(PDO::FETCH_ASSOC);
?>
<pre>sessions=<?= (int)$n ?>

modes=<?= htmlspecialchars(json_encode($mode)) ?>

meta=<?= htmlspecialchars((string)$meta) ?>
</pre>
<p>Student debrief feature flag: <strong>OFF</strong> (by design).</p>

<?php elseif ($view === 'cohort'): ?>
<h2>Shadow cohort</h2>
<table>
<tr><th>session</th><th>mode</th><th>state</th><th>aircraft</th><th>review</th></tr>
<?php foreach ($pdo->query('SELECT * FROM shadow_session ORDER BY generated_at DESC') as $s): ?>
<tr>
<td><?= htmlspecialchars($s['shadow_session_id']) ?></td>
<td><?= htmlspecialchars($s['cohort_mode']) ?></td>
<td><?= htmlspecialchars($s['evidence_state']) ?></td>
<td><?= htmlspecialchars((string)$s['aircraft']) ?></td>
<td>
<form method="post">
<input type="hidden" name="action" value="shadow_review">
<input type="hidden" name="shadow_session_id" value="<?= htmlspecialchars($s['shadow_session_id']) ?>">
<input type="hidden" name="opened_at_ms" value="<?= (int)(microtime(true)*1000) ?>">
<textarea name="instructor_after_system_summary" rows="2" cols="40" placeholder="C: instructor after seeing system"></textarea>
<button type="submit">Save shadow review</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'clinic'): ?>
<h2>Examiner clinic (complete dual reviews)</h2>
<?php
$st = $pdo->query("SELECT metric_name,metric_value,n,notes FROM phase9_examiner_clinic_status")->fetchAll(PDO::FETCH_ASSOC);
?>
<pre><?= htmlspecialchars(json_encode($st, JSON_PRETTY_PRINT)) ?></pre>
<table>
<tr><th>attempt</th><th>reviewer</th><th>verdict</th><th>submit</th></tr>
<?php foreach ($pdo->query("SELECT attempt_id,reviewer_id,verdict FROM phase8_examiner_review ORDER BY attempt_id,reviewer_id LIMIT 80") as $r): ?>
<tr>
<td><?= htmlspecialchars($r['attempt_id']) ?></td>
<td><?= htmlspecialchars($r['reviewer_id']) ?></td>
<td><?= htmlspecialchars($r['verdict']) ?></td>
<td>
<?php if ($r['verdict']==='PENDING'): ?>
<form method="post">
<input type="hidden" name="action" value="clinic_verdict">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($r['attempt_id']) ?>">
<input type="hidden" name="reviewer_id" value="<?= htmlspecialchars($r['reviewer_id']) ?>">
<select name="verdict"><option>CORRECT</option><option>PARTIALLY_CORRECT</option><option>INCORRECT</option><option>INSUFFICIENT_EVIDENCE</option></select>
<?php foreach (['BOUNDARY_WRONG','WRONG_TOLERANCE','METRIC_EXTRACTION_WRONG','INDEPENDENCE_WRONG','CONSISTENCY_WRONG','AI_OVERINTERPRETATION','OBJECTIVE_DATA_INSUFFICIENT','OTHER'] as $c): ?>
<label><input type="checkbox" name="reason_codes[]" value="<?= $c ?>"> <?= $c ?></label>
<?php endforeach; ?>
<textarea name="notes" rows="1" cols="30"></textarea>
<button type="submit">Save</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'dispositions'): ?>
<h2>Maneuver dispositions</h2>
<?php foreach ($pdo->query('SELECT * FROM maneuver_disposition') as $d): ?>
<div class="card"><?= htmlspecialchars($d['canonical_exercise_id']) ?>: <?= htmlspecialchars($d['disposition']) ?>
<?= htmlspecialchars((string)$d['rationale']) ?>
<form method="post">
<input type="hidden" name="action" value="maneuver_disposition">
<input type="hidden" name="canonical_exercise_id" value="<?= htmlspecialchars($d['canonical_exercise_id']) ?>">
<select name="disposition">
<option>APPROVED</option><option>APPROVED_WITH_CHANGES</option><option selected>MORE_VALIDATION_REQUIRED</option><option>NOT_READY</option>
</select>
<textarea name="rationale" rows="2" cols="60"></textarea>
<button type="submit">Update</button>
</form>
</div>
<?php endforeach; ?>

<?php elseif ($view === 'gates'): ?>
<h2>Readiness gates</h2>
<table><tr><th>gate</th><th>status</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase9_readiness_gate') as $g): ?>
<tr>
<td><?= htmlspecialchars($g['gate_code']) ?></td>
<td class="<?= $g['status']==='PASS'?'ok':($g['status']==='BLOCKED'||$g['status']==='FAIL'?'bad':'warn') ?>"><?= htmlspecialchars($g['status']) ?></td>
<td><?= htmlspecialchars((string)$g['evidence_notes']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<h3>Feature flag plan (not enabled)</h3>
<table><tr><th>flag</th><th>intended</th><th>description</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase9_feature_flag_plan') as $f): ?>
<tr><td><?= htmlspecialchars($f['flag_name']) ?></td><td><?= htmlspecialchars($f['intended_initial_state']) ?></td><td><?= htmlspecialchars($f['description']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'claims'): ?>
<h2>Claim → evidence (no freeform AI)</h2>
<?php foreach ($pdo->query('SELECT * FROM shadow_debrief_claim LIMIT 40') as $c): ?>
<div class="card"><?= htmlspecialchars($c['claim_id']) ?>
<?= htmlspecialchars($c['claim_text']) ?>
evidence=<?= htmlspecialchars($c['supporting_evidence_ids_json']) ?>
source=<?= htmlspecialchars($c['assessment_source']) ?> completeness=<?= htmlspecialchars($c['evidence_completeness']) ?>
</div>
<?php endforeach; ?>

<?php elseif ($view === 'boundaries'): ?>
<h2>Boundary source stats + review queue</h2>
<pre><?php
foreach ($pdo->query('SELECT * FROM phase9_boundary_source_stats') as $r) {
    echo htmlspecialchars($r['end_boundary_source'].' n='.$r['n'].' share='.$r['share'])."\n";
}
?></pre>
<table><tr><th>attempt</th><th>flag</th><th>detail</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase9_boundary_review_queue LIMIT 100') as $q): ?>
<tr><td><?= htmlspecialchars((string)$q['shadow_attempt_id']) ?></td><td><?= htmlspecialchars($q['flag']) ?></td><td><?= htmlspecialchars((string)$q['detail']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</body></html>
