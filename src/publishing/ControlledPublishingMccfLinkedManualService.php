<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBookSectionIndexService.php';
require_once __DIR__ . '/ControlledPublishingMccfBcaaViewService.php';

/**
 * Resolve MCCF requirement manual links against the live published book (not legacy excerpts).
 */
final class ControlledPublishingMccfLinkedManualService
{
    private ControlledPublishingBookSectionIndexService $index;

    /** @var bool|null */
    private static ?bool $bookLinkColumnsPresent = null;

    public function __construct(private PDO $pdo)
    {
        $this->index = new ControlledPublishingBookSectionIndexService($this->pdo);
    }

    public static function bookLinkColumnsPresent(PDO $pdo): bool
    {
        if (self::$bookLinkColumnsPresent !== null) {
            return self::$bookLinkColumnsPresent;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM ipca_canonical_requirement_excerpt_links LIKE 'section_ref'");
            self::$bookLinkColumnsPresent = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            self::$bookLinkColumnsPresent = false;
        }

        return self::$bookLinkColumnsPresent;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function linkedSectionsForRequirement(int $requirementId): array
    {
        $map = $this->linkedSectionsForRequirements(array($requirementId));

        return $map[$requirementId] ?? array();
    }

    /**
     * @param list<int> $requirementIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function linkedSectionsForRequirements(array $requirementIds): array
    {
        $requirementIds = array_values(array_filter(array_map('intval', $requirementIds), static fn(int $id): bool => $id > 0));
        if ($requirementIds === array()) {
            return array();
        }

        $in = implode(',', $requirementIds);
        if (self::bookLinkColumnsPresent($this->pdo)) {
            $stmt = $this->pdo->query("
                SELECT
                  l.id AS link_id,
                  l.requirement_id,
                  l.link_type,
                  l.confidence,
                  l.notes,
                  l.excerpt_key,
                  l.excerpt_id,
                  l.book_version_id,
                  l.manual_code AS link_manual_code,
                  l.manual_part AS link_manual_part,
                  l.section_ref AS link_section_ref,
                  l.stable_anchor,
                  COALESCE(NULLIF(TRIM(l.section_ref), ''), e.section_ref) AS resolved_section_ref,
                  COALESCE(NULLIF(TRIM(l.manual_code), ''), e.manual_code) AS resolved_manual_code,
                  COALESCE(NULLIF(TRIM(l.manual_part), ''), e.manual_part) AS resolved_manual_part,
                  COALESCE(l.book_version_id, 0) AS resolved_book_version_id
                FROM ipca_canonical_requirement_excerpt_links l
                LEFT JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
                WHERE l.requirement_id IN ({$in})
                  AND l.source_status = 'active'
                ORDER BY l.requirement_id, l.link_type, resolved_manual_part, resolved_section_ref
            ");
        } else {
            $stmt = $this->pdo->query("
                SELECT
                  l.id AS link_id,
                  l.requirement_id,
                  l.link_type,
                  l.confidence,
                  l.notes,
                  l.excerpt_key,
                  l.excerpt_id,
                  e.section_ref AS resolved_section_ref,
                  e.manual_code AS resolved_manual_code,
                  e.manual_part AS resolved_manual_part,
                  0 AS resolved_book_version_id,
                  '' AS stable_anchor
                FROM ipca_canonical_requirement_excerpt_links l
                LEFT JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
                WHERE l.requirement_id IN ({$in})
                  AND l.source_status = 'active'
                ORDER BY l.requirement_id, l.link_type, e.manual_part, e.section_ref
            ");
        }

        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $rid = (int)$row['requirement_id'];
            $section = $this->enrichLinkRow($row);
            if ($section === null) {
                continue;
            }
            $map[$rid][] = $section;
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function enrichLinkRow(array $row): ?array
    {
        $manualCode = strtoupper(trim((string)($row['resolved_manual_code'] ?? '')));
        $part = trim((string)($row['resolved_manual_part'] ?? ''));
        $sectionRef = rtrim(trim((string)($row['resolved_section_ref'] ?? '')), '.');
        if ($manualCode === '' || $part === '' || $sectionRef === '') {
            $parsed = ControlledPublishingBookSectionIndexService::parseLinkKey((string)($row['excerpt_key'] ?? ''));
            if ($parsed !== null) {
                $manualCode = $manualCode !== '' ? $manualCode : $parsed['manual_code'];
                $part = $part !== '' ? $part : $parsed['manual_part'];
                $sectionRef = $sectionRef !== '' ? $sectionRef : $parsed['section_ref'];
            }
        }

        if ($manualCode === '' || $part === '' || $sectionRef === '') {
            return null;
        }

        $bookVersionId = (int)($row['resolved_book_version_id'] ?? 0);
        if ($bookVersionId <= 0) {
            $bookVersionId = $this->index->resolveBookVersionId($manualCode);
        }

        $manualPart = (int)$part;
        $indexed = $this->index->getSection($bookVersionId, $manualPart, $sectionRef);
        $versionLabel = $this->index->versionLabelForManual($manualCode);
        $linkKey = ControlledPublishingBookSectionIndexService::makeLinkKey($manualCode, $versionLabel, $part, $sectionRef);
        $title = is_array($indexed) ? trim((string)($indexed['title'] ?? '')) : '';
        $bodyText = is_array($indexed) ? trim((string)($indexed['body_text'] ?? '')) : '';

        return array(
            'link_id' => (int)($row['link_id'] ?? 0),
            'link_type' => (string)($row['link_type'] ?? 'PRIMARY'),
            'confidence' => (string)($row['confidence'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'excerpt_key' => $linkKey,
            'excerpt_id' => (int)($row['excerpt_id'] ?? 0),
            'manual_code' => $manualCode,
            'manual_part' => $part,
            'section_ref' => $sectionRef,
            'title' => $title,
            'body_text' => $bodyText,
            'book_version_id' => $bookVersionId,
            'stable_anchor' => trim((string)($row['stable_anchor'] ?? ($indexed['stable_anchor'] ?? ''))),
            'section_id' => is_array($indexed) ? (int)($indexed['chapter_section_id'] ?? 0) : 0,
        );
    }
}
