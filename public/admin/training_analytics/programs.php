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
$family = trim((string)($_GET['family'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'sessions'));
$dir = strtoupper(trim((string)($_GET['dir'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

cw_header('Programs');

ta_page_open([
    'title' => 'Program health',
    'description' => 'Compare programs by identity, volume, continuity proxies, PE durability, and trajectory mix. Not a best/worst ranking.',
    'active' => 'programs',
    'filters' => $filters,
    'filter_options' => $options,
    'filter_fields' => ['program_id', 'version_id'],
    'freshness' => $freshness,
    'db_missing' => $db === null,
]);

if ($db) {
    $rows = ta_program_health_rows($db);
    if ($filters['program_id']) {
        $rows = array_values(array_filter($rows, static fn ($r) => (string)$r['program_id'] === (string)$filters['program_id']));
    }
    if ($filters['version_id']) {
        $rows = array_values(array_filter($rows, static fn ($r) => (string)$r['curriculum_version_id'] === (string)$filters['version_id']));
    }
    if ($family !== '') {
        $rows = array_values(array_filter($rows, static fn ($r) => (string)$r['family_code'] === $family));
    }

    $sortMap = [
        'sessions' => 'sessions',
        'students' => 'students',
        'below' => 'below_required_rate',
        'pe' => 'pe_stability',
        'repeat' => 'avg_extra_sessions',
        'name' => 'program_name',
    ];
    $key = $sortMap[$sort] ?? 'sessions';
    usort($rows, static function ($a, $b) use ($key, $dir) {
        $va = $a[$key] ?? null;
        $vb = $b[$key] ?? null;
        if ($va === null && $vb === null) {
            return 0;
        }
        if ($va === null) {
            return 1;
        }
        if ($vb === null) {
            return -1;
        }
        $cmp = $va <=> $vb;
        return $dir === 'ASC' ? $cmp : -$cmp;
    });

    $families = [];
    foreach ($rows as $r) {
        if ($r['family_code']) {
            $families[(string)$r['family_code']] = true;
        }
    }
    ksort($families);

    echo '<form class="card ta-filters" method="get"><div class="ta-filters__grid">';
    echo '<label class="ta-field"><span>Family</span><select class="app-select" name="family"><option value="">All</option>';
    foreach (array_keys($families) as $f) {
        echo '<option value="' . ta_h($f) . '"' . ($family === $f ? ' selected' : '') . '>' . ta_h($f) . '</option>';
    }
    echo '</select></label>';
    if ($filters['program_id']) {
        echo '<input type="hidden" name="program_id" value="' . ta_h($filters['program_id']) . '">';
    }
    echo '<label class="ta-field"><span>Sort</span><select class="app-select" name="sort">';
    foreach (['sessions' => 'Sessions', 'students' => 'Students', 'pe' => 'PE stability', 'repeat' => 'Repeat burden', 'below' => 'Below-required', 'name' => 'Name'] as $k => $lab) {
        echo '<option value="' . ta_h($k) . '"' . ($sort === $k ? ' selected' : '') . '>' . ta_h($lab) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="ta-field"><span>Direction</span><select class="app-select" name="dir">';
    echo '<option value="DESC"' . ($dir === 'DESC' ? ' selected' : '') . '>Desc</option>';
    echo '<option value="ASC"' . ($dir === 'ASC' ? ' selected' : '') . '>Asc</option></select></label>';
    echo '</div><div class="ta-filters__actions" style="margin-top:10px"><button class="btn" type="submit">Apply</button></div></form>';

    echo '<div class="card ta-card"><p class="ta-calib-note">Programs are identified by <strong>program ID + curriculum version + source tracking table</strong>. Display names can collide across generations.</p>';
    echo '<div class="ta-table-wrap"><table class="ta-table"><thead><tr>';
    echo '<th>Program</th><th>Identity</th><th>Students</th><th>Sessions</th><th>Dates</th><th>Prog. missions</th><th>Repeat burden</th><th>Below-req</th><th>PE stability</th><th>Narr. cov.</th><th>Trajectories</th><th>Sufficiency</th></tr></thead><tbody>';

    foreach ($rows as $r) {
        $href = '/admin/training_analytics/program.php?program_id=' . rawurlencode((string)$r['program_id']);
        $trajBits = [];
        foreach (($r['trajectory']['labels'] ?? []) as $lab => $c) {
            if ($lab === 'UNKNOWN') {
                continue;
            }
            $trajBits[] = $lab . '=' . $c;
        }
        $trajTxt = $trajBits ? implode(', ', array_slice($trajBits, 0, 4)) : 'INSUFFICIENT DATA';
        echo '<tr>';
        echo '<td><a href="' . ta_h($href) . '">' . ta_h((string)$r['program_name']) . '</a><div class="ta-muted">' . ta_h((string)($r['family_code'] ?? '') . ' · ' . (string)($r['version_code'] ?? '')) . '</div></td>';
        echo '<td><span class="ta-id-chip" title="source_tracking_table">id=' . ta_h((string)$r['program_id']) . '</span><div class="ta-muted">' . ta_h((string)($r['source_tracking_table'] ?? '')) . '</div></td>';
        echo '<td>' . ta_h(ta_fmt_int($r['students'])) . '</td>';
        echo '<td>' . ta_h(ta_fmt_int($r['sessions'])) . '</td>';
        echo '<td>' . ta_h((string)($r['date_min'] ?? '—')) . ' → ' . ta_h((string)($r['date_max'] ?? '—')) . '</td>';
        echo '<td>' . ta_h(ta_fmt_int($r['progression_mission_count'])) . '</td>';
        echo '<td>' . (($r['avg_extra_sessions'] !== null) ? ta_h(ta_fmt_num($r['avg_extra_sessions'], 2)) : '<span class="ta-muted">NO DATA</span>') . '</td>';
        echo '<td>' . (($r['below_required_rate'] !== null) ? ta_h(ta_fmt_pct($r['below_required_rate'])) : '—') . '</td>';
        echo '<td>' . (($r['pe_stability'] !== null) ? ta_h(ta_fmt_pct($r['pe_stability'])) . ' <span class="ta-muted">n=' . ta_h(ta_fmt_int($r['pe_n'])) . '</span>' : '<span class="ta-muted">INSUFFICIENT</span>') . '</td>';
        echo '<td>' . (($r['narrative_coverage'] !== null) ? ta_h(ta_fmt_pct($r['narrative_coverage'])) : '—') . '</td>';
        echo '<td class="ta-muted" style="max-width:220px">' . ta_h($trajTxt) . '</td>';
        echo '<td>' . ta_h((string)$r['sufficiency']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="ta-muted" style="margin-top:10px">Training-gap sensitivity is not materialized per program in analysis_training_gap_effect — shown as NO DATA here; Continuity page has population models.</p></div>';
}

ta_page_close($freshness);
cw_footer();
