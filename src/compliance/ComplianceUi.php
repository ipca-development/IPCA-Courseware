<?php
declare(strict_types=1);

/**
 * Compliance Operating System — UI rendering helpers.
 *
 * Every compliance page must:
 *   1. cw_header(...)
 *   2. compliance_page_open([
 *          'overline'    => 'Compliance',
 *          'title'       => 'Findings',
 *          'description' => 'Create and manage NCRs; open a row for 5-Whys RCA.',
 *          'actions'     => [...],   // optional pill buttons in the hero
 *          'stats'       => [...],   // optional KPI chips embedded in the hero
 *          'back'        => [...],   // optional back-link strip
 *          'flash'       => $flash,  // optional flash array
 *      ]);
 *      ...page content...
 *   3. compliance_page_close();
 *   4. cw_footer();
 *
 * Together with the `.cmp-page` CSS in app-shell.css, this delivers a single
 * premium look across every compliance page (blue hero banner, consistent
 * cards, identical buttons / inputs / dropdowns / pills, no exceptions).
 */

if (!function_exists('compliance_page_open')) {

    /**
     * @param array{
     *   overline?:string,
     *   title:string,
     *   description?:string,
     *   actions?:array<int,array{label:string,href?:string,icon?:string,variant?:string}>,
     *   stats?:array<int,array{label:string,value:int|string,sub?:string,tone?:string,href?:string}>,
     *   back?:array{href:string,label:string,code?:string},
     *   flash?:array{type:string,message:string}|null,
     * } $opts
     */
    function compliance_page_open(array $opts): void
    {
        $overline = isset($opts['overline']) ? trim((string)$opts['overline']) : 'Compliance';
        $title = isset($opts['title']) ? (string)$opts['title'] : '';
        $description = isset($opts['description']) ? (string)$opts['description'] : '';
        $actions = isset($opts['actions']) && is_array($opts['actions']) ? $opts['actions'] : array();
        $stats = isset($opts['stats']) && is_array($opts['stats']) ? $opts['stats'] : array();
        $back = isset($opts['back']) && is_array($opts['back']) ? $opts['back'] : null;
        $flash = isset($opts['flash']) && is_array($opts['flash']) ? $opts['flash'] : null;

        echo '<div class="cmp-page">';

        if ($back !== null && isset($back['href'], $back['label'])) {
            compliance_back_link($back);
        }

        echo '<section class="app-section-hero cmp-hero">';

        if ($overline !== '') {
            echo '<div class="hero-overline">' . htmlspecialchars($overline, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        echo '<div class="cmp-hero-head">';
        echo '  <div class="cmp-hero-copy">';
        echo '    <h1 class="cmp-hero-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        if ($description !== '') {
            echo '    <p class="cmp-hero-text">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '  </div>';

        if ($actions) {
            echo '  <div class="cmp-hero-actions">';
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $label = (string)($action['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $href = (string)($action['href'] ?? '');
                $icon = (string)($action['icon'] ?? '');
                $svg = $icon !== '' ? compliance_ui_icon($icon) : '';

                if ($href !== '') {
                    echo '<a class="cmp-hero-action" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                    echo $svg;
                    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</a>';
                } else {
                    echo '<button type="button" class="cmp-hero-action">';
                    echo $svg;
                    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</button>';
                }
            }
            echo '  </div>';
        }

        echo '</div>'; // .cmp-hero-head

        if ($stats) {
            echo '<div class="cmp-hero-stats">';
            foreach ($stats as $chip) {
                if (!is_array($chip)) {
                    continue;
                }
                $cl = (string)($chip['label'] ?? '');
                $cv = (string)($chip['value'] ?? '');
                $cs = (string)($chip['sub'] ?? '');
                $tone = (string)($chip['tone'] ?? '');
                $href = (string)($chip['href'] ?? '');

                $classList = 'cmp-stat-chip';
                if (in_array($tone, array('warn', 'crit', 'ok'), true)) {
                    $classList .= ' is-' . $tone;
                }

                if ($href !== '') {
                    echo '<a class="' . $classList . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                } else {
                    echo '<div class="' . $classList . '">';
                }
                echo '<div class="cmp-stat-label">' . htmlspecialchars($cl, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="cmp-stat-value">' . htmlspecialchars($cv, ENT_QUOTES, 'UTF-8') . '</div>';
                if ($cs !== '') {
                    echo '<div class="cmp-stat-sub">' . htmlspecialchars($cs, ENT_QUOTES, 'UTF-8') . '</div>';
                }
                echo $href !== '' ? '</a>' : '</div>';
            }
            echo '</div>';
        }

        echo '</section>';

        if ($flash !== null) {
            compliance_flash($flash);
        }
    }
}

if (!function_exists('compliance_page_close')) {
    function compliance_page_close(): void
    {
        echo '</div>';
    }
}

if (!function_exists('compliance_flash')) {
    /**
     * @param array{type:string,message:string}|null $flash
     */
    function compliance_flash(?array $flash): void
    {
        if ($flash === null) {
            return;
        }
        $type = strtolower((string)($flash['type'] ?? ''));
        $msg = (string)($flash['message'] ?? '');
        if ($msg === '') {
            return;
        }
        $cls = 'cmp-flash ';
        if ($type === 'success' || $type === 'ok') {
            $cls .= 'is-ok';
        } elseif ($type === 'warn' || $type === 'warning') {
            $cls .= 'is-warn';
        } elseif ($type === 'error' || $type === 'danger') {
            $cls .= 'is-danger';
        }
        echo '<div class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

if (!function_exists('compliance_back_link')) {
    /**
     * @param array{href:string,label:string,code?:string} $back
     */
    function compliance_back_link(array $back): void
    {
        $href = (string)($back['href'] ?? '');
        $label = (string)($back['label'] ?? 'Back');
        $code = (string)($back['code'] ?? '');
        if ($href === '') {
            return;
        }
        echo '<div class="cmp-back">';
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">&larr; '
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        if ($code !== '') {
            echo '<span class="cmp-back-divider">|</span>';
            echo '<span class="cmp-back-code">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('compliance_ui_icon')) {
    function compliance_ui_icon(string $name): string
    {
        switch ($name) {
            case 'plus':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'search':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15a7.5 7.5 0 0 1 0 15Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'filter':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'mail':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-9Zm0 .5l8 5.5l8-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'shield':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.4-2.7 8.4-7 10c-4.3-1.6-7-5.6-7-10V6l7-3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'play':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5l12 7l-12 7V5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
            case 'doc':
            case 'document':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm8 0v5h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'open':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M10 14L19 5M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'gear':
            case 'settings':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3a1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9a1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h0a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'pulse':
            case 'monitor':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-7l4 14l3-7h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'list':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'calendar':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7Zm0 4h14M9 4v3m6-3v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'check':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'inbox':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13l2-7h12l2 7M4 13v6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-6M4 13h5l1 2h4l1-2h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'flag':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3v18M5 4h12l-2 4l2 4H5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'tools':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6a4 4 0 0 1 5.5 5.5l-9 9l-3-3l9-9A4 4 0 0 1 14 6Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            default:
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
        }
    }
}
