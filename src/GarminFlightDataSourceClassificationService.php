<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/G3XFlightStreamParser.php';

final class GarminFlightDataSourceClassificationService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function classifyPath(string $path): array
    {
        if (!is_file($path)) {
            return $this->invalidReport('FILE_MISSING', 'Downloaded Garmin source file is missing.');
        }
        if (filesize($path) === 0) {
            return $this->invalidReport('EMPTY_FILE', 'Downloaded Garmin source file is empty.');
        }

        try {
            $parsed = G3XFlightStreamParser::parseFile($path);
            $headers = array_values(array_map('strval', $parsed['headers']));
            $rows = array_slice($parsed['rows'], 0, 500);
            $report = $this->capabilityReport($headers, $rows, true);
            $report['aircraft_ident'] = $parsed['aircraft_ident'];
            $report['product'] = $parsed['product'];
            $metadata = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : array();
            $report['system_identifier'] = (string)($metadata['system_id'] ?? $metadata['system_identifier'] ?? '');
            $report['airframe_hours_start'] = is_numeric($metadata['airframe_hours'] ?? null) ? (float)$metadata['airframe_hours'] : null;
            $report['engine_hours_start'] = is_numeric($metadata['engine_hours'] ?? null) ? (float)$metadata['engine_hours'] : null;
            $report['airframe_info_metadata'] = $metadata;
            $report['raw_header'] = (string)($parsed['raw_header'] ?? '');
            $report['flightstream_header'] = (string)($parsed['flightstream_header'] ?? ($metadata['flightstream_header'] ?? ''));
            $report['parser_profile'] = $parsed['import_profile'];
            $report['valid_sample_count'] = (int)$parsed['row_count'];
            $report['first_timestamp_utc'] = $this->formatTimestamp(G3XFlightStreamParser::firstUtcTimestamp($parsed['rows']));
            $report['last_timestamp_utc'] = $this->formatTimestamp(G3XFlightStreamParser::lastUtcTimestamp($parsed['rows']));
            return $report;
        } catch (Throwable $g3xError) {
            $generic = $this->parseGenericCsv($path, 500);
            if (!$generic['headers'] || !$generic['rows']) {
                return $this->invalidReport('UNRECOGNIZED_CSV', $g3xError->getMessage());
            }
            $report = $this->capabilityReport($generic['headers'], $generic['rows'], false);
            if ($report['data_log_type'] === 'UNKNOWN_SUPPORTED') {
                $report['classification_reason'] = 'Generic Garmin-like CSV parsed, but capabilities were limited: ' . $g3xError->getMessage();
            }
            return $report;
        }
    }

    public function classifySource(int $sourceId, string $path): array
    {
        if ($this->pdo === null) {
            throw new RuntimeException('A PDO connection is required to persist Garmin source classification.');
        }
        $report = $this->classifyPath($path);
        $stmt = $this->pdo->prepare("
            UPDATE ipca_garmin_flight_data_sources
            SET format_status = :format_status,
                data_log_type = :data_log_type,
                completeness_status = :completeness_status,
                capabilities_json = :capabilities_json,
                detected_columns_json = :detected_columns_json,
                parser_profile = :parser_profile,
                parser_version = :parser_version,
                valid_sample_count = :valid_sample_count,
                invalid_sample_count = :invalid_sample_count,
                csv_first_timestamp_utc = :csv_first_timestamp_utc,
                csv_last_timestamp_utc = :csv_last_timestamp_utc,
                field_coverage_json = :field_coverage_json,
                classification_reason = :classification_reason,
                supports_full_replay = :supports_full_replay,
                supports_gps_replay = :supports_gps_replay,
                supports_hobbs_calculation = :supports_hobbs_calculation,
                supports_tacho_calculation = :supports_tacho_calculation,
                supports_operational_flight_record = :supports_operational_flight_record,
                classified_at = CURRENT_TIMESTAMP(3),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':id' => $sourceId,
            ':format_status' => $report['format_status'],
            ':data_log_type' => $report['data_log_type'],
            ':completeness_status' => $report['completeness_status'],
            ':capabilities_json' => AuditEventService::jsonEncode($report['capabilities']),
            ':detected_columns_json' => AuditEventService::jsonEncode($report['detected_columns']),
            ':parser_profile' => $report['parser_profile'],
            ':parser_version' => $report['parser_version'],
            ':valid_sample_count' => $report['valid_sample_count'],
            ':invalid_sample_count' => $report['invalid_sample_count'],
            ':csv_first_timestamp_utc' => $report['first_timestamp_utc'] ?? null,
            ':csv_last_timestamp_utc' => $report['last_timestamp_utc'] ?? null,
            ':field_coverage_json' => AuditEventService::jsonEncode($report['field_coverage']),
            ':classification_reason' => $report['classification_reason'],
            ':supports_full_replay' => !empty($report['capabilities']['supports_full_replay']) ? 1 : 0,
            ':supports_gps_replay' => !empty($report['capabilities']['supports_gps_replay']) ? 1 : 0,
            ':supports_hobbs_calculation' => !empty($report['capabilities']['supports_hobbs_calculation']) ? 1 : 0,
            ':supports_tacho_calculation' => !empty($report['capabilities']['supports_tacho_calculation']) ? 1 : 0,
            ':supports_operational_flight_record' => !empty($report['capabilities']['supports_operational_flight_record']) ? 1 : 0,
        ));
        return $report;
    }

    /**
     * @param list<string> $headers
     * @param list<array<string,string>> $rows
     * @return array<string,mixed>
     */
    private function capabilityReport(array $headers, array $rows, bool $isStrictG3x): array
    {
        $normalizedHeaders = array();
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeHeader($header)] = $header;
        }
        $hasTime = $this->hasAny($normalizedHeaders, array('dateyyyy-mm-dd', 'lcldate', 'utctimehhmmss', 'utctime', 'timestamp', 'time'));
        $hasLat = $this->hasAny($normalizedHeaders, array('latitudedeg', 'latitude', 'lat'));
        $hasLon = $this->hasAny($normalizedHeaders, array('longitudedeg', 'longitude', 'lon', 'lng'));
        $hasGps = $hasLat && $hasLon;
        $hasGroundSpeed = $this->hasAny($normalizedHeaders, array('gpsgroundspeedkt', 'groundspeed', 'groundspeedkt', 'speed', 'speedkt'));
        $hasAltitude = $this->hasAny($normalizedHeaders, array('gpsaltitudeft', 'baroaltitudeft', 'pressurealtitudeft', 'altitude', 'altitudeft'));
        $hasRpm = $this->hasAny($normalizedHeaders, array('rpm', 'e1rpm', 'engine1rpm'));
        $hasAirframeHours = $this->hasAny($normalizedHeaders, array('airframehours', 'airframehrs', 'hobbs'));
        $hasEngineHours = $this->hasAny($normalizedHeaders, array('enginehours', 'enginehrs', 'tach', 'tacho'));
        $hasFuelFlow = $this->hasAny($normalizedHeaders, array('fuelflowgalhour', 'e1fflow', 'fuelflow'));
        $hasFuelQty = $this->hasAny($normalizedHeaders, array('fuelqtygal', 'fqty1', 'fuelquantity', 'fuelremaining'));
        $hasAttitude = $this->hasAny($normalizedHeaders, array('pitchdeg', 'rolldeg', 'pitch', 'roll'));
        $hasAirspeed = $this->hasAny($normalizedHeaders, array('indicatedairspeedkt', 'trueairspeedkt', 'ias', 'tas', 'airspeed'));

        $capabilities = array(
            'has_time' => $hasTime,
            'has_position' => $hasGps,
            'has_groundspeed' => $hasGroundSpeed,
            'has_altitude' => $hasAltitude,
            'has_rpm' => $hasRpm,
            'has_airframe_hours' => $hasAirframeHours,
            'has_engine_hours' => $hasEngineHours,
            'has_fuel_flow' => $hasFuelFlow,
            'has_fuel_quantity' => $hasFuelQty,
            'has_attitude' => $hasAttitude,
            'has_airspeed' => $hasAirspeed,
            'supports_gps_replay' => $hasTime && $hasGps,
            'supports_full_replay' => $hasTime && $hasGps && ($hasAttitude || $hasAirspeed || $hasAltitude),
            'supports_hobbs_calculation' => $hasTime && ($hasRpm || $hasAirframeHours),
            'supports_tacho_calculation' => $hasTime && ($hasRpm || $hasEngineHours),
            'supports_operational_flight_record' => $hasTime && $hasGps && ($hasRpm || $hasAirframeHours || $hasEngineHours),
            'supports_route_detection' => $hasTime && $hasGps,
            'supports_fuel_analysis' => $hasFuelFlow || $hasFuelQty,
        );

        if (!$hasTime || !$hasGps) {
            $type = 'INVALID';
            $format = 'invalid';
            $reason = 'Garmin source does not contain usable time and GPS position columns.';
        } elseif ($hasRpm && ($hasAirframeHours || $hasEngineHours || $hasFuelFlow || $hasAttitude || $hasAirspeed)) {
            $type = 'FULL_AVIONICS';
            $format = 'supported';
            $reason = 'Source contains time, GPS, RPM, and avionics/engine/fuel fields.';
        } elseif ($hasRpm || $hasAirframeHours || $hasEngineHours || $hasFuelFlow || $hasFuelQty || $hasAttitude || $hasAirspeed) {
            $type = 'PARTIAL_AVIONICS';
            $format = 'supported';
            $reason = 'Source contains GPS plus some avionics fields but is not complete.';
        } elseif ($hasTime && $hasGps) {
            $type = 'GPS_ONLY';
            $format = 'supported';
            $reason = 'Source contains valid time and GPS track data but no engine/avionics fields.';
        } else {
            $type = 'UNKNOWN_SUPPORTED';
            $format = 'supported';
            $reason = 'Source is parseable but capability coverage is not yet recognized.';
        }

        return array(
            'format_status' => $format,
            'data_log_type' => $type,
            'completeness_status' => $type === 'FULL_AVIONICS' ? 'complete' : ($type === 'INVALID' ? 'unknown' : 'partial'),
            'capabilities' => $capabilities,
            'detected_columns' => $headers,
            'canonical_fields' => array(),
            'field_coverage' => array(
                'sampled_rows' => count($rows),
                'header_count' => count($headers),
            ),
            'parser_profile' => $isStrictG3x ? 'G3X_STRICT' : 'GENERIC_GARMIN_CSV',
            'parser_version' => 'garmin-source-classifier-v1',
            'raw_header' => '',
            'flightstream_header' => '',
            'airframe_info_metadata' => array(),
            'valid_sample_count' => count($rows),
            'invalid_sample_count' => 0,
            'classification_reason' => $reason,
            'first_timestamp_utc' => $this->genericFirstTimestamp($rows),
            'last_timestamp_utc' => $this->genericLastTimestamp($rows),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function invalidReport(string $code, string $reason): array
    {
        return array(
            'format_status' => 'invalid',
            'data_log_type' => 'INVALID',
            'completeness_status' => 'unknown',
            'capabilities' => array(
                'has_time' => false,
                'has_position' => false,
                'supports_gps_replay' => false,
                'supports_full_replay' => false,
                'supports_hobbs_calculation' => false,
                'supports_tacho_calculation' => false,
                'supports_operational_flight_record' => false,
                'supports_route_detection' => false,
            ),
            'detected_columns' => array(),
            'canonical_fields' => array(),
            'field_coverage' => array('error_code' => $code),
            'parser_profile' => 'UNRECOGNIZED',
            'parser_version' => 'garmin-source-classifier-v1',
            'raw_header' => '',
            'flightstream_header' => '',
            'airframe_info_metadata' => array(),
            'valid_sample_count' => 0,
            'invalid_sample_count' => 0,
            'classification_reason' => $reason,
            'first_timestamp_utc' => null,
            'last_timestamp_utc' => null,
        );
    }

    /**
     * @return array{headers:list<string>,rows:list<array<string,string>>}
     */
    private function parseGenericCsv(string $path, int $maxRows): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return array('headers' => array(), 'rows' => array());
        }
        $headers = array();
        $rows = array();
        try {
            while (($raw = fgets($handle)) !== false) {
                $line = trim($raw);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $candidate = str_getcsv($line, ',', '"', '\\');
                if (count($candidate) >= 2) {
                    $headers = array_values(array_map(static fn($value): string => trim((string)$value), $candidate));
                    break;
                }
            }
            if (!$headers) {
                return array('headers' => array(), 'rows' => array());
            }
            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false && count($rows) < $maxRows) {
                if (!$values) {
                    continue;
                }
                $row = array();
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $row[$header] = trim((string)($values[$index] ?? ''));
                }
                if ($row) {
                    $rows[] = $row;
                }
            }
        } finally {
            fclose($handle);
        }
        return array('headers' => $headers, 'rows' => $rows);
    }

    /**
     * @param array<string,string> $headers
     * @param list<string> $candidates
     */
    private function hasAny(array $headers, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($this->normalizeHeader($candidate), $headers)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $header) ?? '');
    }

    private function formatTimestamp(?DateTimeImmutable $dt): ?string
    {
        return $dt !== null ? $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v') : null;
    }

    /**
     * @param list<array<string,string>> $rows
     */
    private function genericFirstTimestamp(array $rows): ?string
    {
        foreach ($rows as $row) {
            $timestamp = $this->genericTimestamp($row);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,string>> $rows
     */
    private function genericLastTimestamp(array $rows): ?string
    {
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $timestamp = $this->genericTimestamp($rows[$i]);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $row
     */
    private function genericTimestamp(array $row): ?string
    {
        $timestamp = '';
        foreach (array('timestamp', 'Timestamp', 'time', 'Time', 'UTC Time', 'DateTime', 'datetime') as $key) {
            if (isset($row[$key]) && trim($row[$key]) !== '') {
                $timestamp = trim($row[$key]);
                break;
            }
        }
        if ($timestamp === '') {
            $date = trim((string)($row['date'] ?? ($row['Date'] ?? '')));
            $time = trim((string)($row['UTC Time'] ?? ($row['Time'] ?? '')));
            if ($date !== '' && $time !== '') {
                $timestamp = $date . ' ' . $time;
            }
        }
        if ($timestamp === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (Throwable) {
            return null;
        }
    }
}
