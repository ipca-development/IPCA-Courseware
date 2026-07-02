<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/G3XFlightStreamParser.php';
require_once __DIR__ . '/../src/CockpitReplayPipeline.php';

$csvPath = '';
$outputPath = '';

foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvPath = trim(substr($arg, strlen('--csv=')));
    } elseif (str_starts_with($arg, '--output=')) {
        $outputPath = trim(substr($arg, strlen('--output=')));
    }
}

if ($csvPath === '') {
    fwrite(STDERR, "Usage: php scripts/build_standalone_g3x_replay.php --csv=/path/to/g3x.csv [--output=/path/to/replay.json]\n");
    exit(1);
}

try {
    $realCsvPath = realpath($csvPath);
    if ($realCsvPath === false || !is_file($realCsvPath)) {
        throw new RuntimeException('G3X CSV file not found.');
    }

    $parsed = G3XFlightStreamParser::parseFile($realCsvPath);
    $g3xSamples = standalone_g3x_samples($parsed['rows']);
    if (!$g3xSamples) {
        throw new RuntimeException('G3X CSV contains no timestamped samples.');
    }

    $firstRow = $g3xSamples[0]['row'];
    $lastRow = $g3xSamples[count($g3xSamples) - 1]['row'];
    $duration = max(0.0, (float)$g3xSamples[count($g3xSamples) - 1]['seconds']);
    $startedAt = G3XFlightStreamParser::rowUtcTimestamp($firstRow);

    $recording = array(
        'id' => 0,
        'recording_uid' => 'standalone-g3x-' . substr(sha1($realCsvPath), 0, 10),
        'started_at' => $startedAt !== null ? $startedAt->format(DateTimeInterface::ATOM) : null,
        'duration_seconds' => $duration,
        'aircraft_registration' => (string)$parsed['aircraft_ident'],
        'aircraft_display_name' => (string)$parsed['aircraft_ident'],
        'aircraft_type' => '',
    );

    $replay = (new CockpitReplayPipeline())->build(
        $recording,
        array(),
        array(),
        $g3xSamples,
        array(),
        array('replay_source_mode' => 'g3x_only')
    );

    $diagnostics = $replay['diagnostics'];
    $samples = standalone_public_samples($replay['samples']);
    unset($replay['samples']);
    $warnings = isset($diagnostics['warnings']) && is_array($diagnostics['warnings'])
        ? $diagnostics['warnings']
        : array();

    $payload = array(
        'ok' => true,
        'version' => 2,
        'standalone' => true,
        'recording' => array(
            'id' => 0,
            'recording_id' => (string)$recording['recording_uid'],
            'duration' => $duration,
            'started_at' => $recording['started_at'],
            'aircraft' => array(
                'registration' => (string)$recording['aircraft_registration'],
                'display_name' => (string)$recording['aircraft_display_name'],
                'type' => '',
            ),
            'audio_url' => null,
            'reconstruction_status' => 'standalone',
            'g3x_available' => true,
        ),
        'source' => array(
            'mode' => 'g3x_only',
            'csv_path' => $realCsvPath,
            'csv_sha1' => sha1_file($realCsvPath),
            'aircraft_ident' => (string)$parsed['aircraft_ident'],
            'product' => (string)$parsed['product'],
            'row_count' => (int)$parsed['row_count'],
            'first_utc' => standalone_row_utc_string($firstRow),
            'last_utc' => standalone_row_utc_string($lastRow),
        ),
        'sample_rate_hz' => (int)($diagnostics['sample_rate_hz'] ?? 10),
        'fixed_timestep_s' => (float)($diagnostics['fixed_timestep_s'] ?? 0.1),
        'raw_gps_count' => 0,
        'raw_ahrs_count' => 0,
        'raw_g3x_count' => (int)($diagnostics['raw_g3x_count'] ?? count($g3xSamples)),
        'replay_sample_count' => count($samples),
        'max_raw_g3x_gap_s' => isset($diagnostics['max_raw_g3x_gap_s']) ? (float)$diagnostics['max_raw_g3x_gap_s'] : null,
        'max_replay_dt_s' => isset($diagnostics['max_replay_dt_s']) ? (float)$diagnostics['max_replay_dt_s'] : null,
        'diagnostics' => $diagnostics,
        'warnings' => $warnings,
        'phases' => array(),
        'events' => array(),
        'samples' => $samples,
    );

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode standalone replay payload.');
    }

    if ($outputPath !== '') {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create output directory.');
        }
        file_put_contents($outputPath, $json . PHP_EOL);
        echo json_encode(array(
            'ok' => true,
            'output' => $outputPath,
            'samples' => count($samples),
            'duration_s' => $duration,
            'max_raw_g3x_gap_s' => $payload['max_raw_g3x_gap_s'],
            'warnings' => $warnings,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $json . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Standalone G3X replay build failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param list<array<string,string>> $rows
 * @return list<array{seconds:float,row:array<string,string>}>
 */
function standalone_g3x_samples(array $rows): array
{
    $samples = array();
    $firstTow = null;
    $firstUtc = null;

    foreach ($rows as $row) {
        $tow = G3XFlightStreamParser::numericValue($row, 'GPS Time of Week (sec)');
        if ($tow !== null) {
            $firstTow ??= $tow;
            $seconds = (float)$tow - (float)$firstTow;
            $samples[] = array('seconds' => $seconds, 'row' => $row);
            continue;
        }

        $utc = G3XFlightStreamParser::rowUtcTimestamp($row);
        if ($utc === null) {
            continue;
        }
        $firstUtc ??= $utc;
        $samples[] = array(
            'seconds' => (float)($utc->getTimestamp() - $firstUtc->getTimestamp()),
            'row' => $row,
        );
    }

    usort($samples, static fn(array $a, array $b): int => ((float)$a['seconds']) <=> ((float)$b['seconds']));
    return $samples;
}

/**
 * @param list<array<string,mixed>> $samples
 * @return list<array<string,mixed>>
 */
function standalone_public_samples(array $samples): array
{
    $public = array();
    $numericFields = array(
        't',
        'lat',
        'lon',
        'altitude_ft',
        'heading_deg',
        'pitch_deg',
        'roll_deg',
        'ground_speed_kt',
        'vertical_speed_fpm',
        'heading_deg_true',
        'heading_deg_magnetic',
        'track_deg_true',
        'wind_direction_deg_true',
        'magnetic_variation_deg',
        'compass_deviation_deg',
        'crab_angle_deg',
        'raw_pitch_deg',
        'raw_roll_deg',
    );
    $stringFields = array(
        'phase',
        'position_quality',
        'altitude_quality',
        'attitude_quality',
        'magnetic_variation_source',
        'compass_deviation_source',
        'heading_reference',
        'track_reference',
        'heading_source',
        'heading_owner',
        'heading_quality',
        'track_source',
        'track_quality',
        'speed_source',
        'speed_quality',
        'position_source',
        'altitude_source',
        'position_quality_reason',
        'altitude_quality_reason',
        'attitude_quality_reason',
        'heading_quality_reason',
        'track_quality_reason',
        'speed_quality_reason',
        'raw_attitude_source',
        'raw_attitude_quality',
    );

    foreach ($samples as $sample) {
        $row = array();
        foreach ($numericFields as $field) {
            if (array_key_exists($field, $sample)) {
                $row[$field] = $sample[$field] !== null && is_numeric($sample[$field]) ? (float)$sample[$field] : null;
            }
        }
        foreach ($stringFields as $field) {
            if (array_key_exists($field, $sample)) {
                $row[$field] = (string)($sample[$field] ?? '');
            }
        }

        $row['altitude_ft_msl'] = $row['altitude_ft'] ?? null;
        $row['visual_pitch_deg'] = isset($row['pitch_deg']) && is_numeric($row['pitch_deg']) ? round((float)$row['pitch_deg'], 2) : 0.0;
        $row['visual_roll_deg'] = isset($row['roll_deg']) && is_numeric($row['roll_deg']) ? round((float)$row['roll_deg'], 2) : 0.0;
        $public[] = $row;
    }

    return $public;
}

/**
 * @param array<string,string> $row
 */
function standalone_row_utc_string(array $row): ?string
{
    $date = trim((string)($row['Date (yyyy-mm-dd)'] ?? ''));
    $utc = trim((string)($row['UTC Time (hh:mm:ss)'] ?? ''));
    return $date !== '' && $utc !== '' ? $date . ' ' . $utc : null;
}
