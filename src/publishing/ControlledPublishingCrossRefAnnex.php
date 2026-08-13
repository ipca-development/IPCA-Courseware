<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingDocxReader.php';

/**
 * Cross Reference Annex — default seed data and parsing from ANNEXES section blocks.
 *
 * @phpstan-type CrossRefEntry array{key:string,label:string}
 */
final class ControlledPublishingCrossRefAnnex
{
    /**
     * @return array<string,list<CrossRefEntry>>
     */
    public static function defaultCatalog(): array
    {
        return array(
            'PART NCO' => array(
                array('key' => 'NCO.GEN.103', 'label' => 'NCO.GEN.103 — General requirements'),
                array('key' => 'NCO.GEN.105', 'label' => 'NCO.GEN.105 — Crew requirements'),
                array('key' => 'NCO.GEN.110', 'label' => 'NCO.GEN.110 — Operator responsibilities'),
                array('key' => 'NCO.OP.100', 'label' => 'NCO.OP.100 — Operational procedures'),
                array('key' => 'NCO.OP.125', 'label' => 'NCO.OP.125 — Pre-flight duties'),
            ),
            'PART FCL' => array(
                array('key' => 'FCL.1010', 'label' => 'FCL.1010 — Training course requirements'),
                array('key' => 'FCL.1050', 'label' => 'FCL.1050 — Instructor privileges'),
            ),
            'PART CAMO' => array(
                array('key' => 'CAMO.A.300', 'label' => 'CAMO.A.300 — Continuing airworthiness'),
            ),
        );
    }

    /**
     * @return array<string,list<CrossRefEntry>>
     */
    public static function catalog(): array
    {
        return self::defaultCatalog();
    }

    /**
     * Build block payloads to seed an empty Cross Reference Annex section.
     *
     * @return list<array{block_type:string,payload:array<string,mixed>}>
     */
    public static function seedBlockSpecs(): array
    {
        $specs = array();
        foreach (self::defaultCatalog() as $document => $entries) {
            $specs[] = array(
                'block_type' => 'paragraph',
                'payload' => array(
                    'html' => '<p>' . htmlspecialchars($document, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                    'paragraph_style' => 'subtitle_1',
                ),
            );
            $rows = array();
            foreach ($entries as $entry) {
                $title = $entry['label'];
                if (str_contains($title, ' — ')) {
                    $parts = explode(' — ', $title, 2);
                    $title = trim($parts[1]);
                } else {
                    $title = $entry['key'];
                }
                $rows[] = array($entry['key'], $title);
            }
            $specs[] = array(
                'block_type' => 'table',
                'payload' => array(
                    'headers' => array('Reference', 'Title'),
                    'rows' => $rows,
                    'col_widths' => array(160, 320),
                    'border_width' => 'thin',
                    'table_style_kind' => 'standard',
                ),
            );
        }

        return $specs;
    }

    /**
     * Parse cross-reference tables grouped by preceding document heading blocks.
     *
     * @param list<array<string,mixed>> $blocks
     * @param callable(array): array<string,mixed> $decodePayload
     * @return array<string,list<CrossRefEntry>>
     */
    public static function parseCatalogFromBlocks(array $blocks, callable $decodePayload): array
    {
        $catalog = array();
        $currentDocument = '';

        usort($blocks, static function (array $a, array $b): int {
            return (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);
        });

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string)($block['block_type'] ?? '');
            $payload = $decodePayload($block);

            if ($type === 'paragraph' || $type === 'heading') {
                $text = self::plainTextFromBlockPayload($payload, $type);
                if ($text !== '' && self::looksLikeDocumentHeading($text, $payload)) {
                    $currentDocument = $text;
                    if (!isset($catalog[$currentDocument])) {
                        $catalog[$currentDocument] = array();
                    }
                }
                continue;
            }

            if ($type !== 'table' || $currentDocument === '') {
                continue;
            }

            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = self::plainCellText((string)($row[0] ?? ''));
                if ($key === '') {
                    continue;
                }
                $title = self::plainCellText((string)($row[1] ?? ''));
                $label = $title !== '' ? $key . ' — ' . $title : $key;
                $catalog[$currentDocument][] = array(
                    'key' => $key,
                    'label' => $label,
                );
            }
        }

        return $catalog;
    }

    public static function formatDisplay(string $document, string $key): string
    {
        $document = trim($document);
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (preg_match('/^(NCO|FCL|CAMO|SPO|CAT|ORO)\./i', $key) === 1) {
            return 'Part ' . $key;
        }
        if ($document !== '') {
            return $document . ' ' . $key;
        }

        return $key;
    }

    /**
     * @return list<string>
     */
    public static function documentKeys(): array
    {
        return array_keys(self::defaultCatalog());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function plainTextFromBlockPayload(array $payload, string $blockType): string
    {
        if ($blockType === 'heading') {
            return self::plainCellText((string)($payload['text'] ?? ''));
        }
        $html = (string)($payload['html'] ?? '');

        return ControlledPublishingDocxReader::plainTextFromHtml($html);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function looksLikeDocumentHeading(string $text, array $payload): bool
    {
        $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        if (in_array($style, array('title', 'subtitle_1', 'subtitle_2', 'subtitle_3', 'subtitle_4'), true)) {
            return true;
        }

        return preg_match('/^PART\s+[A-Z0-9][A-Z0-9\s\-]*$/i', $text) === 1;
    }

    private static function plainCellText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text);
    }
}
