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

        $allowed = array('b', 'strong', 'i', 'em', 'u', 'br', 'ul', 'ol', 'li', 'p', 'span', 'a');
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
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
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
