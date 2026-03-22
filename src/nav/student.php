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
        'match_paths' => [
            '/student/dashboard.php',
            '/student/index.php',
        ],
    ],

    [
        'type' => 'section',
        'label' => 'Theory Training',
    ],
    [
        'key' => 'my_courses',
        'label' => 'My Courses',
        'icon' => 'training',
        'href' => '/student/courses.php',
        'match_paths' => [
            '/student/courses.php',
            '/student/course.php',
        ],
    ],
    [
        'key' => 'my_notebook',
        'label' => 'My Notebook',
        'icon' => 'theory',
        'href' => '/student/lesson_summaries.php',
        'match_paths' => [
            '/student/lesson_summaries.php',
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
        'key' => 'my_profile',
        'label' => 'My Profile',
        'icon' => 'account',
        'href' => '/student/profile.php',
        'match_paths' => [
            '/student/profile.php',
        ],
    ],
];