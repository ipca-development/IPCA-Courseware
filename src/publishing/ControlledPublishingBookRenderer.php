<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingHtmlSanitizer.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';
require_once __DIR__ . '/ControlledPublishingTableFormula.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';
require_once __DIR__ . '/ControlledPublishingCoverPageService.php';
require_once __DIR__ . '/ControlledPublishingLepService.php';

/**
 * Shared render pipeline for editor canvas, e-reader, and PDF source HTML.
 */
final class ControlledPublishingBookRenderer
{
    public const MODE_READ = 'read';
    public const MODE_EDIT = 'edit';
    private const TOC_FONT_SIZE_DELTA = 4;

    /** @var array<string,mixed> */
    private array $bookStyles = array();

    /** @var array<int,string> */
    private array $sectionNumberDisplay = array();

    /** @var array<int,string> */
    private array $suggestedRegulatoryRefs = array();

    private ?ControlledPublishingBookStyleService $styleService = null;

    private ?ControlledPublishingSectionNumberService $sectionNumberService = null;

    private ?ControlledPublishingPageHeaderService $pageHeaderService = null;

    private ?ControlledPublishingCoverPageService $coverPageService = null;

    private ?ControlledPublishingLepService $lepPageService = null;

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
        $system = !empty($block['is_system_managed']) || $type === 'toc';

        $change = (string)($block['change_status'] ?? '');
        $changeClass = in_array($change, array('new', 'modified'), true) ? ' cpb-block--changed cpb-block--' . $change : '';
        $attrs = ' class="cpb-block cpb-block--' . h($type) . $changeClass . '"'
            . ' data-block-id="' . $id . '"'
            . ' data-block-type="' . h($type) . '"'
            . ' data-stable-anchor="' . h($anchor) . '"';
        if ($anchor !== '') {
            $attrs .= ' id="' . h($anchor) . '"';
        }
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
            'toc' => $this->renderToc($payload, $mode),
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
        $hasToc = false;
        foreach ($blocks as $block) {
            if (is_array($block) && (string)($block['block_type'] ?? '') === 'toc') {
                $hasToc = true;
                break;
            }
        }

        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if ($hasToc && (string)($block['block_type'] ?? '') === 'generated_placeholder') {
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

    public function setCoverPageService(?ControlledPublishingCoverPageService $coverPageService): void
    {
        $this->coverPageService = $coverPageService;
    }

    public function setLepPageService(?ControlledPublishingLepService $lepPageService): void
    {
        $this->lepPageService = $lepPageService;
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param array<string,mixed>|null $lepPage
     * @param array<string,mixed>|null $approval
     */
    public function renderLepPageShell(
        array $version,
        array $section,
        string $mode = self::MODE_READ,
        ?array $pageHeaderConfig = null,
        ?array $lepPage = null,
        ?array $approval = null
    ): string {
        $editable = $mode === self::MODE_EDIT;
        $lepSvc = $this->lepPageService;
        $lep = is_array($lepPage)
            ? $lepPage
            : ($lepSvc !== null ? $lepSvc->resolveFromVersion($version) : array());

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

        $tokenContext = $this->buildHeaderTokenContext($version, $section, $mode, $pageHeaderConfig);

        $headerHtml = '';
        $footerHtml = '';
        if (!empty($pageHeader['enabled'])) {
            $headerHtml = $this->renderPageHeaderTable($pageHeader, $tokenContext, $editable, $headerSvc);
        }
        if (!empty($pageFooter['enabled'])) {
            $footerHtml = $this->renderPageFooterTable($pageFooter, $tokenContext, $editable, $headerSvc);
        }

        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        $editAttr = $editable ? ' data-lep-editable="1"' : '';

        $headings = is_array($lep['headings'] ?? null) ? $lep['headings'] : array();
        $headingsByKey = array();
        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }
            $headingsByKey[(string)($heading['key'] ?? '')] = $heading;
        }

        $internalSlots = array();
        $authoritySlot = null;
        foreach (is_array($lep['signatories'] ?? null) ? $lep['signatories'] : array() as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            if ((string)($slot['signer_type'] ?? '') === 'authority' || (string)($slot['slot_key'] ?? '') === 'authority') {
                $authoritySlot = $slot;
                continue;
            }
            $internalSlots[] = $slot;
        }
        if ($internalSlots === array() && $lepSvc !== null) {
            $internalSlots = $lepSvc->defaultSignatories();
        }

        $signatureHtml = '<div class="cpb-lep-signatures">';
        foreach ($internalSlots as $slot) {
            $signatureHtml .= $this->renderLepSignatoryBox($slot, $editable);
        }
        $signatureHtml .= '</div>';

        $authorityHtml = $this->renderLepAuthorityBlock($authoritySlot, $approval, $editable, (int)($version['id'] ?? 0));

        $partsHtml = $this->renderLepPartsTable($lep, $editable);

        $preCertHeadings = '';
        foreach (array('part_title', 'title', 'subtitle_1') as $headingKey) {
            if (!isset($headingsByKey[$headingKey]) || !is_array($headingsByKey[$headingKey])) {
                continue;
            }
            $preCertHeadings .= $this->renderLepHeading($headingsByKey[$headingKey], $editable);
        }

        $tableHeadingHtml = '';
        if (isset($headingsByKey['subtitle_2']) && is_array($headingsByKey['subtitle_2'])) {
            $tableHeadingHtml = $this->renderLepHeading($headingsByKey['subtitle_2'], $editable);
        }

        $bodyStyle = $this->lepParagraphStyle('body');
        $certText = (string)($lep['certification_text'] ?? '');
        $onBehalf = (string)($lep['on_behalf_text'] ?? '');

        return '<div class="cpb-sheet cpb-sheet--lep" data-section-id="' . (int)($section['id'] ?? 0) . '"' . $editAttr . '>'
            . $headerHtml
            . '<div class="cpb-lep" contenteditable="false">'
            . $preCertHeadings
            . '<div class="cpb-lep-cert-wrap">'
            . '<div class="cpb-lep-cert-block">'
            . '<div class="cpb-lep-cert-row cpb-lep-cert-row--text">'
            . '<div class="' . trim($bodyStyle['class'] . ' cpb-lep-cert-text') . '" data-lep-field="certification_text"' . $bodyStyle['attr'] . $fieldEdit . '>'
            . h($certText) . '</div>'
            . '</div>'
            . '<div class="cpb-lep-cert-row cpb-lep-cert-row--text">'
            . '<div class="' . trim($bodyStyle['class'] . ' cpb-lep-on-behalf') . '" data-lep-field="on_behalf_text"' . $bodyStyle['attr'] . $fieldEdit . '>'
            . h($onBehalf) . '</div>'
            . '</div>'
            . '<div class="cpb-lep-cert-row cpb-lep-cert-row--signatures">' . $signatureHtml . '</div>'
            . $authorityHtml
            . '</div>'
            . '</div>'
            . $tableHeadingHtml
            . $partsHtml
            . '</div>'
            . $footerHtml
            . '</div>';
    }

    /**
     * Part 0 admin page shell (0.2–0.7): shared heading hierarchy + section body.
     *
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array{key:string,style:string,text:string}> $headings
     */
    public function renderPart0AdminPageShell(
        array $version,
        array $section,
        array $headings,
        string $bodyHtml,
        string $mode = self::MODE_READ,
        ?array $pageHeaderConfig = null
    ): string {
        $editable = $mode === self::MODE_EDIT;
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

        $tokenContext = $this->buildHeaderTokenContext($version, $section, $mode, $pageHeaderConfig);

        $headerHtml = '';
        $footerHtml = '';
        if (!empty($pageHeader['enabled'])) {
            $headerHtml = $this->renderPageHeaderTable($pageHeader, $tokenContext, $editable, $headerSvc);
        }
        if (!empty($pageFooter['enabled'])) {
            $footerHtml = $this->renderPageFooterTable($pageFooter, $tokenContext, $editable, $headerSvc);
        }

        $editAttr = $editable ? ' data-part0-editable="1"' : '';
        $headingsByKey = array();
        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }
            $headingsByKey[(string)($heading['key'] ?? '')] = $heading;
        }

        $headingsHtml = '';
        foreach (array('subtitle_1') as $headingKey) {
            if (!isset($headingsByKey[$headingKey]) || !is_array($headingsByKey[$headingKey])) {
                continue;
            }
            $headingsHtml .= $this->renderPart0Heading($headingsByKey[$headingKey], $editable);
        }

        return '<div class="cpb-sheet cpb-sheet--part0" data-section-id="' . (int)($section['id'] ?? 0) . '"' . $editAttr . '>'
            . $headerHtml
            . '<div class="cpb-part0 cpb-lep" contenteditable="false">'
            . $headingsHtml
            . '<div class="cpb-part0-body">' . $bodyHtml . '</div>'
            . '</div>'
            . $footerHtml
            . '</div>';
    }

    /**
     * @param array<string,mixed> $heading
     */
    private function renderPart0Heading(array $heading, bool $editable): string
    {
        $key = h((string)($heading['key'] ?? ''));
        $styleKey = (string)($heading['style'] ?? 'body');
        $text = h((string)($heading['text'] ?? ''));
        $styling = $this->lepParagraphStyle($styleKey);
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        return '<div class="cpb-lep-heading cpb-lep-heading--' . $key . ' cpb-part0-heading ' . $styling['class'] . '"'
            . ' data-part0-heading="' . $key . '" data-part0-field="heading_' . $key . '"'
            . $styling['attr'] . $fieldEdit . '>' . $text . '</div>';
    }

    /**
     * @param array<string,mixed> $page
     */
    public function renderAmendmentListContent(array $page, bool $editable): string
    {
        $rows = is_array($page['rows'] ?? null) ? $page['rows'] : array();
        $emptyRows = max(0, min(30, (int)($page['empty_rows'] ?? 8)));
        $tableStyle = $this->resolveStandardTableStyle();
        $headerRow = $tableStyle['header_row'];
        $bodyRow = $tableStyle['body_row'];
        $headerVisual = $this->tableRowVisualAttr($headerRow, 'center');
        $bodyVisual = $this->tableRowVisualAttr($bodyRow, 'center');
        $borderWidth = (string)$tableStyle['border_width'];
        $borderColor = (string)$tableStyle['border_color'];
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';

        $bodyHtml = '';
        $rowIdx = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bodyHtml .= '<tr class="cpb-part0-amend-row" data-part0-row="' . $rowIdx . '">'
                . $this->renderPart0TableCell('revision_nr', $rowIdx, (string)($row['revision_nr'] ?? ''), $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('reason', $rowIdx, (string)($row['reason'] ?? ''), $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('revision_date', $rowIdx, (string)($row['revision_date'] ?? ''), $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('effective_date', $rowIdx, (string)($row['effective_date'] ?? ''), $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('date_incorp', $rowIdx, (string)($row['date_incorp'] ?? ''), $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('incorp_by', $rowIdx, (string)($row['incorp_by'] ?? ''), $bodyVisual, $fieldEdit)
                . '</tr>';
            $rowIdx++;
        }
        for ($i = 0; $i < $emptyRows; $i++) {
            $bodyHtml .= '<tr class="cpb-part0-amend-row cpb-part0-row--empty" data-part0-row="' . $rowIdx . '">'
                . $this->renderPart0TableCell('revision_nr', $rowIdx, '', $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('reason', $rowIdx, '', $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('revision_date', $rowIdx, '', $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('effective_date', $rowIdx, '', $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('date_incorp', $rowIdx, '', $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('incorp_by', $rowIdx, '', $bodyVisual, $fieldEdit)
                . '</tr>';
            $rowIdx++;
        }

        return '<div class="cpb-part0-amendment cpb-table-wrap cpb-table-border-' . h($borderWidth) . '"'
            . ' data-border-width="' . h($borderWidth) . '"'
            . ' style="--cpb-table-border-color:' . h($borderColor) . '" contenteditable="false">'
            . '<table class="cpb-table cpb-part0-table" data-part0-table="amendment_list">'
            . '<thead><tr class="cpb-table-header-row">'
            . '<th' . $headerVisual . '>REVISION NR</th>'
            . '<th' . $headerVisual . '>REASON</th>'
            . '<th' . $headerVisual . '>REVISION DATE</th>'
            . '<th' . $headerVisual . '>EFFECTIVE DATE</th>'
            . '<th' . $headerVisual . '>DATE INCORP.</th>'
            . '<th' . $headerVisual . '>INCORP. BY</th>'
            . '</tr></thead><tbody>' . $bodyHtml . '</tbody></table>'
            . '</div>';
    }

    /**
     * @param array<string,mixed> $page
     */
    public function renderDistributionListContent(array $page, bool $editable): string
    {
        $rows = is_array($page['rows'] ?? null) ? $page['rows'] : array();
        $emptyRows = max(0, min(30, (int)($page['empty_rows'] ?? 10)));
        $tableStyle = $this->resolveStandardTableStyle();
        $headerRow = $tableStyle['header_row'];
        $bodyRow = $tableStyle['body_row'];
        $headerVisual = $this->tableRowVisualAttr($headerRow, 'center');
        $bodyVisual = $this->tableRowVisualAttr($bodyRow, 'center');
        $borderWidth = (string)$tableStyle['border_width'];
        $borderColor = (string)$tableStyle['border_color'];
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';

        $bodyHtml = '';
        $rowIdx = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bodyHtml .= '<tr class="cpb-part0-dist-row" data-part0-row="' . $rowIdx . '">'
                . $this->renderPart0TableCell('copy_nr', $rowIdx, (string)($row['copy_nr'] ?? ''), $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('issue_to', $rowIdx, (string)($row['issue_to'] ?? ''), $bodyVisual, $fieldEdit)
                . '</tr>';
            $rowIdx++;
        }
        for ($i = 0; $i < $emptyRows; $i++) {
            $bodyHtml .= '<tr class="cpb-part0-dist-row cpb-part0-row--empty" data-part0-row="' . $rowIdx . '">'
                . $this->renderPart0TableCell('copy_nr', $rowIdx, '', $bodyVisual, $fieldEdit)
                . $this->renderPart0TableCell('issue_to', $rowIdx, '', $bodyVisual, $fieldEdit)
                . '</tr>';
            $rowIdx++;
        }

        return '<div class="cpb-part0-distribution cpb-table-wrap cpb-table-border-' . h($borderWidth) . '"'
            . ' data-border-width="' . h($borderWidth) . '"'
            . ' style="--cpb-table-border-color:' . h($borderColor) . '" contenteditable="false">'
            . '<table class="cpb-table cpb-part0-table" data-part0-table="distribution_list">'
            . '<thead><tr class="cpb-table-header-row">'
            . '<th' . $headerVisual . '>COPY NR</th>'
            . '<th' . $headerVisual . '>ISSUE TO</th>'
            . '</tr></thead><tbody>' . $bodyHtml . '</tbody></table>'
            . '</div>';
    }

    /**
     * @param array<string,mixed> $page
     */
    public function renderAbbreviationsIndexContent(array $page, bool $editable): string
    {
        $entries = is_array($page['entries'] ?? null) ? $page['entries'] : array();
        $emptyRows = max(0, min(30, (int)($page['empty_rows'] ?? 10)));
        $bodyStyle = $this->lepParagraphStyle('body');
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        $rowsHtml = '';
        $rowIdx = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $abbr = h((string)($entry['abbreviation'] ?? ''));
            $def = trim((string)($entry['definition'] ?? ''));
            $status = strtolower(trim((string)($entry['definition_status'] ?? '')));
            if ($status === '' && $def !== '') {
                $status = 'confirmed';
            }
            if ($def === '') {
                $status = 'needs_review';
            }

            $rowClass = 'cpb-part0-abbr-row';
            if ($status === 'needs_review') {
                $rowClass .= ' cpb-part0-abbr-row--review';
            } elseif ($status === 'ai_suggested') {
                $rowClass .= ' cpb-part0-abbr-row--ai';
            }

            $badge = '';
            if ($status === 'needs_review') {
                $badge = '<span class="cpb-part0-abbr-flag" title="Meaning not confirmed — please review">Review</span>';
            } elseif ($status === 'ai_suggested') {
                $badge = '<span class="cpb-part0-abbr-flag cpb-part0-abbr-flag--ai" title="AI-suggested expansion — please verify">AI</span>';
            }

            $actions = '';
            if ($editable) {
                $actions = '<span class="cpb-part0-abbr-actions">'
                    . '<button type="button" class="cpb-tool-btn cpb-part0-abbr-btn" data-abbr-action="find" data-abbr="' . h((string)($entry['abbreviation'] ?? '')) . '" title="Find mentions in manual">Find</button>'
                    . '<button type="button" class="cpb-tool-btn cpb-part0-abbr-btn cpb-part0-abbr-btn--remove" data-abbr-action="remove" data-abbr="' . h((string)($entry['abbreviation'] ?? '')) . '" title="Remove from list permanently">Remove</button>'
                    . '</span>';
            }

            $defHtml = $def !== '' ? h($def) : ($editable ? 'Add meaning…' : '—');
            $defClass = 'cpb-part0-abbr-def ' . $bodyStyle['class'];
            if ($def === '') {
                $defClass .= ' cpb-part0-abbr-def--empty';
            }

            $rowsHtml .= '<div class="' . $rowClass . '" data-part0-row="' . $rowIdx . '"'
                . ' data-definition-status="' . h($status) . '">'
                . '<span class="cpb-part0-abbr-term ' . $bodyStyle['class'] . '" data-part0-col="abbreviation"'
                . ' data-part0-row="' . $rowIdx . '"' . $bodyStyle['attr'] . '>' . $abbr . '</span>'
                . '<span class="cpb-part0-abbr-sep" aria-hidden="true">–</span>'
                . '<span class="' . $defClass . '" data-part0-col="definition"'
                . ' data-part0-row="' . $rowIdx . '"' . $bodyStyle['attr'] . $fieldEdit . '>' . $defHtml . '</span>'
                . $badge
                . $actions
                . '</div>';
            $rowIdx++;
        }
        for ($i = 0; $i < $emptyRows; $i++) {
            $rowsHtml .= '<div class="cpb-part0-abbr-row cpb-part0-row--empty" data-part0-row="' . $rowIdx . '">'
                . '<span class="cpb-part0-abbr-term ' . $bodyStyle['class'] . '" data-part0-col="abbreviation"'
                . ' data-part0-row="' . $rowIdx . '"' . $bodyStyle['attr'] . '>&nbsp;</span>'
                . '<span class="cpb-part0-abbr-sep" aria-hidden="true">–</span>'
                . '<span class="cpb-part0-abbr-def ' . $bodyStyle['class'] . '" data-part0-col="definition"'
                . ' data-part0-row="' . $rowIdx . '"' . $bodyStyle['attr'] . $fieldEdit . '>&nbsp;</span>'
                . '</div>';
            $rowIdx++;
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<p class="' . trim($bodyStyle['class']) . '"' . $bodyStyle['attr']
                . '>No abbreviations were identified in the manual content. Use Regenerate after adding content, or add entries manually.</p>';
        }

        return '<div class="cpb-part0-abbreviations" data-part0-table="abbreviations" contenteditable="false">'
            . $rowsHtml . '</div>';
    }

    /**
     * @param array<string,mixed> $page
     */
    public function renderDefinitionsListContent(array $page, bool $editable): string
    {
        $entries = is_array($page['entries'] ?? null) ? $page['entries'] : array();
        $emptyRows = max(0, min(30, (int)($page['empty_rows'] ?? 12)));
        $bodyStyle = $this->lepParagraphStyle('body');
        $termStyle = $this->lepEmphasisStyle();
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        $rowsHtml = '';
        $rowIdx = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $term = h((string)($entry['term'] ?? ''));
            $def = h((string)($entry['definition'] ?? ''));
            $rowsHtml .= '<div class="cpb-part0-def-row" data-part0-row="' . $rowIdx . '">'
                . '<span class="cpb-part0-def-term ' . trim($termStyle['class'] . ' cpb-lep-emphasis') . '" data-part0-col="term"'
                . ' data-part0-row="' . $rowIdx . '"' . $termStyle['attr'] . $fieldEdit . '>' . $term . ':</span>'
                . '<span class="cpb-part0-def-text ' . $bodyStyle['class'] . '" data-part0-col="definition"'
                . ' data-part0-row="' . $rowIdx . '"' . $bodyStyle['attr'] . $fieldEdit . '>' . $def . '</span>'
                . '</div>';
            $rowIdx++;
        }
        for ($i = 0; $i < $emptyRows; $i++) {
            $rowsHtml .= '<div class="cpb-part0-def-row cpb-part0-row--empty" data-part0-row="' . $rowIdx . '">'
                . '<span class="cpb-part0-def-term ' . trim($termStyle['class'] . ' cpb-lep-emphasis') . '" data-part0-col="term"'
                . ' data-part0-row="' . $rowIdx . '"' . $termStyle['attr'] . $fieldEdit . '>&nbsp;</span>'
                . '<span class="cpb-part0-def-text ' . $bodyStyle['class'] . '" data-part0-col="definition"'
                . ' data-part0-row="' . $rowIdx . '"' . $bodyStyle['attr'] . $fieldEdit . '>&nbsp;</span>'
                . '</div>';
            $rowIdx++;
        }

        return '<div class="cpb-part0-definitions" data-part0-table="definitions" contenteditable="false">'
            . $rowsHtml . '</div>';
    }

    private function renderPart0TableCell(
        string $col,
        int $rowIdx,
        string $value,
        string $bodyVisual,
        string $fieldEdit
    ): string {
        $display = $value !== '' ? h($value) : '&nbsp;';
        return '<td' . $bodyVisual
            . ' data-part0-col="' . h($col) . '" data-part0-row="' . $rowIdx . '"' . $fieldEdit . '>'
            . $display . '</td>';
    }

    /**
     * @return array{class:string,attr:string}
     */
    private function lepParagraphStyle(string $styleKey, bool $forceBold = false): array
    {
        $payload = array('paragraph_style' => $styleKey);
        if ($forceBold) {
            $payload['font_bold'] = true;
        }
        return array(
            'class' => trim('cpb-paragraph' . $this->styleClass($payload)),
            'attr' => $this->styleAttr($payload),
        );
    }

    /**
     * Body typography with bold weight for post-holder names and titles.
     *
     * @return array{class:string,attr:string}
     */
    private function lepEmphasisStyle(): array
    {
        return $this->lepParagraphStyle('body', true);
    }

    /**
     * @param array<string,mixed> $heading
     */
    private function renderLepHeading(array $heading, bool $editable): string
    {
        $key = h((string)($heading['key'] ?? ''));
        $styleKey = (string)($heading['style'] ?? 'body');
        $text = h((string)($heading['text'] ?? ''));
        $styling = $this->lepParagraphStyle($styleKey);
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        return '<div class="cpb-lep-heading cpb-lep-heading--' . $key . ' ' . $styling['class'] . '"'
            . ' data-lep-heading="' . $key . '" data-lep-field="heading_' . $key . '"'
            . $styling['attr'] . $fieldEdit . '>' . $text . '</div>';
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveStandardTableStyle(): array
    {
        if ($this->styleService !== null && $this->bookStyles !== array()) {
            return $this->styleService->resolveStandardTableStyle($this->bookStyles);
        }
        return array(
            'border_width' => 'thin',
            'border_color' => '#94a3b8',
            'cell_bg' => '#ffffff',
            'title_row' => array(
                'font_family' => 'sans',
                'font_size' => 11,
                'color' => '#0f2744',
                'bg' => '#e8eef6',
                'font_bold' => true,
                'font_italic' => false,
                'font_underline' => false,
            ),
            'header_row' => array(
                'font_family' => 'sans',
                'font_size' => 10,
                'color' => '#0f172a',
                'bg' => '#f1f5f9',
                'font_bold' => true,
                'font_italic' => false,
                'font_underline' => false,
            ),
            'body_row' => array(
                'font_family' => 'sans',
                'font_size' => 10,
                'color' => '#0f172a',
                'bg' => '',
                'font_bold' => false,
                'font_italic' => false,
                'font_underline' => false,
            ),
        );
    }

    /**
     * @param array<string,mixed> $rowStyle
     */
    private function tableRowVisualAttr(array $rowStyle, string $align = 'center', string $bgOverride = ''): string
    {
        $bg = $bgOverride !== '' ? $bgOverride : (string)($rowStyle['bg'] ?? '');
        return $this->tableCellVisualAttr(
            $bg,
            $align,
            (string)($rowStyle['font_family'] ?? 'serif'),
            (int)($rowStyle['font_size'] ?? 10),
            (string)($rowStyle['color'] ?? '#0f172a'),
            $this->normalizeDecorationBool($rowStyle['font_bold'] ?? null, false),
            $this->normalizeDecorationBool($rowStyle['font_italic'] ?? null, false),
            $this->normalizeDecorationBool($rowStyle['font_underline'] ?? null, false)
        );
    }

    private function normalizeDecorationBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($normalized, array('0', 'false', 'no', 'off', ''), true)) {
                return false;
            }
        }
        return $default;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function renderLepSignatoryBox(array $slot, bool $editable): string
    {
        $slotKey = h((string)($slot['slot_key'] ?? ''));
        $name = h((string)($slot['name'] ?? ''));
        $title = h((string)($slot['title'] ?? ''));
        $date = h((string)($slot['date'] ?? ''));
        $sigUrl = trim((string)($slot['signature_url'] ?? ''));
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        $bodyStyle = $this->lepParagraphStyle('body');
        $emphasisStyle = $this->lepEmphasisStyle();

        $sigInner = '';
        if ($sigUrl !== '') {
            $sigInner = '<img class="cpb-lep-signature-img" src="' . h($sigUrl) . '" alt="Signature">';
        } elseif ($editable) {
            $sigInner = '<button type="button" class="cpb-lep-sign-btn" data-lep-sign="' . $slotKey . '">Sign</button>';
        } else {
            $sigInner = '<span class="cpb-lep-signature-empty">—</span>';
        }

        return '<div class="cpb-lep-signatory" data-lep-slot="' . $slotKey . '">'
            . '<div class="' . trim($emphasisStyle['class'] . ' cpb-lep-signatory-name cpb-lep-emphasis') . '" data-lep-field="name"' . $emphasisStyle['attr'] . $fieldEdit . '>' . $name . '</div>'
            . '<div class="' . trim($emphasisStyle['class'] . ' cpb-lep-signatory-title cpb-lep-emphasis') . '" data-lep-field="title"' . $emphasisStyle['attr'] . $fieldEdit . '>' . $title . '</div>'
            . '<div class="' . trim($bodyStyle['class'] . ' cpb-lep-signatory-date') . '"' . $bodyStyle['attr'] . '>'
            . '<span class="cpb-lep-label">Date:</span> '
            . '<span class="cpb-lep-signatory-date-value" data-lep-field="date"' . $fieldEdit . '>' . $date . '</span></div>'
            . '<div class="' . trim($bodyStyle['class'] . ' cpb-lep-signatory-signature') . '"' . $bodyStyle['attr'] . '>'
            . '<span class="cpb-lep-label">Signature:</span> '
            . '<div class="cpb-lep-signature-box" data-lep-signature-box="' . $slotKey . '">' . $sigInner . '</div></div>'
            . '</div>';
    }

    /**
     * @param array<string,mixed>|null $authoritySlot
     * @param array<string,mixed>|null $approval
     */
    private function renderLepAuthorityBlock(?array $authoritySlot, ?array $approval, bool $editable, int $versionId): string
    {
        $signed = is_array($authoritySlot)
            && trim((string)($authoritySlot['signature_url'] ?? '')) !== '';
        if ($signed) {
            $bodyStyle = $this->lepParagraphStyle('body');
            return '<div class="cpb-lep-cert-row cpb-lep-cert-row--authority">'
                . '<div class="' . trim($bodyStyle['class'] . ' cpb-lep-authority-label cpb-lep-emphasis') . '"' . $bodyStyle['attr'] . '>Authority approval</div>'
                . $this->renderLepSignatoryBox($authoritySlot, false)
                . '</div>';
        }
        $approvalUrl = '';
        if ($versionId > 0 && is_array($approval) && trim((string)($approval['token'] ?? '')) !== '') {
            $approvalUrl = '/admin/compliance/controlled_book_approval.php?version_id='
                . $versionId
                . '&token=' . rawurlencode((string)$approval['token']);
        }
        $pending = '<div class="cpb-lep-authority-pending ' . $this->lepParagraphStyle('body')['class'] . '"'
            . $this->lepParagraphStyle('body')['attr'] . '>'
            . '<strong class="cpb-lep-emphasis">Authority signature pending</strong>'
            . '<span>Competent authority approval is collected via the Approval page.</span>';
        if ($editable && $approvalUrl !== '') {
            $pending .= '<a class="cpb-lep-approval-link" href="' . h($approvalUrl) . '" target="_blank" rel="noopener">Open Approval page</a>';
        }
        $pending .= '</div>';
        return '<div class="cpb-lep-cert-row cpb-lep-cert-row--authority">' . $pending . '</div>';
    }

    /**
     * @param array<string,mixed> $lep
     */
    private function renderLepPartsTable(array $lep, bool $editable): string
    {
        $parts = is_array($lep['effective_parts'] ?? null) ? $lep['effective_parts'] : array();
        $emptyRows = max(0, min(20, (int)($lep['empty_rows'] ?? 10)));
        $tableStyle = $this->resolveStandardTableStyle();
        $headerRow = $tableStyle['header_row'];
        $bodyRow = $tableStyle['body_row'];
        $headerVisual = $this->tableRowVisualAttr($headerRow, 'center');
        $bodyVisual = $this->tableRowVisualAttr($bodyRow, 'center');
        $borderWidth = (string)$tableStyle['border_width'];
        $borderColor = (string)$tableStyle['border_color'];

        $rows = '';
        foreach ($parts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows .= '<tr class="cpb-lep-part-row" data-lep-part-generated="1">'
                . '<td' . $bodyVisual . '>' . h((string)($row['part'] ?? '')) . '</td>'
                . '<td' . $bodyVisual . '>' . h((string)($row['pages'] ?? '—')) . '</td>'
                . '<td' . $bodyVisual . '>' . h((string)($row['date'] ?? '')) . '</td>'
                . '<td' . $bodyVisual . '>' . h((string)($row['revision'] ?? '')) . '</td>'
                . '</tr>';
        }
        for ($i = 0; $i < $emptyRows; $i++) {
            $rows .= '<tr class="cpb-lep-part-row cpb-lep-part-row--empty">'
                . '<td' . $bodyVisual . '>&nbsp;</td>'
                . '<td' . $bodyVisual . '></td>'
                . '<td' . $bodyVisual . '></td>'
                . '<td' . $bodyVisual . '></td></tr>';
        }
        if ($rows === '' && $editable) {
            $rows = '<tr class="cpb-lep-part-row cpb-lep-part-row--empty"><td colspan="4"'
                . $bodyVisual
                . '>No parts generated yet — use Regenerate in the toolbar.</td></tr>';
        }

        return '<div class="cpb-lep-parts-wrap cpb-table-wrap cpb-table-border-' . h($borderWidth) . '"'
            . ' data-border-width="' . h($borderWidth) . '"'
            . ' style="--cpb-table-border-color:' . h($borderColor) . '" contenteditable="false">'
            . '<table class="cpb-table cpb-lep-table" data-lep-parts-table="1">'
            . '<thead><tr class="cpb-table-header-row">'
            . '<th' . $headerVisual . '>Part</th>'
            . '<th' . $headerVisual . '>Pages</th>'
            . '<th' . $headerVisual . '>Date</th>'
            . '<th' . $headerVisual . '>Revision</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div>';
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param array<string,mixed>|null $coverPage
     */
    public function renderCoverPageShell(
        array $version,
        array $section,
        string $mode = self::MODE_READ,
        ?array $pageHeaderConfig = null,
        ?array $coverPage = null
    ): string {
        $editable = $mode === self::MODE_EDIT;
        $coverSvc = $this->coverPageService;
        $cover = is_array($coverPage)
            ? $coverPage
            : ($coverSvc !== null ? $coverSvc->resolveFromVersion($version) : array());

        $headerSvc = $this->pageHeaderService;
        $tokenContext = $headerSvc !== null
            ? $headerSvc->buildTokenContext($version, $section, array(
                'editor_preview' => $mode === self::MODE_EDIT,
                'page' => 1,
            ))
            : array();

        $companyName = h((string)($cover['company_name'] ?? ''));
        $registration = h((string)($cover['registration_number'] ?? ''));
        $manualTitle = h((string)($cover['manual_title'] ?? ''));
        $logoUrl = trim((string)($cover['logo_url'] ?? ''));
        $logoAlt = h((string)($cover['logo_alt'] ?? ''));
        $coverImageUrl = trim((string)($cover['cover_image_url'] ?? ''));

        $logoHtml = '';
        if ($logoUrl !== '') {
            $logoHtml = '<img class="cpb-cover-logo-img" src="' . h($logoUrl) . '" alt="' . $logoAlt . '">';
        } elseif ($editable) {
            $logoHtml = '<span class="cpb-cover-logo-placeholder">Drop logo here — spans full page width</span>';
        }

        $coverImageHtml = '';
        if ($coverImageUrl !== '') {
            $coverImageHtml = '<img class="cpb-cover-image-img" src="' . h($coverImageUrl) . '" alt="' . h((string)($cover['cover_image_alt'] ?? '')) . '">';
        } elseif ($editable) {
            $coverImageHtml = '<span class="cpb-cover-image-placeholder">Drop cover image here</span>';
        }

        $revisionLine = $coverSvc !== null ? h($coverSvc->buildRevisionLine($version)) : '';
        $dateLine = $coverSvc !== null ? h($coverSvc->buildDateLine($version)) : '';
        $statusLine = $coverSvc !== null ? h($coverSvc->buildStatusLine($version)) : '';

        $editAttr = $editable ? ' data-cover-editable="1"' : '';
        $fieldEdit = $editable ? ' contenteditable="true"' : ' contenteditable="false"';
        $logoDropAttr = $editable ? ' data-cover-drop="logo"' : '';
        $imageDropAttr = $editable ? ' data-cover-drop="cover_image"' : '';

        $metaLines = array();
        if ($revisionLine !== '') {
            $metaLines[] = '<p class="cpb-cover-meta-line">' . $revisionLine . '</p>';
        }
        if ($dateLine !== '') {
            $metaLines[] = '<p class="cpb-cover-meta-line">' . $dateLine . '</p>';
        }
        if ($statusLine !== '') {
            $metaLines[] = '<p class="cpb-cover-meta-line cpb-cover-status">' . $statusLine . '</p>';
        }

        return '<div class="cpb-sheet cpb-sheet--cover" data-section-id="' . (int)($section['id'] ?? 0) . '"' . $editAttr . '>'
            . '<div class="cpb-cover" contenteditable="false">'
            . '<header class="cpb-cover-header">'
            . '<div class="cpb-cover-logo-band"' . $logoDropAttr . '>' . $logoHtml . '</div>'
            . '<div class="cpb-cover-brand">'
            . '<div class="cpb-cover-company" data-cover-field="company_name"' . $fieldEdit . '>' . $companyName . '</div>'
            . '<div class="cpb-cover-registration" data-cover-field="registration_number"' . $fieldEdit . '>' . $registration . '</div>'
            . '</div>'
            . '</header>'
            . '<div class="cpb-cover-hero">'
            . '<div class="cpb-cover-bar" aria-hidden="true"></div>'
            . '<div class="cpb-cover-image"' . $imageDropAttr . '>' . $coverImageHtml . '</div>'
            . '<div class="cpb-cover-bar" aria-hidden="true"></div>'
            . '</div>'
            . '<div class="cpb-cover-details">'
            . '<h1 class="cpb-cover-manual-title" data-cover-field="manual_title"' . $fieldEdit . '>' . $manualTitle . '</h1>'
            . implode('', $metaLines)
            . '</div>'
            . '</div>'
            . '</div>';
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

        $tokenContext = $this->buildHeaderTokenContext($version, $section, $mode, $pageHeaderConfig);

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
        $logoMaxHeight = max(16, min(120, (int)($pageHeader['logo_max_height'] ?? 40)));
        $logoStyle = ' style="max-height:' . $logoMaxHeight . 'px;"';
        $rowHeight = max(20, min(120, (int)($pageHeader['row_height'] ?? 32)));
        $rowCellStyle = $this->pageBandRowCellStyleAttr($rowHeight, false);
        $centerTypo = $this->columnTypography($pageHeader, 'center');
        $rightTypo = $this->columnTypography($pageHeader, 'right');
        $centerStyle = $this->pageBandCellStyleAttr($centerTypo, $rowHeight);
        $rightStyle = $this->pageBandCellStyleAttr($rightTypo, $rowHeight);

        $leftCell = '';
        if ($logoUrl !== '') {
            $leftCell = '<img class="cpb-page-header-logo" src="' . h($logoUrl) . '" alt="' . $logoAlt . '"' . $logoStyle . '>';
        } elseif ($editable) {
            $leftCell = '<span class="cpb-page-header-logo-placeholder">Logo</span>';
        }

        $editAttr = $editable
            ? ' data-open-header-editor="1" title="Click to edit page header"'
            : '';

        return '<header class="cpb-page-header"' . $editAttr . ' contenteditable="false">'
            . '<table class="cpb-page-header-table" role="presentation">'
            . '<tr>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--left"' . $rowCellStyle . '>' . $leftCell . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--center ' . $this->pageBandFontClass($centerTypo) . '"' . $centerStyle . '>'
            . $resolve((string)($pageHeader['center_text'] ?? ''))
            . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--right ' . $this->pageBandFontClass($rightTypo) . '"' . $rightStyle . '>'
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
        ?ControlledPublishingPageHeaderService $headerSvc,
        bool $plain = false
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

        $rowHeight = max(20, min(120, (int)($pageFooter['row_height'] ?? 26)));
        $leftTypo = $this->columnTypography($pageFooter, 'left');
        $centerTypo = $this->columnTypography($pageFooter, 'center');
        $rightTypo = $this->columnTypography($pageFooter, 'right');
        $leftStyle = $this->pageBandCellStyleAttr($leftTypo, $rowHeight);
        $centerStyle = $this->pageBandCellStyleAttr($centerTypo, $rowHeight);
        $rightStyle = $this->pageBandCellStyleAttr($rightTypo, $rowHeight);

        $plainClass = $plain ? ' cpb-page-footer--plain' : '';

        return '<footer class="cpb-page-footer' . $plainClass . '"' . $editAttr . ' contenteditable="false">'
            . '<table class="cpb-page-header-table cpb-page-footer-table" role="presentation">'
            . '<tr>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--left ' . $this->pageBandFontClass($leftTypo) . '"' . $leftStyle . '>'
            . $resolve((string)($pageFooter['left_text'] ?? ''))
            . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--center ' . $this->pageBandFontClass($centerTypo) . '"' . $centerStyle . '>'
            . $resolve((string)($pageFooter['center_text'] ?? ''))
            . '</td>'
            . '<td class="cpb-page-header-cell cpb-page-header-cell--right ' . $this->pageBandFontClass($rightTypo) . '"' . $rightStyle . '>'
            . $resolve((string)($pageFooter['right_text'] ?? ''))
            . '</td>'
            . '</tr></table></footer>';
    }

    /**
     * @param array<string,mixed> $band
     * @return array{font_family:string,font_size:int,font_bold:bool,font_italic:bool,font_underline:bool}
     */
    private function columnTypography(array $band, string $prefix): array
    {
        return array(
            'font_family' => (string)($band[$prefix . '_font_family'] ?? 'sans'),
            'font_size' => (int)($band[$prefix . '_font_size'] ?? 11),
            'font_bold' => !empty($band[$prefix . '_font_bold']),
            'font_italic' => !empty($band[$prefix . '_font_italic']),
            'font_underline' => !empty($band[$prefix . '_font_underline']),
        );
    }

    private function pageBandRowCellStyleAttr(int $rowHeight, bool $textCell = false): string
    {
        $rowHeight = max(20, min(120, $rowHeight));
        $padY = max(2, (int)round(($rowHeight - 14) / 2));
        $lineHeight = $textCell ? '1.45' : '1.2';
        return ' style="padding:' . $padY . 'px 8px;min-height:' . $rowHeight
            . 'px;line-height:' . $lineHeight . ';box-sizing:border-box;"';
    }

    /**
     * @param array{font_family:string,font_size:int,font_bold:bool,font_italic:bool,font_underline:bool} $typography
     */
    private function pageBandCellStyleAttr(array $typography, int $rowHeight): string
    {
        $rowHeight = max(20, min(120, $rowHeight));
        $padY = max(2, (int)round(($rowHeight - 14) / 2));
        $fontSize = max(8, min(24, (int)$typography['font_size']));
        $fontStack = $this->fontFamilyStack((string)$typography['font_family']);
        $parts = array(
            'font-size:' . $fontSize . 'pt',
            'font-weight:' . (!empty($typography['font_bold']) ? '700' : '400'),
            'font-style:' . (!empty($typography['font_italic']) ? 'italic' : 'normal'),
            'text-decoration:' . (!empty($typography['font_underline']) ? 'underline' : 'none'),
            'padding:' . $padY . 'px 8px',
            'min-height:' . $rowHeight . 'px',
            'line-height:1.45',
            'box-sizing:border-box',
        );
        if ($fontStack !== '') {
            $parts[] = 'font-family:' . $fontStack . ' !important';
        }
        return ' style="' . implode(';', $parts) . '"';
    }

    /**
     * @param array{font_family:string,font_size:int,font_bold:bool,font_italic:bool,font_underline:bool} $typography
     */
    private function pageBandFontClass(array $typography): string
    {
        $key = preg_replace('/[^a-z]/', '', strtolower((string)($typography['font_family'] ?? 'sans')));
        if (!in_array($key, ControlledPublishingBookStyleService::FONT_KEYS, true)) {
            $key = 'sans';
        }
        return 'cpb-font-' . $key;
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
        $canonRef = trim((string)($payload['canonical_section_ref'] ?? ''));
        $canonAttr = $canonRef !== '' ? ' data-canonical-section-ref="' . h($canonRef) . '"' : '';
        return '<div class="cpb-paragraph-row"' . $canonAttr . '>'
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
        return '<span class="cpb-section-number' . $this->styleClass($payload) . '" contenteditable="false"'
            . $this->typographyStyleAttr($payload)
            . ' data-section-number="' . h($display) . '">' . h($display) . ' </span>';
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
        $bodyTypo = $this->resolveBodyTypography();
        $fontFamily = (string)$bodyTypo['font_family'];
        $fontSize = (int)$bodyTypo['font_size'];
        $fontKey = preg_replace('/[^a-z]/', '', strtolower($fontFamily));
        $fontStack = $this->fontFamilyStack($fontFamily);
        $badgeStyle = ' style="font-family:' . $fontStack . ';font-size:' . $fontSize
            . 'pt;font-weight:400;color:#1e3a8a"';
        return '<span class="cpb-regulatory-ref cpb-font-' . h($fontKey) . '" contenteditable="false" data-regulatory-ref="'
            . h($effective) . '"' . $autoAttr . $badgeStyle . '>' . h($effective) . '</span> ';
    }

    /**
     * @return array{font_family:string,font_size:int,color:string}
     */
    private function resolveBodyTypography(): array
    {
        if ($this->styleService !== null && $this->bookStyles !== array()) {
            return $this->styleService->resolveBlockTypography(
                array('paragraph_style' => 'body'),
                $this->bookStyles
            );
        }
        return array(
            'font_family' => 'serif',
            'font_size' => 11,
            'color' => '#0f172a',
        );
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
    private function renderToc(array $payload, string $mode): string
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : array();
        if ($entries === array()) {
            return '<nav class="cpb-toc" aria-label="Table of contents"><p class="cpb-toc-empty">No entries.</p></nav>';
        }

        $titleColor = (string)$this->resolveTypography(array('paragraph_style' => 'title'))['color'];
        $rows = '';
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $style = (string)($entry['style'] ?? 'body');
            $depth = max(0, min(4, (int)($entry['depth'] ?? 0)));
            $label = (string)($entry['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $anchor = trim((string)($entry['target_anchor'] ?? ''));
            $sectionId = (int)($entry['section_id'] ?? 0);
            $page = $entry['page'] ?? null;
            $pageText = $page === null || $page === '' ? '—' : (string)$page;

            $typoPayload = array(
                'paragraph_style' => $style,
                'text_color' => $titleColor,
            );
            $baseTypography = $this->resolveTypography($typoPayload);
            $typoPayload['font_size'] = max(6, (int)$baseTypography['font_size'] - self::TOC_FONT_SIZE_DELTA);
            $styleClass = $this->styleClass($typoPayload);
            $styleAttr = $this->styleAttr($typoPayload);
            $titleClass = $style === 'title' ? ' cpb-toc-row--title' : '';

            $labelInner = h($label);
            if ($anchor !== '') {
                $href = '#' . rawurlencode($anchor);
                $labelInner = '<a class="cpb-toc-link" href="' . h($href) . '"'
                    . ' data-section-id="' . $sectionId . '"'
                    . ' data-toc-target="' . h($anchor) . '">'
                    . h($label) . '</a>';
            }

            $rows .= '<div class="cpb-toc-row cpb-toc-depth-' . $depth . $titleClass
                . $styleClass . '" data-toc-depth="' . $depth . '" data-toc-style="' . h($style) . '"'
                . $styleAttr . '>'
                . '<span class="cpb-toc-label">' . $labelInner . '</span>'
                . '<span class="cpb-toc-leader" aria-hidden="true"></span>'
                . '<span class="cpb-toc-page" data-toc-page="' . h($pageText) . '">' . h($pageText) . '</span>'
                . '</div>';
        }

        return '<nav class="cpb-toc" aria-label="Table of contents">' . $rows . '</nav>';
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
        $titleTextColor = (string)$table['title_text_color'];
        $headerAligns = $table['header_align'];
        $headerFontFamilies = $table['header_font_family'];
        $headerFontSizes = $table['header_font_size'];
        $headerTextColors = $table['header_text_color'];
        $cellAligns = $table['cell_align'];
        $cellFontFamilies = $table['cell_font_family'];
        $cellFontSizes = $table['cell_font_size'];
        $cellTextColors = $table['cell_text_color'];
        $headerColspans = $table['header_colspans'];
        $rowColspans = $table['row_colspans'];
        $hasTitleRow = !empty($table['has_title_row']);
        $tableAlign = (string)$table['table_align'];
        $tableStyleKind = strtolower(trim((string)($payload['table_style_kind'] ?? 'standard')));
        if (!in_array($tableStyleKind, array('standard', 'text'), true)) {
            $tableStyleKind = 'standard';
        }
        $colCount = count($headers);
        $edit = $mode === self::MODE_EDIT;

        $html = '<div class="cpb-table-block cpb-table-block--align-' . h($tableAlign) . '"'
            . ' data-table-align="' . h($tableAlign) . '"'
            . ' data-table-style-kind="' . h($tableStyleKind) . '">';
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
            $titlePlain = trim(strip_tags($title));
            $titleRowClass = 'cpb-table-title-row' . ($titlePlain === '' ? ' is-empty' : '');
            $titleEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $titleDisplay = $this->renderTableCellInner($title, $edit, $rows);
            $titleRowStyle = $this->resolveStandardTableStyle()['title_row'] ?? array();
            $titleVisual = $this->tableCellVisualAttr(
                $titleBg,
                $titleAlign,
                $titleFontFamily,
                $titleFontSize,
                $titleTextColor,
                $this->normalizeDecorationBool($titleRowStyle['font_bold'] ?? null, true),
                $this->normalizeDecorationBool($titleRowStyle['font_italic'] ?? null, false),
                $this->normalizeDecorationBool($titleRowStyle['font_underline'] ?? null, false)
            );
            $html .= '<tr class="' . $titleRowClass . '" data-title-row="1">';
            $html .= '<td colspan="' . $colCount . '"' . $titleEdit . $titleVisual
                . ' data-placeholder="Table title (spans all columns)">' . $titleDisplay . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr class="cpb-table-header-row">';
        $colIndex = 0;
        foreach ($headers as $headerIndex => $header) {
            $colspan = max(1, (int)($headerColspans[$headerIndex] ?? 1));
            $headerEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
            $headerBg = (string)($headerBgs[$colIndex] ?? '');
            $headerAlign = (string)($headerAligns[$colIndex] ?? 'left');
            $headerFont = (string)($headerFontFamilies[$colIndex] ?? '');
            $headerSize = (int)($headerFontSizes[$colIndex] ?? 0);
            $headerColor = (string)($headerTextColors[$colIndex] ?? '');
            $html .= '<th colspan="' . $colspan . '"' . $headerEdit . $this->tableCellVisualAttr($headerBg, $headerAlign, $headerFont, $headerSize, $headerColor) . ' data-col-index="' . $colIndex . '">';
            $html .= '<span class="cpb-th-text">' . $this->renderTableCellInner((string)$header, $edit, $rows) . '</span>';
            if ($edit) {
                $html .= '<span class="cpb-col-resize" data-col-index="' . $colIndex . '" title="Resize column"></span>';
            }
            $html .= '</th>';
            $colIndex += $colspan;
        }
        $html .= '</tr></thead>';

        $html .= '<tbody data-table-part="body">';
        $rowIndex = 0;
        foreach ($rows as $row) {
            $html .= '<tr>';
            $cellIndex = 0;
            $spans = $rowColspans[$rowIndex] ?? array();
            foreach ($row as $cellPos => $cell) {
                $colspan = max(1, (int)($spans[$cellPos] ?? 1));
                $cellEdit = $edit ? ' contenteditable="true" spellcheck="true"' : '';
                $bg = (string)($cellBgs[$rowIndex][$cellIndex] ?? '');
                $align = (string)($cellAligns[$rowIndex][$cellIndex] ?? 'left');
                $cellFont = (string)($cellFontFamilies[$rowIndex][$cellIndex] ?? '');
                $cellSize = (int)($cellFontSizes[$rowIndex][$cellIndex] ?? 0);
                $cellColor = (string)($cellTextColors[$rowIndex][$cellIndex] ?? '');
                $rawCell = (string)$cell;
                $formulaAttr = (!$edit && str_starts_with($rawCell, '='))
                    ? ' data-formula="' . h($rawCell) . '" title="' . h($rawCell) . '"'
                    : '';
                $html .= '<td colspan="' . $colspan . '"' . $cellEdit . $this->tableCellVisualAttr($bg, $align, $cellFont, $cellSize, $cellColor) . $formulaAttr . '>'
                    . $this->renderTableCellInner($rawCell, $edit, $rows) . '</td>';
                $cellIndex += $colspan;
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
                . '<span class="cpb-table-style-label">Rows</span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="move-row-up" title="Move selected row up">↑ Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="move-row-down" title="Move selected row down">↓ Row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-row" title="Add row at bottom">+ Row</button>'
                . '<button type="button" class="cpb-mini-btn cpb-mini-btn--danger" data-table-action="del-row" title="Delete selected row">Delete row</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="add-col" title="Add column at right">+ Column</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="del-col" title="Remove rightmost column">− Column</button>'
                . '<span class="cpb-table-style-sep"></span>'
                . '<span class="cpb-table-style-label">Cells</span>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="merge-cells-right" title="Merge with cell to the right">Merge →</button>'
                . '<button type="button" class="cpb-mini-btn" data-table-action="unmerge-cells" title="Split merged cell into columns">Unmerge</button>'
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
                . '<span class="cpb-table-style-label">Text</span>'
                . '<input type="color" class="cpb-table-color" data-table-action="cell-text-color" value="#0f172a" title="Text color (select a cell first)">'
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
        $title = $this->sanitizeTableCellValue(trim((string)($payload['title'] ?? '')));
        $hasTitleRow = !empty($payload['has_title_row']);
        $headers = array();
        $rows = array();
        $colWidths = array();

        if (is_array($payload['headers'] ?? null)) {
            foreach ($payload['headers'] as $cell) {
                $headers[] = $this->sanitizeTableCellValue(trim((string)$cell));
            }
        }
        if (is_array($payload['rows'] ?? null)) {
            foreach ($payload['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $cell) {
                    $line[] = $this->sanitizeTableCellValue(trim((string)$cell));
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
        $titleStyle = $this->resolveStandardTableStyle()['title_row'] ?? array();
        $titleFontDefault = (string)($titleStyle['font_family'] ?? 'sans');
        $titleSizeDefault = (int)($titleStyle['font_size'] ?? 11);
        $titleColorDefault = (string)($titleStyle['color'] ?? '#0f2744');
        $titleBgDefault = (string)($titleStyle['bg'] ?? '#e8eef6');
        $titleFontFamily = $this->normalizeTableCellFont((string)($payload['title_font_family'] ?? ''), $titleFontDefault);
        // Older DOCX imports stored serif on title rows; follow book-style default instead.
        if ($titleFontFamily === 'serif' && $titleFontDefault !== 'serif') {
            $titleFontFamily = $titleFontDefault;
        }
        $titleFontSize = $this->normalizeTableCellFontSize($payload['title_font_size'] ?? $titleSizeDefault);
        $titleTextColor = $this->normalizeTableHexColor((string)($payload['title_text_color'] ?? ''), $titleColorDefault);
        if ($titleBg === '' && trim((string)($payload['title_bg'] ?? '')) === '') {
            $titleBg = $this->normalizeTableHexColor($titleBgDefault, '#e8eef6');
        }

        $headerAlign = array();
        if (is_array($payload['header_align'] ?? null)) {
            foreach ($payload['header_align'] as $align) {
                $headerAlign[] = $this->normalizeTableCellAlign((string)$align, 'left');
            }
        }
        $headerAlign = array_pad(array_slice($headerAlign, 0, $colCount), $colCount, 'left');

        $headerFontFamily = $this->normalizeTableOptionalFontRow($payload, 'header_font_family', $colCount);
        $headerFontSize = $this->normalizeTableOptionalFontSizeRow($payload, 'header_font_size', $colCount);
        $headerTextColor = $this->normalizeTableOptionalColorRow($payload, 'header_text_color', $colCount);

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

        $cellFontFamily = $this->normalizeTableOptionalFontGrid($payload, 'cell_font_family', count($normalizedRows), $colCount);
        $cellFontSize = $this->normalizeTableOptionalFontSizeGrid($payload, 'cell_font_size', count($normalizedRows), $colCount);
        $cellTextColor = $this->normalizeTableOptionalColorGrid($payload, 'cell_text_color', count($normalizedRows), $colCount);

        $headerColspans = array();
        if (is_array($payload['header_colspans'] ?? null)) {
            foreach ($payload['header_colspans'] as $span) {
                $headerColspans[] = max(1, (int)$span);
            }
        }
        if ($headerColspans === array()) {
            $headerColspans = array_fill(0, $colCount, 1);
        }

        $rowColspans = array();
        if (is_array($payload['row_colspans'] ?? null)) {
            foreach ($payload['row_colspans'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $span) {
                    $line[] = max(1, (int)$span);
                }
                $rowColspans[] = $line;
            }
        }
        while (count($rowColspans) < count($normalizedRows)) {
            $rowColspans[] = array_fill(0, max(1, count($normalizedRows[count($rowColspans)] ?? array())), 1);
        }

        return array(
            'title' => $title,
            'has_title_row' => $hasTitleRow,
            'headers' => $headers,
            'header_colspans' => $headerColspans,
            'rows' => $normalizedRows,
            'row_colspans' => $rowColspans,
            'col_widths' => $colWidths,
            'border_width' => $borderWidth,
            'border_color' => $borderColor,
            'title_bg' => $titleBg,
            'header_bg' => $headerBg,
            'cell_bg' => $cellBg,
            'title_align' => $titleAlign,
            'title_font_family' => $titleFontFamily,
            'title_font_size' => $titleFontSize,
            'title_text_color' => $titleTextColor,
            'header_align' => $headerAlign,
            'header_font_family' => $headerFontFamily,
            'header_font_size' => $headerFontSize,
            'header_text_color' => $headerTextColor,
            'cell_align' => $cellAlign,
            'cell_font_family' => $cellFontFamily,
            'cell_font_size' => $cellFontSize,
            'cell_text_color' => $cellTextColor,
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
        int $fontSize = 0,
        string $textColor = '',
        ?bool $fontBold = null,
        ?bool $fontItalic = null,
        ?bool $fontUnderline = null
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
            $styles[] = 'font-size:' . $fontSize . 'pt !important';
            $attrs['data-font-size'] = (string)$fontSize;
        }
        if ($textColor !== '') {
            $styles[] = 'color:' . $textColor . ' !important';
            $styles[] = '-webkit-text-fill-color:' . $textColor . ' !important';
            $attrs['data-text-color'] = $textColor;
        }
        if ($fontBold !== null) {
            $styles[] = 'font-weight:' . ($fontBold ? '700' : '400') . ' !important';
            $attrs['data-font-bold'] = $fontBold ? '1' : '0';
        }
        if ($fontItalic !== null) {
            $styles[] = 'font-style:' . ($fontItalic ? 'italic' : 'normal') . ' !important';
            $attrs['data-font-italic'] = $fontItalic ? '1' : '0';
        }
        if ($fontUnderline !== null) {
            $styles[] = 'text-decoration:' . ($fontUnderline ? 'underline' : 'none') . ' !important';
            $attrs['data-font-underline'] = $fontUnderline ? '1' : '0';
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

    private function sanitizeTableCellValue(string $cell): string
    {
        $cell = ControlledPublishingDocxReader::sanitizeImportedText(trim($cell));
        if ($cell === '' || str_starts_with($cell, '=')) {
            return $cell;
        }
        if (str_contains($cell, '<')) {
            return ControlledPublishingHtmlSanitizer::sanitizeInline($cell);
        }
        return $cell;
    }

    /**
     * @param list<list<string>> $allRows
     */
    private function renderTableCellInner(string $cell, bool $edit, array $allRows): string
    {
        $raw = (string)$cell;
        if (!$edit && str_starts_with($raw, '=')) {
            return h(ControlledPublishingTableFormula::displayValue($raw, $allRows));
        }
        if (str_contains($raw, '<')) {
            return ControlledPublishingHtmlSanitizer::sanitizeInline($raw);
        }
        return h($raw);
    }

    private function normalizeTableCellFontOptional(string $font): string
    {
        $font = strtolower(trim($font));
        if ($font === '') {
            return '';
        }
        return $this->normalizeTableCellFont($font, '');
    }

    private function normalizeTableCellFontSizeOptional(mixed $size): int
    {
        $fontSize = (int)$size;
        if ($fontSize <= 0) {
            return 0;
        }
        return $this->normalizeTableCellFontSize($fontSize);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function normalizeTableOptionalFontRow(array $payload, string $key, int $colCount): array
    {
        $out = array();
        if (is_array($payload[$key] ?? null)) {
            foreach ($payload[$key] as $font) {
                $out[] = $this->normalizeTableCellFontOptional((string)$font);
            }
        }
        return array_pad(array_slice($out, 0, $colCount), $colCount, '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function normalizeTableOptionalFontSizeRow(array $payload, string $key, int $colCount): array
    {
        $out = array();
        if (is_array($payload[$key] ?? null)) {
            foreach ($payload[$key] as $size) {
                $out[] = $this->normalizeTableCellFontSizeOptional($size);
            }
        }
        return array_pad(array_slice($out, 0, $colCount), $colCount, 0);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function normalizeTableOptionalColorRow(array $payload, string $key, int $colCount): array
    {
        $out = array();
        if (is_array($payload[$key] ?? null)) {
            foreach ($payload[$key] as $color) {
                $out[] = $this->normalizeTableHexColor((string)$color, '');
            }
        }
        return array_pad(array_slice($out, 0, $colCount), $colCount, '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<list<string>>
     */
    private function normalizeTableOptionalFontGrid(array $payload, string $key, int $rowCount, int $colCount): array
    {
        $grid = array();
        if (is_array($payload[$key] ?? null)) {
            foreach ($payload[$key] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $font) {
                    $line[] = $this->normalizeTableCellFontOptional((string)$font);
                }
                $grid[] = array_pad(array_slice($line, 0, $colCount), $colCount, '');
            }
        }
        while (count($grid) < $rowCount) {
            $grid[] = array_fill(0, $colCount, '');
        }
        return array_slice($grid, 0, $rowCount);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<list<int>>
     */
    private function normalizeTableOptionalFontSizeGrid(array $payload, string $key, int $rowCount, int $colCount): array
    {
        $grid = array();
        if (is_array($payload[$key] ?? null)) {
            foreach ($payload[$key] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $size) {
                    $line[] = $this->normalizeTableCellFontSizeOptional($size);
                }
                $grid[] = array_pad(array_slice($line, 0, $colCount), $colCount, 0);
            }
        }
        while (count($grid) < $rowCount) {
            $grid[] = array_fill(0, $colCount, 0);
        }
        return array_slice($grid, 0, $rowCount);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<list<string>>
     */
    private function normalizeTableOptionalColorGrid(array $payload, string $key, int $rowCount, int $colCount): array
    {
        $grid = array();
        if (is_array($payload[$key] ?? null)) {
            foreach ($payload[$key] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = array();
                foreach ($row as $color) {
                    $line[] = $this->normalizeTableHexColor((string)$color, '');
                }
                $grid[] = array_pad(array_slice($line, 0, $colCount), $colCount, '');
            }
        }
        while (count($grid) < $rowCount) {
            $grid[] = array_fill(0, $colCount, '');
        }
        return array_slice($grid, 0, $rowCount);
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
        $rotationDeg = (int)($payload['rotation_deg'] ?? 0);
        if (!in_array($rotationDeg, array(0, 90, 180, 270), true)) {
            $rotationDeg = 0;
        }
        $editClass = $mode === self::MODE_EDIT ? ' cpb-image--editable' : '';
        $resize = $mode === self::MODE_EDIT
            ? '<button type="button" class="cpb-image-rotate" title="Rotate 90° clockwise">↻</button>'
            . '<span class="cpb-image-resize" title="Drag to resize"></span>'
            : '';
        $rotationAttr = $rotationDeg > 0 ? ' data-rotation-deg="' . $rotationDeg . '"' : '';
        $imgStyle = $rotationDeg > 0 ? ' style="transform:rotate(' . $rotationDeg . 'deg)"' : '';
        return '<figure class="cpb-image' . $editClass . '" data-field="image" style="width:' . $widthPct . '%" data-width-pct="' . $widthPct . '"' . $rotationAttr . '>'
            . '<div class="cpb-image-frame">'
            . '<img src="' . h($url) . '" alt="' . h($alt) . '" loading="lazy"' . $imgStyle
            . ($rotationDeg > 0 ? ' data-rotation-deg="' . $rotationDeg . '"' : '') . '>'
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
        if (!in_array($type, array('warning', 'caution', 'info', 'note'), true)) {
            $type = 'warning';
        }
        $title = (string)($payload['title'] ?? strtoupper($type));
        $text = (string)($payload['text'] ?? '');
        $titleFontFamily = (string)($payload['title_font_family'] ?? '');
        $titleFontSize = (int)($payload['title_font_size'] ?? 0);
        $titleTextColor = (string)($payload['title_text_color'] ?? '');
        $textFontFamily = (string)($payload['text_font_family'] ?? '');
        $textFontSize = (int)($payload['text_font_size'] ?? 0);
        $textTextColor = (string)($payload['text_text_color'] ?? '');
        $edit = $mode === self::MODE_EDIT;
        $icon = $type;

        $styleDef = array();
        if ($this->styleService !== null && $this->bookStyles !== array()) {
            $styleDef = $this->styleService->resolveCalloutStyle($this->bookStyles, $type);
        }
        if ($titleFontFamily === '' && $styleDef !== array()) {
            $titleFontFamily = (string)($styleDef['title_font_family'] ?? 'sans');
            $titleFontSize = (int)($styleDef['title_font_size'] ?? 11);
            $titleTextColor = (string)($styleDef['title_color'] ?? '#0f2744');
        }
        if ($textFontFamily === '' && $styleDef !== array()) {
            $textFontFamily = (string)($styleDef['text_font_family'] ?? 'sans');
            $textFontSize = (int)($styleDef['text_font_size'] ?? 10);
            $textTextColor = (string)($styleDef['text_color'] ?? '#1e293b');
        }

        $boxStyle = '';
        if ($styleDef !== array()) {
            $boxStyle = ' style="border-color:' . h((string)($styleDef['border_color'] ?? '#94a3b8'))
                . ';background:' . h((string)($styleDef['background'] ?? '#ffffff')) . '"';
        }
        $iconStyle = '';
        if ($styleDef !== array()) {
            $iconStyle = ' style="background:' . h((string)($styleDef['icon_color'] ?? '#0f2744')) . '"';
        }

        $titleEdit = $edit ? ' contenteditable="true" data-field="callout_title" spellcheck="true"' : '';
        $textEdit = $edit ? ' contenteditable="true" data-field="callout_text" spellcheck="true"' : '';
        $titleVisual = $this->tableCellVisualAttr('', '', $titleFontFamily, $titleFontSize, $titleTextColor);
        $textVisual = $this->tableCellVisualAttr('', '', $textFontFamily, $textFontSize, $textTextColor);
        $titleHtml = $title !== '' ? $this->renderTableCellInner($title, $edit, array()) : h(strtoupper($type));
        $textHtml = $text !== '' ? $this->renderTableCellInner($text, $edit, array()) : '';
        return '<div class="cpb-callout cpb-callout--' . h($type) . '" data-callout-type="' . h($type) . '"' . $boxStyle . '>'
            . '<div class="cpb-callout-icon cpb-callout-icon--' . h($icon) . '" aria-hidden="true"' . $iconStyle . '></div>'
            . '<div class="cpb-callout-body">'
            . '<div class="cpb-callout-title"' . $titleEdit . $titleVisual . '>' . $titleHtml . '</div>'
            . '<div class="cpb-callout-text"' . $textEdit . $textVisual . '>' . $textHtml . '</div>'
            . '</div></div>';
    }

    private function canonicalParagraphStyle(array $payload): string
    {
        $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        $style = ControlledPublishingBookStyleService::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
        return $style !== '' ? $style : 'body';
    }

    /**
     * Font size, family, and color matching the adjacent block — not indent/align.
     *
     * @param array<string,mixed> $payload
     */
    private function typographyStyleAttr(array $payload): string
    {
        $typography = $this->resolveTypography($payload);
        $fontStack = $this->fontFamilyStack((string)$typography['font_family']);
        $styles = array(
            'font-size:' . (int)$typography['font_size'] . 'pt',
            'color:' . (string)$typography['color'],
            'font-weight:' . (!empty($typography['font_bold']) ? '700' : '400'),
            'font-style:' . (!empty($typography['font_italic']) ? 'italic' : 'normal'),
            'text-decoration:' . (!empty($typography['font_underline']) ? 'underline' : 'none'),
        );
        if ($fontStack !== '') {
            $styles[] = 'font-family:' . $fontStack;
        }
        return ' style="' . implode(';', $styles) . '"';
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
            'font-weight:' . (!empty($typography['font_bold']) ? '700' : '400'),
            'font-style:' . (!empty($typography['font_italic']) ? 'italic' : 'normal'),
            'text-decoration:' . (!empty($typography['font_underline']) ? 'underline' : 'none'),
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
            . ' data-font-bold="' . (!empty($typography['font_bold']) ? '1' : '0') . '"'
            . ' data-font-italic="' . (!empty($typography['font_italic']) ? '1' : '0') . '"'
            . ' data-font-underline="' . (!empty($typography['font_underline']) ? '1' : '0') . '"'
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
     * @return array{font_family:string,font_size:int,color:string,text_align:string,indent_level:int,font_bold:bool,font_italic:bool,font_underline:bool}
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
            'font_bold' => !empty($payload['font_bold']),
            'font_italic' => !empty($payload['font_italic']),
            'font_underline' => !empty($payload['font_underline']),
        );
    }

    /**
     * @param array<string,mixed>|null $pageHeaderConfig
     * @return array<string,string>
     */
    private function buildHeaderTokenContext(
        array $version,
        array $section,
        string $mode,
        ?array $pageHeaderConfig = null
    ): array {
        $headerSvc = $this->pageHeaderService;
        if ($headerSvc === null) {
            return array();
        }
        $overrides = array('editor_preview' => $mode === self::MODE_EDIT);
        if (is_array($pageHeaderConfig['token_overrides'] ?? null)) {
            $overrides = array_merge($overrides, $pageHeaderConfig['token_overrides']);
        }
        return $headerSvc->buildTokenContext($version, $section, $overrides);
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
