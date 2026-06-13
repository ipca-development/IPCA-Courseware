<?php
declare(strict_types=1);

/**
 * Surface EASA regulation drift and unresolved MCCF regulation links for review.
 */
final class ControlledPublishingMccfRegulationReviewService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingEasaMonitorChanges(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id, url, label, changed_flag, last_checked_at, last_changed_at
                FROM easa_download_monitor
                WHERE changed_flag = 1
                ORDER BY last_changed_at DESC, id DESC
                LIMIT 20
            ");

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        } catch (Throwable) {
            return array();
        }
    }

    public function hasPendingEasaChanges(): bool
    {
        return $this->listPendingEasaMonitorChanges() !== array();
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $regulationLinks
     */
    public function rowNeedsRegulationReview(array $row, array $regulationLinks, bool $globalEasaChanged): bool
    {
        if (trim((string)($row['regulation_ref'] ?? '')) === '') {
            return false;
        }

        foreach ($regulationLinks as $link) {
            if (($link['match_confidence'] ?? '') === 'UNRESOLVED') {
                return true;
            }
        }

        if ($regulationLinks === array() && $globalEasaChanged) {
            return true;
        }

        return $globalEasaChanged && $regulationLinks !== array();
    }
}
