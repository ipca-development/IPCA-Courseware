<?php
declare(strict_types=1);

/**
 * Imports an official ECCAIRS taxonomy archive into immutable, versioned tables.
 *
 * Preflight (no database writes):
 *   php scripts/safety/eccairs_taxonomy_import.php --archive=/restricted/taxonomy.zip --dry-run
 *
 * Import and activate:
 *   php scripts/safety/eccairs_taxonomy_import.php --archive=/restricted/taxonomy.zip \
 *     --organization-id=1 --user-id=1 --activate
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/src/safety/SafetySupport.php';

$options = getopt('', array(
    'archive:',
    'organization-id::',
    'user-id::',
    'activate',
    'dry-run',
    'pretty',
));
$archivePath = trim((string)($options['archive'] ?? ''));
$dryRun = array_key_exists('dry-run', $options);
$activate = array_key_exists('activate', $options);
$organizationId = max(1, (int)($options['organization-id'] ?? 1));
$userId = (int)($options['user-id'] ?? 0);

try {
    if ($archivePath === '' || !is_file($archivePath) || !is_readable($archivePath)) {
        throw new InvalidArgumentException('--archive must name a readable ECCAIRS taxonomy ZIP.');
    }
    if (!$dryRun && $userId < 1) {
        throw new InvalidArgumentException('--user-id is required for an import.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZIP extension is required.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Could not open the taxonomy archive.');
    }
    foreach (array(
        'schema/Schema.xsd',
        'schema/dataTypes.xsd',
        'mappings/Entities.csv',
        'mappings/Attributes.csv',
    ) as $required) {
        if ($zip->locateName($required) === false) {
            throw new RuntimeException('Required taxonomy member is missing: ' . $required);
        }
    }

    $schemaRaw = $zip->getFromName('schema/Schema.xsd');
    if (!is_string($schemaRaw)) {
        throw new RuntimeException('Could not read the taxonomy schema.');
    }
    $schema = mb_convert_encoding($schemaRaw, 'UTF-8', 'UTF-16');
    $schema = preg_replace('/encoding="UTF-16"/i', 'encoding="UTF-8"', $schema, 1) ?? $schema;
    $taxonomyName = eccairsSchemaMetadata($schema, 'Project generated with Taxonomy');
    $taxonomyVersion = eccairsSchemaMetadata($schema, 'Taxonomy Version');
    $schemaVersion = eccairsSchemaMetadata($schema, 'Version');
    $generated = eccairsSchemaMetadata($schema, 'Generation date');
    if ($taxonomyName === '' || $taxonomyVersion === '' || $schemaVersion === '') {
        throw new RuntimeException('Taxonomy identity metadata is incomplete.');
    }
    $attributeCardinality = eccairsSchemaAttributeCardinality($schema);

    $entities = eccairsCsv((string)$zip->getFromName('mappings/Entities.csv'));
    $attributes = eccairsCsv((string)$zip->getFromName('mappings/Attributes.csv'));
    $valueFiles = array();
    $valueCount = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
        if (preg_match('#^mappings/(VL[0-9]+)\.csv$#', $name, $match) !== 1) {
            continue;
        }
        $rows = eccairsCsv((string)$zip->getFromName($name));
        $valueFiles[$match[1]] = $rows;
        $valueCount += count($rows);
    }
    $sourceHash = hash_file('sha256', $archivePath);
    if (!is_string($sourceHash)) {
        throw new RuntimeException('Could not fingerprint the taxonomy archive.');
    }
    $manifest = array(
        'format' => 'eccairs-taxonomy-package',
        'taxonomy_name' => $taxonomyName,
        'taxonomy_version' => $taxonomyVersion,
        'schema_version' => $schemaVersion,
        'generated_at_source' => $generated,
        'source_sha256' => $sourceHash,
        'source_byte_size' => filesize($archivePath),
        'archive_members' => $zip->numFiles,
        'entity_rows' => count($entities),
        'attribute_rows' => count($attributes),
        'required_attribute_rows' => array_sum(array_map(
            static fn (array $attributes): int => count(array_filter(
                $attributes,
                static fn (array $cardinality): bool => $cardinality['min'] > 0
            )),
            $attributeCardinality
        )),
        'value_lists' => count($valueFiles),
        'value_rows' => $valueCount,
    );
    if ($dryRun) {
        $zip->close();
        eccairsOutput(array('ok' => true, 'mode' => 'dry_run') + $manifest, $options);
        exit(0);
    }

    require_once dirname(__DIR__, 2) . '/src/db.php';
    $pdo = cw_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $existing = $pdo->prepare(
        'SELECT id FROM ipca_safety_eccairs_taxonomy_packages
         WHERE organization_id = ? AND source_sha256 = ? LIMIT 1'
    );
    $existing->execute(array($organizationId, $sourceHash));
    if ($existing->fetchColumn()) {
        throw new RuntimeException('This exact taxonomy package has already been imported.');
    }
    $packageUuid = SafetySupport::uuid();
    $generatedUtc = eccairsGeneratedUtc($generated);
    $pdo->prepare(
        'INSERT INTO ipca_safety_eccairs_taxonomy_packages
         (organization_id, package_uuid, taxonomy_name, taxonomy_version, schema_version,
          generated_at_utc, source_sha256, source_byte_size, manifest_json, imported_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(array(
        $organizationId,
        $packageUuid,
        $taxonomyName,
        $taxonomyVersion,
        $schemaVersion,
        $generatedUtc,
        $sourceHash,
        filesize($archivePath),
        SafetySupport::json($manifest),
        $userId,
    ));
    $packageId = (int)$pdo->lastInsertId();

    $entityInsert = $pdo->prepare(
        'INSERT INTO ipca_safety_eccairs_taxonomy_entities
         (organization_id, taxonomy_package_id, entity_code, entity_name, parent_path,
          entity_path, is_link, is_linked, sequence_number)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($entities as $row) {
        $entityInsert->execute(array(
            $organizationId,
            $packageId,
            eccairsRequired($row, 'Entity ID'),
            eccairsRequired($row, 'Entity Synonym'),
            trim((string)($row['Entity Synonym Path'] ?? '')),
            trim((string)($row['Entity Path'] ?? '')),
            (int)($row['IsLink'] ?? 0),
            (int)($row['IsLinked'] ?? 0),
            (int)($row['Entity Sequence'] ?? 0),
        ));
    }

    $attributeInsert = $pdo->prepare(
        'INSERT INTO ipca_safety_eccairs_taxonomy_attributes
         (organization_id, taxonomy_package_id, attribute_code, attribute_name, entity_code,
          entity_name, datatype_code, default_unit_code, value_list_code,
          min_instances, max_instances, sequence_number)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($attributes as $row) {
        $entityName = eccairsRequired($row, 'Parent Entity Synonym');
        $attributeName = eccairsRequired($row, 'Attribute Synonym');
        $cardinality = $attributeCardinality[$entityName][$attributeName] ?? array(
            'min' => 0,
            'max' => 1,
        );
        $attributeInsert->execute(array(
            $organizationId,
            $packageId,
            eccairsRequired($row, 'Attribute ID'),
            $attributeName,
            eccairsRequired($row, 'Parent Entity ID'),
            $entityName,
            eccairsRequired($row, 'ECCAIRS Datatype'),
            eccairsNullable($row['UM default'] ?? null),
            eccairsNullable($row['Valuelist ID'] ?? null),
            $cardinality['min'],
            $cardinality['max'],
            (int)($row['Attribute Sequence'] ?? 0),
        ));
    }

    $valueInsert = $pdo->prepare(
        'INSERT INTO ipca_safety_eccairs_taxonomy_values
         (organization_id, taxonomy_package_id, value_list_code, value_code,
          value_label, parent_value_code, sequence_number, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($valueFiles as $valueList => $rows) {
        foreach ($rows as $sequence => $row) {
            $code = trim((string)($row['Value ID'] ?? $row['Value Synonym'] ?? ''));
            if ($code === '') {
                continue;
            }
            $label = trim((string)($row['Value Description'] ?? $row['Value Synonym'] ?? $code));
            $parent = eccairsNullable(
                $row['Parent Value ID'] ?? $row['Parent ID'] ?? $row['Parent Value'] ?? null
            );
            $valueInsert->execute(array(
                $organizationId,
                $packageId,
                $valueList,
                $code,
                $label !== '' ? $label : $code,
                $parent,
                $sequence,
                SafetySupport::json($row),
            ));
        }
    }

    if ($activate) {
        $pdo->prepare(
            "UPDATE ipca_safety_eccairs_taxonomy_packages
             SET status = 'retired'
             WHERE organization_id = ? AND status = 'active' AND id <> ?"
        )->execute(array($organizationId, $packageId));
        $pdo->prepare(
            "UPDATE ipca_safety_eccairs_taxonomy_packages
             SET status = 'active', activated_by_user_id = ?,
                 activated_at_utc = CURRENT_TIMESTAMP(3)
             WHERE organization_id = ? AND id = ?"
        )->execute(array($userId, $organizationId, $packageId));
    }
    $pdo->commit();
    $zip->close();
    eccairsOutput(array(
        'ok' => true,
        'mode' => 'import',
        'package_uuid' => $packageUuid,
        'status' => $activate ? 'active' : 'imported',
    ) + $manifest, $options);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($zip) && $zip instanceof ZipArchive) {
        $zip->close();
    }
    fwrite(STDERR, SafetySupport::json(array(
        'ok' => false,
        'error' => get_class($e),
        'message' => $e->getMessage(),
    )) . "\n");
    exit(1);
}

function eccairsSchemaMetadata(string $schema, string $source): string
{
    $pattern = '/<xs:documentation\s+source="' . preg_quote($source, '/') . '">([^<]+)<\/xs:documentation>/';
    return preg_match($pattern, $schema, $match) === 1 ? trim((string)$match[1]) : '';
}

/** @return array<string,array<string,array{min:int,max:?int}>> */
function eccairsSchemaAttributeCardinality(string $schema): array
{
    $document = new DOMDocument();
    if (!$document->loadXML($schema, LIBXML_NONET)) {
        throw new RuntimeException('Could not parse the taxonomy schema.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
    $result = array();
    $types = $xpath->query('/xs:schema/xs:complexType[@name]');
    if (!$types instanceof DOMNodeList) {
        return $result;
    }
    foreach ($types as $type) {
        if (!$type instanceof DOMElement) {
            continue;
        }
        $entityName = $type->getAttribute('name');
        $attributes = $xpath->query(
            './xs:all/xs:element[@name="ATTRIBUTES"]/xs:complexType/xs:sequence/xs:element[@name]',
            $type
        );
        if (!$attributes instanceof DOMNodeList) {
            continue;
        }
        foreach ($attributes as $attribute) {
            if (!$attribute instanceof DOMElement) {
                continue;
            }
            $min = $attribute->hasAttribute('minOccurs')
                ? (int)$attribute->getAttribute('minOccurs')
                : 1;
            $maxRaw = $attribute->hasAttribute('maxOccurs')
                ? $attribute->getAttribute('maxOccurs')
                : '1';
            $result[$entityName][$attribute->getAttribute('name')] = array(
                'min' => $min,
                'max' => $maxRaw === 'unbounded' ? null : (int)$maxRaw,
            );
        }
    }
    return $result;
}

/** @return list<array<string,string>> */
function eccairsCsv(string $csv): array
{
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('Could not allocate CSV parser storage.');
    }
    fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
    rewind($stream);
    $headers = fgetcsv($stream, 0, ',', '"', '\\');
    if (!is_array($headers) || $headers === array()) {
        fclose($stream);
        return array();
    }
    $headers = array_map(static fn ($value): string => trim((string)$value), $headers);
    $rows = array();
    while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        if ($values === array(null) || $values === array()) {
            continue;
        }
        $values = array_pad($values, count($headers), '');
        $row = array_combine($headers, array_slice($values, 0, count($headers)));
        if (is_array($row)) {
            $rows[] = array_map(static fn ($value): string => trim((string)$value), $row);
        }
    }
    fclose($stream);
    return $rows;
}

/** @param array<string,string> $row */
function eccairsRequired(array $row, string $key): string
{
    $value = trim((string)($row[$key] ?? ''));
    if ($value === '') {
        throw new RuntimeException('Taxonomy row is missing ' . $key . '.');
    }
    return $value;
}

function eccairsNullable(mixed $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function eccairsNullableInt(mixed $value): ?int
{
    $value = trim((string)$value);
    return $value === '' || preg_match('/^-?\d+$/', $value) !== 1 ? null : (int)$value;
}

function eccairsGeneratedUtc(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', trim($value), new DateTimeZone('UTC'));
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d H:i:s.000') : null;
}

/** @param array<string,mixed> $payload @param array<string,mixed> $options */
function eccairsOutput(array $payload, array $options): void
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if (array_key_exists('pretty', $options)) {
        $flags |= JSON_PRETTY_PRINT;
    }
    fwrite(STDOUT, json_encode($payload, $flags) . "\n");
}
