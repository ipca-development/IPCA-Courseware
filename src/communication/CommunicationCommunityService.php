<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationAuthService.php';
require_once __DIR__ . '/CommunicationObjectStore.php';
require_once __DIR__ . '/CommunicationPushService.php';
require_once __DIR__ . '/CommunicationSupport.php';

/**
 * Isolated Community feed. Does not write messaging tables, share the
 * message outbox, or participate in communication sync.
 */
final class CommunicationCommunityService
{
    public const FEED_PAGE = 20;
    public const MAX_CAPTION = 2000;
    public const MAX_BODY = 2000;
    public const MAX_COMMENT = 2000;
    public const MAX_PHOTO_BYTES = 26214400;
    public const MAX_VIDEO_BYTES = 52428800;
    public const MAX_STAFF_VIDEO_BYTES = 209715200;
    public const MAX_VIDEO_MS = 30000;
    public const MAX_STAFF_VIDEO_MS = 600000;
    public const MAX_POSTER_BYTES = 2097152;
    public const PUT_EXPIRES = 900;
    public const GET_EXPIRES = 300;
    public const PUBLIC_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** @var array<int,string> */
    public const PHOTO_MIME = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
    );

    /** @var array<int,string> */
    public const VIDEO_MIME = array(
        'video/mp4',
        'video/quicktime',
        'video/x-m4v',
    );

    /** @var array<int,string> */
    public const REPORT_REASONS = array('spam', 'inappropriate', 'harassment', 'other');

    public function __construct(
        private PDO $pdo,
        private CommunicationConfigService $config,
        private CommunicationAuthService $auth,
        private CommunicationObjectStore $store,
        private CommunicationPushService $push
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function feed(array $session, int $cursor = 0): array
    {
        $this->config->requireCommunity();
        $viewerId = (int)$session['user']['id'];
        $limit = self::FEED_PAGE + 1;
        if ($cursor > 0) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ipca_community_posts
                WHERE status = 'published' AND id < ?
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute(array($cursor));
        } else {
            $stmt = $this->pdo->query("
                SELECT * FROM ipca_community_posts
                WHERE status = 'published'
                ORDER BY id DESC
                LIMIT {$limit}
            ");
        }
        $rows = $stmt === false ? array() : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > self::FEED_PAGE;
        if ($hasMore) {
            $rows = array_slice($rows, 0, self::FEED_PAGE);
        }
        $nextCursor = $hasMore ? (int)$rows[count($rows) - 1]['id'] : null;
        return array(
            'posts' => $this->publicPosts($rows, $viewerId, $session),
            'next_cursor' => $nextCursor,
            'posting_enabled' => $this->config->enabled('community_posting_enabled'),
        );
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function post(array $session, string $postUuid): array
    {
        $this->config->requireCommunity();
        $row = $this->requirePublishedPost($postUuid);
        $posts = $this->publicPosts(array($row), (int)$session['user']['id'], $session);
        return array(
            'post' => $posts[0],
            'posting_enabled' => $this->config->enabled('community_posting_enabled'),
        );
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function presignMedia(
        array $session,
        string $filename,
        string $mimeType,
        int $byteSize,
        int $durationMs = 0,
        ?string $mediaUuid = null
    ): array {
        $this->config->requireCommunityPosting();
        $kind = $this->kindForMime($mimeType);
        $mimeType = $this->normalizeMime($mimeType, $kind);
        $filename = $this->sanitizeFilename($filename);
        $maxBytes = $this->maxVideoBytes($session);
        $max = $kind === 'video' ? $maxBytes : self::MAX_PHOTO_BYTES;
        if ($byteSize < 1 || $byteSize > $max) {
            throw new CommunicationException('validation_error', 'That file is too large to share.', 400);
        }
        if ($kind === 'video' && $durationMs > $this->maxVideoMs($session)) {
            throw new CommunicationException(
                'validation_error',
                $this->auth->userCanPostLongCommunityVideo($session['user'])
                    ? 'Keep Community videos to 10 minutes.'
                    : 'Keep Community videos to 30 seconds.',
                400
            );
        }
        if ($mediaUuid !== null && $mediaUuid !== '') {
            $mediaUuid = CommunicationSupport::requireUuid($mediaUuid, 'media_uuid');
        } else {
            $mediaUuid = CommunicationSupport::uuid();
        }

        $existing = $this->findMedia($mediaUuid);
        if ($existing !== null) {
            if ((int)$existing['uploaded_by_user_id'] !== (int)$session['user']['id']) {
                throw new CommunicationException('conflict', 'That media already exists.', 409);
            }
            if ((string)$existing['status'] === 'uploaded' || (string)$existing['status'] === 'attached') {
                return $this->publicPresign($existing, $mimeType);
            }
            return $this->publicPresign($existing, (string)$existing['mime_type']);
        }

        $now = CommunicationSupport::nowUtc();
        $storageKey = 'community/1/m/' . $mediaUuid;
        $this->pdo->prepare("
            INSERT INTO ipca_community_post_media
              (media_uuid, post_id, organization_id, uploaded_by_user_id, uploaded_by_device_id,
               storage_key, original_filename, mime_type, kind, byte_size, duration_ms, status, created_at_utc)
            VALUES (?, NULL, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ")->execute(array(
            $mediaUuid,
            (int)$session['user']['id'],
            (int)$session['device']['id'],
            $storageKey,
            $filename,
            $mimeType,
            $kind,
            $byteSize,
            max(0, $durationMs),
            $now,
        ));
        $row = $this->findMedia($mediaUuid);
        return $this->publicPresign(is_array($row) ? $row : array(
            'media_uuid' => $mediaUuid,
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'status' => 'pending',
        ), $mimeType);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function completeMedia(array $session, string $mediaUuid): array
    {
        $this->config->requireCommunityPosting();
        $mediaUuid = CommunicationSupport::requireUuid($mediaUuid, 'media_uuid');
        $row = $this->findMedia($mediaUuid);
        if ($row === null || (int)$row['uploaded_by_user_id'] !== (int)$session['user']['id']) {
            throw new CommunicationException('not_found', 'Media not found.', 404);
        }
        if ((string)$row['status'] === 'uploaded' || (string)$row['status'] === 'attached') {
            $this->attachPosterIfPresent($row);
            $updated = $this->findMedia($mediaUuid);
            return $this->publicMedia(is_array($updated) ? $updated : $row, false);
        }
        $head = $this->store->head((string)$row['storage_key']);
        if ($head === null) {
            throw new CommunicationException('upload_incomplete', 'Upload is not finished yet. Try again.', 409);
        }
        $kind = (string)$row['kind'];
        $max = $kind === 'video' ? $this->maxVideoBytes($session) : self::MAX_PHOTO_BYTES;
        $size = (int)$head['byte_size'];
        if ($size < 1 || $size > $max) {
            throw new CommunicationException('validation_error', 'That file is too large to share.', 400);
        }
        $contentType = $this->normalizeMime((string)($head['content_type'] ?: $row['mime_type']), $kind);
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare("
            UPDATE ipca_community_post_media
            SET status = 'uploaded', uploaded_at_utc = ?, byte_size = ?, mime_type = ?
            WHERE id = ? AND status = 'pending'
        ")->execute(array($now, $size, $contentType, (int)$row['id']));
        $updated = $this->findMedia($mediaUuid);
        if (is_array($updated)) {
            $this->attachPosterIfPresent($updated);
            $updated = $this->findMedia($mediaUuid) ?? $updated;
        }
        return $this->publicMedia(is_array($updated) ? $updated : $row, false);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function downloadMedia(array $session, string $mediaUuid): array
    {
        $this->config->requireCommunity();
        $mediaUuid = CommunicationSupport::requireUuid($mediaUuid, 'media_uuid');
        $row = $this->findMedia($mediaUuid);
        if ($row === null) {
            throw new CommunicationException('not_found', 'Media not found.', 404);
        }
        $status = (string)$row['status'];
        if ($status !== 'uploaded' && $status !== 'attached') {
            throw new CommunicationException('not_found', 'Media is not ready.', 404);
        }
        if ($status === 'attached') {
            $post = $this->findPostById((int)$row['post_id']);
            if ($post === null || (string)$post['status'] !== 'published') {
                throw new CommunicationException('not_found', 'Media is not ready.', 404);
            }
        } elseif ((int)$row['uploaded_by_user_id'] !== (int)$session['user']['id']) {
            throw new CommunicationException('not_found', 'Media not found.', 404);
        }
        return array(
            'media_uuid' => (string)$row['media_uuid'],
            'kind' => (string)$row['kind'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'get_url' => $this->store->publicUrl((string)$row['storage_key']),
            'expires_in' => 0,
        );
    }

    /**
     * @param array<string,mixed> $session
     * @param array<int,string> $mediaUuids
     * @return array<string,mixed>
     */
    public function create(array $session, string $caption, array $mediaUuids, ?string $postUuid = null, string $body = ''): array
    {
        $this->config->requireCommunityPosting();
        $caption = trim($caption);
        $body = trim($body);
        if (mb_strlen($caption) > self::MAX_CAPTION) {
            throw new CommunicationException('validation_error', 'That caption is too long.', 400);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new CommunicationException('validation_error', 'That text is too long.', 400);
        }
        $unique = array();
        foreach ($mediaUuids as $uuid) {
            $unique[] = CommunicationSupport::requireUuid((string)$uuid, 'media_uuid');
        }
        $unique = array_values(array_unique($unique));
        if (count($unique) !== 1) {
            throw new CommunicationException('validation_error', 'Share one photo or one short video.', 400);
        }
        if ($postUuid !== null && $postUuid !== '') {
            $postUuid = CommunicationSupport::requireUuid($postUuid, 'post_uuid');
        } else {
            $postUuid = CommunicationSupport::uuid();
        }
        $existing = $this->findPostByUuid($postUuid);
        if ($existing !== null) {
            if ((int)$existing['author_user_id'] !== (int)$session['user']['id']) {
                throw new CommunicationException('conflict', 'That post already exists.', 409);
            }
            $posts = $this->publicPosts(array($existing), (int)$session['user']['id'], $session);
            return array('post' => $posts[0]);
        }

        $media = $this->findMedia($unique[0]);
        if ($media === null || (int)$media['uploaded_by_user_id'] !== (int)$session['user']['id']) {
            throw new CommunicationException('not_found', 'Upload that photo or video first.', 404);
        }
        if ((string)$media['status'] !== 'uploaded') {
            throw new CommunicationException('upload_incomplete', 'Upload is not finished yet. Try again.', 409);
        }
        if ($media['post_id'] !== null && (int)$media['post_id'] > 0) {
            throw new CommunicationException('conflict', 'That media is already in a post.', 409);
        }

        $now = CommunicationSupport::nowUtc();
        if ($this->hasBodyColumn()) {
            $this->pdo->prepare("
                INSERT INTO ipca_community_posts
                  (post_uuid, author_user_id, author_device_id, organization_id, caption, body, status, created_at_utc)
                VALUES (?, ?, ?, 1, ?, ?, 'published', ?)
            ")->execute(array(
                $postUuid,
                (int)$session['user']['id'],
                (int)$session['device']['id'],
                $caption,
                $body,
                $now,
            ));
        } else {
            $this->pdo->prepare("
                INSERT INTO ipca_community_posts
                  (post_uuid, author_user_id, author_device_id, organization_id, caption, status, created_at_utc)
                VALUES (?, ?, ?, 1, ?, 'published', ?)
            ")->execute(array(
                $postUuid,
                (int)$session['user']['id'],
                (int)$session['device']['id'],
                $caption,
                $now,
            ));
        }
        $postId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("
            UPDATE ipca_community_post_media
            SET post_id = ?, status = 'attached', sort_order = 0
            WHERE id = ? AND status = 'uploaded'
        ")->execute(array($postId, (int)$media['id']));

        CommunicationSupport::log('communication.community.post.created', array(
            'post_uuid' => $postUuid,
            'user_id' => (int)$session['user']['id'],
            'kind' => (string)$media['kind'],
        ));
        $row = $this->findPostByUuid($postUuid);
        $posts = $this->publicPosts(is_array($row) ? array($row) : array(), (int)$session['user']['id'], $session);
        return array('post' => $posts[0] ?? array('post_uuid' => $postUuid));
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function like(array $session, string $postUuid): array
    {
        $this->config->requireCommunity();
        $post = $this->requirePublishedPost($postUuid);
        $userId = (int)$session['user']['id'];
        $existing = $this->pdo->prepare('SELECT id FROM ipca_community_likes WHERE post_id = ? AND user_id = ? LIMIT 1');
        $existing->execute(array((int)$post['id'], $userId));
        if ($existing->fetchColumn() === false) {
            try {
                $this->pdo->prepare("
                    INSERT INTO ipca_community_likes (like_uuid, post_id, user_id, device_id, created_at_utc)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute(array(
                    CommunicationSupport::uuid(),
                    (int)$post['id'],
                    $userId,
                    (int)$session['device']['id'],
                    CommunicationSupport::nowUtc(),
                ));
            } catch (Throwable) {
                // Unique (post, user) — already liked.
            }
        }
        $fresh = $this->requirePublishedPost($postUuid);
        $posts = $this->publicPosts(array($fresh), $userId, $session);
        return array('post' => $posts[0]);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function unlike(array $session, string $postUuid): array
    {
        $this->config->requireCommunity();
        $post = $this->requirePublishedPost($postUuid);
        $this->pdo->prepare('DELETE FROM ipca_community_likes WHERE post_id = ? AND user_id = ?')
            ->execute(array((int)$post['id'], (int)$session['user']['id']));
        $fresh = $this->requirePublishedPost($postUuid);
        $posts = $this->publicPosts(array($fresh), (int)$session['user']['id'], $session);
        return array('post' => $posts[0]);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function comment(array $session, string $postUuid, string $body, ?string $commentUuid = null): array
    {
        $this->config->requireCommunity();
        $post = $this->requirePublishedPost($postUuid);
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
                if ((int)$existing['user_id'] !== (int)$session['user']['id'] || (int)$existing['post_id'] !== (int)$post['id']) {
                    throw new CommunicationException('conflict', 'That comment already exists.', 409);
                }
                return array('comment' => $this->publicComment($existing, $session));
            }
        } else {
            $commentUuid = CommunicationSupport::uuid();
        }
        $this->pdo->prepare("
            INSERT INTO ipca_community_comments (comment_uuid, post_id, user_id, device_id, body, created_at_utc)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute(array(
            $commentUuid,
            (int)$post['id'],
            (int)$session['user']['id'],
            (int)$session['device']['id'],
            $body,
            CommunicationSupport::nowUtc(),
        ));
        CommunicationSupport::log('communication.community.comment.created', array(
            'comment_uuid' => $commentUuid,
            'post_uuid' => $postUuid,
            'user_id' => (int)$session['user']['id'],
        ));
        $row = $this->findComment($commentUuid);
        $commenterName = trim((string)($session['user']['name'] ?? ''));
        if ($commenterName === '') {
            $commenterName = 'Someone';
        }
        try {
            $this->push->notifyCommunityComment(
                (int)$post['author_user_id'],
                (int)$session['user']['id'],
                (int)$session['device']['id'],
                $postUuid,
                $commenterName,
                $body
            );
        } catch (Throwable $e) {
            CommunicationSupport::log('communication.community.push.error', array(
                'error' => $e->getMessage(),
                'post_uuid' => $postUuid,
            ));
        }
        return array('comment' => $this->publicComment(is_array($row) ? $row : array(
            'comment_uuid' => $commentUuid,
            'body' => $body,
            'created_at_utc' => CommunicationSupport::nowUtc(),
            'deleted_at_utc' => null,
            'user_id' => (int)$session['user']['id'],
        ), $session));
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function comments(array $session, string $postUuid): array
    {
        $this->config->requireCommunity();
        $post = $this->requirePublishedPost($postUuid);
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_community_comments
            WHERE post_id = ? AND deleted_at_utc IS NULL
            ORDER BY id ASC
        ");
        $stmt->execute(array((int)$post['id']));
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->publicComment($row, $session);
        }
        return array('comments' => $out);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function deletePost(array $session, string $postUuid): array
    {
        $this->config->requireCommunity();
        $postUuid = CommunicationSupport::requireUuid($postUuid, 'post_uuid');
        $post = $this->findPostByUuid($postUuid);
        if ($post === null || (string)$post['status'] !== 'published') {
            throw new CommunicationException('not_found', 'Post not found.', 404);
        }
        $viewerId = (int)$session['user']['id'];
        $isAuthor = (int)$post['author_user_id'] === $viewerId;
        if (!$isAuthor && !$this->auth->userIsStaff($session['user'])) {
            throw new CommunicationException('forbidden', 'You can only delete your own posts.', 403);
        }
        $this->pdo->prepare("
            UPDATE ipca_community_posts
            SET status = 'deleted', deleted_at_utc = ?, deleted_by_user_id = ?
            WHERE id = ? AND status = 'published'
        ")->execute(array(CommunicationSupport::nowUtc(), $viewerId, (int)$post['id']));
        CommunicationSupport::log('communication.community.post.deleted', array(
            'post_uuid' => $postUuid,
            'user_id' => $viewerId,
            'author_user_id' => (int)$post['author_user_id'],
        ));
        return array('deleted' => true, 'post_uuid' => $postUuid);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public function report(array $session, string $postUuid, string $reason, string $details = ''): array
    {
        $this->config->requireCommunity();
        $post = $this->requirePublishedPost($postUuid);
        $reason = strtolower(trim($reason));
        if (!in_array($reason, self::REPORT_REASONS, true)) {
            throw new CommunicationException('validation_error', 'Choose a report reason.', 400);
        }
        $details = trim($details);
        if (mb_strlen($details) > self::MAX_COMMENT) {
            throw new CommunicationException('validation_error', 'That report is too long.', 400);
        }
        if ((int)$post['author_user_id'] === (int)$session['user']['id']) {
            throw new CommunicationException('validation_error', 'You cannot report your own post.', 400);
        }
        $existing = $this->pdo->prepare('SELECT report_uuid FROM ipca_community_reports WHERE post_id = ? AND reporter_user_id = ? LIMIT 1');
        $existing->execute(array((int)$post['id'], (int)$session['user']['id']));
        $reportUuid = $existing->fetchColumn();
        if (is_string($reportUuid) && $reportUuid !== '') {
            return array('report_uuid' => $reportUuid, 'already_reported' => true);
        }
        $reportUuid = CommunicationSupport::uuid();
        try {
            $this->pdo->prepare("
                INSERT INTO ipca_community_reports
                  (report_uuid, post_id, reporter_user_id, reporter_device_id, reason, details, created_at_utc)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute(array(
                $reportUuid,
                (int)$post['id'],
                (int)$session['user']['id'],
                (int)$session['device']['id'],
                $reason,
                $details,
                CommunicationSupport::nowUtc(),
            ));
        } catch (Throwable) {
            return array('report_uuid' => $reportUuid, 'already_reported' => true);
        }
        CommunicationSupport::log('communication.community.post.reported', array(
            'report_uuid' => $reportUuid,
            'post_uuid' => $postUuid,
            'user_id' => (int)$session['user']['id'],
            'reason' => $reason,
        ));
        return array('report_uuid' => $reportUuid, 'already_reported' => false);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $session
     * @return array<int,array<string,mixed>>
     */
    private function publicPosts(array $rows, int $viewerId, array $session): array
    {
        if ($rows === array()) {
            return array();
        }
        $ids = array();
        $authorIds = array();
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
            $authorIds[] = (int)$row['author_user_id'];
        }
        $authors = $this->auth->usersByIds($authorIds);
        $mediaByPost = $this->mediaByPostIds($ids);
        $likeCounts = $this->counts('ipca_community_likes', $ids);
        $commentCounts = $this->commentCounts($ids);
        $liked = $this->likedPostIds($ids, $viewerId);
        $staff = $this->auth->userIsStaff($session['user']);

        $out = array();
        foreach ($rows as $row) {
            $postId = (int)$row['id'];
            $author = $authors[(int)$row['author_user_id']] ?? array('id' => (int)$row['author_user_id']);
            $out[] = array(
                'post_uuid' => (string)$row['post_uuid'],
                'caption' => (string)$row['caption'],
                'body' => (string)($row['body'] ?? ''),
                'created_at_utc' => (string)$row['created_at_utc'],
                'author' => CommunicationSupport::publicUser($author),
                'can_delete' => (int)$row['author_user_id'] === $viewerId || $staff,
                'like_count' => $likeCounts[$postId] ?? 0,
                'liked' => isset($liked[$postId]),
                'comment_count' => $commentCounts[$postId] ?? 0,
                'media' => $mediaByPost[$postId] ?? array(),
            );
        }
        return $out;
    }

    /**
     * @param array<int> $postIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function mediaByPostIds(array $postIds): array
    {
        if ($postIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_community_post_media
            WHERE post_id IN ({$placeholders}) AND status = 'attached'
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute($postIds);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['post_id']][] = $this->publicMedia($row, true);
        }
        return $out;
    }

    /**
     * @param array<int> $postIds
     * @return array<int,int>
     */
    private function counts(string $table, array $postIds): array
    {
        if ($postIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare("SELECT post_id, COUNT(*) AS n FROM {$table} WHERE post_id IN ({$placeholders}) GROUP BY post_id");
        $stmt->execute($postIds);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['post_id']] = (int)$row['n'];
        }
        return $out;
    }

    /**
     * @param array<int> $postIds
     * @return array<int,int>
     */
    private function commentCounts(array $postIds): array
    {
        if ($postIds === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT post_id, COUNT(*) AS n
            FROM ipca_community_comments
            WHERE post_id IN ({$placeholders}) AND deleted_at_utc IS NULL
            GROUP BY post_id
        ");
        $stmt->execute($postIds);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['post_id']] = (int)$row['n'];
        }
        return $out;
    }

    /**
     * @param array<int> $postIds
     * @return array<int,bool>
     */
    private function likedPostIds(array $postIds, int $userId): array
    {
        if ($postIds === array() || $userId < 1) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $params = $postIds;
        $params[] = $userId;
        $stmt = $this->pdo->prepare("SELECT post_id FROM ipca_community_likes WHERE post_id IN ({$placeholders}) AND user_id = ?");
        $stmt->execute($params);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['post_id']] = true;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicMedia(array $row, bool $includeGetUrl): array
    {
        $out = array(
            'media_uuid' => (string)$row['media_uuid'],
            'kind' => (string)$row['kind'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'duration_ms' => (int)($row['duration_ms'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
        );
        if ($includeGetUrl && ((string)$row['status'] === 'uploaded' || (string)$row['status'] === 'attached')) {
            $out['get_url'] = $this->store->publicUrl((string)$row['storage_key']);
        }
        $posterKey = trim((string)($row['poster_storage_key'] ?? ''));
        if ($posterKey !== '' && ((string)$row['status'] === 'uploaded' || (string)$row['status'] === 'attached')) {
            $out['poster_url'] = $this->store->publicUrl($posterKey);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicPresign(array $row, string $mimeType): array
    {
        $headers = $this->publicPutHeaders($mimeType);
        $out = array(
            'media_uuid' => (string)$row['media_uuid'],
            'put_url' => $this->store->presignPublicPut((string)$row['storage_key'], $mimeType, self::PUT_EXPIRES),
            'headers' => $headers,
            'expires_in' => self::PUT_EXPIRES,
            'status' => (string)($row['status'] ?? 'pending'),
        );
        if ((string)($row['kind'] ?? '') === 'video' && $this->hasPosterColumn()) {
            $posterKey = $this->posterKeyFor((string)$row['storage_key']);
            $out['poster_storage_key'] = $posterKey;
            $out['poster_put_url'] = $this->store->presignPublicPut($posterKey, 'image/jpeg', self::PUT_EXPIRES);
            $out['poster_headers'] = $this->publicPutHeaders('image/jpeg');
        }
        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function publicPutHeaders(string $mimeType): array
    {
        return array(
            'Content-Type' => $mimeType,
            'x-amz-acl' => 'public-read',
            'Cache-Control' => self::PUBLIC_CACHE_CONTROL,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function publicComment(array $row, array $session): array
    {
        $author = $this->auth->userById((int)$row['user_id']) ?? array('id' => (int)$row['user_id']);
        $viewerId = (int)$session['user']['id'];
        return array(
            'comment_uuid' => (string)$row['comment_uuid'],
            'body' => (string)$row['body'],
            'created_at_utc' => (string)$row['created_at_utc'],
            'author' => CommunicationSupport::publicUser($author),
            'can_delete' => (int)$row['user_id'] === $viewerId || $this->auth->userIsStaff($session['user']),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function requirePublishedPost(string $postUuid): array
    {
        $postUuid = CommunicationSupport::requireUuid($postUuid, 'post_uuid');
        $post = $this->findPostByUuid($postUuid);
        if ($post === null || (string)$post['status'] !== 'published') {
            throw new CommunicationException('not_found', 'Post not found.', 404);
        }
        return $post;
    }

    /**
     * @param array<string,mixed> $session
     */
    private function maxVideoMs(array $session): int
    {
        return $this->auth->userCanPostLongCommunityVideo($session['user'] ?? array())
            ? self::MAX_STAFF_VIDEO_MS
            : self::MAX_VIDEO_MS;
    }

    /**
     * @param array<string,mixed> $session
     */
    private function maxVideoBytes(array $session): int
    {
        return $this->auth->userCanPostLongCommunityVideo($session['user'] ?? array())
            ? self::MAX_STAFF_VIDEO_BYTES
            : self::MAX_VIDEO_BYTES;
    }

    private function hasBodyColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $stmt = $this->pdo->query('SELECT body FROM ipca_community_posts LIMIT 0');
            $has = $stmt !== false;
        } catch (Throwable) {
            $has = false;
        }
        return $has;
    }

    private function hasPosterColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $stmt = $this->pdo->query('SELECT poster_storage_key FROM ipca_community_post_media LIMIT 0');
            $has = $stmt !== false;
        } catch (Throwable) {
            $has = false;
        }
        return $has;
    }

    private function posterKeyFor(string $storageKey): string
    {
        return $storageKey . '.poster.jpg';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function attachPosterIfPresent(array $row): void
    {
        if ((string)($row['kind'] ?? '') !== 'video' || !$this->hasPosterColumn()) {
            return;
        }
        if (trim((string)($row['poster_storage_key'] ?? '')) !== '') {
            return;
        }
        $posterKey = $this->posterKeyFor((string)$row['storage_key']);
        $head = $this->store->head($posterKey);
        if ($head === null) {
            return;
        }
        $size = (int)$head['byte_size'];
        if ($size < 1 || $size > self::MAX_POSTER_BYTES) {
            return;
        }
        $this->pdo->prepare('UPDATE ipca_community_post_media SET poster_storage_key = ? WHERE id = ?')
            ->execute(array($posterKey, (int)$row['id']));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPostByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_community_posts WHERE post_uuid = ? LIMIT 1');
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPostById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_community_posts WHERE id = ? LIMIT 1');
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findMedia(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_community_post_media WHERE media_uuid = ? LIMIT 1');
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findComment(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_community_comments WHERE comment_uuid = ? LIMIT 1');
        $stmt->execute(array($uuid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function kindForMime(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        if (in_array($mimeType, self::VIDEO_MIME, true) || str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        return 'photo';
    }

    private function normalizeMime(string $mimeType, string $kind): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        $allowed = $kind === 'video' ? self::VIDEO_MIME : self::PHOTO_MIME;
        if (!in_array($mimeType, $allowed, true)) {
            throw new CommunicationException('validation_error', 'That file type cannot be shared in Community.', 400);
        }
        return $mimeType;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(array('\\', '/'), '', trim($filename));
        $filename = preg_replace('/[\x00-\x1F]/', '', $filename) ?? '';
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'community-media';
        }
        if (strlen($filename) > 255) {
            $filename = substr($filename, 0, 255);
        }
        return $filename;
    }
}
