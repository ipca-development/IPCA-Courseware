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
    $matchQuery = isset($item['match_query']) && is_array($item['match_query'])
        ? $item['match_query']
        : null;

    $href = (string)($item['href'] ?? '');
    if ($href !== '' && cw_nav_is_current($href, $currentPath)) {
        if ($matchQuery === null || cw_nav_query_params_match($matchQuery)) {
            return true;
        }
    }

    $matchPaths = isset($item['match_paths']) && is_array($item['match_paths'])
        ? $item['match_paths']
        : [];

    foreach ($matchPaths as $matchPath) {
        if (!is_string($matchPath) || trim($matchPath) === '') {
            continue;
        }

        if (rtrim($matchPath, '/') !== rtrim($currentPath, '/')) {
            continue;
        }

        if ($matchQuery === null || cw_nav_query_params_match($matchQuery)) {
            return true;
        }
    }

    return false;
}

function cw_nav_current_query_params(): array
{
    $query = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return [];
    }

    parse_str($query, $params);
    return is_array($params) ? $params : [];
}

function cw_nav_query_params_match(array $required): bool
{
    $current = cw_nav_current_query_params();
    foreach ($required as $key => $value) {
        if (!array_key_exists($key, $current) || (string)$current[$key] !== (string)$value) {
            return false;
        }
    }

    return true;
}

function cw_nav_program_label(array $row): string
{
    $label = trim((string)($row['program_name'] ?? ''));
    if ($label === '') {
        $label = trim((string)($row['program_key'] ?? ''));
    }
    if ($label === '') {
        $label = 'Training Program';
    }

    return $label;
}

function cw_nav_student_program_course_items(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            co.id AS cohort_id,
            co.name AS cohort_name,
            p.name AS program_name,
            p.program_key
        FROM cohort_students cs
        JOIN cohorts co ON co.id = cs.cohort_id
        LEFT JOIN programs p ON p.id = co.program_id
        WHERE cs.user_id = ?
        ORDER BY p.name ASC, co.name ASC, co.id DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $items = [];
    $labelCounts = [];

    foreach ($rows as $row) {
        $cohortId = (int)($row['cohort_id'] ?? 0);
        if ($cohortId <= 0) {
            continue;
        }

        $baseLabel = cw_nav_program_label($row);
        $labelCounts[$baseLabel] = (int)($labelCounts[$baseLabel] ?? 0) + 1;
    }

    foreach ($rows as $row) {
        $cohortId = (int)($row['cohort_id'] ?? 0);
        if ($cohortId <= 0) {
            continue;
        }

        $baseLabel = cw_nav_program_label($row);
        $label = $baseLabel;
        if (($labelCounts[$baseLabel] ?? 0) > 1) {
            $cohortName = trim((string)($row['cohort_name'] ?? ''));
            $label = $cohortName !== '' ? ($baseLabel . ' · ' . $cohortName) : ($baseLabel . ' #' . $cohortId);
        }

        $items[] = [
            'key' => 'course_cohort_' . $cohortId,
            'label' => $label,
            'icon' => 'theory',
            'href' => '/student/course.php?cohort_id=' . $cohortId,
            'match_paths' => [
                '/student/course.php',
            ],
            'match_query' => [
                'cohort_id' => (string)$cohortId,
            ],
        ];
    }

    return $items;
}

function cw_nav_inject_student_program_links(array $entries, PDO $pdo, int $userId): array
{
    $programItems = cw_nav_student_program_course_items($pdo, $userId);
    if (!$programItems) {
        return $entries;
    }

    foreach ($entries as $index => $entry) {
        if (($entry['key'] ?? '') !== 'theory_training') {
            continue;
        }

        $children = cw_nav_child_items($entry);
        if (!$children) {
            continue;
        }

        $newChildren = [];
        foreach ($children as $child) {
            $newChildren[] = $child;
            if (($child['key'] ?? '') === 'my_courses') {
                foreach ($programItems as $programItem) {
                    $newChildren[] = $programItem;
                }
            }
        }

        if (isset($entry['children']) && is_array($entry['children'])) {
            $entries[$index]['children'] = $newChildren;
        } elseif (isset($entry['items']) && is_array($entry['items'])) {
            $entries[$index]['items'] = $newChildren;
        }
    }

    return $entries;
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

function cw_render_navigation(
    string $role,
    string $currentPath,
    string $roleLabel = '',
    int $adminStudentPreviewUserId = 0,
    ?PDO $pdo = null,
    ?array $navUser = null
): string
{
    $entries = cw_nav_items_for_role($role);
    if ($role === 'student' && $pdo instanceof PDO && is_array($navUser)) {
        $studentUserId = cw_student_view_user_id($pdo, $navUser);
        $entries = cw_nav_inject_student_program_links($entries, $pdo, $studentUserId);
    }
    $entries = cw_nav_filter_items($entries);

    if (!$entries) {
        return '';
    }

    $rewriteStudentPreview = static function (string $href) use ($adminStudentPreviewUserId): string {
        if ($adminStudentPreviewUserId <= 0) {
            return $href;
        }
        return cw_nav_href_with_admin_student_preview($href, $adminStudentPreviewUserId);
    };

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
                $resolvedHref = $rewriteStudentPreview($href);
                $class = 'nav-link';
                $current = '';
                if (cw_nav_item_is_current($entry, $currentPath)) {
                    $class .= ' is-active';
                    $current = ' aria-current="page"';
                }

                $html .= '<a class="' . $class . '" href="' . htmlspecialchars($resolvedHref, ENT_QUOTES, 'UTF-8') . '"' . $current . '>';
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

            $resolvedChildHref = $rewriteStudentPreview($itemHref);

            $html .= '<a class="' . $class . '" href="' . htmlspecialchars($resolvedChildHref, ENT_QUOTES, 'UTF-8') . '"' . $current . '>';
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