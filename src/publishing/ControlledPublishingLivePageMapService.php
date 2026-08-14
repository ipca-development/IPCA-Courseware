<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderService.php';
require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/ControlledPublishingReaderPageMapStore.php';
require_once __DIR__ . '/ControlledPublishingAuthoritativePaginationService.php';

/**
 * Phase B revision-safe page-map coordinator.
 *
 * One DB row coalesces each version/profile. Browser output is written only to
 * staging and is promoted with a generation/lease/fingerprint CAS. Production
 * pages are therefore the last valid map across failures and stale completions.
 */
final class ControlledPublishingLivePageMapService
{
    public const STATUS_CURRENT = 'current';
    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_STALE = 'stale';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRY_AVAILABLE = 'retry_available';

    private ControlledPublishingReaderService $reader;
    private ControlledPublishingReaderPageMapStore $store;
    /** @var callable(int):?array<string,mixed> */
    private $versionLoader;
    /** @var callable(array<string,mixed>,string):array<string,mixed> */
    private $sourceLoader;
    /** @var callable(array<string,mixed>,array<string,mixed>,string):array<string,mixed> */
    private $fingerprintLoader;
    /** @var callable(array<string,mixed>,array<string,mixed>,string):array{pages:list<array<string,mixed>>,generation:array<string,mixed>} */
    private $generator;
    /** @var callable(int,string):void */
    private $spawner;
    /** @var callable():DateTimeImmutable */
    private $clock;
    private int $leaseSeconds;
    /** @var array<string,bool> */
    private static array $localSingleFlightLocks = array();

    /**
     * Test seams avoid launching Chromium and background processes.
     *
     * Supported seams: store, version_loader, source_loader,
     * fingerprint_loader, generator, spawner, clock, lease_seconds.
     *
     * @param array<string,mixed> $seams
     */
    public function __construct(
        private PDO $pdo,
        ?ControlledPublishingReaderService $reader = null,
        array $seams = array()
    ) {
        $this->reader = $reader ?? new ControlledPublishingReaderService($pdo);
        $this->store = $seams['store'] ?? $this->reader->pageMapStore();
        $this->versionLoader = $seams['version_loader']
            ?? fn(int $id): ?array => $this->reader->resolveVersionById($id);
        $this->sourceLoader = $seams['source_loader']
            ?? fn(array $version, string $profile): array
                => $this->reader->loadReaderPaginateSource($version);
        $this->fingerprintLoader = $seams['fingerprint_loader']
            ?? function (array $source, array $version, string $profile): array {
                return (new ControlledPublishingAuthoritativePaginationService($this->reader))
                    ->fingerprint($source, $version);
            };
        $this->generator = $seams['generator']
            ?? function (array $source, array $version, string $profile): array {
                return (new ControlledPublishingAuthoritativePaginationService($this->reader))
                    ->generate($source, $version);
            };
        $this->spawner = $seams['spawner'] ?? self::defaultSpawner(dirname(__DIR__, 2));
        $this->clock = $seams['clock']
            ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Browser worker hard timeout is 300s; never permit live-lease takeover
        // while that process can still be running and holding the named lock.
        $this->leaseSeconds = max(360, (int)($seams['lease_seconds'] ?? 900));
    }

    /**
     * Coalesces an async request for the complete current fingerprint.
     *
     * @return array<string,mixed>
     */
    public function ensure(
        int $bookVersionId,
        int $requestedByUserId,
        ?string $layoutProfile = null
    ): array
    {
        return $this->request($bookVersionId, $requestedByUserId, false, $layoutProfile);
    }

    /**
     * Explicitly starts a fresh generation sequence after a failed/stale run.
     *
     * @return array<string,mixed>
     */
    public function retry(
        int $bookVersionId,
        int $requestedByUserId,
        ?string $layoutProfile = null
    ): array
    {
        return $this->request($bookVersionId, $requestedByUserId, true, $layoutProfile);
    }

    /**
     * @return array<string,mixed>
     */
    private function request(
        int $bookVersionId,
        int $requestedByUserId,
        bool $force,
        ?string $layoutProfile
    ): array
    {
        $version = $this->requireMutableVersion($bookVersionId);
        $profile = $this->normalizeProfile($layoutProfile);
        $fingerprint = $this->readFingerprint($version, $profile);
        $fingerprintJson = self::canonicalJson($fingerprint);
        $fingerprintHash = hash('sha256', $fingerprintJson);

        $queued = $this->queueRequest(
            $bookVersionId,
            $profile,
            $fingerprintJson,
            $fingerprintHash,
            $requestedByUserId,
            $force,
            $this->productionMatches($bookVersionId, $profile, $fingerprint)
        );
        if ($queued['spawn']) {
            ($this->spawner)($bookVersionId, $profile);
        }

        return $this->statusPayload($bookVersionId, $profile, $queued['row'], $fingerprint);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(int $bookVersionId, ?string $layoutProfile = null): array
    {
        $version = $this->requireMutableVersion($bookVersionId);
        $profile = $this->normalizeProfile($layoutProfile);
        $fingerprint = $this->readFingerprint($version, $profile);
        $row = $this->loadState($bookVersionId, $profile);
        if ($row === null) {
            $status = $this->productionMatches($bookVersionId, $profile, $fingerprint)
                ? self::STATUS_CURRENT
                : self::STATUS_STALE;
            $row = array('generation_seq' => 0, 'status' => $status);
        } elseif (
            (string)($row['status'] ?? '') === self::STATUS_CURRENT
            && !$this->productionMatches($bookVersionId, $profile, $fingerprint)
        ) {
            $row['status'] = self::STATUS_STALE;
        }
        return $this->statusPayload($bookVersionId, $profile, $row, $fingerprint);
    }

    /**
     * Claims and runs at most one pending/expired job.
     *
     * @return array<string,mixed>|null
     */
    public function workOne(?int $bookVersionId = null, ?string $layoutProfile = null): ?array
    {
        if ($bookVersionId === null || $layoutProfile === null || $layoutProfile === '') {
            $candidate = $this->peekClaimable($bookVersionId, $layoutProfile);
            if ($candidate === null) {
                return null;
            }
            $bookVersionId = (int)$candidate['book_version_id'];
            $layoutProfile = (string)$candidate['layout_profile'];
        }
        $release = $this->acquireServerSingleFlight($bookVersionId, $layoutProfile);
        if ($release === null) {
            return null;
        }
        try {
            return $this->workOneLocked($bookVersionId, $layoutProfile);
        } finally {
            $release();
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function workOneLocked(int $bookVersionId, string $layoutProfile): ?array
    {
        $claim = $this->claim($bookVersionId, $layoutProfile);
        if ($claim === null) {
            return null;
        }

        $versionId = (int)$claim['book_version_id'];
        $profile = (string)$claim['layout_profile'];
        $seq = (int)$claim['generation_seq'];
        $leaseToken = (string)$claim['lease_token'];
        try {
            $version = $this->requireMutableVersion($versionId);
            $source = ($this->sourceLoader)($version, $profile);
            $startFingerprint = ($this->fingerprintLoader)($source, $version, $profile);
            if (!$this->claimFingerprintMatches($claim, $startFingerprint)) {
                $this->markStale($versionId, $profile, $seq, $leaseToken, 'Source changed before generation.');
                return $this->status($versionId, $profile);
            }

            $result = ($this->generator)($source, $version, $profile);
            $pages = is_array($result['pages'] ?? null) ? $result['pages'] : array();
            $generation = is_array($result['generation'] ?? null) ? $result['generation'] : array();
            $this->validateGeneratedResult($pages, $generation, $startFingerprint);
            $this->store->replaceStagingPages(
                $versionId,
                $profile,
                $seq,
                $leaseToken,
                ControlledPublishingReaderLayoutProfile::layoutHash(),
                $pages,
                (int)($claim['requested_by_user_id'] ?? 0)
            );

            $promoted = $this->store->promoteStagingPagesCas(
                $versionId,
                $profile,
                $seq,
                $leaseToken,
                ControlledPublishingReaderLayoutProfile::layoutHash(),
                (int)($claim['requested_by_user_id'] ?? 0),
                $generation,
                function () use ($versionId, $profile): array {
                    $freshVersion = $this->requireMutableVersion($versionId);
                    $freshSource = ($this->sourceLoader)($freshVersion, $profile);
                    return ($this->fingerprintLoader)($freshSource, $freshVersion, $profile);
                }
            );
            if (!$promoted) {
                // This deletes only this candidate. Production is never touched.
                $this->store->deleteStagingPages($versionId, $profile, $seq, $leaseToken);
            }
            return $this->status($versionId, $profile);
        } catch (Throwable $e) {
            try {
                $this->store->deleteStagingPages($versionId, $profile, $seq, $leaseToken);
            } catch (Throwable) {
                // Cleanup failure must not hide the generation failure.
            }
            $this->markFailure($versionId, $profile, $seq, $leaseToken, $e);
            return $this->status($versionId, $profile);
        }
    }

    /**
     * @return array{row:array<string,mixed>,spawn:bool}
     */
    private function queueRequest(
        int $versionId,
        string $profile,
        string $fingerprintJson,
        string $fingerprintHash,
        int $userId,
        bool $force,
        bool $productionCurrent
    ): array {
        $this->pdo->beginTransaction();
        try {
            $existing = $this->loadState($versionId, $profile, true);
            if (is_array($existing) && (string)$existing['status'] === self::STATUS_GENERATING) {
                $activeMatches = $this->storedFingerprintMatches(
                    (string)$existing['requested_fingerprint_hash'],
                    (string)$existing['requested_fingerprint_json'],
                    $fingerprintHash,
                    $fingerprintJson
                );
                $pendingMatches = $existing['pending_generation_seq'] !== null
                    && $this->storedFingerprintMatches(
                        (string)($existing['pending_fingerprint_hash'] ?? ''),
                        (string)($existing['pending_fingerprint_json'] ?? ''),
                        $fingerprintHash,
                        $fingerprintJson
                    );

                if (!$force && $activeMatches) {
                    // The newest source reverted to the active fingerprint.
                    // Cancel any obsolete pending revision without touching the lease.
                    if ($existing['pending_generation_seq'] !== null) {
                        $this->pdo->prepare(
                            'UPDATE ipca_publishing_page_map_generation_state
                                SET pending_generation_seq = NULL,
                                    pending_fingerprint_hash = NULL,
                                    pending_fingerprint_json = NULL,
                                    pending_requested_by_user_id = NULL,
                                    updated_at = CURRENT_TIMESTAMP
                              WHERE book_version_id = ? AND layout_profile = ?
                                AND generation_seq = ? AND lease_token = ?'
                        )->execute(array(
                            $versionId,
                            $profile,
                            (int)$existing['generation_seq'],
                            (string)$existing['lease_token'],
                        ));
                        $existing = $this->loadState($versionId, $profile, false) ?? $existing;
                    }
                    $this->pdo->commit();
                    return array(
                        'row' => $existing,
                        'spawn' => $this->leaseExpired($existing),
                    );
                }
                if (!$force && $pendingMatches) {
                    $this->pdo->commit();
                    return array(
                        'row' => $existing,
                        'spawn' => $this->leaseExpired($existing),
                    );
                }

                $pendingSeq = max(
                    (int)$existing['generation_seq'],
                    (int)($existing['pending_generation_seq'] ?? 0)
                ) + 1;
                $this->pdo->prepare(
                    'UPDATE ipca_publishing_page_map_generation_state
                        SET pending_generation_seq = ?, pending_fingerprint_hash = ?,
                            pending_fingerprint_json = ?, pending_requested_by_user_id = ?,
                            updated_at = CURRENT_TIMESTAMP
                      WHERE book_version_id = ? AND layout_profile = ?
                        AND generation_seq = ? AND status = ?
                        AND lease_token = ?'
                )->execute(array(
                    $pendingSeq,
                    $fingerprintHash,
                    $fingerprintJson,
                    $userId > 0 ? $userId : null,
                    $versionId,
                    $profile,
                    (int)$existing['generation_seq'],
                    self::STATUS_GENERATING,
                    (string)$existing['lease_token'],
                ));
                $row = $this->loadState($versionId, $profile, false) ?? $existing;
                $this->pdo->commit();
                // The active scoped worker owns continuation; never spawn overlap.
                return array('row' => $row, 'spawn' => $this->leaseExpired($row));
            }

            if (!$force && $productionCurrent) {
                if ($existing === null) {
                    $this->pdo->prepare(
                        "INSERT INTO ipca_publishing_page_map_generation_state (
                            book_version_id, layout_profile, generation_seq, status,
                            requested_fingerprint_hash, requested_fingerprint_json,
                            created_at, updated_at
                         ) VALUES (?, ?, 0, 'current', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                    )->execute(array($versionId, $profile, $fingerprintHash, $fingerprintJson));
                } else {
                    $this->pdo->prepare(
                        "UPDATE ipca_publishing_page_map_generation_state
                            SET status = 'current', requested_fingerprint_hash = ?,
                                requested_fingerprint_json = ?, lease_token = NULL,
                                lease_expires_at = NULL, pending_generation_seq = NULL,
                                pending_fingerprint_hash = NULL,
                                pending_fingerprint_json = NULL,
                                pending_requested_by_user_id = NULL,
                                last_error_code = NULL, last_error_message = NULL,
                                updated_at = CURRENT_TIMESTAMP
                          WHERE book_version_id = ? AND layout_profile = ?"
                    )->execute(array($fingerprintHash, $fingerprintJson, $versionId, $profile));
                }
                $row = $this->loadState($versionId, $profile, false) ?? array();
                $this->pdo->commit();
                return array('row' => $row, 'spawn' => false);
            }

            if (
                !$force
                && is_array($existing)
                && (string)$existing['status'] === self::STATUS_PENDING
                && $this->storedFingerprintMatches(
                    (string)$existing['requested_fingerprint_hash'],
                    (string)$existing['requested_fingerprint_json'],
                    $fingerprintHash,
                    $fingerprintJson
                )
            ) {
                $this->pdo->commit();
                return array('row' => $existing, 'spawn' => false);
            }

            $seq = (int)($existing['generation_seq'] ?? 0) + 1;
            $params = array(
                $seq,
                self::STATUS_PENDING,
                $fingerprintHash,
                $fingerprintJson,
                $userId > 0 ? $userId : null,
                $versionId,
                $profile,
            );
            if ($existing === null) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO ipca_publishing_page_map_generation_state (
                        generation_seq, status, requested_fingerprint_hash,
                        requested_fingerprint_json, requested_by_user_id,
                        book_version_id, layout_profile, created_at, updated_at
                     ) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
                );
                $insert->execute($params);
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE ipca_publishing_page_map_generation_state
                        SET generation_seq = ?, status = ?, requested_fingerprint_hash = ?,
                            requested_fingerprint_json = ?, requested_by_user_id = ?,
                            lease_token = NULL, lease_expires_at = NULL,
                            pending_generation_seq = NULL,
                            pending_fingerprint_hash = NULL,
                            pending_fingerprint_json = NULL,
                            pending_requested_by_user_id = NULL,
                            last_error_code = NULL, last_error_message = NULL,
                            updated_at = CURRENT_TIMESTAMP
                      WHERE book_version_id = ? AND layout_profile = ?'
                );
                $update->execute($params);
            }
            $row = $this->loadState($versionId, $profile, false);
            $this->pdo->commit();
            return array('row' => $row ?? array(), 'spawn' => true);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Resolve a claimable key before taking its server-authoritative lock.
     *
     * @return array{book_version_id:int|string,layout_profile:string}|null
     */
    private function peekClaimable(?int $versionId, ?string $profile): ?array
    {
        $where = "status = 'pending' OR (status = 'generating' AND lease_expires_at < CURRENT_TIMESTAMP)";
        $params = array();
        if ($versionId !== null) {
            $where = 'book_version_id = ? AND (' . $where . ')';
            $params[] = $versionId;
        }
        if ($profile !== null && $profile !== '') {
            $where = 'layout_profile = ? AND (' . $where . ')';
            array_unshift($params, $profile);
        }
        $stmt = $this->pdo->prepare(
            'SELECT book_version_id, layout_profile
               FROM ipca_publishing_page_map_generation_state
              WHERE ' . $where . '
              ORDER BY updated_at ASC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * MySQL named locks are connection-owned and released on process crash.
     * The lock is intentionally version-wide: production approval metadata has
     * one reader_page_map slot per version, so two profiles must serialize even
     * though their coalescing/lease rows remain profile-specific.
     *
     * @return callable():void|null
     */
    private function acquireServerSingleFlight(int $versionId, string $profile): ?callable
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockName = 'ipca-pagemap-v-' . $versionId;
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute(array($lockName));
            if ((int)$stmt->fetchColumn() !== 1) {
                return null;
            }
            return function () use ($lockName): void {
                try {
                    $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $stmt->execute(array($lockName));
                } catch (Throwable) {
                    // Connection close/crash also releases a MySQL named lock.
                }
            };
        }

        $localKey = spl_object_id($this->pdo) . ':' . $lockName;
        if (isset(self::$localSingleFlightLocks[$localKey])) {
            return null;
        }
        self::$localSingleFlightLocks[$localKey] = true;
        return static function () use ($localKey): void {
            unset(self::$localSingleFlightLocks[$localKey]);
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function leaseExpired(array $row): bool
    {
        $expires = trim((string)($row['lease_expires_at'] ?? ''));
        if ($expires === '') {
            return false;
        }
        return $expires < ($this->clock)()->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function claim(?int $versionId, ?string $profile): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $where = "status = 'pending' OR (status = 'generating' AND lease_expires_at < CURRENT_TIMESTAMP)";
            $params = array();
            if ($versionId !== null) {
                $where = 'book_version_id = ? AND (' . $where . ')';
                $params[] = $versionId;
            }
            if ($profile !== null && $profile !== '') {
                $where = 'layout_profile = ? AND (' . $where . ')';
                array_unshift($params, $profile);
            }
            $lockSuffix = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' FOR UPDATE'
                : '';
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_publishing_page_map_generation_state
                  WHERE ' . $where . '
                  ORDER BY updated_at ASC LIMIT 1' . $lockSuffix
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $expires = ($this->clock)()->modify('+' . $this->leaseSeconds . ' seconds')
                ->format('Y-m-d H:i:s');
            $update = $this->pdo->prepare(
                "UPDATE ipca_publishing_page_map_generation_state
                    SET status = 'generating', lease_token = ?, lease_expires_at = ?,
                        attempt_count = attempt_count + 1, updated_at = CURRENT_TIMESTAMP
                  WHERE book_version_id = ? AND layout_profile = ?
                    AND generation_seq = ?"
            );
            $update->execute(array(
                $token,
                $expires,
                (int)$row['book_version_id'],
                (string)$row['layout_profile'],
                (int)$row['generation_seq'],
            ));
            $this->pdo->commit();
            $row['status'] = self::STATUS_GENERATING;
            $row['lease_token'] = $token;
            $row['lease_expires_at'] = $expires;
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function markStale(
        int $versionId,
        string $profile,
        int $seq,
        string $leaseToken,
        string $message
    ): void {
        $this->finishWithStatus(
            $versionId,
            $profile,
            $seq,
            $leaseToken,
            self::STATUS_STALE,
            'STALE_COMPLETION',
            $message
        );
    }

    private function markFailure(
        int $versionId,
        string $profile,
        int $seq,
        string $leaseToken,
        Throwable $error
    ): void {
        $hasLastValidMap = $this->store->pageCount($versionId, $profile) > 0;
        $status = $hasLastValidMap ? self::STATUS_RETRY_AVAILABLE : self::STATUS_FAILED;
        $code = $error instanceof ControlledPublishingPaginationValidationException
            ? $error->codeName()
            : 'GENERATION_FAILED';
        $this->finishWithStatus(
            $versionId,
            $profile,
            $seq,
            $leaseToken,
            $status,
            $code !== '' ? $code : 'GENERATION_FAILED',
            mb_substr($error->getMessage(), 0, 2000)
        );
    }

    private function finishWithStatus(
        int $versionId,
        string $profile,
        int $seq,
        string $leaseToken,
        string $status,
        string $errorCode,
        string $errorMessage
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ipca_publishing_page_map_generation_state
                SET status = CASE
                        WHEN pending_generation_seq IS NULL THEN ?
                        ELSE ?
                    END,
                    generation_seq = COALESCE(pending_generation_seq, generation_seq),
                    requested_fingerprint_hash = COALESCE(
                        pending_fingerprint_hash, requested_fingerprint_hash
                    ),
                    requested_fingerprint_json = COALESCE(
                        pending_fingerprint_json, requested_fingerprint_json
                    ),
                    requested_by_user_id = COALESCE(
                        pending_requested_by_user_id, requested_by_user_id
                    ),
                    last_error_code = CASE
                        WHEN pending_generation_seq IS NULL THEN ?
                        ELSE NULL
                    END,
                    last_error_message = CASE
                        WHEN pending_generation_seq IS NULL THEN ?
                        ELSE NULL
                    END,
                    lease_token = NULL, lease_expires_at = NULL,
                    pending_generation_seq = NULL,
                    pending_fingerprint_hash = NULL,
                    pending_fingerprint_json = NULL,
                    pending_requested_by_user_id = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE book_version_id = ? AND layout_profile = ?
                AND generation_seq = ? AND lease_token = ?'
        );
        $stmt->execute(array(
            $status,
            self::STATUS_PENDING,
            $errorCode,
            $errorMessage,
            $versionId,
            $profile,
            $seq,
            $leaseToken,
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadState(int $versionId, string $profile, bool $lock = false): ?array
    {
        $suffix = $lock && (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_publishing_page_map_generation_state
              WHERE book_version_id = ? AND layout_profile = ? LIMIT 1' . $suffix
        );
        $stmt->execute(array($versionId, $profile));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $fingerprint
     * @return array<string,mixed>
     */
    private function statusPayload(
        int $versionId,
        string $profile,
        array $row,
        array $fingerprint
    ): array {
        $requested = json_decode((string)($row['requested_fingerprint_json'] ?? '{}'), true);
        return array(
            'book_version_id' => $versionId,
            'layout_profile' => $profile,
            'status' => (string)($row['status'] ?? self::STATUS_STALE),
            'generation_seq' => (int)($row['generation_seq'] ?? 0),
            'pending_generation_seq' => isset($row['pending_generation_seq'])
                ? (int)$row['pending_generation_seq']
                : null,
            'attempt_count' => (int)($row['attempt_count'] ?? 0),
            'requested_fingerprint' => is_array($requested) ? $requested : array(),
            'pending_fingerprint' => is_array(
                $pending = json_decode((string)($row['pending_fingerprint_json'] ?? ''), true)
            ) ? $pending : null,
            'current_fingerprint' => $fingerprint,
            'has_last_valid_map' => $this->store->pageCount($versionId, $profile) > 0,
            'last_error_code' => $row['last_error_code'] ?? null,
            'last_error_message' => $row['last_error_message'] ?? null,
            'lease_expires_at' => $row['lease_expires_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function readFingerprint(array $version, string $profile): array
    {
        $source = ($this->sourceLoader)($version, $profile);
        $fingerprint = ($this->fingerprintLoader)($source, $version, $profile);
        if (!is_array($fingerprint) || $fingerprint === array()) {
            throw new RuntimeException('Authoritative fingerprint is unavailable.');
        }
        return $fingerprint;
    }

    /**
     * @param array<string,mixed> $fingerprint
     */
    private function productionMatches(int $versionId, string $profile, array $fingerprint): bool
    {
        if ($this->store->pageCount($versionId, $profile) <= 0) {
            return false;
        }
        $approval = $this->store->approvalMeta($versionId);
        $generation = is_array($approval['generation'] ?? null)
            ? $approval['generation']
            : array();
        foreach ($fingerprint as $key => $value) {
            if (
                !array_key_exists($key, $generation)
                || !hash_equals((string)$value, (string)$generation[$key])
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,mixed> $fingerprint
     */
    private function claimFingerprintMatches(array $claim, array $fingerprint): bool
    {
        $requested = json_decode((string)($claim['requested_fingerprint_json'] ?? '{}'), true);
        if (!is_array($requested)) {
            return false;
        }
        $currentJson = self::canonicalJson($fingerprint);
        return hash_equals(self::canonicalJson($requested), $currentJson)
            && hash_equals(
                (string)($claim['requested_fingerprint_hash'] ?? ''),
                hash('sha256', $currentJson)
            );
    }

    private function storedFingerprintMatches(
        string $storedHash,
        string $storedJson,
        string $expectedHash,
        string $expectedJson
    ): bool {
        $stored = json_decode($storedJson, true);
        return is_array($stored)
            && hash_equals($storedHash, $expectedHash)
            && hash_equals(self::canonicalJson($stored), $expectedJson);
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @param array<string,mixed> $generation
     * @param array<string,mixed> $expectedFingerprint
     */
    private function validateGeneratedResult(
        array $pages,
        array $generation,
        array $expectedFingerprint
    ): void
    {
        if ($pages === array() || count($pages) !== (int)($generation['page_count'] ?? 0)) {
            throw new RuntimeException('Authoritative generator returned an incomplete page map.');
        }
        foreach ($pages as $index => $page) {
            if (!is_array($page) || (int)($page['page_number'] ?? 0) !== $index + 1) {
                throw new RuntimeException('Authoritative generator returned invalid page ordering.');
            }
        }
        if (empty($generation['validation']['is_valid'])) {
            throw new RuntimeException('Authoritative page validation failed.');
        }
        foreach ($expectedFingerprint as $key => $value) {
            if (
                !array_key_exists($key, $generation)
                || !hash_equals((string)$value, (string)$generation[$key])
            ) {
                throw new RuntimeException('Generated page map fingerprint mismatch: ' . $key . '.');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function requireMutableVersion(int $versionId): array
    {
        $version = ($this->versionLoader)($versionId);
        if (!is_array($version)) {
            throw new RuntimeException('Manual version not found.');
        }
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released authoritative pagination is immutable.');
        }
        return $version;
    }

    private function normalizeProfile(?string $profile): string
    {
        $profile = trim((string)($profile ?? ControlledPublishingReaderLayoutProfile::profileKey()));
        if ($profile === '' || strlen($profile) > 64) {
            throw new RuntimeException('Invalid pagination profile.');
        }
        return $profile;
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function canonicalJson(array $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        return json_encode(
            $sort($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return callable(int,string):void
     */
    private static function defaultSpawner(string $root): callable
    {
        return static function (int $versionId, string $profile) use ($root): void {
            // Drain every pending profile for this version. A worker spawned for
            // another profile may lose the version-wide named-lock race and exit;
            // the lock owner must therefore pick that profile up before it exits.
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($root . '/scripts/controlled_publishing_page_map_worker.php')
                . ' --drain'
                . ' --version-id=' . $versionId
                . ' > /dev/null 2>&1 &';
            exec($command);
        };
    }
}
