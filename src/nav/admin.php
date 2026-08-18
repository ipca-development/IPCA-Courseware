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
        'href' => '/admin/schedule.php',
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
                'href' => '/admin/cohorts.php',
                'match_paths' => [
                    '/admin/cohorts.php',
                    '/admin/cohort.php',
                ],
            ],
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
                'label' => 'Slide Bulk Enrich',
                'icon' => 'enrich',
                'href' => '/admin/bulk_enrich.php',
            ],
            [
                'key' => 'lesson_summary_blueprints',
                'label' => 'Lesson Bulk Enrich',
                'icon' => 'enrich',
                'href' => '/admin/lesson_summary_blueprints.php',
            ],
            [
                'key' => 'written_test_preparation',
                'label' => 'Written Test Preparation',
                'icon' => 'training',
                'href' => '/admin/written_test.php',
                'match_paths' => [
                    '/admin/written_test.php',
                ],
            ],
        ],
    ],
    [
        'key' => 'flight_training',
        'label' => 'Flight Training',
        'icon' => 'flight',
        'items' => [
            [
                'key' => 'flight_training_form_manager',
                'label' => 'Forms / Form Manager',
                'icon' => 'documents',
                'href' => '/admin/flight_training/forms/index.php',
                'match_paths' => [
                    '/admin/flight_training/forms/index.php',
                    '/admin/flight_training/forms/editor.php',
                ],
            ],
            [
                'key' => 'flight_training_form_packets',
                'label' => 'Send Form Packets',
                'icon' => 'documents',
                'href' => '/admin/flight_training/forms/send.php',
                'match_paths' => [
                    '/admin/flight_training/forms/send.php',
                ],
            ],
            [
                'key' => 'flight_training_admin_logbook',
                'label' => 'Admin Logbook',
                'icon' => 'flight',
                'href' => '/admin/flight_training/logbooks/index.php',
                'match_paths' => [
                    '/admin/flight_training/logbooks/index.php',
                    '/admin/flight_training/logbooks/view.php',
                ],
            ],
            [
                'key' => 'master_logbook',
                'label' => 'Master Logbook',
                'icon' => 'flight',
                'href' => '/admin/master_logbook.php',
                'match_paths' => [
                    '/admin/master_logbook.php',
                ],
            ],
            [
                'key' => 'flight_records',
                'label' => 'Flight Records',
                'icon' => 'flight',
                'href' => '/admin/flight_records.php',
                'match_paths' => [
                    '/admin/flight_records.php',
                    '/admin/flight_record_logbook_proposals.php',
                    '/admin/cvr_logbook_proposals.php',
                ],
            ],
            [
                'key' => 'flight_log_garmin_connection',
                'label' => 'Garmin Sync Agent',
                'icon' => 'flight',
                'href' => '/admin/flight_log_garmin_connection.php',
                'match_paths' => [
                    '/admin/flight_log_garmin_connection.php',
                ],
            ],
            [
                'key' => 'flight_training_requirement_categories',
                'label' => 'Requirement Categories',
                'icon' => 'documents',
                'href' => '/admin/flight_training/requirements/index.php',
                'match_paths' => [
                    '/admin/flight_training/requirements/index.php',
                ],
            ],
            [
                'key' => 'mission_catalog',
                'label' => 'Mission Catalog',
                'icon' => 'documents',
                'href' => '/admin/missions.php',
                'match_paths' => [
                    '/admin/missions.php',
                ],
            ],
            [
                'key' => 'exercise_catalogue',
                'label' => 'Exercise Catalogue',
                'icon' => 'documents',
                'href' => '/admin/exercise_catalogue.php',
                'match_paths' => [
                    '/admin/exercise_catalogue.php',
                ],
            ],
            [
                'key' => 'flight_debriefs',
                'label' => 'Flight Debriefs',
                'icon' => 'reviews',
                'href' => '/admin/flight_debriefs.php',
                'match_paths' => [
                    '/admin/flight_debriefs.php',
                ],
            ],
        ],
    ],

    [
        'type' => 'section',
        'label' => 'Analytics',
    ],
    [
        'key' => 'training_analytics',
        'label' => 'Training Analytics',
        'icon' => 'scanner',
        'match_paths' => [
            '/admin/training_analytics/index.php',
            '/admin/training_analytics/continuity.php',
            '/admin/training_analytics/competencies.php',
            '/admin/training_analytics/competency.php',
            '/admin/training_analytics/curriculum.php',
            '/admin/training_analytics/bottlenecks.php',
            '/admin/training_analytics/mission.php',
            '/admin/training_analytics/progression.php',
            '/admin/training_analytics/instructors.php',
            '/admin/training_analytics/instructor.php',
            '/admin/training_analytics/narratives.php',
            '/admin/training_analytics/programs.php',
            '/admin/training_analytics/program.php',
            '/admin/training_analytics/discoveries.php',
            '/admin/training_analytics/data_quality.php',
        ],
        'items' => [
            [
                'key' => 'training_analytics_overview',
                'label' => 'Overview',
                'icon' => 'dashboard',
                'href' => '/admin/training_analytics/index.php',
            ],
            [
                'key' => 'training_analytics_discoveries',
                'label' => 'Research / Discoveries',
                'icon' => 'decisions',
                'href' => '/admin/training_analytics/discoveries.php',
            ],
            [
                'key' => 'training_analytics_programs',
                'label' => 'Programs',
                'icon' => 'courses',
                'href' => '/admin/training_analytics/programs.php',
                'match_paths' => [
                    '/admin/training_analytics/programs.php',
                    '/admin/training_analytics/program.php',
                ],
            ],
            [
                'key' => 'training_analytics_curriculum',
                'label' => 'Curriculum',
                'icon' => 'documents',
                'href' => '/admin/training_analytics/curriculum.php',
            ],
            [
                'key' => 'training_analytics_progression',
                'label' => 'Student Progression',
                'icon' => 'training',
                'href' => '/admin/training_analytics/progression.php',
            ],
            [
                'key' => 'training_analytics_competencies',
                'label' => 'Competencies',
                'icon' => 'health',
                'href' => '/admin/training_analytics/competencies.php',
                'match_paths' => [
                    '/admin/training_analytics/competencies.php',
                    '/admin/training_analytics/competency.php',
                ],
            ],
            [
                'key' => 'training_analytics_continuity',
                'label' => 'Training Continuity',
                'icon' => 'schedule',
                'href' => '/admin/training_analytics/continuity.php',
            ],
            [
                'key' => 'training_analytics_bottlenecks',
                'label' => 'Mission Bottlenecks',
                'icon' => 'flight',
                'href' => '/admin/training_analytics/bottlenecks.php',
                'match_paths' => [
                    '/admin/training_analytics/bottlenecks.php',
                    '/admin/training_analytics/mission.php',
                ],
            ],
            [
                'key' => 'training_analytics_instructors',
                'label' => 'Instructors',
                'icon' => 'users',
                'href' => '/admin/training_analytics/instructors.php',
                'match_paths' => [
                    '/admin/training_analytics/instructors.php',
                    '/admin/training_analytics/instructor.php',
                ],
            ],
            [
                'key' => 'training_analytics_narratives',
                'label' => 'Narrative Insights',
                'icon' => 'reviews',
                'href' => '/admin/training_analytics/narratives.php',
            ],
            [
                'key' => 'training_analytics_data_quality',
                'label' => 'Data Quality',
                'icon' => 'tools',
                'href' => '/admin/training_analytics/data_quality.php',
            ],
        ],
    ],

    [
        'type' => 'section',
        'label' => 'Operations',
    ],
    [
        'key' => 'operations',
        'label' => 'Operations',
        'icon' => 'operations',
        'href' => '/admin/tv_screens/index.php',
        'match_paths' => [
            '/admin/tv_screens/index.php',
        ],
    ],
    [
        'key' => 'user_accounts',
        'label' => 'User Accounts',
        'icon' => 'users',
        'href' => '/admin/users/index.php',
        'match_paths' => [
            '/admin/users/index.php',
            '/admin/users/create.php',
            '/admin/users/edit.php',
        ],
    ],
    [
        'key' => 'ipca_app',
        'label' => 'IPCA App',
        'icon' => 'users',
        'href' => '/admin/ipca_app.php',
        'match_paths' => [
            '/admin/ipca_app.php',
            '/admin/ipca_training_videos.php',
            '/admin/ipca_media_library.php',
        ],
        'items' => [
            [
                'key' => 'ipca_app_enrollment',
                'label' => 'Enrollment',
                'icon' => 'users',
                'href' => '/admin/ipca_app.php',
            ],
            [
                'key' => 'ipca_training_videos',
                'label' => 'Training Videos',
                'icon' => 'reviews',
                'href' => '/admin/ipca_training_videos.php',
            ],
            [
                'key' => 'ipca_media_library',
                'label' => 'Media Library',
                'icon' => 'slides',
                'href' => '/admin/ipca_media_library.php',
            ],
        ],
    ],
    [
        'key' => 'projects',
        'label' => 'Projects',
        'icon' => 'projects',
        'href' => null,
        'coming_soon' => true,
    ],
    [
        'key' => 'safety_management',
        'label' => 'Safety Management',
        'icon' => 'safety',
        'match_paths' => [
            '/admin/safety/index.php',
        ],
        'visible' => static function (): bool {
            global $pdo;
            if (!isset($pdo) || !($pdo instanceof PDO) || !function_exists('cw_current_user')) {
                return false;
            }
            $accessFile = __DIR__ . '/../safety/SafetyAccessService.php';
            if (is_file($accessFile)) {
                require_once $accessFile;
            }
            if (!class_exists('SafetyAccessService')) {
                return false;
            }
            try {
                $user = cw_current_user($pdo);
                if (!is_array($user)) {
                    return false;
                }
                $access = new SafetyAccessService($pdo);
                return $access->hasPermission(
                    array('user' => $user + array('organization_id' => 1)),
                    'report.read_all'
                );
            } catch (Throwable) {
                return false;
            }
        },
        'items' => [
            [
                'key' => 'safety_management_system',
                'label' => 'System',
                'icon' => 'safety',
                'href' => '/admin/safety/index.php',
                'match_paths' => [
                    '/admin/safety/index.php',
                ],
            ],
        ],
    ],
    [
        'key' => 'books_manuals',
        'label' => 'Books & Manuals',
        'icon' => 'documents',
        'match_paths' => [
            '/admin/books_manuals/index.php',
            '/admin/books_manuals/manual.php',
            '/admin/books_manuals/annexes.php',
        ],
        'items' => [
            [
                'key' => 'books_manuals_library',
                'label' => 'IPCA Library',
                'icon' => 'documents',
                'href' => '/admin/books_manuals/index.php',
                'match_paths' => [
                    '/admin/books_manuals/index.php',
                    '/admin/books_manuals/manual.php',
                ],
            ],
            [
                'key' => 'books_manuals_annexes',
                'label' => 'Annexes',
                'icon' => 'documents',
                'href' => '/admin/books_manuals/annexes.php',
            ],
        ],
    ],
    [
        'key' => 'compliance_os',
        'label' => 'Compliance',
        'icon' => 'compliance',
        'match_paths' => [
            '/admin/compliance/index.php',
            '/admin/compliance/calendar.php',
        ],
        'visible' => static function (): bool {
            if (!function_exists('cw_current_user')) {
                return false;
            }
            global $pdo;
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                return false;
            }

            $access = __DIR__ . '/../compliance/ComplianceAccess.php';
            if (is_file($access)) {
                require_once $access;
            }

            try {
                $u = cw_current_user($pdo);
            } catch (Throwable $e) {
                return false;
            }

            if (!function_exists('compliance_user_has_access')) {
                return false;
            }

            return compliance_user_has_access($u);
        },
        'items' => [
            [
                'key' => 'compliance_dashboard',
                'label' => 'Dashboard',
                'icon' => 'dashboard',
                'href' => '/admin/compliance/index.php',
            ],
            [
                'key' => 'compliance_schedule',
                'label' => 'Schedule',
                'icon' => 'schedule',
                'href' => '/admin/compliance/calendar.php',
            ],

            [
                'type' => 'section',
                'label' => 'Compliance',
            ],
            [
                'key' => 'compliance_audits',
                'label' => 'Audits',
                'icon' => 'reviews',
                'href' => '/admin/compliance/audits.php',
            ],
            [
                'key' => 'compliance_books_manuals_audits',
                'label' => 'Books & Manuals Audit',
                'icon' => 'reviews',
                'href' => '/admin/compliance/books_manuals_audits.php',
            ],
            [
                'key' => 'compliance_findings',
                'label' => 'Findings',
                'icon' => 'decisions',
                'href' => '/admin/compliance/findings.php',
            ],
            [
                'key' => 'compliance_corrective_actions',
                'label' => 'Corrective Actions',
                'icon' => 'tools',
                'href' => '/admin/compliance/corrective_actions.php',
            ],
            [
                'key' => 'compliance_meetings',
                'label' => 'Meetings',
                'icon' => 'schedule',
                'href' => '/admin/compliance/meetings.php',
            ],
            [
                'key' => 'compliance_inbox',
                'label' => 'Mail',
                'icon' => 'documents',
                'href' => '/admin/compliance/inbox.php',
                'match_paths' => [
                    '/admin/compliance/inbox.php',
                    '/admin/compliance/email_thread.php',
                    '/admin/compliance/email_compose.php',
                    '/admin/compliance/email_drafts.php',
                    '/admin/compliance/mail_api.php',
                    '/admin/compliance/email_attachment.php',
                ],
            ],
            [
                'key' => 'compliance_reports',
                'label' => 'Reports',
                'icon' => 'documents',
                'href' => '/admin/compliance/reports.php',
            ],

            [
                'type' => 'section',
                'label' => 'Manuals',
            ],
            [
                'key' => 'compliance_regulations',
                'label' => 'Regulations',
                'icon' => 'documents',
                'href' => '/admin/compliance/regulations.php',
            ],
            [
                'key' => 'compliance_procedures',
                'label' => 'Procedures',
                'icon' => 'documents',
                'href' => '/admin/compliance/procedures.php',
            ],
            [
                'key' => 'compliance_canonical_sources',
                'label' => 'Canonical Sources',
                'icon' => 'documents',
                'href' => '/admin/compliance/canonical_sources.php',
            ],
            [
                'key' => 'compliance_mccf_browser',
                'label' => 'MCCF Browser',
                'icon' => 'documents',
                'href' => '/admin/compliance/mccf_browser.php',
                'match_paths' => [
                    '/admin/compliance/mccf_browser.php',
                ],
            ],
            [
                'key' => 'compliance_controlled_books',
                'label' => 'Controlled Books',
                'icon' => 'documents',
                'href' => '/admin/compliance/controlled_books.php',
                'visible' => false,
                'match_paths' => [
                    '/admin/compliance/controlled_books.php',
                    '/admin/compliance/controlled_book_version.php',
                    '/admin/compliance/controlled_book_section_editor.php',
                    '/admin/compliance/controlled_book_editor.php',
                ],
            ],
            [
                'key' => 'compliance_manual_drafts',
                'label' => 'Draft Manuals',
                'icon' => 'documents',
                'href' => '/admin/compliance/manual_drafts.php',
            ],
            [
                'key' => 'compliance_manual_approved',
                'label' => 'Approved Manuals',
                'icon' => 'documents',
                'href' => '/admin/compliance/manual_approved.php',
            ],
            [
                'key' => 'compliance_change_requests',
                'label' => 'Change Requests',
                'icon' => 'tools',
                'href' => '/admin/compliance/change_requests.php',
            ],
            [
                'key' => 'compliance_moc',
                'label' => 'Management of Change',
                'icon' => 'maintenance',
                'href' => '/admin/compliance/moc.php',
            ],

            [
                'type' => 'section',
                'label' => 'Monitoring',
            ],
            [
                'key' => 'compliance_live_monitoring',
                'label' => 'Live Monitoring',
                'icon' => 'scanner',
                'href' => '/admin/compliance/live_monitoring.php',
            ],
            [
                'key' => 'compliance_cap_monitoring',
                'label' => 'CAP Monitoring',
                'icon' => 'scanner',
                'href' => '/admin/compliance/cap_monitoring.php',
            ],
            [
                'key' => 'compliance_fstd_monitoring',
                'label' => 'FSTD Monitoring',
                'icon' => 'flight',
                'href' => '/admin/compliance/fstd_monitoring.php',
            ],
            [
                'key' => 'compliance_safety_monitoring',
                'label' => 'Safety Monitoring',
                'icon' => 'safety',
                'href' => '/admin/compliance/safety_monitoring.php',
            ],
            [
                'key' => 'compliance_part_is',
                'label' => 'Cyber / Part-IS',
                'icon' => 'safety',
                'href' => '/admin/compliance/part_is.php',
            ],
            [
                'key' => 'compliance_monitoring_rules',
                'label' => 'Monitoring Rules',
                'icon' => 'tools',
                'href' => '/admin/compliance/monitoring_rules.php',
            ],

            [
                'type' => 'section',
                'label' => 'System',
            ],
            [
                'key' => 'compliance_settings',
                'label' => 'Settings',
                'icon' => 'settings',
                'href' => '/admin/compliance/settings.php',
            ],
        ],
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
                'key' => 'theory_control_center',
                'label' => 'Theory Control',
                'icon' => 'settings',
                'href' => '/admin/theory_control_center.php',
                'match_paths' => [
                    '/admin/theory_control_center.php',
                ],
            ],
            [
                'key' => 'resource_library',
                'label' => 'Resource Library',
                'icon' => 'documents',
                'href' => '/admin/resource_library.php',
                'match_paths' => [
                    '/admin/resource_library.php',
                ],
            ],
            [
                'key' => 'ai_dev_agents',
                'label' => 'AI Dev Agents',
                'icon' => 'settings',
                'href' => '/admin/ai_jake_console.php',
                'match_paths' => [
                    '/admin/ai_jake_console.php',
                ],
                'visible' => static function (): bool {
                    if (!function_exists('cw_current_user')) {
                        return false;
                    }

                    global $pdo;

                    if (!isset($pdo) || !($pdo instanceof PDO)) {
                        return false;
                    }

                    try {
                        $u = cw_current_user($pdo);
                    } catch (Throwable $e) {
                        return false;
                    }

                    return (int)($u['id'] ?? 0) === 1 && (string)($u['role'] ?? '') === 'admin';
                },
            ],
            [
                'key' => 'system_health',
                'label' => 'System Health',
                'icon' => 'health',
                'href' => '/admin/architecture_scanner.php',
                'match_paths' => [
                    '/admin/architecture_scanner.php',
                ],
            ],
        ],
    ],
];