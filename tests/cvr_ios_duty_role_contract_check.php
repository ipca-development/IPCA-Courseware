<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$models = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift') ?: '';
$catalog = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRCatalogModels.swift') ?: '';
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift') ?: '';
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';
$failures = array();

$expectations = array(
    array($models, 'enum CVRPilotFunction', 'pilot-function enum'),
    array($models, 'case pilotFlying = "PF"', 'PF value'),
    array($models, 'case pilotMonitoring = "PM"', 'PM value'),
    array($models, 'case supervisingInstructor', 'supervising instructor role'),
    array($models, 'case examiner', 'examiner role'),
    array($models, 'case pilotMonitoring = "pilot_monitoring"', 'pilot-monitoring participant role'),
    array($models, 'var pilotFunction: CVRPilotFunction?', 'crew pilot function'),
    array($models, 'var isPIC: Bool?', 'independent PIC responsibility'),
    array($models, 'EXACTLY ONE CUSTOMER / PILOT FLYING REQUIRED', 'fixed Customer/PF validation'),
    array($models, 'ONE OR TWO PILOTS LOGGING PIC REQUIRED', 'dual-PIC validation'),
    array($catalog, 'case pilotFunction = "pilot_function"', 'scheduled pilot function'),
    array($catalog, 'case isPIC = "is_pic"', 'scheduled PIC responsibility'),
    array($store, 'session.engineSessionContinuityActive', 'engine-active crew lock'),
    array($store, 'assignment.effectivePilotFunction.rawValue', 'material signature pilot function'),
    array($views, 'fixedCrewPositionEditor', 'fixed-position crew editor'),
    array($views, 'crewPositionPanel("CUSTOMER")', 'Customer position'),
    array($views, 'crewPositionPanel("PERSON 2 (OPTIONAL)")', 'Person 2 position'),
    array($views, 'crewPositionPanel("PERSON 3 (OPTIONAL)")', 'Person 3 position'),
    array($views, 'frame(maxWidth: .infinity, minHeight: 56', 'full-width cockpit selectors'),
    array($views, 'stepIncrement: max(operationalConfig.oilCapacity / 10', 'oil selector ten-percent increments'),
    array($views, 'Confirm the airplane was refueled before this flight', 'short fuel uplift confirmation'),
    array($views, 'dispatch.refueledSincePreviousFlight = refueledSincePreviousFlight', 'explicit reversible fuel uplift acknowledgment'),
    array($views, 'studentCrewUsers', 'student-only Customer list'),
    array($views, 'operationalCrewUsers', 'non-admin optional-person list'),
    array($views, 'saveFixedCrewPositions', 'approved-combination save path'),
    array($views, 'material role change requires a new reservation', 'duty-boundary guidance'),
);
foreach ($expectations as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ' missing `' . $needle . '`';
    }
}
if (str_contains($views, 'case .crew:' . PHP_EOL . '                        editorSection("CREW")')) {
    $failures[] = 'Crew positions must not be wrapped in the outer editor card';
}
if (str_contains($views, 'fuelGallons >= (operationalConfig.fuelCapacity')) {
    $failures[] = 'A full fuel selector must not force uplift acknowledgment back on';
}

if ($failures !== array()) {
    fwrite(STDERR, "cvr_ios_duty_role_contract_check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "cvr_ios_duty_role_contract_check OK\n");
