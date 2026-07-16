<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';

cw_require_admin();

function cockpit_bulk_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * @return list<int>
 */
function cockpit_bulk_recording_ids(): array
{
    $rawIds = $_POST['ids'] ?? $_POST['id'] ?? array();
    if (!is_array($rawIds)) {
        $rawIds = array($rawIds);
    }

    $ids = array();
    foreach ($rawIds as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

$action = trim((string)($_POST['action'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$return = trim((string)($_POST['return'] ?? '/admin/cockpit_recorder.php'));
if (!str_starts_with($return, '/admin/cockpit_recorder.php')) {
    $return = '/admin/cockpit_recorder.php';
}

try {
    $ids = cockpit_bulk_recording_ids();
    if (!$ids) {
        throw new RuntimeException('Select at least one recording.');
    }

    $service = new CockpitRecorderService($pdo);
    $actorUserId = isset($_SESSION['cw_user_id']) ? (int)$_SESSION['cw_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    if ($actorUserId !== null && $actorUserId <= 0) {
        $actorUserId = null;
    }

    if ($action === 'soft_delete') {
        $count = $service->softDeleteRecordings($ids, $actorUserId, $reason);
        cockpit_bulk_redirect($return . (str_contains($return, '?') ? '&' : '?') . 'recordings_hidden=' . urlencode((string)$count));
    }

    if ($action === 'restore') {
        $count = $service->restoreRecordings($ids);
        cockpit_bulk_redirect($return . (str_contains($return, '?') ? '&' : '?') . 'recordings_restored=' . urlencode((string)$count));
    }

    throw new RuntimeException('Unsupported cockpit recorder bulk action.');
} catch (Throwable $e) {
    cockpit_bulk_redirect('/admin/cockpit_recorder.php?error=' . urlencode($e->getMessage()));
}
