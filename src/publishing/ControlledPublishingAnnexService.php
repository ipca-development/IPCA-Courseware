<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingDocxImportService.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingLepService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';

/**
 * Annex register, per-annex sections, import (image / DOCX), and revision metadata.
 */
final class ControlledPublishingAnnexService
{
    public const REGISTER_SECTION_KEY = 'annexes_register';
    public const HIGHLIGHTS_SECTION_KEY = 'annexes_highlights';
    public const PARENT_SECTION_KEY = 'annexes';
    public const ANNEX_SECTION_PREFIX = 'annexes_annex_';
    public const MANUAL_PART_ANNEX = 'annex';

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingFoundationService $foundation,
        private ControlledPublishingSectionService $sections,
        private ControlledPublishingBlockService $blocks,
        private ?ControlledPublishingDocxImportService $docxImport = null
    ) {
    }

    /**
     * Ensure annex register + highlights child sections exist under the annexes parent.
     *
     * @return array{register_id:int,highlights_id:int,created:int}
     */
    public function ensureAnnexInfrastructure(int $versionId, ?int $actorUserId = null): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }

        $this->foundation->ensureTemplates($actorUserId);
        $this->foundation->scaffoldVersionSections($versionId, $actorUserId);

        $parentId = $this->sectionIdByKey($versionId, self::PARENT_SECTION_KEY);
        if ($parentId <= 0) {
            throw new RuntimeException('Annexes parent section not found.');
        }

        $this->pdo->prepare("
            UPDATE ipca_publishing_book_sections
            SET is_system_managed = 1,
                section_type = 'annex',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND book_version_id = :version_id
        ")->execute(array(':id' => $parentId, ':version_id' => $versionId));

        $bookKey = (string)$version['book_key'];
        $versionLabel = (string)$version['version_label'];
        $parent = $this->sections->getSection($versionId, $parentId);
        $parentAnchor = (string)($parent['stable_anchor'] ?? 'ANNEXES');

        $created = 0;
        $registerId = $this->ensureChildSystemSection(
            $versionId,
            $parentId,
            $parentAnchor,
            self::REGISTER_SECTION_KEY,
            'Annex Register',
            'annex_register',
            10,
            $bookKey,
            $versionLabel,
            $actorUserId,
            $created
        );
        $highlightsId = $this->ensureChildSystemSection(
            $versionId,
            $parentId,
            $parentAnchor,
            self::HIGHLIGHTS_SECTION_KEY,
            'Annex Highlight of Changes',
            'annex_highlights',
            20,
            $bookKey,
            $versionLabel,
            $actorUserId,
            $created
        );

        return array(
            'register_id' => $registerId,
            'highlights_id' => $highlightsId,
            'created' => $created,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAnnexSections(int $versionId): array
    {
        $parentId = $this->sectionIdByKey($versionId, self::PARENT_SECTION_KEY);
        if ($parentId <= 0) {
            return array();
        }

        $rows = array();
        foreach ($this->listChildSections($versionId, $parentId) as $row) {
            if (!$this->isAnnexContentSection($row)) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, function (array $a, array $b): int {
            return $this->annexNumberFromSection($a) <=> $this->annexNumberFromSection($b);
        });

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRegisterRows(int $versionId): array
    {
        $rows = array();
        foreach ($this->listAnnexSections($versionId) as $section) {
            $meta = $this->decodeAnnexMeta($section);
            $number = (int)($meta['number'] ?? 0);
            $rows[] = array(
                'annex_number' => $number,
                'section_id' => (int)($section['id'] ?? 0),
                'title' => (string)($section['title'] ?? ''),
                'revision' => (string)($meta['revision'] ?? ''),
                'revision_date' => (string)($meta['revision_date'] ?? ''),
                'updated_by' => (string)($meta['updated_by_name'] ?? ''),
                'content_mode' => (string)($meta['content_mode'] ?? ''),
                'orientation' => (string)($meta['page_orientation'] ?? 'portrait'),
            );
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createAnnex(int $versionId, array $input, ?int $actorUserId = null): array
    {
        $version = $this->requireDraftVersion($versionId);
        $this->ensureAnnexInfrastructure($versionId, $actorUserId);

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Annex title is required.');
        }

        $number = (int)($input['annex_number'] ?? 0);
        if ($number <= 0) {
            $number = $this->nextAnnexNumber($versionId);
        }

        $revision = trim((string)($input['revision'] ?? '1.0'));
        if ($revision === '') {
            $revision = '1.0';
        }
        $revisionDate = trim((string)($input['revision_date'] ?? ''));
        if ($revisionDate === '') {
            $revisionDate = date('Y-m-d');
        }
        $orientation = strtolower(trim((string)($input['orientation'] ?? 'portrait')));
        if (!in_array($orientation, array('portrait', 'landscape'), true)) {
            $orientation = 'portrait';
        }
        $contentMode = strtolower(trim((string)($input['content_mode'] ?? 'empty')));
        if (!in_array($contentMode, array('empty', 'image', 'docx'), true)) {
            $contentMode = 'empty';
        }

        $parentId = $this->sectionIdByKey($versionId, self::PARENT_SECTION_KEY);
        $parent = $this->sections->getSection($versionId, $parentId);
        if ($parent === null) {
            throw new RuntimeException('Annexes parent section not found.');
        }

        $sectionKey = $this->annexSectionKey($number);
        if ($this->sectionIdByKey($versionId, $sectionKey) > 0) {
            throw new RuntimeException('Annex ' . str_pad((string)$number, 2, '0', STR_PAD_LEFT) . ' already exists.');
        }

        $navTitle = $this->formatAnnexNavTitle($number, $title);
        $stableAnchor = $this->childAnchor((string)$parent['stable_anchor'], $sectionKey);
        $sortOrder = 100 + ($number * 10);
        $updatedByName = $this->resolveUserDisplayName($actorUserId);

        $metadata = array(
            'annex' => array(
                'number' => $number,
                'revision' => $revision,
                'revision_date' => $revisionDate,
                'updated_by_user_id' => $actorUserId,
                'updated_by_name' => $updatedByName,
                'content_mode' => $contentMode,
                'page_orientation' => $orientation,
                'ocr_status' => 'pending',
                'canonical_excerpt_id' => null,
            ),
            'page_layout' => array(
                'hide_header_footer' => false,
                'orientation' => $orientation,
            ),
        );

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, parent_section_id, section_key, stable_anchor, title, section_type,
                 is_system_managed, is_generated, sort_order, metadata_json, created_by)
            VALUES
                (:book_version_id, :parent_section_id, :section_key, :stable_anchor, :title, 'annex_content',
                 0, 0, :sort_order, :metadata_json, :created_by)
        ");
        $stmt->execute(array(
            ':book_version_id' => $versionId,
            ':parent_section_id' => $parentId,
            ':section_key' => $sectionKey,
            ':stable_anchor' => $stableAnchor,
            ':title' => $navTitle,
            ':sort_order' => $sortOrder,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':created_by' => $actorUserId,
        ));
        $sectionId = (int)$this->pdo->lastInsertId();

        $importStats = array('blocks_created' => 0, 'images_uploaded' => 0, 'ocr_status' => 'skipped');
        if ($contentMode === 'image' && !empty($input['image_path']) && is_readable((string)$input['image_path'])) {
            $importStats = $this->importAnnexImage(
                $versionId,
                $sectionId,
                (string)$input['image_path'],
                true,
                $actorUserId
            );
        } elseif ($contentMode === 'docx' && !empty($input['docx_path']) && is_readable((string)$input['docx_path'])) {
            $importStats = $this->importAnnexDocx(
                $versionId,
                $sectionId,
                (string)$input['docx_path'],
                true,
                $actorUserId
            );
        }

        $this->regenerateRegister($versionId, $actorUserId);

        return array(
            'section_id' => $sectionId,
            'section_key' => $sectionKey,
            'annex_number' => $number,
            'title' => $navTitle,
            'import' => $importStats,
        );
    }

    /**
     * @return array{blocks_created:int,images_uploaded:int,ocr_status:string,canonical_excerpt_id:int}
     */
    public function importAnnexImage(
        int $versionId,
        int $sectionId,
        string $imagePath,
        bool $force,
        ?int $actorUserId = null
    ): array {
        $version = $this->requireDraftVersion($versionId);
        $section = $this->sections->getSection($versionId, $sectionId);
        if ($section === null || !$this->isAnnexContentSection($section)) {
            throw new RuntimeException('Annex section not found.');
        }

        $bytes = file_get_contents($imagePath);
        if ($bytes === false) {
            throw new RuntimeException('Could not read image file.');
        }

        $mime = (string)(mime_content_type($imagePath) ?: '');
        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)),
        };
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
            throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
        }

        require_once __DIR__ . '/../spaces.php';
        global $CDN_BASE;

        $bookKey = strtolower((string)$version['book_key']);
        $versionLabel = str_replace('.', '_', (string)$version['version_label']);
        $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/annex_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $put = cw_spaces_put_object($objectKey, $bytes, $mime !== '' ? $mime : 'image/png');
        $url = (string)($put['cdn_url'] ?? '');
        if ($url === '') {
            $localDir = dirname(__DIR__, 2) . '/public/uploads/publishing/' . $bookKey . '/' . $versionLabel;
            if (!is_dir($localDir) && !mkdir($localDir, 0755, true) && !is_dir($localDir)) {
                throw new RuntimeException('Could not create upload directory.');
            }
            $filename = basename($objectKey);
            file_put_contents($localDir . '/' . $filename, $bytes);
            $url = '/uploads/publishing/' . $bookKey . '/' . $versionLabel . '/' . $filename;
        }

        if ($force) {
            $this->clearAuthorBlocks($sectionId);
        }

        $meta = $this->decodeAnnexMeta($section);
        $this->blocks->createBlock($versionId, $sectionId, 'image', array(
            'url' => $url,
            'alt' => (string)($section['title'] ?? 'Annex'),
            'width_pct' => 100,
        ), $actorUserId);

        $this->updateAnnexMeta($sectionId, array(
            'content_mode' => 'image',
            'source_file_hash' => hash('sha256', $bytes),
        ), $actorUserId);

        $ocrStatus = 'pending';
        $excerptId = 0;
        try {
            $ocrText = $this->extractTextFromImageUrl($url);
            if ($ocrText !== '') {
                $excerptId = $this->upsertAnnexCanonicalExcerpt($version, $section, $ocrText, 'annex_image_import');
                $ocrStatus = 'complete';
            } else {
                $ocrStatus = 'failed';
            }
        } catch (Throwable $e) {
            $ocrStatus = 'failed';
        }

        $this->updateAnnexMeta($sectionId, array(
            'ocr_status' => $ocrStatus,
            'canonical_excerpt_id' => $excerptId > 0 ? $excerptId : null,
        ), $actorUserId);

        $this->regenerateRegister($versionId, $actorUserId);

        return array(
            'blocks_created' => 1,
            'images_uploaded' => 1,
            'ocr_status' => $ocrStatus,
            'canonical_excerpt_id' => $excerptId,
        );
    }

    /**
     * @return array{blocks_created:int,images_uploaded:int,warnings:list<string>}
     */
    public function importAnnexDocx(
        int $versionId,
        int $sectionId,
        string $docxPath,
        bool $force,
        ?int $actorUserId = null
    ): array {
        $version = $this->requireDraftVersion($versionId);
        $section = $this->sections->getSection($versionId, $sectionId);
        if ($section === null || !$this->isAnnexContentSection($section)) {
            throw new RuntimeException('Annex section not found.');
        }

        $importSvc = $this->docxImport ?? new ControlledPublishingDocxImportService(
            $this->pdo,
            $this->foundation,
            $this->sections,
            $this->blocks,
            new ControlledPublishingManualStructureService($this->pdo, $this->foundation, $this->sections, $this->blocks),
            new ControlledPublishingPart0PageService($this->pdo, $this->blocks),
            new ControlledPublishingBookStyleService($this->pdo),
            new ControlledPublishingLepService($this->pdo)
        );

        if ($force) {
            $this->clearAuthorBlocks($sectionId);
        }

        $result = $importSvc->importAnnexSectionContent($versionId, $sectionId, $docxPath, $actorUserId);
        $this->updateAnnexMeta($sectionId, array('content_mode' => 'docx'), $actorUserId);
        $this->regenerateRegister($versionId, $actorUserId);

        return $result;
    }

    /**
     * @return array{section_id:int,blocks_created:int}
     */
    public function regenerateRegister(int $versionId, ?int $actorUserId = null): array
    {
        $this->ensureAnnexInfrastructure($versionId, $actorUserId);
        $registerId = $this->sectionIdByKey($versionId, self::REGISTER_SECTION_KEY);
        if ($registerId <= 0) {
            throw new RuntimeException('Annex register section not found.');
        }

        $this->pdo->prepare("
            DELETE FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id AND is_system_managed = 1
        ")->execute(array(':section_id' => $registerId));

        $rows = $this->listRegisterRows($versionId);
        $payload = array(
            'register_rows' => $rows,
            'generated_at' => gmdate('c'),
        );
        $contentHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $section = $this->sections->getSection($versionId, $registerId);
        $stableBase = (string)($section['stable_anchor'] ?? 'ANNEX-REGISTER');

        $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, 'annex_register_data', :stable_anchor, 'annex_register', 10,
                 :payload_json, :content_hash, 1, :created_by, :created_by)
        ")->execute(array(
            ':book_version_id' => $versionId,
            ':section_id' => $registerId,
            ':stable_anchor' => $stableBase . '-DATA',
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $contentHash,
            ':created_by' => $actorUserId,
        ));

        return array(
            'section_id' => $registerId,
            'blocks_created' => 1,
            'rows' => count($rows),
        );
    }

    /**
     * @return array{section_id:int,blocks_created:int,changes_count:int}
     */
    public function regenerateHighlights(int $versionId, ?int $actorUserId = null): array
    {
        $this->ensureAnnexInfrastructure($versionId, $actorUserId);
        $highlightsId = $this->sectionIdByKey($versionId, self::HIGHLIGHTS_SECTION_KEY);
        if ($highlightsId <= 0) {
            throw new RuntimeException('Annex highlights section not found.');
        }

        require_once __DIR__ . '/ControlledPublishingRevisionService.php';
        $revisionSvc = new ControlledPublishingRevisionService($this->pdo);
        $prior = $revisionSvc->priorVersion($versionId);

        $this->pdo->prepare("
            DELETE FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id AND is_system_managed = 1
        ")->execute(array(':section_id' => $highlightsId));

        $changes = array();
        foreach ($this->listAnnexSections($versionId) as $annexSection) {
            $sectionId = (int)($annexSection['id'] ?? 0);
            $blocks = $this->blocks->listSectionBlocks($sectionId);
            $annotated = $revisionSvc->annotateChangeStatus($versionId, $blocks);
            foreach ($annotated as $block) {
                $status = (string)($block['change_status'] ?? 'unchanged');
                if ($status === 'unchanged') {
                    continue;
                }
                $block['annex_title'] = (string)($annexSection['title'] ?? '');
                $block['annex_number'] = $this->annexNumberFromSection($annexSection);
                $changes[] = $block;
            }
        }

        $section = $this->sections->getSection($versionId, $highlightsId);
        $stableBase = (string)($section['stable_anchor'] ?? 'ANNEX-HIGHLIGHTS');
        $sort = 10;
        $created = 0;

        $summaryPayload = array(
            'text' => 'Annex changes' . ($prior ? ' since version ' . (string)$prior['version_label'] : ''),
            'paragraph_style' => 'body',
        );
        $this->insertHighlightBlock($versionId, $highlightsId, $stableBase, 'summary', 'paragraph', $summaryPayload, $sort, $actorUserId);
        $created++;
        $sort += 10;

        if ($changes === array()) {
            $intro = array(
                'text' => 'No annex content changes detected compared to the prior book version.',
                'paragraph_style' => 'body',
            );
            $this->insertHighlightBlock($versionId, $highlightsId, $stableBase, 'none', 'paragraph', $intro, $sort, $actorUserId);
            $created++;
        } else {
            foreach ($changes as $change) {
                $annexNum = str_pad((string)(int)($change['annex_number'] ?? 0), 2, '0', STR_PAD_LEFT);
                $title = trim((string)($change['annex_title'] ?? ''));
                $status = (string)($change['change_status'] ?? 'modified');
                $label = $status === 'new' ? 'New' : 'Modified';
                $text = 'Annex ' . $annexNum . ($title !== '' ? ' – ' . $title : '') . ': ' . $label . ' content.';
                $this->insertHighlightBlock(
                    $versionId,
                    $highlightsId,
                    $stableBase,
                    'change_' . (int)($change['id'] ?? 0),
                    'paragraph',
                    array('text' => $text, 'paragraph_style' => 'body'),
                    $sort,
                    $actorUserId
                );
                $sort += 10;
                $created++;
            }
        }

        return array(
            'section_id' => $highlightsId,
            'blocks_created' => $created,
            'changes_count' => count($changes),
        );
    }

    /**
     * @param array<string,mixed> $section
     */
    public function isAnnexContentSection(array $section): bool
    {
        $key = (string)($section['section_key'] ?? '');
        return str_starts_with($key, self::ANNEX_SECTION_PREFIX);
    }

    public function isAnnexRegisterSection(array $section): bool
    {
        return (string)($section['section_key'] ?? '') === self::REGISTER_SECTION_KEY;
    }

    public function isAnnexHighlightsSection(array $section): bool
    {
        return (string)($section['section_key'] ?? '') === self::HIGHLIGHTS_SECTION_KEY;
    }

    public function isAnnexSystemOrContentSection(array $section): bool
    {
        $key = (string)($section['section_key'] ?? '');
        return $key === self::REGISTER_SECTION_KEY
            || $key === self::HIGHLIGHTS_SECTION_KEY
            || $this->isAnnexContentSection($section);
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    public function decodeAnnexMeta(array $section): array
    {
        $meta = $this->decodeMeta($section);
        $annex = is_array($meta['annex'] ?? null) ? $meta['annex'] : array();
        $layout = is_array($meta['page_layout'] ?? null) ? $meta['page_layout'] : array();
        if (!empty($layout['orientation']) && empty($annex['page_orientation'])) {
            $annex['page_orientation'] = (string)$layout['orientation'];
        }
        return $annex;
    }

    /**
     * @param array<string,mixed> $section
     */
    public function pageOrientation(array $section): string
    {
        $annex = $this->decodeAnnexMeta($section);
        $orientation = strtolower(trim((string)($annex['page_orientation'] ?? 'portrait')));
        return $orientation === 'landscape' ? 'landscape' : 'portrait';
    }

    public function formatAnnexNavTitle(int $number, string $title): string
    {
        $num = str_pad((string)$number, 2, '0', STR_PAD_LEFT);
        $title = trim($title);
        if ($title === '') {
            return 'Annex ' . $num;
        }
        if (preg_match('/^annex\s+\d+/i', $title) === 1) {
            return $title;
        }
        return 'Annex ' . $num . ' – ' . $title;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function updateAnnexMeta(int $sectionId, array $patch, ?int $actorUserId = null): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_sections WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $sectionId));
        $section = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($section)) {
            throw new RuntimeException('Section not found.');
        }

        $meta = $this->decodeMeta($section);
        $annex = is_array($meta['annex'] ?? null) ? $meta['annex'] : array();
        $meta['annex'] = array_merge($annex, $patch);
        if (isset($patch['page_orientation'])) {
            $layout = is_array($meta['page_layout'] ?? null) ? $meta['page_layout'] : array();
            $layout['orientation'] = (string)$patch['page_orientation'];
            $meta['page_layout'] = $layout;
        }
        if ($actorUserId !== null && $actorUserId > 0) {
            $meta['annex']['updated_by_user_id'] = $actorUserId;
            $meta['annex']['updated_by_name'] = $this->resolveUserDisplayName($actorUserId);
        }

        $this->pdo->prepare("
            UPDATE ipca_publishing_book_sections
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $sectionId,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveRegisterPage(int $versionId): array
    {
        $registerId = $this->sectionIdByKey($versionId, self::REGISTER_SECTION_KEY);
        if ($registerId <= 0) {
            return array('rows' => array());
        }

        foreach ($this->blocks->listSectionBlocks($registerId) as $block) {
            if ((string)($block['block_type'] ?? '') !== 'annex_register') {
                continue;
            }
            $payload = $this->blocks->decodePayload($block);
            if (is_array($payload['register_rows'] ?? null)) {
                return array(
                    'rows' => $payload['register_rows'],
                    'generated_at' => (string)($payload['generated_at'] ?? ''),
                );
            }
        }

        return array('rows' => $this->listRegisterRows($versionId));
    }

    public function runAnnexOcr(int $versionId, int $sectionId, ?int $actorUserId = null): array
    {
        $section = $this->sections->getSection($versionId, $sectionId);
        if ($section === null || !$this->isAnnexContentSection($section)) {
            throw new RuntimeException('Annex section not found.');
        }

        $imageUrl = '';
        foreach ($this->blocks->listSectionBlocks($sectionId) as $block) {
            if ((string)($block['block_type'] ?? '') !== 'image') {
                continue;
            }
            $payload = $this->blocks->decodePayload($block);
            $imageUrl = trim((string)($payload['url'] ?? ''));
            if ($imageUrl !== '') {
                break;
            }
        }
        if ($imageUrl === '') {
            throw new RuntimeException('No image block found in this annex.');
        }

        $version = $this->requireDraftVersion($versionId);
        $ocrText = $this->extractTextFromImageUrl($imageUrl);
        if ($ocrText === '') {
            $this->updateAnnexMeta($sectionId, array('ocr_status' => 'failed'), $actorUserId);
            throw new RuntimeException('OCR returned no text.');
        }

        $excerptId = $this->upsertAnnexCanonicalExcerpt($version, $section, $ocrText, 'annex_ocr');
        $this->updateAnnexMeta($sectionId, array(
            'ocr_status' => 'complete',
            'canonical_excerpt_id' => $excerptId,
        ), $actorUserId);

        return array(
            'ocr_status' => 'complete',
            'canonical_excerpt_id' => $excerptId,
            'text_length' => strlen($ocrText),
        );
    }

    private function nextAnnexNumber(int $versionId): int
    {
        $max = 0;
        foreach ($this->listAnnexSections($versionId) as $section) {
            $max = max($max, $this->annexNumberFromSection($section));
        }
        return $max + 1;
    }

    /**
     * @param array<string,mixed> $section
     */
    private function annexNumberFromSection(array $section): int
    {
        $meta = $this->decodeAnnexMeta($section);
        if (!empty($meta['number'])) {
            return (int)$meta['number'];
        }
        $key = (string)($section['section_key'] ?? '');
        if (preg_match('/^annexes_annex_(\d+)$/', $key, $m) === 1) {
            return (int)$m[1];
        }
        return 0;
    }

    private function annexSectionKey(int $number): string
    {
        return self::ANNEX_SECTION_PREFIX . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
    }

    private function ensureChildSystemSection(
        int $versionId,
        int $parentId,
        string $parentAnchor,
        string $sectionKey,
        string $title,
        string $sectionType,
        int $sortOrder,
        string $bookKey,
        string $versionLabel,
        ?int $actorUserId,
        int &$created
    ): int {
        $existing = $this->sectionIdByKey($versionId, $sectionKey);
        if ($existing > 0) {
            return $existing;
        }

        $stableAnchor = $this->childAnchor($parentAnchor, $sectionKey);
        $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_sections
                (book_version_id, parent_section_id, section_key, stable_anchor, title, section_type,
                 is_system_managed, is_generated, sort_order, created_by)
            VALUES
                (:book_version_id, :parent_id, :section_key, :stable_anchor, :title, :section_type,
                 1, 1, :sort_order, :created_by)
        ")->execute(array(
            ':book_version_id' => $versionId,
            ':parent_id' => $parentId,
            ':section_key' => $sectionKey,
            ':stable_anchor' => $stableAnchor,
            ':title' => $title,
            ':section_type' => $sectionType,
            ':sort_order' => $sortOrder,
            ':created_by' => $actorUserId,
        ));
        $created++;
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $section
     */
    private function upsertAnnexCanonicalExcerpt(array $version, array $section, string $bodyText, string $sourceFile): int
    {
        $sourceSetId = $this->resolveManualSourceSetId((int)$version['id']);
        if ($sourceSetId <= 0) {
            return 0;
        }

        $manualCode = strtoupper(trim((string)($version['manual_code'] ?? $version['book_key'] ?? 'OM')));
        $versionLabel = (string)$version['version_label'];
        $annexMeta = $this->decodeAnnexMeta($section);
        $number = (int)($annexMeta['number'] ?? $this->annexNumberFromSection($section));
        $sectionRef = str_pad((string)$number, 2, '0', STR_PAD_LEFT);
        $title = (string)($section['title'] ?? 'Annex ' . $sectionRef);
        $bodyText = trim($bodyText);
        if ($bodyText === '') {
            return 0;
        }

        $excerptKey = strtoupper($manualCode) . '_' . str_replace('.', '_', $versionLabel) . '_ANNEX_' . $sectionRef;
        $excerptKeyNorm = strtolower(str_replace('_', '-', $excerptKey));
        $contentHash = hash('sha256', $bodyText);
        $docId = $this->resolveSourceDocumentId($sourceSetId);

        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND manual_part = :manual_part
              AND section_ref = :section_ref
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => $manualCode,
            ':manual_part' => self::MANUAL_PART_ANNEX,
            ':section_ref' => $sectionRef,
        ));
        $existingId = (int)$stmt->fetchColumn();

        if ($existingId > 0) {
            $this->pdo->prepare("
                UPDATE ipca_canonical_excerpts
                SET title = :title, body_text = :body_text, source_file = :source_file,
                    content_hash = :content_hash, source_hash = :content_hash,
                    source_status = 'active', last_synced_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(array(
                ':title' => mb_substr($title, 0, 500),
                ':body_text' => $bodyText,
                ':source_file' => $sourceFile,
                ':content_hash' => $contentHash,
                ':id' => $existingId,
            ));
            return $existingId;
        }

        $this->pdo->prepare("
            INSERT INTO ipca_canonical_excerpts
                (source_set_id, source_document_id, excerpt_key, excerpt_key_norm, manual_code, manual_rev,
                 manual_part, section_ref, title, body_text, source_file, content_hash, source_hash, source_status, last_synced_at)
            VALUES
                (:source_set_id, :source_document_id, :excerpt_key, :excerpt_key_norm, :manual_code, :manual_rev,
                 :manual_part, :section_ref, :title, :body_text, :source_file, :content_hash, :content_hash, 'active', CURRENT_TIMESTAMP)
        ")->execute(array(
            ':source_set_id' => $sourceSetId,
            ':source_document_id' => $docId,
            ':excerpt_key' => $excerptKey,
            ':excerpt_key_norm' => $excerptKeyNorm,
            ':manual_code' => $manualCode,
            ':manual_rev' => $versionLabel,
            ':manual_part' => self::MANUAL_PART_ANNEX,
            ':section_ref' => $sectionRef,
            ':title' => mb_substr($title, 0, 500),
            ':body_text' => $bodyText,
            ':source_file' => $sourceFile,
            ':content_hash' => $contentHash,
        ));

        return (int)$this->pdo->lastInsertId();
    }

    private function extractTextFromImageUrl(string $imageUrl): string
    {
        require_once __DIR__ . '/../openai.php';

        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array('extracted_text' => array('type' => 'string')),
            'required' => array('extracted_text'),
        );

        $instructions = 'Extract all readable text from this annex page image. Preserve line breaks and table structure using plain text. Return only the extracted text, no commentary.';

        $payload = array(
            'model' => cw_openai_model(),
            'input' => array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => $instructions))),
                array('role' => 'user', 'content' => array(
                    array('type' => 'input_text', 'text' => 'Extract all text from this annex image for canonical storage.'),
                    array('type' => 'input_image', 'image_url' => $imageUrl),
                )),
            ),
            'text' => array(
                'format' => array(
                    'type' => 'json_schema',
                    'name' => 'annex_ocr_v1',
                    'schema' => $schema,
                    'strict' => true,
                ),
            ),
            'temperature' => 0.1,
        );

        $resp = cw_openai_responses($payload, 120);
        $json = cw_openai_extract_json_text($resp);
        return trim((string)($json['extracted_text'] ?? ''));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function insertHighlightBlock(
        int $versionId,
        int $sectionId,
        string $stableBase,
        string $keySuffix,
        string $blockType,
        array $payload,
        int $sortOrder,
        ?int $actorUserId
    ): void {
        $contentHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, :block_type, :sort_order,
                 :payload_json, :content_hash, 1, :created_by, :created_by)
        ")->execute(array(
            ':book_version_id' => $versionId,
            ':section_id' => $sectionId,
            ':block_key' => 'annex_highlights_' . $keySuffix,
            ':stable_anchor' => $stableBase . '-' . strtoupper($keySuffix),
            ':block_type' => $blockType,
            ':sort_order' => $sortOrder,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $contentHash,
            ':created_by' => $actorUserId,
        ));
    }

    private function clearAuthorBlocks(int $sectionId): void
    {
        foreach ($this->blocks->listSectionBlocks($sectionId) as $block) {
            if (!empty($block['is_system_managed'])) {
                continue;
            }
            $this->blocks->deleteBlock((int)$block['id']);
        }
    }

    private function resolveManualSourceSetId(int $versionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT ss.id
            FROM ipca_publishing_book_version_source_sets vss
            INNER JOIN ipca_canonical_source_sets ss ON ss.id = vss.source_set_id
            WHERE vss.book_version_id = :version_id AND vss.selection_role = 'manual_source'
            ORDER BY vss.id LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return (int)$stmt->fetchColumn();
    }

    private function resolveSourceDocumentId(int $sourceSetId): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_canonical_documents
            WHERE source_set_id = :source_set_id
            ORDER BY id LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : null;
    }

    private function resolveUserDisplayName(?int $userId): string
    {
        if ($userId === null || $userId <= 0) {
            return '';
        }
        $stmt = $this->pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $userId));
        return trim((string)$stmt->fetchColumn());
    }

    /**
     * @return array<string,mixed>
     */
    private function requireDraftVersion(int $versionId): array
    {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        if ((string)($version['lifecycle_status'] ?? '') === 'released') {
            throw new RuntimeException('Released versions cannot be edited.');
        }
        return $version;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listChildSections(int $versionId, int $parentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND parent_section_id = :parent_id
            ORDER BY sort_order, id
        ");
        $stmt->execute(array(':version_id' => $versionId, ':parent_id' => $parentId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    private function sectionIdByKey(int $versionId, string $sectionKey): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId, ':section_key' => $sectionKey));
        return (int)$stmt->fetchColumn();
    }

    private function childAnchor(string $parentAnchor, string $sectionKey): string
    {
        $suffix = strtoupper(str_replace('_', '-', $sectionKey));
        if (strlen($parentAnchor . '-' . $suffix) > 191) {
            $suffix = strtoupper(substr(md5($sectionKey), 0, 12));
        }
        return $parentAnchor . '-' . $suffix;
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
}
