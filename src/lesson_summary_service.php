<?php
declare(strict_types=1);

/**
 * Lesson Summary Domain Service
 *
 * SSOT RULE:
 * - lesson_summaries is the ONLY live store
 * - this service controls ALL writes
 *
 * This service MUST be used by:
 * - existing summary_save.php
 * - notebook page (later)
 */
final class LessonSummaryService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Save summary through the single centralized write path.
     *
     * Options:
     * - require_unlock_for_accepted: bool
     *      false = preserve current legacy save behavior
     *      true  = accepted summaries require explicit unlock first
     */
    public function saveSummary(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $summaryHtml,
        string $actor = 'student',
        array $options = []
    ): array {
        $requireUnlockForAccepted = !empty($options['require_unlock_for_accepted']);

        $plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags($summaryHtml)));

        if ($plain === '') {
            return ['ok' => false, 'error' => 'Empty summary'];
        }

        $existing = $this->getExisting($userId, $cohortId, $lessonId);

        // Accepted summary protection is opt-in for now so we do not break existing flows.
        if (
            $requireUnlockForAccepted &&
            $existing &&
            (string)$existing['review_status'] === 'acceptable'
        ) {
            return [
                'ok' => false,
                'error' => 'Summary is accepted and must be unlocked before editing'
            ];
        }

        // Skip noise / identical writes
        if ($existing && $this->isSameContent(
            (string)($existing['summary_html'] ?? ''),
            (string)($existing['summary_plain'] ?? ''),
            $summaryHtml,
            $plain
        )) {
            return ['ok' => true, 'skipped' => true];
        }

        $this->pdo->beginTransaction();

        try {
            // Snapshot before meaningful overwrite only
            if ($existing) {
                $this->createVersionSnapshot(
                    $existing,
                    $userId,
                    'manual_save',
                    $actor
                );
            }

            // Preserve existing transition behavior exactly:
            // needs_revision + save => pending
            $newStatus = 'pending';
            if ($existing) {
                $existingReviewStatus = (string)($existing['review_status'] ?? '');
                $newStatus = ($existingReviewStatus !== '') ? $existingReviewStatus : 'pending';

                if ($existingReviewStatus === 'needs_revision') {
                    $newStatus = 'pending';
                }
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO lesson_summaries
                (
                    user_id,
                    cohort_id,
                    lesson_id,
                    summary_html,
                    summary_plain,
                    review_status
                )
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    summary_html = VALUES(summary_html),
                    summary_plain = VALUES(summary_plain),
                    review_status = VALUES(review_status),
                    updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $userId,
                $cohortId,
                $lessonId,
                $summaryHtml,
                $plain,
                $newStatus
            ]);

            $this->pdo->commit();

            return ['ok' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Unlock accepted summary for notebook editing.
     * This is not yet wired into the old player flow.
     */
    public function unlockSummary(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $actor = 'student'
    ): array {
        $existing = $this->getExisting($userId, $cohortId, $lessonId);

        if (!$existing) {
            return ['ok' => false, 'error' => 'Summary not found'];
        }

        if ((string)$existing['review_status'] !== 'acceptable') {
            return ['ok' => true];
        }

        $this->pdo->beginTransaction();

        try {
            $this->createVersionSnapshot(
                $existing,
                $userId,
                'pre_edit_accepted',
                $actor
            );

            $this->logSecurityEvent(
                $userId,
                $cohortId,
                $lessonId,
                'edit_unlock_clicked',
                [
                    'actor' => $actor,
                    'source' => 'lesson_summary_service'
                ]
            );

            $stmt = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET review_status = 'pending',
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
            ");
            $stmt->execute([$userId, $cohortId, $lessonId]);

            $this->pdo->commit();

            return ['ok' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Restore a previous version into the live canonical row.
     *
     * Restore rules:
     * 1. snapshot current live row first
     * 2. restore content fields only
     * 3. set review_status = pending
     * 4. do not revive old approval as active truth
     * 5. do not revive old instructor feedback as active truth
     */
    public function restoreVersion(
        int $versionId,
        int $userId,
        string $actor = 'student'
    ): array {
        $v = $this->pdo->prepare("
            SELECT *
            FROM lesson_summary_versions
            WHERE id = ?
            LIMIT 1
        ");
        $v->execute([$versionId]);
        $version = $v->fetch();

        if (!$version) {
            return ['ok' => false, 'error' => 'Version not found'];
        }

        // Strictly user-scoped
        if ((int)$version['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Forbidden'];
        }

        $existing = $this->getExisting(
            (int)$version['user_id'],
            (int)$version['cohort_id'],
            (int)$version['lesson_id']
        );

        if (!$existing) {
            return ['ok' => false, 'error' => 'Live summary not found'];
        }

        $this->pdo->beginTransaction();

        try {
            $this->createVersionSnapshot(
                $existing,
                $userId,
                'pre_restore',
                $actor
            );

            $stmt = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET
                    summary_html = ?,
                    summary_plain = ?,
                    review_status = 'pending',
                    review_score = NULL,
                    review_feedback = NULL,
                    review_notes_by_instructor = NULL,
                    gap_topics = NULL,
                    reviewed_at = NULL,
                    reviewed_by_user_id = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
            ");

            $stmt->execute([
                (string)$version['summary_html'],
                (string)$version['summary_plain'],
                (int)$version['user_id'],
                (int)$version['cohort_id'],
                (int)$version['lesson_id']
            ]);

            $this->pdo->commit();

            return ['ok' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function getExisting(int $userId, int $cohortId, int $lessonId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM lesson_summaries
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            LIMIT 1
        ");
        $st->execute([$userId, $cohortId, $lessonId]);

        $row = $st->fetch();
        return $row ?: null;
    }

    private function isSameContent(
        string $oldHtml,
        string $oldPlain,
        string $newHtml,
        string $newPlain
    ): bool {
        $oldPlain = trim($oldPlain);
        $newPlain = trim($newPlain);

        if ($oldPlain !== '' && $oldPlain === $newPlain) {
            return true;
        }

        return trim($oldHtml) === trim($newHtml);
    }

    private function createVersionSnapshot(
        array $row,
        int $actorUserId,
        string $reason,
        string $actor = 'student'
    ): void {
        $plain = trim((string)($row['summary_plain'] ?? ''));
        if ($plain === '') {
            return;
        }

        $cnt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM lesson_summary_versions
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
        ");
        $cnt->execute([
            (int)$row['user_id'],
            (int)$row['cohort_id'],
            (int)$row['lesson_id']
        ]);
        $versionNo = ((int)$cnt->fetchColumn()) + 1;

        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_summary_versions
            (
                lesson_summary_id,
                user_id,
                cohort_id,
                lesson_id,
                version_no,
                snapshot_reason,
                summary_html,
                summary_plain,
                source_review_status,
                source_review_score,
                source_updated_at,
                created_by_user_id,
                created_at
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");

        $stmt->execute([
            (int)$row['id'],
            (int)$row['user_id'],
            (int)$row['cohort_id'],
            (int)$row['lesson_id'],
            $versionNo,
            $reason,
            (string)$row['summary_html'],
            (string)$row['summary_plain'],
            (string)($row['review_status'] ?? null),
            $row['review_score'] !== null ? (int)$row['review_score'] : null,
            (string)($row['updated_at'] ?? null),
            $actorUserId
        ]);
    }

    private function logSecurityEvent(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $type,
        array $payload = []
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_summary_security_events
            (
                user_id,
                cohort_id,
                lesson_id,
                event_type,
                payload_json,
                created_at
            )
            VALUES (?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $userId,
            $cohortId,
            $lessonId,
            $type,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    }
}