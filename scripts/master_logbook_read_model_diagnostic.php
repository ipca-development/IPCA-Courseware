<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/MasterLogbookReadService.php';

function ml_diag_json(array $payload, int $exitCode): void
{
    $started = microtime(true);
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $jsonMs = round((microtime(true) - $started) * 1000.0, 3);
    if (isset($payload['diagnostics']) && is_array($payload['diagnostics'])) {
        $payload['diagnostics']['json_serialization_ms'] = $jsonMs;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    echo $encoded . PHP_EOL;
    exit($exitCode);
}

try {
    $fixtureMode = in_array('--fixture', $argv, true) || in_array('--fixture-mode', $argv, true);
    $liveMode = in_array('--live', $argv, true);
    $includeUnresolved = in_array('--include-unresolved', $argv, true) || $fixtureMode;
    $includeSimulator = in_array('--include-simulator', $argv, true) || $fixtureMode;
    $includeNonFlight = in_array('--include-non-flight', $argv, true) || $fixtureMode;
    $page = 1;
    $pageSize = 25;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--page=')) {
            $page = max(1, (int)substr($arg, 7));
        }
        if (str_starts_with($arg, '--page-size=')) {
            $pageSize = max(1, min(100, (int)substr($arg, 12)));
        }
    }

    if (!$fixtureMode && !$liveMode) {
        ml_diag_json(array(
            'ok' => false,
            'error' => 'Run with --fixture for contract fixtures or --live for a read-only database diagnostic.',
        ), 2);
    }

    if ($fixtureMode) {
        $fixture = require __DIR__ . '/../tests/fixtures/master_logbook_read_model.php';
        $service = new MasterLogbookReadService(null, $fixture);
        $mode = 'fixture';
    } else {
        require_once __DIR__ . '/../src/bootstrap.php';
        $service = new MasterLogbookReadService($pdo);
        $mode = 'live';
    }

    $result = $service->listLegRows(array(
        'page' => $page,
        'page_size' => $pageSize,
        'include_unresolved' => $includeUnresolved,
        'include_simulator' => $includeSimulator,
        'include_non_flight' => $includeNonFlight,
        'include_diagnostics' => true,
    ));

    $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
    $diagnostics = isset($result['query_diagnostics']) && is_array($result['query_diagnostics']) ? $result['query_diagnostics'] : array();
    foreach (array('query_count', 'query_ms', 'row_build_ms', 'dedupe_ms', 'evidence_batch_ms', 'candidate_count_by_source_branch', 'suppressed_duplicate_count', 'unresolved_count') as $required) {
        if (!array_key_exists($required, $diagnostics)) {
            ml_diag_json(array('ok' => false, 'error' => 'Missing diagnostic field: ' . $required), 1);
        }
    }

    ml_diag_json(array(
        'ok' => true,
        'request' => array(
            'mode' => $mode,
            'page' => $result['page'],
            'page_size' => $result['page_size'],
            'filters' => array(
                'include_unresolved' => $includeUnresolved,
                'include_simulator' => $includeSimulator,
                'include_non_flight' => $includeNonFlight,
            ),
            'sort' => $result['sort_field'] . '_' . $result['sort_direction'],
        ),
        'summary' => array(
            'rows' => count($rows),
            'total_matching_rows' => $result['total_matching_rows'],
            'suppressed_duplicates' => $diagnostics['suppressed_duplicate_count'],
            'unresolved_records' => $diagnostics['unresolved_count'],
        ),
        'diagnostics' => $diagnostics,
        'sample_rows' => array_slice($rows, 0, 5),
    ), 0);
} catch (Throwable $e) {
    ml_diag_json(array(
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ), 1);
}
