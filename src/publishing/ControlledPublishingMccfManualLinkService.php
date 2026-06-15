<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingManualStructureService.php';
require_once __DIR__ . '/ControlledPublishingFoundationService.php';
require_once __DIR__ . '/ControlledPublishingMccfBcaaViewService.php';
require_once __DIR__ . '/ControlledPublishingSectionService.php';
require_once __DIR__ . '/ControlledPublishingDocxReader.php';

/**
 * Browse controlled manuals and maintain MCCF requirement → manual excerpt links.
 */
final class ControlledPublishingMccfManualLinkService
{
    /** @var array<string,array{source_set_key:string,label:string,version_label:string}> */
    private const MANUAL_BOOKS = array(
        'OM' => array(
            'source_set_key' => 'MANUAL:OM:6_0',
            'label' => 'Operations Manual (OM) Rev 6.0',
            'version_label' => '6.0',
        ),
        'OMM' => array(
            'source_set_key' => 'MANUAL:OMM:4_0',
            'label' => 'Organization Management Manual (OMM) Rev 4.0',
            'version_label' => '4.0',
        ),
    );

    private ControlledPublishingManualStructureService $structure;

    public function __construct(private PDO $pdo)
    {
        $foundation = new ControlledPublishingFoundationService($this->pdo);
        $this->structure = new ControlledPublishingManualStructureService(
            $this->pdo,
            $foundation,
            new ControlledPublishingSectionService($this->pdo)
        );
    }

    /**
     * @return list<array{manual_code:string,label:string,version_label:string,source_set_id:int}>
     */
    public function listBooks(): array
    {
        $books = array();
        foreach (self::MANUAL_BOOKS as $manualCode => $def) {
            $sourceSetId = $this->resolveManualSourceSetId($manualCode);
            if ($sourceSetId <= 0) {
                continue;
            }
            $books[] = array(
                'manual_code' => $manualCode,
                'label' => (string)$def['label'],
                'version_label' => (string)$def['version_label'],
                'source_set_id' => $sourceSetId,
            );
        }

        return $books;
    }

    /**
     * @return list<array{part:string,label:string}>
     */
    public function listParts(string $manualCode): array
    {
        $manualCode = strtoupper(trim($manualCode));
        $sourceSetId = $this->resolveManualSourceSetId($manualCode);
        if ($sourceSetId <= 0) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT manual_part
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND source_status = 'active'
              AND manual_part IS NOT NULL
              AND manual_part <> ''
            ORDER BY CAST(manual_part AS UNSIGNED), manual_part
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => $manualCode,
        ));

        $parts = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $part = trim((string)($row['manual_part'] ?? ''));
            if ($part === '') {
                continue;
            }
            $parts[] = array(
                'part' => $part,
                'label' => 'Part ' . $part,
            );
        }

        return $parts;
    }

    /**
     * @return list<array{chapter:string,label:string,title:string}>
     */
    public function listChapters(string $manualCode, string $part): array
    {
        $manualCode = strtoupper(trim($manualCode));
        $part = trim($part);
        $sourceSetId = $this->resolveManualSourceSetId($manualCode);
        if ($sourceSetId <= 0 || $part === '') {
            return array();
        }

        $chapters = $this->structure->listChaptersForPart($manualCode, $sourceSetId, (int)$part);
        $out = array();
        foreach ($chapters as $chapter) {
            $number = (int)($chapter['chapter_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }
            $title = trim((string)($chapter['title'] ?? ''));
            $label = 'Chapter ' . $number;
            if ($title !== '' && !preg_match('/^Chapter\s+' . $number . '$/i', $title)) {
                $label .= ' — ' . $title;
            }
            $out[] = array(
                'chapter' => (string)$number,
                'label' => $label,
                'title' => $title,
            );
        }

        return $out;
    }

    /**
     * @return list<array{id:int,excerpt_key:string,section_ref:string,title:string,label:string,has_children:bool,depth:int,preview:string}>
     */
    public function listSections(string $manualCode, string $part, string $chapter, ?string $parentSectionRef = null): array
    {
        $manualCode = strtoupper(trim($manualCode));
        $part = trim($part);
        $chapter = trim($chapter);
        $parentSectionRef = $parentSectionRef !== null ? trim($parentSectionRef) : null;
        $sourceSetId = $this->resolveManualSourceSetId($manualCode);
        if ($sourceSetId <= 0 || $part === '' || $chapter === '') {
            return array();
        }

        $allRefs = $this->loadSectionRefsForChapter($sourceSetId, $manualCode, $part, $chapter);
        if ($allRefs === array()) {
            return array();
        }

        $parentDepth = $parentSectionRef === null || $parentSectionRef === ''
            ? 0
            : substr_count($parentSectionRef, '.') + 1;

        $sections = array();
        foreach ($allRefs as $row) {
            $ref = (string)$row['section_ref'];
            $depth = substr_count($ref, '.') + ($ref === $chapter ? 0 : 0);
            if (preg_match('/^(\d+)$/', $ref, $m)) {
                $depth = 0;
            } elseif (preg_match('/^(\d+(?:\.\d+)*)/', $ref, $m)) {
                $depth = substr_count($m[1], '.');
            }

            $isChild = false;
            if ($parentSectionRef === null || $parentSectionRef === '') {
                $isChild = ($ref === $chapter) || (bool)preg_match('/^' . preg_quote($chapter, '/') . '\.\d+$/', $ref);
            } else {
                $isChild = ($ref === $parentSectionRef)
                    || (bool)preg_match('/^' . preg_quote($parentSectionRef, '/') . '\.\d+$/', $ref);
            }

            if (!$isChild) {
                continue;
            }

            $childPattern = $ref === $chapter
                ? '/^' . preg_quote($chapter, '/') . '\.\d+$/'
                : '/^' . preg_quote($ref, '/') . '\.\d+$/';
            $hasChildren = false;
            foreach ($allRefs as $candidate) {
                if (preg_match($childPattern, (string)$candidate['section_ref'])) {
                    $hasChildren = true;
                    break;
                }
            }

            $title = $this->resolveSectionTitle($row);
            $label = '§' . $ref;
            if ($title !== '') {
                $label .= ' — ' . $title;
            }

            $sections[] = array(
                'id' => (int)$row['id'],
                'excerpt_key' => (string)$row['excerpt_key'],
                'section_ref' => $ref,
                'title' => $title,
                'label' => $label,
                'has_children' => $hasChildren,
                'depth' => $depth,
                'preview' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($row['body_text'] ?? '')) ?? ''), 0, 220),
                'source_status' => (string)($row['source_status'] ?? 'active'),
            );
        }

        usort($sections, static function (array $a, array $b): int {
            return self::compareSectionRefs((string)$a['section_ref'], (string)$b['section_ref']);
        });

        return $sections;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLinksForRequirement(int $requirementId): array
    {
        if ($requirementId <= 0) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT
              l.id,
              l.link_type,
              l.confidence,
              l.notes,
              l.excerpt_key,
              l.source_status,
              e.id AS excerpt_id,
              e.manual_code,
              e.manual_part,
              e.section_ref,
              e.title AS excerpt_title,
              LEFT(e.body_text, 300) AS excerpt_preview
            FROM ipca_canonical_requirement_excerpt_links l
            INNER JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
            WHERE l.requirement_id = :requirement_id
              AND l.source_status = 'active'
              AND e.source_status = 'active'
            ORDER BY l.link_type, e.manual_code, e.manual_part, e.section_ref, e.excerpt_key
        ");
        $stmt->execute(array(':requirement_id' => $requirementId));

        $links = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $manualCode = strtoupper(trim((string)($row['manual_code'] ?? 'OM')));
            $part = trim((string)($row['manual_part'] ?? ''));
            $sec = trim((string)($row['section_ref'] ?? ''));
            $title = trim((string)($row['excerpt_title'] ?? ''));
            $bookLabel = ControlledPublishingMccfBcaaViewService::bookVersionLabel($manualCode);
            $label = $bookLabel . ' Part ' . $part . ' §' . $sec;
            if ($title !== '') {
                $label .= ' — ' . $title;
            }
            $row['display_label'] = $label;
            $links[] = $row;
        }

        return $links;
    }

    /**
     * @return array{ok:bool,link?:array<string,mixed>,error?:string}
     */
    public function addLink(int $requirementId, int $excerptId, string $linkType = 'PRIMARY', ?string $notes = null): array
    {
        $requirement = $this->loadRequirement($requirementId);
        if ($requirement === null) {
            return array('ok' => false, 'error' => 'Requirement not found.');
        }

        $excerpt = $this->loadExcerpt($excerptId, true);
        if ($excerpt === null) {
            return array('ok' => false, 'error' => 'Manual section not found.');
        }

        $this->reactivateExcerptIfRetired($excerptId);

        $linkType = $this->normalizeLinkType($linkType);
        $existing = $this->findLink($requirementId, $excerptId, $linkType);
        if ($existing !== null) {
            $this->reactivateLink((int)$existing['id'], $excerpt, $notes);
            return array('ok' => true, 'link' => $this->getLinkById((int)$existing['id']));
        }

        $hash = hash('sha256', (string)$requirement['requirement_key'] . '|' . (string)$excerpt['excerpt_key'] . '|' . $linkType . '|MANUAL');
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_canonical_requirement_excerpt_links (
              source_set_id, source_document_id, requirement_id, excerpt_id,
              requirement_key, excerpt_key, link_type, confidence, notes,
              source_link_id, source_hash, source_status, last_synced_at
            ) VALUES (
              :source_set_id, :source_document_id, :requirement_id, :excerpt_id,
              :requirement_key, :excerpt_key, :link_type, 'MANUAL', :notes,
              NULL, :source_hash, 'active', CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute(array(
            ':source_set_id' => (int)$requirement['source_set_id'],
            ':source_document_id' => (int)$requirement['source_document_id'],
            ':requirement_id' => $requirementId,
            ':excerpt_id' => $excerptId,
            ':requirement_key' => (string)$requirement['requirement_key'],
            ':excerpt_key' => (string)$excerpt['excerpt_key'],
            ':link_type' => $linkType,
            ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : 'Added manually in MCCF browser',
            ':source_hash' => $hash,
        ));

        return array('ok' => true, 'link' => $this->getLinkById((int)$this->pdo->lastInsertId()));
    }

    /**
     * @param array{excerpt_id?:int,link_type?:string,notes?:string|null} $fields
     * @return array{ok:bool,link?:array<string,mixed>,error?:string}
     */
    public function updateLink(int $linkId, array $fields): array
    {
        $link = $this->getLinkById($linkId);
        if ($link === null) {
            return array('ok' => false, 'error' => 'Link not found.');
        }

        $excerptId = isset($fields['excerpt_id']) ? (int)$fields['excerpt_id'] : (int)$link['excerpt_id'];
        $linkType = isset($fields['link_type']) ? $this->normalizeLinkType((string)$fields['link_type']) : (string)$link['link_type'];
        $notes = array_key_exists('notes', $fields) ? $fields['notes'] : ($link['notes'] ?? null);

        $excerpt = $this->loadExcerpt($excerptId, true);
        if ($excerpt === null) {
            return array('ok' => false, 'error' => 'Manual section not found.');
        }

        $this->reactivateExcerptIfRetired($excerptId);

        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_requirement_excerpt_links
            SET excerpt_id = :excerpt_id,
                excerpt_key = :excerpt_key,
                link_type = :link_type,
                confidence = 'MANUAL',
                notes = :notes,
                source_status = 'active',
                last_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':id' => $linkId,
            ':excerpt_id' => $excerptId,
            ':excerpt_key' => (string)$excerpt['excerpt_key'],
            ':link_type' => $linkType,
            ':notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : 'Updated manually in MCCF browser',
        ));

        return array('ok' => true, 'link' => $this->getLinkById($linkId));
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function deleteLink(int $linkId): array
    {
        $link = $this->getLinkById($linkId);
        if ($link === null) {
            return array('ok' => false, 'error' => 'Link not found.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_requirement_excerpt_links
            SET source_status = 'retired',
                confidence = 'MANUAL',
                notes = CONCAT(COALESCE(notes, ''), ' | Removed manually in MCCF browser'),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(':id' => $linkId));

        return array('ok' => true);
    }

    /**
     * @return array{ok:bool,manual_section_ref?:string,error?:string}
     */
    public function updateManualSectionRef(int $requirementId, string $manualSectionRef): array
    {
        $requirement = $this->loadRequirement($requirementId);
        if ($requirement === null) {
            return array('ok' => false, 'error' => 'Requirement not found.');
        }

        $manualSectionRef = trim($manualSectionRef);
        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_requirements
            SET manual_section_ref = :manual_section_ref,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':id' => $requirementId,
            ':manual_section_ref' => $manualSectionRef !== '' ? $manualSectionRef : null,
        ));

        return array('ok' => true, 'manual_section_ref' => $manualSectionRef);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getRequirementEditorContext(int $requirementId): ?array
    {
        $requirement = $this->loadRequirement($requirementId);
        if ($requirement === null) {
            return null;
        }

        return array(
            'requirement_id' => $requirementId,
            'requirement_key' => (string)$requirement['requirement_key'],
            'subject' => (string)$requirement['subject'],
            'manual_code' => (string)$requirement['manual_code'],
            'manual_section_ref' => (string)($requirement['manual_section_ref'] ?? ''),
            'links' => $this->listLinksForRequirement($requirementId),
            'books' => $this->listBooks(),
        );
    }

    private function resolveManualSourceSetId(string $manualCode): int
    {
        $manualCode = strtoupper(trim($manualCode));
        $sourceSetKey = self::MANUAL_BOOKS[$manualCode]['source_set_key'] ?? '';
        if ($sourceSetKey === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_canonical_source_sets
            WHERE source_set_key = :source_set_key
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_key' => $sourceSetKey));

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array{id:int,excerpt_key:string,section_ref:string,title:string,body_text:string,source_status:string}>
     */
    private function loadSectionRefsForChapter(int $sourceSetId, string $manualCode, string $part, string $chapter): array
    {
        $chapter = trim($chapter);
        $pattern = '^' . preg_quote($chapter, '/') . '(\\.[0-9]+)*$';
        $stmt = $this->pdo->prepare("
            SELECT id, excerpt_key, section_ref, title, body_text, source_status
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND manual_part = :manual_part
              AND source_status IN ('active', 'retired')
              AND section_ref REGEXP :chapter_pattern
            ORDER BY section_ref, FIELD(source_status, 'active', 'retired'), id
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => strtoupper(trim($manualCode)),
            ':manual_part' => $part,
            ':chapter_pattern' => $pattern,
        ));

        /** @var array<string,array{id:int,excerpt_key:string,section_ref:string,title:string,body_text:string,source_status:string}> $byRef */
        $byRef = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $ref = trim((string)($row['section_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $row['section_ref'] = $ref;
            if (!isset($byRef[$ref]) || (string)($byRef[$ref]['source_status'] ?? '') !== 'active') {
                $byRef[$ref] = $row;
            }
        }

        $this->supplementSectionRefsFromBookBlocks($manualCode, $part, $chapter, $byRef);

        return array_values($byRef);
    }

    /**
     * Fill missing subsection refs from published book blocks (canonical_section_ref).
     *
     * @param array<string,array{id:int,excerpt_key:string,section_ref:string,title:string,body_text:string,source_status:string}> $byRef
     */
    private function supplementSectionRefsFromBookBlocks(string $manualCode, string $part, string $chapter, array &$byRef): void
    {
        $bookVersionId = $this->resolveBookVersionId($manualCode);
        if ($bookVersionId <= 0) {
            return;
        }

        $chapterPattern = '/^' . preg_quote($chapter, '/') . '(?:\.[0-9]+)*$/';
        $stmt = $this->pdo->prepare("
            SELECT b.payload_json
            FROM ipca_publishing_book_blocks b
            WHERE b.book_version_id = :version_id
            ORDER BY b.section_id, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $bookVersionId));

        $refsWanted = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $ref = trim((string)($payload['canonical_section_ref'] ?? ''));
            if ($ref === '' || !preg_match($chapterPattern, $ref)) {
                continue;
            }
            $refsWanted[$ref] = true;
        }

        if ($refsWanted === array()) {
            return;
        }

        $sourceSetId = $this->resolveManualSourceSetId($manualCode);
        if ($sourceSetId <= 0) {
            return;
        }

        foreach (array_keys($refsWanted) as $ref) {
            if (isset($byRef[$ref])) {
                continue;
            }
            $excerpt = $this->findExcerptBySectionRef($sourceSetId, $manualCode, $part, $ref, true);
            if ($excerpt !== null) {
                $byRef[$ref] = $excerpt;
            }
        }
    }

    /**
     * @return array{id:int,excerpt_key:string,section_ref:string,title:string,body_text:string,source_status:string}|null
     */
    private function findExcerptBySectionRef(
        int $sourceSetId,
        string $manualCode,
        string $preferredPart,
        string $sectionRef,
        bool $includeRetired = false
    ): ?array {
        $statusSql = $includeRetired ? "source_status IN ('active', 'retired')" : "source_status = 'active'";
        $stmt = $this->pdo->prepare("
            SELECT id, excerpt_key, section_ref, title, body_text, source_status, manual_part
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = :manual_code
              AND section_ref = :section_ref
              AND {$statusSql}
            ORDER BY
              CASE WHEN manual_part <=> :preferred_part THEN 0 ELSE 1 END,
              FIELD(source_status, 'active', 'retired'),
              manual_part,
              id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':source_set_id' => $sourceSetId,
            ':manual_code' => strtoupper(trim($manualCode)),
            ':section_ref' => trim($sectionRef),
            ':preferred_part' => trim($preferredPart),
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function resolveBookVersionId(string $manualCode): int
    {
        $manualCode = strtoupper(trim($manualCode));
        $versionLabel = self::MANUAL_BOOKS[$manualCode]['version_label'] ?? '6.0';
        try {
            $stmt = $this->pdo->prepare("
                SELECT bv.id
                FROM ipca_publishing_book_versions bv
                INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
                WHERE b.book_key = :book_key
                  AND bv.version_label = :version_label
                ORDER BY bv.id DESC
                LIMIT 1
            ");
            $stmt->execute(array(
                ':book_key' => $manualCode,
                ':version_label' => $versionLabel,
            ));

            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveSectionTitle(array $row): string
    {
        $title = ControlledPublishingDocxReader::sanitizeSectionTitle(trim((string)($row['title'] ?? '')));
        if ($title !== '') {
            return $title;
        }

        $bodyText = trim(str_replace('\\n', "\n", (string)($row['body_text'] ?? '')));
        if ($bodyText === '') {
            return '';
        }

        $firstLine = trim(strtok($bodyText, "\n") ?: '');
        if ($firstLine === '') {
            return '';
        }

        $parsed = ControlledPublishingDocxReader::parseSectionHeading($firstLine);
        if ($parsed !== null) {
            return ControlledPublishingDocxReader::sanitizeSectionTitle((string)($parsed['title'] ?? ''));
        }

        return ControlledPublishingDocxReader::sanitizeImportedText($firstLine);
    }

    private function reactivateExcerptIfRetired(int $excerptId): void
    {
        if ($excerptId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_excerpts
            SET source_status = 'active',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND source_status = 'retired'
        ");
        $stmt->execute(array(':id' => $excerptId));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadRequirement(int $requirementId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, source_set_id, source_document_id, requirement_key, manual_code, manual_section_ref, subject
            FROM ipca_canonical_requirements
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $requirementId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadExcerpt(int $excerptId, bool $includeRetired = false): ?array
    {
        $statusSql = $includeRetired ? "source_status IN ('active', 'retired')" : "source_status = 'active'";
        $stmt = $this->pdo->prepare("
            SELECT id, excerpt_key, manual_code, manual_part, section_ref, title, source_status
            FROM ipca_canonical_excerpts
            WHERE id = :id
              AND {$statusSql}
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $excerptId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findLink(int $requirementId, int $excerptId, string $linkType): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, source_status
            FROM ipca_canonical_requirement_excerpt_links
            WHERE requirement_id = :requirement_id
              AND excerpt_id = :excerpt_id
              AND link_type = :link_type
            LIMIT 1
        ");
        $stmt->execute(array(
            ':requirement_id' => $requirementId,
            ':excerpt_id' => $excerptId,
            ':link_type' => $linkType,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $excerpt
     */
    private function reactivateLink(int $linkId, array $excerpt, ?string $notes): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_requirement_excerpt_links
            SET excerpt_key = :excerpt_key,
                confidence = 'MANUAL',
                notes = :notes,
                source_status = 'active',
                last_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':id' => $linkId,
            ':excerpt_key' => (string)$excerpt['excerpt_key'],
            ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : 'Re-linked manually in MCCF browser',
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getLinkById(int $linkId): ?array
    {
        if ($linkId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
              l.id,
              l.link_type,
              l.confidence,
              l.notes,
              l.excerpt_key,
              e.id AS excerpt_id,
              e.manual_code,
              e.manual_part,
              e.section_ref,
              e.title AS excerpt_title
            FROM ipca_canonical_requirement_excerpt_links l
            INNER JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
            WHERE l.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $linkId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $manualCode = strtoupper(trim((string)($row['manual_code'] ?? 'OM')));
        $part = trim((string)($row['manual_part'] ?? ''));
        $sec = trim((string)($row['section_ref'] ?? ''));
        $title = trim((string)($row['excerpt_title'] ?? ''));
        $bookLabel = ControlledPublishingMccfBcaaViewService::bookVersionLabel($manualCode);
        $label = $bookLabel . ' Part ' . $part . ' §' . $sec;
        if ($title !== '') {
            $label .= ' — ' . $title;
        }
        $row['display_label'] = $label;

        return $row;
    }

    private function normalizeLinkType(string $linkType): string
    {
        $linkType = strtoupper(trim($linkType));

        return in_array($linkType, array('PRIMARY', 'SUPPORTING'), true) ? $linkType : 'PRIMARY';
    }

    private static function compareSectionRefs(string $a, string $b): int
    {
        $aParts = array_map('intval', explode('.', $a));
        $bParts = array_map('intval', explode('.', $b));
        $max = max(count($aParts), count($bParts));
        for ($i = 0; $i < $max; $i++) {
            $av = $aParts[$i] ?? 0;
            $bv = $bParts[$i] ?? 0;
            if ($av !== $bv) {
                return $av <=> $bv;
            }
        }

        return strcmp($a, $b);
    }
}
