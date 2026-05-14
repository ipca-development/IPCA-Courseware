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
     *   actions?:array<int,array{label:string,href?:string,modal?:string,icon?:string,variant?:string}>,
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

        echo '<div class="cmp-page compliance-page">';

        if ($back !== null && isset($back['href'], $back['label'])) {
            compliance_back_link($back);
        }

        echo '<section class="app-section-hero cmp-hero compliance-hero">';

        if ($overline !== '') {
            echo '<div class="hero-overline compliance-hero__eyebrow">' . htmlspecialchars($overline, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        echo '<div class="cmp-hero-head compliance-hero__head">';
        echo '  <div class="cmp-hero-copy compliance-hero__copy">';
        echo '    <h1 class="cmp-hero-title compliance-hero__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        if ($description !== '') {
            echo '    <p class="cmp-hero-text compliance-hero__text">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '  </div>';

        if ($actions) {
            echo '  <div class="cmp-hero-actions compliance-hero__actions">';
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $label = (string)($action['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $href = (string)($action['href'] ?? '');
                $modal = (string)($action['modal'] ?? '');
                $icon = (string)($action['icon'] ?? '');
                $svg = $icon !== '' ? compliance_ui_icon($icon) : '';

                if ($href !== '') {
                    echo '<a class="cmp-hero-action compliance-hero__action compliance-btn compliance-btn--secondary" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                    echo $svg;
                    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</a>';
                } elseif ($modal !== '') {
                    echo '<button type="button" class="cmp-hero-action compliance-hero__action compliance-btn compliance-btn--secondary" data-compliance-modal-open="' . htmlspecialchars($modal, ENT_QUOTES, 'UTF-8') . '">';
                    echo $svg;
                    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</button>';
                } else {
                    echo '<button type="button" class="cmp-hero-action compliance-hero__action compliance-btn compliance-btn--secondary">';
                    echo $svg;
                    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</button>';
                }
            }
            echo '  </div>';
        }

        echo '</div>'; // .cmp-hero-head

        if ($stats) {
            echo '<div class="cmp-hero-stats compliance-kpi-grid">';
            foreach ($stats as $chip) {
                if (!is_array($chip)) {
                    continue;
                }
                $cl = (string)($chip['label'] ?? '');
                $cv = (string)($chip['value'] ?? '');
                $cs = (string)($chip['sub'] ?? '');
                $tone = (string)($chip['tone'] ?? '');
                $href = (string)($chip['href'] ?? '');

                $classList = 'cmp-stat-chip compliance-kpi-card';
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

if (!function_exists('compliance_friendly_label')) {
    function compliance_friendly_label(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Not set';
        }
        if (preg_match('/^LEVEL_(\d+)$/', strtoupper($value), $m)) {
            return 'LEVEL ' . $m[1];
        }

        return ucwords(strtolower(str_replace('_', ' ', $value)));
    }
}

if (!function_exists('compliance_badge_class')) {
    function compliance_badge_class(string $value, string $kind = 'status'): string
    {
        $v = strtoupper(trim($value));
        $classes = 'cmp-pill compliance-badge';

        if ($kind === 'level') {
            if ($v === 'LEVEL_1') { return $classes . ' compliance-badge--level-1'; }
            if ($v === 'LEVEL_2') { return $classes . ' compliance-badge--level-2'; }
            if ($v === 'LEVEL_3') { return $classes . ' compliance-badge--level-3'; }
        }

        if ($kind === 'severity') {
            if ($v === 'CRITICAL') { return $classes . ' compliance-badge--severity-critical'; }
            if ($v === 'HIGH') { return $classes . ' compliance-badge--severity-high'; }
            if ($v === 'MEDIUM') { return $classes . ' compliance-badge--severity-medium'; }
            if ($v === 'LOW') { return $classes . ' compliance-badge--severity-low'; }
        }

        if (in_array($v, array('CLOSED', 'COMPLETED', 'RELEASED', 'SENT', 'VERIFIED', 'RESOLVED'), true)) {
            return $classes . ' compliance-badge--status-closed';
        }
        if (in_array($v, array('IN_PROGRESS', 'WAITING_AUTHORITY', 'WAITING_INTERNAL', 'WAITING_EXTERNAL', 'UNDER_REVIEW', 'LIVE', 'SENDING', 'READY_TO_SEND'), true)) {
            return $classes . ' compliance-badge--status-progress';
        }
        if (in_array($v, array('OPEN', 'NEW', 'SCHEDULED'), true)) {
            return $classes . ' compliance-badge--status-open';
        }
        if (in_array($v, array('PLANNED', 'DRAFT', 'PROPOSED', 'PENDING', 'PENDING_APPROVAL'), true)) {
            return $classes . ' compliance-badge--planned';
        }
        if (in_array($v, array('CANCELLED', 'VOID', 'REJECTED', 'FAILED'), true)) {
            return $classes . ' compliance-badge--status-muted';
        }

        return $classes;
    }
}

if (!function_exists('compliance_badge')) {
    function compliance_badge(string $value, string $kind = 'status'): string
    {
        return '<span class="' . htmlspecialchars(compliance_badge_class($value, $kind), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(compliance_friendly_label($value), ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

if (!function_exists('compliance_deadline_badge')) {
    function compliance_deadline_badge(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return '<span class="cmp-pill compliance-badge compliance-badge--status-muted">No due date</span>';
        }
        try {
            $today = new DateTimeImmutable(date('Y-m-d'));
            $due = new DateTimeImmutable(substr($date, 0, 10));
            $days = (int)$today->diff($due)->format('%r%a');
        } catch (Throwable $e) {
            return '<span class="cmp-pill compliance-badge compliance-badge--status-muted">' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        if ($days < 0) {
            $label = 'Expired ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago';
            $class = 'compliance-badge--deadline-expired';
        } elseif ($days === 0) {
            $label = 'Due today';
            $class = 'compliance-badge--deadline-warning';
        } elseif ($days <= 7) {
            $label = 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's');
            $class = 'compliance-badge--deadline-warning';
        } else {
            $label = 'Due in ' . $days . ' days';
            $class = 'compliance-badge--deadline-ok';
        }

        return '<span class="cmp-pill compliance-badge ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('compliance_modal_open')) {
    function compliance_modal_open(string $id, string $title): void
    {
        echo '<dialog class="compliance-modal" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="compliance-modal__panel">';
        echo '<div class="compliance-modal__header">';
        echo '<h2 class="compliance-modal__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
        echo '<button type="button" class="compliance-modal__close cmp-btn-secondary" data-compliance-modal-close aria-label="Close modal">&times;</button>';
        echo '</div>';
        echo '<div class="compliance-modal__body">';
    }
}

if (!function_exists('compliance_modal_close')) {
    function compliance_modal_close(): void
    {
        echo '</div></div></dialog>';
    }
}

if (!function_exists('compliance_ui_script')) {
    function compliance_ui_script(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
        <script>
        (function () {
          if (window.__ipcaComplianceUiReady) { return; }
          window.__ipcaComplianceUiReady = true;
          document.addEventListener('click', function (ev) {
            var opener = ev.target.closest('[data-compliance-modal-open]');
            if (opener) {
              var id = opener.getAttribute('data-compliance-modal-open');
              var modal = id ? document.getElementById(id) : null;
              if (modal && typeof modal.showModal === 'function') { modal.showModal(); }
              ev.preventDefault();
              return;
            }
            if (ev.target.matches('[data-compliance-modal-close]')) {
              var dialog = ev.target.closest('dialog');
              if (dialog) { dialog.close(); }
              ev.preventDefault();
              return;
            }
            var row = ev.target.closest('tr[data-href]');
            if (row && !ev.target.closest('a,button,input,select,textarea,label,form')) {
              window.location.href = row.getAttribute('data-href');
            }
          });
        })();
        </script>
        <?php
    }
}

if (!function_exists('compliance_page_close')) {
    function compliance_page_close(): void
    {
        compliance_ui_script();
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
