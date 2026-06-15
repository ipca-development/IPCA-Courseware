<?php
declare(strict_types=1);

/**
 * Render EASA regulation node content using the same structured-block layout as Resource Library.
 */
final class ControlledPublishingMccfEasaPreviewRenderer
{
    /**
     * @param array<string,mixed> $node
     */
    public static function renderNodeDetail(array $node, string $highlightToken = ''): string
    {
        $nodeTitle = trim((string)($node['title'] ?? ''));
        $blocks = $node['structured_blocks'] ?? null;
        if (is_array($blocks) && $blocks !== array()) {
            $html = self::structuredBlocksHtml($blocks, $highlightToken, $nodeTitle);
        } else {
            $body = trim((string)($node['plain_text_effective'] ?? $node['plain_text'] ?? ''));
            if ($body === '') {
                $body = trim((string)($node['canonical_text'] ?? ''));
            }
            if ($body === '') {
                $body = trim((string)($node['breadcrumb'] ?? ''));
            }
            $html = self::plainTextHtml($body);
            if ($highlightToken !== '') {
                $html = self::highlightToken($html, $highlightToken);
            }
        }

        $meta = array();
        if (trim((string)($node['source_erules_id'] ?? '')) !== '') {
            $meta[] = (string)$node['source_erules_id'];
        }
        if (trim((string)($node['breadcrumb'] ?? '')) !== '') {
            $meta[] = (string)$node['breadcrumb'];
        }

        $metaHtml = $meta !== array()
            ? '<div class="rl-easa-detail-meta">' . h(implode("\n", $meta)) . '</div>'
            : '';

        return '<div class="mccf-easa-preview">'
            . $metaHtml
            . '<div class="rl-easa-detail-body rl-easa-detail-body-structured">' . $html . '</div>'
            . '</div>';
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    public static function structuredBlocksHtml(array $blocks, string $highlightToken = '', string $nodeTitle = ''): string
    {
        $markerPath = self::highlightMarkerPath($highlightToken, $nodeTitle);
        $stack = array();
        $bits = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string)($block['type'] ?? '');
            $highlightLine = false;
            if ($type === 'heading') {
                $level = (int)($block['level'] ?? 3);
                if ($level < 1 || $level > 6) {
                    $level = 3;
                }
                $bits[] = '<h' . $level . ' class="rl-easa-bl-h">' . h((string)($block['text'] ?? '')) . '</h' . $level . '>';
            } elseif ($type === 'paragraph') {
                $bits[] = '<p class="rl-easa-bl-p">' . h((string)($block['text'] ?? '')) . '</p>';
            } elseif ($type === 'list_item') {
                $marker = trim((string)($block['marker'] ?? ''));
                $stack = self::advanceMarkerStack($stack, $marker);
                $highlightLine = self::listItemMatchesMarkerPath($stack, $markerPath);
                $lineClass = 'rl-easa-bl-li' . ($highlightLine ? ' mccf-hl-line' : '');
                $lineAttr = $highlightLine ? ' data-mccf-highlight="1"' : '';
                $bits[] = '<div class="' . $lineClass . '"' . $lineAttr . '>'
                    . '<span class="rl-easa-bl-marker">' . h($marker) . '</span>'
                    . '<span class="rl-easa-bl-litext">' . h((string)($block['text'] ?? '')) . '</span>'
                    . '</div>';
            } elseif ($type === 'table') {
                $bits[] = '<table class="rl-easa-bl-tbl">';
                foreach ($block['rows'] ?? array() as $row) {
                    $bits[] = '<tr>';
                    foreach (is_array($row) ? $row : array() as $cell) {
                        $cellText = str_replace("\n", '<br>', h((string)$cell));
                        $bits[] = '<td>' . $cellText . '</td>';
                    }
                    $bits[] = '</tr>';
                }
                $bits[] = '</table>';
            }
        }

        return '<article class="rl-easa-bl-article" aria-label="Rule text">' . implode('', $bits) . '</article>';
    }

    public static function plainTextHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = preg_split('/\R/u', $escaped) ?: array($escaped);
        $out = array();
        foreach ($lines as $line) {
            $out[] = '<p class="rl-easa-bl-p">' . $line . '</p>';
        }

        return '<article class="rl-easa-bl-article" aria-label="Rule text">' . implode('', $out) . '</article>';
    }

    public static function highlightToken(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        return preg_replace(
            '/(' . preg_quote($token, '/') . ')/iu',
            '<mark class="mccf-hl">$1</mark>',
            $html
        ) ?? $html;
    }

    /**
     * @return list<string> Uppercase marker ids without parentheses, e.g. ['B','A','6'].
     */
    private static function parentheticalGroups(string $text): array
    {
        if ($text === '') {
            return array();
        }
        if (preg_match_all('/\(([A-Za-z0-9]+)\)/', strtoupper($text), $matches)) {
            return $matches[1];
        }

        return array();
    }

    /**
     * @return list<string>
     */
    private static function highlightMarkerPath(string $highlightToken, string $nodeTitle): array
    {
        $path = self::parentheticalGroups($highlightToken);
        if ($path === array()) {
            return array();
        }

        $titlePath = self::parentheticalGroups($nodeTitle);
        while ($path !== array() && $titlePath !== array() && $path[0] === $titlePath[0]) {
            array_shift($path);
            array_shift($titlePath);
        }

        return $path;
    }

    /**
     * @param list<string> $stack
     * @return list<string>
     */
    private static function advanceMarkerStack(array $stack, string $marker): array
    {
        $norm = self::normalizeMarker($marker);
        if ($norm === '') {
            return $stack;
        }

        if (self::isLetterMarker($norm)) {
            return array($norm);
        }
        if (self::isNumberMarker($norm) && $stack !== array() && self::isLetterMarker($stack[0])) {
            return array($stack[0], $norm);
        }

        return array($norm);
    }

    /**
     * @param list<string> $stack
     * @param list<string> $markerPath
     */
    private static function listItemMatchesMarkerPath(array $stack, array $markerPath): bool
    {
        if ($markerPath === array() || $stack === array()) {
            return false;
        }
        if (count($stack) < count($markerPath)) {
            return false;
        }

        $suffix = array_slice($stack, -count($markerPath));

        return $suffix === $markerPath;
    }

    private static function normalizeMarker(string $marker): string
    {
        $marker = strtoupper(trim($marker));
        if ($marker === '') {
            return '';
        }
        if (preg_match('/^\(([^)]+)\)$/', $marker, $m)) {
            return strtoupper(trim($m[1]));
        }
        if (preg_match('/^(\d+)\.$/', $marker, $m)) {
            return $m[1];
        }

        return $marker;
    }

    private static function isLetterMarker(string $norm): bool
    {
        return preg_match('/^[A-Z]$/', $norm) === 1;
    }

    private static function isNumberMarker(string $norm): bool
    {
        return preg_match('/^\d+$/', $norm) === 1;
    }
}
