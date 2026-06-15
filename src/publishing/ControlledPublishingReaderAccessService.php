<?php
declare(strict_types=1);

/**
 * Access policy for the student/instructor manual e-reader (released content only).
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

    /**
     * MVP: any logged-in student, instructor, chief instructor, or admin may read active released manuals.
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
}
