<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingDocxReader.php';
require_once __DIR__ . '/ControlledPublishingBlockService.php';
require_once __DIR__ . '/ControlledPublishingBookStyleService.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/ControlledPublishingPart0PageService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingLepService.php';

/**
 * Imports Apple Pages / Word DOCX part exports into canonical excerpts and controlled book blocks.
 */
final class ControlledPublishingDocxImportService
{
    /** @var array<string,string> */
    private const PART0_SECTION_MAP = array(
        '0.1' => 'lep',
        '0.2' => 'revision_system',
        '0.3' => 'amendment_list',
        '0.4' => 'distribution_list',
        '0.5' => 'abbreviations',
        '0.6' => 'definitions',
        '0.7' => 'highlights',
    );

    private ControlledPublishingDocxReader $reader;

    public function __construct(
        private PDO $pdo,
        private ControlledPublishingFoundationService $foundation,
        private ControlledPublishingSectionService $sections,
        private ControlledPublishingBlockService $blocks,
        private ControlledPublishingManualStructureService $manualStructure,
        private ControlledPublishingPart0PageService $part0Pages,
        private ControlledPublishingBookStyleService $styleService,
        private ControlledPublishingLepService $lepService,
        ?ControlledPublishingDocxReader $reader = null
    ) {
        $this->reader = $reader ?? new ControlledPublishingDocxReader();
    }

    /**
     * @param array<int,string> $partFiles manual_part => absolute path
     * @return array<string,mixed>
     */
    public function preview(int $versionId, array $partFiles): array
    {
        $version = $this->requireDraftVersion($versionId);
        $summary = $this->emptySummary($version);

        foreach ($partFiles as $manualPart => $path) {
            $parsed = $this->reader->parseFile($path, (int)$manualPart);
            $partSummary = $this->summarizeParsedPart($parsed);
            $summary['parts'][(int)$manualPart] = $partSummary;
            $summary['totals']['headings'] += $partSummary['headings'];
            $summary['totals']['tables'] += $partSummary['tables'];
            $summary['totals']['images'] += $partSummary['images'];
            $summary['totals']['lists'] += $partSummary['lists'];
            $summary['totals']['paragraphs'] += $partSummary['paragraphs'];
            $summary['warnings'] = array_merge($summary['warnings'], $partSummary['warnings']);
        }

        ksort($summary['parts']);
        return $summary;
    }

    /**
     * @param array<int,string> $partFiles manual_part => absolute path
     * @return array<string,mixed>
     */
    public function apply(int $versionId, array $partFiles, bool $force = true, ?int $actorUserId = null): array
    {
        $version = $this->requireDraftVersion($versionId);
        $bookKey = strtolower((string)$version['book_key']);
        $manualCode = strtoupper(trim((string)($version['manual_code'] ?? $version['book_key'] ?? 'OM')));
        $versionLabel = (string)$version['version_label'];
        $sourceSetId = $this->manualStructure->resolveManualSourceSetIdPublic($versionId);
        if ($sourceSetId <= 0) {
            throw new RuntimeException('Link a manual canonical source set before importing.');
        }

        $bookStyles = $this->styleService->resolveFromVersion($version);
        $standardTable = $this->styleService->resolveStandardTableStyle($bookStyles);
        $textTable = is_array($bookStyles['table_styles']['text'] ?? null)
            ? $bookStyles['table_styles']['text']
            : $standardTable;

        $this->foundation->ensureTemplates($actorUserId);
        $this->foundation->scaffoldVersionSections($versionId, $actorUserId);

        $stats = array(
            'canonical_excerpts' => 0,
            'blocks_created' => 0,
            'images_uploaded' => 0,
            'part0_pages_updated' => 0,
            'parts_imported' => 0,
            'warnings' => array(),
        );

        ksort($partFiles);
        foreach ($partFiles as $manualPart => $path) {
            $manualPart = (int)$manualPart;
            $parsed = $this->reader->parseFile($path, $manualPart);
            $stats['warnings'] = array_merge($stats['warnings'], $parsed['warnings']);

            if ($manualPart === 0) {
                $result = $this->importPart0(
                    $versionId,
                    $parsed['nodes'],
                    $standardTable,
                    $textTable,
                    $force,
                    $actorUserId
                );
                $stats['blocks_created'] += $result['blocks_created'];
                $stats['images_uploaded'] += $result['images_uploaded'];
                $stats['part0_pages_updated'] += $result['part0_pages_updated'];
                $stats['canonical_excerpts'] += $this->upsertPart0CanonicalExcerpts(
                    $sourceSetId,
                    $manualCode,
                    $versionLabel,
                    $parsed['nodes']
                );
            } else {
                $excerptCount = $this->upsertContentCanonicalExcerpts(
                    $sourceSetId,
                    $manualCode,
                    $versionLabel,
                    $manualPart,
                    $parsed['nodes']
                );
                $stats['canonical_excerpts'] += $excerptCount;
            }
            $stats['parts_imported']++;
        }

        $this->manualStructure->syncVersionStructure($versionId, $actorUserId);

        foreach ($partFiles as $manualPart => $path) {
            $manualPart = (int)$manualPart;
            if ($manualPart <= 0) {
                continue;
            }
            $parsed = $this->reader->parseFile($path, $manualPart);
            $result = $this->importContentPartBlocks(
                $versionId,
                $version,
                $manualPart,
                $parsed['nodes'],
                $standardTable,
                $textTable,
                $bookKey,
                $versionLabel,
                $force,
                $actorUserId
            );
            $stats['blocks_created'] += $result['blocks_created'];
            $stats['images_uploaded'] += $result['images_uploaded'];
            $stats['warnings'] = array_merge($stats['warnings'], $result['warnings']);
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function emptySummary(array $version): array
    {
        return array(
            'version_id' => (int)$version['id'],
            'book_key' => (string)$version['book_key'],
            'version_label' => (string)$version['version_label'],
            'parts' => array(),
            'totals' => array(
                'headings' => 0,
                'paragraphs' => 0,
                'tables' => 0,
                'images' => 0,
                'lists' => 0,
            ),
            'warnings' => array(),
        );
    }

    /**
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function summarizeParsedPart(array $parsed): array
    {
        $summary = array(
            'manual_part' => (int)$parsed['manual_part'],
            'headings' => 0,
            'paragraphs' => 0,
            'tables' => 0,
            'images' => 0,
            'lists' => 0,
            'warnings' => is_array($parsed['warnings'] ?? null) ? $parsed['warnings'] : array(),
        );

        foreach ($parsed['nodes'] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string)($node['type'] ?? '');
            if ($type === 'paragraph') {
                if (($node['section_ref'] ?? '') !== '') {
                    $summary['headings']++;
                } else {
                    $summary['paragraphs']++;
                }
                if (!empty($node['is_bullet'])) {
                    $summary['lists']++;
                }
            } elseif ($type === 'table') {
                $summary['tables']++;
            } elseif ($type === 'image') {
                $summary['images']++;
            }
        }

        return $summary;
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
            throw new RuntimeException('Released versions cannot be imported.');
        }
        return $version;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     */
    private function upsertContentCanonicalExcerpts(
        int $sourceSetId,
        string $manualCode,
        string $versionLabel,
        int $manualPart,
        array $nodes
    ): int {
        $sections = $this->groupContentSections($nodes, false);
        $count = 0;
        foreach ($sections as $section) {
            $ref = (string)($section['section_ref'] ?? '');
            $title = (string)($section['title'] ?? '');
            if ($ref === '' || !ControlledPublishingDocxReader::isPlausibleManualSectionRef($ref, $title, $manualPart)) {
                continue;
            }
            $body = $this->buildCanonicalBodyText($section);
            $this->upsertCanonicalExcerpt(
                $sourceSetId,
                $manualCode,
                $versionLabel,
                $manualPart,
                $ref,
                $title,
                $body,
                'docx_import_part_' . $manualPart
            );
            $count++;
        }
        return $count;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     */
    private function upsertPart0CanonicalExcerpts(
        int $sourceSetId,
        string $manualCode,
        string $versionLabel,
        array $nodes
    ): int {
        $sections = $this->groupContentSections($nodes, true);
        $count = 0;
        foreach ($sections as $section) {
            $ref = (string)($section['section_ref'] ?? '');
            $title = (string)($section['title'] ?? '');
            if ($ref === '' || !ControlledPublishingDocxReader::isPlausibleManualSectionRef($ref, $title, 0)) {
                continue;
            }
            $this->upsertCanonicalExcerpt(
                $sourceSetId,
                $manualCode,
                $versionLabel,
                0,
                $ref,
                (string)($section['title'] ?? ''),
                $this->buildCanonicalBodyText($section),
                'docx_import_part_0'
            );
            $count++;
        }
        return $count;
    }

    private function upsertCanonicalExcerpt(
        int $sourceSetId,
        string $manualCode,
        string $versionLabel,
        int $manualPart,
        string $sectionRef,
        string $title,
        string $bodyText,
        string $sourceFile
    ): void {
        $bodyText = trim($bodyText);
        if ($bodyText === '' && $title === '') {
            return;
        }

        $revToken = str_replace('.', '_', $versionLabel);
        $excerptKey = $manualCode . $revToken . '_P' . $manualPart . '_' . str_replace('.', '_', $sectionRef);
        $contentHash = hash('sha256', $bodyText);

        $docId = $this->resolveSourceDocumentId($sourceSetId);
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_canonical_excerpts
                (source_set_id, source_document_id, excerpt_key, excerpt_key_norm, manual_code, manual_rev,
                 manual_part, section_ref, title, body_text, source_file, content_hash, source_hash, source_status, last_synced_at)
            VALUES
                (:source_set_id, :source_document_id, :excerpt_key, :excerpt_key_norm, :manual_code, :manual_rev,
                 :manual_part, :section_ref, :title, :body_text, :source_file, :content_hash, :content_hash, 'active', CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                body_text = VALUES(body_text),
                source_file = VALUES(source_file),
                content_hash = VALUES(content_hash),
                source_hash = VALUES(source_hash),
                source_status = 'active',
                last_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':source_document_id' => $docId,
            ':excerpt_key' => $excerptKey,
            ':excerpt_key_norm' => $excerptKey,
            ':manual_code' => $manualCode,
            ':manual_rev' => $versionLabel,
            ':manual_part' => (string)$manualPart,
            ':section_ref' => $sectionRef,
            ':title' => mb_substr($title, 0, 500),
            ':body_text' => $bodyText,
            ':source_file' => $sourceFile,
            ':content_hash' => $contentHash,
        ));
    }

    private function resolveSourceDocumentId(int $sourceSetId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT source_document_id
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
            ORDER BY id
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $fallback = $this->pdo->prepare("
            SELECT id FROM ipca_canonical_documents
            WHERE source_set_id = :source_set_id
            ORDER BY id
            LIMIT 1
        ");
        $fallback->execute(array(':source_set_id' => $sourceSetId));
        $id = (int)$fallback->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException('No canonical document found for the linked manual source set.');
        }
        return $id;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function groupContentSections(array $nodes, bool $part0): array
    {
        $sections = array();
        $current = null;

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'paragraph' && ($node['section_ref'] ?? '') !== '') {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = array(
                    'section_ref' => (string)$node['section_ref'],
                    'title' => (string)($node['section_title'] ?? ''),
                    'nodes' => array($node),
                );
                continue;
            }
            if ($current === null) {
                continue;
            }
            $current['nodes'][] = $node;
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        if (!$part0) {
            return $sections;
        }

        return $sections;
    }

    /**
     * @param array<string,mixed> $section
     */
    private function buildCanonicalBodyText(array $section): string
    {
        $ref = (string)($section['section_ref'] ?? '');
        $title = (string)($section['title'] ?? '');
        $lines = array();
        if ($ref !== '' && $title !== '') {
            $lines[] = $ref . ' ' . $title;
        }

        foreach (is_array($section['nodes'] ?? null) ? $section['nodes'] : array() as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string)($node['type'] ?? '');
            if ($type === 'paragraph') {
                if (($node['section_ref'] ?? '') !== '') {
                    continue;
                }
                $text = trim((string)($node['text'] ?? ''));
                if ($text !== '') {
                    $lines[] = $text;
                }
            } elseif ($type === 'table') {
                foreach (is_array($node['rows'] ?? null) ? $node['rows'] : array() as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lines[] = implode("\t", array_map('trim', $row));
                }
            }
        }

        return trim(implode("\n\n", $lines));
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed> $version
     * @param array<string,mixed> $standardTable
     * @param array<string,mixed> $textTable
     * @return array{blocks_created:int,images_uploaded:int,warnings:list<string>}
     */
    private function importContentPartBlocks(
        int $versionId,
        array $version,
        int $manualPart,
        array $nodes,
        array $standardTable,
        array $textTable,
        string $bookKey,
        string $versionLabel,
        bool $force,
        ?int $actorUserId
    ): array {
        $blocksCreated = 0;
        $imagesUploaded = 0;
        $warnings = array();

        $sections = $this->groupContentSections($nodes, false);
        $byChapter = array();
        foreach ($sections as $section) {
            $ref = (string)($section['section_ref'] ?? '');
            $title = (string)($section['title'] ?? '');
            if ($ref === '' || !preg_match('/^(\d+)/', $ref, $m)) {
                continue;
            }
            if (!ControlledPublishingDocxReader::isPlausibleManualSectionRef($ref, $title, $manualPart)) {
                continue;
            }
            $chapter = (int)$m[1];
            if (!isset($byChapter[$chapter])) {
                $byChapter[$chapter] = array();
            }
            $byChapter[$chapter][] = $section;
        }

        foreach ($byChapter as $chapterNumber => $chapterSections) {
            $sectionId = $this->resolveChapterSectionId($versionId, $manualPart, $chapterNumber);
            if ($sectionId <= 0) {
                $warnings[] = 'No chapter section found for part ' . $manualPart . ' chapter ' . $chapterNumber;
                continue;
            }

            if ($force) {
                $this->clearAuthorBlocks($sectionId);
            }

            $pendingList = array();
            $flushList = function () use (&$pendingList, $versionId, $sectionId, $actorUserId, &$blocksCreated): void {
                if ($pendingList === array()) {
                    return;
                }
                $this->blocks->createBlock($versionId, $sectionId, 'list', array(
                    'ordered' => false,
                    'items' => $pendingList,
                ), $actorUserId);
                $blocksCreated++;
                $pendingList = array();
            };

            foreach ($chapterSections as $section) {
                foreach (is_array($section['nodes'] ?? null) ? $section['nodes'] : array() as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    $type = (string)($node['type'] ?? '');

                    if ($type === 'paragraph') {
                        $text = trim((string)($node['text'] ?? ''));
                        if ($text === '') {
                            continue;
                        }
                        if (!empty($node['is_bullet']) && ($node['section_ref'] ?? '') === '') {
                            $pendingList[] = $text;
                            continue;
                        }
                        $flushList();

                        if (($node['section_ref'] ?? '') !== '') {
                            $style = (string)($node['paragraph_style'] ?? 'body');
                            $headingText = (string)($node['section_title'] ?? $text);
                            $this->blocks->createBlock($versionId, $sectionId, 'paragraph', array(
                                'html' => '<p>' . htmlspecialchars($headingText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
                                'paragraph_style' => $style,
                                'canonical_section_ref' => (string)$node['section_ref'],
                            ), $actorUserId);
                            $blocksCreated++;
                            continue;
                        }

                        $this->blocks->createBlock($versionId, $sectionId, 'paragraph', array(
                            'html' => $this->textToHtml($text),
                            'paragraph_style' => 'body',
                        ), $actorUserId);
                        $blocksCreated++;
                        continue;
                    }

                    if ($type === 'table') {
                        $flushList();
                        $payload = $this->tablePayloadFromRows(
                            is_array($node['rows'] ?? null) ? $node['rows'] : array(),
                            'standard',
                            $standardTable
                        );
                        $this->blocks->createBlock($versionId, $sectionId, 'table', $payload, $actorUserId);
                        $blocksCreated++;
                        continue;
                    }

                    if ($type === 'image') {
                        $flushList();
                        $url = $this->storeImageBytes(
                            $bookKey,
                            $versionLabel,
                            (string)($node['bytes'] ?? ''),
                            (string)($node['mime'] ?? 'image/png'),
                            (string)($node['ext'] ?? 'png')
                        );
                        $this->blocks->createBlock($versionId, $sectionId, 'image', array(
                            'url' => $url,
                            'alt' => (string)($node['alt'] ?? 'Manual figure'),
                            'width_pct' => (int)($node['width_pct'] ?? 100),
                        ), $actorUserId);
                        $blocksCreated++;
                        $imagesUploaded++;
                    }
                }
            }
            $flushList();
        }

        return array(
            'blocks_created' => $blocksCreated,
            'images_uploaded' => $imagesUploaded,
            'warnings' => $warnings,
        );
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed> $standardTable
     * @param array<string,mixed> $textTable
     * @return array{blocks_created:int,images_uploaded:int,part0_pages_updated:int,warnings:list<string>}
     */
    private function importPart0(
        int $versionId,
        array $nodes,
        array $standardTable,
        array $textTable,
        bool $force,
        ?int $actorUserId
    ): array {
        $version = $this->foundation->getVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Book version not found.');
        }
        $bookKey = strtolower((string)$version['book_key']);
        $versionLabel = (string)$version['version_label'];

        $sections = $this->groupContentSections($nodes, true);
        $blocksCreated = 0;
        $imagesUploaded = 0;
        $part0Updated = 0;

        foreach ($sections as $section) {
            $ref = (string)($section['section_ref'] ?? '');
            $pageKey = self::PART0_SECTION_MAP[$ref] ?? '';
            if ($pageKey === '') {
                continue;
            }

            if (in_array($pageKey, ControlledPublishingPart0PageService::STRUCTURED_PAGE_KEYS, true)) {
                $this->importPart0StructuredPage($versionId, $pageKey, $section, $textTable);
                $part0Updated++;
                continue;
            }

            if ($pageKey === 'lep') {
                $this->importPart0LepPage($versionId, $section, $actorUserId);
                $part0Updated++;
                continue;
            }

            $sectionId = $this->sectionIdByKey($versionId, $pageKey);
            if ($sectionId <= 0) {
                continue;
            }
            if ($force) {
                $this->clearAuthorBlocks($sectionId);
            }

            $pendingList = array();
            $flushList = function () use (&$pendingList, $versionId, $sectionId, $actorUserId, &$blocksCreated): void {
                if ($pendingList === array()) {
                    return;
                }
                $this->blocks->createBlock($versionId, $sectionId, 'list', array(
                    'ordered' => false,
                    'items' => $pendingList,
                ), $actorUserId);
                $blocksCreated++;
                $pendingList = array();
            };

            foreach (is_array($section['nodes'] ?? null) ? $section['nodes'] : array() as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = (string)($node['type'] ?? '');

                if ($type === 'paragraph') {
                    $text = trim((string)($node['text'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    if (($node['section_ref'] ?? '') !== '') {
                        continue;
                    }
                    if (!empty($node['is_bullet'])) {
                        $pendingList[] = $text;
                        continue;
                    }
                    $flushList();
                    $this->blocks->createBlock($versionId, $sectionId, 'paragraph', array(
                        'html' => $this->textToHtml($text),
                        'paragraph_style' => 'body',
                    ), $actorUserId);
                    $blocksCreated++;
                    continue;
                }

                if ($type === 'table') {
                    $flushList();
                    continue;
                }

                if ($type === 'image') {
                    $flushList();
                    $url = $this->storeImageBytes(
                        $bookKey,
                        $versionLabel,
                        (string)($node['bytes'] ?? ''),
                        (string)($node['mime'] ?? 'image/png'),
                        (string)($node['ext'] ?? 'png')
                    );
                    $this->blocks->createBlock($versionId, $sectionId, 'image', array(
                        'url' => $url,
                        'alt' => (string)($node['alt'] ?? 'Manual figure'),
                        'width_pct' => (int)($node['width_pct'] ?? 100),
                    ), $actorUserId);
                    $blocksCreated++;
                    $imagesUploaded++;
                }
            }
            $flushList();
        }

        return array(
            'blocks_created' => $blocksCreated,
            'images_uploaded' => $imagesUploaded,
            'part0_pages_updated' => $part0Updated,
            'warnings' => array(),
        );
    }

    /**
     * @param array<string,mixed> $section
     */
    private function importPart0LepPage(int $versionId, array $section, ?int $actorUserId): void
    {
        $existing = $this->lepService->resolveFromVersion($this->requireDraftVersion($versionId));
        $payload = array(
            'certification_text' => $existing['certification_text'],
            'on_behalf_text' => $existing['on_behalf_text'],
            'effective_parts' => $existing['effective_parts'],
        );

        $tables = array();
        foreach (is_array($section['nodes'] ?? null) ? $section['nodes'] : array() as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'table') {
                $tables[] = is_array($node['rows'] ?? null) ? $node['rows'] : array();
                continue;
            }
            if (($node['type'] ?? '') !== 'paragraph' || ($node['section_ref'] ?? '') !== '') {
                continue;
            }
            $text = trim((string)($node['text'] ?? ''));
            if ($text === '' || !empty($node['is_bullet'])) {
                continue;
            }
            if (stripos($text, 'certify') !== false && strlen($text) > 40) {
                $payload['certification_text'] = $text;
            } elseif (stripos($text, 'on behalf') !== false) {
                $payload['on_behalf_text'] = $text;
            }
        }

        $effectiveParts = $this->parseEffectivePartsTable($tables);
        if ($effectiveParts !== array()) {
            $payload['effective_parts'] = $effectiveParts;
        }

        $this->lepService->saveLepPageForVersion($versionId, $payload, $actorUserId);
    }

    /**
     * @param list<list<list<string>>> $tables
     * @return list<array<string,string>>
     */
    private function parseEffectivePartsTable(array $tables): array
    {
        foreach ($tables as $rows) {
            if ($rows === array()) {
                continue;
            }
            $header = array_map(static fn(string $v): string => strtoupper(trim($v)), $rows[0]);
            $partIdx = array_search('PART', $header, true);
            if ($partIdx === false) {
                $partIdx = 0;
            }
            $pagesIdx = array_search('PAGES', $header, true);
            if ($pagesIdx === false) {
                $pagesIdx = 1;
            }
            $dateIdx = array_search('DATE', $header, true);
            if ($dateIdx === false) {
                $dateIdx = 2;
            }
            $revIdx = array_search('REVISION', $header, true);
            if ($revIdx === false) {
                $revIdx = 3;
            }

            if (!in_array('PART', $header, true) && !in_array('PAGES', $header, true)) {
                continue;
            }

            $out = array();
            for ($i = 1, $c = count($rows); $i < $c; $i++) {
                $row = $rows[$i];
                $part = trim((string)($row[(int)$partIdx] ?? ''));
                if ($part === '') {
                    continue;
                }
                $out[] = array(
                    'part' => $part,
                    'label' => '',
                    'pages' => trim((string)($row[(int)$pagesIdx] ?? '')),
                    'date' => trim((string)($row[(int)$dateIdx] ?? '')),
                    'revision' => trim((string)($row[(int)$revIdx] ?? '')),
                );
            }
            if ($out !== array()) {
                return $out;
            }
        }

        return array();
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $textTable
     */
    private function importPart0StructuredPage(int $versionId, string $pageKey, array $section, array $textTable): void
    {
        $tables = array();
        foreach (is_array($section['nodes'] ?? null) ? $section['nodes'] : array() as $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'table') {
                $tables[] = is_array($node['rows'] ?? null) ? $node['rows'] : array();
            }
        }

        if ($pageKey === 'amendment_list') {
            $rows = $this->parseAmendmentTable($tables);
            if ($rows !== array()) {
                $this->part0Pages->saveAmendmentListForVersion($versionId, array(
                    'rows' => $rows,
                    'empty_rows' => 0,
                    'synced_from' => 'docx_import',
                ));
            }
            return;
        }

        if ($pageKey === 'distribution_list') {
            $rows = $this->parseDistributionTable($tables);
            if ($rows !== array()) {
                $this->part0Pages->saveDistributionListForVersion($versionId, array(
                    'rows' => $rows,
                    'empty_rows' => 0,
                ));
            }
            return;
        }

        if ($pageKey === 'abbreviations') {
            $entries = $this->parseTwoColumnTable($tables, 'abbreviation', 'definition');
            if ($entries !== array()) {
                $this->part0Pages->saveAbbreviationsPageForVersion($versionId, array(
                    'entries' => $entries,
                    'empty_rows' => 0,
                    'synced_from' => 'docx_import',
                ));
            }
            return;
        }

        if ($pageKey === 'definitions') {
            $entries = $this->parseTwoColumnTable($tables, 'term', 'definition');
            if ($entries !== array()) {
                $this->part0Pages->saveDefinitionsForVersion($versionId, array(
                    'entries' => $entries,
                    'empty_rows' => 0,
                ));
            }
        }
    }

    /**
     * @param list<list<list<string>>> $tables
     * @return list<array<string,string>>
     */
    private function parseAmendmentTable(array $tables): array
    {
        foreach ($tables as $rows) {
            if ($rows === array()) {
                continue;
            }
            $header = array_map(static fn(string $v): string => strtoupper(trim($v)), $rows[0]);
            if (!in_array('REVISION NR', $header, true) && !in_array('REVISION', $header, true)) {
                continue;
            }
            $map = array();
            foreach ($header as $idx => $label) {
                $map[$label] = $idx;
            }
            $out = array();
            for ($i = 1, $c = count($rows); $i < $c; $i++) {
                $row = $rows[$i];
                $revision = trim((string)($row[$map['REVISION NR'] ?? $map['REVISION'] ?? 0] ?? ''));
                if ($revision === '') {
                    continue;
                }
                $out[] = array(
                    'revision_nr' => $revision,
                    'reason' => trim((string)($row[$map['REASON'] ?? 1] ?? '')),
                    'revision_date' => trim((string)($row[$map['REVISION DATE'] ?? 2] ?? '')),
                    'effective_date' => trim((string)($row[$map['EFFECTIVE DATE'] ?? 3] ?? '')),
                    'date_incorp' => trim((string)($row[$map['DATE INCORP.'] ?? $map['DATE INCORPORATED'] ?? 4] ?? '')),
                    'incorp_by' => trim((string)($row[$map['INCORP. BY'] ?? $map['INCORPORATED BY'] ?? 5] ?? '')),
                );
            }
            if ($out !== array()) {
                return $out;
            }
        }
        return array();
    }

    /**
     * @param list<list<list<string>>> $tables
     * @return list<array<string,string>>
     */
    private function parseDistributionTable(array $tables): array
    {
        foreach ($tables as $rows) {
            if ($rows === array()) {
                continue;
            }
            $header = array_map(static fn(string $v): string => strtoupper(trim($v)), $rows[0]);
            if (!in_array('COPY NR', $header, true) && !in_array('COPY NO', $header, true)) {
                continue;
            }
            $copyIdx = array_search('COPY NR', $header, true);
            if ($copyIdx === false) {
                $copyIdx = array_search('COPY NO', $header, true);
            }
            $issueIdx = array_search('ISSUE TO', $header, true);
            if ($issueIdx === false) {
                $issueIdx = 1;
            }
            $out = array();
            for ($i = 1, $c = count($rows); $i < $c; $i++) {
                $row = $rows[$i];
                $copy = trim((string)($row[(int)$copyIdx] ?? ''));
                $issue = trim((string)($row[(int)$issueIdx] ?? ''));
                if ($copy === '' && $issue === '') {
                    continue;
                }
                $out[] = array(
                    'copy_nr' => $copy,
                    'issue_to' => $issue,
                );
            }
            if ($out !== array()) {
                return $out;
            }
        }
        return array();
    }

    /**
     * @param list<list<list<string>>> $tables
     * @return list<array<string,string>>
     */
    private function parseTwoColumnTable(array $tables, string $leftKey, string $rightKey): array
    {
        $out = array();
        foreach ($tables as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            for ($i = 1, $c = count($rows); $i < $c; $i++) {
                $row = $rows[$i];
                if (count($row) < 2) {
                    continue;
                }
                $left = trim((string)$row[0]);
                $right = trim((string)$row[1]);
                if ($left === '' || $right === '') {
                    continue;
                }
                $left = rtrim($left, ':');
                $out[] = array(
                    $leftKey => $left,
                    $rightKey => $right,
                );
            }
            if ($out !== array()) {
                return $out;
            }
        }
        return $out;
    }

    /**
     * @param list<list<string>> $rows
     * @param array<string,mixed> $styleDef
     * @return array<string,mixed>
     */
    private function tablePayloadFromRows(array $rows, string $kind, array $styleDef): array
    {
        if ($rows === array()) {
            $rows = array(array('Column 1', 'Column 2'), array('', ''));
        }

        $headers = array_map('trim', $rows[0]);
        $bodyRows = array();
        for ($i = 1, $c = count($rows); $i < $c; $i++) {
            $bodyRows[] = array_map('trim', $rows[$i]);
        }
        if ($bodyRows === array()) {
            $bodyRows = array(array_fill(0, max(1, count($headers)), ''));
        }

        $colCount = max(count($headers), 1);
        $headers = array_pad(array_slice($headers, 0, $colCount), $colCount, '');
        $normalizedRows = array();
        foreach ($bodyRows as $row) {
            $normalizedRows[] = array_pad(array_slice($row, 0, $colCount), $colCount, '');
        }

        $colWidth = max(60, min(600, (int)floor(840 / $colCount)));

        return array(
            'title' => '',
            'has_title_row' => false,
            'headers' => $headers,
            'rows' => $normalizedRows,
            'col_widths' => array_fill(0, $colCount, $colWidth),
            'border_width' => (string)($styleDef['border_width'] ?? 'medium'),
            'border_color' => (string)($styleDef['border_color'] ?? '#94a3b8'),
            'cell_bg' => (string)($styleDef['cell_bg'] ?? '#ffffff'),
            'table_style_kind' => $kind,
            'table_align' => 'left',
        );
    }

    private function textToHtml(string $text): string
    {
        $paragraphs = preg_split("/\n\s*\n/", $text) ?: array($text);
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $lines = preg_split("/\r\n|\n/", $paragraph) ?: array($paragraph);
            $inner = implode('<br>', array_map(
                static fn(string $line): string => htmlspecialchars(trim($line), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $lines
            ));
            $html .= '<p>' . $inner . '</p>';
        }
        if ($html === '') {
            $html = '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        return $html;
    }

    private function storeImageBytes(
        string $bookKey,
        string $versionLabel,
        string $bytes,
        string $mime,
        string $ext
    ): string {
        if ($bytes === '') {
            throw new RuntimeException('Empty image payload.');
        }

        $versionLabel = str_replace('.', '_', $versionLabel);
        $objectKey = 'publishing/' . $bookKey . '/' . $versionLabel . '/import_' . bin2hex(random_bytes(12)) . '.' . $ext;

        require_once __DIR__ . '/../spaces.php';
        try {
            $put = cw_spaces_put_object($objectKey, $bytes, $mime);
            $url = trim((string)($put['cdn_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        } catch (Throwable) {
            // Fall through to local storage for dev environments.
        }

        $localDir = dirname(__DIR__, 2) . '/public/uploads/publishing/' . $bookKey . '/' . $versionLabel;
        if (!is_dir($localDir) && !mkdir($localDir, 0755, true) && !is_dir($localDir)) {
            throw new RuntimeException('Could not create local upload directory.');
        }
        $filename = basename($objectKey);
        $fullPath = $localDir . '/' . $filename;
        if (file_put_contents($fullPath, $bytes) === false) {
            throw new RuntimeException('Failed to store imported image.');
        }
        return '/uploads/publishing/' . $bookKey . '/' . $versionLabel . '/' . $filename;
    }

    private function resolveChapterSectionId(int $versionId, int $manualPart, int $chapterNumber): int
    {
        foreach ($this->sections->listFlatSections($versionId) as $row) {
            $meta = $this->decodeJsonMeta($row['metadata_json'] ?? null);
            if ((int)($meta['manual_part'] ?? 0) !== $manualPart) {
                continue;
            }
            if ((int)($meta['chapter_number'] ?? 0) === $chapterNumber) {
                return (int)($row['id'] ?? 0);
            }
        }
        return 0;
    }

    private function sectionIdByKey(int $versionId, string $sectionKey): int
    {
        foreach ($this->sections->listFlatSections($versionId) as $row) {
            if ((string)($row['section_key'] ?? '') === $sectionKey) {
                return (int)($row['id'] ?? 0);
            }
        }
        return 0;
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

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}
