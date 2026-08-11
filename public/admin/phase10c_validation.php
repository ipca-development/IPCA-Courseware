<?php
/**
 * Phase 10C live validation closure admin.
 * Human examiner clinic + workload/claim capture only.
 * Does NOT alter official debrief/grades/scheduling. Flags OFF.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/RuntimeSecrets.php';
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
if (!is_file($dbPath)) {
    http_response_code(500);
    echo 'Run analytics/etl/phase10c_01_validation_closure.py first';
    exit;
}
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$invPath = $root . '/storage/analytics/phase10c_investigations.sqlite';
$inv = is_file($invPath) ? new PDO('sqlite:' . $invPath) : null;
if ($inv) {
    $inv->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$view = $_GET['view'] ?? 'home';
$msg = '';
$t0 = (int)(microtime(true) * 1000);
$allowedVerdict = ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'INSUFFICIENT_EVIDENCE'];
$allowedTx = ['GOOD', 'USABLE', 'LIMITED', 'UNUSABLE', 'MISSING'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clinic_dimensional') {
        $aid = (string)($_POST['attempt_id'] ?? '');
        $reviewer = (string)($_POST['reviewer_id'] ?? '');
        $fields = [
            'boundary_verdict' => (string)($_POST['boundary_verdict'] ?? ''),
            'objective_verdict' => (string)($_POST['objective_verdict'] ?? ''),
            'tolerance_verdict' => (string)($_POST['tolerance_verdict'] ?? ''),
            'procedure_verdict' => (string)($_POST['procedure_verdict'] ?? ''),
            'independence_verdict' => (string)($_POST['independence_verdict'] ?? ''),
            'consistency_verdict' => (string)($_POST['consistency_verdict'] ?? ''),
            'overall_verdict' => (string)($_POST['overall_verdict'] ?? ''),
        ];
        $ok = true;
        foreach ($fields as $v) {
            if (!in_array($v, $allowedVerdict, true)) {
                $ok = false;
            }
        }
        $codes = $_POST['reason_codes'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }
        if ($ok && $aid !== '' && $reviewer !== '') {
            $notes = (string)($_POST['notes'] ?? '');
            $exercise = (string)($_POST['exercise_id'] ?? '');
            $pdo->prepare(
                'INSERT OR REPLACE INTO phase10c_clinic_review
                (attempt_id,reviewer_id,reviewed_at,exercise_id,boundary_verdict,objective_verdict,tolerance_verdict,
                 procedure_verdict,independence_verdict,consistency_verdict,overall_verdict,reason_codes_json,
                 narrative_notes,analysis_version,generated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $aid,
                $reviewer,
                gmdate('c'),
                $exercise !== '' ? $exercise : null,
                $fields['boundary_verdict'],
                $fields['objective_verdict'],
                $fields['tolerance_verdict'],
                $fields['procedure_verdict'],
                $fields['independence_verdict'],
                $fields['consistency_verdict'],
                $fields['overall_verdict'],
                json_encode(array_values($codes)),
                $notes,
                'phase10c-v1',
                gmdate('c'),
            ]);
            // Mirror overall into phase8 for compatibility (genuine human only)
            $pdo->prepare('UPDATE phase8_examiner_review SET verdict=?, reason_codes_json=?, narrative_notes=?, reviewed_at=? WHERE attempt_id=? AND reviewer_id=?')
                ->execute([$fields['overall_verdict'], json_encode(array_values($codes)), $notes, gmdate('c'), $aid, $reviewer]);
            $msg = 'Dimensional clinic review saved (human only). Official grades untouched. Re-run phase10c pipeline to refresh gates.';
        } else {
            $msg = 'All dimensional verdicts required (CORRECT/PARTIALLY_CORRECT/INCORRECT/INSUFFICIENT_EVIDENCE).';
        }
    }
    if ($action === 'workload_finish') {
        $elapsed = (int)(microtime(true) * 1000) - (int)($_POST['opened_at_ms'] ?? $t0);
        $payload = [
            'taps' => (int)($_POST['tap_count'] ?? 0),
            'corrections' => (int)($_POST['corrections'] ?? 0),
            'observations' => (int)($_POST['added_observations'] ?? 0),
            'drilldowns' => (int)($_POST['evidence_drilldowns'] ?? 0),
            'exceptions' => (int)($_POST['exception_count'] ?? 0),
            'segment' => (string)($_POST['segment'] ?? 'routine'),
            'reason' => (string)($_POST['reason_code'] ?? 'other'),
        ];
        $pdo->prepare('INSERT INTO shadow_workload_event (shadow_session_id,instructor_id,event_type,elapsed_ms,payload_json,analysis_version,generated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([(string)($_POST['shadow_session_id'] ?? 'phase10c'), 'pilot_instructor', 'live_review_finish', $elapsed, json_encode($payload), 'phase10c-v1', gmdate('c')]);
        $reason = (string)($_POST['reason_code'] ?? 'other');
        $pdo->prepare('UPDATE phase10c_workload_reason SET n = COALESCE(n,0)+1 WHERE reason_code=?')->execute([$reason]);
        $msg = 'Workload event stored.';
    }
    if ($action === 'exception_rating') {
        $rating = (string)($_POST['rating'] ?? '');
        if (in_array($rating, ['USEFUL', 'NEUTRAL', 'NOISY', 'WRONG'], true)) {
            $pdo->prepare('UPDATE phase10c_exception_snr SET n = COALESCE(n,0)+1 WHERE rating=?')->execute([$rating]);
            $msg = "Exception rated $rating";
        }
    }
    if ($action === 'claim_review') {
        $cid = (string)($_POST['claim_id'] ?? ('claim_' . bin2hex(random_bytes(4))));
        $support = (string)($_POST['support_class'] ?? '');
        $ctype = (string)($_POST['claim_type'] ?? 'other');
        if (in_array($support, ['FULLY_SUPPORTED', 'PARTIALLY_SUPPORTED', 'UNSUPPORTED', 'MISLEADING'], true)) {
            $pdo->prepare('INSERT OR REPLACE INTO phase10c_claim_review (claim_id,claim_type,support_class,notes,analysis_version,generated_at) VALUES (?,?,?,?,?,?)')
                ->execute([$cid, $ctype, $support, (string)($_POST['notes'] ?? ''), 'phase10c-v1', gmdate('c')]);
            $pdo->prepare('UPDATE phase10c_claim_rate SET n = COALESCE(n,0)+1 WHERE claim_type=? AND support_class=?')
                ->execute([$ctype, $support]);
            // preserve system vs human
            $pdo->prepare('INSERT INTO phase10c_system_human (entity_ref,system_proposal_json,human_correction_json,final_human_confirmed_json,analysis_version,generated_at) VALUES (?,?,?,?,?,?)')
                ->execute([$cid, (string)($_POST['system_json'] ?? '{}'), json_encode(['support' => $support]), json_encode(['support' => $support]), 'phase10c-v1', gmdate('c')]);
            $msg = 'Claim review saved; system proposal preserved separately.';
        }
    }
    if ($action === 'debrief_acceptance') {
        $acc = (string)($_POST['acceptance'] ?? '');
        if (in_array($acc, ['ACCEPT', 'ACCEPT_WITH_MINOR_EDITS', 'MAJOR_CORRECTION', 'REJECT', 'INSUFFICIENT_EVIDENCE'], true)) {
            $pdo->prepare('INSERT OR REPLACE INTO phase10c_debrief_acceptance (shadow_session_id,acceptance,reason,analysis_version,generated_at) VALUES (?,?,?,?,?)')
                ->execute([(string)($_POST['shadow_session_id'] ?? 'unknown'), $acc, (string)($_POST['reason'] ?? ''), 'phase10c-v1', gmdate('c')]);
            $msg = 'Debrief acceptance saved.';
        }
    }
    if ($action === 'transcript_human') {
        $op = (string)($_POST['operational_session_uuid'] ?? '');
        $q = (string)($_POST['quality_class'] ?? '');
        if ($op !== '' && in_array($q, $allowedTx, true)) {
            $useful = in_array($q, ['GOOD', 'USABLE'], true) ? 1 : 0;
            $pdo->prepare('UPDATE phase10c_transcript_quality SET quality_class=?, transcript_useful=?, classification_source=?, speaker_notes=?, latency_notes=? WHERE operational_session_uuid=?')
                ->execute([
                    $q,
                    $useful,
                    'HUMAN_REVIEW',
                    (string)($_POST['speaker_notes'] ?? ''),
                    (string)($_POST['notes'] ?? ''),
                    $op,
                ]);
            $pdo->prepare('UPDATE phase10c_review_queue SET status=? WHERE queue_type=? AND ref_id=? AND status=?')
                ->execute(['DONE', 'TRANSCRIPT', $op, 'OPEN']);
            $msg = 'Human transcript classification saved (PRESENT≠USEFUL enforced by class).';
        }
    }
    if ($action === 'queue_done') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        if ($qid > 0 && $inv) {
            $inv->prepare('UPDATE phase10c_review_queue SET status=? WHERE queue_id=?')->execute(['DONE', $qid]);
            $msg = "Queue item $qid marked DONE";
        }
    }
}header('Content-Type: text/html; charset=utf-8');
$verdict = $pdo->query('SELECT verdict, rationale, generated_at FROM phase10c_overall_verdict ORDER BY generated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
$freeze = $pdo->query('SELECT * FROM phase10c_cohort_freeze WHERE freeze_id="phase10c-live-freeze-v1"')->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Phase 10C Live Validation</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;font-size:13px;margin:16px;max-width:1200px}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:4px 6px;vertical-align:top}
.msg{background:#eef;padding:8px}.nav a{margin-right:10px}.bad{color:#900}.warn{color:#860}.ok{color:#060}
.card{border:1px solid #666;padding:10px;margin:10px 0}button,select,textarea,input{font:inherit}
</style></head><body>
<div class="nav">
<a href="?view=home">Home</a>
<a href="?view=cohort">Frozen cohort</a>
<a href="?view=evidence">Evidence gaps</a>
<a href="?view=queues">Review queues</a>
<a href="?view=clinic">Examiner clinic</a>
<a href="?view=adjudication">Adjudication</a>
<a href="?view=gates">Exit gates</a>
<a href="?view=workload">Workload</a>
<a href="?view=claims">Claims</a>
<a href="?view=transcript">Transcripts</a>
<a href="?view=attempts">Attempt denominators</a>
<a href="?view=llm">LLM progress</a>
</div>
<h1>Phase 10C — Live Validation Closure</h1>
<p class="warn">Architecture frozen. Human reviews only — no fabricated examiner verdicts. Official training untouched. Flags OFF.</p>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($view === 'home'): ?>
<h2 class="bad">Verdict: <?= htmlspecialchars((string)($verdict['verdict'] ?? 'UNKNOWN')) ?></h2>
<pre><?= htmlspecialchars((string)($verdict['rationale'] ?? '')) ?></pre>
<h3>Frozen cohort</h3>
<pre><?= htmlspecialchars(json_encode($freeze, JSON_PRETTY_PRINT)) ?></pre>
<h3>Clinic progress</h3>
<table><tr><th>metric</th><th>value</th><th>n</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_clinic_progress') as $r): ?>
<tr><td><?= htmlspecialchars($r['metric_name']) ?></td><td><?= htmlspecialchars((string)$r['metric_value']) ?></td><td><?= (int)$r['n'] ?></td><td><?= htmlspecialchars((string)$r['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<h3>Exact remaining blockers</h3>
<table><tr><th>gate</th><th>why</th><th>required action</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_blocker') as $b): ?>
<tr><td class="bad"><?= htmlspecialchars($b['gate_code']) ?></td><td><?= htmlspecialchars($b['why']) ?></td><td><?= htmlspecialchars($b['required_action']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'cohort'): ?>
<h2>Frozen LIVE_PRODUCTION_SHADOW cohort (<?= htmlspecialchars((string)($freeze['freeze_id'] ?? '')) ?>)</h2>
<table><tr><th>dimension</th><th>value</th><th>n</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_cohort_composition WHERE freeze_id="phase10c-live-freeze-v1" ORDER BY dimension, value') as $c): ?>
<tr><td><?= htmlspecialchars($c['dimension']) ?></td><td><?= htmlspecialchars($c['value']) ?></td><td><?= (int)$c['n'] ?></td></tr>
<?php endforeach; ?>
</table>
<h3>Source partitions (do not mix)</h3>
<table><tr><th>class</th><th>n</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_source_partition') as $s): ?>
<tr><td><?= htmlspecialchars($s['source_class']) ?></td><td><?= (int)$s['n_sessions'] ?></td><td><?= htmlspecialchars((string)$s['notes']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'clinic'): ?>
<h2>Examiner clinic — dual dimensional reviews</h2>
<p>A case is complete only when <strong>both</strong> reviewers fill all required fields. Partial = not complete.</p>
<?php
$dimSelect = function (string $name) use ($allowedVerdict): string {
    $html = '<select name="' . htmlspecialchars($name) . '" required><option value="">—</option>';
    foreach ($allowedVerdict as $o) {
        $html .= '<option>' . $o . '</option>';
    }
    return $html . '</select>';
};
foreach ($pdo->query("SELECT attempt_id, reviewer_id, overall_verdict, boundary_verdict FROM phase10c_clinic_review ORDER BY attempt_id, reviewer_id LIMIT 80") as $r):
?>
<div class="card">
<strong><?= htmlspecialchars($r['attempt_id']) ?></strong> / <?= htmlspecialchars($r['reviewer_id']) ?>
 overall=<?= htmlspecialchars((string)$r['overall_verdict']) ?> boundary=<?= htmlspecialchars((string)$r['boundary_verdict']) ?>
<?php if (($r['overall_verdict'] ?? 'PENDING') === 'PENDING' || ($r['boundary_verdict'] ?? 'PENDING') === 'PENDING'): ?>
<form method="post">
<input type="hidden" name="action" value="clinic_dimensional">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($r['attempt_id']) ?>">
<input type="hidden" name="reviewer_id" value="<?= htmlspecialchars($r['reviewer_id']) ?>">
exercise <input name="exercise_id" size="16">
boundary <?= $dimSelect('boundary_verdict') ?>
objective <?= $dimSelect('objective_verdict') ?>
tolerance <?= $dimSelect('tolerance_verdict') ?>
procedure <?= $dimSelect('procedure_verdict') ?>
independence <?= $dimSelect('independence_verdict') ?>
consistency <?= $dimSelect('consistency_verdict') ?>
overall <?= $dimSelect('overall_verdict') ?>
<?php foreach (['BOUNDARY_WRONG','WRONG_TOLERANCE','METRIC_EXTRACTION_WRONG','INDEPENDENCE_WRONG','CONSISTENCY_WRONG','AI_OVERINTERPRETATION','OBJECTIVE_DATA_INSUFFICIENT','OTHER'] as $c): ?>
<label><input type="checkbox" name="reason_codes[]" value="<?= $c ?>"> <?= $c ?></label>
<?php endforeach; ?>
<textarea name="notes" rows="2" cols="60"></textarea>
<button type="submit">Save genuine review</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php elseif ($view === 'adjudication'): ?>
<h2>Adjudication queue (do not auto-pick a winner)</h2>
<table><tr><th>attempt</th><th>class</th><th>status</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_adjudication_queue') as $q): ?>
<tr><td><?= htmlspecialchars($q['attempt_id']) ?></td><td><?= htmlspecialchars((string)$q['disagreement_class']) ?></td><td><?= htmlspecialchars((string)$q['status']) ?></td><td><?= htmlspecialchars((string)$q['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php if (!$pdo->query('SELECT COUNT(*) FROM phase10c_adjudication_queue')->fetchColumn()): ?>
<p class="warn">Empty until dual complete conflicting reviews exist.</p>
<?php endif; ?>

<?php elseif ($view === 'gates'): ?>
<table><tr><th>gate</th><th>status</th><th>notes</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_exit_gate ORDER BY gate_code') as $g): ?>
<tr>
<td><?= htmlspecialchars($g['gate_code']) ?></td>
<td class="<?= $g['status']==='PASS'?'ok':(in_array($g['status'],['FAIL','BLOCKED'],true)?'bad':'warn') ?>"><?= htmlspecialchars($g['status']) ?></td>
<td><?= htmlspecialchars((string)$g['evidence_notes']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<h3>Maneuver dispositions</h3>
<?php foreach ($pdo->query('SELECT * FROM phase10c_maneuver_disposition') as $m): ?>
<div class="card"><?= htmlspecialchars($m['canonical_exercise_id']) ?>: <strong><?= htmlspecialchars($m['disposition']) ?></strong>
 live_attempts=<?= (int)$m['live_attempt_count'] ?> reviewed=<?= (int)$m['reviewed_attempt_count'] ?>
 <?= htmlspecialchars((string)$m['rationale']) ?></div>
<?php endforeach; ?>

<?php elseif ($view === 'workload'): ?>
<table><tr><th>segment</th><th>n</th><th>median</th><th>p75</th><th>p90</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_workload') as $w): ?>
<tr><td><?= htmlspecialchars($w['segment']) ?></td><td><?= (int)$w['n'] ?></td><td><?= htmlspecialchars((string)$w['median_min']) ?></td><td><?= htmlspecialchars((string)$w['p75_min']) ?></td><td><?= htmlspecialchars((string)$w['p90_min']) ?></td></tr>
<?php endforeach; ?>
</table>
<form method="post" class="card">
<input type="hidden" name="action" value="workload_finish">
<input type="hidden" name="opened_at_ms" value="<?= (int)(microtime(true)*1000) ?>">
session <input name="shadow_session_id">
segment <select name="segment"><option>routine</option><option>complex</option><option>high_exercise_count</option></select>
reason <select name="reason_code">
<?php foreach (['independence_input','boundary_correction','bad_transcript','too_many_exceptions','ai_wording_correction','procedure_review','missing_evidence','narrative_writing','other'] as $r): ?>
<option><?= $r ?></option>
<?php endforeach; ?>
</select>
taps <input name="tap_count" type="number" value="0" style="width:4em">
corrections <input name="corrections" type="number" value="0" style="width:4em">
exceptions <input name="exception_count" type="number" value="0" style="width:4em">
drilldowns <input name="evidence_drilldowns" type="number" value="0" style="width:4em">
<button type="submit">Finish review</button>
</form>
<h3>Exception SNR</h3>
<form method="post"><input type="hidden" name="action" value="exception_rating">
<select name="rating"><option>USEFUL</option><option>NEUTRAL</option><option>NOISY</option><option>WRONG</option></select>
<button type="submit">Rate exception</button></form>
<table><tr><th>rating</th><th>n</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_exception_snr') as $e): ?>
<tr><td><?= htmlspecialchars($e['rating']) ?></td><td><?= (int)$e['n'] ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'claims'): ?>
<form method="post" class="card">
<input type="hidden" name="action" value="claim_review">
claim_id <input name="claim_id">
type <select name="claim_type"><option>deficiency</option><option>improvement</option><option>independence</option><option>consistency</option><option>procedure</option><option>regression</option><option>safety</option><option>next_focus</option></select>
support <select name="support_class"><option>FULLY_SUPPORTED</option><option>PARTIALLY_SUPPORTED</option><option>UNSUPPORTED</option><option>MISLEADING</option></select>
<textarea name="notes" rows="2" cols="50"></textarea>
<textarea name="system_json" rows="2" cols="50" placeholder="system proposal JSON preserved"></textarea>
<button type="submit">Save claim review</button>
</form>
<form method="post" class="card">
<input type="hidden" name="action" value="debrief_acceptance">
session <input name="shadow_session_id">
<select name="acceptance"><option>ACCEPT</option><option>ACCEPT_WITH_MINOR_EDITS</option><option>MAJOR_CORRECTION</option><option>REJECT</option><option>INSUFFICIENT_EVIDENCE</option></select>
<textarea name="reason" rows="2" cols="50"></textarea>
<button type="submit">Save debrief acceptance</button>
</form>

<?php elseif ($view === 'evidence'): ?>
<h2>Why FULL_EVIDENCE = 0</h2>
<?php if (!$inv): ?><p class="bad">Run phase10c_02_investigations.py first.</p>
<?php else: ?>
<table><tr><th>metric</th><th>value</th><th>notes</th></tr>
<?php foreach ($inv->query('SELECT * FROM phase10c_evidence_summary ORDER BY metric_name') as $r): ?>
<tr><td><?= htmlspecialchars($r['metric_name']) ?></td><td><?= htmlspecialchars((string)$r['metric_value']) ?></td><td><?= htmlspecialchars((string)$r['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<h3>Crew linkage findings</h3>
<table><tr><th>finding</th><th>n</th><th>notes</th></tr>
<?php foreach ($inv->query('SELECT * FROM phase10c_linkage_finding ORDER BY n DESC') as $r): ?>
<tr><td><?= htmlspecialchars($r['finding_code']) ?></td><td><?= (int)$r['n'] ?></td><td><?= htmlspecialchars((string)$r['notes']) ?></td></tr>
<?php endforeach; ?>
</table>
<p class="warn">Correct join: <code>ipca_flight_sessions.reservation_uuid = ipca_flight_schedule_slots.scheduler_record_id</code> and <code>crew.schedule_slot_id = slots.id</code>. Many live sessions have no reservation/dispatch.</p>
<?php endif; ?>

<?php elseif ($view === 'queues'): ?>
<h2>Human review queues (no fabricated answers)</h2>
<?php if (!$inv): ?><p class="bad">Run phase10c_02_investigations.py first.</p>
<?php else: ?>
<table><tr><th>id</th><th>type</th><th>ref</th><th>priority</th><th>status</th><th>reason</th><th></th></tr>
<?php foreach ($inv->query("SELECT * FROM phase10c_review_queue WHERE status='OPEN' ORDER BY priority, queue_id LIMIT 120") as $q): ?>
<tr>
<td><?= (int)$q['queue_id'] ?></td>
<td><?= htmlspecialchars($q['queue_type']) ?></td>
<td><?= htmlspecialchars($q['ref_id']) ?></td>
<td><?= (int)$q['priority'] ?></td>
<td><?= htmlspecialchars((string)$q['status']) ?></td>
<td><?= htmlspecialchars((string)$q['reason']) ?></td>
<td>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="queue_done">
<input type="hidden" name="queue_id" value="<?= (int)$q['queue_id'] ?>">
<button type="submit">Mark done</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php elseif ($view === 'attempts'): ?>
<h2>Attempt-centric maneuver denominators (not session count)</h2>
<?php if (!$inv): ?><p class="bad">Run phase10c_02_investigations.py first.</p>
<?php else: ?>
<table><tr><th>maneuver</th><th>total attempts</th><th>usable boundary</th><th>usable telemetry</th><th>examiner reviewed</th><th>disposition</th><th>notes</th></tr>
<?php foreach ($inv->query('SELECT * FROM phase10c_attempt_denominator') as $a): ?>
<tr>
<td><?= htmlspecialchars($a['canonical_exercise_id']) ?></td>
<td><?= (int)$a['total_live_attempts'] ?></td>
<td><?= (int)$a['usable_boundary'] ?></td>
<td><?= (int)$a['usable_telemetry'] ?></td>
<td><?= (int)$a['examiner_reviewed'] ?></td>
<td><?= htmlspecialchars((string)$a['disposition']) ?></td>
<td><?= htmlspecialchars((string)$a['notes']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php elseif ($view === 'transcript'): ?>
<table><tr><th>session</th><th>aircraft</th><th>class</th><th>present</th><th>useful</th><th>source</th><th>human review</th></tr>
<?php foreach ($pdo->query('SELECT * FROM phase10c_transcript_quality LIMIT 100') as $t): ?>
<tr>
<td><?= htmlspecialchars($t['operational_session_uuid']) ?></td>
<td><?= htmlspecialchars((string)$t['aircraft']) ?></td>
<td><?= htmlspecialchars($t['quality_class']) ?></td>
<td><?= (int)$t['transcript_present'] ?></td>
<td><?= (int)$t['transcript_useful'] ?></td>
<td><?= htmlspecialchars($t['classification_source']) ?></td>
<td>
<form method="post">
<input type="hidden" name="action" value="transcript_human">
<input type="hidden" name="operational_session_uuid" value="<?= htmlspecialchars($t['operational_session_uuid']) ?>">
<select name="quality_class"><?php foreach ($allowedTx as $o): ?><option <?= $t['quality_class']===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select>
<input name="speaker_notes" placeholder="speaker/ATC notes" size="24">
<input name="notes" placeholder="alignment/prompts" size="24">
<button type="submit">Save human class</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</table>
<p class="warn">SYSTEM_PROVISIONAL until HUMAN_REVIEW. PRESENT ≠ USEFUL.</p>

<?php elseif ($view === 'llm'): ?>
<?php
$llm = null;
if ($inv) {
    $llm = $inv->query('SELECT * FROM phase10c_llm_progress_snapshot ORDER BY generated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$llm) {
    $llm = $pdo->query('SELECT * FROM phase10c_llm_progress')->fetch(PDO::FETCH_ASSOC) ?: [];
}
?>
<pre><?= htmlspecialchars(json_encode($llm, JSON_PRETTY_PRINT)) ?></pre>
<p class="warn">Incomplete historical NLP must not block clinic / live validation.</p>
<?php endif; ?>
</body></html>
