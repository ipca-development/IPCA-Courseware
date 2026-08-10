<?php
declare(strict_types=1);

/**
 * Phase 4 bootstrap: analysis tables, mission roles, narrative sample.
 * Analytics SQLite only. No E-gle writes.
 */

$root = dirname(__DIR__, 2);
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
require_once $root . '/analytics/lib/mission_role.php';

$VERSION = 'phase4-v1';
$NOW = gmdate('c');

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');

$sql = file_get_contents($root . '/analytics/schema/phase4_tables.sql');
// Strip line comments then split statements.
$lines = [];
foreach (explode("\n", (string)$sql) as $line) {
    $trim = ltrim($line);
    if (str_starts_with($trim, '--')) {
        continue;
    }
    $lines[] = $line;
}
$cleaned = implode("\n", $lines);
foreach (array_filter(array_map('trim', explode(';', $cleaned))) as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $pdo->exec($stmt);
}

$pdo->exec("DELETE FROM analysis_meta");
$pdo->prepare('INSERT INTO analysis_meta (analysis_version, generated_at, notes) VALUES (?,?,?)')
    ->execute([$VERSION, $NOW, 'Phase 4 deep training effectiveness analysis']);

echo "Computing mission context stats...\n";
$ctxRows = $pdo->query("
    SELECT s.mission_id,
           COUNT(DISTINCT s.student_id) student_count,
           COUNT(*) sessions,
           AVG(COALESCE(s.mission_attempt_number,1)) avg_attempts,
           AVG(CASE WHEN COALESCE(s.mission_attempt_number,1) > 1 THEN 1.0 ELSE 0.0 END) share_repeat,
           SUM(CASE WHEN COALESCE(s.mission_attempt_number,1) > 1 THEN 1 ELSE 0 END) * 1.0
             / NULLIF(COUNT(DISTINCT s.student_id),0) extra_per_student,
           AVG(CASE WHEN s.grading_completion='I' THEN 1.0 ELSE 0.0 END) share_incomplete
    FROM fact_training_session s
    WHERE s.session_date_valid=1 AND s.mission_id IS NOT NULL
      AND s.qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
    GROUP BY s.mission_id
")->fetchAll(PDO::FETCH_ASSOC);
$ctx = [];
foreach ($ctxRows as $r) {
    $ctx[(int)$r['mission_id']] = $r;
}

echo "Classifying mission roles...\n";
$pdo->exec('DELETE FROM analysis_mission_role');
$ins = $pdo->prepare('INSERT INTO analysis_mission_role (
  mission_id, source_scenario_id, program_id, mission_code, mission_name, source_session_type, event_class,
  mission_role, mission_role_confidence, mission_role_reason, analysis_version, generated_at
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

$missions = $pdo->query("
    SELECT m.*, p.program_id AS prog_join
    FROM dim_mission m
    LEFT JOIN dim_program p ON p.source_program_id = m.source_program_id
")->fetchAll(PDO::FETCH_ASSOC);

$roleCounts = [];
foreach ($missions as $m) {
    $mid = (int)$m['mission_id'];
    $role = classify_mission_role($m, $ctx[$mid] ?? []);
    $programId = null;
    // resolve program via tracking sessions preferentially
    $pid = $pdo->query("SELECT program_id FROM fact_training_session WHERE mission_id={$mid} AND program_id IS NOT NULL LIMIT 1")->fetchColumn();
    if ($pid !== false) {
        $programId = (int)$pid;
    } elseif (!empty($m['prog_join'])) {
        $programId = (int)$m['prog_join'];
    }
    $ins->execute([
        $mid, (int)$m['source_scenario_id'], $programId, $m['mission_code'], $m['mission_name'],
        $m['source_session_type'], $m['event_class'],
        $role['mission_role'], $role['mission_role_confidence'], $role['mission_role_reason'],
        $VERSION, $NOW,
    ]);
    $roleCounts[$role['mission_role']] = ($roleCounts[$role['mission_role']] ?? 0) + 1;
}
echo "Mission roles: " . json_encode($roleCounts) . "\n";

echo "Selecting stratified narrative sample...\n";
$pdo->exec('DELETE FROM analysis_narrative_sample');
$narrIns = $pdo->prepare('INSERT INTO analysis_narrative_sample (
  narrative_id, session_id, program_id, sample_stratum, raw_text, text_hash, analysis_version, generated_at
) VALUES (?,?,?,?,?,?,?,?)');

$strata = [
    'below_standard' => "
        SELECT n.narrative_id, n.session_id, s.program_id, n.raw_text, n.text_hash
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id=n.session_id
        WHERE s.session_date_valid=1 AND (s.grading_color='R' OR s.grading_completion='I' OR s.exercises_below_required>0)
          AND length(n.raw_text) >= 40
        ORDER BY RANDOM() LIMIT 80
    ",
    'high_performing' => "
        SELECT n.narrative_id, n.session_id, s.program_id, n.raw_text, n.text_hash
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id=n.session_id
        WHERE s.session_date_valid=1 AND s.grading_raw='GC' AND COALESCE(s.exercises_below_required,0)=0
          AND length(n.raw_text) >= 40
        ORDER BY RANDOM() LIMIT 80
    ",
    'repeated_mission' => "
        SELECT n.narrative_id, n.session_id, s.program_id, n.raw_text, n.text_hash
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id=n.session_id
        WHERE s.session_date_valid=1 AND COALESCE(s.mission_attempt_number,1) >= 2
          AND length(n.raw_text) >= 40
        ORDER BY RANDOM() LIMIT 80
    ",
    'pe_then_regression_context' => "
        SELECT n.narrative_id, n.session_id, s.program_id, n.raw_text, n.text_hash
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id=n.session_id
        JOIN fact_exercise_attempt a ON a.session_id=s.session_id
        WHERE a.exercise_regressed=1 AND length(n.raw_text) >= 40
        ORDER BY RANDOM() LIMIT 80
    ",
    'cross_program_era' => "
        SELECT n.narrative_id, n.session_id, s.program_id, n.raw_text, n.text_hash
        FROM fact_narrative n
        JOIN fact_training_session s ON s.session_id=n.session_id
        WHERE s.session_date_valid=1 AND length(n.raw_text) >= 40
          AND substr(s.session_date,1,4) IN ('2016','2018','2020','2022','2024','2026')
        ORDER BY RANDOM() LIMIT 100
    ",
];

$seen = [];
$total = 0;
foreach ($strata as $name => $sqlStratum) {
    foreach ($pdo->query($sqlStratum) as $r) {
        $nid = (int)$r['narrative_id'];
        if (isset($seen[$nid])) {
            continue;
        }
        $seen[$nid] = true;
        $narrIns->execute([
            $nid, (int)$r['session_id'], $r['program_id'] !== null ? (int)$r['program_id'] : null,
            $name, $r['raw_text'], $r['text_hash'], $VERSION, $NOW,
        ]);
        $total++;
    }
}
echo "Narrative sample size: {$total}\n";
echo "Bootstrap complete.\n";
