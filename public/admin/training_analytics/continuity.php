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

cw_header('Training Continuity');

ta_page_open([
    'title' => 'Training continuity',
    'description' => 'How outcomes change as days since previous training increase. Raw descriptive buckets plus program-controlled models where available.',
    'active' => 'continuity',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['session_type', 'program_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    if (!ta_table_exists($db, 'analysis_training_gap_effect')) {
        ta_unavailable('Training-gap analysis table is missing.', 'analysis_training_gap_effect');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $sessionType = $filters['session_type'];
    $buckets = ta_gap_buckets($db);
    $models = ta_gap_models($db, $sessionType);

    // When session type selected, prefer correlational strata for that type
    $typeModels = [];
    if ($sessionType) {
        $typeModels = ta_query_all(
            $db,
            "SELECT * FROM analysis_training_gap_effect WHERE stratum = ? ORDER BY outcome",
            ['type=' . $sessionType]
        );
        echo '<div class="card ta-card"><p><span class="ta-pill ta-pill--active">Population altered</span> Showing training-type stratum <strong>' . ta_h($sessionType) . '</strong> from analysis_training_gap_effect. Descriptive all-session buckets remain below for context.</p></div>';
    }

    if ($filters['program_id']) {
        echo '<div class="card ta-card"><p class="ta-muted">Program filter is recorded, but gap models are precomputed with program dummies (not separate program-only curves). Full per-program gap curves are not materialized — <strong>DATA NOT AVAILABLE</strong> for program-specific dose-response plots.</p></div>';
    }

    echo '<div class="card ta-card">';
    echo '<h2>Incomplete &amp; repeat probability by gap bucket</h2>';
    echo '<p class="ta-sub">Denominator: sessions with a prior session in the historical warehouse. Source: analysis_training_gap_effect · descriptive_bucket · stratum=all_sessions.</p>';

    if (!$buckets) {
        ta_unavailable('No descriptive gap buckets found.', 'analysis_training_gap_effect descriptive_bucket');
    } else {
        $maxInc = 0.01;
        foreach ($buckets as $b) {
            $maxInc = max($maxInc, (float)($b['incomplete'] ?? 0), (float)($b['repeat'] ?? 0));
        }
        echo '<div class="ta-bars">';
        foreach ($buckets as $b) {
            $label = str_replace('gap=', '', (string)$b['bucket']) . ' days';
            $inc = (float)($b['incomplete'] ?? 0);
            $w = round(100 * $inc / $maxInc);
            echo '<div class="ta-bar-row"><div>' . ta_h($label) . '</div>';
            echo '<div class="ta-bar-track" title="Incomplete ' . ta_h(ta_fmt_pct($inc)) . ' · n=' . ta_h(ta_fmt_int($b['n'])) . '">';
            echo '<div class="ta-bar-fill" style="width:' . $w . '%"></div></div>';
            echo '<strong>' . ta_h(ta_fmt_pct($inc)) . '</strong></div>';
        }
        echo '</div>';
        echo '<div class="ta-chart-legend"><span><i style="background:#3d5a80"></i> Incomplete rate</span></div>';

        echo '<div class="ta-table-wrap" style="margin-top:16px"><table class="ta-table"><thead><tr>';
        echo '<th>Gap bucket</th><th>Incomplete</th><th>Repeat</th><th>n</th></tr></thead><tbody>';
        foreach ($buckets as $b) {
            echo '<tr><td>' . ta_h(str_replace('gap=', '', (string)$b['bucket'])) . ' days</td>';
            echo '<td>' . ta_h(ta_fmt_pct($b['incomplete'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($b['repeat'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($b['n'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="card ta-card">';
    echo '<h2>Adjusted / modelled effect</h2>';
    echo '<p class="ta-sub">Logit on log1p(days_since_previous) with program dummy controls where available. Odds ratios describe association, not a causal policy threshold.</p>';

    $logitRows = ta_query_all(
        $db,
        "SELECT * FROM analysis_training_gap_effect WHERE model_name='logit_log_gap' ORDER BY outcome, stratum"
    );
    if (!$logitRows) {
        ta_unavailable('No logit gap models found.', 'analysis_training_gap_effect logit_log_gap');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
        echo '<th>Outcome</th><th>Stratum</th><th>OR</th><th>95% CI</th><th>p</th><th>n</th><th>Notes</th></tr></thead><tbody>';
        foreach ($logitRows as $r) {
            echo '<tr>';
            echo '<td>' . ta_h((string)$r['outcome']) . '</td>';
            echo '<td>' . ta_h((string)$r['stratum']) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($r['odds_ratio'] ?? null, 3)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($r['ci_low'] ?? null, 3) . ' – ' . ta_fmt_num($r['ci_high'] ?? null, 3)) . '</td>';
            echo '<td>' . ta_h(isset($r['p_value']) ? sprintf('%.2e', (float)$r['p_value']) : '—') . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['n'] ?? 0)) . '</td>';
            echo '<td>' . ta_h((string)($r['notes'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    if ($typeModels) {
        echo '<h2 style="margin-top:18px">Training-type correlations</h2>';
        echo '<p class="ta-sub">Pearson descriptive correlations for selected type stratum.</p>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Outcome</th><th>Coefficient</th><th>n</th><th>Notes</th></tr></thead><tbody>';
        foreach ($typeModels as $r) {
            echo '<tr><td>' . ta_h((string)$r['outcome']) . '</td><td>' . ta_h(ta_fmt_num($r['coefficient'] ?? null, 4)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['n'] ?? 0)) . '</td><td>' . ta_h((string)($r['notes'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Threshold interpretation — only if model supports; we do NOT invent a day cutoff.
    // Descriptive dose-response shows monotonic rise; logit is continuous log1p — no discrete threshold in table.
    echo '<div class="card ta-card">';
    echo '<h2>Operational interpretation</h2>';
    $hasThreshold = ta_query_one(
        $db,
        "SELECT 1 AS x FROM analysis_training_gap_effect
         WHERE lower(COALESCE(notes,'')) LIKE '%threshold%' OR lower(COALESCE(predictor,'')) LIKE '%threshold%'
         LIMIT 1"
    );
    if ($hasThreshold) {
        echo '<p>A statistical threshold predictor is present in the analysis table — review model notes for the supported cut-point.</p>';
    } else {
        echo '<p><strong>No discrete day threshold is published in the current models.</strong> ';
        echo 'The logit uses continuous <code>log1p(days_since_previous)</code>. Descriptive buckets show a graded dose response ';
        echo '(incomplete ≈ ' . ta_h($buckets ? ta_fmt_pct($buckets[0]['incomplete'] ?? null) : '—') . ' at 0–2 days vs ≈ ';
        $last = $buckets ? end($buckets) : null;
        echo ta_h($last ? ta_fmt_pct($last['incomplete'] ?? null) : '—') . ' at 21+ days), ';
        echo 'but inventing a single “after X days” policy cut-off is not supported by the stored analysis.</p>';
        echo '<p class="ta-muted">Phase 6 early-warning pattern LONG_TRAINING_GAP uses a ≥14-day operational definition for alerting — that is a pattern definition, not a validated inflection-point estimate.</p>';
    }
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
