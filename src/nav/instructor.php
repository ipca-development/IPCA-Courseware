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
                'key' => 'cohorts',
                'label' => 'Cohorts',
                'icon' => 'cohorts',
                'href' => '/instructor/cohorts.php',
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
];
