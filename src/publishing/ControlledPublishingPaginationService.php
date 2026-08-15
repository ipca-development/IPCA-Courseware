<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderService.php';
require_once __DIR__ . '/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
require_once __DIR__ . '/ControlledPublishingAuthoritativePaginationService.php';
require_once __DIR__ . '/ControlledPublishingPublicationFilter.php';

/**
 * Deterministic page-map generator for authoritative OM/OMM manuals.
 *
 * Admin/compliance context only — produces frozen page HTML against a fixed
 * layout profile. Student readers must never call generate methods.
 */
final class ControlledPublishingPaginationService
{
    /** @var array<string,mixed> */
    private array $lastGeneration = array();

    /** @var list<string> */
    private const PART_KEYS = array('part_1', 'part_2', 'part_3', 'part_4', 'main_content');

    /** @var list<string> */
    private const PART0_SECTION_KEYS = array(
        'toc',
        'lep',
        'revision_system',
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
        'highlights',
    );

    /** @var list<string> */
    private const ATOMIC_BLOCK_TYPES = array('table', 'image', 'callout', 'toc', 'lep', 'list');

    /** @var list<string> */
    private const SPLITTABLE_BLOCK_TYPES = array('paragraph', 'heading');

    public function __construct(private ControlledPublishingReaderService $reader)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildPaginateSource(string $bookKey, ?array $version = null): array
    {
        if ($version === null) {
            $version = $this->reader->requireReleasedVersion($bookKey);
        }
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? $bookKey)));
        $versionId = (int)$version['id'];
        $this->reader->paginationConfigureRenderer($version);

        $flat = $this->reader->paginationSections()->listFlatSections($versionId);
        $byId = array();
        foreach ($flat as $row) {
            $byId[(int)$row['id']] = $row;
        }

        $bookKeyResolved = (string)($version['book_key'] ?? $bookKey);
        $orderedSections = $this->reader->paginationEditorNav()->flattenNavigableSectionRows(
            $versionId,
            $bookKeyResolved,
            $flat
        );

        $sectionsOut = array();
        foreach ($orderedSections as $section) {
            $sectionId = (int)($section['id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }

            $flags = $this->classifySection($section, $byId);
            $pageHeaderConfig = $this->reader->paginationPageHeaderConfig($version, $section);
            $partTitle = '';
            if (is_array($pageHeaderConfig['token_overrides'] ?? null)) {
                $partTitle = trim((string)($pageHeaderConfig['token_overrides']['part_title'] ?? ''));
            }

            $tokenContext = $this->reader->paginationTokenContext($version, $section, array(
                'page' => '{page}',
                'page_total' => '{page_total}',
                'part_title' => $partTitle,
            ));

            $layout = $this->reader->paginationLayout()->resolveLayout($section);
            $hideBands = $flags['is_cover'] || !empty($layout['hide_header_footer']);
            $headerFooter = $hideBands
                ? array('header_template' => '', 'footer_template' => '', 'show_bands' => false)
                : $this->extractHeaderFooterTemplates(
                    $version,
                    $section,
                    $pageHeaderConfig,
                    $tokenContext,
                    $layout
                );

            $sectionBlocks = $this->reader->paginationRevision()->annotateChangeStatus(
                $versionId,
                $this->reader->paginationBlocks()->listSectionBlocks($sectionId)
            );

            $entry = array(
                'section_id' => $sectionId,
                'section_key' => (string)($section['section_key'] ?? ''),
                'section_type' => (string)($section['section_type'] ?? ''),
                'title' => (string)($section['title'] ?? ''),
                'stable_anchor' => (string)($section['stable_anchor'] ?? ''),
                'manual_part' => $flags['manual_part'],
                'part_title' => $partTitle,
                'parent_section_id' => $section['parent_section_id'] !== null ? (int)$section['parent_section_id'] : null,
                'pagination_authority' => (string)($flags['pagination_authority'] ?? 'author'),
                'is_generated' => !empty($section['is_generated']),
                'is_system_managed' => !empty($section['is_system_managed']),
                'allow_author_blocks' => !empty($section['allow_author_blocks']),
                'flags' => $flags,
                'header_template' => $headerFooter['header_template'],
                'footer_template' => $headerFooter['footer_template'],
                'show_header_footer' => $headerFooter['show_bands'],
                'sheet_open' => '',
                'token_context' => $this->reader->paginationTokenContext($version, $section, array(
                    'part_title' => $partTitle,
                    'page' => '—',
                    'page_total' => '—',
                )),
            );

            if ($flags['is_cover']) {
                $coverHtml = $this->stripEditorChrome(
                    $this->reader->paginationRenderSectionShellHtml(
                        $version,
                        $section,
                        $sectionBlocks,
                        $pageHeaderConfig,
                        $this->reader->paginationTokenContext($version, $section, array(
                            'part_title' => $partTitle,
                            'page' => '1',
                            'page_total' => '1',
                        ))
                    )
                );
                $entry['content_mode'] = 'cover';
                $entry['cover_html'] = $coverHtml;
                $entry['sheet_open'] = '';
                $entry['units'] = array();
            } elseif ($this->shouldSkipEmptyContainer($section, $sectionBlocks, $byId)) {
                continue;
            } else {
                $shellHtml = $this->stripEditorChrome(
                    $this->reader->paginationResolveHtml(
                        $this->reader->paginationRenderSectionShellHtml(
                            $version,
                            $section,
                            $sectionBlocks,
                            $pageHeaderConfig,
                            $tokenContext
                        ),
                        $tokenContext
                    )
                );
                $parsed = $this->parseRenderedSheet($shellHtml);
                $entry['content_mode'] = 'units';
                $entry['cover_html'] = null;
                $entry['sheet_open'] = $parsed['sheet_open'];
                $entry['uses_sheet_body'] = $parsed['uses_sheet_body'];
                if ($headerFooter['show_bands']) {
                    if ($parsed['header'] !== '') {
                        $entry['header_template'] = $parsed['header'];
                    }
                    if ($parsed['footer'] !== '') {
                        $entry['footer_template'] = $parsed['footer'];
                    }
                }
                $entry['units'] = $this->unitsFromRenderedBody($parsed['body'], $section, $flags);
                if (
                    !empty($flags['force_page_break_before'])
                    && isset($entry['units'][0])
                    && is_array($entry['units'][0])
                ) {
                    $entry['units'][0]['force_break_before'] = true;
                    $entry['units'][0]['generated_section_boundary'] = !empty($flags['is_part0']);
                }
            }

            if ($entry['content_mode'] === 'cover' || ($entry['units'] ?? array()) !== array()) {
                $sectionsOut[] = $entry;
            }
        }

        $manualCode = trim((string)($version['manual_code'] ?? ''));
        if ($manualCode === '') {
            $manualCode = (string)($version['book_key'] ?? $bookKey);
        }

        $manualBreakIdentity = $this->reader->paginationManualPageBreakIdentity($versionId);
        $sectionsOut = $this->reader->paginationApplyManualPageBreaks($versionId, $sectionsOut);

        return array(
            'book_key' => (string)($version['book_key'] ?? $bookKey),
            'book_title' => (string)($version['book_title'] ?? ''),
            'manual_code' => $manualCode,
            'version_id' => $versionId,
            'version_label' => (string)($version['version_label'] ?? ''),
            'released_at' => $version['released_at'] ?? null,
            'effective_date' => $version['effective_date'] ?? null,
            'layout' => ControlledPublishingReaderLayoutProfile::spec(),
            'layout_profile' => ControlledPublishingReaderLayoutProfile::profileKey(),
            'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
            'manual_page_breaks' => $manualBreakIdentity,
            'nav' => $this->reader->buildReaderNavTree($bookKey, $version),
            'sections' => $sectionsOut,
        );
    }

    /**
     * @param array<string,mixed> $section
     * @param array<int,array<string,mixed>> $byId
     * @return array<string,mixed>
     */
    private function classifySection(array $section, array $byId): array
    {
        $key = (string)($section['section_key'] ?? '');
        $parentId = $section['parent_section_id'] !== null ? (int)$section['parent_section_id'] : 0;
        $parent = $parentId > 0 ? ($byId[$parentId] ?? null) : null;
        $parentKey = is_array($parent) ? (string)($parent['section_key'] ?? '') : '';

        $isCover = $key === 'cover';
        $isPart0 = in_array($key, self::PART0_SECTION_KEYS, true);
        $isPartStart = in_array($key, self::PART_KEYS, true);
        $isChapterStart = false;
        $isMajorSectionStart = false;
        $manualPart = null;

        if ($isPartStart) {
            $manualPart = $key === 'main_content' ? 'part_1' : $key;
            $isMajorSectionStart = true;
        } elseif ($parent !== null) {
            if (in_array($parentKey, self::PART_KEYS, true)) {
                $isChapterStart = true;
                $isMajorSectionStart = true;
                $manualPart = $parentKey === 'main_content' ? 'part_1' : $parentKey;
            } elseif ($parentKey !== '' && $parent['parent_section_id'] !== null) {
                $grandParent = $byId[(int)$parent['parent_section_id']] ?? null;
                $grandKey = is_array($grandParent) ? (string)($grandParent['section_key'] ?? '') : '';
                if (in_array($grandKey, self::PART_KEYS, true)) {
                    $manualPart = $grandKey === 'main_content' ? 'part_1' : $grandKey;
                }
            }
        }

        $pageBreakBefore = $isCover || $isPart0;

        return array(
            'is_cover' => $isCover,
            'is_part0' => $isPart0,
            'is_part_start' => $isPartStart,
            'is_chapter_start' => $isChapterStart,
            'is_major_section_start' => $isMajorSectionStart,
            'is_section_start' => $isPartStart || $isChapterStart || $isCover || $isPart0,
            'force_page_break_before' => $pageBreakBefore,
            'manual_part' => $manualPart,
            'pagination_authority' => $this->paginationAuthority($section),
            'is_generated' => !empty($section['is_generated']),
            'is_system_managed' => !empty($section['is_system_managed']),
            'allow_author_blocks' => !empty($section['allow_author_blocks']),
        );
    }

    /**
     * Part 0 and generated/system-owned content cannot receive Manual Page Breaks.
     * Part 0 editability does not change its automatic pagination authority.
     *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $hints
     */
    private function paginationAuthority(array $section, array $hints = array()): string
    {
        $key = strtolower(trim((string)($section['section_key'] ?? '')));
        $isPart0 = !empty($hints['is_part0'])
            || !empty($section['is_part0'])
            || in_array($key, self::PART0_SECTION_KEYS, true);
        if ($isPart0) {
            return 'generated';
        }
        $declared = strtolower(trim((string)($hints['pagination_authority'] ?? $section['pagination_authority'] ?? '')));
        if ($declared === 'generated' || $declared === 'author') {
            return $declared;
        }
        if (!empty($hints['is_system_managed'])) {
            return 'generated';
        }
        if (in_array($key, array('toc', 'lep'), true)) {
            return 'generated';
        }
        $allowAuthor = !empty($section['allow_author_blocks']);
        $systemOwned = !empty($section['is_system_managed']) || !empty($section['is_generated']);
        if ($systemOwned && !$allowAuthor) {
            return 'generated';
        }

        return 'author';
    }

    /**
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $blocks
     * @param array<int,array<string,mixed>> $byId
     */
    private function shouldSkipEmptyContainer(array $section, array $blocks, array $byId): bool
    {
        if ($blocks !== array()) {
            return false;
        }
        $key = (string)($section['section_key'] ?? '');
        if (in_array($key, array('cover', 'toc', 'lep'), true)) {
            return false;
        }
        if ($this->reader->paginationIsPart0ShellSection($section)) {
            return false;
        }
        $sectionId = (int)($section['id'] ?? 0);
        foreach ($byId as $row) {
            if ((int)($row['parent_section_id'] ?? 0) === $sectionId) {
                return true;
            }
        }

        return $key !== 'annexes';
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param array<string,mixed> $pageHeaderConfig
     * @param array<string,string> $tokenContext
     * @param array<string,mixed> $layout
     * @return array{header_template:string,footer_template:string,show_bands:bool}
     */
    private function extractHeaderFooterTemplates(
        array $version,
        array $section,
        array $pageHeaderConfig,
        array $tokenContext,
        array $layout
    ): array {
        $paginationHeaderConfig = $pageHeaderConfig;
        $existingOverrides = is_array($pageHeaderConfig['token_overrides'] ?? null)
            ? $pageHeaderConfig['token_overrides']
            : array();
        $paginationHeaderConfig['token_overrides'] = array_merge($existingOverrides, array(
            'page' => '{page}',
            'page_total' => '{page_total}',
        ));

        $shellHtml = $this->reader->paginationResolveHtml(
            $this->reader->paginationRenderSectionShellHtml(
                $version,
                $section,
                array(),
                $paginationHeaderConfig,
                $tokenContext
            ),
            $tokenContext
        );

        $header = '';
        $footer = '';
        if (preg_match('/<header class="cpb-page-header"[^>]*>.*?<\/header>/s', $shellHtml, $m) === 1) {
            $header = $m[0];
        }
        if (preg_match('/<footer class="cpb-page-footer"[^>]*>.*?<\/footer>/s', $shellHtml, $m) === 1) {
            $footer = $m[0];
        }

        $pageHeader = $this->reader->paginationPageHeader();
        $show = $pageHeader->shouldShowHeader(
            $section,
            is_array($pageHeaderConfig['page_header'] ?? null) ? $pageHeaderConfig['page_header'] : array(),
            is_array($pageHeaderConfig['page_footer'] ?? null) ? $pageHeaderConfig['page_footer'] : array(),
            $layout
        );

        return array(
            'header_template' => $header,
            'footer_template' => $footer,
            'show_bands' => $show && ($header !== '' || $footer !== ''),
        );
    }

    private function stripEditorChrome(string $html): string
    {
        return ControlledPublishingPublicationFilter::filterHtml($html);
    }

    /**
     * @return array{sheet_open:string,header:string,body:string,footer:string,uses_sheet_body:bool}
     */
    private function parseRenderedSheet(string $shellHtml): array
    {
        $parsed = ControlledPublishingPublicationFilter::parseSheet($shellHtml);
        $parsed['body'] = $this->sanitizeBodyHtml((string)($parsed['body'] ?? ''));

        return $parsed;
    }

    /**
     * Extract pagination units from canonical MODE_READ body HTML without rebuilding markup.
     *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $flags
     * @return list<array<string,mixed>>
     */
    private function unitsFromRenderedBody(string $bodyHtml, array $section, array $flags): array
    {
        $sectionId = (int)($section['id'] ?? 0);
        $sectionKey = (string)($section['section_key'] ?? '');
        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '' || trim(strip_tags($bodyHtml)) === '') {
            return array();
        }

        if ($sectionKey === 'toc' && str_contains($bodyHtml, 'cpb-toc-row')) {
            return $this->unitsFromTocBody($bodyHtml, $sectionId, $flags);
        }

        if (
            $sectionKey === 'lep'
            || str_contains($bodyHtml, 'cpb-lep-table')
            || str_contains($bodyHtml, 'data-lep-parts-table')
        ) {
            return $this->unitsFromLepBody($bodyHtml, $sectionId, $flags);
        }

        $units = array();
        $part0HeadingTexts = array();
        $part0HeadingPattern = '/<div class="[^"]*\bcpb-part0-heading\b[^"]*"[^>]*>.*?<\/div>/s';
        if (preg_match_all(
            $part0HeadingPattern,
            $bodyHtml,
            $headingMatches
        ) >= 1) {
            foreach ($headingMatches[0] as $headingIndex => $headingHtml) {
                $headingText = mb_strtolower(trim(preg_replace(
                    '/\s+/u',
                    ' ',
                    html_entity_decode(strip_tags($headingHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                ) ?? ''));
                if ($headingText !== '') {
                    $part0HeadingTexts[$headingText] = ($part0HeadingTexts[$headingText] ?? 0) + 1;
                }
                $paragraphStyle = 'subtitle_1';
                if (preg_match('/\bdata-paragraph-style="([^"]+)"/', $headingHtml, $styleMatch) === 1) {
                    $paragraphStyle = strtolower(trim(html_entity_decode(
                        (string)$styleMatch[1],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )));
                }
                $headingLevels = array(
                    'part_title' => 1,
                    'title' => 1,
                    'subtitle_1' => 2,
                    'subtitle_2' => 3,
                    'subtitle_3' => 4,
                    'subtitle_4' => 5,
                );
                $units[] = array(
                    'unit_key' => 'part0_heading_' . $sectionId . '_' . $headingIndex,
                    'block_id' => 0,
                    'stable_anchor' => (string)($section['stable_anchor'] ?? '')
                        . '-HEADING-' . ($headingIndex + 1),
                    'block_type' => 'heading',
                    'paragraph_style' => $paragraphStyle,
                    'heading_level' => (int)($headingLevels[$paragraphStyle] ?? 2),
                    'html' => $headingHtml,
                    'splittable' => false,
                    'atomic' => true,
                    'force_break_before' => false,
                    'is_heading' => true,
                    'is_system_managed' => true,
                    'pagination_authority' => 'generated',
                );
            }
        }
        // Part 0 headings are authoritative units in their own right. Leaving
        // them inside the generated body unit renders the same title twice.
        $bodyWithoutPart0Headings = trim(
            preg_replace($part0HeadingPattern, '', $bodyHtml) ?? $bodyHtml
        );
        if (preg_match_all('/<article class="cpb-block[^"]*"[^>]*>.*?<\/article>/s', $bodyHtml, $matches) >= 1) {
            $idx = 0;
            foreach ($matches[0] as $articleHtml) {
                $articleText = mb_strtolower(trim(preg_replace(
                    '/\s+/u',
                    ' ',
                    html_entity_decode(strip_tags($articleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                ) ?? ''));
                if ($articleText !== '' && ($part0HeadingTexts[$articleText] ?? 0) > 0) {
                    $part0HeadingTexts[$articleText]--;
                    continue;
                }
                $type = 'paragraph';
                if (preg_match('/cpb-block--([a-z_]+)/', $articleHtml, $tm) === 1) {
                    $type = $tm[1];
                }
                $paragraphStyle = '';
                if (preg_match('/\bdata-paragraph-style="([^"]+)"/', $articleHtml, $styleMatch) === 1) {
                    $paragraphStyle = strtolower(trim(html_entity_decode(
                        (string)$styleMatch[1],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )));
                } elseif (preg_match('/\bcpb-ps-([a-z0-9_]+)/i', $articleHtml, $styleClassMatch) === 1) {
                    $paragraphStyle = strtolower((string)$styleClassMatch[1]);
                }
                $headingLevels = array(
                    'part_title' => 1,
                    'title' => 1,
                    'subtitle_1' => 2,
                    'subtitle_2' => 3,
                    'subtitle_3' => 4,
                    'subtitle_4' => 5,
                    'heading_1' => 3,
                    'heading_2' => 4,
                );
                $headingLevel = (int)($headingLevels[$paragraphStyle] ?? 0);
                $isHeading = $type === 'heading' || $headingLevel > 0;
                $stableAnchor = '';
                if (preg_match('/\bdata-stable-anchor="([^"]+)"/', $articleHtml, $anchorMatch) === 1) {
                    $stableAnchor = html_entity_decode(
                        (string)$anchorMatch[1],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                }
                $blockId = 0;
                if (preg_match('/\bdata-block-id="(\d+)"/', $articleHtml, $blockMatch) === 1) {
                    $blockId = (int)$blockMatch[1];
                }
                $systemManaged = str_contains($articleHtml, 'data-system-managed="1"');
                $units[] = array(
                    'unit_key' => $stableAnchor !== '' ? $stableAnchor : 'b' . $sectionId . '_' . $idx,
                    'block_id' => $blockId,
                    'stable_anchor' => $stableAnchor,
                    'block_type' => $type,
                    'paragraph_style' => $paragraphStyle,
                    'heading_level' => $headingLevel,
                    'html' => $articleHtml,
                    'splittable' => in_array($type, self::SPLITTABLE_BLOCK_TYPES, true),
                    'atomic' => in_array($type, self::ATOMIC_BLOCK_TYPES, true) || $isHeading,
                    'force_break_before' => false,
                    'is_heading' => $isHeading,
                    'is_system_managed' => $systemManaged,
                    'pagination_authority' => $this->paginationAuthority(
                        $section,
                        array('is_system_managed' => $systemManaged)
                    ),
                );
                $idx++;
            }

            return $units;
        }

        $authority = $this->paginationAuthority($section);
        if ($bodyWithoutPart0Headings === '' || trim(strip_tags($bodyWithoutPart0Headings)) === '') {
            return $units;
        }
        $units[] = array(
            'unit_key' => 's' . $sectionId . '_0',
            'block_id' => 0,
            'block_type' => $authority === 'generated' ? 'generated' : 'shell',
            'html' => $bodyWithoutPart0Headings,
            'splittable' => false,
            'atomic' => $authority !== 'generated',
            'force_break_before' => false,
            'is_heading' => false,
            'pagination_authority' => $authority,
        );

        return $units;
    }

    /**
     * Split TOC at row boundaries while preserving editor nav markup.
     *
     * @param array<string,mixed> $flags
     * @return list<array<string,mixed>>
     */
    private function unitsFromTocBody(string $bodyHtml, int $sectionId, array $flags): array
    {
        if (trim($bodyHtml) === '') {
            return array();
        }

        // Preserve the complete generated TOC DOM. ReaderPaginationCore performs
        // exact browser measurement and splits it at real .cpb-toc-row
        // boundaries; pre-batching with regex/height estimates can create
        // malformed rows and overflow the fixed body frame.
        return array(array(
            'unit_key' => 'toc' . $sectionId . '_0',
            'block_id' => 0,
            'block_type' => 'toc',
            'html' => $bodyHtml,
            'splittable' => false,
            'atomic' => false,
            'force_break_before' => false,
            'is_heading' => false,
            'pagination_authority' => 'generated',
        ));
    }

    /**
     * Preserve generated LEP as one logical object. ReaderPaginationCore
     * measures it in-browser and continues at real .cpb-lep-part-row
     * boundaries. Continuation pages are not persisted as Manual Page Breaks.
     *
     * @param array<string,mixed> $flags
     * @return list<array<string,mixed>>
     */
    private function unitsFromLepBody(string $bodyHtml, int $sectionId, array $flags): array
    {
        if (trim($bodyHtml) === '') {
            return array();
        }

        return array(array(
            'unit_key' => 'lep' . $sectionId . '_0',
            'block_id' => 0,
            'block_type' => 'lep',
            'html' => $bodyHtml,
            'splittable' => false,
            'atomic' => false,
            'force_break_before' => false,
            'is_heading' => false,
            'pagination_authority' => 'generated',
        ));
    }

    /**
     * Generate a deterministic frozen page map for a released book version.
     *
     * @return list<array<string,mixed>>
     */
    public function generateFrozenPageMap(string $bookKey, ?array $version = null): array
    {
        $resolvedVersion = $version ?? $this->reader->requireReleasedVersion($bookKey);
        $source = $this->reader->loadReaderPaginateSource($resolvedVersion);
        $result = (new ControlledPublishingAuthoritativePaginationService($this->reader))
            ->generate($source, $resolvedVersion);
        $this->lastGeneration = $result['generation'];
        foreach ($result['pages'] as &$page) {
            $metadata = is_array($page['metadata'] ?? null) ? $page['metadata'] : array();
            $metadata['publication_generation'] = $this->lastGeneration;
            $page['metadata'] = $metadata;
        }
        unset($page);

        return $result['pages'];
    }

    /**
     * @return array<string,mixed>
     */
    public function lastGeneration(): array
    {
        return $this->lastGeneration;
    }

    private function sanitizeBodyHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<header class="cpb-page-header"[^>]*>.*?<\/header>/s', '', $html) ?? $html;
        $html = preg_replace('/<footer class="cpb-page-footer"[^>]*>.*?<\/footer>/s', '', $html) ?? $html;

        return trim($html);
    }

}
