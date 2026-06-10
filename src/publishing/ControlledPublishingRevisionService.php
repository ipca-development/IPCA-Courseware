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
        $priorHashes = $prior !== null ? $this->blockHashesByAnchor((int)$prior['id']) : array();

        foreach ($blocks as $idx => $block) {
            $anchor = (string)($block['stable_anchor'] ?? '');
            $hash = (string)($block['content_hash'] ?? '');
            if ($anchor === '') {
                $blocks[$idx]['change_status'] = 'unchanged';
                continue;
            }
            if (!isset($priorHashes[$anchor])) {
                $blocks[$idx]['change_status'] = 'new';
            } elseif ($priorHashes[$anchor] !== $hash) {
                $blocks[$idx]['change_status'] = 'modified';
            } else {
                $blocks[$idx]['change_status'] = 'unchanged';
            }
        }
        return $blocks;
    }

    /**
     * @return array<string,string> stable_anchor => content_hash
     */
    private function blockHashesByAnchor(int $versionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT stable_anchor, content_hash
            FROM ipca_publishing_book_blocks
            WHERE book_version_id = :version_id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $map[(string)$row['stable_anchor']] = (string)$row['content_hash'];
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
            SELECT b.*, s.title AS section_title, s.section_key
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key != 'highlights'
            ORDER BY s.sort_order, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $allBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $annotated = $this->annotateChangeStatus($versionId, $allBlocks);

        $changes = array();
        foreach ($annotated as $block) {
            $status = (string)($block['change_status'] ?? 'unchanged');
            if ($status === 'unchanged') {
                continue;
            }
            $changes[] = $block;
        }

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

        $summaryPayload = array(
            'text' => 'Auto-detected changes',
            'level' => 2,
        );
        $this->insertHighlightBlock($ins, $versionId, $sectionId, $stableBase, 'summary', 'heading', $summaryPayload, $sort, $actorUserId);
        $sort += 10;
        $created++;

        $introPayload = array(
            'html' => '<p>' . count($changes) . ' governed change(s) detected versus the prior book version.</p>',
        );
        $this->insertHighlightBlock($ins, $versionId, $sectionId, $stableBase, 'summary_intro', 'paragraph', $introPayload, $sort, $actorUserId);
        $sort += 10;
        $created++;

        if ($changes === array()) {
            $para = array('html' => '<p>No content changes detected versus the prior version.</p>');
            $this->insertHighlightBlock($ins, $versionId, $sectionId, $stableBase, 'none', 'paragraph', $para, $sort, $actorUserId);
            return array('section_id' => $sectionId, 'blocks_created' => $created + 1, 'changes_count' => 0);
        }

        foreach ($changes as $change) {
            $status = (string)($change['change_status'] ?? 'modified');
            $type = (string)($change['block_type'] ?? 'block');
            $anchor = (string)($change['stable_anchor'] ?? '');
            $sectionTitle = (string)($change['section_title'] ?? '');
            $label = strtoupper($status) . ' · ' . $sectionTitle . ' · ' . $type . ' · ' . $anchor;
            $para = array('html' => '<p>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</p>');
            $key = 'change_' . substr(hash('sha256', $anchor), 0, 12);
            $this->insertHighlightBlock($ins, $versionId, $sectionId, $stableBase, $key, 'paragraph', $para, $sort, $actorUserId);
            $sort += 10;
            $created++;
        }

        return array(
            'section_id' => $sectionId,
            'blocks_created' => $created,
            'changes_count' => count($changes),
        );
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
