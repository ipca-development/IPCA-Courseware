<?php
declare(strict_types=1);

/**
 * Shared structured document payload normalization for manuals, forms, and future documents.
 */
final class StructuredDocumentPayload
{
    private const BLOCK_TYPES = array('heading', 'paragraph', 'list', 'table', 'image', 'callout', 'checkbox', 'field', 'date', 'signature', 'initial');
    private const FIELD_BLOCK_TYPES = array('checkbox', 'field', 'date', 'signature', 'initial');
    private const ROLES = array('admin', 'instructor', 'student', 'other_instructor', 'examiner', 'external_party');

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public static function normalizeDocument(array $document): array
    {
        $sections = array();
        if (is_array($document['sections'] ?? null)) {
            foreach ($document['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $sections[] = array(
                    'id' => max(1, (int)($section['id'] ?? 1)),
                    'section_key' => self::cleanKey((string)($section['section_key'] ?? 'section')),
                    'title' => trim((string)($section['title'] ?? 'Section')),
                    'sort_order' => (int)($section['sort_order'] ?? 10),
                    'parent_section_id' => !empty($section['parent_section_id']) ? (int)$section['parent_section_id'] : null,
                    'layout' => is_array($section['layout'] ?? null) ? $section['layout'] : array(),
                );
            }
        }

        $blocks = array();
        if (is_array($document['blocks'] ?? null)) {
            foreach ($document['blocks'] as $block) {
                if (is_array($block)) {
                    $blocks[] = self::normalizeBlock($block);
                }
            }
        }

        return array(
            'document_type' => self::cleanKey((string)($document['document_type'] ?? 'structured_document'), 'structured_document'),
            'schema_version' => max(1, (int)($document['schema_version'] ?? 1)),
            'title' => trim((string)($document['title'] ?? '')),
            'layout' => is_array($document['layout'] ?? null) ? $document['layout'] : array('page' => 'letter', 'orientation' => 'portrait'),
            'book_styles' => is_array($document['book_styles'] ?? null) ? $document['book_styles'] : array(),
            'page_header' => is_array($document['page_header'] ?? null) ? $document['page_header'] : array(),
            'page_footer' => is_array($document['page_footer'] ?? null) ? $document['page_footer'] : array(),
            'sections' => $sections,
            'blocks' => $blocks,
        );
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    public static function normalizeBlock(array $block): array
    {
        $type = self::normalizeBlockType((string)($block['block_type'] ?? $block['type'] ?? 'paragraph'));
        $key = self::cleanKey((string)($block['block_key'] ?? ''));
        if ($key === '') {
            $key = 'block_' . bin2hex(random_bytes(5));
        }

        return array(
            'id' => max(0, (int)($block['id'] ?? 0)),
            'section_id' => max(1, (int)($block['section_id'] ?? 1)),
            'block_key' => $key,
            'stable_anchor' => trim((string)($block['stable_anchor'] ?? '')),
            'block_type' => $type,
            'payload' => self::normalizePayload($type, is_array($block['payload'] ?? null) ? $block['payload'] : $block),
            'sort_order' => (int)($block['sort_order'] ?? 10),
            'is_system_managed' => !empty($block['is_system_managed']) ? 1 : 0,
        );
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    public static function collectFields(array $blocks): array
    {
        $fields = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string)($block['block_type'] ?? '');
            if (!in_array($type, self::FIELD_BLOCK_TYPES, true)) {
                continue;
            }
            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : array();
            $fieldKey = self::cleanKey((string)($payload['field_key'] ?? $block['block_key'] ?? ''));
            if ($fieldKey === '') {
                continue;
            }
            $fields[] = array(
                'field_key' => $fieldKey,
                'field_type' => self::fieldTypeForBlock($type, (string)($payload['field_type'] ?? '')),
                'label' => trim((string)($payload['label'] ?? '')),
                'required' => !empty($payload['required']),
                'assigned_role' => self::normalizeRole((string)($payload['assigned_role'] ?? 'instructor')),
                'variable_key' => trim((string)($payload['variable_key'] ?? '')),
                'validation' => is_array($payload['validation'] ?? null) ? $payload['validation'] : array(),
                'position' => array('block_key' => (string)($block['block_key'] ?? '')),
                'metadata' => array('block_type' => $type),
            );
        }
        return $fields;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizePayload(string $type, array $payload): array
    {
        return match ($type) {
            'heading' => self::normalizeTextPayload($payload, true),
            'list' => self::normalizeList($payload),
            'table' => self::normalizeTable($payload),
            'image' => self::normalizeImage($payload),
            'callout' => self::normalizeCallout($payload),
            'checkbox', 'field', 'date', 'signature', 'initial' => self::normalizeFieldPayload($type, $payload),
            default => self::normalizeTextPayload($payload, false),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeTextPayload(array $payload, bool $heading): array
    {
        $out = $heading
            ? array(
                'text' => trim((string)($payload['text'] ?? 'Heading')),
                'level' => max(1, min(6, (int)($payload['level'] ?? 2))),
            )
            : array('html' => (string)($payload['html'] ?? $payload['text'] ?? ''));

        foreach (array('paragraph_style', 'font_family', 'text_align', 'text_color', 'regulatory_ref', 'html') as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = (string)$payload[$key];
            }
        }
        foreach (array('font_size', 'indent_level', 'font_bold', 'font_italic', 'font_underline') as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = is_bool($payload[$key]) ? $payload[$key] : (int)$payload[$key];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeList(array $payload): array
    {
        $out = $payload;
        $items = array();
        foreach ((array)($payload['items'] ?? array()) as $item) {
            $items[] = (string)$item;
        }
        return array_merge($out, self::normalizeTextPayload($payload, false), array(
            'ordered' => !empty($payload['ordered']),
            'items' => $items !== array() ? $items : array('List item'),
        ));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeTable(array $payload): array
    {
        $out = $payload;
        $headers = array();
        foreach ((array)($payload['headers'] ?? array('Column 1', 'Column 2')) as $cell) {
            $headers[] = (string)$cell;
        }
        if ($headers === array()) {
            $headers = array('Column 1', 'Column 2');
        }

        $rows = array();
        foreach ((array)($payload['rows'] ?? array(array('', ''))) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = array();
            foreach ($row as $cell) {
                $line[] = (string)$cell;
            }
            $rows[] = $line;
        }
        if ($rows === array()) {
            $rows = array(array_fill(0, count($headers), ''));
        }

        return array_merge($out, array(
            'title' => trim((string)($payload['title'] ?? '')),
            'headers' => $headers,
            'rows' => $rows,
            'border_width' => trim((string)($payload['border_width'] ?? 'medium')),
            'border_color' => trim((string)($payload['border_color'] ?? '#94a3b8')),
            'table_align' => trim((string)($payload['table_align'] ?? 'left')),
            'col_widths' => is_array($payload['col_widths'] ?? null) ? $payload['col_widths'] : array(),
            'header_bg' => is_array($payload['header_bg'] ?? null) ? $payload['header_bg'] : array(),
            'cell_bg' => is_array($payload['cell_bg'] ?? null) ? $payload['cell_bg'] : array(),
            'header_align' => is_array($payload['header_align'] ?? null) ? $payload['header_align'] : array(),
            'cell_align' => is_array($payload['cell_align'] ?? null) ? $payload['cell_align'] : array(),
            'header_font_family' => is_array($payload['header_font_family'] ?? null) ? $payload['header_font_family'] : array(),
            'header_font_size' => is_array($payload['header_font_size'] ?? null) ? $payload['header_font_size'] : array(),
            'header_text_color' => is_array($payload['header_text_color'] ?? null) ? $payload['header_text_color'] : array(),
            'cell_font_family' => is_array($payload['cell_font_family'] ?? null) ? $payload['cell_font_family'] : array(),
            'cell_font_size' => is_array($payload['cell_font_size'] ?? null) ? $payload['cell_font_size'] : array(),
            'cell_text_color' => is_array($payload['cell_text_color'] ?? null) ? $payload['cell_text_color'] : array(),
            'header_colspans' => is_array($payload['header_colspans'] ?? null) ? $payload['header_colspans'] : array(),
            'row_colspans' => is_array($payload['row_colspans'] ?? null) ? $payload['row_colspans'] : array(),
            'has_title_row' => !empty($payload['has_title_row']),
            'title_bg' => trim((string)($payload['title_bg'] ?? '')),
            'title_align' => trim((string)($payload['title_align'] ?? 'center')),
            'title_font_family' => trim((string)($payload['title_font_family'] ?? '')),
            'title_font_size' => (int)($payload['title_font_size'] ?? 0),
            'title_text_color' => trim((string)($payload['title_text_color'] ?? '')),
            'table_style_kind' => trim((string)($payload['table_style_kind'] ?? 'standard')),
        ));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeImage(array $payload): array
    {
        return array(
            'url' => trim((string)($payload['url'] ?? '')),
            'alt' => trim((string)($payload['alt'] ?? '')),
            'width_pct' => max(20, min(100, (int)($payload['width_pct'] ?? 100))),
            'rotation_deg' => (int)($payload['rotation_deg'] ?? 0),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeFieldPayload(string $type, array $payload): array
    {
        $fieldKey = self::cleanKey((string)($payload['field_key'] ?? ''));
        return array(
            'field_key' => $fieldKey,
            'field_type' => self::fieldTypeForBlock($type, (string)($payload['field_type'] ?? '')),
            'label' => trim((string)($payload['label'] ?? self::labelFromKey($fieldKey !== '' ? $fieldKey : $type))),
            'placeholder' => trim((string)($payload['placeholder'] ?? '')),
            'required' => !empty($payload['required']),
            'assigned_role' => self::normalizeRole((string)($payload['assigned_role'] ?? 'instructor')),
            'variable_key' => trim((string)($payload['variable_key'] ?? '')),
            'validation' => is_array($payload['validation'] ?? null) ? $payload['validation'] : array(),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeCallout(array $payload): array
    {
        $out = $payload;
        $type = strtolower(trim((string)($payload['callout_type'] ?? 'warning')));
        if (!in_array($type, array('warning', 'caution', 'info', 'note'), true)) {
            $type = 'warning';
        }
        return array_merge($out, array(
            'callout_type' => $type,
            'title' => (string)($payload['title'] ?? strtoupper($type)),
            'text' => (string)($payload['text'] ?? ''),
            'title_font_family' => trim((string)($payload['title_font_family'] ?? '')),
            'title_font_size' => (int)($payload['title_font_size'] ?? 0),
            'title_text_color' => trim((string)($payload['title_text_color'] ?? '')),
            'text_font_family' => trim((string)($payload['text_font_family'] ?? '')),
            'text_font_size' => (int)($payload['text_font_size'] ?? 0),
            'text_text_color' => trim((string)($payload['text_text_color'] ?? '')),
        ));
    }

    private static function normalizeBlockType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::BLOCK_TYPES, true) ? $type : 'paragraph';
    }

    private static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, self::ROLES, true) ? $role : 'instructor';
    }

    private static function fieldTypeForBlock(string $blockType, string $fieldType): string
    {
        $fieldType = strtolower(trim($fieldType));
        if ($fieldType !== '') {
            return $fieldType;
        }
        return $blockType === 'field' ? 'text' : $blockType;
    }

    private static function cleanKey(string $key, string $fallback = ''): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
        $key = trim($key, '_');
        return $key !== '' ? $key : $fallback;
    }

    private static function labelFromKey(string $key): string
    {
        $label = str_replace('_', ' ', trim($key));
        return ucwords($label !== '' ? $label : 'Field');
    }

    private static function sanitizeInline(string $html): string
    {
        $html = strip_tags($html, '<b><strong><i><em><u><br><span>');
        return trim($html);
    }

    private static function sanitizeCell(string $cell): string
    {
        return trim(strip_tags($cell, '<b><strong><i><em><u><br>'));
    }
}
