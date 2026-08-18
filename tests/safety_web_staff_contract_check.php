<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$staff = file_get_contents($root . '/src/safety/SafetyStaffService.php') ?: '';
$api = file_get_contents($root . '/public/admin/api/safety.php') ?: '';
$adminUi = file_get_contents($root . '/public/admin/safety/index.php') ?: '';
$studentUi = file_get_contents($root . '/public/student/safety.php') ?: '';
$adminNav = file_get_contents($root . '/src/nav/admin.php') ?: '';
$studentNav = file_get_contents($root . '/src/nav/student.php') ?: '';
$monitor = file_get_contents($root . '/src/compliance/ComplianceMonitorView.php') ?: '';
$push = file_get_contents($root . '/src/communication/CommunicationPushService.php') ?: '';
$domain = file_get_contents($root . '/src/safety/SafetyDomainServices.php') ?: '';

$checks = array(
    'staff API requires admin web authentication' =>
        str_contains($api, 'cw_require_admin()')
        && str_contains($api, "Cache-Control: no-store, private"),
    'staff mutations require session CSRF' =>
        str_contains($api, "\$_SESSION['safety_staff_csrf']")
        && str_contains($api, 'hash_equals($expected, $provided)'),
    'staff API delegates operations through safety domain services' =>
        str_contains($api, '$kernel->workflow->transition')
        && str_contains($api, '$kernel->reportability->assess')
        && str_contains($api, '$kernel->risk->snapshotRisk')
        && str_contains($api, '$kernel->investigations->complete')
        && str_contains($api, '$kernel->actions->reviewEffectiveness')
        && str_contains($api, '$kernel->feedback->send'),
    'staff read model scopes report projections to organization' =>
        substr_count($staff, 'organization_id = ?') >= 20
        && str_contains($staff, 'SafetySupport::organizationId($session)'),
    'general staff projections never access reporter vault' =>
        !str_contains($staff, 'ipca_safety_reporter_vault')
        && !str_contains($api, 'ipca_safety_reporter_vault')
        && !str_contains($adminUi, 'ipca_safety_reporter_vault'),
    'staff projections do not expose reporter linkage columns' =>
        !str_contains($staff, 'reporter_user_id')
        && !str_contains($staff, 'anonymous_mailbox_id'),
    'student history uses own-report service boundary' =>
        str_contains($studentUi, '$kernel->intake->listOwn($session)')
        && str_contains($studentUi, '$kernel->intake->detailOwn($session')
        && str_contains($studentUi, '$kernel->intake->postReporterUpdate'),
    'student interface preview cannot impersonate reporter' =>
        str_contains($studentUi, "!== 'student'")
        && str_contains($studentUi, 'cannot be opened in interface preview mode'),
    'admin and student navigation expose safety workspaces' =>
        str_contains($adminNav, '/admin/safety/index.php')
        && str_contains($studentNav, '/student/safety.php'),
    'safety and compliance workspaces cross-link' =>
        str_contains($adminUi, '/admin/compliance/safety_monitoring.php')
        && str_contains($monitor, '/admin/safety/index.php'),
    'identified reporter feedback uses privacy-minimized safety push' =>
        str_contains($push, 'notifySafetyUpdate(')
        && str_contains($push, "'safety_report_uuid'")
        && str_contains($push, "'report_digest'")
        && str_contains($domain, 'notifySafetyUpdate(')
        && str_contains($domain, "confidentiality'] === 'standard'"),
    'staff workspace covers required operational registers' =>
        str_contains($adminUi, 'Occurrence & reportability')
        && str_contains($adminUi, 'Hazards & risk')
        && str_contains($adminUi, 'Investigations')
        && str_contains($adminUi, 'Actions & effectiveness')
        && str_contains($adminUi, 'Reporter feedback')
        && str_contains($adminUi, 'Safety bulletins'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed safety web/staff checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: safety web and staff API contract checks passed.' . PHP_EOL;
