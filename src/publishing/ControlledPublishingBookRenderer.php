<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';
require_once __DIR__ . '/ControlledPublishingTableFormula.php';

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

        $change = (string)($block['change_status'] ?? '');
        $changeClass = in_array($change, array('new', 'modified'), true) ? ' cpb-block--changed cpb-block--' . $change : '';
        $attrs = ' class="cpb-block cpb-block--' . h($type) . $changeClass . '"'
            . ' data-block-id="' . $id . '"'
            . ' data-block-type="' . h($type) . '"'
            . ' data-stable-anchor="' . h($anchor) . '"';
        if ($change !== '' && $change !== 'unchanged') {
            $attrs .= ' data-change-status="' . h($change) . '"';
        }
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
            'callout' => $this->renderCallout($payload, $mode),
            'generated_placeholder' => $this->renderPlaceholder($payload),
            default => '<p class="cpb-unknown">Unsupported block: ' . h($type) . '</p>',
        };

        $marker = in_array($change, array('new', 'modified'), true)
            ? '<div class="cpb-change-marker" title="' . h(ucfirst($change) . ' since prior version') . '"></div>'
            : '';

        return '<article' . $attrs . '>' . $marker . $chrome . $inner . '</article>';
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
        string $mode = self::MODE_READ,
        ?array $pageLayout = null
    ): string {
        $bookKey = h((string)($version['book_key'] ?? ''));
        $versionLabel = h((string)($version['version_label'] ?? ''));
        $bookTitle = h((string)($version['book_title'] ?? $version['title'] ?? ''));
        $sectionTitle = h((string)($section['title'] ?? ''));
        $editable = $mode === self::MODE_EDIT && !empty($section['allow_author_blocks']);
        $layout = is_array($pageLayout) ? $pageLayout : array();
        $showRunning = !empty($layout['show_running_header_footer']);

        $drop = $editable
            ? '<div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div>'
            : '';

        $runningHeader = '';
        $runningFooter = '';
        if ($showRunning) {
            $runningHeader = $this->renderRunningBand('header', $layout, $editable);
            $runningFooter = $this->renderRunningBand('footer', $layout, $editable);
        } elseif ($editable) {
            $runningHeader = '<div class="cpb-running-band cpb-running-band--off" contenteditable="false">'
                . '<label><input type="checkbox" data-layout-toggle="show_running_header_footer"> '
                . 'Show running header/footer on this section</label></div>';
        }

        return '<div class="cpb-sheet" data-section-id="' . (int)($section['id'] ?? 0) . '">'
            . $runningHeader
            . '<header class="cpb-sheet-header">'
            . '<div class="cpb-sheet-org">IPCA · Controlled Manual</div>'
            . '<div class="cpb-sheet-title">' . $bookTitle . '</div>'
            . '<div class="cpb-sheet-meta">' . $bookKey . ' Rev ' . $versionLabel . '</div>'
            . '<h1 class="cpb-section-title">' . $sectionTitle . '</h1>'
            . '</header>'
            . '<div class="cpb-sheet-body" data-blocks-root="1">' . $blocksHtml . '</div>'
            . $drop
            . $runningFooter
            . '</div>';
    }

    /**
     * @param array<string,mixed> $layout
     */
    private function renderRunningBand(string $band, array $layout, bool $editable): string
    {
        $prefix = $band === 'footer' ? 'footer' : 'header';
        $cells = array('left', 'center', 'right');
        $html = '<div class="cpb-running-band cpb-running-band--' . h($band) . '" data-running-band="' . h($band) . '">';
        if ($editable && $band === 'header') {
            $html .= '<label class="cpb-running-toggle">'
                . '<input type="checkbox" data-layout-toggle="show_running_header_footer" checked> Header/footer</label>';
        }
        $html .= '<div class="cpb-running-row">';
        foreach ($cells as $cell) {
            $key = $prefix . '_' . $cell;
            $value = (string)($layout[$key] ?? '');
            $edit = $editable ? ' contenteditable="true" spellcheck="true" data-layout-field="' . h($key) . '"' : '';
            $html .= '<div class="cpb-running-cell cpb-running-cell--' . $cell . '"' . $edit . '>'
                . h($value) . '</div>';
        }
        $html .= '</div></div>';
        return $html;
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
        $style = $this->styleAttr($payload);
        return '<' . $tag . ' class="cpb-heading cpb-heading--l' . $level . $this->styleClass($payload) . '"'
            . $levelAttr . $style . $edit . '>'
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
        $style = $this->styleAttr($payload);
        return '<div class="cpb-paragraph' . $this->styleClass($payload) . '"' . $style . $edit . '>' . $html . '</div>';
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
        $borderWidth = (string)$table['border_width'];
        $borderColor = (string)$table['border_color'];
        $titleBg = (string)$table['title_bg'];
        $headerBgs = $table['header_bg'];
        $cellBgs = $table['cell_bg'];
        $hasTitleRow = !empty($table['has_title_row']);
        $colCount = count($headers);
        $edit = $mode === self::MODE_EDIT;

        $html = '<div class="cpb-table-block">';
        $html .= '<div class="cpb-table-wrap cpb-table-border-' . h($borderWidth) . '"'
            . ' data-border-width="' . h($borderWidth) . '"'
            . ' data-border-color="' . h($borderColor) . '"'
            . ' style="--cpb-table-border-color:' . h($borderColor) . '">';
        $html .= '<table class="cpb-table" data-field="table">';
        $html .= '<colgroup>';
        foreach ($colWidths as $width) {
            $html .= '<col style="width:' . (int)$width . 'px">';
        }
        $html .= '</colgroup>';

        $html .= '<thead>';
        if ($hasTitleRow) {
            $titleRowClass = 'cpb-table-title-row' . ($title === '' ? ' is-empty' : '');
            $titleEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $titleDisplay = $title !== '' ? h($title) : '';
            $titleBgAttr = $this->tableCellBgAttr($titleBg);
            $html .= '<tr class="' . $titleRowClass . '" data-title-row="1">';
            $html .= '<td colspan="' . $colCount . '"' . $titleEdit . $titleBgAttr
                . ' data-placeholder="Table title (spans all columns)">' . $titleDisplay . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr class="cpb-table-header-row">';
        $colIndex = 0;
        foreach ($headers as $header) {
            $headerEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $headerBg = (string)($headerBgs[$colIndex] ?? '');
            $html .= '<th' . $headerEdit . $this->tableCellBgAttr($headerBg) . ' data-col-index="' . $colIndex . '">';
            $html .= '<span class="cpb-th-text">' . h((string)$header) . '</span>';
            if ($edit) {
                $html .= '<span class="cpb-col-resize" data-col-index="' . $colIndex . '" title="Resize column"></span>';
            }
            $html .= '</th>';
            $colIndex++;
        }
        $html .= '</tr></thead>';

        $html .= '<tbody data-table-part="body">';
        $rowIndex = 0;
        foreach ($rows as $row) {
            $html .= '<tr>';
            $cellIndex = 0;
            foreach ($row as $cell) {
                $cellEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
                $bg = (string)($cellBgs[$rowIndex][$cellIndex] ?? '');
                $rawCell = (string)$cell;
                $displayCell = $edit
                    ? $rawCell
                    : ControlledPublishingTableFormula::displayValue($rawCell, $rows);
                $formulaAttr = (!$edit && str_starts_with($rawCell, '='))
                    ? ' data-formula="' . h($rawCell) . '" title="' . h($rawCell) . '"'
                    : '';
                $html .= '<td' . $cellEdit . $this->tableCellBgAttr($bg) . $formulaAttr . '>'
                    . h($displayCell) . '</td>';
                $cellIndex++;
            }
            $html .= '</tr>';
            $rowIndex++;
        }
        $html .= '</tbody></table></div>';

        if ($edit) {
            $html .= '<div class="cpb-table-tools" contenteditable="false">'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-row">+ Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="del-row">− Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-col">+ Column</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="del-col">− Column</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="toggle-title">'
                . ($hasTitleRow ? 'Remove title row' : '+ Title row')
                . '</button>'
                . '<span class="cpb-table-style-sep"></span>'
                . '<span class="cpb-table-style-label">Border</span>'
                . '<button type="button" class="cpb-mini-btn' . ($borderWidth === 'thin' ? ' is-active' : '') . '" data-table-action="border-thin" title="Thin border">─</button>'
                . '<button type="button" class="cpb-mini-btn' . ($borderWidth === 'medium' ? ' is-active' : '') . '" data-table-action="border-medium" title="Medium border">━</button>'
                . '<button type="button" class="cpb-mini-btn' . ($borderWidth === 'thick' ? ' is-active' : '') . '" data-table-action="border-thick" title="Thick border">▬</button>'
                . '<input type="color" class="cpb-table-color" data-table-action="border-color" value="' . h($borderColor) . '" title="Border color">'
                . '<span class="cpb-table-style-sep"></span>'
                . '<span class="cpb-table-style-label">Cell fill</span>'
                . '<input type="color" class="cpb-table-color" data-table-action="cell-bg" value="#ffffff" title="Cell background (select a cell first)">'
                . '<button type="button" class="cpb-mini-btn" data-table-action="cell-bg-clear" title="Clear cell fill">Clear</button>'
                . '<span class="cpb-table-style-sep"></span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="copy-cells" title="Copy column or selection">Copy</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="paste-cells" title="Paste TSV column or grid">Paste</button>'
                . '<span class="cpb-table-style-sep"></span>'
                . '<span class="cpb-table-style-label">Calc</span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="formula-sum" title="Insert SUM formula">SUM</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="formula-avg" title="Insert AVG formula">AVG</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="formula-custom" title="Insert custom formula">fx</button>'
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
        while (count($cellBg) < count($normalizedRows)) {
            $cellBg[] = array_fill(0, $colCount, '');
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
        );
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

    private function tableCellBgAttr(string $bg): string
    {
        if ($bg === '') {
            return '';
        }
        return ' data-cell-bg="' . h($bg) . '" style="background-color:' . h($bg) . '"';
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
     * @param array<string,mixed> $payload
     */
    private function renderCallout(array $payload, string $mode): string
    {
        $type = (string)($payload['callout_type'] ?? 'warning');
        $title = (string)($payload['title'] ?? strtoupper($type));
        $text = (string)($payload['text'] ?? '');
        $edit = $mode === self::MODE_EDIT;
        $icon = $type === 'caution' ? 'caution' : 'warning';
        $titleEdit = $edit ? ' contenteditable="true" data-field="callout_title" spellcheck="true"' : '';
        $textEdit = $edit ? ' contenteditable="true" data-field="callout_text" spellcheck="true"' : '';
        return '<div class="cpb-callout cpb-callout--' . h($type) . '" data-callout-type="' . h($type) . '">'
            . '<div class="cpb-callout-icon cpb-callout-icon--' . h($icon) . '" aria-hidden="true"></div>'
            . '<div class="cpb-callout-body">'
            . '<div class="cpb-callout-title"' . $titleEdit . '>' . h($title) . '</div>'
            . '<div class="cpb-callout-text"' . $textEdit . '>' . nl2br(h($text), false) . '</div>'
            . '</div></div>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function styleClass(array $payload): string
    {
        $font = (string)($payload['font_family'] ?? 'serif');
        $align = (string)($payload['text_align'] ?? 'left');
        return ' cpb-font-' . preg_replace('/[^a-z]/', '', strtolower($font))
            . ' cpb-align-' . preg_replace('/[^a-z]/', '', strtolower($align));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function styleAttr(array $payload): string
    {
        $align = (string)($payload['text_align'] ?? 'left');
        if (!in_array($align, array('left', 'center', 'right'), true)) {
            $align = 'left';
        }
        return ' style="text-align:' . h($align) . '"'
            . ' data-font-family="' . h((string)($payload['font_family'] ?? 'serif')) . '"'
            . ' data-text-align="' . h($align) . '"';
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
