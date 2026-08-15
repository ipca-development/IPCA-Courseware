<?php
declare(strict_types=1);

/**
 * Strips editor-only authoring chrome from HTML before it becomes
 * authoritative publication content (paginate source, page_html, preview, iOS).
 *
 * EDITOR AUTHORING UI ≠ PUBLISHED MANUAL CONTENT.
 *
 * Classification is semantic (classes / data attributes), not appearance.
 * Real images, captions, and governed manual blocks are preserved.
 */
final class ControlledPublishingPublicationFilter
{
    /** @var list<string> */
    public const CHROME_CLASSES = array(
        'cpb-dropzone',
        'cpb-block-chrome',
        'cpb-block-btn',
        'cpb-table-tools',
        'cpb-image-resize',
        'cpb-image-rotate',
        'cpb-page-layout-toggle',
        'cpb-orientation-toggle',
        'cpb-col-resize',
        'cpb-image--empty',
        'cpb-change-marker',
    );

    /** @var list<string> */
    private const PUBLICATION_ONLY_STRIP_CLASSES = array(
        'cpb-block--changed',
        'cpb-block--new',
        'cpb-block--modified',
    );

    /** @var list<string> */
    public const CHROME_ATTRIBUTES = array(
        'data-dropzone',
        'data-editor-only',
        'data-layout-toggle',
        'data-table-action',
        'data-table-tools-close',
        'data-image-action',
    );

    public static function filterHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        [$doc, $root] = self::loadFragment($html);
        if ($root === null) {
            return $html;
        }

        self::stripChrome($root);
        return trim(self::innerHTML($root));
    }

    /**
     * @return array{
     *   sheet_open:string,
     *   header:string,
     *   body:string,
     *   footer:string,
     *   uses_sheet_body:bool
     * }
     */
    public static function parseSheet(string $html): array
    {
        $filtered = self::filterHtml($html);
        if ($filtered === '') {
            return array(
                'sheet_open' => '',
                'header' => '',
                'body' => '',
                'footer' => '',
                'uses_sheet_body' => false,
            );
        }

        [$doc, $root] = self::loadFragment($filtered);
        if ($root === null) {
            return array(
                'sheet_open' => '',
                'header' => '',
                'body' => $filtered,
                'footer' => '',
                'uses_sheet_body' => false,
            );
        }

        $sheet = self::firstByClass($root, 'cpb-sheet');
        $header = self::firstTagClass($root, 'header', 'cpb-page-header');
        $footer = self::firstTagClass($root, 'footer', 'cpb-page-footer');
        $bodyNode = self::firstByClass($root, 'cpb-sheet-body');

        $sheetOpen = '';
        if (preg_match('/<div class="cpb-sheet[^"]*"[^>]*>/', $filtered, $m) === 1) {
            $sheetOpen = $m[0];
        } elseif ($sheet instanceof DOMElement) {
            $clone = $sheet->cloneNode(false);
            if ($clone instanceof DOMElement) {
                $saved = trim((string)$doc->saveHTML($clone));
                $sheetOpen = preg_replace('/\s*><\/div>$/', '>', $saved) ?? '';
                $sheetOpen = preg_replace('/\s*\/>$/', '>', $sheetOpen) ?? $sheetOpen;
            }
        }

        return array(
            'sheet_open' => $sheetOpen,
            'header' => $header instanceof DOMElement ? trim((string)$doc->saveHTML($header)) : '',
            'body' => $bodyNode instanceof DOMElement
                ? trim(self::innerHTML($bodyNode))
                : self::fallbackBody($filtered, $header, $footer, $doc),
            'footer' => $footer instanceof DOMElement ? trim((string)$doc->saveHTML($footer)) : '',
            'uses_sheet_body' => $bodyNode instanceof DOMElement,
        );
    }

    private static function fallbackBody(
        string $filtered,
        ?DOMNode $header,
        ?DOMNode $footer,
        DOMDocument $doc
    ): string {
        $body = $filtered;
        if ($header instanceof DOMElement) {
            $body = str_replace(trim((string)$doc->saveHTML($header)), '', $body);
        }
        if ($footer instanceof DOMElement) {
            $body = str_replace(trim((string)$doc->saveHTML($footer)), '', $body);
        }
        return self::unwrapPageShell(trim($body));
    }

    /**
     * Pagination units must be content fragments inside the body frame.
     * Do not keep page-shell width/min-height/header/footer geometry.
     */
    private static function unwrapPageShell(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        [$doc, $root] = self::loadFragment($html);
        if ($root === null) {
            return $html;
        }
        $sheet = self::firstByClass($root, 'cpb-sheet');
        if (!($sheet instanceof DOMElement)) {
            return $html;
        }
        foreach (array(
            self::firstTagClass($sheet, 'header', 'cpb-page-header'),
            self::firstTagClass($sheet, 'footer', 'cpb-page-footer'),
        ) as $chrome) {
            if ($chrome instanceof DOMElement && $chrome->parentNode !== null) {
                $chrome->parentNode->removeChild($chrome);
            }
        }
        $preferred = self::directChildByClass($sheet, 'cpb-sheet-body')
            ?: self::directChildByClass($sheet, 'cpb-lep')
            ?: self::directChildByClass($sheet, 'cpb-part0')
            ?: self::directChildByClass($sheet, 'cpb-toc');
        $children = array();
        foreach ($sheet->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        $extracted = $preferred ?: (count($children) === 1 ? $children[0] : null);
        if ($extracted instanceof DOMElement) {
            return trim((string)$doc->saveHTML($extracted));
        }
        return trim(self::innerHTML($sheet));
    }

    private static function directChildByClass(DOMElement $parent, string $className): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($className, self::classTokens($child), true)) {
                return $child;
            }
        }
        return null;
    }

    private static function stripChrome(DOMNode $root): void
    {
        $remove = array();
        $nodes = self::elements($root);
        foreach ($nodes as $element) {
            if (self::isEditorChrome($element)) {
                $remove[] = $element;
                continue;
            }
            if ($element->hasAttribute('contenteditable')) {
                $element->removeAttribute('contenteditable');
            }
            if ($element->hasAttribute('data-change-status')) {
                $element->removeAttribute('data-change-status');
            }
            $classes = array_values(array_diff(
                self::classTokens($element),
                self::PUBLICATION_ONLY_STRIP_CLASSES
            ));
            if ($classes === array()) {
                $element->removeAttribute('class');
            } else {
                $element->setAttribute('class', implode(' ', $classes));
            }
        }
        foreach ($remove as $element) {
            if ($element->parentNode !== null) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    private static function isEditorChrome(DOMElement $element): bool
    {
        foreach (self::classTokens($element) as $token) {
            if (in_array($token, self::CHROME_CLASSES, true)) {
                return true;
            }
        }
        foreach (self::CHROME_ATTRIBUTES as $attribute) {
            if ($element->hasAttribute($attribute)) {
                return true;
            }
        }
        $tag = strtolower($element->tagName);
        if ($tag === 'button' && $element->hasAttribute('data-action')) {
            return true;
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function classTokens(DOMElement $element): array
    {
        $class = trim((string)$element->getAttribute('class'));
        if ($class === '') {
            return array();
        }
        return preg_split('/\s+/', $class) ?: array();
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMNode $root): array
    {
        $output = array();
        $owner = $root instanceof DOMDocument ? $root : $root->ownerDocument;
        if ($owner === null) {
            return array();
        }
        $nodes = (new DOMXPath($owner))->query('.//*', $root);
        if ($nodes === false) {
            return array();
        }
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $output[] = $node;
            }
        }
        return $output;
    }

    private static function firstByClass(DOMNode $root, string $className): ?DOMElement
    {
        foreach (self::elements($root) as $element) {
            if (in_array($className, self::classTokens($element), true)) {
                return $element;
            }
        }
        if ($root instanceof DOMElement && in_array($className, self::classTokens($root), true)) {
            return $root;
        }
        return null;
    }

    private static function firstTagClass(DOMNode $root, string $tag, string $className): ?DOMElement
    {
        $tag = strtolower($tag);
        foreach (self::elements($root) as $element) {
            if (strtolower($element->tagName) === $tag
                && in_array($className, self::classTokens($element), true)
            ) {
                return $element;
            }
        }
        return null;
    }

    /**
     * @return array{0:DOMDocument,1:?DOMElement}
     */
    private static function loadFragment(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="ipca-pub-filter-root">' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div instanceof DOMElement && $div->getAttribute('id') === 'ipca-pub-filter-root') {
                $root = $div;
                break;
            }
        }
        if ($root === null) {
            $first = $doc->documentElement;
            $root = $first instanceof DOMElement ? $first : null;
        }
        return array($doc, $root);
    }

    private static function innerHTML(DOMNode $node): string
    {
        $doc = $node->ownerDocument;
        if ($doc === null) {
            return '';
        }
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= (string)$doc->saveHTML($child);
        }
        return $html;
    }
}
