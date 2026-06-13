<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingMccfDocxImportService.php';

$apply = in_array('--apply', $argv ?? array(), true);
$sourceSetId = 0;
$sourceSetKey = '';
$manualCode = 'OM';
$path = '';

foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--source-set-id=')) {
        $sourceSetId = (int)substr($arg, strlen('--source-set-id='));
    }
    if (str_starts_with($arg, '--source-set=')) {
        $sourceSetKey = trim(substr($arg, strlen('--source-set=')));
    }
    if (str_starts_with($arg, '--manual=')) {
        $manualCode = strtoupper(trim(substr($arg, strlen('--manual='))));
    }
    if (str_starts_with($arg, '--file=')) {
        $path = trim(substr($arg, strlen('--file=')));
    }
}

if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/import_mccf_bcaa_docx.php --file=/path/to/mccf.docx [--manual=OM|OMM] [--source-set-id=N] [--apply]\n");
    exit(1);
}

$browser = new ControlledPublishingMccfBrowserService($pdo);
if ($sourceSetId <= 0) {
    $sourceSetId = $browser->resolveSourceSetId(0, $sourceSetKey);
}
if ($sourceSetId <= 0) {
    fwrite(STDERR, "No MCCF source set found.\n");
    exit(1);
}

$importer = new ControlledPublishingMccfDocxImportService($pdo);
$result = $importer->importFile($path, $sourceSetId, $manualCode, $apply);

echo ($apply ? 'APPLY' : 'DRY-RUN') . " BCAA MCCF DOCX import (source_set_id={$sourceSetId}, manual={$manualCode})\n";
echo "  rows parsed: {$result['rows_parsed']}\n";
echo "  matched:     {$result['matched']}\n";
echo "  updated:     {$result['updated']}\n";
echo "  unmatched:   {$result['unmatched']}\n";
foreach ($result['warnings'] as $warning) {
    echo "  warning: {$warning}\n";
}
foreach ($result['unmatched_samples'] as $sample) {
    echo "  unmatched sample: {$sample}\n";
}

if (!$apply) {
    echo "\nRe-run with --apply to update canonical requirements from the DOCX.\n";
}

exit($result['warnings'] !== array() && $result['rows_parsed'] === 0 ? 1 : 0);
