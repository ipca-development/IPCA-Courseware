<?php
declare(strict_types=1);

/**
 * Revision change markers and auto-generated Highlight of Changes content.
 */
final class ControlledPublishingRevisionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function priorVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT bv.*, b.book_key
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            WHERE bv.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $versionId));
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            return null;
        }
        $supersedesId = (int)($current['supersedes_version_id'] ?? 0);
        if ($supersedesId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ipca_publishing_book_versions WHERE id = :id LIMIT 1'
            );
            $stmt->execute(array(':id' => $supersedesId));
            $superseded = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($superseded)) {
                return $superseded;
            }
        }

        $stmt = $this->pdo->prepare("
            SELECT bv.*
            FROM ipca_publishing_book_versions bv
            WHERE bv.book_id = :book_id
              AND bv.id < :version_id
            ORDER BY bv.id DESC
            LIMIT 1
        ");
        $stmt->execute(array(
            ':book_id' => (int)$current['book_id'],
            ':version_id' => $versionId,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    public function annotateChangeStatus(int $versionId, array $blocks): array
    {
        $prior = $this->priorVersion($versionId);
        $priorBlocks = $prior !== null
            ? $this->blockComparisonsByKey((int)$prior['id'])
            : array();

        foreach ($blocks as $idx => $block) {
            if (!empty($block['is_system_managed'])) {
                $blocks[$idx]['change_status'] = 'unchanged';
                continue;
            }
            $blockKey = (string)($block['block_key'] ?? '');
            $hash = (string)($block['content_hash'] ?? '');
            if ($blockKey === '') {
                $blocks[$idx]['change_status'] = 'unchanged';
                continue;
            }
            if (!isset($priorBlocks[$blockKey])) {
                $blocks[$idx]['change_status'] = 'new';
            } elseif ((string)$priorBlocks[$blockKey]['content_hash'] !== $hash) {
                $currentText = $this->payloadText($this->decodePayload($block['payload_json'] ?? null));
                $priorText = (string)$priorBlocks[$blockKey]['content_text'];
                if ($currentText !== '' && hash_equals($priorText, $currentText)) {
                    $blocks[$idx]['change_status'] = 'unchanged';
                    continue;
                }
                $blocks[$idx]['change_status'] = 'modified';
            } else {
                $blocks[$idx]['change_status'] = 'unchanged';
            }
        }
        return $blocks;
    }

    /**
     * @return array<string,array{content_hash:string,content_text:string}>
     */
    private function blockComparisonsByKey(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT block_key, content_hash, payload_json
            FROM ipca_publishing_book_blocks
            WHERE book_version_id = :version_id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $key = trim((string)($row['block_key'] ?? ''));
            if ($key !== '') {
                $map[$key] = array(
                    'content_hash' => (string)$row['content_hash'],
                    'content_text' => $this->payloadText(
                        $this->decodePayload($row['payload_json'] ?? null)
                    ),
                );
            }
        }
        return $map;
    }

    /**
     * Rebuild the generated Highlight of Changes section from revision markers.
     *
     * @return array{section_id:int,blocks_created:int,changes_count:int}
     */
    public function regenerateHighlightsSection(int $versionId, ?int $actorUserId = null): array
    {
        $sectionId = $this->highlightsSectionId($versionId);
        if ($sectionId <= 0) {
            throw new RuntimeException('Highlight of Changes section not found for this version.');
        }

        $stmt = $this->pdo->prepare("
            SELECT b.*, s.title AS section_title, s.section_key,
                   s.sort_order AS section_sort_order
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key != 'highlights'
              AND b.is_system_managed = 0
            ORDER BY s.sort_order, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $allBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $annotated = $this->annotateChangeStatus($versionId, $allBlocks);
        $prior = $this->priorVersion($versionId);
        $priorBlocks = $prior !== null
            ? $this->blocksByKeyWithSection((int)$prior['id'])
            : array();
        $currentContexts = $this->blockContexts($allBlocks);
        $priorContexts = $this->blockContexts(array_values($priorBlocks));

        $changes = array();
        $currentKeys = array();
        foreach ($annotated as $block) {
            $blockKey = trim((string)($block['block_key'] ?? ''));
            if ($blockKey !== '') {
                $currentKeys[$blockKey] = true;
            }
            $status = (string)($block['change_status'] ?? 'unchanged');
            if ($status === 'unchanged') {
                continue;
            }
            if ($status === 'modified' && isset($priorBlocks[$blockKey])) {
                $block['prior_payload_json'] = $priorBlocks[$blockKey]['payload_json'] ?? null;
            }
            $block['change_context'] = $currentContexts[$blockKey] ?? array();
            $changes[] = $block;
        }
        foreach ($priorBlocks as $blockKey => $priorBlock) {
            if (isset($currentKeys[$blockKey])) {
                continue;
            }
            $priorBlock['change_status'] = 'deleted';
            $priorBlock['prior_payload_json'] = $priorBlock['payload_json'] ?? null;
            $priorBlock['change_context'] = $priorContexts[$blockKey] ?? array();
            $changes[] = $priorBlock;
        }
        $summaries = $this->humanChangeSummaries($changes);

        $this->pdo->prepare("
            DELETE FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id AND is_system_managed = 1
        ")->execute(array(':section_id' => $sectionId));

        $section = $this->sectionRow($sectionId);
        $stableBase = (string)($section['stable_anchor'] ?? 'HIGHLIGHTS');
        $sortStmt = $this->pdo->prepare("
            SELECT COALESCE(MAX(sort_order), 0) FROM ipca_publishing_book_blocks
            WHERE section_id = :section_id AND is_system_managed = 0
        ");
        $sortStmt->execute(array(':section_id' => $sectionId));
        $sort = max(10, (int)$sortStmt->fetchColumn() + 10);
        $created = 0;
        $ins = $this->pdo->prepare("
            INSERT INTO ipca_publishing_book_blocks
                (book_version_id, section_id, block_key, stable_anchor, block_type, sort_order,
                 payload_json, content_hash, is_system_managed, created_by, updated_by)
            VALUES
                (:book_version_id, :section_id, :block_key, :stable_anchor, :block_type, :sort_order,
                 :payload_json, :content_hash, 1, :actor, :actor)
        ");

        $versionLabel = $this->revisionDisplayLabel($versionId);
        $summaryPayload = array(
            'html' => '<p>Revision ' . htmlspecialchars($versionLabel, ENT_QUOTES, 'UTF-8')
                . ' Changes</p>',
            'paragraph_style' => 'subtitle_2',
        );
        $this->insertHighlightBlock(
            $ins,
            $versionId,
            $sectionId,
            $stableBase,
            'summary',
            'paragraph',
            $summaryPayload,
            $sort,
            $actorUserId
        );
        $sort += 10;
        $created++;

        if ($summaries === array()) {
            $para = array('html' => '<p>No content changes detected versus the prior version.</p>');
            $this->insertHighlightBlock($ins, $versionId, $sectionId, $stableBase, 'none', 'paragraph', $para, $sort, $actorUserId);
            return array('section_id' => $sectionId, 'blocks_created' => $created + 1, 'changes_count' => 0);
        }

        $byPart = array();
        foreach ($summaries as $summary) {
            $part = trim((string)($summary['part'] ?? ''));
            $byPart[$part][] = $summary;
        }
        foreach ($byPart as $part => $partSummaries) {
            $partPayload = array(
                'html' => '<p>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</p>',
                'paragraph_style' => 'subtitle_2',
            );
            $partKey = 'part_' . substr(hash('sha256', $part), 0, 12);
            $this->insertHighlightBlock(
                $ins,
                $versionId,
                $sectionId,
                $stableBase,
                $partKey,
                'paragraph',
                $partPayload,
                $sort,
                $actorUserId
            );
            $sort += 10;
            $created++;

            $items = array_map(
                static fn(array $summary): string => htmlspecialchars(
                    (string)$summary['text'],
                    ENT_QUOTES,
                    'UTF-8'
                ),
                $partSummaries
            );
            $listPayload = array(
                'ordered' => false,
                'items' => $items,
            );
            $listKey = 'changes_' . substr(
                hash('sha256', implode('|', array_column($partSummaries, 'key'))),
                0,
                12
            );
            $this->insertHighlightBlock(
                $ins,
                $versionId,
                $sectionId,
                $stableBase,
                $listKey,
                'list',
                $listPayload,
                $sort,
                $actorUserId
            );
            $sort += 10;
            $created++;
        }

        return array(
            'section_id' => $sectionId,
            'blocks_created' => $created,
            'changes_count' => count($summaries),
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function blocksByKeyWithSection(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.*, s.title AS section_title, s.section_key,
                   s.sort_order AS section_sort_order
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key != 'highlights'
              AND b.is_system_managed = 0
            ORDER BY s.sort_order, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $output = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $key = trim((string)($row['block_key'] ?? ''));
            if ($key !== '') {
                $output[$key] = $row;
            }
        }
        return $output;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return array<string,array{reference:string,title:string}>
     */
    private function blockContexts(array $blocks): array
    {
        $contexts = array();
        $nearest = array();
        foreach ($blocks as $block) {
            $sectionId = (int)($block['section_id'] ?? 0);
            $payload = $this->decodePayload($block['payload_json'] ?? null);
            $text = $this->payloadText($payload);
            $style = strtolower(trim((string)($payload['paragraph_style'] ?? '')));
            $isHeading = (string)($block['block_type'] ?? '') === 'heading'
                || in_array($style, array('title', 'subtitle_1', 'subtitle_2', 'subtitle_3', 'subtitle_4'), true);
            if ($isHeading && $text !== '') {
                $reference = trim((string)($payload['canonical_section_ref'] ?? ''));
                if ($reference === '' && preg_match('/^(\d+(?:\.\d+)+)\b/u', $text, $match) === 1) {
                    $reference = (string)$match[1];
                }
                $title = trim(preg_replace('/^\d+(?:\.\d+)*\.?\s*/u', '', $text) ?? $text);
                $nearest[$sectionId] = array('reference' => $reference, 'title' => $title);
            }
            $key = trim((string)($block['block_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $context = $nearest[$sectionId] ?? array('reference' => '', 'title' => '');
            $ownReference = trim((string)($payload['canonical_section_ref'] ?? ''));
            if ($ownReference !== '') {
                $context['reference'] = $ownReference;
            }
            $contexts[$key] = $context;
        }
        return $contexts;
    }

    /**
     * @param list<array<string,mixed>> $changes
     * @return list<array{key:string,text:string,part:string}>
     */
    private function humanChangeSummaries(array $changes): array
    {
        $groups = array();
        foreach ($changes as $change) {
            $sectionKey = strtolower((string)($change['section_key'] ?? ''));
            $part = $this->partLabel($sectionKey);
            $context = is_array($change['change_context'] ?? null)
                ? $change['change_context']
                : array();
            $reference = trim((string)($context['reference'] ?? ''));
            $title = trim((string)($context['title'] ?? ''));
            $sectionTitle = trim((string)($change['section_title'] ?? ''));
            $groupKey = implode('|', array($part, $sectionKey, $reference, $title));
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = array(
                    'part' => $part,
                    'section_key' => $sectionKey,
                    'section_sort_order' => (int)($change['section_sort_order'] ?? 0),
                    'section_title' => $sectionTitle,
                    'reference' => $reference,
                    'title' => $title,
                    'added' => array(),
                    'modified' => array(),
                    'deleted' => array(),
                );
            }
            $status = (string)($change['change_status'] ?? 'modified');
            $payload = $this->decodePayload($change['payload_json'] ?? null);
            $currentText = $this->payloadText($payload);
            $priorText = $this->payloadText(
                $this->decodePayload($change['prior_payload_json'] ?? null)
            );
            if ($status === 'new') {
                $groups[$groupKey]['added'][] = $currentText;
            } elseif ($status === 'deleted') {
                $groups[$groupKey]['deleted'][] = $priorText;
            } else {
                $groups[$groupKey]['modified'][] = array($priorText, $currentText);
            }
        }

        uasort($groups, function (array $left, array $right): int {
            $partOrder = $this->partSortOrder((string)($left['part'] ?? ''))
                <=> $this->partSortOrder((string)($right['part'] ?? ''));
            if ($partOrder !== 0) {
                return $partOrder;
            }
            $sectionOrder = (int)($left['section_sort_order'] ?? 0)
                <=> (int)($right['section_sort_order'] ?? 0);
            if ($sectionOrder !== 0) {
                return $sectionOrder;
            }
            $referenceOrder = strnatcasecmp(
                (string)($left['reference'] ?? ''),
                (string)($right['reference'] ?? '')
            );
            if ($referenceOrder !== 0) {
                return $referenceOrder;
            }
            return strnatcasecmp(
                (string)($left['section_key'] ?? ''),
                (string)($right['section_key'] ?? '')
            );
        });

        $output = array();
        foreach ($groups as $key => $group) {
            $reference = trim((string)($group['reference'] ?? ''));
            $title = trim((string)($group['title'] ?? ''));
            if ($title === '') {
                $title = trim((string)($group['section_title'] ?? ''));
            }
            $location = trim(
                ($reference !== '' ? 'Section ' . $reference : '')
                . ($reference !== '' && $title !== '' ? ' — ' : '')
                . $title
            );
            if ($location === '') {
                $location = 'General content';
            }
            $sentences = array();
            if ($group['modified'] !== array()) {
                $pair = $group['modified'][0];
                $detail = $this->modificationDetail((string)$pair[0], (string)$pair[1]);
                if (count($group['modified']) === 1) {
                    $sentences[] = $detail . '.';
                } else {
                    $sentences[] = 'Updated ' . count($group['modified'])
                        . ' content items. Example: ' . $detail . '.';
                }
            }
            if ($group['added'] !== array()) {
                $example = $group['added'][0] !== ''
                    ? ': “' . $this->truncateText((string)$group['added'][0]) . '”'
                    : '';
                $sentences[] = 'Added ' . count($group['added'])
                    . ' content item' . (count($group['added']) === 1 ? '' : 's') . $example . '.';
            }
            if ($group['deleted'] !== array()) {
                $example = $group['deleted'][0] !== ''
                    ? ': “' . $this->truncateText((string)$group['deleted'][0]) . '”'
                    : '';
                $sentences[] = 'Removed ' . count($group['deleted'])
                    . ' previous content item' . (count($group['deleted']) === 1 ? '' : 's') . $example . '.';
            }
            $output[] = array(
                'key' => $key,
                'part' => (string)$group['part'],
                'text' => $location . ': ' . implode(' ', $sentences),
            );
        }
        return $output;
    }

    private function modificationDetail(string $priorText, string $currentText): string
    {
        if ($priorText === '' || $currentText === '') {
            return 'Updated the content wording';
        }
        $priorWords = preg_split('/\s+/u', $priorText) ?: array();
        $currentWords = preg_split('/\s+/u', $currentText) ?: array();
        $prefix = 0;
        $prefixLimit = min(count($priorWords), count($currentWords));
        while ($prefix < $prefixLimit && $priorWords[$prefix] === $currentWords[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while (
            $suffix < count($priorWords) - $prefix
            && $suffix < count($currentWords) - $prefix
            && $priorWords[count($priorWords) - 1 - $suffix]
                === $currentWords[count($currentWords) - 1 - $suffix]
        ) {
            $suffix++;
        }
        $priorLength = count($priorWords) - $prefix - $suffix;
        $currentLength = count($currentWords) - $prefix - $suffix;
        $priorChange = implode(' ', array_slice($priorWords, $prefix, $priorLength));
        $currentChange = implode(' ', array_slice($currentWords, $prefix, $currentLength));
        if ($priorChange === '' && $currentChange !== '') {
            return 'Added “' . $this->truncateText($currentChange) . '”';
        }
        if ($priorChange !== '' && $currentChange === '') {
            return 'Removed “' . $this->truncateText($priorChange) . '”';
        }
        if ($priorChange === '' || $currentChange === '') {
            return 'Updated the content wording';
        }
        return 'Changed “' . $this->truncateText($priorChange)
            . '” to “' . $this->truncateText($currentChange) . '”';
    }

    private function revisionDisplayLabel(int $versionId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT version_label FROM ipca_publishing_book_versions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(array(':id' => $versionId));
        $label = trim((string)$stmt->fetchColumn());
        if ($label === '') {
            return 'Draft';
        }
        return preg_replace('/\.0+$/', '', $label) ?: $label;
    }

    private function partLabel(string $sectionKey): string
    {
        if (str_contains($sectionKey, 'part_2')) return 'Part 2 — Technical';
        if (str_contains($sectionKey, 'part_3')) return 'Part 3 — Route';
        if (str_contains($sectionKey, 'part_4')) return 'Part 4 — Training';
        if (str_contains($sectionKey, 'part_1') || str_contains($sectionKey, 'main_content')) {
            return 'Part 1 — General';
        }
        if (str_contains($sectionKey, 'part0')) return 'Part 0 — Manual Administration';
        if (str_contains($sectionKey, 'annex')) return 'Annexes';
        return 'Other Changes';
    }

    private function partSortOrder(string $part): int
    {
        return match (true) {
            str_starts_with($part, 'Part 0') => 0,
            str_starts_with($part, 'Part 1') => 1,
            str_starts_with($part, 'Part 2') => 2,
            str_starts_with($part, 'Part 3') => 3,
            str_starts_with($part, 'Part 4') => 4,
            $part === 'Annexes' => 5,
            default => 6,
        };
    }

    /** @return array<string,mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        $decoded = json_decode((string)$payload, true);
        return is_array($decoded) ? $decoded : array();
    }

    /** @param array<string,mixed> $payload */
    private function payloadText(array $payload): string
    {
        $value = (string)($payload['text'] ?? $payload['html'] ?? $payload['caption'] ?? '');
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function truncateText(string $text): string
    {
        if (mb_strlen($text) <= 140) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, 137)) . '…';
    }

    private function highlightsSectionId(int $versionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = 'highlights'
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function sectionRow(int $sectionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_sections WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $sectionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : array();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function insertHighlightBlock(
        PDOStatement $ins,
        int $versionId,
        int $sectionId,
        string $stableBase,
        string $keySuffix,
        string $blockType,
        array $payload,
        int $sort,
        ?int $actorUserId
    ): void {
        $blockKey = 'highlights_' . $keySuffix;
        $anchor = $stableBase . '-BLOCK-HL-' . strtoupper($keySuffix);
        $hash = hash('sha256', $blockType . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        $ins->execute(array(
            ':book_version_id' => $versionId,
            ':section_id' => $sectionId,
            ':block_key' => substr($blockKey, 0, 128),
            ':stable_anchor' => substr($anchor, 0, 191),
            ':block_type' => $blockType,
            ':sort_order' => $sort,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':content_hash' => $hash,
            ':actor' => $actorUserId,
        ));
    }
}
