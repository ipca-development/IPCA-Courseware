<?php
declare(strict_types=1);

require_once __DIR__ . '/FlightTotalsService.php';
require_once __DIR__ . '/FlightRequirementEngine.php';
require_once __DIR__ . '/FlightVariableService.php';
require_once __DIR__ . '/LogbookImageExtractionService.php';

final class AdminLogbookService
{
    private const REQUIRED_TABLES = array(
        'ipca_admin_logbooks',
        'ipca_admin_logbook_pages',
        'ipca_admin_logbook_entries',
        'ipca_admin_logbook_entry_audit',
        'ipca_flight_requirement_categories',
        'ipca_flight_requirement_assignments',
        'ipca_flight_requirement_assignment_entries',
        'ipca_flight_requirement_evaluations',
        'ipca_flight_variable_snapshots',
    );

    private const REQUIRED_ENTRY_COLUMNS = array(
        'external_system',
        'external_id',
        'import_profile',
        'source_json',
        'source_hash',
        'normalized_hash',
        'sync_status',
        'fnpt_simulator_time',
        'accepted_at',
        'accepted_by',
    );

    private const EGLE_LEGACY_COLUMNS = array(
        'student_name',
        'student_email',
        'instructor_name',
        'instructor_email',
        'aircraft_name',
        'aircraft_type',
        'aircraft_engine',
        'aircraft_sort',
        'lb_dep',
        'lb_arr',
        'lb_deptime',
        'lb_dur',
        'lb_ld',
        'lb_cond',
        'lb_ifr',
        'lb_fnpt',
        'lb_dual',
        'lb_xc',
    );

    private FlightTotalsService $totals;
    private FlightRequirementEngine $requirements;
    private FlightVariableService $variables;
    private LogbookImageExtractionService $extractor;

    public function __construct(private PDO $pdo)
    {
        $this->totals = new FlightTotalsService();
        $this->requirements = new FlightRequirementEngine($this->totals);
        $this->variables = new FlightVariableService();
        $this->extractor = new LogbookImageExtractionService();
    }

    public function schemaReady(): bool
    {
        return $this->missingTables() === array();
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        $missing = array();
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        if ($this->tableExists('ipca_admin_logbook_entries')) {
            foreach (self::REQUIRED_ENTRY_COLUMNS as $column) {
                if (!$this->columnExists('ipca_admin_logbook_entries', $column)) {
                    $missing[] = 'ipca_admin_logbook_entries.' . $column;
                }
            }
        }
        return $missing;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listStudents(): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->query("
            SELECT id, name, email, role
            FROM users
            WHERE role = 'student'
            ORDER BY name ASC, email ASC
            LIMIT 500
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLogbooks(): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->query("
            SELECT l.*, u.name AS student_name, u.email AS student_email,
                   COUNT(e.id) AS entry_count
            FROM ipca_admin_logbooks l
            LEFT JOIN users u ON u.id = l.student_user_id
            LEFT JOIN ipca_admin_logbook_entries e ON e.logbook_id = l.id AND e.review_status <> 'deleted'
            GROUP BY l.id
            ORDER BY l.updated_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function getOrCreateLogbook(int $studentUserId, ?int $cohortId, int $actorUserId): int
    {
        $this->requireSchema();
        if ($studentUserId <= 0) {
            throw new RuntimeException('Student is required.');
        }
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_admin_logbooks
            WHERE student_user_id = :student_user_id
              AND ((:cohort_id_is_null = 1 AND cohort_id IS NULL) OR cohort_id = :cohort_id_value)
            LIMIT 1
        ");
        $stmt->execute(array(
            ':student_user_id' => $studentUserId,
            ':cohort_id_is_null' => $cohortId === null ? 1 : 0,
            ':cohort_id_value' => $cohortId,
        ));
        $existing = (int)$stmt->fetchColumn();
        if ($existing > 0) {
            return $existing;
        }
        $ins = $this->pdo->prepare("
            INSERT INTO ipca_admin_logbooks (student_user_id, cohort_id, created_by, status, source_type, metadata_json)
            VALUES (:student_user_id, :cohort_id, :created_by, 'active', 'admin', JSON_OBJECT())
        ");
        $ins->execute(array(
            ':student_user_id' => $studentUserId,
            ':cohort_id' => $cohortId,
            ':created_by' => $actorUserId > 0 ? $actorUserId : null,
        ));
        $id = (int)$this->pdo->lastInsertId();
        $this->writeAudit($id, null, $actorUserId, 'logbook_created', null, array('student_user_id' => $studentUserId));
        return $id;
    }

    /**
     * @return array<string,mixed>
     */
    public function loadWorkspace(int $logbookId): array
    {
        $this->requireSchema();
        $logbook = $this->requireLogbook($logbookId);
        $entries = $this->listEntries($logbookId);
        $categories = $this->listRequirementCategories();
        $assignments = $this->listAssignments($logbookId);
        $totals = $this->totals->calculate($entries);
        $evaluations = $this->requirements->evaluateAll($entries, $categories, $assignments);
        $variables = $this->variables->buildVariables($totals, $evaluations);
        $this->saveVariableSnapshot($logbook, $variables);

        return array(
            'logbook' => $logbook,
            'pages' => $this->listPages($logbookId),
            'entries' => $entries,
            'totals' => $totals,
            'iacra_8710' => $this->totals->iacra8710Summary($totals),
            'requirements' => $evaluations,
            'requirement_categories' => $categories,
            'assignments' => $assignments,
            'variables' => $variables,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function saveEntry(int $logbookId, array $data, int $actorUserId): array
    {
        $this->requireSchema();
        $this->requireLogbook($logbookId);
        $entryId = (int)($data['id'] ?? 0);
        $before = $entryId > 0 ? $this->getEntry($entryId) : null;
        $row = $this->normalizeEntry($data);
        $row['logbook_id'] = $logbookId;
        if ($before !== null && $row['external_system'] === null && trim((string)($before['external_system'] ?? '')) !== '') {
            $row['external_system'] = (string)$before['external_system'];
        }
        if ($before !== null && $row['external_id'] === null && trim((string)($before['external_id'] ?? '')) !== '') {
            $row['external_id'] = (string)$before['external_id'];
        }
        if ($before !== null && $row['source_json'] === '[]' && trim((string)($before['source_json'] ?? '')) !== '') {
            $row['source_json'] = (string)$before['source_json'];
        }
        if ($before !== null && $row['import_profile'] === null && trim((string)($before['import_profile'] ?? '')) !== '') {
            $row['import_profile'] = (string)$before['import_profile'];
        }
        foreach (array('source_hash', 'normalized_hash', 'sync_status') as $preservedKey) {
            if ($before !== null && $row[$preservedKey] === null && trim((string)($before[$preservedKey] ?? '')) !== '') {
                $row[$preservedKey] = (string)$before[$preservedKey];
            }
        }

        if ($entryId > 0 && $before !== null) {
            $sets = array();
            $params = array(':id' => $entryId, ':logbook_id' => $logbookId);
            foreach ($row as $key => $value) {
                if ($key === 'logbook_id') {
                    continue;
                }
                $sets[] = $key . ' = :' . $key;
                $params[':' . $key] = $value;
            }
            $sql = 'UPDATE ipca_admin_logbook_entries SET ' . implode(', ', $sets) . ' WHERE id = :id AND logbook_id = :logbook_id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $savedId = $entryId;
            $event = 'entry_updated';
        } else {
            $columns = array_keys($row);
            $params = array();
            foreach ($columns as $column) {
                $params[':' . $column] = $row[$column];
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO ipca_admin_logbook_entries (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', array_keys($params)) . ")
            ");
            $stmt->execute($params);
            $savedId = (int)$this->pdo->lastInsertId();
            $event = 'entry_created';
        }

        $after = $this->getEntry($savedId);
        $this->writeAudit($logbookId, $savedId, $actorUserId, $event, $before, $after);
        return $after ?? array();
    }

    public function deleteEntry(int $logbookId, int $entryId, int $actorUserId): void
    {
        $before = $this->getEntry($entryId);
        $stmt = $this->pdo->prepare("
            UPDATE ipca_admin_logbook_entries
            SET review_status = 'deleted'
            WHERE id = :id AND logbook_id = :logbook_id
        ");
        $stmt->execute(array(':id' => $entryId, ':logbook_id' => $logbookId));
        $this->writeAudit($logbookId, $entryId, $actorUserId, 'entry_deleted', $before, array('review_status' => 'deleted'));
    }

    /**
     * @param list<int> $entryIds
     */
    public function flagEntries(int $logbookId, array $entryIds, int $actorUserId): void
    {
        foreach ($entryIds as $entryId) {
            $entryId = (int)$entryId;
            if ($entryId <= 0) {
                continue;
            }
            $before = $this->getEntry($entryId);
            $stmt = $this->pdo->prepare("
                UPDATE ipca_admin_logbook_entries
                SET review_status = 'flagged'
                WHERE id = :id AND logbook_id = :logbook_id
            ");
            $stmt->execute(array(':id' => $entryId, ':logbook_id' => $logbookId));
            $this->writeAudit($logbookId, $entryId, $actorUserId, 'entry_flagged', $before, array('review_status' => 'flagged'));
        }
    }

    /**
     * @param list<int> $entryIds
     */
    public function acceptEntries(int $logbookId, array $entryIds, int $actorUserId): void
    {
        foreach ($entryIds as $entryId) {
            $entryId = (int)$entryId;
            if ($entryId <= 0) {
                continue;
            }
            $before = $this->getEntry($entryId);
            $stmt = $this->pdo->prepare("
                UPDATE ipca_admin_logbook_entries
                SET review_status = 'accepted',
                    accepted_at = CURRENT_TIMESTAMP,
                    accepted_by = :actor_user_id
                WHERE id = :id AND logbook_id = :logbook_id
            ");
            $stmt->execute(array(
                ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
                ':id' => $entryId,
                ':logbook_id' => $logbookId,
            ));
            $this->writeAudit($logbookId, $entryId, $actorUserId, 'entry_accepted', $before, array('review_status' => 'accepted'));
        }
    }

    /**
     * @param list<int> $entryIds
     */
    public function rejectEntries(int $logbookId, array $entryIds, int $actorUserId): void
    {
        foreach ($entryIds as $entryId) {
            $entryId = (int)$entryId;
            if ($entryId <= 0) {
                continue;
            }
            $before = $this->getEntry($entryId);
            $stmt = $this->pdo->prepare("
                UPDATE ipca_admin_logbook_entries
                SET review_status = 'rejected'
                WHERE id = :id AND logbook_id = :logbook_id
            ");
            $stmt->execute(array(':id' => $entryId, ':logbook_id' => $logbookId));
            $this->writeAudit($logbookId, $entryId, $actorUserId, 'entry_rejected', $before, array('review_status' => 'rejected'));
        }
    }

    public function splitEntry(int $logbookId, int $entryId, int $actorUserId): array
    {
        $entry = $this->getEntry($entryId);
        if ($entry === null || (int)$entry['logbook_id'] !== $logbookId) {
            throw new RuntimeException('Entry not found.');
        }
        unset($entry['id'], $entry['created_at'], $entry['updated_at']);
        $entry['review_status'] = 'split';
        $columns = array_keys($entry);
        $params = array();
        foreach ($columns as $column) {
            $params[':' . $column] = $entry[$column];
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_admin_logbook_entries (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', array_keys($params)) . ")
        ");
        $stmt->execute($params);
        $newId = (int)$this->pdo->lastInsertId();
        $after = $this->getEntry($newId);
        $this->writeAudit($logbookId, $newId, $actorUserId, 'entry_split', $entry, $after);
        return $after ?? array();
    }

    /**
     * @param list<int> $entryIds
     */
    public function mergeEntries(int $logbookId, array $entryIds, int $actorUserId): array
    {
        $entryIds = array_values(array_filter(array_map('intval', $entryIds), static fn (int $id): bool => $id > 0));
        if (count($entryIds) < 2) {
            throw new RuntimeException('Select at least two saved entries to merge.');
        }
        $entries = array();
        foreach ($entryIds as $entryId) {
            $entry = $this->getEntry($entryId);
            if ($entry === null || (int)$entry['logbook_id'] !== $logbookId) {
                throw new RuntimeException('One or more entries were not found.');
            }
            $entries[] = $entry;
        }
        $keep = $entries[0];
        $timeFields = array(
            'single_engine_time', 'multi_engine_time', 'pic_time', 'copilot_time', 'dual_received_time',
            'instructor_time', 'solo_time', 'cross_country_time', 'cross_country_distance_nm', 'night_time',
            'instrument_time', 'actual_instrument_time', 'simulated_instrument_time',
            'basic_instrument_flying_time', 'total_flight_time',
        );
        $countFields = array('day_landings', 'night_landings', 'towered_airport_landings');
        foreach (array_slice($entries, 1) as $entry) {
            foreach ($timeFields as $field) {
                $keep[$field] = round((float)$keep[$field] + (float)$entry[$field], 2);
            }
            foreach ($countFields as $field) {
                $keep[$field] = (int)$keep[$field] + (int)$entry[$field];
            }
            $keep['remarks'] = implode(' / ', array_filter(array((string)($keep['remarks'] ?? ''), (string)($entry['remarks'] ?? ''))));
            $keep['endorsements'] = implode(' / ', array_filter(array((string)($keep['endorsements'] ?? ''), (string)($entry['endorsements'] ?? ''))));
        }
        $keep['review_status'] = 'merged';
        $before = $entries[0];
        $this->saveEntry($logbookId, $keep, $actorUserId);
        foreach (array_slice($entries, 1) as $entry) {
            $this->deleteEntry($logbookId, (int)$entry['id'], $actorUserId);
        }
        $after = $this->getEntry((int)$keep['id']);
        $this->writeAudit($logbookId, (int)$keep['id'], $actorUserId, 'entry_merged', $before, array('merged_entry_ids' => $entryIds, 'entry' => $after));
        return $after ?? array();
    }

    /**
     * @return array<string,mixed>
     */
    public function addPageFromUpload(int $logbookId, array $file, int $actorUserId): array
    {
        $this->requireSchema();
        $this->requireLogbook($logbookId);
        $url = $this->storeUpload($logbookId, $file);
        $pageNumber = $this->nextPageNumber($logbookId);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_admin_logbook_pages
              (logbook_id, page_number, image_url, original_filename, mime_type, extraction_status, extracted_json)
            VALUES
              (:logbook_id, :page_number, :image_url, :original_filename, :mime_type, 'manual', JSON_OBJECT())
        ");
        $stmt->execute(array(
            ':logbook_id' => $logbookId,
            ':page_number' => $pageNumber,
            ':image_url' => $url,
            ':original_filename' => (string)($file['name'] ?? ''),
            ':mime_type' => (string)($file['type'] ?? ''),
        ));
        $id = (int)$this->pdo->lastInsertId();
        $page = $this->getPage($id);
        $this->writeAudit($logbookId, null, $actorUserId, 'logbook_image_uploaded', null, $page);
        return $page ?? array();
    }

    /**
     * @return array{profile:string,total_rows:int,imported_count:int,candidates:list<array<string,mixed>>,warnings:list<string>}
     */
    public function importCsvUpload(int $logbookId, array $file, int $actorUserId): array
    {
        $this->requireSchema();
        $this->requireLogbook($logbookId);

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('CSV upload failed (code ' . $err . ').');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid CSV upload.');
        }

        $rows = $this->readCsvRows($tmp);
        $profile = $this->detectCsvProfile($rows['headers']);
        $created = array();
        $warnings = array();
        foreach ($rows['rows'] as $idx => $sourceRow) {
            $candidate = $this->mapEgleLegacyCsvRow($sourceRow);
            $candidate['import_profile'] = $profile;
            $candidate['source'] = $sourceRow;
            $candidate['review_status'] = 'imported';
            $candidate['metadata'] = array(
                'source' => 'csv_import',
                'profile' => $profile,
                'row_number' => $idx + 2,
                'student_name' => $sourceRow['student_name'] ?? null,
                'student_email' => $sourceRow['student_email'] ?? null,
                'instructor_email' => $sourceRow['instructor_email'] ?? null,
            );
            $created[] = $this->saveEntry($logbookId, $candidate, $actorUserId);
        }

        $this->writeAudit($logbookId, null, $actorUserId, 'csv_imported', null, array(
            'profile' => $profile,
            'original_filename' => (string)($file['name'] ?? ''),
            'total_rows' => count($rows['rows']),
            'imported_count' => count($created),
            'warnings' => $warnings,
        ));

        return array(
            'profile' => $profile,
            'total_rows' => count($rows['rows']),
            'imported_count' => count($created),
            'candidates' => $created,
            'warnings' => $warnings,
        );
    }

    /**
     * Import candidate rows already present in a page's extracted_json payload.
     * OCR/AI population can be added later without changing this review workflow.
     *
     * @return array<string,mixed>
     */
    public function attemptPageExtraction(int $logbookId, int $pageId, int $actorUserId): array
    {
        $this->requireSchema();
        $page = $this->getPage($pageId);
        if ($page === null || (int)$page['logbook_id'] !== $logbookId) {
            throw new RuntimeException('Logbook page not found.');
        }

        if ($this->existingExtractionCandidateCount($logbookId, $pageId) > 0) {
            return array(
                'page' => $page,
                'candidate_count' => 0,
                'candidates' => array(),
                'already_imported' => true,
            );
        }

        $payload = $this->decodeJson((string)($page['extracted_json'] ?? '{}'));
        if (!is_array($payload['rows'] ?? null) || $payload['rows'] === array()) {
            $extracted = $this->extractor->extractRows((string)$page['image_url'], (string)($page['mime_type'] ?? ''));
            $payload = array(
                'rows' => $extracted['rows'],
                'warnings' => $extracted['warnings'],
                'model' => $extracted['model'],
                'extracted_at' => date('c'),
            );
            $stmt = $this->pdo->prepare("
                UPDATE ipca_admin_logbook_pages
                SET extraction_status = 'extracted',
                    extracted_json = :extracted_json
                WHERE id = :id AND logbook_id = :logbook_id
            ");
            $stmt->execute(array(
                ':extracted_json' => $this->encodeJson($payload),
                ':id' => $pageId,
                ':logbook_id' => $logbookId,
            ));
            $page = $this->getPage($pageId) ?? $page;
        }

        $candidateRows = is_array($payload['rows'] ?? null) ? $payload['rows'] : array();
        $created = array();
        foreach ($candidateRows as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidate['source_page_id'] = $pageId;
            $candidate['review_status'] = 'flagged';
            $candidate['metadata'] = array(
                'source' => 'extraction_candidate',
                'accepted' => false,
                'confidence' => $candidate['confidence'] ?? null,
                'warnings' => $candidate['warnings'] ?? array(),
            );
            $created[] = $this->saveEntry($logbookId, $candidate, $actorUserId);
        }

        $status = $created !== array() ? 'review' : 'no_candidates';
        $stmt = $this->pdo->prepare("
            UPDATE ipca_admin_logbook_pages
            SET extraction_status = :status,
                extracted_json = JSON_SET(COALESCE(extracted_json, JSON_OBJECT()), '$.last_attempt_at', :attempted_at)
            WHERE id = :id AND logbook_id = :logbook_id
        ");
        $stmt->execute(array(
            ':status' => $status,
            ':attempted_at' => date('c'),
            ':id' => $pageId,
            ':logbook_id' => $logbookId,
        ));

        $after = $this->getPage($pageId);
        $this->writeAudit($logbookId, null, $actorUserId, 'logbook_extraction_attempted', $page, array(
            'page' => $after,
            'candidate_count' => count($created),
        ));

        return array(
            'page' => $after ?? array(),
            'candidate_count' => count($created),
            'candidates' => $created,
            'warnings' => is_array($payload['warnings'] ?? null) ? $payload['warnings'] : array(),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRequirementCategories(): array
    {
        $this->requireSchema();
        $stmt = $this->pdo->query("
            SELECT *
            FROM ipca_flight_requirement_categories
            ORDER BY authority, certificate, label
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function saveRequirementCategory(array $data): array
    {
        $this->requireSchema();
        $id = (int)($data['id'] ?? 0);
        $row = array(
            'authority' => strtoupper(trim((string)($data['authority'] ?? 'FAA_PART_61'))),
            'certificate' => strtoupper(trim((string)($data['certificate'] ?? 'PPL'))),
            'requirement_key' => $this->key((string)($data['requirement_key'] ?? '')),
            'label' => trim((string)($data['label'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')),
            'minimum_time' => $this->nullableDecimal($data['minimum_time'] ?? null),
            'minimum_distance_nm' => $this->nullableDecimal($data['minimum_distance_nm'] ?? null),
            'minimum_count' => $this->nullableInt($data['minimum_count'] ?? null),
            'automatic_rules_json' => $this->encodeJson($this->decodeJson((string)($data['automatic_rules_json'] ?? '{}'))),
            'manual_rules_json' => $this->encodeJson($this->decodeJson((string)($data['manual_rules_json'] ?? '{}'))),
            'allow_one_flight_multiple_requirements' => !empty($data['allow_one_flight_multiple_requirements']) ? 1 : 0,
            'allow_multiple_flights_one_requirement' => !empty($data['allow_multiple_flights_one_requirement']) ? 1 : 0,
            'status' => trim((string)($data['status'] ?? 'active')) ?: 'active',
        );
        if ($row['requirement_key'] === '' || $row['label'] === '') {
            throw new RuntimeException('Requirement key and label are required.');
        }

        if ($id > 0) {
            $sets = array();
            $params = array(':id' => $id);
            foreach ($row as $key => $value) {
                $sets[] = $key . ' = :' . $key;
                $params[':' . $key] = $value;
            }
            $stmt = $this->pdo->prepare('UPDATE ipca_flight_requirement_categories SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params);
        } else {
            $columns = array_keys($row);
            $params = array();
            foreach ($columns as $column) {
                $params[':' . $column] = $row[$column];
            }
            $stmt = $this->pdo->prepare('INSERT INTO ipca_flight_requirement_categories (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ')');
            $stmt->execute($params);
            $id = (int)$this->pdo->lastInsertId();
        }

        return $this->getRequirementCategory($id) ?? array();
    }

    public function assignRequirement(int $logbookId, int $studentUserId, int $categoryId, array $entryIds, int $actorUserId): void
    {
        $this->requireSchema();
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_requirement_assignments
              (student_user_id, logbook_id, requirement_category_id, assigned_by, status, metadata_json)
            VALUES
              (:student_user_id, :logbook_id, :requirement_category_id, :assigned_by, 'assigned', JSON_OBJECT())
        ");
        $stmt->execute(array(
            ':student_user_id' => $studentUserId > 0 ? $studentUserId : null,
            ':logbook_id' => $logbookId,
            ':requirement_category_id' => $categoryId,
            ':assigned_by' => $actorUserId > 0 ? $actorUserId : null,
        ));
        $assignmentId = (int)$this->pdo->lastInsertId();
        $ins = $this->pdo->prepare("
            INSERT IGNORE INTO ipca_flight_requirement_assignment_entries (assignment_id, entry_id)
            VALUES (:assignment_id, :entry_id)
        ");
        foreach ($entryIds as $entryId) {
            $entryId = (int)$entryId;
            if ($entryId > 0) {
                $ins->execute(array(':assignment_id' => $assignmentId, ':entry_id' => $entryId));
            }
        }
        $this->writeAudit($logbookId, null, $actorUserId, 'requirement_assigned', null, array('assignment_id' => $assignmentId, 'entry_ids' => array_values($entryIds)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listPages(int $logbookId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_admin_logbook_pages WHERE logbook_id = :id ORDER BY page_number, id');
        $stmt->execute(array(':id' => $logbookId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listEntries(int $logbookId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_admin_logbook_entries
            WHERE logbook_id = :id
              AND review_status <> 'deleted'
            ORDER BY entry_date IS NULL, entry_date, id
        ");
        $stmt->execute(array(':id' => $logbookId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listAssignments(int $logbookId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*, c.requirement_key, c.label, c.authority, c.certificate
            FROM ipca_flight_requirement_assignments a
            INNER JOIN ipca_flight_requirement_categories c ON c.id = a.requirement_category_id
            WHERE a.logbook_id = :id
            ORDER BY a.created_at DESC
        ");
        $stmt->execute(array(':id' => $logbookId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>
     */
    private function requireLogbook(int $logbookId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT l.*, u.name AS student_name, u.email AS student_email
            FROM ipca_admin_logbooks l
            LEFT JOIN users u ON u.id = l.student_user_id
            WHERE l.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $logbookId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Logbook not found.');
        }
        return $row;
    }

    private function getEntry(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_admin_logbook_entries WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $entryId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function getPage(int $pageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_admin_logbook_pages WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $pageId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function existingExtractionCandidateCount(int $logbookId, int $pageId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_admin_logbook_entries
            WHERE logbook_id = :logbook_id
              AND source_page_id = :page_id
              AND review_status <> 'deleted'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'extraction_candidate'
        ");
        $stmt->execute(array(':logbook_id' => $logbookId, ':page_id' => $pageId));
        return (int)$stmt->fetchColumn();
    }

    private function getRequirementCategory(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_flight_requirement_categories WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{headers:list<string>,rows:list<array<string,string>>}
     */
    private function readCsvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open CSV file.');
        }
        $headers = fgetcsv($handle);
        if (!is_array($headers) || $headers === array()) {
            fclose($handle);
            throw new RuntimeException('CSV file is empty.');
        }
        $headers = array_map(static fn (mixed $value): string => strtolower(trim((string)$value)), $headers);
        $rows = array();
        while (($line = fgetcsv($handle)) !== false) {
            if (!is_array($line)) {
                continue;
            }
            $row = array();
            foreach ($headers as $idx => $header) {
                $value = (string)($line[$idx] ?? '');
                $row[$header] = trim($value) === 'NULL' ? '' : trim($value);
            }
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);
        return array('headers' => $headers, 'rows' => $rows);
    }

    /**
     * @param list<string> $headers
     */
    private function detectCsvProfile(array $headers): string
    {
        $lookup = array_flip($headers);
        foreach (self::EGLE_LEGACY_COLUMNS as $required) {
            if (!array_key_exists($required, $lookup)) {
                throw new RuntimeException('Unsupported CSV format. Missing column for EGLE_LEGACY_LOGBOOK_V1: ' . $required);
            }
        }
        return 'EGLE_LEGACY_LOGBOOK_V1';
    }

    /**
     * @param array<string,string> $source
     * @return array<string,mixed>
     */
    private function mapEgleLegacyCsvRow(array $source): array
    {
        $duration = $this->durationDecimal($source['lb_dur'] ?? '');
        $engine = strtoupper(trim((string)($source['aircraft_engine'] ?? '')));
        $aircraftTypeFlag = strtoupper(trim((string)($source['aircraft_type'] ?? '')));
        $isSimulator = $aircraftTypeFlag === 'SIMULATOR' || $this->truthy($source['lb_fnpt'] ?? '');
        $isDual = $this->truthy($source['lb_dual'] ?? '');
        $isNight = $this->nightCondition($source['lb_cond'] ?? '');
        $isIfr = $this->truthy($source['lb_ifr'] ?? '');
        $isCrossCountry = $this->crossCountryFlag($source['lb_xc'] ?? '');
        $landings = max(0, (int)round((float)($source['lb_ld'] ?? 0)));

        return array(
            'entry_date' => $this->dateFromLegacyDepartureTime($source['lb_deptime'] ?? ''),
            'departure_airport' => $source['lb_dep'] ?? null,
            'departure_time' => $this->timeFromLegacyDepartureTime($source['lb_deptime'] ?? ''),
            'arrival_airport' => $source['lb_arr'] ?? null,
            'arrival_time' => null,
            'aircraft_type' => trim((string)($source['aircraft_sort'] ?? '')) !== '' ? $source['aircraft_sort'] : ($source['aircraft_type'] ?? null),
            'aircraft_registration' => $source['aircraft_name'] ?? null,
            'single_engine_time' => (!$isSimulator && $engine === 'SE') ? $duration : 0,
            'multi_engine_time' => (!$isSimulator && $engine === 'ME') ? $duration : 0,
            'pic_time' => 0,
            'copilot_time' => 0,
            'dual_received_time' => $isDual ? $duration : 0,
            'instructor_time' => $isDual ? $duration : 0,
            'solo_time' => 0,
            'cross_country_time' => $isCrossCountry ? $duration : 0,
            'cross_country_distance_nm' => 0,
            'night_time' => $isNight ? $duration : 0,
            'instrument_time' => $isIfr ? $duration : 0,
            'actual_instrument_time' => 0,
            'simulated_instrument_time' => 0,
            'basic_instrument_flying_time' => 0,
            'fnpt_simulator_time' => $isSimulator ? $duration : 0,
            'day_landings' => $isNight ? 0 : $landings,
            'night_landings' => $isNight ? $landings : 0,
            'towered_airport_landings' => 0,
            'total_flight_time' => $duration,
            'instructor_name' => $source['instructor_name'] ?? null,
            'remarks' => $this->legacyRemarks($source),
            'endorsements' => null,
        );
    }

    /**
     * @param array<string,string> $source
     */
    private function legacyRemarks(array $source): string
    {
        $parts = array();
        if (trim((string)($source['lb_xc'] ?? '')) !== '') {
            $parts[] = 'XC: ' . trim((string)$source['lb_xc']);
        }
        if (trim((string)($source['lb_cond'] ?? '')) !== '') {
            $parts[] = 'Condition: ' . trim((string)$source['lb_cond']);
        }
        if ($this->truthy($source['lb_fnpt'] ?? '')) {
            $parts[] = 'FNPT / Simulator';
        }
        if (trim((string)($source['lb_brief'] ?? '')) !== '') {
            $parts[] = 'Brief: ' . trim((string)$source['lb_brief']);
        }
        return implode(' · ', $parts);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeEntry(array $data): array
    {
        $timeFields = array(
            'single_engine_time', 'multi_engine_time', 'pic_time', 'copilot_time', 'dual_received_time',
            'instructor_time', 'solo_time', 'cross_country_time', 'cross_country_distance_nm',
            'night_time', 'instrument_time', 'actual_instrument_time', 'simulated_instrument_time',
            'basic_instrument_flying_time', 'fnpt_simulator_time', 'total_flight_time',
        );
        $row = array(
            'source_page_id' => $this->nullableInt($data['source_page_id'] ?? null),
            'external_system' => $this->textOrNull($data['external_system'] ?? null, 32),
            'external_id' => $this->textOrNull($data['external_id'] ?? null, 128),
            'import_profile' => $this->textOrNull($data['import_profile'] ?? null, 64),
            'source_json' => $this->encodeJson(is_array($data['source'] ?? null) ? $data['source'] : array()),
            'source_hash' => $this->textOrNull($data['source_hash'] ?? null, 64),
            'normalized_hash' => $this->textOrNull($data['normalized_hash'] ?? null, 64),
            'sync_status' => $this->textOrNull($data['sync_status'] ?? null, 32),
            'entry_date' => $this->dateOrNull($data['entry_date'] ?? null),
            'departure_airport' => $this->upperOrNull($data['departure_airport'] ?? null, 16),
            'departure_time' => $this->timeOrNull($data['departure_time'] ?? null),
            'arrival_airport' => $this->upperOrNull($data['arrival_airport'] ?? null, 16),
            'arrival_time' => $this->timeOrNull($data['arrival_time'] ?? null),
            'aircraft_type' => $this->textOrNull($data['aircraft_type'] ?? null, 64),
            'aircraft_registration' => $this->upperOrNull($data['aircraft_registration'] ?? null, 32),
            'day_landings' => (int)($data['day_landings'] ?? 0),
            'night_landings' => (int)($data['night_landings'] ?? 0),
            'towered_airport_landings' => (int)($data['towered_airport_landings'] ?? 0),
            'instructor_name' => $this->textOrNull($data['instructor_name'] ?? null, 255),
            'remarks' => $this->textOrNull($data['remarks'] ?? null, 4000),
            'endorsements' => $this->textOrNull($data['endorsements'] ?? null, 4000),
            'review_status' => in_array((string)($data['review_status'] ?? 'ok'), array('imported', 'needs_review', 'accepted', 'rejected', 'ok', 'flagged', 'merged', 'split'), true) ? (string)$data['review_status'] : 'ok',
            'accepted_at' => $this->dateTimeOrNull($data['accepted_at'] ?? null),
            'accepted_by' => $this->nullableInt($data['accepted_by'] ?? null),
            'metadata_json' => $this->encodeJson(is_array($data['metadata'] ?? null) ? $data['metadata'] : array()),
        );
        foreach ($timeFields as $field) {
            $row[$field] = round((float)($data[$field] ?? 0), 2);
        }
        return $row;
    }

    private function saveVariableSnapshot(array $logbook, array $variables): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_flight_variable_snapshots
              (student_user_id, logbook_id, scope_key, variables_json)
            VALUES
              (:student_user_id, :logbook_id, 'default', :variables_json)
            ON DUPLICATE KEY UPDATE
              student_user_id = VALUES(student_user_id),
              variables_json = VALUES(variables_json),
              calculated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':student_user_id' => isset($logbook['student_user_id']) ? (int)$logbook['student_user_id'] : null,
            ':logbook_id' => (int)$logbook['id'],
            ':variables_json' => $this->encodeJson($variables),
        ));
    }

    private function writeAudit(int $logbookId, ?int $entryId, int $actorUserId, string $eventType, ?array $before, ?array $after): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_admin_logbook_entry_audit
              (logbook_id, entry_id, actor_user_id, event_type, before_json, after_json, event_json, ip_address, user_agent)
            VALUES
              (:logbook_id, :entry_id, :actor_user_id, :event_type, :before_json, :after_json, JSON_OBJECT(), :ip_address, :user_agent)
        ");
        $stmt->execute(array(
            ':logbook_id' => $logbookId,
            ':entry_id' => $entryId,
            ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':event_type' => $eventType,
            ':before_json' => $before !== null ? $this->encodeJson($before) : null,
            ':after_json' => $after !== null ? $this->encodeJson($after) : null,
            ':ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ));
    }

    private function storeUpload(int $logbookId, array $file): string
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (code ' . $err . ').');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        $mime = (string)($file['type'] ?? '');
        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };
        if ($ext === '') {
            throw new RuntimeException('Only JPG, PNG, or WEBP images are supported for MVP.');
        }
        $dir = dirname(__DIR__, 2) . '/public/uploads/flight_training_logbooks/' . $logbookId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create upload directory.');
        }
        $name = 'page_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new RuntimeException('Could not store upload.');
        }
        return '/uploads/flight_training_logbooks/' . $logbookId . '/' . $name;
    }

    private function nextPageNumber(int $logbookId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(page_number), 0) + 1 FROM ipca_admin_logbook_pages WHERE logbook_id = :id');
        $stmt->execute(array(':id' => $logbookId));
        return max(1, (int)$stmt->fetchColumn());
    }

    private function requireSchema(): void
    {
        $missing = $this->missingTables();
        if ($missing !== array()) {
            throw new RuntimeException('Admin Logbook tables are not installed. Apply scripts/sql/2026_06_17_admin_logbook_requirements_foundation.sql.');
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
        ");
        $stmt->execute(array(':table' => $table));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column
        ");
        $stmt->execute(array(':table' => $table, ':column' => $column));
        return (int)$stmt->fetchColumn() > 0;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function timeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{1,2}:\d{2}/', $value) ? substr($value, 0, 5) . ':00' : null;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function dateFromLegacyDepartureTime(mixed $value): ?string
    {
        $timestamp = $this->legacyTimestamp($value);
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : null;
    }

    private function timeFromLegacyDepartureTime(mixed $value): ?string
    {
        $timestamp = $this->legacyTimestamp($value);
        return $timestamp > 0 ? date('H:i:s', $timestamp) : null;
    }

    private function legacyTimestamp(mixed $value): int
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int)$value;
        }
        $ts = strtotime($value);
        return $ts ? (int)$ts : 0;
    }

    private function textOrNull(mixed $value, int $max): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? substr($value, 0, $max) : null;
    }

    private function upperOrNull(mixed $value, int $max): ?string
    {
        $value = strtoupper(trim((string)$value));
        return $value !== '' ? substr($value, 0, $max) : null;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $value = trim((string)$value);
        return $value !== '' ? round((float)$value, 2) : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string)$value);
        return $value !== '' ? (int)$value : null;
    }

    private function durationDecimal(mixed $value): float
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0.0;
        }
        return round((float)$value, 2);
    }

    private function truthy(mixed $value): bool
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, array('1', 'Y', 'YES', 'TRUE', 'T', 'IFR', 'FNPT'), true);
    }

    private function crossCountryFlag(mixed $value): bool
    {
        $value = strtoupper(trim((string)$value));
        if ($value === '' || $value === 'NO' || $value === 'LOCAL') {
            return false;
        }
        return true;
    }

    private function nightCondition(mixed $value): bool
    {
        $value = strtoupper(trim((string)$value));
        if ($value === '') {
            return false;
        }
        return in_array($value, array('N', 'NIGHT', 'NITE'), true) || str_contains($value, 'NIGHT');
    }

    private function key(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
