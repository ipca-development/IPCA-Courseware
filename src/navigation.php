<?php
declare(strict_types=1);

function cw_nav_items_for_role(string $role): array
{
    $role = trim(strtolower($role));

    return match ($role) {
        'admin' => require __DIR__ . '/nav/admin.php',
        'instructor', 'chief_instructor' => require __DIR__ . '/nav/instructor.php',
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

    $html .= '<style>';
    $html .= '.cw-nav-shell{margin:0 0 18px 0;padding:14px 16px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,0.05);}';
    $html .= '.cw-nav-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}';
    $html .= '.cw-nav-group-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#6b7280;margin-bottom:8px;}';
    $html .= '.cw-nav-list{display:flex;flex-direction:column;gap:6px;}';
    $html .= '.cw-nav-link{display:block;padding:8px 10px;border-radius:10px;text-decoration:none;background:#f8fafc;color:#1f2937;font-size:14px;font-weight:700;border:1px solid transparent;}';
    $html .= '.cw-nav-link:hover{background:#eef2ff;color:#1e3c72;border-color:#c7d2fe;}';
    $html .= '.cw-nav-link.current{background:#1e3c72;color:#fff;border-color:#1e3c72;}';
    $html .= '.cw-nav-link.disabled{background:#f9fafb;color:#9ca3af;border-color:#e5e7eb;cursor:default;pointer-events:none;}';
    $html .= '</style>';

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
