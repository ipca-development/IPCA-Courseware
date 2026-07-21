<?php
declare(strict_types=1);

if (!function_exists('cw_mpdf_write_html_in_chunks')) {
    /**
     * mPDF can hit pcre.backtrack_limit when a large document is parsed in one
     * WriteHTML() call. Stream CSS once, then feed the body in bounded chunks.
     */
    function cw_mpdf_write_html_in_chunks($mpdf, string $html, int $maxChunkLength = 200000): void
    {
        $html = trim($html);
        if ($html === '') {
            return;
        }

        if (strlen($html) <= $maxChunkLength) {
            $mpdf->WriteHTML($html);
            return;
        }

        $css = cw_mpdf_extract_inline_css($html);
        if ($css !== '') {
            $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        }

        $body = cw_mpdf_extract_body_html($html);
        foreach (cw_mpdf_split_html_chunks($body, $maxChunkLength) as $chunk) {
            if (trim($chunk) !== '') {
                $mpdf->WriteHTML($chunk, \Mpdf\HTMLParserMode::HTML_BODY);
            }
        }
    }
}

if (!function_exists('cw_mpdf_extract_inline_css')) {
    function cw_mpdf_extract_inline_css(string $html): string
    {
        $head = cw_mpdf_extract_between_tags($html, 'head');
        $source = $head !== '' ? $head : $html;

        if (!preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $source, $matches)) {
            return '';
        }

        return trim(implode("\n", $matches[1]));
    }
}

if (!function_exists('cw_mpdf_extract_body_html')) {
    function cw_mpdf_extract_body_html(string $html): string
    {
        $body = cw_mpdf_extract_between_tags($html, 'body');
        if ($body !== '') {
            return trim($body);
        }

        $html = preg_replace('/<!doctype\b[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
        $html = preg_replace('/<\/?html\b[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/<\/?body\b[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        return trim($html);
    }
}

if (!function_exists('cw_mpdf_extract_between_tags')) {
    function cw_mpdf_extract_between_tags(string $html, string $tagName): string
    {
        $openStart = stripos($html, '<' . $tagName);
        if ($openStart === false) {
            return '';
        }

        $openEnd = strpos($html, '>', $openStart);
        if ($openEnd === false) {
            return '';
        }

        $closeStart = stripos($html, '</' . $tagName . '>', $openEnd);
        if ($closeStart === false) {
            return '';
        }

        return substr($html, $openEnd + 1, $closeStart - $openEnd - 1);
    }
}

if (!function_exists('cw_mpdf_split_html_chunks')) {
    /**
     * Split on tag boundaries when possible. If a single text node is huge,
     * fall back to fixed-size slices for that node only.
     *
     * @return list<string>
     */
    function cw_mpdf_split_html_chunks(string $html, int $maxChunkLength): array
    {
        if ($maxChunkLength <= 0 || strlen($html) <= $maxChunkLength) {
            return array($html);
        }

        $parts = preg_split('/(?<=>)(?=\s*<)/', $html);
        if (!is_array($parts) || count($parts) === 0) {
            return cw_mpdf_split_text_bytes($html, $maxChunkLength);
        }

        $chunks = array();
        $current = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (strlen($part) > $maxChunkLength) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach (cw_mpdf_split_text_bytes($part, $maxChunkLength) as $slice) {
                    if ($slice !== '') {
                        $chunks[] = $slice;
                    }
                }
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($part) > $maxChunkLength) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $part;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}

if (!function_exists('cw_mpdf_split_text_bytes')) {
    /**
     * @return list<string>
     */
    function cw_mpdf_split_text_bytes(string $text, int $maxChunkLength): array
    {
        if ($maxChunkLength <= 0 || strlen($text) <= $maxChunkLength) {
            return array($text);
        }

        if (!function_exists('mb_strcut')) {
            return str_split($text, $maxChunkLength);
        }

        $chunks = array();
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $chunk = mb_strcut($text, $offset, $maxChunkLength, 'UTF-8');
            if ($chunk === '') {
                break;
            }

            $chunks[] = $chunk;
            $offset += strlen($chunk);
        }

        return $chunks;
    }
}
