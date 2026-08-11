<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$filters = ta_parse_filters($_GET);
$options = $db ? ta_filter_options($db) : [];
$freshness = $db ? ta_analysis_freshness($db) : null;
$label = trim((string)($_GET['label'] ?? ''));
$eraNote = trim((string)($_GET['era'] ?? ''));

cw_header('Student Progression');

ta_page_open([
    'title' => 'Student progression patterns',
    'description' => 'Aggregate longitudinal trajectory categories from Phase 4. Analytical groups — not judgments about individual students. No student names.',
    'active' => 'progression',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id', 'version_id', 'session_type'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    if (!ta_table_exists($db, 'analysis_student_trajectory')) {
        ta_unavailable('Trajectory table missing.', 'analysis_student_trajectory');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    if ($filters['version_id'] || $filters['session_type'] || $eraNote !== '') {
        echo '<div class="card ta-card"><p class="ta-muted">Curriculum version, training type, and era are <strong>not columns</strong> on analysis_student_trajectory. ';
        echo 'Program filter is supported. For version/era comparisons use Programs / Curriculum views or request a future materialization. ';
        if ($filters['version_id'] || $filters['session_type'] || $eraNote !== '') {
            echo '<span class="ta-pill ta-pill--active">Unsupported filter ignored for trajectory counts</span>';
        }
        echo '</p></div>';
    }

    $dist = ta_trajectory_distribution($db, $filters['program_id']);
    echo '<div class="card ta-card"><h2>Trajectory distribution</h2>';
    echo '<p class="ta-sub">Canonical labels stored in analysis_student_trajectory'
        . ($filters['program_id'] ? ' · program_id=' . ta_h($filters['program_id']) : ' · all programs') . '.</p>';
    if (!$dist) {
        ta_insufficient('No trajectory rows.', 'analysis_student_trajectory');
    } else {
        $max = 1;
        foreach ($dist as $t) {
            $max = max($max, (int)$t['c']);
        }
        echo '<div class="ta-bars">';
        foreach ($dist as $t) {
            $href = '/admin/training_analytics/progression.php?' . http_build_query(array_filter([
                'program_id' => $filters['program_id'],
                'label' => $t['label'],
            ]));
            $w = round(100 * (int)$t['c'] / $max);
            echo '<div class="ta-bar-row"><div><a href="' . ta_h($href) . '">' . ta_h((string)$t['label']) . '</a></div>';
            echo '<div class="ta-bar-track"><div class="ta-bar-fill" style="width:' . $w . '%"></div></div>';
            echo '<strong>' . ta_h(ta_fmt_int($t['c'])) . ' · ' . ta_h(ta_fmt_pct($t['share'])) . '</strong></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // By program comparison (compact)
    echo '<div class="card ta-card"><h2>Distribution by program</h2>';
    echo '<p class="ta-sub">Share of non-UNKNOWN trajectories by program (programs with ≥20 labeled rows).</p>';
    $byProg = ta_query_all(
        $db,
        "SELECT t.program_id, p.program_name, t.trajectory_label, COUNT(*) AS c
         FROM analysis_student_trajectory t
         LEFT JOIN dim_program p ON p.program_id = t.program_id
         WHERE t.trajectory_label != 'UNKNOWN'
         GROUP BY t.program_id, t.trajectory_label
         ORDER BY t.program_id, c DESC"
    );
    $pivot = [];
    foreach ($byProg as $r) {
        $pid = (string)$r['program_id'];
        if (!isset($pivot[$pid])) {
            $pivot[$pid] = ['name' => (string)($r['program_name'] ?? $pid), 'total' => 0, 'labels' => []];
        }
        $pivot[$pid]['labels'][(string)$r['trajectory_label']] = (int)$r['c'];
        $pivot[$pid]['total'] += (int)$r['c'];
    }
    $pivot = array_filter($pivot, static fn ($p) => $p['total'] >= 20);
    if (!$pivot) {
        ta_insufficient('No programs meet the ≥20 labeled-trajectory threshold.', '');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Program</th><th>n</th><th>Top patterns</th></tr></thead><tbody>';
        foreach ($pivot as $pid => $p) {
            arsort($p['labels']);
            $bits = [];
            foreach (array_slice($p['labels'], 0, 4, true) as $lab => $c) {
                $bits[] = $lab . ' ' . ta_fmt_pct($c / max(1, $p['total']));
            }
            $href = '/admin/training_analytics/progression.php?program_id=' . rawurlencode((string)$pid);
            echo '<tr><td><a href="' . ta_h($href) . '">' . ta_h($p['name']) . '</a> <span class="ta-id-chip">id=' . ta_h((string)$pid) . '</span></td>';
            echo '<td>' . ta_h(ta_fmt_int($p['total'])) . '</td><td class="ta-muted">' . ta_h(implode(' · ', $bits)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Group drill
    if ($label !== '') {
        echo '<div class="card ta-card"><h2>Trajectory group: ' . ta_h($label) . '</h2>';
        echo '<p class="ta-sub">Anonymized aggregate profile — no student identifiers.</p>';
        $prof = ta_trajectory_group_profile($db, $label, $filters['program_id']);
        if (!$prof) {
            ta_insufficient('No rows for this trajectory label under current filters.', $label);
        } else {
            echo '<div class="ta-insight__metrics">';
            foreach ([
                ['Students (rows)', ta_fmt_int($prof['n'])],
                ['Avg sessions', ta_fmt_num($prof['avg_sessions'], 1)],
                ['Avg flight hours', ta_fmt_num($prof['avg_flight_hours'], 1)],
                ['Avg calendar days', ta_fmt_num($prof['avg_calendar_days'], 0)],
                ['Avg progression repeats', ta_fmt_num($prof['avg_repeats'], 2)],
                ['Avg regressions', ta_fmt_num($prof['avg_regressions'], 2)],
                ['Avg median gap (days)', ta_fmt_num($prof['avg_median_gap'], 1)],
                ['Avg instructor switches', ta_fmt_num($prof['avg_instructor_switches'], 2)],
                ['Avg PE stability', ta_fmt_pct($prof['avg_pe_stability'])],
                ['Avg below-required', ta_fmt_pct($prof['avg_below_required'])],
            ] as [$l, $v]) {
                echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
            }
            echo '</div>';

            // Representative reasons from features_json (aggregate counts)
            $reasons = ta_query_all(
                $db,
                "SELECT features_json, COUNT(*) AS c FROM analysis_student_trajectory
                 WHERE trajectory_label=?" . ($filters['program_id'] ? ' AND CAST(program_id AS TEXT)=?' : '') . '
                 GROUP BY features_json ORDER BY c DESC LIMIT 8',
                $filters['program_id'] ? [$label, $filters['program_id']] : [$label]
            );
            echo '<h3 style="font-size:14px;margin:14px 0 6px">Stored feature reasons (aggregated)</h3>';
            echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>features_json</th><th>n</th></tr></thead><tbody>';
            foreach ($reasons as $r) {
                echo '<tr><td><code>' . ta_h((string)($r['features_json'] ?? '')) . '</code></td><td>' . ta_h(ta_fmt_int($r['c'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }

    // Drivers — only associations supported
    echo '<div class="card ta-card"><h2>Progression drivers (supported associations)</h2>';
    echo '<p class="ta-sub">Group averages from trajectory features — not causal models.</p>';
    $drivers = ta_query_all(
        $db,
        "SELECT trajectory_label,
                AVG(median_gap_days) AS gap,
                AVG(progression_mission_repeats) AS repeats,
                AVG(exercise_regression_count) AS regressions,
                AVG(instructor_switches) AS switches,
                COUNT(*) AS n
         FROM analysis_student_trajectory
         WHERE trajectory_label != 'UNKNOWN'"
         . ($filters['program_id'] ? ' AND CAST(program_id AS TEXT)=?' : '') . '
         GROUP BY trajectory_label
         ORDER BY n DESC',
        $filters['program_id'] ? [$filters['program_id']] : []
    );
    if (!$drivers) {
        ta_unavailable('No driver aggregates.', 'analysis_student_trajectory');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Trajectory</th><th>n</th><th>Median gap</th><th>Mission repeats</th><th>Regressions</th><th>Instructor switches</th></tr></thead><tbody>';
        foreach ($drivers as $d) {
            echo '<tr><td>' . ta_h((string)$d['trajectory_label']) . '</td><td>' . ta_h(ta_fmt_int($d['n'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($d['gap'], 1)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($d['repeats'], 2)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($d['regressions'], 2)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($d['switches'], 2)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p class="ta-muted">Curriculum version / modality drivers are not on this table — <strong>NO DATA</strong> for those joins here.</p></div>';
}

ta_page_close($freshness);
cw_footer();
