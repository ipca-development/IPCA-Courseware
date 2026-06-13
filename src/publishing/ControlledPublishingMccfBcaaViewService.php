<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/ControlledPublishingMccfIntegrityService.php';
require_once __DIR__ . '/ControlledPublishingMccfRegulationLinkService.php';

/**
 * BCAA MCCF checklist presentation: formatting, enrichment, merged rows.
 */
final class ControlledPublishingMccfBcaaViewService
{
    private ControlledPublishingMccfIntegrityService $integrity;

    public function __construct(private PDO $pdo)
    {
        $this->integrity = new ControlledPublishingMccfIntegrityService();
    }

    /**
     * @param array{part?:string,q?:string,coverage?:string} $filters
     * @return list<array{part:string,label:string,rows:list<array<string,mixed>>}>
     */
    public function listPartSections(int $sourceSetId, array $filters = array()): array
    {
        if ($sourceSetId <= 0) {
            return array();
        }

        $browser = new ControlledPublishingMccfBrowserService($this->pdo);
        $search = $browser->searchRequirements($sourceSetId, $filters, 1, 5000);
        $rows = $search['rows'];
        if ($rows === array()) {
            return array();
        }

        $reqIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $excerptMap = $this->loadLinkedExcerpts($reqIds);
        $regLinkMap = $this->loadRegulationLinks($reqIds);
        $bookVersionId = $this->resolveBookVersionId($rows[0]['manual_code'] ?? 'OM', $sourceSetId);

        /** @var array<string,list<array<string,mixed>>> $grouped */
        $grouped = array();
        foreach ($rows as $row) {
            $rid = (int)$row['id'];
            $row['linked_excerpts'] = $excerptMap[$rid] ?? array();
            $row['regulation_links'] = $regLinkMap[$rid] ?? array();
            $row['integrity'] = $this->integrity->scoreRequirement(
                $row,
                $row['linked_excerpts'],
                $row['regulation_links']
            );
            $row['book_version_id'] = $bookVersionId;
            $row['location_lines'] = self::formatLocationLines($row, $bookVersionId);

            $part = trim((string)($row['manual_part'] ?? ''));
            if ($part === '') {
                $part = '—';
            }
            $grouped[$part][] = $row;
        }

        ksort($grouped, SORT_NATURAL);

        $sections = array();
        foreach ($grouped as $part => $partRows) {
            $sections[] = array(
                'part' => $part,
                'label' => ControlledPublishingMccfRegulationLinkService::bcaaPartHeading($part),
                'rows' => self::applyMergedRowPresentation($partRows),
            );
        }

        return $sections;
    }

    public static function formatBcaaItemLabel(array $row, bool $show): string
    {
        if (!$show) {
            return '';
        }
        $item = trim((string)($row['item_no'] ?? ''));
        if ($item === '' || !ctype_digit($item)) {
            return $item;
        }

        return str_pad($item, 2, '0', STR_PAD_LEFT);
    }

    public static function formatBcaaSubLabel(array $row): string
    {
        $item = trim((string)($row['item_no'] ?? ''));
        $sub = trim((string)($row['sub_item_no'] ?? ''));
        if ($item === '' || $sub === '') {
            return $sub !== '' ? $sub : '—';
        }
        if (!ctype_digit($item) || !ctype_digit($sub)) {
            return $item . '.' . $sub;
        }

        return str_pad($item, 2, '0', STR_PAD_LEFT) . '.' . str_pad($sub, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function applyMergedRowPresentation(array $rows): array
    {
        $lastGroupKey = null;
        $out = array();
        foreach ($rows as $row) {
            $groupKey = trim((string)($row['item_no'] ?? '')) . '|' . trim((string)($row['subject'] ?? ''));
            $showGroup = ($groupKey !== $lastGroupKey);
            $lastGroupKey = $groupKey;
            $row['bcaa_show_group'] = $showGroup;
            $row['bcaa_item_label'] = self::formatBcaaItemLabel($row, $showGroup);
            $row['bcaa_sub_label'] = self::formatBcaaSubLabel($row);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array{label:string,href:?string,kind:string}>
     */
    public static function formatLocationLines(array $row, int $bookVersionId = 0): array
    {
        $lines = array();
        $manualRef = trim((string)($row['manual_section_ref'] ?? ''));
        if ($manualRef !== '') {
            foreach (preg_split('/\R+/u', $manualRef) ?: array() as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $lines[] = array(
                    'label' => $line,
                    'href' => $bookVersionId > 0
                        ? '/admin/compliance/controlled_book_editor.php?version_id=' . $bookVersionId
                        : null,
                    'kind' => 'manual_ref',
                );
            }
        }

        foreach ($row['linked_excerpts'] ?? array() as $excerpt) {
            if (!is_array($excerpt)) {
                continue;
            }
            $part = trim((string)($excerpt['manual_part'] ?? ''));
            $sec = trim((string)($excerpt['section_ref'] ?? ''));
            $title = trim((string)($excerpt['title'] ?? ''));
            $label = 'OM Part ' . $part . ' §' . $sec;
            if ($title !== '') {
                $label .= ' — ' . $title;
            }
            $href = $bookVersionId > 0
                ? '/admin/compliance/controlled_book_editor.php?version_id=' . $bookVersionId
                : null;
            $lines[] = array(
                'label' => $label,
                'href' => $href,
                'kind' => 'excerpt',
            );
        }

        if ($lines === array()) {
            return array(array('label' => '—', 'href' => null, 'kind' => 'empty'));
        }

        return $lines;
    }

    /**
     * @param list<int> $requirementIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function loadLinkedExcerpts(array $requirementIds): array
    {
        if ($requirementIds === array()) {
            return array();
        }
        $in = implode(',', array_map('intval', $requirementIds));
        $stmt = $this->pdo->query("
            SELECT
              l.requirement_id,
              e.excerpt_key,
              e.title,
              e.section_ref,
              e.manual_part,
              LEFT(e.body_text, 600) AS body_text
            FROM ipca_canonical_requirement_excerpt_links l
            INNER JOIN ipca_canonical_excerpts e
              ON e.id = l.excerpt_id AND e.source_status = 'active'
            WHERE l.requirement_id IN ({$in})
              AND l.source_status = 'active'
            ORDER BY l.requirement_id, e.manual_part, e.section_ref
        ");

        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $rid = (int)$row['requirement_id'];
            unset($row['requirement_id']);
            $map[$rid][] = $row;
        }

        return $map;
    }

    /**
     * @param list<int> $requirementIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function loadRegulationLinks(array $requirementIds): array
    {
        if ($requirementIds === array()
            || !ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($this->pdo)) {
            return array();
        }

        $in = implode(',', array_map('intval', $requirementIds));
        try {
            $stmt = $this->pdo->query("
                SELECT requirement_id, rule_token, match_confidence, target_batch_id, target_node_uid
                FROM ipca_canonical_requirement_regulation_links
                WHERE requirement_id IN ({$in}) AND source_status = 'active'
                ORDER BY requirement_id, rule_token
            ");
        } catch (Throwable) {
            return array();
        }

        $map = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $rid = (int)$row['requirement_id'];
            unset($row['requirement_id']);
            $map[$rid][] = $row;
        }

        return $map;
    }

    private function resolveBookVersionId(mixed $manualCode, int $sourceSetId): int
    {
        $manualCode = strtoupper(trim((string)$manualCode));
        if ($manualCode === '') {
            $manualCode = 'OM';
        }

        $rev = '6.0';
        if ($manualCode === 'OMM') {
            $rev = '4.0';
        }

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
                ':version_label' => $rev,
            ));

            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
