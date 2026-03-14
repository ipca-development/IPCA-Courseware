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
        'href' => '/student/dashboard.php',
    ],
    [
        'key' => 'my_training',
        'label' => 'My Training',
        'icon' => 'training',
        'href' => '/student/dashboard.php',
    ],
    [
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'icon' => 'theory',
        'href' => '/student/dashboard.php',
    ],
    [
        'key' => 'flight_training',
        'label' => 'Flight Training',
        'icon' => 'flight',
        'href' => null,
        'coming_soon' => true,
    ],
    [
        'key' => 'safety',
        'label' => 'Safety',
        'icon' => 'safety',
        'href' => null,
        'coming_soon' => true,
    ],
    [
        'key' => 'schedule',
        'label' => 'Schedule',
        'icon' => 'schedule',
        'href' => null,
        'coming_soon' => true,
    ],
    [
        'key' => 'documents',
        'label' => 'Documents',
        'icon' => 'documents',
        'href' => null,
        'coming_soon' => true,
    ],

    [
        'type' => 'section',
        'label' => 'Account',
    ],
    [
        'key' => 'account',
        'label' => 'Account',
        'icon' => 'account',
        'href' => null,
        'coming_soon' => true,
    ],
];
