<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/StandaloneG3XReplayBuilder.php';

cw_require_admin();

function cockpit_garmin_replay_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function cockpit_garmin_replay_storage_path(string $storagePath): string
{
    $storagePath = trim($storagePath);
    if ($storagePath === '') {
        throw new RuntimeException('Garmin CSV storage path is empty.');
    }
    $projectRoot = CockpitRecorderService::projectRoot();
    $candidates = str_starts_with($storagePath, '/')
        ? array($storagePath)
        : array($projectRoot . '/' . ltrim($storagePath, '/'), $projectRoot . '/storage/cvr/' . ltrim($storagePath, '/'));
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Stored Garmin CSV file is not available on this server.');
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

    $stmt = $pdo->prepare('SELECT * FROM ipca_garmin_csv_files WHERE id = ? LIMIT 1');
    $stmt->execute(array($csvFileId));
    $csvFile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($csvFile)) {
        throw new RuntimeException('Garmin CSV evidence record not found.');
    }

    $csvPath = cockpit_garmin_replay_storage_path((string)($csvFile['storage_path'] ?? ''));
    $aircraftLabel = trim((string)($csvFile['aircraft_registration'] ?? ''));
    if ($aircraftLabel === '') {
        $aircraftLabel = trim((string)($csvFile['aircraft_ident'] ?? ''));
    }
    $payload = (new StandaloneG3XReplayBuilder())->build(
        $csvPath,
        (string)($csvFile['import_profile'] ?? ''),
        $aircraftLabel
    );

    $payloadDir = CockpitRecorderService::projectRoot() . '/storage/tmp';
    if (!is_dir($payloadDir) && !mkdir($payloadDir, 0775, true) && !is_dir($payloadDir)) {
        throw new RuntimeException('Could not create replay payload directory.');
    }
    if (!is_writable($payloadDir)) {
        throw new RuntimeException('Replay payload directory is not writable.');
    }

    $key = 'garmin_csv_replay_' . $csvFileId . '_' . gmdate('Ymd_His') . '_' . substr(sha1((string)($csvFile['sha256'] ?? '') . '|' . random_bytes(8)), 0, 10);
    $payloadPath = $payloadDir . '/' . $key . '.json';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode Garmin replay payload.');
    }
    if (file_put_contents($payloadPath, $json . PHP_EOL) === false) {
        throw new RuntimeException('Could not store Garmin replay payload.');
    }

    cockpit_garmin_replay_redirect('/admin/cockpit_recorder_replay.php?standalone=' . urlencode($key));
} catch (Throwable $e) {
    cockpit_garmin_replay_redirect('/admin/cockpit_recorder.php?error=' . urlencode($e->getMessage()));
}
