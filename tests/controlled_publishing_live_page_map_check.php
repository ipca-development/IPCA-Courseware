<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/ControlledPublishingLivePageMapService.php';

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE ipca_publishing_books (
    id INTEGER PRIMARY KEY,
    book_type TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE ipca_publishing_book_versions (
    id INTEGER PRIMARY KEY,
    book_id INTEGER NOT NULL,
    lifecycle_status TEXT NOT NULL,
    metadata_json TEXT NULL
)');
$pdo->exec('CREATE TABLE ipca_publishing_reader_page_maps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_version_id INTEGER NOT NULL,
    layout_profile TEXT NOT NULL,
    layout_hash TEXT NOT NULL,
    page_number INTEGER NOT NULL,
    section_id INTEGER NULL,
    stable_anchor TEXT NULL,
    page_type TEXT NOT NULL,
    is_cover INTEGER NOT NULL DEFAULT 0,
    is_section_start INTEGER NOT NULL DEFAULT 0,
    is_major_section_start INTEGER NOT NULL DEFAULT 0,
    page_html TEXT NOT NULL,
    thumbnail_html TEXT NULL,
    metadata_json TEXT NULL,
    generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by_user_id INTEGER NULL,
    UNIQUE (book_version_id, layout_profile, page_number)
)');
$pdo->exec('CREATE TABLE ipca_publishing_page_map_generation_state (
    book_version_id INTEGER NOT NULL,
    layout_profile TEXT NOT NULL,
    generation_seq INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    requested_fingerprint_hash TEXT NOT NULL DEFAULT \'\',
    requested_fingerprint_json TEXT NULL,
    requested_mutation_json TEXT NULL,
    pending_generation_seq INTEGER NULL,
    pending_fingerprint_hash TEXT NULL,
    pending_fingerprint_json TEXT NULL,
    pending_mutation_json TEXT NULL,
    pending_requested_by_user_id INTEGER NULL,
    lease_token TEXT NULL,
    lease_expires_at TEXT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    requested_by_user_id INTEGER NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_version_id, layout_profile)
)');
$pdo->exec('CREATE TABLE ipca_publishing_reader_page_map_staging (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_version_id INTEGER NOT NULL,
    layout_profile TEXT NOT NULL,
    generation_seq INTEGER NOT NULL,
    lease_token TEXT NOT NULL,
    layout_hash TEXT NOT NULL,
    page_number INTEGER NOT NULL,
    section_id INTEGER NULL,
    stable_anchor TEXT NULL,
    page_type TEXT NOT NULL,
    is_cover INTEGER NOT NULL DEFAULT 0,
    is_section_start INTEGER NOT NULL DEFAULT 0,
    is_major_section_start INTEGER NOT NULL DEFAULT 0,
    page_html TEXT NOT NULL,
    thumbnail_html TEXT NULL,
    metadata_json TEXT NULL,
    generated_by_user_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (book_version_id, layout_profile, generation_seq, lease_token, page_number)
)');
$pdo->exec("INSERT INTO ipca_publishing_books (id, book_type) VALUES (1, 'manual')");
$pdo->exec("INSERT INTO ipca_publishing_book_versions (id, book_id, lifecycle_status, metadata_json)
    VALUES (1, 1, 'draft', '{}'), (2, 1, 'draft', '{}'), (3, 1, 'draft', '{}')");

$revision = 'a';
$mode = 'success';
$spawned = array();
$activeGenerators = 0;
$maxActiveGenerators = 0;
$generatorRuns = 0;
$duringGenerationRequest = null;
$pendingRequestsDuringGeneration = array();
$nestedWorkResult = 'not-run';
$service = null;
$versions = array(
    1 => array('id' => 1, 'book_key' => 'OM', 'lifecycle_status' => 'draft'),
    2 => array('id' => 2, 'book_key' => 'OMM', 'lifecycle_status' => 'draft'),
    3 => array('id' => 3, 'book_key' => 'OM', 'lifecycle_status' => 'draft'),
);
$fingerprintFor = static function (string $value, string $profile = 'LETTER_READER_v1'): array {
    return array(
        'engine_version' => 'fake-v1',
        'source_hash' => hash('sha256', $value),
        'style_hash' => hash('sha256', 'style'),
        'manifest_hash' => hash('sha256', 'manifest'),
        'layout_hash' => hash('sha256', 'layout:' . $profile),
        'manual_page_break_hash' => hash('sha256', 'breaks'),
        'header_footer_hash' => hash('sha256', 'bands'),
    );
};

$store = new ControlledPublishingReaderPageMapStore($pdo);
$service = new ControlledPublishingLivePageMapService(
    $pdo,
    new ControlledPublishingReaderService($pdo),
    array(
        'store' => $store,
        'version_loader' => static fn(int $id): ?array => $versions[$id] ?? null,
        'source_loader' => static function (array $version, string $profile) use (&$revision): array {
            return array(
                'revision' => $revision,
                'version_id' => (int)$version['id'],
                'profile' => $profile,
            );
        },
        'fingerprint_loader' => static fn(array $source, array $version, string $profile): array
            => $fingerprintFor((string)$source['revision'], $profile),
        'generator' => static function (array $source, array $version, string $profile) use (
            &$mode,
            &$revision,
            &$service,
            &$activeGenerators,
            &$maxActiveGenerators,
            &$generatorRuns,
            &$duringGenerationRequest,
            &$pendingRequestsDuringGeneration,
            &$nestedWorkResult,
            $fingerprintFor
        ): array {
            ++$generatorRuns;
            ++$activeGenerators;
            $maxActiveGenerators = max($maxActiveGenerators, $activeGenerators);
            try {
                if ($mode === 'fail') {
                    throw new RuntimeException('fake browser failed');
                }
                $captured = (string)$source['revision'];
                if ($mode === 'change_during_generation') {
                    $revision = 'c';
                } elseif ($mode === 'queue_during_generation') {
                    $revision = 'newest';
                    $duringGenerationRequest = $service->ensure(1, 8);
                } elseif ($mode === 'queue_five_during_generation') {
                    foreach (range(1, 5) as $edit) {
                        $revision = 'edit-' . $edit;
                        $pendingRequestsDuringGeneration[] = $service->ensure(
                            1,
                            8,
                            $profile,
                            array(
                                'layout_impact' => 'suffix',
                                'stable_anchor' => 'edit-anchor-' . $edit,
                                'client_mutation_revision' => $edit,
                            )
                        );
                    }
                } elseif ($mode === 'same_key_reentry') {
                    $nestedWorkResult = $service->workOne((int)$version['id'], $profile);
                } elseif (
                    $mode === 'different_version_independent'
                    && (int)$version['id'] === 1
                ) {
                    $service->ensure(2, 9, $profile);
                    $nestedWorkResult = $service->workOne(2, $profile);
                } elseif (
                    $mode === 'different_profile_serialized'
                    && $profile !== 'ALT_READER_v1'
                ) {
                    $nestedWorkResult = $service->workOne(1, 'ALT_READER_v1');
                } elseif ($mode === 'failure_with_pending') {
                    $revision = 'after-failure';
                    $duringGenerationRequest = $service->ensure((int)$version['id'], 8, $profile);
                    throw new RuntimeException('fake active failure');
                }
                $html = '<article>' . $captured . '</article>';
                return array(
                    'pages' => array(array(
                        'page_number' => 1,
                        'section_id' => 10,
                        'stable_anchor' => 'section-' . $captured,
                        'page_type' => 'content',
                        'page_html' => $html,
                        'metadata' => array('revision' => $captured),
                    )),
                    'generation' => array_merge($fingerprintFor($captured, $profile), array(
                        'page_count' => 1,
                        'page_map_hash' => hash('sha256', $html),
                        'validation' => array('is_valid' => true),
                    )),
                );
            } finally {
                --$activeGenerators;
            }
        },
        'spawner' => static function (int $versionId, string $profile) use (&$spawned): void {
            $spawned[] = array($versionId, $profile);
        },
    )
);

$first = $service->ensure(1, 7);
$second = $service->ensure(1, 7);
$assert($first['status'] === 'pending', 'ensure did not create pending state.');
$assert($first['generation_seq'] === 1, 'first request did not allocate generation 1.');
$assert($second['generation_seq'] === 1, 'identical pending request was not coalesced.');
$assert(count($spawned) === 1, 'coalesced request spawned duplicate workers.');

$completed = $service->workOne(1, ControlledPublishingReaderLayoutProfile::profileKey());
$assert(($completed['status'] ?? '') === 'current', 'successful worker did not become current.');
$assert($store->pageCount(1, ControlledPublishingReaderLayoutProfile::profileKey()) === 1, 'successful worker did not promote staging.');
$assert(
    ($store->loadPage(1, ControlledPublishingReaderLayoutProfile::profileKey(), 1)['page_html'] ?? '') === '<article>a</article>',
    'promoted page payload is incorrect.'
);

$revision = 'b';
$driftedStatus = $service->status(1);
$assert($driftedStatus['status'] === 'stale', 'live status did not detect source drift from current production.');
$mode = 'change_during_generation';
$staleRequest = $service->ensure(1, 7);
$assert($staleRequest['generation_seq'] === 2, 'changed fingerprint did not allocate a new generation.');
$stale = $service->workOne(1, ControlledPublishingReaderLayoutProfile::profileKey());
$assert(($stale['status'] ?? '') === 'stale', 'commit-time fingerprint change was not marked stale.');
$assert(
    ($store->loadPage(1, ControlledPublishingReaderLayoutProfile::profileKey(), 1)['page_html'] ?? '') === '<article>a</article>',
    'stale completion replaced the last valid production map.'
);
$stagingCount = (int)$pdo->query('SELECT COUNT(*) FROM ipca_publishing_reader_page_map_staging')->fetchColumn();
$assert($stagingCount === 0, 'stale candidate was not cleaned from staging.');

$mode = 'fail';
$failedRequest = $service->ensure(1, 7);
$assert($failedRequest['generation_seq'] === 3, 'ensure after stale did not queue current fingerprint.');
$failed = $service->workOne(1, ControlledPublishingReaderLayoutProfile::profileKey());
$assert(($failed['status'] ?? '') === 'retry_available', 'failure with a last valid map did not expose retry_available.');
$assert(
    ($store->loadPage(1, ControlledPublishingReaderLayoutProfile::profileKey(), 1)['page_html'] ?? '') === '<article>a</article>',
    'failed generation replaced/deleted the last valid map.'
);
$spawnCountAfterFailure = count($spawned);
$sameFailedFingerprint = $service->ensure(1, 7);
$assert(
    ($sameFailedFingerprint['generation_seq'] ?? 0) === 3
        && ($sameFailedFingerprint['status'] ?? '') === 'retry_available',
    'same failed fingerprint allocated another automatic generation.'
);
$assert(
    count($spawned) === $spawnCountAfterFailure,
    'same failed fingerprint spawned an automatic retry loop.'
);

$mode = 'success';
$retry = $service->retry(1, 7);
$assert($retry['generation_seq'] === 4 && $retry['status'] === 'pending', 'live retry did not allocate a fresh pending generation.');
$retried = $service->workOne(1, ControlledPublishingReaderLayoutProfile::profileKey());
$assert(($retried['status'] ?? '') === 'current', 'retried generation did not become current.');
$assert(
    ($store->loadPage(1, ControlledPublishingReaderLayoutProfile::profileKey(), 1)['page_html'] ?? '') === '<article>c</article>',
    'retried generation did not promote current source.'
);

$profile = ControlledPublishingReaderLayoutProfile::profileKey();
$revision = 'active';
$mode = 'queue_during_generation';
$spawnCountBeforeConcurrency = count($spawned);
$activeRequest = $service->ensure(1, 7);
$firstCompletion = $service->workOne(1, ControlledPublishingReaderLayoutProfile::profileKey());
$assert($activeRequest['status'] === 'pending', 'concurrency scenario did not queue active generation.');
$assert(
    ($duringGenerationRequest['status'] ?? '') === 'generating'
        && ($duringGenerationRequest['generation_seq'] ?? 0) === 5
        && ($duringGenerationRequest['pending_generation_seq'] ?? 0) === 6,
    'changed request did not remain in the newest-pending slot behind the active lease.'
);
$assert(
    ($firstCompletion['status'] ?? '') === 'pending'
        && ($firstCompletion['generation_seq'] ?? 0) === 6,
    'stale active completion did not atomically continue with newest pending generation.'
);
$assert(
    count($spawned) === $spawnCountBeforeConcurrency + 1,
    'changed request spawned an overlapping worker.'
);
$assert(
    ($store->loadPage(1, $profile, 1)['page_html'] ?? '') === '<article>c</article>',
    'stale active completion promoted over the last valid production map.'
);
$mode = 'success';
$continued = $service->workOne(1, ControlledPublishingReaderLayoutProfile::profileKey());
$assert(($continued['status'] ?? '') === 'current', 'scoped worker continuation did not finish newest pending generation.');
$assert($maxActiveGenerators === 1, 'more than one fake Chromium generator was active concurrently.');
$assert(
    ($store->loadPage(1, $profile, 1)['page_html'] ?? '') === '<article>newest</article>',
    'newest pending revision was not the generation ultimately promoted.'
);

// (1) A second worker for the identical active key cannot enter the generator.
$mode = 'same_key_reentry';
$nestedWorkResult = 'not-run';
$maxActiveGenerators = 0;
$service->retry(1, 7, $profile);
$service->workOne(1, $profile);
$assert($nestedWorkResult === null, 'same-key worker entered while the server lock was active.');
$assert($maxActiveGenerators === 1, 'same version/profile ran overlapping generators.');

// (2) Five edits collapse to one follow-up and only the fifth fingerprint runs.
$revision = 'five-active';
$mode = 'queue_five_during_generation';
$pendingRequestsDuringGeneration = array();
$runsBeforeFive = $generatorRuns;
$spawnsBeforeFive = count($spawned);
$service->ensure(1, 7, $profile);
$fiveActiveResult = $service->workOne(1, $profile);
$pendingSequences = array_map(
    static fn(array $row): int => (int)($row['pending_generation_seq'] ?? 0),
    $pendingRequestsDuringGeneration
);
$sortedPendingSequences = $pendingSequences;
sort($sortedPendingSequences, SORT_NUMERIC);
$assert(count($pendingSequences) === 5, 'not all five active edits reached the coalescing slot.');
$assert(
    $pendingSequences === $sortedPendingSequences
        && count(array_unique($pendingSequences)) === 5,
    'pending generation sequence did not advance monotonically for five edits.'
);
$assert(($fiveActiveResult['status'] ?? '') === 'pending', 'newest edit was not continued after stale active run.');
$assert(
    count($spawned) === $spawnsBeforeFive + 1,
    'intermediate edits spawned workers instead of coalescing.'
);
$mode = 'success';
$fiveFollowUp = $service->workOne(1, $profile);
$assert(($fiveFollowUp['status'] ?? '') === 'current', 'latest of five edits did not complete.');
$assert($generatorRuns === $runsBeforeFive + 2, 'five edits caused more than one follow-up generator.');
$assert(
    ($store->loadPage(1, $profile, 1)['page_html'] ?? '') === '<article>edit-5</article>',
    'an intermediate edit was promoted instead of the newest fingerprint.'
);
$latestMutationJson = (string)$pdo->query(
    "SELECT requested_mutation_json
       FROM ipca_publishing_page_map_generation_state
      WHERE book_version_id = 1 AND layout_profile = '" . $profile . "'"
)->fetchColumn();
$latestMutation = json_decode($latestMutationJson, true);
$assert(
    is_array($latestMutation)
        && ($latestMutation['stable_anchor'] ?? '') === 'edit-anchor-5'
        && (int)($latestMutation['client_mutation_revision'] ?? 0) === 5,
    'coalescing did not retain the newest suffix mutation hint.'
);

// (3) Different versions have independent server locks and may generate together.
$revision = 'independent-version';
$mode = 'different_version_independent';
$nestedWorkResult = null;
$maxActiveGenerators = 0;
$service->ensure(1, 7, $profile);
$differentVersionOuter = $service->workOne(1, $profile);
$assert(
    is_array($nestedWorkResult) && ($nestedWorkResult['status'] ?? '') === 'current',
    'different version could not generate independently.'
);
$assert($maxActiveGenerators === 2, 'different versions were incorrectly serialized.');
$assert(($differentVersionOuter['status'] ?? '') === 'current', 'outer independent version did not complete.');

// (4) Different profiles are deliberately version-serialized because approval
// metadata has one reader_page_map slot per version.
$revision = 'profile-safety';
$mode = 'different_profile_serialized';
$nestedWorkResult = 'not-run';
$maxActiveGenerators = 0;
$service->ensure(1, 7, $profile);
$service->ensure(1, 7, 'ALT_READER_v1');
$service->workOne(1, $profile);
$assert($nestedWorkResult === null, 'same-version profiles bypassed the version safety lock.');
$assert($maxActiveGenerators === 1, 'same-version profiles overlapped despite shared approval metadata.');
$mode = 'success';
$altResult = $service->workOne(1, null);
$assert(
    ($altResult['status'] ?? '') === 'current'
        && ($altResult['layout_profile'] ?? '') === 'ALT_READER_v1',
    'version-scoped drain did not run the serialized alternate profile afterward.'
);

// (5) Active failure atomically hands off to the newest pending fingerprint.
$revision = 'failure-active';
$mode = 'failure_with_pending';
$runsBeforeFailure = $generatorRuns;
$service->ensure(1, 7, $profile);
$failedWithPending = $service->workOne(1, $profile);
$assert(
    ($failedWithPending['status'] ?? '') === 'pending',
    'active failure discarded or failed the newer pending fingerprint.'
);
$mode = 'success';
$afterFailure = $service->workOne(1, $profile);
$assert(($afterFailure['status'] ?? '') === 'current', 'newest pending did not run after active failure.');
$assert($generatorRuns === $runsBeforeFailure + 2, 'failure handoff did not run exactly one follow-up.');
$assert(
    ($store->loadPage(1, $profile, 1)['page_html'] ?? '') === '<article>after-failure</article>',
    'failure handoff promoted the wrong fingerprint.'
);

$leasePage = array(array(
    'page_number' => 1,
    'page_html' => '<article>lease</article>',
    'metadata' => array(),
));
$store->replaceStagingPages(2, $profile, 99, 'old-lease', str_repeat('a', 64), $leasePage, 7);
$store->replaceStagingPages(2, $profile, 99, 'new-lease', str_repeat('b', 64), $leasePage, 7);
$store->deleteStagingPages(2, $profile, 99, 'old-lease');
$newLeaseCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM ipca_publishing_reader_page_map_staging WHERE lease_token = 'new-lease'"
)->fetchColumn();
$assert($newLeaseCount === 1, 'expired worker cleanup deleted replacement lease staging.');
$store->deleteStagingPages(2, $profile, 99, 'new-lease');

// (7) A crashed worker has no named lock; its expired DB lease is reclaimable.
$revision = 'crash-recovery';
$mode = 'success';
$service->ensure(2, 7, $profile);
$pdo->prepare(
    "UPDATE ipca_publishing_page_map_generation_state
        SET status = 'generating', lease_token = 'crashed-worker',
            lease_expires_at = '2000-01-01 00:00:00'
      WHERE book_version_id = ? AND layout_profile = ?"
)->execute(array(2, $profile));
$spawnsBeforeRecovery = count($spawned);
$recoveryEnsure = $service->ensure(2, 7, $profile);
$recovered = $service->workOne(2, $profile);
$assert(count($spawned) === $spawnsBeforeRecovery + 1, 'expired lease did not dispatch recovery worker.');
$assert(($recoveryEnsure['status'] ?? '') === 'generating', 'expired active state was not preserved for fenced reclaim.');
$assert(($recovered['status'] ?? '') === 'current', 'expired/crashed worker lease was not recovered.');

// (8) A late suffix mutation carries its hint through the server queue and
// reuses only pages before a safe one-page-back reflow boundary.
$incrementalFingerprint = $fingerprintFor('incremental-new', $profile);
$priorFingerprint = array_merge(
    $incrementalFingerprint,
    array('source_hash' => hash('sha256', 'incremental-old'))
);
$pdo->prepare('UPDATE ipca_publishing_book_versions SET metadata_json = ? WHERE id = 3')
    ->execute(array(json_encode(array(
        'reader_page_map' => array('generation' => $priorFingerprint),
    ), JSON_THROW_ON_ERROR)));
$insertProduction = $pdo->prepare(
    'INSERT INTO ipca_publishing_reader_page_maps (
        book_version_id, layout_profile, layout_hash, page_number, section_id,
        stable_anchor, page_type, page_html, metadata_json
     ) VALUES (?,?,?,?,?,?,?,?,?)'
);
foreach (range(1, 4) as $pageNumber) {
    $order = $pageNumber - 1;
    $anchor = $pageNumber === 4 ? 'changed-anchor' : 'stable-' . $pageNumber;
    $insertProduction->execute(array(
        3,
        $profile,
        str_repeat('c', 64),
        $pageNumber,
        30,
        $anchor,
        'content',
        '<article data-stable-anchor="' . $anchor . '" data-block-id="' . (300 + $pageNumber) . '"></article>',
        json_encode(array(
            'coverage' => array(array(
                'source_order' => $order,
                'range_start' => 0,
                'range_end' => 10,
                'source_length' => 10,
                'source_fingerprint' => hash('sha256', 'source-' . $order),
                'presentation_copy' => false,
            )),
            'metrics' => array('break_reason' => $pageNumber === 1 ? '' : 'automatic_author_flow'),
        ), JSON_THROW_ON_ERROR),
    ));
}
$capturedIncrementalOptions = null;
$incrementalService = new ControlledPublishingLivePageMapService(
    $pdo,
    new ControlledPublishingReaderService($pdo),
    array(
        'store' => $store,
        'version_loader' => static fn(int $id): ?array => $versions[$id] ?? null,
        'source_loader' => static fn(array $version, string $profile): array => array(
            'revision' => 'incremental-new',
            'version_id' => (int)$version['id'],
            'profile' => $profile,
        ),
        'fingerprint_loader' => static fn(array $source, array $version, string $profile): array
            => $fingerprintFor('incremental-new', $profile),
        'generator' => static function (
            array $source,
            array $version,
            string $profile,
            array $options
        ) use (&$capturedIncrementalOptions): array {
            $capturedIncrementalOptions = $options;
            throw new RuntimeException('stop after incremental-plan proof');
        },
        'spawner' => static function (): void {
        },
    )
);
$incrementalService->ensure(3, 7, $profile, array(
    'layout_impact' => 'suffix',
    'stable_anchor' => 'changed-anchor',
    'block_id' => 304,
    'mutation_kind' => 'update_block',
    'client_mutation_revision' => 88,
));
$incrementalService->workOne(3, $profile);
$assert(
    count($capturedIncrementalOptions['prefix_pages'] ?? array()) === 2,
    'late suffix mutation did not reuse the proven two-page prefix.'
);
$assert(
    (int)($capturedIncrementalOptions['incremental']['start_source_order'] ?? -1) === 2,
    'suffix pagination did not start at the safe prior-page source boundary.'
);
$assert(
    (int)($capturedIncrementalOptions['incremental']['page_number_offset'] ?? -1) === 2,
    'suffix pagination page-number offset is incorrect.'
);

if ($failures !== array()) {
    fwrite(STDERR, "Controlled publishing live page map: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Controlled publishing live page map: PASS\n";
echo "Proof 1: same version/profile max active generators = 1\n";
echo "Proof 2: five active edits produced one follow-up using edit-5\n";
echo "Proof 3: different versions generated independently\n";
echo "Proof 4: same-version profiles serialized for shared approval metadata\n";
echo "Proof 5: active failure preserved and ran newest pending\n";
echo "Proof 6: stale output preserved the newer production map\n";
echo "Proof 7: expired crashed-worker lease recovered\n";
echo "Proof 8: late suffix mutation reused a safe authoritative prefix\n";
