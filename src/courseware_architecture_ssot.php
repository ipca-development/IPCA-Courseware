<?php
declare(strict_types=1);

return [
    'project_name' => 'IPCA Courseware',
    'environment'  => [
        'php'       => '8.2',
        'mysql'     => '8.0',
        'hosting'   => 'DigitalOcean App Platform',
        'media'     => 'DigitalOcean Spaces',
        'email'     => 'Postmark',
        'code'      => 'GitHub',
        'scan_mode' => 'deployment_web_root',
    ],

    /**
     * IMPORTANT:
     * This scanner currently scans the deployed web root:
     * /var/www/html
     *
     * In GitHub this corresponds to the repository's /public folder.
     */
    'required_directories' => [
        'admin',
        'assets',
        'instructor',
        'player',
        'student',
    ],

    'ignored_directories' => [
        '.git',
        '.github',
        'node_modules',
    ],

    'forbidden_file_patterns' => [
        '/\.env$/i',
        '/\.env\./i',
        '/\.sql$/i',
        '/\.bak$/i',
        '/\.old$/i',
        '/\.orig$/i',
        '/\.tmp$/i',
        '/~$/',
        '/copy of/i',
        '/\.DS_Store$/i',
        '/backup/i',
    ],

    'max_repo_file_size_bytes' => 15 * 1024 * 1024,

    /**
     * Only require files that should exist inside the deployed web root.
     */
    'required_files' => [
        'index.php',
        'login.php',
        'logout.php',
        'admin/architecture_scanner.php',
    ],

    /**
     * Exclude scanner files from secret scanning to avoid self-triggered regex false positives.
     */
    'secret_scan_excluded_files' => [
        'admin/architecture_scanner.php',
    ],

    'components' => [
        'slide_player' => [
            'label' => 'Slide player',
            'markers' => [
                'player',
                'player/api',
            ],
        ],
        'ai_narration' => [
            'label' => 'AI narration (OpenAI TTS)',
            'markers' => [
                'player',
                'student/api',
                'admin',
            ],
        ],
        'progress_tests' => [
            'label' => 'AI-generated progress tests',
            'markers' => [
                'student',
                'student/api',
            ],
        ],
        'progression_engine_v2' => [
            'label' => 'Training progression engine (v2)',
            'markers' => [
                'student/api',
                'admin',
            ],
        ],
        'remediation_escalation' => [
            'label' => 'Remediation / instructor escalation logic',
            'markers' => [
                'instructor',
                'admin',
            ],
        ],
        'policy_engine' => [
            'label' => 'Policy engine',
            'markers' => [
                'admin',
                'student/api',
                'instructor',
            ],
        ],
        'cohort_scheduling' => [
            'label' => 'Cohort scheduling engine',
            'markers' => [
                'admin',
                'instructor',
                'student',
            ],
        ],
        'slide_designer_canonical' => [
            'label' => 'Slide designer / canonical data system',
            'markers' => [
                'admin',
                'assets',
            ],
        ],
    ],

    'secret_patterns' => [
        'OpenAI API key' => '/sk-[A-Za-z0-9]{20,}/',
        'Postmark token assignment' => '/postmark[a-z0-9\-_]{10,}/i',
        'AWS or Spaces key assignment' => '/(AWS|SPACES)_(ACCESS_KEY|SECRET_KEY)/i',
        'Hardcoded bearer token usage' => '/Bearer\s+[A-Za-z0-9\-\._~\+\/=]{20,}/i',
    ],

    'scannable_extensions' => [
        'php', 'inc', 'phtml', 'html', 'js', 'ts', 'json', 'yml', 'yaml', 'md', 'txt'
    ],
];