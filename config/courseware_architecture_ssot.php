<?php
declare(strict_types=1);

/**
 * IPCA Courseware - SSOT Architecture Definition
 *
 * This file is the architecture scanner's local SSOT reference.
 * Keep this aligned with the IPCA Courseware SSOT Master Context Pack.
 */

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

    /**
     * Directories that should exist in the repository.
     * Adjust only if your actual structure differs.
     */
    'required_directories' => [
        'config',
        'public',
        'src',
        'storage',
    ],

    /**
     * Directories ignored by the scanner.
     */
    'ignored_directories' => [
        '.git',
        '.github',
        'vendor',
        'node_modules',
        'storage/cache',
        'storage/logs',
        'storage/tmp',
        'public/uploads',
        'public/media',
    ],

    /**
     * File patterns that should never normally be committed.
     */
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
    ],

    /**
     * Large files in repo that likely do not belong in Git.
     * 15 MB default threshold.
     */
    'max_repo_file_size_bytes' => 15 * 1024 * 1024,

    /**
     * Static architecture markers by component.
     * Each component passes when at least one marker is found.
     * Add/remove markers to match your actual repository.
     */
    'components' => [
        'slide_player' => [
            'label' => 'Slide player',
            'markers' => [
                'public/slide_player.php',
                'public/player.php',
                'src/SlidePlayer',
                'src/Player',
            ],
        ],
        'ai_narration' => [
            'label' => 'AI narration (OpenAI TTS)',
            'markers' => [
                'src/AI/TTS',
                'src/OpenAI/TTS',
                'src/Services/TTS',
                'src/Services/Narration',
                'public/admin/tts_generate.php',
            ],
        ],
        'progress_tests' => [
            'label' => 'AI-generated progress tests',
            'markers' => [
                'src/ProgressTests',
                'src/Tests/Progress',
                'src/Services/ProgressTest',
                'public/admin/progress_tests.php',
            ],
        ],
        'progression_engine_v2' => [
            'label' => 'Training progression engine (v2)',
            'markers' => [
                'src/Progression/V2',
                'src/Training/ProgressionV2',
                'src/Services/ProgressionEngineV2',
            ],
        ],
        'remediation_escalation' => [
            'label' => 'Remediation / instructor escalation logic',
            'markers' => [
                'src/Remediation',
                'src/Escalation',
                'src/Services/InstructorEscalation',
                'src/Services/Remediation',
            ],
        ],
        'policy_engine' => [
            'label' => 'Policy engine',
            'markers' => [
                'src/Policy',
                'src/PolicyEngine',
                'src/Services/PolicyEngine',
                'config/policies.php',
            ],
        ],
        'cohort_scheduling' => [
            'label' => 'Cohort scheduling engine',
            'markers' => [
                'src/Scheduling',
                'src/Cohorts',
                'src/Services/CohortScheduling',
                'src/Services/Schedule',
            ],
        ],
        'slide_designer_canonical' => [
            'label' => 'Slide designer / canonical data system',
            'markers' => [
                'src/Designer',
                'src/Canonical',
                'src/Services/SlideDesigner',
                'src/Services/CanonicalData',
                'public/admin/slide_designer.php',
            ],
        ],
    ],

    /**
     * Files that are strong anchors of the application.
     * Missing these should be treated as critical.
     * Adjust to your actual structure.
     */
    'required_files' => [
        'config/courseware_architecture_ssot.php',
    ],

    /**
     * Regex patterns used to detect likely secrets committed into code.
     */
    'secret_patterns' => [
        'OpenAI API key' => '/sk-[A-Za-z0-9]{20,}/',
        'Postmark server token' => '/POSTMARK(_SERVER)?_TOKEN\s*[\'"]?\s*=>?\s*[\'"][A-Za-z0-9\-_]{20,}[\'"]/i',
        'AWS / Spaces access key assignment' => '/(AWS|SPACES)_(ACCESS_KEY|SECRET_KEY)\s*[\'"]?\s*=>?\s*[\'"][^\'"]{12,}[\'"]/i',
        'Generic bearer token' => '/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i',
    ],

    /**
     * File extensions that should be scanned for code/content.
     */
    'scannable_extensions' => [
        'php', 'inc', 'phtml', 'html', 'js', 'ts', 'json', 'yml', 'yaml', 'md', 'txt'
    ],
];