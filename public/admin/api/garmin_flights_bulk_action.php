<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/AsyncJobService.php';
require_once __DIR__ . '/../../../src/GarminCsvReplayPayloadService.php';

cw_require_admin();

function garmin_flights_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function garmin_flights_ensure_state_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ipca_garmin_flight_artifact_states (
          track_artifact_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
          hidden_at DATETIME(3) NULL,
          hidden_by_user_id BIGINT UNSIGNED NULL,
          hidden_reason VARCHAR(255) NOT NULL DEFAULT '',
          created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
          updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$return = trim((string)($_POST['return'] ?? '/admin/flight_log_garmin_connection.php'));
if (!str_starts_with($return, '/admin/flight_log_garmin_connection.php')) {
    $return = '/admin/flight_log_garmin_connection.php';
}
$separator = str_contains($return, '?') ? '&' : '?';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['track_artifact_ids'] ?? array())))));
    if (!$ids) {
        throw new RuntimeException('Select at least one Garmin flight.');
    }
    $action = trim((string)($_POST['action'] ?? ''));
    $user = cw_current_user($pdo) ?: array();
    $actorId = (int)($user['id'] ?? 0);
    garmin_flights_ensure_state_table($pdo);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'hide') {
        $reason = substr(trim((string)($_POST['reason'] ?? 'bulk hidden')), 0, 255);
        $stmt = $pdo->prepare("
            INSERT INTO ipca_garmin_flight_artifact_states
              (track_artifact_id, hidden_at, hidden_by_user_id, hidden_reason)
            VALUES " . implode(',', array_fill(0, count($ids), '(?, CURRENT_TIMESTAMP(3), ?, ?)')) . "
            ON DUPLICATE KEY UPDATE
              hidden_at = CURRENT_TIMESTAMP(3),
              hidden_by_user_id = VALUES(hidden_by_user_id),
              hidden_reason = VALUES(hidden_reason),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $params = array();
        foreach ($ids as $id) {
            array_push($params, $id, $actorId > 0 ? $actorId : null, $reason);
        }
        $stmt->execute($params);
        garmin_flights_redirect($return . $separator . 'flights_hidden=' . count($ids));
    }

    if ($action === 'restore') {
        $stmt = $pdo->prepare("UPDATE ipca_garmin_flight_artifact_states SET hidden_at = NULL, hidden_reason = '', updated_at = CURRENT_TIMESTAMP(3) WHERE track_artifact_id IN ({$placeholders})");
        $stmt->execute($ids);
        garmin_flights_redirect($return . $separator . 'flights_restored=' . count($ids));
    }

    if ($action === 'reprocess') {
        $jobs = new AsyncJobService($pdo);
        $queued = 0;
        foreach ($ids as $id) {
            if ($jobs->enqueue('GARMIN_TRACK_FLIGHT_SUMMARY', 'ipca_garmin_normalized_track_artifacts', (string)$id, array('track_artifact_id' => $id), null, 80) > 0) {
                $queued++;
            }
        }
        garmin_flights_redirect($return . $separator . 'flights_reprocess_queued=' . $queued);
    }

    if ($action === 'rebuild_replay') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id AS csv_file_id
            FROM ipca_garmin_normalized_track_artifacts t
            JOIN (
                SELECT provider_name, garmin_entry_uuid, canonical_track_uuid, MAX(garmin_csv_file_id) AS garmin_csv_file_id
                FROM ipca_garmin_flight_data_track_links
                GROUP BY provider_name, garmin_entry_uuid, canonical_track_uuid
            ) link
              ON link.provider_name = t.provider_name
             AND link.garmin_entry_uuid = t.garmin_entry_uuid
             AND link.canonical_track_uuid = t.track_uuid
            JOIN ipca_garmin_csv_files c ON c.id = link.garmin_csv_file_id
            LEFT JOIN ipca_garmin_track_flight_summaries s ON s.track_artifact_id = t.id
            WHERE t.id IN ({$placeholders})
              AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.summary_json, '$.status_label')), '') = 'Complete'
        ");
        $stmt->execute($ids);
        $csvIds = array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
        $service = new GarminCsvReplayPayloadService($pdo);
        $rebuilt = 0;
        foreach ($csvIds as $csvId) {
            $service->buildForCsvFileId($csvId, true);
            $rebuilt++;
        }
        garmin_flights_redirect($return . $separator . 'replays_rebuilt=' . $rebuilt);
    }

    throw new RuntimeException('Unknown bulk action.');
} catch (Throwable $e) {
    garmin_flights_redirect('/admin/flight_log_garmin_connection.php?error=' . urlencode($e->getMessage()));
}
