<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$freshness = $db ? ta_analysis_freshness($db) : null;
$programId = trim((string)($_GET['program_id'] ?? ''));

cw_header('Program detail');

ta_page_open([
    'title' => 'Program drill-down',
    'description' => 'Management health view for one curriculum identity — population, bottlenecks, competencies, trajectories, instructors, narratives.',
    'active' => 'programs',
    'show_filters' => false,
    'freshness' => $freshness,
    'back' => ['href' => '/admin/training_analytics/programs.php', 'label' => 'Programs'],
    'db_missing' => $db === null,
]);

if ($db) {
    if ($programId === '') {
        ta_unavailable('Select a program from the Programs list.', 'program_id');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $rows = ta_program_health_rows($db);
    $prog = null;
    foreach ($rows as $r) {
        if ((string)$r['program_id'] === $programId) {
            $prog = $r;
            break;
        }
    }
    if (!$prog || (int)$prog['sessions'] === 0) {
        ta_insufficient('No training sessions for this program identity.', 'program_id=' . $programId);
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    echo '<div class="card ta-card">';
    echo '<h2>' . ta_h((string)$prog['program_name']) . '</h2>';
    echo '<p class="ta-sub">Canonical identity: <span class="ta-id-chip">program_id=' . ta_h($programId) . '</span> · ';
    echo '<span class="ta-id-chip">version=' . ta_h((string)($prog['version_code'] ?? '—')) . '</span> · ';
    echo '<span class="ta-id-chip">family=' . ta_h((string)($prog['family_code'] ?? '—')) . '</span> · ';
    echo '<span class="ta-id-chip" title="source tracking table">' . ta_h((string)($prog['source_tracking_table'] ?? '—')) . '</span></p>';
    echo '<div class="ta-insight__metrics">';
    foreach ([
        ['Students', ta_fmt_int($prog['students'])],
        ['Sessions', ta_fmt_int($prog['sessions'])],
        ['Era', (string)($prog['date_min'] ?? '—') . ' → ' . (string)($prog['date_max'] ?? '—')],
        ['Flight', ta_fmt_int($prog['flight_sessions'])],
        ['FNPT', ta_fmt_int($prog['fnpt_sessions'])],
        ['Ground/brief', ta_fmt_int($prog['ground_sessions'])],
        ['Data sufficiency', (string)$prog['sufficiency']],
    ] as [$l, $v]) {
        echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
    }
    echo '</div></div>';

    $attempts = (int)ta_query_value($db, 'SELECT COUNT(*) FROM fact_exercise_attempt WHERE CAST(program_id AS TEXT)=?', [$programId], 0);
    echo '<div class="card ta-card"><h2>Program overview</h2>';
    echo '<p class="ta-sub">Exercise attempts (fact_exercise_attempt): <strong>' . ta_h(ta_fmt_int($attempts)) . '</strong>. Narrative session coverage: <strong>'
        . ta_h(ta_fmt_pct($prog['narrative_coverage'])) . '</strong>.</p></div>';

    // Bottlenecks
    echo '<div class="card ta-card"><h2>Bottlenecks — progression missions</h2>';
    $bn = ta_query_all(
        $db,
        "SELECT item_id, item_label, metric_value, n, confidence FROM analysis_program_bottleneck
         WHERE item_type='PROGRESSION_MISSION' AND metric_name='extra_sessions_per_student' AND CAST(program_id AS TEXT)=?
         ORDER BY metric_value DESC LIMIT 12",
        [$programId]
    );
    if (!$bn) {
        ta_insufficient('No progression bottlenecks materialized for this program.', 'analysis_program_bottleneck');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Mission</th><th>Extra sessions/student</th><th>n</th><th>Confidence</th><th></th></tr></thead><tbody>';
        foreach ($bn as $b) {
            $href = '/admin/training_analytics/mission.php?item_id=' . rawurlencode((string)$b['item_id']) . '&program_id=' . rawurlencode($programId);
            echo '<tr><td>' . ta_h((string)$b['item_label']) . '</td><td>' . ta_h(ta_fmt_num($b['metric_value'], 2)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($b['n'])) . '</td><td>' . ta_confidence_badge((string)$b['confidence']) . '</td>';
            echo '<td><a href="' . ta_h($href) . '">Drill</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Competencies
    echo '<div class="card ta-card"><h2>Competencies</h2>';
    $ex = ta_query_all(
        $db,
        "SELECT item_label, metric_value, n, confidence FROM analysis_program_bottleneck
         WHERE item_type='EXERCISE' AND CAST(program_id AS TEXT)=? ORDER BY metric_value DESC LIMIT 10",
        [$programId]
    );
    if ($prog['pe_stability'] !== null) {
        echo '<p>PE stability (weighted, n_reobs≥10): <strong>' . ta_h(ta_fmt_pct($prog['pe_stability'])) . '</strong> (n=' . ta_h(ta_fmt_int($prog['pe_n'])) . '). ';
        echo '<a href="/admin/training_analytics/competencies.php?program_id=' . rawurlencode($programId) . '">Open explorer</a></p>';
    } else {
        ta_insufficient('PE stability not available at sample threshold for this program.', 'analysis_competency_stability');
    }
    if ($ex) {
        echo '<h3 style="font-size:14px;margin:12px 0 6px">Top difficult exercises (not-met)</h3>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Exercise</th><th>Not-met</th><th>n</th></tr></thead><tbody>';
        foreach ($ex as $e) {
            echo '<tr><td>' . ta_h((string)$e['item_label']) . '</td><td>' . ta_h(ta_fmt_pct($e['metric_value'])) . '</td><td>' . ta_h(ta_fmt_int($e['n'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Continuity
    echo '<div class="card ta-card"><h2>Continuity</h2>';
    echo '<p class="ta-sub">Per-program gap logit curves are <strong>NOT</strong> in analysis_training_gap_effect (program dummies only).</p>';
    $gapProxy = ta_query_one(
        $db,
        "SELECT AVG(days_since_previous_session) avg_gap,
                AVG(CASE WHEN days_since_previous_session >= 14 THEN 1.0 ELSE 0.0 END) share_14plus
         FROM fact_training_session
         WHERE CAST(program_id AS TEXT)=? AND days_since_previous_session IS NOT NULL",
        [$programId]
    );
    if ($gapProxy) {
        echo '<p>Descriptive session gaps for this program: avg prior gap <strong>' . ta_h(ta_fmt_num($gapProxy['avg_gap'], 1))
            . '</strong> days · share ≥14d <strong>' . ta_h(ta_fmt_pct($gapProxy['share_14plus'])) . '</strong>.</p>';
        echo '<p><a href="/admin/training_analytics/continuity.php">Population continuity models →</a></p>';
    }
    echo '</div>';

    // Trajectories
    echo '<div class="card ta-card"><h2>Student trajectories</h2>';
    echo '<p class="ta-sub">Analytical categories — not student judgments. analysis_student_trajectory</p>';
    $traj = ta_trajectory_distribution($db, $programId);
    if (!$traj) {
        ta_insufficient('No trajectory rows for this program.', 'analysis_student_trajectory');
    } else {
        $max = 1;
        foreach ($traj as $t) {
            $max = max($max, (int)$t['c']);
        }
        echo '<div class="ta-bars">';
        foreach ($traj as $t) {
            $w = round(100 * (int)$t['c'] / $max);
            echo '<div class="ta-bar-row"><div>' . ta_h((string)$t['label']) . '</div>';
            echo '<div class="ta-bar-track"><div class="ta-bar-fill" style="width:' . $w . '%"></div></div>';
            echo '<strong>' . ta_h(ta_fmt_int($t['c'])) . '</strong></div>';
        }
        echo '</div>';
        echo '<p><a href="/admin/training_analytics/progression.php?program_id=' . rawurlencode($programId) . '">Progression page →</a></p>';
    }
    echo '</div>';

    // Instructors
    echo '<div class="card ta-card"><h2>Instructor mix</h2>';
    echo '<p class="ta-sub">Session volume by instructor in this program (fact aggregate). Calibration signals from analysis_instructor_calibration.</p>';
    $imix = ta_query_all(
        $db,
        "SELECT s.instructor_id, COUNT(*) AS sessions, COUNT(DISTINCT s.student_id) AS students,
                c.instructor_name, c.pattern_signal, c.sample_sufficiency, c.pe_rate, c.required_met_rate
         FROM fact_training_session s
         LEFT JOIN analysis_instructor_calibration c ON c.instructor_id = s.instructor_id
         WHERE CAST(s.program_id AS TEXT)=? AND s.instructor_id IS NOT NULL
         GROUP BY s.instructor_id
         ORDER BY sessions DESC LIMIT 15",
        [$programId]
    );
    if (!$imix) {
        ta_insufficient('No instructor mix for this program.', 'fact_training_session');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Instructor</th><th>Sessions</th><th>Students</th><th>PE rate</th><th>Req met</th><th>Signal</th><th></th></tr></thead><tbody>';
        foreach ($imix as $i) {
            $name = (string)($i['instructor_name'] ?? ('#' . $i['instructor_id']));
            $href = '/admin/training_analytics/instructor.php?instructor_id=' . rawurlencode((string)$i['instructor_id']);
            echo '<tr><td>' . ta_h($name) . ' <span class="ta-id-chip">id=' . ta_h((string)$i['instructor_id']) . '</span></td>';
            echo '<td>' . ta_h(ta_fmt_int($i['sessions'])) . '</td><td>' . ta_h(ta_fmt_int($i['students'])) . '</td>';
            echo '<td>' . ($i['pe_rate'] !== null ? ta_h(ta_fmt_pct($i['pe_rate'])) : '—') . '</td>';
            echo '<td>' . ($i['required_met_rate'] !== null ? ta_h(ta_fmt_pct($i['required_met_rate'])) : '—') . '</td>';
            echo '<td>' . ta_h(ta_instructor_signal_label((string)($i['pattern_signal'] ?? ''))) . '</td>';
            echo '<td><a href="' . ta_h($href) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="ta-calib-note">Analytical calibration signal — not an instructor performance judgment.</p>';
    }
    echo '</div>';

    // Narrative
    echo '<div class="card ta-card"><h2>Narrative signal</h2>';
    $nq = ta_query_all(
        $db,
        "SELECT metric_name, metric_value, n FROM analysis_phase6_nlp_qa WHERE stratum = ? ORDER BY metric_name",
        ['program_id=' . $programId]
    );
    if (!$nq) {
        ta_insufficient('No Phase 6 NLP QA stratum for this program.', 'analysis_phase6_nlp_qa');
    } else {
        echo '<p class="ta-sub">Source: analysis_phase6_nlp_qa · stratum=program_id=' . ta_h($programId) . ' (heuristic-scaled enrichment mix — see Narratives for extractor separation).</p>';
        echo '<div class="ta-insight__metrics">';
        foreach ($nq as $n) {
            if ((string)$n['metric_name'] === 'n') {
                continue;
            }
            echo '<div><span>' . ta_h((string)$n['metric_name']) . '</span><strong>' . ta_h(ta_fmt_pct($n['metric_value'])) . '</strong></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // Curriculum comparison
    echo '<div class="card ta-card"><h2>Curriculum comparison</h2>';
    $fam = (string)($prog['family_code'] ?? '');
    $ver = (string)($prog['version_code'] ?? '');
    $pairs = $fam !== '' ? array_values(array_filter(ta_curriculum_pairs($db), static fn ($p) => (string)$p['family_code'] === $fam && ((string)$p['version_a'] === $ver || (string)$p['version_b'] === $ver))) : [];
    if (!$pairs) {
        ta_insufficient('No old/new curriculum pair involving this version.', 'analysis_curriculum_comparison');
    } else {
        foreach ($pairs as $p) {
            echo '<p><a href="/admin/training_analytics/curriculum.php?family=' . rawurlencode($fam) . '">'
                . ta_h($p['version_a'] . ' → ' . $p['version_b']) . '</a> · '
                . '<span class="ta-verdict ' . ta_h($p['rollup']['class']) . '">' . ta_h($p['rollup']['label']) . '</span></p>';
        }
    }
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
