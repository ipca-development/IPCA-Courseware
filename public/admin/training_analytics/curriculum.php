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
$familyFilter = trim((string)($_GET['family'] ?? ''));

cw_header('Curriculum');

ta_page_open([
    'title' => 'Curriculum comparison',
    'description' => 'Compare curriculum generations using verified family mappings. Hours alone do not define quality — PE stability, repeats, and continuity matter.',
    'active' => 'curriculum',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id', 'version_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    if (!ta_table_exists($db, 'analysis_curriculum_comparison')) {
        ta_unavailable('Curriculum comparison table missing.', 'analysis_curriculum_comparison');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $pairs = ta_curriculum_pairs($db);
    if ($familyFilter !== '') {
        $pairs = array_values(array_filter($pairs, static fn ($p) => (string)$p['family_code'] === $familyFilter));
    }

    $families = [];
    foreach (ta_query_all($db, 'SELECT DISTINCT family_code FROM analysis_curriculum_comparison ORDER BY 1') as $f) {
        $families[] = (string)$f['family_code'];
    }

    echo '<div class="card ta-card">';
    echo '<form method="get" class="ta-search">';
    echo '<label class="ta-field"><span>Family</span><select class="app-select" name="family"><option value="">All families</option>';
    foreach ($families as $f) {
        $sel = $familyFilter === $f ? ' selected' : '';
        echo '<option value="' . ta_h($f) . '"' . $sel . '>' . ta_h($f) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="btn" type="submit">Filter</button>';
    echo '</form>';
    echo '<p class="ta-sub">Verified pairs include PPL→PPLA, MEP→MEPNEW, IR→IRNEW SE/ME, CPLA→CPLAUPRT from dim_curriculum_family / analysis_curriculum_comparison.</p>';
    echo '</div>';

    if ($filters['active']) {
        echo '<div class="card ta-card"><p class="ta-muted">Curriculum metrics are precomputed per version pair. Global program/version GET filters do not recompute deltas — use the family selector above. Population for each metric is n_a / n_b on each row.</p></div>';
    }

    if (!$pairs) {
        ta_unavailable('No curriculum comparison pairs found.', 'analysis_curriculum_comparison');
    }

    $metricLabels = [
        'sessions_per_student' => 'Sessions / student',
        'flight_hours_per_student' => 'Flight hours / student',
        'sim_hours_per_student' => 'Sim hours / student',
        'calendar_days_per_student' => 'Calendar days / student',
        'progression_repeat_sessions_per_student' => 'Progression repeat burden',
        'below_required_rate' => 'Below-required rate',
        'pe_stability_rate' => 'PE stability',
        'median_gap_days' => 'Median gap (days)',
    ];

    foreach ($pairs as $pair) {
        $title = $pair['family_code'] . ': ' . $pair['version_a'] . ' → ' . $pair['version_b'];
        $rollup = $pair['rollup'];
        echo '<div class="card ta-card">';
        echo '<div class="ta-insight__top"><h2 style="margin:0">' . ta_h($title) . '</h2>';
        echo '<span class="ta-verdict ' . ta_h($rollup['class']) . '">' . ta_h($rollup['label']) . '</span></div>';
        echo '<p class="ta-sub">' . ta_h($rollup['note']) . '</p>';

        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
        echo '<th>Metric</th><th>' . ta_h((string)$pair['version_a']) . '</th><th>' . ta_h((string)$pair['version_b']) . '</th>';
        echo '<th>Δ</th><th>n_a</th><th>n_b</th><th>Verdict</th><th>Confidence</th></tr></thead><tbody>';

        foreach ($metricLabels as $key => $lab) {
            if (!isset($pair['metrics'][$key])) {
                continue;
            }
            $m = $pair['metrics'][$key];
            $isRate = str_contains($key, 'rate') || str_contains($key, 'stability');
            $fmt = static function ($v) use ($isRate) {
                if ($v === null || $v === '') {
                    return '—';
                }
                return $isRate ? ta_fmt_pct($v) : ta_fmt_num($v, 2);
            };
            echo '<tr>';
            echo '<td>' . ta_h($lab) . '</td>';
            echo '<td>' . ta_h($fmt($m['value_a'] ?? null)) . '</td>';
            echo '<td>' . ta_h($fmt($m['value_b'] ?? null)) . '</td>';
            echo '<td>' . ta_h($fmt($m['delta'] ?? null)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($m['n_a'] ?? 0)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($m['n_b'] ?? 0)) . '</td>';
            echo '<td><span class="ta-verdict ' . ta_verdict_class((string)($m['verdict'] ?? '')) . '">' . ta_h((string)($m['verdict'] ?? '')) . '</span></td>';
            echo '<td>' . ta_confidence_badge((string)($m['confidence'] ?? 'LOW')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
    }
}

ta_page_close($freshness);
cw_footer();
