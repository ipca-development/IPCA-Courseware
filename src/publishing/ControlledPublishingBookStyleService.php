<?php
declare(strict_types=1);

/**
 * Book-level paragraph and table style definitions stored in version metadata_json.
 */
final class ControlledPublishingBookStyleService
{
    /** @var list<string> */
    public const PARAGRAPH_STYLE_KEYS = array(
        'title',
        'subtitle_1',
        'heading_1',
        'heading_2',
        'subtitle_3',
        'subtitle_4',
        'body',
        'caption',
    );

    /** @var list<string> */
    public const TOC_PARAGRAPH_STYLE_KEYS = array(
        'title',
        'subtitle_1',
        'heading_1',
        'heading_2',
        'subtitle_3',
        'subtitle_4',
    );

    /** @var list<string> */
    public const FONT_KEYS = array('serif', 'sans', 'arial', 'mono');

    /** @var list<string> */
    public const CALLOUT_TYPES = array('warning', 'caution', 'info');

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultBookStyles(): array
    {
        return array(
            'paragraph_styles' => array(
                'title' => array('font_family' => 'sans', 'font_size' => 24, 'color' => '#0f2744'),
                'subtitle_1' => array('font_family' => 'sans', 'font_size' => 18, 'color' => '#0f2744'),
                'heading_1' => array('font_family' => 'sans', 'font_size' => 16, 'color' => '#0f2744'),
                'heading_2' => array('font_family' => 'sans', 'font_size' => 14, 'color' => '#0f2744'),
                'subtitle_3' => array('font_family' => 'sans', 'font_size' => 12, 'color' => '#334155'),
                'subtitle_4' => array('font_family' => 'sans', 'font_size' => 11, 'color' => '#475569'),
                'body' => array('font_family' => 'serif', 'font_size' => 11, 'color' => '#0f172a'),
                'caption' => array('font_family' => 'sans', 'font_size' => 9, 'color' => '#64748b'),
            ),
            'table_styles' => array(
                'standard' => $this->defaultTableStyle(),
                'text' => array_merge($this->defaultTableStyle(), array(
                    'border_width' => 'thin',
                    'title_row' => array('font_family' => 'sans', 'font_size' => 10, 'color' => '#0f2744', 'bg' => '#eef2f7'),
                    'body_row' => array('font_family' => 'serif', 'font_size' => 10, 'color' => '#0f172a', 'bg' => ''),
                )),
            ),
            'callout_presets' => $this->defaultCalloutPresets(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultTableStyle(): array
    {
        return array(
            'border_width' => 'medium',
            'border_color' => '#94a3b8',
            'cell_bg' => '#ffffff',
            'title_row' => array('font_family' => 'sans', 'font_size' => 11, 'color' => '#0f2744', 'bg' => '#e8eef6'),
            'header_row' => array('font_family' => 'sans', 'font_size' => 10, 'color' => '#0f172a', 'bg' => '#f1f5f9'),
            'body_row' => array('font_family' => 'serif', 'font_size' => 10, 'color' => '#0f172a', 'bg' => ''),
        );
    }

    /**
     * @return list<array<string,string>>
     */
    public function defaultCalloutPresets(): array
    {
        return array(
            array('callout_type' => 'warning', 'title' => 'WARNING', 'text' => ''),
            array('callout_type' => 'caution', 'title' => 'CAUTION', 'text' => ''),
            array('callout_type' => 'info', 'title' => 'INFO', 'text' => ''),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function resolveFromMetadata(array $metadata): array
    {
        $defaults = $this->defaultBookStyles();
        $paragraph = is_array($metadata['paragraph_styles'] ?? null) ? $metadata['paragraph_styles'] : array();
        $tables = is_array($metadata['table_styles'] ?? null) ? $metadata['table_styles'] : array();
        $callouts = is_array($metadata['callout_presets'] ?? null) ? $metadata['callout_presets'] : array();

        $resolvedParagraph = array();
        foreach (self::PARAGRAPH_STYLE_KEYS as $key) {
            $resolvedParagraph[$key] = $this->normalizeParagraphStyle(
                is_array($paragraph[$key] ?? null) ? $paragraph[$key] : array(),
                is_array($defaults['paragraph_styles'][$key] ?? null) ? $defaults['paragraph_styles'][$key] : array()
            );
        }

        return array(
            'paragraph_styles' => $resolvedParagraph,
            'table_styles' => array(
                'standard' => $this->normalizeTableStyle(
                    is_array($tables['standard'] ?? null) ? $tables['standard'] : array(),
                    $defaults['table_styles']['standard']
                ),
                'text' => $this->normalizeTableStyle(
                    is_array($tables['text'] ?? null) ? $tables['text'] : array(),
                    $defaults['table_styles']['text']
                ),
            ),
            'callout_presets' => $this->normalizeCalloutPresets($callouts),
        );
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveFromVersion(array $version): array
    {
        $raw = $version['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $this->resolveFromMetadata($raw);
        }
        $decoded = json_decode((string)$raw, true);
        return $this->resolveFromMetadata(is_array($decoded) ? $decoded : array());
    }

    /**
     * @param array<string,mixed> $styles
     */
    public function saveForVersion(int $versionId, array $styles, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $normalized = $this->resolveFromMetadata(array_merge($meta, $styles));
        $meta['paragraph_styles'] = $normalized['paragraph_styles'];
        $meta['table_styles'] = $normalized['table_styles'];
        $meta['callout_presets'] = $normalized['callout_presets'];

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));

        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $bookStyles
     * @return array{font_family:string,font_size:int,color:string,text_align:string,indent_level:int}
     */
    public function resolveBlockTypography(array $payload, array $bookStyles): array
    {
        $paragraphStyle = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
        $paragraphDefs = is_array($bookStyles['paragraph_styles'] ?? null)
            ? $bookStyles['paragraph_styles']
            : array();
        $base = array(
            'font_family' => 'serif',
            'font_size' => 11,
            'color' => '#0f172a',
            'text_align' => 'left',
            'indent_level' => 0,
        );
        if ($paragraphStyle !== '' && isset($paragraphDefs[$paragraphStyle])) {
            $def = $paragraphDefs[$paragraphStyle];
            $base['font_family'] = (string)($def['font_family'] ?? $base['font_family']);
            $base['font_size'] = (int)($def['font_size'] ?? $base['font_size']);
            $base['color'] = (string)($def['color'] ?? $base['color']);
        }
        $base['font_family'] = $this->normalizeFont((string)($payload['font_family'] ?? $base['font_family']));
        $base['font_size'] = $this->normalizeFontSize($payload['font_size'] ?? $base['font_size']);
        $base['color'] = $this->normalizeColor((string)($payload['text_color'] ?? $payload['color'] ?? $base['color']), $base['color']);
        $align = strtolower(trim((string)($payload['text_align'] ?? 'left')));
        $base['text_align'] = in_array($align, array('left', 'center', 'right'), true) ? $align : 'left';
        $base['indent_level'] = max(0, min(8, (int)($payload['indent_level'] ?? 0)));
        return $base;
    }

    public function paragraphStyleLabel(string $key): string
    {
        return match ($key) {
            'title' => 'Title',
            'subtitle_1' => 'Subtitle 1',
            'heading_1' => 'Heading 1',
            'heading_2' => 'Heading 2',
            'subtitle_3' => 'Subtitle 3',
            'subtitle_4' => 'Subtitle 4',
            'body' => 'Body',
            'caption' => 'Caption',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    public function fontFamilyStack(string $fontFamily): string
    {
        $key = preg_replace('/[^a-z]/', '', strtolower($fontFamily));
        return match ($key) {
            'serif' => "Georgia,'Times New Roman',serif",
            'sans' => 'system-ui,-apple-system,Segoe UI,sans-serif',
            'mono' => "'Courier New',Courier,monospace",
            'arial' => 'Arial,Helvetica,sans-serif',
            default => "Georgia,'Times New Roman',serif",
        };
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $fallback
     * @return array{font_family:string,font_size:int,color:string}
     */
    private function normalizeParagraphStyle(array $input, array $fallback): array
    {
        return array(
            'font_family' => $this->normalizeFont((string)($input['font_family'] ?? $fallback['font_family'] ?? 'serif')),
            'font_size' => $this->normalizeFontSize($input['font_size'] ?? $fallback['font_size'] ?? 11),
            'color' => $this->normalizeColor((string)($input['color'] ?? $fallback['color'] ?? '#0f172a'), '#0f172a'),
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function normalizeTableStyle(array $input, array $fallback): array
    {
        $borderWidth = strtolower(trim((string)($input['border_width'] ?? $fallback['border_width'] ?? 'medium')));
        if (!in_array($borderWidth, array('thin', 'medium', 'thick'), true)) {
            $borderWidth = 'medium';
        }
        return array(
            'border_width' => $borderWidth,
            'border_color' => $this->normalizeColor((string)($input['border_color'] ?? $fallback['border_color'] ?? '#94a3b8'), '#94a3b8'),
            'cell_bg' => $this->normalizeColor((string)($input['cell_bg'] ?? $fallback['cell_bg'] ?? '#ffffff'), '#ffffff'),
            'title_row' => $this->normalizeTableRowStyle(
                is_array($input['title_row'] ?? null) ? $input['title_row'] : array(),
                is_array($fallback['title_row'] ?? null) ? $fallback['title_row'] : array()
            ),
            'header_row' => $this->normalizeTableRowStyle(
                is_array($input['header_row'] ?? null) ? $input['header_row'] : array(),
                is_array($fallback['header_row'] ?? null) ? $fallback['header_row'] : array()
            ),
            'body_row' => $this->normalizeTableRowStyle(
                is_array($input['body_row'] ?? null) ? $input['body_row'] : array(),
                is_array($fallback['body_row'] ?? null) ? $fallback['body_row'] : array()
            ),
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $fallback
     * @return array{font_family:string,font_size:int,color:string,bg:string}
     */
    private function normalizeTableRowStyle(array $input, array $fallback): array
    {
        return array(
            'font_family' => $this->normalizeFont((string)($input['font_family'] ?? $fallback['font_family'] ?? 'serif')),
            'font_size' => $this->normalizeFontSize($input['font_size'] ?? $fallback['font_size'] ?? 10),
            'color' => $this->normalizeColor((string)($input['color'] ?? $fallback['color'] ?? '#0f172a'), '#0f172a'),
            'bg' => $this->normalizeColor((string)($input['bg'] ?? $fallback['bg'] ?? ''), ''),
        );
    }

    /**
     * @param list<array<string,mixed>> $presets
     * @return list<array<string,string>>
     */
    private function normalizeCalloutPresets(array $presets): array
    {
        $out = array();
        foreach ($presets as $preset) {
            if (!is_array($preset)) {
                continue;
            }
            $type = strtolower(trim((string)($preset['callout_type'] ?? '')));
            if (!in_array($type, self::CALLOUT_TYPES, true)) {
                continue;
            }
            $out[$type] = array(
                'callout_type' => $type,
                'title' => trim((string)($preset['title'] ?? strtoupper($type))),
                'text' => trim((string)($preset['text'] ?? '')),
            );
        }
        foreach ($this->defaultCalloutPresets() as $default) {
            $type = (string)$default['callout_type'];
            if (!isset($out[$type])) {
                $out[$type] = $default;
            }
        }
        return array_values($out);
    }

    private function normalizeFont(string $font): string
    {
        $font = strtolower(trim($font));
        if (in_array($font, self::FONT_KEYS, true)) {
            return $font;
        }
        if (in_array($font, array('manuallabel', 'manualtitle', 'sectiontitle'), true)) {
            return 'sans';
        }
        return 'serif';
    }

    private function normalizeFontSize(mixed $size): int
    {
        $allowed = array(8, 9, 10, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32);
        $size = (int)$size;
        return in_array($size, $allowed, true) ? $size : 11;
    }

    private function normalizeColor(string $color, string $fallback): string
    {
        $color = trim($color);
        if ($color === '') {
            return $fallback;
        }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1) {
            return strtolower($color);
        }
        return $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_versions WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Version not found.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeMeta(array $version): array
    {
        $raw = $version['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}
