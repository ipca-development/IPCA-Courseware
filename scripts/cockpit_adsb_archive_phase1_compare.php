<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/CockpitRecorderService.php';
require_once __DIR__ . '/../src/AdsbTrafficArchiveService.php';
require_once __DIR__ . '/../src/LocalTrafficArchiveRepository.php';

$id = trim((string)($argv[1] ?? ''));
if ($id === '') {
    fwrite(STDERR, "Usage: php scripts/cockpit_adsb_archive_phase1_compare.php <recording-id-or-uid>\n");
    exit(2);
}

try {
    $pdo = cw_db();
    $recording = (new CockpitRecorderService($pdo))->recordingByAnyId($id);
    if (!$recording) {
        throw new RuntimeException('Recording not found.');
    }
    $startedAt = trim((string)($recording['started_at'] ?? ''));
    $durationSeconds = max(0, (int)round((float)($recording['duration_seconds'] ?? 0)));
    if ($startedAt === '' || $durationSeconds <= 0) {
        throw new RuntimeException('Recording has no usable UTC time window.');
    }
    $start = (new DateTimeImmutable($startedAt, new DateTimeZone('UTC')))->modify('-30 seconds')->format(DateTimeInterface::ATOM);
    $end = (new DateTimeImmutable($startedAt, new DateTimeZone('UTC')))->modify('+' . ($durationSeconds + 30) . ' seconds')->format(DateTimeInterface::ATOM);
    $path = replayPathPoints($pdo, (int)$recording['id']);

    $legacy = new AdsbTrafficArchiveService($pdo);
    $repo = new LocalTrafficArchiveRepository($pdo);
    $legacyPayload = $path !== array()
        ? $legacy->trafficForReplayPath($start, $end, $path, 25.0)
        : $legacy->trafficForReplay($start, $end);
    $repoPayload = $path !== array()
        ? $repo->trafficForReplayPath($start, $end, $path, 25.0)
        : $repo->trafficForReplay($start, $end);

    $legacyKeys = sampleKeys((array)($legacyPayload['traffic'] ?? array()));
    $repoKeys = sampleKeys((array)($repoPayload['traffic'] ?? array()));
    $missing = array_values(array_diff($legacyKeys, $repoKeys));
    $added = array_values(array_diff($repoKeys, $legacyKeys));
    echo json_encode(array(
        'ok' => $missing === array(),
        'recording_id' => (int)$recording['id'],
        'recording_uid' => (string)($recording['recording_uid'] ?? ''),
        'window' => array('start_utc' => $start, 'end_utc' => $end),
        'path_point_count' => count($path),
        'legacy_sample_count' => count($legacyKeys),
        'repository_sample_count' => count($repoKeys),
        'legacy_samples_missing_from_repository' => count($missing),
        'repository_samples_not_in_legacy' => count($added),
        'first_missing_keys' => array_slice($missing, 0, 20),
        'legacy_meta' => $legacyPayload['meta'] ?? array(),
        'repository_meta' => $repoPayload['meta'] ?? array(),
    ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($missing === array() ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/**
 * @return list<array{lat:float,lon:float}>
 */
function replayPathPoints(PDO $pdo, int $recordingId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_cockpit_replay_samples'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() <= 0) {
        return array();
    }
    $stmt = $pdo->prepare('
        SELECT latitude, longitude
        FROM ipca_cockpit_replay_samples
        WHERE recording_id = ?
          AND latitude IS NOT NULL
          AND longitude IS NOT NULL
        ORDER BY sample_index ASC
    ');
    $stmt->execute(array($recordingId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || $rows === array()) {
        return array();
    }
    $count = count($rows);
    $maxPoints = 24;
    $points = array();
    for ($i = 0; $i < min($maxPoints, $count); $i++) {
        $index = (int)round($i * ($count - 1) / max(1, min($maxPoints, $count) - 1));
        $row = $rows[$index];
        if (is_numeric($row['latitude'] ?? null) && is_numeric($row['longitude'] ?? null)) {
            $points[] = array('lat' => (float)$row['latitude'], 'lon' => (float)$row['longitude']);
        }
    }
    return $points;
}

/**
 * @param list<array<string,mixed>> $samples
 * @return list<string>
 */
function sampleKeys(array $samples): array
{
    $keys = array_map(static function (array $sample): string {
        return implode('|', array(
            strtolower((string)($sample['hex'] ?? '')),
            (string)($sample['utc'] ?? ''),
            isset($sample['lat']) ? (string)round((float)$sample['lat'], 5) : '',
            isset($sample['lon']) ? (string)round((float)$sample['lon'], 5) : '',
            isset($sample['alt']) ? (string)round((float)$sample['alt'], 0) : '',
        ));
    }, $samples);
    sort($keys);
    return $keys;
}
