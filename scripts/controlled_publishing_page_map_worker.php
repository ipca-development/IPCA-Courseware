<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const IPCA_PAGE_MAP_WORKER_MEMORY_LIMIT = '384M';
const IPCA_PAGE_MAP_WORKER_MEMORY_BYTES = 384 * 1024 * 1024;

if (@ini_set('memory_limit', IPCA_PAGE_MAP_WORKER_MEMORY_LIMIT) === false) {
    fwrite(STDERR, "Unable to enforce page-map worker memory limit.\n");
    exit(70);
}
$effectiveMemoryLimit = trim((string)ini_get('memory_limit'));
if ($effectiveMemoryLimit === '-1' || $effectiveMemoryLimit === '') {
    fwrite(STDERR, "Page-map worker refuses to run with unlimited memory.\n");
    exit(70);
}
$unit = strtolower(substr($effectiveMemoryLimit, -1));
$numeric = (float)$effectiveMemoryLimit;
$effectiveBytes = match ($unit) {
    'g' => (int)($numeric * 1024 * 1024 * 1024),
    'm' => (int)($numeric * 1024 * 1024),
    'k' => (int)($numeric * 1024),
    default => (int)$numeric,
};
if ($effectiveBytes <= 0 || $effectiveBytes > IPCA_PAGE_MAP_WORKER_MEMORY_BYTES) {
    fwrite(STDERR, "Page-map worker memory limit exceeds the hard safety cap.\n");
    exit(70);
}

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingLivePageMapService.php';

$options = getopt('', array('once', 'drain', 'version-id::', 'profile::', 'max-jobs::', 'idle-ms::'));
$once = array_key_exists('once', $options);
$drain = array_key_exists('drain', $options);
$versionId = isset($options['version-id']) ? (int)$options['version-id'] : null;
$profile = isset($options['profile']) ? trim((string)$options['profile']) : null;
$maxJobs = max(1, (int)($options['max-jobs'] ?? ($once ? 1 : 100)));
$idleMs = max(50, (int)($options['idle-ms'] ?? 500));

$service = new ControlledPublishingLivePageMapService(cw_db());
$processed = 0;
while ($processed < $maxJobs) {
    $result = $service->workOne(
        $versionId !== null && $versionId > 0 ? $versionId : null,
        $profile !== null && $profile !== '' ? $profile : null
    );
    if ($result === null) {
        if ($once || $drain || $versionId !== null) {
            break;
        }
        usleep($idleMs * 1000);
        continue;
    }
    ++$processed;
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    if ($once) {
        break;
    }
}

fwrite(STDOUT, 'processed=' . $processed . PHP_EOL);
