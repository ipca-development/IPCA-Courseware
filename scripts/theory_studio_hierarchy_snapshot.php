<?php
declare(strict_types=1);

/**
 * Read-only operational hierarchy snapshot for Theory Content Studio migrations.
 * Does not mutate rows. Prints JSON counts and representative id tuples.
 *
 * Usage: php scripts/theory_studio_hierarchy_snapshot.php
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/theory_studio/TheoryHierarchySnapshot.php';

$snap = new TheoryHierarchySnapshot($pdo);
$payload = $snap->capture();
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
