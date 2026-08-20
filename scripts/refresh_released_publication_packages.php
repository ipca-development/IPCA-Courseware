<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../src/RuntimeSecrets.php';
RuntimeSecrets::ensureCliEnvLoaded();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingReaderService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingAuthoritativePaginationService.php';
require_once __DIR__ . '/../src/publishing/BooksManualsWorkflowService.php';

$options = getopt('', array('version-ids:', 'actor-id:', 'apply', 'include-previews'));
$ids = array_values(array_unique(array_filter(array_map(
    'intval',
    explode(',', (string)($options['version-ids'] ?? ''))
), static fn(int $id): bool => $id > 0)));
$actorId = (int)($options['actor-id'] ?? 0);
$apply = array_key_exists('apply', $options);
$includePreviews = array_key_exists('include-previews', $options);
if ($ids === array() || $actorId <= 0) {
    fwrite(STDERR, "Usage: php scripts/refresh_released_publication_packages.php "
        . "--version-ids=1,2 --actor-id=25 [--include-previews] [--apply]\n");
    exit(1);
}

$pdo = cw_db();
$reader = new ControlledPublishingReaderService($pdo);
$paginator = new ControlledPublishingAuthoritativePaginationService($reader);
$workflow = new BooksManualsWorkflowService($pdo);
$profile = ControlledPublishingReaderLayoutProfile::profileKey();
$layoutHash = ControlledPublishingReaderLayoutProfile::layoutHash();

foreach ($ids as $versionId) {
    $version = $reader->resolveVersionById($versionId);
    if (!is_array($version)) {
        throw new RuntimeException("Version {$versionId} was not found.");
    }
    $lifecycle = (string)($version['lifecycle_status'] ?? '');
    $isReleased = $lifecycle === 'released';
    if (!$isReleased && !$includePreviews) {
        throw new RuntimeException(
            "Version {$versionId} is not released; use --include-previews explicitly."
        );
    }
    $approval = $reader->pageMapStore()->approvalMeta($versionId);
    $mapStatus = (string)($approval['status'] ?? '');
    if (!is_array($approval)
        || ($isReleased && $mapStatus !== 'approved')
        || (!$isReleased && !in_array($mapStatus, array('draft', 'approved'), true))) {
        throw new RuntimeException("Version {$versionId} has no usable stored page map.");
    }
    $source = $reader->loadReaderPaginateSource($version);
    $currentPackage = $reader->paginationPublicationPackage($version, $source);
    $generation = is_array($approval['generation'] ?? null) ? $approval['generation'] : array();
    $styleCurrent = hash_equals(
        (string)($generation['style_hash'] ?? ''),
        (string)($currentPackage['css']['hash'] ?? '')
    );
    $manifestCurrent = hash_equals(
        (string)($generation['manifest_hash'] ?? ''),
        (string)($currentPackage['manifest_hash'] ?? '')
    );
    $hasSnapshot = is_array($generation['publication_package'] ?? null);
    echo sprintf(
        "%d %s lifecycle=%s style=%s manifest=%s snapshot=%s mode=%s\n",
        $versionId,
        (string)($version['book_key'] ?? ''),
        $lifecycle,
        $styleCurrent ? 'current' : 'stale',
        $manifestCurrent ? 'current' : 'stale',
        $hasSnapshot ? 'yes' : 'no',
        $apply ? 'apply' : 'preflight'
    );
    if (!$apply) {
        continue;
    }

    $result = $paginator->generate($source, $version);
    if ($isReleased) {
        $reader->pageMapStore()->replaceApprovedReleasedPages(
            $versionId,
            $profile,
            $layoutHash,
            $result['pages'],
            $actorId,
            $result['generation']
        );
        $workflow->syncUpdateIdentity($versionId);
    } else {
        $reader->pageMapStore()->replaceDraftPages(
            $versionId,
            $profile,
            $layoutHash,
            $result['pages'],
            $actorId,
            $result['generation']
        );
    }
    echo sprintf(
        "%d %s refreshed pages=%d style=%s manifest=%s\n",
        $versionId,
        (string)($version['book_key'] ?? ''),
        count($result['pages']),
        (string)($result['generation']['style_hash'] ?? ''),
        (string)($result['generation']['manifest_hash'] ?? '')
    );
}
