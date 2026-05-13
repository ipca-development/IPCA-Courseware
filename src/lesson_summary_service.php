<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';

/**
 * Lesson Summary Service
 *
 * Responsibility scope:
 * - canonical lesson summary content writes
 * - accepted-summary unlock flow
 * - version snapshotting
 * - summary restore
 * - notebook read shaping
 *
 * This is NOT the full courseware progression workflow service.
 * It does not own:
 * - attempt threshold escalation
 * - instructor intervention creation
 * - instructor decision workflow
 * - progression email/event orchestration
 *
 * SSOT RULE:
 * - lesson_summaries is the ONLY live canonical store
 * - notebook remains a VIEW layer only
 */
final class LessonSummaryService
{
    private PDO $pdo;

    private const MANUAL_SAVE_KEEP_COUNT = 20;
    private const MANUAL_SAVE_MIN_SECONDS = 600;
    private const MANUAL_SAVE_MIN_WORD_DELTA = 8;
    private const MANUAL_SAVE_MIN_CHAR_DELTA = 40;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Central save path for lesson summaries.
     *
     * Core rule:
     * - accepted summaries cannot be directly overwritten
     * - unlock is required first
     */
    public function saveSummary(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $summaryHtml,
        string $actor = 'student',
        array $options = []
    ): array {
        $plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags($summaryHtml)));

        if ($plain === '') {
            return ['ok' => false, 'error' => 'Empty summary'];
        }

        $existing = $this->getExisting($userId, $cohortId, $lessonId);

        if (
            $existing &&
            (string)$existing['review_status'] === 'acceptable' &&
            (int)($existing['student_soft_locked'] ?? 0) === 1
        ) {
            return [
                'ok' => false,
                'error' => 'Summary is locked and must be unlocked before editing'
            ];
        }

        $isSame = $existing && $this->isSameContent(
            (string)($existing['summary_html'] ?? ''),
            (string)($existing['summary_plain'] ?? ''),
            $summaryHtml,
            $plain
        );

        $wasLocked = $existing && ((int)($existing['student_soft_locked'] ?? 0) === 1);

        if ($existing && $isSame && !$wasLocked) {
            return [
                'ok' => true,
                'skipped' => true,
                'review_status' => (string)($existing['review_status'] ?? 'pending'),
                'student_soft_locked' => (int)($existing['student_soft_locked'] ?? 0)
            ];
        }

        $this->pdo->beginTransaction();

        try {
            if ($existing && $this->shouldCreateManualSaveSnapshot($existing, $summaryHtml, $plain)) {
                $this->createVersionSnapshot(
                    $existing,
                    $userId,
                    'manual_save'
                );

                $this->pruneManualSaveSnapshots(
                    $userId,
                    $cohortId,
                    $lessonId,
                    self::MANUAL_SAVE_KEEP_COUNT
                );
            }

            $reviewStatus = 'pending';
            $reviewScore = null;
            $reviewFeedback = null;
            $gapTopics = null;
            $reviewedAt = null;
            $studentSoftLocked = 0;

            $stmt = $this->pdo->prepare("
                INSERT INTO lesson_summaries
                (
                    user_id,
                    cohort_id,
                    lesson_id,
                    summary_html,
                    summary_plain,
                    review_status,
                    student_soft_locked,
                    review_score,
                    review_feedback,
                    gap_topics,
                    reviewed_at,
                    reviewed_by_user_id,
                    reviewed_by_logic_version
                )
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    summary_html = VALUES(summary_html),
                    summary_plain = VALUES(summary_plain),
                    review_status = VALUES(review_status),
                    review_score = VALUES(review_score),
                    review_feedback = VALUES(review_feedback),
                    gap_topics = VALUES(gap_topics),
                    reviewed_at = VALUES(reviewed_at),
                    reviewed_by_user_id = VALUES(reviewed_by_user_id),
                    reviewed_by_logic_version = VALUES(reviewed_by_logic_version),
                    updated_at = CURRENT_TIMESTAMP,
                    student_soft_locked = VALUES(student_soft_locked)
            ");

            $stmt->execute([
                $userId,
                $cohortId,
                $lessonId,
                $summaryHtml,
                $plain,
                $reviewStatus,
                $studentSoftLocked,
                $reviewScore,
                $reviewFeedback,
                $gapTopics,
                $reviewedAt,
                null,
                'v2.0'
            ]);

            $this->pdo->commit();

            return [
                'ok' => true,
                'review_status' => $reviewStatus,
                'student_soft_locked' => $studentSoftLocked,
                'saved_as_draft' => true
            ];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function checkSummary(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $actor = 'student'
    ): array {
        $existing = $this->getExisting($userId, $cohortId, $lessonId);

        if (!$existing) {
            return ['ok' => false, 'error' => 'Summary not found'];
        }

        $summaryHtml = (string)($existing['summary_html'] ?? '');
        $summaryPlain = trim((string)($existing['summary_plain'] ?? ''));

        if ($summaryPlain === '') {
            return ['ok' => false, 'error' => 'Summary is empty'];
        }

        $evaluation = $this->evaluateSummaryQuality(
            $userId,
            $cohortId,
            $lessonId,
            $summaryHtml,
            $summaryPlain
        );

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET
                    review_status = ?,
                    student_soft_locked = ?,
                    review_score = ?,
                    review_feedback = ?,
                    gap_topics = ?,
                    reviewed_at = ?,
                    reviewed_by_user_id = NULL,
                    reviewed_by_logic_version = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
            ");

            $stmt->execute([
					(string)$evaluation['review_status'],
					((string)$evaluation['review_status'] === 'acceptable') ? 1 : 0,
					isset($evaluation['review_score']) ? (int)$evaluation['review_score'] : null,
					(string)$evaluation['review_feedback'],
					(string)$evaluation['gap_topics'],
					gmdate('Y-m-d H:i:s'),
					(string)($evaluation['logic_version'] ?? 'v2.0'),
					$userId,
					$cohortId,
					$lessonId
				]);

				$activityStmt = $this->pdo->prepare("
					UPDATE lesson_activity
					SET
						summary_status = ?,
						completion_status = ?,
						last_state_eval_at = ?,
						updated_at = CURRENT_TIMESTAMP
					WHERE user_id = ?
					  AND cohort_id = ?
					  AND lesson_id = ?
				");

				$summaryStatus = (string)$evaluation['review_status'];
				$completionStatus = ($summaryStatus === 'acceptable')
					? 'in_progress'
					: 'awaiting_summary_review';

				$nowUtc = gmdate('Y-m-d H:i:s');

				$activityStmt->execute([
					$summaryStatus,
					$completionStatus,
					$nowUtc,
					$userId,
					$cohortId,
					$lessonId
				]);

				$this->pdo->commit();

            return [
                'ok' => true,
                'review_status' => (string)$evaluation['review_status'],
                'student_soft_locked' => ((string)$evaluation['review_status'] === 'acceptable') ? 1 : 0,
                'review_score' => isset($evaluation['review_score']) ? (int)$evaluation['review_score'] : null,
                'review_feedback' => (string)$evaluation['review_feedback'],
                'gap_topics' => (string)$evaluation['gap_topics'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Unlock accepted summary for editing.
     *
     * Required flow:
     * 1. snapshot
     * 2. log security event
     * 3. set review_status to pending
     * 4. caller may then open editor
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
                'pre_edit_accepted'
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
                SET
                    student_soft_locked = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
                  AND cohort_id = ?
                  AND lesson_id = ?
            ");
            $stmt->execute([$userId, $cohortId, $lessonId]);

            $this->pdo->commit();

            return [
                'ok' => true,
                'review_status' => (string)$existing['review_status'],
                'student_soft_locked' => 0
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Restore prior version into the live summary row.
     *
     * Required restore rules:
     * 1. snapshot current live row first
     * 2. restore content only
     * 3. set review_status = pending
     * 4. clear old active instructor review fields
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
                'pre_restore'
            );

            $stmt = $this->pdo->prepare("
                UPDATE lesson_summaries
                SET
                    summary_html = ?,
                    summary_plain = ?,
                    review_status = 'pending',
                    student_soft_locked = 0,
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
     * Truthful notebook scope selector.
     * Current live model is cohort-driven, so selector is cohort/program scoped.
     */
    public function getAvailableNotebookScopes(int $userId): array
    {
        $st = $this->pdo->prepare("
            SELECT
                co.id AS cohort_id,
                co.name AS cohort_name,
                co.start_date,
                co.end_date,
                c.id AS course_id,
                c.title AS course_title,
                p.id AS program_id,
                p.name AS program_name,
                p.sort_order AS program_sort_order
            FROM cohort_students cs
            JOIN cohorts co ON co.id = cs.cohort_id
            JOIN courses c ON c.id = co.course_id
            LEFT JOIN programs p ON p.id = co.program_id
            WHERE cs.user_id = ?
              AND cs.status IN ('active','paused','completed')
            ORDER BY
                COALESCE(p.sort_order, 999999) ASC,
                p.id ASC,
                co.start_date DESC,
                co.id DESC
        ");
        $st->execute([$userId]);
        $rows = $st->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $programName = trim((string)($row['program_name'] ?? ''));
            $courseTitle = trim((string)($row['course_title'] ?? ''));
            $cohortName = trim((string)($row['cohort_name'] ?? ''));

            $label = $programName !== ''
                ? ($programName . ' — ' . $cohortName)
                : ($courseTitle . ' — ' . $cohortName);

            $out[] = [
                'cohort_id' => (int)$row['cohort_id'],
                'cohort_name' => $cohortName,
                'course_id' => (int)$row['course_id'],
                'course_title' => $courseTitle,
                'program_id' => isset($row['program_id']) ? (int)$row['program_id'] : null,
                'program_name' => $programName,
                'label' => $label,
            ];
        }

        return $out;
    }

    /**
     * Notebook view data.
     *
     * Read shaping only. Does not own workflow transitions.
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
                student_soft_locked,
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

        $activitySt = $this->pdo->prepare("
            SELECT
                lesson_id,
                completion_status,
                one_on_one_required,
                one_on_one_completed,
                training_suspended
            FROM lesson_activity
            WHERE user_id = ?
              AND cohort_id = ?
        ");
        $activitySt->execute([$userId, $cohortId]);
        $activityRows = $activitySt->fetchAll();

        $activityMap = [];
        foreach ($activityRows as $row) {
            $activityMap[(int)$row['lesson_id']] = $row;
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
            $activity = $activityMap[$lessonId] ?? null;

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

            $notebookAttentionReason = '';
            $studentActionRequired = false;
            $studentActionReason = '';

            if (in_array($reviewStatus, ['needs_revision', 'rejected'], true)) {
                $studentActionRequired = true;
                $studentActionReason = 'summary_review';
                $notebookAttentionReason = 'summary_review';
            }

            if ($activity) {
                $completionStatus = (string)($activity['completion_status'] ?? '');
                $oneOnOneRequired = (int)($activity['one_on_one_required'] ?? 0);
                $oneOnOneCompleted = (int)($activity['one_on_one_completed'] ?? 0);
                $trainingSuspended = (int)($activity['training_suspended'] ?? 0);

                if ($trainingSuspended === 1) {
                    $notebookAttentionReason = 'training_suspended';
                } elseif ($oneOnOneRequired === 1 && $oneOnOneCompleted !== 1) {
                    $notebookAttentionReason = 'one_on_one_required';
                } elseif (
                    $notebookAttentionReason === '' &&
                    in_array($completionStatus, ['remediation_required', 'blocked_reason_required'], true)
                ) {
                    $notebookAttentionReason = 'workflow_action';
                }
            }

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
                'student_soft_locked' => $summary ? (int)($summary['student_soft_locked'] ?? 0) : 0,
                'read_only_by_default' => ($summary ? (int)($summary['student_soft_locked'] ?? 0) : 0) === 1,
                'student_action_reason' => $studentActionReason,
                'notebook_attention_reason' => $notebookAttentionReason,
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
            'courses' => array_values($courses),
        ];
    }
	
	    public function getNotebookExportData(
        int $userId,
        int $cohortId,
        string $studentName,
        string $exportVersion,
        string $exportTimestamp
    ): array {
        $view = $this->getNotebookViewData($userId, $cohortId, $studentName);

        $cohort = isset($view['cohort']) && is_array($view['cohort']) ? $view['cohort'] : [];

        $programTitle = trim((string)($cohort['program_name'] ?? ''));
        if ($programTitle === '') {
            $programTitle = trim((string)($cohort['course_title'] ?? 'Training Program'));
        }

        $scopeLabel = trim((string)($cohort['scope_label'] ?? ''));
        if ($scopeLabel === '') {
            $scopeLabel = trim((string)($cohort['cohort_name'] ?? ''));
        }
        if ($scopeLabel === '') {
            $scopeLabel = 'Cohort ' . $cohortId;
        }

        $courses = [];
        $courseCount = 0;
        $lessonCount = 0;

        foreach ((array)($view['courses'] ?? []) as $course) {
            $courseLessons = [];

            foreach ((array)($course['lessons'] ?? []) as $lesson) {
                $summaryHtml = (string)($lesson['summary_html'] ?? '');
                $summaryPlain = trim((string)($lesson['summary_plain'] ?? ''));

                if ($summaryPlain === '') {
                    $summaryPlain = trim((string)preg_replace('/\s+/', ' ', strip_tags($summaryHtml)));
                }

                $courseLessons[] = [
                    'lesson_id' => (int)($lesson['lesson_id'] ?? 0),
                    'anchor_id' => (string)($lesson['anchor_id'] ?? ('lesson-' . (int)($lesson['lesson_id'] ?? 0))),
                    'lesson_number' => (string)($lesson['lesson_number'] ?? ''),
                    'lesson_title' => (string)($lesson['lesson_title'] ?? ''),
                    'review_status' => (string)($lesson['review_status'] ?? 'pending'),
                    'word_count' => (int)($lesson['word_count'] ?? 0),
                    'version_count' => (int)($lesson['version_count'] ?? 0),
                    'updated_at' => (string)($lesson['updated_at'] ?? ''),
                    'summary_html' => $summaryHtml,
                    'summary_plain' => $summaryPlain,
                ];

                $lessonCount++;
            }

            $courses[] = [
                'course_id' => (int)($course['course_id'] ?? 0),
                'anchor_id' => (string)($course['anchor_id'] ?? ('course-' . (int)($course['course_id'] ?? 0))),
                'course_number' => (string)($course['course_number'] ?? ''),
                'course_title' => (string)($course['course_title'] ?? ''),
                'lessons' => $courseLessons,
            ];

            $courseCount++;
        }

        return [
            'student_name' => $studentName,
            'program_title' => $programTitle,
            'scope_label' => $scopeLabel,
            'export_version' => $exportVersion,
            'export_timestamp' => $exportTimestamp,
            'course_count' => $courseCount,
            'lesson_count' => $lessonCount,
            'courses' => $courses,
        ];
    }

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

    /**
     * Evaluator dispatcher — v2.1 (Option A) is the ACTIVE evaluator for all users.
     *
     * v2.1 was activated on 2026-05-12 to fix the contradictory-feedback /
     * over-rejection pattern documented in the 2026-05 investigation
     * (see evaluateSummaryQualityV2 below).
     *
     * Failure mode is "fail open to v1": any uncaught exception inside v2 silently
     * falls back to v1, so a v2 bug can never block a student.
     *
     * === HOW TO REVERT TO V1 ===
     * Replace the try/catch block below with a single line:
     *     return $this->evaluateSummaryQualityV1($userId, $cohortId, $lessonId, $summaryHtml, $summaryPlain);
     * v1 remains in this file (evaluateSummaryQualityV1, immediately below) and is
     * byte-identical to the pre-2026-05 implementation, so reverting requires no
     * other changes.
     */
    private function evaluateSummaryQuality(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $summaryHtml,
        string $summaryPlain
    ): array {
        try {
            return $this->evaluateSummaryQualityV2($userId, $cohortId, $lessonId, $summaryHtml, $summaryPlain);
        } catch (Throwable $e) {
            return $this->evaluateSummaryQualityV1($userId, $cohortId, $lessonId, $summaryHtml, $summaryPlain);
        }
    }

    /**
     * Legacy evaluator (v2.0).
     *
     * Kept byte-identical to the pre-2026-05 implementation. The active dispatcher
     * (evaluateSummaryQuality, above) routes everything to v2.1 today and falls back
     * here only when v2 throws. To revert all traffic to v1, replace the dispatcher
     * body with `return $this->evaluateSummaryQualityV1(...)`. Do not modify this
     * function — make any changes in evaluateSummaryQualityV2 instead.
     */
    private function evaluateSummaryQualityV1(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $summaryHtml,
        string $summaryPlain
    ): array {
        $summaryPlain = trim($summaryPlain);

        if ($summaryPlain === '') {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 0,
                'review_feedback' => 'Summary is empty.',
                'gap_topics' => 'No usable summary content provided.',
                'logic_version' => 'v2.0',
            ];
        }

        $minimumWordCount = 35;
        if ($this->wordCount($summaryPlain) < $minimumWordCount) {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 20,
                'review_feedback' => 'Your summary is too short. Add the main aircraft concepts, components, and operational details from the lesson in your own words.',
                'gap_topics' => 'Summary too short; likely missing key lesson concepts.',
                'logic_version' => 'v2.0',
            ];
        }

        $lessonTitle = $this->getLessonTitle($lessonId);
        $sourceText = $this->buildLessonReferenceText($lessonId);

        if ($sourceText === '') {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 0,
                'review_feedback' => 'Automatic summary review could not verify your summary against the lesson content. Please expand and improve your summary.',
                'gap_topics' => 'Lesson reference content unavailable for automatic validation.',
                'logic_version' => 'v2.0',
            ];
        }

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'review_status' => [
                    'type' => 'string',
                    'enum' => ['acceptable', 'needs_revision']
                ],
                'review_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100
                ],
                'review_feedback' => [
                    'type' => 'string'
                ],
                'gap_topics' => [
                    'type' => 'string'
                ]
            ],
            'required' => [
                'review_status',
                'review_score',
                'review_feedback',
                'gap_topics'
            ]
        ];

        $systemPrompt = "You are an aviation training summary evaluator. Your task is to judge whether the student's lesson summary demonstrates adequate understanding of the lesson content. Approve only if the summary is factually aligned with the lesson, covers the important points, and is written as a genuine concise study summary. If important lesson concepts are missing, vague, or inaccurate, mark it needs_revision. Do not require perfect wording. Be practical and training-oriented.";

        $userPrompt = "LESSON TITLE:\n"
            . $lessonTitle
            . "\n\nLESSON REFERENCE CONTENT:\n"
            . $this->truncateForAi($sourceText, 12000)
            . "\n\nSTUDENT SUMMARY:\n"
            . $this->truncateForAi($summaryPlain, 5000)
            . "\n\nEvaluate the student summary.\n"
            . "- acceptable only if it captures the important lesson points with adequate understanding\n"
            . "- needs_revision if it is too vague, incomplete, generic, or misses key concepts\n"
            . "- review_feedback should be short, direct, and student-facing\n"
            . "- gap_topics should list the missing or weak topics in plain text";

        try {
            $resp = cw_openai_responses([
                'model' => cw_openai_model(),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            ['type' => 'input_text', 'text' => $systemPrompt]
                        ]
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $userPrompt]
                        ]
                    ]
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'lesson_summary_quality_review',
                        'schema' => $schema,
                        'strict' => true
                    ]
                ],
                'temperature' => 0.1
            ]);

            $json = cw_openai_extract_json_text($resp);

            $reviewStatus = trim((string)($json['review_status'] ?? 'needs_revision'));
            if (!in_array($reviewStatus, ['acceptable', 'needs_revision'], true)) {
                $reviewStatus = 'needs_revision';
            }

            $reviewScore = isset($json['review_score']) ? (int)$json['review_score'] : null;
            if ($reviewScore !== null) {
                if ($reviewScore < 0) {
                    $reviewScore = 0;
                }
                if ($reviewScore > 100) {
                    $reviewScore = 100;
                }
            }

            $reviewFeedback = trim((string)($json['review_feedback'] ?? ''));
            $gapTopics = trim((string)($json['gap_topics'] ?? ''));

            if ($reviewFeedback === '') {
                $reviewFeedback = $reviewStatus === 'acceptable'
                    ? 'Summary quality is acceptable.'
                    : 'Please revise your summary to better cover the key lesson topics.';
            }

            return [
                'review_status' => $reviewStatus,
                'review_score' => $reviewScore,
                'review_feedback' => $reviewFeedback,
                'gap_topics' => $gapTopics,
                'logic_version' => 'v2.0',
            ];
        } catch (Throwable $e) {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 0,
                'review_feedback' => 'Automatic summary review is temporarily unavailable. Please improve and resave your summary shortly.',
                'gap_topics' => 'Automatic validation unavailable at save time.',
                'logic_version' => 'v2.0',
            ];
        }
    }

    /**
     * New evaluator (v2.1) — Option A from the 2026-05 investigation.
     *
     * Key differences vs v1:
     *   1. Structured output: topics_missing / topics_off_topic / clarity_notes are
     *      separate arrays in the model schema rather than one free-form blob.
     *   2. review_feedback is single-intent — praise on accept, coaching on reject —
     *      never mixed with "however" clauses. Eliminates the contradictory
     *      "add this but you also added that" pattern students were experiencing.
     *   3. Pass threshold is explicitly stated as ~70% coverage of primary concepts.
     *   4. Length is declared irrelevant.
     *   5. Scope tolerance: related-but-adjacent content is treated as normal context,
     *      not as off-topic. The bar for topics_off_topic is set very high.
     *   6. Status is bound to score (>=75 must be acceptable, <60 must be needs_revision)
     *      both in the prompt and as a defense-in-depth post-processing step.
     *   7. The model is explicitly forbidden from listing a topic as missing if the
     *      student already covered it (even briefly or imperfectly).
     *
     * The structured output is composed back into the legacy gap_topics column as
     * a human-readable sectioned text block, so no DB schema migration is required
     * and downstream consumers see a familiar format.
     */
    private function evaluateSummaryQualityV2(
        int $userId,
        int $cohortId,
        int $lessonId,
        string $summaryHtml,
        string $summaryPlain
    ): array {
        $summaryPlain = trim($summaryPlain);

        if ($summaryPlain === '') {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 0,
                'review_feedback' => 'Summary is empty.',
                'gap_topics' => 'No usable summary content provided.',
                'logic_version' => 'v2.1',
            ];
        }

        $minimumWordCount = 35;
        if ($this->wordCount($summaryPlain) < $minimumWordCount) {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 20,
                'review_feedback' => 'Your summary is too short. Add the main lesson concepts in your own words — a brief paragraph or short list covering the major points is enough.',
                'gap_topics' => 'Summary too short; likely missing key lesson concepts.',
                'logic_version' => 'v2.1',
            ];
        }

        $lessonTitle = $this->getLessonTitle($lessonId);
        $sourceText  = $this->buildLessonReferenceText($lessonId);

        if ($sourceText === '') {
            return [
                'review_status' => 'needs_revision',
                'review_score' => 0,
                'review_feedback' => 'Automatic summary review could not verify your summary against the lesson content. Please expand and improve your summary.',
                'gap_topics' => 'Lesson reference content unavailable for automatic validation.',
                'logic_version' => 'v2.1',
            ];
        }

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'review_status' => [
                    'type' => 'string',
                    'enum' => ['acceptable', 'needs_revision']
                ],
                'review_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100
                ],
                'review_feedback' => [
                    'type' => 'string'
                ],
                'topics_missing' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ],
                'topics_off_topic' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ],
                'clarity_notes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ]
            ],
            'required' => [
                'review_status',
                'review_score',
                'review_feedback',
                'topics_missing',
                'topics_off_topic',
                'clarity_notes'
            ]
        ];

        $systemPrompt =
            "You are an aviation training summary evaluator. Your task is to judge whether the student's lesson summary demonstrates adequate understanding of the lesson's PRIMARY concepts.\n\n"
            . "CALIBRATION RULES — apply strictly.\n\n"
            . "1. PASS THRESHOLD. A summary is acceptable when it covers approximately 70% or more of the lesson's primary concepts. Minor details, examples, edge cases, exact numbers, and exhaustive lists are NOT required to pass.\n\n"
            . "2. LENGTH IS IRRELEVANT. Do not penalise concise wording. Do not penalise longer wording. Evaluate coverage of major concepts only. A 100-word focused summary that covers the key concepts is at least as good as a 400-word summary that wanders.\n\n"
            . "3. SCOPE TOLERANCE. Aviation concepts naturally connect, and the student may have seen content on slide images that is not fully text-described in the lesson reference. If the student includes related but adjacent topics (broader system context, chart-reading details, content visible on slide visuals, terminology from related lessons), TREAT THIS AS NORMAL CONTEXT, NOT off-topic. Only flag content in topics_off_topic if it clearly belongs to a completely different lesson. When in doubt, do NOT flag it. The bar for topics_off_topic is intentionally very high.\n\n"
            . "4. STATUS MUST FOLLOW SCORE.\n"
            . "   - If review_score >= 75: review_status MUST be 'acceptable'.\n"
            . "   - If review_score < 60:  review_status MUST be 'needs_revision'.\n"
            . "   - 60-74: choose based on coverage of primary concepts.\n\n"
            . "5. NEVER ASK FOR CONTENT THE STUDENT ALREADY WROTE. Before placing a topic in topics_missing, verify that the student did not cover it. If they mentioned it at all — even briefly or imperfectly — do NOT list it as missing. If their wording on a covered topic is wrong, put the correction in clarity_notes instead.\n\n"
            . "6. review_feedback IS SINGLE-INTENT.\n"
            . "   - If acceptable: brief praise listing 2-4 strengths the student demonstrated. No 'however' clauses. Do NOT mention missing topics, off-topic content, or clarity issues in review_feedback. Use the dedicated arrays for those.\n"
            . "   - If needs_revision: brief actionable guidance naming the 1-3 most important things to add. Do NOT mention off-topic content. Do NOT mention clarity issues. Focus only on what to add.\n\n"
            . "7. clarity_notes is for wording, accuracy, or terminology corrections — NEVER for missing content.\n\n"
            . "8. Empty arrays are encouraged when there is nothing to say in a category. Do not invent items to fill a category.\n\n"
            . "Evaluator version: v2.1.";

        $userPrompt = "LESSON TITLE:\n"
            . $lessonTitle
            . "\n\nLESSON REFERENCE CONTENT:\n"
            . $this->truncateForAi($sourceText, 12000)
            . "\n\nSTUDENT SUMMARY:\n"
            . $this->truncateForAi($summaryPlain, 5000)
            . "\n\nEvaluate the student summary per the rules above. Output structured JSON only.";

        try {
            $resp = cw_openai_responses([
                'model' => cw_openai_model(),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            ['type' => 'input_text', 'text' => $systemPrompt]
                        ]
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $userPrompt]
                        ]
                    ]
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'lesson_summary_quality_review_v2',
                        'schema' => $schema,
                        'strict' => true
                    ]
                ],
                'temperature' => 0.1
            ]);

            $json = cw_openai_extract_json_text($resp);

            $reviewStatus = trim((string)($json['review_status'] ?? 'needs_revision'));
            if (!in_array($reviewStatus, ['acceptable', 'needs_revision'], true)) {
                $reviewStatus = 'needs_revision';
            }

            $reviewScore = isset($json['review_score']) ? (int)$json['review_score'] : null;
            if ($reviewScore !== null) {
                if ($reviewScore < 0)   $reviewScore = 0;
                if ($reviewScore > 100) $reviewScore = 100;
            }

            // Defense in depth: enforce the status/score binding from rule 4 even if the
            // model returns an inconsistent pair.
            if ($reviewScore !== null) {
                if ($reviewScore >= 75 && $reviewStatus !== 'acceptable') {
                    $reviewStatus = 'acceptable';
                }
                if ($reviewScore < 60 && $reviewStatus !== 'needs_revision') {
                    $reviewStatus = 'needs_revision';
                }
            }

            $reviewFeedback = trim((string)($json['review_feedback'] ?? ''));
            if ($reviewFeedback === '') {
                $reviewFeedback = $reviewStatus === 'acceptable'
                    ? 'Good summary. You covered the main lesson concepts.'
                    : 'Please revise your summary to better cover the lesson\'s primary topics.';
            }

            $topicsMissing  = $this->coerceStringList($json['topics_missing']   ?? []);
            $topicsOffTopic = $this->coerceStringList($json['topics_off_topic'] ?? []);
            $clarityNotes   = $this->coerceStringList($json['clarity_notes']    ?? []);

            $gapTopics = $this->composeGapTopicsText($topicsMissing, $topicsOffTopic, $clarityNotes);

            return [
                'review_status'   => $reviewStatus,
                'review_score'    => $reviewScore,
                'review_feedback' => $reviewFeedback,
                'gap_topics'      => $gapTopics,
                'logic_version'   => 'v2.1',
            ];
        } catch (Throwable $e) {
            return [
                'review_status'   => 'needs_revision',
                'review_score'    => 0,
                'review_feedback' => 'Automatic summary review is temporarily unavailable. Please improve and resave your summary shortly.',
                'gap_topics'      => 'Automatic validation unavailable at save time.',
                'logic_version'   => 'v2.1',
            ];
        }
    }

    /**
     * Compose the structured v2 fields back into the legacy gap_topics text column.
     *
     * The student-facing UI consumes review_feedback (single-intent), not gap_topics.
     * gap_topics is retained for instructor/admin DB inspection and for the debug
     * log, so it is rendered as a sectioned human-readable text block. Sections with
     * no items are omitted entirely so the column stays terse when nothing of note
     * was flagged.
     */
    private function composeGapTopicsText(array $missing, array $offTopic, array $clarity): string
    {
        $blocks = [];

        $appendBlock = static function (string $heading, array $items) use (&$blocks): void {
            $lines = [$heading];
            foreach ($items as $item) {
                $t = trim((string)$item);
                if ($t !== '') {
                    $lines[] = '- ' . $t;
                }
            }
            if (count($lines) > 1) {
                $blocks[] = implode("\n", $lines);
            }
        };

        if (!empty($missing)) {
            $appendBlock('Missing topics:', $missing);
        }
        if (!empty($offTopic)) {
            $appendBlock("Outside this lesson's focus (low priority):", $offTopic);
        }
        if (!empty($clarity)) {
            $appendBlock('Clarity / accuracy notes:', $clarity);
        }

        return trim(implode("\n\n", $blocks));
    }

    private function coerceStringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $t = trim($item);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    /**
     * Public replay entry points — for offline harness use only (scripts/replay_summary_evals.php).
     *
     * These do NOT write to the database. They exist solely so the harness can invoke
     * the v1 / v2 evaluators directly to compare verdicts on historical content
     * without going through the env-var-controlled dispatcher.
     *
     * Production paths must always call saveSummary() / checkSummary(), which route
     * through evaluateSummaryQuality() (the dispatcher).
     */
    public function replayEvaluateV1(int $userId, int $cohortId, int $lessonId, string $summaryHtml, string $summaryPlain): array
    {
        return $this->evaluateSummaryQualityV1($userId, $cohortId, $lessonId, $summaryHtml, $summaryPlain);
    }

    public function replayEvaluateV2(int $userId, int $cohortId, int $lessonId, string $summaryHtml, string $summaryPlain): array
    {
        return $this->evaluateSummaryQualityV2($userId, $cohortId, $lessonId, $summaryHtml, $summaryPlain);
    }

    private function buildLessonReferenceText(int $lessonId): string
    {
        $parts = [];

        $lessonTitle = $this->getLessonTitle($lessonId);
        if ($lessonTitle !== '') {
            $parts[] = 'Lesson Title: ' . $lessonTitle;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                s.page_number,
                sao.summary AS ai_summary,
                se.narration_en,
                sc.plain_text
            FROM slides s
            LEFT JOIN slide_ai_outputs sao
              ON sao.slide_id = s.id
             AND sao.status = 'approved'
            LEFT JOIN slide_enrichment se
              ON se.slide_id = s.id
            LEFT JOIN slide_content sc
              ON sc.slide_id = s.id
             AND sc.lang = 'en'
            WHERE s.lesson_id = ?
              AND s.is_deleted = 0
            ORDER BY s.page_number ASC
        ");
        $stmt->execute([$lessonId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $pageNumber = (int)($row['page_number'] ?? 0);
            $pageChunks = [];

            $aiSummary = trim((string)($row['ai_summary'] ?? ''));
            if ($aiSummary !== '') {
                $pageChunks[] = $aiSummary;
            }

            $narration = trim((string)($row['narration_en'] ?? ''));
            if ($narration !== '') {
                $pageChunks[] = $narration;
            }

            $plainText = trim((string)($row['plain_text'] ?? ''));
            if ($plainText !== '') {
                $pageChunks[] = $plainText;
            }

            $pageText = trim(implode("\n", $pageChunks));
            if ($pageText === '') {
                continue;
            }

            $parts[] = 'Slide ' . $pageNumber . ":\n" . $pageText;
        }

        return trim(implode("\n\n", $parts));
    }

    private function getLessonTitle(int $lessonId): string
    {
        $stmt = $this->pdo->prepare("
            SELECT title
            FROM lessons
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$lessonId]);

        $title = $stmt->fetchColumn();

        return is_string($title) ? trim($title) : '';
    }

    private function truncateForAi(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $maxChars) {
                return $text;
            }
            return rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
        }

        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxChars - 1)) . '…';
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
        $oldPlain = trim((string)preg_replace('/\s+/u', ' ', $oldPlain));
        $newPlain = trim((string)preg_replace('/\s+/u', ' ', $newPlain));

        $oldHtml = trim((string)$oldHtml);
        $newHtml = trim((string)$newHtml);

        return ($oldPlain === $newPlain) && ($oldHtml === $newHtml);
    }

    private function shouldCreateManualSaveSnapshot(array $existing, string $newHtml, string $newPlain): bool
    {
        $oldHtml = (string)($existing['summary_html'] ?? '');
        $oldPlain = (string)($existing['summary_plain'] ?? '');
        $updatedAt = trim((string)($existing['updated_at'] ?? ''));

        if ($oldPlain === '') {
            return false;
        }

        if ($this->isSameContent($oldHtml, $oldPlain, $newHtml, $newPlain)) {
            return false;
        }

        $oldWords = $this->wordCount($oldPlain);
        $newWords = $this->wordCount($newPlain);
        $wordDelta = abs($newWords - $oldWords);

        $oldChars = strlen(trim($oldPlain));
        $newChars = strlen(trim($newPlain));
        $charDelta = abs($newChars - $oldChars);

        $enoughContentChange = ($wordDelta >= self::MANUAL_SAVE_MIN_WORD_DELTA)
            || ($charDelta >= self::MANUAL_SAVE_MIN_CHAR_DELTA)
            || $this->hasStructuralHtmlChange($oldHtml, $newHtml);

        if (!$enoughContentChange) {
            return false;
        }

        if ($updatedAt === '') {
            return true;
        }

        $lastTs = strtotime($updatedAt);
        if ($lastTs === false) {
            return true;
        }

        return (time() - $lastTs) >= self::MANUAL_SAVE_MIN_SECONDS;
    }

    private function hasStructuralHtmlChange(string $oldHtml, string $newHtml): bool
    {
        $normalize = static function (string $html): string {
            $html = strtolower($html);
            $html = preg_replace('/\s+/u', ' ', $html);
            $html = preg_replace('/\sstyle="[^"]*"/u', '', $html);
            return trim((string)$html);
        };

        $oldNormalized = $normalize($oldHtml);
        $newNormalized = $normalize($newHtml);

        if ($oldNormalized === $newNormalized) {
            return false;
        }

        $tagsToCheck = ['<ul', '<ol', '<li', '<h1', '<h2', '<h3', '<blockquote', '<mark'];

        foreach ($tagsToCheck as $tag) {
            $oldHas = strpos($oldNormalized, $tag) !== false;
            $newHas = strpos($newNormalized, $tag) !== false;
            if ($oldHas !== $newHas) {
                return true;
            }
        }

        return false;
    }

    private function createVersionSnapshot(array $row, int $actorId, string $reason): void
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

    private function pruneManualSaveSnapshots(int $userId, int $cohortId, int $lessonId, int $keepCount): void
    {
        if ($keepCount < 1) {
            $keepCount = 1;
        }

        $st = $this->pdo->prepare("
            SELECT id
            FROM lesson_summary_versions
            WHERE user_id = ?
              AND cohort_id = ?
              AND lesson_id = ?
              AND snapshot_reason = 'manual_save'
            ORDER BY version_no DESC, id DESC
        ");
        $st->execute([$userId, $cohortId, $lessonId]);
        $rows = $st->fetchAll();

        if (!$rows || count($rows) <= $keepCount) {
            return;
        }

        $deleteIds = [];
        foreach ($rows as $index => $row) {
            if ($index >= $keepCount) {
                $deleteIds[] = (int)$row['id'];
            }
        }

        if (!$deleteIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $del = $this->pdo->prepare("
            DELETE FROM lesson_summary_versions
            WHERE id IN ($placeholders)
        ");
        $del->execute($deleteIds);
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
            'needs_revision', 'rejected' => 'warn',
            'pending' => 'pending',
            default => 'pending',
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