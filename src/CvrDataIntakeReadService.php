<?php
declare(strict_types=1);

final class CvrDataIntakeReadService
{
    /** @var array<string,bool> */
    private array $tableCache = array();

    /** @var array<string,array<string,bool>> */
    private array $columnCache = array();

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{available:bool,rows:list<array<string,mixed>>,message:string}
     */
    public function dispatchRows(int $limit = 100): array
    {
        $table = $this->firstExistingTable(array('ipca_cvr_dispatches', 'ipca_cvr_dispatch_records'));
        if ($table === null) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'No Dispatch intake table is connected yet. Dispatch records created in the CVR app are still local-only.',
            );
        }

        $columns = $this->columns($table);
        $deviceExpression = $table === 'ipca_cvr_dispatches'
            && isset($columns['device_id'])
            && $this->tableExists('ipca_cvr_devices')
            ? "COALESCE((SELECT d.device_uuid FROM ipca_cvr_devices d WHERE d.id = ipca_cvr_dispatches.device_id LIMIT 1), '') AS device_identifier"
            : $this->columnExpression($columns, array('device_uuid', 'device_id'), 'device_identifier');
        $select = array(
            $this->columnExpression($columns, array('id'), 'id', '0'),
            $this->columnExpression($columns, array('dispatch_uuid', 'dispatch_id', 'id'), 'dispatch_uuid'),
            $this->columnExpression($columns, array('current_version', 'version', 'dispatch_version'), 'dispatch_version', '1'),
            $this->columnExpression($columns, array('aircraft_registration', 'tail_number'), 'aircraft_registration'),
            $this->columnExpression($columns, array('mission_code'), 'mission_code'),
            $this->columnExpression($columns, array('status'), 'status'),
            $this->columnExpression($columns, array('source', 'dispatch_source'), 'source'),
            $deviceExpression,
            $this->columnExpression($columns, array('crew_json', 'crew'), 'crew_json', 'NULL'),
            $this->columnExpression($columns, array('error_message', 'last_error'), 'error_message'),
            $this->columnExpression($columns, array('workflow_flight_record_uuid', 'flight_record_uuid'), 'workflow_flight_record_uuid'),
            $this->columnExpression($columns, array('last_received_at', 'received_at', 'created_at', 'updated_at'), 'received_at', 'NULL'),
        );
        if ($table === 'ipca_cvr_dispatches' && $this->tableExists('ipca_cvr_dispatch_versions')) {
            $select[] = "COALESCE((
                SELECT v.receipt_uuid
                FROM ipca_cvr_dispatch_versions v
                WHERE v.dispatch_id = ipca_cvr_dispatches.id
                  AND v.dispatch_version = ipca_cvr_dispatches.current_version
                LIMIT 1
            ), '') AS server_receipt_id";
        } else {
            $select[] = "'' AS server_receipt_id";
        }
        $orderColumn = $this->firstColumn($columns, array('last_received_at', 'received_at', 'created_at', 'updated_at', 'id')) ?? 'id';
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdentifier($table)
            . ' ORDER BY ' . $this->quoteIdentifier($orderColumn) . ' DESC'
            . ' LIMIT ' . $this->normalizeLimit($limit);

        return array(
            'available' => true,
            'rows' => $this->fetchAll($sql),
            'message' => '',
        );
    }

    /**
     * @return array{available:bool,rows:list<array<string,mixed>>,message:string}
     */
    public function audioRows(int $limit = 100): array
    {
        $table = 'ipca_cockpit_recordings';
        if (!$this->tableExists($table)) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'Cockpit Audio storage is not installed.',
            );
        }

        $columns = $this->columns($table);
        $select = array(
            $this->columnExpression($columns, array('id'), 'id', '0'),
            $this->columnExpression($columns, array('recording_uid'), 'recording_uid'),
            $this->columnExpression($columns, array('aircraft_registration', 'aircraft_ident'), 'aircraft_registration'),
            $this->columnExpression($columns, array('flight_session_uid', 'session_uuid'), 'session_uuid'),
            $this->columnExpression($columns, array('started_at'), 'started_at', 'NULL'),
            $this->columnExpression($columns, array('duration_seconds'), 'duration_seconds', '0'),
            $this->columnExpression($columns, array('input_device'), 'input_device'),
            $this->columnExpression($columns, array('original_filename'), 'original_filename'),
            $this->columnExpression($columns, array('file_size_bytes'), 'file_size_bytes', '0'),
            $this->columnExpression($columns, array('upload_status'), 'upload_status'),
            $this->columnExpression($columns, array('transcription_status'), 'transcription_status'),
            $this->columnExpression($columns, array('transcription_progress'), 'transcription_progress', '0'),
            $this->columnExpression($columns, array('error_message'), 'error_message'),
            $this->columnExpression($columns, array('uploaded_at', 'created_at'), 'received_at', 'NULL'),
            $this->columnExpression($columns, array('created_at'), 'created_at', 'NULL'),
        );
        $orderColumn = $this->firstColumn($columns, array('uploaded_at', 'created_at', 'id')) ?? 'id';
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdentifier($table)
            . ' ORDER BY ' . $this->quoteIdentifier($orderColumn) . ' DESC'
            . ' LIMIT ' . $this->normalizeLimit($limit);

        return array(
            'available' => true,
            'rows' => $this->fetchAll($sql),
            'message' => '',
        );
    }

    /**
     * @return array{available:bool,rows:list<array<string,mixed>>,message:string}
     */
    public function garminRows(int $limit = 100): array
    {
        $table = 'ipca_garmin_csv_files';
        if (!$this->tableExists($table)) {
            return array(
                'available' => false,
                'rows' => array(),
                'message' => 'Garmin CSV storage is not installed.',
            );
        }

        $columns = $this->columns($table);
        $validationJoin = '';
        $validationSelect = "'' AS validation_status, '' AS validation_severity";
        if ($this->tableExists('ipca_garmin_csv_validation_results')) {
            $validationJoin = "
                LEFT JOIN ipca_garmin_csv_validation_results v
                  ON v.id = (
                    SELECT v2.id
                    FROM ipca_garmin_csv_validation_results v2
                    WHERE v2.csv_file_id = f.id
                    ORDER BY v2.id DESC
                    LIMIT 1
                  )";
            $validationSelect = "COALESCE(v.status, '') AS validation_status, COALESCE(v.severity, '') AS validation_severity";
        }

        $uploadSourceExpression = isset($columns['upload_source'])
            ? "COALESCE(f.upload_source, '')"
            : "CASE WHEN COALESCE(f.source, '') = 'garmin_cloud' THEN 'desktop_sync_agent' ELSE 'iphone_files_import' END";
        $sourceExpression = isset($columns['source']) ? "COALESCE(f.source, '')" : "''";
        $providerExpression = isset($columns['provider_name']) ? "COALESCE(f.provider_name, '')" : "''";
        $receivedExpression = isset($columns['created_at'])
            ? 'f.created_at'
            : (isset($columns['updated_at']) ? 'f.updated_at' : 'NULL');

        $where = "(
              {$uploadSourceExpression} IN ('iphone_files_import','cvr_app','ios_share','desktop_sync_agent')
              OR {$sourceExpression} IN ('iphone_files_import','cvr_device','cvr_app','garmin_cloud')
            )
            AND LOWER({$sourceExpression}) NOT LIKE '%historical%'
            AND LOWER({$sourceExpression}) NOT LIKE '%flightcircle%'";

        $select = array(
            $this->prefixedColumnExpression($columns, 'f', array('id'), 'id', '0'),
            $this->prefixedColumnExpression($columns, 'f', array('csv_file_uuid'), 'csv_file_uuid'),
            $this->prefixedColumnExpression($columns, 'f', array('original_filename'), 'original_filename'),
            $this->prefixedColumnExpression($columns, 'f', array('aircraft_registration', 'aircraft_ident'), 'aircraft_registration'),
            $this->prefixedColumnExpression($columns, 'f', array('sha256'), 'sha256'),
            $this->prefixedColumnExpression($columns, 'f', array('file_size_bytes'), 'file_size_bytes', '0'),
            $this->prefixedColumnExpression($columns, 'f', array('evidence_status'), 'evidence_status'),
            $this->prefixedColumnExpression($columns, 'f', array('valid_row_count'), 'valid_row_count', '0'),
            $this->prefixedColumnExpression($columns, 'f', array('first_valid_sample_utc'), 'first_valid_sample_utc', 'NULL'),
            $this->prefixedColumnExpression($columns, 'f', array('last_valid_sample_utc'), 'last_valid_sample_utc', 'NULL'),
            $this->prefixedColumnExpression($columns, 'f', array('system_identifier'), 'system_identifier'),
            $this->prefixedColumnExpression($columns, 'f', array('workflow_flight_record_uuid'), 'workflow_flight_record_uuid'),
            $this->prefixedColumnExpression($columns, 'f', array('session_id'), 'session_id', 'NULL'),
            "{$uploadSourceExpression} AS upload_source",
            "{$sourceExpression} AS source",
            "{$providerExpression} AS provider_name",
            "{$receivedExpression} AS received_at",
            $validationSelect,
        );

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . $this->quoteIdentifier($table) . ' f'
            . $validationJoin
            . ' WHERE ' . $where
            . ' ORDER BY received_at DESC, f.id DESC'
            . ' LIMIT ' . $this->normalizeLimit($limit);

        $rows = $this->fetchAll($sql);
        foreach ($rows as &$row) {
            $uploadSource = strtolower(trim((string)($row['upload_source'] ?? '')));
            $source = strtolower(trim((string)($row['source'] ?? '')));
            $row['source_label'] = $uploadSource === 'desktop_sync_agent' || $source === 'garmin_cloud'
                ? 'IPCA SYNC AGENT'
                : 'CVR APP';
        }
        unset($row);

        return array(
            'available' => true,
            'rows' => $rows,
            'message' => '',
        );
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute(array($table));
        return $this->tableCache[$table] = (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param list<string> $tables
     */
    private function firstExistingTable(array $tables): ?string
    {
        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                return $table;
            }
        }
        return null;
    }

    /**
     * @return array<string,bool>
     */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $stmt = $this->pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute(array($table));
        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[(string)$column] = true;
        }
        return $this->columnCache[$table] = $columns;
    }

    /**
     * @param array<string,bool> $available
     * @param list<string> $candidates
     */
    private function firstColumn(array $available, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($available[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param array<string,bool> $available
     * @param list<string> $candidates
     */
    private function columnExpression(array $available, array $candidates, string $alias, string $fallback = "''"): string
    {
        $column = $this->firstColumn($available, $candidates);
        return ($column !== null ? $this->quoteIdentifier($column) : $fallback)
            . ' AS ' . $this->quoteIdentifier($alias);
    }

    /**
     * @param array<string,bool> $available
     * @param list<string> $candidates
     */
    private function prefixedColumnExpression(array $available, string $prefix, array $candidates, string $alias, string $fallback = "''"): string
    {
        $column = $this->firstColumn($available, $candidates);
        return ($column !== null ? $prefix . '.' . $this->quoteIdentifier($column) : $fallback)
            . ' AS ' . $this->quoteIdentifier($alias);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(500, $limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        return is_array($rows) ? $rows : array();
    }
}
