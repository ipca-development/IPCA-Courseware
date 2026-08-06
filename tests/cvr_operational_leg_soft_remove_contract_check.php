<?php
declare(strict_types=1);

/**
 * Contract: Operational Legs can be soft-removed from Master Logbook without deleting evidence.
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

$visibility = $root . '/src/CvrOperationalLegVisibilityService.php';
$intake = $root . '/src/CvrDataIntakeReadService.php';
$page = $root . '/public/admin/master_logbook_intake.php';

require_contains($visibility, 'class CvrOperationalLegVisibilityService', 'visibility service', $failures);
require_contains($visibility, 'function hide', 'soft hide', $failures);
require_contains($visibility, 'function restore', 'restore', $failures);
require_contains($visibility, 'ipca_cvr_logbook_hidden_legs', 'hidden legs table', $failures);
require_contains($visibility, 'operational_leg.hide', 'hide audit action', $failures);
require_contains($visibility, 'operational_leg.restore', 'restore audit action', $failures);
require_absent($visibility, 'DELETE FROM ipca_cvr_dispatches', 'must not hard-delete dispatches', $failures);

require_contains($intake, 'CvrOperationalLegVisibilityService', 'intake uses visibility service', $failures);
require_contains($intake, 'onlyHidden', 'intake supports removed-only filter', $failures);
require_contains($intake, 'ipca_cvr_logbook_hidden_legs', 'intake filters hidden legs', $failures);
require_contains($intake, 'is_hidden', 'intake marks soft-removed rows', $failures);

require_contains($page, 'hide_operational_leg', 'hide POST action', $failures);
require_contains($page, 'restore_operational_leg', 'restore POST action', $failures);
require_contains($page, 'legs_show_removed', 'show removed filter', $failures);
require_contains($page, 'Show removed', 'show removed label', $failures);
require_contains($page, 'legs-btn-remove', 'remove button', $failures);
require_contains($page, 'Evidence stays on file', 'soft-remove confirmation copy', $failures);
require_contains($page, 'cvr_intake_legs_query()', 'remove/restore preserve filter query', $failures);
require_absent($page, 'DELETE FROM ipca_cvr_dispatches', 'page must not hard-delete dispatches', $failures);

require_once $visibility;
if (!class_exists('CvrOperationalLegVisibilityService')) {
    $failures[] = 'CvrOperationalLegVisibilityService failed to load';
}

if ($failures === array()) {
    fwrite(STDOUT, "cvr_operational_leg_soft_remove_contract_check OK\n");
    exit(0);
}

fwrite(STDERR, "cvr_operational_leg_soft_remove_contract_check FAILED\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "- {$failure}\n");
}
exit(1);
