<?php
declare(strict_types=1);

/**
 * Browse canonical MCCF requirements and manual excerpt coverage links.
 */
final class ControlledPublishingMccfBrowserService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listMccfSourceSets(): array
    {
        $stmt = $this->pdo->query("
            SELECT
              ss.id,
              ss.source_set_key,
              ss.revision_label,
              ss.authority,
              ss.status,
              ss.last_synced_at,
              COUNT(DISTINCT r.id) AS requirements,
              COUNT(DISTINCT l.id) AS links
            FROM ipca_canonical_source_sets ss
            LEFT JOIN ipca_canonical_requirements r
              ON r.source_set_id = ss.id AND r.source_status = 'active'
            LEFT JOIN ipca_canonical_requirement_excerpt_links l
              ON l.source_set_id = ss.id AND l.source_status = 'active'
            WHERE ss.source_family = 'mccf'
               OR ss.source_set_key LIKE 'MCCF:%'
            GROUP BY ss.id, ss.source_set_key, ss.revision_label, ss.authority, ss.status, ss.last_synced_at
            ORDER BY ss.source_set_key
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function resolveSourceSetId(int $sourceSetId, string $sourceSetKey = ''): int
    {
        if ($sourceSetId > 0) {
            return $sourceSetId;
        }
        $sourceSetKey = trim($sourceSetKey);
        if ($sourceSetKey === '') {
            $sets = $this->listMccfSourceSets();
            return $sets !== array() ? (int)($sets[0]['id'] ?? 0) : 0;
        }
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_canonical_source_sets
            WHERE source_set_key = :key
            LIMIT 1
        ");
        $stmt->execute(array(':key' => $sourceSetKey));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    public function listParts(int $sourceSetId): array
    {
        if ($sourceSetId <= 0) {
            return array();
        }
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT manual_part
            FROM ipca_canonical_requirements
            WHERE source_set_id = :source_set_id
              AND source_status = 'active'
              AND manual_part IS NOT NULL
              AND manual_part <> ''
            ORDER BY manual_part
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $parts = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: array() as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return $parts;
    }

    /**
     * @return array{
     *   total:int,
     *   linked:int,
     *   unlinked:int,
     *   by_part:list<array{part:string,total:int,linked:int,unlinked:int}>
     * }
     */
    public function coverageSummary(int $sourceSetId): array
    {
        if ($sourceSetId <= 0) {
            return array('total' => 0, 'linked' => 0, 'unlinked' => 0, 'by_part' => array());
        }

        $stmt = $this->pdo->prepare("
            SELECT
              COALESCE(NULLIF(TRIM(r.manual_part), ''), '—') AS part_label,
              COUNT(DISTINCT r.id) AS total,
              COUNT(DISTINCT CASE WHEN e.id IS NOT NULL THEN r.id END) AS linked
            FROM ipca_canonical_requirements r
            LEFT JOIN ipca_canonical_requirement_excerpt_links l
              ON l.requirement_id = r.id
             AND l.source_set_id = r.source_set_id
             AND l.source_status = 'active'
            LEFT JOIN ipca_canonical_excerpts e
              ON e.id = l.excerpt_id
             AND e.source_status = 'active'
            WHERE r.source_set_id = :source_set_id
              AND r.source_status = 'active'
            GROUP BY part_label
            ORDER BY part_label
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));

        $byPart = array();
        $total = 0;
        $linked = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $partTotal = (int)($row['total'] ?? 0);
            $partLinked = (int)($row['linked'] ?? 0);
            $total += $partTotal;
            $linked += $partLinked;
            $byPart[] = array(
                'part' => (string)($row['part_label'] ?? '—'),
                'total' => $partTotal,
                'linked' => $partLinked,
                'unlinked' => max(0, $partTotal - $partLinked),
            );
        }

        return array(
            'total' => $total,
            'linked' => $linked,
            'unlinked' => max(0, $total - $linked),
            'by_part' => $byPart,
        );
    }

    /**
     * @param array{part?:string,q?:string,coverage?:string,applicable?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function searchRequirements(
        int $sourceSetId,
        array $filters = array(),
        int $page = 1,
        int $perPage = 50
    ): array {
        if ($sourceSetId <= 0) {
            return array('rows' => array(), 'total' => 0, 'page' => 1, 'per_page' => $perPage);
        }

        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = array(
            'r.source_set_id = :source_set_id',
            "r.source_status = 'active'",
        );
        $params = array(':source_set_id' => $sourceSetId);

        $part = trim((string)($filters['part'] ?? ''));
        if ($part !== '') {
            $where[] = 'r.manual_part = :manual_part';
            $params[':manual_part'] = $part;
        }

        $coverage = strtolower(trim((string)($filters['coverage'] ?? 'all')));
        if ($coverage === 'linked') {
            $where[] = 'EXISTS (
                SELECT 1 FROM ipca_canonical_requirement_excerpt_links l
                INNER JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id AND e.source_status = \'active\'
                WHERE l.requirement_id = r.id
                  AND l.source_set_id = r.source_set_id
                  AND l.source_status = \'active\'
            )';
        } elseif ($coverage === 'unlinked') {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM ipca_canonical_requirement_excerpt_links l
                INNER JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id AND e.source_status = \'active\'
                WHERE l.requirement_id = r.id
                  AND l.source_set_id = r.source_set_id
                  AND l.source_status = \'active\'
            )';
        }

        $applicable = trim((string)($filters['applicable'] ?? ''));
        if ($applicable !== '') {
            $where[] = 'r.applicable = :applicable';
            $params[':applicable'] = $applicable;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(
                r.requirement_key LIKE :q_key
                OR r.subject LIKE :q_subject
                OR r.requirement_text LIKE :q_text
                OR r.manual_section_ref LIKE :q_section
                OR r.regulation_ref LIKE :q_reg
            )';
            $like = '%' . $q . '%';
            $params[':q_key'] = $like;
            $params[':q_subject'] = $like;
            $params[':q_text'] = $like;
            $params[':q_section'] = $like;
            $params[':q_reg'] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ipca_canonical_requirements r
            WHERE {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT
              r.id,
              r.requirement_key,
              r.mccf_id,
              r.manual_code,
              r.manual_part,
              r.item_no,
              r.sub_item_no,
              r.subject,
              r.requirement_text,
              r.regulation_ref,
              r.manual_section_ref,
              r.applicable,
              r.remarks,
              r.finding_ref,
              r.legacy_excerpt_id,
              (
                SELECT COUNT(*)
                FROM ipca_canonical_requirement_excerpt_links l
                WHERE l.requirement_id = r.id
                  AND l.source_set_id = r.source_set_id
                  AND l.source_status = 'active'
              ) AS link_count
            FROM ipca_canonical_requirements r
            WHERE {$whereSql}
            ORDER BY r.manual_part, r.item_no, r.sub_item_no, r.requirement_key
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array(
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getRequirement(int $requirementId): ?array
    {
        if ($requirementId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
              r.*,
              ss.source_set_key,
              ss.revision_label AS source_revision_label,
              (
                SELECT COUNT(*)
                FROM ipca_canonical_requirement_excerpt_links l
                WHERE l.requirement_id = r.id
                  AND l.source_set_id = r.source_set_id
                  AND l.source_status = 'active'
              ) AS link_count
            FROM ipca_canonical_requirements r
            INNER JOIN ipca_canonical_source_sets ss ON ss.id = r.source_set_id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $requirementId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $linkStmt = $this->pdo->prepare("
            SELECT
              l.id AS link_id,
              l.link_type,
              l.confidence,
              l.notes,
              l.excerpt_key,
              e.id AS excerpt_id,
              e.manual_code AS excerpt_manual_code,
              e.title AS excerpt_title,
              e.section_ref AS excerpt_section_ref,
              e.manual_part AS excerpt_manual_part,
              LEFT(e.body_text, 400) AS excerpt_preview
            FROM ipca_canonical_requirement_excerpt_links l
            INNER JOIN ipca_canonical_excerpts e ON e.id = l.excerpt_id
            WHERE l.requirement_id = :requirement_id
              AND l.source_status = 'active'
              AND e.source_status = 'active'
            ORDER BY l.link_type, e.manual_part, e.section_ref, e.excerpt_key
        ");
        $linkStmt->execute(array(':requirement_id' => $requirementId));
        $row['linked_excerpts'] = $linkStmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function sourceSetById(int $sourceSetId): ?array
    {
        if ($sourceSetId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare("
            SELECT id, source_set_key, revision_label, authority, status, last_synced_at
            FROM ipca_canonical_source_sets
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(':id' => $sourceSetId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function formatItemRef(array $row): string
    {
        $item = trim((string)($row['item_no'] ?? ''));
        $sub = trim((string)($row['sub_item_no'] ?? ''));
        if ($item === '') {
            return '—';
        }
        return $sub !== '' ? ($item . '.' . $sub) : $item;
    }

    public static function coverageLabel(int $linkCount, string $legacyExcerptId = ''): string
    {
        if ($linkCount > 0) {
            return $linkCount === 1 ? '1 excerpt' : ($linkCount . ' excerpts');
        }
        if (trim($legacyExcerptId) !== '') {
            return 'Legacy ref only';
        }
        return 'No link';
    }
}
