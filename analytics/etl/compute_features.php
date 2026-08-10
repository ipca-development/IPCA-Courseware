<?php
declare(strict_types=1);

/**
 * Deterministic temporal / progression feature computation on analytics SQLite.
 * Streams large exercise tables to avoid memory exhaustion.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php compute_features.php <etl_run_id> <sqlite_path>\n");
    exit(1);
}
$etlRunId = (int)$argv[1];
$path = $argv[2];
ini_set('memory_limit', '1024M');

$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once dirname(__DIR__) . '/lib/grade_maps.php';

echo "Computing session temporal features...\n";

$rows = $pdo->query("
    SELECT session_id, student_id, instructor_id, mission_id, session_date,
           dual_hours, pic_hours, fnpt_hours, brief_hours, total_training_hours,
           flight_hours, sim_hours
    FROM fact_training_session
    WHERE session_date_valid = 1 AND student_id IS NOT NULL
    ORDER BY student_id, session_date, session_id
")->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("
    UPDATE fact_training_session SET
      previous_session_date = ?,
      days_since_previous_session = ?,
      next_session_date = ?,
      days_until_next_session = ?,
      student_session_number = ?,
      mission_attempt_number = ?,
      cumulative_training_sessions = ?,
      cumulative_flight_time = ?,
      cumulative_sim_time = ?,
      cumulative_training_time = ?,
      previous_instructor_id = ?,
      instructor_change_indicator = ?,
      previous_mission_id = ?,
      next_mission_id = ?,
      mission_repeated = ?,
      same_mission_next_session = ?,
      mission_returned_to_later = ?
    WHERE session_id = ?
");

$byStudent = [];
foreach ($rows as $r) {
    $byStudent[(int)$r['student_id']][] = $r;
}
unset($rows);

$pdo->beginTransaction();
foreach ($byStudent as $sessions) {
    $cumFlight = 0.0;
    $cumSim = 0.0;
    $cumTotal = 0.0;
    $missionAttemptCounts = [];
    $missionSeenBefore = [];
    $n = count($sessions);
    for ($i = 0; $i < $n; $i++) {
        $cur = $sessions[$i];
        $sid = (int)$cur['session_id'];
        $missionId = $cur['mission_id'] !== null ? (int)$cur['mission_id'] : null;
        $instrId = $cur['instructor_id'] !== null ? (int)$cur['instructor_id'] : null;
        $prev = $i > 0 ? $sessions[$i - 1] : null;
        $next = $i < $n - 1 ? $sessions[$i + 1] : null;

        $prevDate = $prev['session_date'] ?? null;
        $nextDate = $next['session_date'] ?? null;
        $daysPrev = ($prevDate && $cur['session_date'])
            ? (int)((strtotime((string)$cur['session_date']) - strtotime((string)$prevDate)) / 86400)
            : null;
        $daysNext = ($nextDate && $cur['session_date'])
            ? (int)((strtotime((string)$nextDate) - strtotime((string)$cur['session_date'])) / 86400)
            : null;

        $sessionNumber = $i + 1;
        if ($missionId !== null) {
            $missionAttemptCounts[$missionId] = ($missionAttemptCounts[$missionId] ?? 0) + 1;
            $attemptNo = $missionAttemptCounts[$missionId];
        } else {
            $attemptNo = null;
        }

        $cumFlight += (float)$cur['flight_hours'];
        $cumSim += (float)$cur['sim_hours'];
        $cumTotal += (float)$cur['total_training_hours'];

        $prevInstr = ($prev !== null && $prev['instructor_id'] !== null) ? (int)$prev['instructor_id'] : null;
        $instrChange = ($prev === null) ? null : (($prevInstr !== null && $instrId !== null && $prevInstr !== $instrId) ? 1 : 0);
        $prevMission = ($prev !== null && $prev['mission_id'] !== null) ? (int)$prev['mission_id'] : null;
        $nextMission = ($next !== null && $next['mission_id'] !== null) ? (int)$next['mission_id'] : null;

        $missionRepeated = ($attemptNo !== null && $attemptNo >= 2) ? 1 : (($attemptNo === null) ? null : 0);
        $sameNext = ($missionId !== null && $nextMission !== null && $missionId === $nextMission) ? 1 : (($next === null || $missionId === null) ? null : 0);

        $returnedLater = null;
        if ($missionId !== null) {
            $returnedLater = (!empty($missionSeenBefore[$missionId]) && $prevMission !== null && $prevMission !== $missionId) ? 1 : 0;
            $missionSeenBefore[$missionId] = true;
        }

        $upd->execute([
            $prevDate, $daysPrev, $nextDate, $daysNext,
            $sessionNumber, $attemptNo, $sessionNumber,
            $cumFlight, $cumSim, $cumTotal,
            $prevInstr, $instrChange, $prevMission, $nextMission,
            $missionRepeated, $sameNext, $returnedLater,
            $sid,
        ]);
    }
}
$pdo->commit();
unset($byStudent);
echo "Session features done.\n";

echo "Computing exercise attempt numbers and regression flags (streaming)...\n";
/**
 * Regression rule:
 * Only when both previous and current achieved grades have documented ordinals (R=1..B=4),
 * and current ordinal < previous ordinal for same student + source_exercise_id.
 */
$exUpd = $pdo->prepare("
    UPDATE fact_exercise_attempt SET
      exercise_attempt_number = ?,
      previous_achieved_grade_raw = ?,
      subsequent_achieved_grade_raw = ?,
      exercise_reappeared = ?,
      exercise_regressed = ?
    WHERE exercise_attempt_id = ?
");

// Process one student at a time to bound memory.
$studentIds = $pdo->query("
    SELECT DISTINCT student_id FROM fact_exercise_attempt
    WHERE student_id IS NOT NULL
    ORDER BY student_id
")->fetchAll(PDO::FETCH_COLUMN);

$sel = $pdo->prepare("
    SELECT exercise_attempt_id, source_exercise_id, session_date, session_id, achieved_grade_raw
    FROM fact_exercise_attempt
    WHERE student_id = ?
      AND source_exercise_id IS NOT NULL
      AND session_date IS NOT NULL
    ORDER BY source_exercise_id, session_date, session_id, exercise_attempt_id
");

$processedStudents = 0;
foreach ($studentIds as $studentId) {
    $sel->execute([(int)$studentId]);
    $list = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$list) {
        continue;
    }

    // Group in-memory per student only
    $grouped = [];
    foreach ($list as $r) {
        $grouped[(int)$r['source_exercise_id']][] = $r;
    }
    unset($list);

    $pdo->beginTransaction();
    foreach ($grouped as $attempts) {
        $n = count($attempts);
        for ($i = 0; $i < $n; $i++) {
            $cur = $attempts[$i];
            $prev = $i > 0 ? $attempts[$i - 1] : null;
            $next = $i < $n - 1 ? $attempts[$i + 1] : null;
            $attemptNo = $i + 1;
            $reappeared = $attemptNo >= 2 ? 1 : 0;
            $prevGrade = $prev['achieved_grade_raw'] ?? null;
            $nextGrade = $next['achieved_grade_raw'] ?? null;
            $regressed = null;
            if ($prev !== null) {
                $prevOrd = exercise_grade_ordinal($prevGrade);
                $curOrd = exercise_grade_ordinal($cur['achieved_grade_raw']);
                if ($prevOrd !== null && $curOrd !== null) {
                    $regressed = ($curOrd < $prevOrd) ? 1 : 0;
                }
            }
            $exUpd->execute([
                $attemptNo, $prevGrade, $nextGrade, $reappeared, $regressed,
                (int)$cur['exercise_attempt_id'],
            ]);
        }
    }
    $pdo->commit();
    unset($grouped);
    $processedStudents++;
    if ($processedStudents % 50 === 0) {
        echo "  exercise features: {$processedStudents} students\n";
    }
}

$pdo->prepare("INSERT INTO qa_data_issue (etl_run_id, severity, issue_code, entity_table, entity_id, source_table, source_pk, detail, created_at)
 VALUES (?,?,?,?,?,?,?,?,?)")->execute([
    $etlRunId, 'INFO', 'FEATURE_DEFINITIONS', 'etl_run', $etlRunId, null, null,
    'mission_repeated: attempt_number>=2 for student+mission. same_mission_next_session: next session same mission. mission_returned_to_later: mission seen earlier and previous session was a different mission. exercise_regressed: later attempt ordinal < prior ordinal where R=1,Y=2,G=3,B=4. required_level_met: achieved_ordinal >= required_ordinal.',
    gmdate('c'),
]);

echo "Feature computation complete.\n";
