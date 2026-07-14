<?php
declare(strict_types=1);

require_once __DIR__ . '/WrittenTestSupport.php';
require_once __DIR__ . '/WrittenTestAllocationService.php';
require_once __DIR__ . '/WrittenTestPolicyService.php';

final class WrittenTestAccessService
{
    private WrittenTestAllocationService $allocations;
    private WrittenTestPolicyService $policies;

    public function __construct(private PDO $pdo)
    {
        WrittenTestSupport::ensureSchema($this->pdo);
        $this->allocations = new WrittenTestAllocationService($this->pdo);
        $this->policies = new WrittenTestPolicyService($this->pdo);
    }

    /** @return array<string,mixed> */
    public function evaluate(int $studentId, int $allocationId): array
    {
        $allocation = $this->allocations->getAllocation($allocationId);
        if (!$allocation) {
            return $this->state('not_allocated', false, 'Not Allocated', [], null, []);
        }

        $cohortId = (int)$allocation['cohort_id'];
        if (!$this->studentInCohort($studentId, $cohortId)) {
            return $this->state('not_allocated', false, 'Not Allocated', [], $allocation, []);
        }

        $version = $this->policies->currentPolicyVersionForAllocation($allocationId);
        if (!$version) {
            return $this->state('policy_required', false, 'Policy Publication Required', [
                $this->reason('policy_snapshot', 'Publish a Written Test Preparation policy snapshot before students can qualify.'),
            ], $allocation, []);
        }

        $payload = json_decode((string)$version['resolved_policy_json'], true);
        $policy = is_array($payload) && isset($payload['policy']) && is_array($payload['policy']) ? $payload['policy'] : [];
        $overrides = $this->activeOverrides($allocationId, $studentId);
        $requirements = [];
        $lockReasons = [];

        if (!$this->policyBool($policy, 'written_test.preparation_enabled')) {
            $requirements[] = $this->requirement('written_test.preparation_enabled', false, 'Preparation feature enabled', 'Written Test Preparation is disabled by policy.');
        }

        $status = (string)($allocation['allocation_status'] ?? '');
        if ($status !== 'active') {
            $requirements[] = $this->requirement('allocation_status', false, 'Allocation active', 'Allocation status is ' . ($status !== '' ? $status : 'unknown') . '.');
        }

        $now = time();
        $start = trim((string)($allocation['effective_start_at'] ?? ''));
        $end = trim((string)($allocation['effective_end_at'] ?? ''));
        if ($start !== '' && strtotime($start) > $now) {
            $requirements[] = $this->requirement('allocation_start', false, 'Allocation window open', 'Access opens on ' . $start . ' UTC.');
        }
        if ($end !== '' && strtotime($end) < $now) {
            $requirements[] = $this->requirement('allocation_end', false, 'Allocation window still open', 'The allocation window closed on ' . $end . ' UTC.');
        }

        $lessonStats = $this->lessonStats($studentId, $cohortId, (int)($allocation['related_course_id'] ?? 0));
        $completionRequired = max(0, min(100, (int)($policy['written_test.required_ground_school_completion_pct'] ?? 100)));
        $completionOk = (int)$lessonStats['completion_pct'] >= $completionRequired;
        if ($this->policyBool($policy, 'written_test.require_complete_ground_school') || $completionRequired > 0) {
            $requirements[] = $this->requirement(
                'ground_school_completion',
                $completionOk,
                'Ground School completion',
                $lessonStats['completed_lessons'] . '/' . $lessonStats['total_lessons'] . ' scoped lessons complete (' . $lessonStats['completion_pct'] . '% of ' . $completionRequired . '% required).',
                ['stats' => $lessonStats]
            );
        }

        if ($this->policyBool($policy, 'written_test.require_mandatory_summaries')) {
            $summaryOk = (int)$lessonStats['summary_accepted_lessons'] >= (int)$lessonStats['total_lessons'];
            $requirements[] = $this->requirement(
                'mandatory_summaries',
                $summaryOk,
                'Mandatory lesson summaries',
                $lessonStats['summary_accepted_lessons'] . '/' . $lessonStats['total_lessons'] . ' scoped summaries accepted.',
                ['stats' => $lessonStats]
            );
        }

        if ($this->policyBool($policy, 'written_test.require_progress_tests_completed')) {
            $scoreRequired = max(0, min(100, (int)($policy['written_test.minimum_progress_test_score_pct'] ?? 70)));
            $ptOk = (int)$lessonStats['progress_test_passed_lessons'] >= (int)$lessonStats['total_lessons']
                && (int)$lessonStats['lowest_best_score_pct'] >= $scoreRequired;
            $requirements[] = $this->requirement(
                'progress_tests_completed',
                $ptOk,
                'Required Progress Tests',
                $lessonStats['progress_test_passed_lessons'] . '/' . $lessonStats['total_lessons'] . ' scoped Progress Tests passed; lowest best score ' . $lessonStats['lowest_best_score_pct'] . '% of ' . $scoreRequired . '% required.',
                ['stats' => $lessonStats]
            );
        }

        if ($this->policyBool($policy, 'written_test.require_remediation_resolved')) {
            $remediationCount = $this->openRemediationCount($studentId, $cohortId);
            $requirements[] = $this->requirement(
                'remediation_resolved',
                $remediationCount === 0,
                'Remediation resolved',
                $remediationCount === 0 ? 'No open mandatory remediation actions.' : $remediationCount . ' remediation action(s) remain open.'
            );
        }

        if ($this->policyBool($policy, 'written_test.treat_overdue_required_lessons_as_lock_reason')) {
            $requirements[] = $this->requirement(
                'overdue_required_lessons',
                (int)$lessonStats['overdue_incomplete_lessons'] === 0,
                'No overdue required lessons',
                (int)$lessonStats['overdue_incomplete_lessons'] === 0 ? 'No scoped overdue incomplete lessons.' : $lessonStats['overdue_incomplete_lessons'] . ' scoped lesson(s) are overdue and incomplete.',
                ['stats' => $lessonStats]
            );
        }

        if ($this->policyBool($policy, 'written_test.require_instructor_approval')) {
            $requirements[] = $this->requirement(
                'instructor_approval',
                $this->hasApproval($allocationId, $studentId, 'instructor'),
                'Instructor approval',
                'Instructor approval is required for this cohort allocation.'
            );
        }

        if ($this->policyBool($policy, 'written_test.require_administrator_approval')) {
            $requirements[] = $this->requirement(
                'administrator_approval',
                $this->hasApproval($allocationId, $studentId, 'administrator'),
                'Administrator approval',
                'Administrator approval is required for this cohort allocation.'
            );
        }

        $requirements = $this->applyOverrides($requirements, $overrides, $policy);
        foreach ($requirements as $req) {
            if (empty($req['met'])) {
                $lockReasons[] = $this->reason((string)$req['key'], (string)$req['detail']);
            }
        }

        $accessGranted = count($lockReasons) === 0;
        $stateCode = $accessGranted ? 'unlocked' : 'locked';
        $stateLabel = $accessGranted ? 'Unlocked' : 'Locked';
        foreach ($overrides as $override) {
            if (($override['override_action'] ?? '') === 'deny') {
                $accessGranted = false;
                $stateCode = 'manual_denial';
                $stateLabel = 'Manually Denied';
                $lockReasons = [$this->reason('manual_denial', 'Access denied by authorized override: ' . (string)$override['reason'])];
                break;
            }
        }

        $state = $this->state($stateCode, $accessGranted, $stateLabel, $lockReasons, $allocation, $policy);
        $state['policy_version'] = $version;
        $state['requirements'] = $requirements;
        $state['overrides'] = $overrides;
        $state['lesson_stats'] = $lessonStats;
        return $state;
    }

    public function createOverride(array $data, int $actorUserId): int
    {
        $allocationId = (int)($data['allocation_id'] ?? 0);
        $scope = (string)($data['override_scope'] ?? 'student');
        $studentId = (int)($data['student_id'] ?? 0);
        $requirementKey = trim((string)($data['requirement_key'] ?? 'all_access_requirements'));
        $action = trim((string)($data['override_action'] ?? 'waive'));
        $reason = trim((string)($data['reason'] ?? ''));
        if ($allocationId <= 0 || $reason === '' || $actorUserId <= 0) {
            throw new InvalidArgumentException('Allocation, authorized actor, and override reason are required.');
        }
        if (!in_array($scope, ['cohort', 'student'], true)) {
            $scope = 'student';
        }
        if ($scope === 'student' && $studentId <= 0) {
            throw new InvalidArgumentException('Student override requires a student.');
        }
        if (!in_array($action, ['satisfy', 'waive', 'deny'], true)) {
            throw new InvalidArgumentException('Unsupported override action.');
        }

        $st = $this->pdo->prepare("
            INSERT INTO written_test_access_overrides
              (allocation_id, student_id, override_scope, requirement_key, override_action, reason,
               authorized_by_user_id, effective_start_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $allocationId,
            $scope === 'student' ? $studentId : null,
            $scope,
            $requirementKey !== '' ? $requirementKey : 'all_access_requirements',
            $action,
            $reason,
            $actorUserId,
            WrittenTestSupport::dateTimeOrNull((string)($data['effective_start_at'] ?? '')) ?: WrittenTestSupport::utcNow(),
            WrittenTestSupport::dateTimeOrNull((string)($data['expires_at'] ?? '')),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function approveAccess(int $allocationId, int $studentId, string $type, int $actorUserId, string $reason): int
    {
        if (!in_array($type, ['instructor', 'administrator'], true)) {
            throw new InvalidArgumentException('Unsupported approval type.');
        }
        if ($allocationId <= 0 || $studentId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('Allocation, student, and actor are required.');
        }
        $this->pdo->prepare("
            UPDATE written_test_access_approvals
            SET approval_status = 'revoked', revoked_by_user_id = ?, revoked_at = UTC_TIMESTAMP(), revocation_reason = 'Superseded by newer approval'
            WHERE allocation_id = ? AND student_id = ? AND approval_type = ? AND approval_status = 'approved'
        ")->execute([$actorUserId, $allocationId, $studentId, $type]);
        $st = $this->pdo->prepare("
            INSERT INTO written_test_access_approvals
              (allocation_id, student_id, approval_type, approval_status, reason, approved_by_user_id, approved_at, created_by_user_id)
            VALUES (?, ?, ?, 'approved', ?, ?, UTC_TIMESTAMP(), ?)
        ");
        $st->execute([$allocationId, $studentId, $type, trim($reason) ?: null, $actorUserId, $actorUserId]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function lessonStats(int $studentId, int $cohortId, int $courseId): array
    {
        $params = [$cohortId];
        $courseWhere = '';
        if ($courseId > 0) {
            $courseWhere = ' AND l.course_id = ?';
            $params[] = $courseId;
        }
        $st = $this->pdo->prepare("
            SELECT d.lesson_id, d.deadline_utc, l.title AS lesson_title
            FROM cohort_lesson_deadlines d
            JOIN lessons l ON l.id = d.lesson_id
            WHERE d.cohort_id = ? $courseWhere
            ORDER BY l.sort_order ASC, l.id ASC
        ");
        $st->execute($params);
        $lessons = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lessonIds = array_map(static fn($row) => (int)$row['lesson_id'], $lessons);
        $total = count($lessonIds);
        if ($total === 0) {
            return [
                'total_lessons' => 0,
                'completed_lessons' => 0,
                'completion_pct' => 100,
                'summary_accepted_lessons' => 0,
                'progress_test_passed_lessons' => 0,
                'lowest_best_score_pct' => 100,
                'overdue_incomplete_lessons' => 0,
                'incomplete_lesson_titles' => [],
            ];
        }

        $ph = implode(',', array_fill(0, $total, '?'));
        $pt = $this->pdo->prepare("
            SELECT lesson_id, MAX(CASE WHEN pass_gate_met = 1 AND status = 'completed' THEN 1 ELSE 0 END) AS passed,
                   MAX(CASE WHEN status = 'completed' THEN COALESCE(score_pct, 0) ELSE 0 END) AS best_score
            FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id IN ($ph)
            GROUP BY lesson_id
        ");
        $pt->execute(array_merge([$studentId, $cohortId], $lessonIds));
        $ptRows = [];
        foreach ($pt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ptRows[(int)$row['lesson_id']] = $row;
        }

        $sum = $this->pdo->prepare("
            SELECT lesson_id, MAX(CASE WHEN review_status = 'acceptable' THEN 1 ELSE 0 END) AS accepted
            FROM lesson_summaries
            WHERE user_id = ? AND cohort_id = ? AND lesson_id IN ($ph)
            GROUP BY lesson_id
        ");
        $sum->execute(array_merge([$studentId, $cohortId], $lessonIds));
        $summaryRows = [];
        foreach ($sum->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $summaryRows[(int)$row['lesson_id']] = $row;
        }

        $completed = 0;
        $passed = 0;
        $summaryAccepted = 0;
        $lowestBest = 100;
        $overdue = 0;
        $incompleteTitles = [];
        $now = time();
        foreach ($lessons as $lesson) {
            $lessonId = (int)$lesson['lesson_id'];
            $lessonPassed = !empty($ptRows[$lessonId]['passed']);
            $bestScore = isset($ptRows[$lessonId]) ? (int)($ptRows[$lessonId]['best_score'] ?? 0) : 0;
            if ($lessonPassed) {
                $completed++;
                $passed++;
            }
            if (!empty($summaryRows[$lessonId]['accepted'])) {
                $summaryAccepted++;
            }
            $lowestBest = min($lowestBest, $bestScore);
            $deadline = trim((string)($lesson['deadline_utc'] ?? ''));
            if (!$lessonPassed && $deadline !== '' && strtotime($deadline) < $now) {
                $overdue++;
            }
            if (!$lessonPassed && count($incompleteTitles) < 5) {
                $incompleteTitles[] = (string)($lesson['lesson_title'] ?? ('Lesson ' . $lessonId));
            }
        }

        return [
            'total_lessons' => $total,
            'completed_lessons' => $completed,
            'completion_pct' => $total > 0 ? (int)floor(($completed / $total) * 100) : 100,
            'summary_accepted_lessons' => $summaryAccepted,
            'progress_test_passed_lessons' => $passed,
            'lowest_best_score_pct' => $lowestBest,
            'overdue_incomplete_lessons' => $overdue,
            'incomplete_lesson_titles' => $incompleteTitles,
        ];
    }

    private function studentInCohort(int $studentId, int $cohortId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM cohort_students WHERE user_id = ? AND cohort_id = ? LIMIT 1');
        $st->execute([$studentId, $cohortId]);
        return (bool)$st->fetchColumn();
    }

    private function openRemediationCount(int $studentId, int $cohortId): int
    {
        if (!WrittenTestSupport::tableExists($this->pdo, 'student_required_actions')) {
            return 0;
        }
        $st = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM student_required_actions
            WHERE user_id = ?
              AND cohort_id = ?
              AND action_type IN ('remediation_acknowledgement','deadline_reason_submission')
              AND status NOT IN ('completed','approved','closed','cancelled')
        ");
        $st->execute([$studentId, $cohortId]);
        return (int)$st->fetchColumn();
    }

    private function hasApproval(int $allocationId, int $studentId, string $type): bool
    {
        $st = $this->pdo->prepare("
            SELECT 1
            FROM written_test_access_approvals
            WHERE allocation_id = ? AND student_id = ? AND approval_type = ? AND approval_status = 'approved'
            LIMIT 1
        ");
        $st->execute([$allocationId, $studentId, $type]);
        return (bool)$st->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function activeOverrides(int $allocationId, int $studentId): array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM written_test_access_overrides
            WHERE allocation_id = ?
              AND revoked_at IS NULL
              AND effective_start_at <= UTC_TIMESTAMP()
              AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
              AND (
                override_scope = 'cohort'
                OR (override_scope = 'student' AND student_id = ?)
              )
            ORDER BY FIELD(override_action, 'deny','satisfy','waive'), authorized_at DESC, id DESC
        ");
        $st->execute([$allocationId, $studentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string,mixed>> $requirements @param list<array<string,mixed>> $overrides */
    private function applyOverrides(array $requirements, array $overrides, array $policy): array
    {
        $allowStudent = $this->policyBool($policy, 'written_test.allow_manual_student_override');
        $allowCohort = $this->policyBool($policy, 'written_test.allow_manual_cohort_override');
        foreach ($overrides as $override) {
            $scope = (string)($override['override_scope'] ?? '');
            $action = (string)($override['override_action'] ?? '');
            if ($action === 'deny') {
                continue;
            }
            if (($scope === 'student' && !$allowStudent) || ($scope === 'cohort' && !$allowCohort)) {
                continue;
            }
            $key = (string)($override['requirement_key'] ?? 'all_access_requirements');
            foreach ($requirements as $idx => $req) {
                if ($key !== 'all_access_requirements' && $key !== (string)$req['key']) {
                    continue;
                }
                $requirements[$idx]['met'] = true;
                $requirements[$idx]['override_applied'] = true;
                $requirements[$idx]['override_id'] = (int)$override['id'];
                $requirements[$idx]['detail'] = 'Satisfied by authorized override: ' . (string)$override['reason'];
            }
        }
        return $requirements;
    }

    private function policyBool(array $policy, string $key): bool
    {
        return !empty($policy[$key]);
    }

    /** @return array<string,mixed> */
    private function state(string $code, bool $accessGranted, string $label, array $lockReasons, ?array $allocation, array $policy): array
    {
        return [
            'state_code' => $code,
            'state_label' => $label,
            'access_granted' => $accessGranted,
            'lock_reasons' => $lockReasons,
            'allocation' => $allocation,
            'policy' => $policy,
        ];
    }

    /** @return array<string,mixed> */
    private function reason(string $key, string $message): array
    {
        return ['key' => $key, 'message' => $message];
    }

    /** @return array<string,mixed> */
    private function requirement(string $key, bool $met, string $label, string $detail, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'met' => $met,
            'detail' => $detail,
            'override_applied' => false,
        ], $extra);
    }
}
