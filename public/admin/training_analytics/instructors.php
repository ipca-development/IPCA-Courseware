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
$minSessions = max(10, (int)($_GET['min_sessions'] ?? 50));
$signal = trim((string)($_GET['signal'] ?? ''));
$suff = trim((string)($_GET['sufficiency'] ?? ''));

cw_header('Instructors');

ta_page_open([
    'title' => 'Instructor calibration',
    'description' => 'Management-only calibration indicators. Populations, assignments, and student difficulty differ — these are not performance rankings.',
    'active' => 'instructors',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    echo '<div class="card ta-warn-banner"><strong>Calibration analytics — not a leaderboard.</strong> ';
    echo 'Do not interpret PE rate, repeats, or downstream stability as “best/worst instructor.” ';
    echo 'Instructor populations, curriculum assignments, phases, and student difficulty differ.</div>';

    if (!ta_table_exists($db, 'analysis_instructor_calibration')) {
        ta_unavailable('Instructor calibration table missing.', 'analysis_instructor_calibration');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $rows = ta_instructor_calibration_rows($db, $minSessions);
    $baseline = $rows[0]['baseline'] ?? null;

    // Optional program filter via fact join (instructor taught in program)
    if ($filters['program_id']) {
        $ids = [];
        foreach (ta_query_all(
            $db,
            'SELECT DISTINCT instructor_id FROM fact_training_session WHERE CAST(program_id AS TEXT)=? AND instructor_id IS NOT NULL',
            [$filters['program_id']]
        ) as $r) {
            $ids[(string)$r['instructor_id']] = true;
        }
        $rows = array_values(array_filter($rows, static fn ($r) => isset($ids[(string)$r['instructor_id']])));
        echo '<div class="card ta-card"><p><span class="ta-pill ta-pill--active">Population altered</span> Showing instructors with ≥1 session in program_id=' . ta_h($filters['program_id']) . '.</p></div>';
    }

    if ($signal !== '') {
        $rows = array_values(array_filter($rows, static fn ($r) => (string)$r['pattern_signal'] === $signal || (string)$r['signal_label'] === $signal));
    }
    if ($suff !== '') {
        $rows = array_values(array_filter($rows, static fn ($r) => (string)$r['sample_sufficiency'] === $suff));
    }
    $rows = array_values(array_filter($rows, static fn ($r) => (int)$r['n_sessions'] >= $minSessions));

    echo '<form class="card ta-filters" method="get"><div class="ta-filters__grid">';
    if ($filters['program_id']) {
        echo '<input type="hidden" name="program_id" value="' . ta_h($filters['program_id']) . '">';
    }
    echo '<label class="ta-field"><span>Min sessions</span><input class="app-input" type="number" name="min_sessions" value="' . (int)$minSessions . '"></label>';
    echo '<label class="ta-field"><span>Sample sufficiency</span><select class="app-select" name="sufficiency"><option value="">All</option>';
    foreach (['SAMPLE_OK', 'SAMPLE_LIMITED'] as $s) {
        echo '<option value="' . ta_h($s) . '"' . ($suff === $s ? ' selected' : '') . '>' . ta_h($s) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="ta-field"><span>Pattern signal</span><select class="app-select" name="signal"><option value="">All</option>';
    foreach (['FAST_ADVANCEMENT', 'STRICT_SIGNAL', 'POSSIBLE_PREMATURE_ADVANCEMENT', 'POSSIBLE_OVERTRAINING', 'UNCLEAR'] as $s) {
        echo '<option value="' . ta_h($s) . '"' . ($signal === $s ? ' selected' : '') . '>' . ta_h($s) . '</option>';
    }
    echo '</select></label></div>';
    echo '<div class="ta-filters__actions" style="margin-top:10px"><button class="btn" type="submit">Apply</button></div></form>';

    if ($baseline) {
        echo '<div class="card ta-card"><h2>Population calibration reference</h2>';
        echo '<p class="ta-sub">Median among SAMPLE_OK instructors with ≥' . (int)$minSessions . ' sessions (n_instructors=' . ta_h(ta_fmt_int($baseline['n_instructors'])) . '). Not a program-adjusted model table — Phase 4 stores person-level calibration with pattern notes.</p>';
        echo '<div class="ta-insight__metrics">';
        foreach ([
            ['PE assignment rate', ta_fmt_pct($baseline['pe_rate'])],
            ['Required-level met', ta_fmt_pct($baseline['required_met_rate'])],
            ['Progression repeat', ta_fmt_pct($baseline['progression_repeat_rate'])],
            ['Downstream problem', ta_fmt_pct($baseline['downstream_problem_rate'])],
        ] as [$l, $v]) {
            echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
        }
        echo '</div></div>';
    }

    $dups = ta_instructor_duplicate_names($db);
    if ($dups) {
        echo '<div class="card ta-warn-banner"><strong>Duplicate identity candidates:</strong> ';
        $bits = [];
        foreach ($dups as $name => $ids) {
            $bits[] = $name . ' → ids ' . implode(', ', $ids);
        }
        echo ta_h(implode(' · ', $bits)) . '. Do not silently merge.</div>';
    }

    echo '<div class="card ta-card"><h2>Calibration table</h2>';
    if (!$rows) {
        ta_insufficient('No instructors meet the current sample filters.', 'Raise/lower min sessions or clear filters');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
        echo '<th>Instructor</th><th>Sessions</th><th>Marks</th><th>PE rate</th><th>vs ref</th><th>Req met</th><th>Repeat burden</th><th>Downstream</th><th>Signal</th><th>Sample</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $href = '/admin/training_analytics/instructor.php?instructor_id=' . rawurlencode((string)$r['instructor_id']);
            $peRef = $baseline['pe_rate'] ?? null;
            $delta = ($peRef !== null && $r['pe_rate'] !== null) ? ((float)$r['pe_rate'] - (float)$peRef) : null;
            echo '<tr>';
            echo '<td><a href="' . ta_h($href) . '">' . ta_h((string)$r['instructor_name']) . '</a>';
            if (!empty($r['duplicate_identity'])) {
                echo ' <span class="ta-pill ta-pill--active">dup identity</span>';
            }
            echo '<div class="ta-muted"><span class="ta-id-chip">id=' . ta_h((string)$r['instructor_id']) . '</span></div></td>';
            echo '<td>' . ta_h(ta_fmt_int($r['n_sessions'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['n_exercise_marks'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($r['pe_rate'])) . '</td>';
            echo '<td>' . ($delta !== null ? ta_h(($delta >= 0 ? '+' : '') . ta_fmt_pct($delta)) : '—') . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($r['required_met_rate'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($r['progression_repeat_rate'])) . '</td>';
            echo '<td>' . ($r['downstream_problem_rate'] !== null ? ta_h(ta_fmt_pct($r['downstream_problem_rate'])) : '—') . '</td>';
            echo '<td title="' . ta_h((string)($r['pattern_notes'] ?? '')) . '">' . ta_h((string)$r['signal_label']) . '</td>';
            echo '<td>' . ta_h((string)$r['sample_sufficiency']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p class="ta-calib-note" style="margin-top:12px">Analytical calibration signal — not an instructor performance judgment. Grade distribution detail is on the drill-down.</p></div>';
}

ta_page_close($freshness);
cw_footer();
