<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentRole = trim((string)($currentUser['role'] ?? ''));

$allowedRoles = array('admin', 'supervisor', 'instructor', 'chief_instructor');
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

http_response_code(410);
cw_header('Cohort Progress');
?>
<div style="padding:24px;max-width:720px;">
    <p style="font-size:15px;line-height:1.6;color:#334155;">Cohort Progress has been retired. Use <strong>Theory Control Center</strong> for cohort theory visibility and interventions.</p>
    <p style="margin-top:16px;"><a href="/instructor/theory_control_center.php" style="font-weight:800;color:#12355f;">Open Theory Control Center</a></p>
</div>
<?php
cw_footer();
