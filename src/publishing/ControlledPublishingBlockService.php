<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';

/**
 * Controlled publishing block CRUD for the document-style editor.
 */
final class ControlledPublishingBlockService
{
    /** @var list<string> */
    private const AUTHOR_BLOCK_TYPES = array('heading', 'paragraph', 'list', 'table', 'image', 'callout');

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
        ?int $actorUserId = null,
        ?int $insertAfterBlockId = null
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

        $newId = (int)$this->pdo->lastInsertId();
        if ($insertAfterBlockId !== null && $insertAfterBlockId > 0) {
            $this->insertBlockAfter($sectionId, $insertAfterBlockId, $newId, $actorUserId);
        }

        return $newId;
    }

    private function insertBlockAfter(
        int $sectionId,
        int $afterBlockId,
        int $newBlockId,
        ?int $actorUserId
    ): void {
        $blocks = $this->listSectionBlocks($sectionId);
        $ids = array();
        foreach ($blocks as $row) {
            $ids[] = (int)$row['id'];
        }
        $ids = array_values(array_filter(
            $ids,
            static fn(int $id): bool => $id !== $newBlockId
        ));
        $index = array_search($afterBlockId, $ids, true);
        if ($index === false) {
            return;
        }
        array_splice($ids, $index + 1, 0, array($newBlockId));
        $this->reorderBlocks($sectionId, $ids, $actorUserId);
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
            'callout' => $this->normalizeCalloutPayload($payload, $strict),
            default => throw new RuntimeException('Unsupported block type.'),
        };
    }

    /**
     * @return array{text:string,level:int}
     */
    private function normalizeHeadingPayload(array $payload, bool $strict): array
    {
        $text = ControlledPublishingHtmlSanitizer::stripLeadingSectionNumberText(
            trim((string)($payload['text'] ?? ''))
        );
        if ($strict && $text === '') {
            throw new RuntimeException('Heading text is required.');
        }
        $level = (int)($payload['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }
        $out = array('text' => $text, 'level' => $level);
        return array_merge($out, $this->normalizeStyleFields($payload));
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
        return array_merge(array('html' => $html), $this->normalizeStyleFields($payload));
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
        return array_merge(
            array('ordered' => $ordered, 'items' => $items),
            $this->normalizeStyleFields($payload)
        );
    }

    /**
     * @return array{title:string,has_title_row:bool,headers:list<string>,rows:list<list<string>>,col_widths:list<int>}
     */
    private function normalizeTablePayload(array $payload, bool $strict): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $hasTitleRow = !empty($payload['has_title_row']);
        $headers = array();
        $rows = array();
        $colWidths = array();

        if (is_array($payload['headers'] ?? null)) {
            foreach ($payload['headers'] as $cell) {
                $headers[] = trim((string)$cell);
            }
        }

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

        // Legacy tables stored everything in rows[] with first row as header.
        if ($headers === array() && $rows !== array()) {
            $headers = array_shift($rows) ?: array();
        }

        if ($headers === array()) {
            $headers = array('Column 1', 'Column 2');
        }
        if ($rows === array()) {
            $rows = array(array_fill(0, count($headers), ''));
        }

        $colCount = count($headers);
        $headers = array_pad(array_slice($headers, 0, $colCount), $colCount, '');
        $normalizedRows = array();
        foreach ($rows as $row) {
            $normalizedRows[] = array_pad(array_slice($row, 0, $colCount), $colCount, '');
        }

        if (is_array($payload['col_widths'] ?? null)) {
            foreach ($payload['col_widths'] as $width) {
                $colWidths[] = max(60, min(600, (int)$width));
            }
        }
        $colWidths = array_pad(array_slice($colWidths, 0, $colCount), $colCount, 140);

        $borderWidth = strtolower(trim((string)($payload['border_width'] ?? 'medium')));
        if (!in_array($borderWidth, array('thin', 'medium', 'thick'), true)) {
            $borderWidth = 'medium';
        }
        $borderColor = $this->normalizeTableHexColor((string)($payload['border_color'] ?? ''), '#94a3b8');

        $headerBg = array();
        if (is_array($payload['header_bg'] ?? null)) {
            foreach ($payload['header_bg'] as $color) {
                $headerBg[] = $this->normalizeTableHexColor((string)$color, '');
            }
        }
        $headerBg = array_pad(array_slice($headerBg, 0, $colCount), $colCount, '');

        $titleBg = $this->normalizeTableHexColor((string)($payload['title_bg'] ?? ''), '');

        $cellBg = array();
        if (is_array($payload['cell_bg'] ?? null)) {
            foreach ($payload['cell_bg'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $color) {
                    $line[] = $this->normalizeTableHexColor((string)$color, '');
                }
                $cellBg[] = array_pad(array_slice($line, 0, $colCount), $colCount, '');
            }
        }
        $cellBg = array_pad(array_slice($cellBg, 0, count($normalizedRows)), count($normalizedRows), array());
        foreach ($cellBg as $idx => $row) {
            $cellBg[$idx] = array_pad(array_slice($row, 0, $colCount), $colCount, '');
        }
        while (count($cellBg) < count($normalizedRows)) {
            $cellBg[] = array_fill(0, $colCount, '');
        }

        $titleAlign = $this->normalizeTableCellAlign((string)($payload['title_align'] ?? ''), 'center');
        $titleFontFamily = $this->normalizeTableCellFont((string)($payload['title_font_family'] ?? ''), 'serif');
        $titleFontSize = $this->normalizeTableCellFontSize($payload['title_font_size'] ?? 11);

        $headerAlign = array();
        if (is_array($payload['header_align'] ?? null)) {
            foreach ($payload['header_align'] as $align) {
                $headerAlign[] = $this->normalizeTableCellAlign((string)$align, 'left');
            }
        }
        $headerAlign = array_pad(array_slice($headerAlign, 0, $colCount), $colCount, 'left');

        $cellAlign = array();
        if (is_array($payload['cell_align'] ?? null)) {
            foreach ($payload['cell_align'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $align) {
                    $line[] = $this->normalizeTableCellAlign((string)$align, 'left');
                }
                $cellAlign[] = array_pad(array_slice($line, 0, $colCount), $colCount, 'left');
            }
        }
        while (count($cellAlign) < count($normalizedRows)) {
            $cellAlign[] = array_fill(0, $colCount, 'left');
        }

        return array(
            'title' => $title,
            'has_title_row' => $hasTitleRow,
            'headers' => $headers,
            'rows' => $normalizedRows,
            'col_widths' => $colWidths,
            'border_width' => $borderWidth,
            'border_color' => $borderColor,
            'title_bg' => $titleBg,
            'header_bg' => $headerBg,
            'cell_bg' => $cellBg,
            'title_align' => $titleAlign,
            'title_font_family' => $titleFontFamily,
            'title_font_size' => $titleFontSize,
            'header_align' => $headerAlign,
            'cell_align' => $cellAlign,
            'table_align' => $this->normalizeTableCellAlign((string)($payload['table_align'] ?? ''), 'left'),
        );
    }

    private function normalizeTableCellAlign(string $align, string $default): string
    {
        $align = strtolower(trim($align));
        return in_array($align, array('left', 'center', 'right'), true) ? $align : $default;
    }

    private function normalizeTableCellFont(string $font, string $default): string
    {
        $fonts = array('serif', 'sans', 'mono', 'arial', 'manuallabel', 'manualtitle', 'sectiontitle');
        $font = strtolower(trim($font));
        return in_array($font, $fonts, true) ? $font : $default;
    }

    private function normalizeTableCellFontSize(mixed $size): int
    {
        $allowed = array(8, 9, 10, 11, 12, 14, 16, 18);
        $fontSize = (int)$size;
        return in_array($fontSize, $allowed, true) ? $fontSize : 11;
    }

    private function normalizeTableHexColor(string $color, string $fallback): string
    {
        $color = trim($color);
        if ($color === '') {
            return $fallback === '' ? '' : $fallback;
        }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1) {
            return strtolower($color);
        }
        return $fallback;
    }

    /**
     * @return array{callout_type:string,title:string,text:string}
     */
    private function normalizeCalloutPayload(array $payload, bool $strict): array
    {
        $type = strtolower(trim((string)($payload['callout_type'] ?? 'warning')));
        if (!in_array($type, array('warning', 'caution', 'info'), true)) {
            $type = 'warning';
        }
        $title = trim((string)($payload['title'] ?? strtoupper($type)));
        $text = trim((string)($payload['text'] ?? ''));
        if ($strict && $text === '') {
            throw new RuntimeException('Callout text is required.');
        }
        return array(
            'callout_type' => $type,
            'title' => $title !== '' ? $title : strtoupper($type),
            'text' => $text,
        );
    }

    /**
     * @return array{font_family:string,text_align:string,font_size:int}
     */
    private function normalizeStyleFields(array $payload): array
    {
        $fonts = array('serif', 'sans', 'mono', 'arial');
        $font = strtolower(trim((string)($payload['font_family'] ?? 'serif')));
        if (in_array($font, array('manuallabel', 'manualtitle', 'sectiontitle'), true)) {
            $font = 'sans';
        }
        if (!in_array($font, $fonts, true)) {
            $font = 'serif';
        }
        $paragraphStyle = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        if ($paragraphStyle !== '') {
            $paragraphStyle = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$paragraphStyle] ?? $paragraphStyle;
        }
        $allowedStyles = array(
            'title', 'subtitle_1', 'subtitle_2', 'subtitle_3', 'subtitle_4',
            'regulatory_reference', 'body', 'caption',
        );
        if ($paragraphStyle === '' || !in_array($paragraphStyle, $allowedStyles, true)) {
            $paragraphStyle = 'body';
        }
        $textColor = trim((string)($payload['text_color'] ?? $payload['color'] ?? ''));
        if ($textColor !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $textColor) !== 1) {
            $textColor = '';
        }
        $align = strtolower(trim((string)($payload['text_align'] ?? 'left')));
        if (!in_array($align, array('left', 'center', 'right'), true)) {
            $align = 'left';
        }
        $allowedSizes = array(8, 9, 10, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32);
        $fontSize = (int)($payload['font_size'] ?? 11);
        if (!in_array($fontSize, $allowedSizes, true)) {
            $fontSize = 11;
        }
        $indentLevel = max(0, min(8, (int)($payload['indent_level'] ?? 0)));
        $out = array(
            'paragraph_style' => $paragraphStyle,
            'text_align' => $align,
            'indent_level' => $indentLevel,
        );
        if (array_key_exists('font_family', $payload)) {
            $out['font_family'] = $font;
        }
        if (array_key_exists('font_size', $payload)) {
            $out['font_size'] = $fontSize;
        }
        if ($textColor !== '') {
            $out['text_color'] = strtolower($textColor);
        }
        if (array_key_exists('regulatory_ref', $payload)) {
            $regulatoryRef = trim((string)$payload['regulatory_ref']);
            $regulatoryRef = preg_replace('/\s+/', '', $regulatoryRef) ?? $regulatoryRef;
            if ($regulatoryRef !== '' && strlen($regulatoryRef) <= 128) {
                $out['regulatory_ref'] = $regulatoryRef;
            }
        }
        return $out;
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
