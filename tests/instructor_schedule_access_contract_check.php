<?php
declare(strict_types=1);

/**
 * Instructors get full-edit online Schedule access (parity with admin writes).
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

require_contains(
    $root . '/src/auth.php',
    'function cw_user_can_edit_flight_schedule',
    'schedule staff gate helper',
    $failures
);
require_contains(
    $root . '/src/auth.php',
    'function cw_require_flight_schedule_editor',
    'schedule staff require helper',
    $failures
);
require_contains(
    $root . '/public/instructor/schedule.php',
    "base_path' => '/instructor/schedule.php'",
    'instructor schedule wrapper',
    $failures
);
require_contains(
    $root . '/public/instructor/schedule.php',
    "require __DIR__ . '/../admin/schedule.php'",
    'instructor reuses admin schedule UI',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    '$flightScheduleContext',
    'admin schedule accepts shared context',
    $failures
);
require_contains(
    $root . '/public/admin/schedule.php',
    '$scheduleBasePath',
    'schedule navigation uses base path',
    $failures
);
require_contains(
    $root . '/src/nav/instructor.php',
    "'href' => '/instructor/schedule.php'",
    'instructor nav Schedule link',
    $failures
);
require_contains(
    $root . '/public/admin/api/schedule_aircraft_adsb.php',
    'cw_require_flight_schedule_editor',
    'schedule ADS-B API open to schedule editors',
    $failures
);

$nav = file_get_contents($root . '/src/nav/instructor.php') ?: '';
if (preg_match("/\\[[\\s\\n]*'key'\\s*=>\\s*'schedule',[\\s\\S]*?\\],/", $nav, $m)) {
    if (str_contains($m[0], "'coming_soon'")) {
        $failures[] = 'instructor Schedule must not remain coming_soon';
    }
    if (!str_contains($m[0], "'/instructor/schedule.php'")) {
        $failures[] = 'instructor Schedule nav must link to /instructor/schedule.php';
    }
} else {
    $failures[] = 'instructor Schedule nav item missing';
}

if ($failures !== array()) {
    fwrite(STDERR, "instructor_schedule_access_contract_check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "instructor_schedule_access_contract_check OK\n");
