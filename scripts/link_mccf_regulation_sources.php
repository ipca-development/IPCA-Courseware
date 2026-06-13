<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingMccfRegulationLinkService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingMccfBrowserService.php';

$apply = in_array('--apply', $argv ?? array(), true);
$sourceSetId = 0;
$sourceSetKey = '';

foreach ($argv ?? array() as $arg) {
    if (str_starts_with($arg, '--source-set-id=')) {
        $sourceSetId = (int)substr($arg, strlen('--source-set-id='));
    }
    if (str_starts_with($arg, '--source-set=')) {
        $sourceSetKey = trim(substr($arg, strlen('--source-set=')));
    }
}

$browser = new ControlledPublishingMccfBrowserService($pdo);
$linker = new ControlledPublishingMccfRegulationLinkService($pdo);

if ($sourceSetId <= 0) {
    $sourceSetId = $browser->resolveSourceSetId(0, $sourceSetKey);
}

if ($sourceSetId <= 0) {
    fwrite(STDERR, "No MCCF source set found. Use --source-set-id=N or --source-set=MCCF:OM:REV_6_0\n");
    exit(1);
}

if (!ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($pdo)) {
    fwrite(STDERR, "Apply scripts/sql/2026_06_06_mccf_regulation_links.sql first.\n");
    exit(1);
}

$result = $linker->autoLinkSourceSet($sourceSetId, $apply);

echo ($apply ? 'APPLY' : 'DRY-RUN') . " MCCF regulation auto-link for source_set_id={$sourceSetId}\n";
echo "  linked:     {$result['linked']}\n";
echo "  unresolved: {$result['unresolved']}\n";
echo "  skipped:    {$result['skipped']}\n";
foreach ($result['errors'] as $error) {
    echo "  error: {$error}\n";
}

if (!$apply) {
    echo "\nRe-run with --apply to write ipca_canonical_requirement_regulation_links rows.\n";
}

exit($result['errors'] !== array() ? 1 : 0);
