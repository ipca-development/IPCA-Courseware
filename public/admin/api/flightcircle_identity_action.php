<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/FlightCircleHistoricalImportService.php';

cw_require_admin();

function flightcircle_identity_json(int $code, array $payload): void
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
    $mappingId = max(0, (int)($_POST['mapping_id'] ?? 0));
    $actorId = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $service = new FlightCircleHistoricalImportService($pdo);
    if ($action === 'bulk_create_users') {
        $mappingIds = array_values(array_filter(array_map('intval', (array)($_POST['mapping_ids'] ?? array()))));
        if ($mappingIds === array()) {
            throw new RuntimeException('Select at least one user suggestion.');
        }
        $created = array();
        $failed = array();
        $createdCount = 0;
        $mappedExistingCount = 0;
        foreach ($mappingIds as $id) {
            try {
                $result = $service->createUserForIdentityMapping($id, $actorId);
                $created[] = $result;
                if ((string)($result['status'] ?? '') === 'created_user') {
                    $createdCount++;
                } elseif (in_array((string)($result['status'] ?? ''), array('confirmed', 'matched_existing_user'), true)) {
                    $mappedExistingCount++;
                }
            } catch (Throwable $e) {
                $failed[] = array('mapping_id' => $id, 'error' => $e->getMessage());
            }
        }
        flightcircle_identity_json(200, array('ok' => true, 'created_count' => $createdCount, 'mapped_existing_count' => $mappedExistingCount, 'failed_count' => count($failed), 'created' => $created, 'failed' => $failed));
    }
    if ($action === 'create_user') {
        flightcircle_identity_json(200, $service->createUserForIdentityMapping($mappingId, $actorId));
    }
    if ($action === 'map_existing') {
        $userId = max(0, (int)($_POST['user_id'] ?? 0));
        flightcircle_identity_json(200, $service->mapIdentityToExistingUser($mappingId, $userId, $actorId));
    }
    throw new RuntimeException('Unknown FlightCircle identity action.');
} catch (Throwable $e) {
    flightcircle_identity_json(500, array('ok' => false, 'error' => $e->getMessage()));
}
