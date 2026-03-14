<?php
declare(strict_types=1);

return [
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
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'icon' => 'theory',
        'items' => [
            ['label' => 'Cohorts', 'icon' => 'cohorts', 'href' => '/instructor/cohorts.php'],
            ['label' => 'Summary Reviews', 'icon' => 'summary', 'href' => null, 'coming_soon' => true],
            ['label' => 'Instructor Decisions', 'icon' => 'decision', 'href' => null, 'coming_soon' => true],
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
];
