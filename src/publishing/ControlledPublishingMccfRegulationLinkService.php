<?php
declare(strict_types=1);

require_once __DIR__ . '/../compliance/ComplianceRegulatoryLinkEngine.php';

/**
 * Parse MCCF regulation_ref strings and auto-link to EASA eRules nodes in Resource Library.
 */
final class ControlledPublishingMccfRegulationLinkService
{
    /** @var array<string,list<string>> */
    private const PART_HINTS = array(
        'FCL' => array('Part-FCL', 'PART-FCL', 'Part FCL', 'Aircrew'),
        'ORA' => array('Part-ORA', 'PART-ORA', 'Part ORA', 'Organisations'),
        'CAT' => array('Part-CAT', 'PART-CAT', 'Part CAT'),
        'ARA' => array('Part-ARA', 'PART-ARA', 'Part ARA'),
        'MED' => array('Part-MED', 'PART-MED', 'Part MED'),
        'NCO' => array('Part-NCO', 'PART-NCO', 'Part NCO'),
        'SPO' => array('Part-SPO', 'PART-SPO', 'Part SPO'),
    );

    public function __construct(private PDO $pdo)
    {
    }

    public static function regulationLinksTablePresent(PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE 'ipca_canonical_requirement_regulation_links'");

            return (bool)$st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{token:string,role:string,prefix:?string}>
     */
    public static function parseRegulationRef(string $regulationRef): array
    {
        $regulationRef = trim($regulationRef);
        if ($regulationRef === '') {
            return array();
        }

        $out = array();
        $seen = array();
        foreach (preg_split('/\s*;\s*/u', $regulationRef) ?: array() as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                continue;
            }

            $role = 'PRIMARY';
            if (preg_match('/^(AMC\d*)\s+/iu', $segment, $m)) {
                $role = 'AMC';
                $segment = trim(substr($segment, strlen($m[0])));
            } elseif (preg_match('/^(GM\d*)\s+/iu', $segment, $m)) {
                $role = 'GM';
                $segment = trim(substr($segment, strlen($m[0])));
            }

            if (preg_match_all(
                '/\b(FCL\.\d+[A-Z]?|(?:ORA|CAT|DTO|ARA|MED|NCO|SPO)(?:\.[A-Za-z0-9()]+)+)\b/u',
                $segment,
                $matches
            )) {
                foreach ($matches[1] as $raw) {
                    $token = self::normalizeRuleToken((string)$raw);
                    if ($token === '' || isset($seen[$token])) {
                        continue;
                    }
                    $seen[$token] = true;
                    $prefix = self::rulePrefix($token);
                    $out[] = array(
                        'token' => $token,
                        'role' => $role,
                        'prefix' => $prefix,
                    );
                }
            }
        }

        return $out;
    }

    public static function normalizeRuleToken(string $token): string
    {
        $token = strtoupper(trim($token));
        $token = preg_replace('/\s+/u', '', $token) ?? $token;

        return $token;
    }

    public static function rulePrefix(string $token): ?string
    {
        if (preg_match('/^(FCL|ORA|CAT|DTO|ARA|MED|NCO|SPO)\./u', $token, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    public static function resourceLibraryEasaHref(int $batchId, string $nodeUid): string
    {
        return '/admin/resource_library.php?tab=easa&batch_id=' . $batchId . '&node_uid=' . rawurlencode($nodeUid);
    }

    public static function regulationsSearchHref(string $ruleToken): string
    {
        return '/admin/compliance/regulations.php?corpus=easa&q=' . rawurlencode($ruleToken);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveEasaNode(string $ruleToken, ?string $prefix = null): ?array
    {
        if (!ComplianceRegulatoryLinkEngine::easaStagingPresent($this->pdo)) {
            return null;
        }

        $ruleToken = self::normalizeRuleToken($ruleToken);
        if ($ruleToken === '') {
            return null;
        }
        $prefix = $prefix ?? self::rulePrefix($ruleToken);

        $batchIds = $this->publishedBatchIdsForPrefix($prefix);
        if ($batchIds === array()) {
            $batchIds = $this->publishedBatchIds();
        }

        $candidates = $this->searchNodes($ruleToken, $batchIds);
        if ($candidates === array()) {
            return null;
        }

        return $this->pickBestCandidate($ruleToken, $candidates);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLinksForRequirement(int $requirementId): array
    {
        if ($requirementId <= 0 || !self::regulationLinksTablePresent($this->pdo)) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM ipca_canonical_requirement_regulation_links
            WHERE requirement_id = :requirement_id
              AND source_status = 'active'
            ORDER BY FIELD(link_role, 'PRIMARY', 'AMC', 'GM', 'SUPPORTING'), rule_token
        ");
        $stmt->execute(array(':requirement_id' => $requirementId));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @return array{linked:int,unresolved:int,skipped:int,errors:list<string>}
     */
    public function autoLinkSourceSet(int $sourceSetId, bool $apply = false): array
    {
        $result = array('linked' => 0, 'unresolved' => 0, 'skipped' => 0, 'errors' => array());
        if ($sourceSetId <= 0) {
            $result['errors'][] = 'Invalid source set id.';

            return $result;
        }
        if (!self::regulationLinksTablePresent($this->pdo)) {
            $result['errors'][] = 'Apply scripts/sql/2026_06_06_mccf_regulation_links.sql first.';

            return $result;
        }

        $stmt = $this->pdo->prepare("
            SELECT id, regulation_ref
            FROM ipca_canonical_requirements
            WHERE source_set_id = :source_set_id
              AND source_status = 'active'
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();

        foreach ($rows as $row) {
            $requirementId = (int)($row['id'] ?? 0);
            $tokens = self::parseRegulationRef((string)($row['regulation_ref'] ?? ''));
            if ($tokens === array()) {
                $result['skipped']++;
                continue;
            }

            foreach ($tokens as $tokenRow) {
                $token = (string)$tokenRow['token'];
                $existing = $this->findExistingLink($requirementId, $token);
                if ($existing !== null) {
                    $result['skipped']++;
                    continue;
                }

                $node = $this->resolveEasaNode($token, $tokenRow['prefix'] ?? null);
                if ($node === null) {
                    if ($apply) {
                        $this->insertUnresolvedLink($sourceSetId, $requirementId, $token, (string)$tokenRow['role']);
                    }
                    $result['unresolved']++;
                    continue;
                }

                if ($apply) {
                    $this->insertResolvedLink(
                        $sourceSetId,
                        $requirementId,
                        $token,
                        (string)$tokenRow['role'],
                        $node
                    );
                }
                $result['linked']++;
            }
        }

        return $result;
    }

    /**
     * @return list<array{part:string,label:string,rows:list<array<string,mixed>>}>
     */
    public function listBcaaPartSections(int $sourceSetId, array $filters = array()): array
    {
        $search = (new ControlledPublishingMccfBrowserService($this->pdo))
            ->searchRequirements($sourceSetId, $filters, 1, 5000);
        $rows = $search['rows'];

        /** @var array<string,list<array<string,mixed>>> $grouped */
        $grouped = array();
        foreach ($rows as $row) {
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
                'label' => self::bcaaPartHeading($part),
                'rows' => $partRows,
            );
        }

        return $sections;
    }

    public static function bcaaPartHeading(string $manualPart): string
    {
        $manualPart = trim($manualPart);
        if (preg_match('/PART\s*(\d+)/iu', $manualPart, $m)) {
            $n = (int)$m[1];
            $names = array(
                0 => 'Manual Administration',
                1 => 'General',
                2 => 'Technical',
                3 => 'Route',
                4 => 'Personnel training',
            );

            return 'Part ' . $n . ' – ' . ($names[$n] ?? $manualPart);
        }

        return $manualPart;
    }

    /**
     * @return list<int>
     */
    private function publishedBatchIds(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id
                FROM easa_erules_import_batches
                WHERE status IN ('published', 'ready_for_review')
                ORDER BY id DESC
            ");

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array());
        } catch (Throwable) {
            return array();
        }
    }

    /**
     * @return list<int>
     */
    private function publishedBatchIdsForPrefix(?string $prefix): array
    {
        $prefix = $prefix !== null ? strtoupper(trim($prefix)) : '';
        if ($prefix === '' || !isset(self::PART_HINTS[$prefix])) {
            return array();
        }

        $hints = self::PART_HINTS[$prefix];
        $ids = array();
        try {
            $stmt = $this->pdo->query("
                SELECT id, original_filename, publication_meta_json
                FROM easa_erules_import_batches
                WHERE status IN ('published', 'ready_for_review')
                ORDER BY id DESC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $batch) {
                $hay = strtoupper((string)($batch['original_filename'] ?? ''));
                $meta = json_encode($batch['publication_meta_json'] ?? '', JSON_UNESCAPED_UNICODE) ?: '';
                $hay .= ' ' . strtoupper($meta);
                foreach ($hints as $hint) {
                    if (str_contains($hay, strtoupper($hint))) {
                        $ids[] = (int)$batch['id'];
                        break;
                    }
                }
            }
        } catch (Throwable) {
            return array();
        }

        return $ids;
    }

    /**
     * @param list<int> $batchIds
     * @return list<array<string,mixed>>
     */
    private function searchNodes(string $ruleToken, array $batchIds): array
    {
        $where = array('(title LIKE :like OR breadcrumb LIKE :like OR plain_text LIKE :like OR source_erules_id LIKE :like)');
        $params = array(':like' => '%' . $ruleToken . '%');
        if ($batchIds !== array()) {
            $in = implode(',', array_map('intval', $batchIds));
            $where[] = 'batch_id IN (' . $in . ')';
        }

        $sql = '
            SELECT batch_id, node_uid, source_erules_id, title, breadcrumb, node_type, depth
            FROM easa_erules_import_nodes_staging
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY depth ASC, id ASC
            LIMIT 40
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    private function pickBestCandidate(string $ruleToken, array $candidates): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($candidates as $row) {
            $score = 0;
            $title = strtoupper(trim((string)($row['title'] ?? '')));
            $firstLine = strtoupper(trim(strtok($title, "\n") ?: $title));
            if ($firstLine === $ruleToken || str_starts_with($firstLine, $ruleToken . ' ')) {
                $score += 100;
            } elseif (str_contains($title, $ruleToken)) {
                $score += 60;
            }
            $breadcrumb = strtoupper((string)($row['breadcrumb'] ?? ''));
            if (str_contains($breadcrumb, $ruleToken)) {
                $score += 20;
            }
            $erules = strtoupper((string)($row['source_erules_id'] ?? ''));
            if (str_contains($erules, $ruleToken)) {
                $score += 40;
            }
            if (preg_match('/\b(AMC|GM)\d*\b/u', $firstLine)) {
                $score -= 15;
            }
            $depth = (int)($row['depth'] ?? 0);
            $score -= min(10, $depth);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $bestScore >= 20 ? $best : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findExistingLink(int $requirementId, string $ruleToken): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM ipca_canonical_requirement_regulation_links
            WHERE requirement_id = :requirement_id
              AND rule_token = :rule_token
              AND target_source = 'easa_erules'
              AND source_status = 'active'
            LIMIT 1
        ");
        $stmt->execute(array(
            ':requirement_id' => $requirementId,
            ':rule_token' => $ruleToken,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $node
     */
    private function insertResolvedLink(
        int $sourceSetId,
        int $requirementId,
        string $ruleToken,
        string $role,
        array $node
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_canonical_requirement_regulation_links (
              requirement_id, source_set_id, rule_token, link_role, target_source,
              target_batch_id, target_node_uid, target_erules_id, target_title, target_breadcrumb,
              match_confidence, source_status
            ) VALUES (
              :requirement_id, :source_set_id, :rule_token, :link_role, 'easa_erules',
              :target_batch_id, :target_node_uid, :target_erules_id, :target_title, :target_breadcrumb,
              'AUTO', 'active'
            )
            ON DUPLICATE KEY UPDATE
              target_batch_id = VALUES(target_batch_id),
              target_node_uid = VALUES(target_node_uid),
              target_erules_id = VALUES(target_erules_id),
              target_title = VALUES(target_title),
              target_breadcrumb = VALUES(target_breadcrumb),
              match_confidence = VALUES(match_confidence),
              source_status = 'active',
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':requirement_id' => $requirementId,
            ':source_set_id' => $sourceSetId,
            ':rule_token' => $ruleToken,
            ':link_role' => $role,
            ':target_batch_id' => (int)($node['batch_id'] ?? 0) ?: null,
            ':target_node_uid' => (string)($node['node_uid'] ?? ''),
            ':target_erules_id' => (string)($node['source_erules_id'] ?? ''),
            ':target_title' => mb_substr((string)($node['title'] ?? ''), 0, 512),
            ':target_breadcrumb' => (string)($node['breadcrumb'] ?? ''),
        ));
    }

    private function insertUnresolvedLink(
        int $sourceSetId,
        int $requirementId,
        string $ruleToken,
        string $role
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO ipca_canonical_requirement_regulation_links (
              requirement_id, source_set_id, rule_token, link_role, target_source,
              match_confidence, source_status, notes
            ) VALUES (
              :requirement_id, :source_set_id, :rule_token, :link_role, 'easa_erules',
              'UNRESOLVED', 'active', 'No matching EASA eRules node found during auto-link'
            )
            ON DUPLICATE KEY UPDATE
              match_confidence = 'UNRESOLVED',
              source_status = 'active',
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(array(
            ':requirement_id' => $requirementId,
            ':source_set_id' => $sourceSetId,
            ':rule_token' => $ruleToken,
            ':link_role' => $role,
        ));
    }
}
