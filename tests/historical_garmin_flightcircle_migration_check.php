<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'migration' => $root . '/scripts/sql/2026_07_20_historical_garmin_flightcircle_migration.sql',
    'flightcircle_service' => $root . '/src/FlightCircleHistoricalImportService.php',
    'garmin_service' => $root . '/src/GarminHistoricalBackfillService.php',
    'match_service' => $root . '/src/FlightCircleGarminMatchService.php',
    'active_dataset_migration' => $root . '/scripts/sql/2026_07_20_flightcircle_active_dataset.sql',
    'identity_api' => $root . '/public/admin/api/flightcircle_identity_action.php',
    'worker' => $root . '/scripts/run_async_jobs.php',
    'admin_page' => $root . '/public/admin/flight_log_garmin_connection.php',
);

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$checks = array(
    'migration creates source-neutral canonical operation and evidence tables' =>
        str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ipca_aircraft_operations')
        && str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ipca_source_evidence')
        && str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ipca_meter_readings')
        && str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ipca_crew_assignments')
        && str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ipca_migration_cutovers'),

    'FlightCircle importer preserves raw files and rows before normalization' =>
        str_contains($files['migration'], 'ipca_flightcircle_raw_files')
        && str_contains($files['migration'], 'ipca_flightcircle_raw_rows')
        && str_contains($files['flightcircle_service'], 'createSourceEvidence')
        && str_contains($files['flightcircle_service'], 'insertRawRow'),

    'FlightCircle resources are explicitly classified with ignored resources and AL172M2 AATD' =>
        str_contains($files['flightcircle_service'], "SIMULATOR_RESOURCE = 'AL172M2'")
        && str_contains($files['flightcircle_service'], 'N397EA')
        && str_contains($files['flightcircle_service'], 'N482EA')
        && str_contains($files['flightcircle_service'], 'CLASSROOM I')
        && str_contains($files['flightcircle_service'], 'APPLE VISION PRO')
        && str_contains($files['flightcircle_service'], "'aatd_simulator'")
        && str_contains($files['flightcircle_service'], "'ignored_resource'"),

    'mission codes and route text remain informational only' =>
        str_contains($files['flightcircle_service'], 'training_mission_codes_in_notes_are_informational_only')
        && str_contains($files['flightcircle_service'], 'flightcircle_route_is_planned_or_informational_until_confirmed')
        && str_contains($files['admin_page'], 'Informational only'),

    'unmatched users are suggested for admin review, not auto-created' =>
        str_contains($files['flightcircle_service'], 'suggested_create_user')
        && str_contains($files['migration'], 'ipca_flightcircle_user_mappings')
        && str_contains($files['flightcircle_service'], 'ensureIdentitySuggestion')
        && !str_contains(extractMethod($files['flightcircle_service'], 'ensureIdentitySuggestion'), 'insertMigrationUser')
        && !str_contains(extractMethod($files['flightcircle_service'], 'parseRows'), 'insertMigrationUser'),

    'identity review supports create user and map existing actions' =>
        str_contains($files['flightcircle_service'], 'createUserForIdentityMapping')
        && str_contains($files['flightcircle_service'], 'mapIdentityToExistingUser')
        && str_contains($files['identity_api'], "action === 'create_user'")
        && str_contains($files['identity_api'], "action === 'bulk_create_users'")
        && str_contains($files['identity_api'], "action === 'map_existing'")
        && str_contains($files['admin_page'], 'data-fc-create-user')
        && str_contains($files['admin_page'], 'data-fc-bulk-create-users')
        && str_contains($files['admin_page'], 'data-fc-map-existing'),

    'AL172M2 creates simulator proposals and not aircraft operation ledgers' =>
        str_contains($files['flightcircle_service'], 'createSimulatorLogbookProposal')
        && str_contains($files['flightcircle_service'], "'student_simulator'")
        && str_contains($files['flightcircle_service'], "'aatd_simulator'")
        && str_contains($files['flightcircle_service'], 'garmin_position_airport_data_not_authoritative'),

    'historical Garmin backfill uses distinct provider and separate async queue' =>
        str_contains($files['garmin_service'], "PROVIDER_NAME = 'historical_sd_card_csv'")
        && str_contains($files['garmin_service'], "SOURCE_TYPE = 'GARMIN_SD_CARD_HISTORICAL_CSV'")
        && str_contains($files['garmin_service'], "'historical_backfill'")
        && str_contains($files['worker'], 'GARMIN_HISTORICAL_FILE_PROCESS'),

    'FlightCircle-to-Garmin matching creates reviewable candidates without auto-merge' =>
        str_contains($files['match_service'], 'ipca_flightcircle_garmin_matches')
        && str_contains($files['match_service'], 'high_confidence')
        && str_contains($files['match_service'], 'probable')
        && !str_contains($files['match_service'], "UPDATE ipca_aircraft_operations\n            SET review_status = 'approved'"),

    'FlightCircle matching uses active replacement dataset and explainable tail Hobbs keys' =>
        str_contains($files['migration'], 'active_dataset')
        && str_contains($files['active_dataset_migration'], 'active_dataset')
        && str_contains($files['flightcircle_service'], 'replaceActiveDataset')
        && str_contains($files['flightcircle_service'], 'activeDatasetValidation')
        && str_contains($files['match_service'], 'datasetBatchesForMatching')
        && str_contains($files['match_service'], 'matchSelectedGarminBackfillFiles')
        && str_contains($files['match_service'], 'matchGarminSegmentToFlightCircle')
        && str_contains($files['match_service'], 'needs_tail_alias')
        && str_contains($files['match_service'], 'no_flightcircle_candidate')
        && str_contains($files['match_service'], 'AIRCRAFT_TAIL_ALIASES')
        && str_contains($files['match_service'], 'departure_hobbs_matches')
        && !str_contains($files['match_service'], 'same_departure_date')
        && !str_contains($files['match_service'], 'dateWindowForRecord')
        && str_contains($files['match_service'], 'noMatchDiagnostic')
        && str_contains($files['admin_page'], 'Active FlightCircle Dataset Validation')
        && str_contains($files['admin_page'], 'replace_active_dataset'),

    'admin page exposes both migration upload sections' =>
        str_contains($files['admin_page'], 'Historical SD Card CSV Backfill')
        && str_contains($files['admin_page'], 'FlightCircle Historical Migration')
        && str_contains($files['admin_page'], 'AL172M2'),

    'unified Garmin import overview supports bulk process and FlightCircle enrichment' =>
        str_contains($files['admin_page'], 'All Garmin Imports')
        && str_contains($files['admin_page'], 'data-import-bulk-action="process_selected_inline"')
        && str_contains($files['admin_page'], 'data-import-bulk-action="match_flightcircle"')
        && str_contains($files['admin_page'], 'data-hobbs-out-cell')
        && str_contains($files['admin_page'], 'Hobbs continuity gap')
        && str_contains($files['admin_page'], 'FC row')
        && str_contains($files['admin_page'], 'FC Hobbs')
        && str_contains($files['admin_page'], 'selected Garmin row(s)'),
);

$failed = array();
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$pass) {
        $failed[] = $name;
    }
}

if ($failed !== array()) {
    fwrite(STDERR, "\nFailed checks:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

function extractMethod(string $source, string $method): string
{
    $needle = 'function ' . $method . '(';
    $pos = strpos($source, $needle);
    if ($pos === false) {
        return '';
    }
    $brace = strpos($source, '{', $pos);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $pos, $i - $pos + 1);
            }
        }
    }
    return '';
}
