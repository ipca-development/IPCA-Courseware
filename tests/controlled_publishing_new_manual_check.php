<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/publishing/ControlledPublishingFoundationService.php');
$structure = file_get_contents($root . '/src/publishing/ControlledPublishingManualStructureService.php');
$page = file_get_contents($root . '/public/admin/compliance/controlled_books.php');

if (!is_string($service) || !is_string($structure) || !is_string($page)) {
    fwrite(STDERR, "Unable to read new-manual implementation files.\n");
    exit(1);
}

$serviceMarkers = array(
    'public function createManualFromVersion(',
    "INSERT INTO ipca_publishing_books",
    '$this->copyVersionSections(',
    '$this->copyVersionBlocks(',
    'ControlledPublishingBookStyleService',
    'ensureManualImportSourceSet(',
    "'controlled_book_imports'",
    "'manual_source'",
    '$systemManagedOnly',
    '$oldBookKey',
    '$newBookKey',
);
foreach ($serviceMarkers as $marker) {
    if (!str_contains($service, $marker)) {
        fwrite(STDERR, "Missing new-manual service marker: {$marker}\n");
        exit(1);
    }
}

$pageMarkers = array(
    'Create New Manual',
    'name="book_key"',
    'name="title"',
    'name="version_label"',
    'name="source_version_id"',
    'name="copy_content"',
    '$svc->createManualFromVersion(',
    'A separate canonical import source is created automatically',
);
foreach ($pageMarkers as $marker) {
    if (!str_contains($page, $marker)) {
        fwrite(STDERR, "Missing new-manual UI marker: {$marker}\n");
        exit(1);
    }
}

if (!str_contains(
    $service,
    'if ($systemManagedOnly && empty($row[\'is_system_managed\']))'
)) {
    fwrite(STDERR, "Structure-only clone must exclude author content.\n");
    exit(1);
}

foreach (array(
    "vss.selection_role = 'manual_source'",
    "ss.source_family = 'manual'",
    "CASE WHEN vss.selection_role = 'manual_source' THEN 0 ELSE 1 END",
) as $marker) {
    if (!str_contains($structure, $marker)) {
        fwrite(STDERR, "Missing resilient manual-source resolution marker: {$marker}\n");
        exit(1);
    }
}

echo "Controlled publishing new manual workflow: PASS\n";
echo "Independent book/version, cloned structure/styles, isolated DOCX source\n";

