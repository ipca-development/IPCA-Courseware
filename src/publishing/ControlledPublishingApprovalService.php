<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingLepService.php';

/**
 * Authority approval workflow for LEP e-signatures.
 */
final class ControlledPublishingApprovalService
{
    public function __construct(
        private PDO $pdo,
        private ControlledPublishingLepService $lepService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveApproval(int $versionId): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $raw = is_array($meta['lep_approval'] ?? null) ? $meta['lep_approval'] : array();
        return $this->normalizeApproval($raw);
    }

    /**
     * @return array{approval:array<string,mixed>,approval_url:string}
     */
    public function ensureApprovalToken(int $versionId, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $approval = $this->normalizeApproval(is_array($meta['lep_approval'] ?? null) ? $meta['lep_approval'] : array());
        if ($approval['token'] === '') {
            $approval['token'] = bin2hex(random_bytes(24));
            $approval['created_at'] = date('c');
            $approval['created_by_user_id'] = $actorUserId;
        }
        $approval['token_expires_at'] = date('c', strtotime('+90 days'));
        $meta['lep_approval'] = $approval;
        $this->persistMeta($versionId, $meta);

        return array(
            'approval' => $approval,
            'approval_url' => $this->buildApprovalUrl($versionId, $approval['token']),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getVersionByApprovalToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_publishing_book_versions
            WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.lep_approval.token')) = :token
            LIMIT 1
        ");
        $stmt->execute(array(':token' => $token));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{lep_page:array<string,mixed>,authority:array<string,mixed>}
     */
    public function applyAuthoritySignature(
        int $versionId,
        string $token,
        string $name,
        string $title,
        string $signatureUrl,
        ?int $actorUserId = null
    ): array {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $approval = $this->normalizeApproval(is_array($meta['lep_approval'] ?? null) ? $meta['lep_approval'] : array());
        if ($approval['token'] === '' || !hash_equals($approval['token'], $token)) {
            throw new RuntimeException('Invalid approval token.');
        }
        if ($approval['token_expires_at'] !== '' && strtotime($approval['token_expires_at']) < time()) {
            throw new RuntimeException('Approval token has expired.');
        }

        $lep = $this->lepService->resolveFromMetadata($meta);
        $authority = array(
            'slot_key' => 'authority',
            'name' => trim($name),
            'title' => trim($title) !== '' ? trim($title) : 'Competent Authority',
            'date' => date('d-m-Y'),
            'signature_url' => trim($signatureUrl),
            'signed_at' => date('c'),
            'signed_by_user_id' => $actorUserId,
            'signer_type' => 'authority',
        );
        if ($authority['name'] === '' || $authority['signature_url'] === '') {
            throw new RuntimeException('Authority name and signature are required.');
        }

        $lep['signatories'] = $this->upsertAuthoritySignatory($lep['signatories'], $authority);
        $approval['authority_signed_at'] = date('c');
        $approval['authority_signed_by_user_id'] = $actorUserId;
        $meta['lep_page'] = $lep;
        $meta['lep_approval'] = $approval;
        $this->persistMeta($versionId, $meta);

        return array(
            'lep_page' => $lep,
            'authority' => $authority,
        );
    }

    public function buildApprovalUrl(int $versionId, string $token): string
    {
        return '/admin/compliance/controlled_book_approval.php?version_id=' . $versionId . '&token=' . rawurlencode($token);
    }

    /**
     * @param list<array<string,mixed>> $signatories
     * @param array<string,mixed> $authority
     * @return list<array<string,mixed>>
     */
    private function upsertAuthoritySignatory(array $signatories, array $authority): array
    {
        $found = false;
        foreach ($signatories as $idx => $slot) {
            if ((string)($slot['slot_key'] ?? '') === 'authority' || (string)($slot['signer_type'] ?? '') === 'authority') {
                $signatories[$idx] = $authority;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $signatories[] = $authority;
        }
        return $signatories;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeApproval(array $raw): array
    {
        return array(
            'token' => trim((string)($raw['token'] ?? '')),
            'token_expires_at' => (string)($raw['token_expires_at'] ?? ''),
            'created_at' => (string)($raw['created_at'] ?? ''),
            'created_by_user_id' => isset($raw['created_by_user_id']) ? (int)$raw['created_by_user_id'] : null,
            'authority_signed_at' => (string)($raw['authority_signed_at'] ?? ''),
            'authority_signed_by_user_id' => isset($raw['authority_signed_by_user_id']) ? (int)$raw['authority_signed_by_user_id'] : null,
        );
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function persistMeta(int $versionId, array $meta): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function requireVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_versions WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Version not found.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeMeta(array $version): array
    {
        $raw = $version['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}
