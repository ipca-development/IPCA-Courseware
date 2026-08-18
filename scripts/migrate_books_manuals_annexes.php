<?php
declare(strict_types=1);

/**
 * Inventory and migrate embedded controlled-publishing annexes.
 *
 * Dry-run (default):
 *   php scripts/migrate_books_manuals_annexes.php \
 *     --dry-run --output=tmp/books-manuals-annexes.json
 *
 * Apply only after the report has been reviewed and explicitly approved:
 *   php scripts/migrate_books_manuals_annexes.php \
 *     --apply --approved-manifest=tmp/books-manuals-annexes.json --actor-user-id=1 \
 *     --output=tmp/books-manuals-annexes-applied.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/BooksManualsAnnexMigrationService.php';

$options = getopt('', array(
    'dry-run',
    'apply',
    'approved-manifest:',
    'actor-user-id:',
    'output:',
));
$apply = array_key_exists('apply', $options);
$dryRun = array_key_exists('dry-run', $options) || !$apply;
if ($apply && $dryRun) {
    fwrite(STDERR, "Choose either --dry-run or --apply.\n");
    exit(2);
}

$output = trim((string)($options['output'] ?? ''));

try {
    $pdo = cw_db();
    $service = new BooksManualsAnnexMigrationService($pdo);
    if ($apply) {
        $manifestPath = trim((string)($options['approved-manifest'] ?? ''));
        $actorUserId = (int)($options['actor-user-id'] ?? 0);
        if ($manifestPath === '' || !is_file($manifestPath)) {
            throw new RuntimeException('--approved-manifest must name a reviewed dry-run report.');
        }
        if ($actorUserId <= 0) {
            throw new RuntimeException('--actor-user-id is required for an approved migration.');
        }
        $approved = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($approved)) {
            throw new RuntimeException('The approved migration manifest is not valid JSON.');
        }
        $result = $service->applyApprovedReport($approved, $actorUserId);
    } else {
        $result = $service->dryRunReport();
    }

    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    if ($output !== '') {
        $parent = dirname($output);
        if (!is_dir($parent)) {
            throw new RuntimeException('Output directory does not exist: ' . $parent);
        }
        if (file_put_contents($output, $json) === false) {
            throw new RuntimeException('Could not write migration report: ' . $output);
        }
        fwrite(STDOUT, ($apply ? 'Apply' : 'Dry-run') . " report written to {$output}\n");
    } else {
        fwrite(STDOUT, $json);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Annex migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
