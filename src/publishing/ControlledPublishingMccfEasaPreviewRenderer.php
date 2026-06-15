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
        $blocks = $node['structured_blocks'] ?? null;
        if (is_array($blocks) && $blocks !== array()) {
            $html = self::structuredBlocksHtml($blocks);
        } else {
            $body = trim((string)($node['plain_text_effective'] ?? $node['plain_text'] ?? ''));
            if ($body === '') {
                $body = trim((string)($node['canonical_text'] ?? ''));
            }
            if ($body === '') {
                $body = trim((string)($node['breadcrumb'] ?? ''));
            }
            $html = self::plainTextHtml($body);
        }

        if ($highlightToken !== '') {
            $html = self::highlightToken($html, $highlightToken);
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
    public static function structuredBlocksHtml(array $blocks): string
    {
        $bits = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string)($block['type'] ?? '');
            if ($type === 'heading') {
                $level = (int)($block['level'] ?? 3);
                if ($level < 1 || $level > 6) {
                    $level = 3;
                }
                $bits[] = '<h' . $level . ' class="rl-easa-bl-h">' . h((string)($block['text'] ?? '')) . '</h' . $level . '>';
            } elseif ($type === 'paragraph') {
                $bits[] = '<p class="rl-easa-bl-p">' . h((string)($block['text'] ?? '')) . '</p>';
            } elseif ($type === 'list_item') {
                $bits[] = '<div class="rl-easa-bl-li">'
                    . '<span class="rl-easa-bl-marker">' . h((string)($block['marker'] ?? '')) . '</span>'
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
}
