<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$freshness = $db ? ta_analysis_freshness($db) : null;
$exerciseId = trim((string)($_GET['exercise_id'] ?? ''));
$programId = trim((string)($_GET['program_id'] ?? ''));

cw_header('Competency detail');

ta_page_open([
    'title' => 'Competency drill-down',
    'description' => 'Learning curve, stability, co-difficulties, prerequisites, and objective-recorder potential — connecting historical analytics to future Cockpit Recorder capability.',
    'active' => 'competencies',
    'show_filters' => false,
    'freshness' => $freshness,
    'back' => ['href' => '/admin/training_analytics/competencies.php', 'label' => 'Competency explorer'],
    'db_missing' => $db === null,
]);

if ($db) {
    if ($exerciseId === '') {
        ta_unavailable('Select a competency from the explorer.', 'exercise_id query parameter');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $stab = ta_query_one(
        $db,
        'SELECT s.*, p.program_name FROM analysis_competency_stability s
         LEFT JOIN dim_program p ON p.program_id = s.program_id
         WHERE CAST(s.exercise_id AS TEXT) = ?' . ($programId !== '' ? ' AND CAST(s.program_id AS TEXT) = ?' : '') . '
         ORDER BY s.n_reobserved DESC LIMIT 1',
        $programId !== '' ? [$exerciseId, $programId] : [$exerciseId]
    );

    if (!$stab) {
        ta_unavailable('No stability row for this exercise.', 'analysis_competency_stability');
        ta_page_close($freshness);
        cw_footer();
        exit;
    }

    $programId = (string)($stab['program_id'] ?? $programId);

    echo '<div class="card ta-card">';
    echo '<h2>' . ta_h((string)$stab['exercise_name']) . '</h2>';
    echo '<p class="ta-sub">Program: ' . ta_h((string)($stab['program_name'] ?? $programId))
        . ' · Required: ' . ta_h((string)$stab['required_level'])
        . ' · Source exercise: ' . ta_h((string)($stab['source_exercise_id'] ?? '')) . '</p>';
    echo '<div class="ta-insight__metrics">';
    foreach ([
        ['PE reached', ta_fmt_int($stab['n_reached_pe'])],
        ['Reobserved', ta_fmt_int($stab['n_reobserved'])],
        ['PE stability', ta_fmt_pct($stab['stable_pe_rate'])],
        ['One-time softening', ta_fmt_pct($stab['one_time_regression_rate'])],
        ['Repeated regression', ta_fmt_pct($stab['repeated_regression_rate'])],
    ] as [$l, $v]) {
        echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
    }
    echo '</div></div>';

    // Learning curve
    echo '<div class="card ta-card">';
    echo '<h2>Learning curve</h2>';
    echo '<p class="ta-sub">Attempt 1 → 2 → 3… met rate among students at each attempt number. analysis_exercise_learning_curve</p>';
    $curve = ta_query_all(
        $db,
        "SELECT attempt_number, n_students, n_exposures, met_rate, required_level
         FROM analysis_exercise_learning_curve
         WHERE CAST(exercise_id AS TEXT) = ? AND CAST(program_id AS TEXT) = ?
         ORDER BY attempt_number ASC LIMIT 20",
        [$exerciseId, $programId]
    );
    if (!$curve) {
        // try source id
        $curve = ta_query_all(
            $db,
            "SELECT attempt_number, n_students, n_exposures, met_rate, required_level
             FROM analysis_exercise_learning_curve
             WHERE CAST(source_exercise_id AS TEXT) = ? AND CAST(program_id AS TEXT) = ?
             ORDER BY attempt_number ASC LIMIT 20",
            [(string)($stab['source_exercise_id'] ?? ''), $programId]
        );
    }
    if (!$curve) {
        ta_unavailable('No learning curve rows for this exercise/program.', 'analysis_exercise_learning_curve');
    } else {
        $max = 0.01;
        foreach ($curve as $c) {
            $max = max($max, (float)$c['met_rate']);
        }
        echo '<div class="ta-bars">';
        foreach ($curve as $c) {
            $w = round(100 * (float)$c['met_rate'] / $max);
            echo '<div class="ta-bar-row"><div>Attempt ' . (int)$c['attempt_number'] . '</div>';
            echo '<div class="ta-bar-track" title="n_students=' . (int)$c['n_students'] . '"><div class="ta-bar-fill" style="width:' . $w . '%"></div></div>';
            echo '<strong>' . ta_h(ta_fmt_pct($c['met_rate'])) . '</strong></div>';
        }
        echo '</div>';
        echo '<div class="ta-table-wrap" style="margin-top:12px"><table class="ta-table"><thead><tr><th>Attempt</th><th>Students</th><th>Exposures</th><th>Met rate</th><th>Req</th></tr></thead><tbody>';
        foreach ($curve as $c) {
            echo '<tr><td>' . (int)$c['attempt_number'] . '</td><td>' . ta_h(ta_fmt_int($c['n_students'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($c['n_exposures'])) . '</td><td>' . ta_h(ta_fmt_pct($c['met_rate'])) . '</td>';
            echo '<td>' . ta_h((string)$c['required_level']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Transitions — program-level matrix (exercise-level not in table)
    echo '<div class="card ta-card">';
    echo '<h2>Competency transitions (program-level)</h2>';
    echo '<p class="ta-sub">DE → EX → PR → PE transition rates for this program from analysis_competency_transition. Exercise-specific transition matrices are not materialized — showing program context, not a misleading Sankey.</p>';
    $trans = ta_query_all(
        $db,
        "SELECT from_stage, to_stage, n_transitions, rate, median_exposures_between
         FROM analysis_competency_transition
         WHERE CAST(program_id AS TEXT) = ?
         ORDER BY from_stage, to_stage",
        [$programId]
    );
    if (!$trans) {
        ta_unavailable('No transition rows for this program.', 'analysis_competency_transition');
    } else {
        $stages = ['DE', 'EX', 'PR', 'PE'];
        $map = [];
        foreach ($trans as $t) {
            $map[(string)$t['from_stage'] . '|' . (string)$t['to_stage']] = $t;
        }
        echo '<div class="ta-matrix">';
        echo '<div class="ta-matrix__cell ta-matrix__head"></div>';
        foreach ($stages as $to) {
            echo '<div class="ta-matrix__cell ta-matrix__head">→ ' . ta_h($to) . '</div>';
        }
        foreach ($stages as $from) {
            echo '<div class="ta-matrix__cell ta-matrix__label">' . ta_h($from) . '</div>';
            foreach ($stages as $to) {
                $cell = $map[$from . '|' . $to] ?? null;
                if (!$cell) {
                    echo '<div class="ta-matrix__cell">—</div>';
                } else {
                    $title = 'n=' . (int)$cell['n_transitions'] . ' · median exposures between=' . ta_fmt_num($cell['median_exposures_between'] ?? null, 1);
                    echo '<div class="ta-matrix__cell" title="' . ta_h($title) . '">' . ta_h(ta_fmt_pct($cell['rate'])) . '</div>';
                }
            }
        }
        echo '</div>';
        echo '<p class="ta-muted" style="margin-top:10px">Progression on diagonal-adjacent cells; regression appears as PE→PR/EX where present. Denominators are transition counts in the analysis table — hover cells for n.</p>';
    }
    echo '</div>';

    // Stability / regression already in header — gap effect note
    echo '<div class="card ta-card">';
    echo '<h2>Training gap effect</h2>';
    echo '<p class="ta-sub">Exercise-specific gap models are not in analysis_training_gap_effect (session/exercise-not-met strata only).</p>';
    $gapEx = ta_query_one($db, "SELECT * FROM analysis_training_gap_effect WHERE outcome='not_met' AND model_name='logit_log_gap' LIMIT 1");
    if ($gapEx) {
        echo '<p>Population exercise not-met association with log gap: OR=' . ta_h(ta_fmt_num($gapEx['odds_ratio'] ?? null, 3))
            . ' (n=' . ta_h(ta_fmt_int($gapEx['n'] ?? 0)) . '). ' . ta_confidence_badge('MODERATE') . '</p>';
    } else {
        ta_unavailable('No exercise-level gap model.', 'analysis_training_gap_effect outcome=not_met');
    }
    echo '</div>';

    // Co-difficulties
    echo '<div class="card ta-card">';
    echo '<h2>Co-difficulties</h2>';
    echo '<p class="ta-sub">Pairs that co-occur as difficult within the same program. analysis_codifficulty</p>';
    $src = (string)($stab['source_exercise_id'] ?? $exerciseId);
    $codiff = ta_query_all(
        $db,
        "SELECT * FROM analysis_codifficulty
         WHERE CAST(program_id AS TEXT) = ?
           AND (CAST(exercise_a_id AS TEXT) = ? OR CAST(exercise_b_id AS TEXT) = ?
                OR CAST(exercise_a_id AS TEXT) = ? OR CAST(exercise_b_id AS TEXT) = ?)
         ORDER BY lift DESC LIMIT 15",
        [$programId, $exerciseId, $exerciseId, $src, $src]
    );
    if (!$codiff) {
        ta_unavailable('No co-difficulty pairs for this exercise.', 'analysis_codifficulty');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Partner</th><th>n co-difficult</th><th>Support</th><th>Lift</th><th>Confidence</th></tr></thead><tbody>';
        foreach ($codiff as $c) {
            $partner = ((string)$c['exercise_a_id'] === $exerciseId || (string)$c['exercise_a_id'] === $src)
                ? (string)$c['exercise_b_name'] : (string)$c['exercise_a_name'];
            echo '<tr><td>' . ta_h($partner) . '</td><td>' . ta_h(ta_fmt_int($c['n_co_difficult'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($c['support'] ?? null, 3)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($c['lift'] ?? null, 2)) . '</td>';
            echo '<td>' . ta_confidence_badge((string)($c['evidence_confidence'] ?? 'LOW')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Prerequisites
    echo '<div class="card ta-card">';
    echo '<h2>Prerequisite candidates</h2>';
    echo '<p class="ta-sub">Exploratory associations — not curriculum prescriptions. analysis_prerequisite_candidate</p>';
    $prereq = ta_query_all(
        $db,
        "SELECT * FROM analysis_prerequisite_candidate
         WHERE CAST(program_id AS TEXT) = ?
           AND (CAST(exercise_a_id AS TEXT) IN (?,?) OR CAST(exercise_b_id AS TEXT) IN (?,?))
         ORDER BY lift DESC LIMIT 12",
        [$programId, $exerciseId, $src, $exerciseId, $src]
    );
    if (!$prereq) {
        // show program-level top if none linked
        $prereq = ta_query_all(
            $db,
            "SELECT * FROM analysis_prerequisite_candidate WHERE CAST(program_id AS TEXT) = ? ORDER BY lift DESC LIMIT 8",
            [$programId]
        );
        if ($prereq) {
            echo '<p class="ta-muted">No direct link for this exercise; showing program-level candidates.</p>';
        }
    }
    if (!$prereq) {
        ta_unavailable('No prerequisite candidates.', 'analysis_prerequisite_candidate');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>A</th><th>B</th><th>n</th><th>Lift</th><th>Effect</th><th>p</th></tr></thead><tbody>';
        foreach ($prereq as $p) {
            echo '<tr><td>' . ta_h((string)$p['exercise_a_name']) . '</td><td>' . ta_h((string)$p['exercise_b_name']) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($p['n_students'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($p['lift'] ?? null, 2)) . '</td>';
            echo '<td>' . ta_h(ta_fmt_num($p['effect_size'] ?? null, 3)) . '</td>';
            echo '<td>' . ta_h(isset($p['p_value']) ? sprintf('%.3g', (float)$p['p_value']) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Objective recorder
    echo '<div class="card ta-card">';
    echo '<h2>Objective recorder potential</h2>';
    echo '<p class="ta-sub">Bridge to future Cockpit Recorder. analysis_objective_measurement_candidate</p>';
    $obj = ta_query_all(
        $db,
        "SELECT candidate, reason FROM analysis_objective_measurement_candidate
         WHERE CAST(exercise_id AS TEXT) = ? OR CAST(source_exercise_id AS TEXT) = ?
         LIMIT 20",
        [$exerciseId, $src]
    );
    if (!$obj) {
        ta_unavailable('No recorder-candidate row for this exercise.', 'analysis_objective_measurement_candidate');
    } else {
        foreach ($obj as $o) {
            $cand = strtoupper(trim((string)$o['candidate']));
            $label = match (true) {
                str_contains($cand, 'YES') || $cand === 'HIGH' => 'YES',
                str_contains($cand, 'PARTIAL') || str_contains($cand, 'MEDIUM') => 'PARTIAL',
                default => 'NO',
            };
            echo '<p><span class="ta-verdict ' . ($label === 'YES' ? 'ta-verdict--improve' : ($label === 'PARTIAL' ? 'ta-verdict--mixed' : 'ta-verdict--none')) . '">'
                . ta_h($label) . '</span> ';
            echo ta_h((string)$o['candidate']) . ' — ' . ta_h((string)$o['reason']) . '</p>';
        }
    }
    echo '</div>';

    echo '<div class="card ta-card">';
    echo '<h2>Instructor variation</h2>';
    echo '<p class="ta-sub">Exercise-linked instructor calibration is not in a dedicated analysis table for this drill-down.</p>';
    ta_unavailable('Instructor×exercise variation view scheduled with Instructor Analytics milestone.', 'analysis_instructor_calibration (session-level only today)');
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
