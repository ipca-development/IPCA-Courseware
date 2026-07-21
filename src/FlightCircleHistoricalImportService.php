<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditEventService.php';
require_once __DIR__ . '/FlightCircleGarminMatchService.php';

final class FlightCircleHistoricalImportService
{
    private const AIRCRAFT_RESOURCES = array('N397EA', 'N392EA', 'N482EA', 'N428EA', 'N446CS', 'N153PC', 'N641TH');
    private const IGNORED_RESOURCES = array('CLASSROOM I', 'CLASSROOM II', 'APPLE VISION PRO', 'EXAM ROOM', 'MAIN OFFICE');
    private const SIMULATOR_RESOURCE = 'AL172M2';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function importUploadedFile(string $tmpPath, string $originalFilename, ?int $createdByUserId = null, bool $replaceActiveDataset = true): array
    {
        if (!is_file($tmpPath)) {
            throw new RuntimeException('FlightCircle CSV upload is missing.');
        }
        $bytes = (string)file_get_contents($tmpPath);
        if ($bytes === '') {
            throw new RuntimeException('FlightCircle CSV upload is empty.');
        }
        if (strlen($bytes) > 100 * 1024 * 1024) {
            throw new RuntimeException('FlightCircle CSV upload exceeds the 100 MB safety limit.');
        }
        $sha256 = hash('sha256', $bytes);
        $storedPath = $this->storeImmutable($originalFilename, $bytes);
        $header = $this->readHeader($storedPath);
        $this->assertSupportedFlightReport($header);

        $this->pdo->beginTransaction();
        try {
            $batchId = $this->createBatch($originalFilename, $sha256, strlen($bytes), $createdByUserId);
            $evidenceId = $this->createSourceEvidence(
                'flightcircle',
                'FLIGHTCIRCLE_HISTORICAL_FLIGHT_REPORT_CSV',
                $originalFilename,
                $storedPath,
                $sha256,
                strlen($bytes),
                '',
                array('headers' => $header),
                $createdByUserId
            );
            $rawFileId = $this->createRawFile($batchId, $evidenceId, $originalFilename, $storedPath, $sha256, strlen($bytes), $header);
            $counts = $this->parseRows($batchId, $rawFileId, $storedPath, $createdByUserId);
            $matchResult = (new FlightCircleGarminMatchService($this->pdo))->matchBatch($batchId);
            $counts['garmin_match_candidates'] = (int)($matchResult['created'] ?? 0);
            $this->finishBatch($batchId, $counts, $replaceActiveDataset);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return array(
            'ok' => true,
            'batch_id' => $batchId,
            'sha256' => $sha256,
            'active_dataset' => $replaceActiveDataset,
            'counts' => $counts,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function status(int $limit = 10, array $stagingFilters = array()): array
    {
        if (!$this->tableExists('ipca_flightcircle_import_batches')) {
            return array('ready' => false, 'message' => 'FlightCircle migration tables have not been installed.', 'batches' => array());
        }
        $hasActiveDataset = $this->tableHasColumn('ipca_flightcircle_import_batches', 'active_dataset');
        $activeOrder = $hasActiveDataset ? 'active_dataset DESC,' : '';
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_flightcircle_import_batches
            ORDER BY {$activeOrder} created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $review = $this->countsByStatus('ipca_flightcircle_user_mappings', 'mapping_status');
        $resources = $this->countsByStatus('ipca_flightcircle_staging_records', 'resource_type');
        $dispositions = $this->countsByStatus('ipca_flightcircle_staging_records', 'import_disposition');
        $activeBatchId = $this->activeDatasetBatchId();
        $activeValidation = $this->activeDatasetValidation($activeBatchId);
        $identityStmt = $this->pdo->query("
            SELECT id, source_name, parsed_first_name, parsed_middle_name, parsed_last_name,
                   suggested_role_context, ipca_user_id, mapping_status, confidence_score, updated_at
            FROM ipca_flightcircle_user_mappings
            WHERE mapping_status IN ('suggested_create_user','unmapped')
            ORDER BY updated_at DESC, id DESC
            LIMIT 15
        ");
        $stagingWhere = array();
        $stagingParams = array();
        if ($activeBatchId > 0) {
            $stagingWhere[] = 'batch_id = ?';
            $stagingParams[] = $activeBatchId;
        }
        $resourceType = trim((string)($stagingFilters['resource_type'] ?? 'aircraft'));
        if ($resourceType !== '' && $resourceType !== 'all') {
            $stagingWhere[] = 'resource_type = ?';
            $stagingParams[] = $resourceType;
        }
        $tail = strtoupper(trim((string)($stagingFilters['tail'] ?? '')));
        if ($tail !== '') {
            $stagingWhere[] = "UPPER(REPLACE(REPLACE(COALESCE(tail_number, resource_identifier, ''), '-', ''), ' ', '')) = ?";
            $stagingParams[] = str_replace(array('-', ' '), '', $tail);
        }
        $student = trim((string)($stagingFilters['student'] ?? ''));
        if ($student !== '') {
            $stagingWhere[] = 'user_text LIKE ?';
            $stagingParams[] = '%' . $student . '%';
        }
        $instructor = trim((string)($stagingFilters['instructor'] ?? ''));
        if ($instructor !== '') {
            $stagingWhere[] = 'instructor_text LIKE ?';
            $stagingParams[] = '%' . $instructor . '%';
        }
        $dateFrom = trim((string)($stagingFilters['date_from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $stagingWhere[] = 'depart_local >= ?';
            $stagingParams[] = $dateFrom . ' 00:00:00';
        }
        $dateTo = trim((string)($stagingFilters['date_to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            $stagingWhere[] = 'depart_local <= ?';
            $stagingParams[] = $dateTo . ' 23:59:59';
        }
        $stagingWhereSql = $stagingWhere !== array() ? 'WHERE ' . implode(' AND ', $stagingWhere) : '';
        $sort = (string)($stagingFilters['sort'] ?? 'date_desc');
        $sortSql = match ($sort) {
            'date_asc' => 'COALESCE(depart_local, updated_at) ASC, id ASC',
            'tail_asc' => 'tail_number ASC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'tail_desc' => 'tail_number DESC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'student_asc' => 'user_text ASC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'student_desc' => 'user_text DESC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'instructor_asc' => 'instructor_text ASC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'instructor_desc' => 'instructor_text DESC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'hobbs_asc' => 'hobbs_out ASC, COALESCE(depart_local, updated_at) ASC, id ASC',
            'hobbs_desc' => 'hobbs_out DESC, COALESCE(depart_local, updated_at) DESC, id DESC',
            default => 'COALESCE(depart_local, updated_at) DESC, id DESC',
        };
        $stagingLimitSetting = (string)($stagingFilters['limit'] ?? '250');
        $stagingLimit = $stagingLimitSetting === 'all' ? 10000 : max(50, min(10000, (int)$stagingLimitSetting));
        $filteredCountStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_flightcircle_staging_records
            {$stagingWhereSql}
        ");
        $filteredCountStmt->execute($stagingParams);
        $filteredStagingCount = (int)$filteredCountStmt->fetchColumn();
        $recentStagingStmt = $this->pdo->prepare("
            SELECT id, batch_id, resource_identifier, resource_type, import_disposition, tail_number,
                   user_text, instructor_text, reservation_type, route_text, depart_local,
                   hobbs_out, hobbs_in, tach_out, tach_in, operation_id, review_status, updated_at
            FROM ipca_flightcircle_staging_records
            {$stagingWhereSql}
            ORDER BY {$sortSql}
            LIMIT ?
        ");
        $stagingFetchParams = $stagingParams;
        $stagingFetchParams[] = $stagingLimit;
        foreach ($stagingFetchParams as $idx => $value) {
            $recentStagingStmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $recentStagingStmt->execute();

        return array(
            'ready' => true,
            'batches' => $batches,
            'identity_mappings' => $review,
            'resources' => $resources,
            'dispositions' => $dispositions,
            'active_batch_id' => $activeBatchId,
            'active_validation' => $activeValidation,
            'identity_suggestions' => $identityStmt !== false ? ($identityStmt->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array(),
            'recent_staging_records' => $recentStagingStmt->fetchAll(PDO::FETCH_ASSOC) ?: array(),
            'recent_staging_filtered_count' => $filteredStagingCount,
            'recent_staging_limit' => $stagingLimitSetting,
            'recent_staging_filters' => $stagingFilters,
            'existing_users' => $this->existingUsersForMapping(),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function existingUsersForMapping(): array
    {
        if (!$this->tableExists('users')) {
            return array();
        }
        $stmt = $this->pdo->query("
            SELECT id, name, first_name, last_name, email, role
            FROM users
            ORDER BY COALESCE(NULLIF(TRIM(last_name), ''), name, email), COALESCE(NULLIF(TRIM(first_name), ''), name, email)
            LIMIT 500
        ");
        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array()) : array();
    }

    /**
     * @return array<string,mixed>
     */
    public function mapIdentityToExistingUser(int $mappingId, int $userId, ?int $actorUserId = null): array
    {
        if ($mappingId <= 0 || $userId <= 0) {
            throw new RuntimeException('Mapping and user id are required.');
        }
        $mapping = $this->identityMappingById($mappingId);
        $user = $this->userById($userId);
        if ($mapping === null || $user === null) {
            throw new RuntimeException('Identity mapping or user not found.');
        }
        $this->pdo->prepare("
            UPDATE ipca_flightcircle_user_mappings
            SET ipca_user_id = ?,
                mapping_status = 'confirmed',
                confidence_score = 100.00,
                confirmed_by_user_id = ?,
                confirmed_at = CURRENT_TIMESTAMP(3),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($userId, $actorUserId, $mappingId));
        $this->applyIdentityMapping((string)$mapping['source_name'], $userId);
        return array('ok' => true, 'mapping_id' => $mappingId, 'user_id' => $userId, 'status' => 'confirmed');
    }

    /**
     * @return array<string,mixed>
     */
    public function createUserForIdentityMapping(int $mappingId, ?int $actorUserId = null): array
    {
        if ($mappingId <= 0) {
            throw new RuntimeException('Identity mapping id is required.');
        }
        $mapping = $this->identityMappingById($mappingId);
        if ($mapping === null) {
            throw new RuntimeException('Identity mapping not found.');
        }
        if ((int)($mapping['ipca_user_id'] ?? 0) > 0) {
            return $this->mapIdentityToExistingUser($mappingId, (int)$mapping['ipca_user_id'], $actorUserId);
        }
        $sourceName = trim((string)($mapping['source_name'] ?? ''));
        $existing = $this->findUserByName($sourceName);
        if ((int)$existing['user_id'] > 0) {
            $result = $this->mapIdentityToExistingUser($mappingId, (int)$existing['user_id'], $actorUserId);
            $result['status'] = 'matched_existing_user';
            return $result;
        }
        $firstName = trim((string)($mapping['parsed_first_name'] ?? ''));
        $middleName = trim((string)($mapping['parsed_middle_name'] ?? ''));
        $lastName = trim((string)($mapping['parsed_last_name'] ?? ''));
        if ($firstName === '' && $lastName === '') {
            $parts = $this->parseName($sourceName);
            $firstName = $parts['first'];
            $middleName = $parts['middle'];
            $lastName = $parts['last'];
        }
        if ($lastName === '' && $firstName !== '') {
            $lastName = 'Unknown';
        }
        if ($firstName === '') {
            $firstName = $sourceName !== '' ? $sourceName : 'FlightCircle';
        }
        $displayName = trim($firstName . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $lastName);
        $roleContext = strtolower(trim((string)($mapping['suggested_role_context'] ?? '')));
        $role = $roleContext === 'instructor' ? 'supervisor' : 'student';
        $email = $this->uniqueMigrationEmail($displayName, $mappingId);
        $userId = $this->insertMigrationUser($firstName, $lastName, $displayName, $email, $role, $actorUserId);
        $this->pdo->prepare("
            UPDATE ipca_flightcircle_user_mappings
            SET ipca_user_id = ?,
                mapping_status = 'created_user',
                confidence_score = 100.00,
                confirmed_by_user_id = ?,
                confirmed_at = CURRENT_TIMESTAMP(3),
                updated_at = CURRENT_TIMESTAMP(3)
            WHERE id = ?
        ")->execute(array($userId, $actorUserId, $mappingId));
        $this->applyIdentityMapping($sourceName, $userId);
        return array('ok' => true, 'mapping_id' => $mappingId, 'user_id' => $userId, 'email' => $email, 'status' => 'created_user');
    }

    /**
     * @return array<string,mixed>
     */
    public function classifyResource(string $resource): array
    {
        $raw = trim($resource);
        $upper = strtoupper($raw);
        if ($upper === '') {
            return array('resource_type' => 'unknown', 'disposition' => 'needs_review', 'reason' => 'missing_resource');
        }
        if (in_array($upper, self::AIRCRAFT_RESOURCES, true)) {
            return array('resource_type' => 'aircraft', 'disposition' => 'operation_candidate', 'reason' => 'known_aircraft');
        }
        if ($upper === self::SIMULATOR_RESOURCE) {
            return array('resource_type' => 'aatd_simulator', 'disposition' => 'simulator_logbook_candidate', 'reason' => 'known_aatd_simulator');
        }
        if (in_array($upper, self::IGNORED_RESOURCES, true)) {
            return array('resource_type' => 'ignored_resource', 'disposition' => 'ignored', 'reason' => 'ignored_for_now');
        }
        return array('resource_type' => 'unknown', 'disposition' => 'needs_review', 'reason' => 'unmapped_resource');
    }

    /**
     * @return list<string>
     */
    private function readHeader(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open FlightCircle CSV.');
        }
        $header = fgetcsv($handle);
        fclose($handle);
        if (!is_array($header)) {
            throw new RuntimeException('FlightCircle CSV header is missing.');
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        return array_map(static fn($value): string => trim((string)$value), $header);
    }

    /**
     * @param list<string> $header
     */
    private function assertSupportedFlightReport(array $header): void
    {
        $required = array('Check-in Date', 'Tail Number', 'Depart Date', 'Return Date', 'Tach-Out', 'Tach-In', 'Hobbs-Out', 'Hobbs-In');
        $missing = array_values(array_diff($required, $header));
        if ($missing !== array()) {
            throw new RuntimeException('Unsupported FlightCircle export. Missing required columns: ' . implode(', ', $missing));
        }
    }

    /**
     * @return array<string,int>
     */
    private function parseRows(int $batchId, int $rawFileId, string $path, ?int $createdByUserId): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read stored FlightCircle CSV.');
        }
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new RuntimeException('FlightCircle CSV header is missing.');
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        $header = array_map(static fn($value): string => trim((string)$value), $header);

        $counts = array(
            'row_count' => 0,
            'aircraft_row_count' => 0,
            'simulator_row_count' => 0,
            'ignored_row_count' => 0,
            'unknown_resource_count' => 0,
            'identity_review_count' => 0,
            'operation_candidate_count' => 0,
        );
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $row = $this->combineCsvRow($header, $values);
            if ($this->isBlankRow($row)) {
                continue;
            }
            $counts['row_count']++;
            $identityHash = $this->rowIdentityHash($row);
            $rowHash = hash('sha256', AuditEventService::jsonEncode($row));
            $rawRowId = $this->insertRawRow($batchId, $rawFileId, $rowNumber, $identityHash, $rowHash, $row);
            $staging = $this->normalizeRow($row);
            $resource = $this->classifyResource((string)$staging['resource_identifier']);
            $staging['resource_type'] = $resource['resource_type'];
            $staging['import_disposition'] = $resource['disposition'];
            $staging['warnings'][] = array('resource_reason' => $resource['reason']);
            $stagingId = $this->insertStagingRecord($batchId, $rawRowId, $staging);

            if ($resource['resource_type'] === 'aircraft') {
                $counts['aircraft_row_count']++;
                $operationId = $this->createOperationCandidate($stagingId, $identityHash, $staging, $createdByUserId);
                $this->createMeterReadings($operationId, $rawRowId, $staging);
                $this->createFuelFacts($operationId, $rawRowId, $staging);
                $this->createCrewAssignments($operationId, $rawRowId, $staging);
                $this->createFlightLogbookProposal($operationId, $stagingId, $rawRowId, $staging);
                $counts['operation_candidate_count']++;
            } elseif ($resource['resource_type'] === 'aatd_simulator') {
                $counts['simulator_row_count']++;
                $this->createSimulatorLogbookProposal($stagingId, $rawRowId, $staging);
            } elseif ($resource['resource_type'] === 'ignored_resource') {
                $counts['ignored_row_count']++;
            } else {
                $counts['unknown_resource_count']++;
            }

            foreach (array('student' => $staging['user_text'], 'instructor' => $staging['instructor_text']) as $role => $name) {
                $name = trim((string)$name);
                if ($name === '') {
                    continue;
                }
                $mappingStatus = $this->ensureIdentitySuggestion($name, $role, $rawRowId, $stagingId);
                if ($mappingStatus === 'suggested_create_user') {
                    $counts['identity_review_count']++;
                }
            }
        }
        fclose($handle);
        return $counts;
    }

    /**
     * @param list<string> $header
     * @param list<string|null> $values
     * @return array<string,mixed>
     */
    private function combineCsvRow(array $header, array $values): array
    {
        $row = array();
        foreach ($header as $index => $name) {
            $row[$name] = trim((string)($values[$index] ?? ''));
        }
        if (count($values) > count($header)) {
            $row['_extra_columns'] = array_slice($values, count($header));
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $notes = trim(implode("\n", array_filter(array(
            trim((string)($row['Public Notes'] ?? '')),
            trim((string)($row['Private Notes'] ?? '')),
        ))));
        return array(
            'resource_identifier' => trim((string)($row['Tail Number'] ?? '')),
            'tail_number' => strtoupper(trim((string)($row['Tail Number'] ?? ''))),
            'user_text' => trim((string)($row['User'] ?? '')),
            'instructor_text' => trim((string)($row['Instructor'] ?? '')),
            'first_name' => trim((string)($row['First Name'] ?? '')),
            'middle_name' => trim((string)($row['Middle Name'] ?? '')),
            'last_name' => trim((string)($row['Last Name'] ?? '')),
            'reservation_type' => trim((string)($row['Reservation Type'] ?? '')),
            'rules_text' => trim((string)($row['Rules'] ?? '')),
            'route_text' => trim((string)($row['Route'] ?? '')),
            'depart_local' => $this->dateTimeOrNull($row['Depart Date'] ?? null),
            'return_local' => $this->dateTimeOrNull($row['Return Date'] ?? null),
            'check_in_local' => $this->dateTimeOrNull($row['Check-in Date'] ?? null),
            'hours' => $this->decimalOrNull($row['Hours'] ?? null),
            'hobbs_out' => $this->decimalOrNull($row['Hobbs-Out'] ?? null),
            'hobbs_in' => $this->decimalOrNull($row['Hobbs-In'] ?? null),
            'hobbs_total' => $this->decimalOrNull($row['Hobbs Total'] ?? null),
            'tach_out' => $this->decimalOrNull($row['Tach-Out'] ?? null),
            'tach_in' => $this->decimalOrNull($row['Tach-In'] ?? null),
            'tach_total' => $this->decimalOrNull($row['Tach Total'] ?? null),
            'ttaf_out' => $this->decimalOrNull($row['TTAF-Out'] ?? null),
            'ttaf_in' => $this->decimalOrNull($row['TTAF-In'] ?? null),
            'ttaf_total' => $this->decimalOrNull($row['TTAF Total'] ?? null),
            'fuel_remaining' => $this->decimalOrNull($row['Fuel Remaining'] ?? null),
            'fuel_added' => $this->decimalOrNull($row['Fuel Added'] ?? null),
            'notes_text' => $notes,
            'source_row' => $row,
            'warnings' => array(
                'training_mission_codes_in_notes_are_informational_only',
                'flightcircle_route_is_planned_or_informational_until_confirmed',
            ),
        );
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function insertStagingRecord(int $batchId, int $rawRowId, array $staging): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flightcircle_staging_records
              (batch_id, raw_row_id, resource_identifier, resource_type, import_disposition, tail_number,
               user_text, instructor_text, reservation_type, rules_text, route_text,
               depart_local, return_local, check_in_local, hours,
               hobbs_out, hobbs_in, hobbs_total, tach_out, tach_in, tach_total,
               ttaf_out, ttaf_in, ttaf_total, fuel_remaining, fuel_added, notes_text,
               normalized_json, warnings_json, review_status)
            VALUES
              (:batch_id, :raw_row_id, :resource_identifier, :resource_type, :import_disposition, :tail_number,
               :user_text, :instructor_text, :reservation_type, :rules_text, :route_text,
               :depart_local, :return_local, :check_in_local, :hours,
               :hobbs_out, :hobbs_in, :hobbs_total, :tach_out, :tach_in, :tach_total,
               :ttaf_out, :ttaf_in, :ttaf_total, :fuel_remaining, :fuel_added, :notes_text,
               :normalized_json, :warnings_json, :review_status)
            ON DUPLICATE KEY UPDATE
              resource_type = VALUES(resource_type),
              import_disposition = VALUES(import_disposition),
              normalized_json = VALUES(normalized_json),
              warnings_json = VALUES(warnings_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            ':batch_id' => $batchId,
            ':raw_row_id' => $rawRowId,
            ':resource_identifier' => (string)$staging['resource_identifier'],
            ':resource_type' => (string)$staging['resource_type'],
            ':import_disposition' => (string)$staging['import_disposition'],
            ':tail_number' => (string)$staging['tail_number'],
            ':user_text' => (string)$staging['user_text'],
            ':instructor_text' => (string)$staging['instructor_text'],
            ':reservation_type' => (string)$staging['reservation_type'],
            ':rules_text' => (string)$staging['rules_text'],
            ':route_text' => (string)$staging['route_text'],
            ':depart_local' => $staging['depart_local'],
            ':return_local' => $staging['return_local'],
            ':check_in_local' => $staging['check_in_local'],
            ':hours' => $staging['hours'],
            ':hobbs_out' => $staging['hobbs_out'],
            ':hobbs_in' => $staging['hobbs_in'],
            ':hobbs_total' => $staging['hobbs_total'],
            ':tach_out' => $staging['tach_out'],
            ':tach_in' => $staging['tach_in'],
            ':tach_total' => $staging['tach_total'],
            ':ttaf_out' => $staging['ttaf_out'],
            ':ttaf_in' => $staging['ttaf_in'],
            ':ttaf_total' => $staging['ttaf_total'],
            ':fuel_remaining' => $staging['fuel_remaining'],
            ':fuel_added' => $staging['fuel_added'],
            ':notes_text' => (string)$staging['notes_text'],
            ':normalized_json' => AuditEventService::jsonEncode($staging),
            ':warnings_json' => AuditEventService::jsonEncode($staging['warnings']),
            ':review_status' => (string)$staging['import_disposition'] === 'ignored' ? 'ignored' : 'needs_review',
        ));
        $id = (int)$this->pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $lookup = $this->pdo->prepare('SELECT id FROM ipca_flightcircle_staging_records WHERE raw_row_id = ? LIMIT 1');
        $lookup->execute(array($rawRowId));
        return (int)$lookup->fetchColumn();
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function createOperationCandidate(int $stagingId, string $identityHash, array $staging, ?int $createdByUserId): int
    {
        $aircraftId = $this->aircraftIdForRegistration((string)$staging['tail_number']);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_aircraft_operations
              (operation_uuid, aircraft_id, aircraft_registration, resource_identifier, resource_type, operation_type,
               source_mode, operation_status, review_status, scheduled_start_local, scheduled_end_local, check_in_local,
               user_text, instructor_text, reservation_type, rules_text, route_text, mission_notes,
               source_identity_hash, source_summary_json, created_by_user_id)
            VALUES
              (:operation_uuid, :aircraft_id, :aircraft_registration, :resource_identifier, 'aircraft', 'dispatch_session',
               'flightcircle_migration', 'proposed', 'needs_review', :scheduled_start_local, :scheduled_end_local, :check_in_local,
               :user_text, :instructor_text, :reservation_type, :rules_text, :route_text, :mission_notes,
               :source_identity_hash, :source_summary_json, :created_by_user_id)
            ON DUPLICATE KEY UPDATE
              id = LAST_INSERT_ID(id),
              source_summary_json = VALUES(source_summary_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ");
        $stmt->execute(array(
            ':operation_uuid' => AuditEventService::uuid(),
            ':aircraft_id' => $aircraftId > 0 ? $aircraftId : null,
            ':aircraft_registration' => (string)$staging['tail_number'],
            ':resource_identifier' => (string)$staging['resource_identifier'],
            ':scheduled_start_local' => $staging['depart_local'],
            ':scheduled_end_local' => $staging['return_local'],
            ':check_in_local' => $staging['check_in_local'],
            ':user_text' => (string)$staging['user_text'],
            ':instructor_text' => (string)$staging['instructor_text'],
            ':reservation_type' => (string)$staging['reservation_type'],
            ':rules_text' => (string)$staging['rules_text'],
            ':route_text' => (string)$staging['route_text'],
            ':mission_notes' => (string)$staging['notes_text'],
            ':source_identity_hash' => $identityHash,
            ':source_summary_json' => AuditEventService::jsonEncode(array(
                'flightcircle_staging_record_id' => $stagingId,
                'route_is_informational_only' => true,
                'mission_codes_are_informational_only' => true,
                'garmin_telemetry_status' => 'unmatched',
            )),
            ':created_by_user_id' => $createdByUserId,
        ));
        $operationId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE ipca_flightcircle_staging_records SET operation_id = ?, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
            ->execute(array($operationId, $stagingId));
        $this->linkOperationEvidence($operationId, 'flightcircle', 'ipca_flightcircle_staging_records', (string)$stagingId, 'operational_evidence', array('source_identity_hash' => $identityHash));
        return $operationId;
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function createMeterReadings(int $operationId, int $rawRowId, array $staging): void
    {
        foreach (array(
            'hobbs' => array('out' => 'hobbs_out', 'in' => 'hobbs_in', 'delta' => 'hobbs_total'),
            'tach' => array('out' => 'tach_out', 'in' => 'tach_in', 'delta' => 'tach_total'),
            'ttaf' => array('out' => 'ttaf_out', 'in' => 'ttaf_in', 'delta' => 'ttaf_total'),
        ) as $type => $keys) {
            if ($staging[$keys['out']] === null && $staging[$keys['in']] === null && $staging[$keys['delta']] === null) {
                continue;
            }
            $this->pdo->prepare("
                INSERT INTO ipca_meter_readings
                  (meter_reading_uuid, operation_id, aircraft_registration, resource_identifier, meter_type, reading_out, reading_in, reading_delta,
                   source_system, source_record_id, source_precedence, confidence_score, continuity_status, review_status, original_values_json)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, 'flightcircle', ?, 'historical_ledger_authoritative_before_cutover', 85.00, 'unchecked', 'needs_review', ?)
            ")->execute(array(
                AuditEventService::uuid(),
                $operationId,
                (string)$staging['tail_number'],
                (string)$staging['resource_identifier'],
                $type,
                $staging[$keys['out']],
                $staging[$keys['in']],
                $staging[$keys['delta']],
                (string)$rawRowId,
                AuditEventService::jsonEncode(array('source' => 'FlightCircle export', 'values' => $keys)),
            ));
        }
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function createFuelFacts(int $operationId, int $rawRowId, array $staging): void
    {
        foreach (array('fuel_remaining' => 'return_fuel_remaining', 'fuel_added' => 'fuel_added') as $key => $type) {
            if ($staging[$key] === null) {
                continue;
            }
            $this->pdo->prepare("
                INSERT INTO ipca_fuel_transactions
                  (fuel_transaction_uuid, operation_id, aircraft_registration, transaction_type, quantity, unit,
                   source_system, source_record_id, confidence_score, review_status, source_json)
                VALUES
                  (?, ?, ?, ?, ?, 'unknown', 'flightcircle', ?, 60.00, 'needs_review', ?)
            ")->execute(array(
                AuditEventService::uuid(),
                $operationId,
                (string)$staging['tail_number'],
                $type,
                $staging[$key],
                (string)$rawRowId,
                AuditEventService::jsonEncode(array('field' => $key, 'source_value' => $staging[$key])),
            ));
        }
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function createCrewAssignments(int $operationId, int $rawRowId, array $staging): void
    {
        foreach (array('student' => $staging['user_text'], 'instructor' => $staging['instructor_text']) as $sourceRole => $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $match = $this->findUserByName($name);
            $this->pdo->prepare("
                INSERT INTO ipca_crew_assignments
                  (crew_assignment_uuid, operation_id, person_user_id, source_person_text, source_role_text,
                   resolved_role, mapping_status, confidence_score, review_status, source_system, source_record_id, evidence_json)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, 'flightcircle', ?, ?)
            ")->execute(array(
                AuditEventService::uuid(),
                $operationId,
                $match['user_id'] > 0 ? $match['user_id'] : null,
                $name,
                $sourceRole,
                $sourceRole === 'instructor' ? 'instructor' : 'unknown_crew_role',
                $match['status'],
                $match['confidence'],
                $match['user_id'] > 0 ? 'needs_role_review' : 'needs_identity_review',
                (string)$rawRowId,
                AuditEventService::jsonEncode(array('source_role' => $sourceRole, 'source_name' => $name)),
            ));
        }
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function createFlightLogbookProposal(int $operationId, int $stagingId, int $rawRowId, array $staging): void
    {
        $user = trim((string)$staging['user_text']);
        if ($user === '') {
            return;
        }
        $match = $this->findUserByName($user);
        $this->insertLogbookProposal($operationId, $stagingId, $match['user_id'], $user, 'student_flight', 'flight', $staging['hours'], 'flightcircle', (string)$rawRowId, array(
            'aircraft_registration' => $staging['tail_number'],
            'date' => $staging['depart_local'] !== null ? substr((string)$staging['depart_local'], 0, 10) : null,
            'block_or_hobbs_time' => $staging['hours'],
            'route_text_informational_only' => $staging['route_text'],
            'mission_notes_informational_only' => $staging['notes_text'],
            'review_required' => true,
        ));
    }

    /**
     * @param array<string,mixed> $staging
     */
    private function createSimulatorLogbookProposal(int $stagingId, int $rawRowId, array $staging): void
    {
        $user = trim((string)$staging['user_text']);
        if ($user === '') {
            return;
        }
        $match = $this->findUserByName($user);
        $this->insertLogbookProposal(null, $stagingId, $match['user_id'], $user, 'student_simulator', 'aatd_simulator', $staging['hours'], 'flightcircle', (string)$rawRowId, array(
            'simulator_resource' => 'AL172M2',
            'aatd_time' => $staging['hours'],
            'instructor_text' => $staging['instructor_text'],
            'mission_notes_informational_only' => $staging['notes_text'],
            'garmin_position_airport_data_not_authoritative' => true,
            'review_required' => true,
        ));
    }

    /**
     * @param array<string,mixed> $values
     */
    private function insertLogbookProposal(?int $operationId, int $stagingId, int $ownerUserId, string $sourcePersonText, string $entryType, string $activityType, mixed $durationHours, string $sourceSystem, string $sourceRecordId, array $values): void
    {
        $this->pdo->prepare("
            INSERT INTO ipca_historical_logbook_proposals
              (proposal_uuid, operation_id, staging_record_id, owner_user_id, source_person_text, entry_type, activity_type,
               proposed_duration_hours, review_status, source_system, source_record_id, proposed_values_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, 'Proposed', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              proposed_values_json = VALUES(proposed_values_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ")->execute(array(
            AuditEventService::uuid(),
            $operationId,
            $stagingId,
            $ownerUserId > 0 ? $ownerUserId : null,
            $sourcePersonText,
            $entryType,
            $activityType,
            $durationHours,
            $sourceSystem,
            $sourceRecordId,
            AuditEventService::jsonEncode($values),
        ));
    }

    private function ensureIdentitySuggestion(string $sourceName, string $role, int $rawRowId, int $stagingId): string
    {
        $match = $this->findUserByName($sourceName);
        $nameParts = $this->parseName($sourceName);
        $status = $match['user_id'] > 0 ? 'matched_existing_user' : 'suggested_create_user';
        $hash = $this->normalizeNameHash($sourceName);
        $this->pdo->prepare("
            INSERT INTO ipca_flightcircle_user_mappings
              (source_name_hash, source_name, parsed_first_name, parsed_middle_name, parsed_last_name,
               suggested_role_context, ipca_user_id, mapping_status, confidence_score, evidence_json)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              ipca_user_id = COALESCE(VALUES(ipca_user_id), ipca_user_id),
              mapping_status = IF(mapping_status IN ('confirmed','ignored','manual'), mapping_status, VALUES(mapping_status)),
              evidence_json = VALUES(evidence_json),
              updated_at = CURRENT_TIMESTAMP(3)
        ")->execute(array(
            $hash,
            $sourceName,
            $nameParts['first'],
            $nameParts['middle'],
            $nameParts['last'],
            $role,
            $match['user_id'] > 0 ? $match['user_id'] : null,
            $status,
            $match['confidence'],
            AuditEventService::jsonEncode(array('raw_row_id' => $rawRowId, 'staging_record_id' => $stagingId, 'role_context' => $role)),
        ));
        return $status;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function identityMappingById(int $mappingId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_flightcircle_user_mappings WHERE id = ? LIMIT 1');
        $stmt->execute(array($mappingId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function userById(int $userId): ?array
    {
        if (!$this->tableExists('users')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute(array($userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function applyIdentityMapping(string $sourceName, int $userId): void
    {
        if ($sourceName === '' || $userId <= 0) {
            return;
        }
        if ($this->tableExists('ipca_crew_assignments')) {
            $this->pdo->prepare("
                UPDATE ipca_crew_assignments
                SET person_user_id = ?,
                    mapping_status = 'confirmed',
                    review_status = IF(review_status = 'needs_identity_review', 'needs_role_review', review_status),
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE source_system = 'flightcircle'
                  AND source_person_text = ?
            ")->execute(array($userId, $sourceName));
        }
        if ($this->tableExists('ipca_historical_logbook_proposals')) {
            $this->pdo->prepare("
                UPDATE ipca_historical_logbook_proposals
                SET owner_user_id = ?,
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE source_system = 'flightcircle'
                  AND source_person_text = ?
            ")->execute(array($userId, $sourceName));
        }
    }

    private function uniqueMigrationEmail(string $displayName, int $mappingId): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $displayName) ?? 'flightcircle.user', '.'));
        if ($base === '') {
            $base = 'flightcircle.user';
        }
        $candidate = $base . '.fc' . $mappingId . '@ipca.training';
        $suffix = 1;
        while ($this->emailExists($candidate)) {
            $candidate = $base . '.fc' . $mappingId . '.' . $suffix . '@ipca.training';
            $suffix++;
        }
        return $candidate;
    }

    private function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute(array($email));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function insertMigrationUser(string $firstName, string $lastName, string $displayName, string $email, string $role, ?int $actorUserId): int
    {
        if (!$this->tableExists('users')) {
            throw new RuntimeException('Users table is missing.');
        }
        $columns = $this->tableColumns('users');
        $values = array(
            'email' => $email,
            'name' => $displayName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'role' => in_array($role, array('student', 'supervisor'), true) ? $role : 'student',
            'status' => 'pending_activation',
            'must_change_password' => 1,
            'created_by_user_id' => $actorUserId,
            'updated_by_user_id' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $insertColumns = array();
        $params = array();
        foreach ($values as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insertColumns[] = $column;
                $params[] = $value;
            }
        }
        if (!in_array('email', $insertColumns, true) || !in_array('password_hash', $insertColumns, true) || !in_array('role', $insertColumns, true)) {
            throw new RuntimeException('Users table does not have the required account columns.');
        }
        $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
        $sql = 'INSERT INTO users (`' . implode('`,`', $insertColumns) . '`) VALUES (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        $stmt = $this->pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute(array($table));
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
    }

    /**
     * @return array{user_id:int,status:string,confidence:float}
     */
    private function findUserByName(string $sourceName): array
    {
        $normalized = $this->normalizeName($sourceName);
        if ($normalized === '' || !$this->tableExists('users')) {
            return array('user_id' => 0, 'status' => 'suggested_create_user', 'confidence' => 0.0);
        }
        $stmt = $this->pdo->query("SELECT id, name, first_name, last_name, email FROM users");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $candidates = array(
                (string)($row['name'] ?? ''),
                trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
                (string)($row['email'] ?? ''),
            );
            foreach ($candidates as $candidate) {
                if ($this->normalizeName($candidate) === $normalized) {
                    return array('user_id' => (int)$row['id'], 'status' => 'matched_existing_user', 'confidence' => 100.0);
                }
            }
        }
        return array('user_id' => 0, 'status' => 'suggested_create_user', 'confidence' => 0.0);
    }

    /**
     * @return array{first:string,middle:string,last:string}
     */
    private function parseName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: array();
        if (count($parts) === 0) {
            return array('first' => '', 'middle' => '', 'last' => '');
        }
        if (count($parts) === 1) {
            return array('first' => $parts[0], 'middle' => '', 'last' => '');
        }
        $first = array_shift($parts);
        $last = array_pop($parts);
        return array('first' => (string)$first, 'middle' => implode(' ', $parts), 'last' => (string)$last);
    }

    private function normalizeNameHash(string $name): string
    {
        return hash('sha256', $this->normalizeName($name));
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowIdentityHash(array $row): string
    {
        $fields = array('Tail Number', 'Depart Date', 'Return Date', 'Check-in Date', 'User', 'Instructor', 'Hobbs-Out', 'Hobbs-In', 'Tach-Out', 'Tach-In', 'Reservation Type', 'Created Date');
        $parts = array();
        foreach ($fields as $field) {
            $parts[] = strtolower(trim((string)($row[$field] ?? '')));
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function insertRawRow(int $batchId, int $rawFileId, int $rowNumber, string $identityHash, string $rowHash, array $row): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flightcircle_raw_rows
              (batch_id, raw_file_id, `row_number`, source_row_identity_hash, source_row_hash, row_json)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              id = LAST_INSERT_ID(id),
              source_row_hash = VALUES(source_row_hash),
              row_json = VALUES(row_json)
        ");
        $stmt->execute(array($batchId, $rawFileId, $rowNumber, $identityHash, $rowHash, AuditEventService::jsonEncode($row)));
        return (int)$this->pdo->lastInsertId();
    }

    private function createBatch(string $filename, string $sha256, int $bytes, ?int $userId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flightcircle_import_batches
              (batch_uuid, created_by_user_id, import_status, export_type, original_filename, sha256, file_size_bytes)
            VALUES (?, ?, 'importing', 'flight_report', ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $userId, $filename, $sha256, $bytes));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $header
     */
    private function createRawFile(int $batchId, int $evidenceId, string $filename, string $path, string $sha256, int $bytes, array $header): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flightcircle_raw_files
              (batch_id, evidence_id, original_filename, storage_path, sha256, file_size_bytes, header_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $stmt->execute(array($batchId, $evidenceId, $filename, $path, $sha256, $bytes, AuditEventService::jsonEncode($header)));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,int> $counts
     */
    private function finishBatch(int $batchId, array $counts, bool $replaceActiveDataset): void
    {
        if ($replaceActiveDataset && $this->tableHasColumn('ipca_flightcircle_import_batches', 'active_dataset')) {
            $this->pdo->prepare("
                UPDATE ipca_flightcircle_import_batches
                SET active_dataset = 0,
                    superseded_by_batch_id = ?,
                    superseded_at = CURRENT_TIMESTAMP(3),
                    updated_at = CURRENT_TIMESTAMP(3)
                WHERE active_dataset = 1
                  AND id <> ?
            ")->execute(array($batchId, $batchId));
        }
        $activeSql = $this->tableHasColumn('ipca_flightcircle_import_batches', 'active_dataset')
            ? ', active_dataset = ' . ($replaceActiveDataset ? '1' : '0') . ', superseded_by_batch_id = NULL, superseded_at = NULL'
            : '';
        $this->pdo->prepare("
            UPDATE ipca_flightcircle_import_batches
            SET import_status = 'completed',
                row_count = ?,
                aircraft_row_count = ?,
                simulator_row_count = ?,
                ignored_row_count = ?,
                unknown_resource_count = ?,
                identity_review_count = ?,
                operation_candidate_count = ?,
                counters_json = ?,
                completed_at = CURRENT_TIMESTAMP(3),
                updated_at = CURRENT_TIMESTAMP(3)
                {$activeSql}
            WHERE id = ?
        ")->execute(array(
            $counts['row_count'],
            $counts['aircraft_row_count'],
            $counts['simulator_row_count'],
            $counts['ignored_row_count'],
            $counts['unknown_resource_count'],
            $counts['identity_review_count'],
            $counts['operation_candidate_count'],
            AuditEventService::jsonEncode($counts),
            $batchId,
        ));
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function createSourceEvidence(string $sourceSystem, string $sourceType, string $label, string $path, string $sha256, int $bytes, string $externalHash, array $metadata, ?int $userId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_source_evidence
              (evidence_uuid, source_system, source_type, source_label, storage_path, sha256, file_size_bytes, external_source_hash, metadata_json, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(AuditEventService::uuid(), $sourceSystem, $sourceType, $label, $path, $sha256, $bytes, $externalHash, AuditEventService::jsonEncode($metadata), $userId));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function linkOperationEvidence(int $operationId, string $sourceSystem, string $sourceTable, string $sourceRecordId, string $relationship, array $evidence): void
    {
        $this->pdo->prepare("
            INSERT INTO ipca_aircraft_operation_evidence_links
              (operation_id, source_system, source_table, source_record_id, relationship_type, confidence_score, evidence_json)
            VALUES (?, ?, ?, ?, ?, 80.00, ?)
            ON DUPLICATE KEY UPDATE evidence_json = VALUES(evidence_json)
        ")->execute(array($operationId, $sourceSystem, $sourceTable, $sourceRecordId, $relationship, AuditEventService::jsonEncode($evidence)));
    }

    private function aircraftIdForRegistration(string $registration): int
    {
        if ($registration === '' || !$this->tableExists('ipca_aircraft_devices')) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM ipca_aircraft_devices WHERE UPPER(registration) = ? LIMIT 1');
        $stmt->execute(array(strtoupper($registration)));
        return (int)$stmt->fetchColumn();
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s.000', $timestamp);
    }

    private function decimalOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return number_format((float)$value, 4, '.', '');
    }

    private function storeImmutable(string $filename, string $bytes): string
    {
        $dir = dirname(__DIR__) . '/storage/migration/flightcircle/' . gmdate('Y/m/d');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create FlightCircle migration storage directory.');
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename)) ?: 'flightcircle.csv';
        $target = $dir . '/' . gmdate('His') . '-' . substr(hash('sha256', $bytes), 0, 12) . '-' . $safe;
        $tmp = $target . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $bytes, LOCK_EX);
        rename($tmp, $target);
        return $target;
    }

    /**
     * @return array<string,int>
     */
    private function countsByStatus(string $table, string $column): array
    {
        if (!$this->tableExists($table)) {
            return array();
        }
        $sql = 'SELECT ' . $column . ' AS k, COUNT(*) AS c FROM ' . $table . ' GROUP BY ' . $column;
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $out = array();
        foreach ($rows as $row) {
            $out[(string)$row['k']] = (int)$row['c'];
        }
        return $out;
    }

    private function activeDatasetBatchId(): int
    {
        if (!$this->tableExists('ipca_flightcircle_import_batches')) {
            return 0;
        }
        if ($this->tableHasColumn('ipca_flightcircle_import_batches', 'active_dataset')) {
            $stmt = $this->pdo->query("
                SELECT id
                FROM ipca_flightcircle_import_batches
                WHERE active_dataset = 1
                  AND import_status = 'completed'
                ORDER BY completed_at DESC, id DESC
                LIMIT 1
            ");
            $id = $stmt !== false ? (int)$stmt->fetchColumn() : 0;
            if ($id > 0) {
                return $id;
            }
        }
        $stmt = $this->pdo->query("
            SELECT id
            FROM ipca_flightcircle_import_batches
            WHERE import_status = 'completed'
            ORDER BY completed_at DESC, id DESC
            LIMIT 1
        ");
        return $stmt !== false ? (int)$stmt->fetchColumn() : 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function activeDatasetValidation(int $batchId): array
    {
        if ($batchId <= 0 || !$this->tableExists('ipca_flightcircle_staging_records')) {
            return array('ready' => false, 'batch_id' => 0);
        }
        $stmt = $this->pdo->prepare("
            SELECT
              COUNT(*) AS total_rows,
              SUM(resource_type = 'aircraft') AS aircraft_rows,
              SUM(resource_type = 'aatd_simulator') AS simulator_rows,
              SUM(resource_type = 'ignored_resource') AS ignored_rows,
              SUM(resource_type = 'unknown') AS unknown_rows,
              SUM(resource_type = 'aircraft' AND depart_local IS NULL) AS missing_date_rows,
              SUM(resource_type = 'aircraft' AND (tail_number IS NULL OR TRIM(tail_number) = '')) AS missing_tail_rows,
              SUM(resource_type = 'aircraft' AND hobbs_out IS NULL) AS missing_hobbs_out_rows,
              MIN(CASE WHEN resource_type = 'aircraft' THEN depart_local ELSE NULL END) AS first_depart_local,
              MAX(CASE WHEN resource_type = 'aircraft' THEN depart_local ELSE NULL END) AS last_depart_local
            FROM ipca_flightcircle_staging_records
            WHERE batch_id = ?
        ");
        $stmt->execute(array($batchId));
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $tailStmt = $this->pdo->prepare("
            SELECT tail_number, COUNT(*) AS total
            FROM ipca_flightcircle_staging_records
            WHERE batch_id = ?
              AND tail_number IS NOT NULL
              AND TRIM(tail_number) <> ''
              AND (
                resource_type = 'aircraft'
                OR UPPER(TRIM(tail_number)) IN ('N397EA', 'N392EA', 'N482EA', 'N428EA', 'N446CS', 'N153PC', 'N641TH')
              )
            GROUP BY tail_number
            ORDER BY tail_number ASC
            LIMIT 50
        ");
        $tailStmt->execute(array($batchId));
        return array(
            'ready' => true,
            'batch_id' => $batchId,
            'summary' => $summary,
            'tail_counts' => $tailStmt->fetchAll(PDO::FETCH_ASSOC) ?: array(),
        );
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns($table), true);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(array($table));
        return (int)$stmt->fetchColumn() > 0;
    }
}
