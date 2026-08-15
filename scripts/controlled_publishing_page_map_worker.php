<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
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
