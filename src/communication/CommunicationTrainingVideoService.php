<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationObjectStore.php';
require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationTrainingMediaLibraryService.php';
require_once __DIR__ . '/CommunicationTrainingThumbnailRenderer.php';

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

    private const GRANT_TYPES = array('all', 'users', 'cohorts', 'programs', 'roles');
    private const VIDEO_MIME = array('video/mp4', 'video/quicktime');
    private const POSTER_MIME = array('image/jpeg', 'image/jpg', 'image/png', 'image/webp');

    /** @var array<string,bool> */
    private array $tableCache = array();
    /** @var array<string,bool> */
    private array $columnCache = array();
    private CommunicationTrainingMediaLibraryService $mediaLibrary;
    private CommunicationTrainingThumbnailRenderer $renderer;

    public function __construct(
        private PDO $pdo,
        private CommunicationConfigService $config,
        private CommunicationObjectStore $store,
        ?CommunicationTrainingMediaLibraryService $mediaLibrary = null,
        ?CommunicationTrainingThumbnailRenderer $renderer = null
    ) {
        $this->mediaLibrary = $mediaLibrary ?? new CommunicationTrainingMediaLibraryService($pdo, $store);
        $this->renderer = $renderer ?? new CommunicationTrainingThumbnailRenderer();
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
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $videos[] = $this->adminVideo($row);
        }
        return array('videos' => $videos, 'options' => $this->adminOptions());
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
        }
        $this->saveOptionalVideoFields($videoUuid, $input, is_array($existing) ? $existing : array());
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
            $width = max(0, (int)($options['width'] ?? 0));
            $height = max(0, (int)($options['height'] ?? 0));
            $orientation = CommunicationTrainingThumbnailRenderer::videoOrientation($width, $height);
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
            $this->updateVideoFields((int)$row['id'], array(
                'width' => $width,
                'height' => $height,
                'orientation' => $orientation === '' ? null : $orientation,
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
        $this->renderGeneratedThumbnail($videoUuid, 'regenerate', '');
        $row = $this->requireAdminVideo($videoUuid);
        return array(
            'video' => $this->adminVideo($row),
            'grants' => $this->grantsFor((int)$row['id']),
        );
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
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_training_video_grants WHERE video_id = ?');
        $stmt->execute(array($videoId));
        $now = CommunicationSupport::nowUtc();
        $latest = null;
        $context = $this->userGrantContext($user);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $grant) {
            $from = (string)$grant['available_from_utc'];
            $until = (string)$grant['available_until_utc'];
            if ($from === '' || $until === '' || $from > $now || $until < $now) {
                continue;
            }
            if (!$this->grantMatches($grant, $context)) {
                continue;
            }
            if ($latest === null || $until > $latest) {
                $latest = $until;
            }
        }
        return $latest;
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
     * @return list<array{grant_type:string,grant_value:string,available_from_utc:string,available_until_utc:string}>
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
            $from = $this->requireUtc((string)($grant['available_from_utc'] ?? ''), 'available_from_utc');
            $until = $this->requireUtc((string)($grant['available_until_utc'] ?? ''), 'available_until_utc');
            if ($until <= $from) {
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
     * @param list<array{grant_type:string,grant_value:string,available_from_utc:string,available_until_utc:string}> $grants
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

    private function requireUtc(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new CommunicationException('validation_error', 'Access from and until times are required.', 400);
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new CommunicationException('validation_error', 'Access times must be valid dates.', 400);
        }
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
        return array(
            'id' => $videoId,
            'video_uuid' => (string)$row['video_uuid'],
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
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
            'available_until' => $availableUntil,
            'created_at_utc' => (string)$row['created_at_utc'],
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
        return array(
            'id' => (int)$row['id'],
            'video_uuid' => (string)$row['video_uuid'],
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'aircraft' => (string)($row['aircraft'] ?? ''),
            'program' => (string)($row['program'] ?? ''),
            'status' => (string)$row['status'],
            'duration_ms' => (int)$row['duration_ms'],
            'byte_size' => (int)$row['byte_size'],
            'width' => (int)($row['width'] ?? 0),
            'height' => (int)($row['height'] ?? 0),
            'orientation' => (string)($row['orientation'] ?? ''),
            'mime_type' => (string)$row['mime_type'],
            'has_video' => $videoKey !== '',
            'has_poster' => $posterKey !== '',
            'app_visible' => $videoKey !== '' && (string)$row['status'] === 'published',
            'poster_url' => $posterKey !== '' ? $this->store->presignGet($posterKey, self::GET_EXPIRES) : '',
            'poster_source' => $source,
            'poster_source_label' => $sourceLabel,
            'poster_template' => (string)($row['poster_template'] ?? ''),
            'thumbnail_candidates' => $this->thumbnailCandidates($row),
            'thumbnail_candidate_index' => (int)($row['poster_candidate_index'] ?? 0),
            'view_count' => $this->countViews((int)$row['id']),
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
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
        $orientation = strtolower(trim((string)($row['orientation'] ?? '')));
        if ($orientation !== 'portrait' && $orientation !== 'landscape') {
            $orientation = CommunicationTrainingThumbnailRenderer::videoOrientation(
                (int)($row['width'] ?? 0),
                (int)($row['height'] ?? 0)
            );
        }
        if ($orientation === '') {
            $orientation = 'landscape';
        }
        $template = CommunicationTrainingThumbnailRenderer::templateForOrientation($orientation);
        $context = array(
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'aircraft' => (string)($row['aircraft'] ?? ''),
            'program' => (string)($row['program'] ?? ''),
        );
        $candidates = $this->resolvedCandidates($row, $context, $orientation, $mode === 'ensure');
        $index = (int)($row['poster_candidate_index'] ?? 0);
        if ($mode === 'regenerate' && $candidates !== array()) {
            $index = ($index + 1) % count($candidates);
        } elseif ($mode === 'choose' && $chosenAssetUuid !== '') {
            $chosen = $this->mediaLibrary->findByUuid($chosenAssetUuid);
            if ($chosen === null) {
                throw new CommunicationException('not_found', 'That photograph was not found.', 404);
            }
            if (!$this->orientationAllowed($orientation, (string)$chosen['orientation'])) {
                throw new CommunicationException('validation_error', 'That photograph does not match the video orientation.', 400);
            }
            array_unshift($candidates, $this->candidateFromRow($chosen, 100.0));
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
            'category' => trim((string)($row['category'] ?? '') . ((string)($row['aircraft'] ?? '') !== '' ? ' · ' . $row['aircraft'] : '')),
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
        $ranked = $this->mediaLibrary->rankForVideo($context, $orientation, 3);
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
        $assetOrientation = strtolower(trim($assetOrientation));
        if ($assetOrientation === $videoOrientation) {
            return true;
        }
        return $assetOrientation === 'square';
    }
}
