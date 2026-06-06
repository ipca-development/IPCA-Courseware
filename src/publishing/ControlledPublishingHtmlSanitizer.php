<?php
declare(strict_types=1);

/**
 * Allow a small inline HTML subset for governed paragraph blocks.
 */
final class ControlledPublishingHtmlSanitizer
{
    /**
     * Strip to b, strong, i, em, u, br, ul, ol, li — no attributes except on nothing.
     */
    public static function sanitizeInline(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = array('b', 'strong', 'i', 'em', 'u', 'br', 'ul', 'ol', 'li', 'p');
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
                while ($child->attributes->length > 0) {
                    $child->removeAttribute($child->attributes->item(0)->name);
                }
                self::sanitizeNode($child, $allowed);
            }
        }
        foreach ($remove as $n) {
            $node->removeChild($n);
        }
    }
}
