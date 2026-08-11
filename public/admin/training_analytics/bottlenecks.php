<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$filters = ta_parse_filters($_GET);
if (($filters['mission_role'] ?? null) === null || $filters['mission_role'] === '') {
    $filters['mission_role'] = 'PROGRESSION_MISSION';
}
$options = $db ? ta_filter_options($db) : [];
$freshness = $db ? ta_analysis_freshness($db) : null;
$sort = trim((string)($_GET['sort'] ?? 'metric'));
$dir = strtoupper(trim((string)($_GET['dir'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

cw_header('Mission Bottlenecks');

ta_page_open([
    'title' => 'Mission bottlenecks',
    'description' => 'Progression missions where students accumulate extra sessions. Intentional accumulation, briefing, and proficiency roles are excluded unless you explicitly include them.',
    'active' => 'bottlenecks',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id', 'mission_role'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    if (!ta_table_exists($db, 'analysis_program_bottleneck')) {
        ta_unavailable('Bottleneck table missing.', 'analysis_program_bottleneck');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $role = (string)($filters['mission_role'] ?? 'PROGRESSION_MISSION');
    if ($role === 'ALL' || in_array($role, ['ACCUMULATION_MISSION', 'PROFICIENCY_MISSION', 'BRIEFING_OR_GROUND_EVENT'], true)) {
        echo '<div class="card ta-card ta-alert"><p><strong>Mission-role note.</strong> ';
        echo '<code>analysis_program_bottleneck</code> materializes progression-mission and exercise bottlenecks only. ';
        if ($role !== 'ALL' && $role !== 'PROGRESSION_MISSION') {
            echo 'Alternate rankings for role=' . ta_h($role) . ' are <strong>DATA NOT AVAILABLE</strong> in the current table.';
        } else {
            echo 'Showing progression bottlenecks (the validated default).';
            $role = 'PROGRESSION_MISSION';
        }
        echo '</p></div>';
    }

    $rows = ($role === 'PROGRESSION_MISSION' || $role === 'ALL')
        ? ta_bottlenecks($db, $filters['program_id'], 'PROGRESSION_MISSION', $sort, $dir)
        : [];

    if ($filters['program_id']) {
        echo '<div class="card ta-card"><p><span class="ta-pill ta-pill--active">Population altered</span> Program filter applied to bottleneck rows.</p></div>';
    }

    echo '<div class="card ta-card">';
    echo '<h2>Progression mission repeat burden</h2>';
    echo '<p class="ta-sub">Metric: extra_sessions_per_student · item_type=PROGRESSION_MISSION · analysis_program_bottleneck. Sortable. Click a mission for drill-down.</p>';

    $baseQs = [];
    if ($filters['program_id']) {
        $baseQs['program_id'] = $filters['program_id'];
    }
    $baseQs['mission_role'] = $filters['mission_role'] ?? 'PROGRESSION_MISSION';
    $sortLink = static function (string $col) use ($baseQs, $sort, $dir): string {
        $qs = $baseQs;
        $qs['sort'] = $col;
        $qs['dir'] = ($sort === $col && $dir === 'DESC') ? 'ASC' : 'DESC';
        return '/admin/training_analytics/bottlenecks.php?' . http_build_query($qs);
    };

    if (!$rows) {
        ta_unavailable('No bottleneck rows for this selection.', 'analysis_program_bottleneck');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
        echo '<th><a href="' . ta_h($sortLink('label')) . '">Mission</a></th>';
        echo '<th><a href="' . ta_h($sortLink('program')) . '">Program</a></th>';
        echo '<th>Role</th>';
        echo '<th><a href="' . ta_h($sortLink('n')) . '">Exposure (n)</a></th>';
        echo '<th>Students</th>';
        echo '<th>Repeat rate</th>';
        echo '<th>Avg attempts</th>';
        echo '<th><a href="' . ta_h($sortLink('metric')) . '">Extra sessions</a></th>';
        echo '<th><a href="' . ta_h($sortLink('confidence')) . '">Confidence</a></th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $href = '/admin/training_analytics/mission.php?item_id=' . rawurlencode((string)$r['item_id'])
                . '&program_id=' . rawurlencode((string)$r['program_id']);
            echo '<tr>';
            echo '<td><a href="' . ta_h($href) . '">' . ta_h((string)$r['item_label']) . '</a></td>';
            echo '<td>' . ta_h((string)$r['program_name']) . '</td>';
            echo '<td>' . ta_h((string)($r['mission_role'] ?? 'PROGRESSION_MISSION')) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['n'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['students'] ?? $r['n'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($r['repeat_rate'] ?? null)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($r['avg_attempts'] ?? null, 2)) . '</td>';
            echo '<td><strong>' . ta_h(ta_fmt_num($r['metric_value'], 2)) . '</strong></td>';
            echo '<td>' . ta_confidence_badge((string)($r['confidence'] ?? 'MODERATE')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Also show top difficult exercises if present
    $ex = ta_query_all(
        $db,
        "SELECT * FROM analysis_program_bottleneck
         WHERE item_type='EXERCISE' AND metric_name='not_met_rate'"
         . ($filters['program_id'] ? ' AND CAST(program_id AS TEXT) = ?' : '')
         . ' ORDER BY metric_value DESC LIMIT 15',
        $filters['program_id'] ? [$filters['program_id']] : []
    );
    if ($ex) {
        echo '<div class="card ta-card">';
        echo '<h2>Related: high not-met exercises</h2>';
        echo '<p class="ta-sub">From the same bottleneck table (exercise item_type). Useful companions when investigating mission time loss.</p>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Exercise</th><th>Program</th><th>Not-met rate</th><th>n</th><th>Confidence</th></tr></thead><tbody>';
        foreach ($ex as $r) {
            echo '<tr><td>' . ta_h((string)$r['item_label']) . '</td><td>' . ta_h((string)$r['program_name']) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($r['metric_value'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['n'])) . '</td>';
            echo '<td>' . ta_confidence_badge((string)($r['confidence'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}

ta_page_close($freshness);
cw_footer();
