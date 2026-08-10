<?php
declare(strict_types=1);

/**
 * Five proof-of-value analyses against normalized analytics DB.
 * Correlation != causation. Sample sizes reported. No instructor rankings.
 */

$root = dirname(__DIR__, 2);
$dbPath = $root . '/storage/analytics/egle_training_analytics.sqlite';
$outPath = $root . '/docs/analytics/phase3-proof-of-value.md';
$jsonPath = $root . '/tmp/analytics/phase3_proof_of_value.json';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function q(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$out = [];

// 1. Mission bottlenecks
$out['1_mission_bottlenecks'] = q($pdo, "
WITH base AS (
  SELECT s.program_id, p.program_name, s.mission_id, m.mission_code, m.mission_name,
         s.student_id, s.session_id, s.mission_attempt_number
  FROM fact_training_session s
  JOIN dim_mission m ON m.mission_id = s.mission_id
  LEFT JOIN dim_program p ON p.program_id = s.program_id
  WHERE s.session_date_valid = 1
    AND s.student_id IS NOT NULL
    AND s.qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
),
agg AS (
  SELECT program_id, program_name, mission_id, mission_code, mission_name,
         COUNT(DISTINCT student_id) student_count,
         COUNT(session_id) session_count,
         COUNT(DISTINCT student_id || '-' || mission_id) pairs,
         SUM(CASE WHEN mission_attempt_number > 1 THEN 1 ELSE 0 END) extra_sessions,
         AVG(mission_attempt_number) avg_attempts
  FROM base
  GROUP BY program_id, mission_id
),
med AS (
  SELECT program_id, mission_id,
         AVG(max_attempt) median_attempts_approx
  FROM (
    SELECT program_id, mission_id, student_id, MAX(mission_attempt_number) max_attempt
    FROM base GROUP BY program_id, mission_id, student_id
  )
  GROUP BY program_id, mission_id
)
SELECT a.*, m.median_attempts_approx,
       ROUND(1.0 * a.extra_sessions / NULLIF(a.student_count,0), 3) extra_sessions_per_student,
       ROUND(1.0 * a.session_count / NULLIF(a.student_count,0), 3) sessions_per_student,
       CASE WHEN a.student_count >= 20 THEN 'HIGH'
            WHEN a.student_count >= 8 THEN 'MEDIUM'
            ELSE 'LOW' END confidence
FROM agg a
JOIN med m ON m.program_id=a.program_id AND m.mission_id=a.mission_id
WHERE a.student_count >= 5
ORDER BY extra_sessions_per_student DESC, a.session_count DESC
LIMIT 25
");

// 2. Exercise difficulties
$out['2_exercise_difficulties'] = q($pdo, "
SELECT e.source_exercise_id, e.exercise_name_raw, e.required_level_normalized,
       p.program_name,
       COUNT(*) exposure_count,
       COUNT(DISTINCT a.student_id) student_count,
       SUM(CASE WHEN a.required_level_not_met=1 THEN 1 ELSE 0 END) not_met_count,
       ROUND(100.0 * SUM(CASE WHEN a.required_level_not_met=1 THEN 1 ELSE 0 END) / COUNT(*), 2) not_met_rate_pct,
       SUM(CASE WHEN a.required_level_met=1 THEN 1 ELSE 0 END) met_count,
       SUM(CASE WHEN a.deferred=1 THEN 1 ELSE 0 END) deferred_count,
       CASE WHEN COUNT(DISTINCT a.student_id) >= 20 THEN 'HIGH'
            WHEN COUNT(DISTINCT a.student_id) >= 8 THEN 'MEDIUM'
            ELSE 'LOW' END confidence
FROM fact_exercise_attempt a
JOIN dim_exercise e ON e.exercise_id = a.exercise_id
LEFT JOIN dim_program p ON p.program_id = a.program_id
WHERE a.required_level_normalized IS NOT NULL
  AND a.session_date IS NOT NULL
GROUP BY e.exercise_id, p.program_id
HAVING exposure_count >= 30 AND student_count >= 5 AND not_met_count > 0
ORDER BY not_met_rate_pct DESC, exposure_count DESC
LIMIT 25
");

// 3. Competency stability: after first PE (B) when required PE, later poorer ordinal?
$out['3_competency_stability'] = q($pdo, "
WITH pe_hits AS (
  SELECT a.*, 
         ROW_NUMBER() OVER (PARTITION BY a.student_id, a.source_exercise_id ORDER BY a.session_date, a.session_id) rn
  FROM fact_exercise_attempt a
  WHERE a.achieved_grade_raw = 'B'
    AND a.required_level_normalized = 'PE'
    AND a.student_id IS NOT NULL
    AND a.session_date IS NOT NULL
),
first_pe AS (
  SELECT * FROM pe_hits WHERE rn = 1
),
later AS (
  SELECT f.student_id, f.source_exercise_id, f.exercise_name_raw, f.program_id, f.session_date AS first_pe_date,
         a.session_date AS later_date, a.achieved_grade_raw AS later_grade,
         CASE a.achieved_grade_raw WHEN 'R' THEN 1 WHEN 'Y' THEN 2 WHEN 'G' THEN 3 WHEN 'B' THEN 4 ELSE NULL END later_ord
  FROM first_pe f
  JOIN fact_exercise_attempt a
    ON a.student_id = f.student_id
   AND a.source_exercise_id = f.source_exercise_id
   AND (a.session_date > f.session_date OR (a.session_date = f.session_date AND a.session_id > f.session_id))
  WHERE a.achieved_grade_raw IN ('R','Y','G','B')
)
SELECT COALESCE(p.program_name,'UNKNOWN') program_name,
       COUNT(DISTINCT f.student_id || '-' || f.source_exercise_id) pe_competency_events,
       COUNT(DISTINCT CASE WHEN l.later_ord IS NOT NULL THEN f.student_id || '-' || f.source_exercise_id END) with_later_observation,
       COUNT(DISTINCT CASE WHEN l.later_ord < 4 THEN f.student_id || '-' || f.source_exercise_id END) later_below_pe,
       ROUND(100.0 * COUNT(DISTINCT CASE WHEN l.later_ord < 4 THEN f.student_id || '-' || f.source_exercise_id END)
            / NULLIF(COUNT(DISTINCT CASE WHEN l.later_ord IS NOT NULL THEN f.student_id || '-' || f.source_exercise_id END),0), 2)
         AS pct_later_below_pe_among_reobserved
FROM first_pe f
LEFT JOIN later l ON l.student_id=f.student_id AND l.source_exercise_id=f.source_exercise_id
LEFT JOIN dim_program p ON p.program_id=f.program_id
GROUP BY f.program_id
ORDER BY pe_competency_events DESC
");

$out['3_stability_top_exercises'] = q($pdo, "
WITH pe_hits AS (
  SELECT a.*, ROW_NUMBER() OVER (PARTITION BY a.student_id, a.source_exercise_id ORDER BY a.session_date, a.session_id) rn
  FROM fact_exercise_attempt a
  WHERE a.achieved_grade_raw='B' AND a.required_level_normalized='PE' AND a.student_id IS NOT NULL AND a.session_date IS NOT NULL
),
first_pe AS (SELECT * FROM pe_hits WHERE rn=1),
later AS (
  SELECT f.source_exercise_id, f.exercise_name_raw, f.student_id,
         MIN(CASE WHEN a.achieved_grade_raw IN ('R','Y','G') THEN 1 ELSE 0 END) AS had_later_below
  FROM first_pe f
  JOIN fact_exercise_attempt a
    ON a.student_id=f.student_id AND a.source_exercise_id=f.source_exercise_id
   AND (a.session_date > f.session_date OR (a.session_date=f.session_date AND a.session_id > f.session_id))
  WHERE a.achieved_grade_raw IN ('R','Y','G','B')
  GROUP BY f.source_exercise_id, f.student_id
)
SELECT source_exercise_id, exercise_name_raw,
       COUNT(*) reobserved_students,
       SUM(had_later_below) students_later_below_pe,
       ROUND(100.0*SUM(had_later_below)/COUNT(*),2) pct_later_below
FROM later
GROUP BY source_exercise_id
HAVING reobserved_students >= 8
ORDER BY pct_later_below DESC, reobserved_students DESC
LIMIT 20
");

// 4. Training gap effect
$out['4_training_gap_effect'] = q($pdo, "
WITH s AS (
  SELECT session_id, program_id, days_since_previous_session,
         CASE
           WHEN days_since_previous_session IS NULL THEN 'FIRST'
           WHEN days_since_previous_session <= 2 THEN '0-2'
           WHEN days_since_previous_session <= 5 THEN '3-5'
           WHEN days_since_previous_session <= 10 THEN '6-10'
           WHEN days_since_previous_session <= 20 THEN '11-20'
           ELSE '21+'
         END gap_bucket,
         grading_color, grading_completion,
         CASE WHEN grading_completion='I' OR grading_color='R' THEN 1 ELSE 0 END weak_session,
         mission_repeated
  FROM fact_training_session
  WHERE session_date_valid=1
    AND qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
)
SELECT COALESCE(p.program_name,'ALL') program_name, s.gap_bucket,
       COUNT(*) sessions,
       ROUND(100.0*AVG(weak_session),2) pct_weak_session_RI_or_incompleteish,
       ROUND(100.0*AVG(CASE WHEN grading_completion='I' THEN 1 ELSE 0 END),2) pct_incomplete,
       ROUND(100.0*AVG(CASE WHEN grading_color='R' THEN 1 ELSE 0 END),2) pct_red,
       ROUND(100.0*AVG(COALESCE(mission_repeated,0)),2) pct_mission_repeat_attempt,
       CASE WHEN COUNT(*) >= 100 THEN 'HIGH' WHEN COUNT(*) >= 30 THEN 'MEDIUM' ELSE 'LOW' END confidence
FROM s
LEFT JOIN dim_program p ON p.program_id=s.program_id
WHERE s.gap_bucket != 'FIRST'
GROUP BY s.program_id, s.gap_bucket
HAVING sessions >= 20
ORDER BY p.program_name, CASE s.gap_bucket
  WHEN '0-2' THEN 1 WHEN '3-5' THEN 2 WHEN '6-10' THEN 3 WHEN '11-20' THEN 4 ELSE 5 END
");

$out['4_gap_overall'] = q($pdo, "
SELECT CASE
         WHEN days_since_previous_session <= 2 THEN '0-2'
         WHEN days_since_previous_session <= 5 THEN '3-5'
         WHEN days_since_previous_session <= 10 THEN '6-10'
         WHEN days_since_previous_session <= 20 THEN '11-20'
         ELSE '21+'
       END gap_bucket,
       COUNT(*) sessions,
       ROUND(100.0*AVG(CASE WHEN grading_completion='I' THEN 1 ELSE 0 END),2) pct_incomplete,
       ROUND(100.0*AVG(CASE WHEN grading_color='R' THEN 1 ELSE 0 END),2) pct_red,
       ROUND(AVG(exercises_below_required),2) avg_exercises_below_required,
       ROUND(100.0*AVG(COALESCE(mission_repeated,0)),2) pct_repeat_attempt
FROM fact_training_session
WHERE session_date_valid=1 AND days_since_previous_session IS NOT NULL
  AND qa_class IN ('HIGH_CONFIDENCE','USABLE_WITH_QUALIFICATION')
GROUP BY gap_bucket
ORDER BY CASE gap_bucket WHEN '0-2' THEN 1 WHEN '3-5' THEN 2 WHEN '6-10' THEN 3 WHEN '11-20' THEN 4 ELSE 5 END
");

// 5. Instructor downstream validity (measurable? useful?) — no ranking
$out['5_instructor_downstream_validity'] = q($pdo, "
WITH strong AS (
  SELECT a.instructor_id, a.student_id, a.source_exercise_id, a.session_id, a.session_date, a.program_id,
         a.achieved_grade_raw, a.required_level_normalized
  FROM fact_exercise_attempt a
  WHERE a.instructor_id IS NOT NULL
    AND a.student_id IS NOT NULL
    AND a.session_date IS NOT NULL
    AND a.required_level_met = 1
    AND a.achieved_grade_raw IN ('G','B')
),
later_problem AS (
  SELECT s.instructor_id, s.student_id, s.source_exercise_id, s.session_date AS strong_date, s.program_id,
         MAX(CASE WHEN a.required_level_not_met=1 OR a.achieved_grade_raw IN ('R','Y') THEN 1 ELSE 0 END) later_problem
  FROM strong s
  JOIN fact_exercise_attempt a
    ON a.student_id=s.student_id AND a.source_exercise_id=s.source_exercise_id
   AND (a.session_date > s.session_date OR (a.session_date=s.session_date AND a.session_id > s.session_id))
  GROUP BY s.instructor_id, s.student_id, s.source_exercise_id, s.session_date, s.program_id
)
SELECT i.source_user_id, i.first_name, i.last_name,
       COUNT(*) strong_marks_with_later_obs,
       SUM(later_problem) later_became_problematic,
       ROUND(100.0*SUM(later_problem)/COUNT(*),2) pct_later_problem,
       CASE WHEN COUNT(*) >= 80 THEN 'SAMPLE_OK' WHEN COUNT(*) >= 30 THEN 'SAMPLE_LIMITED' ELSE 'SAMPLE_TOO_SMALL' END sample_sufficiency
FROM later_problem lp
JOIN dim_instructor i ON i.instructor_id = lp.instructor_id
GROUP BY lp.instructor_id
HAVING strong_marks_with_later_obs >= 30
ORDER BY strong_marks_with_later_obs DESC
");

$out['5_metric_assessment'] = [
    'measurable' => true,
    'definition' => 'Among exercise marks where required level was met and achieved was G or B, and the same student+exercise was observed again later: share where a later attempt was required_level_not_met or achieved R/Y.',
    'caveats' => [
        'Not adjusted for student ability, mission difficulty, or curriculum version in this proof-of-value pass.',
        'Instructors teaching harder phases may show higher later-problem rates without being poorer instructors.',
        'Later problems may occur under a different instructor; this metric is about durability after a strong mark, not blame.',
        'Do not rank instructors from this table alone.',
    ],
];

file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

ob_start();
echo "# Phase 3 Proof-of-Value Analyses\n\n";
echo "Generated: " . gmdate('c') . "\n\n";
echo "These analyses use the normalized analytics SQLite DB. They are descriptive / associative, not causal claims.\n\n";

echo "## 1. Top mission bottlenecks\n\n";
echo "Rule: among valid sessions, extra sessions beyond first attempt for a student+mission, normalized by student count.\n\n";
echo "| program | code | mission | students | sessions | extra/student | sessions/student | confidence |\n|---|---|---|---:|---:|---:|---:|---|\n";
foreach ($out['1_mission_bottlenecks'] as $r) {
    echo "| {$r['program_name']} | {$r['mission_code']} | " . str_replace('|', '/', $r['mission_name']) . " | {$r['student_count']} | {$r['session_count']} | {$r['extra_sessions_per_student']} | {$r['sessions_per_student']} | {$r['confidence']} |\n";
}

echo "\n## 2. Top exercise difficulties\n\n";
echo "Rule: required_level_not_met rate where required level is known; exposure>=30, students>=5.\n\n";
echo "| program | required | not_met% | exposure | students | exercise |\n|---|---|---:|---:|---:|---|\n";
foreach ($out['2_exercise_difficulties'] as $r) {
    echo "| {$r['program_name']} | {$r['required_level_normalized']} | {$r['not_met_rate_pct']} | {$r['exposure_count']} | {$r['student_count']} | " . str_replace('|', '/', substr((string)$r['exercise_name_raw'], 0, 90)) . " |\n";
}

echo "\n## 3. Competency stability after PE\n\n";
echo "Rule: first time an exercise is marked B while required PE; among those later re-observed, share with a later R/Y/G.\n\n";
echo "| program | PE events | reobserved | later below PE | % |\n|---|---:|---:|---:|---:|\n";
foreach ($out['3_competency_stability'] as $r) {
    echo "| {$r['program_name']} | {$r['pe_competency_events']} | {$r['with_later_observation']} | {$r['later_below_pe']} | {$r['pct_later_below_pe_among_reobserved']} |\n";
}
echo "\n### Exercises with highest later-below-PE rates (reobserved students ≥ 8)\n\n";
echo "| % later below | reobserved | exercise |\n|---:|---:|---|\n";
foreach ($out['3_stability_top_exercises'] as $r) {
    echo "| {$r['pct_later_below']} | {$r['reobserved_students']} | " . str_replace('|', '/', substr((string)$r['exercise_name_raw'], 0, 100)) . " |\n";
}

echo "\n## 4. Training gap effect\n\n";
echo "Overall association (not causal):\n\n";
echo "| gap days | sessions | % incomplete | % red | avg exercises below | % repeat attempt |\n|---|---:|---:|---:|---:|---:|\n";
foreach ($out['4_gap_overall'] as $r) {
    echo "| {$r['gap_bucket']} | {$r['sessions']} | {$r['pct_incomplete']} | {$r['pct_red']} | {$r['avg_exercises_below_required']} | {$r['pct_repeat_attempt']} |\n";
}

echo "\n## 5. Instructor downstream validity (no rankings)\n\n";
echo $out['5_metric_assessment']['definition'] . "\n\n";
echo "Caveats:\n";
foreach ($out['5_metric_assessment']['caveats'] as $c) {
    echo "- {$c}\n";
}
echo "\n| instructor | strong marks w/ later obs | later problematic | % | sample |\n|---|---:|---:|---:|---|\n";
foreach ($out['5_instructor_downstream_validity'] as $r) {
    echo "| {$r['first_name']} {$r['last_name']} ({$r['source_user_id']}) | {$r['strong_marks_with_later_obs']} | {$r['later_became_problematic']} | {$r['pct_later_problem']} | {$r['sample_sufficiency']} |\n";
}

echo "\n---\n\nFull JSON: `tmp/analytics/phase3_proof_of_value.json`\n";
$md = ob_get_clean();
file_put_contents($outPath, $md);
echo "Wrote {$outPath}\n";
