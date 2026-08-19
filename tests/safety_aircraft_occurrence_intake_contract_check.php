<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents(
    $root . '/scripts/sql/2026_08_18_safety_aircraft_occurrence_intake.sql'
) ?: '';
$context = file_get_contents(
    $root . '/src/safety/SafetyOccurrenceIntakeContextService.php'
) ?: '';
$intake = file_get_contents($root . '/src/safety/SafetyIntakeService.php') ?: '';
$api = file_get_contents($root . '/public/api/safety/reports.php') ?: '';
$ios = file_get_contents($root . '/ipca-app-ios/IPCA/Views/SafetyView.swift') ?: '';
$web = file_get_contents($root . '/public/student/safety.php') ?: '';

$checks = array(
    'configured types use existing internal taxonomy table' =>
        str_contains($context, 'ipca_safety_taxonomy_nodes')
        && str_contains($context, 'reporter_occurrence_type_node_type')
        && str_contains($context, 'n.active = 1'),
    'report stores occurrence type by foreign key' =>
        str_contains($migration, 'occurrence_type_node_id')
        && str_contains($migration, 'fk_safety_report_occurrence_type'),
    'report flight link uses constrained source identifiers' =>
        str_contains($migration, 'ipca_safety_report_flight_links')
        && str_contains($migration, 'fk_safety_report_flight_slot')
        && str_contains($migration, 'fk_safety_report_flight_dispatch')
        && str_contains($migration, 'fk_safety_report_flight_session'),
    'flight candidates are scoped to logged-in assigned crew' =>
        str_contains($context, 'own_crew.user_id = ?')
        && str_contains($context, "NOT IN ('cancelled','canceled','superseded')"),
    'reporter confirmation is required before flight persistence' =>
        str_contains($context, "'reporter_confirmed'")
        && str_contains($context, 'flight_link_choice'),
    'late evidence resolver follows schedule dispatch and session identifiers' =>
        str_contains($context, 'scheduler_record_id')
        && str_contains($context, 'workflow_flight_record_uuid')
        && str_contains($context, 'operational_flight_record_id'),
    'intake stores structured human observations' =>
        str_contains($intake, 'injury_state')
        && str_contains($intake, 'damage_state')
        && str_contains($intake, 'weather_relevance')
        && str_contains($intake, 'phase_of_flight'),
    'authenticated API exposes types and flight candidates' =>
        str_contains($api, "action === 'occurrence_types'")
        && str_contains($api, "action === 'flight_candidates'"),
    'iOS has no hard-coded reporter category array' =>
        !str_contains($ios, 'private let categories =')
        && str_contains($ios, 'loadSafetyOccurrenceTypes'),
    'iOS is reservation first with explicit no-reservation path' =>
        str_contains($ios, 'Select the flight')
        && str_contains($ios, 'Not related to a scheduled flight')
        && str_contains($ios, 'selectedScheduleSlotID'),
    'iOS uses conditional impact details' =>
        str_contains($ios, 'if injuryState == "yes"')
        && str_contains($ios, 'if damageState == "yes"')
        && str_contains($ios, 'weatherRelevance == "unsure"'),
    'web has no hard-coded category values' =>
        !str_contains($web, '<option>flight_operations</option>')
        && str_contains($web, '$occurrenceTypes'),
    'regulatory fields remain outside reporter intake' =>
        !str_contains($ios, 'ECCAIRS')
        && !str_contains($web, 'authority deadline'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed aircraft occurrence intake checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo "OK: aircraft occurrence intake contract checks passed.\n";
