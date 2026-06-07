<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';

/**
 * Shared render pipeline for editor canvas, e-reader, and PDF source HTML.
 */
final class ControlledPublishingBookRenderer
{
    public const MODE_READ = 'read';
    public const MODE_EDIT = 'edit';

    /**
     * @param array<string,mixed> $block
     */
    public function renderBlock(array $block, string $mode = self::MODE_READ): string
    {
        $mode = $mode === self::MODE_EDIT ? self::MODE_EDIT : self::MODE_READ;
        $type = (string)($block['block_type'] ?? 'paragraph');
        $payload = $this->decodePayload($block);
        $id = (int)($block['id'] ?? 0);
        $anchor = (string)($block['stable_anchor'] ?? '');
        $system = !empty($block['is_system_managed']);

        $attrs = ' class="cpb-block cpb-block--' . h($type) . '"'
            . ' data-block-id="' . $id . '"'
            . ' data-block-type="' . h($type) . '"'
            . ' data-stable-anchor="' . h($anchor) . '"';
        if ($system) {
            $attrs .= ' data-system-managed="1"';
        }

        $chrome = '';
        if ($mode === self::MODE_EDIT && !$system && $type !== 'generated_placeholder') {
            $chrome = '<div class="cpb-block-chrome" contenteditable="false">'
                . '<button type="button" class="cpb-block-btn" data-action="move-up" title="Move up">↑</button>'
                . '<button type="button" class="cpb-block-btn" data-action="move-down" title="Move down">↓</button>'
                . '<button type="button" class="cpb-block-btn cpb-block-btn--danger" data-action="delete" title="Delete">×</button>'
                . '</div>';
        }

        $inner = match ($type) {
            'heading' => $this->renderHeading($payload, $mode),
            'paragraph' => $this->renderParagraph($payload, $mode),
            'list' => $this->renderList($payload, $mode),
            'table' => $this->renderTable($payload, $mode),
            'image' => $this->renderImage($payload, $mode),
            'generated_placeholder' => $this->renderPlaceholder($payload),
            default => '<p class="cpb-unknown">Unsupported block: ' . h($type) . '</p>',
        };

        return '<article' . $attrs . '>' . $chrome . $inner . '</article>';
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    public function renderBlocks(array $blocks, string $mode = self::MODE_READ): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $html .= $this->renderBlock($block, $mode);
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     */
    public function renderPageShell(
        array $version,
        array $section,
        string $blocksHtml,
        string $mode = self::MODE_READ
    ): string {
        $bookKey = h((string)($version['book_key'] ?? ''));
        $versionLabel = h((string)($version['version_label'] ?? ''));
        $bookTitle = h((string)($version['book_title'] ?? $version['title'] ?? ''));
        $sectionTitle = h((string)($section['title'] ?? ''));
        $editable = $mode === self::MODE_EDIT && !empty($section['allow_author_blocks']);

        $drop = $editable
            ? '<div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div>'
            : '';

        return '<div class="cpb-sheet" data-section-id="' . (int)($section['id'] ?? 0) . '">'
            . '<header class="cpb-sheet-header">'
            . '<div class="cpb-sheet-org">IPCA · Controlled Manual</div>'
            . '<div class="cpb-sheet-title">' . $bookTitle . '</div>'
            . '<div class="cpb-sheet-meta">' . $bookKey . ' Rev ' . $versionLabel . '</div>'
            . '<h1 class="cpb-section-title">' . $sectionTitle . '</h1>'
            . '</header>'
            . '<div class="cpb-sheet-body" data-blocks-root="1">' . $blocksHtml . '</div>'
            . $drop
            . '</div>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHeading(array $payload, string $mode): string
    {
        $level = max(1, min(6, (int)($payload['level'] ?? 2)));
        $text = (string)($payload['text'] ?? '');
        $tag = 'h' . $level;
        $edit = $mode === self::MODE_EDIT
            ? ' contenteditable="true" data-field="text" spellcheck="true"'
            : '';
        $levelAttr = $mode === self::MODE_EDIT
            ? ' data-field="level" data-level="' . $level . '"'
            : '';
        return '<' . $tag . ' class="cpb-heading cpb-heading--l' . $level . '"' . $levelAttr . $edit . '>'
            . h($text) . '</' . $tag . '>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderParagraph(array $payload, string $mode): string
    {
        $html = (string)($payload['html'] ?? '');
        if ($html === '' && isset($payload['text'])) {
            $html = nl2br(h((string)$payload['text']), false);
        } else {
            $html = ControlledPublishingHtmlSanitizer::sanitizeInline($html);
        }
        $edit = $mode === self::MODE_EDIT
            ? ' contenteditable="true" data-field="html" spellcheck="true"'
            : '';
        return '<div class="cpb-paragraph"' . $edit . '>' . $html . '</div>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderList(array $payload, string $mode): string
    {
        $ordered = !empty($payload['ordered']);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : array();
        $tag = $ordered ? 'ol' : 'ul';
        $edit = $mode === self::MODE_EDIT
            ? ' contenteditable="true" data-field="items" spellcheck="true"'
            : '';
        $html = '<' . $tag . ' class="cpb-list"' . $edit . '>';
        if ($items === array() && $mode === self::MODE_EDIT) {
            $html .= '<li data-placeholder="1">List item</li>';
        } else {
            foreach ($items as $item) {
                $html .= '<li>' . h((string)$item) . '</li>';
            }
        }
        $html .= '</' . $tag . '>';
        return $html;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderTable(array $payload, string $mode): string
    {
        $table = $this->normalizeTableShape($payload);
        $title = (string)$table['title'];
        $headers = $table['headers'];
        $rows = $table['rows'];
        $colWidths = $table['col_widths'];
        $hasTitleRow = !empty($table['has_title_row']) || $title !== '';
        $colCount = count($headers);
        $edit = $mode === self::MODE_EDIT;

        $html = '<div class="cpb-table-block">';
        $html .= '<div class="cpb-table-wrap"><table class="cpb-table" data-field="table">';
        $html .= '<colgroup>';
        foreach ($colWidths as $width) {
            $html .= '<col style="width:' . (int)$width . 'px">';
        }
        $html .= '</colgroup>';

        if ($hasTitleRow) {
            $titleRowClass = 'cpb-table-title-row' . ($title === '' ? ' is-empty' : '');
            $titleEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $titleDisplay = $title !== '' ? h($title) : '';
            $html .= '<tbody data-table-part="title"><tr class="' . $titleRowClass . '" data-title-row="1">';
            $html .= '<td colspan="' . $colCount . '"' . $titleEdit
                . ' data-placeholder="Table title (spans all columns)">' . $titleDisplay . '</td>';
            $html .= '</tr></tbody>';
        }

        $html .= '<thead><tr>';
        $colIndex = 0;
        foreach ($headers as $header) {
            $headerEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $html .= '<th' . $headerEdit . ' data-col-index="' . $colIndex . '">';
            $html .= '<span class="cpb-th-text">' . h((string)$header) . '</span>';
            if ($edit) {
                $html .= '<span class="cpb-col-resize" data-col-index="' . $colIndex . '" title="Resize column"></span>';
            }
            $html .= '</th>';
            $colIndex++;
        }
        $html .= '</tr></thead>';

        $html .= '<tbody data-table-part="body">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $cellEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
                $html .= '<td' . $cellEdit . '>' . h((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        if ($edit) {
            $html .= '<div class="cpb-table-tools" contenteditable="false">'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-row">+ Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-col">+ Column</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="toggle-title">'
                . ($hasTitleRow ? 'Remove title row' : '+ Title row')
                . '</button>'
                . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{title:string,has_title_row:bool,headers:list<string>,rows:list<list<string>>,col_widths:list<int>}
     */
    private function normalizeTableShape(array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $hasTitleRow = !empty($payload['has_title_row']) || $title !== '';
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
                $rows[] = $line;
            }
        }
        if ($headers === array() && $rows !== array()) {
            $headers = array_shift($rows) ?: array('Column 1', 'Column 2');
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

        return array(
            'title' => $title,
            'has_title_row' => $hasTitleRow,
            'headers' => $headers,
            'rows' => $normalizedRows,
            'col_widths' => $colWidths,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderImage(array $payload, string $mode): string
    {
        $url = trim((string)($payload['url'] ?? ''));
        $alt = (string)($payload['alt'] ?? '');
        if ($url === '') {
            if ($mode === self::MODE_EDIT) {
                return '<div class="cpb-image cpb-image--empty" data-field="image">'
                    . '<span>Image missing — upload or drop a file</span></div>';
            }
            return '';
        }
        $editClass = $mode === self::MODE_EDIT ? ' cpb-image--editable' : '';
        return '<figure class="cpb-image' . $editClass . '" data-field="image">'
            . '<img src="' . h($url) . '" alt="' . h($alt) . '" loading="lazy">'
            . ($mode === self::MODE_EDIT
                ? '<figcaption contenteditable="true" data-field="alt" spellcheck="true">' . h($alt) . '</figcaption>'
                : ($alt !== '' ? '<figcaption>' . h($alt) . '</figcaption>' : ''))
            . '</figure>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderPlaceholder(array $payload): string
    {
        $message = (string)($payload['message'] ?? 'Generated section — content produced at publish time.');
        return '<div class="cpb-placeholder" contenteditable="false">' . h($message) . '</div>';
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
