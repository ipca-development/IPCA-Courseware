<?php
declare(strict_types=1);

/**
 * Access policy for the student/instructor manual e-reader.
 */
final class ControlledPublishingReaderAccessService
{
    /** @var list<string> */
    private const READER_ROLES = array(
        'student',
        'instructor',
        'supervisor',
        'chief_instructor',
        'admin',
    );

    /** @var list<string> */
    private const DRAFT_PREVIEW_ROLES = array(
        'instructor',
        'supervisor',
        'chief_instructor',
        'admin',
    );

    /**
     * Any logged-in student, instructor/supervisor, chief instructor, or admin may read active released manuals.
     *
     * @param array<string,mixed>|null $user
     */
    public function canReadManuals(?array $user): bool
    {
        if (!is_array($user)) {
            return false;
        }

        $role = strtolower(trim((string)($user['role'] ?? '')));

        return in_array($role, self::READER_ROLES, true);
    }

    /**
     * Instructors and admins may preview draft / in-review / approved versions in the reader.
     *
     * @param array<string,mixed>|null $user
     */
    public function canPreviewDraftManuals(?array $user): bool
    {
        if (!is_array($user)) {
            return false;
        }

        $role = strtolower(trim((string)($user['role'] ?? '')));

        return in_array($role, self::DRAFT_PREVIEW_ROLES, true);
    }

    /**
     * Admins are always allowed. Instructor roles require explicit online approval.
     *
     * @param array<string,mixed>|null $user
     */
    public function canReviewManuals(?array $user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        $role = strtolower(trim((string)($user['role'] ?? '')));
        if ($role === 'admin') {
            return true;
        }
        if (!in_array($role, array('instructor', 'supervisor', 'chief_instructor'), true)) {
            return false;
        }

        return (int)($user['can_manual_reviewer'] ?? 0) === 1;
    }
}
