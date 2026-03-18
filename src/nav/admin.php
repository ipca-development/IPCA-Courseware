<?php
declare(strict_types=1);

return [
    [
        'type' => 'section',
        'label' => 'Main',
    ],
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
        'type' => 'section',
        'label' => 'Training',
    ],
    [
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'icon' => 'theory',
        'items' => [
            [
                'key' => 'courses',
                'label' => 'Courses',
                'icon' => 'courses',
                'href' => '/admin/courses.php',
            ],
            [
                'key' => 'lessons',
                'label' => 'Lessons',
                'icon' => 'lessons',
                'href' => '/admin/lessons.php',
            ],
            [
                'key' => 'slides',
                'label' => 'Slides',
                'icon' => 'slides',
                'href' => '/admin/slides.php',
            ],
            [
                'key' => 'bulk_import',
                'label' => 'Bulk Import',
                'icon' => 'import',
                'href' => '/admin/import_lab.php',
            ],
            [
                'key' => 'bulk_enrich',
                'label' => 'Bulk Enrich',
                'icon' => 'enrich',
                'href' => '/admin/bulk_enrich.php',
            ],
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
        'type' => 'section',
        'label' => 'Operations',
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
        'key' => 'compliance_monitoring',
        'label' => 'Compliance Monitoring',
        'icon' => 'compliance',
        'href' => null,
        'coming_soon' => true,
    ],
    [
        'key' => 'safety_management',
        'label' => 'Safety Management',
        'icon' => 'safety',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'type' => 'section',
        'label' => 'System',
    ],
    [
        'key' => 'settings',
        'label' => 'Settings',
        'icon' => 'settings',
        'items' => [
            [
                'key' => 'system_health',
                'label' => 'System Health',
                'icon' => 'health',
                'href' => null,
                'coming_soon' => true,
            ],
            [
                'key' => 'architecture_scanner',
                'label' => 'Architecture Scanner',
                'icon' => 'scanner',
                'href' => '/admin/architecture_scanner.php',
            ],

            /* ✅ NEW: Notifications */
            [
                'key' => 'notifications',
                'label' => 'Notifications',
                'icon' => 'settings', // you can later replace with 'bell' if you add icon
                'href' => '/admin/notifications.php',
                'match_paths' => [
                    '/admin/notifications.php',
                    '/admin/notification_edit.php',
                    '/admin/notification_versions.php',
                ],
            ],
        ],
    ],
];
