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

    return rtrim($href, '/') === rtrim($currentPath, '/');
}

function cw_render_navigation(string $role, string $currentPath): string
{
    $groups = cw_nav_items_for_role($role);
    if (!$groups) {
        return '';
    }

    $html = '';

    $html .= '<div class="cw-nav-shell">';
    $html .= '<div class="cw-nav-groups">';

    foreach ($groups as $group) {
        $html .= '<div class="cw-nav-group">';
        $html .= '<div class="cw-nav-group-title">' . htmlspecialchars((string)$group['label'], ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<div class="cw-nav-list">';

        foreach ((array)$group['items'] as $item) {
            $label = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
            $href = (string)($item['href'] ?? '');
            $comingSoon = !empty($item['coming_soon']);

            if ($href === '' || $comingSoon) {
                $html .= '<span class="cw-nav-link disabled">' . $label . '</span>';
                continue;
            }

            $class = 'cw-nav-link';
            if (cw_nav_is_current($href, $currentPath)) {
                $class .= ' current';
            }

            $html .= '<a class="' . $class . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
        }

        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
