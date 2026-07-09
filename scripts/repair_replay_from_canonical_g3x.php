<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CockpitRecorderService.php';
require_once __DIR__ . '/../src/CockpitReconstructionService.php';
require_once __DIR__ . '/../src/CockpitReplayPipeline.php';

$recordingId = (int)($argv[1] ?? 0);
if ($recordingId <= 0) {
    fwrite(STDERR, "Usage: php scripts/repair_replay_from_canonical_g3x.php RECORDING_ID\n");
    exit(1);
}

@set_time_limit(0);
@ini_set('memory_limit', '2048M');

try {
    $recorder = new CockpitRecorderService($pdo);
    $recording = $recorder->recordingByAnyId((string)$recordingId);
    if (!$recording) {
        throw new RuntimeException('Recording not found.');
    }

    $stmt = $pdo->prepare('
        SELECT seconds_since_start, latitude, longitude, groundspeed_kt, pitch_deg, roll_deg, g3x_row_json
        FROM ipca_cockpit_flight_samples
        WHERE recording_id = ?
        ORDER BY seconds_since_start ASC
    ');
    $stmt->execute(array($recordingId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        throw new RuntimeException('No canonical flight samples found.');
    }

    $formatCoord = static function (?float $value, int $decimals, bool $signed = false): string {
        if ($value === null) {
            return '';
        }
        $prefix = $signed && $value >= 0.0 ? '+' : '';
        return $prefix . number_format($value, $decimals, '.', '');
    };

    $g3xSamples = array();
    foreach ($rows as $row) {
        $raw = trim((string)($row['g3x_row_json'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        $latitude = $row['latitude'] !== null ? (float)$row['latitude'] : null;
        $longitude = $row['longitude'] !== null ? (float)$row['longitude'] : null;
        if ($latitude !== null) {
            $decoded['Latitude (deg)'] = $formatCoord($latitude, 7, true);
        }
        if ($longitude !== null) {
            $decoded['Longitude'] = $formatCoord($longitude, 7, true);
            $decoded['Longitude (deg)'] = $decoded['Longitude'];
        }
        $g3xSamples[] = array(
            'seconds' => (float)($row['seconds_since_start'] ?? 0),
            'row' => array_map(static fn($value): string => (string)$value, $decoded),
        );
    }
    if ($g3xSamples === array()) {
        throw new RuntimeException('Canonical samples are missing g3x_row_json.');
    }

    $service = new CockpitReconstructionService($pdo);
    $canonical = array_map(static function (array $row): array {
        return array(
            'seconds_since_start' => (float)($row['seconds_since_start'] ?? 0),
            'groundspeed_kt' => $row['groundspeed_kt'] !== null ? (float)$row['groundspeed_kt'] : null,
            'pitch_deg' => $row['pitch_deg'] !== null ? (float)$row['pitch_deg'] : null,
            'roll_deg' => $row['roll_deg'] !== null ? (float)$row['roll_deg'] : null,
        );
    }, $rows);
    if (!$canonical) {
        throw new RuntimeException('Could not load canonical samples for timeline detection.');
    }

    $reflection = new ReflectionClass($service);
    $detectTimeline = $reflection->getMethod('detectTimeline');
    $detectTimeline->setAccessible(true);
    $timeline = $detectTimeline->invoke($service, $recording, $canonical);

    $pipeline = new CockpitReplayPipeline();
    $replayResult = $pipeline->build(
        $recording,
        array(),
        array(),
        $g3xSamples,
        $timeline['phases'],
        array('replay_source_mode' => 'g3x_only')
    );

    $deleteReplaySamples = $reflection->getMethod('deleteReplaySamples');
    $deleteReplaySamples->setAccessible(true);
    $storeReplaySamples = $reflection->getMethod('storeReplaySamples');
    $storeReplaySamples->setAccessible(true);

    $pdo->beginTransaction();
    $deleteReplaySamples->invoke($service, $recordingId);
    $storeReplaySamples->invoke($service, $recordingId, $replayResult['samples'], null);

    $summaryRaw = trim((string)($recording['reconstruction_summary_json'] ?? ''));
    $summary = $summaryRaw !== '' ? json_decode($summaryRaw, true) : array();
    if (!is_array($summary)) {
        $summary = array();
    }
    $summary['replay_v2'] = $replayResult['diagnostics'];
    $summary['reconstruction_profiling']['replay_repair_from_canonical_g3x'] = gmdate('c');
    $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $pdo->prepare('
        UPDATE ipca_cockpit_recordings
        SET reconstruction_status = \'ready\',
            timeline_status = \'ready\',
            error_message = NULL,
            reconstruction_summary_json = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ')->execute(array($summaryJson ?: null, $recordingId));

    $pdo->commit();

    $samples = $replayResult['samples'];
    $first = $samples[0] ?? array();
    $mid = $samples[(int)floor(count($samples) / 2)] ?? array();
    $last = $samples[count($samples) - 1] ?? array();
    echo 'Replay repair complete for recording ' . $recordingId . PHP_EOL;
    echo 'Replay samples: ' . number_format(count($samples)) . PHP_EOL;
    echo 'Rejected position knots: ' . (int)($replayResult['diagnostics']['raw_position_outliers_rejected'] ?? 0) . PHP_EOL;
    echo 't=0 lat/lon: ' . ($first['lat'] ?? 'null') . ', ' . ($first['lon'] ?? 'null') . PHP_EOL;
    echo 'mid lat/lon: ' . ($mid['lat'] ?? 'null') . ', ' . ($mid['lon'] ?? 'null') . PHP_EOL;
    echo 'end lat/lon: ' . ($last['lat'] ?? 'null') . ', ' . ($last['lon'] ?? 'null') . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Replay repair failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
