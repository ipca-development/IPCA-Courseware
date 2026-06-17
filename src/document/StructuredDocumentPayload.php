<?php
declare(strict_types=1);

/**
 * Shared structured document payload normalization for manuals, forms, and future documents.
 */
final class StructuredDocumentPayload
{
    private const BLOCK_TYPES = array('heading', 'paragraph', 'table', 'checkbox', 'field', 'date', 'signature', 'initial');
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
            'heading' => array(
                'text' => trim((string)($payload['text'] ?? 'Heading')),
                'level' => max(1, min(6, (int)($payload['level'] ?? 2))),
            ),
            'table' => self::normalizeTable($payload),
            'checkbox', 'field', 'date', 'signature', 'initial' => self::normalizeFieldPayload($type, $payload),
            default => array('html' => self::sanitizeInline((string)($payload['html'] ?? $payload['text'] ?? ''))),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeTable(array $payload): array
    {
        $headers = array();
        foreach ((array)($payload['headers'] ?? array('Column 1', 'Column 2')) as $cell) {
            $headers[] = self::sanitizeCell((string)$cell);
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
                $line[] = self::sanitizeCell((string)$cell);
            }
            $rows[] = array_pad(array_slice($line, 0, count($headers)), count($headers), '');
        }
        if ($rows === array()) {
            $rows = array(array_fill(0, count($headers), ''));
        }

        return array(
            'title' => trim((string)($payload['title'] ?? '')),
            'headers' => $headers,
            'rows' => $rows,
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
