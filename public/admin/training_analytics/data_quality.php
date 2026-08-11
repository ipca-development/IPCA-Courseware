<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$freshness = $db ? ta_analysis_freshness($db) : null;
$issue = trim((string)($_GET['issue'] ?? ''));

cw_header('Data Quality');

ta_page_open([
    'title' => 'Data quality & lineage',
    'description' => 'What the historical analysis actually knows — coverage, exclusions, identity risks, and limitations. Builds trust for management use.',
    'active' => 'data_quality',
    'show_filters' => false,
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    $dq = ta_data_quality_snapshot($db);
    $s = $dq['scope'];

    ta_research_status_banner(ta_research_status($db));

    echo '<div class="card ta-card"><h2>Analytical lineage</h2>';
    echo '<div class="ta-lineage">';
    echo '<span>Combell E-gle</span><em>READ ONLY</em><span>Canonical extraction</span><em>→</em>';
    echo '<span>Validation / QA</span><em>→</em><span>analysis_* tables</span><em>→</em><span>Training Analytics UI</span>';
    echo '</div>';
    echo '<p style="margin-top:12px"><strong>The management dashboard does not write back to E-gle.</strong> ';
    echo 'It does not call OpenAI on page load, and it does not change official grades, debriefs, scheduling, or production competency state. ';
    echo 'Phase 10C live competency validation is a separate concern.</p></div>';

    echo '<div class="card ta-card"><h2>Source coverage</h2>';
    echo '<div class="ta-insight__metrics">';
    foreach ([
        ['Date range', $s['year_min'] . '–' . $s['year_max']],
        ['Sessions', ta_fmt_int($s['sessions'])],
        ['Students (sessions)', ta_fmt_int($s['students'])],
        ['Students (dim)', ta_fmt_int($s['students_dim'])],
        ['Exercise attempts', ta_fmt_int($s['attempts'])],
        ['Narratives', ta_fmt_int($s['narratives'])],
        ['Programs', ta_fmt_int($s['programs'])],
        ['Data through', (string)($dq['freshness']['data_through'] ?? '—')],
    ] as [$l, $v]) {
        echo '<div><span>' . ta_h($l) . '</span><strong>' . ta_h($v) . '</strong></div>';
    }
    echo '</div></div>';

    echo '<div class="card ta-card"><h2>Session QA classes</h2>';
    echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>qa_class</th><th>Sessions</th><th>Share</th></tr></thead><tbody>';
    $total = max(1, (int)$s['sessions']);
    foreach ($dq['qa_class'] as $k => $c) {
        echo '<tr><td>' . ta_h($k) . '</td><td>' . ta_h(ta_fmt_int($c)) . '</td><td>' . ta_h(ta_fmt_pct($c / $total)) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="card ta-card"><h2>Known defects & exclusions</h2>';
    echo '<div class="ta-insight__metrics">';
    echo '<div><span>Invalid dates</span><strong>' . ta_h(ta_fmt_int($dq['invalid_dates'])) . '</strong></div>';
    echo '<div><span>Missing student link</span><strong>' . ta_h(ta_fmt_int($dq['missing_student'])) . '</strong></div>';
    echo '<div><span>Missing mission link</span><strong>' . ta_h(ta_fmt_int($dq['missing_mission'])) . '</strong></div>';
    foreach ($dq['ex_blob'] as $k => $c) {
        echo '<div><span>ex_blob ' . ta_h($k) . '</span><strong>' . ta_h(ta_fmt_int($c)) . '</strong></div>';
    }
    echo '</div>';
    if ($dq['exclusions']) {
        echo '<h3 style="font-size:14px;margin:12px 0 6px">Exclusion log</h3>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Reason</th><th>QA class</th><th>Count</th></tr></thead><tbody>';
        foreach ($dq['exclusions'] as $e) {
            echo '<tr><td>' . ta_h((string)$e['reason_code']) . '</td><td>' . ta_h((string)$e['qa_class']) . '</td><td>' . ta_h(ta_fmt_int($e['c'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if ($dq['issues']) {
        echo '<h3 style="font-size:14px;margin:12px 0 6px">QA data issues</h3>';
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Issue</th><th>Severity</th><th>Count</th></tr></thead><tbody>';
        foreach ($dq['issues'] as $i) {
            echo '<tr><td>' . ta_h((string)$i['issue_code']) . '</td><td>' . ta_h((string)$i['severity']) . '</td><td>' . ta_h(ta_fmt_int($i['c'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="card ta-card"><h2>Curriculum & mission-role mapping</h2>';
    echo '<p>Mission-role classified: <strong>' . ta_h(ta_fmt_int($dq['mission_role_classified'])) . '</strong> / '
        . ta_h(ta_fmt_int($dq['missions_total'])) . ' missions.</p>';
    $roles = ta_query_all($db, 'SELECT mission_role, COUNT(*) AS c FROM analysis_mission_role GROUP BY 1 ORDER BY c DESC');
    echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Role</th><th>Missions</th></tr></thead><tbody>';
    foreach ($roles as $r) {
        echo '<tr><td>' . ta_h((string)$r['mission_role']) . '</td><td>' . ta_h(ta_fmt_int($r['c'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="ta-muted">Curriculum identity = program_id + version_code + source_tracking_table. Display names can collide.</p></div>';

    echo '<div class="card ta-card"><h2>Duplicate identity candidates</h2>';
    if (!$dq['instructor_dups']) {
        echo '<p>No duplicate instructor name groups detected in dim_instructor.</p>';
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Name</th><th>Instructor IDs</th><th>Impact</th></tr></thead><tbody>';
        foreach ($dq['instructor_dups'] as $name => $ids) {
            echo '<tr><td>' . ta_h($name) . '</td><td>' . ta_h(implode(', ', $ids)) . '</td>';
            echo '<td>Calibration may split/merge wrongly until resolved</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="card ta-card"><h2>Narrative coverage & extractors</h2>';
    echo '<p>Sessions with narrative present: <strong>' . ta_h(ta_fmt_int($dq['narrative_sessions'])) . '</strong>.</p>';
    echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Extractor</th><th>Extractions</th></tr></thead><tbody>';
    foreach ($dq['extractors'] as $e) {
        echo '<tr><td>' . ta_h((string)$e['extractor']) . '</td><td>' . ta_h(ta_fmt_int($e['c'])) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="card ta-card"><h2>Analysis version lineage</h2>';
    $f = $dq['freshness'];
    echo '<ul>';
    echo '<li>Phase 4 structured: <strong>' . ta_h((string)$f['phase4']) . '</strong></li>';
    echo '<li>Phase 5B narrative validation: <strong>' . ta_h((string)$f['phase5b']) . '</strong></li>';
    echo '<li>Phase 6 scale: <strong>' . ta_h((string)$f['phase6']) . '</strong></li>';
    echo '<li>UI historical label: <strong>' . ta_h((string)$f['historical']) . '</strong></li>';
    echo '<li>Generated: <strong>' . ta_h((string)$f['generated_at']) . '</strong></li>';
    echo '</ul></div>';

    echo '<div class="card ta-warn-banner"><h2 style="margin:0 0 8px;font-size:16px">Major limitations (not buried)</h2>';
    echo '<ul style="margin:0;padding-left:18px">';
    echo '<li>Historical missing/ambiguous student & instructor IDs</li>';
    echo '<li>Curriculum generations can share display names — use canonical IDs</li>';
    echo '<li>Small program populations → INSUFFICIENT DATA, not precise zeros</li>';
    echo '<li>Narrative extractor sources differ (heuristic vs LLM-validated) — never equate them</li>';
    echo '<li>Associations are non-causal unless a model explicitly supports intervention claims</li>';
    echo '<li>Historical independence is NOT_RELIABLY_EXTRACTABLE from narrative silence</li>';
    echo '<li>Per-program training-gap logits are not materialized (population models with program dummies only)</li>';
    echo '</ul></div>';

    // Drill: invalid dates / missing links by program
    echo '<div class="card ta-card"><h2>Quality drill-down by program</h2>';
    echo '<p class="ta-sub">Aggregate defect counts — no personal details.</p>';
    $byProg = ta_query_all(
        $db,
        "SELECT program_id,
                SUM(CASE WHEN session_date_valid=0 THEN 1 ELSE 0 END) AS invalid_dates,
                SUM(CASE WHEN student_id IS NULL OR CAST(student_id AS TEXT)='' THEN 1 ELSE 0 END) AS missing_student,
                SUM(CASE WHEN mission_id IS NULL THEN 1 ELSE 0 END) AS missing_mission,
                SUM(CASE WHEN ex_blob_parse_status='EMPTY' THEN 1 ELSE 0 END) AS empty_ex,
                COUNT(*) AS sessions
         FROM fact_training_session
         GROUP BY program_id
         HAVING invalid_dates + missing_student + missing_mission + empty_ex > 0
         ORDER BY (invalid_dates + missing_student + missing_mission + empty_ex) DESC"
    );
    if (!$byProg) {
        echo '<p>No per-program defect aggregates above zero.</p>';
    } else {
        echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr><th>Program ID</th><th>Sessions</th><th>Invalid dates</th><th>Missing student</th><th>Missing mission</th><th>Empty ex blob</th><th>Impact</th></tr></thead><tbody>';
        foreach ($byProg as $r) {
            $impact = [];
            if ((int)$r['invalid_dates']) {
                $impact[] = 'date continuity';
            }
            if ((int)$r['missing_student']) {
                $impact[] = 'trajectory linkage';
            }
            if ((int)$r['missing_mission']) {
                $impact[] = 'bottleneck attribution';
            }
            if ((int)$r['empty_ex']) {
                $impact[] = 'exercise analytics';
            }
            echo '<tr><td><span class="ta-id-chip">' . ta_h((string)$r['program_id']) . '</span></td>';
            echo '<td>' . ta_h(ta_fmt_int($r['sessions'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['invalid_dates'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['missing_student'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['missing_mission'])) . '</td>';
            echo '<td>' . ta_h(ta_fmt_int($r['empty_ex'])) . '</td>';
            echo '<td class="ta-muted">' . ta_h(implode(', ', $impact)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

ta_page_close($freshness);
cw_footer();
