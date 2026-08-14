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
require_once __DIR__ . '/ControlledPublishingReaderTokenResolver.php';
require_once __DIR__ . '/ControlledPublishingReaderCoverService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleManifestService.php';
require_once __DIR__ . '/ControlledPublishingManualPageBreakService.php';
require_once __DIR__ . '/ControlledPublishingAuthoritativePaginationService.php';

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
    private ?ControlledPublishingReaderTokenResolver $tokenResolver = null;
    private ?ControlledPublishingReaderCoverService $coverSvc = null;

    private static ?bool $progressTableReady = null;

    /** @var array<int, list<array<string,mixed>>> */
    private static array $ephemeralPageMapCache = array();

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActiveReleasedLibrary(?int $userId = null): array
    {
        return $this->listActiveLibrary($userId, false);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActiveLibrary(?int $userId, bool $includeDraftPreview): array
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
            try {
                $bookKey = (string)($book['book_key'] ?? '');
                $released = $this->resolveLatestReleasedVersion($bookKey);
                if ($released !== null) {
                    $library[] = $this->buildLibraryEntry($book, $released, $userId, false);
                }

                if (!$includeDraftPreview) {
                    continue;
                }

                $draft = $this->resolveLatestDraftVersion($bookKey);
                if ($draft === null) {
                    continue;
                }
                if ($released !== null && (int)$draft['id'] === (int)$released['id']) {
                    continue;
                }

                $library[] = $this->buildLibraryEntry($book, $draft, $userId, true);
            } catch (Throwable $e) {
                error_log('listActiveLibrary skipped book: ' . $e->getMessage());
            }
        }

        return $library;
    }

    /**
     * @param array<string,mixed> $book
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function buildLibraryEntry(array $book, array $version, ?int $userId, bool $isPreview): array
    {
        $bookKey = (string)($book['book_key'] ?? '');
        $cover = $this->cover()->resolveCoverForVersion($version);
        $progress = (!$isPreview && $userId !== null && $userId > 0)
            ? $this->getReadingProgress($userId, $bookKey)
            : null;
        $lifecycle = (string)($version['lifecycle_status'] ?? '');

        return array(
            'book_id' => (int)($book['id'] ?? 0),
            'book_key' => $bookKey,
            'book_title' => (string)($book['title'] ?? ''),
            'manual_code' => (string)($book['manual_code'] ?? ''),
            'version_id' => (int)($version['id'] ?? 0),
            'version_label' => (string)($version['version_label'] ?? ''),
            'effective_date' => $version['effective_date'] ?? null,
            'released_at' => $version['released_at'] ?? null,
            'lifecycle_status' => $lifecycle,
            'is_preview' => $isPreview,
            'cover_url' => $cover['cover_url'],
            'cover_image_url' => $cover['cover_image_url'],
            'logo_url' => $cover['logo_url'],
            'cover_fallback' => $cover['fallback'],
            'has_progress' => is_array($progress),
            'has_page_map' => $isPreview ? true : $this->safeHasApprovedPageMap($version, $bookKey),
            'continue_section_id' => is_array($progress) ? (int)($progress['section_id'] ?? 0) : null,
            'continue_stable_anchor' => is_array($progress) ? (string)($progress['stable_anchor'] ?? '') : '',
            'continue_page_number' => is_array($progress) ? (int)($progress['scroll_pct'] ?? 0) : null,
        );
    }

    /**
     * @param array<string,mixed> $version
     */
    private function safeHasApprovedPageMap(array $version, string $bookKey): bool
    {
        try {
            if (method_exists($this, 'hasApprovedFrozenPageMapForVersion')) {
                return $this->hasApprovedFrozenPageMapForVersion($version);
            }

            return $this->hasApprovedFrozenPageMap($bookKey);
        } catch (Throwable $e) {
            return false;
        }
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
     * @return array<string,mixed>|null
     */
    public function resolveLatestDraftVersion(string $bookKey): ?array
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
              AND bv.lifecycle_status IN ('draft', 'in_review', 'approved')
            ORDER BY bv.id DESC
            LIMIT 1
        ");
        $stmt->execute(array(':book_key' => $bookKey));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveVersionById(int $bookVersionId): ?array
    {
        if ($bookVersionId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
              bv.*,
              b.book_key,
              b.title AS book_title,
              b.manual_code,
              b.status AS book_status
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            WHERE bv.id = :version_id
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $bookVersionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ((string)($row['book_status'] ?? '') !== 'active') {
            return null;
        }

        return $row;
    }

    /**
     * Resolve the reader version: explicit id, else latest released, else latest draft (preview roles).
     *
     * @return array<string,mixed>
     */
    public function resolveReaderVersion(string $bookKey, ?int $versionId, bool $allowDraftPreview): array
    {
        $bookKey = strtoupper(trim($bookKey));

        if ($versionId !== null && $versionId > 0) {
            $version = $this->resolveVersionById($versionId);
            if ($version === null) {
                throw new RuntimeException('Manual version not found.');
            }
            if (strtoupper(trim((string)($version['book_key'] ?? ''))) !== $bookKey) {
                throw new RuntimeException('Version does not match book.');
            }

            $lifecycle = (string)($version['lifecycle_status'] ?? '');
            if ($lifecycle === 'released') {
                return $version;
            }
            if ($allowDraftPreview && $this->isPreviewableLifecycleStatus($lifecycle)) {
                return $version;
            }

            throw new RuntimeException('You cannot view this manual version.');
        }

        $released = $this->resolveLatestReleasedVersion($bookKey);
        if ($released !== null) {
            return $released;
        }

        if ($allowDraftPreview) {
            $draft = $this->resolveLatestDraftVersion($bookKey);
            if ($draft !== null) {
                return $draft;
            }
        }

        throw new RuntimeException('No manual available.');
    }

    private function isPreviewableLifecycleStatus(string $lifecycle): bool
    {
        return in_array($lifecycle, array('draft', 'in_review', 'approved'), true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function buildReaderNavTree(string $bookKey, ?array $version = null): array
    {
        $version = $version ?? $this->requireReleasedVersion($bookKey);
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
        ?string $stableAnchor = null,
        ?array $version = null
    ): ?array {
        $version = $version ?? $this->requireReleasedVersion($bookKey);
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
        $isCover = $this->isCoverSection($section);
        $pageHeaderConfig = $this->pageHeaderConfig($version, $section, $versionId);
        $partTitle = '';
        if (is_array($pageHeaderConfig['token_overrides'] ?? null)) {
            $partTitle = trim((string)($pageHeaderConfig['token_overrides']['part_title'] ?? ''));
        }
        $tokenContext = $this->tokens()->buildContext($version, $section, array(
            'page' => 1,
            'page_total' => 1,
            'part_title' => $partTitle,
        ));
        $html = $this->renderSectionHtml($version, $section, $tokenContext, $pageHeaderConfig);
        $layout = $this->layoutSvc()->resolveLayout($section);

        return array(
            'html' => $html,
            'section_id' => $sectionId,
            'section_title' => (string)($section['title'] ?? ''),
            'stable_anchor' => (string)($section['stable_anchor'] ?? ''),
            'section_key' => (string)($section['section_key'] ?? ''),
            'section_type' => (string)($section['section_type'] ?? ''),
            'is_cover' => $isCover,
            'hide_header_footer' => $isCover || !empty($layout['hide_header_footer']),
            'token_context' => $this->tokens()->contextForApi($tokenContext),
            'prev_section_id' => $nav['prev'],
            'next_section_id' => $nav['next'],
            'version_id' => $versionId,
            'version_label' => (string)($version['version_label'] ?? ''),
            'book_title' => (string)($version['book_title'] ?? ''),
            'book_key' => (string)($version['book_key'] ?? $bookKey),
        );
    }

    public function defaultSectionId(string $bookKey, ?array $version = null): int
    {
        $version = $version ?? $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $flat = $this->sections()->listFlatSections($versionId);

        return $flat !== array() ? (int)$flat[0]['id'] : 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchSectionTitles(string $bookKey, string $query, int $limit = 40, ?array $version = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return array();
        }

        $version = $version ?? $this->requireReleasedVersion($bookKey);
        $versionId = (int)$version['id'];
        $needle = mb_strtolower($query);
        $results = array();

        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $pages = $this->pageMapStore()->isApproved($versionId, $profile)
            ? $this->pageMapStore()->loadStoredPages($versionId, $profile)
            : $this->getEphemeralPageMapPages($version);
        foreach ($pages as $page) {
            $plain = trim(preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(
                    strip_tags((string)($page['page_html'] ?? '')),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            ) ?? '');
            $position = mb_stripos($plain, $query);
            if ($position === false) {
                continue;
            }
            $metadata = is_array($page['metadata'] ?? null) ? $page['metadata'] : array();
            $start = max(0, (int)$position - 55);
            $excerpt = mb_substr($plain, $start, mb_strlen($query) + 130);
            $results[] = array(
                'section_id' => (int)($page['section_id'] ?? 0),
                'section_title' => (string)($metadata['section_title'] ?? ''),
                'stable_anchor' => (string)($page['stable_anchor'] ?? ''),
                'page_number' => (int)($page['page_number'] ?? 0),
                'excerpt' => ($start > 0 ? '…' : '') . $excerpt
                    . (($start + mb_strlen($excerpt)) < mb_strlen($plain) ? '…' : ''),
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

        $scrollPct = max(0, min(9999, $scrollPct));
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
     * @param array<string,string> $tokenContext
     * @param array<string,mixed> $pageHeaderConfig
     */
    private function renderSectionHtml(
        array $version,
        array $section,
        array $tokenContext,
        array $pageHeaderConfig
    ): string {
        $versionId = (int)$version['id'];
        $sectionId = (int)$section['id'];
        $mode = ControlledPublishingBookRenderer::MODE_READ;

        $this->configureRenderer($version);
        if (is_array($pageHeaderConfig['token_overrides'] ?? null)) {
            $pageHeaderConfig['token_overrides'] = array_merge(
                $pageHeaderConfig['token_overrides'],
                array(
                    'page' => (int)($tokenContext['page'] ?? 1),
                    'page_total' => (int)($tokenContext['page_total'] ?? 1),
                )
            );
        } else {
            $pageHeaderConfig['token_overrides'] = array(
                'part_title' => (string)($tokenContext['part_title'] ?? ''),
                'page' => (int)($tokenContext['page'] ?? 1),
                'page_total' => (int)($tokenContext['page_total'] ?? 1),
            );
        }

        $sectionBlocks = $this->revision()->annotateChangeStatus($versionId, $this->blocks()->listSectionBlocks($sectionId));
        $pageLayout = $this->layoutSvc()->resolveLayout($section);
        $blocksHtml = $this->renderer()->renderBlocks($sectionBlocks, $mode);

        $html = $this->renderPageHtml(
            $version,
            $section,
            $blocksHtml,
            $mode,
            $pageLayout,
            $pageHeaderConfig,
            $sectionBlocks
        );

        return $this->tokens()->resolveHtml($html, $tokenContext);
    }

    /**
     * @param array<string,mixed> $version
     */
    private function configureRenderer(array $version): void
    {
        $versionId = (int)$version['id'];
        $bookStyles = $this->styleSvc()->resolveFromVersion($version);
        $renderer = $this->renderer();
        $renderer->setBookStyles($bookStyles, $this->styleSvc());
        $renderer->setPageHeaderService($this->pageHeaderSvc());
        $renderer->setCoverPageService($this->coverPageSvc());
        $computed = $this->numberSvc()->computeForVersion($versionId, (string)($version['manual_code'] ?? ''));
        $renderer->setSectionNumbers(
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

    private function tokens(): ControlledPublishingReaderTokenResolver
    {
        return $this->tokenResolver ??= new ControlledPublishingReaderTokenResolver($this->pageHeaderSvc());
    }

    private function cover(): ControlledPublishingReaderCoverService
    {
        return $this->coverSvc ??= new ControlledPublishingReaderCoverService(
            $this->pdo,
            $this->coverPageSvc(),
            $this->sections(),
            $this->blocks()
        );
    }

    /**
     * Pagination layer entry point (released content only).
     *
     * @return array<string,mixed>
     */
    public function buildPaginateSource(string $bookKey, ?array $version = null): array
    {
        require_once __DIR__ . '/ControlledPublishingPaginationService.php';

        return (new ControlledPublishingPaginationService($this))->buildPaginateSource($bookKey, $version);
    }

    /**
     * Read-only pagination source for a reader-specific, client-side page map.
     *
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function loadReaderPaginateSource(array $version): array
    {
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? '')));
        $source = $this->buildPaginateSource($bookKey, $version);
        foreach ($source['sections'] ?? array() as $index => $section) {
            if (!is_array($section)) {
                continue;
            }
            unset($section['token_context']);
            $source['sections'][$index] = $section;
        }

        return $source;
    }

    /**
     * Shared publication contract used by authoritative pagination and readers.
     *
     * @param array<string,mixed> $version
     * @param array<string,mixed> $paginateSource
     * @return array<string,mixed>
     */
    public function paginationPublicationPackage(array $version, array $paginateSource): array
    {
        return (new ControlledPublishingBookStyleManifestService($this->pdo))
            ->buildPublicationPackage($version, $paginateSource);
    }

    /**
     * @param list<array<string,mixed>> $sections
     * @return list<array<string,mixed>>
     */
    public function paginationApplyManualPageBreaks(int $versionId, array $sections): array
    {
        return (new ControlledPublishingManualPageBreakService($this->pdo))
            ->applyToSections($versionId, $sections);
    }

    /**
     * @return array{count:int,hash:string,anchors:list<string>}
     */
    public function paginationManualPageBreakIdentity(int $versionId): array
    {
        return (new ControlledPublishingManualPageBreakService($this->pdo))->identity($versionId);
    }

    /**
     * Blocks release unless the approved map was generated from the current
     * content, style, layout, manual-break set, and validated page payload.
     *
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function assertAuthoritativePageMapReadyForRelease(array $version): array
    {
        $versionId = (int)($version['id'] ?? 0);
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $store = $this->pageMapStore();
        $approval = $store->getApprovalState($versionId, $profile);
        if (($approval['status'] ?? '') !== 'approved') {
            throw new RuntimeException(
                'Release blocked: generate, validate, and approve Page Preview pagination first.'
            );
        }
        $generation = is_array($approval['generation'] ?? null)
            ? $approval['generation']
            : array();
        $source = $this->loadReaderPaginateSource($version);
        $expected = (new ControlledPublishingAuthoritativePaginationService($this))
            ->fingerprint($source, $version);
        foreach ($expected as $key => $value) {
            if ($value === '' || !hash_equals((string)($generation[$key] ?? ''), $value)) {
                throw new RuntimeException(
                    'Release blocked: Page Preview pagination is stale (' . $key . '). Regenerate and approve it.'
                );
            }
        }
        $validation = is_array($generation['validation'] ?? null)
            ? $generation['validation']
            : array();
        if (empty($validation['is_valid'])) {
            throw new RuntimeException('Release blocked: authoritative page validation did not pass.');
        }
        $pages = $store->loadStoredPages($versionId, $profile);
        if ($pages === array() || count($pages) !== (int)($generation['page_count'] ?? 0)) {
            throw new RuntimeException('Release blocked: frozen page payload is incomplete.');
        }
        $actualPageMapHash = hash(
            'sha256',
            implode("\n", array_map(
                static fn(array $page): string => (string)$page['page_html'],
                $pages
            ))
        );
        if (!hash_equals((string)($generation['page_map_hash'] ?? ''), $actualPageMapHash)) {
            throw new RuntimeException('Release blocked: frozen page payload hash does not match approval.');
        }

        return $generation;
    }

    /**
     * @param array<string,mixed> $version
     * @return array{is_current:bool,mismatches:list<string>}
     */
    public function authoritativePageMapFreshness(array $version, ?array $source = null): array
    {
        $versionId = (int)($version['id'] ?? 0);
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $approval = $this->pageMapStore()->getApprovalState($versionId, $profile);
        $generation = is_array($approval['generation'] ?? null)
            ? $approval['generation']
            : array();
        if ($generation === array()) {
            return array('is_current' => false, 'mismatches' => array('not_generated'));
        }
        $source ??= $this->loadReaderPaginateSource($version);
        $expected = (new ControlledPublishingAuthoritativePaginationService($this))
            ->fingerprint($source, $version);
        $mismatches = array();
        foreach ($expected as $key => $value) {
            if ($value === '' || !hash_equals((string)($generation[$key] ?? ''), $value)) {
                $mismatches[] = $key;
            }
        }

        return array('is_current' => $mismatches === array(), 'mismatches' => $mismatches);
    }

    public function pageMapStore(): ControlledPublishingReaderPageMapStore
    {
        require_once __DIR__ . '/ControlledPublishingReaderPageMapStore.php';

        return $this->pageMapStore ??= new ControlledPublishingReaderPageMapStore($this->pdo);
    }

    /**
     * Queue/coalesce revision-safe background generation for the current source.
     *
     * @return array<string,mixed>
     */
    public function ensureLivePageMap(int $bookVersionId, int $requestedByUserId): array
    {
        return $this->livePageMapService()->ensure($bookVersionId, $requestedByUserId);
    }

    /**
     * @return array<string,mixed>
     */
    public function livePageMapStatus(int $bookVersionId): array
    {
        return $this->livePageMapService()->status($bookVersionId);
    }

    /**
     * @return array<string,mixed>
     */
    public function retryLivePageMap(int $bookVersionId, int $requestedByUserId): array
    {
        return $this->livePageMapService()->retry($bookVersionId, $requestedByUserId);
    }

    private function livePageMapService(): ControlledPublishingLivePageMapService
    {
        require_once __DIR__ . '/ControlledPublishingLivePageMapService.php';
        return $this->livePageMapService
            ??= new ControlledPublishingLivePageMapService($this->pdo, $this);
    }

    public function hasApprovedFrozenPageMap(string $bookKey): bool
    {
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
        $version = $this->resolveLatestReleasedVersion($bookKey);
        if ($version === null) {
            return false;
        }

        return $this->hasApprovedFrozenPageMapForVersion($version);
    }

    /**
     * @param array<string,mixed> $version
     */
    public function hasApprovedFrozenPageMapForVersion(array $version): bool
    {
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';

        return $this->pageMapStore()->isApproved(
            (int)$version['id'],
            ControlledPublishingReaderLayoutProfile::profileKey()
        );
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function loadReaderPageMap(array $version, bool $allowEphemeral): array
    {
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? '')));
        $versionId = (int)$version['id'];
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $lifecycle = (string)($version['lifecycle_status'] ?? '');
        $isPreview = $lifecycle !== 'released';

        $hasStoredMap = $this->pageMapStore()->pageCount($versionId, $profile) > 0
            && ($isPreview || $this->pageMapStore()->isApproved($versionId, $profile));
        if ($hasStoredMap) {
            $summary = $this->pageMapStore()->loadPageMapSummary($versionId, $profile);

            return array_merge($summary, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => $versionId,
                'version_label' => (string)($version['version_label'] ?? ''),
                'book_title' => (string)($version['book_title'] ?? ''),
                'lifecycle_status' => $lifecycle,
                'is_preview' => false,
            ));
        }

        if (!$allowEphemeral) {
            throw new RuntimeException('No approved page map for this manual. Contact compliance.');
        }

        $summary = $this->buildEphemeralPageMapSummary($version);

        return array_merge($summary, array(
            'ok' => true,
            'book_key' => $bookKey,
            'version_id' => $versionId,
            'version_label' => (string)($version['version_label'] ?? ''),
            'book_title' => (string)($version['book_title'] ?? ''),
            'lifecycle_status' => $lifecycle,
            'is_preview' => $isPreview,
        ));
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function loadReaderPage(array $version, int $pageNumber, bool $allowEphemeral): array
    {
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? '')));
        $versionId = (int)$version['id'];
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $lifecycle = (string)($version['lifecycle_status'] ?? '');
        $isPreview = $lifecycle !== 'released';

        if ($pageNumber <= 0) {
            throw new RuntimeException('page_number required.');
        }

        $hasStoredMap = $this->pageMapStore()->pageCount($versionId, $profile) > 0
            && ($isPreview || $this->pageMapStore()->isApproved($versionId, $profile));
        if ($hasStoredMap) {
            $page = $this->pageMapStore()->loadPage($versionId, $profile, $pageNumber);
            if ($page === null) {
                throw new RuntimeException('Page not found.');
            }

            return array_merge($page, array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => $versionId,
                'is_preview' => false,
            ));
        }

        if (!$allowEphemeral) {
            throw new RuntimeException('No approved page map for this manual.');
        }

        $pages = $this->getEphemeralPageMapPages($version);
        foreach ($pages as $page) {
            if ((int)($page['page_number'] ?? 0) === $pageNumber) {
                return array_merge($page, array(
                    'ok' => true,
                    'book_key' => $bookKey,
                    'version_id' => $versionId,
                    'page_count' => count($pages),
                    'is_preview' => $isPreview,
                ));
            }
        }

        throw new RuntimeException('Page not found.');
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function loadReaderTocWithPages(array $version, bool $allowEphemeral): array
    {
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? '')));
        $versionId = (int)$version['id'];
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $lifecycle = (string)($version['lifecycle_status'] ?? '');
        $isPreview = $lifecycle !== 'released';

        $hasStoredMap = $this->pageMapStore()->pageCount($versionId, $profile) > 0
            && ($isPreview || $this->pageMapStore()->isApproved($versionId, $profile));
        if ($hasStoredMap) {
            $sectionPages = $this->pageMapStore()->sectionPageIndex($versionId, $profile);
            $nav = $this->buildReaderNavTree($bookKey, $version);
            $this->annotateNavWithPageNumbers($nav, $sectionPages);

            return array(
                'ok' => true,
                'book_key' => $bookKey,
                'version_id' => $versionId,
                'lifecycle_status' => $lifecycle,
                'is_preview' => false,
                'nav' => $nav,
                'section_page_index' => $sectionPages,
                'page_count' => $this->pageMapStore()->pageCount($versionId, $profile),
            );
        }

        if (!$allowEphemeral) {
            throw new RuntimeException('No approved page map for this manual.');
        }

        $summary = $this->buildEphemeralPageMapSummary($version);
        $sectionPages = array();
        foreach ($summary['pages'] as $page) {
            $sectionId = $page['section_id'] ?? null;
            if ($sectionId === null) {
                continue;
            }
            $sid = (int)$sectionId;
            if (!isset($sectionPages[$sid])) {
                $sectionPages[$sid] = (int)$page['page_number'];
            }
        }
        $nav = $this->buildReaderNavTree($bookKey, $version);
        $this->annotateNavWithPageNumbers($nav, $sectionPages);

        return array(
            'ok' => true,
            'book_key' => $bookKey,
            'version_id' => $versionId,
            'lifecycle_status' => $lifecycle,
            'is_preview' => $isPreview,
            'nav' => $nav,
            'section_page_index' => $sectionPages,
            'page_count' => (int)($summary['page_count'] ?? 0),
        );
    }

    /**
     * @param array<string,mixed> $version
     * @return list<array<string,mixed>>
     */
    private function getEphemeralPageMapPages(array $version): array
    {
        $versionId = (int)$version['id'];
        if (isset(self::$ephemeralPageMapCache[$versionId])) {
            return self::$ephemeralPageMapCache[$versionId];
        }

        require_once __DIR__ . '/ControlledPublishingPaginationService.php';
        $bookKey = strtoupper(trim((string)($version['book_key'] ?? '')));
        $pages = (new ControlledPublishingPaginationService($this))->generateFrozenPageMap($bookKey, $version);
        self::$ephemeralPageMapCache[$versionId] = $pages;

        return $pages;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function buildEphemeralPageMapSummary(array $version): array
    {
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';
        $pages = $this->getEphemeralPageMapPages($version);
        $summaryPages = array();

        foreach ($pages as $page) {
            $meta = is_array($page['metadata'] ?? null) ? $page['metadata'] : array();
            $summaryPages[] = array(
                'page_number' => (int)($page['page_number'] ?? 0),
                'section_id' => isset($page['section_id']) ? (int)$page['section_id'] : null,
                'stable_anchor' => $page['stable_anchor'] ?? null,
                'page_type' => (string)($page['page_type'] ?? 'content'),
                'is_cover' => !empty($page['is_cover']),
                'is_section_start' => !empty($page['is_section_start']),
                'is_major_section_start' => !empty($page['is_major_section_start']),
                'section_title' => (string)($meta['section_title'] ?? ''),
                'thumbnail_html' => $page['thumbnail_html'] ?? null,
            );
        }
        $firstMetadata = is_array($pages[0]['metadata'] ?? null) ? $pages[0]['metadata'] : array();
        $generation = is_array($firstMetadata['publication_generation'] ?? null)
            ? $firstMetadata['publication_generation']
            : array();

        return array(
            'layout_profile' => ControlledPublishingReaderLayoutProfile::profileKey(),
            'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
            'layout' => ControlledPublishingReaderLayoutProfile::spec(),
            'page_count' => count($summaryPages),
            'page_map_hash' => (string)($generation['page_map_hash'] ?? ''),
            'source_hash' => (string)($generation['source_hash'] ?? ''),
            'style_hash' => (string)($generation['style_hash'] ?? ''),
            'manual_page_break_hash' => (string)($generation['manual_page_break_hash'] ?? ''),
            'header_footer_hash' => (string)($generation['header_footer_hash'] ?? ''),
            'manifest_hash' => (string)($generation['manifest_hash'] ?? ''),
            'approval' => null,
            'pages' => $summaryPages,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function loadFrozenPageMap(string $bookKey): array
    {
        $version = $this->requireReleasedVersion($bookKey);

        return $this->loadReaderPageMap($version, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function loadFrozenPage(string $bookKey, int $pageNumber): array
    {
        $version = $this->requireReleasedVersion($bookKey);

        return $this->loadReaderPage($version, $pageNumber, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function loadTocWithPages(string $bookKey): array
    {
        $version = $this->requireReleasedVersion($bookKey);

        return $this->loadReaderTocWithPages($version, false);
    }

    /**
     * Admin: generate and persist draft page map for a released version.
     *
     * @return array<string,mixed>
     */
    public function generateFrozenPageMapDraft(
        string $bookKey,
        int $generatedByUserId,
        ?array $version = null
    ): array
    {
        require_once __DIR__ . '/ControlledPublishingPaginationService.php';
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';

        $version = $version ?? $this->requireReleasedVersion($bookKey);
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released authoritative pagination is immutable.');
        }
        $pagination = new ControlledPublishingPaginationService($this);
        $pages = $pagination->generateFrozenPageMap($bookKey, $version);
        $profile = ControlledPublishingReaderLayoutProfile::profileKey();
        $hash = ControlledPublishingReaderLayoutProfile::layoutHash();
        $count = $this->pageMapStore()->replaceDraftPages(
            (int)$version['id'],
            $profile,
            $hash,
            $pages,
            $generatedByUserId,
            $pagination->lastGeneration()
        );

        return array(
            'book_key' => $bookKey,
            'version_id' => (int)$version['id'],
            'version_label' => (string)($version['version_label'] ?? ''),
            'layout_profile' => $profile,
            'layout_hash' => $hash,
            'page_count' => $count,
            'status' => 'draft',
        );
    }

    /**
     * Admin: preview without persisting.
     *
     * @return array<string,mixed>
     */
    public function previewFrozenPageMap(string $bookKey, ?array $version = null): array
    {
        require_once __DIR__ . '/ControlledPublishingPaginationService.php';
        require_once __DIR__ . '/ControlledPublishingReaderLayoutProfile.php';

        $version = $version ?? $this->requireReleasedVersion($bookKey);
        $pagination = new ControlledPublishingPaginationService($this);
        $pages = $pagination->generateFrozenPageMap($bookKey, $version);

        return array(
            'book_key' => $bookKey,
            'version_id' => (int)$version['id'],
            'layout_profile' => ControlledPublishingReaderLayoutProfile::profileKey(),
            'layout_hash' => ControlledPublishingReaderLayoutProfile::layoutHash(),
            'page_count' => count($pages),
            'generation' => $pagination->lastGeneration(),
            'pages' => array_map(static function (array $p): array {
                return array(
                    'page_number' => (int)$p['page_number'],
                    'section_id' => $p['section_id'],
                    'stable_anchor' => $p['stable_anchor'],
                    'is_cover' => !empty($p['is_cover']),
                    'section_title' => is_array($p['metadata'] ?? null)
                        ? (string)($p['metadata']['section_title'] ?? '') : '',
                    'page_html' => (string)($p['page_html'] ?? ''),
                );
            }, $pages),
        );
    }

    /**
     * @param array<int,int> $sectionPages
     */
    private function annotateNavWithPageNumbers(array &$nav, array $sectionPages): void
    {
        foreach ($nav as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $id = (int)($node['id'] ?? 0);
            if ($id > 0 && isset($sectionPages[$id])) {
                $node['page_number'] = $sectionPages[$id];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->annotateNavWithPageNumbers($node['children'], $sectionPages);
            }
        }
        unset($node);
    }

    /**
     * @return array<string,mixed>
     */
    public function requireReleasedVersionById(int $bookVersionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bv.*, b.book_key, b.title AS book_title, b.manual_code
               FROM ipca_publishing_book_versions bv
               INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
              WHERE bv.id = ?
              LIMIT 1'
        );
        $stmt->execute(array($bookVersionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)($row['lifecycle_status'] ?? '') !== 'released') {
            throw new RuntimeException('Only released versions can receive a page map.');
        }

        return $row;
    }

    private ?ControlledPublishingReaderPageMapStore $pageMapStore = null;
    private ?ControlledPublishingLivePageMapService $livePageMapService = null;

    /**
     * @param array<string,mixed> $version
     */
    public function paginationConfigureRenderer(array $version): void
    {
        $this->configureRenderer($version);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    public function paginationPageHeaderConfig(array $version, array $section): array
    {
        return $this->pageHeaderConfig($version, $section, (int)$version['id']);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @return array<string,string>
     */
    public function paginationTokenContext(array $version, array $section, array $overrides = array()): array
    {
        return $this->tokens()->buildContext($version, $section, $overrides);
    }

    /**
     * @param array<string,string> $context
     */
    public function paginationResolveHtml(string $html, array $context): string
    {
        return $this->tokens()->resolveHtml($html, $context);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $sectionBlocks
     */
    public function paginationRenderSectionShellHtml(
        array $version,
        array $section,
        array $sectionBlocks,
        array $pageHeaderConfig,
        array $tokenContext
    ): string {
        $mode = ControlledPublishingBookRenderer::MODE_READ;
        $pageLayout = $this->layoutSvc()->resolveLayout($section);
        $blocksHtml = $this->renderer()->renderBlocks($sectionBlocks, $mode);

        return $this->paginationResolveHtml(
            $this->renderPageHtml(
                $version,
                $section,
                $blocksHtml,
                $mode,
                $pageLayout,
                $pageHeaderConfig,
                $sectionBlocks
            ),
            $tokenContext
        );
    }

    /**
     * @param array<string,mixed> $block
     */
    public function paginationRenderBlock(array $block): string
    {
        return $this->renderer()->renderBlock($block, ControlledPublishingBookRenderer::MODE_READ);
    }

    public function paginationSections(): ControlledPublishingSectionService
    {
        return $this->sections();
    }

    public function paginationEditorNav(): ControlledPublishingEditorNavService
    {
        return $this->editorNav();
    }

    public function paginationBlocks(): ControlledPublishingBlockService
    {
        return $this->blocks();
    }

    public function paginationRevision(): ControlledPublishingRevisionService
    {
        return $this->revision();
    }

    public function paginationLayout(): ControlledPublishingSectionLayoutService
    {
        return $this->layoutSvc();
    }

    public function paginationPageHeader(): ControlledPublishingPageHeaderService
    {
        return $this->pageHeaderSvc();
    }

    public function paginationManualStructure(): ControlledPublishingManualStructureService
    {
        return $this->manualStructure();
    }

    /**
     * @param array<string,mixed> $section
     */
    public function paginationIsCoverSection(array $section): bool
    {
        return $this->isCoverSection($section);
    }

    /**
     * @param array<string,mixed> $section
     */
    public function paginationIsPart0ShellSection(array $section): bool
    {
        return $this->isPart0ShellSection($section);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     * @param list<array<string,mixed>> $sectionBlocks
     */
    public function paginationBuildPart0BodyHtml(
        array $version,
        array $section,
        string $blocksHtml,
        array $sectionBlocks
    ): string {
        return $this->buildPart0BodyHtml(
            $version,
            $section,
            $blocksHtml,
            $sectionBlocks,
            ControlledPublishingBookRenderer::MODE_READ
        );
    }
}
