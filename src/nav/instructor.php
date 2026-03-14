<?php
declare(strict_types=1);

return [
    [
        'key' => 'dashboard',
        'label' => 'Dashboard',
        'items' => [
            ['label' => 'Instructor Dashboard', 'href' => '/instructor/dashboard.php'],
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
            ['label' => 'Cohorts', 'href' => '/instructor/cohorts.php'],
            ['label' => 'Summary Reviews', 'href' => null, 'coming_soon' => true],
            ['label' => 'Instructor Decisions', 'href' => null, 'coming_soon' => true],
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
];
