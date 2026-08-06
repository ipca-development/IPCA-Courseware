<?php
declare(strict_types=1);

/**
 * Contract: Instructor Master Logbook is Operational Legs only, without delete/enroll.
 */

$root = dirname(__DIR__);
$failures = array();

function require_contains(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (!str_contains($contents, $needle)) {
        $failures[] = "{$label}: expected `{$needle}` in {$path}";
    }
}

function require_absent(string $path, string $needle, string $label, array &$failures): void
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $failures[] = "missing file: {$path}";
        return;
    }
    if (str_contains($contents, $needle)) {
        $failures[] = "{$label}: unexpected `{$needle}` in {$path}";
    }
}

$instructorPage = $root . '/public/instructor/master_logbook.php';
$intake = $root . '/public/admin/master_logbook_intake.php';
$nav = $root . '/src/nav/instructor.php';
$replay = $root . '/public/admin/cockpit_recorder_replay.php';
$copyApi = $root . '/public/admin/api/operational_leg_debrief_copy.php';

require_contains($instructorPage, "'audience' => 'instructor'", 'instructor audience', $failures);
require_contains($instructorPage, "'can_remove' => false", 'instructor cannot remove', $failures);
require_contains($instructorPage, "'can_enroll' => false", 'instructor cannot enroll', $failures);
require_contains($instructorPage, "'show_audio' => false", 'instructor hides audio tab', $failures);
require_contains($instructorPage, "'show_garmin' => false", 'instructor hides garmin tab', $failures);
require_contains($instructorPage, "'show_reconstruction' => false", 'instructor hides reconstruction tab', $failures);
require_contains($instructorPage, '/instructor/master_logbook.php', 'instructor base path', $failures);
require_contains($instructorPage, 'master_logbook_intake.php', 'reuses shared intake', $failures);

require_contains($nav, '/instructor/master_logbook.php', 'instructor nav link', $failures);
require_contains($nav, 'Master Logbook', 'instructor nav label', $failures);

require_contains($intake, 'cvrMlCanRemove', 'shared intake respects remove capability', $failures);
require_contains($intake, 'cvrMlCanEnroll', 'shared intake respects enroll capability', $failures);
require_contains($intake, "\$cvrMlAudience === 'instructor'", 'shared intake supports instructor audience', $failures);
require_contains($intake, 'Removing operational legs is not available', 'server blocks instructor remove', $failures);
require_contains($intake, 'This action is not available in the instructor Master Logbook', 'server blocks instructor admin actions', $failures);

require_contains($replay, '/instructor/master_logbook.php', 'replay allows instructor return', $failures);
require_contains($replay, 'instructor', 'replay allows instructor roles', $failures);
require_absent($replay, "cw_require_admin();\n}", 'replay no longer admin-only', $failures);

require_contains($copyApi, 'instructor', 'debrief copy allows instructors', $failures);
require_absent($copyApi, 'cw_require_admin()', 'debrief copy not admin-only', $failures);

if ($failures === array()) {
    fwrite(STDOUT, "cvr_instructor_master_logbook_contract_check OK\n");
    exit(0);
}

fwrite(STDERR, "cvr_instructor_master_logbook_contract_check FAILED\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "- {$failure}\n");
}
exit(1);
