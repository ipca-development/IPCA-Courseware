<?php
declare(strict_types=1);

/**
 * Sync legacy ipca_compliance MCCF/manual source data into the local
 * ipca_courseware canonical source layer.
 *
 * Default mode is a dry-run: source rows are read, target state is compared,
 * validation gates are evaluated, and an audit run/row-map is written. Canonical
 * data rows are only inserted/updated/deactivated when --apply is provided.
 *
 * Required target env:
 *   CW_DB_HOST, CW_DB_NAME, CW_DB_USER, CW_DB_PASS, optional CW_DB_PORT
 *
 * Required legacy env:
 *   LEGACY_COMPLIANCE_DB_HOST, LEGACY_COMPLIANCE_DB_USER,
 *   LEGACY_COMPLIANCE_DB_PASS, optional LEGACY_COMPLIANCE_DB_NAME,
 *   optional LEGACY_COMPLIANCE_DB_PORT
 *
 * Usage:
 *   php scripts/sync_legacy_compliance_canonical_sources.php
 *   php scripts/sync_legacy_compliance_canonical_sources.php --apply
 *   php scripts/sync_legacy_compliance_canonical_sources.php --allow-count-mismatch
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';

$apply = in_array('--apply', $argv, true);
$allowCountMismatch = in_array('--allow-count-mismatch', $argv, true);
$expected = array(
    'requirements' => 254,
    'excerpts' => 312,
    'links' => 292,
);

$target = cw_db();
$legacy = legacy_db();

$requirements = fetch_all_by_key($legacy, 'SELECT * FROM mccf_requirements ORDER BY mccf_row_id', 'requirement_key');
$excerpts = fetch_all_by_key($legacy, 'SELECT * FROM manual_excerpts ORDER BY manual_code, manual_part, section_ref, excerpt_id', 'excerpt_id');
$links = fetch_all_by_key($legacy, 'SELECT * FROM mccf_excerpt_links ORDER BY link_id', 'link_id');

$observed = array(
    'requirements' => count($requirements),
    'excerpts' => count($excerpts),
    'links' => count($links),
    'requirements_by_manual' => count_by($requirements, 'manual_code'),
    'excerpts_by_manual' => count_by($excerpts, 'manual_code'),
);

$warnings = array();
$errors = array();

foreach ($expected as $key => $count) {
    if ($observed[$key] !== $count) {
        $message = "{$key} count expected {$count}, observed {$observed[$key]}";
        if ($allowCountMismatch) {
            $warnings[] = $message;
        } else {
            $errors[] = $message;
        }
    }
}

$missingRequirements = array();
$missingExcerpts = array();
foreach ($links as $link) {
    $requirementKey = (string)$link['requirement_key'];
    $excerptKey = (string)$link['excerpt_id'];
    if (!isset($requirements[$requirementKey])) {
        $missingRequirements[$requirementKey] = true;
    }
    if (!isset($excerpts[$excerptKey])) {
        $missingExcerpts[$excerptKey] = true;
    }
}
if ($missingRequirements !== array()) {
    $errors[] = 'Link integrity failed: missing requirements: ' . implode(', ', array_keys($missingRequirements));
}
if ($missingExcerpts !== array()) {
    $errors[] = 'Link integrity failed: missing excerpts: ' . implode(', ', array_keys($missingExcerpts));
}

$sourceId = ensure_source($target);
$sourceSets = ensure_source_sets($target, $sourceId, !$apply, inventory_hash($requirements, $excerpts, $links));
$runId = create_sync_run($target, $sourceId, null, !$apply, $expected, $observed, $warnings, $errors, array(
    'apply' => $apply,
    'allow_count_mismatch' => $allowCountMismatch,
));

if ($errors !== array()) {
    finish_sync_run($target, $runId, 'failed', array(), $warnings, $errors, inventory_hash($requirements, $excerpts, $links));
    report($apply, $observed, array(), $warnings, $errors);
    exit(1);
}

$target->beginTransaction();
try {
    $documents = ensure_documents($target, $sourceId, $sourceSets, $apply);
    $actions = array(
        'sources' => array('inserted' => 0, 'updated' => 0, 'unchanged' => 1, 'deactivated' => 0, 'error' => 0),
        'source_sets' => array('inserted' => 0, 'updated' => 0, 'unchanged' => count($sourceSets), 'deactivated' => 0, 'error' => 0),
        'documents' => array('inserted' => 0, 'updated' => 0, 'unchanged' => count($documents), 'deactivated' => 0, 'error' => 0),
        'requirements' => sync_requirements($target, $runId, $requirements, $sourceSets, $documents, $apply),
        'excerpts' => sync_excerpts($target, $runId, $excerpts, $sourceSets, $documents, $apply),
    );

    $localRequirements = load_target_map($target, 'ipca_canonical_requirements', 'requirement_key');
    $localExcerpts = load_target_map($target, 'ipca_canonical_excerpts', 'excerpt_key');
    $actions['links'] = sync_links($target, $runId, $links, $localRequirements, $localExcerpts, $sourceSets, $documents, $apply);

    $actions['requirements'] = array_merge_counts(
        $actions['requirements'],
        deactivate_missing($target, $runId, 'ipca_canonical_requirements', 'requirement_key', array_keys($requirements), $apply)
    );
    $actions['excerpts'] = array_merge_counts(
        $actions['excerpts'],
        deactivate_missing($target, $runId, 'ipca_canonical_excerpts', 'excerpt_key', array_keys($excerpts), $apply)
    );
    $actions['links'] = array_merge_counts(
        $actions['links'],
        deactivate_missing($target, $runId, 'ipca_canonical_requirement_excerpt_links', 'source_link_id', array_keys($links), $apply)
    );

    finish_sync_run($target, $runId, $apply ? 'success' : 'dry_run', $actions, $warnings, array(), inventory_hash($requirements, $excerpts, $links));
    $target->commit();
    report($apply, $observed, $actions, $warnings, array());
} catch (Throwable $e) {
    $target->rollBack();
    finish_sync_run($target, $runId, 'failed', array(), $warnings, array($e->getMessage()), inventory_hash($requirements, $excerpts, $links));
    fwrite(STDERR, "Sync failed: {$e->getMessage()}\n");
    exit(1);
}

function legacy_db(): PDO
{
    $host = env_required('LEGACY_COMPLIANCE_DB_HOST');
    $db = getenv('LEGACY_COMPLIANCE_DB_NAME') ?: 'ipca_compliance';
    $user = env_required('LEGACY_COMPLIANCE_DB_USER');
    $pass = env_required('LEGACY_COMPLIANCE_DB_PASS');
    $port = getenv('LEGACY_COMPLIANCE_DB_PORT') ?: '25060';

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
}

function env_required(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing env var {$key}");
    }
    return $value;
}

/**
 * @return array<string,array<string,mixed>>
 */
function fetch_all_by_key(PDO $pdo, string $sql, string $key): array
{
    $rows = array();
    foreach ($pdo->query($sql) as $row) {
        $rows[(string)$row[$key]] = $row;
    }
    return $rows;
}

/**
 * @param array<string,array<string,mixed>> $rows
 * @return array<string,int>
 */
function count_by(array $rows, string $field): array
{
    $counts = array();
    foreach ($rows as $row) {
        $key = (string)($row[$field] ?? '');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

function ensure_source(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        INSERT INTO ipca_canonical_sources
            (source_key, source_type, display_name, authority, origin_database, origin_table_prefix, status, config_json)
        VALUES
            ('legacy_ipca_compliance', 'legacy_db', 'Legacy IPCA Compliance Database', 'BCAA', 'ipca_compliance', 'mccf/manual', 'active',
             JSON_OBJECT('tables', JSON_ARRAY('mccf_requirements', 'manual_excerpts', 'mccf_excerpt_links')))
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute();

    $id = $pdo->query("SELECT id FROM ipca_canonical_sources WHERE source_key = 'legacy_ipca_compliance'")->fetchColumn();
    if (!$id) {
        throw new RuntimeException('Could not create or load canonical source row.');
    }
    return (int)$id;
}

/**
 * @return array<string,int>
 */
function ensure_source_sets(PDO $pdo, int $sourceId, bool $dryRun, string $inventoryHash): array
{
    $sets = array(
        'MCCF:OM:REV_6_0' => array('mccf', 'BCAA', 'MCCF — Operations Manual REV 6.0', 'OM REV 6.0'),
        'MCCF:OMM:REV_4_0' => array('mccf', 'BCAA', 'MCCF — Organization Management Manual REV 4.0', 'OMM REV 4.0'),
        'MANUAL:OM:6_0' => array('manual', 'BCAA', 'Operations Manual 6.0', '6.0'),
        'MANUAL:OMM:4_0' => array('manual', 'BCAA', 'Organization Management Manual 4.0', '4.0'),
    );

    if (!$dryRun) {
        $stmt = $pdo->prepare("
            INSERT INTO ipca_canonical_source_sets
                (source_id, source_set_key, source_family, authority, title, revision_label, status, source_hash, last_synced_at)
            VALUES
                (:source_id, :source_set_key, :source_family, :authority, :title, :revision_label, 'active', :source_hash, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                status = 'active',
                source_hash = VALUES(source_hash),
                last_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($sets as $key => $set) {
            $stmt->execute(array(
                ':source_id' => $sourceId,
                ':source_set_key' => $key,
                ':source_family' => $set[0],
                ':authority' => $set[1],
                ':title' => $set[2],
                ':revision_label' => $set[3],
                ':source_hash' => $inventoryHash,
            ));
        }
    }

    $existing = load_source_sets($pdo);
    foreach ($sets as $key => $set) {
        if (!isset($existing[$key]) && $dryRun) {
            $existing[$key] = 0;
        }
    }
    return $existing;
}

/**
 * @return array<string,int>
 */
function load_source_sets(PDO $pdo): array
{
    $rows = array();
    foreach ($pdo->query('SELECT id, source_set_key FROM ipca_canonical_source_sets') as $row) {
        $rows[(string)$row['source_set_key']] = (int)$row['id'];
    }
    return $rows;
}

function create_sync_run(PDO $pdo, int $sourceId, ?int $sourceSetId, bool $dryRun, array $expected, array $observed, array $warnings, array $errors, array $options): int
{
    $runKey = 'legacy-ipca-compliance-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $stmt = $pdo->prepare("
        INSERT INTO ipca_canonical_sync_runs
            (source_id, source_set_id, run_key, run_type, status, dry_run, expected_counts_json, observed_counts_json, warnings_json, errors_json, options_json)
        VALUES
            (:source_id, :source_set_id, :run_key, 'legacy_sync', 'running', :dry_run, :expected, :observed, :warnings, :errors, :options)
    ");
    $stmt->execute(array(
        ':source_id' => $sourceId,
        ':source_set_id' => $sourceSetId,
        ':run_key' => $runKey,
        ':dry_run' => $dryRun ? 1 : 0,
        ':expected' => json_encode($expected, JSON_THROW_ON_ERROR),
        ':observed' => json_encode($observed, JSON_THROW_ON_ERROR),
        ':warnings' => json_encode($warnings, JSON_THROW_ON_ERROR),
        ':errors' => json_encode($errors, JSON_THROW_ON_ERROR),
        ':options' => json_encode($options, JSON_THROW_ON_ERROR),
    ));
    return (int)$pdo->lastInsertId();
}

function finish_sync_run(PDO $pdo, int $runId, string $status, array $actions, array $warnings, array $errors, string $inventoryHash): void
{
    $stmt = $pdo->prepare("
        UPDATE ipca_canonical_sync_runs
        SET status = :status,
            completed_at = CURRENT_TIMESTAMP,
            action_counts_json = :actions,
            warnings_json = :warnings,
            errors_json = :errors,
            source_inventory_hash = :inventory_hash,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute(array(
        ':status' => $status,
        ':actions' => json_encode($actions, JSON_THROW_ON_ERROR),
        ':warnings' => json_encode($warnings, JSON_THROW_ON_ERROR),
        ':errors' => json_encode($errors, JSON_THROW_ON_ERROR),
        ':inventory_hash' => $inventoryHash,
        ':id' => $runId,
    ));
}

/**
 * @param array<string,int> $sourceSets
 * @return array<string,array{id:int,source_set_id:int}>
 */
function ensure_documents(PDO $pdo, int $sourceId, array $sourceSets, bool $apply): array
{
    $docs = array(
        'MCCF:OM:OM REV 6.0' => array('MCCF:OM:REV_6_0', 'mccf', 'BCAA', 'OM', 'OM REV 6.0', 'MCCF — Operations Manual REV 6.0', 'mccf_requirements'),
        'MCCF:OMM:OMM REV 4.0' => array('MCCF:OMM:REV_4_0', 'mccf', 'BCAA', 'OMM', 'OMM REV 4.0', 'MCCF — Organization Management Manual REV 4.0', 'mccf_requirements'),
        'MANUAL:OM:6.0' => array('MANUAL:OM:6_0', 'manual', 'BCAA', 'OM', '6.0', 'Operations Manual 6.0', 'manual_excerpts'),
        'MANUAL:OMM:4.0' => array('MANUAL:OMM:4_0', 'manual', 'BCAA', 'OMM', '4.0', 'Organization Management Manual 4.0', 'manual_excerpts'),
    );

    if ($apply) {
        $stmt = $pdo->prepare("
            INSERT INTO ipca_canonical_documents
                (source_id, source_set_id, document_key, document_type, authority, manual_code, revision_code, title, status, source_database, source_table)
            VALUES
                (:source_id, :source_set_id, :document_key, :document_type, :authority, :manual_code, :revision_code, :title, 'active',
                 'ipca_compliance', :source_table)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                status = VALUES(status),
                last_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($docs as $key => $doc) {
            $stmt->execute(array(
                ':source_id' => $sourceId,
                ':source_set_id' => $sourceSets[$doc[0]],
                ':document_key' => $key,
                ':document_type' => $doc[1],
                ':authority' => $doc[2],
                ':manual_code' => $doc[3],
                ':revision_code' => $doc[4],
                ':title' => $doc[5],
                ':source_table' => $doc[6],
            ));
        }
    }

    $existing = load_documents($pdo);
    foreach ($docs as $key => $doc) {
        if (!isset($existing[$key]) && !$apply) {
            $existing[$key] = array('id' => 0, 'source_set_id' => $sourceSets[$doc[0]] ?? 0);
        }
    }
    return $existing;
}

/**
 * @return array<string,array{id:int,source_set_id:int}>
 */
function load_documents(PDO $pdo): array
{
    $rows = array();
    foreach ($pdo->query('SELECT id, source_set_id, document_key FROM ipca_canonical_documents') as $row) {
        $rows[(string)$row['document_key']] = array(
            'id' => (int)$row['id'],
            'source_set_id' => (int)$row['source_set_id'],
        );
    }
    return $rows;
}

/**
 * @param array<string,array<string,mixed>> $requirements
 * @param array<string,int> $sourceSets
 * @param array<string,array{id:int,source_set_id:int}> $documents
 * @return array<string,int>
 */
function sync_requirements(PDO $pdo, int $runId, array $requirements, array $sourceSets, array $documents, bool $apply): array
{
    $counts = new_counts();
    $existing = load_target_map($pdo, 'ipca_canonical_requirements', 'requirement_key');
    $upsert = $pdo->prepare("
        INSERT INTO ipca_canonical_requirements
            (source_set_id, source_document_id, requirement_key, source_mccf_row_id, mccf_id, authority, manual_code, manual_type,
             manual_part, item_no, sub_item_no, subject, requirement_text, regulation_ref, manual_section_ref,
             legacy_excerpt_id, applicable, remarks, finding_ref, source_hash, source_status, last_synced_at)
        VALUES
            (:source_set_id, :source_document_id, :requirement_key, :source_mccf_row_id, :mccf_id, :authority, :manual_code, :manual_type,
             :manual_part, :item_no, :sub_item_no, :subject, :requirement_text, :regulation_ref, :manual_section_ref,
             :legacy_excerpt_id, :applicable, :remarks, :finding_ref, :source_hash, 'active', CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            source_set_id = VALUES(source_set_id),
            source_document_id = VALUES(source_document_id),
            source_mccf_row_id = VALUES(source_mccf_row_id),
            mccf_id = VALUES(mccf_id),
            authority = VALUES(authority),
            manual_code = VALUES(manual_code),
            manual_type = VALUES(manual_type),
            manual_part = VALUES(manual_part),
            item_no = VALUES(item_no),
            sub_item_no = VALUES(sub_item_no),
            subject = VALUES(subject),
            requirement_text = VALUES(requirement_text),
            regulation_ref = VALUES(regulation_ref),
            manual_section_ref = VALUES(manual_section_ref),
            legacy_excerpt_id = VALUES(legacy_excerpt_id),
            applicable = VALUES(applicable),
            remarks = VALUES(remarks),
            finding_ref = VALUES(finding_ref),
            source_hash = VALUES(source_hash),
            source_status = 'active',
            last_synced_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($requirements as $key => $row) {
        $hash = row_hash($row, array('mccf_row_id', 'manual_code', 'requirement_key', 'mccf_id', 'authority', 'regulation_ref', 'manual_type', 'manual_part', 'item_no', 'sub_item_no', 'subject', 'requirement_text', 'manual_section_ref', 'excerpt_id', 'applicable', 'remarks', 'finding_ref'));
        $action = classify_action($existing[$key] ?? null, $hash);
        $counts[$action]++;
        $docKey = ((string)$row['manual_code'] === 'OMM') ? 'MCCF:OMM:OMM REV 4.0' : 'MCCF:OM:OM REV 6.0';
        $sourceSetKey = ((string)$row['manual_code'] === 'OMM') ? 'MCCF:OMM:REV_4_0' : 'MCCF:OM:REV_6_0';

        if ($apply) {
            $upsert->execute(array(
                ':source_set_id' => $sourceSets[$sourceSetKey],
                ':source_document_id' => $documents[$docKey]['id'],
                ':requirement_key' => $key,
                ':source_mccf_row_id' => $row['mccf_row_id'],
                ':mccf_id' => $row['mccf_id'],
                ':authority' => $row['authority'],
                ':manual_code' => $row['manual_code'],
                ':manual_type' => $row['manual_type'],
                ':manual_part' => $row['manual_part'],
                ':item_no' => $row['item_no'],
                ':sub_item_no' => $row['sub_item_no'],
                ':subject' => $row['subject'],
                ':requirement_text' => $row['requirement_text'],
                ':regulation_ref' => $row['regulation_ref'],
                ':manual_section_ref' => $row['manual_section_ref'],
                ':legacy_excerpt_id' => $row['excerpt_id'],
                ':applicable' => $row['applicable'],
                ':remarks' => $row['remarks'],
                ':finding_ref' => $row['finding_ref'],
                ':source_hash' => $hash,
            ));
        }
        write_row_map($pdo, $runId, $sourceSets[$sourceSetKey] ?: null, 'ipca_canonical_requirements', $apply ? local_id($pdo, 'ipca_canonical_requirements', 'requirement_key', $key) : ($existing[$key]['id'] ?? null), 'mccf_requirements', (string)$row['mccf_row_id'], $key, $hash, $action);
    }
    return $counts;
}

/**
 * @param array<string,array<string,mixed>> $excerpts
 * @param array<string,int> $sourceSets
 * @param array<string,array{id:int,source_set_id:int}> $documents
 * @return array<string,int>
 */
function sync_excerpts(PDO $pdo, int $runId, array $excerpts, array $sourceSets, array $documents, bool $apply): array
{
    $counts = new_counts();
    $existing = load_target_map($pdo, 'ipca_canonical_excerpts', 'excerpt_key');
    $upsert = $pdo->prepare("
        INSERT INTO ipca_canonical_excerpts
            (source_set_id, source_document_id, excerpt_key, excerpt_key_norm, manual_code, manual_rev, manual_part, section_ref,
             title, body_text, source_file, source_sha256, content_hash, source_table, source_hash, source_status, last_synced_at)
        VALUES
            (:source_set_id, :source_document_id, :excerpt_key, :excerpt_key_norm, :manual_code, :manual_rev, :manual_part, :section_ref,
             :title, :body_text, :source_file, :source_sha256, :content_hash, 'manual_excerpts', :source_hash, 'active', CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            source_set_id = VALUES(source_set_id),
            source_document_id = VALUES(source_document_id),
            excerpt_key_norm = VALUES(excerpt_key_norm),
            manual_code = VALUES(manual_code),
            manual_rev = VALUES(manual_rev),
            manual_part = VALUES(manual_part),
            section_ref = VALUES(section_ref),
            title = VALUES(title),
            body_text = VALUES(body_text),
            source_file = VALUES(source_file),
            source_sha256 = VALUES(source_sha256),
            content_hash = VALUES(content_hash),
            source_hash = VALUES(source_hash),
            source_status = 'active',
            last_synced_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($excerpts as $key => $row) {
        $hash = row_hash($row, array('excerpt_id', 'manual_code', 'manual_rev', 'manual_part', 'section_ref', 'title', 'text', 'source_file', 'sha256', 'excerpt_id_norm'));
        $action = classify_action($existing[$key] ?? null, $hash);
        $counts[$action]++;
        $docKey = ((string)$row['manual_code'] === 'OMM') ? 'MANUAL:OMM:4.0' : 'MANUAL:OM:6.0';
        $sourceSetKey = ((string)$row['manual_code'] === 'OMM') ? 'MANUAL:OMM:4_0' : 'MANUAL:OM:6_0';

        if ($apply) {
            $upsert->execute(array(
                ':source_set_id' => $sourceSets[$sourceSetKey],
                ':source_document_id' => $documents[$docKey]['id'],
                ':excerpt_key' => $key,
                ':excerpt_key_norm' => $row['excerpt_id_norm'],
                ':manual_code' => $row['manual_code'],
                ':manual_rev' => $row['manual_rev'],
                ':manual_part' => $row['manual_part'],
                ':section_ref' => $row['section_ref'],
                ':title' => $row['title'],
                ':body_text' => $row['text'],
                ':source_file' => $row['source_file'],
                ':source_sha256' => $row['sha256'],
                ':content_hash' => hash('sha256', normalize_text((string)$row['text'])),
                ':source_hash' => $hash,
            ));
        }
        write_row_map($pdo, $runId, $sourceSets[$sourceSetKey] ?: null, 'ipca_canonical_excerpts', $apply ? local_id($pdo, 'ipca_canonical_excerpts', 'excerpt_key', $key) : ($existing[$key]['id'] ?? null), 'manual_excerpts', $key, $key, $hash, $action);
    }
    return $counts;
}

/**
 * @param array<string,array<string,mixed>> $links
 * @param array<string,array{id:int,source_hash:string}> $requirements
 * @param array<string,array{id:int,source_hash:string}> $excerpts
 * @param array<string,int> $sourceSets
 * @param array<string,array{id:int,source_set_id:int}> $documents
 * @return array<string,int>
 */
function sync_links(PDO $pdo, int $runId, array $links, array $requirements, array $excerpts, array $sourceSets, array $documents, bool $apply): array
{
    $counts = new_counts();
    $existing = load_target_map($pdo, 'ipca_canonical_requirement_excerpt_links', 'source_link_id');
    $upsert = $pdo->prepare("
        INSERT INTO ipca_canonical_requirement_excerpt_links
            (source_set_id, source_document_id, requirement_id, excerpt_id, requirement_key, excerpt_key, link_type, confidence,
             notes, verified_by_source, verified_on, source_link_id, source_hash, source_status, last_synced_at)
        VALUES
            (:source_set_id, :source_document_id, :requirement_id, :excerpt_id, :requirement_key, :excerpt_key, :link_type, :confidence,
             :notes, :verified_by_source, :verified_on, :source_link_id, :source_hash, 'active', CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            source_set_id = VALUES(source_set_id),
            source_document_id = VALUES(source_document_id),
            requirement_id = VALUES(requirement_id),
            excerpt_id = VALUES(excerpt_id),
            requirement_key = VALUES(requirement_key),
            excerpt_key = VALUES(excerpt_key),
            link_type = VALUES(link_type),
            confidence = VALUES(confidence),
            notes = VALUES(notes),
            verified_by_source = VALUES(verified_by_source),
            verified_on = VALUES(verified_on),
            source_hash = VALUES(source_hash),
            source_status = 'active',
            last_synced_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($links as $linkId => $row) {
        $hash = row_hash($row, array('link_id', 'requirement_key', 'excerpt_id', 'link_type', 'confidence', 'notes', 'verified_by', 'verified_on'));
        $action = classify_action($existing[$linkId] ?? null, $hash);
        $counts[$action]++;
        $manualCode = str_starts_with((string)$row['requirement_key'], 'OMM') ? 'OMM' : 'OM';
        $docKey = $manualCode === 'OMM' ? 'MCCF:OMM:OMM REV 4.0' : 'MCCF:OM:OM REV 6.0';
        $sourceSetKey = $manualCode === 'OMM' ? 'MCCF:OMM:REV_4_0' : 'MCCF:OM:REV_6_0';

        if ($apply) {
            $upsert->execute(array(
                ':source_set_id' => $sourceSets[$sourceSetKey],
                ':source_document_id' => $documents[$docKey]['id'],
                ':requirement_id' => $requirements[(string)$row['requirement_key']]['id'],
                ':excerpt_id' => $excerpts[(string)$row['excerpt_id']]['id'],
                ':requirement_key' => $row['requirement_key'],
                ':excerpt_key' => $row['excerpt_id'],
                ':link_type' => $row['link_type'],
                ':confidence' => $row['confidence'],
                ':notes' => $row['notes'],
                ':verified_by_source' => $row['verified_by'],
                ':verified_on' => $row['verified_on'],
                ':source_link_id' => $row['link_id'],
                ':source_hash' => $hash,
            ));
        }
        write_row_map($pdo, $runId, $sourceSets[$sourceSetKey] ?: null, 'ipca_canonical_requirement_excerpt_links', $apply ? local_id($pdo, 'ipca_canonical_requirement_excerpt_links', 'source_link_id', (string)$linkId) : ($existing[$linkId]['id'] ?? null), 'mccf_excerpt_links', (string)$row['link_id'], (string)$linkId, $hash, $action);
    }
    return $counts;
}

/**
 * @return array<string,array{id:int,source_set_id:int,source_hash:string}>
 */
function load_target_map(PDO $pdo, string $table, string $keyColumn): array
{
    $allowed = array('ipca_canonical_requirements', 'ipca_canonical_excerpts', 'ipca_canonical_requirement_excerpt_links');
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid target table.');
    }
    $keyColumns = array('requirement_key', 'excerpt_key', 'source_link_id');
    if (!in_array($keyColumn, $keyColumns, true)) {
        throw new InvalidArgumentException('Invalid key column.');
    }

    $rows = array();
    foreach ($pdo->query("SELECT id, source_set_id, {$keyColumn} AS stable_key, source_hash FROM {$table}") as $row) {
        $rows[(string)$row['stable_key']] = array(
            'id' => (int)$row['id'],
            'source_set_id' => (int)$row['source_set_id'],
            'source_hash' => (string)$row['source_hash'],
        );
    }
    return $rows;
}

function classify_action(?array $existing, string $hash): string
{
    if ($existing === null) {
        return 'inserted';
    }
    return $existing['source_hash'] === $hash ? 'unchanged' : 'updated';
}

function row_hash(array $row, array $fields): string
{
    $parts = array();
    foreach ($fields as $field) {
        $parts[$field] = normalize_text((string)($row[$field] ?? ''));
    }
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function normalize_text(string $value): string
{
    return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
}

function inventory_hash(array $requirements, array $excerpts, array $links): string
{
    return hash('sha256', json_encode(array(
        'requirements' => array_map(static fn($row) => row_hash($row, array_keys($row)), $requirements),
        'excerpts' => array_map(static fn($row) => row_hash($row, array_keys($row)), $excerpts),
        'links' => array_map(static fn($row) => row_hash($row, array_keys($row)), $links),
    ), JSON_THROW_ON_ERROR));
}

function write_row_map(PDO $pdo, int $runId, ?int $sourceSetId, string $localTable, ?int $localId, string $sourceTable, string $sourcePk, string $sourceStableKey, string $sourceHash, string $action): void
{
    $stmt = $pdo->prepare("
        INSERT INTO ipca_canonical_sync_row_map
            (sync_run_id, source_set_id, local_table, local_id, source_database, source_table, source_pk, source_stable_key, source_hash, action)
        VALUES
            (:sync_run_id, :source_set_id, :local_table, :local_id, 'ipca_compliance', :source_table, :source_pk, :source_stable_key, :source_hash, :action)
    ");
    $stmt->execute(array(
        ':sync_run_id' => $runId,
        ':source_set_id' => $sourceSetId,
        ':local_table' => $localTable,
        ':local_id' => $localId,
        ':source_table' => $sourceTable,
        ':source_pk' => $sourcePk,
        ':source_stable_key' => $sourceStableKey,
        ':source_hash' => $sourceHash,
        ':action' => $action,
    ));
}

function local_id(PDO $pdo, string $table, string $keyColumn, string $key): int
{
    $allowed = array('ipca_canonical_requirements', 'ipca_canonical_excerpts', 'ipca_canonical_requirement_excerpt_links');
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid target table.');
    }
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$keyColumn} = :key LIMIT 1");
    $stmt->execute(array(':key' => $key));
    $id = $stmt->fetchColumn();
    if (!$id) {
        throw new RuntimeException("Could not resolve local id for {$table}.{$keyColumn}={$key}");
    }
    return (int)$id;
}

/**
 * @return array<string,int>
 */
function deactivate_missing(PDO $pdo, int $runId, string $table, string $keyColumn, array $currentKeys, bool $apply): array
{
    $counts = new_counts();
    $existing = load_target_map($pdo, $table, $keyColumn);
    $current = array_fill_keys($currentKeys, true);
    $stmt = $pdo->prepare("UPDATE {$table} SET source_status = 'missing_from_source', updated_at = CURRENT_TIMESTAMP WHERE {$keyColumn} = :key");

    foreach ($existing as $key => $row) {
        if (isset($current[$key])) {
            continue;
        }
        if ($apply) {
            $stmt->execute(array(':key' => $key));
        }
        $counts['deactivated']++;
        write_row_map($pdo, $runId, $row['source_set_id'] ?? null, $table, $row['id'], table_source_name($table), $key, $key, $row['source_hash'], 'deactivated');
    }
    return $counts;
}

function table_source_name(string $table): string
{
    return match ($table) {
        'ipca_canonical_requirements' => 'mccf_requirements',
        'ipca_canonical_excerpts' => 'manual_excerpts',
        default => 'mccf_excerpt_links',
    };
}

/**
 * @return array<string,int>
 */
function new_counts(): array
{
    return array('inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'error' => 0);
}

function array_merge_counts(array $a, array $b): array
{
    foreach ($b as $key => $value) {
        $a[$key] = ($a[$key] ?? 0) + $value;
    }
    return $a;
}

function report(bool $apply, array $observed, array $actions, array $warnings, array $errors): void
{
    fwrite(STDOUT, ($apply ? "APPLY" : "DRY RUN") . " legacy compliance canonical source sync\n");
    fwrite(STDOUT, "Observed counts: " . json_encode($observed, JSON_UNESCAPED_SLASHES) . "\n");
    if ($actions !== array()) {
        fwrite(STDOUT, "Actions: " . json_encode($actions, JSON_UNESCAPED_SLASHES) . "\n");
    }
    foreach ($warnings as $warning) {
        fwrite(STDOUT, "WARNING: {$warning}\n");
    }
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
}
