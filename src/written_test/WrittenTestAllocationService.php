<?php
declare(strict_types=1);

require_once __DIR__ . '/WrittenTestSupport.php';

final class WrittenTestAllocationService
{
    public function __construct(private PDO $pdo)
    {
        WrittenTestSupport::ensureSchema($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    public function listAllocations(?int $cohortId = null): array
    {
        $params = [];
        $where = "a.allocation_status <> 'retired'";
        if ($cohortId !== null && $cohortId > 0) {
            $where .= ' AND a.cohort_id = ?';
            $params[] = $cohortId;
        }

        $st = $this->pdo->prepare("
            SELECT
                a.*,
                co.name AS cohort_name,
                p.display_name AS written_test_program_name,
                p.program_key AS written_test_program_key,
                p.authority,
                p.certificate_type,
                c.title AS related_course_title,
                pv.version_number AS current_policy_version_number,
                pv.published_at AS current_policy_published_at
            FROM cohort_written_test_allocations a
            JOIN cohorts co ON co.id = a.cohort_id
            JOIN written_test_programs p ON p.id = a.written_test_program_id
            LEFT JOIN courses c ON c.id = a.related_course_id
            LEFT JOIN written_test_policy_versions pv ON pv.id = a.current_published_policy_version_id
            WHERE $where
            ORDER BY co.name ASC, a.id DESC
        ");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function getAllocation(int $allocationId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT
                a.*,
                co.name AS cohort_name,
                p.display_name AS written_test_program_name,
                p.program_key AS written_test_program_key,
                p.authority,
                p.certificate_type,
                c.title AS related_course_title,
                pv.version_number AS current_policy_version_number,
                pv.published_at AS current_policy_published_at
            FROM cohort_written_test_allocations a
            JOIN cohorts co ON co.id = a.cohort_id
            JOIN written_test_programs p ON p.id = a.written_test_program_id
            LEFT JOIN courses c ON c.id = a.related_course_id
            LEFT JOIN written_test_policy_versions pv ON pv.id = a.current_published_policy_version_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $st->execute([$allocationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function allocationsForStudent(int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }
        $st = $this->pdo->prepare("
            SELECT
                a.*,
                co.name AS cohort_name,
                p.display_name AS written_test_program_name,
                p.program_key AS written_test_program_key,
                p.authority,
                p.certificate_type,
                c.title AS related_course_title,
                pv.version_number AS current_policy_version_number,
                pv.published_at AS current_policy_published_at
            FROM cohort_students cs
            JOIN cohort_written_test_allocations a ON a.cohort_id = cs.cohort_id
            JOIN cohorts co ON co.id = a.cohort_id
            JOIN written_test_programs p ON p.id = a.written_test_program_id
            LEFT JOIN courses c ON c.id = a.related_course_id
            LEFT JOIN written_test_policy_versions pv ON pv.id = a.current_published_policy_version_id
            WHERE cs.user_id = ?
              AND a.allocation_status <> 'retired'
            ORDER BY FIELD(a.allocation_status, 'active','draft','suspended','completed'), a.id DESC
        ");
        $st->execute([$studentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createAllocation(array $data, int $actorUserId): int
    {
        $cohortId = (int)($data['cohort_id'] ?? 0);
        $programId = (int)($data['written_test_program_id'] ?? 0);
        if ($cohortId <= 0 || $programId <= 0) {
            throw new InvalidArgumentException('Cohort and Written Test Preparation program are required.');
        }

        $relatedCourseId = (int)($data['related_course_id'] ?? 0);
        if ($relatedCourseId <= 0) {
            $course = $this->pdo->prepare('SELECT related_course_id FROM written_test_programs WHERE id = ? LIMIT 1');
            $course->execute([$programId]);
            $relatedCourseId = (int)($course->fetchColumn() ?: 0);
        }

        $st = $this->pdo->prepare("
            INSERT INTO cohort_written_test_allocations
              (cohort_id, written_test_program_id, related_course_id, allocation_status,
               effective_start_at, effective_end_at, allocated_by_user_id, updated_by_user_id)
            VALUES (?, ?, ?, 'draft', ?, ?, ?, ?)
        ");
        $st->execute([
            $cohortId,
            $programId,
            $relatedCourseId > 0 ? $relatedCourseId : null,
            WrittenTestSupport::dateTimeOrNull((string)($data['effective_start_at'] ?? '')),
            WrittenTestSupport::dateTimeOrNull((string)($data['effective_end_at'] ?? '')),
            $actorUserId > 0 ? $actorUserId : null,
            $actorUserId > 0 ? $actorUserId : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function activateAllocation(int $allocationId, int $actorUserId): void
    {
        $allocation = $this->getAllocation($allocationId);
        if (!$allocation) {
            throw new RuntimeException('Allocation not found.');
        }
        if ((int)($allocation['current_published_policy_version_id'] ?? 0) <= 0) {
            throw new RuntimeException('Publish a Written Test Preparation policy snapshot before activation.');
        }

        $this->pdo->prepare("
            UPDATE cohort_written_test_allocations
            SET allocation_status = 'active',
                updated_by_user_id = ?,
                updated_at = UTC_TIMESTAMP(),
                suspended_by_user_id = NULL,
                suspended_at = NULL,
                suspension_reason = NULL
            WHERE id = ?
        ")->execute([$actorUserId > 0 ? $actorUserId : null, $allocationId]);
    }

    public function suspendAllocation(int $allocationId, int $actorUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A suspension reason is required.');
        }
        $this->pdo->prepare("
            UPDATE cohort_written_test_allocations
            SET allocation_status = 'suspended',
                suspended_by_user_id = ?,
                suspended_at = UTC_TIMESTAMP(),
                suspension_reason = ?,
                updated_by_user_id = ?,
                updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([
            $actorUserId > 0 ? $actorUserId : null,
            $reason,
            $actorUserId > 0 ? $actorUserId : null,
            $allocationId,
        ]);
    }

    public function hasStudentAllocation(int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }
        $st = $this->pdo->prepare("
            SELECT 1
            FROM cohort_students cs
            JOIN cohort_written_test_allocations a ON a.cohort_id = cs.cohort_id
            WHERE cs.user_id = ?
              AND a.allocation_status <> 'retired'
            LIMIT 1
        ");
        $st->execute([$studentId]);
        return (bool)$st->fetchColumn();
    }
}
