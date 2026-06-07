<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';
require_once __DIR__ . '/ControlledPublishingTableFormula.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';

/**
 * Shared render pipeline for editor canvas, e-reader, and PDF source HTML.
 */
final class ControlledPublishingBookRenderer
{
    public const MODE_READ = 'read';
    public const MODE_EDIT = 'edit';

    /** @var array<string,mixed> */
    private array $bookStyles = array();

    /** @var array<int,string> */
    private array $sectionNumberDisplay = array();

    /** @var array<int,string> */
    private array $suggestedRegulatoryRefs = array();

    private ?ControlledPublishingBookStyleService $styleService = null;

    private ?ControlledPublishingSectionNumberService $sectionNumberService = null;

    private ?ControlledPublishingPageHeaderService $pageHeaderService = null;

    /**
     * @param array<string,mixed> $styles
     */
    public function setBookStyles(array $styles, ?ControlledPublishingBookStyleService $styleService = null): void
    {
        $this->bookStyles = $styles;
        $this->styleService = $styleService;
    }

    /**
     * @param array<int,string> $displayByBlockId
     * @param array<int,string> $suggestedRegulatoryRefs
     */
    public function setSectionNumbers(
        array $displayByBlockId,
        array $suggestedRegulatoryRefs = array(),
        ?ControlledPublishingSectionNumberService $sectionNumberService = null
    ): void {
        $this->sectionNumberDisplay = $displayByBlockId;
        $this->suggestedRegulatoryRefs = $suggestedRegulatoryRefs;
        $this->sectionNumberService = $sectionNumberService;
    }

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
                . '<button type="button" class="cpb-block-btn" data-action="insert-paragraph" title="Insert paragraph below">¶+</button>'
                . '<button type="button" class="cpb-block-btn" data-action="move-up" title="Move up">↑</button>'
                . '<button type="button" class="cpb-block-btn" data-action="move-down" title="Move down">↓</button>'
                . '<button type="button" class="cpb-block-btn cpb-block-btn--danger" data-action="delete" title="Delete">×</button>'
                . '</div>';
        }

        $inner = match ($type) {
            'heading' => $this->renderHeading($payload, $mode, $id),
            'paragraph' => $this->renderParagraph($payload, $mode, $id),
            'list' => $this->renderList($payload, $mode, $id),
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

    public function setPageHeaderService(?ControlledPublishingPageHeaderService $pageHeaderService): void
    {
        $this->pageHeaderService = $pageHeaderService;
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
        ?array $pageLayout = null,
        ?array $pageHeaderConfig = null
    ): string {
        $editable = $mode === self::MODE_EDIT && !empty($section['allow_author_blocks']);
        $layout = is_array($pageLayout) ? $pageLayout : array();

        $headerSvc = $this->pageHeaderService;
        $defaults = $headerSvc !== null
            ? $headerSvc->resolveFromMetadata(array())
            : array(
                'page_header' => array('enabled' => true),
                'page_footer' => array('enabled' => true),
            );

        $config = is_array($pageHeaderConfig) ? $pageHeaderConfig : array();
        $pageHeader = is_array($config['page_header'] ?? null)
            ? $config['page_header']
            : $defaults['page_header'];
        $pageFooter = is_array($config['page_footer'] ?? null)
            ? $config['page_footer']
            : $defaults['page_footer'];

        $showHeaderFooter = $headerSvc !== null
            ? $headerSvc->shouldShowHeader($section, $pageHeader, $pageFooter, $layout)
            : (!empty($pageHeader['enabled']) || !empty($pageFooter['enabled']));

        $tokenContext = $headerSvc !== null
            ? $headerSvc->buildTokenContext($version, $section, array('editor_preview' => $mode === self::MODE_EDIT))
            : array();

        $drop = $editable
            ? '<div class="cpb-dropzone" data-dropzone="image">Drop image here to insert</div>'
            : '';

        $headerHtml = '';
        $footerHtml = '';
        $layoutToggle = '';
        if ($editable) {
            $hideChecked = !empty($layout['hide_header_footer']) ? ' checked' : '';
            $layoutToggle = '<div class="cpb-page-layout-toggle" contenteditable="false">'
                . '<label><input type="checkbox" data-layout-toggle="hide_header_footer"' . $hideChecked . '> '
                . 'Hide header/footer on this section</label></div>';
        }

        if ($showHeaderFooter) {
            if (!empty($pageHeader['enabled'])) {
                $headerHtml = $this->renderPageHeaderTable($pageHeader, $tokenContext, $editable, $headerSvc);
            }
            if (!empty($pageFooter['enabled'])) {
                $footerHtml = $this->renderPageFooterTable($pageFooter, $tokenContext, $editable, $headerSvc);
            }
        }

        return '<div class="cpb-sheet" data-section-id="' . (int)($section['id'] ?? 0) . '">'
            . $layoutToggle
            . $headerHtml
            . '<div class="cpb-sheet-body" data-blocks-root="1">' . $blocksHtml . '</div>'
            . $drop
            . $footerHtml
            . '</div>';
    }

    /**
     * @param array<string,mixed> $pageHeader
     * @param array<string,string> $tokenContext
     */
    private function renderPageHeaderTable(
        array $pageHeader,
        array $tokenContext,
        bool $editable,
        ?ControlledPublishingPageHeaderService $headerSvc
    ): string {
        $resolve = static function (string $template) use ($headerSvc, $tokenContext): string {
            if ($headerSvc === null) {
                return h($template);
            }
            return $headerSvc->resolveTokensToHtml($template, $tokenContext);
        };

        $logoUrl = trim((string)($pageHeader['logo_url'] ?? ''));
        $logoAlt = h((string)($pageHeader['logo_alt'] ?? ''));
        $leftCell = '';
        if ($logoUrl !== '') {
            $leftCell = '<img class="cpb-page-header-logo" src="' . h($logoUrl) . '" alt="' . $logoAlt . '">';
        } elseif ($editable) {
            $leftCell = '<span class="cpb-page-header-logo-placeholder">Logo</span>';
        }

        $editAttr = $editable
            ? ' data-open-header-editor="1" title="Click to edit page header"'
            : '';

        return '<header class="cpb-page-header"' . $editAttr . ' contenteditable="false">'
            . '<table class="cpb-page-header-table" role="presentation">'
            . '<tr>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--left">' . $leftCell . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--center">'
            . $resolve((string)($pageHeader['center_text'] ?? ''))
            . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--right">'
            . $resolve((string)($pageHeader['right_text'] ?? ''))
            . '</td>'
            . '</tr></table></header>';
    }

    /**
     * @param array<string,mixed> $pageFooter
     * @param array<string,string> $tokenContext
     */
    private function renderPageFooterTable(
        array $pageFooter,
        array $tokenContext,
        bool $editable,
        ?ControlledPublishingPageHeaderService $headerSvc
    ): string {
        $resolve = static function (string $template) use ($headerSvc, $tokenContext): string {
            if ($headerSvc === null) {
                return h($template);
            }
            return $headerSvc->resolveTokensToHtml($template, $tokenContext);
        };

        $editAttr = $editable
            ? ' data-open-header-editor="1" title="Click to edit page footer"'
            : '';

        return '<footer class="cpb-page-footer"' . $editAttr . ' contenteditable="false">'
            . '<table class="cpb-page-header-table cpb-page-footer-table" role="presentation">'
            . '<tr>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--left">'
            . $resolve((string)($pageFooter['left_text'] ?? ''))
            . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--center">'
            . $resolve((string)($pageFooter['center_text'] ?? ''))
            . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--right">'
            . $resolve((string)($pageFooter['right_text'] ?? ''))
            . '</td>'
            . '</tr></table></footer>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHeading(array $payload, string $mode, int $blockId = 0): string
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
        $prefix = $this->renderSectionNumberPrefix($payload, $blockId);
        $regRef = $this->renderRegulatoryRefPrefix($payload, $blockId);
        return '<div class="cpb-heading-row">'
            . $prefix . $regRef
            . '<' . $tag . ' class="cpb-heading cpb-heading--l' . $level . $this->styleClass($payload) . '"'
            . $levelAttr . $style . $edit . '>'
            . h(ControlledPublishingHtmlSanitizer::stripLeadingSectionNumberText($text)) . '</' . $tag . '>'
            . '</div>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderParagraph(array $payload, string $mode, int $blockId = 0): string
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
        $prefix = $this->renderSectionNumberPrefix($payload, $blockId);
        $regRef = $this->renderRegulatoryRefPrefix($payload, $blockId);
        return '<div class="cpb-paragraph-row">'
            . $prefix . $regRef
            . '<div class="cpb-paragraph' . $this->styleClass($payload) . '"' . $style . $edit . '>'
            . $html . '</div></div>';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderSectionNumberPrefix(array $payload, int $blockId): string
    {
        $style = $this->canonicalParagraphStyle($payload);
        if (!isset(ControlledPublishingSectionNumberService::NUMBERED_STYLE_DEPTHS[$style])) {
            return '';
        }
        $display = $this->sectionNumberDisplay[$blockId] ?? '';
        if ($display === '') {
            return '';
        }
        return '<span class="cpb-section-number" contenteditable="false" data-section-number="'
            . h($display) . '">' . h($display) . '</span> ';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderRegulatoryRefPrefix(array $payload, int $blockId): string
    {
        $style = $this->canonicalParagraphStyle($payload);
        if ($style !== 'regulatory_reference') {
            return '';
        }
        $suggested = $this->suggestedRegulatoryRefs[$blockId] ?? '';
        $effective = $this->sectionNumberService !== null
            ? $this->sectionNumberService->resolveRegulatoryRef($payload, $suggested)
            : trim((string)($payload['regulatory_ref'] ?? $payload['regulatory_ref_auto'] ?? $suggested));
        if ($effective === '') {
            return '';
        }
        $manual = trim((string)($payload['regulatory_ref'] ?? ''));
        $autoAttr = $manual === '' ? ' data-regulatory-ref-auto="' . h($suggested) . '"' : '';
        return '<span class="cpb-regulatory-ref" contenteditable="false" data-regulatory-ref="'
            . h($effective) . '"' . $autoAttr . '>' . h($effective) . '</span> ';
    }

    private function renderList(array $payload, string $mode, int $blockId = 0): string
    {
        $ordered = !empty($payload['ordered']);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : array();
        $tag = $ordered ? 'ol' : 'ul';
        $edit = $mode === self::MODE_EDIT
            ? ' contenteditable="true" data-field="items" spellcheck="true"'
            : '';
        $style = $this->styleAttr($payload);
        $html = '<' . $tag . ' class="cpb-list' . $this->styleClass($payload) . '"' . $style . $edit . '>';
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
        $titleAlign = (string)$table['title_align'];
        $titleFontFamily = (string)$table['title_font_family'];
        $titleFontSize = (int)$table['title_font_size'];
        $headerAligns = $table['header_align'];
        $cellAligns = $table['cell_align'];
        $hasTitleRow = !empty($table['has_title_row']);
        $tableAlign = (string)$table['table_align'];
        $colCount = count($headers);
        $edit = $mode === self::MODE_EDIT;

        $html = '<div class="cpb-table-block cpb-table-block--align-' . h($tableAlign) . '" data-table-align="' . h($tableAlign) . '">';
        $html .= '<div class="cpb-table-wrap cpb-table-border-' . h($borderWidth) . '"'
            . ' data-border-width="' . h($borderWidth) . '"'
            . ' data-border-color="' . h($borderColor) . '"'
            . ' style="--cpb-table-border-color:' . h($borderColor) . '">';
        $totalWidth = array_sum($colWidths);
        $html .= '<table class="cpb-table" data-field="table" style="width:' . max(1, $totalWidth) . 'px">';
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
            $titleVisual = $this->tableCellVisualAttr($titleBg, $titleAlign, $titleFontFamily, $titleFontSize);
            $html .= '<tr class="' . $titleRowClass . '" data-title-row="1">';
            $html .= '<td colspan="' . $colCount . '"' . $titleEdit . $titleVisual
                . ' data-placeholder="Table title (spans all columns)">' . $titleDisplay . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr class="cpb-table-header-row">';
        $colIndex = 0;
        foreach ($headers as $header) {
            $headerEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $headerBg = (string)($headerBgs[$colIndex] ?? '');
            $headerAlign = (string)($headerAligns[$colIndex] ?? 'left');
            $html .= '<th' . $headerEdit . $this->tableCellVisualAttr($headerBg, $headerAlign) . ' data-col-index="' . $colIndex . '">';
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
                $align = (string)($cellAligns[$rowIndex][$cellIndex] ?? 'left');
                $rawCell = (string)$cell;
                $displayCell = $edit
                    ? $rawCell
                    : ControlledPublishingTableFormula::displayValue($rawCell, $rows);
                $formulaAttr = (!$edit && str_starts_with($rawCell, '='))
                    ? ' data-formula="' . h($rawCell) . '" title="' . h($rawCell) . '"'
                    : '';
                $html .= '<td' . $cellEdit . $this->tableCellVisualAttr($bg, $align) . $formulaAttr . '>'
                    . h($displayCell) . '</td>';
                $cellIndex++;
            }
            $html .= '</tr>';
            $rowIndex++;
        }
        $html .= '</tbody></table></div>';

        if ($edit) {
            $html .= '<div class="cpb-table-tools" contenteditable="false">'
                . '<button type="button" class="cpb-mini-btn cpb-mini-btn--danger" data-table-action="delete-table" title="Delete table">Delete table</button>'
                . '<span class="cpb-table-style-sep"></span>'
                . '<span class="cpb-table-style-label">Align</span>'
                . '<button type="button" class="cpb-mini-btn' . ($tableAlign === 'left' ? ' is-active' : '') . '" data-table-action="table-align-left" title="Align table left">L</button>'
                . '<button type="button" class="cpb-mini-btn' . ($tableAlign === 'center' ? ' is-active' : '') . '" data-table-action="table-align-center" title="Align table center">C</button>'
                . '<button type="button" class="cpb-mini-btn' . ($tableAlign === 'right' ? ' is-active' : '') . '" data-table-action="table-align-right" title="Align table right">R</button>'
                . '<span class="cpb-table-style-sep"></span>'
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

    private function tableCellVisualAttr(
        string $bg = '',
        string $align = '',
        string $fontFamily = '',
        int $fontSize = 0
    ): string {
        $styles = array();
        $attrs = array();
        if ($bg !== '') {
            $styles[] = 'background-color:' . $bg;
            $attrs['data-cell-bg'] = $bg;
        }
        if ($align !== '' && in_array($align, array('left', 'center', 'right'), true)) {
            $styles[] = 'text-align:' . $align;
            $attrs['data-cell-align'] = $align;
        }
        if ($fontFamily !== '') {
            $fontKey = preg_replace('/[^a-z]/', '', strtolower($fontFamily));
            $attrs['class'] = 'cpb-font-' . $fontKey;
            $attrs['data-font-family'] = $fontFamily;
            $fontStack = $this->fontFamilyStack($fontFamily);
            if ($fontStack !== '') {
                $styles[] = 'font-family:' . $fontStack . ' !important';
            }
        }
        if ($fontSize > 0) {
            $styles[] = 'font-size:' . $fontSize . 'pt';
            $attrs['data-font-size'] = (string)$fontSize;
        }

        $html = '';
        if ($styles !== array()) {
            $html .= ' style="' . h(implode(';', $styles)) . '"';
        }
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $key . '="' . h((string)$value) . '"';
        }
        return $html;
    }

    private function fontFamilyStack(string $fontFamily): string
    {
        $key = preg_replace('/[^a-z]/', '', strtolower($fontFamily));
        return match ($key) {
            'serif' => "Georgia,'Times New Roman',serif",
            'sans' => 'system-ui,-apple-system,Segoe UI,sans-serif',
            'mono' => "'Courier New',Courier,monospace",
            'arial' => 'Arial,Helvetica,sans-serif',
            'manuallabel', 'manualtitle', 'sectiontitle' => 'system-ui,-apple-system,Segoe UI,sans-serif',
            default => '',
        };
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
        $widthPct = max(20, min(100, (int)($payload['width_pct'] ?? 100)));
        $editClass = $mode === self::MODE_EDIT ? ' cpb-image--editable' : '';
        $resize = $mode === self::MODE_EDIT
            ? '<span class="cpb-image-resize" title="Drag to resize"></span>'
            : '';
        return '<figure class="cpb-image' . $editClass . '" data-field="image" style="width:' . $widthPct . '%" data-width-pct="' . $widthPct . '">'
            . '<div class="cpb-image-frame">'
            . '<img src="' . h($url) . '" alt="' . h($alt) . '" loading="lazy">'
            . $resize
            . '</div>'
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
        if (!in_array($type, array('warning', 'caution', 'info'), true)) {
            $type = 'warning';
        }
        $title = (string)($payload['title'] ?? strtoupper($type));
        $text = (string)($payload['text'] ?? '');
        $edit = $mode === self::MODE_EDIT;
        $icon = $type;
        $titleEdit = $edit ? ' contenteditable="true" data-field="callout_title" spellcheck="true"' : '';
        $textEdit = $edit ? ' contenteditable="true" data-field="callout_text" spellcheck="true"' : '';
        return '<div class="cpb-callout cpb-callout--' . h($type) . '" data-callout-type="' . h($type) . '">'
            . '<div class="cpb-callout-icon cpb-callout-icon--' . h($icon) . '" aria-hidden="true"></div>'
            . '<div class="cpb-callout-body">'
            . '<div class="cpb-callout-title"' . $titleEdit . '>' . h($title) . '</div>'
            . '<div class="cpb-callout-text"' . $textEdit . '>' . nl2br(h($text), false) . '</div>'
            . '</div></div>';
    }

    private function canonicalParagraphStyle(array $payload): string
    {
        $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        return ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function styleClass(array $payload): string
    {
        $typography = $this->resolveTypography($payload);
        $paragraphStyle = $this->canonicalParagraphStyle($payload);
        $psClass = $paragraphStyle !== '' ? ' cpb-ps-' . preg_replace('/[^a-z0-9_]/', '', $paragraphStyle) : '';
        return ' cpb-font-' . preg_replace('/[^a-z]/', '', strtolower((string)$typography['font_family']))
            . ' cpb-align-' . preg_replace('/[^a-z]/', '', strtolower((string)$typography['text_align']))
            . $psClass;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function styleAttr(array $payload): string
    {
        $typography = $this->resolveTypography($payload);
        $paragraphStyle = $this->canonicalParagraphStyle($payload);
        $fontStack = $this->fontFamilyStack((string)$typography['font_family']);
        $styles = array(
            'text-align:' . (string)$typography['text_align'],
            'font-size:' . (int)$typography['font_size'] . 'pt',
            'color:' . (string)$typography['color'],
            'font-family:' . $fontStack,
        );
        $indentLevel = (int)$typography['indent_level'];
        if ($indentLevel > 0) {
            $styles[] = 'margin-left:' . ($indentLevel * 24) . 'px';
        }
        $attrs = ' style="' . h(implode(';', $styles)) . '"'
            . ' data-font-family="' . h((string)$typography['font_family']) . '"'
            . ' data-text-align="' . h((string)$typography['text_align']) . '"'
            . ' data-font-size="' . (int)$typography['font_size'] . '"'
            . ' data-text-color="' . h((string)$typography['color']) . '"'
            . ' data-indent-level="' . $indentLevel . '"';
        if ($paragraphStyle !== '') {
            $attrs .= ' data-paragraph-style="' . h($paragraphStyle) . '"';
        }
        $regulatoryRef = trim((string)($payload['regulatory_ref'] ?? ''));
        if ($regulatoryRef !== '') {
            $attrs .= ' data-regulatory-ref="' . h($regulatoryRef) . '"';
        }
        return $attrs;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{font_family:string,font_size:int,color:string,text_align:string,indent_level:int}
     */
    private function resolveTypography(array $payload): array
    {
        if ($this->styleService !== null && $this->bookStyles !== array()) {
            return $this->styleService->resolveBlockTypography($payload, $this->bookStyles);
        }
        $align = (string)($payload['text_align'] ?? 'left');
        if (!in_array($align, array('left', 'center', 'right'), true)) {
            $align = 'left';
        }
        $fontSize = (int)($payload['font_size'] ?? 11);
        if ($fontSize < 8 || $fontSize > 32) {
            $fontSize = 11;
        }
        return array(
            'font_family' => (string)($payload['font_family'] ?? 'serif'),
            'font_size' => $fontSize,
            'color' => (string)($payload['text_color'] ?? $payload['color'] ?? '#0f172a'),
            'text_align' => $align,
            'indent_level' => max(0, min(8, (int)($payload['indent_level'] ?? 0))),
        );
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
