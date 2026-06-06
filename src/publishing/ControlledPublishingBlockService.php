<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';

/**
 * Controlled publishing block CRUD for the document-style editor.
 */
final class ControlledPublishingBlockService
{
    /** @var list<string> */
    private const AUTHOR_BLOCK_TYPES = array('heading', 'paragraph', 'list', 'table', 'image');

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSectionForEditing(int $versionId, int $sectionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              s.*,
              COALESCE(ts.allow_author_blocks, 1) AS allow_author_blocks,
              bv.lifecycle_status,
              b.book_key,
              bv.version_label
            FROM ipca_publishing_book_sections s
            INNER JOIN ipca_publishing_book_versions bv ON bv.id = s.book_version_id
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            LEFT JOIN ipca_publishing_book_template_sections ts ON ts.id = s.template_section_id
            WHERE s.id = :section_id AND s.book_version_id = :version_id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':section_id' => $sectionId,
            ':version_id' => $versionId,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (!empty($row['parent_section_id'])) {
            $row['allow_author_blocks'] = 1;
        }
        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSectionBlocks(int $sectionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id
            ORDER BY sort_order, id
        ");
        $stmt->execute(array(':section_id' => $sectionId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getBlock(int $blockId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_blocks WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $blockId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createBlock(
        int $versionId,
        int $sectionId,
        string $blockType,
        array $payload,
        ?int $actorUserId = null
    ): int {
        $section = $this->requireEditableSection($versionId, $sectionId);
        $blockType = $this->normalizeBlockType($blockType);
        $payload = $this->normalizePayload($blockType, $payload, false);
        $contentHash = $this->contentHash($blockType, $payload);

        $sortOrder = $this->nextSortOrder($sectionId);
        $blockSeq = $this->nextBlockSequence($versionId, (string)$section['stable_anchor']);
        $blockKey = $this->blockKey((string)$section['section_key'], $blockType, $blockSeq);
        $stableAnchor = (string)$section['stable_anchor'] . '-BLOCK-' . str_pad((string)$blockSeq, 3, '0', STR_PAD_LEFT);

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, :block_type, :sort_order,
                 :payload_json, :content_hash, 0, :actor_user_id, :actor_user_id)
        ");
        $stmt->execute(array(
            ':book_version_id' => $versionId,
            ':section_id' => $sectionId,
            ':block_key' => $blockKey,
            ':stable_anchor' => $stableAnchor,
            ':block_type' => $blockType,
            ':sort_order' => $sortOrder,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $contentHash,
            ':actor_user_id' => $actorUserId,
        ));

        return (int)$this->pdo->lastInsertId();
    }

    public function updateBlock(int $blockId, array $payload, ?int $actorUserId = null): void
    {
        $block = $this->requireEditableBlock($blockId);
        $blockType = (string)$block['block_type'];
        $payload = $this->normalizePayload($blockType, $payload, true);
        $contentHash = $this->contentHash($blockType, $payload);

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_blocks
            SET payload_json = :payload_json,
                content_hash = :content_hash,
                updated_by = :updated_by,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $contentHash,
            ':updated_by' => $actorUserId,
            ':id' => $blockId,
        ));
    }

    public function deleteBlock(int $blockId, ?int $actorUserId = null): void
    {
        $block = $this->requireEditableBlock($blockId);
        if (!empty($block['is_system_managed'])) {
            throw new RuntimeException('System-managed blocks cannot be deleted.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM ipca_publishing_book_blocks WHERE id = :id');
        $stmt->execute(array(':id' => $blockId));
    }

    public function moveBlock(int $blockId, string $direction, ?int $actorUserId = null): void
    {
        $block = $this->requireEditableBlock($blockId);
        $sectionId = (int)$block['section_id'];
        $blocks = $this->listSectionBlocks($sectionId);
        $ids = array();
        foreach ($blocks as $row) {
            $ids[] = (int)$row['id'];
        }
        $index = array_search($blockId, $ids, true);
        if ($index === false) {
            throw new RuntimeException('Block not found in section.');
        }
        if ($direction === 'up' && $index > 0) {
            $tmp = $ids[$index - 1];
            $ids[$index - 1] = $ids[$index];
            $ids[$index] = $tmp;
        } elseif ($direction === 'down' && $index < count($ids) - 1) {
            $tmp = $ids[$index + 1];
            $ids[$index + 1] = $ids[$index];
            $ids[$index] = $tmp;
        } else {
            return;
        }
        $this->reorderBlocks($sectionId, $ids, $actorUserId);
    }

    /**
     * @param list<int> $blockIds
     */
    public function reorderBlocks(int $sectionId, array $blockIds, ?int $actorUserId = null): void
    {
        if ($blockIds === array()) {
            return;
        }
        $stmt = $this->pdo->prepare("
            SELECT b.id, b.book_version_id, bv.lifecycle_status
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_versions bv ON bv.id = b.book_version_id
            WHERE b.section_id = :section_id
        ");
        $stmt->execute(array(':section_id' => $sectionId));
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $existingIds = array();
        foreach ($existing as $row) {
            if ((string)$row['lifecycle_status'] === 'released') {
                throw new RuntimeException('Released versions cannot be edited.');
            }
            $existingIds[(int)$row['id']] = true;
        }
        foreach ($blockIds as $id) {
            if (!isset($existingIds[(int)$id])) {
                throw new RuntimeException('Invalid block order payload.');
            }
        }

        $order = 10;
        $upd = $this->pdo->prepare("
            UPDATE ipca_publishing_book_blocks
            SET sort_order = :sort_order, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND section_id = :section_id
        ");
        foreach ($blockIds as $id) {
            $upd->execute(array(
                ':sort_order' => $order,
                ':updated_by' => $actorUserId,
                ':id' => (int)$id,
                ':section_id' => $sectionId,
            ));
            $order += 10;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function decodePayload(array $block): array
    {
        $raw = $block['payload_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @return array<string,mixed>
     */
    private function requireEditableSection(int $versionId, int $sectionId): array
    {
        $section = $this->getSectionForEditing($versionId, $sectionId);
        if ($section === null) {
            throw new RuntimeException('Section not found for this version.');
        }
        if ((string)$section['lifecycle_status'] === 'released') {
            throw new RuntimeException('Released versions cannot be edited.');
        }
        if (empty($section['allow_author_blocks'])) {
            throw new RuntimeException('This section does not allow author blocks.');
        }
        return $section;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireEditableBlock(int $blockId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              b.*,
              s.section_key,
              s.parent_section_id,
              s.stable_anchor AS section_stable_anchor,
              COALESCE(ts.allow_author_blocks, 0) AS template_allow_blocks,
              bv.lifecycle_status
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            INNER JOIN ipca_publishing_book_versions bv ON bv.id = b.book_version_id
            LEFT JOIN ipca_publishing_book_template_sections ts ON ts.id = s.template_section_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $blockId));
        $block = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($block)) {
            throw new RuntimeException('Block not found.');
        }
        if ((string)$block['lifecycle_status'] === 'released') {
            throw new RuntimeException('Released versions cannot be edited.');
        }
        $allow = !empty($block['template_allow_blocks']) || !empty($block['parent_section_id']);
        if (!$allow) {
            throw new RuntimeException('This section does not allow author blocks.');
        }
        if (!in_array((string)$block['block_type'], self::AUTHOR_BLOCK_TYPES, true)) {
            throw new RuntimeException('This block type cannot be edited.');
        }
        return $block;
    }

    private function normalizeBlockType(string $blockType): string
    {
        $blockType = strtolower(trim($blockType));
        if (!in_array($blockType, self::AUTHOR_BLOCK_TYPES, true)) {
            throw new RuntimeException('Unsupported block type.');
        }
        return $blockType;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePayload(string $blockType, array $payload, bool $strict): array
    {
        return match ($blockType) {
            'heading' => $this->normalizeHeadingPayload($payload, $strict),
            'paragraph' => $this->normalizeParagraphPayload($payload, $strict),
            'list' => $this->normalizeListPayload($payload, $strict),
            'table' => $this->normalizeTablePayload($payload, $strict),
            'image' => $this->normalizeImagePayload($payload, $strict),
            default => throw new RuntimeException('Unsupported block type.'),
        };
    }

    /**
     * @return array{text:string,level:int}
     */
    private function normalizeHeadingPayload(array $payload, bool $strict): array
    {
        $text = trim((string)($payload['text'] ?? ''));
        if ($strict && $text === '') {
            throw new RuntimeException('Heading text is required.');
        }
        $level = (int)($payload['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }
        return array('text' => $text, 'level' => $level);
    }

    /**
     * @return array{html:string}
     */
    private function normalizeParagraphPayload(array $payload, bool $strict): array
    {
        $html = (string)($payload['html'] ?? '');
        if ($html === '' && isset($payload['text'])) {
            $html = nl2br(h((string)$payload['text']), false);
        }
        $html = ControlledPublishingHtmlSanitizer::sanitizeInline($html);
        if ($strict && trim(strip_tags($html)) === '') {
            throw new RuntimeException('Paragraph content is required.');
        }
        return array('html' => $html);
    }

    /**
     * @return array{ordered:bool,items:list<string>}
     */
    private function normalizeListPayload(array $payload, bool $strict): array
    {
        $ordered = !empty($payload['ordered']);
        $items = array();
        if (is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $item) {
                $t = trim((string)$item);
                if ($t !== '') {
                    $items[] = $t;
                }
            }
        }
        if ($strict && $items === array()) {
            throw new RuntimeException('List must contain at least one item.');
        }
        if ($items === array()) {
            $items = array('List item');
        }
        return array('ordered' => $ordered, 'items' => $items);
    }

    /**
     * @return array{rows:list<list<string>>}
     */
    private function normalizeTablePayload(array $payload, bool $strict): array
    {
        $rows = array();
        if (is_array($payload['rows'] ?? null)) {
            foreach ($payload['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $cell) {
                    $line[] = trim((string)$cell);
                }
                if ($line !== array()) {
                    $rows[] = $line;
                }
            }
        }
        if ($rows === array()) {
            $rows = array(
                array('Header 1', 'Header 2'),
                array('', ''),
            );
        }
        return array('rows' => $rows);
    }

    /**
     * @return array{url:string,alt:string,width_pct:int}
     */
    private function normalizeImagePayload(array $payload, bool $strict): array
    {
        $url = trim((string)($payload['url'] ?? ''));
        if ($strict && $url === '') {
            throw new RuntimeException('Image URL is required.');
        }
        return array(
            'url' => $url,
            'alt' => trim((string)($payload['alt'] ?? '')),
            'width_pct' => max(20, min(100, (int)($payload['width_pct'] ?? 100))),
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function contentHash(string $blockType, array $payload): string
    {
        return hash('sha256', $blockType . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function nextSortOrder(int $sectionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM ipca_publishing_book_blocks WHERE section_id = :section_id');
        $stmt->execute(array(':section_id' => $sectionId));
        return ((int)$stmt->fetchColumn()) + 10;
    }

    private function nextBlockSequence(int $versionId, string $sectionStableAnchor): int
    {
        $stmt = $this->pdo->prepare("
            SELECT stable_anchor
            FROM ipca_publishing_book_blocks
            WHERE book_version_id = :version_id
              AND stable_anchor LIKE :anchor_prefix
            ORDER BY stable_anchor DESC
            LIMIT 1
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':anchor_prefix' => $sectionStableAnchor . '-BLOCK-%',
        ));
        $lastAnchor = (string)($stmt->fetchColumn() ?: '');
        if ($lastAnchor === '') {
            return 1;
        }
        if (preg_match('/-BLOCK-(\d+)$/', $lastAnchor, $matches) !== 1) {
            return 1;
        }
        return ((int)$matches[1]) + 1;
    }

    private function blockKey(string $sectionKey, string $blockType, int $sequence): string
    {
        return substr($sectionKey . '_' . $blockType . '_' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT), 0, 128);
    }
}
