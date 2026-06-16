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

        $sectionsOut = array();
        foreach ($flat as $section) {
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
                'token_context' => $this->reader->paginationTokenContext($version, $section, array(
                    'part_title' => $partTitle,
                    'page' => '—',
                    'page_total' => '—',
                )),
            );

            if ($flags['is_cover']) {
                $coverHtml = $this->reader->paginationRenderSectionShellHtml(
                    $version,
                    $section,
                    $sectionBlocks,
                    $pageHeaderConfig,
                    $this->reader->paginationTokenContext($version, $section, array(
                        'part_title' => $partTitle,
                        'page' => '1',
                        'page_total' => '1',
                    ))
                );
                $entry['content_mode'] = 'cover';
                $entry['cover_html'] = $coverHtml;
                $entry['units'] = array();
            } elseif ($this->shouldSkipEmptyContainer($section, $sectionBlocks, $byId)) {
                continue;
            } else {
                $entry['content_mode'] = 'units';
                $entry['cover_html'] = null;
                $entry['units'] = $this->buildContentUnits(
                    $version,
                    $section,
                    $sectionBlocks,
                    $pageHeaderConfig,
                    $tokenContext,
                    $flags
                );
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
            || $isChapterStart;

        return array(
            'is_cover' => $isCover,
            'is_part_start' => $isPartStart,
            'is_chapter_start' => $isChapterStart,
            'is_major_section_start' => $isMajorSectionStart,
            'is_section_start' => $isPartStart || $isChapterStart || $isCover,
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

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $sectionBlocks
     * @param array<string,mixed> $pageHeaderConfig
     * @param array<string,string> $tokenContext
     * @param array<string,mixed> $flags
     * @return list<array<string,mixed>>
     */
    private function buildContentUnits(
        array $version,
        array $section,
        array $sectionBlocks,
        array $pageHeaderConfig,
        array $tokenContext,
        array $flags
    ): array {
        if ($this->reader->paginationIsPart0ShellSection($section)
            || in_array((string)($section['section_key'] ?? ''), array('lep', 'toc'), true)
            || str_starts_with((string)($section['section_key'] ?? ''), 'annexes_')
        ) {
            return $this->unitsFromShellHtml(
                $version,
                $section,
                $sectionBlocks,
                $pageHeaderConfig,
                $tokenContext,
                $flags
            );
        }

        $units = array();
        $first = true;
        foreach ($sectionBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string)($block['block_type'] ?? 'paragraph');
            if ($type === 'generated_placeholder') {
                continue;
            }

            $html = $this->reader->paginationResolveHtml(
                $this->reader->paginationRenderBlock($block),
                $tokenContext
            );
            if (trim(strip_tags($html)) === '' && $type !== 'image') {
                continue;
            }

            $isHeading = $type === 'heading';
            $units[] = array(
                'unit_key' => 'b' . (int)($block['id'] ?? 0),
                'block_id' => (int)($block['id'] ?? 0),
                'block_type' => $type,
                'html' => $html,
                'splittable' => in_array($type, self::SPLITTABLE_BLOCK_TYPES, true),
                'atomic' => in_array($type, self::ATOMIC_BLOCK_TYPES, true) || $isHeading,
                'force_break_before' => $first && !empty($flags['force_page_break_before']),
                'is_heading' => $isHeading,
            );
            $first = false;
        }

        return $units;
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $sectionBlocks
     * @param array<string,mixed> $pageHeaderConfig
     * @param array<string,string> $tokenContext
     * @param array<string,mixed> $flags
     * @return list<array<string,mixed>>
     */
    private function unitsFromShellHtml(
        array $version,
        array $section,
        array $sectionBlocks,
        array $pageHeaderConfig,
        array $tokenContext,
        array $flags
    ): array {
        $shellHtml = $this->reader->paginationResolveHtml(
            $this->reader->paginationRenderSectionShellHtml(
                $version,
                $section,
                $sectionBlocks,
                $pageHeaderConfig,
                $tokenContext
            ),
            $tokenContext
        );

        $bodyHtml = $shellHtml;
        if (preg_match('/<div class="cpb-sheet-body"[^>]*>(.*)<\/div>\s*(?:<div class="cpb-dropzone|<footer)/s', $shellHtml, $m) === 1) {
            $bodyHtml = $m[1];
        } elseif (preg_match('/<div class="cpb-part0-admin-body"[^>]*>(.*)<\/div>/s', $shellHtml, $m) === 1) {
            $bodyHtml = $m[1];
        } elseif (preg_match('/<div class="cpb-lep-body"[^>]*>(.*)<\/div>/s', $shellHtml, $m) === 1) {
            $bodyHtml = $m[1];
        }

        $units = array();
        $wrap = '<div id="mr-shell-root">' . $bodyHtml . '</div>';
        if (preg_match_all('/<article class="cpb-block[^"]*"[^>]*>.*?<\/article>/s', $wrap, $matches) >= 1) {
            $idx = 0;
            foreach ($matches[0] as $articleHtml) {
                $type = 'paragraph';
                if (preg_match('/cpb-block--([a-z_]+)/', $articleHtml, $tm) === 1) {
                    $type = $tm[1];
                }
                $units[] = array(
                    'unit_key' => 's' . (int)$section['id'] . '_' . $idx,
                    'block_id' => 0,
                    'block_type' => $type,
                    'html' => $articleHtml,
                    'splittable' => in_array($type, self::SPLITTABLE_BLOCK_TYPES, true),
                    'atomic' => in_array($type, self::ATOMIC_BLOCK_TYPES, true) || $type === 'heading',
                    'force_break_before' => $idx === 0 && !empty($flags['force_page_break_before']),
                    'is_heading' => $type === 'heading',
                );
                $idx++;
            }
        }

        if ($units === array() && trim(strip_tags($bodyHtml)) !== '') {
            $units[] = array(
                'unit_key' => 's' . (int)$section['id'] . '_0',
                'block_id' => 0,
                'block_type' => 'shell',
                'html' => '<div class="mr-shell-block">' . $bodyHtml . '</div>',
                'splittable' => true,
                'atomic' => false,
                'force_break_before' => !empty($flags['force_page_break_before']),
                'is_heading' => false,
            );
        }

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
                'thumbnail_html' => $this->buildThumbnailHtml($page, $pageNum, $total),
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
                'section_stable_anchor' => (string)($section['stable_anchor'] ?? ''),
                'section_title' => (string)($section['title'] ?? ''),
                'manual_part' => $section['manual_part'] ?? null,
                'part_title' => (string)($section['part_title'] ?? ''),
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

                if (!empty($unit['is_heading']) && !empty($unit['atomic']) && $unitIdx === 0) {
                    $finalizePage($current);
                    $current = $newContentPage($section, $sectionFlags);
                } elseif ($current === null) {
                    $current = $newContentPage($section, $sectionFlags);
                }

                $html = (string)($unit['html'] ?? '');
                $splittable = !empty($unit['splittable'])
                    && in_array((string)($unit['block_type'] ?? ''), array('paragraph', 'heading', 'shell'), true);

                if ($splittable) {
                    foreach ($this->splitParagraphHtml($html) as $frag) {
                        if (!$tryAppendHtml($current, $frag)) {
                            $finalizePage($current);
                            $current = $newContentPage($section, $sectionFlags);
                            if (!$tryAppendHtml($current, $frag)) {
                                $current['body_parts'][] = $frag;
                                $current['body_html'] = $frag;
                            }
                        }
                    }
                } else {
                    if (!$tryAppendHtml($current, $html)) {
                        $finalizePage($current);
                        $current = $newContentPage($section, $sectionFlags);
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
        $style = ControlledPublishingReaderLayoutProfile::frozenPageInlineStyle();
        $coverClass = !empty($page['is_cover']) ? ' mr-frozen-page--cover' : '';

        if (!empty($page['is_cover'])) {
            return '<div class="mr-frozen-page' . $coverClass . '" data-page="' . $pageNum . '" style="' . $style . '">'
                . '<div class="mr-gen-body mr-gen-body--cover">' . (string)($page['body_html'] ?? '') . '</div>'
                . '</div>';
        }

        $header = $this->applyPageTokens((string)($page['header_template'] ?? ''), $pageNum, $total);
        $body = (string)($page['body_html'] ?? '');
        $footer = $this->applyPageTokens((string)($page['footer_template'] ?? ''), $pageNum, $total);

        return '<div class="mr-frozen-page' . $coverClass . '" data-page="' . $pageNum . '" style="' . $style . '">'
            . ($header !== '' ? '<div class="mr-gen-header">' . $header . '</div>' : '')
            . '<div class="mr-gen-body">' . $body . '</div>'
            . ($footer !== '' ? '<div class="mr-gen-footer">' . $footer . '</div>' : '')
            . '</div>';
    }

    /**
     * Compact filmstrip thumbnail (does not duplicate full page_html — keeps row size small).
     *
     * @param array<string,mixed> $page
     */
    private function buildThumbnailHtml(array $page, int $pageNum, int $total): string
    {
        $profile = ControlledPublishingReaderLayoutProfile::spec();
        $w = (int)round((int)$profile['page_width_px'] * 0.12);
        $h = (int)round((int)$profile['page_height_px'] * 0.12);
        $title = htmlspecialchars(
            mb_substr((string)($page['section_title'] ?? ''), 0, 36, 'UTF-8'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        if (!empty($page['is_cover'])) {
            return '<div class="mr-frozen-thumb mr-frozen-thumb--cover" style="width:' . $w . 'px;height:' . $h
                . 'px;display:flex;align-items:center;justify-content:center;font-size:9px;background:#fff;">Cover</div>';
        }

        return '<div class="mr-frozen-thumb" style="width:' . $w . 'px;height:' . $h
            . 'px;display:flex;flex-direction:column;justify-content:space-between;padding:4px;'
            . 'box-sizing:border-box;background:#fff;border:1px solid #ddd;font-size:8px;line-height:1.2;">'
            . '<span style="opacity:0.7;">' . $title . '</span>'
            . '<span style="text-align:right;font-weight:600;">' . $pageNum . ' / ' . $total . '</span>'
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
}
