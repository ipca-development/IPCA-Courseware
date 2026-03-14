<?php
declare(strict_types=1);

return [
    [
        'key' => 'dashboard',
        'label' => 'Dashboard',
        'icon' => 'dashboard',
        'href' => '/admin/dashboard.php',
    ],

    [
        'key' => 'schedule',
        'label' => 'Schedule',
        'icon' => 'schedule',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'icon' => 'theory',
        'items' => [
            ['label' => 'Courses', 'icon' => 'courses', 'href' => '/admin/courses.php'],
            ['label' => 'Lessons', 'icon' => 'lessons', 'href' => '/admin/lessons.php'],
            ['label' => 'Slides', 'icon' => 'slides', 'href' => '/admin/slides.php'],
            ['label' => 'Bulk Import', 'icon' => 'import', 'href' => '/admin/import_lab.php'],
            ['label' => 'Bulk Enrich', 'icon' => 'enrich', 'href' => '/admin/bulk_enrich.php'],
        ],
    ],

    [
        'key' => 'flight_training',
        'label' => 'Flight Training',
        'icon' => 'flight',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'key' => 'operations',
        'label' => 'Operations',
        'icon' => 'operations',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'key' => 'projects',
        'label' => 'Projects',
        'icon' => 'projects',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'key' => 'compliance',
        'label' => 'Compliance Monitoring',
        'icon' => 'compliance',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'key' => 'safety',
        'label' => 'Safety Management',
        'icon' => 'safety',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'key' => 'settings',
        'label' => 'Settings',
        'icon' => 'settings',
        'items' => [
            ['label' => 'System Health', 'icon' => 'health', 'href' => null, 'coming_soon' => true],
            ['label' => 'Architecture Scanner', 'icon' => 'scanner', 'href' => '/admin/architecture_scanner.php'],
        ],
    ],
];
