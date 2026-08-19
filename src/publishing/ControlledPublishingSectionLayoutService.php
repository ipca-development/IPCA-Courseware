<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';
require_once __DIR__ . '/BooksManualsVersionEditPolicy.php';

/**
 * Per-section page header/footer visibility stored in section metadata_json.
 */
final class ControlledPublishingSectionLayoutService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    public function resolveLayout(array $section): array
    {
        $meta = $this->decodeMeta($section);
        $layout = is_array($meta['page_layout'] ?? null) ? $meta['page_layout'] : array();
        $sectionKey = (string)($section['section_key'] ?? '');
        $defaultHide = in_array($sectionKey, ControlledPublishingPageHeaderService::HIDE_HEADER_FOOTER_SECTIONS, true);

        $orientation = strtolower(trim((string)($layout['orientation'] ?? '')));
        if ($orientation !== 'landscape') {
            $annex = is_array($meta['annex'] ?? null) ? $meta['annex'] : array();
            $orientation = strtolower(trim((string)($annex['page_orientation'] ?? 'portrait')));
        }

        return array(
            'hide_header_footer' => (bool)($layout['hide_header_footer'] ?? $defaultHide),
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
        );
    }

    /**
     * @param array<string,mixed> $layout
     */
    public function saveLayout(int $versionId, int $sectionId, array $layout, ?int $actorUserId = null): void
    {
        $section = $this->requireSection($versionId, $sectionId);
        BooksManualsVersionEditPolicy::assertMutationAllowed(
            $section,
            BooksManualsVersionEditPolicy::ANNEX_PRESENTATION
        );
        $meta = $this->decodeMeta($section);
        $orientation = strtolower(trim((string)($layout['orientation'] ?? '')));
        if ($orientation !== 'landscape' && $orientation !== 'portrait') {
            $orientation = $this->resolveLayout($section)['orientation'];
        }
        if ($orientation !== 'landscape') {
            $orientation = 'portrait';
        }
        $meta['page_layout'] = array(
            'hide_header_footer' => !empty($layout['hide_header_footer']),
            'orientation' => $orientation,
        );
        if (is_array($meta['annex'] ?? null)) {
            $meta['annex']['page_orientation'] = $orientation;
        }

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_sections
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND book_version_id = :version_id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $sectionId,
            ':version_id' => $versionId,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function requireSection(int $versionId, int $sectionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, bv.lifecycle_status, b.book_type
              FROM ipca_publishing_book_sections s
              JOIN ipca_publishing_book_versions bv ON bv.id = s.book_version_id
              JOIN ipca_publishing_books b ON b.id = bv.book_id
             WHERE s.id = :id AND s.book_version_id = :version_id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $sectionId, ':version_id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Section not found.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function decodeMeta(array $section): array
    {
        $raw = $section['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}
