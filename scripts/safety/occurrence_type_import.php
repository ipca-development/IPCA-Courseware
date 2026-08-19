<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if (($argv[1] ?? '') === '' || ($argv[2] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php scripts/safety/occurrence_type_import.php manifest.json --apply\n");
    exit(2);
}
$raw = file_get_contents((string)$argv[1]);
$manifest = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($manifest) || !is_array($manifest['types'] ?? null)) {
    throw new RuntimeException('Manifest must contain a types array.');
}
$organizationId = (int)($manifest['organization_id'] ?? 1);
$nodeType = trim((string)($manifest['node_type'] ?? 'reporter_occurrence_type'));
if ($organizationId <= 0 || $nodeType === '' || strlen($nodeType) > 48) {
    throw new RuntimeException('Invalid organization_id or node_type.');
}
$types = array();
foreach ($manifest['types'] as $index => $type) {
    if (!is_array($type)) {
        throw new RuntimeException("types[{$index}] must be an object.");
    }
    $code = trim((string)($type['code'] ?? ''));
    $label = trim((string)($type['label'] ?? ''));
    if ($code === '' || strlen($code) > 64 || $label === '' || strlen($label) > 180) {
        throw new RuntimeException("types[{$index}] has an invalid code or label.");
    }
    if (isset($types[$code])) {
        throw new RuntimeException("Duplicate occurrence type code: {$code}");
    }
    $types[$code] = array(
        'code' => $code,
        'label' => $label,
        'description' => trim((string)($type['description'] ?? '')) ?: null,
        'parent_code' => trim((string)($type['parent_code'] ?? '')) ?: null,
        'active' => array_key_exists('active', $type) ? (bool)$type['active'] : true,
        'sort_order' => (int)($type['sort_order'] ?? 0),
    );
}
if ($types === array()) {
    throw new RuntimeException('Manifest types array must not be empty.');
}
foreach ($types as $type) {
    if ($type['parent_code'] !== null && !isset($types[$type['parent_code']])) {
        throw new RuntimeException(
            "Parent {$type['parent_code']} for {$type['code']} is not present in the manifest."
        );
    }
}

$pdo = cw_db();
$pdo->beginTransaction();
try {
    $upsert = $pdo->prepare(
        'INSERT INTO ipca_safety_taxonomy_nodes
         (organization_id, taxonomy_code, label, description, node_type, active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description),
           node_type = VALUES(node_type), active = VALUES(active), sort_order = VALUES(sort_order)'
    );
    foreach ($types as $type) {
        $upsert->execute(array(
            $organizationId,
            $type['code'],
            $type['label'],
            $type['description'],
            $nodeType,
            $type['active'] ? 1 : 0,
            $type['sort_order'],
        ));
    }
    $lookup = $pdo->prepare(
        'SELECT id, taxonomy_code FROM ipca_safety_taxonomy_nodes
         WHERE organization_id = ? AND taxonomy_code IN (' .
        implode(',', array_fill(0, count($types), '?')) . ')'
    );
    $lookup->execute(array_merge(array($organizationId), array_keys($types)));
    $ids = array();
    foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ids[(string)$row['taxonomy_code']] = (int)$row['id'];
    }
    $parent = $pdo->prepare(
        'UPDATE ipca_safety_taxonomy_nodes SET parent_id = ?
         WHERE organization_id = ? AND taxonomy_code = ?'
    );
    foreach ($types as $type) {
        $parent->execute(array(
            $type['parent_code'] === null ? null : $ids[$type['parent_code']],
            $organizationId,
            $type['code'],
        ));
    }
    $pdo->prepare(
        'INSERT INTO ipca_safety_config
         (organization_id, config_key, config_value)
         VALUES (?, \'reporter_occurrence_type_node_type\', ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value),
           updated_at_utc = CURRENT_TIMESTAMP(3)'
    )->execute(array(
        $organizationId,
        json_encode(array('value' => $nodeType), JSON_THROW_ON_ERROR),
    ));
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
echo json_encode(array(
    'ok' => true,
    'organization_id' => $organizationId,
    'node_type' => $nodeType,
    'imported' => count($types),
), JSON_UNESCAPED_SLASHES) . PHP_EOL;
