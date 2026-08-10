<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
$outPath = $root . '/docs/analytics/phase3-validation.md';
$jsonPath = $root . '/tmp/analytics/phase3_validation.json';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Analytics DB not found: {$dbPath}\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function q(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function q1(PDO $pdo, string $sql)
{
    $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $r ? array_values($r)[0] : null;
}

$report = [];
$report['etl_run'] = q($pdo, 'SELECT * FROM etl_run ORDER BY etl_run_id DESC LIMIT 1')[0] ?? null;

$report['A_row_counts'] = [
    'dim_student' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_student'),
    'dim_instructor' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_instructor'),
    'dim_program' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_program'),
    'dim_curriculum_family' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_curriculum_family'),
    'dim_curriculum_version' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_curriculum_version'),
    'dim_stage' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_stage'),
    'dim_phase' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_phase'),
    'dim_mission' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_mission'),
    'dim_exercise' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_exercise'),
    'dim_device' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_device'),
    'fact_training_session' => (int)q1($pdo, 'SELECT COUNT(*) FROM fact_training_session'),
    'fact_exercise_attempt' => (int)q1($pdo, 'SELECT COUNT(*) FROM fact_exercise_attempt'),
    'fact_srm_attempt' => (int)q1($pdo, 'SELECT COUNT(*) FROM fact_srm_attempt'),
    'fact_logbook_leg' => (int)q1($pdo, 'SELECT COUNT(*) FROM fact_logbook_leg'),
    'fact_narrative' => (int)q1($pdo, 'SELECT COUNT(*) FROM fact_narrative'),
    'bridge_student_identity' => (int)q1($pdo, 'SELECT COUNT(*) FROM bridge_student_identity'),
    'qa_exclusion_log' => (int)q1($pdo, 'SELECT COUNT(*) FROM qa_exclusion_log'),
    'qa_data_issue' => (int)q1($pdo, 'SELECT COUNT(*) FROM qa_data_issue'),
];

$srcSessions = (int)q1($pdo, 'SELECT COUNT(*) FROM fact_training_session');
$mappedOk = (int)q1($pdo, "SELECT COUNT(*) FROM fact_training_session WHERE qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')");
$report['B_session_mapping'] = [
    'source_sessions_extracted' => $srcSessions,
    'high_or_usable' => $mappedOk,
    'pct_high_or_usable' => $srcSessions ? round(100 * $mappedOk / $srcSessions, 2) : null,
    'by_qa_class' => q($pdo, 'SELECT qa_class, COUNT(*) n FROM fact_training_session GROUP BY qa_class ORDER BY n DESC'),
];

$exOk = (int)q1($pdo, "SELECT COUNT(*) FROM fact_exercise_attempt WHERE parse_status='OK'");
$exFailSessions = (int)q1($pdo, "SELECT COUNT(*) FROM fact_training_session WHERE ex_blob_parse_status='FAIL'");
$exEmptySessions = (int)q1($pdo, "SELECT COUNT(*) FROM fact_training_session WHERE ex_blob_parse_status='EMPTY'");
$report['C_D_exercise_parse'] = [
    'exercise_attempts_ok' => $exOk,
    'sessions_ex_parse_fail' => $exFailSessions,
    'sessions_ex_empty' => $exEmptySessions,
    'sessions_ex_ok' => (int)q1($pdo, "SELECT COUNT(*) FROM fact_training_session WHERE ex_blob_parse_status='OK'"),
];

$report['E_sessions_without_student'] = (int)q1($pdo, 'SELECT COUNT(*) FROM fact_training_session WHERE student_id IS NULL');
$report['F_sessions_without_mission'] = (int)q1($pdo, 'SELECT COUNT(*) FROM fact_training_session WHERE mission_id IS NULL');
$report['G_orphans'] = [
    'sessions_mission_missing' => $report['F_sessions_without_mission'],
    'exercise_attempts_without_dim_exercise' => (int)q1($pdo, 'SELECT COUNT(*) FROM fact_exercise_attempt WHERE exercise_id IS NULL'),
    'missions_without_program' => (int)q1($pdo, 'SELECT COUNT(*) FROM dim_mission WHERE source_program_id IS NULL OR source_program_id=0'),
];

$report['H_required_level_dist'] = q($pdo, "SELECT required_level_normalized, COUNT(*) n FROM dim_exercise WHERE is_title=0 GROUP BY required_level_normalized ORDER BY n DESC");
$report['H_required_level_parse_status'] = q($pdo, 'SELECT required_level_parse_status, COUNT(*) n FROM dim_exercise GROUP BY required_level_parse_status');
$report['I_achieved_grade_dist'] = q($pdo, 'SELECT achieved_grade_raw, achieved_competency_stage, COUNT(*) n FROM fact_exercise_attempt GROUP BY achieved_grade_raw, achieved_competency_stage ORDER BY n DESC');
$report['J_session_grade_dist'] = q($pdo, 'SELECT grading_raw, grading_color, grading_completion, COUNT(*) n FROM fact_training_session GROUP BY grading_raw, grading_color, grading_completion ORDER BY n DESC');

$report['K_by_year'] = q($pdo, "SELECT substr(session_date,1,4) year, COUNT(*) n FROM fact_training_session WHERE session_date_valid=1 GROUP BY year ORDER BY year");
$report['K_by_program'] = q($pdo, "SELECT p.program_name, COUNT(*) n FROM fact_training_session s LEFT JOIN dim_program p ON p.program_id=s.program_id GROUP BY p.program_name ORDER BY n DESC");
$report['K_by_instructor'] = q($pdo, "SELECT i.source_user_id, i.first_name, i.last_name, COUNT(*) n FROM fact_training_session s LEFT JOIN dim_instructor i ON i.instructor_id=s.instructor_id GROUP BY i.instructor_id ORDER BY n DESC LIMIT 30");
$report['K_by_training_type'] = q($pdo, 'SELECT source_session_type, session_type_normalized, COUNT(*) n FROM fact_training_session GROUP BY source_session_type, session_type_normalized ORDER BY n DESC');

$report['L_duplicate_candidates'] = [
    'candidate_rows' => (int)q1($pdo, 'SELECT COUNT(*) FROM bridge_student_identity'),
    'candidate_groups' => (int)q1($pdo, 'SELECT COUNT(DISTINCT candidate_group_id) FROM bridge_student_identity'),
    'sample_groups' => q($pdo, "SELECT candidate_group_id, GROUP_CONCAT(source_user_id) users, MAX(match_score) score, MAX(match_signals_json) signals
        FROM bridge_student_identity GROUP BY candidate_group_id ORDER BY score DESC LIMIT 20"),
];

$report['M_curriculum_mapping'] = q($pdo, "
    SELECT f.family_code, v.version_code, v.version_name, v.generation_order, v.is_current, p.source_program_id, p.program_name, p.source_tracking_table, p.location_raw
    FROM dim_program p
    LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id=p.curriculum_version_id
    LEFT JOIN dim_curriculum_family f ON f.curriculum_family_id=p.curriculum_family_id
    ORDER BY f.family_code, v.generation_order, p.source_program_id
");

$report['N_event_class'] = q($pdo, 'SELECT event_class, event_class_confidence, COUNT(*) n FROM dim_mission GROUP BY event_class, event_class_confidence ORDER BY n DESC');
$report['N_event_class_samples'] = q($pdo, "SELECT event_class, event_class_confidence, mission_code, mission_name, event_class_reason
    FROM dim_mission WHERE event_class != 'NORMAL TRAINING' ORDER BY event_class, mission_name LIMIT 80");

$report['O_srm_dictionary'] = q($pdo, "
    SELECT k.raw_key, k.probable_ui_label, k.confidence, k.evidence,
           COUNT(a.srm_attempt_id) freq,
           COUNT(DISTINCT a.program_id) programs,
           GROUP_CONCAT(DISTINCT a.srm_value_raw) values_seen
    FROM dim_srm_key k
    LEFT JOIN fact_srm_attempt a ON a.srm_key_raw=k.raw_key
    GROUP BY k.raw_key
    ORDER BY freq DESC
");

$report['O_next_alternative_findings'] = [
    'summary' => 'From training_record_instructor.php: sctr_next and sctr_alternative store scenario IDs selected by instructor. UI labels are Next Mission and Alternative Mission. Sentinel 999999999 means none. Email/print templates resolve them via SELECT * FROM scenarios WHERE sc_id=...',
    'next_none_pct' => q1($pdo, 'SELECT ROUND(100.0*SUM(sctr_next_is_none)/COUNT(*),2) FROM fact_training_session'),
    'alt_none_pct' => q1($pdo, 'SELECT ROUND(100.0*SUM(sctr_alternative_is_none)/COUNT(*),2) FROM fact_training_session'),
    'next_value_sample' => q($pdo, 'SELECT sctr_next_raw, COUNT(*) n FROM fact_training_session GROUP BY sctr_next_raw ORDER BY n DESC LIMIT 15'),
];

$report['material_discoveries'] = [
    'SRM grade columns in UI are EX/PR/MD/NO while exercise grades use DE/EX/PR/PE; both store R/Y/G/B — do not conflate.',
    'SAB/LB confirmed in scenarios_admin.php as Simulator Briefing / Long Briefing; user also describes SAB as scenario-based simulator session.',
    'sctr_next/sctr_alternative are instructor-selected next/alternative scenario IDs, not session IDs.',
    'Overall session grades are often auto-derived from exercise colors; treat as dependent evidence.',
    'Curriculum families are explicit via pr_db old/new pairs and should be compared longitudinally.',
];

@mkdir(dirname($jsonPath), 0755, true);
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

ob_start();
echo "# Phase 3 Validation Report\n\n";
echo "Generated: " . gmdate('c') . "\n\n";
echo "## A. Canonical row counts\n\n";
foreach ($report['A_row_counts'] as $k => $v) {
    echo "- **{$k}**: {$v}\n";
}
echo "\n## B. Session mapping\n\n";
echo "- Extracted sessions: {$report['B_session_mapping']['source_sessions_extracted']}\n";
echo "- HIGH_CONFIDENCE + USABLE_WITH_QUALIFICATION: {$report['B_session_mapping']['high_or_usable']} ({$report['B_session_mapping']['pct_high_or_usable']}%)\n\n";
echo "| qa_class | n |\n|---|---:|\n";
foreach ($report['B_session_mapping']['by_qa_class'] as $r) {
    echo "| {$r['qa_class']} | {$r['n']} |\n";
}
echo "\n## C–D. Exercise parse\n\n";
echo "- Exercise attempt rows parsed OK: {$report['C_D_exercise_parse']['exercise_attempts_ok']}\n";
echo "- Sessions with ex blob OK/EMPTY/FAIL: {$report['C_D_exercise_parse']['sessions_ex_ok']} / {$report['C_D_exercise_parse']['sessions_ex_empty']} / {$report['C_D_exercise_parse']['sessions_ex_parse_fail']}\n";
echo "\n## E–G. Identity / mission / orphans\n\n";
echo "- Sessions without student: {$report['E_sessions_without_student']}\n";
echo "- Sessions without mission: {$report['F_sessions_without_mission']}\n";
echo "- Exercise attempts missing dim_exercise: {$report['G_orphans']['exercise_attempts_without_dim_exercise']}\n";
echo "\n## H. Required level distribution (dim_exercise, non-title implied in null handling)\n\n";
echo "| required_level_normalized | n |\n|---|---:|\n";
foreach ($report['H_required_level_dist'] as $r) {
    echo "| " . ($r['required_level_normalized'] ?? 'NULL') . " | {$r['n']} |\n";
}
echo "\n## I. Achieved grade distribution\n\n";
echo "| achieved_grade_raw | stage | n |\n|---|---|---:|\n";
foreach ($report['I_achieved_grade_dist'] as $r) {
    echo "| " . ($r['achieved_grade_raw'] === '' ? '(blank)' : $r['achieved_grade_raw']) . " | {$r['achieved_competency_stage']} | {$r['n']} |\n";
}
echo "\n## J. Session grade distribution\n\n";
echo "| grading_raw | color | completion | n |\n|---|---|---|---:|\n";
foreach ($report['J_session_grade_dist'] as $r) {
    $raw = $r['grading_raw'] === '' ? '(blank)' : $r['grading_raw'];
    echo "| {$raw} | {$r['grading_color']} | {$r['grading_completion']} | {$r['n']} |\n";
}
echo "\n## K. Session counts\n\n### By year\n\n";
echo "| year | n |\n|---|---:|\n";
foreach ($report['K_by_year'] as $r) {
    echo "| {$r['year']} | {$r['n']} |\n";
}
echo "\n### By program\n\n";
echo "| program | n |\n|---|---:|\n";
foreach ($report['K_by_program'] as $r) {
    echo "| " . ($r['program_name'] ?? 'NULL') . " | {$r['n']} |\n";
}
echo "\n### By training type\n\n";
echo "| source_session_type | normalized | n |\n|---|---|---:|\n";
foreach ($report['K_by_training_type'] as $r) {
    echo "| " . ($r['source_session_type'] === '' ? '(blank)' : $r['source_session_type']) . " | {$r['session_type_normalized']} | {$r['n']} |\n";
}
echo "\n## L. Candidate duplicate identities\n\n";
echo "- Groups: {$report['L_duplicate_candidates']['candidate_groups']} (rows {$report['L_duplicate_candidates']['candidate_rows']})\n";
echo "- Status: all **CANDIDATE** (no automatic merges)\n\n";
echo "\n## M. Curriculum family / version mapping\n\n";
echo "| family | version | gen | current | program | tracking | loc |\n|---|---|---:|---:|---|---|---|\n";
foreach ($report['M_curriculum_mapping'] as $r) {
    echo "| {$r['family_code']} | {$r['version_code']} | {$r['generation_order']} | {$r['is_current']} | {$r['program_name']} | {$r['source_tracking_table']} | {$r['location_raw']} |\n";
}
echo "\n## N. Formal check / solo classifications\n\n";
echo "| class | confidence | n |\n|---|---|---:|\n";
foreach ($report['N_event_class'] as $r) {
    echo "| {$r['event_class']} | {$r['event_class_confidence']} | {$r['n']} |\n";
}
echo "\n## O. Notable discoveries\n\n";
foreach ($report['material_discoveries'] as $d) {
    echo "- {$d}\n";
}
echo "\n### SRM dictionary\n\n";
echo "| key | UI label | confidence | freq | values |\n|---|---|---|---:|---|\n";
foreach ($report['O_srm_dictionary'] as $r) {
    echo "| {$r['raw_key']} | {$r['probable_ui_label']} | {$r['confidence']} | {$r['freq']} | {$r['values_seen']} |\n";
}
echo "\n### sctr_next / sctr_alternative\n\n";
echo $report['O_next_alternative_findings']['summary'] . "\n\n";
echo "- next none: {$report['O_next_alternative_findings']['next_none_pct']}%\n";
echo "- alternative none: {$report['O_next_alternative_findings']['alt_none_pct']}%\n";

$md = ob_get_clean();
file_put_contents($outPath, $md);
echo "Wrote {$outPath}\n";
echo "Wrote {$jsonPath}\n";
