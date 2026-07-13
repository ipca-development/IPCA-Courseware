<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$checkDbOnly = in_array('--check-db-capabilities', $argv, true);
$failures = array();

$capabilities = array(
    'version' => (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
    'engine' => (string)$pdo->query("SELECT @@default_storage_engine")->fetchColumn(),
    'tx_isolation' => (string)$pdo->query("SELECT @@transaction_isolation")->fetchColumn(),
    'sql_mode' => (string)$pdo->query("SELECT @@sql_mode")->fetchColumn(),
    'json_valid' => (string)$pdo->query("SELECT JSON_VALID('{}')")->fetchColumn(),
);
try {
    $pdo->exec('CREATE TEMPORARY TABLE ipca_phase1_dt_check (dt DATETIME(3) NULL) ENGINE=InnoDB');
    $capabilities['datetime3'] = 'ok';
} catch (Throwable $e) {
    $failures[] = 'DATETIME(3) capability failed: ' . $e->getMessage();
}
try {
    $pdo->beginTransaction();
    $pdo->query('SELECT 1 FOR UPDATE');
    $pdo->commit();
    $capabilities['for_update'] = 'ok';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures[] = 'FOR UPDATE capability failed: ' . $e->getMessage();
}

echo "Database capabilities:\n";
foreach ($capabilities as $key => $value) {
    echo "- {$key}: {$value}\n";
}

if (!$checkDbOnly) {
    $requiredTables = array(
        'ipca_audit_events',
        'ipca_validation_results',
        'ipca_cvr_devices',
        'ipca_cvr_device_credentials',
        'ipca_cvr_device_enrollments',
        'ipca_flight_sessions',
        'ipca_garmin_csv_upload_requests',
        'ipca_garmin_csv_files',
        'ipca_garmin_csv_fingerprints',
        'ipca_garmin_csv_validation_results',
        'ipca_garmin_csv_session_matches',
        'ipca_operational_flight_records',
        'ipca_operational_flight_record_versions',
        'ipca_operational_flight_leg_versions',
        'ipca_logbook_proposal_groups',
        'ipca_flight_record_logbook_proposals',
        'ipca_accepted_logbook_proposal_links',
        'ipca_async_jobs',
    );
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute(array($table));
        if ((int)$stmt->fetchColumn() !== 1) {
            $failures[] = "Missing Phase 1 table: {$table}";
        }
    }

    $protectedEndpoints = array(
        __DIR__ . '/../public/api/recordings/upload_chunk.php',
        __DIR__ . '/../public/api/recordings/upload_finalize.php',
        __DIR__ . '/../public/api/recordings/status.php',
        __DIR__ . '/../public/api/recordings/transcript.php',
        __DIR__ . '/../public/api/recordings/replay.php',
    );
    foreach ($protectedEndpoints as $path) {
        if (!is_file($path)) {
            $failures[] = 'Protected endpoint missing: ' . basename($path);
        }
    }
}

if ($failures) {
    echo "\nFAIL:\n";
    foreach ($failures as $failure) {
        echo "- {$failure}\n";
    }
    exit(1);
}

echo "\nOK: Phase 1 regression checks passed.\n";
