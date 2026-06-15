<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';

/**
 * Resolves manual template tokens in reader HTML — never exposes raw placeholders.
 */
final class ControlledPublishingReaderTokenResolver
{
    private const FALLBACK = '—';

    /** @var list<string> */
    private const KNOWN_TOKENS = array(
        'book_title',
        'manual_code',
        'part_title',
        'page',
        'page_total',
        'revision',
        'date',
        'section_title',
        'annex_number',
        'annex_title',
        'annex_revision',
        'annex_revision_date',
    );

    public function __construct(private ControlledPublishingPageHeaderService $pageHeaderSvc)
    {
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param array<string,mixed> $overrides
     * @return array<string,string>
     */
    public function buildContext(array $version, array $section, array $overrides = array()): array
    {
        $merged = array_merge(array(
            'page' => 1,
            'page_total' => null,
        ), $overrides);

        $context = $this->pageHeaderSvc->buildTokenContext($version, $section, $merged);

        $normalized = array();
        foreach (self::KNOWN_TOKENS as $key) {
            $value = trim((string)($context[$key] ?? ''));
            if ($value === '' || $value === '—') {
                $value = self::FALLBACK;
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Resolve all known tokens in HTML, then strip any remaining placeholders.
     *
     * @param array<string,string> $context
     */
    public function resolveHtml(string $html, array $context): string
    {
        if ($html === '') {
            return '';
        }

        foreach (self::KNOWN_TOKENS as $key) {
            $value = (string)($context[$key] ?? self::FALLBACK);
            if ($value === '') {
                $value = self::FALLBACK;
            }
            $html = str_replace('{{' . $key . '}}', $value, $html);
            $html = str_replace('{' . $key . '}', $value, $html);
        }

        return $this->stripRemainingTokens($html);
    }

    /**
     * Replace any leftover {token} or {{token}} patterns with fallback.
     */
    public function stripRemainingTokens(string $html): string
    {
        $html = (string)preg_replace('/\{\{[a-z_]+\}\}/', self::FALLBACK, $html);
        $html = (string)preg_replace('/\{[a-z_]+\}/', self::FALLBACK, $html);

        return $html;
    }

    /**
     * @return array<string,string>
     */
    public function contextForApi(array $context): array
    {
        $out = array();
        foreach (self::KNOWN_TOKENS as $key) {
            $out[$key] = (string)($context[$key] ?? self::FALLBACK);
        }

        return $out;
    }
}
