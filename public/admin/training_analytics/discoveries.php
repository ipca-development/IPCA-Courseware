<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsUi.php';
require_once __DIR__ . '/../../../src/analytics/TrainingAnalyticsDiscoveries.php';

ta_require_admin();

$db = ta_analytics_pdo();
$filters = ta_parse_filters($_GET);
$options = $db ? ta_filter_options($db) : [];
$freshness = $db ? ta_analysis_freshness($db) : null;
$category = trim((string)($_GET['category'] ?? ''));
$confidence = trim((string)($_GET['confidence'] ?? ''));

cw_header('Research / Discoveries');

ta_page_open([
    'title' => 'Research / discoveries',
    'description' => 'What twelve years of training data revealed that management should know — ranked findings with evidence, not a metric dump.',
    'active' => 'discoveries',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    ta_research_status_banner(ta_research_status($db));

    $all = ta_discoveries_catalog($db);
    $shown = ta_filter_discoveries($all, $category !== '' ? $category : null, $confidence !== '' ? $confidence : null, $filters['program_id']);

    echo '<form class="card ta-filters" method="get"><div class="ta-filters__head"><div><strong>Discovery filters</strong>';
    echo ' <span class="ta-pill">' . count($shown) . ' of ' . count($all) . ' shown</span></div>';
    echo '<div class="ta-filters__actions"><button class="btn" type="submit">Apply</button> ';
    echo '<a class="btn btn-secondary" href="/admin/training_analytics/discoveries.php">Reset</a></div></div>';
    echo '<div class="ta-filters__grid">';
    $cats = ['CURRICULUM', 'CONTINUITY', 'COMPETENCY', 'INSTRUCTOR', 'NARRATIVE', 'DATA_QUALITY', 'EARLY_WARNING', 'UNEXPECTED'];
    echo '<label class="ta-field"><span>Category</span><select class="app-select" name="category"><option value="">All</option>';
    foreach ($cats as $c) {
        echo '<option value="' . ta_h($c) . '"' . ($category === $c ? ' selected' : '') . '>' . ta_h($c) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="ta-field"><span>Confidence</span><select class="app-select" name="confidence"><option value="">All</option>';
    foreach (['HIGH', 'MODERATE', 'EXPLORATORY'] as $c) {
        echo '<option value="' . ta_h($c) . '"' . ($confidence === $c ? ' selected' : '') . '>' . ta_h($c) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="ta-field"><span>Program (keeps global findings)</span><select class="app-select" name="program_id"><option value="">All / global</option>';
    foreach ($options['programs'] as $p) {
        $sel = ((string)($filters['program_id'] ?? '') === (string)$p['id']) ? ' selected' : '';
        echo '<option value="' . ta_h($p['id']) . '"' . $sel . '>' . ta_h($p['label']) . ' · id=' . ta_h($p['id']) . '</option>';
    }
    echo '</select></label></div>';
    echo '<p class="ta-filters__note">Program filter hides only program-specific discoveries that do not match. Global findings always remain.</p></form>';

    if (!$shown) {
        ta_unavailable('No discoveries match these filters.', 'ta_discoveries_catalog');
    } else {
        echo '<div class="ta-grid" style="grid-template-columns:1fr">';
        foreach ($shown as $d) {
            ta_discovery_card($d, false);
        }
        echo '</div>';
    }
}

ta_page_close($freshness);
cw_footer();
