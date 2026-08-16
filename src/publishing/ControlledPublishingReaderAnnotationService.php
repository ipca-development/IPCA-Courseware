<?php
declare(strict_types=1);

final class ControlledPublishingReaderAnnotationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPersonalAnnotations(int $userId, int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_manual_reader_annotations
             WHERE user_id = ? AND book_version_id = ?
             ORDER BY server_updated_at_utc ASC, id ASC'
        );
        $stmt->execute(array($userId, $versionId));

        return array_map(
            fn(array $row): array => $this->publicAnnotation($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array()
        );
    }

    /**
     * @param list<array<string,mixed>> $annotations
     * @return list<array<string,mixed>>
     */
    public function pushPersonalAnnotations(
        int $userId,
        int $versionId,
        string $bookKey,
        array $annotations
    ): array {
        if (count($annotations) > 500) {
            throw new RuntimeException('A maximum of 500 annotation changes may be synchronized.');
        }
        $this->pdo->beginTransaction();
        try {
            foreach ($annotations as $annotation) {
                $this->upsertPersonalAnnotation($userId, $versionId, $bookKey, $annotation);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->listPersonalAnnotations($userId, $versionId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReviewThreads(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.name AS creator_name, u.role AS creator_role,
                    u.photo_path AS creator_photo_path
             FROM ipca_manual_reader_review_threads t
             JOIN users u ON u.id = t.created_by_user_id
             WHERE t.book_version_id = ?
             ORDER BY t.updated_at_utc DESC, t.id DESC'
        );
        $stmt->execute(array($versionId));
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if ($threads === array()) {
            return array();
        }
        $threadIds = array_map(static fn(array $row): int => (int)$row['id'], $threads);
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $commentsStmt = $this->pdo->prepare(
            "SELECT c.*, u.name AS author_name, u.role AS author_role,
                    u.photo_path AS author_photo_path
             FROM ipca_manual_reader_review_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.thread_id IN ({$placeholders}) AND c.deleted_at_utc IS NULL
             ORDER BY c.id ASC"
        );
        $commentsStmt->execute($threadIds);
        $commentsByThread = array();
        foreach ($commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $comment) {
            $commentsByThread[(int)$comment['thread_id']][] = $this->publicComment($comment);
        }

        return array_map(function (array $thread) use ($commentsByThread): array {
            $threadId = (int)$thread['id'];
            return $this->publicThread($thread, $commentsByThread[$threadId] ?? array());
        }, $threads);
    }

    /**
     * @param array<string,mixed> $anchor
     * @return array<string,mixed>
     */
    public function createReviewThread(
        int $userId,
        int $versionId,
        string $bookKey,
        array $anchor,
        string $body
    ): array {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Reviewer note text is required.');
        }
        $threadUuid = $this->uuid((string)($anchor['thread_uuid'] ?? ''));
        $commentUuid = $this->uuid((string)($anchor['comment_uuid'] ?? ''));
        $existingStmt = $this->pdo->prepare(
            'SELECT id, book_version_id, created_by_user_id
             FROM ipca_manual_reader_review_threads WHERE thread_uuid = ? LIMIT 1'
        );
        $existingStmt->execute(array($threadUuid));
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['book_version_id'] !== $versionId
                || (int)$existing['created_by_user_id'] !== $userId) {
                throw new RuntimeException('Reviewer thread UUID belongs to another user or version.');
            }
            $this->insertComment((int)$existing['id'], $userId, $commentUuid, $body, null);
            return $this->threadByUuid($threadUuid);
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ipca_manual_reader_review_threads
                 (thread_uuid, book_version_id, book_key, page_number_snapshot, selected_text,
                  source_fragment_id, stable_anchor, character_start, character_end,
                  created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array(
                $threadUuid,
                $versionId,
                $bookKey,
                max(1, (int)($anchor['page_number'] ?? 1)),
                trim((string)($anchor['selected_text'] ?? '')),
                $this->nullableString($anchor['source_fragment_id'] ?? null),
                $this->nullableString($anchor['stable_anchor'] ?? null),
                max(0, (int)($anchor['start_offset'] ?? 0)),
                max(0, (int)($anchor['end_offset'] ?? 0)),
                $userId,
            ));
            $threadId = (int)$this->pdo->lastInsertId();
            $this->insertComment($threadId, $userId, $commentUuid, $body, null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->threadByUuid($threadUuid);
    }

    /**
     * @param array<string,mixed>|null $regulationReference
     * @return array<string,mixed>
     */
    public function addReviewComment(
        int $userId,
        int $versionId,
        string $threadUuid,
        string $commentUuid,
        string $body,
        ?array $regulationReference = null
    ): array {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Comment text is required.');
        }
        $thread = $this->threadRow($threadUuid);
        if ((int)$thread['book_version_id'] !== $versionId) {
            throw new RuntimeException('Reviewer thread does not belong to this manual version.');
        }
        $this->insertComment(
            (int)$thread['id'],
            $userId,
            $this->uuid($commentUuid),
            $body,
            $regulationReference
        );
        $this->pdo->prepare(
            'UPDATE ipca_manual_reader_review_threads SET updated_at_utc = CURRENT_TIMESTAMP(3)
             WHERE id = ?'
        )->execute(array((int)$thread['id']));

        return $this->threadByUuid($threadUuid);
    }

    /**
     * @param array<string,mixed> $annotation
     */
    private function upsertPersonalAnnotation(
        int $userId,
        int $versionId,
        string $bookKey,
        array $annotation
    ): void {
        $uuid = $this->uuid((string)($annotation['annotation_uuid'] ?? $annotation['id'] ?? ''));
        $kind = strtolower(trim((string)($annotation['kind'] ?? '')));
        if (!in_array($kind, array('bookmark', 'highlight'), true)) {
            throw new RuntimeException('Invalid annotation kind.');
        }
        $ownerStmt = $this->pdo->prepare(
            'SELECT user_id, book_version_id FROM ipca_manual_reader_annotations
             WHERE annotation_uuid = ? LIMIT 1'
        );
        $ownerStmt->execute(array($uuid));
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        if ($owner && ((int)$owner['user_id'] !== $userId || (int)$owner['book_version_id'] !== $versionId)) {
            throw new RuntimeException('Annotation UUID belongs to another user or manual version.');
        }
        $clientUpdated = $this->dateTime((string)($annotation['client_updated_at_utc'] ?? ''));
        $deletedAt = !empty($annotation['deleted_at_utc'])
            ? $this->dateTime((string)$annotation['deleted_at_utc'])
            : null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_manual_reader_annotations
             (annotation_uuid, user_id, book_version_id, book_key, kind, page_number_snapshot,
              label, selected_text, source_fragment_id, stable_anchor, character_start,
              character_end, prefix_text, suffix_text, color_key, personal_note,
              client_updated_at_utc, deleted_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              page_number_snapshot = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(page_number_snapshot), page_number_snapshot),
              label = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc, VALUES(label), label),
              selected_text = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(selected_text), selected_text),
              source_fragment_id = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(source_fragment_id), source_fragment_id),
              stable_anchor = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(stable_anchor), stable_anchor),
              character_start = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(character_start), character_start),
              character_end = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(character_end), character_end),
              prefix_text = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(prefix_text), prefix_text),
              suffix_text = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(suffix_text), suffix_text),
              color_key = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(color_key), color_key),
              personal_note = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(personal_note), personal_note),
              deleted_at_utc = IF(VALUES(client_updated_at_utc) >= client_updated_at_utc,
                  VALUES(deleted_at_utc), deleted_at_utc),
              client_updated_at_utc = GREATEST(client_updated_at_utc, VALUES(client_updated_at_utc))'
        );
        $stmt->execute(array(
            $uuid,
            $userId,
            $versionId,
            $bookKey,
            $kind,
            max(1, (int)($annotation['page_number'] ?? 1)),
            $this->nullableString($annotation['label'] ?? null),
            $this->nullableString($annotation['selected_text'] ?? null),
            $this->nullableString($annotation['source_fragment_id'] ?? null),
            $this->nullableString($annotation['stable_anchor'] ?? null),
            isset($annotation['start_offset']) ? max(0, (int)$annotation['start_offset']) : null,
            isset($annotation['end_offset']) ? max(0, (int)$annotation['end_offset']) : null,
            $this->nullableString($annotation['prefix'] ?? null),
            $this->nullableString($annotation['suffix'] ?? null),
            $this->nullableString($annotation['color'] ?? null),
            $this->nullableString($annotation['personal_note'] ?? null),
            $clientUpdated,
            $deletedAt,
        ));
    }

    private function insertComment(
        int $threadId,
        int $userId,
        string $uuid,
        string $body,
        ?array $regulationReference
    ): void {
        $existingStmt = $this->pdo->prepare(
            'SELECT thread_id, user_id FROM ipca_manual_reader_review_comments
             WHERE comment_uuid = ? LIMIT 1'
        );
        $existingStmt->execute(array($uuid));
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['thread_id'] !== $threadId || (int)$existing['user_id'] !== $userId) {
                throw new RuntimeException('Reviewer comment UUID belongs to another user or thread.');
            }
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ipca_manual_reader_review_comments
             (comment_uuid, thread_id, user_id, body, regulation_reference_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $uuid,
            $threadId,
            $userId,
            trim($body),
            $regulationReference === null
                ? null
                : json_encode($regulationReference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function threadByUuid(string $uuid): array
    {
        $row = $this->threadRow($uuid);
        foreach ($this->listReviewThreads((int)$row['book_version_id']) as $thread) {
            if ((string)$thread['thread_uuid'] === $uuid) {
                return $thread;
            }
        }
        throw new RuntimeException('Reviewer thread not found.');
    }

    /**
     * @return array<string,mixed>
     */
    private function threadRow(string $uuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_manual_reader_review_threads WHERE thread_uuid = ? LIMIT 1'
        );
        $stmt->execute(array($this->uuid($uuid)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Reviewer thread not found.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicAnnotation(array $row): array
    {
        return array(
            'annotation_uuid' => (string)$row['annotation_uuid'],
            'kind' => (string)$row['kind'],
            'book_key' => (string)$row['book_key'],
            'version_id' => (int)$row['book_version_id'],
            'page_number' => (int)$row['page_number_snapshot'],
            'label' => $row['label'],
            'selected_text' => $row['selected_text'],
            'source_fragment_id' => $row['source_fragment_id'],
            'stable_anchor' => $row['stable_anchor'],
            'start_offset' => $row['character_start'] === null ? null : (int)$row['character_start'],
            'end_offset' => $row['character_end'] === null ? null : (int)$row['character_end'],
            'prefix' => $row['prefix_text'],
            'suffix' => $row['suffix_text'],
            'color' => $row['color_key'],
            'personal_note' => $row['personal_note'],
            'client_updated_at_utc' => (string)$row['client_updated_at_utc'],
            'server_updated_at_utc' => (string)$row['server_updated_at_utc'],
            'deleted_at_utc' => $row['deleted_at_utc'],
            'created_at_utc' => (string)$row['created_at_utc'],
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $comments
     * @return array<string,mixed>
     */
    private function publicThread(array $row, array $comments): array
    {
        return array(
            'thread_uuid' => (string)$row['thread_uuid'],
            'version_id' => (int)$row['book_version_id'],
            'book_key' => (string)$row['book_key'],
            'page_number' => (int)$row['page_number_snapshot'],
            'selected_text' => (string)$row['selected_text'],
            'source_fragment_id' => $row['source_fragment_id'],
            'stable_anchor' => $row['stable_anchor'],
            'start_offset' => $row['character_start'] === null ? null : (int)$row['character_start'],
            'end_offset' => $row['character_end'] === null ? null : (int)$row['character_end'],
            'status' => (string)$row['status'],
            'created_at_utc' => (string)$row['created_at_utc'],
            'updated_at_utc' => (string)$row['updated_at_utc'],
            'created_by' => $this->authorPayload(
                (int)$row['created_by_user_id'],
                (string)$row['creator_name'],
                (string)$row['creator_role'],
                $row['creator_photo_path'] === null ? null : (string)$row['creator_photo_path']
            ),
            'comments' => $comments,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicComment(array $row): array
    {
        $regulation = $row['regulation_reference_json'] === null
            ? null
            : json_decode((string)$row['regulation_reference_json'], true);
        return array(
            'comment_uuid' => (string)$row['comment_uuid'],
            'body' => (string)$row['body'],
            'created_at_utc' => (string)$row['created_at_utc'],
            'updated_at_utc' => (string)$row['updated_at_utc'],
            'regulation_reference' => is_array($regulation) ? $regulation : null,
            'author' => $this->authorPayload(
                (int)$row['user_id'],
                (string)$row['author_name'],
                (string)$row['author_role'],
                $row['author_photo_path'] === null ? null : (string)$row['author_photo_path']
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function authorPayload(int $id, string $name, string $role, ?string $photoPath): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: array();
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr((string)$part, 0, 1));
        }
        return array(
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'photo_url' => $photoPath,
            'initials' => $initials !== '' ? $initials : '?',
        );
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new RuntimeException('A valid UUID is required.');
        }
        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function dateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return gmdate('Y-m-d H:i:s.v');
        }
        return gmdate('Y-m-d H:i:s.v', $timestamp);
    }
}
