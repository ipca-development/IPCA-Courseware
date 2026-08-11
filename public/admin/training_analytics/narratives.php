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
$extractor = trim((string)($_GET['extractor'] ?? 'LLM-v1'));
$era = trim((string)($_GET['era'] ?? ''));

cw_header('Narrative Insights');

ta_page_open([
    'title' => 'Narrative insights',
    'description' => 'What written instructor debriefs contain that structured grades do not. Extraction sources are never mixed without labels.',
    'active' => 'narratives',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    $totalNarr = (int)ta_query_value($db, 'SELECT COUNT(*) FROM fact_narrative', [], 0);
    $sample405 = (int)ta_query_value($db, 'SELECT COUNT(*) FROM analysis_narrative_sample', [], 0);
    $extractors = ta_query_all($db, 'SELECT extractor, COUNT(*) AS c FROM analysis_phase6_narrative_extraction GROUP BY extractor ORDER BY c DESC');
    $llmStatus = ta_query_one($db, 'SELECT * FROM phase10_llm_status LIMIT 1');
    $p5b = ta_query_one($db, 'SELECT * FROM analysis_phase5b_meta LIMIT 1');

    echo '<div class="card ta-card"><h2>Narrative population</h2>';
    echo '<div class="ta-insight__metrics">';
    echo '<div><span>Total narratives</span><strong>' . ta_h(ta_fmt_int($totalNarr)) . '</strong></div>';
    echo '<div><span>Phase 5/5B validation sample</span><strong>' . ta_h(ta_fmt_int($sample405 ?: 405)) . '</strong></div>';
    foreach ($extractors as $e) {
        $lab = (string)$e['extractor'];
        $kind = str_contains(strtolower($lab), 'heuristic') ? 'HEURISTIC' : (str_contains(strtolower($lab), 'llm') || str_contains(strtolower($lab), 'agent') ? 'LLM-VALIDATED/REUSED' : 'OTHER');
        echo '<div><span>' . ta_h($kind) . '</span><strong>' . ta_h(ta_fmt_int($e['c'])) . '</strong><div class="ta-muted">' . ta_h($lab) . '</div></div>';
    }
    echo '</div>';
    if ($llmStatus) {
        echo '<p class="ta-muted">Targeted enrichment status: <strong>' . ta_h((string)$llmStatus['status']) . '</strong> · done='
            . ta_h(ta_fmt_int($llmStatus['hashes_done'])) . ' remaining=' . ta_h(ta_fmt_int($llmStatus['hashes_remaining']))
            . ' · ' . ta_h((string)($llmStatus['notes'] ?? '')) . '</p>';
    }
    if ($p5b) {
        echo '<p class="ta-muted">Narrative model: ' . ta_h((string)$p5b['analysis_version']) . ' · LLM=' . ta_h((string)$p5b['llm_extractor'])
            . ' · heuristic=' . ta_h((string)$p5b['heuristic_extractor']) . '</p>';
    }
    echo '<p class="ta-calib-note">Heuristic evidence is <strong>not</strong> LLM-validated evidence. Rates below always state their source.</p></div>';

    // Key findings
    echo '<div class="card ta-card"><h2>Key findings</h2><p class="ta-sub">Prefer Phase 5B LLM-validated reference where available.</p><div class="ta-grid">';

    $findings = [
        ['phase5b_llm_mismatch_hidden_signal', 'Grade ↔ narrative mismatch', '405 validation sample · LLM-v1'],
        ['phase5b_llm_encouraging_def', 'Encouraging/mixed tone + deficiency', '405 validation sample · LLM-v1'],
        ['phase5b_llm_consistency_present', 'Consistency signal present', '405 validation sample · LLM-v1'],
    ];
    foreach ($findings as [$name, $title, $src]) {
        $row = ta_query_one($db, 'SELECT * FROM analysis_phase6_scale_findings WHERE finding_name=? LIMIT 1', [$name]);
        if ($row) {
            ta_insight_card([
                'eyebrow' => $src,
                'title' => $title,
                'metrics' => [['label' => 'Rate', 'value' => ta_fmt_pct($row['metric_value'])], ['label' => 'n', 'value' => ta_fmt_int($row['n'])]],
                'what' => $title . ' = ' . ta_fmt_pct($row['metric_value']) . ' on ' . (string)$row['population'],
                'so_what' => 'Structured grades alone understate written performance evidence.',
                'confidence' => 'HIGH',
                'evidence' => 'analysis_phase6_scale_findings · ' . $name . ' · ' . (string)$row['analysis_version'],
            ]);
        }
    }

    $assist = ta_query_one($db, "SELECT * FROM analysis_phase5_extractor_summary WHERE metric_name='assistance_present' LIMIT 1");
    if ($assist) {
        ta_insight_card([
            'eyebrow' => '405 validation sample',
            'title' => 'Assistance / independence documentation is scarce',
            'metrics' => [
                ['label' => 'LLM assistance present', 'value' => ta_fmt_pct($assist['llm_rate'])],
                ['label' => 'Heuristic', 'value' => ta_fmt_pct($assist['heuristic_rate'])],
            ],
            'what' => 'Assistance language appears sparsely even on the validated sample.',
            'so_what' => 'Historical independence is NOT_RELIABLY_EXTRACTABLE from silence.',
            'confidence' => 'HIGH',
            'evidence' => 'analysis_phase5_extractor_summary · assistance_present',
            'href' => '#independence',
        ]);
    }

    foreach (['HIGH_GRADE_NARRATIVE_DEFICIENCY', 'REPEATED_DEFICIENCY_WINDOW', 'CONSISTENCY_CONCERN'] as $code) {
        $ew = ta_query_one($db, 'SELECT * FROM analysis_phase6_early_warning_pattern WHERE pattern_code=? LIMIT 1', [$code]);
        if ($ew) {
            ta_insight_card([
                'eyebrow' => 'Early warning · phase6-v1 (enrichment mix — see extractor notes)',
                'title' => (string)$ew['description'],
                'metrics' => [
                    ['label' => 'Later-problem', 'value' => ta_fmt_pct($ew['later_problem_rate'])],
                    ['label' => 'Baseline', 'value' => ta_fmt_pct($ew['baseline_rate'])],
                ],
                'what' => (string)$ew['explainable_template'],
                'so_what' => 'Use as triage signal, not a student label.',
                'confidence' => 'MODERATE',
                'evidence' => 'analysis_phase6_early_warning_pattern · ' . $code,
            ]);
        }
    }
    echo '</div></div>';

    // Independence
    echo '<div class="card ta-card" id="independence"><h2>Independence historical limitation</h2>';
    echo '<p><strong>Absence of assistance language ≠ independent performance.</strong></p>';
    echo '<p>Historical independence is <span class="ta-verdict ta-verdict--insuff">NOT_RELIABLY_EXTRACTABLE</span>. ';
    echo 'Missing assistance language should be treated as <strong>NOT_OBSERVED</strong> / UNKNOWN — not success.</p>';
    echo '<p class="ta-muted">This is an important reason the future Cockpit Recorder system captures independence explicitly (analysis_phase5_final_architecture.observed_independence).</p></div>';

    // Tone vs performance
    echo '<div class="card ta-card"><h2>Tone vs performance evidence</h2>';
    echo '<p class="ta-sub">Narrative tone and performance evidence are separate variables. Encouraging language is not framed as bad instructional practice — supportive language can coexist with significant deficiencies.</p>';
    $tone = ta_query_all(
        $db,
        "SELECT overall_narrative_tone,
                COUNT(*) AS c,
                AVG(CASE WHEN json_extract(summary_flags_json, '$.encouraging_tone_with_deficiency') = 1 THEN 1.0 ELSE 0.0 END) AS enc_def_share
         FROM analysis_phase6_narrative_extraction
         WHERE extractor LIKE '%heuristic%'
         GROUP BY 1 ORDER BY c DESC"
    );
    if ($tone) {
        echo '<p class="ta-muted">Source: heuristic-scaled Phase 6 extractions (not LLM-validated rates).</p>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Tone</th><th>n</th><th>Encouraging+deficiency flag share</th></tr></thead><tbody>';
        foreach ($tone as $t) {
            echo '<tr><td>' . ta_h((string)$t['overall_narrative_tone']) . '</td><td>' . ta_h(ta_fmt_int($t['c'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($t['enc_def_share'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        ta_unavailable('Tone breakdown unavailable.', 'analysis_phase6_narrative_extraction');
    }
    echo '</div>';

    // Agreement view
    echo '<div class="card ta-card"><h2>Narrative ↔ grade agreement</h2>';
    echo '<form method="get" class="ta-search">';
    if ($filters['program_id']) {
        echo '<input type="hidden" name="program_id" value="' . ta_h($filters['program_id']) . '">';
    }
    echo '<label class="ta-field"><span>Extractor source (Phase 5B mismatch table)</span><select class="app-select" name="extractor">';
    foreach (['LLM-v1' => 'LLM-validated (405)', 'heuristic-v1' => 'Heuristic (405)'] as $k => $lab) {
        echo '<option value="' . ta_h($k) . '"' . ($extractor === $k ? ' selected' : '') . '>' . ta_h($lab) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="ta-field"><span>Era (session year)</span><input class="app-input" name="era" placeholder="e.g. 2022" value="' . ta_h($era) . '"></label>';
    echo '<button class="btn" type="submit">Apply</button></form>';

    $agree = ta_query_all(
        $db,
        'SELECT agreement_category, n, rate FROM analysis_phase5_mismatch_llm WHERE extractor=? ORDER BY n DESC',
        [$extractor]
    );
    echo '<p class="ta-sub">Source: analysis_phase5_mismatch_llm · extractor=<strong>' . ta_h($extractor) . '</strong> · Phase 5B n=405 reference.</p>';
    if (!$agree) {
        ta_unavailable('No mismatch rows for this extractor.', 'analysis_phase5_mismatch_llm');
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Agreement class</th><th>n</th><th>Rate</th></tr></thead><tbody>';
        foreach ($agree as $a) {
            echo '<tr><td>' . ta_h((string)$a['agreement_category']) . '</td><td>' . ta_h(ta_fmt_int($a['n'])) . '</td><td>' . ta_h(ta_fmt_pct($a['rate'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // Optional filter on narrative_grade_agreement (405 sample enriched)
    $where = ['1=1'];
    $args = [];
    if ($filters['program_id']) {
        $where[] = 'CAST(program_id AS TEXT)=?';
        $args[] = $filters['program_id'];
    }
    if ($era !== '' && preg_match('/^\d{4}$/', $era)) {
        $where[] = 'CAST(session_year AS TEXT)=?';
        $args[] = $era;
    }
    $sampleAgree = ta_query_all(
        $db,
        'SELECT agreement_category, COUNT(*) AS c FROM analysis_narrative_grade_agreement WHERE ' . implode(' AND ', $where) . ' GROUP BY 1 ORDER BY c DESC',
        $args
    );
    if ($sampleAgree) {
        echo '<h3 style="font-size:14px;margin:16px 0 6px">Sample agreement rows (analysis_narrative_grade_agreement)</h3>';
        echo '<p class="ta-sub">Filterable by program/era where columns exist. This table is the structured sample labeling set — confirm extractor separately above.</p>';
        if ($filters['program_id'] || $era !== '') {
            echo '<p><span class="ta-pill ta-pill--active">Population altered</span></p>';
        }
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Category</th><th>n</th></tr></thead><tbody>';
        foreach ($sampleAgree as $a) {
            echo '<tr><td>' . ta_h((string)$a['agreement_category']) . '</td><td>' . ta_h(ta_fmt_int($a['c'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="card ta-card" id="early-warning"><h2>Early-warning patterns</h2>';
    echo '<p class="ta-sub">From analysis_phase6_early_warning_pattern. Downstream rates use enriched narrative windows — interpret with extractor-mix caveats.</p>';
    $ews = ta_query_all($db, 'SELECT * FROM analysis_phase6_early_warning_pattern ORDER BY later_problem_rate DESC');
    if ($ews) {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Pattern</th><th>Later-problem</th><th>Baseline</th><th>Episodes</th><th>Students</th></tr></thead><tbody>';
        foreach ($ews as $e) {
            echo '<tr><td><strong>' . ta_h((string)$e['pattern_code']) . '</strong><div class="ta-muted">' . ta_h((string)$e['description']) . '</div></td>';
            echo '<td>' . ta_h(ta_fmt_pct($e['later_problem_rate'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_pct($e['baseline_rate'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($e['n_episodes'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($e['n_students'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
