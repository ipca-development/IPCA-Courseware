<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AsyncJobService.php';
require_once __DIR__ . '/../../../src/AuditEventService.php';
require_once __DIR__ . '/../../../src/GarminHistoricalBackfillService.php';
require_once __DIR__ . '/../../../src/FlightCircleGarminMatchService.php';

cw_require_admin();

function garmin_historical_action_json(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    $action = trim((string)($_POST['action'] ?? ''));
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['backfill_file_ids'] ?? array()))));
    if ($action === 'process_queued_inline') {
        $batchId = max(0, (int)($_POST['batch_id'] ?? 0));
        $limit = max(1, min(50, (int)($_POST['limit'] ?? 10)));
        $result = (new GarminHistoricalBackfillService($pdo))->processQueuedFiles($batchId, $limit);
        $result['status'] = (new GarminHistoricalBackfillService($pdo))->status(10, $batchId > 0 ? $batchId : null);
        garmin_historical_action_json(200, $result);
    }
    if ($ids === array()) {
        throw new RuntimeException('Select at least one historical Garmin file.');
    }
    $jobs = new AsyncJobService($pdo);
    $queued = 0;
    $changed = 0;
    if ($action === 'process_selected_inline') {
        $result = (new GarminHistoricalBackfillService($pdo))->processSelectedFiles($ids);
        $result['match'] = (new FlightCircleGarminMatchService($pdo))->matchSelectedGarminBackfillFiles($ids);
        garmin_historical_action_json(200, $result);
    }
    if ($action === 'match_flightcircle') {
        $result = (new FlightCircleGarminMatchService($pdo))->matchSelectedGarminBackfillFiles($ids);
        garmin_historical_action_json(200, $result);
    }
    if ($action === 'reprocess') {
        foreach ($ids as $id) {
            $stmt = $pdo->prepare('SELECT csv_file_id FROM ipca_garmin_historical_backfill_files WHERE id = ? LIMIT 1');
            $stmt->execute(array($id));
            $csvFileId = (int)$stmt->fetchColumn();
            if ($csvFileId > 0) {
                $jobs->enqueue('GARMIN_HISTORICAL_FILE_PROCESS', 'ipca_garmin_historical_backfill_files', (string)$id, array('backfill_file_id' => $id, 'csv_file_id' => $csvFileId, 'reprocess' => true), null, 120, 3, 'historical_backfill');
                $queued++;
            }
        }
    } elseif ($action === 'mark_avionics_only') {
        foreach ($ids as $id) {
            $pdo->prepare("
                UPDATE ipca_garmin_historical_backfill_files
                SET classification = 'Avionics Power On Only',
                    review_status = 'approved_ignored',
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = ?
            ")->execute(array($id));
            $pdo->prepare("
                UPDATE ipca_garmin_historical_segments
                SET classification = 'Avionics Power On Only',
                    review_status = 'approved_ignored',
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE backfill_file_id = ?
            ")->execute(array($id));
            $changed++;
        }
    } else {
        throw new RuntimeException('Unknown historical Garmin action.');
    }
    garmin_historical_action_json(200, array('ok' => true, 'queued' => $queued, 'changed' => $changed));
} catch (Throwable $e) {
    garmin_historical_action_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
