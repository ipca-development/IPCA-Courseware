<?php
declare(strict_types=1);

return [
    [
        'key' => 'dashboard',
        'label' => 'Dashboard',
        'items' => [
            ['label' => 'Admin Dashboard', 'href' => '/admin/dashboard.php'],
        ],
    ],

    [
        'key' => 'schedule',
        'label' => 'Schedule',
        'items' => [
            ['label' => 'Schedule', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'items' => [
            ['label' => 'Courses', 'href' => '/admin/courses.php'],
            ['label' => 'Lessons', 'href' => '/admin/lessons.php'],
            ['label' => 'Slides', 'href' => '/admin/slides.php'],
            ['label' => 'Import Lab', 'href' => '/admin/import_lab.php'],
            ['label' => 'Bulk Enrich', 'href' => '/admin/bulk_enrich.php'],
            ['label' => 'Overlay Editor', 'href' => '/admin/slide_overlay_editor.php'],
        ],
    ],

    [
        'key' => 'flight_training',
        'label' => 'Flight Training',
        'items' => [
            ['label' => 'Flight Training', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'operations',
        'label' => 'Operations',
        'items' => [
            ['label' => 'Operations', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'projects',
        'label' => 'Projects',
        'items' => [
            ['label' => 'Projects', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'compliance_monitoring',
        'label' => 'Compliance Monitoring',
        'items' => [
            ['label' => 'Compliance Monitoring', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'safety_management',
        'label' => 'Safety Management',
        'items' => [
            ['label' => 'Safety Management', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'settings',
        'label' => 'Settings',
        'items' => [
            ['label' => 'Settings', 'href' => null, 'coming_soon' => true],
        ],
    ],

    [
        'key' => 'maintenance',
        'label' => 'Maintenance',
        'items' => [
            ['label' => 'Architecture Scanner', 'href' => '/admin/architecture_scanner.php'],
            ['label' => 'Maintenance', 'href' => null, 'coming_soon' => true],
        ],
    ],
];
