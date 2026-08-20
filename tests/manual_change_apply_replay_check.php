<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/BooksManualsChangePlanService.php';
require_once dirname(__DIR__) . '/src/publishing/BooksManualsChangeApplyService.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_plans (
        id INTEGER PRIMARY KEY,
        owner_id INTEGER NOT NULL,
        stage TEXT NOT NULL,
        primary_manual_version_id INTEGER NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_publishing_book_versions (
        id INTEGER PRIMARY KEY,
        lifecycle_status TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE ipca_manual_ai_architect_operations (
        id INTEGER PRIMARY KEY,
        plan_id INTEGER NOT NULL,
        operation_type TEXT NOT NULL,
        status TEXT NOT NULL,
        result_json TEXT
    )'
);
$db->exec(
    "INSERT INTO ipca_manual_ai_architect_plans
     (id,owner_id,stage,primary_manual_version_id)
     VALUES (20,7,'operations',44)"
);
// A replay must remain idempotent even if the version later advances beyond
// the lifecycle in which the original application was allowed.
$db->exec(
    "INSERT INTO ipca_publishing_book_versions (id,lifecycle_status)
     VALUES (44,'released')"
);
$stored = array(
    'schema' => 'ipca.manual-change-in-place-result.v1',
    'plan_id' => 20,
    'operation_id' => 91,
    'book_version_id' => 44,
    'affected_section_numbers' => array('5.6'),
);
$stmt = $db->prepare(
    "INSERT INTO ipca_manual_ai_architect_operations
     (id,plan_id,operation_type,status,result_json)
     VALUES (91,20,'apply_accepted_wizard_changes','succeeded',?)"
);
$stmt->execute(array(json_encode($stored, JSON_THROW_ON_ERROR)));

$service = new BooksManualsChangeApplyService(
    $db,
    new BooksManualsChangePlanService($db)
);
$replayed = $service->apply(20, 7);
if ((int)($replayed['operation_id'] ?? 0) !== 91
    || (int)($replayed['book_version_id'] ?? 0) !== 44
    || !array_key_exists('derived_refresh_warnings', $replayed)) {
    fwrite(STDERR, "FAIL: Successful Wizard application replay was not authoritative.\n");
    exit(1);
}

echo "PASS: Successful Wizard application replay is idempotent before mutable preflight.\n";
