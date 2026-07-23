<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/MasterLogbookReadService.php';

$fixture = require __DIR__ . '/fixtures/master_logbook_read_model.php';
$service = new MasterLogbookReadService(null, $fixture);
$failures = array();
$scenarioResults = array();

$default = $service->listLegRows(array('include_diagnostics' => true, 'page_size' => 100));
$all = $service->listLegRows(array(
    'include_diagnostics' => true,
    'include_unresolved' => true,
    'include_simulator' => true,
    'include_non_flight' => true,
    'page_size' => 100,
));

scenario('current Garmin-derived flight with CVR', row_has_state($default, 'current-event:ofr:101', 'cvr', 'usable'));
scenario('current multi-leg flight', count_rows_for_event($default, 'current-event:ofr:102') === 2);
scenario('FlightCircle-only historical aircraft operation', row_has_leg_type($default, 'historical-event:ao:301', 'aggregate_dispatch'));
scenario('FlightCircle enriched with multiple Garmin legs', count_rows_for_event($default, 'historical-event:ao:302') === 2 && detail_leg_count($service, 'historical-event:ao:302') === 2);
scenario('unmatched Garmin record', row_exists($all, 'unresolved-garmin:csv:401') && !row_exists($default, 'unresolved-garmin:csv:401'));
scenario('simulator session', row_exists($all, 'simulator-event:fcs:601') && !row_exists($default, 'simulator-event:fcs:601') && row_has_leg_type($all, 'simulator-event:fcs:601', 'simulator_session'));
scenario('non-flight or ignored FlightCircle resource', row_exists($all, 'nonflight-event:fcs:701') && !row_exists($default, 'nonflight-event:fcs:701') && row_has_leg_type($all, 'nonflight-event:fcs:701', 'non_flight_operation'));
scenario('duplicate Garmin source representing same real-world flight', suppressed($all, 'unresolved-garmin:csv:407', 'current-event:ofr:107'));
scenario('ambiguous FlightCircle/Garmin association is not silently merged', row_exists($all, 'historical-event:ao:303') && row_exists($all, 'unresolved-garmin:csv:402'));
scenario('recording with replay transcript and ADS-B', row_has_state($default, 'current-event:ofr:101', 'replay', 'usable') && row_has_state($default, 'current-event:ofr:101', 'adsb', 'usable') && transcript_raw_available($service, 'current-event:ofr:101'));
scenario('record with CVR but no usable FDM', row_has_state($default, 'current-event:ofr:103', 'cvr', 'usable') && row_has_state($default, 'current-event:ofr:103', 'fdm', 'not_available'));
scenario('record with Garmin/FDM but no CVR', row_has_state($default, 'current-event:ofr:104', 'fdm', 'usable') && row_has_state($default, 'current-event:ofr:104', 'cvr', 'not_available'));
scenario('stale or failed replay is not usable and has no launch URL', stale_replay_not_launchable($default, 'current-event:ofr:105'));
scenario('finalized official logbook association does not create duplicate event', row_finalized($default, 'current-event:ofr:101') && suppressed($all, 'unresolved-garmin:csv:101', 'current-event:ofr:101') && no_event_prefix($all, 'logbook'));
scenario('logbook proposal not accepted does not create duplicate event', row_exists($default, 'current-event:ofr:106') && no_event_prefix($all, 'proposal'));
scenario('FlightCircle dispatch with total Hobbs has unresolved leg structure', row_has_leg_type($default, 'historical-event:ao:304', 'unresolved_leg_structure'));
scenario('record with conflicting meter values', row_conflict($default, 'historical-event:ao:307', 'warning'));
scenario('record with unresolved crew roles', crew_and_mission_unresolved($default, 'historical-event:ao:308'));

scenario('aggregate FlightCircle row suppressed when explicit Garmin legs exist', suppressed($default, 'historical-event:ao:302', 'historical-event:ao:302'));
scenario('orphaned source evidence remains outside normal result unless requested', !row_exists($default, 'orphan-recording:rec:801') && row_exists($all, 'orphan-recording:rec:801'));
scenario('query diagnostics include required performance fields', diagnostics_have_fields($all));
scenario('event-key parser rejects unsupported formats', parser_rejects_bad_event_key($service));
scenario('leg-key parser rejects unsupported formats', parser_rejects_bad_leg_key($service));
scenario('service source contains no write SQL keywords', service_is_read_only_source());
scenario('Local IFR Training Mission produces unresolved DEP and ARR', row_airport_pair($default, 'historical-event:ao:309', null, null));
scenario('missing route produces unresolved airports instead of fallback', row_airport_pair($default, 'historical-event:ao:310', null, null));
scenario('valid route text resolves only supported airport endpoints', row_airport_pair($default, 'historical-event:ao:311', 'KTRM', 'KTRM'));
scenario('confirmed multi-leg event summary uses first departure and last arrival', detail_summary_airport_pair($service, 'current-event:ofr:102', 'KTRM', 'KTRM'));
scenario('confirmed Garmin rows expose one-decimal Hobbs and Tacho counters', row_has_one_decimal_counters($default, 'historical-event:ao:302'));
scenario('malformed route text does not produce airports', row_airport_pair($default, 'historical-event:ao:312', null, null));
scenario('four-letter non-airport word is rejected', row_airport_pair($default, 'historical-event:ao:313', null, null));
scenario('conflicting airport route evidence is not silently resolved', row_airport_pair($default, 'historical-event:ao:314', null, null) && row_conflict($default, 'historical-event:ao:314', 'warning'));
scenario('no Master Logbook airport fallback remains', no_hardcoded_airport_fallback());

foreach ($scenarioResults as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
}

if ($failures !== array()) {
    fwrite(STDERR, PHP_EOL . "Failed Master Logbook contract checks:" . PHP_EOL . '- ' . implode(PHP_EOL . '- ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'OK: MasterLogbookReadService contract checks passed.' . PHP_EOL;

function scenario(string $name, bool $passed): void
{
    global $scenarioResults;
    $scenarioResults[$name] = $passed;
}

function rows(array $result): array
{
    return isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
}

function row_for(array $result, string $eventKey): ?array
{
    foreach (rows($result) as $row) {
        if (($row['event_key'] ?? '') === $eventKey) {
            return $row;
        }
    }
    return null;
}

function row_exists(array $result, string $eventKey): bool
{
    return row_for($result, $eventKey) !== null;
}

function count_rows_for_event(array $result, string $eventKey): int
{
    $count = 0;
    foreach (rows($result) as $row) {
        if (($row['event_key'] ?? '') === $eventKey) {
            $count++;
        }
    }
    return $count;
}

function row_has_state(array $result, string $eventKey, string $evidenceKey, string $state): bool
{
    $row = row_for($result, $eventKey);
    return is_array($row) && (($row[$evidenceKey]['state'] ?? null) === $state);
}

function row_has_leg_type(array $result, string $eventKey, string $type): bool
{
    $row = row_for($result, $eventKey);
    return is_array($row) && (($row['leg_structure_type'] ?? null) === $type);
}

function row_finalized(array $result, string $eventKey): bool
{
    $row = row_for($result, $eventKey);
    return is_array($row) && (($row['finalization_status'] ?? null) === 'finalized');
}

function row_conflict(array $result, string $eventKey, string $status): bool
{
    $row = row_for($result, $eventKey);
    return is_array($row) && (($row['conflict_status'] ?? null) === $status);
}

function row_airport_pair(array $result, string $eventKey, ?string $departure, ?string $arrival): bool
{
    $row = row_for($result, $eventKey);
    if (!is_array($row)) {
        return false;
    }
    return (($row['departure_airport']['resolved_icao'] ?? $row['departure_airport']['resolved_value'] ?? null) === $departure)
        && (($row['arrival_airport']['resolved_icao'] ?? $row['arrival_airport']['resolved_value'] ?? null) === $arrival);
}

function detail_summary_airport_pair(MasterLogbookReadService $service, string $eventKey, ?string $departure, ?string $arrival): bool
{
    $detail = $service->eventDetail($eventKey);
    return (($detail['summary']['departure_airport']['resolved_icao'] ?? $detail['summary']['departure_airport']['resolved_value'] ?? null) === $departure)
        && (($detail['summary']['arrival_airport']['resolved_icao'] ?? $detail['summary']['arrival_airport']['resolved_value'] ?? null) === $arrival);
}

function row_has_one_decimal_counters(array $result, string $eventKey): bool
{
    $row = row_for($result, $eventKey);
    if (!is_array($row)) {
        return false;
    }
    foreach (array('departure_hobbs', 'arrival_hobbs', 'departure_tacho', 'arrival_tacho') as $field) {
        $value = (string)($row[$field]['resolved_value'] ?? '');
        if (preg_match('/^-?\d+\.\d$/', $value) !== 1) {
            return false;
        }
    }
    return true;
}

function suppressed(array $result, string $eventKey, string $winnerEventKey): bool
{
    $items = $result['query_diagnostics']['suppressed_duplicates'] ?? array();
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if (($item['event_key'] ?? '') === $eventKey && ($item['suppressed_by_event_key'] ?? '') === $winnerEventKey) {
            return true;
        }
    }
    return false;
}

function no_event_prefix(array $result, string $prefix): bool
{
    foreach (rows($result) as $row) {
        if (str_starts_with((string)($row['event_key'] ?? ''), $prefix)) {
            return false;
        }
    }
    return true;
}

function transcript_raw_available(MasterLogbookReadService $service, string $eventKey): bool
{
    $detail = $service->eventDetail($eventKey);
    return !empty($detail['transcript']['raw_transcript']) || !empty($detail['evidence']['transcript']['raw_available']);
}

function detail_leg_count(MasterLogbookReadService $service, string $eventKey): int
{
    $detail = $service->eventDetail($eventKey);
    return isset($detail['legs']) && is_array($detail['legs']) ? count($detail['legs']) : 0;
}

function stale_replay_not_launchable(array $result, string $eventKey): bool
{
    $row = row_for($result, $eventKey);
    if (!is_array($row)) {
        return false;
    }
    if (($row['replay']['state'] ?? '') === 'usable') {
        return false;
    }
    foreach (($row['available_actions'] ?? array()) as $action) {
        if (($action['type'] ?? '') === 'launch_replay') {
            return false;
        }
    }
    return true;
}

function crew_and_mission_unresolved(array $result, string $eventKey): bool
{
    $row = row_for($result, $eventKey);
    if (!is_array($row)) {
        return false;
    }
    return value_is_null($row, 'pilot_1', 'resolved_value')
        && value_is_null($row, 'pilot_1_role', 'resolved_value')
        && value_is_null($row, 'mission', 'resolved_value')
        && ($row['pilot_1']['raw_source_value'] ?? null) !== null;
}

function value_is_null(array $row, string $field, string $key): bool
{
    return isset($row[$field]) && is_array($row[$field]) && array_key_exists($key, $row[$field]) && $row[$field][$key] === null;
}

function diagnostics_have_fields(array $result): bool
{
    $diag = $result['query_diagnostics'] ?? array();
    foreach (array('query_count', 'query_ms', 'schema_table_discovery_ms', 'count_query_ms', 'row_query_ms', 'evidence_query_count', 'evidence_query_ms', 'row_build_ms', 'dedupe_ms', 'evidence_batch_ms', 'json_serialization_ms', 'candidate_count_by_source_branch', 'suppressed_duplicate_count', 'unresolved_count') as $field) {
        if (!array_key_exists($field, $diag)) {
            return false;
        }
    }
    return true;
}

function parser_rejects_bad_event_key(MasterLogbookReadService $service): bool
{
    try {
        $service->parseEventKey('current-event:raw-table:123');
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

function parser_rejects_bad_leg_key(MasterLogbookReadService $service): bool
{
    try {
        $service->parseLegKey('current-leg:whatever:123');
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

function service_is_read_only_source(): bool
{
    $source = (string)file_get_contents(__DIR__ . '/../src/MasterLogbookReadService.php');
    if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i', $source)) {
        return false;
    }
    foreach (array('->enrich(', '->reconstruct(', '->deriveFromCsvFile(', '->buildForCsvFileId(', '->matchBatch(', '->processTranscriptionStep(') as $needle) {
        if (str_contains($source, $needle)) {
            return false;
        }
    }
    return true;
}

function no_hardcoded_airport_fallback(): bool
{
    $page = (string)file_get_contents(__DIR__ . '/../public/admin/master_logbook.php');
    $service = (string)file_get_contents(__DIR__ . '/../src/MasterLogbookReadService.php');
    foreach (array($page, $service) as $source) {
        if (preg_match('/return\s+[\'"]K[A-Z0-9]{3}[\'"]\s*;/', $source)) {
            return false;
        }
        if (preg_match('/\?\s*[\'"]K[A-Z0-9]{3}[\'"]/', $source)) {
            return false;
        }
    }
    return true;
}
