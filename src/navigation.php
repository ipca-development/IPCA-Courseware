<?php
declare(strict_types=1);

function cw_nav_items_for_role(string $role): array
{
    $role = strtolower(trim($role));

    return match ($role) {
        'admin' => require __DIR__ . '/nav/admin.php',
        'instructor', 'supervisor', 'chief_instructor' => require __DIR__ . '/nav/instructor.php',
        'student' => require __DIR__ . '/nav/student.php',
        default => [],
    };
}

function cw_nav_is_current(string $href, string $currentPath): bool
{
    if ($href === '') {
        return false;
    }

    $hrefPath = parse_url($href, PHP_URL_PATH);
    if (!is_string($hrefPath) || $hrefPath === '') {
        $hrefPath = $href;
    }

    return rtrim($hrefPath, '/') === rtrim($currentPath, '/');
}

function cw_nav_item_is_current(array $item, string $currentPath): bool
{
    $href = (string)($item['href'] ?? '');
    if ($href !== '' && cw_nav_is_current($href, $currentPath)) {
        return true;
    }

    $matchPaths = isset($item['match_paths']) && is_array($item['match_paths'])
        ? $item['match_paths']
        : [];

    foreach ($matchPaths as $matchPath) {
        if (!is_string($matchPath) || trim($matchPath) === '') {
            continue;
        }

        if (rtrim($matchPath, '/') === rtrim($currentPath, '/')) {
            return true;
        }
    }

    return false;
}

function cw_nav_child_items(array $item): array
{
    if (isset($item['items']) && is_array($item['items'])) {
        return $item['items'];
    }

    if (isset($item['children']) && is_array($item['children'])) {
        return $item['children'];
    }

    return [];
}

function cw_nav_group_is_active(array $items, string $currentPath): bool
{
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'section') {
            continue;
        }

        if (cw_nav_item_is_current($item, $currentPath)) {
            return true;
        }

        $children = cw_nav_child_items($item);
        if ($children && cw_nav_group_is_active($children, $currentPath)) {
            return true;
        }
    }

    return false;
}

function cw_nav_icon_img(?string $icon, string $label): string
{
    $icon = trim((string)$icon);
    if ($icon === '') {
        return '';
    }

    $svgSrc = '/assets/icons/' . rawurlencode($icon) . '.svg';
    $pngSrc = '/assets/icons/' . rawurlencode($icon) . '.png';

    return ''
        . '<img'
        . ' class="nav-icon"'
        . ' src="' . htmlspecialchars($svgSrc, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-png-fallback="' . htmlspecialchars($pngSrc, ENT_QUOTES, 'UTF-8') . '"'
        . ' alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
        . ' loading="lazy"'
        . '>';
}

function cw_nav_item_is_visible(array $item): bool
{
    if (!array_key_exists('visible', $item)) {
        return true;
    }

    $visible = $item['visible'];

    if (is_bool($visible)) {
        return $visible;
    }

    if (is_callable($visible)) {
        try {
            return (bool)$visible();
        } catch (Throwable $e) {
            return false;
        }
    }

    return true;
}

function cw_nav_filter_items(array $items): array
{
    $filtered = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (($item['type'] ?? '') === 'section') {
            $filtered[] = $item;
            continue;
        }

        if (!cw_nav_item_is_visible($item)) {
            continue;
        }

        $children = cw_nav_child_items($item);
        if ($children) {
            $children = cw_nav_filter_items($children);

            if (isset($item['items']) && is_array($item['items'])) {
                $item['items'] = $children;
            } elseif (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = $children;
            }

            if (count($children) === 0) {
                continue;
            }
        }

        $filtered[] = $item;
    }

    return $filtered;
}

function cw_render_navigation(string $role, string $currentPath, string $roleLabel = ''): string
{
    $entries = cw_nav_items_for_role($role);
    $entries = cw_nav_filter_items($entries);

    if (!$entries) {
        return '';
    }

    $html = '';
    $html .= '<aside class="app-sidebar-shell">';
    $html .= '  <div class="app-sidebar-top">';
    $html .= '    <div class="app-brand">';
    $html .= '      <div class="app-brand-mark">';
    $html .= '        <img src="/assets/logo/ipca_logo_white.png" alt="IPCA">';
    $html .= '      </div>';
    $html .= '      <div class="app-brand-copy">';
    $html .= '        <div class="app-brand-title">IPCA Academy</div>';
    $html .= '        <div class="app-brand-subtitle">Aviation Training Platform</div>';
    $html .= '      </div>';
    $html .= '    </div>';
    $html .= '  </div>';

    $html .= '  <div class="app-sidebar-nav">';
    $html .= '    <div class="cw-nav-groups">';

    foreach ($entries as $entry) {
        $type = (string)($entry['type'] ?? '');

        if ($type === 'section') {
            $label = (string)($entry['label'] ?? '');
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

            $html .= '<div class="nav-section-label">' . $labelEsc . '</div>';
            continue;
        }

        $label = (string)($entry['label'] ?? '');
        $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $href = (string)($entry['href'] ?? '');
        $icon = (string)($entry['icon'] ?? '');
        $comingSoon = !empty($entry['coming_soon']);
        $items = cw_nav_child_items($entry);

        if (!$items) {
            $html .= '<div class="nav-block nav-block-direct">';

            if ($href === '' || $comingSoon) {
                $html .= '<span class="nav-link is-disabled">';
                $html .= '<span class="nav-link-icon-rail">' . cw_nav_icon_img($icon, $label) . '</span>';
                $html .= '<span class="nav-link-label">' . $labelEsc . '</span>';
                $html .= '</span>';
            } else {
                $class = 'nav-link';
                $current = '';
                if (cw_nav_item_is_current($entry, $currentPath)) {
                    $class .= ' is-active';
                    $current = ' aria-current="page"';
                }

                $html .= '<a class="' . $class . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $current . '>';
                $html .= '<span class="nav-link-accent"></span>';
                $html .= '<span class="nav-link-icon-rail">' . cw_nav_icon_img($icon, $label) . '</span>';
                $html .= '<span class="nav-link-label">' . $labelEsc . '</span>';
                $html .= '</a>';
            }

            $html .= '</div>';
            continue;
        }

        $groupActive = cw_nav_group_is_active($items, $currentPath);
        $detailsClass = 'nav-group';
        if ($groupActive) {
            $detailsClass .= ' is-open';
        }

        $html .= '<details class="' . $detailsClass . '"' . ($groupActive ? ' open' : '') . '>';
        $html .= '  <summary class="nav-group-summary">';
        $html .= '    <span class="nav-group-summary-left">';
        $html .= '      <span class="nav-link-icon-rail">' . cw_nav_icon_img($icon, $label) . '</span>';
        $html .= '      <span class="nav-group-title">' . $labelEsc . '</span>';
        $html .= '    </span>';
        $html .= '    <span class="nav-group-caret">›</span>';
        $html .= '  </summary>';
        $html .= '  <div class="nav-group-items">';

        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'section') {
                $itemLabel = (string)($item['label'] ?? '');
                $itemLabelEsc = htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8');
                $html .= '<div class="nav-subsection-label">' . $itemLabelEsc . '</div>';
                continue;
            }

            $itemLabel = (string)($item['label'] ?? '');
            $itemLabelEsc = htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8');
            $itemHref = (string)($item['href'] ?? '');
            $itemIcon = (string)($item['icon'] ?? '');
            $itemComingSoon = !empty($item['coming_soon']);

            if ($itemHref === '' || $itemComingSoon) {
                $html .= '<span class="nav-link nav-link-child is-disabled">';
                $html .= '<span class="nav-link-icon-rail">' . cw_nav_icon_img($itemIcon, $itemLabel) . '</span>';
                $html .= '<span class="nav-link-label">' . $itemLabelEsc . '</span>';
                $html .= '</span>';
                continue;
            }

            $class = 'nav-link nav-link-child';
            $current = '';
            if (cw_nav_item_is_current($item, $currentPath)) {
                $class .= ' is-active';
                $current = ' aria-current="page"';
            }

            $html .= '<a class="' . $class . '" href="' . htmlspecialchars($itemHref, ENT_QUOTES, 'UTF-8') . '"' . $current . '>';
            $html .= '<span class="nav-link-accent"></span>';
            $html .= '<span class="nav-link-icon-rail">' . cw_nav_icon_img($itemIcon, $itemLabel) . '</span>';
            $html .= '<span class="nav-link-label">' . $itemLabelEsc . '</span>';
            $html .= '</a>';
        }

        $html .= '  </div>';
        $html .= '</details>';
    }

    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '</aside>';

    return $html;
}