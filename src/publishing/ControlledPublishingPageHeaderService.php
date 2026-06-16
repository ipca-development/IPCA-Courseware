<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingAnnexService.php';

/**
 * Book-level page header/footer templates stored in version metadata_json.
 */
final class ControlledPublishingPageHeaderService
{
    /** @var list<string> */
    public const HIDE_HEADER_FOOTER_SECTIONS = array('cover');

    /** @var array<string,array{label:string,description:string}> */
    public const TOKEN_CATALOG = array(
        'page' => array('label' => 'Page number', 'description' => 'Current page (adaptive in e-reader/PDF)'),
        'page_total' => array('label' => 'Total pages', 'description' => 'Total page count'),
        'revision' => array('label' => 'Revision number', 'description' => 'Manual version label'),
        'date' => array('label' => 'Publication date', 'description' => 'Effective or release date'),
        'manual_code' => array('label' => 'Manual code', 'description' => 'Short manual identifier (e.g. OM)'),
        'book_title' => array('label' => 'Manual title', 'description' => 'Full manual title'),
        'part_title' => array('label' => 'Part title', 'description' => 'Current manual part (e.g. PART 1 – General)'),
        'section_title' => array('label' => 'Section title', 'description' => 'Current section name'),
        'annex_number' => array('label' => 'Annex number', 'description' => 'Annex number (e.g. 01, 02a)'),
        'annex_title' => array('label' => 'Annex title', 'description' => 'Annex title without prefix'),
        'annex_revision' => array('label' => 'Annex revision', 'description' => 'Annex revision label (e.g. 1.1)'),
        'annex_revision_date' => array('label' => 'Annex revision date', 'description' => 'Annex revision date'),
    );

    private const ANNEX_FAMILY_SECTION_KEYS = array('annexes', 'annexes_register', 'annexes_highlights');
    private const ANNEX_CONTENT_SECTION_PREFIX = 'annexes_annex_';
    private const ANNEX_CENTER_TEXT = '{book_title} ({manual_code}) Annexes';
    public const SCOPE_MAIN = 'main';
    public const SCOPE_ANNEX = 'annex';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultPageHeader(): array
    {
        return array(
            'enabled' => true,
            'left_type' => 'logo',
            'logo_url' => '',
            'logo_alt' => 'EuroPilot Center',
            'logo_max_height' => 40,
            'row_height' => 32,
            'center_text' => "{book_title} ({manual_code})\n{part_title}",
            'center_font_family' => 'sans',
            'center_font_size' => 11,
            'center_font_bold' => true,
            'center_font_italic' => false,
            'center_font_underline' => false,
            'right_text' => "Page: {page}\nRevision: {revision}\nDate: {date}",
            'right_font_family' => 'sans',
            'right_font_size' => 10,
            'right_font_bold' => true,
            'right_font_italic' => false,
            'right_font_underline' => false,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultPageFooter(): array
    {
        return array(
            'enabled' => true,
            'row_height' => 26,
            'left_text' => '',
            'left_font_family' => 'sans',
            'left_font_size' => 9,
            'left_font_bold' => false,
            'left_font_italic' => false,
            'left_font_underline' => false,
            'center_text' => 'Controlled copy — internal use',
            'center_font_family' => 'sans',
            'center_font_size' => 9,
            'center_font_bold' => false,
            'center_font_italic' => false,
            'center_font_underline' => false,
            'right_text' => '',
            'right_font_family' => 'sans',
            'right_font_size' => 9,
            'right_font_bold' => false,
            'right_font_italic' => false,
            'right_font_underline' => false,
        );
    }

    /**
     * @return list<array{token:string,label:string,description:string}>
     */
    public function tokenCatalogForApi(): array
    {
        $out = array();
        foreach (self::TOKEN_CATALOG as $token => $meta) {
            $out[] = array(
                'token' => '{' . $token . '}',
                'label' => (string)$meta['label'],
                'description' => (string)$meta['description'],
            );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed>|null $legacySectionLayout
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>}
     */
    public function resolveFromMetadata(array $metadata, ?array $legacySectionLayout = null): array
    {
        $headerDefaults = $this->defaultPageHeader();
        $footerDefaults = $this->defaultPageFooter();

        $rawHeader = is_array($metadata['page_header'] ?? null) ? $metadata['page_header'] : null;
        $rawFooter = is_array($metadata['page_footer'] ?? null) ? $metadata['page_footer'] : null;

        if ($rawHeader === null && $legacySectionLayout !== null) {
            $migrated = $this->migrateLegacySectionLayout($legacySectionLayout);
            $rawHeader = $migrated['header'];
            if ($rawFooter === null) {
                $rawFooter = $migrated['footer'];
            }
        }

        return array(
            'page_header' => $this->normalizePageHeader($rawHeader ?? array(), $headerDefaults),
            'page_footer' => $this->normalizePageFooter($rawFooter ?? array(), $footerDefaults),
        );
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed>|null $legacySectionLayout
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>}
     */
    public function resolveFromVersion(array $version, ?array $legacySectionLayout = null): array
    {
        $meta = $this->decodeMeta($version);
        return $this->resolveFromMetadata($meta, $legacySectionLayout);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param array<string,mixed>|null $legacySectionLayout
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>}
     */
    public function resolveForSection(array $version, array $section, ?array $legacySectionLayout = null): array
    {
        if ($this->headerScopeForSection($section) === self::SCOPE_ANNEX) {
            $meta = $this->decodeMeta($version);
            return $this->resolveAnnexFromMetadata($meta, $legacySectionLayout);
        }
        return $this->resolveFromVersion($version, $legacySectionLayout);
    }

    /**
     * @param array<string,mixed> $section
     */
    public function headerScopeForSection(array $section): string
    {
        return $this->isAnnexFamilySection($section) ? self::SCOPE_ANNEX : self::SCOPE_MAIN;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed>|null $legacySectionLayout
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>}
     */
    public function resolveAnnexFromMetadata(array $metadata, ?array $legacySectionLayout = null): array
    {
        $main = $this->resolveFromMetadata($metadata, $legacySectionLayout);
        $hasAnnexHeader = is_array($metadata['annex_page_header'] ?? null);
        $hasAnnexFooter = is_array($metadata['annex_page_footer'] ?? null);

        if (!$hasAnnexHeader && !$hasAnnexFooter) {
            return $this->applyAnnexSectionDefaultCenter($main);
        }

        $payload = array(
            'page_header' => $hasAnnexHeader
                ? $metadata['annex_page_header']
                : $main['page_header'],
            'page_footer' => $hasAnnexFooter
                ? $metadata['annex_page_footer']
                : $main['page_footer'],
        );
        $config = $this->resolveFromMetadata($payload);
        if (!$hasAnnexHeader) {
            $config = $this->applyAnnexSectionDefaultCenter($config);
        }
        return $config;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function hasAnnexHeaderOverride(array $metadata): bool
    {
        return is_array($metadata['annex_page_header'] ?? null)
            || is_array($metadata['annex_page_footer'] ?? null);
    }

    /**
     * @param array<string,mixed> $section
     */
    public function isAnnexContentSection(array $section): bool
    {
        return str_starts_with((string)($section['section_key'] ?? ''), self::ANNEX_CONTENT_SECTION_PREFIX);
    }

    /**
     * @param array<string,mixed> $section
     */
    public function isAnnexFamilySection(array $section): bool
    {
        $key = (string)($section['section_key'] ?? '');
        if (in_array($key, self::ANNEX_FAMILY_SECTION_KEYS, true)) {
            return true;
        }
        return $this->isAnnexContentSection($section);
    }

    /**
     * Apply annex-specific running-header defaults when no dedicated annex template exists yet.
     *
     * @param array{page_header:array<string,mixed>,page_footer:array<string,mixed>} $config
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>}
     */
    public function applyAnnexSectionDefaultCenter(array $config): array
    {
        $center = trim((string)($config['page_header']['center_text'] ?? ''));
        $defaultCenter = trim((string)$this->defaultPageHeader()['center_text']);
        if ($center === '' || $center === $defaultCenter) {
            $config['page_header']['center_text'] = self::ANNEX_CENTER_TEXT;
        }
        return $config;
    }

    /**
     * @deprecated Use applyAnnexSectionDefaultCenter() or resolveAnnexFromMetadata().
     *
     * @param array{page_header:array<string,mixed>,page_footer:array<string,mixed>} $config
     * @param array<string,mixed> $section
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>,token_overrides?:array<string,mixed>}
     */
    public function applyAnnexSectionHeaderConfig(array $config, array $section): array
    {
        if (!$this->isAnnexFamilySection($section)) {
            return $config;
        }
        return $this->applyAnnexSectionDefaultCenter($config);
    }

    /**
     * @param array<string,mixed> $pageLayout
     * @return array{page_header:array<string,mixed>,page_footer:array<string,mixed>}
     */
    public function saveForVersion(int $versionId, array $pageLayout, ?int $actorUserId = null, string $scope = self::SCOPE_MAIN): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $scope = $scope === self::SCOPE_ANNEX ? self::SCOPE_ANNEX : self::SCOPE_MAIN;

        if ($scope === self::SCOPE_ANNEX) {
            $base = $this->resolveAnnexFromMetadata($meta);
            $merge = array(
                'page_header' => is_array($pageLayout['page_header'] ?? null)
                    ? $pageLayout['page_header']
                    : $base['page_header'],
                'page_footer' => is_array($pageLayout['page_footer'] ?? null)
                    ? $pageLayout['page_footer']
                    : $base['page_footer'],
            );
            $normalized = $this->resolveFromMetadata($merge);
            $meta['annex_page_header'] = $normalized['page_header'];
            $meta['annex_page_footer'] = $normalized['page_footer'];
            $saved = $this->resolveAnnexFromMetadata($meta);
        } else {
            $normalized = $this->resolveFromMetadata(array_merge($meta, $pageLayout));
            $meta['page_header'] = $normalized['page_header'];
            $meta['page_footer'] = $normalized['page_footer'];
            $saved = $normalized;
        }

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));

        return $saved;
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $pageHeader
     * @param array<string,mixed> $pageFooter
     * @param array<string,mixed> $sectionLayout
     */
    public function shouldShowHeader(
        array $section,
        array $pageHeader,
        array $pageFooter,
        array $sectionLayout = array()
    ): bool {
        if (!empty($sectionLayout['hide_header_footer'])) {
            return false;
        }
        $sectionKey = (string)($section['section_key'] ?? '');
        if (in_array($sectionKey, self::HIDE_HEADER_FOOTER_SECTIONS, true)) {
            return false;
        }
        return !empty($pageHeader['enabled']) || !empty($pageFooter['enabled']);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param array{page?:int|string,page_total?:int|string,editor_preview?:bool,part_title?:string,revision?:string,date?:string} $overrides
     * @return array<string,string>
     */
    public function buildTokenContext(array $version, array $section, array $overrides = array()): array
    {
        $editorPreview = !empty($overrides['editor_preview']);
        $page = $overrides['page'] ?? null;
        $pageTotal = $overrides['page_total'] ?? null;
        $isAnnexContent = $this->isAnnexContentSection($section);

        $bookKey = (string)($version['book_key'] ?? '');
        $manualCode = trim((string)($version['manual_code'] ?? ''));
        if ($manualCode === '') {
            $manualCode = $bookKey;
        }

        $dateRaw = (string)($version['effective_date'] ?? '');
        if ($dateRaw === '' && !empty($version['released_at'])) {
            $dateRaw = (string)$version['released_at'];
        }
        $dateFormatted = $this->formatDate($dateRaw);

        $annexNumber = '';
        $annexTitle = '';
        $annexRevision = '';
        $annexRevisionDate = '';
        $metaRaw = $section['metadata_json'] ?? '{}';
        $meta = is_array($metaRaw) ? $metaRaw : json_decode((string)$metaRaw, true);
        if (is_array($meta) && is_array($meta['annex'] ?? null)) {
            $annex = $meta['annex'];
            $num = (int)($annex['number'] ?? 0);
            if ($num > 0) {
                $suffix = array_key_exists('suffix', $annex)
                    ? ControlledPublishingAnnexService::normalizeAnnexSuffix((string)$annex['suffix'])
                    : '';
                if ($suffix === '' && preg_match('/^annexes_annex_(\d+)([a-z])?$/', (string)($section['section_key'] ?? ''), $m) === 1) {
                    $suffix = (string)($m[2] ?? '');
                }
                $annexNumber = ControlledPublishingAnnexService::formatAnnexDisplayNumber($num, $suffix);
            }
            $annexRevision = trim((string)($annex['revision'] ?? ''));
            $annexRevisionDate = trim((string)($annex['revision_date'] ?? ''));
        }
        $sectionTitle = (string)($section['title'] ?? '');
        if (preg_match('/^Annex\s+\d+[a-z]?\s*[–\-—]\s*(.+)$/iu', $sectionTitle, $m) === 1) {
            $annexTitle = trim($m[1]);
        } elseif (preg_match('/^Annex\s+\d+[a-z]?/iu', $sectionTitle) !== 1) {
            $annexTitle = $sectionTitle;
        }

        $revision = (string)($version['version_label'] ?? '');
        $date = $dateFormatted;
        if ($isAnnexContent) {
            if ($annexRevision !== '') {
                $revision = $annexRevision;
            }
            if ($annexRevisionDate !== '') {
                $date = $this->formatDate($annexRevisionDate);
            }
        }
        if (array_key_exists('revision', $overrides)) {
            $revision = trim((string)$overrides['revision']);
        }
        if (array_key_exists('date', $overrides)) {
            $date = trim((string)$overrides['date']);
        }

        if (is_string($page) && preg_match('/^\{(page|page_total)\}$/', trim($page))) {
            $pageDisplay = trim($page);
        } elseif ($isAnnexContent) {
            $pageDisplay = $editorPreview ? '—' : ($page === null ? '1' : (string)$page);
        } else {
            $pageDisplay = $editorPreview || $page === null ? '—' : (string)$page;
        }
        if (is_string($pageTotal) && preg_match('/^\{(page|page_total)\}$/', trim($pageTotal))) {
            $pageTotalDisplay = trim($pageTotal);
        } else {
            $pageTotalDisplay = $editorPreview || $pageTotal === null ? '—' : (string)$pageTotal;
        }

        return array(
            'page' => $pageDisplay,
            'page_total' => $pageTotalDisplay,
            'revision' => $revision,
            'date' => $date,
            'manual_code' => $manualCode,
            'book_title' => (string)($version['book_title'] ?? $version['title'] ?? ''),
            'part_title' => trim((string)($overrides['part_title'] ?? '')),
            'section_title' => $sectionTitle,
            'annex_number' => $annexNumber,
            'annex_title' => $annexTitle,
            'annex_revision' => $annexRevision,
            'annex_revision_date' => $annexRevisionDate !== '' ? $this->formatDate($annexRevisionDate) : '',
        );
    }

    /**
     * @param array<string,string> $context
     */
    public function resolveTokens(string $template, array $context): string
    {
        if ($template === '') {
            return '';
        }
        return (string)preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use ($context): string {
                $key = (string)($m[1] ?? '');
                return array_key_exists($key, $context) ? (string)$context[$key] : '';
            },
            $template
        );
    }

    /**
     * @param array<string,string> $context
     */
    public function resolveTokensToHtml(string $template, array $context): string
    {
        $resolved = $this->resolveTokens($template, $context);
        if ($resolved === '') {
            return '';
        }
        $lines = preg_split("/\r\n|\n|\r/", $resolved) ?: array();
        $parts = array();
        foreach ($lines as $line) {
            $parts[] = h((string)$line);
        }
        return implode('<br>', $parts);
    }

    /**
     * @param array<string,mixed> $layout
     * @return array{header:array<string,mixed>,footer:array<string,mixed>}
     */
    private function migrateLegacySectionLayout(array $layout): array
    {
        $center = trim((string)($layout['header_center'] ?? ''));
        $right = trim((string)($layout['header_right'] ?? ''));
        $left = trim((string)($layout['header_left'] ?? ''));

        $header = $this->defaultPageHeader();
        $header['enabled'] = !empty($layout['show_running_header_footer']);
        if ($left !== '' && preg_match('#^https?://#i', $left)) {
            $header['logo_url'] = $left;
        } elseif ($left !== '' && $left !== 'IPCA · Controlled Manual') {
            $header['center_text'] = $left . "\n" . $header['center_text'];
        }
        if ($center !== '') {
            $header['center_text'] = $center;
        }
        if ($right !== '') {
            $header['right_text'] = $right;
        }

        $footer = $this->defaultPageFooter();
        $footerLeft = trim((string)($layout['footer_left'] ?? ''));
        $footerCenter = trim((string)($layout['footer_center'] ?? ''));
        $footerRight = trim((string)($layout['footer_right'] ?? ''));
        if ($footerLeft !== '') {
            $footer['left_text'] = $footerLeft;
        }
        if ($footerCenter !== '') {
            $footer['center_text'] = $footerCenter;
        }
        if ($footerRight !== '') {
            $footer['right_text'] = $footerRight;
        }

        return array('header' => $header, 'footer' => $footer);
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function normalizePageHeader(array $raw, array $defaults): array
    {
        $logoUrl = trim((string)($raw['logo_url'] ?? ''));
        if ($logoUrl !== '' && !preg_match('#^https?://#i', $logoUrl) && !str_starts_with($logoUrl, '/')) {
            $logoUrl = '';
        }

        $centerText = trim((string)($raw['center_text'] ?? $defaults['center_text']));
        if ($centerText === "{manual_code}\n{section_title}") {
            $centerText = (string)$defaults['center_text'];
        }

        return array_merge(
            array(
                'enabled' => array_key_exists('enabled', $raw) ? !empty($raw['enabled']) : (bool)$defaults['enabled'],
                'left_type' => (string)($raw['left_type'] ?? $defaults['left_type']) === 'logo' ? 'logo' : 'logo',
                'logo_url' => $logoUrl,
                'logo_alt' => $this->truncate(trim((string)($raw['logo_alt'] ?? $defaults['logo_alt'])), 200),
                'logo_max_height' => $this->normalizeLogoMaxHeight($raw['logo_max_height'] ?? $defaults['logo_max_height']),
                'row_height' => $this->normalizeRowHeight($raw['row_height'] ?? $defaults['row_height']),
                'center_text' => $this->truncate($centerText, 2000),
                'right_text' => $this->truncate(trim((string)($raw['right_text'] ?? $defaults['right_text'])), 2000),
            ),
            $this->normalizeColumnTypography($raw, $defaults, 'center'),
            $this->normalizeColumnTypography($raw, $defaults, 'right')
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function normalizePageFooter(array $raw, array $defaults): array
    {
        return array_merge(
            array(
                'enabled' => array_key_exists('enabled', $raw) ? !empty($raw['enabled']) : (bool)$defaults['enabled'],
                'row_height' => $this->normalizeRowHeight($raw['row_height'] ?? $defaults['row_height']),
                'left_text' => $this->truncate(trim((string)($raw['left_text'] ?? $defaults['left_text'])), 2000),
                'center_text' => $this->truncate(trim((string)($raw['center_text'] ?? $defaults['center_text'])), 2000),
                'right_text' => $this->truncate(trim((string)($raw['right_text'] ?? $defaults['right_text'])), 2000),
            ),
            $this->normalizeColumnTypography($raw, $defaults, 'left'),
            $this->normalizeColumnTypography($raw, $defaults, 'center'),
            $this->normalizeColumnTypography($raw, $defaults, 'right')
        );
    }

    private function formatDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return date('d/m/Y', $ts);
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }

    private function normalizeFont(string $font): string
    {
        $font = strtolower(trim($font));
        if (in_array($font, ControlledPublishingBookStyleService::FONT_KEYS, true)) {
            return $font;
        }
        return 'sans';
    }

    private function normalizeFontSize(mixed $size): int
    {
        $allowed = array(8, 9, 10, 11, 12, 14, 16, 18, 20, 22, 24);
        $size = (int)$size;
        return in_array($size, $allowed, true) ? $size : 11;
    }

    private function normalizeLogoMaxHeight(mixed $height): int
    {
        $height = (int)$height;
        return max(16, min(120, $height > 0 ? $height : 40));
    }

    private function normalizeRowHeight(mixed $height): int
    {
        $height = (int)$height;
        return max(20, min(120, $height > 0 ? $height : 32));
    }

    private function normalizeBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($lower, array('0', 'false', 'no', 'off', ''), true)) {
                return false;
            }
        }
        return !empty($value);
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function normalizeColumnTypography(array $raw, array $defaults, string $prefix): array
    {
        return array(
            $prefix . '_font_family' => $this->normalizeFont((string)($raw[$prefix . '_font_family'] ?? $defaults[$prefix . '_font_family'] ?? 'sans')),
            $prefix . '_font_size' => $this->normalizeFontSize($raw[$prefix . '_font_size'] ?? $defaults[$prefix . '_font_size'] ?? 11),
            $prefix . '_font_bold' => $this->normalizeBool($raw[$prefix . '_font_bold'] ?? null, (bool)($defaults[$prefix . '_font_bold'] ?? false)),
            $prefix . '_font_italic' => $this->normalizeBool($raw[$prefix . '_font_italic'] ?? null, (bool)($defaults[$prefix . '_font_italic'] ?? false)),
            $prefix . '_font_underline' => $this->normalizeBool($raw[$prefix . '_font_underline'] ?? null, (bool)($defaults[$prefix . '_font_underline'] ?? false)),
        );
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
