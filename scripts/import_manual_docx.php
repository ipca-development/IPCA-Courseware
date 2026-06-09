#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Import manual Part DOCX exports into a controlled book version.
 *
 * Usage:
 *   php scripts/import_manual_docx.php --version-id=1 --part=1=/path/Part\ 1.docx [--part=2=...] [--preview] [--no-force]
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingBlockService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingSectionService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingDocxImportService.php';

$versionId = 0;
$partFiles = array();
$preview = false;
$force = true;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--version-id=')) {
        $versionId = (int)substr($arg, 13);
        continue;
    }
    if (str_starts_with($arg, '--part=')) {
        $spec = substr($arg, 7);
        $eq = strpos($spec, '=');
        if ($eq === false) {
            fwrite(STDERR, "Invalid --part= syntax. Use --part=1=/path/file.docx\n");
            exit(1);
        }
        $part = (int)substr($spec, 0, $eq);
        $path = substr($spec, $eq + 1);
        if ($part < 0 || $path === '' || !is_readable($path)) {
            fwrite(STDERR, "Invalid or unreadable part file: {$spec}\n");
            exit(1);
        }
        $partFiles[$part] = $path;
        continue;
    }
    if ($arg === '--preview') {
        $preview = true;
        continue;
    }
    if ($arg === '--no-force') {
        $force = false;
    }
}

if ($versionId <= 0 || $partFiles === array()) {
    fwrite(STDERR, "Usage: php scripts/import_manual_docx.php --version-id=ID --part=0=/path/Part0.docx [--part=1=...] [--preview] [--no-force]\n");
    exit(1);
}

$foundation = new ControlledPublishingFoundationService($pdo);
$blocks = new ControlledPublishingBlockService($pdo);
$sections = new ControlledPublishingSectionService($pdo);
$styleSvc = new ControlledPublishingBookStyleService($pdo);
$part0PageSvc = new ControlledPublishingPart0PageService($pdo, $blocks);
$manualStructureSvc = new ControlledPublishingManualStructureService($pdo, $foundation, $sections, $blocks);
$importSvc = new ControlledPublishingDocxImportService(
    $pdo,
    $foundation,
    $sections,
    $blocks,
    $manualStructureSvc,
    $part0PageSvc,
    $styleSvc
);

try {
    ksort($partFiles);
    if ($preview) {
        $result = $importSvc->preview($versionId, $partFiles);
    } else {
        $result = $importSvc->apply($versionId, $partFiles, $force, null);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
