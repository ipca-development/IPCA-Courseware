<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$freshness = $db ? ta_analysis_freshness($db) : null;
$instructorId = trim((string)($_GET['instructor_id'] ?? ''));

cw_header('Instructor detail');

ta_page_open([
    'title' => 'Instructor drill-down',
    'description' => 'Volume, program mix, grade distribution, and calibration signals. Analytical calibration — not a performance judgment.',
    'active' => 'instructors',
    'show_filters' => false,
    'freshness' => $freshness,
    'back' => ['href' => '/admin/training_analytics/instructors.php', 'label' => 'Instructors'],
    'db_missing' => $db === null,
]);

if ($db) {
    if ($instructorId === '') {
        ta_unavailable('Select an instructor from the calibration list.', 'instructor_id');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $cal = ta_query_one($db, 'SELECT * FROM analysis_instructor_calibration WHERE CAST(instructor_id AS TEXT)=? LIMIT 1', [$instructorId]);
    $dups = ta_instructor_duplicate_names($db);
    $dupWarn = null;
    foreach ($dups as $name => $ids) {
        if (in_array($instructorId, $ids, true)) {
            $dupWarn = $name . ' → ids ' . implode(', ', $ids);
            break;
        }
    }

    echo '<div class="card ta-warn-banner"><strong>Analytical calibration signal — not an instructor performance judgment.</strong> ';
    echo 'Populations, curriculum assignments, phases, and student difficulty differ.</div>';

    if ($dupWarn) {
        echo '<div class="card ta-warn-banner"><strong>Unresolved duplicate identity candidate:</strong> ' . ta_h($dupWarn) . '. Not silently merged.</div>';
    }

    if (!$cal) {
        ta_insufficient('No calibration row for this instructor.', 'analysis_instructor_calibration');
    } else {
        if ((string)$cal['sample_sufficiency'] === 'SAMPLE_LIMITED') {
            echo '<div class="card ta-card ta-alert"><strong>SAMPLE_LIMITED</strong><p>Interpret signals cautiously — sample below preferred threshold.</p></div>';
        }
        echo '<div class="card ta-card">';
        echo '<h2>' . ta_h((string)$cal['instructor_name']) . '</h2>';
        echo '<p class="ta-sub"><span class="ta-id-chip">instructor_id=' . ta_h($instructorId) . '</span> · ';
        echo '<span class="ta-id-chip">source_user_id=' . ta_h((string)($cal['source_user_id'] ?? '')) . '</span> · ';
        echo ta_h((string)$cal['analysis_version']) . '</p>';
        echo '<div class="ta-insight__metrics">';
        foreach ([
            ['Sessions', ta_fmt_int($cal['n_sessions'])],
            ['Exercise marks', ta_fmt_int($cal['n_exercise_marks'])],
            ['PE assignment rate', ta_fmt_pct($cal['pe_rate'])],
            ['Required-level met', ta_fmt_pct($cal['required_met_rate'])],
            ['Progression repeat', ta_fmt_pct($cal['progression_repeat_rate'])],
            ['Downstream problem', $cal['downstream_problem_rate'] !== null ? ta_fmt_pct($cal['downstream_problem_rate']) : 'NO DATA'],
        ] as [$l, $v]) {
            echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
        }
        echo '</div>';
        echo '<p><span class="ta-pill">' . ta_h(ta_instructor_signal_label((string)$cal['pattern_signal'])) . '</span> ';
        echo ta_h((string)($cal['pattern_notes'] ?? '')) . ' · Sample: ' . ta_h((string)$cal['sample_sufficiency']) . '</p>';
        echo '</div>';
    }

    // Program mix
    echo '<div class="card ta-card"><h2>Program mix</h2>';
    $mix = ta_query_all(
        $db,
        "SELECT s.program_id, p.program_name, v.version_code, COUNT(*) AS sessions,
                COUNT(DISTINCT s.student_id) AS students,
                MIN(s.session_date) AS dmin, MAX(s.session_date) AS dmax
         FROM fact_training_session s
         LEFT JOIN dim_program p ON p.program_id = s.program_id
         LEFT JOIN dim_curriculum_version v ON v.curriculum_version_id = p.curriculum_version_id
         WHERE CAST(s.instructor_id AS TEXT)=?
         GROUP BY s.program_id
         ORDER BY sessions DESC",
        [$instructorId]
    );
    if (!$mix) {
        ta_insufficient('No sessions for this instructor.', 'fact_training_session');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Program</th><th>Version</th><th>Sessions</th><th>Students</th><th>Era</th></tr></thead><tbody>';
        foreach ($mix as $m) {
            echo '<tr><td>' . ta_h((string)($m['program_name'] ?? $m['program_id'])) . ' <span class="ta-id-chip">id=' . ta_h((string)$m['program_id']) . '</span></td>';
            echo '<td>' . ta_h((string)($m['version_code'] ?? '—')) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($m['sessions'])) . '</td><td>' . ta_h(ta_fmt_int($m['students'])) . '</td>';
            echo '<td>' . ta_h((string)$m['dmin'] . ' → ' . (string)$m['dmax']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Grade distribution
    echo '<div class="card ta-card"><h2>Grade distribution</h2>';
    echo '<p class="ta-sub">Session-level grading_raw counts (fact_training_session). Not exercise marks.</p>';
    $grades = ta_query_all(
        $db,
        "SELECT COALESCE(NULLIF(TRIM(grading_raw),''), '(blank)') AS grade, COUNT(*) AS c
         FROM fact_training_session WHERE CAST(instructor_id AS TEXT)=?
         GROUP BY 1 ORDER BY c DESC LIMIT 20",
        [$instructorId]
    );
    if (!$grades) {
        ta_unavailable('No grade distribution.', 'fact_training_session.grading_raw');
    } else {
        $max = 1;
        foreach ($grades as $g) {
            $max = max($max, (int)$g['c']);
        }
        echo '<div class="ta-bars">';
        foreach ($grades as $g) {
            $w = round(100 * (int)$g['c'] / $max);
            echo '<div class="ta-bar-row"><div>' . ta_h((string)$g['grade']) . '</div>';
            echo '<div class="ta-bar-track"><div class="ta-bar-fill" style="width:' . $w . '%"></div></div>';
            echo '<strong>' . ta_h(ta_fmt_int($g['c'])) . '</strong></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // Mission repeats
    echo '<div class="card ta-card"><h2>Mission repeat patterns</h2>';
    $reps = ta_query_all(
        $db,
        "SELECT m.mission_code, m.mission_name, COUNT(*) AS sessions,
                AVG(s.mission_attempt_number) AS avg_attempts,
                SUM(CASE WHEN COALESCE(s.mission_attempt_number,1)>1 THEN 1 ELSE 0 END)*1.0/COUNT(*) AS repeat_share
         FROM fact_training_session s
         LEFT JOIN dim_mission m ON m.mission_id = s.mission_id
         LEFT JOIN analysis_mission_role r ON r.mission_id = s.mission_id
         WHERE CAST(s.instructor_id AS TEXT)=?
           AND COALESCE(r.mission_role,'PROGRESSION_MISSION')='PROGRESSION_MISSION'
         GROUP BY s.mission_id
         HAVING sessions >= 10
         ORDER BY repeat_share DESC, sessions DESC
         LIMIT 12",
        [$instructorId]
    );
    if (!$reps) {
        ta_insufficient('Not enough progression-mission volume for repeat patterns.', 'min 10 sessions/mission');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Mission</th><th>Sessions</th><th>Avg attempts</th><th>Repeat share</th></tr></thead><tbody>';
        foreach ($reps as $r) {
            echo '<tr><td>' . ta_h(trim((string)($r['mission_code'] ?? '') . ' | ' . (string)($r['mission_name'] ?? ''))) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['sessions'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($r['avg_attempts'], 2)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($r['repeat_share'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Instructor change sessions involving this instructor
    echo '<div class="card ta-card"><h2>Student transition / instructor-change context</h2>';
    $chg = ta_query_one(
        $db,
        "SELECT COUNT(*) AS n_change,
                AVG(CASE WHEN instructor_change_indicator=1 THEN 1.0 ELSE 0.0 END) AS share_as_change
         FROM fact_training_session WHERE CAST(instructor_id AS TEXT)=?",
        [$instructorId]
    );
    $pop = ta_query_one($db, "SELECT * FROM analysis_unexpected_finding WHERE finding_id=14 LIMIT 1");
    if ($chg) {
        echo '<p>Share of this instructor’s sessions marked as instructor-change: <strong>' . ta_h(ta_fmt_pct($chg['share_as_change'])) . '</strong>.</p>';
    }
    if ($pop) {
        echo '<p class="ta-muted">Population finding: ' . ta_h((string)$pop['title']) . ' — ' . ta_h((string)$pop['magnitude']) . ' (' . ta_h((string)$pop['analysis_version']) . ').</p>';
    }
    echo '</div>';

    // Baseline comparison
    $all = ta_instructor_calibration_rows($db, 50);
    $baseline = $all[0]['baseline'] ?? null;
    if ($cal && $baseline) {
        echo '<div class="card ta-card"><h2>Comparison with population reference</h2>';
        echo '<p class="ta-sub">SAMPLE_OK median reference (not a full program-adjusted multilevel model). Phase 4 does not store a separate program-adjusted baseline table.</p>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Metric</th><th>Instructor</th><th>Reference median</th><th>Δ</th></tr></thead><tbody>';
        foreach (['pe_rate' => 'PE rate', 'required_met_rate' => 'Required met', 'progression_repeat_rate' => 'Repeat burden', 'downstream_problem_rate' => 'Downstream problem'] as $k => $lab) {
            $iv = $cal[$k] ?? null;
            $bv = $baseline[$k] ?? null;
            $d = ($iv !== null && $bv !== null) ? ((float)$iv - (float)$bv) : null;
            echo '<tr><td>' . ta_h($lab) . '</td><td>' . ($iv !== null ? ta_h(ta_fmt_pct($iv)) : '—') . '</td>';
            echo '<td>' . ($bv !== null ? ta_h(ta_fmt_pct($bv)) : '—') . '</td>';
            echo '<td>' . ($d !== null ? ta_h(($d >= 0 ? '+' : '') . ta_fmt_pct($d)) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    echo '<div class="card ta-card"><h2>Exercise-specific patterns</h2>';
    ta_unavailable('Exercise×instructor calibration is not materialized in analysis_instructor_calibration.', 'Would require new analysis table');
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
