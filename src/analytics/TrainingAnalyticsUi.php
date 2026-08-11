<?php
declare(strict_types=1);

require_once __DIR__ . '/TrainingAnalyticsDb.php';

/**
 * Shared shell for Training Analytics pages — mirrors Compliance/admin patterns
 * using IPCA shell tokens (no separate visual language).
 */

if (!function_exists('ta_require_admin')) {
    function ta_require_admin(): void
    {
        if (function_exists('cw_require_admin')) {
            cw_require_admin();
            return;
        }
        http_response_code(403);
        exit('Forbidden');
    }
}

if (!function_exists('ta_nav_items')) {
    /**
     * @return list<array{key:string,label:string,href:string,ready:bool}>
     */
    function ta_nav_items(): array
    {
        $base = '/admin/training_analytics';

        return [
            ['key' => 'overview', 'label' => 'Overview', 'href' => $base . '/index.php', 'ready' => true],
            ['key' => 'discoveries', 'label' => 'Research / Discoveries', 'href' => $base . '/discoveries.php', 'ready' => true],
            ['key' => 'programs', 'label' => 'Programs', 'href' => $base . '/programs.php', 'ready' => true],
            ['key' => 'curriculum', 'label' => 'Curriculum', 'href' => $base . '/curriculum.php', 'ready' => true],
            ['key' => 'progression', 'label' => 'Student Progression', 'href' => $base . '/progression.php', 'ready' => true],
            ['key' => 'competencies', 'label' => 'Competencies', 'href' => $base . '/competencies.php', 'ready' => true],
            ['key' => 'continuity', 'label' => 'Training Continuity', 'href' => $base . '/continuity.php', 'ready' => true],
            ['key' => 'bottlenecks', 'label' => 'Mission Bottlenecks', 'href' => $base . '/bottlenecks.php', 'ready' => true],
            ['key' => 'instructors', 'label' => 'Instructors', 'href' => $base . '/instructors.php', 'ready' => true],
            ['key' => 'narratives', 'label' => 'Narrative Insights', 'href' => $base . '/narratives.php', 'ready' => true],
            ['key' => 'data_quality', 'label' => 'Data Quality', 'href' => $base . '/data_quality.php', 'ready' => true],
        ];
    }
}

if (!function_exists('ta_page_open')) {
    /**
     * @param array{
     *   title:string,
     *   description?:string,
     *   active?:string,
     *   stats?:list<array{label:string,value:string,sub?:string,tone?:string,href?:string}>,
     *   filters?:array,
     *   filter_options?:array,
     *   show_filters?:bool,
     *   filter_fields?:list<string>,
     *   freshness?:array,
     *   back?:array{href:string,label:string},
     *   db_missing?:bool
     * } $opts
     */
    function ta_page_open(array $opts): void
    {
        $title = (string)($opts['title'] ?? 'Training Analytics');
        $description = (string)($opts['description'] ?? '');
        $active = (string)($opts['active'] ?? 'overview');
        $stats = isset($opts['stats']) && is_array($opts['stats']) ? $opts['stats'] : [];
        $filters = isset($opts['filters']) && is_array($opts['filters']) ? $opts['filters'] : ta_parse_filters($_GET);
        $filterOptions = isset($opts['filter_options']) && is_array($opts['filter_options']) ? $opts['filter_options'] : [];
        $showFilters = (bool)($opts['show_filters'] ?? true);
        $filterFields = isset($opts['filter_fields']) && is_array($opts['filter_fields'])
            ? $opts['filter_fields']
            : ['date_from', 'date_to', 'program_id', 'version_id', 'session_type'];
        $freshness = isset($opts['freshness']) && is_array($opts['freshness']) ? $opts['freshness'] : null;
        $back = isset($opts['back']) && is_array($opts['back']) ? $opts['back'] : null;
        $dbMissing = (bool)($opts['db_missing'] ?? false);

        echo '<link rel="stylesheet" href="/assets/training-analytics.css">';
        echo '<div class="ta-page cmp-page">';

        if ($back !== null && isset($back['href'], $back['label'])) {
            echo '<div class="ta-back"><a href="' . ta_h((string)$back['href']) . '">&larr; ' . ta_h((string)$back['label']) . '</a></div>';
        }

        echo '<section class="app-section-hero cmp-hero ta-hero">';
        echo '<div class="hero-overline">Training Analytics</div>';
        echo '<div class="cmp-hero-head">';
        echo '  <div class="cmp-hero-copy">';
        echo '    <h1 class="cmp-hero-title">' . ta_h($title) . '</h1>';
        if ($description !== '') {
            echo '    <p class="cmp-hero-text">' . ta_h($description) . '</p>';
        }
        echo '  </div>';
        echo '</div>';

        if ($stats) {
            echo '<div class="cmp-hero-stats">';
            foreach ($stats as $chip) {
                if (!is_array($chip)) {
                    continue;
                }
                $tone = (string)($chip['tone'] ?? '');
                $cls = 'cmp-stat-chip';
                if (in_array($tone, ['warn', 'crit', 'ok'], true)) {
                    $cls .= ' is-' . $tone;
                }
                $href = (string)($chip['href'] ?? '');
                $tagOpen = $href !== '' ? '<a class="' . $cls . '" href="' . ta_h($href) . '">' : '<div class="' . $cls . '">';
                $tagClose = $href !== '' ? '</a>' : '</div>';
                echo $tagOpen;
                echo '<div class="cmp-stat-label">' . ta_h((string)($chip['label'] ?? '')) . '</div>';
                echo '<div class="cmp-stat-value">' . ta_h((string)($chip['value'] ?? '')) . '</div>';
                if (!empty($chip['sub'])) {
                    echo '<div class="cmp-stat-sub">' . ta_h((string)$chip['sub']) . '</div>';
                }
                echo $tagClose;
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<nav class="ta-subnav" aria-label="Training Analytics sections">';
        foreach (ta_nav_items() as $item) {
            $isActive = $item['key'] === $active;
            $cls = 'ta-subnav__item' . ($isActive ? ' is-active' : '') . (!$item['ready'] ? ' is-later' : '');
            echo '<a class="' . $cls . '" href="' . ta_h($item['href']) . '">' . ta_h($item['label']);
            if (!$item['ready']) {
                echo ' <span class="ta-subnav__soon">soon</span>';
            }
            echo '</a>';
        }
        echo '</nav>';

        if ($dbMissing) {
            echo '<div class="card ta-card ta-alert"><strong>DATA NOT AVAILABLE</strong>';
            echo '<p>Analytics warehouse not found at <code>storage/analytics/egle_training_analytics.sqlite</code>.</p></div>';
            return;
        }

        echo '<div class="ta-notice card">';
        echo '<strong>Management / analytics only.</strong> ';
        echo 'Read-only historical insights from Phases 3–6. Does not change official grades, student records, curriculum progression, scheduling, debriefs, or production competency state.';
        echo '</div>';

        if ($showFilters) {
            ta_render_filter_bar($filters, $filterOptions, $filterFields);
        }

        if ($freshness) {
            $GLOBALS['__ta_freshness'] = $freshness;
        }
    }
}

if (!function_exists('ta_render_filter_bar')) {
    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @param list<string> $fields
     */
    function ta_render_filter_bar(array $filters, array $options, array $fields): void
    {
        $active = !empty($filters['active']);
        echo '<form class="card ta-filters" method="get" action="">';
        echo '<div class="ta-filters__head">';
        echo '<div><strong>Global filters</strong>';
        if ($active) {
            echo ' <span class="ta-pill ta-pill--active">Altering population</span>';
        } else {
            echo ' <span class="ta-pill">Full historical population</span>';
        }
        echo '</div>';
        echo '<div class="ta-filters__actions">';
        echo '<button type="submit" class="btn">Apply</button> ';
        echo '<a class="btn btn-secondary" href="' . ta_h(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '') . '">Reset Filters</a>';
        echo '</div></div>';

        echo '<div class="ta-filters__grid">';

        if (in_array('date_from', $fields, true)) {
            echo '<label class="ta-field"><span>Date from</span>';
            echo '<input class="app-input" type="date" name="date_from" value="' . ta_h((string)($filters['date_from'] ?? '')) . '"></label>';
        }
        if (in_array('date_to', $fields, true)) {
            echo '<label class="ta-field"><span>Date to</span>';
            echo '<input class="app-input" type="date" name="date_to" value="' . ta_h((string)($filters['date_to'] ?? '')) . '"></label>';
        }
        if (in_array('program_id', $fields, true)) {
            echo '<label class="ta-field"><span>Program</span><select class="app-select" name="program_id"><option value="">All programs</option>';
            foreach (($options['programs'] ?? []) as $p) {
                $sel = ((string)($filters['program_id'] ?? '') === (string)$p['id']) ? ' selected' : '';
                echo '<option value="' . ta_h($p['id']) . '"' . $sel . '>' . ta_h($p['label']) . '</option>';
            }
            echo '</select></label>';
        }
        if (in_array('version_id', $fields, true)) {
            echo '<label class="ta-field"><span>Curriculum version</span><select class="app-select" name="version_id"><option value="">All versions</option>';
            foreach (($options['versions'] ?? []) as $v) {
                $sel = ((string)($filters['version_id'] ?? '') === (string)$v['id']) ? ' selected' : '';
                $lab = $v['family'] !== '' ? $v['family'] . ' · ' . $v['label'] : $v['label'];
                echo '<option value="' . ta_h($v['id']) . '"' . $sel . '>' . ta_h($lab) . '</option>';
            }
            echo '</select></label>';
        }
        if (in_array('session_type', $fields, true)) {
            echo '<label class="ta-field"><span>Training type</span><select class="app-select" name="session_type"><option value="">All types</option>';
            foreach (($options['session_types'] ?? []) as $t) {
                $sel = ((string)($filters['session_type'] ?? '') === (string)$t['id']) ? ' selected' : '';
                echo '<option value="' . ta_h($t['id']) . '"' . $sel . '>' . ta_h($t['label']) . '</option>';
            }
            echo '</select></label>';
        }
        if (in_array('instructor_id', $fields, true)) {
            echo '<label class="ta-field"><span>Instructor</span><select class="app-select" name="instructor_id"><option value="">All instructors</option>';
            foreach (($options['instructors'] ?? []) as $i) {
                $sel = ((string)($filters['instructor_id'] ?? '') === (string)$i['id']) ? ' selected' : '';
                echo '<option value="' . ta_h($i['id']) . '"' . $sel . '>' . ta_h($i['label']) . '</option>';
            }
            echo '</select></label>';
        }
        if (in_array('mission_role', $fields, true)) {
            $roles = [
                'PROGRESSION_MISSION' => 'Progression only (default)',
                'ALL' => 'Include all roles',
                'PROFICIENCY_MISSION' => 'Proficiency',
                'ACCUMULATION_MISSION' => 'Accumulation',
                'BRIEFING_OR_GROUND_EVENT' => 'Briefing / ground',
            ];
            $cur = (string)($filters['mission_role'] ?? '');
            echo '<label class="ta-field"><span>Mission role</span><select class="app-select" name="mission_role">';
            foreach ($roles as $id => $lab) {
                $sel = ($cur === $id || ($cur === '' && $id === 'PROGRESSION_MISSION')) ? ' selected' : '';
                echo '<option value="' . ta_h($id) . '"' . $sel . '>' . ta_h($lab) . '</option>';
            }
            echo '</select></label>';
        }

        echo '</div>';
        echo '<p class="ta-filters__note">Aircraft is not shown — analysis tables do not support reliable aircraft-level filtering yet. Filters only apply where the underlying materialized analysis supports them; otherwise the page notes the limitation.</p>';
        echo '</form>';
    }
}

if (!function_exists('ta_confidence_badge')) {
    function ta_confidence_badge(?string $raw, string $title = ''): string
    {
        $label = ta_confidence_label($raw);
        $cls = ta_confidence_class($raw);
        $tip = $title !== '' ? ' title="' . ta_h($title) . '"' : '';

        return '<span class="' . $cls . '"' . $tip . '>' . ta_h($label) . '</span>';
    }
}

if (!function_exists('ta_insight_card')) {
    /**
     * @param array{
     *   eyebrow?:string,title:string,what:string,so_what:string,
     *   confidence:?string,evidence?:string,href?:string,metrics?:list<array{label:string,value:string}>
     * } $card
     */
    function ta_insight_card(array $card): void
    {
        $href = (string)($card['href'] ?? '');
        $tag = $href !== '' ? 'a' : 'div';
        $hrefAttr = $href !== '' ? ' href="' . ta_h($href) . '"' : '';
        echo '<' . $tag . ' class="card ta-insight"' . $hrefAttr . '>';
        echo '<div class="ta-insight__top">';
        if (!empty($card['eyebrow'])) {
            echo '<div class="ta-insight__eyebrow">' . ta_h((string)$card['eyebrow']) . '</div>';
        }
        echo ta_confidence_badge($card['confidence'] ?? null, (string)($card['evidence'] ?? ''));
        echo '</div>';
        echo '<h3 class="ta-insight__title">' . ta_h((string)($card['title'] ?? '')) . '</h3>';
        if (!empty($card['metrics']) && is_array($card['metrics'])) {
            echo '<div class="ta-insight__metrics">';
            foreach ($card['metrics'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                echo '<div><span>' . ta_h((string)($m['label'] ?? '')) . '</span><strong>' . ta_h((string)($m['value'] ?? '')) . '</strong></div>';
            }
            echo '</div>';
        }
        echo '<div class="ta-insight__block"><span>WHAT?</span><p>' . ta_h((string)($card['what'] ?? '')) . '</p></div>';
        echo '<div class="ta-insight__block"><span>SO WHAT?</span><p>' . ta_h((string)($card['so_what'] ?? '')) . '</p></div>';
        if (!empty($card['evidence'])) {
            echo '<div class="ta-insight__evidence">' . ta_h((string)$card['evidence']) . '</div>';
        }
        if ($href !== '') {
            echo '<div class="ta-insight__cta">Explore evidence →</div>';
        }
        echo '</' . $tag . '>';
    }
}

if (!function_exists('ta_unavailable')) {
    function ta_unavailable(string $message, string $missing = ''): void
    {
        echo '<div class="card ta-card ta-alert">';
        echo '<strong>DATA NOT AVAILABLE</strong>';
        echo '<p>' . ta_h($message) . '</p>';
        if ($missing !== '') {
            echo '<p class="ta-muted">Missing: <code>' . ta_h($missing) . '</code></p>';
        }
        echo '</div>';
    }
}

if (!function_exists('ta_insufficient')) {
    function ta_insufficient(string $message, string $reason = ''): void
    {
        echo '<div class="card ta-card ta-alert">';
        echo '<strong>INSUFFICIENT DATA</strong>';
        echo '<p>' . ta_h($message) . '</p>';
        if ($reason !== '') {
            echo '<p class="ta-muted">' . ta_h($reason) . '</p>';
        }
        echo '</div>';
    }
}

if (!function_exists('ta_research_status_banner')) {
    /** @param list<array{label:string,state:string,note:string}> $rows */
    function ta_research_status_banner(array $rows): void
    {
        echo '<div class="card ta-card ta-research">';
        echo '<h2>Research status</h2>';
        echo '<p class="ta-sub">Historical management analytics vs live competency validation remain separate concerns.</p>';
        echo '<div class="ta-research__grid">';
        foreach ($rows as $r) {
            echo '<div class="ta-research__item">';
            echo '<div class="ta-research__label">' . ta_h($r['label']) . '</div>';
            echo '<div class="ta-research__state">' . ta_h($r['state']) . '</div>';
            echo '<div class="ta-muted">' . ta_h($r['note']) . '</div>';
            echo '</div>';
        }
        echo '</div></div>';
    }
}

if (!function_exists('ta_discovery_card')) {
    /** @param array<string,mixed> $d */
    function ta_discovery_card(array $d, bool $compact = false): void
    {
        $href = (string)($d['href'] ?? '');
        echo '<article class="card ta-discovery" id="' . ta_h((string)($d['id'] ?? '')) . '">';
        echo '<div class="ta-discovery__top">';
        echo '<span class="ta-pill">' . ta_h((string)($d['category'] ?? '')) . '</span>';
        echo ta_confidence_badge((string)($d['confidence'] ?? ''));
        echo '</div>';
        echo '<h3 class="ta-discovery__title">' . ta_h((string)($d['finding'] ?? '')) . '</h3>';
        if (!$compact) {
            echo '<div class="ta-discovery__grid">';
            echo '<div><span>WHY IT MATTERS</span><p>' . ta_h((string)($d['why'] ?? '')) . '</p></div>';
            echo '<div><span>EVIDENCE</span><p>' . ta_h((string)($d['evidence'] ?? '')) . '</p></div>';
            echo '<div><span>MAGNITUDE</span><p>' . ta_h((string)($d['magnitude'] ?? '')) . '</p></div>';
            echo '<div><span>POPULATION / SAMPLE</span><p>' . ta_h((string)($d['n_label'] ?? ('n=' . ta_fmt_int($d['n'] ?? 0)))) . '</p></div>';
            echo '<div><span>POSSIBLE EXPLANATIONS</span><p>' . ta_h((string)($d['explanations'] ?? '')) . '</p></div>';
            echo '<div><span>POTENTIAL ACTION</span><p>' . ta_h((string)($d['action'] ?? '')) . '</p></div>';
            echo '</div>';
            echo '<div class="ta-discovery__meta">Source analysis: <strong>' . ta_h((string)($d['version'] ?? '')) . '</strong></div>';
        } else {
            echo '<p class="ta-muted">' . ta_h((string)($d['why'] ?? '')) . '</p>';
            echo '<p><strong>' . ta_h((string)($d['magnitude'] ?? '')) . '</strong> · ' . ta_h((string)($d['n_label'] ?? '')) . '</p>';
        }
        if ($href !== '') {
            echo '<a class="ta-insight__cta" href="' . ta_h($href) . '">Open supporting view →</a>';
        }
        echo '</article>';
    }
}

if (!function_exists('ta_page_close')) {
    function ta_page_close(?array $freshness = null): void
    {
        $freshness = $freshness ?? ($GLOBALS['__ta_freshness'] ?? null);
        if (is_array($freshness)) {
            echo '<footer class="ta-freshness">';
            echo 'Historical analytics: <strong>' . ta_h((string)($freshness['historical'] ?? 'n/a')) . '</strong>';
            if (!empty($freshness['data_through'])) {
                echo ' · Data through: <strong>' . ta_h((string)$freshness['data_through']) . '</strong>';
            }
            if (!empty($freshness['narrative_model'])) {
                echo ' · Narrative model: <strong>' . ta_h((string)$freshness['narrative_model']) . '</strong>';
            }
            if (!empty($freshness['phase4'])) {
                echo ' · Phase 4: <strong>' . ta_h((string)$freshness['phase4']) . '</strong>';
            }
            echo '</footer>';
        }
        echo '</div>'; // .ta-page
    }
}

if (!function_exists('ta_coming_soon_page')) {
    function ta_coming_soon_page(string $title, string $active, string $blurb): void
    {
        $db = ta_analytics_pdo();
        $freshness = $db ? ta_analysis_freshness($db) : null;
        ta_page_open([
            'title' => $title,
            'description' => $blurb,
            'active' => $active,
            'show_filters' => false,
            'freshness' => $freshness,
            'db_missing' => $db === null,
        ]);
        if ($db) {
            echo '<div class="card ta-card">';
            echo '<h2>Next milestone</h2>';
            echo '<p>This section is scaffolded in navigation. The first milestone delivers Overview, Training Continuity, Competency Explorer, Curriculum Comparison, and Mission Bottlenecks.</p>';
            echo '<p class="ta-muted">Underlying analysis tables already exist for later wiring — no placeholder insights will be invented here.</p>';
            echo '</div>';
        }
        ta_page_close($freshness);
    }
}
