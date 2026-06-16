<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingReaderService.php';
require_once __DIR__ . '/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';

/**
 * Deterministic page-map generator for authoritative OM/OMM manuals.
 *
 * Admin/compliance context only — produces frozen page HTML against a fixed
 * layout profile. Student readers must never call generate methods.
 */
final class ControlledPublishingPaginationService
{
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
    private const ATOMIC_BLOCK_TYPES = array('table', 'image', 'callout', 'toc', 'list');

    /** @var list<string> */
    private const SPLITTABLE_BLOCK_TYPES = array('paragraph', 'heading');

    public function __construct(private ControlledPublishingReaderService $reader)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildPaginateSource(string $bookKey): array
    {
        $version = $this->reader->requireReleasedVersion($bookKey);
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
            }

            if ($entry['content_mode'] === 'cover' || ($entry['units'] ?? array()) !== array()) {
                $sectionsOut[] = $entry;
            }
        }

        $manualCode = trim((string)($version['manual_code'] ?? ''));
        if ($manualCode === '') {
            $manualCode = (string)($version['book_key'] ?? $bookKey);
        }

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
            'nav' => $this->reader->buildReaderNavTree($bookKey),
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

        $meta = $this->decodeMeta($section);
        $pageBreakBefore = !empty($meta['page_break_before'])
            || $isCover
            || $isPartStart
            || $isChapterStart
            || $isPart0;

        return array(
            'is_cover' => $isCover,
            'is_part0' => $isPart0,
            'is_part_start' => $isPartStart,
            'is_chapter_start' => $isChapterStart,
            'is_major_section_start' => $isMajorSectionStart,
            'is_section_start' => $isPartStart || $isChapterStart || $isCover || $isPart0,
            'force_page_break_before' => $pageBreakBefore,
            'manual_part' => $manualPart,
        );
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
        $html = preg_replace('/<div class="cpb-page-layout-toggle"[^>]*>.*?<\/div>/s', '', $html) ?? $html;
        $html = preg_replace('/<div class="cpb-dropzone"[^>]*>.*?<\/div>/s', '', $html) ?? $html;
        $html = preg_replace('/\scontenteditable="(?:true|false)"/i', '', $html) ?? $html;

        return $html;
    }

    /**
     * @return array{sheet_open:string,header:string,body:string,footer:string,uses_sheet_body:bool}
     */
    private function parseRenderedSheet(string $shellHtml): array
    {
        $sheetOpen = '';
        if (preg_match('/<div class="cpb-sheet[^"]*"[^>]*>/', $shellHtml, $m) === 1) {
            $sheetOpen = $m[0];
        }

        $header = '';
        if (preg_match('/<header class="cpb-page-header"[^>]*>.*?<\/header>/s', $shellHtml, $m) === 1) {
            $header = $m[0];
        }

        $footer = '';
        if (preg_match('/<footer class="cpb-page-footer"[^>]*>.*?<\/footer>/s', $shellHtml, $m) === 1) {
            $footer = $m[0];
        }

        $usesSheetBody = str_contains($shellHtml, 'cpb-sheet-body');
        $body = '';

        if ($usesSheetBody && preg_match('/<div class="cpb-sheet-body"[^>]*>(.*)<\/div>\s*(?:<footer)/s', $shellHtml, $m) === 1) {
            $body = $m[1];
        } elseif (preg_match('/<div class="cpb-sheet[^"]*"[^>]*>(.*)<\/div>\s*$/s', trim($shellHtml), $m) === 1) {
            $inner = $m[1];
            if ($header !== '') {
                $inner = preg_replace('/^\s*' . preg_quote($header, '/') . '/s', '', $inner) ?? $inner;
            }
            if ($footer !== '') {
                $inner = preg_replace('/\s*' . preg_quote($footer, '/') . '\s*$/s', '', $inner) ?? $inner;
            }
            $body = trim($inner);
        }

        return array(
            'sheet_open' => $sheetOpen,
            'header' => $header,
            'body' => $body,
            'footer' => $footer,
            'uses_sheet_body' => $usesSheetBody,
        );
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

        $units = array();
        if (preg_match_all('/<article class="cpb-block[^"]*"[^>]*>.*?<\/article>/s', $bodyHtml, $matches) >= 1) {
            $idx = 0;
            foreach ($matches[0] as $articleHtml) {
                $type = 'paragraph';
                if (preg_match('/cpb-block--([a-z_]+)/', $articleHtml, $tm) === 1) {
                    $type = $tm[1];
                }
                $isHeading = $type === 'heading';
                $units[] = array(
                    'unit_key' => 'b' . $sectionId . '_' . $idx,
                    'block_id' => 0,
                    'block_type' => $type,
                    'html' => $articleHtml,
                    'splittable' => in_array($type, self::SPLITTABLE_BLOCK_TYPES, true),
                    'atomic' => in_array($type, self::ATOMIC_BLOCK_TYPES, true) || $isHeading,
                    'force_break_before' => $idx === 0 && !empty($flags['force_page_break_before']),
                    'is_heading' => $isHeading,
                );
                $idx++;
            }

            return $units;
        }

        $units[] = array(
            'unit_key' => 's' . $sectionId . '_0',
            'block_id' => 0,
            'block_type' => 'shell',
            'html' => $bodyHtml,
            'splittable' => false,
            'atomic' => true,
            'force_break_before' => !empty($flags['force_page_break_before']),
            'is_heading' => false,
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
        if (preg_match_all('/<div class="cpb-toc-row[^"]*"[^>]*>.*?<\/div>/s', $bodyHtml, $rows) < 1) {
            return array();
        }

        $navPrefix = '';
        if (preg_match('/<nav class="cpb-toc"[^>]*>/', $bodyHtml, $navOpen) === 1) {
            $navPrefix = $navOpen[0];
        }

        $profile = ControlledPublishingReaderLayoutProfile::spec();
        $bodyCapacity = (int)$profile['body_capacity_px'];
        $units = array();
        $idx = 0;
        $batch = array();

        $wrapNav = static function (array $rowBatch) use ($navPrefix): string {
            $open = $navPrefix !== '' ? $navPrefix : '<nav class="cpb-toc" aria-label="Table of contents">';
            return $open . implode('', $rowBatch) . '</nav>';
        };

        $flush = function () use (&$units, &$idx, &$batch, $sectionId, $flags, $wrapNav): void {
            if ($batch === array()) {
                return;
            }
            $units[] = array(
                'unit_key' => 'toc' . $sectionId . '_' . $idx,
                'block_id' => 0,
                'block_type' => 'toc',
                'html' => $wrapNav($batch),
                'splittable' => false,
                'atomic' => true,
                'force_break_before' => $idx === 0 && !empty($flags['force_page_break_before']),
                'is_heading' => false,
            );
            $idx++;
            $batch = array();
        };

        foreach ($rows[0] as $row) {
            $trial = $wrapNav(array_merge($batch, array($row)));
            if ($batch !== array() && $this->estimateHtmlHeight($trial) > $bodyCapacity) {
                $flush();
            }
            $batch[] = $row;
        }
        $flush();

        return $units;
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function decodeMeta(array $section): array
    {
        $raw = $section['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Generate a deterministic frozen page map for a released book version.
     *
     * @return list<array<string,mixed>>
     */
    public function generateFrozenPageMap(string $bookKey): array
    {
        $source = $this->buildPaginateSource($bookKey);
        $draftPages = $this->paginateSourceDeterministic($source);
        $sectionPageIndex = $this->buildSectionFirstPageIndex($draftPages);

        foreach ($draftPages as $idx => $page) {
            if (str_contains((string)($page['body_html'] ?? ''), 'cpb-toc-row')) {
                $draftPages[$idx]['body_html'] = $this->injectTocPageNumbers(
                    (string)$page['body_html'],
                    $sectionPageIndex
                );
            }
        }

        $total = count($draftPages);

        $stored = array();
        foreach ($draftPages as $idx => $page) {
            $pageNum = $idx + 1;
            $pageHtml = $this->assembleFrozenPageHtml($page, $pageNum, $total);
            $stored[] = array(
                'page_number' => $pageNum,
                'section_id' => $page['section_id'],
                'stable_anchor' => $page['section_stable_anchor'] ?? null,
                'page_type' => !empty($page['is_cover']) ? 'cover' : 'content',
                'is_cover' => !empty($page['is_cover']),
                'is_section_start' => !empty($page['is_section_start']),
                'is_major_section_start' => !empty($page['is_major_section_start']),
                'page_html' => $pageHtml,
                'thumbnail_html' => null,
                'metadata' => array(
                    'section_title' => (string)($page['section_title'] ?? ''),
                    'manual_part' => $page['manual_part'] ?? null,
                    'part_title' => (string)($page['part_title'] ?? ''),
                ),
            );
        }

        return $stored;
    }

    /**
     * @param array<string,mixed> $source
     * @return list<array<string,mixed>>
     */
    private function paginateSourceDeterministic(array $source): array
    {
        $profile = ControlledPublishingReaderLayoutProfile::spec();
        $bodyCapacity = (int)$profile['body_capacity_px'];
        $pages = array();
        $current = null;

        $finalizePage = function (?array $page) use (&$pages): void {
            if ($page === null || (($page['body_html'] ?? '') === '' && empty($page['is_cover']))) {
                return;
            }
            $pages[] = $page;
        };

        $newContentPage = function (array $section, array $flags) use ($source): array {
            return array(
                'book_version_id' => (int)($source['version_id'] ?? 0),
                'section_id' => (int)$section['section_id'],
                'section_key' => (string)($section['section_key'] ?? ''),
                'section_stable_anchor' => (string)($section['stable_anchor'] ?? ''),
                'section_title' => (string)($section['title'] ?? ''),
                'manual_part' => $section['manual_part'] ?? null,
                'part_title' => (string)($section['part_title'] ?? ''),
                'sheet_open' => (string)($section['sheet_open'] ?? '<div class="cpb-sheet">'),
                'uses_sheet_body' => !empty($section['uses_sheet_body']),
                'is_cover' => false,
                'is_section_start' => !empty($flags['is_section_start']),
                'is_major_section_start' => !empty($flags['is_major_section_start']),
                'header_template' => !empty($section['show_header_footer']) ? (string)$section['header_template'] : '',
                'footer_template' => !empty($section['show_header_footer']) ? (string)$section['footer_template'] : '',
                'body_html' => '',
                'body_parts' => array(),
            );
        };

        $tryAppendHtml = function (array &$page, string $html) use ($bodyCapacity): bool {
            $combined = implode('', $page['body_parts']) . $html;
            if ($this->estimateHtmlHeight($combined) <= $bodyCapacity) {
                $page['body_parts'][] = $html;
                $page['body_html'] = $combined;

                return true;
            }

            return false;
        };

        foreach ($source['sections'] ?? array() as $section) {
            if (($section['content_mode'] ?? '') === 'cover') {
                $finalizePage($current);
                $current = null;
                $pages[] = array(
                    'book_version_id' => (int)($source['version_id'] ?? 0),
                    'section_id' => (int)$section['section_id'],
                    'section_key' => (string)($section['section_key'] ?? ''),
                    'section_stable_anchor' => (string)($section['stable_anchor'] ?? ''),
                    'section_title' => (string)($section['title'] ?? ''),
                    'manual_part' => $section['manual_part'] ?? null,
                    'part_title' => (string)($section['part_title'] ?? ''),
                    'is_cover' => true,
                    'is_section_start' => true,
                    'is_major_section_start' => true,
                    'header_template' => '',
                    'footer_template' => '',
                    'body_html' => (string)($section['cover_html'] ?? ''),
                    'body_parts' => array((string)($section['cover_html'] ?? '')),
                );
                continue;
            }

            $units = $section['units'] ?? array();
            if ($units === array()) {
                continue;
            }

            foreach ($units as $unitIdx => $unit) {
                if (!is_array($unit)) {
                    continue;
                }

                if ($unitIdx === 0 && $current !== null && trim((string)($current['body_html'] ?? '')) !== '') {
                    $finalizePage($current);
                    $current = null;
                }

                $forceBreak = !empty($unit['force_break_before'])
                    || ($unitIdx === 0 && !empty($section['flags']['force_page_break_before']));
                if ($forceBreak && $current !== null) {
                    $finalizePage($current);
                    $current = null;
                }

                $sectionFlags = array(
                    'is_section_start' => $unitIdx === 0 && !empty($section['flags']['is_section_start']),
                    'is_major_section_start' => $unitIdx === 0 && !empty($section['flags']['is_major_section_start']),
                );

                if (!empty($unit['is_heading']) && !empty($unit['atomic'])) {
                    if ($current !== null && !$tryAppendHtml($current, (string)($unit['html'] ?? ''))) {
                        $finalizePage($current);
                        $current = null;
                    }
                }

                if ($current === null) {
                    $current = $newContentPage($section, $sectionFlags);
                }

                $html = (string)($unit['html'] ?? '');
                $splittable = !empty($unit['splittable'])
                    && in_array((string)($unit['block_type'] ?? ''), self::SPLITTABLE_BLOCK_TYPES, true);

                if ($splittable) {
                    foreach ($this->splitParagraphHtml($html) as $frag) {
                        if (!$tryAppendHtml($current, $frag)) {
                            $finalizePage($current);
                            $current = $newContentPage($section, array(
                                'is_section_start' => false,
                                'is_major_section_start' => false,
                            ));
                            if (!$tryAppendHtml($current, $frag)) {
                                $current['body_parts'][] = $frag;
                                $current['body_html'] = $frag;
                            }
                        }
                    }
                } else {
                if (!$tryAppendHtml($current, $html)) {
                    $finalizePage($current);
                    $current = $newContentPage($section, array(
                        'is_section_start' => false,
                        'is_major_section_start' => false,
                    ));
                        if (!$tryAppendHtml($current, $html)) {
                            $current['body_parts'][] = $html;
                            $current['body_html'] = $html;
                        }
                    }
                }
            }
        }

        $finalizePage($current);

        return $pages;
    }

    /**
     * @param array<string,mixed> $page
     */
    private function assembleFrozenPageHtml(array $page, int $pageNum, int $total): string
    {
        if (!empty($page['is_cover'])) {
            return $this->applyPageTokens((string)($page['body_html'] ?? ''), $pageNum, $total);
        }

        $sheetOpen = (string)($page['sheet_open'] ?? '<div class="cpb-sheet">');
        if (preg_match('/\bdata-reader-page="/', $sheetOpen) !== 1) {
            $sheetOpen = preg_replace(
                '/^<div /',
                '<div data-reader-page="' . $pageNum . '" ',
                $sheetOpen,
                1
            ) ?? $sheetOpen;
        }

        $header = $this->applyPageTokens((string)($page['header_template'] ?? ''), $pageNum, $total);
        $body = (string)($page['body_html'] ?? '');
        $footer = $this->applyPageTokens((string)($page['footer_template'] ?? ''), $pageNum, $total);

        if (!empty($page['uses_sheet_body'])) {
            $body = '<div class="cpb-sheet-body" data-blocks-root="1">' . $body . '</div>';
        }

        return $sheetOpen
            . $header
            . $body
            . $footer
            . '</div>';
    }

    private function applyPageTokens(string $html, int $pageNum, int $total): string
    {
        $replacements = array(
            '{{page}}' => (string)$pageNum,
            '{page}' => (string)$pageNum,
            '{{page_total}}' => (string)$total,
            '{page_total}' => (string)$total,
            'Page: —' => 'Page: ' . $pageNum,
            'Page:&nbsp;—' => 'Page: ' . $pageNum,
        );

        return strtr($html, $replacements);
    }

    /**
     * @return list<string>
     */
    private function splitParagraphHtml(string $html): array
    {
        $profile = ControlledPublishingReaderLayoutProfile::spec();
        $chunkSize = (int)$profile['split_words_per_chunk'];

        if (trim(strip_tags($html)) === '') {
            return array($html);
        }

        if (preg_match('/<p[^>]*>/i', $html) === 1) {
            $parts = array();
            $segments = preg_split('/<\/p>/i', $html) ?: array();
            foreach ($segments as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $piece = stripos($chunk, '<p') !== false ? $chunk . '</p>' : '<p>' . $chunk . '</p>';
                $parts[] = $piece;
            }
            if ($parts !== array()) {
                return $parts;
            }
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', trim($plain)) ?? '';
        if ($plain === '') {
            return array($html);
        }

        $words = preg_split('/\s+/u', $plain) ?: array();
        if (count($words) <= 1) {
            return array($html);
        }

        $segments = array();
        $chunk = array();
        foreach ($words as $word) {
            $chunk[] = $word;
            if (count($chunk) >= $chunkSize) {
                $segments[] = implode(' ', $chunk);
                $chunk = array();
            }
        }
        if ($chunk !== array()) {
            $segments[] = implode(' ', $chunk);
        }

        $parts = array();
        foreach ($segments as $seg) {
            $escaped = htmlspecialchars($seg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/class="[^"]*cpb-block[^"]*"/', $html) === 1) {
                $parts[] = preg_replace('/>([^<]*)<\//', '>' . $escaped . '</', $html, 1) ?? $html;
            } else {
                $parts[] = '<p>' . $escaped . '</p>';
            }
        }

        return $parts !== array() ? $parts : array($html);
    }

    private function estimateHtmlHeight(string $html): int
    {
        if (trim(strip_tags($html)) === '') {
            return 0;
        }

        $profile = ControlledPublishingReaderLayoutProfile::spec();
        $lineHeight = (int)$profile['line_height_px'];

        if (preg_match('/cpb-toc-row/i', $html) === 1) {
            $rows = preg_match_all('/cpb-toc-row/i', $html) ?: 1;

            return max(1, $rows) * 22 + 8;
        }

        if (preg_match('/<table/i', $html) === 1) {
            $rows = preg_match_all('/<tr/i', $html) ?: 0;

            return 28 + max(1, $rows) * 22 + 12;
        }

        if (preg_match('/<img/i', $html) === 1) {
            $h = 180;
            if (preg_match('/height\s*[:=]\s*["\']?(\d+)/i', $html, $m) === 1) {
                $h = (int)$m[1];
            }

            return min(400, max(80, $h)) + 16;
        }

        if (preg_match('/cpb-block--heading|cpb-heading|<h[1-6]/i', $html) === 1) {
            $lines = $this->estimateTextLines($html);
            $level = 2;
            if (preg_match('/cpb-heading--(\d)|level-(\d)/', $html, $m) === 1) {
                $level = (int)($m[1] ?: $m[2] ?: 2);
            }
            $base = match ($level) {
                1 => 28,
                2 => 24,
                3 => 20,
                default => 18,
            };

            return $base + max(0, $lines - 1) * $lineHeight + 12;
        }

        if (preg_match('/<ul|<ol|cpb-block--list/i', $html) === 1) {
            $items = preg_match_all('/<li/i', $html) ?: 1;

            return $items * $lineHeight + 16;
        }

        if (preg_match('/cpb-block--callout|cpb-callout/i', $html) === 1) {
            return 24 + $this->estimateTextLines($html) * $lineHeight + 12;
        }

        $lines = max(1, $this->estimateTextLines($html));

        return $lines * $lineHeight + 8;
    }

    private function estimateTextLines(string $html): int
    {
        $profile = ControlledPublishingReaderLayoutProfile::spec();
        $charsPerLine = (int)$profile['chars_per_line'];
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if ($text === '') {
            return 0;
        }

        return (int)ceil(mb_strlen($text, 'UTF-8') / max(1, $charsPerLine));
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @return array<int,int>
     */
    private function buildSectionFirstPageIndex(array $pages): array
    {
        $index = array();
        foreach ($pages as $idx => $page) {
            $sectionId = (int)($page['section_id'] ?? 0);
            if ($sectionId <= 0 || isset($index[$sectionId])) {
                continue;
            }
            $index[$sectionId] = $idx + 1;
        }

        return $index;
    }

    /**
     * @param array<int,int> $sectionPageIndex
     */
    private function injectTocPageNumbers(string $html, array $sectionPageIndex): string
    {
        if (!str_contains($html, 'cpb-toc-row')) {
            return $html;
        }

        $updated = preg_replace_callback(
            '/<div class="cpb-toc-row[^"]*"[^>]*>.*?<\/div>/s',
            function (array $match) use ($sectionPageIndex): string {
                $row = $match[0];
                $pageText = '—';
                if (preg_match('/data-section-id="(\d+)"/', $row, $sid) === 1) {
                    $pageNum = $sectionPageIndex[(int)$sid[1]] ?? null;
                    if ($pageNum !== null && $pageNum > 0) {
                        $pageText = (string)$pageNum;
                    }
                }
                $escaped = htmlspecialchars($pageText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $replaced = preg_replace(
                    '/(<span class="cpb-toc-page" data-toc-page=")[^"]*(">)[^<]*(<\/span>)/s',
                    '$1' . $escaped . '$2' . $escaped . '$3',
                    $row
                );

                return is_string($replaced) ? $replaced : $row;
            },
            $html
        );

        return is_string($updated) ? $updated : $html;
    }
}
