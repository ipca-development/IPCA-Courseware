<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/sql/2026_07_28_cvr_dispatch_intake.sql') ?: '';
$endpoint = file_get_contents($root . '/public/api/cvr/dispatch_sync.php') ?: '';
$reader = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';
$page = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';

$checks = array(
    'migration creates Dispatch projection' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_cvr_dispatches'),
    'migration preserves immutable versions' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_cvr_dispatch_versions'),
    'migration preserves independent consent evidence' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_cvr_dispatch_consents'),
    'Dispatch UUID is idempotent' => str_contains($migration, 'UNIQUE KEY uk_ipca_cvr_dispatches_uuid'),
    'Dispatch version is idempotent' => str_contains($migration, 'UNIQUE KEY uk_ipca_cvr_dispatch_versions_version'),
    'migration contains no destructive table operation' => preg_match('/\b(?:DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i', $migration) !== 1,
    'endpoint requires device authentication' => str_contains($endpoint, 'DeviceAuthService($pdo))->requireDevice()'),
    'endpoint delegates to Dispatch intake service' => str_contains($endpoint, 'CvrDispatchIntakeService($pdo))->receive('),
    'reader prioritizes canonical Dispatch table' => str_contains($reader, "array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_records')"),
    'admin page exposes Dispatch tab' => str_contains($page, 'data-intake-tab="dispatch"'),
    'admin page exposes Cockpit Audio tab' => str_contains($page, 'data-intake-tab="audio"'),
    'admin page exposes Garmin CSV tab' => str_contains($page, 'data-intake-tab="garmin"'),
);

$failures = array();
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}
if ($failures !== array()) {
    fwrite(STDERR, 'Failed CVR Dispatch migration checks: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: CVR Dispatch migration and wiring checks passed.' . PHP_EOL;
