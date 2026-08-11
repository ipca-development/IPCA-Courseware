<?php
declare(strict_types=1);

/**
 * Phase 5 bootstrap: create tables + enrich 405-row narrative sample with session/outcome linkage.
 * Analytics SQLite only. No E-gle writes.
 */

$root = dirname(__DIR__, 2);
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
$VERSION = 'phase5-v1';
$NOW = gmdate('c');

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');

$sql = file_get_contents($root . '/analytics/schema/phase5_tables.sql');
$lines = [];
foreach (explode("\n", (string)$sql) as $line) {
    $trim = ltrim($line);
    if (str_starts_with($trim, '--')) {
        continue;
    }
    $lines[] = $line;
}
foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $stmt) {
    if ($stmt !== '') {
        $pdo->exec($stmt);
    }
}

echo "Enriching narrative sample...\n";
$pdo->exec('DELETE FROM analysis_narrative_sample_enriched');

$rows = $pdo->query("
    SELECT ns.narrative_id, ns.session_id, ns.sample_stratum, ns.text_hash, ns.raw_text,
           s.student_id, s.instructor_id, s.program_id, s.curriculum_version_id, s.curriculum_family_id,
           s.mission_id, s.session_date, s.grading_raw, s.grading_color, s.grading_completion,
           s.exercises_below_required, s.mission_attempt_number,
           p.program_name, v.version_code, f.family_code,
           m.mission_code, m.mission_name, r.mission_role
    FROM analysis_narrative_sample ns
    JOIN fact_training_session s ON s.session_id = ns.session_id
    LEFT JOIN dim_program p ON p.program_id = s.program_id
    LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id = s.curriculum_version_id
    LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id = s.curriculum_family_id
    LEFT JOIN dim_mission m ON m.mission_id = s.mission_id
    LEFT JOIN analysis_mission_role r ON r.mission_id = s.mission_id
")->fetchAll(PDO::FETCH_ASSOC);

$ins = $pdo->prepare('INSERT INTO analysis_narrative_sample_enriched (
  narrative_id, session_id, sample_stratum, text_hash, raw_text, character_count,
  student_id, instructor_id, program_id, curriculum_version_id, curriculum_family_id,
  version_code, family_code, program_name, mission_id, mission_code, mission_name, mission_role,
  session_date, session_year, grading_raw, grading_color, grading_completion,
  exercises_below_required, mission_attempt_number, trajectory_label, pe_stability_proxy,
  later_regression_flag, later_repeat_flag, later_checkpoint_problem_flag,
  analysis_version, generated_at
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$trajStmt = $pdo->prepare('SELECT trajectory_label FROM analysis_student_trajectory WHERE student_id=? AND program_id=? LIMIT 1');

// Later outcomes within same student+program after session date
$laterReg = $pdo->prepare("
    SELECT COUNT(*) FROM fact_exercise_attempt a
    JOIN fact_training_session s2 ON s2.session_id=a.session_id
    WHERE a.student_id=? AND a.program_id=? AND a.session_date > ?
      AND a.exercise_regressed=1
");
$laterRepeat = $pdo->prepare("
    SELECT COUNT(*) FROM fact_training_session
    WHERE student_id=? AND program_id=? AND session_date > ?
      AND COALESCE(mission_attempt_number,1) > 1
      AND session_date_valid=1
");
$laterCheck = $pdo->prepare("
    SELECT COUNT(*) FROM fact_training_session s
    LEFT JOIN analysis_mission_role r ON r.mission_id=s.mission_id
    WHERE s.student_id=? AND s.program_id=? AND s.session_date > ?
      AND r.mission_role='CHECK_EVENT'
      AND (s.grading_completion='I' OR s.grading_color='R' OR COALESCE(s.exercises_below_required,0)>0)
");
$peStab = $pdo->prepare("
    SELECT AVG(stable_pe_rate) FROM analysis_competency_stability
    WHERE program_id=? AND n_reobserved >= 3
");

$n = 0;
foreach ($rows as $r) {
    $sid = (int)$r['student_id'];
    $pid = $r['program_id'] !== null ? (int)$r['program_id'] : null;
    $date = (string)$r['session_date'];
    $year = $date !== '' ? (int)substr($date, 0, 4) : null;

    $traj = null;
    if ($pid !== null) {
        $trajStmt->execute([$sid, $pid]);
        $traj = $trajStmt->fetchColumn();
        $traj = $traj === false ? null : (string)$traj;
    }

    $later_reg = 0;
    $later_rep = 0;
    $later_chk = 0;
    $pe_proxy = null;
    if ($pid !== null && $date !== '') {
        $laterReg->execute([$sid, $pid, $date]);
        $later_reg = ((int)$laterReg->fetchColumn()) > 0 ? 1 : 0;
        $laterRepeat->execute([$sid, $pid, $date]);
        $later_rep = ((int)$laterRepeat->fetchColumn()) > 0 ? 1 : 0;
        $laterCheck->execute([$sid, $pid, $date]);
        $later_chk = ((int)$laterCheck->fetchColumn()) > 0 ? 1 : 0;
        $peStab->execute([$pid]);
        $v = $peStab->fetchColumn();
        $pe_proxy = $v === false || $v === null ? null : (float)$v;
    }

    $ins->execute([
        (int)$r['narrative_id'], (int)$r['session_id'], $r['sample_stratum'], $r['text_hash'], $r['raw_text'],
        strlen((string)$r['raw_text']),
        $sid, $r['instructor_id'] !== null ? (int)$r['instructor_id'] : null, $pid,
        $r['curriculum_version_id'] !== null ? (int)$r['curriculum_version_id'] : null,
        $r['curriculum_family_id'] !== null ? (int)$r['curriculum_family_id'] : null,
        $r['version_code'], $r['family_code'], $r['program_name'],
        $r['mission_id'] !== null ? (int)$r['mission_id'] : null,
        $r['mission_code'], $r['mission_name'], $r['mission_role'],
        $date, $year, $r['grading_raw'], $r['grading_color'], $r['grading_completion'],
        $r['exercises_below_required'] !== null ? (int)$r['exercises_below_required'] : null,
        $r['mission_attempt_number'] !== null ? (int)$r['mission_attempt_number'] : null,
        $traj, $pe_proxy, $later_reg, $later_rep, $later_chk, $VERSION, $NOW,
    ]);
    $n++;
}

$pdo->exec('DELETE FROM analysis_phase5_meta');
$pdo->prepare('INSERT INTO analysis_phase5_meta (analysis_version, prompt_version, extraction_version, llm_model, generated_at, notes)
VALUES (?,?,?,?,?,?)')->execute([$VERSION, null, null, null, $NOW, 'Enriched narrative sample ready for extraction']);

echo "Enriched narratives: {$n}\n";
foreach ($pdo->query('SELECT sample_stratum, COUNT(*) c FROM analysis_narrative_sample_enriched GROUP BY 1 ORDER BY c DESC') as $r) {
    echo "  {$r['sample_stratum']}: {$r['c']}\n";
}
echo "Bootstrap complete.\n";
