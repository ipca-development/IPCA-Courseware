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
    ],

    'required_directories' => [
        'public',
        'src',
        'vendor',
    ],

    'ignored_directories' => [
        '.git',
        '.github',
        'vendor',
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
    ],

    'max_repo_file_size_bytes' => 15 * 1024 * 1024,

    'components' => [
        'slide_player' => [
            'label' => 'Slide player',
            'markers' => [
                'public',
                'src',
            ],
        ],
        'ai_narration' => [
            'label' => 'AI narration (OpenAI TTS)',
            'markers' => [
                'src',
            ],
        ],
        'progress_tests' => [
            'label' => 'AI-generated progress tests',
            'markers' => [
                'src',
            ],
        ],
        'progression_engine_v2' => [
            'label' => 'Training progression engine (v2)',
            'markers' => [
                'src',
            ],
        ],
        'remediation_escalation' => [
            'label' => 'Remediation / instructor escalation logic',
            'markers' => [
                'src',
            ],
        ],
        'policy_engine' => [
            'label' => 'Policy engine',
            'markers' => [
                'src',
            ],
        ],
        'cohort_scheduling' => [
            'label' => 'Cohort scheduling engine',
            'markers' => [
                'src',
            ],
        ],
        'slide_designer_canonical' => [
            'label' => 'Slide designer / canonical data system',
            'markers' => [
                'src',
            ],
        ],
    ],

    'required_files' => [
        'composer.json',
        'composer.lock',
        'Dockerfile',
        '.htaccess',
        'src/courseware_architecture_ssot.php',
        'src/Services/ArchitectureScanner.php',
        'public/admin/architecture_scanner.php',
    ],

    'secret_patterns' => [
        'OpenAI API key' => '/sk-[A-Za-z0-9]{20,}/',
        'Postmark token' => '/postmark[a-z0-9\-_]{10,}/i',
        'AWS / Spaces key assignment' => '/(AWS|SPACES)_(ACCESS_KEY|SECRET_KEY)/i',
        'Generic bearer token' => '/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i',
    ],

    'scannable_extensions' => [
        'php', 'inc', 'phtml', 'html', 'js', 'ts', 'json', 'yml', 'yaml', 'md', 'txt'
    ],
];