<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsQueries.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsDiscoveries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$filters = ta_parse_filters($_GET);
$options = $db ? ta_filter_options($db) : [];
$freshness = $db ? ta_analysis_freshness($db) : null;
$scope = $db ? ta_dataset_scope($db) : null;
$insights = $db ? ta_overview_insights($db) : [];

cw_header('Training Analytics');

$stats = [];
if ($scope) {
    $stats = [
        ['label' => 'Training history', 'value' => $scope['year_min'] . '–' . $scope['year_max']],
        ['label' => 'Students with sessions', 'value' => ta_fmt_int($scope['students']), 'sub' => ta_fmt_int($scope['students_dim']) . ' in student dim'],
        ['label' => 'Training sessions', 'value' => ta_fmt_int($scope['sessions'])],
        ['label' => 'Exercise attempts', 'value' => ta_fmt_int($scope['attempts'])],
        ['label' => 'Narratives', 'value' => ta_fmt_int($scope['narratives'])],
        ['label' => 'Programs', 'value' => ta_fmt_int($scope['programs'])],
    ];
}

ta_page_open([
    'title' => 'Executive overview',
    'description' => 'Explore what approximately twelve years of training history imply for continuity, curriculum, competency durability, and narrative signal. Decision-support only — not operational competency state.',
    'active' => 'overview',
    'stats' => $stats,
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['date_from', 'date_to', 'program_id', 'version_id', 'session_type'],
    'show_filters' => true,
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    ta_research_status_banner(ta_research_status($db));

    if ($filters['active']) {
        echo '<div class="card ta-card"><p class="ta-muted">Global filters are active. Overview insight cards use precomputed analysis_* tables (full historical population). Open Continuity, Competencies, Curriculum, Bottlenecks, Programs, or Narratives for filter-sensitive views where supported.</p></div>';
    }

    echo '<div class="ta-grid">';
    if (!$insights) {
        ta_unavailable('No high-value insight cards could be built from analysis tables.', 'analysis_training_gap_effect / analysis_competency_stability / …');
    } else {
        foreach ($insights as $card) {
            ta_insight_card($card);
        }
    }
    echo '</div>';

    $latest = ta_filter_discoveries(ta_discoveries_catalog($db), null, 'HIGH', null);
    if (count($latest) < 3) {
        $latest = array_slice(ta_discoveries_catalog($db), 0, 5);
    } else {
        $latest = array_slice($latest, 0, 5);
    }
    echo '<div class="card ta-card" style="margin-top:14px"><h2>Latest discoveries</h2>';
    echo '<p class="ta-sub">High-confidence / top-ranked findings for management meetings. <a href="/admin/training_analytics/discoveries.php">Full discoveries notebook →</a></p>';
    echo '<div class="ta-grid">';
    foreach ($latest as $d) {
        ta_discovery_card($d, true);
    }
    echo '</div></div>';

    echo '<div class="card ta-card" style="margin-top:14px">';
    echo '<h2>Explore</h2>';
    echo '<p class="ta-sub">Start from an insight, then drill into evidence.</p>';
    echo '<div class="ta-grid">';
    $links = [
        ['Research / Discoveries', '/admin/training_analytics/discoveries.php', 'Ranked findings: continuity, PE, curriculum, narrative, early warning'],
        ['Programs', '/admin/training_analytics/programs.php', 'Program health by canonical identity — not best/worst ranks'],
        ['Instructors', '/admin/training_analytics/instructors.php', 'Calibration indicators with sample safeguards'],
        ['Narrative Insights', '/admin/training_analytics/narratives.php', 'What grades miss — extractor sources labeled'],
        ['Student Progression', '/admin/training_analytics/progression.php', 'Trajectory distributions and anonymized group profiles'],
        ['Data Quality', '/admin/training_analytics/data_quality.php', 'Coverage, exclusions, lineage, limitations'],
        ['Training Continuity', '/admin/training_analytics/continuity.php', 'Gap dose-response vs incomplete / repeat / regression'],
        ['Competency Explorer', '/admin/training_analytics/competencies.php', 'PE stability, learning curves, softening ranks'],
        ['Curriculum Comparison', '/admin/training_analytics/curriculum.php', 'PPL→PPLA, MEP→MEPNEW, IR→IRNEW, CPL→CPLAUPRT'],
        ['Mission Bottlenecks', '/admin/training_analytics/bottlenecks.php', 'Progression-only repeat burden'],
    ];
    foreach ($links as [$t, $h, $d]) {
        echo '<a class="card ta-insight" href="' . ta_h($h) . '"><h3 class="ta-insight__title">' . ta_h($t) . '</h3>';
        echo '<p class="ta-muted">' . ta_h($d) . '</p><div class="ta-insight__cta">Open →</div></a>';
    }
    echo '</div></div>';
}

ta_page_close($freshness);
cw_footer();
