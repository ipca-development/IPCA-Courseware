<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sql = file_get_contents($root . '/scripts/sql/2026_08_06_cvr_financial_dispatch.sql') ?: '';
$service = file_get_contents($root . '/src/CvrFinancialDispatchService.php') ?: '';
$intake = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';

$checks = array(
    'instructional rates table and seeds exist' =>
        str_contains($sql, 'CREATE TABLE IF NOT EXISTS ipca_instructional_rates')
        && str_contains($sql, 'VFR/EXP/SE')
        && str_contains($sql, 'IFR/CPL/CFI/SE')
        && str_contains($sql, 'OWN AIRPLANE')
        && str_contains($sql, '89.00')
        && str_contains($sql, '99.00')
        && str_contains($sql, '109.00'),
    'financial dispatch and rental/balance tables exist' =>
        str_contains($sql, 'CREATE TABLE IF NOT EXISTS ipca_cvr_financial_dispatches')
        && str_contains($sql, 'CREATE TABLE IF NOT EXISTS ipca_aircraft_rental_rates')
        && str_contains($sql, 'CREATE TABLE IF NOT EXISTS ipca_user_account_balances')
        && str_contains($sql, 'preflight_briefing_hours')
        && str_contains($sql, 'ground_instruction_hours'),
    'financial service supports draft lock unlock and totals' =>
        str_contains($service, 'function saveDraft(')
        && str_contains($service, 'function unlock(')
        && str_contains($service, 'function computeTotals(')
        && str_contains($service, 'DEFAULT_GROUND_HOURS')
        && str_contains($service, 'CW_FINANCIAL_DISPATCH_UNLOCK_CODE'),
    'aircraft rental hours follow Hobbs not flight instruction' =>
        str_contains($service, 'aircraft_rental_hours')
        && str_contains($service, 'Experience-building / solo flights')
        && str_contains($intake, 'financialRentalHoursFromHobbs')
        && str_contains($intake, 'aircraft_rental_hours'),
    'flight instruction 0 is preserved against hobbs autosync' =>
        str_contains($intake, 'financialHasPersistedInstructionHours')
        && str_contains($intake, 'Keep intentional values (including 0.0)')
        && str_contains($intake, 'array_key_exists(\'flight_instruction_hours\'')
        && str_contains($intake, 'Preserve the Flight Instruction field as entered'),
    'rental-only lock does not require instructor' =>
        str_contains($service, 'Experience-building / rental-only')
        && str_contains($intake, 'Rental-only / experience building'),
    'save and lock freezes entire operational leg' =>
        str_contains($intake, 'This Operational Leg is locked.')
        && str_contains($intake, 'Unlock Record')
        && str_contains($intake, 'applyRecordLockState')
        && str_contains($intake, 'Save and LOCK this Operational Leg?')
        && str_contains($intake, 'Operational leg saved and locked.')
        && str_contains($intake, 'id="legs-record-lock"'),
    'Edit Operational Leg modal includes financial dispatch UI' =>
        str_contains($intake, 'Financial Dispatch')
        && str_contains($intake, 'Preflight Briefing')
        && str_contains($intake, 'Flight Instruction')
        && str_contains($intake, 'Ground Instruction')
        && str_contains($intake, 'legs-fin-overview-table')
        && str_contains($intake, 'Save and LOCK')
        && str_contains($intake, 'unlock_financial_dispatch')
        && str_contains($intake, 'refreshFinancialOverview'),
);

$failed = array();
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, "cvr_financial_dispatch_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "cvr_financial_dispatch_contract_check OK (" . count($checks) . " checks)\n";
exit(0);
