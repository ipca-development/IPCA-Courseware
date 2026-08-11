<?php
/**
 * Phase 10 live shadow validation admin — development / approved-host only.
 * Does NOT alter official debrief / grades / scheduling / progression.
 * Feature flags remain OFF. No student send.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/RuntimeSecrets.php';
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
if (!is_file($dbPath)) {
    http_response_code(500);
    echo 'Run analytics/etl/phase10_01_live_shadow.py first';
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
        $dims = [
            'boundary' => (string)($_POST['dim_boundary'] ?? ''),
            'objective_quality' => (string)($_POST['dim_objective'] ?? ''),
            'tolerance' => (string)($_POST['dim_tolerance'] ?? ''),
            'procedure' => (string)($_POST['dim_procedure'] ?? ''),
            'independence' => (string)($_POST['dim_independence'] ?? ''),
            'consistency' => (string)($_POST['dim_consistency'] ?? ''),
            'context' => (string)($_POST['dim_context'] ?? ''),
            'system_competency' => (string)($_POST['dim_system'] ?? ''),
        ];
        $allowed = ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'INSUFFICIENT_EVIDENCE', ''];
        foreach ($dims as $k => $v) {
            if (!in_array($v, $allowed, true)) {
                $dims[$k] = '';
            }
        }
        if (in_array($verdict, ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'INSUFFICIENT_EVIDENCE'], true)) {
            $notes = trim((string)($_POST['notes'] ?? '') . "\nDIMS:" . json_encode($dims));
            $pdo->prepare('UPDATE phase8_examiner_review SET verdict=?, reason_codes_json=?, narrative_notes=?, reviewed_at=? WHERE attempt_id=? AND reviewer_id=? AND verdict=?')
                ->execute([$verdict, json_encode(array_values($codes)), $notes, gmdate('c'), $aid, $reviewer, 'PENDING']);
            $msg = 'Clinic verdict saved (does not change official grades). Re-run phase10 pipeline to refresh gates.';
        }
    }
    if ($action === 'workload_finish') {
        $sid = (string)($_POST['shadow_session_id'] ?? '');
        $elapsed = (int)(microtime(true) * 1000) - (int)($_POST['opened_at_ms'] ?? $t0);
        $payload = [
            'taps' => (int)($_POST['tap_count'] ?? 0),
            'corrections' => (int)($_POST['corrections'] ?? 0),
            'observations' => (int)($_POST['added_observations'] ?? 0),
            'drilldowns' => (int)($_POST['evidence_drilldowns'] ?? 0),
            'exceptions' => (int)($_POST['exception_count'] ?? 0),
            'segment' => (string)($_POST['segment'] ?? 'routine'),
        ];
        $pdo->prepare('INSERT INTO shadow_workload_event (shadow_session_id,instructor_id,event_type,elapsed_ms,payload_json,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$sid ?: 'phase10_ad_hoc', 'pilot_instructor', 'live_review_finish', $elapsed, json_encode($payload), 'phase10-v1', gmdate('c')]);
        $msg = 'Workload event stored (shadow only). Official debrief untouched.';
    }
    if ($action === 'exception_rating') {
        $rating = (string)($_POST['rating'] ?? '');
        if (in_array($rating, ['USEFUL', 'NEUTRAL', 'NOISY', 'WRONG'], true)) {
            $pdo->prepare('UPDATE phase10_exception_snr SET n = COALESCE(n,0)+1 WHERE rating=?')->execute([$rating]);
            $pdo->prepare('UPDATE phase10_exception_snr SET n = MAX(0, COALESCE(n,0)-1) WHERE rating=?')->execute(['PENDING']);
            $msg = "Exception rated $rating";
        }
    }
    if ($action === 'claim_support') {
        $support = (string)($_POST['support'] ?? '');
        $map = [
            'fully_supported' => 'fully_supported_live',
            'partially_supported' => 'partially_supported_live',
            'unsupported' => 'unsupported_live',
            'misleading' => 'misleading_despite_link_live',
        ];
        if (isset($map[$support])) {
            $pdo->prepare('UPDATE phase10_claim_validation SET n = COALESCE(n,0)+1 WHERE metric_name=?')->execute([$map[$support]]);
            $msg = "Claim support sample recorded: $support";
        }
    }
    if ($action === 'debrief_quality') {
        $dim = (string)($_POST['dimension'] ?? '');
        $note = (string)($_POST['score_notes'] ?? '');
        $pdo->prepare('UPDATE phase10_debrief_quality SET score_notes=?, n=COALESCE(n,0)+1 WHERE dimension=?')
            ->execute([$note, $dim]);
        $msg = "Debrief quality note saved for $dim";
    }
}

header('Content-Type: text/html; charset=utf-8');
$openai = RuntimeSecrets::peekStatus('OPENAI_API_KEY');
$dbpass = RuntimeSecrets::peekStatus('CW_DB_PASS');
$verdict = $pdo->query('SELECT verdict, rationale, generated_at FROM phase10_overall_verdict ORDER BY generated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Phase 10 Live Shadow (dev)</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;font-size:13px;margin:16px;max-width:1200px}
table{border-collapse:collapse;width:100%}
td,th{border:1px solid #bbb;padding:4px 6px;vertical-align:top}
.msg{background:#eef;padding:8px}.nav a{margin-right:10px}
.card{border:1px solid #666;padding:10px;margin:10px 0;white-space:pre-wrap}
.bad{color:#900}.warn{color:#860}.ok{color:#060}
button,select,textarea,input{font:inherit}
</style></head><body>
<div class="nav">
<a href="?view=home">Home</a>
<a href="?view=gates">Exit gates</a>
<a href="?view=cohort">Live cohort</a>
<a href="?view=clinic">Examiner clinic</a>
<a href="?view=maneuvers">Maneuver verdicts</a>
<a href="?view=workload">Workload</a>
<a href="?view=claims">Claim support</a>
<a href="?view=exceptions">Exceptions</a>
<a href="?view=debrief">Debrief quality</a>
<a href="?view=schema">Schema review</a>
</div>
<h1>Phase 10 — Approved-Host Live Shadow</h1>
<p class="warn">SHADOW ONLY — official debriefs/grades/progression/scheduling untouched. Student debrief OFF. No production migrations.</p>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($view === 'home'): ?>
<h2 class="<?= ($verdict['verdict'] ?? '') === 'NOT_READY' ? 'bad' : 'warn' ?>">
Verdict: <?= htmlspecialchars((string)($verdict['verdict'] ?? 'UNKNOWN')) ?>
</h2>
<pre><?= htmlspecialchars((string)($verdict['rationale'] ?? '')) ?>

generated=<?= htmlspecialchars((string)($verdict['generated_at'] ?? '')) ?>

OPENAI <?= htmlspecialchars(json_encode($openai)) ?>

DB_PASS <?= htmlspecialchars(json_encode($dbpass)) ?>
</pre>
<h3>Runtime</h3>
<table><tr><th>component</th><th>status</th><th>detail</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_runtime_status') as $r): ?>
<tr>
<td><?= htmlspecialchars($r['component']) ?></td>
<td class="<?= $r['status']==='OK'?'ok':($r['status']==='BLOCKED'?'bad':'warn') ?>"><?= htmlspecialchars($r['status']) ?></td>
<td><?= htmlspecialchars((string)$r['detail']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<p>Feature flags (Phase 10 state):</p>
<table><tr><th>flag</th><th>state</th><th>post-gate intended</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_feature_flag_plan') as $f): ?>
<tr><td><?= htmlspecialchars($f['flag_name']) ?></td><td class="bad"><?= htmlspecialchars($f['phase10_state']) ?></td><td><?= htmlspecialchars((string)$f['post_gate_intended']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'gates'): ?>
<h2>Exit gates A–Q</h2>
<table><tr><th>gate</th><th>status</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_exit_gate ORDER BY gate_code') as $g): ?>
<tr>
<td><?= htmlspecialchars($g['gate_code']) ?></td>
<td class="<?= in_array($g['status'],['PASS'],true)?'ok':(in_array($g['status'],['BLOCKED','FAIL'],true)?'bad':'warn') ?>"><?= htmlspecialchars($g['status']) ?></td>
<td><?= htmlspecialchars((string)$g['evidence_notes']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<h3>Migration gate (all must be met for schema migration recommendation)</h3>
<table><tr><th>gate</th><th>met</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_migration_gate') as $g): ?>
<tr><td><?= htmlspecialchars($g['gate_name']) ?></td><td class="<?= ((int)$g['met'])===1?'ok':'bad' ?>"><?= (int)$g['met'] ?></td><td><?= htmlspecialchars((string)$g['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<p class="bad">Current recommendation: <strong>NO MIGRATION</strong></p>

<?php elseif ($view === 'cohort'): ?>
<h2>Live cohort</h2>
<table><tr><th>dimension</th><th>value</th><th>n</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_cohort_composition') as $c): ?>
<tr><td><?= htmlspecialchars($c['dimension']) ?></td><td><?= htmlspecialchars($c['value']) ?></td><td><?= (int)$c['n'] ?></td></tr>
<?php endforeach; ?>
</table>
<table><tr><th>session</th><th>aircraft</th><th>mode</th><th>completeness</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_live_cohort LIMIT 100') as $s): ?>
<tr>
<td><?= htmlspecialchars($s['operational_session_uuid']) ?></td>
<td><?= htmlspecialchars((string)$s['aircraft']) ?></td>
<td><?= htmlspecialchars((string)$s['ingest_mode']) ?></td>
<td><?= htmlspecialchars((string)$s['evidence_completeness']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php if (!$pdo->query('SELECT COUNT(*) FROM phase10_live_cohort')->fetchColumn()): ?>
<p class="bad">0 live flights ingested. Run on approved host with CW_DB_PASS usable.</p>
<?php endif; ?>

<?php elseif ($view === 'clinic'): ?>
<h2>Examiner clinic — genuine human review only</h2>
<?php foreach ($pdo->query('SELECT * FROM phase10_clinic_completion') as $m): ?>
<pre><?= htmlspecialchars($m['metric_name'].'='.$m['metric_value'].' n='.$m['n'].' '.$m['notes']) ?></pre>
<?php endforeach; ?>
<table>
<tr><th>attempt</th><th>reviewer</th><th>verdict</th><th>dimensional + submit</th></tr>
<?php
$dimOpts = ['','CORRECT','PARTIALLY_CORRECT','INCORRECT','INSUFFICIENT_EVIDENCE'];
foreach ($pdo->query("SELECT attempt_id,reviewer_id,verdict FROM phase8_examiner_review ORDER BY attempt_id,reviewer_id LIMIT 80") as $r):
?>
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
Overall <select name="verdict"><option>CORRECT</option><option>PARTIALLY_CORRECT</option><option>INCORRECT</option><option>INSUFFICIENT_EVIDENCE</option></select>
<?php foreach (['boundary'=>'dim_boundary','objective'=>'dim_objective','tolerance'=>'dim_tolerance','procedure'=>'dim_procedure','independence'=>'dim_independence','consistency'=>'dim_consistency','context'=>'dim_context','system'=>'dim_system'] as $label=>$name): ?>
<?= htmlspecialchars($label) ?>
<select name="<?= $name ?>"><?php foreach ($dimOpts as $o): ?><option><?= $o ?></option><?php endforeach; ?></select>
<?php endforeach; ?>
<?php foreach (['BOUNDARY_WRONG','WRONG_TOLERANCE','METRIC_EXTRACTION_WRONG','INDEPENDENCE_WRONG','CONSISTENCY_WRONG','AI_OVERINTERPRETATION','OBJECTIVE_DATA_INSUFFICIENT','OTHER'] as $c): ?>
<label><input type="checkbox" name="reason_codes[]" value="<?= $c ?>"> <?= $c ?></label>
<?php endforeach; ?>
<textarea name="notes" rows="1" cols="40"></textarea>
<button type="submit">Save genuine review</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'maneuvers'): ?>
<h2>Maneuver-level Phase 10 verdicts</h2>
<?php foreach ($pdo->query('SELECT * FROM phase10_maneuver_verdict') as $d): ?>
<div class="card"><?= htmlspecialchars($d['canonical_exercise_id']) ?>: <strong><?= htmlspecialchars($d['verdict']) ?></strong>
<?= htmlspecialchars((string)$d['rationale']) ?>
</div>
<?php endforeach; ?>
<h3>Tolerance dispositions</h3>
<table><tr><th>pack</th><th>metric</th><th>disposition</th><th>mismatch</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_tolerance_disposition') as $t): ?>
<tr><td><?= htmlspecialchars($t['pack_id']) ?></td><td><?= htmlspecialchars((string)$t['metric']) ?></td><td><?= htmlspecialchars((string)$t['disposition']) ?></td><td><?= htmlspecialchars((string)$t['mismatch_class']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'workload'): ?>
<h2>Live instructor workload capture</h2>
<p class="warn">Design target median &lt;3 min is a hypothesis until measured. Phase 8 estimate ~2.5 min is not acceptance.</p>
<table><tr><th>segment</th><th>median</th><th>p75</th><th>p90</th><th>n</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_workload_live') as $w): ?>
<tr>
<td><?= htmlspecialchars($w['segment']) ?></td>
<td><?= htmlspecialchars((string)$w['median_value']) ?></td>
<td><?= htmlspecialchars((string)$w['p75_value']) ?></td>
<td><?= htmlspecialchars((string)$w['p90_value']) ?></td>
<td><?= (int)$w['n'] ?></td>
</tr>
<?php endforeach; ?>
</table>
<form method="post" class="card">
<input type="hidden" name="action" value="workload_finish">
<input type="hidden" name="opened_at_ms" value="<?= (int)(microtime(true)*1000) ?>">
shadow_session_id <input name="shadow_session_id" size="40">
segment <select name="segment"><option>routine</option><option>problematic</option><option>high_exercise_count</option></select>
taps <input name="tap_count" type="number" value="0" style="width:4em">
corrections <input name="corrections" type="number" value="0" style="width:4em">
observations <input name="added_observations" type="number" value="0" style="width:4em">
drilldowns <input name="evidence_drilldowns" type="number" value="0" style="width:4em">
exceptions <input name="exception_count" type="number" value="0" style="width:4em">
<button type="submit">Finish review (record elapsed)</button>
</form>

<?php elseif ($view === 'claims'): ?>
<h2>Claim → evidence support (sampled live)</h2>
<p>Evidence ID existence ≠ claim support. Rate whether evidence truly supports the claim.</p>
<?php foreach ($pdo->query('SELECT * FROM phase10_claim_validation') as $c): ?>
<pre><?= htmlspecialchars($c['metric_name'].' value='.$c['metric_value'].' n='.$c['n'].' '.$c['notes']) ?></pre>
<?php endforeach; ?>
<?php foreach ($pdo->query('SELECT * FROM shadow_debrief_claim LIMIT 20') as $c): ?>
<div class="card"><?= htmlspecialchars($c['claim_text']) ?>
evidence=<?= htmlspecialchars($c['supporting_evidence_ids_json']) ?>
<form method="post">
<input type="hidden" name="action" value="claim_support">
<select name="support">
<option value="fully_supported">fully supported</option>
<option value="partially_supported">partially supported</option>
<option value="unsupported">unsupported</option>
<option value="misleading">misleading despite link</option>
</select>
<button type="submit">Rate sample</button>
</form>
</div>
<?php endforeach; ?>

<?php elseif ($view === 'exceptions'): ?>
<h2>Exception queue signal-to-noise</h2>
<table><tr><th>rating</th><th>n</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_exception_snr') as $e): ?>
<tr><td><?= htmlspecialchars($e['rating']) ?></td><td><?= (int)$e['n'] ?></td><td><?= htmlspecialchars((string)$e['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<form method="post">
<input type="hidden" name="action" value="exception_rating">
<select name="rating"><option>USEFUL</option><option>NEUTRAL</option><option>NOISY</option><option>WRONG</option></select>
<button type="submit">Rate one exception item</button>
</form>

<?php elseif ($view === 'debrief'): ?>
<h2>Debrief quality (instructor/examiner)</h2>
<?php foreach ($pdo->query('SELECT * FROM phase10_debrief_quality') as $d): ?>
<div class="card"><?= htmlspecialchars($d['dimension']) ?> n=<?= (int)$d['n'] ?>
<?= htmlspecialchars((string)$d['score_notes']) ?>
<form method="post">
<input type="hidden" name="action" value="debrief_quality">
<input type="hidden" name="dimension" value="<?= htmlspecialchars($d['dimension']) ?>">
<textarea name="score_notes" rows="2" cols="60" placeholder="rating + notes; for would_help_debrief answer yes/no/why"></textarea>
<button type="submit">Save</button>
</form>
</div>
<?php endforeach; ?>

<?php elseif ($view === 'schema'): ?>
<h2>Production schema readiness (NO MIGRATION)</h2>
<table><tr><th>entity</th><th>disposition</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10_schema_review') as $s): ?>
<tr><td><?= htmlspecialchars($s['entity_or_column']) ?></td><td><?= htmlspecialchars($s['disposition']) ?></td><td><?= htmlspecialchars((string)$s['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</body></html>
