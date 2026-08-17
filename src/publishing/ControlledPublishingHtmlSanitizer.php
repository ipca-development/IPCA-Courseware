<?php
declare(strict_types=1);

/**
 * Allow a small inline HTML subset for governed paragraph blocks.
 */
final class ControlledPublishingHtmlSanitizer
{
    /**
     * Strip to allowed inline tags; span may keep a governed color style.
     */
    public static function sanitizeInline(string $html): string
    {
        $html = self::stripEditorChrome($html);
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = array('b', 'strong', 'i', 'em', 'u', 'br', 'ul', 'ol', 'li', 'p', 'div', 'span', 'a');
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        self::sanitizeNode($root, $allowed);
        self::deduplicateAdjacentHyperlinks($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function deduplicateAdjacentHyperlinks(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        if (!$doc instanceof DOMDocument) {
            return;
        }
        $anchors = array();
        foreach ($doc->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement) {
                $anchors[] = $anchor;
            }
        }
        foreach ($anchors as $anchor) {
            if ($anchor->parentNode === null) {
                continue;
            }
            $href = trim((string)$anchor->getAttribute('href'));
            $label = trim((string)$anchor->textContent);
            if ($href === '' || $label === '') {
                continue;
            }
            $next = $anchor->nextSibling;
            if ($next instanceof DOMElement && strtolower($next->tagName) === 'a') {
                if (trim((string)$next->getAttribute('href')) === $href
                    && trim((string)$next->textContent) === $label) {
                    $next->parentNode?->removeChild($next);
                }
                continue;
            }
            if (!$next instanceof DOMText || preg_match('/^(?:https?:\/\/|www\.)/i', $label) !== 1) {
                continue;
            }
            $trailing = (string)$next->nodeValue;
            if (!str_starts_with($trailing, $label)) {
                continue;
            }
            $next->nodeValue = substr($trailing, strlen($label));
            if ($next->nodeValue === '') {
                $next->parentNode?->removeChild($next);
            }
        }
    }

    /**
     * @param list<string> $allowed
     */
    private static function sanitizeNode(DOMNode $node, array $allowed): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }
        $remove = array();
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowed, true)) {
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $remove[] = $child;
                    continue;
                }
                $keepAttrs = array();
                if ($tag === 'span') {
                    $style = (string)$child->getAttribute('style');
                    if (preg_match('/color\s*:\s*(#[0-9a-fA-F]{3,8})/', $style, $m) === 1) {
                        $keepAttrs['style'] = 'color:' . strtolower($m[1]);
                    }
                }
                if (in_array($tag, array('span', 'p', 'div'), true)
                    && $child->getAttribute('class') === 'cpb-line-indent') {
                    $level = (int)$child->getAttribute('data-indent-level');
                    $keepAttrs['class'] = 'cpb-line-indent';
                    $keepAttrs['data-indent-level'] = (string)max(0, min(8, $level));
                }
                if ($tag === 'ol') {
                    $start = (int)$child->getAttribute('start');
                    if ($start > 1) {
                        $keepAttrs['start'] = (string)min(9999, $start);
                    }
                }
                if ($tag === 'a') {
                    $href = trim((string)$child->getAttribute('href'));
                    if ($href !== '' && preg_match('/^(https?:\/\/|\/)/i', $href) === 1) {
                        $keepAttrs['href'] = $href;
                    }
                    $class = trim((string)$child->getAttribute('class'));
                    if ($class !== '') {
                        $keepAttrs['class'] = $class;
                    }
                    $target = trim((string)$child->getAttribute('target'));
                    if ($target === '_blank') {
                        $keepAttrs['target'] = '_blank';
                        $keepAttrs['rel'] = 'noopener noreferrer';
                    }
                    $sectionId = trim((string)$child->getAttribute('data-section-id'));
                    if ($sectionId !== '' && ctype_digit($sectionId)) {
                        $keepAttrs['data-section-id'] = $sectionId;
                    }
                }
                while ($child->attributes->length > 0) {
                    $child->removeAttribute($child->attributes->item(0)->name);
                }
                foreach ($keepAttrs as $k => $v) {
                    $child->setAttribute($k, $v);
                }
                self::sanitizeNode($child, $allowed);
            }
        }
        foreach ($remove as $n) {
            $node->removeChild($n);
        }
    }

    /**
     * Remove auto-generated section numbers and regulatory refs accidentally saved in content.
     */
    public static function stripEditorChrome(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $patterns = array(
            '/<span[^>]*\bcpb-section-number\b[^>]*>.*?<\/span>\s*/is',
            '/<span[^>]*\bcpb-regulatory-ref\b[^>]*>.*?<\/span>\s*/is',
        );
        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }
        return $html;
    }

    /**
     * Strip duplicated leading hierarchical numbers from plain heading text.
     */
    public static function stripLeadingSectionNumberText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            $text = preg_replace('/^\d+(?:\.\d+)*\.?\s+/u', '', $text) ?? $text;
            $text = trim($text);
        }
        return $text;
    }
}
