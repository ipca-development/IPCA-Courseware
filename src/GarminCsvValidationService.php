<?php
declare(strict_types=1);

require_once __DIR__ . '/G3XFlightStreamParser.php';
require_once __DIR__ . '/GarminFlightDataSourceClassificationService.php';
require_once __DIR__ . '/ValidationResultService.php';

final class GarminCsvValidationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function validateFile(int $csvFileId, string $path): array
    {
        $classification = (new GarminFlightDataSourceClassificationService($this->pdo))->classifyPath($path);
        $dataLogType = (string)($classification['data_log_type'] ?? 'INVALID');
        $capabilities = is_array($classification['capabilities'] ?? null) ? $classification['capabilities'] : array();
        if ($dataLogType === 'GPS_ONLY') {
            $warnings = array('GPS-only Garmin log is valid for session matching and GPS replay but cannot support Hobbs, Tacho, fuel, attitude, or full operational calculations.');
            $details = array(
                'data_log_type' => 'GPS_ONLY',
                'capabilities' => $capabilities,
                'classification_reason' => $classification['classification_reason'] ?? null,
                'detected_columns' => $classification['detected_columns'] ?? array(),
            );
            $this->store($csvFileId, 'FLIGHT_DATA_GPS_ONLY', ValidationResultService::INFO, (int)($classification['valid_sample_count'] ?? 0), (int)($classification['valid_sample_count'] ?? 0), $warnings, array(), $details);
            return array('status' => 'FLIGHT_DATA_GPS_ONLY', 'severity' => ValidationResultService::INFO, 'warnings' => $warnings, 'errors' => array(), 'details' => $details);
        }
        if ($dataLogType === 'INVALID' || (string)($classification['format_status'] ?? '') === 'invalid') {
            $errors = array((string)($classification['classification_reason'] ?? 'Garmin source format is invalid.'));
            $this->store($csvFileId, 'FLIGHT_DATA_INVALID', ValidationResultService::INVALID, 0, 0, array(), $errors, array('classification' => $classification));
            return array('status' => 'FLIGHT_DATA_INVALID', 'severity' => ValidationResultService::INVALID, 'warnings' => array(), 'errors' => $errors, 'details' => array('classification' => $classification));
        }
        $warnings = array();
        $errors = array();
        try {
            $parsed = G3XFlightStreamParser::parseFile($path);
        } catch (Throwable $e) {
            if ($dataLogType === 'PARTIAL_AVIONICS' || $dataLogType === 'UNKNOWN_SUPPORTED') {
                $warnings[] = 'Garmin source is supported by capability scan but not by the strict G3X parser; operational use requires review.';
                $details = array(
                    'data_log_type' => $dataLogType,
                    'capabilities' => $capabilities,
                    'classification_reason' => $classification['classification_reason'] ?? null,
                    'strict_parser_error' => $e->getMessage(),
                );
                $this->store($csvFileId, 'FLIGHT_DATA_PARTIAL', ValidationResultService::WARNING, (int)($classification['valid_sample_count'] ?? 0), (int)($classification['valid_sample_count'] ?? 0), $warnings, array(), $details);
                return array('status' => 'FLIGHT_DATA_PARTIAL', 'severity' => ValidationResultService::WARNING, 'errors' => array(), 'warnings' => $warnings, 'details' => $details);
            }
            $this->store($csvFileId, 'FLIGHT_DATA_INVALID', ValidationResultService::INVALID, 0, 0, array(), array($e->getMessage()), array('classification' => $classification));
            return array('status' => 'FLIGHT_DATA_INVALID', 'severity' => ValidationResultService::INVALID, 'errors' => array($e->getMessage()), 'warnings' => array(), 'details' => array('classification' => $classification));
        }

        $rows = $parsed['rows'];
        $validTimestampCount = 0;
        $hasRpm = false;
        $hasFuelQty = false;
        $hasFuelFlow = false;
        $hasGps = false;
        foreach ($rows as $row) {
            if (G3XFlightStreamParser::rowUtcTimestamp($row) !== null) {
                $validTimestampCount++;
            }
            $hasRpm = $hasRpm || G3XFlightStreamParser::numericValue($row, 'RPM', 'E1 RPM') !== null;
            $hasFuelQty = $hasFuelQty || G3XFlightStreamParser::numericValue($row, 'Fuel Qty (gal)', 'FQty1') !== null;
            $hasFuelFlow = $hasFuelFlow || G3XFlightStreamParser::numericValue($row, 'Fuel Flow (gal/hour)', 'E1 FFlow') !== null;
            $lat = G3XFlightStreamParser::numericValue($row, 'Latitude (deg)', 'Latitude');
            $lon = G3XFlightStreamParser::numericValue($row, 'Longitude (deg)', 'Longitude');
            $hasGps = $hasGps || ($lat !== null && $lon !== null);
        }

        if ($validTimestampCount === 0) {
            $errors[] = 'CSV contains no valid UTC timestamps.';
        }
        if (!$hasGps) {
            $errors[] = 'CSV contains no usable GPS latitude/longitude samples.';
        }
        if (!$hasRpm) {
            $warnings[] = 'CSV contains no RPM samples; Hobbs engine-state detection will require fallback data.';
        }
        if (!$hasFuelQty) {
            $warnings[] = 'CSV contains no fuel quantity samples.';
        }
        if (!$hasFuelFlow) {
            $warnings[] = 'CSV contains no fuel flow samples.';
        }

        $status = $errors ? 'FLIGHT_DATA_INVALID' : ($warnings ? 'FLIGHT_DATA_PARTIAL' : 'FLIGHT_DATA_OK');
        $severity = $errors ? ValidationResultService::INVALID : ($warnings ? ValidationResultService::WARNING : ValidationResultService::INFO);
        $details = array(
            'data_log_type' => $dataLogType,
            'capabilities' => $capabilities,
            'aircraft_ident' => $parsed['aircraft_ident'],
            'product' => $parsed['product'],
            'import_profile' => $parsed['import_profile'],
            'row_count' => count($rows),
            'valid_timestamp_count' => $validTimestampCount,
            'has_rpm' => $hasRpm,
            'has_fuel_quantity' => $hasFuelQty,
            'has_fuel_flow' => $hasFuelFlow,
            'has_gps' => $hasGps,
            'warnings' => $warnings,
            'errors' => $errors,
        );
        $this->store($csvFileId, $status, $severity, count($rows), $validTimestampCount, $warnings, $errors, $details);
        return array('status' => $status, 'severity' => $severity, 'warnings' => $warnings, 'errors' => $errors, 'details' => $details);
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     * @param array<string,mixed> $details
     */
    private function store(int $csvFileId, string $status, string $severity, int $rowCount, int $validTimestampCount, array $warnings, array $errors, array $details = array()): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_garmin_csv_validation_results
              (validation_uuid, csv_file_id, status, severity, row_count, valid_timestamp_count, warning_count, error_count, details_json)
            VALUES
              (:validation_uuid, :csv_file_id, :status, :severity, :row_count, :valid_timestamp_count, :warning_count, :error_count, :details_json)
        ");
        $stmt->execute(array(
            ':validation_uuid' => AuditEventService::uuid(),
            ':csv_file_id' => $csvFileId,
            ':status' => $status,
            ':severity' => $severity,
            ':row_count' => $rowCount,
            ':valid_timestamp_count' => $validTimestampCount,
            ':warning_count' => count($warnings),
            ':error_count' => count($errors),
            ':details_json' => AuditEventService::jsonEncode($details),
        ));
    }
}
