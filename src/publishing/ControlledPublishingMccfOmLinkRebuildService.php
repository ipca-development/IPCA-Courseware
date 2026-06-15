<?php
declare(strict_types=1);

/**
 * Rebuild MCCF OM requirement → manual excerpt links against current MANUAL:OM:6_0 rows.
 *
 * Part 0 targets prefer active docx_import excerpts; Parts 1–4 fall back to superseded
 * legacy canonical rows still present after DOCX import superseded them.
 */
final class ControlledPublishingMccfOmLinkRebuildService
{
    private const MCCF_SOURCE_SET_KEY = 'MCCF:OM:REV_6_0';
    private const MANUAL_SOURCE_SET_KEY = 'MANUAL:OM:6_0';
    private const MCCF_DOCUMENT_KEY = 'MCCF:OM:OM REV 6.0';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function rebuild(bool $apply = false, bool $activateExcerpts = false): array
    {
        return array(
            'ok' => false,
            'error' => 'Deprecated: OM excerpt link rebuild uses legacy canonical excerpts. '
                . 'MCCF manual links are maintained against the live published book via MCCF browser.',
            'apply' => $apply,
            'activate_excerpts' => $activateExcerpts,
        );

        $ctx = $this->resolveContext();
        if ($ctx === null) {
            return array(
                'ok' => false,
                'error' => 'Missing MCCF:OM:REV_6_0 or MANUAL:OM:6_0 source context.',
            );
        }

        $index = $this->buildExcerptIndex((int)$ctx['manual_source_set_id']);
        $requirements = $this->loadOmRequirements((int)$ctx['mccf_source_set_id']);
        $existingLinks = $this->loadExistingLinks((int)$ctx['mccf_source_set_id']);

        $result = array(
            'ok' => true,
            'apply' => $apply,
            'activate_excerpts' => $activateExcerpts,
            'requirements' => count($requirements),
            'links_created' => 0,
            'links_updated' => 0,
            'links_retired' => 0,
            'links_unchanged' => 0,
            'requirements_with_links' => 0,
            'requirements_without_links' => 0,
            'excerpts_activated' => 0,
            'unresolved' => array(),
        );

        $desiredLinks = array();
        $targetExcerptIds = array();

        foreach ($requirements as $requirement) {
            $resolved = $this->resolveExcerptsForRequirement($requirement, $index, $existingLinks);
            if ($resolved === array()) {
                $result['requirements_without_links']++;
                if (!$this->isUnlinkableRequirement($requirement)) {
                    $result['unresolved'][] = array(
                        'requirement_key' => (string)$requirement['requirement_key'],
                        'manual_section_ref' => (string)($requirement['manual_section_ref'] ?? ''),
                    );
                }
                continue;
            }

            $result['requirements_with_links']++;
            foreach ($resolved as $excerpt) {
                $excerptId = (int)$excerpt['id'];
                $targetExcerptIds[$excerptId] = true;
                $desiredLinks[] = array(
                    'requirement' => $requirement,
                    'excerpt' => $excerpt,
                );
            }
        }

        if ($apply) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($apply && $activateExcerpts) {
                $result['excerpts_activated'] = $this->activateTargetExcerpts(
                    (int)$ctx['manual_source_set_id'],
                    array_keys($targetExcerptIds)
                );
            }

            $desiredKeys = array();
            foreach ($desiredLinks as $desired) {
                $requirement = $desired['requirement'];
                $excerpt = $desired['excerpt'];
                $requirementId = (int)$requirement['id'];
                $excerptId = (int)$excerpt['id'];
                $dedupeKey = $requirementId . '|' . $excerptId . '|PRIMARY';
                $desiredKeys[$dedupeKey] = true;

                $existing = $this->findExistingLink($existingLinks, $requirementId, $excerptId);
                if ($existing === null) {
                    if ($apply) {
                        $this->insertLink($ctx, $requirement, $excerpt);
                    }
                    $result['links_created']++;
                    continue;
                }

                if ($this->linkNeedsUpdate($existing, $excerpt)) {
                    if ($apply) {
                        $this->updateLink($existing, $excerpt);
                    }
                    $result['links_updated']++;
                } else {
                    $result['links_unchanged']++;
                }
            }

            foreach ($existingLinks as $link) {
                $dedupeKey = (int)$link['requirement_id'] . '|' . (int)$link['excerpt_id'] . '|PRIMARY';
                if (isset($desiredKeys[$dedupeKey])) {
                    continue;
                }
                if ($apply) {
                    $this->retireLink((int)$link['id']);
                }
                $result['links_retired']++;
            }

            if ($apply) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($apply && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveContext(): ?array
    {
        $stmt = $this->pdo->query("
            SELECT
              mss.id AS manual_source_set_id,
              mccf.id AS mccf_source_set_id,
              doc.id AS mccf_document_id
            FROM ipca_canonical_source_sets mss
            INNER JOIN ipca_canonical_source_sets mccf
              ON mccf.source_set_key = " . $this->pdo->quote(self::MCCF_SOURCE_SET_KEY) . "
            INNER JOIN ipca_canonical_documents doc
              ON doc.document_key = " . $this->pdo->quote(self::MCCF_DOCUMENT_KEY) . "
            WHERE mss.source_set_key = " . $this->pdo->quote(self::MANUAL_SOURCE_SET_KEY) . "
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadOmRequirements(int $mccfSourceSetId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, requirement_key, manual_part, item_no, sub_item_no, manual_section_ref
            FROM ipca_canonical_requirements
            WHERE source_set_id = :source_set_id
              AND source_status = 'active'
              AND manual_code = 'OM'
            ORDER BY manual_part, item_no, sub_item_no, requirement_key
        ");
        $stmt->execute(array(':source_set_id' => $mccfSourceSetId));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array<int,list<array<string,mixed>>>
     */
    private function loadExistingLinks(int $mccfSourceSetId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              l.id,
              l.requirement_id,
              l.excerpt_id,
              l.excerpt_key,
              l.source_status,
              e.source_status AS excerpt_status
            FROM ipca_canonical_requirement_excerpt_links l
            INNER JOIN ipca_canonical_requirements r ON r.id = l.requirement_id
            LEFT JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
            WHERE r.source_set_id = :source_set_id
              AND l.source_status = 'active'
        ");
        $stmt->execute(array(':source_set_id' => $mccfSourceSetId));

        $links = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $links[] = $row;
        }

        return $links;
    }

    /**
     * @param array<int,list<array<string,mixed>>> $index
     * @param list<array<string,mixed>> $existingLinks
     * @return list<array<string,mixed>>
     */
    private function resolveExcerptsForRequirement(array $requirement, array $index, array $existingLinks): array
    {
        if ($this->isUnlinkableRequirement($requirement)) {
            return array();
        }

        $resolved = array();
        foreach ($this->sectionRefsForRequirement($requirement, $existingLinks) as $ref) {
            $key = $ref['part'] . '|' . $ref['section'];
            if (!isset($index[$key][0])) {
                continue;
            }
            $excerpt = $index[$key][0];
            $resolved[(int)$excerpt['id']] = $excerpt;
        }

        return array_values($resolved);
    }

    /**
     * @param list<array<string,mixed>> $existingLinks
     * @return list<array{part:int,section:string}>
     */
    private function sectionRefsForRequirement(array $requirement, array $existingLinks): array
    {
        $refs = array();
        $seen = array();

        foreach (self::parseManualSectionRef((string)($requirement['manual_section_ref'] ?? '')) as $ref) {
            $k = $ref['part'] . '|' . $ref['section'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $refs[] = $ref;
            }
        }

        $requirementId = (int)$requirement['id'];
        foreach ($existingLinks as $link) {
            if ((int)$link['requirement_id'] !== $requirementId) {
                continue;
            }
            $parsed = self::parseExcerptKey((string)($link['excerpt_key'] ?? ''));
            if ($parsed === null) {
                continue;
            }
            $k = $parsed['part'] . '|' . $parsed['section'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $refs[] = $parsed;
            }
        }

        return $refs;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function buildExcerptIndex(int $manualSourceSetId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, excerpt_key, manual_part, section_ref, source_status, source_file, title
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND manual_code = 'OM'
              AND section_ref IS NOT NULL
              AND TRIM(section_ref) <> ''
        ");
        $stmt->execute(array(':source_set_id' => $manualSourceSetId));

        /** @var array<string,list<array<string,mixed>>> $index */
        $index = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $part = self::normalizeManualPart($row['manual_part']);
            $section = trim((string)$row['section_ref']);
            if ($part === null || $section === '') {
                continue;
            }
            $index[$part . '|' . $section][] = $row;
        }

        foreach ($index as &$rows) {
            usort($rows, static function (array $a, array $b): int {
                return self::excerptPriorityScore($b) <=> self::excerptPriorityScore($a);
            });
        }
        unset($rows);

        return $index;
    }

    /**
     * @param list<int> $excerptIds
     */
    private function activateTargetExcerpts(int $manualSourceSetId, array $excerptIds): int
    {
        if ($excerptIds === array()) {
            return 0;
        }

        $activated = 0;
        $in = implode(',', array_map('intval', $excerptIds));
        $rows = $this->pdo->query("
            SELECT id, manual_part, section_ref, source_status
            FROM ipca_canonical_excerpts
            WHERE id IN ({$in})
              AND source_set_id = {$manualSourceSetId}
              AND source_status <> 'active'
        ")->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $deactivate = $this->pdo->prepare("
            UPDATE ipca_canonical_excerpts
            SET source_status = 'superseded', updated_at = CURRENT_TIMESTAMP
            WHERE source_set_id = :source_set_id
              AND manual_code = 'OM'
              AND manual_part = :manual_part
              AND section_ref = :section_ref
              AND id <> :keep_id
              AND source_status = 'active'
        ");
        $activate = $this->pdo->prepare("
            UPDATE ipca_canonical_excerpts
            SET source_status = 'active', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $deactivate->execute(array(
                ':source_set_id' => $manualSourceSetId,
                ':manual_part' => (string)$row['manual_part'],
                ':section_ref' => (string)$row['section_ref'],
                ':keep_id' => $id,
            ));
            $activate->execute(array(':id' => $id));
            $activated++;
        }

        return $activated;
    }

    /**
     * @param list<array<string,mixed>> $existingLinks
     * @return array<string,mixed>|null
     */
    private function findExistingLink(array $existingLinks, int $requirementId, int $excerptId): ?array
    {
        foreach ($existingLinks as $link) {
            if ((int)$link['requirement_id'] === $requirementId && (int)$link['excerpt_id'] === $excerptId) {
                return $link;
            }
        }

        return null;
    }

    private function linkNeedsUpdate(array $existing, array $excerpt): bool
    {
        return (string)($existing['excerpt_key'] ?? '') !== (string)$excerpt['excerpt_key']
            || (string)($existing['excerpt_status'] ?? '') !== (string)$excerpt['source_status'];
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $requirement
     * @param array<string,mixed> $excerpt
     */
    private function insertLink(array $ctx, array $requirement, array $excerpt): void
    {
        $hash = hash('sha256', (string)$requirement['requirement_key'] . '|' . (string)$excerpt['excerpt_key'] . '|PRIMARY|REBUILT');
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_canonical_requirement_excerpt_links (
              source_set_id, source_document_id, requirement_id, excerpt_id,
              requirement_key, excerpt_key, link_type, confidence, notes,
              source_link_id, source_hash, source_status, last_synced_at
            ) VALUES (
              :source_set_id, :source_document_id, :requirement_id, :excerpt_id,
              :requirement_key, :excerpt_key, 'PRIMARY', 'AUTO', :notes,
              NULL, :source_hash, 'active', CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute(array(
            ':source_set_id' => (int)$ctx['mccf_source_set_id'],
            ':source_document_id' => (int)$ctx['mccf_document_id'],
            ':requirement_id' => (int)$requirement['id'],
            ':excerpt_id' => (int)$excerpt['id'],
            ':requirement_key' => (string)$requirement['requirement_key'],
            ':excerpt_key' => (string)$excerpt['excerpt_key'],
            ':notes' => 'Rebuilt from current MANUAL:OM:6_0 excerpt index',
            ':source_hash' => $hash,
        ));
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $excerpt
     */
    private function updateLink(array $existing, array $excerpt): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_requirement_excerpt_links
            SET excerpt_key = :excerpt_key,
                source_status = 'active',
                confidence = 'AUTO',
                notes = :notes,
                last_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':id' => (int)$existing['id'],
            ':excerpt_key' => (string)$excerpt['excerpt_key'],
            ':notes' => 'Rebuilt from current MANUAL:OM:6_0 excerpt index',
        ));
    }

    private function retireLink(int $linkId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_canonical_requirement_excerpt_links
            SET source_status = 'retired',
                notes = CONCAT(COALESCE(notes, ''), ' | Retired during OM MCCF link rebuild'),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(':id' => $linkId));
    }

    private function isUnlinkableRequirement(array $requirement): bool
    {
        $ref = trim((string)($requirement['manual_section_ref'] ?? ''));
        if ($ref === '') {
            return true;
        }
        if (strcasecmp($ref, 'No procedure') === 0) {
            return true;
        }
        if (stripos($ref, 'Headers Parts') === 0) {
            return true;
        }

        return false;
    }

    /**
     * @return list<array{part:int,section:string}>
     */
    public static function parseManualSectionRef(string $manualSectionRef): array
    {
        $manualSectionRef = trim($manualSectionRef);
        if ($manualSectionRef === '') {
            return array();
        }

        $refs = array();
        foreach (preg_split('/\R+/u', $manualSectionRef) ?: array() as $line) {
            $line = trim((string)$line);
            if ($line === '' || stripos($line, 'Headers Parts') === 0) {
                continue;
            }
            if (preg_match(
                '/Part\s*(\d+)\s*[–—-]\s*(?:Ch\.?\s*|Ch\s+)?([0-9]+(?:\.[0-9]+)*)/iu',
                $line,
                $m
            )) {
                $refs[] = array(
                    'part' => (int)$m[1],
                    'section' => self::normalizeSectionRef((string)$m[2]),
                );
            }
        }

        return $refs;
    }

    /**
     * @return array{part:int,section:string}|null
     */
    public static function parseExcerptKey(string $excerptKey): ?array
    {
        $excerptKey = trim($excerptKey);
        if ($excerptKey === '') {
            return null;
        }
        if (preg_match('/^OM6_(?:0_)?P(\d+)_(.+)$/iu', $excerptKey, $m)) {
            return array(
                'part' => (int)$m[1],
                'section' => self::normalizeSectionRef(str_replace('_', '.', (string)$m[2])),
            );
        }

        return null;
    }

    public static function normalizeManualPart(mixed $manualPart): ?int
    {
        $manualPart = trim((string)$manualPart);
        if ($manualPart === '') {
            return null;
        }
        if (ctype_digit($manualPart)) {
            return (int)$manualPart;
        }
        if (preg_match('/PART\s*(\d+)/iu', $manualPart, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    public static function normalizeSectionRef(string $sectionRef): string
    {
        $sectionRef = trim($sectionRef);
        $sectionRef = preg_replace('/\s+/u', '', $sectionRef) ?? $sectionRef;
        $sectionRef = rtrim($sectionRef, '.');

        return $sectionRef;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function excerptPriorityScore(array $row): int
    {
        $score = 0;
        $status = (string)($row['source_status'] ?? '');
        if ($status === 'active') {
            $score += 100;
        } elseif ($status === 'superseded') {
            $score += 50;
        } elseif ($status === 'retired') {
            $score += 10;
        }

        $sourceFile = (string)($row['source_file'] ?? '');
        if (str_starts_with($sourceFile, 'docx_import')) {
            $score += 20;
        } elseif (str_contains($sourceFile, 'CANONICAL')) {
            $score += 15;
        }

        $score += min(5, (int)((int)($row['id'] ?? 0) / 200000));

        return (int)$score;
    }
}
