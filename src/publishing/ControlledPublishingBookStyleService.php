<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';

/**
 * Book-level paragraph and table style definitions stored in version metadata_json.
 */
final class ControlledPublishingBookStyleService
{
    /** @var list<string> */
    public const PARAGRAPH_STYLE_KEYS = array(
        'title',
        'subtitle_1',
        'subtitle_2',
        'subtitle_3',
        'subtitle_4',
        'regulatory_reference',
        'body',
        'caption',
    );

    /** @var list<string> */
    public const TOC_PARAGRAPH_STYLE_KEYS = array(
        'title',
        'subtitle_1',
        'subtitle_2',
        'subtitle_3',
        'subtitle_4',
    );

    /** @var array<string,string> */
    public const LEGACY_PARAGRAPH_STYLE_ALIASES = array(
        'heading_1' => 'subtitle_2',
        'heading_2' => 'subtitle_3',
    );

    /** @var list<string> */
    public const FONT_KEYS = array('serif', 'sans', 'arial', 'mono');

    /** @var list<string> */
    public const CALLOUT_TYPES = array('warning', 'caution', 'info', 'note');

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
                'title' => array('font_family' => 'sans', 'font_size' => 24, 'color' => '#0f2744', 'font_bold' => true, 'font_italic' => false, 'font_underline' => false),
                'subtitle_1' => array('font_family' => 'sans', 'font_size' => 18, 'color' => '#0f2744', 'font_bold' => true, 'font_italic' => false, 'font_underline' => false),
                'subtitle_2' => array('font_family' => 'sans', 'font_size' => 16, 'color' => '#0f2744', 'font_bold' => true, 'font_italic' => false, 'font_underline' => false),
                'subtitle_3' => array('font_family' => 'sans', 'font_size' => 14, 'color' => '#0f2744', 'font_bold' => false, 'font_italic' => false, 'font_underline' => false),
                'subtitle_4' => array('font_family' => 'sans', 'font_size' => 12, 'color' => '#334155', 'font_bold' => false, 'font_italic' => false, 'font_underline' => false),
                'regulatory_reference' => array('font_family' => 'mono', 'font_size' => 10, 'color' => '#1e3a8a', 'font_bold' => false, 'font_italic' => false, 'font_underline' => false),
                'body' => array('font_family' => 'serif', 'font_size' => 11, 'color' => '#0f172a', 'font_bold' => false, 'font_italic' => false, 'font_underline' => false),
                'caption' => array('font_family' => 'sans', 'font_size' => 9, 'color' => '#64748b', 'font_bold' => false, 'font_italic' => false, 'font_underline' => false),
            ),
            'table_styles' => array(
                'standard' => $this->defaultTableStyle(),
                'text' => array_merge($this->defaultTableStyle(), array(
                    'border_width' => 'thin',
                    'title_row' => array('font_family' => 'sans', 'font_size' => 10, 'color' => '#0f2744', 'bg' => '#eef2f7'),
                    'body_row' => array('font_family' => 'sans', 'font_size' => 10, 'color' => '#0f172a', 'bg' => ''),
                )),
            ),
            'callout_presets' => $this->defaultCalloutPresets(),
            'callout_styles' => $this->defaultCalloutStyles(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultTableStyle(): array
    {
        return array(
            'border_width' => 'thin',
            'border_color' => '#94a3b8',
            'cell_bg' => '#ffffff',
            'title_row' => array('font_family' => 'sans', 'font_size' => 11, 'color' => '#0f2744', 'bg' => '#e8eef6', 'font_bold' => true, 'font_italic' => false, 'font_underline' => false),
            'header_row' => array('font_family' => 'sans', 'font_size' => 10, 'color' => '#0f172a', 'bg' => '#f1f5f9', 'font_bold' => true, 'font_italic' => false, 'font_underline' => false),
                'body_row' => array('font_family' => 'sans', 'font_size' => 10, 'color' => '#0f172a', 'bg' => '', 'font_bold' => false, 'font_italic' => false, 'font_underline' => false),
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
            array('callout_type' => 'note', 'title' => 'NOTE', 'text' => ''),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaultCalloutStyles(): array
    {
        return array(
            'warning' => array(
                'border_color' => '#dc2626',
                'background' => '#fef2f2',
                'icon_color' => '#dc2626',
                'title_color' => '#991b1b',
                'title_font_family' => 'sans',
                'title_font_size' => 11,
                'title_font_bold' => true,
                'text_color' => '#1e293b',
                'text_font_family' => 'sans',
                'text_font_size' => 10,
            ),
            'caution' => array(
                'border_color' => '#ca8a04',
                'background' => '#fffbeb',
                'icon_color' => '#eab308',
                'title_color' => '#854d0e',
                'title_font_family' => 'sans',
                'title_font_size' => 11,
                'title_font_bold' => true,
                'text_color' => '#1e293b',
                'text_font_family' => 'sans',
                'text_font_size' => 10,
            ),
            'info' => array(
                'border_color' => '#1e40af',
                'background' => '#eff6ff',
                'icon_color' => '#1e3a8a',
                'title_color' => '#1e3a8a',
                'title_font_family' => 'sans',
                'title_font_size' => 11,
                'title_font_bold' => true,
                'text_color' => '#1e293b',
                'text_font_family' => 'sans',
                'text_font_size' => 10,
            ),
            'note' => array(
                'border_color' => '#0d9488',
                'background' => '#f0fdfa',
                'icon_color' => '#0d9488',
                'title_color' => '#115e59',
                'title_font_family' => 'sans',
                'title_font_size' => 11,
                'title_font_bold' => true,
                'text_color' => '#134e4a',
                'text_font_family' => 'sans',
                'text_font_size' => 10,
            ),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function resolveFromMetadata(array $metadata): array
    {
        $defaults = $this->defaultBookStyles();
        $paragraph = $this->migrateLegacyParagraphStyles(
            is_array($metadata['paragraph_styles'] ?? null) ? $metadata['paragraph_styles'] : array()
        );
        $tables = is_array($metadata['table_styles'] ?? null) ? $metadata['table_styles'] : array();
        $callouts = is_array($metadata['callout_presets'] ?? null) ? $metadata['callout_presets'] : array();
        $calloutStyles = is_array($metadata['callout_styles'] ?? null) ? $metadata['callout_styles'] : array();

        $resolvedParagraph = array();
        foreach (self::PARAGRAPH_STYLE_KEYS as $key) {
            $resolvedParagraph[$key] = $this->normalizeParagraphStyle(
                is_array($paragraph[$key] ?? null) ? $paragraph[$key] : array(),
                is_array($defaults['paragraph_styles'][$key] ?? null) ? $defaults['paragraph_styles'][$key] : array()
            );
        }

        $pageHeaderSvc = new ControlledPublishingPageHeaderService($this->pdo);
        $pageLayout = $pageHeaderSvc->resolveFromMetadata($metadata);

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
            'callout_styles' => $this->normalizeCalloutStyles($calloutStyles),
            'page_header' => $pageLayout['page_header'],
            'page_footer' => $pageLayout['page_footer'],
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
        $previousStyles = $this->resolveFromMetadata($meta);
        $normalized = $this->resolveFromMetadata(array_merge($meta, $styles));
        $meta['paragraph_styles'] = $normalized['paragraph_styles'];
        $meta['table_styles'] = $normalized['table_styles'];
        $meta['callout_presets'] = $normalized['callout_presets'];
        $meta['callout_styles'] = $normalized['callout_styles'];

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));

        $this->stripRedundantBlockTypography($versionId, $previousStyles['paragraph_styles']);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $bookStyles
     * @return array{font_family:string,font_size:int,color:string,text_align:string,indent_level:int,font_bold:bool,font_italic:bool,font_underline:bool}
     */
    public function resolveBlockTypography(array $payload, array $bookStyles): array
    {
        $paragraphStyle = $this->canonicalParagraphStyleKey((string)($payload['paragraph_style'] ?? ''));
        if ($paragraphStyle === '') {
            $paragraphStyle = 'body';
        }
        $paragraphDefs = is_array($bookStyles['paragraph_styles'] ?? null)
            ? $bookStyles['paragraph_styles']
            : array();
        $base = array(
            'font_family' => 'serif',
            'font_size' => 11,
            'color' => '#0f172a',
            'text_align' => 'left',
            'indent_level' => 0,
            'font_bold' => false,
            'font_italic' => false,
            'font_underline' => false,
        );
        if (isset($paragraphDefs[$paragraphStyle])) {
            $def = $paragraphDefs[$paragraphStyle];
            $base['font_family'] = (string)($def['font_family'] ?? $base['font_family']);
            $base['font_size'] = (int)($def['font_size'] ?? $base['font_size']);
            $base['color'] = (string)($def['color'] ?? $base['color']);
            $base['font_bold'] = $this->normalizeBool($def['font_bold'] ?? null, $base['font_bold']);
            $base['font_italic'] = $this->normalizeBool($def['font_italic'] ?? null, $base['font_italic']);
            $base['font_underline'] = $this->normalizeBool($def['font_underline'] ?? null, $base['font_underline']);
        }
        if (array_key_exists('font_family', $payload)) {
            $override = $this->normalizeFont((string)$payload['font_family']);
            if ($override !== $base['font_family']) {
                $base['font_family'] = $override;
            }
        }
        if (array_key_exists('font_size', $payload)) {
            $override = $this->normalizeFontSize($payload['font_size']);
            if ($override !== $base['font_size']) {
                $base['font_size'] = $override;
            }
        }
        $colorOverride = null;
        if (array_key_exists('text_color', $payload)) {
            $colorOverride = (string)$payload['text_color'];
        } elseif (array_key_exists('color', $payload)) {
            $colorOverride = (string)$payload['color'];
        }
        if ($colorOverride !== null) {
            $override = $this->normalizeColor($colorOverride, $base['color']);
            if ($override !== $base['color']) {
                $base['color'] = $override;
            }
        }
        $align = strtolower(trim((string)($payload['text_align'] ?? 'left')));
        $base['text_align'] = in_array($align, array('left', 'center', 'right'), true) ? $align : 'left';
        $base['indent_level'] = max(0, min(8, (int)($payload['indent_level'] ?? 0)));
        foreach (array('font_bold', 'font_italic', 'font_underline') as $decorationKey) {
            if (array_key_exists($decorationKey, $payload)) {
                $base[$decorationKey] = $this->normalizeBool($payload[$decorationKey], $base[$decorationKey]);
            }
        }
        return $base;
    }

    /**
     * @param array<string,array<string,mixed>> $paragraphStyles
     */
    private function stripRedundantBlockTypography(int $versionId, array $paragraphStyles): void
    {
        $stmt = $this->pdo->prepare("
            SELECT id, payload_json
            FROM ipca_publishing_book_blocks
            WHERE book_version_id = :version_id
              AND block_type IN ('paragraph', 'heading', 'list')
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $update = $this->pdo->prepare("
            UPDATE ipca_publishing_book_blocks
            SET payload_json = :payload_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                continue;
            }
            if (!$this->stripRedundantTypographyFromPayload($payload, $paragraphStyles)) {
                continue;
            }
            $update->execute(array(
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':id' => (int)$row['id'],
            ));
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,array<string,mixed>> $paragraphStyles
     */
    private function stripRedundantTypographyFromPayload(array &$payload, array $paragraphStyles): bool
    {
        $styleKey = $this->canonicalParagraphStyleKey((string)($payload['paragraph_style'] ?? ''));
        if ($styleKey === '') {
            $styleKey = 'body';
        }
        $def = is_array($paragraphStyles[$styleKey] ?? null) ? $paragraphStyles[$styleKey] : null;
        if ($def === null) {
            return false;
        }
        $defFont = $this->normalizeFont((string)($def['font_family'] ?? 'serif'));
        $defSize = $this->normalizeFontSize($def['font_size'] ?? 11);
        $defColor = $this->normalizeColor((string)($def['color'] ?? '#0f172a'), '#0f172a');
        $defBold = $this->normalizeBool($def['font_bold'] ?? null, false);
        $defItalic = $this->normalizeBool($def['font_italic'] ?? null, false);
        $defUnderline = $this->normalizeBool($def['font_underline'] ?? null, false);

        $changed = false;
        if (array_key_exists('font_family', $payload)
            && $this->normalizeFont((string)$payload['font_family']) === $defFont) {
            unset($payload['font_family']);
            $changed = true;
        }
        if (array_key_exists('font_size', $payload)
            && $this->normalizeFontSize($payload['font_size']) === $defSize) {
            unset($payload['font_size']);
            $changed = true;
        }
        $payloadColor = null;
        if (array_key_exists('text_color', $payload)) {
            $payloadColor = (string)$payload['text_color'];
        } elseif (array_key_exists('color', $payload)) {
            $payloadColor = (string)$payload['color'];
        }
        if ($payloadColor !== null
            && $this->normalizeColor($payloadColor, $defColor) === $defColor) {
            unset($payload['text_color'], $payload['color']);
            $changed = true;
        }
        foreach (array(
            'font_bold' => $defBold,
            'font_italic' => $defItalic,
            'font_underline' => $defUnderline,
        ) as $decorationKey => $defValue) {
            if (array_key_exists($decorationKey, $payload)
                && $this->normalizeBool($payload[$decorationKey], $defValue) === $defValue) {
                unset($payload[$decorationKey]);
                $changed = true;
            }
        }
        return $changed;
    }

    public function paragraphStyleLabel(string $key): string
    {
        $key = $this->canonicalParagraphStyleKey($key);
        return match ($key) {
            'title' => 'Title',
            'subtitle_1' => 'Subtitle 1',
            'subtitle_2' => 'Subtitle 2',
            'subtitle_3' => 'Subtitle 3',
            'subtitle_4' => 'Subtitle 4',
            'regulatory_reference' => 'Regulatory Reference',
            'body' => 'Body',
            'caption' => 'Caption',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    public function canonicalParagraphStyleKey(string $style): string
    {
        $style = strtolower(trim($style));
        if ($style === '') {
            return '';
        }
        return self::LEGACY_PARAGRAPH_STYLE_ALIASES[$style] ?? $style;
    }

    /**
     * @param array<string,mixed> $paragraph
     * @return array<string,mixed>
     */
    private function migrateLegacyParagraphStyles(array $paragraph): array
    {
        foreach (self::LEGACY_PARAGRAPH_STYLE_ALIASES as $legacy => $modern) {
            if (isset($paragraph[$legacy]) && !isset($paragraph[$modern])) {
                $paragraph[$modern] = $paragraph[$legacy];
            }
        }
        return $paragraph;
    }

    /**
     * @param array<string,mixed> $bookStyles
     * @return array<string,mixed>
     */
    public function resolveStandardTableStyle(array $bookStyles): array
    {
        $defaults = $this->defaultBookStyles()['table_styles']['standard'];
        $tables = is_array($bookStyles['table_styles'] ?? null) ? $bookStyles['table_styles'] : array();
        return $this->normalizeTableStyle(
            is_array($tables['standard'] ?? null) ? $tables['standard'] : array(),
            $defaults
        );
    }

    /**
     * @param array<string,mixed> $bookStyles
     * @return array<string,mixed>
     */
    public function resolveCalloutStyle(array $bookStyles, string $type): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::CALLOUT_TYPES, true)) {
            $type = 'warning';
        }
        $defaults = $this->defaultCalloutStyles();
        $styles = is_array($bookStyles['callout_styles'] ?? null) ? $bookStyles['callout_styles'] : array();
        $fallback = is_array($defaults[$type] ?? null) ? $defaults[$type] : $defaults['warning'];

        return $this->normalizeCalloutStyle(
            is_array($styles[$type] ?? null) ? $styles[$type] : array(),
            $fallback
        );
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
     * @return array{font_family:string,font_size:int,color:string,font_bold:bool,font_italic:bool,font_underline:bool}
     */
    private function normalizeParagraphStyle(array $input, array $fallback): array
    {
        return array(
            'font_family' => $this->normalizeFont((string)($input['font_family'] ?? $fallback['font_family'] ?? 'serif')),
            'font_size' => $this->normalizeFontSize($input['font_size'] ?? $fallback['font_size'] ?? 11),
            'color' => $this->normalizeColor((string)($input['color'] ?? $fallback['color'] ?? '#0f172a'), '#0f172a'),
            'font_bold' => $this->normalizeBool($input['font_bold'] ?? null, (bool)($fallback['font_bold'] ?? false)),
            'font_italic' => $this->normalizeBool($input['font_italic'] ?? null, (bool)($fallback['font_italic'] ?? false)),
            'font_underline' => $this->normalizeBool($input['font_underline'] ?? null, (bool)($fallback['font_underline'] ?? false)),
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
     * @return array{font_family:string,font_size:int,color:string,bg:string,font_bold:bool,font_italic:bool,font_underline:bool}
     */
    private function normalizeTableRowStyle(array $input, array $fallback): array
    {
        return array(
            'font_family' => $this->normalizeFont((string)($input['font_family'] ?? $fallback['font_family'] ?? 'serif')),
            'font_size' => $this->normalizeFontSize($input['font_size'] ?? $fallback['font_size'] ?? 10),
            'color' => $this->normalizeColor((string)($input['color'] ?? $fallback['color'] ?? '#0f172a'), '#0f172a'),
            'bg' => $this->normalizeColor((string)($input['bg'] ?? $fallback['bg'] ?? ''), ''),
            'font_bold' => $this->normalizeBool($input['font_bold'] ?? null, (bool)($fallback['font_bold'] ?? false)),
            'font_italic' => $this->normalizeBool($input['font_italic'] ?? null, (bool)($fallback['font_italic'] ?? false)),
            'font_underline' => $this->normalizeBool($input['font_underline'] ?? null, (bool)($fallback['font_underline'] ?? false)),
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

    /**
     * @param array<string,mixed> $styles
     * @return array<string, array<string, mixed>>
     */
    private function normalizeCalloutStyles(array $styles): array
    {
        $defaults = $this->defaultCalloutStyles();
        $out = array();
        foreach (self::CALLOUT_TYPES as $type) {
            $fallback = is_array($defaults[$type] ?? null) ? $defaults[$type] : $defaults['warning'];
            $out[$type] = $this->normalizeCalloutStyle(
                is_array($styles[$type] ?? null) ? $styles[$type] : array(),
                $fallback
            );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function normalizeCalloutStyle(array $input, array $fallback): array
    {
        return array(
            'border_color' => $this->normalizeColor((string)($input['border_color'] ?? $fallback['border_color'] ?? '#94a3b8'), '#94a3b8'),
            'background' => $this->normalizeColor((string)($input['background'] ?? $fallback['background'] ?? '#ffffff'), '#ffffff'),
            'icon_color' => $this->normalizeColor((string)($input['icon_color'] ?? $fallback['icon_color'] ?? '#0f2744'), '#0f2744'),
            'title_color' => $this->normalizeColor((string)($input['title_color'] ?? $fallback['title_color'] ?? '#0f2744'), '#0f2744'),
            'title_font_family' => $this->normalizeFont((string)($input['title_font_family'] ?? $fallback['title_font_family'] ?? 'sans')),
            'title_font_size' => $this->normalizeFontSize($input['title_font_size'] ?? $fallback['title_font_size'] ?? 11),
            'title_font_bold' => $this->normalizeBool($input['title_font_bold'] ?? null, (bool)($fallback['title_font_bold'] ?? true)),
            'text_color' => $this->normalizeColor((string)($input['text_color'] ?? $fallback['text_color'] ?? '#1e293b'), '#1e293b'),
            'text_font_family' => $this->normalizeFont((string)($input['text_font_family'] ?? $fallback['text_font_family'] ?? 'sans')),
            'text_font_size' => $this->normalizeFontSize($input['text_font_size'] ?? $fallback['text_font_size'] ?? 10),
        );
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

    private function normalizeBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($normalized, array('0', 'false', 'no', 'off', ''), true)) {
                return false;
            }
        }
        return $default;
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
