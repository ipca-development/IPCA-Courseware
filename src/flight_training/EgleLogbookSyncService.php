<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminLogbookService.php';
require_once __DIR__ . '/EgleConnectionService.php';
require_once __DIR__ . '/EgleUserMappingService.php';

final class EgleLogbookSyncService
{
    public function __construct(
        private PDO $pdo,
        private AdminLogbookService $logbooks,
        private EgleConnectionService $connection,
        private EgleUserMappingService $mappings
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function syncStudent(int $ipcaUserId, int $actorUserId): array
    {
        $mapping = $this->mappings->mappingForIpcaUser($ipcaUserId);
        if ($mapping === null) {
            throw new RuntimeException('No E-GLE mapping exists for this IPCA student.');
        }
        $runId = $this->startRun('student', $ipcaUserId, (string)$mapping['egle_userid'], $actorUserId);
        try {
            $eglePdo = $this->connection->connect();
            $logbookId = $this->logbooks->getOrCreateLogbook($ipcaUserId, null, $actorUserId);
            $sourceRows = $this->fetchEgleLogbookRows($eglePdo, (string)$mapping['egle_userid']);
            $result = $this->importSourceRows($logbookId, $sourceRows, $actorUserId);
            $this->finishRun($runId, 'completed', $result);
            return array('run_id' => $runId, 'logbook_id' => $logbookId) + $result;
        } catch (Throwable $e) {
            $this->finishRun($runId, 'failed', array('error_message' => $e->getMessage()));
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function syncAllStudents(int $actorUserId): array
    {
        $runId = $this->startRun('all_students', null, null, $actorUserId);
        $summary = array(
            'imported_count' => 0,
            'changed_count' => 0,
            'unchanged_count' => 0,
            'pending_review_count' => 0,
            'student_count' => 0,
            'errors' => array(),
        );
        try {
            foreach ($this->mappings->allMappings() as $mapping) {
                $summary['student_count']++;
                try {
                    $result = $this->syncStudent((int)$mapping['ipca_user_id'], $actorUserId);
                    $summary['imported_count'] += (int)($result['imported_count'] ?? 0);
                    $summary['changed_count'] += (int)($result['changed_count'] ?? 0);
                    $summary['unchanged_count'] += (int)($result['unchanged_count'] ?? 0);
                    $summary['pending_review_count'] += (int)($result['pending_review_count'] ?? 0);
                } catch (Throwable $e) {
                    $summary['errors'][] = array(
                        'ipca_user_id' => (int)$mapping['ipca_user_id'],
                        'egle_userid' => (string)$mapping['egle_userid'],
                        'error' => $e->getMessage(),
                    );
                }
            }
            $this->finishRun($runId, $summary['errors'] === array() ? 'completed' : 'failed', $summary);
            return array('run_id' => $runId) + $summary;
        } catch (Throwable $e) {
            $this->finishRun($runId, 'failed', array('error_message' => $e->getMessage()));
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function reviewChanges(?int $logbookId = null): array
    {
        $params = array();
        $where = "
            external_system = 'EGLE'
            AND review_status <> 'deleted'
            AND (review_status IN ('imported', 'needs_review') OR sync_status IN ('imported', 'changed'))
        ";
        if ($logbookId !== null && $logbookId > 0) {
            $where .= ' AND logbook_id = :logbook_id';
            $params[':logbook_id'] = $logbookId;
        }
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_admin_logbook_entries
            WHERE " . $where . "
            ORDER BY entry_date IS NULL, entry_date, id
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        return array(
            'pending_review_count' => count($rows),
            'rows' => $rows,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestRun(?int $ipcaUserId = null): ?array
    {
        $params = array();
        $where = '1 = 1';
        if ($ipcaUserId !== null && $ipcaUserId > 0) {
            $where .= ' AND ipca_user_id = :ipca_user_id';
            $params[':ipca_user_id'] = $ipcaUserId;
        }
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_egle_sync_runs
            WHERE " . $where . "
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<array<string,mixed>> $sourceRows
     * @return array<string,mixed>
     */
    private function importSourceRows(int $logbookId, array $sourceRows, int $actorUserId): array
    {
        $summary = array(
            'imported_count' => 0,
            'changed_count' => 0,
            'unchanged_count' => 0,
            'pending_review_count' => 0,
        );
        foreach ($sourceRows as $sourceRow) {
            $externalId = trim((string)($sourceRow['lb_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $mapped = $this->mapEgleLegacyRow($sourceRow);
            $mapped['external_system'] = 'EGLE';
            $mapped['external_id'] = $externalId;
            $mapped['import_profile'] = 'EGLE_LEGACY_LOGBOOK_V1';
            $mapped['source'] = $sourceRow;
            $mapped['source_hash'] = $this->hashArray($sourceRow);
            $mapped['normalized_hash'] = $this->hashArray($this->normalizedHashPayload($mapped));
            $existingRows = $this->externalRows($externalId);

            if ($existingRows === array()) {
                $mapped['review_status'] = 'imported';
                $mapped['sync_status'] = 'imported';
                $mapped['metadata'] = array('source' => 'egle_sync', 'sync_status' => 'imported');
                $this->logbooks->saveEntry($logbookId, $mapped, $actorUserId);
                $summary['imported_count']++;
                $summary['pending_review_count']++;
                continue;
            }

            if ($this->hasHash($existingRows, (string)$mapped['normalized_hash'])) {
                $summary['unchanged_count']++;
                continue;
            }

            $pending = $this->firstPendingRow($existingRows);
            $mapped['review_status'] = 'needs_review';
            $mapped['sync_status'] = 'changed';
            $mapped['metadata'] = array('source' => 'egle_sync', 'sync_status' => 'changed');
            if ($pending !== null) {
                $mapped['id'] = (int)$pending['id'];
            }
            $this->logbooks->saveEntry($logbookId, $mapped, $actorUserId);
            $summary['changed_count']++;
            $summary['pending_review_count']++;
        }
        return $summary;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchEgleLogbookRows(PDO $eglePdo, string $egleUserid): array
    {
        $logbookColumns = $this->connection->tableColumns($eglePdo, 'logbook');
        $deviceColumns = $this->connection->tableColumns($eglePdo, 'devices');
        $userColumns = $this->connection->tableColumns($eglePdo, 'users');
        if ($logbookColumns === array()) {
            throw new RuntimeException('E-GLE logbook table was not found or exposes no columns.');
        }

        $lbStudent = $this->firstExisting($logbookColumns, array('lb_student', 'student_id', 'userid', 'user_id'));
        $lbId = $this->firstExisting($logbookColumns, array('lb_id', 'id'));
        if ($lbStudent === '' || $lbId === '') {
            throw new RuntimeException('E-GLE logbook table is missing lb_student or lb_id.');
        }
        $lbDev = $this->firstExisting($logbookColumns, array('lb_dev', 'device_id', 'aircraft_id'));
        $lbInstr = $this->firstExisting($logbookColumns, array('lb_instr', 'instructor_id'));
        $deviceId = $this->firstExisting($deviceColumns, array('dev_id', 'id', 'device_id'));
        $userId = $this->firstExisting($userColumns, array('userid', 'user_id', 'id', 'uid', 'usr_id'));

        $select = array();
        foreach ($logbookColumns as $column) {
            $select[] = 'l.' . $this->q($column) . ' AS ' . $this->q($column);
        }
        $select = array_merge($select, $this->logbookAliasSelects($logbookColumns));
        $select = array_merge($select, $this->deviceSelects($deviceColumns));
        $select = array_merge($select, $this->instructorSelects($userColumns));

        $joins = array();
        if ($lbDev !== '' && $deviceId !== '' && $deviceColumns !== array()) {
            $joins[] = 'LEFT JOIN devices d ON d.' . $this->q($deviceId) . ' = l.' . $this->q($lbDev);
        }
        if ($lbInstr !== '' && $userId !== '' && $userColumns !== array()) {
            $joins[] = 'LEFT JOIN users i ON i.' . $this->q($userId) . ' = l.' . $this->q($lbInstr);
        }
        $order = $this->firstExisting($logbookColumns, array('lb_deptime', 'flight_date', 'created_at', 'lb_id'));
        $sql = "
            SELECT " . implode(', ', $select) . "
            FROM logbook l
            " . implode("\n", $joins) . "
            WHERE l." . $this->q($lbStudent) . " = :egle_userid
            ORDER BY l." . $this->q($order !== '' ? $order : $lbId) . " ASC
        ";
        return $this->connection->selectRows($eglePdo, $sql, array(':egle_userid' => $egleUserid));
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function mapEgleLegacyRow(array $source): array
    {
        $duration = $this->durationDecimal($this->firstSourceValue($source, array('lb_dur', 'duration', 'flight_duration')));
        $engine = strtoupper(trim((string)($source['aircraft_engine'] ?? '')));
        $aircraftTypeFlag = strtoupper(trim((string)($source['aircraft_type'] ?? '')));
        $isSimulator = $aircraftTypeFlag === 'SIMULATOR' || $this->truthy($this->firstSourceValue($source, array('lb_fnpt', 'lb_sim', 'fnpt')));
        $isDual = $this->truthy($this->firstSourceValue($source, array('lb_dual', 'dual')));
        $isNight = $this->nightCondition($this->firstSourceValue($source, array('lb_cond', 'lb_condition', 'condition', 'day_night')));
        $isIfr = $this->truthy($this->firstSourceValue($source, array('lb_ifr', 'ifr')));
        $isCrossCountry = $this->crossCountryFlag($this->firstSourceValue($source, array('lb_xc', 'xc')));
        $landings = max(0, (int)round((float)$this->firstSourceValue($source, array('lb_ld', 'lb_landings', 'landings'))));

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
            'instructor_name' => $this->firstSourceValue($source, array('instructor_name', 'instructor_full_name', 'instr_name')),
            'remarks' => $this->legacyRemarks($source),
            'endorsements' => null,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function externalRows(string $externalId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_admin_logbook_entries
            WHERE external_system = 'EGLE'
              AND external_id = :external_id
              AND review_status <> 'deleted'
            ORDER BY id DESC
        ");
        $stmt->execute(array(':external_id' => $externalId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function hasHash(array $rows, string $normalizedHash): bool
    {
        foreach ($rows as $row) {
            if ((string)($row['normalized_hash'] ?? '') === $normalizedHash) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function firstPendingRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            $status = (string)($row['review_status'] ?? '');
            if (in_array($status, array('imported', 'needs_review', 'rejected', 'flagged'), true)) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $mapped
     * @return array<string,mixed>
     */
    private function normalizedHashPayload(array $mapped): array
    {
        $payload = $mapped;
        unset($payload['source'], $payload['source_hash'], $payload['normalized_hash'], $payload['metadata']);
        ksort($payload);
        return $payload;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function hashArray(array $value): string
    {
        ksort($value);
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function startRun(string $type, ?int $ipcaUserId, ?string $egleUserid, int $actorUserId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_egle_sync_runs
              (run_type, status, ipca_user_id, egle_userid, started_by_user_id, metadata_json)
            VALUES
              (:run_type, 'started', :ipca_user_id, :egle_userid, :started_by_user_id, JSON_OBJECT())
        ");
        $stmt->execute(array(
            ':run_type' => $type,
            ':ipca_user_id' => $ipcaUserId,
            ':egle_userid' => $egleUserid,
            ':started_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $result
     */
    private function finishRun(int $runId, string $status, array $result): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_egle_sync_runs
            SET status = :status,
                completed_at = CURRENT_TIMESTAMP,
                imported_count = :imported_count,
                changed_count = :changed_count,
                unchanged_count = :unchanged_count,
                pending_review_count = :pending_review_count,
                error_message = :error_message,
                metadata_json = :metadata_json
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':status' => $status,
            ':imported_count' => (int)($result['imported_count'] ?? 0),
            ':changed_count' => (int)($result['changed_count'] ?? 0),
            ':unchanged_count' => (int)($result['unchanged_count'] ?? 0),
            ':pending_review_count' => (int)($result['pending_review_count'] ?? 0),
            ':error_message' => isset($result['error_message']) ? (string)$result['error_message'] : null,
            ':metadata_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $runId,
        ));
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function logbookAliasSelects(array $columns): array
    {
        return array_filter(array(
            $this->aliasSelect($columns, 'l', array('lb_landings', 'landings', 'ldg'), 'lb_ld_alias'),
            $this->aliasSelect($columns, 'l', array('lb_condition', 'condition', 'day_night'), 'lb_cond_alias'),
        ));
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function deviceSelects(array $columns): array
    {
        return array_filter(array(
            $this->aliasSelect($columns, 'd', array('dev_name', 'name', 'device_name', 'registration'), 'aircraft_name'),
            $this->aliasSelect($columns, 'd', array('dev_type', 'aircraft_type', 'type'), 'aircraft_type'),
            $this->aliasSelect($columns, 'd', array('dev_engine', 'engine', 'aircraft_engine'), 'aircraft_engine'),
            $this->aliasSelect($columns, 'd', array('dev_sort', 'sort', 'aircraft_sort', 'model'), 'aircraft_sort'),
        ));
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function instructorSelects(array $columns): array
    {
        $nameSelect = $this->aliasSelect($columns, 'i', array('name', 'fullname', 'full_name', 'display_name', 'displayname', 'user_name', 'username', 'user_fullname', 'user_full_name'), 'instructor_name');
        if ($nameSelect === '') {
            $firstCol = $this->firstExisting($columns, array('voornaam', 'firstname', 'first_name', 'fname', 'name_first', 'first', 'user_firstname', 'user_first_name', 'usr_firstname'));
            $lastCol = $this->firstExisting($columns, array('naam', 'lastname', 'last_name', 'lname', 'name_last', 'last', 'surname', 'user_lastname', 'user_last_name', 'user_surname', 'usr_lastname'));
            if ($firstCol !== '' || $lastCol !== '') {
                $nameSelect = "TRIM(CONCAT(COALESCE(" . ($firstCol !== '' ? 'i.' . $this->q($firstCol) : "''") . ", ''), ' ', COALESCE(" . ($lastCol !== '' ? 'i.' . $this->q($lastCol) : "''") . ", ''))) AS instructor_name";
            }
        }
        return array_filter(array(
            $this->aliasSelect($columns, 'i', array('email', 'user_email', 'u_email', 'mail', 'user_mail'), 'instructor_email'),
            $nameSelect,
        ));
    }

    /**
     * @param list<string> $columns
     * @param list<string> $candidates
     */
    private function aliasSelect(array $columns, string $alias, array $candidates, string $output): string
    {
        $column = $this->firstExisting($columns, $candidates);
        return $column !== '' ? $alias . '.' . $this->q($column) . ' AS ' . $this->q($output) : '';
    }

    /**
     * @param list<string> $columns
     * @param list<string> $candidates
     */
    private function firstExisting(array $columns, array $candidates): string
    {
        $lookup = array_change_key_case(array_flip($columns), CASE_LOWER);
        foreach ($candidates as $candidate) {
            if (array_key_exists(strtolower($candidate), $lookup)) {
                return $columns[(int)$lookup[strtolower($candidate)]];
            }
        }
        return '';
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     */
    private function firstSourceValue(array $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && trim((string)$source[$key]) !== '') {
                return $source[$key];
            }
        }
        foreach ($keys as $key) {
            $alias = $key . '_alias';
            if (array_key_exists($alias, $source) && trim((string)$source[$alias]) !== '') {
                return $source[$alias];
            }
        }
        return '';
    }

    private function durationDecimal(mixed $value): float
    {
        $value = trim((string)$value);
        return $value !== '' ? round((float)$value, 2) : 0.0;
    }

    private function truthy(mixed $value): bool
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, array('1', 'Y', 'YES', 'TRUE', 'T', 'IFR', 'FNPT'), true);
    }

    private function crossCountryFlag(mixed $value): bool
    {
        $value = strtoupper(trim((string)$value));
        return $value !== '' && $value !== 'NO' && $value !== 'LOCAL';
    }

    private function nightCondition(mixed $value): bool
    {
        $value = strtoupper(trim((string)$value));
        if ($value === '') {
            return false;
        }
        return in_array($value, array('N', 'NIGHT', 'NITE', 'NACHT', 'DARK'), true)
            || str_contains($value, 'NIGHT')
            || str_contains($value, 'NACHT');
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

    /**
     * @param array<string,mixed> $source
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
        return implode(' · ', $parts);
    }
}
