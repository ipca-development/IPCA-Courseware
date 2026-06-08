<?php
declare(strict_types=1);

/**
 * Cover page layout stored in version metadata_json.
 */
final class ControlledPublishingCoverPageService
{
    public const IPCA_BLUE = '#0f2744';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultCoverPage(): array
    {
        return array(
            'logo_url' => '',
            'logo_alt' => 'EuroPilot Center',
            'company_name' => 'EuroPilot Center',
            'registration_number' => 'B/ATO-017',
            'cover_image_url' => '',
            'cover_image_alt' => '',
            'manual_title' => '',
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function resolveFromMetadata(array $metadata, ?array $version = null): array
    {
        $defaults = $this->defaultCoverPage();
        $raw = is_array($metadata['cover_page'] ?? null) ? $metadata['cover_page'] : array();
        $cover = $this->normalizeCoverPage($raw, $defaults);

        if ($version !== null) {
            $cover = $this->applyVersionDefaults($cover, $version);
        }

        return $cover;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveFromVersion(array $version): array
    {
        $meta = $this->decodeMeta($version);
        return $this->resolveFromMetadata($meta, $version);
    }

    /**
     * @param array<string,mixed> $coverPage
     * @return array<string,mixed>
     */
    public function saveForVersion(int $versionId, array $coverPage, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $defaults = $this->defaultCoverPage();
        $existing = is_array($meta['cover_page'] ?? null) ? $meta['cover_page'] : array();
        $merged = array_merge($existing, $coverPage);
        $normalized = $this->normalizeCoverPage($merged, $defaults);
        $normalized = $this->applyVersionDefaults($normalized, $version);
        $meta['cover_page'] = $normalized;

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));

        return $normalized;
    }

    /**
     * @param array<string,mixed> $version
     */
    public function buildRevisionLine(array $version): string
    {
        $label = trim((string)($version['version_label'] ?? ''));
        if ($label === '') {
            return '';
        }
        return 'Revision ' . $label;
    }

    /**
     * @param array<string,mixed> $version
     */
    public function buildDateLine(array $version): string
    {
        $dateRaw = (string)($version['effective_date'] ?? '');
        if ($dateRaw === '' && !empty($version['released_at'])) {
            $dateRaw = (string)$version['released_at'];
        }
        $formatted = $this->formatDate($dateRaw);
        if ($formatted === '') {
            return '';
        }
        return 'Date: ' . $formatted;
    }

    /**
     * @param array<string,mixed> $version
     */
    public function buildStatusLine(array $version): string
    {
        $status = (string)($version['lifecycle_status'] ?? 'draft');
        $label = match ($status) {
            'draft' => 'Draft',
            'in_review' => 'In review',
            'approved' => 'Approved',
            'released' => 'Released',
            'superseded' => 'Superseded',
            'retired' => 'Retired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
        $versionLabel = trim((string)($version['version_label'] ?? ''));
        if ($versionLabel === '') {
            return 'Status: ' . $label;
        }
        return 'Status: ' . $label . ' version ' . $versionLabel;
    }

    /**
     * @param array<string,mixed> $cover
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function applyVersionDefaults(array $cover, array $version): array
    {
        if (trim((string)($cover['manual_title'] ?? '')) === '') {
            $bookTitle = trim((string)($version['book_title'] ?? $version['title'] ?? ''));
            if ($bookTitle !== '') {
                $cover['manual_title'] = $bookTitle;
            }
        }
        return $cover;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function normalizeCoverPage(array $raw, array $defaults): array
    {
        return array(
            'logo_url' => $this->normalizeImageUrl((string)($raw['logo_url'] ?? $defaults['logo_url'])),
            'logo_alt' => $this->truncate(trim((string)($raw['logo_alt'] ?? $defaults['logo_alt'])), 200),
            'company_name' => $this->truncate(trim((string)($raw['company_name'] ?? $defaults['company_name'])), 200),
            'registration_number' => $this->truncate(trim((string)($raw['registration_number'] ?? $defaults['registration_number'])), 120),
            'cover_image_url' => $this->normalizeImageUrl((string)($raw['cover_image_url'] ?? $defaults['cover_image_url'])),
            'cover_image_alt' => $this->truncate(trim((string)($raw['cover_image_alt'] ?? $defaults['cover_image_alt'])), 200),
            'manual_title' => $this->truncate(trim((string)($raw['manual_title'] ?? $defaults['manual_title'])), 300),
        );
    }

    private function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/')) {
            return $url;
        }
        return '';
    }

    private function formatDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return date('d/m/Y', $ts);
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
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
