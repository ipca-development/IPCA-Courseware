<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/CvrLiveCockpitMonitorService.php';

try {
    $count = (new CvrLiveCockpitMonitorService($pdo))->cleanupExpiredChunks();
    fwrite(STDOUT, 'Purged ' . $count . " expired live cockpit monitor chunk(s).\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Live cockpit monitor cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
