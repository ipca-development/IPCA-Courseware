<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';

final class GarminCsvFingerprintService
{
    /**
     * @return array<string,mixed>
     */
    public function fingerprint(string $path, string $originalFilename = ''): array
    {
        $parsed = G3XFlightStreamParser::parseFile($path);
        $rows = $parsed['rows'];
        $headers = array_values(array_map(static fn($h): string => trim((string)$h), $parsed['headers']));
        $firstUtc = G3XFlightStreamParser::firstUtcTimestamp($rows);
        $lastUtc = G3XFlightStreamParser::lastUtcTimestamp($rows);
        $firstRows = array_slice($rows, 0, 25);
        $lastRows = array_slice($rows, -25);
        $gpsSummary = $this->gpsPathSummary($rows);

        $durationMs = null;
        if ($firstUtc !== null && $lastUtc !== null) {
            $durationMs = max(0, (int)round(((float)$lastUtc->format('U.u') - (float)$firstUtc->format('U.u')) * 1000));
        }

        $meta = $this->metaFromFirstLine($path);
        return array(
            'parser_version' => 'phase1-v1',
            'sha256' => hash_file('sha256', $path),
            'file_size_bytes' => filesize($path) ?: 0,
            'normalized_header_hash' => hash('sha256', implode('|', $headers)),
            'first_rows_hash' => hash('sha256', $this->normalizedRows($firstRows)),
            'last_rows_hash' => hash('sha256', $this->normalizedRows($lastRows)),
            'gps_path_summary_hash' => hash('sha256', AuditEventService::jsonEncode($gpsSummary)),
            'first_valid_sample_utc' => $firstUtc?->format('Y-m-d H:i:s.v'),
            'last_valid_sample_utc' => $lastUtc?->format('Y-m-d H:i:s.v'),
            'utc_duration_ms' => $durationMs,
            'valid_row_count' => count($rows),
            'aircraft_ident' => (string)$parsed['aircraft_ident'],
            'product' => (string)$parsed['product'],
            'import_profile' => (string)$parsed['import_profile'],
            'airframe_hours_start' => $meta['airframe_hours'] ?? null,
            'engine_hours_start' => $meta['engine_hours'] ?? null,
            'system_identifier' => (string)($meta['system_id'] ?? ''),
            'source_filename' => $originalFilename,
            'fingerprint_json' => array(
                'headers' => $headers,
                'gps_path_summary' => $gpsSummary,
                'meta' => $meta,
            ),
        );
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,mixed>
     */
    private function gpsPathSummary(array $rows): array
    {
        $points = array();
        $count = count($rows);
        if ($count === 0) {
            return array();
        }
        $indexes = array_unique(array_filter(array(
            0,
            (int)floor($count * 0.25),
            (int)floor($count * 0.5),
            (int)floor($count * 0.75),
            $count - 1,
        ), static fn($index): bool => $index >= 0 && $index < $count));
        foreach ($indexes as $index) {
            $row = $rows[$index];
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude');
            $time = G3XFlightStreamParser::rowUtcTimestamp($row);
            if ($lat !== null && $lon !== null && $time !== null) {
                $points[] = array(
                    't' => $time->format('c'),
                    'lat' => round($lat, 5),
                    'lon' => round($lon, 5),
                );
            }
        }
        return array('sampled_points' => $points);
    }

    /**
     * @param list<array<string,string>> $rows
     */
    private function normalizedRows(array $rows): string
    {
        $normalized = array();
        foreach ($rows as $row) {
            ksort($row);
            $normalized[] = $row;
        }
        return AuditEventService::jsonEncode($normalized);
    }

    /**
     * @return array<string,mixed>
     */
    private function metaFromFirstLine(string $path): array
    {
        $line = '';
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            $line = (string)fgets($handle);
            fclose($handle);
        }
        $meta = array();
        foreach (str_getcsv($line) as $part) {
            if (!is_string($part) || !str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $key = trim($key);
            $value = trim(trim($value), '"');
            if ($key !== '') {
                $meta[$key] = is_numeric($value) ? (float)$value : $value;
            }
        }
        return $meta;
    }
}
