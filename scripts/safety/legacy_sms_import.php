<?php
declare(strict_types=1);

/**
 * Stages, reviews and selectively promotes the legacy SMS SQL dump.
 *
 * php scripts/safety/legacy_sms_import.php --mode=stage --dump=/restricted/legacy.sql
 * php scripts/safety/legacy_sms_import.php --mode=review --manifest=/restricted/review.json --user-id=42
 * php scripts/safety/legacy_sms_import.php --mode=promote --batch=<uuid> --user-id=42
 *
 * Review manifest:
 * {"batch_uuid":"...","decisions":[{"source_entity_type":"ocr","source_key":"1",
 * "decision":"approved","rationale":"Source checked against controlled archive."}]}
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetySupport.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetyAuditEventService.php';

$options = getopt('', array(
    'mode:',
    'dump::',
    'manifest::',
    'batch::',
    'organization-id::',
    'user-id::',
    'pretty',
));
$mode = strtolower(trim((string)($options['mode'] ?? '')));
$organizationId = max(1, (int)($options['organization-id'] ?? 1));
$userId = (int)($options['user-id'] ?? 0);
$pdo = null;

try {
    if ($mode !== 'preflight') {
        $pdo = cw_db();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $result = match ($mode) {
        'preflight' => legacyPreflight((string)($options['dump'] ?? '')),
        'stage' => legacyStage(
            $pdo,
            $organizationId,
            (string)($options['dump'] ?? '')
        ),
        'review' => legacyReview(
            $pdo,
            $organizationId,
            $userId,
            (string)($options['manifest'] ?? '')
        ),
        'promote' => legacyPromote(
            $pdo,
            $organizationId,
            $userId,
            (string)($options['batch'] ?? '')
        ),
        default => throw new InvalidArgumentException('--mode must be preflight, stage, review, or promote.'),
    };
    $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if (array_key_exists('pretty', $options)) {
        $flags |= JSON_PRETTY_PRINT;
    }
    fwrite(STDOUT, json_encode(array('ok' => true) + $result, $flags) . "\n");
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, SafetySupport::json(array(
        'ok' => false,
        'error' => get_class($e),
        'message' => $e->getMessage(),
    )) . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function legacyPreflight(string $dumpPath): array
{
    if ($dumpPath === '' || !is_file($dumpPath) || !is_readable($dumpPath)) {
        throw new InvalidArgumentException('--dump must name a readable SQL dump.');
    }
    $sql = file_get_contents($dumpPath);
    if (!is_string($sql)) {
        throw new RuntimeException('Could not read the legacy SQL dump.');
    }
    $rows = legacySqlRows(
        $sql,
        array('haz', 'haz_cat', 'map_ud_haz', 'map_ud_or', 'ocr', 'ud')
    );
    $counts = array();
    $quarantined = 0;
    $errorCounts = array();
    foreach ($rows as $row) {
        $table = (string)$row['_table'];
        unset($row['_table']);
        $counts[$table] = ($counts[$table] ?? 0) + 1;
        $errors = legacyValidateRow($table, $row);
        if ($errors !== array()) {
            $quarantined++;
            foreach ($errors as $error) {
                $key = $table . ':' . $error['code'] . ':' . $error['field'];
                $errorCounts[$key] = ($errorCounts[$key] ?? 0) + 1;
            }
        }
    }
    ksort($counts);
    ksort($errorCounts);
    return array(
        'mode' => 'preflight',
        'dump_sha256' => hash_file('sha256', $dumpPath),
        'source_rows' => count($rows),
        'table_counts' => $counts,
        'validated_rows' => count($rows) - $quarantined,
        'quarantined_rows' => $quarantined,
        'validation_error_counts' => $errorCounts,
    );
}

/** @return array<string,mixed> */
function legacyStage(PDO $pdo, int $organizationId, string $dumpPath): array
{
    if ($dumpPath === '' || !is_file($dumpPath) || !is_readable($dumpPath)) {
        throw new InvalidArgumentException('--dump must name a readable SQL dump.');
    }
    $sql = file_get_contents($dumpPath);
    if (!is_string($sql)) {
        throw new RuntimeException('Could not read the legacy SQL dump.');
    }
    $tables = array('haz', 'haz_cat', 'map_ud_haz', 'map_ud_or', 'ocr', 'ud');
    $rows = legacySqlRows($sql, $tables);
    $batchUuid = SafetySupport::uuid();
    $insert = $pdo->prepare(
        'INSERT INTO ipca_safety_legacy_staging
         (organization_id, import_batch_uuid, source_system, source_entity_type,
          source_key, payload_json, payload_sha256, validation_status, validation_errors_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $existing = $pdo->prepare(
        'SELECT payload_sha256 FROM ipca_safety_legacy_staging
         WHERE organization_id = ? AND source_system = ? AND source_entity_type = ? AND source_key = ?'
    );
    $counts = array('staged' => 0, 'validated' => 0, 'quarantined' => 0, 'unchanged' => 0);
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $table = $row['_table'];
        unset($row['_table']);
        $key = legacySourceKey($table, $row);
        $errors = legacyValidateRow($table, $row);
        $status = $errors === array() ? 'validated' : 'quarantined';
        $payloadJson = SafetySupport::json($row);
        $hash = SafetySupport::digest($payloadJson);
        $existing->execute(array($organizationId, 'legacy_sms1', $table, $key));
        $existingHash = $existing->fetchColumn();
        if (is_string($existingHash)) {
            if (!hash_equals($existingHash, $hash)) {
                throw new RuntimeException('Legacy source changed after staging: ' . $table . '/' . $key);
            }
            $counts['unchanged']++;
            continue;
        }
        $insert->execute(array(
            $organizationId,
            $batchUuid,
            'legacy_sms1',
            $table,
            $key,
            $payloadJson,
            $hash,
            $status,
            $errors === array() ? null : SafetySupport::json($errors),
        ));
        $counts['staged']++;
        $counts[$status]++;
    }
    $pdo->commit();
    return array(
        'mode' => 'stage',
        'batch_uuid' => $batchUuid,
        'dump_sha256' => hash_file('sha256', $dumpPath),
        'source_rows' => count($rows),
        'counts' => $counts,
    );
}

/** @return array<string,mixed> */
function legacyReview(
    PDO $pdo,
    int $organizationId,
    int $userId,
    string $manifestPath
): array {
    if ($userId < 1) {
        throw new InvalidArgumentException('--user-id is required for review.');
    }
    if ($manifestPath === '' || !is_file($manifestPath) || !is_readable($manifestPath)) {
        throw new InvalidArgumentException('--manifest must name a readable review manifest.');
    }
    $raw = file_get_contents($manifestPath);
    $manifest = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($manifest) || !is_array($manifest['decisions'] ?? null)) {
        throw new InvalidArgumentException('The review manifest is invalid.');
    }
    $batch = strtolower(trim((string)($manifest['batch_uuid'] ?? '')));
    $stmt = $pdo->prepare(
        "UPDATE ipca_safety_legacy_staging
         SET validation_status = ?, reviewed_by_user_id = ?,
             reviewed_at_utc = CURRENT_TIMESTAMP(3), review_rationale = ?
         WHERE organization_id = ? AND import_batch_uuid = ?
           AND source_entity_type = ? AND source_key = ?
           AND validation_status IN ('validated','quarantined')"
    );
    $pdo->beginTransaction();
    $reviewed = 0;
    foreach ($manifest['decisions'] as $decision) {
        if (!is_array($decision)) {
            throw new InvalidArgumentException('Every review decision must be an object.');
        }
        $state = (string)($decision['decision'] ?? '');
        if (!in_array($state, array('approved', 'quarantined'), true)) {
            throw new InvalidArgumentException('Review decisions must be approved or quarantined.');
        }
        $rationale = trim((string)($decision['rationale'] ?? ''));
        if ($rationale === '') {
            throw new InvalidArgumentException('Every review decision requires a rationale.');
        }
        $stmt->execute(array(
            $state,
            $userId,
            mb_substr($rationale, 0, 12000),
            $organizationId,
            $batch,
            (string)($decision['source_entity_type'] ?? ''),
            (string)($decision['source_key'] ?? ''),
        ));
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('A review item was missing, duplicated, or no longer reviewable.');
        }
        $reviewed++;
    }
    $pdo->commit();
    return array(
        'mode' => 'review',
        'batch_uuid' => $batch,
        'reviewed' => $reviewed,
        'manifest_sha256' => hash('sha256', (string)$raw),
    );
}

/** @return array<string,mixed> */
function legacyPromote(PDO $pdo, int $organizationId, int $userId, string $batchUuid): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('--user-id is required for promotion.');
    }
    $batchUuid = strtolower(trim($batchUuid));
    if (preg_match('/^[0-9a-f-]{36}$/', $batchUuid) !== 1) {
        throw new InvalidArgumentException('--batch must be a valid import batch UUID.');
    }
    $stmt = $pdo->prepare(
        "SELECT s.* FROM ipca_safety_legacy_staging s
         WHERE s.organization_id = ? AND s.import_batch_uuid = ?
           AND s.validation_status = 'approved'
           AND s.source_entity_type IN ('ocr','haz')
           AND NOT EXISTS (
             SELECT 1 FROM ipca_safety_import_provenance p
             WHERE p.organization_id = s.organization_id AND p.staging_id = s.id
           )
         ORDER BY FIELD(s.source_entity_type, 'ocr', 'haz'), s.id"
    );
    $stmt->execute(array($organizationId, $batchUuid));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reportInsert = $pdo->prepare(
        "INSERT INTO ipca_safety_reports
         (organization_id, report_uuid, report_number, channel, category_code, title,
          narrative, event_at_utc, immediate_action, status, confidentiality,
          submitted_at_utc, closed_at_utc)
         VALUES (?, ?, ?, 'legacy_import', 'legacy_occurrence', ?, ?, ?, ?, 'legacy_archived',
                 'restricted', ?, ?)"
    );
    $hazardInsert = $pdo->prepare(
        "INSERT INTO ipca_safety_hazards
         (organization_id, hazard_uuid, title, description, hazard_status, created_by_user_id)
         VALUES (?, ?, ?, ?, 'open', ?)"
    );
    $occurrenceInsert = $pdo->prepare(
        "INSERT INTO ipca_safety_occurrences
         (organization_id, occurrence_uuid, report_id, occurrence_type, occurred_at_utc, state)
         VALUES (?, ?, ?, 'legacy_occurrence', ?, 'legacy_archived')"
    );
    $provenanceInsert = $pdo->prepare(
        'INSERT INTO ipca_safety_import_provenance
         (organization_id, staging_id, target_type, target_id, import_action,
          imported_by_user_id, mapping_version)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statusUpdate = $pdo->prepare(
        "UPDATE ipca_safety_legacy_staging SET validation_status = 'imported' WHERE id = ?"
    );
    $events = new SafetyAuditEventService($pdo);
    $counts = array('report' => 0, 'occurrence' => 0, 'hazard' => 0);
    $pdo->beginTransaction();
    foreach ($rows as $staging) {
        $payload = json_decode((string)$staging['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $type = (string)$staging['source_entity_type'];
        $targets = array();
        if ($type === 'ocr') {
            $eventAt = legacyDate($payload['or_issued'] ?? null);
            $narrative = legacySections(array(
                'Description' => $payload['or_description'] ?? null,
                'Actions' => $payload['or_actions'] ?? null,
                'Mitigation' => $payload['or_mitigation'] ?? null,
                'Legacy file reference' => $payload['or_file'] ?? null,
            ));
            $reportNumber = 'LEGACY-OR-' . str_pad((string)$staging['source_key'], 4, '0', STR_PAD_LEFT);
            $reportInsert->execute(array(
                $organizationId,
                SafetySupport::uuid(),
                $reportNumber,
                mb_substr(trim((string)$payload['or_title']), 0, 240),
                $narrative,
                $eventAt,
                mb_substr(trim((string)($payload['or_actions'] ?? '')), 0, 65535) ?: null,
                $eventAt,
                legacyDate($payload['or_updated'] ?? null) ?? $eventAt,
            ));
            $reportId = (int)$pdo->lastInsertId();
            $targets[] = array('type' => 'report', 'id' => $reportId);
            $occurrenceInsert->execute(array(
                $organizationId,
                SafetySupport::uuid(),
                $reportId,
                $eventAt,
            ));
            $targets[] = array('type' => 'occurrence', 'id' => (int)$pdo->lastInsertId());
        } else {
            $description = legacySections(array(
                'Description' => $payload['haz_desc'] ?? null,
                'Defences' => $payload['haz_def'] ?? null,
                'Reference' => $payload['haz_ref'] ?? null,
                'Comments' => $payload['haz_com'] ?? null,
            ));
            $hazardInsert->execute(array(
                $organizationId,
                SafetySupport::uuid(),
                mb_substr(trim((string)$payload['haz_name']), 0, 240),
                $description,
                $userId,
            ));
            $targets[] = array('type' => 'hazard', 'id' => (int)$pdo->lastInsertId());
        }
        $statusUpdate->execute(array((int)$staging['id']));
        foreach ($targets as $target) {
            $provenanceInsert->execute(array(
                $organizationId,
                (int)$staging['id'],
                $target['type'],
                $target['id'],
                'created',
                $userId,
                'legacy-sms1-v1',
            ));
            $events->append(
                $organizationId,
                $target['type'],
                $target['id'],
                'safety.legacy_record_imported',
                'user',
                $userId,
                null,
                array(
                    'source_system' => 'legacy_sms1',
                    'source_entity_type' => $type,
                    'source_key' => (string)$staging['source_key'],
                    'payload_sha256' => (string)$staging['payload_sha256'],
                    'transmit_to_eccairs' => false,
                )
            );
            $counts[$target['type']]++;
        }
    }
    $pdo->commit();
    return array(
        'mode' => 'promote',
        'batch_uuid' => $batchUuid,
        'counts' => $counts,
        'eccairs_transmission_queued' => 0,
    );
}

/**
 * @param list<string> $allowedTables
 * @return list<array<string,mixed>>
 */
function legacySqlRows(string $sql, array $allowedTables): array
{
    $rows = array();
    $offset = 0;
    while (($start = strpos($sql, 'INSERT INTO `', $offset)) !== false) {
        if (preg_match('/\GINSERT INTO `([^`]+)`\s*\(([^)]+)\)\s*VALUES\s*/A', $sql, $match, 0, $start) !== 1) {
            $offset = $start + 12;
            continue;
        }
        $table = $match[1];
        $valuesStart = $start + strlen($match[0]);
        $end = legacySqlStatementEnd($sql, $valuesStart);
        $offset = $end + 1;
        if (!in_array($table, $allowedTables, true)) {
            continue;
        }
        preg_match_all('/`([^`]+)`/', $match[2], $columnMatches);
        $columns = $columnMatches[1];
        foreach (legacySqlTuples(substr($sql, $valuesStart, $end - $valuesStart)) as $values) {
            if (count($values) !== count($columns)) {
                throw new RuntimeException('Column/value mismatch in legacy table ' . $table . '.');
            }
            $row = array_combine($columns, $values);
            if (!is_array($row)) {
                throw new RuntimeException('Could not parse a legacy row.');
            }
            $rows[] = array('_table' => $table) + $row;
        }
    }
    return $rows;
}

function legacySqlStatementEnd(string $sql, int $offset): int
{
    $quoted = false;
    $escaped = false;
    $length = strlen($sql);
    for ($index = $offset; $index < $length; $index++) {
        $character = $sql[$index];
        if ($escaped) {
            $escaped = false;
            continue;
        }
        if ($quoted && $character === '\\') {
            $escaped = true;
            continue;
        }
        if ($character === "'") {
            $quoted = !$quoted;
            continue;
        }
        if (!$quoted && $character === ';') {
            return $index;
        }
    }
    throw new RuntimeException('Unterminated INSERT statement in legacy dump.');
}

/** @return list<list<mixed>> */
function legacySqlTuples(string $valuesSql): array
{
    $tuples = array();
    $current = array();
    $token = '';
    $quoted = false;
    $escaped = false;
    $depth = 0;
    $length = strlen($valuesSql);
    for ($index = 0; $index < $length; $index++) {
        $character = $valuesSql[$index];
        if ($escaped) {
            $token .= '\\' . $character;
            $escaped = false;
            continue;
        }
        if ($quoted && $character === '\\') {
            $escaped = true;
            continue;
        }
        if ($character === "'") {
            $quoted = !$quoted;
            $token .= $character;
            continue;
        }
        if ($quoted) {
            $token .= $character;
            continue;
        }
        if ($character === '(') {
            $depth++;
            if ($depth === 1) {
                $current = array();
                $token = '';
                continue;
            }
        }
        if ($character === ',' && $depth === 1) {
            $current[] = legacySqlValue($token);
            $token = '';
            continue;
        }
        if ($character === ')' && $depth === 1) {
            $current[] = legacySqlValue($token);
            $tuples[] = $current;
            $token = '';
            $depth = 0;
            continue;
        }
        if ($depth > 0) {
            $token .= $character;
        }
    }
    return $tuples;
}

function legacySqlValue(string $token): mixed
{
    $token = trim($token);
    if (strcasecmp($token, 'NULL') === 0) {
        return null;
    }
    if (strlen($token) >= 2 && $token[0] === "'" && $token[strlen($token) - 1] === "'") {
        $value = substr($token, 1, -1);
        return strtr($value, array(
            "\\0" => "\0",
            "\\n" => "\n",
            "\\r" => "\r",
            "\\t" => "\t",
            "\\Z" => chr(26),
            "\\'" => "'",
            '\\"' => '"',
            "\\\\" => "\\",
        ));
    }
    return preg_match('/^-?\d+$/', $token) === 1 ? (int)$token : $token;
}

/** @param array<string,mixed> $row */
function legacySourceKey(string $table, array $row): string
{
    $keyFields = array(
        'haz' => 'haz_id',
        'haz_cat' => 'cat_id',
        'map_ud_haz' => 'map_udhaz_id',
        'map_ud_or' => 'map_udor_id',
        'ocr' => 'or_id',
        'ud' => 'ud_id',
    );
    $key = trim((string)($row[$keyFields[$table]] ?? ''));
    if ($key === '') {
        throw new RuntimeException('A legacy row is missing its source key.');
    }
    return $key;
}

/** @param array<string,mixed> $row @return list<array<string,string>> */
function legacyValidateRow(string $table, array $row): array
{
    $requirements = array(
        'haz' => array('haz_id', 'haz_name'),
        'haz_cat' => array('cat_id', 'cat_name'),
        'map_ud_haz' => array('map_udhaz_id', 'map_udhaz_ud', 'map_udhaz_haz'),
        'map_ud_or' => array('map_udor_id', 'map_udor_ud', 'map_udor_or'),
        'ocr' => array('or_id', 'or_title'),
        'ud' => array('ud_id', 'ud_name'),
    );
    $errors = array();
    foreach ($requirements[$table] as $field) {
        if (trim((string)($row[$field] ?? '')) === '') {
            $errors[] = array('code' => 'required_missing', 'field' => $field);
        }
    }
    if ($table === 'haz' && trim(implode('', array_map(
        static fn (string $field): string => (string)($row[$field] ?? ''),
        array('haz_desc', 'haz_def', 'haz_ref', 'haz_com')
    ))) === '') {
        $errors[] = array('code' => 'content_missing', 'field' => 'haz_description_fields');
    }
    if ($table === 'ocr' && trim(implode('', array_map(
        static fn (string $field): string => (string)($row[$field] ?? ''),
        array('or_description', 'or_actions', 'or_mitigation')
    ))) === '') {
        $errors[] = array('code' => 'content_missing', 'field' => 'or_narrative_fields');
    }
    foreach (array('or_issued', 'or_updated', 'ud_issued', 'ud_updated') as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '' && $value !== '0' && $value !== '0000-00-00' && legacyDate($value) === null) {
            $errors[] = array('code' => 'date_invalid', 'field' => $field);
        }
    }
    return $errors;
}

function legacyDate(mixed $value): ?string
{
    $value = trim((string)$value);
    if (preg_match('/^\d{9,11}$/', $value) === 1) {
        $time = (int)$value;
    } else {
        $time = false;
        foreach (array('!d/m/Y', '!d/m/y', '!d-m-Y', '!d-m-y', '!dmY', '!Y-m-d') as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if (
                $date instanceof DateTimeImmutable
                && (int)$date->format('Y') >= 1970
                && (int)$date->format('Y') <= 2100
            ) {
                $time = $date->getTimestamp();
                break;
            }
        }
        if ($time === false && $value !== '' && !str_starts_with($value, '0000-00-00')) {
            $time = strtotime($value);
        }
    }
    if ($time !== false && ($time < 0 || $time > 4102444799)) {
        $time = false;
    }
    return $time === false ? null : gmdate('Y-m-d H:i:s.000', $time);
}

/** @param array<string,mixed> $sections */
function legacySections(array $sections): string
{
    $result = array();
    foreach ($sections as $label => $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $result[] = $label . ":\n" . $value;
        }
    }
    return mb_substr(implode("\n\n", $result), 0, 16000000);
}
