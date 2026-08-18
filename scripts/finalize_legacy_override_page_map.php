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
require_once __DIR__ . '/../src/publishing/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingReaderPageMapStore.php';
require_once __DIR__ . '/../src/publishing/ControlledPublishingReaderService.php';

$versionId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--version-id=')) {
        $versionId = (int)substr($argument, strlen('--version-id='));
    }
}
if ($versionId <= 0) {
    throw new InvalidArgumentException('Usage: --version-id=<released legacy override version>');
}

$pdo = cw_db();
$version = (new ControlledPublishingFoundationService($pdo))->getVersion($versionId);
if ($version === null || (string)$version['lifecycle_status'] !== 'released') {
    throw new RuntimeException('The selected version is not released.');
}
$overrideStmt = $pdo->prepare(
    'SELECT override_uuid, actor_user_id
     FROM ipca_publishing_compliance_overrides
     WHERE book_version_id = ?
       AND override_scope = ?
     ORDER BY id DESC LIMIT 1'
);
$overrideStmt->execute(array($versionId, 'all_release_blockers'));
$override = $overrideStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($override)) {
    throw new RuntimeException('No immutable legacy compliance override exists for this version.');
}

$store = new ControlledPublishingReaderPageMapStore($pdo);
$map = $store->approvalMeta($versionId);
if (!in_array((string)($map['status'] ?? ''), array('draft', 'approved'), true)) {
    throw new RuntimeException('The released version has no draft page-map evidence to finalize.');
}
$profile = (string)($map['layout_profile'] ?? '');
if ($profile !== ControlledPublishingReaderLayoutProfile::profileKey()) {
    throw new RuntimeException('The stored page map uses an unsupported layout profile.');
}
$pageCount = $store->pageCount($versionId, $profile);
if ($pageCount <= 0) {
    throw new RuntimeException('The released version has no stored page-map rows.');
}
if ((string)($map['layout_hash'] ?? '') !== ControlledPublishingReaderLayoutProfile::layoutHash()) {
    throw new RuntimeException('The page-map layout hash is stale.');
}

$reader = new ControlledPublishingReaderService($pdo);
$source = $reader->loadReaderPaginateSource($version);
$package = $reader->paginationPublicationPackage($version, $source);
$generation = is_array($map['generation'] ?? null) ? $map['generation'] : array();
$frozenStyleHash = (string)($generation['style_hash'] ?? '');
$currentStyleHash = (string)($package['css']['hash'] ?? '');
if (
    $frozenStyleHash !== ''
    && $currentStyleHash !== ''
    && !hash_equals($frozenStyleHash, $currentStyleHash)
) {
    throw new RuntimeException(
        'The frozen map uses different publication styles and cannot be rebound safely.'
    );
}
$generation['style_hash'] = $currentStyleHash;
$generation['manifest_hash'] = (string)($package['manifest_hash'] ?? '');

$metadata = $version['metadata_json'] ?? array();
if (is_string($metadata)) {
    $metadata = json_decode($metadata, true);
}
$metadata = is_array($metadata) ? $metadata : array();
$now = gmdate('Y-m-d H:i:s');
$metadata['reader_page_map'] = array_merge($map, array(
    'status' => 'approved',
    'generation' => $generation,
    'page_count' => $pageCount,
    'approved_at' => $now,
    'approved_by_user_id' => (int)($override['actor_user_id'] ?? 0),
    'approval_basis' => 'legacy_compliance_override',
    'approval_override_uuid' => (string)$override['override_uuid'],
));
$update = $pdo->prepare(
    'UPDATE ipca_publishing_book_versions
     SET metadata_json = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND lifecycle_status = ?'
);
$update->execute(array(
    json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    $versionId,
    'released',
));
if ($update->rowCount() !== 1) {
    throw new RuntimeException('The released page-map evidence was not updated.');
}

echo 'page_map=approved' . PHP_EOL;
echo 'page_count=' . $pageCount . PHP_EOL;
echo 'manifest_hash=' . $generation['manifest_hash'] . PHP_EOL;
echo 'override_uuid=' . (string)$override['override_uuid'] . PHP_EOL;
echo "ok\n";
