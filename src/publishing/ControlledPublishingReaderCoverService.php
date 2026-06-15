<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingCoverPageService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';

/**
 * Resolves cover imagery for the manual bookshelf and reader.
 */
final class ControlledPublishingReaderCoverService
{
    public function __construct(
        private PDO $pdo,
        private ControlledPublishingCoverPageService $coverPageSvc,
        private ControlledPublishingSectionService $sections,
        private ControlledPublishingBlockService $blocks
    ) {
    }

    /**
     * @param array<string,mixed> $version
     * @return array{cover_url:?string,cover_image_url:?string,logo_url:?string,fallback:array<string,mixed>}
     */
    public function resolveCoverForVersion(array $version): array
    {
        $versionId = (int)($version['id'] ?? 0);
        $coverPage = $this->coverPageSvc->resolveFromVersion($version);

        $coverImageUrl = trim((string)($coverPage['cover_image_url'] ?? ''));
        $logoUrl = trim((string)($coverPage['logo_url'] ?? ''));

        if ($coverImageUrl === '' || $logoUrl === '') {
            $blockUrls = $this->extractCoverSectionImageUrls($versionId);
            if ($coverImageUrl === '' && ($blockUrls['cover_image_url'] ?? '') !== '') {
                $coverImageUrl = (string)$blockUrls['cover_image_url'];
            }
            if ($logoUrl === '' && ($blockUrls['logo_url'] ?? '') !== '') {
                $logoUrl = (string)$blockUrls['logo_url'];
            }
        }

        $primaryUrl = $coverImageUrl !== '' ? $coverImageUrl : ($logoUrl !== '' ? $logoUrl : null);

        return array(
            'cover_url' => $primaryUrl,
            'cover_image_url' => $coverImageUrl !== '' ? $coverImageUrl : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
            'fallback' => $this->buildFallbackMeta($version, $coverPage),
        );
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $coverPage
     * @return array<string,mixed>
     */
    private function buildFallbackMeta(array $version, array $coverPage): array
    {
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? '')));
        $manualCode = trim((string)($version['manual_code'] ?? ''));
        if ($manualCode === '') {
            $manualCode = $bookKey;
        }

        $title = trim((string)($coverPage['manual_title'] ?? ''));
        if ($title === '') {
            $title = trim((string)($version['book_title'] ?? ''));
        }

        $releasedAt = trim((string)($version['released_at'] ?? ''));
        $effectiveDate = trim((string)($version['effective_date'] ?? ''));

        return array(
            'book_key' => $bookKey,
            'manual_code' => $manualCode,
            'book_title' => $title,
            'version_label' => trim((string)($version['version_label'] ?? '')),
            'company_name' => trim((string)($coverPage['company_name'] ?? 'EuroPilot Center')),
            'released_at' => $releasedAt,
            'effective_date' => $effectiveDate,
        );
    }

    /**
     * @return array{cover_image_url:string,logo_url:string}
     */
    private function extractCoverSectionImageUrls(int $versionId): array
    {
        $result = array('cover_image_url' => '', 'logo_url' => '');

        foreach ($this->sections()->listFlatSections($versionId) as $row) {
            if ((string)($row['section_key'] ?? '') !== 'cover') {
                continue;
            }
            $sectionId = (int)($row['id'] ?? 0);
            if ($sectionId <= 0) {
                break;
            }

            foreach ($this->blocks()->listSectionBlocks($sectionId) as $block) {
                if ((string)($block['block_type'] ?? '') !== 'image') {
                    continue;
                }
                $payload = $this->decodePayload($block);
                $url = trim((string)($payload['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if ($result['cover_image_url'] === '') {
                    $result['cover_image_url'] = $url;
                } elseif ($result['logo_url'] === '') {
                    $result['logo_url'] = $url;
                }
            }
            break;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function decodePayload(array $block): array
    {
        $raw = $block['payload_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : array();
    }
}
