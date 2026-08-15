<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';
require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/ControlledPublishingCoverPageService.php';

/**
 * Builds the immutable, version-specific publication contract consumed by readers.
 *
 * This service is read-only. It never writes version metadata or publishing rows.
 */
final class ControlledPublishingBookStyleManifestService
{
    public const SCHEMA_VERSION = 'book-style-manifest-v1';
    public const CSS_GENERATOR_VERSION = 'book-style-css-v2';
    public const TEMPLATE_VERSION = 'controlled-page-bands-v1';
    public const RENDERER_VERSION = 'controlled-book-renderer-v1';

    private string $root;

    public function __construct(private PDO $pdo, ?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $paginateSource
     * @return array<string,mixed>
     */
    public function buildPublicationPackage(array $version, array $paginateSource): array
    {
        $templates = $this->buildTemplates($version, $paginateSource);
        $assets = $this->buildAssetInventory($version, $paginateSource);
        $manifest = $this->buildManifest($version, $templates, $assets);
        $manifestJson = self::canonicalJson($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $css = $this->generateBookStyleCss($manifest);
        $cssHash = hash('sha256', $css);

        return array(
            'manifest_version' => self::SCHEMA_VERSION . '-' . substr($manifestHash, 0, 16),
            'manifest_hash' => $manifestHash,
            'manifest' => $manifest,
            'css' => array(
                'filename' => 'book-style-' . (int)($version['id'] ?? 0) . '-' . substr($cssHash, 0, 16) . '.css',
                'media_type' => 'text/css; charset=utf-8',
                'hash_algorithm' => 'sha256',
                'hash' => $cssHash,
                'content' => $css,
            ),
            'templates' => $templates,
            'assets' => $assets,
        );
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $templates
     * @param list<array<string,mixed>> $assets
     * @return array<string,mixed>
     */
    public function buildManifest(array $version, array $templates, array $assets): array
    {
        $styleService = new ControlledPublishingBookStyleService($this->pdo);
        $headerService = new ControlledPublishingPageHeaderService($this->pdo);
        $metadata = $this->decodeMetadata($version);
        $mainBands = $headerService->resolveFromMetadata($metadata);
        $annexBands = $headerService->resolveAnnexFromMetadata($metadata);

        return self::sortRecursively(array(
            'schema_version' => self::SCHEMA_VERSION,
            'book' => array(
                'book_key' => (string)($version['book_key'] ?? ''),
                'manual_code' => (string)($version['manual_code'] ?? ''),
                'version_id' => (int)($version['id'] ?? 0),
                'version_label' => (string)($version['version_label'] ?? ''),
            ),
            'styles' => $styleService->resolveFromVersion($version),
            'page_bands' => array(
                'main' => $mainBands,
                'annex' => $annexBands,
                'annex_override_configured' => $headerService->hasAnnexHeaderOverride($metadata),
            ),
            'layout' => ControlledPublishingReaderLayoutProfile::spec(),
            'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
            'render_pipeline' => array(
                'renderer_version' => self::RENDERER_VERSION,
                'renderer_source_hash' => $this->sourceHash('src/publishing/ControlledPublishingBookRenderer.php'),
                'template_version' => self::TEMPLATE_VERSION,
                'css_generator_version' => self::CSS_GENERATOR_VERSION,
            ),
            'template_identity' => $this->templateIdentity($templates),
            'asset_identity' => array_map(
                static fn(array $asset): array => array(
                    'descriptor' => (string)$asset['descriptor'],
                    'descriptor_hash' => (string)$asset['descriptor_hash'],
                ),
                $assets
            ),
        ));
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public function generateBookStyleCss(array $manifest): string
    {
        $structuralPath = $this->root . '/public/assets/manual_reader_content.css';
        $structural = is_file($structuralPath) ? (string)file_get_contents($structuralPath) : '';
        $styles = is_array($manifest['styles'] ?? null) ? $manifest['styles'] : array();
        $paragraphs = is_array($styles['paragraph_styles'] ?? null) ? $styles['paragraph_styles'] : array();
        $layout = is_array($manifest['layout'] ?? null) ? $manifest['layout'] : array();
        $baseFontSize = max(1, (float)($layout['font_size_pt'] ?? 11));
        $baseFontSizeCSS = rtrim(rtrim(sprintf('%.3F', $baseFontSize), '0'), '.');
        $rules = array();

        $rules[] = ':root{--reader-page-scale:1;--reader-font-scale:1;}';
        $rules[] = '.cpb-sheet-body{font-size:calc(' . $baseFontSizeCSS . 'pt * var(--reader-font-scale,1));}';
        $rules[] = '.reader-page-body:not(.reader-page-cover){font-size:calc(' . $baseFontSizeCSS . 'pt * var(--reader-font-scale,1));}';
        $rules[] = '.reader-page-header-region,.reader-page-footer-region{--reader-font-scale:1;}';
        $rules[] = '.reader-canonical-page.cpb-sheet{padding:0;margin:0;zoom:1;max-width:none;min-height:0;box-shadow:none;border-radius:0;}';
        $rules[] = '.reader-page-header-region,.reader-page-footer-region,.reader-page-body:not(.reader-page-cover){overflow:hidden;}';
        $rules[] = '.reader-page-header-region>.cpb-page-header,.reader-page-footer-region>.cpb-page-footer{position:static;inset:auto;width:100%;height:auto;margin:0;box-sizing:border-box;}';

        foreach ($paragraphs as $key => $style) {
            if (!is_array($style)) {
                continue;
            }
            $selector = '[data-paragraph-style="' . preg_replace('/[^a-z0-9_]/', '', (string)$key) . '"]';
            $rules[] = $selector . '{'
                . 'font-family:' . $this->fontStack((string)($style['font_family'] ?? 'serif')) . ';'
                . 'font-size:calc(' . (int)($style['font_size'] ?? 11) . 'pt * var(--reader-font-scale,1));'
                . 'color:' . (string)($style['color'] ?? '#0f172a') . ';'
                . 'font-weight:' . (!empty($style['font_bold']) ? '700' : '400') . ';'
                . 'font-style:' . (!empty($style['font_italic']) ? 'italic' : 'normal') . ';'
                . 'text-decoration:' . (!empty($style['font_underline']) ? 'underline' : 'none') . ';'
                . ($style['margin_top'] !== null ? 'margin-top:' . (int)$style['margin_top'] . 'px;' : '')
                . ($style['margin_bottom'] !== null ? 'margin-bottom:' . (int)$style['margin_bottom'] . 'px;' : '')
                . '}';
        }

        foreach (($styles['table_styles'] ?? array()) as $kind => $tableStyle) {
            if (!is_array($tableStyle)) {
                continue;
            }
            $kind = preg_replace('/[^a-z0-9_-]/', '', (string)$kind);
            $scope = '[data-table-style-kind="' . $kind . '"]';
            $borderWidth = match ((string)($tableStyle['border_width'] ?? 'thin')) {
                'thick' => 3,
                'medium' => 2,
                default => 1,
            };
            $rules[] = $scope . '{--cpb-table-border-width:' . $borderWidth
                . 'px;--cpb-table-border-color:' . (string)($tableStyle['border_color'] ?? '#94a3b8') . ';}';
            foreach (array(
                'title_row' => '.cpb-table-title-row',
                'header_row' => '.cpb-table-header-row',
                'body_row' => 'tbody tr',
            ) as $rowKey => $rowSelector) {
                $row = is_array($tableStyle[$rowKey] ?? null) ? $tableStyle[$rowKey] : array();
                $background = (string)($row['bg'] ?? '');
                $rules[] = $scope . ' ' . $rowSelector . '>*{'
                    . 'font-family:' . $this->fontStack((string)($row['font_family'] ?? 'sans')) . ';'
                    . 'font-size:calc(' . (int)($row['font_size'] ?? 10) . 'pt * var(--reader-font-scale,1));'
                    . 'color:' . (string)($row['color'] ?? '#0f172a') . ';'
                    . 'background:' . ($background !== '' ? $background : 'transparent') . ';'
                    . 'font-weight:' . (!empty($row['font_bold']) ? '700' : '400') . ';'
                    . 'font-style:' . (!empty($row['font_italic']) ? 'italic' : 'normal') . ';'
                    . 'text-decoration:' . (!empty($row['font_underline']) ? 'underline' : 'none') . ';'
                    . '}';
            }
        }

        foreach (($styles['callout_styles'] ?? array()) as $type => $style) {
            if (!is_array($style)) {
                continue;
            }
            $type = preg_replace('/[^a-z0-9_-]/', '', (string)$type);
            $rules[] = '.cpb-callout--' . $type . '{border-color:' . (string)$style['border_color']
                . ';background:' . (string)$style['background'] . ';}';
            $rules[] = '.cpb-callout--' . $type . ' .cpb-callout-icon{background:'
                . (string)$style['icon_color'] . ';}';
            $rules[] = '.cpb-callout--' . $type . ' .cpb-callout-title{color:' . (string)$style['title_color']
                . ';font-family:' . $this->fontStack((string)$style['title_font_family'])
                . ';font-size:calc(' . (int)$style['title_font_size'] . 'pt * var(--reader-font-scale,1));'
                . 'font-weight:' . (!empty($style['title_font_bold']) ? '700' : '400') . ';}';
            $rules[] = '.cpb-callout--' . $type . ' .cpb-callout-text{color:' . (string)$style['text_color']
                . ';font-family:' . $this->fontStack((string)$style['text_font_family'])
                . ';font-size:calc(' . (int)$style['text_font_size'] . 'pt * var(--reader-font-scale,1));}';
        }

        return "/* Generated per-version book-style.css; inline server block styles remain authoritative. */\n"
            . rtrim($structural) . "\n"
            . implode("\n", $rules) . "\n";
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function buildTemplates(array $version, array $source): array
    {
        $headerService = new ControlledPublishingPageHeaderService($this->pdo);
        $metadata = $this->decodeMetadata($version);
        $configs = array(
            'main' => $headerService->resolveFromMetadata($metadata),
            'annex' => $headerService->resolveAnnexFromMetadata($metadata),
        );
        $found = array('main' => null, 'annex' => null);

        foreach (($source['sections'] ?? array()) as $section) {
            if (!is_array($section) || empty($section['show_header_footer'])) {
                continue;
            }
            $scope = $headerService->isAnnexFamilySection($section) ? 'annex' : 'main';
            if ($found[$scope] !== null) {
                continue;
            }
            $header = (string)($section['header_template'] ?? '');
            $footer = (string)($section['footer_template'] ?? '');
            if ($header === '' && $footer === '') {
                continue;
            }
            $found[$scope] = array(
                'source_section_id' => (int)($section['section_id'] ?? 0),
                'header_html' => $header,
                'footer_html' => $footer,
                'header_hash' => hash('sha256', $header),
                'footer_hash' => hash('sha256', $footer),
                'template_hash' => hash('sha256', $header . "\0" . $footer),
            );
        }

        $out = array();
        foreach (array('main', 'annex') as $scope) {
            $out[$scope] = array(
                'available' => $found[$scope] !== null,
                'config' => $configs[$scope],
                'rendered' => $found[$scope],
            );
        }
        return self::sortRecursively($out);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $source
     * @return list<array<string,mixed>>
     */
    private function buildAssetInventory(array $version, array $source): array
    {
        $metadata = $this->decodeMetadata($version);
        $urls = array();
        $collect = static function (mixed $value) use (&$collect, &$urls): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if (is_string($child) && (str_ends_with((string)$key, '_url') || str_contains($child, ' src='))) {
                        if (str_ends_with((string)$key, '_url') && trim($child) !== '') {
                            $urls[trim($child)] = true;
                        }
                        if (preg_match_all('/\bsrc=["\']([^"\']+)["\']/i', $child, $matches)) {
                            foreach ($matches[1] as $url) {
                                $urls[html_entity_decode((string)$url, ENT_QUOTES)] = true;
                            }
                        }
                    }
                    $collect($child);
                }
            }
        };
        $collect($metadata);
        $collect($source);

        $assets = array();
        foreach (array_keys($urls) as $url) {
            $descriptor = 'image:' . $url;
            $asset = array(
                'descriptor' => $descriptor,
                'kind' => 'image',
                'url' => $url,
                'content_hash' => $this->localUrlHash($url),
                'hash_algorithm' => 'sha256',
            );
            $asset['descriptor_hash'] = hash('sha256', self::canonicalJson($asset));
            $assets[] = $asset;
        }

        $styles = (new ControlledPublishingBookStyleService($this->pdo))->resolveFromVersion($version);
        $fontKeys = array();
        $walkFonts = static function (mixed $value, string $key = '') use (&$walkFonts, &$fontKeys): void {
            if (!is_array($value)) {
                if (is_string($value) && str_contains($key, 'font_family')) {
                    $fontKeys[$value] = true;
                }
                return;
            }
            foreach ($value as $childKey => $child) {
                $walkFonts($child, (string)$childKey);
            }
        };
        $walkFonts($styles);
        foreach (array_keys($fontKeys) as $fontKey) {
            $asset = array(
                'descriptor' => 'font:' . $fontKey,
                'kind' => 'system_font_stack',
                'url' => null,
                'font_family' => $fontKey,
                'font_stack' => $this->fontStack($fontKey),
                'content_hash' => null,
                'hash_algorithm' => 'sha256',
            );
            $asset['descriptor_hash'] = hash('sha256', self::canonicalJson($asset));
            $assets[] = $asset;
        }

        usort($assets, static fn(array $a, array $b): int => strcmp((string)$a['descriptor'], (string)$b['descriptor']));
        return $assets;
    }

    /**
     * @param array<string,mixed> $templates
     * @return array<string,mixed>
     */
    private function templateIdentity(array $templates): array
    {
        $identity = array();
        foreach (array('main', 'annex') as $scope) {
            $rendered = $templates[$scope]['rendered'] ?? null;
            $identity[$scope] = array(
                'available' => is_array($rendered),
                'template_hash' => is_array($rendered) ? (string)$rendered['template_hash'] : null,
            );
        }
        return $identity;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeMetadata(array $version): array
    {
        $raw = $version['metadata_json'] ?? array();
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function sourceHash(string $relative): ?string
    {
        $path = $this->root . '/' . $relative;
        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    private function localUrlHash(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/')) {
            return null;
        }
        $public = realpath($this->root . '/public');
        $candidate = realpath($this->root . '/public' . $path);
        if ($public === false || $candidate === false || !str_starts_with($candidate, $public . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($candidate) ? hash_file('sha256', $candidate) : null;
    }

    private function fontStack(string $font): string
    {
        return (new ControlledPublishingBookStyleService($this->pdo))->fontFamilyStack($font);
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::sortRecursively($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    public static function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sortRecursively(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::sortRecursively($child);
        }
        return $value;
    }
}
