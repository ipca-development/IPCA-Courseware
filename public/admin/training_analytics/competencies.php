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
$q = trim((string)($_GET['q'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'explore'));
$minN = max(10, (int)($_GET['min_n'] ?? 30));

cw_header('Competencies');

ta_page_open([
    'title' => 'Competency explorer',
    'description' => 'Search exercises/competencies for exposure, PE stability, regression, and learning-curve entry points. Management analytics — no student names.',
    'active' => 'competencies',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    if (!ta_table_exists($db, 'analysis_competency_stability')) {
        ta_unavailable('Competency stability table missing.', 'analysis_competency_stability');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    echo '<div class="card ta-card">';
    echo '<form class="ta-search" method="get">';
    if ($filters['program_id']) {
        echo '<input type="hidden" name="program_id" value="' . ta_h($filters['program_id']) . '">';
    }
    echo '<input class="app-input" type="search" name="q" placeholder="Search: Steep Turns, Stalls, Landing, Holding…" value="' . ta_h($q) . '">';
    echo '<select class="app-select" name="view">';
    foreach (['explore' => 'Explorer', 'stability' => 'PE stability ranks', 'softening' => 'Highest later softening'] as $k => $lab) {
        $sel = $view === $k ? ' selected' : '';
        echo '<option value="' . ta_h($k) . '"' . $sel . '>' . ta_h($lab) . '</option>';
    }
    echo '</select>';
    echo '<label class="ta-field" style="min-width:100px"><span>Min reobs</span>';
    echo '<input class="app-input" type="number" name="min_n" min="10" value="' . (int)$minN . '"></label>';
    echo '<button class="btn" type="submit">Search</button>';
    echo '</form>';
    echo '<p class="ta-sub">Tiny samples are excluded from rankings (min reobservations = ' . (int)$minN . ').</p>';
    echo '</div>';

    if ($view === 'stability' || $view === 'softening') {
        $order = $view === 'softening' ? 'stable_pe_rate ASC' : 'stable_pe_rate DESC';
        $args = [$minN];
        $where = "required_level='PE' AND n_reobserved >= ?";
        if ($filters['program_id']) {
            $where .= ' AND CAST(program_id AS TEXT) = ?';
            $args[] = $filters['program_id'];
        }
        if ($q !== '') {
            $where .= ' AND lower(exercise_name) LIKE ?';
            $args[] = '%' . strtolower($q) . '%';
        }
        $rows = ta_query_all(
            $db,
            "SELECT exercise_id, exercise_name, program_id, n_reached_pe, n_reobserved, stable_pe_rate,
                    one_time_regression_rate, repeated_regression_rate
             FROM analysis_competency_stability
             WHERE $where
             ORDER BY $order
             LIMIT 50",
            $args
        );
        echo '<div class="card ta-card">';
        echo '<h2>' . ($view === 'softening' ? 'Competencies with highest later softening' : 'Most stable PE competencies') . '</h2>';
        echo '<p class="ta-sub">Required level PE · n_reobserved ≥ ' . (int)$minN . ' · analysis_competency_stability</p>';
        if (!$rows) {
            ta_unavailable('No rows met the sample threshold.', 'analysis_competency_stability');
        } else {
            echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
            echo '<th>Competency</th><th>Program</th><th>Reached PE</th><th>Reobs</th><th>Stable PE</th><th>One-time soft</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $href = '/admin/training_analytics/competency.php?exercise_id=' . rawurlencode((string)$r['exercise_id'])
                    . '&program_id=' . rawurlencode((string)$r['program_id']);
                $highlight = '';
                $name = strtolower((string)$r['exercise_name']);
                if (str_contains($name, 'intercept') || str_contains($name, 'hold') || str_contains($name, 'go-around') || str_contains($name, 'go around')) {
                    $highlight = ' <span class="ta-pill">IR-relevant</span>';
                }
                echo '<tr><td>' . ta_h((string)$r['exercise_name']) . $highlight . '</td>';
                echo '<td>' . ta_h((string)$r['program_id']) . '</td>';
                echo '<td>' . ta_h(ta_fmt_int($r['n_reached_pe'])) . '</td>';
                echo '<td>' . ta_h(ta_fmt_int($r['n_reobserved'])) . '</td>';
                echo '<td>' . ta_h(ta_fmt_pct($r['stable_pe_rate'])) . '</td>';
                echo '<td>' . ta_h(ta_fmt_pct($r['one_time_regression_rate'])) . '</td>';
                echo '<td><a href="' . ta_h($href) . '">Drill down</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    } else {
        $rows = ta_competency_list($db, $q, $filters['program_id'], 100);
        echo '<div class="card ta-card">';
        echo '<h2>Competencies</h2>';
        echo '<p class="ta-sub">Sorted by reobservation volume. Click through for learning curves, transitions, co-difficulties, and recorder potential.</p>';
        if (!$rows) {
            ta_unavailable('No competencies matched.', 'analysis_competency_stability');
        } else {
            echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
            echo '<th>Exercise</th><th>Program</th><th>Req</th><th>PE reached</th><th>Reobs</th><th>PE stable</th><th>Regression</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $href = '/admin/training_analytics/competency.php?exercise_id=' . rawurlencode((string)$r['exercise_id'])
                    . '&program_id=' . rawurlencode((string)$r['program_id']);
                echo '<tr><td>' . ta_h((string)$r['exercise_name']) . '</td>';
                echo '<td>' . ta_h((string)($r['program_name'] ?? $r['program_id'])) . '</td>';
                echo '<td>' . ta_h((string)$r['required_level']) . '</td>';
                echo '<td>' . ta_h(ta_fmt_int($r['n_reached_pe'])) . '</td>';
                echo '<td>' . ta_h(ta_fmt_int($r['n_reobserved'])) . '</td>';
                echo '<td>' . ta_h(ta_fmt_pct($r['stable_pe_rate'])) . '</td>';
                echo '<td>' . ta_h(ta_fmt_pct($r['one_time_regression_rate'])) . '</td>';
                echo '<td><a href="' . ta_h($href) . '">Open</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }
}

ta_page_close($freshness);
cw_footer();
