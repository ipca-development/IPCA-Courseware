#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Dispatch page UI correction — static contract check.
 */

$root = dirname(__DIR__);
$failures = [];

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

$views = $root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift';
$design = $root . '/ipca-cvr-unit/IPCACVRUnit/Views/CVROperationalDesign.swift';
$route = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRDispatchRouteOverview.swift';
$workflow = $root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift';
$models = $root . '/ipca-cvr-unit/IPCACVRUnit/Models/CVRWorkflowModels.swift';
$pbx = $root . '/ipca-cvr-unit/IPCACVRUnit.xcodeproj/project.pbxproj';
$swiftTest = $root . '/tests/cvr_dispatch_page_ui_contract_check.swift';

require_absent($views, 'Edit Dispatch', '1 no Edit Dispatch button', $failures);
require_absent($views, 'isEditingDispatch', '2 no long-form edit presentation flag', $failures);
require_contains($views, 'Tap to add crew', '3 crew empty hint', $failures);
require_contains($views, 'Starting meters required', '5 meters missing inside block', $failures);
require_contains($views, 'Fuel and oil required', '6 fuel/oil missing inside block', $failures);
require_contains($views, 'routeOverview', '7 route overview present', $failures);
require_contains($workflow, 'session.plannedLegs[index].status = "planned"', 'opening Dispatch keeps route leg scheduled', $failures);
require_contains($workflow, 'markCurrentPlannedLeg(dispatchedIn:', 'Dispatch acknowledgement changes route state', $failures);
require_contains($route, 'Checked In', '10 checked-in display status', $failures);
require_contains($route, 'checkedInStatusIcon', 'checked-in status icon helper', $failures);
require_contains($route, 'checkmark.seal.fill', 'checked-in uses Log COMPLETE seal', $failures);
require_contains($views, 'informativeDispatchRoute', 'Dispatch shows one informative scheduled route', $failures);
require_contains($models, 'informativeRouteAirports', 'Dispatch persists the full informative scheduler route', $failures);
require_contains($views, 'Text("MISSION")', 'Mission heading matches uppercase Route style', $failures);
require_absent($views, '@State private var showRouteEditor', 'Dispatch route editor state removed', $failures);
require_absent($views, 'Text("EDIT LEGS")', 'Dispatch leg edit action removed', $failures);
require_absent($views, 'Text("Add or edit legs")', 'Dispatch add-leg action removed', $failures);
require_contains($views, 'LocalRouteEditorSheet', 'route editor sheet', $failures);
require_contains($views, 'applyLocalRouteDraft', 'route draft apply path', $failures);
require_contains($workflow, 'if $0.activeDispatch != nil && $0.activeFlightRecord == nil', 'legacy pre-dispatch route status correction', $failures);
require_contains($views, 'Label("ERASE"', 'erase swipe label', $failures);
require_contains($views, 'padding(.bottom, 132)', '14 button above tab bar padding', $failures);
require_absent($views, 'Quick Verification', 'quick verification list removed', $failures);
require_absent($views, 'quickVerification', 'quick verification property removed', $failures);
require_contains($views, 'DispatchBlockEditor', 'focused block editors', $failures);
require_contains($views, 'activeBlockEditor', 'tappable blocks open focused editors', $failures);
require_contains($views, 'case .meters:', 'dispatch meters editor present', $failures);
require_contains($models, 'return "VERIFY AND DISPATCH"', 'pre-dispatch title', $failures);
require_contains($models, 'return "DISPATCHED"', 'acknowledged Dispatch title', $failures);
require_contains($views, 'Text("WARNINGS")', 'warnings section heading', $failures);
require_contains($views, 'acceptedWarnings: acceptedWarnings', 'Dispatch click acknowledges displayed warnings', $failures);
require_absent($views, 'title: "Dispatch Confirmed"', 'green Dispatch Confirmed button removed', $failures);

require_contains($design, 'var caption: String? = nil', 'tappable tile caption', $failures);
require_contains($design, 'var action: (() -> Void)? = nil', 'tappable tile action', $failures);

require_contains($route, 'enum CVRDispatchRouteOverview', 'route overview helper', $failures);
require_contains($route, 'func isCurrent(', 'leg_uuid current highlighting', $failures);
require_contains($route, 'isRouteEditingLocked', 'route lock after dispatch', $failures);
require_contains($route, 'Scheduled', 'scheduled display status', $failures);

require_contains($pbx, 'CVRDispatchRouteOverview.swift', 'xcode includes route overview', $failures);
require_contains($swiftTest, 'Dispatch page route overview', 'focused swift test present', $failures);

$viewsText = is_file($views) ? file_get_contents($views) : '';
$start = strpos($viewsText, 'struct DispatchWorkflowView: View {');
$end = $start === false ? false : strpos($viewsText, "\nstruct RecorderWorkflowView", $start);
if ($start === false || $end === false) {
    $failures[] = 'DispatchWorkflowView slice could not be isolated';
} else {
    $slice = substr($viewsText, $start, $end - $start);
    if (str_contains($slice, 'Edit Dispatch')) {
        $failures[] = 'DispatchWorkflowView must not contain Edit Dispatch';
    }
    if (preg_match('/CREW REQUIRED/', $slice) && str_contains($slice, 'CVROperationalWarningCard')) {
        // Allow only exceptional CREW FUNCTION REQUIRED path.
        if (str_contains($slice, 'title: "CREW REQUIRED"') || str_contains($slice, 'title: workflow.dispatchMissingItems.first')) {
            $failures[] = '4 no generic Crew Required / missing-items banner for normal empty state';
        }
    }
    if (str_contains($slice, 'title: workflow.dispatchMissingItems.first')) {
        $failures[] = '4 generic missing-items warning banner must not be the primary incomplete-state UI';
    }
    if (!str_contains($slice, 'Tap to add crew')) {
        $failures[] = '3 Dispatch page Crew block must show Tap to add crew';
    }
    if (!str_contains($slice, 'routeOverview')) {
        $failures[] = '7 Route Overview must appear on Dispatch page';
    }
    if (!str_contains($slice, 'title: "DISPATCH NOW"')
        || !str_contains($slice, 'subtitle: "Hold 2 seconds to confirm"')) {
        $failures[] = '14 Dispatch must use the two-second DISPATCH NOW hold control';
    }
    if (!preg_match('/dispatchTiles\\(metrics\\).*missionSelector.*routeOverview.*dispatchWarningsSection.*actionButtons/s', $slice)) {
        $failures[] = 'Dispatch page order must be blocks, mission, route, warnings, actions';
    }
    // Long-form competing path: unfocused DispatchEditorView() open.
    if (preg_match('/DispatchEditorView\(\s*\)/', $slice)) {
        $failures[] = '2 Dispatch page must not open unfocused long-form DispatchEditorView()';
    }
}

$editorStart = strpos($viewsText, 'struct DispatchEditorView: View {');
if ($editorStart === false) {
    $failures[] = 'focused DispatchEditorView missing';
} else {
    $editorSlice = substr($viewsText, $editorStart, 2500);
    if (!str_contains($editorSlice, 'var focus: DispatchBlockEditor')) {
        $failures[] = '2 DispatchEditorView must require a focused block editor';
    }
}

// Dispatch meters editor must use the same large TACHO/HOBBS boxes as Check-In.
$editorMeters = $editorStart === false ? false : strpos($viewsText, 'case .meters:', $editorStart);
$editorFuel = $editorMeters === false ? false : strpos($viewsText, 'case .fuelOil:', $editorMeters);
if ($editorMeters === false || $editorFuel === false) {
    $failures[] = 'DispatchEditorView meters slice could not be isolated';
} else {
    $metersSlice = substr($viewsText, $editorMeters, $editorFuel - $editorMeters);
    if (!str_contains($metersSlice, 'largeMeterField("TACHO"')) {
        $failures[] = 'Dispatch meters editor must use large TACHO field like Check-In';
    }
    if (!str_contains($metersSlice, 'largeMeterField("HOBBS"')) {
        $failures[] = 'Dispatch meters editor must use large HOBBS field like Check-In';
    }
    if (str_contains($metersSlice, 'numericTextField(')) {
        $failures[] = 'Dispatch meters editor must not use the old compact numericTextField controls';
    }
    if (str_contains($metersSlice, 'numericTextField("Starting Hobbs"')
        || str_contains($metersSlice, 'numericTextField("Starting Tacho"')) {
        $failures[] = 'Dispatch meters editor must not use the old compact Starting Hobbs/Tacho fields';
    }
}

if ($failures) {
    fwrite(STDERR, "Dispatch page UI contract FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Dispatch page UI contract OK\n");
exit(0);
