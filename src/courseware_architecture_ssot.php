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
        'scan_mode' => 'full_project_root',
    ],

    /**
     * IMPORTANT:
     * This scanner now scans the full deployed project root:
     * /var/www/ipca
     *
     * Public-facing application files live under /public.
     * Core system files live under /src, /templates, /storage, /vendor, etc.
     */
    'required_directories' => [
        'public',
        'public/admin',
        'public/assets',
        'public/instructor',
        'public/player',
        'public/student',
        'src',
        'templates',
        'storage',
        'vendor',
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
     * Required files relative to full project root.
     */
    'required_files' => [
        'public/index.php',
        'public/login.php',
        'public/logout.php',
        'public/admin/architecture_scanner.php',
        'src/bootstrap.php',
        'src/openai.php',
    ],

    /**
     * Exclude scanner files from secret scanning to avoid self-triggered regex false positives.
     */
    'secret_scan_excluded_files' => [
        'public/admin/architecture_scanner.php',
    ],

    'components' => [
        'slide_player' => [
            'label' => 'Slide player',
            'markers' => [
                'public/player',
                'public/player/api',
            ],
        ],
        'ai_narration' => [
            'label' => 'AI narration (OpenAI TTS)',
            'markers' => [
                'public/player',
                'public/student/api',
                'public/admin',
                'src/openai.php',
            ],
        ],
        'progress_tests' => [
            'label' => 'AI-generated progress tests',
            'markers' => [
                'public/student',
                'public/student/api',
            ],
        ],
        'progression_engine_v2' => [
            'label' => 'Training progression engine (v2)',
            'markers' => [
                'src/courseware_progression_v2.php',
                'public/student/api',
                'public/admin',
            ],
        ],
        'remediation_escalation' => [
            'label' => 'Remediation / instructor escalation logic',
            'markers' => [
                'public/instructor',
                'public/admin',
            ],
        ],
        'policy_engine' => [
            'label' => 'Policy engine',
            'markers' => [
                'src/courseware_progression_v2.php',
                'public/admin',
                'public/student/api',
                'public/instructor',
            ],
        ],
        'cohort_scheduling' => [
            'label' => 'Cohort scheduling engine',
            'markers' => [
                'public/admin',
                'public/instructor',
                'public/student',
            ],
        ],
        'slide_designer_canonical' => [
            'label' => 'Slide designer / canonical data system',
            'markers' => [
                'public/admin',
                'public/assets',
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