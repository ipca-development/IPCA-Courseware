<?php
/**
 * Phase 8 development prototypes: examiner clinic, instructor confirmation, student debrief.
 * NOT production UI. No polish.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/RuntimeSecrets.php';
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
if (!is_file($dbPath)) {
    http_response_code(500);
    echo 'Analytics DB missing — run analytics/etl/phase8_01_pipeline.py';
    exit;
}
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$view = $_GET['view'] ?? 'home';
$msg = '';

$REASON_CODES = [
    'BOUNDARY_WRONG', 'WRONG_EXERCISE_MAPPING', 'WRONG_TOLERANCE', 'METRIC_EXTRACTION_WRONG',
    'CONTEXT_MISSING', 'CONTEXT_MISINTERPRETED', 'INDEPENDENCE_WRONG', 'CONSISTENCY_WRONG',
    'AI_OVERINTERPRETATION', 'HUMAN_FACTOR_NOT_CAPTURED', 'PROCEDURAL_ISSUE_NOT_CAPTURED',
    'OBJECTIVE_DATA_INSUFFICIENT', 'OTHER',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'examiner_submit') {
        $aid = (string)($_POST['attempt_id'] ?? '');
        $reviewer = (string)($_POST['reviewer_id'] ?? 'examiner_A');
        $verdict = (string)($_POST['verdict'] ?? 'PENDING');
        $codes = $_POST['reason_codes'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }
        $notes = (string)($_POST['notes'] ?? '');
        if (in_array($verdict, ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'INSUFFICIENT_EVIDENCE'], true)) {
            $pdo->prepare('UPDATE phase8_examiner_review SET verdict=?, reason_codes_json=?, narrative_notes=?, reviewed_at=? WHERE attempt_id=? AND reviewer_id=? AND verdict=?')
                ->execute([$verdict, json_encode(array_values($codes)), $notes, gmdate('c'), $aid, $reviewer, 'PENDING']);
            $msg = "Saved $verdict for $aid / $reviewer";
        }
    }
    if ($action === 'confirm_independence_group') {
        $gid = (string)($_POST['group_id'] ?? '');
        $state = (string)($_POST['final_demonstrated_state'] ?? 'NOT_OBSERVED');
        if (!in_array($state, ['ASSISTED', 'PROMPTED', 'INDEPENDENT'], true)) {
            $state = 'NOT_OBSERVED';
        }
        $pdo->prepare('UPDATE phase8_independence_group SET final_demonstrated_state=?, instructor_confirmation=? WHERE group_id=?')
            ->execute([$state, 'CONFIRMED', $gid]);
        // propagate to linked attempts
        $row = $pdo->prepare('SELECT attempt_ids_json FROM phase8_independence_group WHERE group_id=?');
        $row->execute([$gid]);
        $aids = json_decode((string)$row->fetchColumn(), true) ?: [];
        foreach ($aids as $aid) {
            $pdo->prepare('INSERT INTO pilot_independence_observation (attempt_id,independence_state,source,captured_at,analysis_version,generated_at) VALUES (?,?,?,?,?,?)')
                ->execute([$aid, $state, 'INSTRUCTOR_GROUP_CONFIRM', gmdate('c'), 'phase8-v1', gmdate('c')]);
            $pdo->prepare('UPDATE pilot_competency_state SET independence_state=?, independence_source=? WHERE attempt_id=?')
                ->execute([$state, 'INSTRUCTOR_GROUP_CONFIRM', $aid]);
        }
        $msg = "Group independence confirmed: $state";
    }
    if ($action === 'approve_debrief') {
        $pdo->exec("UPDATE phase8_reference_flight SET debrief_json = json_set(COALESCE(debrief_json,'{}'), '$.instructor_confirmation', 'APPROVED') WHERE reference_id='REF_FLIGHT'");
        // sqlite json_set may not exist on older builds — fallback store note
        $msg = 'Debrief approval recorded (prototype).';
    }
}

header('Content-Type: text/html; charset=utf-8');
$openai = RuntimeSecrets::peekStatus('OPENAI_API_KEY');
$dbpass = RuntimeSecrets::peekStatus('CW_DB_PASS');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Phase 8 Evidence Debrief (dev)</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;font-size:13px;margin:16px;max-width:1100px}
table{border-collapse:collapse;width:100%;margin:8px 0}
td,th{border:1px solid #bbb;padding:4px 6px;vertical-align:top}
.msg{background:#eef;padding:8px;margin:8px 0}
.card{border:1px solid #666;padding:10px;margin:10px 0;white-space:pre-wrap}
.nav a{margin-right:12px}
.bad{color:#900}.ok{color:#060}
button,select,input,textarea{font:inherit}
</style></head><body>
<div class="nav">
<a href="?view=home">Home</a>
<a href="?view=clinic">Examiner clinic</a>
<a href="?view=instructor">Instructor confirmation</a>
<a href="?view=student">Student debrief</a>
<a href="?view=exceptions">Exception queue</a>
</div>
<h1>Phase 8 — Evidence → Debrief (dev prototype)</h1>
<p>Human-confirmed interpretation of traceable flight evidence. AI is advisory only.</p>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($view === 'home'): ?>
<h2>Runtime secrets (shapes only)</h2>
<pre>OPENAI: <?= htmlspecialchars(json_encode($openai)) ?>

DB_PASS: <?= htmlspecialchars(json_encode($dbpass)) ?></pre>
<?php
$ref = $pdo->query("SELECT * FROM phase8_reference_flight WHERE reference_id='REF_FLIGHT'")->fetch(PDO::FETCH_ASSOC);
$comp = $pdo->query("SELECT * FROM phase8_evidence_completeness LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$gates = $pdo->query("SELECT gate_code,status FROM phase8_acceptance_gate")->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Reference flight</h2>
<?php if ($ref): ?>
<pre>session=<?= htmlspecialchars((string)$ref['operational_session_id']) ?>
recording=<?= htmlspecialchars((string)$ref['recording_uid']) ?>
aircraft=<?= htmlspecialchars((string)$ref['aircraft']) ?>
completeness=<?= htmlspecialchars((string)$ref['evidence_completeness']) ?>
</pre>
<p>Drill-down contracts:
 <a href="/admin/cockpit_recorder_replay.php?id=22">replay</a> ·
 <a href="/admin/cockpit_recorder_audio.php?id=22">audio</a> ·
 <a href="/admin/cockpit_recorder_g3x.php?id=22">g3x</a>
</p>
<?php else: ?>
<p class="bad">No reference flight — run phase8_01_pipeline.py</p>
<?php endif; ?>
<h2>Evidence completeness</h2>
<pre><?= htmlspecialchars(json_encode($comp, JSON_PRETTY_PRINT)) ?></pre>
<h2>Acceptance gates</h2>
<table><tr><th>gate</th><th>status</th></tr>
<?php foreach ($gates as $g): ?>
<tr><td><?= htmlspecialchars($g['gate_code']) ?></td><td><?= htmlspecialchars($g['status']) ?></td></tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'clinic'): ?>
<h2>Examiner clinic</h2>
<p>Dual reviewers (A/B). Reason codes required when not CORRECT.</p>
<?php
$rows = $pdo->query("SELECT review_id,attempt_id,reviewer_id,verdict,reviewed_dimensions_json FROM phase8_examiner_review ORDER BY attempt_id,reviewer_id LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
?>
<table>
<tr><th>attempt</th><th>reviewer</th><th>verdict</th><th>dims</th><th>submit</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><?= htmlspecialchars($r['attempt_id']) ?></td>
<td><?= htmlspecialchars($r['reviewer_id']) ?></td>
<td><?= htmlspecialchars($r['verdict']) ?></td>
<td><code><?= htmlspecialchars(substr((string)$r['reviewed_dimensions_json'], 0, 120)) ?></code></td>
<td>
<?php if ($r['verdict'] === 'PENDING'): ?>
<form method="post">
<input type="hidden" name="action" value="examiner_submit">
<input type="hidden" name="attempt_id" value="<?= htmlspecialchars($r['attempt_id']) ?>">
<input type="hidden" name="reviewer_id" value="<?= htmlspecialchars($r['reviewer_id']) ?>">
<select name="verdict">
<option>CORRECT</option><option>PARTIALLY_CORRECT</option><option>INCORRECT</option><option>INSUFFICIENT_EVIDENCE</option>
</select>
<?php foreach ($REASON_CODES as $c): ?>
<label><input type="checkbox" name="reason_codes[]" value="<?= $c ?>"> <?= $c ?></label>
<?php endforeach; ?>
<textarea name="notes" rows="2" cols="40" placeholder="optional narrative"></textarea>
<button type="submit">Save</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>

<?php elseif ($view === 'instructor'): ?>
<h2>Instructor confirmation (exception-based)</h2>
<ol>
<li>Review exception queue only</li>
<li>Confirm/correct independence <strong>per exercise group</strong></li>
<li>Review interventions</li>
<li>Review system deficiencies</li>
<li>Optional observation</li>
<li>Approve debrief</li>
</ol>
<h3>Independence groups (one tap each)</h3>
<?php
$groups = $pdo->query("SELECT * FROM phase8_independence_group")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g): ?>
<div class="card">Exercise: <?= htmlspecialchars($g['canonical_exercise_id']) ?>
Attempts: <?= htmlspecialchars($g['attempt_ids_json']) ?>
Current: <?= htmlspecialchars($g['final_demonstrated_state']) ?> (<?= htmlspecialchars($g['instructor_confirmation']) ?>)
SYSTEM_SUGGESTED: <?= htmlspecialchars((string)$g['system_suggested_independence']) ?>
Rationale: <?= htmlspecialchars((string)$g['suggestion_rationale']) ?>

<form method="post">
<input type="hidden" name="action" value="confirm_independence_group">
<input type="hidden" name="group_id" value="<?= htmlspecialchars($g['group_id']) ?>">
<button name="final_demonstrated_state" value="ASSISTED">ASSISTED</button>
<button name="final_demonstrated_state" value="PROMPTED">PROMPTED</button>
<button name="final_demonstrated_state" value="INDEPENDENT">INDEPENDENT</button>
</form>
</div>
<?php endforeach; ?>
<form method="post"><input type="hidden" name="action" value="approve_debrief"><button type="submit">Approve debrief</button></form>

<?php elseif ($view === 'exceptions'): ?>
<h2>Exception queue</h2>
<?php
$items = $pdo->query("SELECT priority_rank,canonical_exercise_id,priority_reason,payload_json FROM phase8_debrief_item ORDER BY priority_rank")->fetchAll(PDO::FETCH_ASSOC);
foreach ($items as $it):
  $p = json_decode((string)$it['payload_json'], true) ?: [];
  if (empty($p['development_items']) && strpos((string)$it['priority_reason'], 'routine_success') !== false) {
      continue;
  }
?>
<div class="card">#<?= (int)$it['priority_rank'] ?> <?= htmlspecialchars($it['canonical_exercise_id']) ?>
Reasons: <?= htmlspecialchars((string)$it['priority_reason']) ?>

WHAT NEEDS DEVELOPMENT:
<?= htmlspecialchars(implode("\n", $p['development_items'] ?? [])) ?>

OBJECTIVE:
<?php foreach ($p['objective_quality'] ?? [] as $m): ?>
- <?= htmlspecialchars($m['metric']) ?> within=<?= (int)$m['within'] ?> max_dev=<?= htmlspecialchars((string)$m['max_dev']) ?>

<?php endforeach; ?>
Drill-down: <?= htmlspecialchars($p['supporting_evidence']['replay_link'] ?? '') ?>
</div>
<?php endforeach; ?>

<?php elseif ($view === 'student'): ?>
<h2>Student debrief (dev)</h2>
<p>Tone and assessment are separate. Supportive ≠ inaccurate.</p>
<?php
$ref = $pdo->query("SELECT debrief_json, evidence_completeness FROM phase8_reference_flight WHERE reference_id='REF_FLIGHT'")->fetch(PDO::FETCH_ASSOC);
$d = $ref ? (json_decode((string)$ref['debrief_json'], true) ?: []) : [];
?>
<div class="card"><strong>Evidence completeness:</strong> <?= htmlspecialchars((string)($ref['evidence_completeness'] ?? 'UNKNOWN')) ?>

=== WHAT WENT WELL ===
<?= htmlspecialchars(implode("\n", array_unique($d['what_went_well'] ?? []))) ?>

=== WHAT NEEDS DEVELOPMENT ===
<?= htmlspecialchars(implode("\n", array_unique($d['what_needs_development'] ?? []))) ?>

=== WHAT THE DATA SHOWED ===
<?= htmlspecialchars(implode("\n", array_slice($d['what_the_data_showed'] ?? [], 0, 12))) ?>

=== WHAT TO FOCUS ON NEXT ===
<?= htmlspecialchars(implode("\n", array_unique($d['what_to_focus_on_next'] ?? []))) ?>
</div>
<?php
$recs = $pdo->query("SELECT recommendation_text, rule_code FROM phase8_recommendation WHERE reference_id='REF_FLIGHT' AND rule_code != 'CURRICULUM_ADAPTATION_RESEARCH_ONLY'")->fetchAll(PDO::FETCH_ASSOC);
?>
<h3>Recommendations (rule-based)</h3>
<ul>
<?php foreach ($recs as $r): ?>
<li>[<?= htmlspecialchars($r['rule_code']) ?>] <?= htmlspecialchars($r['recommendation_text']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</body></html>
