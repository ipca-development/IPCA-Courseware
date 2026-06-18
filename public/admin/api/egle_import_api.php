<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/flight_training/AdminLogbookService.php';
require_once __DIR__ . '/../../../src/flight_training/EgleConnectionService.php';
require_once __DIR__ . '/../../../src/flight_training/EgleUserMappingService.php';
require_once __DIR__ . '/../../../src/flight_training/EgleLogbookSyncService.php';

header('Content-Type: application/json; charset=utf-8');

function egle_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function egle_input(): array
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
$connection = new EgleConnectionService();
$logbooks = new AdminLogbookService($pdo);
$mappings = new EgleUserMappingService($pdo, $connection);
$sync = new EgleLogbookSyncService($pdo, $logbooks, $connection, $mappings);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = egle_input();
    $action = (string)($input['action'] ?? '');
}

try {
    switch ($action) {
        case 'status':
            $logbookId = isset($_GET['logbook_id']) ? (int)$_GET['logbook_id'] : null;
            $ipcaUserId = isset($_GET['ipca_user_id']) ? (int)$_GET['ipca_user_id'] : null;
            $review = $sync->reviewChanges($logbookId);
            egle_json(200, array(
                'ok' => true,
                'connection' => $connection->status(),
                'mappings' => $mappings->allMappings(),
                'latest_run' => $sync->latestRun($ipcaUserId),
                'pending_review_count' => (int)($review['pending_review_count'] ?? 0),
            ));

        case 'test_connection':
            $input = egle_input();
            $credentials = $connection->credentialsFromInput($input);
            $result = $connection->testConnection($credentials);
            $connection->storeTemporaryCredentials($credentials);
            egle_json(200, array(
                'ok' => true,
                'result' => $result,
                'connection' => $connection->status($credentials),
            ));

        case 'disconnect':
            $connection->clearTemporaryCredentials();
            egle_json(200, array('ok' => true, 'connection' => $connection->status()));

        case 'search_users':
            $input = egle_input();
            $eglePdo = $connection->connect();
            $candidates = $mappings->searchEgleUsers(
                $eglePdo,
                (string)($input['query'] ?? ''),
                isset($input['ipca_user_id']) ? (int)$input['ipca_user_id'] : null
            );
            egle_json(200, array('ok' => true, 'candidates' => $candidates));

        case 'save_mapping':
            $input = egle_input();
            $mapping = $mappings->saveMapping($input, $actorUserId);
            egle_json(200, array('ok' => true, 'mapping' => $mapping, 'mappings' => $mappings->allMappings()));

        case 'delete_mapping':
            $input = egle_input();
            $mappings->deleteMapping((int)($input['ipca_user_id'] ?? 0));
            egle_json(200, array('ok' => true, 'mappings' => $mappings->allMappings()));

        case 'sync_student':
            $input = egle_input();
            $result = $sync->syncStudent((int)($input['ipca_user_id'] ?? 0), $actorUserId);
            egle_json(200, array('ok' => true, 'result' => $result));

        case 'sync_all_students':
            $result = $sync->syncAllStudents($actorUserId);
            egle_json(200, array('ok' => true, 'result' => $result));

        case 'review_changes':
            $input = egle_input();
            $result = $sync->reviewChanges(isset($input['logbook_id']) ? (int)$input['logbook_id'] : null);
            egle_json(200, array('ok' => true, 'result' => $result));

        default:
            egle_json(400, array('ok' => false, 'error' => 'Unknown action.'));
    }
} catch (Throwable $e) {
    egle_json(400, array('ok' => false, 'error' => $e->getMessage()));
}
