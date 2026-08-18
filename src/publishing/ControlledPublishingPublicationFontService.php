<?php
declare(strict_types=1);

/**
 * Reversible deterministic typography and editor page-start projection defaults.
 */
final class ControlledPublishingPublicationFontService
{
    public const FAMILY = 'IPCA TM GEN Noto Sans';
    // Independent rollback switches for every current and future publication.
    public const DETERMINISTIC_FONT_ENABLED = true;
    public const EDITOR_AUTHORITATIVE_PAGE_STARTS_ENABLED = true;
    private const FONT_BASE64_FILE = 'tm_gen_noto_sans_latin_wght_normal.woff2.b64';

    /**
     * @param array<string,mixed> $version
     */
    public static function enabledForVersion(array $version): bool
    {
        return self::DETERMINISTIC_FONT_ENABLED;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function enabledForManifest(array $manifest): bool
    {
        $book = is_array($manifest['book'] ?? null) ? $manifest['book'] : array();

        return self::enabledForVersion($book);
    }

    /**
     * @param array<string,mixed> $version
     */
    public static function editorAuthoritativePageStartsEnabledForVersion(array $version): bool
    {
        return self::EDITOR_AUTHORITATIVE_PAGE_STARTS_ENABLED && self::enabledForVersion($version);
    }

    public static function css(string $root): string
    {
        $base64 = self::base64($root);
        if ($base64 === '') {
            return '';
        }

        return '@font-face{font-family:"' . self::FAMILY . '";'
            . 'src:url(data:font/woff2;base64,' . $base64 . ') format("woff2");'
            . 'font-style:normal;font-weight:100 900;font-display:block;}';
    }

    public static function contentHash(string $root): ?string
    {
        $base64 = self::base64($root);
        $bytes = $base64 !== '' ? base64_decode($base64, true) : false;

        return is_string($bytes) ? hash('sha256', $bytes) : null;
    }

    public static function stackFor(string $fontKey, string $fallback): string
    {
        $key = preg_replace('/[^a-z]/', '', strtolower($fontKey));
        if (in_array($key, array('sans', 'arial', 'manuallabel', 'manualtitle', 'sectiontitle'), true)) {
            return '"' . self::FAMILY . '",sans-serif';
        }

        return $fallback;
    }

    public static function applyToRenderedHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $replacement = '"' . self::FAMILY . '",sans-serif';
        $patterns = array(
            '/system-ui\s*,\s*-apple-system\s*,\s*Segoe UI\s*,\s*sans-serif/i',
            '/Arial\s*,\s*Helvetica\s*,\s*sans-serif/i',
        );

        return preg_replace($patterns, $replacement, $html) ?? $html;
    }

    private static function base64(string $root): string
    {
        $path = rtrim($root, '/') . '/src/publishing/' . self::FONT_BASE64_FILE;
        $encoded = is_file($path) ? (string)file_get_contents($path) : '';
        $encoded = preg_replace('/\s+/', '', $encoded) ?? '';

        return $encoded !== '' && base64_decode($encoded, true) !== false ? $encoded : '';
    }
}
