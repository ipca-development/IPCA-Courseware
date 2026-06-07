<?php
declare(strict_types=1);

/**
 * Per-section page header/footer layout stored in section metadata_json.
 */
final class ControlledPublishingSectionLayoutService
{
    private const HIDE_HEADER_FOOTER_SECTIONS = array('cover');

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
        $defaultShow = !in_array($sectionKey, self::HIDE_HEADER_FOOTER_SECTIONS, true);

        return array(
            'show_running_header_footer' => (bool)($layout['show_running_header_footer'] ?? $defaultShow),
            'header_left' => (string)($layout['header_left'] ?? 'IPCA · Controlled Manual'),
            'header_center' => (string)($layout['header_center'] ?? ''),
            'header_right' => (string)($layout['header_right'] ?? ''),
            'footer_left' => (string)($layout['footer_left'] ?? ''),
            'footer_center' => (string)($layout['footer_center'] ?? 'Controlled copy — internal use'),
            'footer_right' => (string)($layout['footer_right'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $layout
     */
    public function saveLayout(int $versionId, int $sectionId, array $layout, ?int $actorUserId = null): void
    {
        $section = $this->requireSection($versionId, $sectionId);
        $meta = $this->decodeMeta($section);
        $meta['page_layout'] = array(
            'show_running_header_footer' => !empty($layout['show_running_header_footer']),
            'header_left' => trim((string)($layout['header_left'] ?? '')),
            'header_center' => trim((string)($layout['header_center'] ?? '')),
            'header_right' => trim((string)($layout['header_right'] ?? '')),
            'footer_left' => trim((string)($layout['footer_left'] ?? '')),
            'footer_center' => trim((string)($layout['footer_center'] ?? '')),
            'footer_right' => trim((string)($layout['footer_right'] ?? '')),
        );

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
            SELECT * FROM ipca_publishing_book_sections
            WHERE id = :id AND book_version_id = :version_id
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
