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
        'key' => 'my_training',
        'label' => 'My Training',
        'icon' => 'training',
        'href' => null,
        'coming_soon' => true,
    ],
    [
        'key' => 'theory_training',
        'label' => 'Theory Training',
        'icon' => 'theory',
        'href' => null,
        'children' => [
            [
                'key' => 'my_courses',
                'label' => 'My Courses',
                'icon' => 'theory',
                'href' => '/student/courses.php',
                'match_paths' => [
                    '/student/courses.php',
                    '/student/course.php',
                ],
            ],
            [
                'key' => 'my_notebook',
                'label' => 'My Notebook',
                'icon' => 'documents',
                'href' => '/student/lesson_summaries.php',
                'match_paths' => [
                    '/student/lesson_summaries.php',
                    '/student/export_lesson_summaries_pdf.php',
                ],
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