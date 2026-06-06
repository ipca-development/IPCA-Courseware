<?php
declare(strict_types=1);

/**
 * Idempotent seed for OM 6.0 and OMM 4.0 controlled publishing foundation.
 *
 * Usage:
 *   php scripts/seed_controlled_publishing_books.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingFoundationService.php';

$pdo = cw_db();
$svc = new ControlledPublishingFoundationService($pdo);

try {
    $result = $svc->seedOmOmmFoundation(null);
    fwrite(STDOUT, "Controlled publishing foundation seed complete.\n");
    fwrite(STDOUT, 'Books: ' . json_encode($result['books'], JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, 'Versions: ' . json_encode($result['versions'], JSON_UNESCAPED_SLASHES) . "\n");

    foreach ($result['versions'] as $bookKey => $versionId) {
        $validation = $svc->validateVersionReleaseFoundation((int)$versionId);
        fwrite(STDOUT, "{$bookKey} version {$versionId} release foundation: {$validation['status']}\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
