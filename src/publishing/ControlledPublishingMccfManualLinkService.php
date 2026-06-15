<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookSectionIndexService.php';
require_once __DIR__ . '/ControlledPublishingMccfLinkedManualService.php';
require_once __DIR__ . '/ControlledPublishingMccfBcaaViewService.php';

/**
 * Browse live published manuals and maintain MCCF requirement → manual section links.
 */
final class ControlledPublishingMccfManualLinkService
{
    /** @var array<string,array{label:string,version_label:string}> */
    private const MANUAL_BOOKS = array(
        'OM' => array(
            'label' => 'Operations Manual (OM) Rev 6.0',
            'version_label' => '6.0',
        ),
        'OMM' => array(
            'label' => 'Organization Management Manual (OMM) Rev 4.0',
            'version_label' => '4.0',
        ),
    );

    private ControlledPublishingBookSectionIndexService $index;
    private ControlledPublishingMccfLinkedManualService $linkedManual;

    public function __construct(private PDO $pdo)
    {
        $this->index = new ControlledPublishingBookSectionIndexService($this->pdo);
        $this->linkedManual = new ControlledPublishingMccfLinkedManualService($this->pdo);
    }

    /**
     * @return list<array{manual_code:string,label:string,version_label:string,book_version_id:int}>
     */
    public function listBooks(): array
    {
        $books = array();
        foreach (self::MANUAL_BOOKS as $manualCode => $def) {
            $bookVersionId = $this->index->resolveBookVersionId($manualCode);
            if ($bookVersionId <= 0) {
                continue;
            }
            $books[] = array(
                'manual_code' => $manualCode,
                'label' => (string)$def['label'],
                'version_label' => (string)$def['version_label'],
                'book_version_id' => $bookVersionId,
            );
        }

        return $books;
    }

    /**
     * @return list<array{part:string,label:string}>
     */
    public function listParts(string $manualCode): array
    {
        $bookVersionId = $this->index->resolveBookVersionId($manualCode);

        return $bookVersionId > 0 ? $this->index->listParts($bookVersionId) : array();
    }

    /**
     * @return list<array{chapter:string,label:string,title:string}>
     */
    public function listChapters(string $manualCode, string $part): array
    {
        $bookVersionId = $this->index->resolveBookVersionId($manualCode);
        $partInt = (int)trim($part);
        if ($bookVersionId <= 0 || $partInt <= 0) {
            return array();
        }

        $out = array();
        foreach ($this->index->listChapters($bookVersionId, $partInt) as $chapter) {
            $out[] = array(
                'chapter' => (string)($chapter['chapter'] ?? ''),
                'label' => (string)($chapter['label'] ?? ''),
                'title' => (string)($chapter['title'] ?? ''),
            );
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSections(string $manualCode, string $part, string $chapter, ?string $parentSectionRef = null): array
    {
        $bookVersionId = $this->index->resolveBookVersionId($manualCode);
        $partInt = (int)trim($part);
        if ($bookVersionId <= 0 || $partInt <= 0 || trim($chapter) === '') {
            return array();
        }

        return $this->index->listSections($bookVersionId, $partInt, trim($chapter), $parentSectionRef);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLinksForRequirement(int $requirementId): array
    {
        if ($requirementId <= 0) {
            return array();
        }

        $links = array();
        foreach ($this->linkedManual->linkedSectionsForRequirement($requirementId) as $section) {
            $manualCode = strtoupper(trim((string)($section['manual_code'] ?? 'OM')));
            $part = trim((string)($section['manual_part'] ?? ''));
            $sec = trim((string)($section['section_ref'] ?? ''));
            $title = trim((string)($section['title'] ?? ''));
            $bookLabel = ControlledPublishingMccfBcaaViewService::bookVersionLabel($manualCode);
            $label = $bookLabel . ' Part ' . $part . ' §' . $sec;
            if ($title !== '') {
                $label .= ' — ' . $title;
            }

            $links[] = array(
                'id' => (int)($section['link_id'] ?? 0),
                'link_type' => (string)($section['link_type'] ?? 'PRIMARY'),
                'confidence' => (string)($section['confidence'] ?? ''),
                'notes' => (string)($section['notes'] ?? ''),
                'excerpt_key' => (string)($section['excerpt_key'] ?? ''),
                'excerpt_id' => (int)($section['excerpt_id'] ?? 0),
                'manual_code' => $manualCode,
                'manual_part' => $part,
                'section_ref' => $sec,
                'excerpt_title' => $title,
                'excerpt_preview' => mb_substr(trim((string)($section['body_text'] ?? '')), 0, 300),
                'display_label' => $label,
                'book_version_id' => (int)($section['book_version_id'] ?? 0),
                'section_picker_id' => ControlledPublishingBookSectionIndexService::sectionPickerId(
                    (int)($section['book_version_id'] ?? 0),
                    (int)$part,
                    $sec
                ),
            );
        }

        return $links;
    }

    /**
     * @return array{ok:bool,link?:array<string,mixed>,error?:string}
     */
    public function addLinkBySection(
        int $requirementId,
        string $manualCode,
        string $part,
        string $sectionRef,
        string $linkType = 'PRIMARY',
        ?string $notes = null
    ): array {
        $requirement = $this->loadRequirement($requirementId);
        if ($requirement === null) {
            return array('ok' => false, 'error' => 'Requirement not found.');
        }

        $manualCode = strtoupper(trim($manualCode));
        $part = trim($part);
        $sectionRef = rtrim(trim($sectionRef), '.');
        $bookVersionId = $this->index->resolveBookVersionId($manualCode);
        if ($bookVersionId <= 0 || $part === '' || $sectionRef === '') {
            return array('ok' => false, 'error' => 'Manual section not found in live book.');
        }

        $section = $this->index->getSection($bookVersionId, (int)$part, $sectionRef);
        if ($section === null) {
            return array('ok' => false, 'error' => 'Manual section not found in live book.');
        }

        $versionLabel = $this->index->versionLabelForManual($manualCode);
        $linkKey = ControlledPublishingBookSectionIndexService::makeLinkKey($manualCode, $versionLabel, $part, $sectionRef);
        $linkType = $this->normalizeLinkType($linkType);

        $existing = $this->findLinkBySection($requirementId, $bookVersionId, $manualCode, $part, $sectionRef, $linkType);
        if ($existing !== null) {
            $this->reactivateLinkRow((int)$existing['id'], $linkKey, $manualCode, $part, $sectionRef, $bookVersionId, (string)($section['stable_anchor'] ?? ''), $notes);

            return array('ok' => true, 'link' => $this->getLinkById((int)$existing['id']));
        }

        $hash = hash('sha256', (string)$requirement['requirement_key'] . '|' . $linkKey . '|' . $linkType . '|MANUAL');
        $this->insertLinkRow(
            $requirement,
            $requirementId,
            $linkKey,
            $linkType,
            $notes,
            $hash,
            $bookVersionId,
            $manualCode,
            $part,
            $sectionRef,
            (string)($section['stable_anchor'] ?? '')
        );

        return array('ok' => true, 'link' => $this->getLinkById((int)$this->pdo->lastInsertId()));
    }

    /**
     * Backward-compatible entry point: accepts section_picker_id or legacy excerpt_id (ignored when section fields provided).
     *
     * @return array{ok:bool,link?:array<string,mixed>,error?:string}
     */
    public function addLink(int $requirementId, int $excerptId, string $linkType = 'PRIMARY', ?string $notes = null): array
    {
        return array('ok' => false, 'error' => 'Use addLinkBySection with live book section_ref.');
    }

    /**
     * @param array{section_picker_id?:string,manual_code?:string,part?:string,section_ref?:string,link_type?:string,notes?:string|null} $fields
     * @return array{ok:bool,link?:array<string,mixed>,error?:string}
     */
    public function updateLink(int $linkId, array $fields): array
    {
        $link = $this->getLinkById($linkId);
        if ($link === null) {
            return array('ok' => false, 'error' => 'Link not found.');
        }

        $manualCode = strtoupper(trim((string)($fields['manual_code'] ?? $link['manual_code'] ?? 'OM')));
        $part = trim((string)($fields['part'] ?? $link['manual_part'] ?? ''));
        $sectionRef = rtrim(trim((string)($fields['section_ref'] ?? $link['section_ref'] ?? '')), '.');
        $linkType = isset($fields['link_type']) ? $this->normalizeLinkType((string)$fields['link_type']) : (string)($link['link_type'] ?? 'PRIMARY');
        $notes = array_key_exists('notes', $fields) ? $fields['notes'] : ($link['notes'] ?? null);

        if ($sectionRef === '' && !empty($fields['section_picker_id'])) {
            $resolved = $this->resolvePickerId((string)$fields['section_picker_id']);
            if ($resolved !== null) {
                $manualCode = $resolved['manual_code'];
                $part = $resolved['part'];
                $sectionRef = $resolved['section_ref'];
            }
        }

        $bookVersionId = $this->index->resolveBookVersionId($manualCode);
        $section = $this->index->getSection($bookVersionId, (int)$part, $sectionRef);
        if ($bookVersionId <= 0 || $section === null) {
            return array('ok' => false, 'error' => 'Manual section not found in live book.');
        }

        $linkKey = ControlledPublishingBookSectionIndexService::makeLinkKey(
            $manualCode,
            $this->index->versionLabelForManual($manualCode),
            $part,
            $sectionRef
        );

        $sql = ControlledPublishingMccfLinkedManualService::bookLinkColumnsPresent($this->pdo)
            ? "UPDATE ipca_canonical_requirement_excerpt_links
               SET excerpt_key = :excerpt_key,
                   book_version_id = :book_version_id,
                   manual_code = :manual_code,
                   manual_part = :manual_part,
                   section_ref = :section_ref,
                   stable_anchor = :stable_anchor,
                   link_type = :link_type,
                   confidence = 'MANUAL',
                   notes = :notes,
                   source_status = 'active',
                   last_synced_at = CURRENT_TIMESTAMP,
                   updated_at = CURRENT_TIMESTAMP
               WHERE id = :id"
            : "UPDATE ipca_canonical_requirement_excerpt_links
               SET excerpt_key = :excerpt_key,
                   link_type = :link_type,
                   confidence = 'MANUAL',
                   notes = :notes,
                   source_status = 'active',
                   last_synced_at = CURRENT_TIMESTAMP,
                   updated_at = CURRENT_TIMESTAMP
               WHERE id = :id";

        $params = array(
            ':id' => $linkId,
            ':excerpt_key' => $linkKey,
            ':link_type' => $linkType,
            ':notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : 'Updated manually in MCCF browser',
        );
        if (ControlledPublishingMccfLinkedManualService::bookLinkColumnsPresent($this->pdo)) {
            $params[':book_version_id'] = $bookVersionId;
            $params[':manual_code'] = $manualCode;
            $params[':manual_part'] = $part;
            $params[':section_ref'] = $sectionRef;
            $params[':stable_anchor'] = (string)($section['stable_anchor'] ?? '');
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

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

    /**
     * @param array<string,mixed> $requirement
     */
    private function insertLinkRow(
        array $requirement,
        int $requirementId,
        string $linkKey,
        string $linkType,
        ?string $notes,
        string $hash,
        int $bookVersionId,
        string $manualCode,
        string $part,
        string $sectionRef,
        string $stableAnchor
    ): void {
        if (ControlledPublishingMccfLinkedManualService::bookLinkColumnsPresent($this->pdo)) {
            $stmt = $this->pdo->prepare("
                INSERT INTO ipca_canonical_requirement_excerpt_links (
                  source_set_id, source_document_id, requirement_id, excerpt_id,
                  book_version_id, manual_code, manual_part, section_ref, stable_anchor,
                  requirement_key, excerpt_key, link_type, confidence, notes,
                  source_link_id, source_hash, source_status, last_synced_at
                ) VALUES (
                  :source_set_id, :source_document_id, :requirement_id, NULL,
                  :book_version_id, :manual_code, :manual_part, :section_ref, :stable_anchor,
                  :requirement_key, :excerpt_key, :link_type, 'MANUAL', :notes,
                  NULL, :source_hash, 'active', CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute(array(
                ':source_set_id' => (int)$requirement['source_set_id'],
                ':source_document_id' => (int)$requirement['source_document_id'],
                ':requirement_id' => $requirementId,
                ':book_version_id' => $bookVersionId,
                ':manual_code' => $manualCode,
                ':manual_part' => $part,
                ':section_ref' => $sectionRef,
                ':stable_anchor' => $stableAnchor !== '' ? $stableAnchor : null,
                ':requirement_key' => (string)$requirement['requirement_key'],
                ':excerpt_key' => $linkKey,
                ':link_type' => $linkType,
                ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : 'Added manually in MCCF browser',
                ':source_hash' => $hash,
            ));

            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_canonical_requirement_excerpt_links (
              source_set_id, source_document_id, requirement_id, excerpt_id,
              requirement_key, excerpt_key, link_type, confidence, notes,
              source_link_id, source_hash, source_status, last_synced_at
            ) VALUES (
              :source_set_id, :source_document_id, :requirement_id, 0,
              :requirement_key, :excerpt_key, :link_type, 'MANUAL', :notes,
              NULL, :source_hash, 'active', CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute(array(
            ':source_set_id' => (int)$requirement['source_set_id'],
            ':source_document_id' => (int)$requirement['source_document_id'],
            ':requirement_id' => $requirementId,
            ':requirement_key' => (string)$requirement['requirement_key'],
            ':excerpt_key' => $linkKey,
            ':link_type' => $linkType,
            ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : 'Added manually in MCCF browser',
            ':source_hash' => $hash,
        ));
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
     * @return array{id:int,source_status:string}|null
     */
    private function findLinkBySection(
        int $requirementId,
        int $bookVersionId,
        string $manualCode,
        string $part,
        string $sectionRef,
        string $linkType
    ): ?array {
        if (ControlledPublishingMccfLinkedManualService::bookLinkColumnsPresent($this->pdo)) {
            $stmt = $this->pdo->prepare("
                SELECT id, source_status
                FROM ipca_canonical_requirement_excerpt_links
                WHERE requirement_id = :requirement_id
                  AND book_version_id = :book_version_id
                  AND section_ref = :section_ref
                  AND link_type = :link_type
                LIMIT 1
            ");
            $stmt->execute(array(
                ':requirement_id' => $requirementId,
                ':book_version_id' => $bookVersionId,
                ':section_ref' => $sectionRef,
                ':link_type' => $linkType,
            ));
        } else {
            $versionLabel = $this->index->versionLabelForManual($manualCode);
            $linkKey = ControlledPublishingBookSectionIndexService::makeLinkKey(
                $manualCode,
                $versionLabel,
                $part,
                $sectionRef
            );
            $stmt = $this->pdo->prepare("
                SELECT id, source_status
                FROM ipca_canonical_requirement_excerpt_links
                WHERE requirement_id = :requirement_id
                  AND excerpt_key = :excerpt_key
                  AND link_type = :link_type
                LIMIT 1
            ");
            $stmt->execute(array(
                ':requirement_id' => $requirementId,
                ':excerpt_key' => $linkKey,
                ':link_type' => $linkType,
            ));
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function reactivateLinkRow(
        int $linkId,
        string $linkKey,
        string $manualCode,
        string $part,
        string $sectionRef,
        int $bookVersionId,
        string $stableAnchor,
        ?string $notes
    ): void {
        $params = array(
            ':id' => $linkId,
            ':excerpt_key' => $linkKey,
            ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : 'Re-linked manually in MCCF browser',
        );

        if (ControlledPublishingMccfLinkedManualService::bookLinkColumnsPresent($this->pdo)) {
            $stmt = $this->pdo->prepare("
                UPDATE ipca_canonical_requirement_excerpt_links
                SET excerpt_key = :excerpt_key,
                    book_version_id = :book_version_id,
                    manual_code = :manual_code,
                    manual_part = :manual_part,
                    section_ref = :section_ref,
                    stable_anchor = :stable_anchor,
                    confidence = 'MANUAL',
                    notes = :notes,
                    source_status = 'active',
                    last_synced_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $params[':book_version_id'] = $bookVersionId;
            $params[':manual_code'] = $manualCode;
            $params[':manual_part'] = $part;
            $params[':section_ref'] = $sectionRef;
            $params[':stable_anchor'] = $stableAnchor !== '' ? $stableAnchor : null;
            $stmt->execute($params);

            return;
        }

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
        $stmt->execute($params);
    }

    /**
     * @return array{manual_code:string,part:string,section_ref:string}|null
     */
    private function resolvePickerId(string $pickerId): ?array
    {
        $pickerId = trim($pickerId);
        if ($pickerId === '' || !preg_match('/^bv(\d+)-p(\d+)-(.+)$/', $pickerId, $m)) {
            return null;
        }

        $bookVersionId = (int)$m[1];
        $manualCode = $this->index->manualCodeForBookVersionId($bookVersionId);
        if ($manualCode === '') {
            return null;
        }

        return array(
            'book_version_id' => $bookVersionId,
            'part' => (string)(int)$m[2],
            'section_ref' => str_replace('_', '.', $m[3]),
            'manual_code' => $manualCode,
        );
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
            SELECT id
            FROM ipca_canonical_requirement_excerpt_links
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $linkId));
        if (!$stmt->fetchColumn()) {
            return null;
        }

        foreach ($this->listLinksForRequirement((int)$this->linkRequirementId($linkId)) as $link) {
            if ((int)($link['id'] ?? 0) === $linkId) {
                return $link;
            }
        }

        return null;
    }

    private function linkRequirementId(int $linkId): int
    {
        $stmt = $this->pdo->prepare('SELECT requirement_id FROM ipca_canonical_requirement_excerpt_links WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $linkId));

        return (int)$stmt->fetchColumn();
    }

    private function normalizeLinkType(string $linkType): string
    {
        $linkType = strtoupper(trim($linkType));

        return in_array($linkType, array('PRIMARY', 'SUPPORTING'), true) ? $linkType : 'PRIMARY';
    }
}
