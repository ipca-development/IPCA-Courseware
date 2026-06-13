<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingMccfOmLinkRebuildService.php';

$apply = in_array('--apply', $argv ?? array(), true);
$activateExcerpts = in_array('--activate-excerpts', $argv ?? array(), true);

$svc = new ControlledPublishingMccfOmLinkRebuildService($pdo);
$result = $svc->rebuild($apply, $activateExcerpts);

if (empty($result['ok'])) {
    fwrite(STDERR, "Rebuild failed: " . (string)($result['error'] ?? 'unknown error') . "\n");
    exit(1);
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . " OM MCCF excerpt link rebuild\n";
echo '  requirements:              ' . (int)$result['requirements'] . "\n";
echo '  requirements_with_links:   ' . (int)$result['requirements_with_links'] . "\n";
echo '  requirements_without_links:' . (int)$result['requirements_without_links'] . "\n";
echo '  links_created:             ' . (int)$result['links_created'] . "\n";
echo '  links_updated:             ' . (int)$result['links_updated'] . "\n";
echo '  links_unchanged:           ' . (int)$result['links_unchanged'] . "\n";
echo '  links_retired:             ' . (int)$result['links_retired'] . "\n";
echo '  excerpts_activated:        ' . (int)$result['excerpts_activated'] . "\n";

$unresolved = $result['unresolved'] ?? array();
if ($unresolved !== array()) {
    echo "\nUnresolved requirements:\n";
    foreach ($unresolved as $row) {
        echo '  ' . (string)$row['requirement_key'] . ' | ' . (string)$row['manual_section_ref'] . "\n";
    }
}

if (!$apply) {
    echo "\nRe-run with --apply to write link changes.\n";
    echo "Add --activate-excerpts to reactivate linked OM excerpts (recommended for coverage view).\n";
}

exit(0);
