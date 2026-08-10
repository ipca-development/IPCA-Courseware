<?php
declare(strict_types=1);

/**
 * Admin CRUD for the flight exercise identification catalogue.
 * ACS/SOP bindings remain foresight layers (evaluation_enabled stays off by default).
 */
final class FlightExerciseCatalogService
{
    private const CATALOG_TABLE = 'ipca_flight_exercise_catalog';
    private const ACS_TABLE = 'ipca_flight_exercise_acs_bindings';
    private const SOP_TABLE = 'ipca_flight_exercise_sop_bindings';

    public function __construct(private PDO $pdo)
    {
    }

    public function schemaReady(): bool
    {
        return $this->tableExists(self::CATALOG_TABLE);
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        $needed = array(self::CATALOG_TABLE, self::ACS_TABLE, self::SOP_TABLE);
        $missing = array();
        foreach ($needed as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listExercises(): array
    {
        if (!$this->schemaReady()) {
            return array();
        }
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::CATALOG_TABLE . '
              ORDER BY sort_order ASC, display_name ASC, id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) {
            return array();
        }
        $acs = $this->acsByExercise();
        $sop = $this->sopByExercise();
        $out = array();
        foreach ($rows as $row) {
            $code = (string)$row['exercise_code'];
            $out[] = array(
                'id' => (int)$row['id'],
                'exercise_code' => $code,
                'display_name' => (string)$row['display_name'],
                'description_text' => (string)($row['description_text'] ?? ''),
                'transcript_aliases' => $this->decodeStringList((string)($row['transcript_aliases_json'] ?? '[]')),
                'detection_rules_json' => $this->prettyJson((string)($row['detection_rules_json'] ?? '{}')),
                'detector_version' => (string)($row['detector_version'] ?? 'v1'),
                'is_active' => (int)($row['is_active'] ?? 0) === 1,
                'sort_order' => (int)($row['sort_order'] ?? 1000),
                'acs_bindings' => $acs[$code] ?? array(),
                'sop_bindings' => $sop[$code] ?? array(),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            );
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function exerciseByCode(string $exerciseCode): ?array
    {
        $code = $this->normalizeCode($exerciseCode);
        if ($code === '') {
            return null;
        }
        foreach ($this->listExercises() as $exercise) {
            if ((string)$exercise['exercise_code'] === $code) {
                return $exercise;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function upsertExercise(array $input): array
    {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Exercise catalogue tables are not installed yet.');
        }

        $code = $this->normalizeCode((string)($input['exercise_code'] ?? ''));
        $displayName = trim((string)($input['display_name'] ?? ''));
        if ($code === '' || $displayName === '') {
            throw new RuntimeException('Exercise code and display name are required.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $code)) {
            throw new RuntimeException('Exercise code must be lowercase snake_case (letters, numbers, underscore).');
        }

        $aliases = $this->parseAliases((string)($input['transcript_aliases'] ?? ''));
        if ($aliases === array()) {
            throw new RuntimeException('At least one transcript alias is required.');
        }

        $rulesJson = trim((string)($input['detection_rules_json'] ?? ''));
        if ($rulesJson === '') {
            $rulesJson = '{}';
        }
        $rulesDecoded = json_decode($rulesJson, true);
        if (!is_array($rulesDecoded)) {
            throw new RuntimeException('Detection rules must be valid JSON object.');
        }

        $description = trim((string)($input['description_text'] ?? ''));
        $detectorVersion = trim((string)($input['detector_version'] ?? 'v1'));
        if ($detectorVersion === '') {
            $detectorVersion = 'v1';
        }
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $sortOrder = (int)($input['sort_order'] ?? 1000);

        $aliasesJson = json_encode(array_values($aliases), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rulesStore = json_encode($rulesDecoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($aliasesJson === false || $rulesStore === false) {
            throw new RuntimeException('Unable to encode exercise JSON fields.');
        }

        $existing = $this->pdo->prepare('SELECT id FROM ' . self::CATALOG_TABLE . ' WHERE exercise_code = ? LIMIT 1');
        $existing->execute(array($code));
        $id = (int)$existing->fetchColumn();

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::CATALOG_TABLE . '
                    SET display_name = ?,
                        description_text = ?,
                        transcript_aliases_json = ?,
                        detection_rules_json = ?,
                        detector_version = ?,
                        is_active = ?,
                        sort_order = ?
                  WHERE exercise_code = ?'
            );
            $stmt->execute(array(
                $displayName,
                $description !== '' ? $description : null,
                $aliasesJson,
                $rulesStore,
                $detectorVersion,
                $isActive,
                $sortOrder,
                $code,
            ));
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::CATALOG_TABLE . '
                   (exercise_code, display_name, description_text, transcript_aliases_json, detection_rules_json,
                    detector_version, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array(
                $code,
                $displayName,
                $description !== '' ? $description : null,
                $aliasesJson,
                $rulesStore,
                $detectorVersion,
                $isActive,
                $sortOrder,
            ));
        }

        $saved = $this->exerciseByCode($code);
        if ($saved === null) {
            throw new RuntimeException('Exercise saved but could not be reloaded.');
        }
        return $saved;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function acsByExercise(): array
    {
        if (!$this->tableExists(self::ACS_TABLE)) {
            return array();
        }
        $stmt = $this->pdo->query(
            'SELECT exercise_code, qualification_code, acs_task_code, acs_task_title, acs_area_title,
                    evaluation_enabled, is_active
               FROM ' . self::ACS_TABLE . '
              ORDER BY qualification_code ASC, acs_task_code ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $map = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $code = (string)$row['exercise_code'];
            $map[$code] ??= array();
            $map[$code][] = array(
                'qualification_code' => (string)$row['qualification_code'],
                'acs_task_code' => (string)$row['acs_task_code'],
                'acs_task_title' => (string)$row['acs_task_title'],
                'acs_area_title' => (string)($row['acs_area_title'] ?? ''),
                'evaluation_enabled' => (int)($row['evaluation_enabled'] ?? 0) === 1,
                'is_active' => (int)($row['is_active'] ?? 0) === 1,
            );
        }
        return $map;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function sopByExercise(): array
    {
        if (!$this->tableExists(self::SOP_TABLE)) {
            return array();
        }
        $stmt = $this->pdo->query(
            'SELECT exercise_code, organization_id, sop_code, sop_title, evaluation_enabled, is_active
               FROM ' . self::SOP_TABLE . '
              ORDER BY organization_id ASC, sop_code ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $map = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $code = (string)$row['exercise_code'];
            $map[$code] ??= array();
            $map[$code][] = array(
                'organization_id' => (int)($row['organization_id'] ?? 0),
                'sop_code' => (string)$row['sop_code'],
                'sop_title' => (string)$row['sop_title'],
                'evaluation_enabled' => (int)($row['evaluation_enabled'] ?? 0) === 1,
                'is_active' => (int)($row['is_active'] ?? 0) === 1,
            );
        }
        return $map;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? $code;
        $code = preg_replace('/_+/', '_', $code) ?? $code;
        return trim($code, '_');
    }

    /**
     * @return list<string>
     */
    private function parseAliases(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: array();
        $out = array();
        foreach ($parts as $part) {
            $alias = trim((string)$part);
            if ($alias === '') {
                continue;
            }
            $out[$alias] = $alias;
        }
        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return array();
        }
        $out = array();
        foreach ($decoded as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    private function prettyJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json !== '' ? $json : '{}';
        }
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    private function tableExists(string $table): bool
    {
        static $cache = array();
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute(array($table));
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}
