<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$freshness = $db ? ta_analysis_freshness($db) : null;
$itemId = trim((string)($_GET['item_id'] ?? ''));
$programId = trim((string)($_GET['program_id'] ?? ''));

cw_header('Mission drill-down');

ta_page_open([
    'title' => 'Mission drill-down',
    'description' => 'Curriculum position, repeat behavior, difficult exercises, and gap relationship. Aggregate management view — individual student names are not shown.',
    'active' => 'bottlenecks',
    'show_filters' => false,
    'freshness' => $freshness,
    'back' => ['href' => '/admin/training_analytics/bottlenecks.php', 'label' => 'Mission bottlenecks'],
    'db_missing' => $db === null,
]);

if ($db) {
    if ($itemId === '') {
        ta_unavailable('Select a mission from the bottlenecks list.', 'item_id');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $bn = ta_query_one(
        $db,
        "SELECT * FROM analysis_program_bottleneck
         WHERE CAST(item_id AS TEXT) = ?" . ($programId !== '' ? ' AND CAST(program_id AS TEXT) = ?' : '') . "
         AND item_type='PROGRESSION_MISSION'
         ORDER BY metric_value DESC LIMIT 1",
        $programId !== '' ? [$itemId, $programId] : [$itemId]
    );

    $mission = ta_query_one(
        $db,
        "SELECT m.*, r.mission_role, r.mission_role_confidence, r.mission_role_reason,
                r.program_id AS role_program_id, p.program_name
         FROM dim_mission m
         LEFT JOIN analysis_mission_role r ON r.mission_id = m.mission_id
         LEFT JOIN dim_program p ON p.program_id = r.program_id
         WHERE CAST(m.mission_id AS TEXT) = ? OR CAST(m.source_scenario_id AS TEXT) = ?
         LIMIT 1",
        [$itemId, $itemId]
    );

    $title = $bn['item_label'] ?? ($mission['mission_name'] ?? ('Mission ' . $itemId));
    $progName = $bn['program_name'] ?? ($mission['program_name'] ?? $programId);

    echo '<div class="card ta-card">';
    echo '<h2>' . ta_h((string)$title) . '</h2>';
    echo '<p class="ta-sub">Program: ' . ta_h((string)$progName);
    if ($mission) {
        echo ' · Code: ' . ta_h((string)($mission['mission_code'] ?? ''));
        echo ' · Role: ' . ta_h((string)($mission['mission_role'] ?? '—'));
        if (!empty($mission['mission_role_confidence'])) {
            echo ' ' . ta_confidence_badge((string)$mission['mission_role_confidence']);
        }
    }
    echo '</p>';
    if ($bn) {
        echo '<div class="ta-insight__metrics">';
        echo '<div><span>Extra sessions / student</span><strong>' . ta_h(ta_fmt_num($bn['metric_value'], 2)) . '</strong></div>';
        echo '<div><span>Sample n</span><strong>' . ta_h(ta_fmt_int($bn['n'])) . '</strong></div>';
        echo '<div><span>Confidence</span><strong>' . ta_h(ta_confidence_label((string)($bn['confidence'] ?? ''))) . '</strong></div>';
        echo '</div>';
    }
    echo '</div>';

    // Curriculum position
    echo '<div class="card ta-card">';
    echo '<h2>Where this mission sits</h2>';
    if (!$mission) {
        ta_unavailable('Mission dimension row not found for this id.', 'dim_mission');
    } else {
        echo '<p class="ta-sub">' . ta_h((string)($mission['mission_role_reason'] ?? 'Role classification from analysis_mission_role')) . '</p>';
        $cols = [];
        foreach (['stage_name', 'phase_name', 'phase_code', 'stage_code', 'mission_order', 'sequence_order'] as $c) {
            if (array_key_exists($c, $mission) && $mission[$c] !== null && $mission[$c] !== '') {
                $cols[] = '<strong>' . ta_h($c) . ':</strong> ' . ta_h((string)$mission[$c]);
            }
        }
        if ($cols) {
            echo '<p>' . implode(' · ', $cols) . '</p>';
        } else {
            echo '<p class="ta-muted">Detailed stage/phase columns are limited on this mission row. Mission id=' . ta_h((string)$mission['mission_id']) . '.</p>';
        }
    }
    echo '</div>';

    // Repeat behavior from fact aggregate (lightweight)
    echo '<div class="card ta-card">';
    echo '<h2>Repeat behavior</h2>';
    echo '<p class="ta-sub">Aggregated from fact_training_session for this mission_id — no student identifiers.</p>';
    $mid = (string)($mission['mission_id'] ?? $itemId);
    $rep = ta_query_one(
        $db,
        "SELECT COUNT(*) AS sessions,
                COUNT(DISTINCT student_id) AS students,
                AVG(mission_attempt_number) AS avg_attempts,
                MAX(mission_attempt_number) AS max_attempts,
                SUM(CASE WHEN COALESCE(mission_attempt_number,1) > 1 THEN 1 ELSE 0 END) * 1.0 / COUNT(*) AS repeat_session_share,
                AVG(CASE WHEN days_since_previous_session IS NOT NULL THEN days_since_previous_session END) AS avg_gap,
                AVG(CASE WHEN grading_completion = 'incomplete' OR required_level_met_session = 0 THEN 1.0 ELSE 0.0 END) AS incomplete_proxy
         FROM fact_training_session
         WHERE CAST(mission_id AS TEXT) = ?",
        [$mid]
    );
    if (!$rep || (int)($rep['sessions'] ?? 0) === 0) {
        ta_unavailable('No sessions for this mission in fact_training_session.', 'fact_training_session');
    } else {
        echo '<div class="ta-insight__metrics">';
        foreach ([
            ['Sessions', ta_fmt_int($rep['sessions'])],
            ['Students', ta_fmt_int($rep['students'])],
            ['Avg attempts', ta_fmt_num($rep['avg_attempts'], 2)],
            ['Max attempts', ta_fmt_int($rep['max_attempts'])],
            ['Repeat session share', ta_fmt_pct($rep['repeat_session_share'])],
            ['Avg prior gap (days)', ta_fmt_num($rep['avg_gap'], 1)],
        ] as [$l, $v]) {
            echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // Attempt distribution
    echo '<div class="card ta-card">';
    echo '<h2>Attempt distribution</h2>';
    $dist = ta_query_all(
        $db,
        "SELECT COALESCE(mission_attempt_number,1) AS attempt_number, COUNT(*) AS sessions, COUNT(DISTINCT student_id) AS students
         FROM fact_training_session
         WHERE CAST(mission_id AS TEXT) = ?
         GROUP BY 1 ORDER BY 1 LIMIT 15",
        [$mid]
    );
    if (!$dist) {
        ta_unavailable('No attempt distribution.', 'fact_training_session');
    } else {
        $maxS = 1;
        foreach ($dist as $d) {
            $maxS = max($maxS, (int)$d['sessions']);
        }
        echo '<div class="ta-bars">';
        foreach ($dist as $d) {
            $w = round(100 * (int)$d['sessions'] / $maxS);
            echo '<div class="ta-bar-row"><div>Attempt ' . (int)$d['attempt_number'] . '</div>';
            echo '<div class="ta-bar-track"><div class="ta-bar-fill" style="width:' . $w . '%"></div></div>';
            echo '<strong>' . ta_h(ta_fmt_int($d['sessions'])) . '</strong></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // Common below-standard exercises in program bottlenecks
    echo '<div class="card ta-card">';
    echo '<h2>Common below-standard exercises (program)</h2>';
    echo '<p class="ta-sub">Program-level exercise bottlenecks — mission-linked required-exercise lists are not fully materialized in analysis_*.</p>';
    $pid = (string)($bn['program_id'] ?? $mission['role_program_id'] ?? $programId);
    $ex = $pid !== '' ? ta_query_all(
        $db,
        "SELECT item_label, metric_value, n, confidence FROM analysis_program_bottleneck
         WHERE item_type='EXERCISE' AND CAST(program_id AS TEXT)=? AND metric_name='not_met_rate'
         ORDER BY metric_value DESC LIMIT 12",
        [$pid]
    ) : [];
    if (!$ex) {
        ta_unavailable('No exercise bottleneck rows for this program.', 'analysis_program_bottleneck EXERCISE');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Exercise</th><th>Not-met</th><th>n</th><th>Confidence</th></tr></thead><tbody>';
        foreach ($ex as $e) {
            echo '<tr><td>' . ta_h((string)$e['item_label']) . '</td><td>' . ta_h(ta_fmt_pct($e['metric_value'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($e['n'])) . '</td><td>' . ta_confidence_badge((string)($e['confidence'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Gap relationship for this mission's sessions
    echo '<div class="card ta-card">';
    echo '<h2>Training-gap relationship</h2>';
    echo '<p class="ta-sub">Descriptive incomplete share by prior-gap bucket for this mission only (lightweight fact aggregate).</p>';
    $gap = ta_query_all(
        $db,
        "SELECT CASE
            WHEN days_since_previous_session IS NULL THEN 'unknown'
            WHEN days_since_previous_session <= 2 THEN '0-2'
            WHEN days_since_previous_session <= 5 THEN '3-5'
            WHEN days_since_previous_session <= 10 THEN '6-10'
            WHEN days_since_previous_session <= 20 THEN '11-20'
            ELSE '21+'
         END AS bucket,
         COUNT(*) AS n,
         AVG(CASE WHEN COALESCE(mission_attempt_number,1) > 1 THEN 1.0 ELSE 0.0 END) AS repeat_share
         FROM fact_training_session
         WHERE CAST(mission_id AS TEXT) = ?
         GROUP BY 1
         ORDER BY CASE bucket
           WHEN '0-2' THEN 1 WHEN '3-5' THEN 2 WHEN '6-10' THEN 3 WHEN '11-20' THEN 4 WHEN '21+' THEN 5 ELSE 6 END",
        [$mid]
    );
    if (!$gap) {
        ta_unavailable('Could not compute mission gap buckets.', 'fact_training_session');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Prior gap</th><th>Sessions</th><th>Repeat share</th></tr></thead><tbody>';
        foreach ($gap as $g) {
            echo '<tr><td>' . ta_h((string)$g['bucket']) . '</td><td>' . ta_h(ta_fmt_int($g['n'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($g['repeat_share'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="card ta-card">';
    echo '<h2>Instructor variation</h2>';
    ta_unavailable('Mission×instructor statistical comparison not yet materialized for this view.', 'Requires instructor Analytics milestone / adjusted baselines');
    echo '</div>';

    echo '<div class="card ta-card">';
    echo '<h2>Narrative themes</h2>';
    ta_unavailable('Mission-linked narrative themes will appear with Narrative Insights.', 'analysis_phase6_narrative_extraction join not wired in milestone 1');
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
