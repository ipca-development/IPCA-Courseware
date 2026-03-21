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
        'href' => '/instructor/dashboard.php',
        'match_paths' => [
            '/instructor/dashboard.php',
            '/instructor/index.php',
        ],
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
        'key' => 'training_roster',
        'label' => 'Training Roster',
        'icon' => 'users',
        'href' => '/instructor/students/index.php',
        'match_paths' => [
            '/instructor/students/index.php',
            '/instructor/students/view.php',
        ],
    ],
    [
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'icon' => 'theory',
        'items' => [
            [
                'key' => 'cohorts',
                'label' => 'Cohorts',
                'icon' => 'cohorts',
                'href' => '/instructor/cohorts.php',
                'match_paths' => [
                    '/instructor/cohorts.php',
                ],
            ],
            [
                'key' => 'summary_reviews',
                'label' => 'Summary Reviews',
                'icon' => 'reviews',
                'href' => null,
                'coming_soon' => true,
            ],
            [
                'key' => 'instructor_decisions',
                'label' => 'Instructor Decisions',
                'icon' => 'decisions',
                'href' => null,
                'coming_soon' => true,
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
        'label' => 'Account',
    ],
    [
        'key' => 'my_profile',
        'label' => 'My Profile',
        'icon' => 'account',
        'href' => '/instructor/profile.php',
        'match_paths' => [
            '/instructor/profile.php',
        ],
    ],
];