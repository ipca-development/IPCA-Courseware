<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/GarminCsvReplayPayloadService.php';

cw_require_admin();

function cockpit_garmin_replay_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    @set_time_limit(0);
    @ini_set('memory_limit', '2048M');

    $csvFileId = (int)($_POST['csv_file_id'] ?? 0);
    if ($csvFileId <= 0) {
        throw new RuntimeException('Garmin CSV file id is required.');
    }

    $force = in_array(strtolower(trim((string)($_POST['force'] ?? ''))), array('1', 'true', 'yes'), true);
    $payload = (new GarminCsvReplayPayloadService($pdo))->buildForCsvFileId($csvFileId, $force);
    $key = (string)($payload['replay_key'] ?? '');
    if ($key === '') {
        throw new RuntimeException('Garmin replay payload was not ready.');
    }

    cockpit_garmin_replay_redirect('/admin/cockpit_recorder_replay.php?standalone=' . urlencode($key));
} catch (Throwable $e) {
    cockpit_garmin_replay_redirect('/admin/cockpit_recorder.php?error=' . urlencode($e->getMessage()));
}
