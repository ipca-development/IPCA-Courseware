<?php
declare(strict_types=1);

require_once __DIR__ . '/BooksManualsAnnexBookService.php';

/**
 * One release-edit boundary for Books/Manuals and Annex Books.
 *
 * Ordinary released Books/Manuals remain immutable. Annex Books use released
 * as their Published state, so their authored content, presentation, and
 * pagination remain editable in place. Manual structure/governance never
 * inherits that Annex exception.
 */
final class BooksManualsVersionEditPolicy
{
    public const ANNEX_CONTENT = 'annex_content';
    public const ANNEX_PRESENTATION = 'annex_presentation';
    public const ANNEX_PAGINATION = 'annex_pagination';
    public const MANUAL_STRUCTURE = 'manual_structure';

    /**
     * @param array<string,mixed> $version
     */
    public static function allowsMutation(array $version, string $scope): bool
    {
        if ((string)($version['lifecycle_status'] ?? '') !== 'released') {
            return true;
        }
        if (!BooksManualsAnnexBookService::allowsReleasedEdits($version)) {
            return false;
        }
        return in_array($scope, array(
            self::ANNEX_CONTENT,
            self::ANNEX_PRESENTATION,
            self::ANNEX_PAGINATION,
        ), true);
    }

    /**
     * @param array<string,mixed> $version
     */
    public static function assertMutationAllowed(
        array $version,
        string $scope,
        string $message = 'Released versions cannot be edited.'
    ): void {
        if (!self::allowsMutation($version, $scope)) {
            throw new RuntimeException($message);
        }
    }
}
