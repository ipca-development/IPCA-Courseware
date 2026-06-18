<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/flight_training/AdminLogbookService.php';

header('Content-Type: application/json; charset=utf-8');

function alog_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function alog_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

cw_require_admin();

$user = cw_current_user($pdo);
$actorUserId = (int)($user['id'] ?? 0);
$service = new AdminLogbookService($pdo);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = alog_input();
    $action = (string)($input['action'] ?? '');
}

try {
    switch ($action) {
        case 'status':
            alog_json(200, array(
                'ok' => true,
                'schema_ready' => $service->schemaReady(),
                'missing_tables' => $service->missingTables(),
            ));

        case 'students':
            alog_json(200, array('ok' => true, 'students' => $service->listStudents()));

        case 'logbooks':
            alog_json(200, array('ok' => true, 'logbooks' => $service->listLogbooks()));

        case 'open_logbook':
            $input = alog_input();
            $id = $service->getOrCreateLogbook((int)($input['student_user_id'] ?? 0), isset($input['cohort_id']) ? (int)$input['cohort_id'] : null, $actorUserId);
            alog_json(200, array('ok' => true, 'logbook_id' => $id));

        case 'workspace':
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace((int)($_GET['logbook_id'] ?? 0))));

        case 'variables':
            $workspace = $service->loadWorkspace((int)($_GET['logbook_id'] ?? 0));
            alog_json(200, array(
                'ok' => true,
                'logbook_id' => (int)($workspace['logbook']['id'] ?? 0),
                'student_user_id' => (int)($workspace['logbook']['student_user_id'] ?? 0),
                'variables' => $workspace['variables'] ?? array(),
                'calculated_at' => date('c'),
            ));

        case 'airport_lookup':
            $input = alog_input();
            $icao = (string)($input['icao'] ?? $_GET['icao'] ?? '');
            $allowAi = (bool)($input['allow_ai'] ?? $_GET['allow_ai'] ?? false);
            alog_json(200, array('ok' => true, 'airport' => $service->lookupAirport($icao, $allowAi)));

        case 'save_entry':
            $input = alog_input();
            $entry = $service->saveEntry((int)($input['logbook_id'] ?? 0), is_array($input['entry'] ?? null) ? $input['entry'] : array(), $actorUserId);
            alog_json(200, array('ok' => true, 'entry' => $entry, 'data' => $service->loadWorkspace((int)($input['logbook_id'] ?? 0))));

        case 'delete_entry':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->deleteEntry($logbookId, (int)($input['entry_id'] ?? 0), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'delete_entries':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->deleteEntries($logbookId, is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'flag_entries':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->flagEntries($logbookId, is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'accept_entries':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->acceptEntries($logbookId, is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'reject_entries':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->rejectEntries($logbookId, is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'bulk_update_entries':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->bulkUpdateEntries(
                $logbookId,
                is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(),
                is_array($input['flags'] ?? null) ? $input['flags'] : array(),
                $actorUserId
            );
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'split_entry':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->splitEntry($logbookId, (int)($input['entry_id'] ?? 0), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'merge_entries':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->mergeEntries($logbookId, is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(), $actorUserId);
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'upload_page':
            if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
                throw new RuntimeException('image file required');
            }
            $logbookId = (int)($_POST['logbook_id'] ?? 0);
            $page = $service->addPageFromUpload($logbookId, $_FILES['image'], $actorUserId);
            alog_json(200, array('ok' => true, 'page' => $page, 'data' => $service->loadWorkspace($logbookId)));

        case 'import_csv':
            if (empty($_FILES['csv']) || !is_array($_FILES['csv'])) {
                throw new RuntimeException('csv file required');
            }
            $logbookId = (int)($_POST['logbook_id'] ?? 0);
            $result = $service->importCsvUpload($logbookId, $_FILES['csv'], $actorUserId);
            alog_json(200, array('ok' => true, 'result' => $result, 'data' => $service->loadWorkspace($logbookId)));

        case 'attempt_extract_page':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $result = $service->attemptPageExtraction($logbookId, (int)($input['page_id'] ?? 0), $actorUserId);
            alog_json(200, array('ok' => true, 'result' => $result, 'data' => $service->loadWorkspace($logbookId)));

        case 'assign_requirement':
            $input = alog_input();
            $logbookId = (int)($input['logbook_id'] ?? 0);
            $service->assignRequirement(
                $logbookId,
                (int)($input['student_user_id'] ?? 0),
                (int)($input['requirement_category_id'] ?? 0),
                is_array($input['entry_ids'] ?? null) ? $input['entry_ids'] : array(),
                $actorUserId
            );
            alog_json(200, array('ok' => true, 'data' => $service->loadWorkspace($logbookId)));

        case 'requirement_categories':
            alog_json(200, array('ok' => true, 'categories' => $service->listRequirementCategories()));

        case 'save_requirement_category':
            $input = alog_input();
            $category = $service->saveRequirementCategory($input);
            alog_json(200, array('ok' => true, 'category' => $category));

        default:
            alog_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    alog_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
