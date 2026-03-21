<?php
declare(strict_types=1);

/**
 * Lesson Summary Domain Service
 *
 * SSOT RULE:
 * - lesson_summaries is the ONLY live store
 * - this service controls ALL writes
 * - notebook is a VIEW layer only
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
     * CENTRAL SAVE (SSOT WRITE PATH)
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

        // Accepted lock (only when explicitly required)
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

        // Skip identical content
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
            // Snapshot BEFORE overwrite
            if ($existing) {
                $this->createVersionSnapshot(
                    $existing,
                    $userId,
                    'manual_save',
                    $actor
                );
            }

            // Preserve existing behavior EXACTLY
            $newStatus = 'pending';
            if ($existing) {
                $existingStatus = (string)($existing['review_status'] ?? '');
                $newStatus = $existingStatus !== '' ? $existingStatus : 'pending';

                if ($existingStatus === 'needs_revision') {
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
     * UNLOCK ACCEPTED SUMMARY
     *
     * Sequence:
     * 1. snapshot current live row
     * 2. log security event
     * 3. immediately set live review_status to pending
     * 4. caller may then open edit mode
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
                ['actor' => $actor]
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
     * RESTORE VERSION
     *
     * Restore rules:
     * 1. snapshot current live row first
     * 2. restore content fields only
     * 3. set review_status = pending
     * 4. do not revive prior approval as active truth
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

    /**
     * NOTEBOOK DATA (READ ONLY / DOCUMENT VIEW)
     *
     * This shapes data for presentation only.
     * It does not own business rules or state transitions.
     */
    public function getNotebookViewData(int $userId, int $cohortId, string $studentName): array
    {
        $this->assertStudentEnrollment($userId, $cohortId);

        $co = $this->pdo->prepare("
            SELECT
                co.id,
                co.name,
                co.start_date,
                co.end_date,
                co.program_id,
                c.id AS course_id,
                c.title AS course_title,
                p.name AS program_name
            FROM cohorts co
            JOIN courses c ON c.id = co.course_id
            LEFT JOIN programs p ON p.id = co.program_id
            WHERE co.id = ?
            LIMIT 1
        ");
        $co->execute([$cohortId]);
        $cohort = $co->fetch();

        if (!$cohort) {
            throw new RuntimeException('Cohort not found');
        }

        // Canonical lesson ordering comes from the training structure, not summary rows.
        $lessonRowsSt = $this->pdo->prepare("
            SELECT
                d.id AS deadline_id,
                d.sort_order AS deadline_sort_order,
                l.id AS lesson_id,
                l.external_lesson_id,
                l.title AS lesson_title,
                c.id AS course_id,
                c.title AS course_title,
                c.sort_order AS course_sort_order
            FROM cohort_lesson_deadlines d
            JOIN lessons l ON l.id = d.lesson_id
            JOIN courses c ON c.id = l.course_id
            WHERE d.cohort_id = ?
            ORDER BY c.sort_order ASC, c.id ASC, d.sort_order ASC, d.id ASC
        ");
        $lessonRowsSt->execute([$cohortId]);
        $lessonRows = $lessonRowsSt->fetchAll();

        $summarySt = $this->pdo->prepare("
            SELECT
                id,
                lesson_id,
                summary_html,
                summary_plain,
                review_status,
                review_score,
                review_feedback,
                review_notes_by_instructor,
                updated_at
            FROM lesson_summaries
            WHERE user_id = ?
              AND cohort_id = ?
        ");
        $summarySt->execute([$userId, $cohortId]);
        $summaryRows = $summarySt->fetchAll();

        $summaryMap = [];
        foreach ($summaryRows as $row) {
            $summaryMap[(int)$row['lesson_id']] = $row;
        }

        $versionMetaSt = $this->pdo->prepare("
            SELECT
                lesson_id,
                COUNT(*) AS version_count,
                MAX(created_at) AS latest_version_at
            FROM lesson_summary_versions
            WHERE user_id = ?
              AND cohort_id = ?
            GROUP BY lesson_id
        ");
        $versionMetaSt->execute([$userId, $cohortId]);
        $versionMetaRows = $versionMetaSt->fetchAll();

        $versionMetaMap = [];
        foreach ($versionMetaRows as $row) {
            $versionMetaMap[(int)$row['lesson_id']] = $row;
        }

        $courses = [];
        $lastSavedAt = null;
        $programNumber = '1';

        foreach ($lessonRows as $row) {
            $lessonId = (int)$row['lesson_id'];
            $courseId = (int)$row['course_id'];

            if (!isset($courses[$courseId])) {
                $courseIndex = count($courses) + 1;
                $courseNumber = $programNumber . '.' . $courseIndex;

                $courses[$courseId] = [
                    'course_id' => $courseId,
                    'course_title' => (string)$row['course_title'],
                    'course_number' => $courseNumber,
                    'anchor_id' => 'course-' . $courseId,
                    'lessons' => [],
                ];
            }

            $lessonIndex = count($courses[$courseId]['lessons']) + 1;
            $lessonNumber = $courses[$courseId]['course_number'] . '.' . $lessonIndex;

            $summary = $summaryMap[$lessonId] ?? null;
            $versionMeta = $versionMetaMap[$lessonId] ?? null;

            $summaryHtml = $summary ? (string)$summary['summary_html'] : '';
            $summaryPlain = $summary ? (string)($summary['summary_plain'] ?? '') : '';
            $reviewStatus = $summary ? (string)($summary['review_status'] ?? 'pending') : 'pending';
            $reviewScore = ($summary && $summary['review_score'] !== null) ? (int)$summary['review_score'] : null;
            $updatedAt = $summary ? (string)$summary['updated_at'] : '';

            if ($updatedAt !== '') {
                if ($lastSavedAt === null || strtotime($updatedAt) > strtotime((string)$lastSavedAt)) {
                    $lastSavedAt = $updatedAt;
                }
            }

            $wordCount = $this->wordCount($summaryPlain);

            $courses[$courseId]['lessons'][] = [
                'lesson_id' => $lessonId,
                'external_lesson_id' => (int)$row['external_lesson_id'],
                'lesson_title' => (string)$row['lesson_title'],
                'lesson_number' => $lessonNumber,
                'anchor_id' => 'lesson-' . $lessonId,
                'summary_id' => $summary ? (int)$summary['id'] : null,
                'summary_html' => $summaryHtml,
                'summary_plain' => $summaryPlain,
                'review_status' => $reviewStatus,
                'review_ui_label' => $this->reviewLabel($reviewStatus),
                'review_ui_class' => $this->reviewClass($reviewStatus),
                'review_score' => $reviewScore,
                'word_count' => $wordCount,
                'version_count' => $versionMeta ? (int)$versionMeta['version_count'] : 0,
                'latest_version_at' => $versionMeta ? (string)$versionMeta['latest_version_at'] : '',
                'updated_at' => $updatedAt,
                'instructor_feedback' => $summary ? (string)($summary['review_feedback'] ?? '') : '',
                'instructor_notes' => $summary ? (string)($summary['review_notes_by_instructor'] ?? '') : '',
                'read_only_by_default' => ($reviewStatus === 'acceptable')
            ];
        }

        return [
            'cohort' => [
                'id' => (int)$cohort['id'],
                'name' => (string)$cohort['name'],
                'start_date' => (string)$cohort['start_date'],
                'end_date' => (string)$cohort['end_date'],
                'program_id' => isset($cohort['program_id']) ? (int)$cohort['program_id'] : null,
                'program_name' => (string)($cohort['program_name'] ?? ''),
                'course_id' => (int)$cohort['course_id'],
                'course_title' => (string)$cohort['course_title'],
            ],
            'student_name' => $studentName,
            'last_saved_at' => $lastSavedAt,
            'program_number' => $programNumber,
            'courses' => array_values($courses)
        ];
    }

    /**
     * Compact version history for document disclosure UI.
     */
    public function getVersionsForLesson(int $userId, int $cohortId, int $lessonId, int $limit = 12): array
    {
        $this->assertStudentEnrollment($userId, $cohortId);

        if ($limit <= 0) {
            $limit = 12;
        }
        if ($limit > 25) {
            $limit = 25;
        }

        $sql = "
            SELECT
                id,
                version_no,
                snapshot_reason,
                source_review_status,
                source_review_score,
                source_updated_at,
                summary_plain,
                created_at
            FROM lesson_summary_versions
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
            ORDER BY version_no DESC, id DESC
            LIMIT " . (int)$limit;

        $st = $this->pdo->prepare($sql);
        $st->execute([$userId, $cohortId, $lessonId]);
        $rows = $st->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $plain = trim((string)($row['summary_plain'] ?? ''));
            $out[] = [
                'id' => (int)$row['id'],
                'version_no' => (int)$row['version_no'],
                'snapshot_reason' => (string)$row['snapshot_reason'],
                'snapshot_reason_label' => $this->snapshotReasonLabel((string)$row['snapshot_reason']),
                'source_review_status' => (string)($row['source_review_status'] ?? ''),
                'source_review_label' => $this->reviewLabel((string)($row['source_review_status'] ?? '')),
                'source_review_score' => $row['source_review_score'] !== null ? (int)$row['source_review_score'] : null,
                'source_updated_at' => (string)($row['source_updated_at'] ?? ''),
                'created_at' => (string)$row['created_at'],
                'preview' => $this->excerpt($plain, 220),
            ];
        }

        return $out;
    }

    private function getVersionCount(int $userId, int $cohortId, int $lessonId): int
    {
        $st = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM lesson_summary_versions
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
        ");
        $st->execute([$userId, $cohortId, $lessonId]);
        return (int)$st->fetchColumn();
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

    private function isSameContent(string $oldHtml, string $oldPlain, string $newHtml, string $newPlain): bool
    {
        $oldPlain = trim($oldPlain);
        $newPlain = trim($newPlain);

        if ($oldPlain !== '' && $oldPlain === $newPlain) {
            return true;
        }

        return trim($oldHtml) === trim($newHtml);
    }

    private function createVersionSnapshot(array $row, int $actorId, string $reason, string $actor): void
    {
        if (trim((string)($row['summary_plain'] ?? '')) === '') {
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

        $st = $this->pdo->prepare("
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
                created_by_user_id
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $st->execute([
            (int)$row['id'],
            (int)$row['user_id'],
            (int)$row['cohort_id'],
            (int)$row['lesson_id'],
            $versionNo,
            $reason,
            (string)$row['summary_html'],
            (string)$row['summary_plain'],
            (string)($row['review_status'] ?? ''),
            $row['review_score'] !== null ? (int)$row['review_score'] : null,
            (string)($row['updated_at'] ?? ''),
            $actorId
        ]);
    }

    private function logSecurityEvent(int $userId, int $cohortId, int $lessonId, string $type, array $payload = []): void
    {
        $st = $this->pdo->prepare("
            INSERT INTO lesson_summary_security_events
            (
                user_id,
                cohort_id,
                lesson_id,
                event_type,
                payload_json
            )
            VALUES (?,?,?,?,?)
        ");
        $st->execute([
            $userId,
            $cohortId,
            $lessonId,
            $type,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    }

    private function reviewLabel(string $status): string
    {
        return match ($status) {
            'acceptable' => 'Accepted',
            'needs_revision' => 'Needs revision',
            'rejected' => 'Needs revision',
            'pending' => 'Pending',
            default => 'Draft',
        };
    }

    private function reviewClass(string $status): string
    {
        return match ($status) {
            'acceptable' => 'ok',
            'needs_revision', 'rejected' => 'danger',
            'pending' => 'warn',
            default => 'info',
        };
    }

    private function snapshotReasonLabel(string $reason): string
    {
        return match ($reason) {
            'autosave' => 'Autosave snapshot',
            'manual_save' => 'Save snapshot',
            'restore_point' => 'Restore point',
            'pre_edit_accepted' => 'Pre-unlock snapshot',
            'pre_restore' => 'Pre-restore snapshot',
            'system_backup' => 'System backup',
            default => 'Snapshot',
        };
    }

    private function excerpt(string $plain, int $max): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($plain) <= $max) {
                return $plain;
            }
            return rtrim(mb_substr($plain, 0, $max - 1)) . '…';
        }

        if (strlen($plain) <= $max) {
            return $plain;
        }

        return rtrim(substr($plain, 0, $max - 1)) . '…';
    }

    private function wordCount(string $plain): int
    {
        $plain = trim($plain);
        if ($plain === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $plain);
        return is_array($parts) ? count($parts) : 0;
    }

    private function assertStudentEnrollment(int $userId, int $cohortId): void
    {
        $st = $this->pdo->prepare("
            SELECT 1
            FROM cohort_students
            WHERE cohort_id = ?
              AND user_id = ?
            LIMIT 1
        ");
        $st->execute([$cohortId, $userId]);

        if (!$st->fetchColumn()) {
            throw new RuntimeException('Forbidden');
        }
    }
}