<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';

/**
 * Hierarchical section numbering (1. / 1.1 / 1.1.1) and MCCF-style regulatory reference hints.
 */
final class ControlledPublishingSectionNumberService
{
    /** @var array<string,int> paragraph_style => 1-based numbering depth */
    public const NUMBERED_STYLE_DEPTHS = array(
        'title' => 1,
        'subtitle_1' => 2,
        'subtitle_2' => 3,
        'subtitle_3' => 4,
        'subtitle_4' => 5,
    );

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingBlockService $blocks
    ) {
    }

    /**
     * @return array{
     *   numbers: array<int,string>,
     *   display: array<int,string>,
     *   nearest_section_number: array<int,string>,
     *   suggested_regulatory_refs: array<int,string>
     * }
     */
    public function computeForVersion(int $versionId, string $manualCode = ''): array
    {
        $manualCode = strtoupper(trim($manualCode));
        $rows = $this->listNumberedBlocks($versionId);

        $counters = array(0, 0, 0, 0, 0, 0);
        $numbers = array();
        $display = array();
        $nearest = array();
        $suggested = array();
        $currentNearest = '';

        foreach ($rows as $row) {
            $blockId = (int)($row['id'] ?? 0);
            if ($blockId <= 0) {
                continue;
            }
            $payload = $this->blocks->decodePayload($row);
            $style = $this->resolveParagraphStyle((string)($row['block_type'] ?? ''), $payload);
            $nearest[$blockId] = $currentNearest;
            if ($manualCode !== '' && $currentNearest !== '') {
                $suggested[$blockId] = $this->formatRegulatoryRef($manualCode, $currentNearest);
            } else {
                $suggested[$blockId] = '';
            }

            $depth = self::NUMBERED_STYLE_DEPTHS[$style] ?? 0;
            if ($depth <= 0) {
                continue;
            }

            $counters[$depth - 1]++;
            for ($i = $depth; $i < count($counters); $i++) {
                $counters[$i] = 0;
            }

            $segments = array();
            for ($i = 0; $i < $depth; $i++) {
                $segments[] = (string)$counters[$i];
            }
            $core = implode('.', $segments);
            $displayText = $style === 'title' ? $core . '.' : $core;

            $numbers[$blockId] = $core;
            $display[$blockId] = $displayText;
            $currentNearest = $displayText;
            $nearest[$blockId] = $displayText;
            if ($manualCode !== '') {
                $suggested[$blockId] = $this->formatRegulatoryRef($manualCode, $displayText);
            }
        }

        return array(
            'numbers' => $numbers,
            'display' => $display,
            'nearest_section_number' => $nearest,
            'suggested_regulatory_refs' => $suggested,
        );
    }

    public function formatRegulatoryRef(string $manualCode, string $sectionNumberDisplay): string
    {
        $manualCode = strtoupper(trim($manualCode));
        $sectionNumberDisplay = trim($sectionNumberDisplay);
        if ($manualCode === '' || $sectionNumberDisplay === '') {
            return '';
        }
        $normalized = rtrim($sectionNumberDisplay, '.');
        return $manualCode . '|' . str_replace('.', '|', $normalized);
    }

    public function formatSectionNumberDisplay(string $style, string $coreNumber): string
    {
        $coreNumber = trim($coreNumber);
        if ($coreNumber === '') {
            return '';
        }
        return $style === 'title' ? $coreNumber . '.' : $coreNumber;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function resolveRegulatoryRef(array $payload, string $suggested = ''): string
    {
        $manual = trim((string)($payload['regulatory_ref'] ?? ''));
        if ($manual !== '') {
            return $manual;
        }
        $auto = trim((string)($payload['regulatory_ref_auto'] ?? ''));
        if ($auto !== '') {
            return $auto;
        }
        return trim($suggested);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listNumberedBlocks(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key NOT IN ('toc', 'highlights', 'cover')
            ORDER BY s.sort_order, s.id, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveParagraphStyle(string $blockType, array $payload): string
    {
        $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        $style = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
        if ($style === '' && $blockType === 'heading') {
            $level = max(1, min(6, (int)($payload['level'] ?? 2)));
            return $level <= 1 ? 'subtitle_2' : ($level === 2 ? 'subtitle_3' : 'subtitle_4');
        }
        return $style;
    }
}
