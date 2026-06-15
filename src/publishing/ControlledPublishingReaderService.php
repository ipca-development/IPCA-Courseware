<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookRenderer.php';
require_once __DIR__ . '/ControlledPublishingRevisionService.php';
require_once __DIR__ . '/ControlledPublishingSectionLayoutService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingSectionNumberService.php';
require_once __DIR__ . '/ControlledPublishingPageHeaderService.php';
require_once __DIR__ . '/ControlledPublishingCoverPageService.php';
require_once __DIR__ . '/ControlledPublishingLepService.php';
require_once __DIR__ . '/ControlledPublishingApprovalService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/ControlledPublishingEditorNavService.php';
require_once __DIR__ . '/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/ControlledPublishingAnnexService.php';

/**
 * Read-only manual e-reader backed by released ipca_publishing_* content only.
 */
final class ControlledPublishingReaderService
{
    private ?ControlledPublishingFoundationService $foundation = null;
    private ?ControlledPublishingSectionService $sections = null;
    private ?ControlledPublishingBlockService $blocks = null;
    private ?ControlledPublishingBookRenderer $renderer = null;
    private ?ControlledPublishingRevisionService $revision = null;
    private ?ControlledPublishingSectionLayoutService $layoutSvc = null;
    private ?ControlledPublishingBookStyleService $styleSvc = null;
    private ?ControlledPublishingSectionNumberService $numberSvc = null;
    private ?ControlledPublishingPageHeaderService $pageHeaderSvc = null;
    private ?ControlledPublishingCoverPageService $coverPageSvc = null;
    private ?ControlledPublishingLepService $lepPageSvc = null;
    private ?ControlledPublishingApprovalService $approvalSvc = null;
    private ?ControlledPublishingPart0PageService $part0PageSvc = null;
    private ?ControlledPublishingEditorNavService $editorNavSvc = null;
    private ?ControlledPublishingManualStructureService $manualStructureSvc = null;
    private ?ControlledPublishingAnnexService $annexSvc = null;

    private static ?bool $progressTableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActiveReleasedLibrary(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, book_key, title, manual_code, status
            FROM ipca_publishing_books
            WHERE status = 'active'
            ORDER BY book_key
        ");
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $library = array();

        foreach ($books as $book) {
            $bookKey = (string)($book['book_key'] ?? '');
            $version = $this->resolveLatestReleasedVersion($bookKey);
            if ($version === null) {
                continue;
            }
            $library[] = array(
                'book_id' => (int)($book['id'] ?? 0),
                'book_key' => $bookKey,
                'book_title' => (string)($book['title'] ?? ''),
                'manual_code' => (string)($book['manual_code'] ?? ''),
                'version_id' => (int)($version['id'] ?? 0),
                'version_label' => (string)($version['version_label'] ?? ''),
                'effective_date' => $version['effective_date'] ?? null,
                'released_at' => $version['released_at'] ?? null,
            );
        }

        return $library;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveLatestReleasedVersion(string $bookKey): ?array
    {
        $bookKey = strtoupper(trim($bookKey));
        if ($bookKey === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
              bv.*,
              b.book_key,
              b.title AS book_title,
              b.manual_code
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            WHERE b.book_key = :book_key
              AND b.status = 'active'
              AND bv.lifecycle_status = 'released'
            ORDER BY bv.released_at DESC, bv.id DESC
            LIMIT 1
        ");
        $stmt->execute(array(':book_key' => $bookKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function buildReaderNavTree(string $bookKey): array
    {
        $version = $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $tree = $this->editorNav()->buildNavTree($versionId, (string)($version['book_key'] ?? $bookKey));

        return $this->sanitizeNavForReader($tree);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadSection(
        string $bookKey,
        ?int $sectionId = null,
        ?string $stableAnchor = null
    ): ?array {
        $version = $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $section = null;

        if ($sectionId !== null && $sectionId > 0) {
            $section = $this->sections()->getSection($versionId, $sectionId);
        } elseif ($stableAnchor !== null && trim($stableAnchor) !== '') {
            $section = $this->getSectionByStableAnchor($versionId, trim($stableAnchor));
        }

        if ($section === null) {
            return null;
        }

        $sectionId = (int)$section['id'];
        $nav = $this->prevNextSectionIds($versionId, $sectionId);
        $html = $this->renderSectionHtml($version, $section);

        return array(
            'html' => $html,
            'section_id' => $sectionId,
            'section_title' => (string)($section['title'] ?? ''),
            'stable_anchor' => (string)($section['stable_anchor'] ?? ''),
            'section_key' => (string)($section['section_key'] ?? ''),
            'prev_section_id' => $nav['prev'],
            'next_section_id' => $nav['next'],
            'version_id' => $versionId,
            'version_label' => (string)($version['version_label'] ?? ''),
            'book_title' => (string)($version['book_title'] ?? ''),
            'book_key' => (string)($version['book_key'] ?? $bookKey),
        );
    }

    public function defaultSectionId(string $bookKey): int
    {
        $version = $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $flat = $this->sections()->listFlatSections($versionId);

        return $flat !== array() ? (int)$flat[0]['id'] : 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchSectionTitles(string $bookKey, string $query, int $limit = 40): array
    {
        $query = trim($query);
        if ($query === '') {
            return array();
        }

        $version = $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $needle = mb_strtolower($query);
        $results = array();

        foreach ($this->sections()->listFlatSections($versionId) as $row) {
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (!str_contains(mb_strtolower($title), $needle)) {
                continue;
            }
            $results[] = array(
                'section_id' => (int)($row['id'] ?? 0),
                'section_title' => $title,
                'stable_anchor' => (string)($row['stable_anchor'] ?? ''),
                'section_key' => (string)($row['section_key'] ?? ''),
            );
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function progressTableReady(): bool
    {
        if (self::$progressTableReady !== null) {
            return self::$progressTableReady;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'ipca_manual_reading_progress'");
            self::$progressTableReady = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            self::$progressTableReady = false;
        }

        return self::$progressTableReady;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getReadingProgress(int $userId, string $bookKey): ?array
    {
        if (!$this->progressTableReady()) {
            return null;
        }

        $version = $this->resolveLatestReleasedVersion($bookKey);
        if ($version === null) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT section_id, stable_anchor, scroll_pct, updated_at
            FROM ipca_manual_reading_progress
            WHERE user_id = :user_id AND book_version_id = :version_id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':user_id' => $userId,
            ':version_id' => (int)$version['id'],
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function saveReadingProgress(
        int $userId,
        string $bookKey,
        int $sectionId,
        ?string $stableAnchor,
        int $scrollPct
    ): bool {
        if (!$this->progressTableReady()) {
            return false;
        }

        $version = $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $section = $this->sections()->getSection($versionId, $sectionId);
        if ($section === null) {
            throw new RuntimeException('Section not found.');
        }

        $scrollPct = max(0, min(100, $scrollPct));
        $anchor = trim((string)($stableAnchor ?? ''));
        if ($anchor === '') {
            $anchor = (string)($section['stable_anchor'] ?? '');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_manual_reading_progress
                (user_id, book_version_id, section_id, stable_anchor, scroll_pct)
            VALUES
                (:user_id, :version_id, :section_id, :stable_anchor, :scroll_pct)
            ON DUPLICATE KEY UPDATE
                section_id = VALUES(section_id),
                stable_anchor = VALUES(stable_anchor),
                scroll_pct = VALUES(scroll_pct),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':user_id' => $userId,
            ':version_id' => $versionId,
            ':section_id' => $sectionId,
            ':stable_anchor' => $anchor !== '' ? $anchor : null,
            ':scroll_pct' => $scrollPct,
        ));

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function requireReleasedVersion(string $bookKey): array
    {
        $version = $this->resolveLatestReleasedVersion($bookKey);
        if ($version === null) {
            throw new RuntimeException('No released manual available.');
        }
        if ((string)($version['lifecycle_status'] ?? '') !== 'released') {
            throw new RuntimeException('Manual version is not released.');
        }

        return $version;
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     */
    private function renderSectionHtml(array $version, array $section): string
    {
        $versionId = (int)$version['id'];
        $sectionId = (int)$section['id'];
        $mode = ControlledPublishingBookRenderer::MODE_READ;

        $this->configureRenderer($version);
        $pageHeaderConfig = $this->pageHeaderConfig($version, $section, $versionId);
        $sectionBlocks = $this->revision()->annotateChangeStatus($versionId, $this->blocks()->listSectionBlocks($sectionId));
        $pageLayout = $this->layoutSvc()->resolveLayout($section);
        $blocksHtml = $this->renderer()->renderBlocks($sectionBlocks, $mode);

        return $this->renderPageHtml(
            $version,
            $section,
            $blocksHtml,
            $mode,
            $pageLayout,
            $pageHeaderConfig,
            $sectionBlocks
        );
    }

    /**
     * @param array<string,mixed> $version
     */
    private function configureRenderer(array $version): void
    {
        $versionId = (int)$version['id'];
        $bookStyles = $this->styleSvc()->resolveFromVersion($version);
        $this->renderer()->setBookStyles($bookStyles, $this->styleSvc());
        $computed = $this->numberSvc()->computeForVersion($versionId, (string)($version['manual_code'] ?? ''));
        $this->renderer()->setSectionNumbers(
            $computed['display'],
            $computed['suggested_regulatory_refs'],
            $this->numberSvc()
        );
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function pageHeaderConfig(array $version, array $section, int $versionId): array
    {
        $meta = $this->decodeVersionMeta($version);
        $legacyLayout = null;
        if (!is_array($meta['page_header'] ?? null)) {
            $legacyLayout = $this->legacySectionLayout($section);
        }
        $config = $this->pageHeaderSvc()->resolveForSection($version, $section, $legacyLayout);
        if (!$this->pageHeaderSvc()->isAnnexFamilySection($section)) {
            $flat = $this->sections()->listFlatSections($versionId);
            $config['token_overrides'] = array(
                'part_title' => $this->manualStructure()->resolvePartTitleForSection($section, $flat),
            );
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $sectionBlocks
     */
    private function renderPageHtml(
        array $version,
        array $section,
        string $blocksHtml,
        string $mode,
        array $pageLayout,
        array $pageHeaderConfig,
        array $sectionBlocks
    ): string {
        if ($this->isCoverSection($section)) {
            $coverPage = $this->coverPageSvc()->resolveFromVersion($version);

            return $this->renderer()->renderCoverPageShell($version, $section, $mode, $pageHeaderConfig, $coverPage);
        }
        if ($this->isLepSection($section)) {
            $lepPage = $this->lepPageSvc()->resolveFromVersion($version);
            $approval = $this->approvalSvc()->resolveApproval((int)$version['id']);

            return $this->renderer()->renderLepPageShell($version, $section, $mode, $pageHeaderConfig, $lepPage, $approval);
        }
        if ($this->isAnnexRegisterSection($section)) {
            $register = $this->annex()->resolveRegisterPage((int)$version['id']);
            $rows = is_array($register['rows'] ?? null) ? $register['rows'] : array();

            return $this->renderer()->renderAnnexRegisterShell($version, $section, $rows, $mode, $pageHeaderConfig);
        }
        if ($this->isAnnexHighlightsSection($section)) {
            $manual = array();
            $system = array();
            foreach ($sectionBlocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (!empty($block['is_system_managed'])) {
                    $system[] = $block;
                } else {
                    $manual[] = $block;
                }
            }
            $manualHtml = $this->renderer()->renderBlocks($manual, $mode);
            $systemHtml = $this->renderer()->renderBlocks($system, $mode);
            $body = $manualHtml;
            if ($systemHtml !== '') {
                $body = $systemHtml . ($manualHtml !== '' ? '<div class="cpb-annex-highlights-manual">' . $manualHtml . '</div>' : '');
            }

            return $this->renderer()->renderAnnexHighlightsShell($version, $section, $body, $mode, $pageHeaderConfig);
        }
        if ($this->isPart0ShellSection($section)) {
            $sectionKey = (string)($section['section_key'] ?? '');
            $headings = $this->part0PageSvc()->resolveHeadingsForSection($sectionKey, $version);
            $bodyHtml = $this->buildPart0BodyHtml($version, $section, $blocksHtml, $sectionBlocks, $mode);

            return $this->renderer()->renderPart0AdminPageShell(
                $version,
                $section,
                $headings,
                $bodyHtml,
                $mode,
                $pageHeaderConfig
            );
        }

        return $this->renderer()->renderPageShell($version, $section, $blocksHtml, $mode, $pageLayout, $pageHeaderConfig);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $sectionBlocks
     */
    private function buildPart0BodyHtml(
        array $version,
        array $section,
        string $blocksHtml,
        array $sectionBlocks,
        string $mode
    ): string {
        $key = (string)($section['section_key'] ?? '');
        $editMode = false;

        if ($key === 'amendment_list') {
            return $this->renderer()->renderAmendmentListContent(
                $this->part0PageSvc()->resolveAmendmentListFromVersion($version),
                $editMode
            );
        }
        if ($key === 'distribution_list') {
            return $this->renderer()->renderDistributionListContent(
                $this->part0PageSvc()->resolveDistributionListFromVersion($version),
                $editMode
            );
        }
        if ($key === 'abbreviations') {
            return $this->renderer()->renderAbbreviationsIndexContent(
                $this->part0PageSvc()->resolveAbbreviationsPageFromVersion($version),
                $editMode
            );
        }
        if ($key === 'definitions') {
            return $this->renderer()->renderDefinitionsListContent(
                $this->part0PageSvc()->resolveDefinitionsFromVersion($version),
                $editMode
            );
        }
        if ($key === 'highlights') {
            $manual = array();
            $system = array();
            foreach ($sectionBlocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if ((string)($block['block_type'] ?? '') === 'generated_placeholder') {
                    continue;
                }
                if (!empty($block['is_system_managed'])) {
                    $system[] = $block;
                } else {
                    $manual[] = $block;
                }
            }
            $manualHtml = $this->renderer()->renderBlocks($manual, $mode);
            $systemHtml = $this->renderer()->renderBlocks($system, $mode);
            $body = '<div class="cpb-part0-highlights-manual">' . $manualHtml . '</div>';
            if ($systemHtml !== '') {
                $body .= '<div class="cpb-part0-highlights-generated" contenteditable="false">' . $systemHtml . '</div>';
            }

            return $body;
        }

        return '<div class="cpb-part0-blocks">' . $blocksHtml . '</div>';
    }

    /**
     * @return array{prev:?int,next:?int}
     */
    private function prevNextSectionIds(int $versionId, int $sectionId): array
    {
        $flat = $this->sections()->listFlatSections($versionId);
        $ids = array();
        foreach ($flat as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $index = array_search($sectionId, $ids, true);
        if ($index === false) {
            return array('prev' => null, 'next' => null);
        }

        return array(
            'prev' => $index > 0 ? $ids[$index - 1] : null,
            'next' => $index < count($ids) - 1 ? $ids[$index + 1] : null,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getSectionByStableAnchor(int $versionId, string $anchor): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*
            FROM ipca_publishing_book_sections s
            WHERE s.book_version_id = :version_id
              AND s.stable_anchor = :anchor
            LIMIT 1
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':anchor' => $anchor,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->sections()->getSection($versionId, (int)$row['id']);
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private function sanitizeNavForReader(array $tree): array
    {
        $out = array();
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $item = array(
                'id' => isset($node['id']) ? (int)$node['id'] : null,
                'title' => (string)($node['title'] ?? ''),
                'stable_anchor' => (string)($node['stable_anchor'] ?? ''),
                'section_key' => (string)($node['section_key'] ?? ''),
                'is_group' => !empty($node['is_group']),
                'is_separator' => !empty($node['is_separator']),
                'is_navigable' => !empty($node['is_navigable']),
                'label_style' => (string)($node['label_style'] ?? ''),
                'scroll_section_ref' => (string)($node['scroll_section_ref'] ?? ''),
            );
            if (!empty($node['children']) && is_array($node['children'])) {
                $item['children'] = $this->sanitizeNavForReader($node['children']);
            } else {
                $item['children'] = array();
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeVersionMeta(array $version): array
    {
        $raw = $version['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>|null
     */
    private function legacySectionLayout(array $section): ?array
    {
        $raw = $section['metadata_json'] ?? '{}';
        $meta = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($meta)) {
            return null;
        }
        $layout = is_array($meta['page_layout'] ?? null) ? $meta['page_layout'] : null;
        if ($layout === null) {
            return null;
        }
        if (isset($layout['header_left']) || isset($layout['header_center']) || isset($layout['show_running_header_footer'])) {
            return $layout;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $section
     */
    private function isCoverSection(array $section): bool
    {
        return (string)($section['section_key'] ?? '') === 'cover';
    }

    /**
     * @param array<string,mixed> $section
     */
    private function isLepSection(array $section): bool
    {
        return (string)($section['section_key'] ?? '') === 'lep';
    }

    /**
     * @param array<string,mixed> $section
     */
    private function isAnnexRegisterSection(array $section): bool
    {
        return (string)($section['section_key'] ?? '') === ControlledPublishingAnnexService::REGISTER_SECTION_KEY;
    }

    /**
     * @param array<string,mixed> $section
     */
    private function isAnnexHighlightsSection(array $section): bool
    {
        return (string)($section['section_key'] ?? '') === ControlledPublishingAnnexService::HIGHLIGHTS_SECTION_KEY;
    }

    /**
     * @param array<string,mixed> $section
     */
    private function isPart0ShellSection(array $section): bool
    {
        $key = (string)($section['section_key'] ?? '');

        return in_array($key, array(
            'revision_system',
            'amendment_list',
            'distribution_list',
            'abbreviations',
            'definitions',
            'highlights',
        ), true);
    }

    private function foundation(): ControlledPublishingFoundationService
    {
        return $this->foundation ??= new ControlledPublishingFoundationService($this->pdo);
    }

    private function sections(): ControlledPublishingSectionService
    {
        return $this->sections ??= new ControlledPublishingSectionService($this->pdo);
    }

    private function blocks(): ControlledPublishingBlockService
    {
        return $this->blocks ??= new ControlledPublishingBlockService($this->pdo);
    }

    private function renderer(): ControlledPublishingBookRenderer
    {
        return $this->renderer ??= new ControlledPublishingBookRenderer();
    }

    private function revision(): ControlledPublishingRevisionService
    {
        return $this->revision ??= new ControlledPublishingRevisionService($this->pdo);
    }

    private function layoutSvc(): ControlledPublishingSectionLayoutService
    {
        return $this->layoutSvc ??= new ControlledPublishingSectionLayoutService($this->pdo);
    }

    private function styleSvc(): ControlledPublishingBookStyleService
    {
        return $this->styleSvc ??= new ControlledPublishingBookStyleService($this->pdo);
    }

    private function numberSvc(): ControlledPublishingSectionNumberService
    {
        return $this->numberSvc ??= new ControlledPublishingSectionNumberService($this->pdo, $this->blocks());
    }

    private function pageHeaderSvc(): ControlledPublishingPageHeaderService
    {
        return $this->pageHeaderSvc ??= new ControlledPublishingPageHeaderService($this->pdo);
    }

    private function coverPageSvc(): ControlledPublishingCoverPageService
    {
        return $this->coverPageSvc ??= new ControlledPublishingCoverPageService($this->pdo);
    }

    private function lepPageSvc(): ControlledPublishingLepService
    {
        return $this->lepPageSvc ??= new ControlledPublishingLepService($this->pdo);
    }

    private function approvalSvc(): ControlledPublishingApprovalService
    {
        return $this->approvalSvc ??= new ControlledPublishingApprovalService($this->pdo, $this->lepPageSvc());
    }

    private function part0PageSvc(): ControlledPublishingPart0PageService
    {
        return $this->part0PageSvc ??= new ControlledPublishingPart0PageService($this->pdo);
    }

    private function manualStructure(): ControlledPublishingManualStructureService
    {
        return $this->manualStructureSvc ??= new ControlledPublishingManualStructureService(
            $this->pdo,
            $this->foundation(),
            $this->sections(),
            $this->blocks()
        );
    }

    private function editorNav(): ControlledPublishingEditorNavService
    {
        return $this->editorNavSvc ??= new ControlledPublishingEditorNavService(
            $this->sections(),
            $this->manualStructure()
        );
    }

    private function annex(): ControlledPublishingAnnexService
    {
        return $this->annexSvc ??= new ControlledPublishingAnnexService(
            $this->pdo,
            $this->foundation(),
            $this->sections(),
            $this->blocks()
        );
    }
}
