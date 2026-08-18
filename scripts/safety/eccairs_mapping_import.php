<?php
declare(strict_types=1);

/**
 * Imports a human-approved IPCA-to-ECCAIRS taxonomy mapping manifest.
 * Existing mapping versions are immutable; corrections require a new version.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/safety/SafetySupport.php';

$options = getopt('', array('manifest:'));
$manifestPath = trim((string)($options['manifest'] ?? ''));

try {
    if ($manifestPath === '' || !is_file($manifestPath) || !is_readable($manifestPath)) {
        throw new InvalidArgumentException('--manifest must name a readable approved JSON file.');
    }
    $raw = file_get_contents($manifestPath);
    $manifest = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($manifest)) {
        throw new InvalidArgumentException('The mapping manifest must be a JSON object.');
    }
    $organizationId = max(1, (int)($manifest['organization_id'] ?? 0));
    $mappingVersion = smsMappingToken((string)($manifest['mapping_version'] ?? ''), 'mapping_version');
    $taxonomyVersion = smsMappingToken((string)($manifest['taxonomy_version'] ?? ''), 'taxonomy_version');
    $approvedBy = (int)($manifest['approved_by_user_id'] ?? 0);
    $mappings = $manifest['mappings'] ?? null;
    if ($approvedBy < 1 || !is_array($mappings) || $mappings === array()) {
        throw new InvalidArgumentException('approved_by_user_id and at least one mapping are required.');
    }
    $allowedSources = array(
        'occurrence.type',
        'occurrence.occurred_at_utc',
        'report.uuid',
        'report.number',
        'report.category',
        'report.title',
        'report.narrative',
        'report.event_at_utc',
        'report.location',
        'report.aircraft_registration',
        'report.immediate_action',
        'assessment.framework',
        'assessment.rationale',
        'assessment.assessed_at_utc',
    );
    $normalized = array();
    foreach ($mappings as $index => $mapping) {
        if (!is_array($mapping)) {
            throw new InvalidArgumentException('Mapping #' . ($index + 1) . ' must be an object.');
        }
        $source = trim((string)($mapping['source_field'] ?? ''));
        if (!in_array($source, $allowedSources, true)) {
            throw new InvalidArgumentException('Mapping #' . ($index + 1) . ' uses a prohibited source field.');
        }
        $entity = smsMappingToken((string)($mapping['target_entity_code'] ?? ''), 'target_entity_code');
        $entityPath = trim((string)($mapping['target_entity_path'] ?? 'Occurrence'));
        if (preg_match('#^Occurrence(?:/[A-Za-z_][A-Za-z0-9_.-]*)*$#', $entityPath) !== 1) {
            throw new InvalidArgumentException('Mapping #' . ($index + 1) . ' has an invalid target_entity_path.');
        }
        $attribute = smsMappingToken((string)($mapping['target_attribute_code'] ?? ''), 'target_attribute_code');
        $transform = (string)($mapping['transform_code'] ?? 'identity');
        if (!in_array($transform, array('identity', 'text', 'upper', 'date', 'datetime_utc', 'value_map'), true)) {
            throw new InvalidArgumentException('Mapping #' . ($index + 1) . ' has an unsupported transform.');
        }
        $required = (string)($mapping['required_state'] ?? 'optional');
        if (!in_array($required, array('required', 'optional'), true)) {
            throw new InvalidArgumentException('Mapping #' . ($index + 1) . ' has an invalid required_state.');
        }
        $valueMap = $mapping['value_map'] ?? null;
        if ($transform === 'value_map' && (!is_array($valueMap) || $valueMap === array())) {
            throw new InvalidArgumentException('Mapping #' . ($index + 1) . ' requires a non-empty value_map.');
        }
        $normalized[] = array(
            $organizationId,
            $mappingVersion,
            $taxonomyVersion,
            $source,
            $entity,
            $entityPath,
            hash('sha256', $entityPath),
            $attribute,
            $transform,
            $valueMap === null ? null : SafetySupport::json($valueMap),
            $required,
            $approvedBy,
        );
    }

    $pdo = cw_db();
    $exists = $pdo->prepare(
        'SELECT COUNT(*) FROM ipca_safety_eccairs_mappings
         WHERE organization_id = ? AND mapping_version = ?'
    );
    $exists->execute(array($organizationId, $mappingVersion));
    if ((int)$exists->fetchColumn() > 0) {
        throw new RuntimeException('This mapping version already exists; create a new immutable version.');
    }
    $taxonomyCheck = $pdo->prepare(
        "SELECT 1
         FROM ipca_safety_eccairs_taxonomy_packages p
         JOIN ipca_safety_eccairs_taxonomy_attributes a
           ON a.organization_id = p.organization_id AND a.taxonomy_package_id = p.id
         JOIN ipca_safety_eccairs_taxonomy_entities e
           ON e.organization_id = p.organization_id AND e.taxonomy_package_id = p.id
          AND e.entity_code = a.entity_code
         WHERE p.organization_id = ? AND p.taxonomy_version = ? AND p.status = 'active'
           AND a.entity_code = ? AND a.attribute_code = ?
           AND (e.parent_path = ? OR (e.entity_code = '24' AND e.entity_name = 'Occurrence'))
         LIMIT 1"
    );
    foreach ($normalized as $index => $row) {
        $taxonomyCheck->execute(array($organizationId, $taxonomyVersion, $row[4], $row[7], $row[5]));
        if (!$taxonomyCheck->fetchColumn()) {
            throw new RuntimeException(
                'Mapping #' . ($index + 1) . ' does not match the active imported taxonomy package.'
            );
        }
    }
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO ipca_safety_eccairs_mappings
         (organization_id, mapping_version, taxonomy_version, source_field,
          target_entity_code, target_entity_path, target_entity_path_sha256,
          target_attribute_code, transform_code, value_map_json,
          required_state, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($normalized as $row) {
        $insert->execute($row);
    }
    $pdo->commit();
    fwrite(STDOUT, SafetySupport::json(array(
        'ok' => true,
        'mapping_version' => $mappingVersion,
        'taxonomy_version' => $taxonomyVersion,
        'mapping_count' => count($normalized),
        'manifest_sha256' => hash('sha256', (string)$raw),
    )) . "\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, SafetySupport::json(array(
        'ok' => false,
        'error' => get_class($e),
        'message' => $e->getMessage(),
    )) . "\n");
    exit(1);
}

function smsMappingToken(string $value, string $field): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value) !== 1) {
        throw new InvalidArgumentException($field . ' is invalid.');
    }
    return $value;
}
