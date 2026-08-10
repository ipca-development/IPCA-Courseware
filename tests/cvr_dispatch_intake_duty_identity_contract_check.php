<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$intake = file_get_contents($root . '/src/CvrDispatchIntakeService.php') ?: '';
$identity = file_get_contents($root . '/src/CvrDutyAssignmentIdentityService.php') ?: '';
$upload = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift') ?: '';
$failures = array();

foreach (array(
    'ingestCanonicalDutyIdentity',
    "'reservation_uuid' => \$reservationUuid",
    "'leg_uuid' => \$legUuid",
    "'alias_type' => \$aliasType",
    'assertDispatchMatches',
) as $needle) {
    if (!str_contains($intake, $needle)) {
        $failures[] = 'dispatch intake missing `' . $needle . '`';
    }
}
foreach (array(
    'duty_fingerprint_sha256',
    'primary_customer_identity_key',
    'pilot_function',
    'Duty Assignment snapshot is required before Dispatch',
    'Material Duty Assignment change requires a new reservation',
) as $needle) {
    if (!str_contains($identity, $needle)) {
        $failures[] = 'duty identity service missing `' . $needle . '`';
    }
}
foreach (array(
    '"reservation_uuid"',
    '"leg_uuid"',
    '"pilot_function"',
    '"is_pic"',
    '"is_primary_customer"',
) as $needle) {
    if (!str_contains($upload, $needle)) {
        $failures[] = 'iOS upload missing `' . $needle . '`';
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "cvr_dispatch_intake_duty_identity_contract_check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "cvr_dispatch_intake_duty_identity_contract_check OK\n");
