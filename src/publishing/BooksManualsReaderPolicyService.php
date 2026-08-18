<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderAccessService.php';

/**
 * Additive server-side policy used by existing reader endpoints.
 * If the rollout migration is not installed, legacy reader behavior is preserved.
 */
final class BooksManualsReaderPolicyService
{
    private ?bool $available = null;

    public function __construct(
        private PDO $pdo,
        private ?ControlledPublishingReaderAccessService $legacyAccess = null
    ) {
        $this->legacyAccess ??= new ControlledPublishingReaderAccessService();
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_publishing_version_audiences'");
            $this->available = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $this->available = false;
        }
        return $this->available;
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed>|null $user
     */
    public function canReadVersion(array $version, ?array $user): bool
    {
        if (!$this->legacyAccess->canReadManuals($user)) {
            return false;
        }
        if (!$this->isAvailable()) {
            return true;
        }

        $status = strtolower(trim((string)($version['lifecycle_status'] ?? '')));
        $bookId = (int)($version['book_id'] ?? 0);
        if ($status === 'draft') {
            return false;
        }
        if (strtolower((string)($user['role'] ?? '')) === 'admin') {
            return true;
        }
        if (in_array($status, array('in_review', 'approved'), true)) {
            return $this->isApprovedReviewer($user)
                && $this->isBookReviewer($bookId, (int)($user['id'] ?? 0));
        }
        if ($status !== 'released') {
            return false;
        }
        $policy = $this->approvedReaderPolicy($bookId);
        if ($policy === 'selected_reviewers') {
            return $this->isApprovedReviewer($user)
                && $this->isBookReviewer($bookId, (int)($user['id'] ?? 0));
        }
        return true;
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed>|null $user
     * @return list<array<string,mixed>>
     */
    public function filterAndDecorateLibrary(array $entries, ?array $user): array
    {
        $visible = array();
        foreach ($entries as $entry) {
            $version = $entry;
            $version['id'] = (int)($entry['version_id'] ?? 0);
            if (!$this->canReadVersion($version, $user)) {
                continue;
            }
            $visible[] = $this->decorate($entry);
        }
        return $visible;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function decorate(array $payload): array
    {
        if (!$this->isAvailable()) {
            return $payload;
        }
        $versionId = (int)($payload['version_id'] ?? $payload['id'] ?? 0);
        if ($versionId <= 0) {
            return $payload;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT update_code, update_sequence, source_fingerprint, page_map_hash, manifest_hash
                 FROM ipca_publishing_version_workflow WHERE book_version_id = ? LIMIT 1'
            );
            $stmt->execute(array($versionId));
            $identity = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($identity)) {
                $payload['update_code'] = (string)$identity['update_code'];
                $payload['update_sequence'] = (int)$identity['update_sequence'];
                $payload['source_fingerprint'] = $identity['source_fingerprint'];
                $payload['workflow_page_map_hash'] = $identity['page_map_hash'];
                $payload['workflow_manifest_hash'] = $identity['manifest_hash'];
            }
        } catch (Throwable) {
            // Optional response fields must never make legacy decoding fail.
        }
        return $payload;
    }

    /**
     * @param array<string,mixed>|null $user
     */
    private function approvedReaderPolicy(int $bookId): string
    {
        if ($bookId <= 0) {
            return 'all_readers';
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT approved_reader_policy
                 FROM ipca_publishing_book_profiles WHERE book_id = ? LIMIT 1'
            );
            $stmt->execute(array($bookId));
            return $stmt->fetchColumn() === 'selected_reviewers'
                ? 'selected_reviewers'
                : 'all_readers';
        } catch (Throwable) {
            return 'all_readers';
        }
    }

    private function isBookReviewer(int $bookId, int $userId): bool
    {
        if ($bookId <= 0 || $userId <= 0) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ipca_publishing_book_reviewers
                 WHERE book_id = ? AND reviewer_user_id = ? LIMIT 1'
            );
            $stmt->execute(array($bookId, $userId));
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed>|null $user
     */
    private function isApprovedReviewer(?array $user): bool
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
        if (array_key_exists('can_manual_reviewer', $user)) {
            return (int)$user['can_manual_reviewer'] === 1;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT can_manual_reviewer FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute(array((int)($user['id'] ?? 0)));
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
