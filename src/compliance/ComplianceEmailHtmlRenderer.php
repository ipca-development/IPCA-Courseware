<?php
declare(strict_types=1);

/**
 * Message HTML renderer for the Compliance Mail workspace.
 *
 * The iframe is a security boundary, not a visible design element. Output is
 * styled to blend into the conversation reader and avoid nested scroll boxes.
 */
final class ComplianceEmailHtmlRenderer
{
    /**
     * @param list<array<string,mixed>> $attachments
     */
    public static function iframeForMessage(array $email, array $attachments): string
    {
        $html = trim((string)($email['html_body'] ?? ''));
        $text = trim((string)($email['text_body'] ?? ''));
        if ($html === '' && $text === '') {
            return '<div class="mail-message-empty">(empty message)</div>';
        }
        if ($html === '') {
            return '<div class="mail-text-fallback">' . nl2br(self::e($text)) . '</div>';
        }

        $srcdoc = self::srcdoc($html, $attachments);

        return '<iframe class="mail-html-frame" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" '
            . 'referrerpolicy="no-referrer" loading="lazy" srcdoc="' . self::e($srcdoc) . '"></iframe>';
    }

    /**
     * @param list<array<string,mixed>> $attachments
     */
    public static function srcdoc(string $html, array $attachments): string
    {
        $html = self::sanitize($html, $attachments);
        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<base target="_blank">'
            . '<style>'
            . 'html,body{margin:0;padding:0;background:transparent;max-width:100%;width:100%;overflow-x:hidden;}'
            . 'body{word-wrap:break-word;overflow-wrap:anywhere;padding-right:24px;box-sizing:border-box;}'
            . 'img{max-width:100%!important;height:auto!important;}'
            . 'table{max-width:100%!important;width:auto!important;}'
            . 'td,th{overflow-wrap:anywhere;}'
            . 'div,p,section,article,main,header,footer{max-width:100%;}'
            . '[width]{max-width:100%!important;}'
            . '*{box-sizing:border-box;}'
            . 'a{color:#1e3c72;}'
            . 'blockquote{margin:12px 0;padding-left:14px;border-left:3px solid #d7deea;color:#526174;}'
            . '</style></head><body>' . $html
            . '<script>try{var h=function(){parent.postMessage({type:"mailFrameHeight",height:document.documentElement.scrollHeight,id:frameElement&&frameElement.dataset?frameElement.dataset.frameId:""},"*")};new ResizeObserver(h).observe(document.body);h();}catch(e){}</script>'
            . '</body></html>';
    }

    /**
     * @param list<array<string,mixed>> $attachments
     */
    public static function sanitize(string $html, array $attachments = array()): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $isFullDocument = stripos($html, '<html') !== false || stripos($html, '<body') !== false;
        $source = $isFullDocument
            ? '<?xml encoding="utf-8" ?>' . $html
            : '<?xml encoding="utf-8" ?><div id="mail-root">' . $html . '</div>';
        $loaded = $doc->loadHTML($source, $isFullDocument ? 0 : (LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD));
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return nl2br(self::e(strip_tags($html)));
        }

        $cidMap = self::cidUrlMap($attachments);
        $root = $isFullDocument ? self::documentBody($doc) : $doc->getElementById('mail-root');
        if ($root === null) {
            return '';
        }
        $headStyles = $isFullDocument ? self::sanitizedHeadStyles($doc) : '';
        self::sanitizeNode($root, $cidMap);

        $out = $headStyles;
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function documentBody(DOMDocument $doc): ?DOMElement
    {
        $bodies = $doc->getElementsByTagName('body');
        if ($bodies->length > 0) {
            $body = $bodies->item(0);
            return $body instanceof DOMElement ? $body : null;
        }
        $html = $doc->documentElement;
        return $html instanceof DOMElement ? $html : null;
    }

    private static function sanitizedHeadStyles(DOMDocument $doc): string
    {
        $out = '';
        foreach ($doc->getElementsByTagName('style') as $style) {
            $css = (string)$style->textContent;
            $css = (string)preg_replace('#@import[^;]+;#i', '', $css);
            $css = (string)preg_replace('#expression\s*\([^)]*\)#i', '', $css);
            $css = (string)preg_replace('#url\s*\(\s*[\'"]?\s*(?!https?:|data:image/|/|#)[^)]+\)#i', '', $css);
            if (trim($css) !== '') {
                $out .= '<style>' . $css . '</style>';
            }
        }
        return $out;
    }

    /**
     * @param array<string,string> $cidMap
     */
    private static function sanitizeNode(DOMNode $node, array $cidMap): void
    {
        $blocked = array('script', 'form', 'input', 'button', 'select', 'textarea', 'object', 'embed', 'iframe', 'meta', 'link');
        $remove = array();

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, $blocked, true)) {
                    $remove[] = $child;
                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $value = trim((string)$attr->value);
                    if (str_starts_with($name, 'on')) {
                        $child->removeAttribute($attr->name);
                        continue;
                    }
                    if (in_array($name, array('href', 'src', 'background'), true)) {
                        $safe = self::safeUrl($value, $cidMap);
                        if ($safe === null) {
                            $child->removeAttribute($attr->name);
                        } else {
                            $child->setAttribute($attr->name, $safe);
                        }
                    }
                    if ($name === 'style') {
                        $child->setAttribute('style', self::sanitizeStyle($value));
                    }
                }

                if ($tag === 'a') {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                self::sanitizeNode($child, $cidMap);
            }
        }

        foreach ($remove as $child) {
            $node->removeChild($child);
        }
    }

    /**
     * @param array<string,string> $cidMap
     */
    private static function safeUrl(string $url, array $cidMap): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (stripos($url, 'cid:') === 0) {
            $cid = trim(substr($url, 4), '<>');
            return $cidMap[$cid] ?? null;
        }
        if (preg_match('#^(https?:)?//#i', $url) === 1 || preg_match('#^(https?|mailto|tel):#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        if (preg_match('#^data:image/(png|gif|jpe?g|webp);base64,#i', $url) === 1) {
            return $url;
        }
        return null;
    }

    private static function sanitizeStyle(string $style): string
    {
        $style = (string)preg_replace('#expression\s*\([^)]*\)#i', '', $style);
        $style = (string)preg_replace('#url\s*\(\s*[\'"]?\s*(?!https?:|data:image/|/|#)[^)]+\)#i', '', $style);
        $style = (string)preg_replace('#behavior\s*:[^;]+;?#i', '', $style);
        return trim($style);
    }

    /**
     * @param list<array<string,mixed>> $attachments
     * @return array<string,string>
     */
    private static function cidUrlMap(array $attachments): array
    {
        $map = array();
        foreach ($attachments as $a) {
            $cid = trim((string)($a['content_id'] ?? ''), '<>');
            if ($cid === '') {
                continue;
            }
            $url = trim((string)($a['public_url'] ?? ''));
            if ($url === '') {
                $url = '/admin/compliance/email_attachment.php?id=' . (int)($a['id'] ?? 0) . '&disposition=inline';
            }
            if ($url !== '') {
                $map[$cid] = $url;
            }
        }
        return $map;
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
