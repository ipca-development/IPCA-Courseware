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
        'chief_instructor',
        'admin',
    );

    /** @var list<string> */
    private const DRAFT_PREVIEW_ROLES = array(
        'instructor',
        'chief_instructor',
        'admin',
    );

    /**
     * Any logged-in student, instructor, chief instructor, or admin may read active released manuals.
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
}
