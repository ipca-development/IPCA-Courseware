<?php
declare(strict_types=1);

/**
 * Apply mock oral SQL migrations (idempotent).
 *
 * Usage: php scripts/apply_mock_oral_migrations.php [--user=14] [--cohort=5]
 */

$root = dirname(__DIR__);

$loadDotenv = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        if (getenv($m[1]) !== false) {
            continue;
        }
        $val = $m[2];
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        putenv($m[1] . '=' . $val);
    }
};

if (!getenv('CW_DB_HOST')) {
    $loadDotenv($root . '/.env');
}

require_once $root . '/src/db.php';
require_once $root . '/src/mock_oral/mock_oral_bootstrap.php';

$opts = getopt('', ['user::', 'cohort::']);
$seedUserId = (int)($opts['user'] ?? 0);
$seedCohortId = (int)($opts['cohort'] ?? 0);

$migrations = [
    'schema' => $root . '/scripts/sql/2026_05_28_mock_oral_schema.sql',
    'acs_seed' => $root . '/scripts/sql/2026_05_28_mock_oral_acs_seed.sql',
    'ai_prompts' => $root . '/scripts/sql/2026_05_28_mock_oral_ai_prompts.sql',
    'auth_email' => $root . '/scripts/sql/2026_05_29_mock_oral_auth_email_fix.sql',
];

function apply_sql_file(PDO $pdo, string $label, string $path): void
{
    if (!is_readable($path)) {
        throw new RuntimeException("Missing migration: {$path}");
    }

    $sql = (string)file_get_contents($path);
    echo "Applying {$label}...\n";

    // Use mysqli multi_query for SET @ / PREPARE blocks (PDO cannot reliably run these).
    $host = getenv('CW_DB_HOST');
    $port = getenv('CW_DB_PORT') ?: '25060';
    $db = getenv('CW_DB_NAME');
    $user = getenv('CW_DB_USER');
    $pass = getenv('CW_DB_PASS');

    $mysqli = mysqli_init();
    if (!$mysqli) {
        throw new RuntimeException('mysqli_init failed');
    }
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 20);
    if (!@$mysqli->real_connect($host, $user, $pass, $db, (int)$port)) {
        throw new RuntimeException('mysqli connect failed: ' . mysqli_connect_error());
    }
    $mysqli->set_charset('utf8mb4');

    if (!$mysqli->multi_query($sql)) {
        throw new RuntimeException("{$label} failed: " . $mysqli->error);
    }

    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
        if ($mysqli->errno) {
            throw new RuntimeException("{$label} failed: " . $mysqli->error);
        }
    } while ($mysqli->more_results() && $mysqli->next_result());

    $mysqli->close();
    echo "  OK\n";
}

try {
    $pdo = cw_db();
    echo 'Connected to ' . getenv('CW_DB_NAME') . ' @ ' . getenv('CW_DB_HOST') . "\n\n";

    foreach ($migrations as $label => $path) {
        apply_sql_file($pdo, $label, $path);
    }

    // Runtime bootstrap ensures IPCA-branded template + live version (matches progress test pattern).
    mo_ensure_remote_email_automation($pdo);
    echo "Runtime email automation bootstrap... OK\n";

    $checks = [
        ['Mock oral ACS areas', "SELECT COUNT(*) FROM mock_oral_acs_areas"],
        ['Mock oral AI prompts', "SELECT COUNT(*) FROM ai_prompts WHERE prompt_key LIKE 'mock_oral_%'"],
        ['Auth template', "SELECT id FROM notification_templates WHERE notification_key = 'mock_oral_auth_request' AND channel = 'email' LIMIT 1"],
        ['Auth template versions', "SELECT COUNT(*) FROM notification_template_versions ntv INNER JOIN notification_templates nt ON nt.id = ntv.notification_template_id WHERE nt.notification_key = 'mock_oral_auth_request'"],
        ['Automation flow', "SELECT id FROM automation_flows WHERE event_key = 'mock_oral_auth_requested' LIMIT 1"],
        ['send_email action', "SELECT COUNT(*) FROM automation_flow_actions afa INNER JOIN automation_flows af ON af.id = afa.flow_id WHERE af.event_key = 'mock_oral_auth_requested' AND afa.action_key = 'send_email'"],
    ];

    echo "\nVerification:\n";
    foreach ($checks as [$label, $sql]) {
        $val = $pdo->query($sql)->fetchColumn();
        echo "  {$label}: {$val}\n";
    }

    if ($seedUserId > 0 && $seedCohortId > 0) {
        echo "\nSeeding test user {$seedUserId} cohort {$seedCohortId}...\n";
        passthru(sprintf(
            'php %s --user=%d --cohort=%d --apply 2>&1',
            escapeshellarg($root . '/scripts/seed_mock_oral_test_user.php'),
            $seedUserId,
            $seedCohortId
        ), $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('Test user seed failed with exit code ' . $exitCode);
        }
    }

    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
