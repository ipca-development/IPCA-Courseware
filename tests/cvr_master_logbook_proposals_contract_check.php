<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = array();

function require_contains(string $haystack, string $needle, string $label, array &$failures): void
{
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ': missing `' . $needle . '`';
    }
}

$sql = file_get_contents($root . '/scripts/sql/2026_08_08_cvr_master_logbook_proposals.sql') ?: '';
$generator = file_get_contents($root . '/src/MasterLogbookLogbookProposalService.php') ?: '';
$accept = file_get_contents($root . '/src/CvrLogbookProposalAcceptService.php') ?: '';
$intake = file_get_contents($root . '/src/CvrWorkflowEvidenceIntakeService.php') ?: '';
$intakeRead = file_get_contents($root . '/src/CvrDataIntakeReadService.php') ?: '';
$correction = file_get_contents($root . '/src/CvrAdminLegCorrectionService.php') ?: '';
$studentUi = file_get_contents($root . '/public/student/flight_records.php') ?: '';
$instructorUi = file_get_contents($root . '/public/instructor/flight_records.php') ?: '';
$instructorLogbook = file_get_contents($root . '/public/instructor/logbook.php') ?: '';
$adminUi = file_get_contents($root . '/public/admin/cvr_logbook_proposals.php') ?: '';
$instructorNav = file_get_contents($root . '/src/nav/instructor.php') ?: '';
$adminNav = file_get_contents($root . '/src/nav/admin.php') ?: '';

require_contains($sql, 'CREATE TABLE IF NOT EXISTS ipca_cvr_logbook_proposals', 'migration creates proposals table', $failures);
require_contains($sql, 'uk_ipca_cvr_logbook_proposals_owner_entry', 'unique dispatch+owner+entry_type', $failures);
require_contains($generator, 'createProposalsForFlightRecord', 'generator API', $failures);
require_contains($generator, 'student_dual', 'student entry type', $failures);
require_contains($generator, 'instructor', 'instructor entry type', $failures);
require_contains($generator, 'ipca_cvr_logbook_hidden_legs', 'skips soft-hidden legs', $failures);
require_contains($accept, 'AdminLogbookService', 'accept writes official logbook', $failures);
require_contains($accept, 'getOrCreateLogbook', 'accept creates owner logbook', $failures);
require_contains($accept, 'Only the proposal owner', 'owner-gated accept', $failures);
require_contains($intake, 'createLogbookProposalsSafely', 'closure intake hooks generator', $failures);
require_contains($intake, 'MasterLogbookLogbookProposalService', 'intake uses generator', $failures);
require_contains(
    $intake,
    "if (\$normalized['component_type'] === 'flight_record_closure')",
    'duplicate closure retry repairs missing proposals',
    $failures
);
require_contains($intakeRead, "return 'session:' . \$operationalSession", 'operational sessions remain separate Master Logbook rows', $failures);
require_contains($intakeRead, 'fc.received_at', 'closure receipt supplies off-block date filter fallback', $failures);
require_contains($correction, 'createProposalsForFlightRecord', 'admin correction refreshes proposals', $failures);
require_contains($studentUi, 'accept_cvr_proposal', 'student accept action', $failures);
require_contains($studentUi, 'Accept', 'student accept button', $failures);
require_contains($instructorUi, 'accept_cvr_proposal', 'instructor accept action', $failures);
require_contains($instructorLogbook, 'My Logbook', 'instructor logbook page', $failures);
require_contains($instructorNav, '/instructor/logbook.php', 'instructor nav my logbook', $failures);
require_contains($adminUi, 'accept_cvr_proposal', 'admin override accept', $failures);
require_contains($adminNav, '/admin/cvr_logbook_proposals.php', 'admin nav includes cvr proposals', $failures);

if ($failures !== array()) {
    fwrite(STDERR, "cvr_master_logbook_proposals_contract_check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "cvr_master_logbook_proposals_contract_check OK\n";
exit(0);
