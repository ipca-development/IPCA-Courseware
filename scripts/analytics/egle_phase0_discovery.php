<?php
declare(strict_types=1);

/**
 * READ-ONLY discovery against the legacy E-gle / Combell training database.
 * Writes JSON artifacts under tmp/analytics/. Does not modify source data.
 */

$outDir = dirname(__DIR__, 2) . '/tmp/analytics';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$host = getenv('EGLE_DB_HOST') ?: '';
$port = (int)(getenv('EGLE_DB_PORT') ?: 3306);
$db   = getenv('EGLE_DB_NAME') ?: '';
$user = getenv('EGLE_DB_USER') ?: '';
$pass = getenv('EGLE_DB_PASS') ?: '';
if ($host === '' || $db === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Set EGLE_DB_HOST, EGLE_DB_NAME, EGLE_DB_USER, EGLE_DB_PASS (read-only).\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
try {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable $e) {
    // ignore if unsupported
}

function save_json(string $path, mixed $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function q(PDO $pdo, string $sql, array $params = []): array
{
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
        throw new RuntimeException('Only read statements allowed: ' . substr($sql, 0, 80));
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
}

function q1(PDO $pdo, string $sql, array $params = []): mixed
{
    $rows = q($pdo, $sql, $params);
    if (!$rows) {
        return null;
    }
    $row = $rows[0];
    return count($row) === 1 ? reset($row) : $row;
}

$meta = q1($pdo, 'SELECT DATABASE() AS db_name, @@hostname AS hostname, @@version AS version');
echo "Connected to {$meta['db_name']} @ {$meta['hostname']} ({$meta['version']})\n";

// --- All tables ---
$tables = q($pdo, "
    SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH,
           CREATE_TIME, UPDATE_TIME, TABLE_COMMENT
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME
");
save_json("$outDir/01_tables.json", $tables);
echo "Tables: " . count($tables) . "\n";

// --- Training-relevant table name filter ---
$relevantPatterns = [
    'users', 'programs', 'programs_users', 'scenarios', 'phases', 'stages', 'exercises',
    'devices', 'logbook', 'contracts', 'contracts_user', 'checklist', 'checklist_user',
    'licenses_users', 'medicals_users', 'ratings_users', 'signatures_users',
    'training_keys', 'training_tools', 'ass_programs', 'ass_users',
    'scenario_tracking_', 'cohorts', 'activity_', 'file_program',
];
$relevant = [];
foreach ($tables as $t) {
    $name = (string)$t['TABLE_NAME'];
    foreach ($relevantPatterns as $p) {
        if ($name === $p || str_starts_with($name, $p) || str_contains($name, $p)) {
            // skip pure QDB / theory question banks for core training map
            if (preg_match('/^(DL_|INSTR_|qdb_)/', $name) && !preg_match('/^(DL_programs|DL_courses)/', $name)) {
                break;
            }
            $relevant[$name] = $t;
            break;
        }
    }
}
// Always include core scenario tracking + curriculum tables even if filtered oddly
foreach ($tables as $t) {
    $name = (string)$t['TABLE_NAME'];
    if (preg_match('/^(scenario_tracking_|scenarios|exercises|phases|stages|programs|users|devices|logbook)/', $name)) {
        $relevant[$name] = $t;
    }
}
ksort($relevant);
save_json("$outDir/02_relevant_tables.json", array_values($relevant));
echo "Relevant candidates: " . count($relevant) . "\n";

// --- Columns + keys for relevant tables ---
$schema = [];
foreach (array_keys($relevant) as $table) {
    $cols = q($pdo, "
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ", [$table]);
    $indexes = q($pdo, "
        SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ORDER BY INDEX_NAME, SEQ_IN_INDEX
    ", [$table]);
    $schema[$table] = ['columns' => $cols, 'indexes' => $indexes];
}
save_json("$outDir/03_relevant_schema.json", $schema);
echo "Schema dump done\n";

// --- Declared FKs (often absent on legacy MyISAM/InnoDB without constraints) ---
$fks = q($pdo, "
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, COLUMN_NAME
");
save_json("$outDir/04_foreign_keys.json", $fks);
echo "Declared FKs: " . count($fks) . "\n";

// --- Core entity counts ---
$counts = [];
$coreCountSql = [
    'users' => 'SELECT COUNT(*) FROM users',
    'users_active' => "SELECT COUNT(*) FROM users WHERE actief = 1 OR actief = '1' OR actief = 'Y' OR actief = 'y'",
    'programs' => 'SELECT COUNT(*) FROM programs',
    'programs_users' => 'SELECT COUNT(*) FROM programs_users',
    'scenarios' => 'SELECT COUNT(*) FROM scenarios',
    'phases' => 'SELECT COUNT(*) FROM phases',
    'stages' => 'SELECT COUNT(*) FROM stages',
    'exercises' => 'SELECT COUNT(*) FROM exercises',
    'devices' => 'SELECT COUNT(*) FROM devices',
    'logbook' => 'SELECT COUNT(*) FROM logbook',
];
foreach ($coreCountSql as $k => $sql) {
    try {
        $counts[$k] = (int)q1($pdo, $sql);
    } catch (Throwable $e) {
        $counts[$k] = ['error' => $e->getMessage()];
    }
}

// Scenario tracking tables
$trackingTables = [];
foreach ($tables as $t) {
    $name = (string)$t['TABLE_NAME'];
    if (str_starts_with($name, 'scenario_tracking_')) {
        try {
            $n = (int)q1($pdo, "SELECT COUNT(*) FROM `{$name}`");
            $trackingTables[$name] = $n;
        } catch (Throwable $e) {
            $trackingTables[$name] = ['error' => $e->getMessage()];
        }
    }
}
$counts['scenario_tracking_tables'] = $trackingTables;
$counts['scenario_tracking_rows_total'] = array_sum(array_filter($trackingTables, 'is_int'));
save_json("$outDir/05_counts.json", $counts);
echo "Counts done. Tracking rows total={$counts['scenario_tracking_rows_total']}\n";

// --- Sample columns for key tables ---
$keyTables = ['users', 'programs', 'programs_users', 'scenarios', 'phases', 'stages', 'exercises', 'devices', 'logbook'];
$samples = [];
foreach ($keyTables as $table) {
    try {
        $samples[$table] = q($pdo, "SELECT * FROM `{$table}` LIMIT 3");
    } catch (Throwable $e) {
        $samples[$table] = ['error' => $e->getMessage()];
    }
}
// One sample from largest tracking tables
foreach (['scenario_tracking_PPLA', 'scenario_tracking_IR', 'scenario_tracking_EASAACP', 'scenario_tracking_PPL', 'scenario_tracking_FAAACP'] as $tt) {
    try {
        $samples[$tt] = q($pdo, "SELECT * FROM `{$tt}` LIMIT 2");
    } catch (Throwable $e) {
        $samples[$tt] = ['error' => $e->getMessage()];
    }
}
save_json("$outDir/06_samples.json", $samples);
echo "Samples done\n";

// --- Value distributions for grading / status fields ---
$distributions = [];

// users role/active fields — discover columns first
$userCols = array_column($schema['users']['columns'] ?? [], 'COLUMN_NAME');
foreach (['actief', 'type', 'user_type', 'rol', 'role', 'functie', 'instructor', 'student', 'level'] as $c) {
    if (in_array($c, $userCols, true)) {
        $distributions["users.$c"] = q($pdo, "SELECT `$c` AS value, COUNT(*) AS n FROM users GROUP BY `$c` ORDER BY n DESC LIMIT 50");
    }
}

$programCols = array_column($schema['programs']['columns'] ?? [], 'COLUMN_NAME');
foreach (['prog_active', 'prog_type', 'prog_name', 'prog_authority', 'prog_sort', 'active', 'name'] as $c) {
    if (in_array($c, $programCols, true)) {
        $distributions["programs.$c"] = q($pdo, "SELECT `$c` AS value, COUNT(*) AS n FROM programs GROUP BY `$c` ORDER BY n DESC LIMIT 50");
    }
}

$scenarioCols = array_column($schema['scenarios']['columns'] ?? [], 'COLUMN_NAME');
foreach (['sc_program', 'sc_phase', 'sc_stage', 'sc_type', 'sc_active', 'sc_name', 'sc_mission', 'sc_sort'] as $c) {
    if (in_array($c, $scenarioCols, true)) {
        $distributions["scenarios.$c"] = q($pdo, "SELECT `$c` AS value, COUNT(*) AS n FROM scenarios GROUP BY `$c` ORDER BY n DESC LIMIT 80");
    }
}

$exerciseCols = array_column($schema['exercises']['columns'] ?? [], 'COLUMN_NAME');
foreach (['ex_program', 'ex_scenario', 'ex_required', 'ex_level', 'ex_type', 'ex_name', 'ex_sort', 'required', 'level'] as $c) {
    if (in_array($c, $exerciseCols, true)) {
        $distributions["exercises.$c"] = q($pdo, "SELECT `$c` AS value, COUNT(*) AS n FROM exercises GROUP BY `$c` ORDER BY n DESC LIMIT 80");
    }
}

$deviceCols = array_column($schema['devices']['columns'] ?? [], 'COLUMN_NAME');
foreach (['dev_type', 'dev_name', 'dev_active', 'dev_sort', 'type'] as $c) {
    if (in_array($c, $deviceCols, true)) {
        $distributions["devices.$c"] = q($pdo, "SELECT `$c` AS value, COUNT(*) AS n FROM devices GROUP BY `$c` ORDER BY n DESC LIMIT 50");
    }
}

// Infer grade-like columns from a tracking table
$trackCols = array_column($schema['scenario_tracking_PPLA']['columns'] ?? [], 'COLUMN_NAME');
$gradeLike = [];
foreach ($trackCols as $c) {
    if (preg_match('/(grade|level|result|score|pass|status|comment|remark|obs|debrief|note|eval|required|achieved|competenc)/i', $c)) {
        $gradeLike[] = $c;
    }
}
$distributions['scenario_tracking_PPLA.grade_like_columns'] = $gradeLike;
foreach (array_slice($gradeLike, 0, 25) as $c) {
    try {
        $distributions["scenario_tracking_PPLA.$c"] = q($pdo, "
            SELECT `$c` AS value, COUNT(*) AS n
            FROM scenario_tracking_PPLA
            GROUP BY `$c`
            ORDER BY n DESC
            LIMIT 40
        ");
    } catch (Throwable $e) {
        $distributions["scenario_tracking_PPLA.$c"] = ['error' => $e->getMessage()];
    }
}

save_json("$outDir/07_distributions.json", $distributions);
echo "Distributions done\n";

// --- Date ranges ---
$dateRanges = [];
foreach (['scenario_tracking_PPLA', 'scenario_tracking_IR', 'scenario_tracking_EASAACP', 'scenario_tracking_FAAACP', 'scenario_tracking_PPL', 'logbook'] as $tt) {
    $cols = array_column($schema[$tt]['columns'] ?? q($pdo, "
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ", [$tt]), 'COLUMN_NAME');
    if (is_array($cols) && isset($cols[0]) && is_array($cols[0])) {
        $cols = array_column($cols, 'COLUMN_NAME');
    }
    $dateCols = [];
    foreach ($cols as $c) {
        if (preg_match('/(date|time|datum|created|updated|start|end)/i', (string)$c)) {
            $dateCols[] = $c;
        }
    }
    $dateRanges[$tt] = ['date_like_columns' => $dateCols];
    foreach (array_slice($dateCols, 0, 8) as $c) {
        try {
            $dateRanges[$tt][$c] = q1($pdo, "
                SELECT MIN(`$c`) AS min_v, MAX(`$c`) AS max_v,
                       SUM(CASE WHEN `$c` IS NULL OR `$c` = '' OR `$c` = '0000-00-00' OR `$c` = '0000-00-00 00:00:00' THEN 1 ELSE 0 END) AS nullish,
                       COUNT(*) AS total
                FROM `$tt`
            ");
        } catch (Throwable $e) {
            $dateRanges[$tt][$c] = ['error' => $e->getMessage()];
        }
    }
}
save_json("$outDir/08_date_ranges.json", $dateRanges);
echo "Date ranges done\n";

// --- Full column list for ALL tables (compact) for appendix ---
$allCols = q($pdo, "
    SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME, ORDINAL_POSITION
");
save_json("$outDir/09_all_columns_compact.json", $allCols);
echo "All columns: " . count($allCols) . "\n";

echo "DONE\n";
