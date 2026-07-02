<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/CockpitReplayPipeline.php';

final class StandaloneG3XReplayBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(string $csvPath): array
    {
        $realCsvPath = realpath($csvPath);
        if ($realCsvPath === false || !is_file($realCsvPath)) {
            throw new RuntimeException('G3X CSV file not found.');
        }

        $parsed = G3XFlightStreamParser::parseFile($realCsvPath);
        $g3xSamples = $this->g3xSamples($parsed['rows']);
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
        $samples = $this->publicSamples($replay['samples']);
        unset($replay['samples']);
        $warnings = isset($diagnostics['warnings']) && is_array($diagnostics['warnings'])
            ? $diagnostics['warnings']
            : array();

        return array(
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
                'first_utc' => $this->rowUtcString($firstRow),
                'last_utc' => $this->rowUtcString($lastRow),
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
    }

    /**
     * @param list<array<string,string>> $rows
     * @return list<array{seconds:float,row:array<string,string>}>
     */
    private function g3xSamples(array $rows): array
    {
        $samples = array();
        $firstTow = null;
        $firstUtc = null;

        foreach ($rows as $row) {
            $tow = G3XFlightStreamParser::numericValue($row, 'GPS Time of Week (sec)');
            if ($tow !== null) {
                $firstTow ??= $tow;
                $samples[] = array('seconds' => (float)$tow - (float)$firstTow, 'row' => $row);
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
    private function publicSamples(array $samples): array
    {
        $public = array();
        $numericFields = array('t', 'lat', 'lon', 'altitude_ft', 'heading_deg', 'pitch_deg', 'roll_deg', 'ground_speed_kt', 'vertical_speed_fpm', 'heading_deg_true', 'heading_deg_magnetic', 'track_deg_true', 'wind_direction_deg_true', 'magnetic_variation_deg', 'compass_deviation_deg', 'crab_angle_deg', 'raw_pitch_deg', 'raw_roll_deg');
        $stringFields = array('phase', 'position_quality', 'altitude_quality', 'attitude_quality', 'magnetic_variation_source', 'compass_deviation_source', 'heading_reference', 'track_reference', 'heading_source', 'heading_owner', 'heading_quality', 'track_source', 'track_quality', 'speed_source', 'speed_quality', 'position_source', 'altitude_source', 'position_quality_reason', 'altitude_quality_reason', 'attitude_quality_reason', 'heading_quality_reason', 'track_quality_reason', 'speed_quality_reason', 'raw_attitude_source', 'raw_attitude_quality');

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
    private function rowUtcString(array $row): ?string
    {
        $date = trim((string)($row['Date (yyyy-mm-dd)'] ?? ''));
        $utc = trim((string)($row['UTC Time (hh:mm:ss)'] ?? ''));
        return $date !== '' && $utc !== '' ? $date . ' ' . $utc : null;
    }
}
