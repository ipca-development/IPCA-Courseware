<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';

/**
 * Builds the Table of Contents section from Title / Subtitle / Heading paragraph styles.
 */
final class ControlledPublishingTocService
{
    public function __construct(
        private PDO $pdo,
        private ControlledPublishingBlockService $blocks
    ) {
    }

    /**
     * @return array{section_id:int,entries_count:int,blocks_created:int}
     */
    public function regenerateTocSection(int $versionId, ?int $actorUserId = null): array
    {
        $sectionId = $this->tocSectionId($versionId);
        if ($sectionId <= 0) {
            throw new RuntimeException('Table of Contents section not found for this version.');
        }

        $entries = $this->collectTocEntries($versionId);
        $this->pdo->prepare("
            DELETE FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id AND is_system_managed = 0
        ")->execute(array(':section_id' => $sectionId));

        $section = $this->sectionRow($sectionId);
        $stableBase = (string)($section['stable_anchor'] ?? 'TOC');
        $sort = 10;
        $created = 0;

        $ins = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, :block_type, :sort_order,
                 :payload_json, :content_hash, 0, :actor, :actor)
        ");

        $headingPayload = array(
            'text' => 'Table of Contents',
            'level' => 1,
            'paragraph_style' => 'title',
        );
        $this->insertTocBlock(
            $ins,
            $versionId,
            $sectionId,
            $stableBase,
            'toc_title',
            'heading',
            $headingPayload,
            $sort,
            $actorUserId
        );
        $sort += 10;
        $created++;

        if ($entries === array()) {
            $para = array(
                'html' => '<p>No TOC entries found. Apply Title, Subtitle, or Heading paragraph styles to content blocks.</p>',
                'paragraph_style' => 'body',
            );
            $this->insertTocBlock($ins, $versionId, $sectionId, $stableBase, 'toc_empty', 'paragraph', $para, $sort, $actorUserId);
            return array('section_id' => $sectionId, 'entries_count' => 0, 'blocks_created' => $created + 1);
        }

        $items = array();
        foreach ($entries as $entry) {
            $prefix = str_repeat('  ', max(0, (int)$entry['depth']));
            $items[] = $prefix . (string)$entry['label'];
        }
        $listPayload = array(
            'ordered' => false,
            'items' => $items,
            'paragraph_style' => 'body',
        );
        $this->insertTocBlock($ins, $versionId, $sectionId, $stableBase, 'toc_list', 'list', $listPayload, $sort, $actorUserId);
        $created++;

        return array(
            'section_id' => $sectionId,
            'entries_count' => count($entries),
            'blocks_created' => $created,
        );
    }

    /**
     * @return list<array{label:string,depth:int,style:string,section_title:string}>
     */
    private function collectTocEntries(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, s.title AS section_title, s.section_key, s.sort_order AS section_sort
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key NOT IN ('toc', 'highlights', 'cover')
            ORDER BY s.sort_order, s.id, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $entries = array();
        foreach ($rows as $row) {
            $payload = $this->blocks->decodePayload($row);
            $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
            if ($style === '' && (string)($row['block_type'] ?? '') === 'heading') {
                $level = max(1, min(6, (int)($payload['level'] ?? 2)));
                $style = $level <= 1 ? 'heading_1' : ($level === 2 ? 'heading_2' : 'subtitle_3');
            }
            if (!in_array($style, ControlledPublishingBookStyleService::TOC_PARAGRAPH_STYLE_KEYS, true)) {
                continue;
            }
            $label = $this->entryLabel((string)($row['block_type'] ?? ''), $payload);
            if ($label === '') {
                continue;
            }
            $entries[] = array(
                'label' => $label,
                'depth' => $this->styleDepth($style),
                'style' => $style,
                'section_title' => (string)($row['section_title'] ?? ''),
            );
        }
        return $entries;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function entryLabel(string $blockType, array $payload): string
    {
        if ($blockType === 'heading') {
            return trim((string)($payload['text'] ?? ''));
        }
        if ($blockType === 'paragraph') {
            $html = (string)($payload['html'] ?? '');
            $text = trim(strip_tags($html));
            return $text !== '' ? $text : '';
        }
        if ($blockType === 'list') {
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : array();
            return isset($items[0]) ? trim((string)$items[0]) : '';
        }
        return '';
    }

    private function styleDepth(string $style): int
    {
        return match ($style) {
            'title' => 0,
            'subtitle_1' => 1,
            'heading_1' => 2,
            'heading_2' => 3,
            'subtitle_3' => 4,
            'subtitle_4' => 5,
            default => 0,
        };
    }

    private function tocSectionId(int $versionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = 'toc'
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function sectionRow(int $sectionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_sections WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $sectionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function insertTocBlock(
        PDOStatement $ins,
        int $versionId,
        int $sectionId,
        string $stableBase,
        string $keySuffix,
        string $blockType,
        array $payload,
        int $sort,
        ?int $actorUserId
    ): void {
        $anchor = $stableBase . '-BLOCK-TOC-' . strtoupper($keySuffix);
        $hash = hash('sha256', $blockType . json_encode($payload, JSON_UNESCAPED_UNICODE));
        $ins->execute(array(
            ':book_version_id' => $versionId,
            ':section_id' => $sectionId,
            ':block_key' => 'toc_' . $keySuffix,
            ':stable_anchor' => substr($anchor, 0, 191),
            ':block_type' => $blockType,
            ':sort_order' => $sort,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $hash,
            ':actor' => $actorUserId,
        ));
    }
}
