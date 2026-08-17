<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationObjectStore.php';
require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationTrainingMediaLibraryService.php';
require_once __DIR__ . '/CommunicationTrainingThumbnailRenderer.php';
require_once __DIR__ . '/CommunicationTrainingVideoAnalyzer.php';

/**
 * Private training-video library. Isolated from messages, Community CDN media,
 * and the communication sync cursor.
 */
final class CommunicationTrainingVideoService
{
    public const GET_EXPIRES = 300;
    public const DOWNLOAD_EXPIRES = 3600;
    public const PUT_EXPIRES = 3600;
    public const MAX_VIDEO_BYTES = 5368709120;
    public const MAX_POSTER_BYTES = 10485760;
    public const MAX_COMMENT = 2000;
    public const PAGE_SIZE = 30;
    public const OPEN_ENDED_UNTIL = '9999-12-31 23:59:59.000';

    private const GRANT_TYPES = array('all', 'users', 'cohorts', 'programs', 'roles');
    private const VIDEO_MIME = array('video/mp4', 'video/quicktime');
    private const POSTER_MIME = array('image/jpeg', 'image/jpg', 'image/png', 'image/webp');

    /** @var array<string,bool> */
    private array $tableCache = array();
    /** @var array<string,bool> */
    private array $columnCache = array();
    private CommunicationTrainingMediaLibraryService $mediaLibrary;
    private CommunicationTrainingThumbnailRenderer $renderer;
    private CommunicationTrainingVideoAnalyzer $analyzer;

    public function __construct(
        private PDO $pdo,
        private CommunicationConfigService $config,
        private CommunicationObjectStore $store,
        ?CommunicationTrainingMediaLibraryService $mediaLibrary = null,
        ?CommunicationTrainingThumbnailRenderer $renderer = null,
        ?CommunicationTrainingVideoAnalyzer $analyzer = null
    ) {
        $this->mediaLibrary = $mediaLibrary ?? new CommunicationTrainingMediaLibraryService($pdo, $store);
        $this->renderer = $renderer ?? new CommunicationTrainingThumbnailRenderer();
        $this->analyzer = $analyzer ?? new CommunicationTrainingVideoAnalyzer($store);
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function feed(array $session, int $cursor = 0): array
    {
        $this->config->requireTrainingVideos();
        $visible = array();
        $scanCursor = max(0, $cursor);
        $batchSize = 100;
        do {
            $sql = "SELECT * FROM ipca_training_videos
                    WHERE status = 'published' AND deleted_at_utc IS NULL AND storage_key IS NOT NULL";
            $args = array();
            if ($scanCursor > 0) {
                $sql .= ' AND id < ?';
                $args[] = $scanCursor;
            }
            $sql .= ' ORDER BY id DESC LIMIT ' . $batchSize;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $scanCursor = (int)$row['id'];
                $until = $this->activeUntil($session['user'], (int)$row['id']);
                if ($until !== null) {
                    $visible[] = $this->publicVideo($row, $session['user'], $until);
                    if (count($visible) > self::PAGE_SIZE) {
                        break 2;
                    }
                }
            }
        } while (count($rows) === $batchSize);
        $hasMore = count($visible) > self::PAGE_SIZE;
        if ($hasMore) {
            $visible = array_slice($visible, 0, self::PAGE_SIZE);
        }
        return array(
            'videos' => $visible,
            'next_cursor' => $hasMore && $visible !== array()
                ? (int)$visible[count($visible) - 1]['id']
                : null,
        );
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function detail(array $session, string $videoUuid): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $until = $this->activeUntil($session['user'], (int)$row['id']);
        return array('video' => $this->publicVideo($row, $session['user'], $until ?? CommunicationSupport::nowUtc()));
    }

    /**
     * Rechecks access immediately before issuing every private media URL.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function playback(array $session, string $videoUuid, bool $download = false): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $storageKey = trim((string)($row['storage_key'] ?? ''));
        if ($storageKey === '') {
            throw new CommunicationException('not_found', 'That video is not available.', 404);
        }
        $until = $this->activeUntil($session['user'], (int)$row['id']);
        if ($until === null) {
            throw new CommunicationException('not_found', 'That video is not available.', 404);
        }
        if (!$download) {
            $this->recordView((int)$row['id'], (int)$session['user']['id']);
            $row = $this->findByUuid($videoUuid) ?? $row;
        }
        $expires = $download ? self::DOWNLOAD_EXPIRES : self::GET_EXPIRES;
        $url = $this->store->presignGet($storageKey, $expires);
        $video = $this->publicVideo($row, $session['user'], $until);
        return array(
            'url' => $url,
            'stream_url' => $url,
            'download_url' => $download ? $url : $this->store->presignGet($storageKey, self::DOWNLOAD_EXPIRES),
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'expires_in' => $expires,
            'available_until' => $until,
            'downloadable' => true,
            'video' => $video,
        );
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function view(array $session, string $videoUuid): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $this->recordView((int)$row['id'], (int)$session['user']['id']);
        $fresh = $this->findByUuid($videoUuid) ?? $row;
        $until = $this->activeUntil($session['user'], (int)$fresh['id']);
        return array('video' => $this->publicVideo($fresh, $session['user'], $until ?? CommunicationSupport::nowUtc()));
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function like(array $session, string $videoUuid): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $userId = (int)$session['user']['id'];
        $existing = $this->pdo->prepare('SELECT id FROM ipca_training_video_likes WHERE video_id = ? AND user_id = ? LIMIT 1');
        $existing->execute(array((int)$row['id'], $userId));
        if ($existing->fetchColumn() === false) {
            try {
                $this->pdo->prepare(
                    'INSERT INTO ipca_training_video_likes (video_id, user_id, created_at_utc) VALUES (?, ?, ?)'
                )->execute(array((int)$row['id'], $userId, CommunicationSupport::nowUtc()));
            } catch (Throwable) {
                // Unique (video, user).
            }
        }
        $fresh = $this->findByUuid($videoUuid) ?? $row;
        $until = $this->activeUntil($session['user'], (int)$fresh['id']);
        return array('video' => $this->publicVideo($fresh, $session['user'], $until ?? CommunicationSupport::nowUtc()));
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function unlike(array $session, string $videoUuid): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $this->pdo->prepare('DELETE FROM ipca_training_video_likes WHERE video_id = ? AND user_id = ?')
            ->execute(array((int)$row['id'], (int)$session['user']['id']));
        $fresh = $this->findByUuid($videoUuid) ?? $row;
        $until = $this->activeUntil($session['user'], (int)$fresh['id']);
        return array('video' => $this->publicVideo($fresh, $session['user'], $until ?? CommunicationSupport::nowUtc()));
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function comments(array $session, string $videoUuid): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_training_video_comments WHERE video_id = ? AND deleted_at_utc IS NULL ORDER BY id ASC'
        );
        $stmt->execute(array((int)$row['id']));
        $comments = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $comment) {
            $comments[] = $this->publicComment($comment);
        }
        return array('comments' => $comments);
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function comment(array $session, string $videoUuid, string $body, ?string $commentUuid = null): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $body = trim($body);
        if ($body === '') {
            throw new CommunicationException('validation_error', 'Write a comment first.', 400);
        }
        if (mb_strlen($body) > self::MAX_COMMENT) {
            throw new CommunicationException('validation_error', 'That comment is too long.', 400);
        }
        if ($commentUuid !== null && $commentUuid !== '') {
            $commentUuid = CommunicationSupport::requireUuid($commentUuid, 'comment_uuid');
            $existing = $this->findComment($commentUuid);
            if ($existing !== null) {
                if ((int)$existing['user_id'] !== (int)$session['user']['id'] || (int)$existing['video_id'] !== (int)$row['id']) {
                    throw new CommunicationException('conflict', 'That comment already exists.', 409);
                }
                return array('comment' => $this->publicComment($existing));
            }
        } else {
            $commentUuid = CommunicationSupport::uuid();
        }
        $this->pdo->prepare(
            'INSERT INTO ipca_training_video_comments (comment_uuid, video_id, user_id, body, created_at_utc)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(array(
            $commentUuid,
            (int)$row['id'],
            (int)$session['user']['id'],
            $body,
            CommunicationSupport::nowUtc(),
        ));
        $saved = $this->findComment($commentUuid);
        return array('comment' => $this->publicComment($saved ?? array(
            'comment_uuid' => $commentUuid,
            'user_id' => (int)$session['user']['id'],
            'body' => $body,
            'created_at_utc' => CommunicationSupport::nowUtc(),
        )));
    }

    /** @return array<string,mixed> */
    public function adminCatalog(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ipca_training_videos WHERE deleted_at_utc IS NULL ORDER BY id DESC'
        );
        $videos = array();
        $published = 0;
        $drafts = 0;
        $views = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $video = $this->adminVideo($row);
            $videos[] = $video;
            if (!empty($video['app_visible'])) {
                $published++;
            } elseif ((string)$video['status'] !== 'archived') {
                $drafts++;
            }
            $views += (int)($video['view_count'] ?? 0);
        }
        return array(
            'videos' => $videos,
            'options' => $this->adminOptions(),
            'categories' => $this->listCategories(false),
            'stats' => array(
                'total' => count($videos),
                'published' => $published,
                'drafts' => $drafts,
                'views' => $views,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function adminDetail(string $videoUuid): array
    {
        $row = $this->requireAdminVideo($videoUuid);
        return array(
            'video' => $this->adminVideo($row),
            'grants' => $this->grantsFor((int)$row['id']),
            'options' => $this->adminOptions(),
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param list<array<string,mixed>> $grants
     * @return array<string,mixed>
     */
    public function saveAdmin(array $input, array $grants, int $adminUserId): array
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new CommunicationException('validation_error', 'Title is required.', 400);
        }
        if (mb_strlen($title) > 255) {
            throw new CommunicationException('validation_error', 'That title is too long.', 400);
        }
        $description = trim((string)($input['description'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'draft')));
        if (!in_array($status, array('draft', 'published', 'archived'), true)) {
            throw new CommunicationException('validation_error', 'Unknown status.', 400);
        }
        $normalizedGrants = $this->normalizeGrants($grants);
        $now = CommunicationSupport::nowUtc();
        $videoUuid = trim((string)($input['video_uuid'] ?? ''));
        $existing = null;
        $titleSource = strtolower(trim((string)($input['title_source'] ?? '')));
        if (!in_array($titleSource, array('custom', 'filename', 'generated'), true)) {
            $titleSource = 'custom';
        }
        if ($videoUuid === '') {
            $videoUuid = CommunicationSupport::uuid();
            $this->pdo->prepare(
                'INSERT INTO ipca_training_videos
                    (video_uuid, title, description, duration_ms, status, created_by_user_id, updated_by_user_id, created_at_utc, updated_at_utc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array(
                $videoUuid,
                $title,
                $description === '' ? null : $description,
                max(0, (int)($input['duration_ms'] ?? 0)),
                $status,
                $adminUserId,
                $adminUserId,
                $now,
                $now,
            ));
        } else {
            $videoUuid = CommunicationSupport::requireUuid($videoUuid, 'video_uuid');
            $existing = $this->requireAdminVideo($videoUuid);
            $this->pdo->prepare(
                'UPDATE ipca_training_videos
                 SET title = ?, description = ?, duration_ms = ?, status = ?, updated_by_user_id = ?, updated_at_utc = ?,
                     archived_at_utc = ?
                 WHERE id = ?'
            )->execute(array(
                $title,
                $description === '' ? null : $description,
                max(0, (int)($input['duration_ms'] ?? $existing['duration_ms'] ?? 0)),
                $status,
                $adminUserId,
                $now,
                $status === 'archived' ? $now : null,
                (int)$existing['id'],
            ));
            if ($title !== trim((string)($existing['title'] ?? ''))) {
                $titleSource = 'custom';
            } elseif (trim((string)($existing['title_source'] ?? '')) !== '') {
                $titleSource = strtolower(trim((string)$existing['title_source']));
            }
        }
        $this->saveOptionalVideoFields($videoUuid, $input, is_array($existing) ? $existing : array());
        $rowForMeta = $this->requireAdminVideo($videoUuid);
        $fields = array('title_source' => $titleSource);
        if (is_array($existing)
            && $description !== trim((string)($existing['description'] ?? ''))
        ) {
            $fields['description_source'] = 'custom';
        }
        $this->updateVideoFields((int)$rowForMeta['id'], $fields);
        $row = $this->requireAdminVideo($videoUuid);
        $this->replaceGrants((int)$row['id'], $normalizedGrants);
        if (trim((string)($row['storage_key'] ?? '')) !== ''
            && strtolower(trim((string)($row['poster_source'] ?? ''))) !== 'custom'
        ) {
            $this->ensureGeneratedThumbnail($videoUuid, 'ensure');
            $row = $this->requireAdminVideo($videoUuid);
        }
        return array(
            'video' => $this->adminVideo($row),
            'grants' => $this->grantsFor((int)$row['id']),
        );
    }

    /** @return array<string,mixed> */
    public function archiveAdmin(string $videoUuid, int $adminUserId): array
    {
        $row = $this->requireAdminVideo($videoUuid);
        return $this->saveAdmin(array(
            'video_uuid' => $videoUuid,
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'aircraft' => (string)($row['aircraft'] ?? ''),
            'program' => (string)($row['program'] ?? ''),
            'status' => 'archived',
        ), $this->grantsFor((int)$row['id']), $adminUserId);
    }

    /** @return array<string,mixed> */
    public function deleteAdmin(string $videoUuid, int $adminUserId): array
    {
        $row = $this->requireAdminVideo($videoUuid);
        $this->pdo->prepare(
            'UPDATE ipca_training_videos SET deleted_at_utc = ?, updated_by_user_id = ?, updated_at_utc = ?, status = ? WHERE id = ?'
        )->execute(array(CommunicationSupport::nowUtc(), $adminUserId, CommunicationSupport::nowUtc(), 'archived', (int)$row['id']));
        return array('deleted' => true, 'video_uuid' => $videoUuid);
    }

    /** @return array<string,mixed> */
    public function presignAdminUpload(string $videoUuid, string $kind, string $mimeType, int $byteSize, string $filename = ''): array
    {
        $row = $this->requireAdminVideo($videoUuid);
        $kind = strtolower(trim($kind));
        $mimeType = strtolower(trim($mimeType));
        if ($kind === 'video') {
            if (!in_array($mimeType, self::VIDEO_MIME, true)) {
                throw new CommunicationException('validation_error', 'Upload an MP4 or QuickTime video.', 400);
            }
            if ($byteSize < 1 || $byteSize > self::MAX_VIDEO_BYTES) {
                throw new CommunicationException('validation_error', 'That video is too large.', 400);
            }
            $key = 'training-videos/1/' . $row['video_uuid'] . '.video';
        } elseif ($kind === 'poster') {
            if (!in_array($mimeType, self::POSTER_MIME, true)) {
                throw new CommunicationException('validation_error', 'Upload a JPEG or PNG poster.', 400);
            }
            if ($byteSize < 1 || $byteSize > self::MAX_POSTER_BYTES) {
                throw new CommunicationException('validation_error', 'That poster is too large.', 400);
            }
            $key = 'training-videos/1/' . $row['video_uuid'] . '.poster';
        } else {
            throw new CommunicationException('validation_error', 'Unknown upload kind.', 400);
        }
        $putUrl = $this->store->presignPut($key, $mimeType, self::PUT_EXPIRES);
        return array(
            'kind' => $kind,
            'filename' => $filename,
            'storage_key' => $key,
            'put_url' => $putUrl,
            'headers' => array('Content-Type' => $mimeType),
            'expires_in' => self::PUT_EXPIRES,
        );
    }

    /**
     * Same-origin admin upload. Streams through the Courseware origin so the
     * browser does not need Spaces CORS.
     *
     * @param resource $stream
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function putAdminObject(
        string $videoUuid,
        string $kind,
        string $mimeType,
        int $byteSize,
        $stream,
        int $durationMs = 0,
        array $options = array()
    ): array {
        $prepared = $this->presignAdminUpload($videoUuid, $kind, $mimeType, $byteSize);
        $key = (string)$prepared['storage_key'];
        $this->store->putStream($key, $stream, $byteSize, strtolower(trim($mimeType)));
        return $this->completeAdminUpload($videoUuid, $kind, $durationMs, $byteSize, $mimeType, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function completeAdminUpload(
        string $videoUuid,
        string $kind,
        int $durationMs = 0,
        int $byteSize = 0,
        string $mimeType = '',
        array $options = array()
    ): array {
        $row = $this->requireAdminVideo($videoUuid);
        $kind = strtolower(trim($kind));
        if ($kind === 'video') {
            $key = 'training-videos/1/' . $row['video_uuid'] . '.video';
        } elseif ($kind === 'poster') {
            $key = 'training-videos/1/' . $row['video_uuid'] . '.poster';
        } else {
            throw new CommunicationException('validation_error', 'Unknown upload kind.', 400);
        }
        $head = $this->store->head($key);
        $contentType = is_array($head) ? trim((string)$head['content_type']) : '';
        if ($contentType === '') {
            $contentType = strtolower(trim($mimeType));
        }
        $storedBytes = is_array($head) ? (int)$head['byte_size'] : 0;
        if ($storedBytes < 1) {
            $storedBytes = max(0, $byteSize);
        }
        if ($contentType === '' || $storedBytes < 1) {
            throw new CommunicationException('validation_error', 'Upload the file before completing.', 400);
        }
        $now = CommunicationSupport::nowUtc();
        if ($kind === 'video') {
            $this->pdo->prepare(
                'UPDATE ipca_training_videos
                 SET storage_key = ?, mime_type = ?, byte_size = ?, duration_ms = ?, updated_at_utc = ?
                 WHERE id = ?'
            )->execute(array(
                $key,
                $contentType,
                $storedBytes,
                max(0, $durationMs),
                $now,
                (int)$row['id'],
            ));
            $row['storage_key'] = $key;
            $geometry = $this->resolveVideoGeometry($row, $options);
            $this->updateVideoFields((int)$row['id'], array(
                'width' => $geometry['width'],
                'height' => $geometry['height'],
                'orientation' => $geometry['orientation'],
            ));
            if (strtolower(trim((string)($row['poster_source'] ?? ''))) !== 'custom') {
                $this->ensureGeneratedThumbnail($videoUuid, 'ensure');
            }
        } else {
            $this->pdo->prepare(
                'UPDATE ipca_training_videos
                 SET poster_storage_key = ?, poster_mime_type = ?, updated_at_utc = ?
                 WHERE id = ?'
            )->execute(array(
                $key,
                $contentType,
                $now,
                (int)$row['id'],
            ));
            $this->updateVideoFields((int)$row['id'], array(
                'poster_source' => 'custom',
                'poster_template' => null,
                'poster_library_asset_id' => null,
                'poster_candidate_json' => null,
                'poster_candidate_index' => 0,
            ));
        }
        return array('video' => $this->adminVideo($this->requireAdminVideo($videoUuid)));
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureGeneratedThumbnail(string $videoUuid, string $mode = 'ensure'): array
    {
        try {
            $this->renderGeneratedThumbnail($videoUuid, $mode, '');
        } catch (Throwable) {
            // Video upload/save must not fail if thumbnail rendering is unavailable.
        }
        return array('video' => $this->adminVideo($this->requireAdminVideo($videoUuid)));
    }

    /**
     * @return array<string,mixed>
     */
    public function regenerateAdminThumbnail(string $videoUuid): array
    {
        @set_time_limit(180);
        $this->renderGeneratedThumbnail($videoUuid, 'regenerate', '');
        $row = $this->requireAdminVideo($videoUuid);
        return array(
            'video' => $this->adminVideo($row),
            'grants' => $this->grantsFor((int)$row['id']),
        );
    }

    /**
     * Re-draw every non-custom poster with the current locked overlay and logo.
     *
     * @return array{regenerated:int,skipped:int}
     */
    public function regenerateGeneratedThumbnails(): array
    {
        @set_time_limit(0);
        $stmt = $this->pdo->query(
            "SELECT video_uuid, poster_source FROM ipca_training_videos
             WHERE deleted_at_utc IS NULL AND storage_key IS NOT NULL AND storage_key != ''"
        );
        $regenerated = 0;
        $skipped = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strtolower(trim((string)($row['poster_source'] ?? ''))) === 'custom') {
                $skipped++;
                continue;
            }
            try {
                $this->renderGeneratedThumbnail((string)$row['video_uuid'], 'refresh', '');
                $regenerated++;
            } catch (Throwable) {
                $skipped++;
            }
        }
        return array('regenerated' => $regenerated, 'skipped' => $skipped);
    }

    /**
     * @return array<string,mixed>
     */
    public function chooseAdminThumbnailAsset(string $videoUuid, string $assetUuid): array
    {
        $this->renderGeneratedThumbnail($videoUuid, 'choose', $assetUuid);
        $row = $this->requireAdminVideo($videoUuid);
        return array(
            'video' => $this->adminVideo($row),
            'grants' => $this->grantsFor((int)$row['id']),
        );
    }

    /**
     * Same-origin admin preview. The browser must not load private Spaces URLs
     * with extra cache-buster query parameters; those break SigV4 signatures.
     *
     * @return array{bytes:string,mime_type:string}
     */
    public function adminPosterBytes(string $videoUuid): array
    {
        $row = $this->requireAdminVideo($videoUuid);
        $key = trim((string)($row['poster_storage_key'] ?? ''));
        if ($key === '') {
            throw new CommunicationException('not_found', 'That thumbnail is not ready yet.', 404);
        }
        $bytes = $this->store->getBytes($key);
        if (!is_string($bytes) || $bytes === '') {
            throw new CommunicationException('not_found', 'That thumbnail is not ready yet.', 404);
        }
        $mime = strtolower(trim((string)($row['poster_mime_type'] ?? 'image/jpeg')));
        if ($mime === '' || $mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }
        return array('bytes' => $bytes, 'mime_type' => $mime);
    }

    /**
     * Analyze the uploaded video and write a short "what you'll learn" description.
     *
     * @return array<string,mixed>
     */
    public function generateAdminExplanation(string $videoUuid, bool $force = false): array
    {
        @set_time_limit(180);
        $row = $this->requireAdminVideo($videoUuid);
        if (trim((string)($row['storage_key'] ?? '')) === '') {
            throw new CommunicationException('validation_error', 'Upload the video before writing the explanation.', 400);
        }
        $existing = trim((string)($row['description'] ?? ''));
        $source = strtolower(trim((string)($row['description_source'] ?? '')));
        if (!$force && $existing !== '' && $source === 'custom') {
            return array(
                'video' => $this->adminVideo($row),
                'grants' => $this->grantsFor((int)$row['id']),
            );
        }
        $result = $this->analyzer->analyze($row, $this->listCategories(true));
        $explanation = trim((string)($result['explanation'] ?? ''));
        if ($explanation === '') {
            throw new CommunicationException('server_error', 'The video explanation could not be written.', 500);
        }
        $titleSource = strtolower(trim((string)($row['title_source'] ?? '')));
        $canWriteTitle = $force || in_array($titleSource, array('', 'filename', 'generated'), true);
        $canWriteCategory = (int)($row['category_id'] ?? 0) < 1
            || strtolower(trim((string)($row['category'] ?? ''))) === ''
            || strtolower(trim((string)($row['category'] ?? ''))) === 'uncategorized';
        $this->pdo->prepare(
            'UPDATE ipca_training_videos SET description = ?, updated_at_utc = ? WHERE id = ?'
        )->execute(array($explanation, CommunicationSupport::nowUtc(), (int)$row['id']));
        $fields = array('description_source' => 'generated');
        $newTitle = trim((string)($result['title'] ?? ''));
        if ($canWriteTitle && $newTitle !== '') {
            $this->pdo->prepare(
                'UPDATE ipca_training_videos SET title = ?, updated_at_utc = ? WHERE id = ?'
            )->execute(array($newTitle, CommunicationSupport::nowUtc(), (int)$row['id']));
            $fields['title_source'] = 'generated';
        }
        if ($canWriteCategory) {
            $resolved = $this->categoryBySlug((string)($result['category_slug'] ?? 'uncategorized'));
            if ($resolved !== null) {
                $fields['category_id'] = (int)$resolved['id'];
                $fields['category'] = (string)$resolved['name'];
            }
        }
        $this->updateVideoFields((int)$row['id'], $fields);
        $fresh = $this->requireAdminVideo($videoUuid);
        if (strtolower(trim((string)($fresh['poster_source'] ?? ''))) !== 'custom') {
            $this->ensureGeneratedThumbnail($videoUuid, 'refresh');
            $fresh = $this->requireAdminVideo($videoUuid);
        }
        return array(
            'video' => $this->adminVideo($fresh),
            'grants' => $this->grantsFor((int)$fresh['id']),
            'used_video' => !empty($result['used_video']),
            'used_ai' => !empty($result['used_ai']),
        );
    }

    /**
     * Member watch progress. Isolated from the messaging outbox.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function progress(array $session, string $videoUuid, int $positionMs, int $durationMs = 0): array
    {
        $this->config->requireTrainingVideos();
        $row = $this->requireAccessible($session, $videoUuid);
        $this->recordProgress(
            (int)$row['id'],
            (int)$session['user']['id'],
            $positionMs,
            $durationMs > 0 ? $durationMs : (int)$row['duration_ms']
        );
        $fresh = $this->findByUuid($videoUuid) ?? $row;
        $until = $this->activeUntil($session['user'], (int)$fresh['id']);
        return array('video' => $this->publicVideo($fresh, $session['user'], $until ?? CommunicationSupport::nowUtc()));
    }

    /**
     * Same-origin admin playback. The browser must not load private Spaces URLs
     * in a video element (Range + CORS). The play endpoint proxies this privately.
     *
     * @return array{url:string,mime_type:string,byte_size:int}
     */
    public function adminVideoOrigin(string $videoUuid): array
    {
        $row = $this->requireAdminVideo($videoUuid);
        $key = trim((string)($row['storage_key'] ?? ''));
        if ($key === '') {
            throw new CommunicationException('not_found', 'That video is not on the server yet.', 404);
        }
        return array(
            'url' => $this->store->presignGet($key, 3600),
            'mime_type' => (string)($row['mime_type'] ?: 'video/mp4'),
            'byte_size' => (int)$row['byte_size'],
        );
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private function requireAccessible(array $session, string $videoUuid): array
    {
        $videoUuid = CommunicationSupport::requireUuid($videoUuid, 'video_uuid');
        $row = $this->findByUuid($videoUuid);
        if ($row === null
            || (string)$row['status'] !== 'published'
            || trim((string)($row['deleted_at_utc'] ?? '')) !== ''
            || $this->activeUntil($session['user'], (int)$row['id']) === null
        ) {
            throw new CommunicationException('not_found', 'That video is not available.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireAdminVideo(string $videoUuid): array
    {
        $videoUuid = CommunicationSupport::requireUuid($videoUuid, 'video_uuid');
        $row = $this->findByUuid($videoUuid, true);
        if ($row === null) {
            throw new CommunicationException('not_found', 'That video was not found.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findByUuid(string $videoUuid, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT * FROM ipca_training_videos WHERE video_uuid = ?';
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at_utc IS NULL';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array($videoUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $user */
    private function activeUntil(array $user, int $videoId): ?string
    {
        if (!$this->categoryEntitlementAllows($user, $videoId)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_training_video_grants WHERE video_id = ?');
        $stmt->execute(array($videoId));
        $now = CommunicationSupport::nowUtc();
        $latest = null;
        $context = $this->userGrantContext($user);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $grant) {
            if (!$this->windowActive((string)($grant['available_from_utc'] ?? ''), (string)($grant['available_until_utc'] ?? ''), $now)) {
                continue;
            }
            if (!$this->grantMatches($grant, $context)) {
                continue;
            }
            $until = $this->effectiveUntil((string)($grant['available_until_utc'] ?? ''));
            if ($latest === null || $until > $latest) {
                $latest = $until;
            }
        }
        return $latest;
    }

    private function windowActive(string $from, string $until, string $now): bool
    {
        $from = trim($from);
        $until = trim($until);
        if ($from !== '' && $from > $now) {
            return false;
        }
        if ($until !== '' && $until < $now) {
            return false;
        }
        return true;
    }

    private function effectiveUntil(string $until): string
    {
        $until = trim($until);
        return $until === '' ? self::OPEN_ENDED_UNTIL : $until;
    }

    private function publicUntil(string $until): string
    {
        $until = trim($until);
        if ($until === '' || str_starts_with($until, '9999-')) {
            return '';
        }
        return $until;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function publicOrientation(array $row): string
    {
        $orientation = strtolower(trim((string)($row['orientation'] ?? '')));
        if ($orientation === 'portrait' || $orientation === 'landscape') {
            return $orientation;
        }
        return CommunicationTrainingThumbnailRenderer::videoOrientation(
            (int)($row['width'] ?? 0),
            (int)($row['height'] ?? 0)
        ) ?: 'landscape';
    }

    /**
     * Prefer ffprobe of the stored file, including rotation metadata.
     * Browser videoWidth/videoHeight is only a fallback.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $options
     * @return array{width:int,height:int,orientation:string}
     */
    private function resolveVideoGeometry(array $row, array $options = array()): array
    {
        $width = max(0, (int)($options['width'] ?? $row['width'] ?? 0));
        $height = max(0, (int)($options['height'] ?? $row['height'] ?? 0));
        $probed = $this->analyzer->probeGeometry((string)($row['storage_key'] ?? ''));
        if (is_array($probed) && ($probed['width'] > 0 || $probed['height'] > 0)) {
            $width = $probed['width'];
            $height = $probed['height'];
        }
        return array(
            'width' => $width,
            'height' => $height,
            'orientation' => CommunicationTrainingThumbnailRenderer::videoOrientation($width, $height) ?: 'landscape',
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function posterRevision(array $row): string
    {
        return substr(sha1(implode('|', array(
            (string)($row['updated_at_utc'] ?? ''),
            (string)($row['poster_template'] ?? ''),
            (string)($row['poster_library_asset_id'] ?? ''),
            (string)($row['poster_candidate_index'] ?? '0'),
        ))), 0, 12);
    }

    /**
     * Users with no category entitlement rows keep video-grant behavior.
     * Once any row exists, the video's category must also be currently entitled.
     *
     * @param array<string,mixed> $user
     */
    private function categoryEntitlementAllows(array $user, int $videoId): bool
    {
        if (!$this->tableExists('ipca_training_video_category_entitlements')) {
            return true;
        }
        $userId = (int)($user['id'] ?? 0);
        if ($userId < 1) {
            return false;
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_training_video_category_entitlements WHERE user_id = ?'
        );
        $count->execute(array($userId));
        if ((int)$count->fetchColumn() === 0) {
            return true;
        }
        $video = $this->pdo->prepare('SELECT category_id, category FROM ipca_training_videos WHERE id = ? LIMIT 1');
        $video->execute(array($videoId));
        $row = $video->fetch(PDO::FETCH_ASSOC);
        $categoryId = is_array($row) ? (int)($row['category_id'] ?? 0) : 0;
        if ($categoryId < 1) {
            $uncategorized = $this->categoryBySlug('uncategorized');
            $categoryId = $uncategorized !== null ? (int)$uncategorized['id'] : 0;
        }
        if ($categoryId < 1) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT available_from_utc, available_until_utc
             FROM ipca_training_video_category_entitlements
             WHERE user_id = ? AND category_id = ?'
        );
        $stmt->execute(array($userId, $categoryId));
        $now = CommunicationSupport::nowUtc();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entitlement) {
            if ($this->windowActive((string)($entitlement['available_from_utc'] ?? ''), (string)($entitlement['available_until_utc'] ?? ''), $now)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $grant
     * @param array{user_id:int,role:string,cohort_ids:list<int>,program_ids:list<int>} $context
     */
    private function grantMatches(array $grant, array $context): bool
    {
        $type = strtolower(trim((string)$grant['grant_type']));
        $value = trim((string)$grant['grant_value']);
        return match ($type) {
            'all' => true,
            'users' => (string)$context['user_id'] === $value,
            'roles' => $context['role'] === strtolower($value),
            'cohorts' => in_array((int)$value, $context['cohort_ids'], true),
            'programs' => in_array((int)$value, $context['program_ids'], true),
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $user
     * @return array{user_id:int,role:string,cohort_ids:list<int>,program_ids:list<int>}
     */
    private function userGrantContext(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $role = strtolower(trim((string)($user['role'] ?? '')));
        $cohortIds = array();
        $programIds = array();
        if ($userId > 0 && $this->tableExists('cohort_students')) {
            try {
                $stmt = $this->pdo->prepare('SELECT cohort_id FROM cohort_students WHERE user_id = ?');
                $stmt->execute(array($userId));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cohortIds[] = (int)$row['cohort_id'];
                }
            } catch (Throwable) {
                $cohortIds = array();
            }
        }
        if ($cohortIds !== array() && $this->tableExists('cohorts') && $this->tableExists('courses')) {
            try {
                $placeholders = implode(',', array_fill(0, count($cohortIds), '?'));
                $sql = "SELECT DISTINCT c.program_id
                        FROM cohorts co
                        INNER JOIN courses c ON c.id = co.course_id
                        WHERE co.id IN ({$placeholders}) AND c.program_id IS NOT NULL";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($cohortIds);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $programIds[] = (int)$row['program_id'];
                }
            } catch (Throwable) {
                $programIds = array();
            }
        }
        return array(
            'user_id' => $userId,
            'role' => $role,
            'cohort_ids' => array_values(array_unique($cohortIds)),
            'program_ids' => array_values(array_unique($programIds)),
        );
    }

    /**
     * @param list<array<string,mixed>> $grants
     * @return list<array{grant_type:string,grant_value:string,available_from_utc:?string,available_until_utc:?string}>
     */
    private function normalizeGrants(array $grants): array
    {
        $normalized = array();
        foreach ($grants as $grant) {
            if (!is_array($grant)) {
                continue;
            }
            $type = strtolower(trim((string)($grant['grant_type'] ?? '')));
            if (!in_array($type, self::GRANT_TYPES, true)) {
                throw new CommunicationException('validation_error', 'Unknown access type.', 400);
            }
            $value = trim((string)($grant['grant_value'] ?? ''));
            if ($type === 'all') {
                $value = '';
            } elseif ($value === '') {
                throw new CommunicationException('validation_error', 'Each grant needs a person, cohort, program, or role.', 400);
            }
            $from = $this->optionalUtc((string)($grant['available_from_utc'] ?? ''));
            $until = $this->optionalUtc((string)($grant['available_until_utc'] ?? ''));
            if ($from !== null && $until !== null && $until <= $from) {
                throw new CommunicationException('validation_error', 'Access must end after it starts.', 400);
            }
            $normalized[] = array(
                'grant_type' => $type,
                'grant_value' => $value,
                'available_from_utc' => $from,
                'available_until_utc' => $until,
            );
        }
        return $normalized;
    }

    /**
     * @param list<array{grant_type:string,grant_value:string,available_from_utc:?string,available_until_utc:?string}> $grants
     */
    private function replaceGrants(int $videoId, array $grants): void
    {
        $this->pdo->prepare('DELETE FROM ipca_training_video_grants WHERE video_id = ?')->execute(array($videoId));
        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_training_video_grants
                (video_id, grant_type, grant_value, available_from_utc, available_until_utc, created_at_utc)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $now = CommunicationSupport::nowUtc();
        foreach ($grants as $grant) {
            $insert->execute(array(
                $videoId,
                $grant['grant_type'],
                $grant['grant_value'],
                $grant['available_from_utc'],
                $grant['available_until_utc'],
                $now,
            ));
        }
    }

    private function optionalUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return $this->requireUtc($value, 'access_time');
    }

    private function requireUtc(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new CommunicationException('validation_error', 'Access times must be valid dates.', 400);
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new CommunicationException('validation_error', 'Access times must be valid dates.', 400);
        }
        unset($field);
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.000');
    }

    private function recordView(int $videoId, int $userId): void
    {
        $now = CommunicationSupport::nowUtc();
        $existing = $this->pdo->prepare('SELECT id FROM ipca_training_video_views WHERE video_id = ? AND user_id = ? LIMIT 1');
        $existing->execute(array($videoId, $userId));
        if ($existing->fetchColumn() === false) {
            try {
                $this->pdo->prepare(
                    'INSERT INTO ipca_training_video_views (video_id, user_id, first_viewed_at_utc, last_viewed_at_utc)
                     VALUES (?, ?, ?, ?)'
                )->execute(array($videoId, $userId, $now, $now));
                return;
            } catch (Throwable) {
                // Unique race.
            }
        }
        $this->pdo->prepare(
            'UPDATE ipca_training_video_views SET last_viewed_at_utc = ? WHERE video_id = ? AND user_id = ?'
        )->execute(array($now, $videoId, $userId));
    }

    private function recordProgress(int $videoId, int $userId, int $positionMs, int $durationMs): void
    {
        $this->recordView($videoId, $userId);
        if (!$this->hasColumn('ipca_training_video_views', 'position_ms')) {
            return;
        }
        $durationMs = max(0, $durationMs);
        $positionMs = max(0, $positionMs);
        if ($durationMs > 0) {
            $positionMs = min($positionMs, $durationMs);
        }
        $stmt = $this->pdo->prepare(
            'SELECT position_ms, max_position_ms, progress_percent, completed_at_utc
             FROM ipca_training_video_views WHERE video_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute(array($videoId, $userId));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            return;
        }
        $maxPosition = max((int)($existing['max_position_ms'] ?? 0), $positionMs);
        $percent = 0;
        if ($durationMs > 0) {
            $percent = (int)min(100, (int)round(($maxPosition * 100) / $durationMs));
        }
        $completedAt = trim((string)($existing['completed_at_utc'] ?? ''));
        $now = CommunicationSupport::nowUtc();
        if ($percent >= 95 && $completedAt === '') {
            $completedAt = $now;
            $percent = 100;
        } elseif ($completedAt !== '') {
            $percent = 100;
        }
        $this->pdo->prepare(
            'UPDATE ipca_training_video_views
             SET position_ms = ?, max_position_ms = ?, progress_percent = ?, completed_at_utc = ?,
                 last_viewed_at_utc = ?, updated_at_utc = ?
             WHERE video_id = ? AND user_id = ?'
        )->execute(array(
            $positionMs,
            $maxPosition,
            $percent,
            $completedAt === '' ? null : $completedAt,
            $now,
            $now,
            $videoId,
            $userId,
        ));
    }

    /**
     * @return array{position_ms:int,progress_percent:int,completed:bool}
     */
    private function watchState(int $videoId, int $userId): array
    {
        $empty = array('position_ms' => 0, 'progress_percent' => 0, 'completed' => false);
        if ($userId < 1 || !$this->hasColumn('ipca_training_video_views', 'progress_percent')) {
            return $empty;
        }
        $stmt = $this->pdo->prepare(
            'SELECT position_ms, progress_percent, completed_at_utc
             FROM ipca_training_video_views WHERE video_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute(array($videoId, $userId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $empty;
        }
        $completed = trim((string)($row['completed_at_utc'] ?? '')) !== '' || (int)($row['progress_percent'] ?? 0) >= 95;
        return array(
            'position_ms' => (int)($row['position_ms'] ?? 0),
            'progress_percent' => $completed ? 100 : (int)($row['progress_percent'] ?? 0),
            'completed' => $completed,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function publicVideo(array $row, array $user, string $availableUntil): array
    {
        $videoId = (int)$row['id'];
        $userId = (int)($user['id'] ?? 0);
        $posterKey = trim((string)($row['poster_storage_key'] ?? ''));
        $liked = false;
        if ($userId > 0) {
            $like = $this->pdo->prepare('SELECT id FROM ipca_training_video_likes WHERE video_id = ? AND user_id = ? LIMIT 1');
            $like->execute(array($videoId, $userId));
            $liked = $like->fetchColumn() !== false;
        }
        $watch = $this->watchState($videoId, $userId);
        $category = $this->categoryPayload($row);
        return array(
            'id' => $videoId,
            'video_uuid' => (string)$row['video_uuid'],
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)$category['name'],
            'category_slug' => (string)$category['slug'],
            'duration_ms' => (int)$row['duration_ms'],
            'duration_seconds' => (int)round(((int)$row['duration_ms']) / 1000),
            'byte_size' => (int)$row['byte_size'],
            'mime_type' => (string)$row['mime_type'],
            'poster_url' => $posterKey !== '' ? $this->store->presignGet($posterKey, self::GET_EXPIRES) : '',
            'view_count' => $this->countViews($videoId),
            'like_count' => $this->countLikes($videoId),
            'liked' => $liked,
            'comment_count' => $this->countComments($videoId),
            'downloadable' => trim((string)($row['storage_key'] ?? '')) !== '',
            'available_until' => $this->publicUntil($availableUntil),
            'created_at_utc' => (string)$row['created_at_utc'],
            'orientation' => $this->publicOrientation($row),
            'watch_percent' => (int)$watch['progress_percent'],
            'watch_completed' => !empty($watch['completed']),
            'resume_position_ms' => !empty($watch['completed']) ? 0 : (int)$watch['position_ms'],
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function adminVideo(array $row): array
    {
        $posterKey = trim((string)($row['poster_storage_key'] ?? ''));
        $videoKey = trim((string)($row['storage_key'] ?? ''));
        $source = strtolower(trim((string)($row['poster_source'] ?? '')));
        $sourceLabel = match ($source) {
            'custom' => 'Custom upload',
            'media_library' => 'IPCA Media Library',
            'ai_generated' => 'AI Generated',
            'branded_fallback' => 'IPCA template',
            default => $posterKey !== '' ? 'Custom upload' : '',
        };
        $category = $this->categoryPayload($row);
        return array(
            'id' => (int)$row['id'],
            'video_uuid' => (string)$row['video_uuid'],
            'title' => (string)$row['title'],
            'title_source' => (string)($row['title_source'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'description_source' => (string)($row['description_source'] ?? ''),
            'category' => (string)$category['name'],
            'category_id' => (int)$category['id'],
            'category_slug' => (string)$category['slug'],
            'aircraft' => (string)($row['aircraft'] ?? ''),
            'program' => (string)($row['program'] ?? ''),
            'status' => (string)$row['status'],
            'duration_ms' => (int)$row['duration_ms'],
            'byte_size' => (int)$row['byte_size'],
            'width' => (int)($row['width'] ?? 0),
            'height' => (int)($row['height'] ?? 0),
            'orientation' => $this->publicOrientation($row),
            'mime_type' => (string)$row['mime_type'],
            'has_video' => $videoKey !== '',
            'has_poster' => $posterKey !== '',
            'app_visible' => $videoKey !== '' && (string)$row['status'] === 'published',
            'poster_url' => $posterKey !== '' ? $this->store->presignGet($posterKey, self::GET_EXPIRES) : '',
            'poster_preview_url' => $posterKey !== ''
                ? '/admin/api/training_videos_preview.php?video_uuid=' . rawurlencode((string)$row['video_uuid'])
                    . '&v=' . rawurlencode($this->posterRevision($row))
                : '',
            'video_play_url' => $videoKey !== ''
                ? '/admin/api/training_videos_play.php?video_uuid=' . rawurlencode((string)$row['video_uuid'])
                : '',
            'poster_source' => $source,
            'poster_source_label' => $sourceLabel,
            'poster_template' => (string)($row['poster_template'] ?? ''),
            'thumbnail_candidates' => $this->thumbnailCandidates($row),
            'thumbnail_candidate_index' => (int)($row['poster_candidate_index'] ?? 0),
            'view_count' => $this->countViews((int)$row['id']),
            'completion_count' => $this->countCompletions((int)$row['id']),
            'like_count' => $this->countLikes((int)$row['id']),
            'comment_count' => $this->countComments((int)$row['id']),
            'created_at_utc' => (string)$row['created_at_utc'],
            'updated_at_utc' => (string)$row['updated_at_utc'],
        );
    }

    /** @return list<array<string,mixed>> */
    private function grantsFor(int $videoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT grant_type, grant_value, available_from_utc, available_until_utc
             FROM ipca_training_video_grants WHERE video_id = ? ORDER BY id ASC'
        );
        $stmt->execute(array($videoId));
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $out[] = array(
                'grant_type' => (string)$row['grant_type'],
                'grant_value' => (string)$row['grant_value'],
                'available_from_utc' => (string)($row['available_from_utc'] ?? ''),
                'available_until_utc' => (string)($row['available_until_utc'] ?? ''),
            );
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function adminOptions(): array
    {
        $users = array();
        try {
            $rows = $this->pdo->query(
                "SELECT id, name, email, role FROM users WHERE status = 'active' ORDER BY name ASC LIMIT 400"
            );
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $users[] = array(
                    'id' => (string)$row['id'],
                    'name' => (string)$row['name'],
                    'email' => (string)$row['email'],
                    'role' => (string)$row['role'],
                );
            }
        } catch (Throwable) {
            $users = array();
        }
        $cohorts = array();
        if ($this->tableExists('cohorts')) {
            try {
                $rows = $this->pdo->query('SELECT id, name FROM cohorts ORDER BY id DESC LIMIT 200');
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cohorts[] = array('id' => (string)$row['id'], 'name' => (string)$row['name']);
                }
            } catch (Throwable) {
                $cohorts = array();
            }
        }
        $programs = array();
        if ($this->tableExists('programs')) {
            try {
                $rows = $this->pdo->query('SELECT id, program_key FROM programs ORDER BY id ASC LIMIT 200');
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = (string)$row['program_key'];
                    $programs[] = array(
                        'id' => (string)$row['id'],
                        'name' => $key !== '' ? ucwords(str_replace(array('-', '_'), ' ', $key)) : ('Program ' . $row['id']),
                    );
                }
            } catch (Throwable) {
                $programs = array();
            }
        }
        return array(
            'users' => $users,
            'cohorts' => $cohorts,
            'programs' => $programs,
            'roles' => array(
                array('id' => 'student', 'name' => 'Student'),
                array('id' => 'instructor', 'name' => 'Instructor'),
                array('id' => 'supervisor', 'name' => 'Supervisor'),
                array('id' => 'chief_instructor', 'name' => 'Chief Instructor'),
                array('id' => 'admin', 'name' => 'Admin'),
            ),
        );
    }

    /**
     * @param array<string,mixed> $comment
     * @return array<string,mixed>
     */
    private function publicComment(array $comment): array
    {
        $author = array(
            'id' => (int)($comment['user_id'] ?? 0),
            'uuid' => '',
            'email' => '',
            'name' => 'IPCA member',
            'first_name' => '',
            'last_name' => '',
            'role' => '',
            'photo_path' => '',
        );
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, uuid, email, name, first_name, last_name, role, photo_path FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute(array((int)($comment['user_id'] ?? 0)));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $author = array(
                    'id' => (int)$row['id'],
                    'uuid' => (string)$row['uuid'],
                    'email' => (string)$row['email'],
                    'name' => (string)$row['name'],
                    'first_name' => (string)$row['first_name'],
                    'last_name' => (string)$row['last_name'],
                    'role' => (string)$row['role'],
                    'photo_path' => (string)($row['photo_path'] ?? ''),
                );
            }
        } catch (Throwable) {
        }
        return array(
            'comment_uuid' => (string)$comment['comment_uuid'],
            'body' => (string)$comment['body'],
            'created_at_utc' => (string)$comment['created_at_utc'],
            'author' => $author,
        );
    }

    /** @return array<string,mixed>|null */
    private function findComment(string $commentUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_training_video_comments WHERE comment_uuid = ? LIMIT 1');
        $stmt->execute(array($commentUuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function countViews(int $videoId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_training_video_views WHERE video_id = ?');
        $stmt->execute(array($videoId));
        return (int)$stmt->fetchColumn();
    }

    private function countLikes(int $videoId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_training_video_likes WHERE video_id = ?');
        $stmt->execute(array($videoId));
        return (int)$stmt->fetchColumn();
    }

    private function countComments(int $videoId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ipca_training_video_comments WHERE video_id = ? AND deleted_at_utc IS NULL');
        $stmt->execute(array($videoId));
        return (int)$stmt->fetchColumn();
    }

    private function tableExists(string $name): bool
    {
        if (array_key_exists($name, $this->tableCache)) {
            return $this->tableCache[$name];
        }
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            return false;
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute(array($name));
                $exists = $stmt->fetchColumn() !== false;
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                );
                $stmt->execute(array($name));
                $exists = $stmt->fetchColumn() !== false;
            }
        } catch (Throwable) {
            $exists = false;
        }
        $this->tableCache[$name] = $exists;
        return $exists;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $existing
     */
    private function saveOptionalVideoFields(string $videoUuid, array $input, array $existing): void
    {
        $row = $this->requireAdminVideo($videoUuid);
        $fields = array();
        foreach (array('category', 'aircraft', 'program') as $column) {
            if (array_key_exists($column, $input)) {
                $value = trim((string)$input[$column]);
            } else {
                $value = trim((string)($existing[$column] ?? ''));
            }
            $fields[$column] = $value === '' ? null : mb_substr($value, 0, 128);
        }
        $resolved = $this->resolveCategory(
            isset($input['category_id']) ? (int)$input['category_id'] : (int)($existing['category_id'] ?? 0),
            (string)($fields['category'] ?? '')
        );
        if ($resolved !== null) {
            $fields['category_id'] = (int)$resolved['id'];
            $fields['category'] = (string)$resolved['name'];
        } elseif (array_key_exists('category_id', $input) && (int)$input['category_id'] === 0) {
            $fields['category_id'] = null;
        }
        $this->updateVideoFields((int)$row['id'], $fields);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function updateVideoFields(int $videoId, array $fields): void
    {
        $sets = array();
        $args = array();
        foreach ($fields as $column => $value) {
            if (!$this->hasVideoColumn($column)) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $args[] = $value;
        }
        if ($sets === array()) {
            return;
        }
        $sets[] = 'updated_at_utc = ?';
        $args[] = CommunicationSupport::nowUtc();
        $args[] = $videoId;
        $this->pdo->prepare(
            'UPDATE ipca_training_videos SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($args);
    }

    private function hasVideoColumn(string $column): bool
    {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }
        $ok = false;
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->query('PRAGMA table_info(ipca_training_videos)');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ((string)($row['name'] ?? '') === $column) {
                        $ok = true;
                        break;
                    }
                }
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $stmt->execute(array('ipca_training_videos', $column));
                $ok = $stmt->fetchColumn() !== false;
            }
        } catch (Throwable) {
            $ok = false;
        }
        $this->columnCache[$column] = $ok;
        return $ok;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }
        $ok = false;
        if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) {
            $this->columnCache[$cacheKey] = false;
            return false;
        }
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ((string)($row['name'] ?? '') === $column) {
                        $ok = true;
                        break;
                    }
                }
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $stmt->execute(array($table, $column));
                $ok = $stmt->fetchColumn() !== false;
            }
        } catch (Throwable) {
            $ok = false;
        }
        $this->columnCache[$cacheKey] = $ok;
        return $ok;
    }

    /**
     * @return list<array{id:int,slug:string,name:string,sort_order:int,is_active:bool}>
     */
    public function listCategories(bool $activeOnly = true): array
    {
        if (!$this->tableExists('ipca_training_video_categories')) {
            return array();
        }
        $sql = 'SELECT id, slug, name, sort_order, is_active FROM ipca_training_video_categories';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $out = array();
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = array(
                'id' => (int)$row['id'],
                'slug' => (string)$row['slug'],
                'name' => (string)$row['name'],
                'sort_order' => (int)$row['sort_order'],
                'is_active' => (int)$row['is_active'] === 1,
            );
        }
        return $out;
    }

    /**
     * @return array<int,list<array<string,mixed>>>
     */
    public function categoryEntitlementsByUser(): array
    {
        if (!$this->tableExists('ipca_training_video_category_entitlements')) {
            return array();
        }
        $stmt = $this->pdo->query(
            'SELECT user_id, category_id, available_from_utc, available_until_utc
             FROM ipca_training_video_category_entitlements
             ORDER BY user_id ASC, category_id ASC'
        );
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int)$row['user_id'];
            $out[$userId][] = array(
                'category_id' => (int)$row['category_id'],
                'available_from_utc' => (string)($row['available_from_utc'] ?? ''),
                'available_until_utc' => (string)($row['available_until_utc'] ?? ''),
            );
        }
        return $out;
    }

    /**
     * @param list<int> $userIds
     * @param list<int> $categoryIds
     * @return array<string,mixed>
     */
    public function grantCategoryEntitlements(
        array $userIds,
        array $categoryIds,
        string $availableFromUtc = '',
        string $availableUntilUtc = ''
    ): array {
        $this->requireEntitlementsTable();
        $from = $this->optionalUtc($availableFromUtc);
        $until = $this->optionalUtc($availableUntilUtc);
        if ($from !== null && $until !== null && $until <= $from) {
            throw new CommunicationException('validation_error', 'Access must end after it starts.', 400);
        }
        $users = array();
        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($userId > 0) {
                $users[$userId] = $userId;
            }
        }
        $categories = array();
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($categoryId > 0) {
                $categories[$categoryId] = $categoryId;
            }
        }
        if ($users === array() || $categories === array()) {
            throw new CommunicationException('validation_error', 'Choose at least one person and one category.', 400);
        }
        $now = CommunicationSupport::nowUtc();
        $delete = $this->pdo->prepare(
            'DELETE FROM ipca_training_video_category_entitlements WHERE user_id = ? AND category_id = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_training_video_category_entitlements
                (user_id, category_id, available_from_utc, available_until_utc, created_at_utc, updated_at_utc)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($users as $userId) {
            foreach ($categories as $categoryId) {
                $delete->execute(array($userId, $categoryId));
                $insert->execute(array($userId, $categoryId, $from, $until, $now, $now));
            }
        }
        return array('ok' => true, 'entitlements' => $this->categoryEntitlementsByUser());
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function replaceUserCategoryEntitlements(int $userId, array $items): array
    {
        $this->requireEntitlementsTable();
        if ($userId < 1) {
            throw new CommunicationException('validation_error', 'A person is required.', 400);
        }
        $this->pdo->prepare(
            'DELETE FROM ipca_training_video_category_entitlements WHERE user_id = ?'
        )->execute(array($userId));
        $now = CommunicationSupport::nowUtc();
        $insert = $this->pdo->prepare(
            'INSERT INTO ipca_training_video_category_entitlements
                (user_id, category_id, available_from_utc, available_until_utc, created_at_utc, updated_at_utc)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $seen = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $categoryId = (int)($item['category_id'] ?? 0);
            if ($categoryId < 1 || isset($seen[$categoryId])) {
                continue;
            }
            $from = $this->optionalUtc((string)($item['available_from_utc'] ?? ''));
            $until = $this->optionalUtc((string)($item['available_until_utc'] ?? ''));
            if ($from !== null && $until !== null && $until <= $from) {
                throw new CommunicationException('validation_error', 'Access must end after it starts.', 400);
            }
            $seen[$categoryId] = true;
            $insert->execute(array($userId, $categoryId, $from, $until, $now, $now));
        }
        return array('ok' => true, 'entitlements' => $this->categoryEntitlementsByUser());
    }

    private function requireEntitlementsTable(): void
    {
        if (!$this->tableExists('ipca_training_video_category_entitlements')) {
            throw new CommunicationException('not_found', 'Category access is not installed yet.', 404);
        }
    }

    /**
     * @return array{id:int,slug:string,name:string}|null
     */
    private function categoryBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !$this->tableExists('ipca_training_video_categories')) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, name FROM ipca_training_video_categories WHERE slug = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(array($slug));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? array(
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'name' => (string)$row['name'],
        ) : null;
    }

    /**
     * @return array{id:int,slug:string,name:string}|null
     */
    private function resolveCategory(int $categoryId, string $text): ?array
    {
        if (!$this->tableExists('ipca_training_video_categories')) {
            return null;
        }
        if ($categoryId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id, slug, name FROM ipca_training_video_categories WHERE id = ? LIMIT 1'
            );
            $stmt->execute(array($categoryId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return array(
                    'id' => (int)$row['id'],
                    'slug' => (string)$row['slug'],
                    'name' => (string)$row['name'],
                );
            }
        }
        $text = strtolower(trim($text));
        if ($text !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT id, slug, name FROM ipca_training_video_categories
                 WHERE is_active = 1 AND (LOWER(name) = ? OR LOWER(slug) = ?)
                 LIMIT 1'
            );
            $stmt->execute(array($text, str_replace(' ', '-', $text)));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return array(
                    'id' => (int)$row['id'],
                    'slug' => (string)$row['slug'],
                    'name' => (string)$row['name'],
                );
            }
        }
        return $this->categoryBySlug('uncategorized');
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,slug:string,name:string}
     */
    private function categoryPayload(array $row): array
    {
        $resolved = $this->resolveCategory(
            (int)($row['category_id'] ?? 0),
            (string)($row['category'] ?? '')
        );
        if ($resolved !== null) {
            return $resolved;
        }
        $name = trim((string)($row['category'] ?? ''));
        return array(
            'id' => 0,
            'slug' => $name === '' ? 'uncategorized' : strtolower(str_replace(' ', '-', $name)),
            'name' => $name === '' ? 'Uncategorized' : $name,
        );
    }

    private function countCompletions(int $videoId): int
    {
        if (!$this->hasColumn('ipca_training_video_views', 'completed_at_utc')) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ipca_training_video_views WHERE video_id = ? AND completed_at_utc IS NOT NULL'
        );
        $stmt->execute(array($videoId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array<string,mixed>>
     */
    private function thumbnailCandidates(array $row): array
    {
        $raw = json_decode((string)($row['poster_candidate_json'] ?? ''), true);
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uuid = (string)($item['asset_uuid'] ?? '');
            $asset = $uuid !== '' ? $this->mediaLibrary->findByUuid($uuid) : null;
            $preview = '';
            if (is_array($asset)) {
                $preview = (string)($this->mediaLibrary->adminAsset($asset)['preview_url'] ?? '');
            }
            $out[] = array(
                'asset_uuid' => $uuid,
                'score' => (float)($item['score'] ?? 0),
                'preview_url' => $preview,
                'orientation' => (string)($item['orientation'] ?? ''),
                'filename' => (string)($item['filename'] ?? ''),
            );
        }
        return $out;
    }

    private function renderGeneratedThumbnail(string $videoUuid, string $mode, string $chosenAssetUuid): void
    {
        if (!extension_loaded('gd')) {
            return;
        }
        $row = $this->requireAdminVideo($videoUuid);
        $geometry = $this->resolveVideoGeometry($row);
        if (
            (int)($row['width'] ?? 0) !== $geometry['width']
            || (int)($row['height'] ?? 0) !== $geometry['height']
            || strtolower(trim((string)($row['orientation'] ?? ''))) !== $geometry['orientation']
        ) {
            $this->updateVideoFields((int)$row['id'], array(
                'width' => $geometry['width'],
                'height' => $geometry['height'],
                'orientation' => $geometry['orientation'],
            ));
            $row['width'] = $geometry['width'];
            $row['height'] = $geometry['height'];
            $row['orientation'] = $geometry['orientation'];
        }
        $orientation = $geometry['orientation'];
        $category = $this->categoryPayload($row);
        $template = CommunicationTrainingThumbnailRenderer::templateFor($orientation, (string)$category['slug']);
        $context = array(
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'aircraft' => (string)($row['aircraft'] ?? ''),
            'program' => (string)($row['program'] ?? ''),
        );
        $candidates = $this->resolvedCandidates(
            $row,
            $context,
            $orientation,
            $mode === 'ensure' || $mode === 'regenerate'
        );
        $exclusions = $this->usedLibraryExclusions((int)$row['id']);
        if ($mode === 'regenerate') {
            $currentId = (int)($row['poster_library_asset_id'] ?? 0);
            if ($currentId > 0) {
                $exclusions['ids'][] = $currentId;
                $currentAsset = $this->mediaLibrary->findById($currentId);
                if (is_array($currentAsset)) {
                    $currentHash = $this->mediaLibrary->ensurePhash($currentAsset);
                    if ($currentHash !== '') {
                        $exclusions['phashes'][] = $currentHash;
                    }
                }
            }
        }
        $candidates = $this->filterUnusedCandidates($candidates, $exclusions['ids'], $exclusions['phashes']);
        if ($candidates === array() && $mode !== 'ensure') {
            $candidates = $this->resolvedCandidates($row, $context, $orientation, false);
            $candidates = $this->filterUnusedCandidates($candidates, $exclusions['ids'], $exclusions['phashes']);
        }
        $index = 0;
        if ($mode === 'choose' && $chosenAssetUuid !== '') {
            $chosen = $this->mediaLibrary->findByUuid($chosenAssetUuid);
            if ($chosen === null) {
                throw new CommunicationException('not_found', 'That photograph was not found.', 404);
            }
            if (!$this->orientationAllowed($orientation, (string)$chosen['orientation'])) {
                throw new CommunicationException('validation_error', 'That photograph does not match the video orientation.', 400);
            }
            $chosenCandidate = $this->filterUnusedCandidates(
                array($this->candidateFromRow($chosen, 100.0)),
                $exclusions['ids'],
                $exclusions['phashes']
            );
            if ($chosenCandidate === array()) {
                throw new CommunicationException('validation_error', 'That photograph is already used on another video.', 400);
            }
            array_unshift($candidates, $chosenCandidate[0]);
            $seen = array();
            $unique = array();
            foreach ($candidates as $candidate) {
                $uuid = (string)$candidate['asset_uuid'];
                if (isset($seen[$uuid])) {
                    continue;
                }
                $seen[$uuid] = true;
                $unique[] = $candidate;
            }
            $candidates = array_slice($unique, 0, 3);
            $index = 0;
        } elseif ($index < 0 || $index >= max(1, count($candidates))) {
            $index = 0;
        }

        $background = null;
        $source = 'branded_fallback';
        $assetId = null;
        $focalX = 0.5;
        $focalY = $orientation === 'portrait' ? 0.38 : 0.48;
        if (isset($candidates[$index])) {
            $asset = $this->mediaLibrary->findByUuid((string)$candidates[$index]['asset_uuid']);
            if (is_array($asset) && $this->orientationAllowed($orientation, (string)$asset['orientation'])) {
                $bytes = $this->mediaLibrary->getImageBytes((string)$asset['asset_uuid']);
                if (is_string($bytes) && $bytes !== '') {
                    $background = $bytes;
                    $source = 'media_library';
                    $assetId = (int)$asset['id'];
                    $analysis = json_decode((string)($asset['analysis_json'] ?? ''), true);
                    if (is_array($analysis)) {
                        $focalX = (float)($analysis['focal_x'] ?? $focalX);
                        $focalY = (float)($analysis['focal_y'] ?? $focalY);
                    }
                }
            }
        }
        if ($background === null) {
            $aiBackground = $this->mediaLibrary->generateAiBackground($context, $orientation);
            if (is_string($aiBackground) && $aiBackground !== '') {
                $background = $aiBackground;
                $source = 'ai_generated';
            }
        }

        $jpeg = $this->renderer->render($template, array(
            'title' => (string)$row['title'],
            'category' => (string)$category['name'],
            'focal_x' => $focalX,
            'focal_y' => $focalY,
        ), $background);
        $posterKey = 'training-videos/1/' . $row['video_uuid'] . '.poster';
        $stream = fopen('php://memory', 'rb+');
        if ($stream === false) {
            throw new RuntimeException('Could not buffer the generated thumbnail.');
        }
        fwrite($stream, $jpeg);
        rewind($stream);
        $this->store->putStream($posterKey, $stream, strlen($jpeg), 'image/jpeg');
        fclose($stream);
        $this->pdo->prepare(
            'UPDATE ipca_training_videos SET poster_storage_key = ?, poster_mime_type = ?, updated_at_utc = ? WHERE id = ?'
        )->execute(array($posterKey, 'image/jpeg', CommunicationSupport::nowUtc(), (int)$row['id']));
        $this->updateVideoFields((int)$row['id'], array(
            'poster_source' => $source,
            'poster_template' => $template,
            'poster_library_asset_id' => $assetId,
            'poster_candidate_json' => json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'poster_candidate_index' => $index,
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    private function resolvedCandidates(array $row, array $context, string $orientation, bool $reuseStored): array
    {
        $stored = json_decode((string)($row['poster_candidate_json'] ?? ''), true);
        if ($reuseStored && is_array($stored) && $stored !== array()) {
            $out = array();
            foreach ($stored as $item) {
                if (is_array($item) && CommunicationSupport::isUuid((string)($item['asset_uuid'] ?? ''))) {
                    $out[] = $item;
                }
            }
            if ($out !== array()) {
                return $out;
            }
        }
        $exclusions = $this->usedLibraryExclusions((int)($row['id'] ?? 0));
        $ranked = $this->mediaLibrary->rankForVideo(
            $context,
            $orientation,
            3,
            $exclusions['ids'],
            $exclusions['phashes']
        );
        $out = array();
        foreach ($ranked as $asset) {
            $out[] = array(
                'asset_uuid' => (string)$asset['asset_uuid'],
                'score' => (float)($asset['score'] ?? 0),
                'orientation' => (string)($asset['orientation'] ?? ''),
                'filename' => (string)($asset['filename'] ?? ''),
            );
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param list<int> $excludeIds
     * @param list<string> $excludePhashes
     * @return list<array<string,mixed>>
     */
    private function filterUnusedCandidates(array $candidates, array $excludeIds, array $excludePhashes): array
    {
        $blocked = array();
        foreach ($excludeIds as $id) {
            $blocked[(int)$id] = true;
        }
        $out = array();
        $seen = array();
        $seenPhashes = $excludePhashes;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $uuid = (string)($candidate['asset_uuid'] ?? '');
            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }
            $asset = $this->mediaLibrary->findByUuid($uuid);
            if (!is_array($asset)) {
                continue;
            }
            $id = (int)($asset['id'] ?? 0);
            if ($id > 0 && isset($blocked[$id])) {
                continue;
            }
            $hash = $this->mediaLibrary->ensurePhash($asset);
            if ($hash !== '' && $this->mediaLibrary->phashMatches($hash, $seenPhashes)) {
                continue;
            }
            $seen[$uuid] = true;
            if ($hash !== '') {
                $seenPhashes[] = $hash;
            }
            $out[] = $candidate;
        }
        return $out;
    }

    /**
     * @return array{ids:list<int>,phashes:list<string>}
     */
    private function usedLibraryExclusions(int $exceptVideoId): array
    {
        $ids = array();
        $phashes = array();
        if (!$this->hasVideoColumn('poster_library_asset_id')) {
            return array('ids' => $ids, 'phashes' => $phashes);
        }
        $sql = 'SELECT poster_library_asset_id FROM ipca_training_videos
                WHERE deleted_at_utc IS NULL AND poster_library_asset_id IS NOT NULL';
        $args = array();
        if ($exceptVideoId > 0) {
            $sql .= ' AND id != ?';
            $args[] = $exceptVideoId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['poster_library_asset_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $ids[] = $id;
            $asset = $this->mediaLibrary->findById($id);
            if (!is_array($asset)) {
                continue;
            }
            $hash = $this->mediaLibrary->ensurePhash($asset);
            if ($hash !== '') {
                $phashes[] = $hash;
            }
        }
        return array('ids' => $ids, 'phashes' => $phashes);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function candidateFromRow(array $row, float $score): array
    {
        return array(
            'asset_uuid' => (string)$row['asset_uuid'],
            'score' => $score,
            'orientation' => (string)($row['orientation'] ?? ''),
            'filename' => (string)($row['original_filename'] ?? ''),
        );
    }

    private function orientationAllowed(string $videoOrientation, string $assetOrientation): bool
    {
        return strtolower(trim($assetOrientation)) === strtolower(trim($videoOrientation));
    }
}
