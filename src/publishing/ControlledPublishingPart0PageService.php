<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';

/**
 * Part 0 — Manual Administration outline pages (0.1–0.7).
 */
final class ControlledPublishingPart0PageService
{
    public const PART_TITLE = 'PART 0 – Manual Administration';

    public const OUTLINE_TITLE = '0. OUTLINE';

    /** @var list<string> */
    public const PART0_SECTION_KEYS = array(
        'lep',
        'revision_system',
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
        'highlights',
    );

    /** @var array<string,array{number:string,label:string,sidebar_title:string}> */
    public const PAGE_REGISTRY = array(
        'lep' => array(
            'number' => '0.1',
            'label' => 'List of effective Parts',
            'sidebar_title' => 'List of Effective Parts + E-Signature',
        ),
        'revision_system' => array(
            'number' => '0.2',
            'label' => 'Revision System',
            'sidebar_title' => 'Revision System',
        ),
        'amendment_list' => array(
            'number' => '0.3',
            'label' => 'Amendment List',
            'sidebar_title' => 'Amendment List',
        ),
        'distribution_list' => array(
            'number' => '0.4',
            'label' => 'Distribution List',
            'sidebar_title' => 'Distribution List',
        ),
        'abbreviations' => array(
            'number' => '0.5',
            'label' => 'Index of Abbreviations',
            'sidebar_title' => 'Index of Abbreviations',
        ),
        'definitions' => array(
            'number' => '0.6',
            'label' => 'Definitions and Terms',
            'sidebar_title' => 'Definitions and Terms',
        ),
        'highlights' => array(
            'number' => '0.7',
            'label' => 'Highlight of Changes',
            'sidebar_title' => 'Highlight of Changes',
        ),
    );

    public function __construct(
        private PDO $pdo,
        private ?ControlledPublishingBlockService $blocks = null
    ) {
    }

    public function isPart0SectionKey(string $sectionKey): bool
    {
        return isset(self::PAGE_REGISTRY[$sectionKey]);
    }

    /** @var list<string> */
    public const STRUCTURED_PAGE_KEYS = array(
        'amendment_list',
        'distribution_list',
        'abbreviations',
        'definitions',
    );

    /**
     * @return list<array{key:string,style:string,text:string}>
     */
    public function defaultHeadingsForSection(string $sectionKey): array
    {
        $def = self::PAGE_REGISTRY[$sectionKey] ?? null;
        if ($def === null) {
            return array();
        }
        $subtitle = $def['number'] . ' ' . $def['label'];
        return array(
            array('key' => 'part_title', 'style' => 'title', 'text' => self::PART_TITLE),
            array('key' => 'title', 'style' => 'title', 'text' => self::OUTLINE_TITLE),
            array('key' => 'subtitle_1', 'style' => 'subtitle_1', 'text' => $subtitle),
        );
    }

    /**
     * @param array<string,mixed> $version
     * @return list<array{key:string,style:string,text:string}>
     */
    public function resolveHeadingsForSection(string $sectionKey, array $version): array
    {
        $headings = $this->defaultHeadingsForSection($sectionKey);
        $meta = $this->decodeMeta($version);
        $raw = is_array($meta['part0_pages'][$sectionKey]['headings'] ?? null)
            ? $meta['part0_pages'][$sectionKey]['headings']
            : array();
        $byKey = array();
        foreach ($raw as $heading) {
            if (!is_array($heading)) {
                continue;
            }
            $key = (string)($heading['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $byKey[$key] = $heading;
        }
        foreach ($headings as $idx => $heading) {
            $key = (string)($heading['key'] ?? '');
            if ($key === '' || !isset($byKey[$key])) {
                continue;
            }
            $override = $byKey[$key];
            if (isset($override['text'])) {
                $headings[$idx]['text'] = (string)$override['text'];
            }
        }
        return $headings;
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultAmendmentListPage(): array
    {
        return array(
            'rows' => array(
                array('revision_nr' => 'Original', 'reason' => '', 'revision_date' => '10/02/08', 'effective_date' => '10/02/08', 'date_incorp' => '10/02/08', 'incorp_by' => 'KVM'),
                array('revision_nr' => 'Rev 1', 'reason' => '', 'revision_date' => '01/06/09', 'effective_date' => '01/06/09', 'date_incorp' => '01/06/09', 'incorp_by' => 'KVM'),
                array('revision_nr' => 'Rev 2', 'reason' => '', 'revision_date' => '01/09/10', 'effective_date' => '01/09/10', 'date_incorp' => '01/09/10', 'incorp_by' => 'KVM'),
                array('revision_nr' => 'Rev 3', 'reason' => '', 'revision_date' => '01/03/12', 'effective_date' => '01/03/12', 'date_incorp' => '01/03/12', 'incorp_by' => 'KVM'),
                array('revision_nr' => 'Rev 4', 'reason' => '', 'revision_date' => '01/06/14', 'effective_date' => '01/06/14', 'date_incorp' => '01/06/14', 'incorp_by' => 'KVM'),
                array('revision_nr' => 'Rev 5', 'reason' => '', 'revision_date' => '01/09/16', 'effective_date' => '01/09/16', 'date_incorp' => '01/09/16', 'incorp_by' => 'KVM'),
                array('revision_nr' => 'Rev 6', 'reason' => '', 'revision_date' => '09/01/23', 'effective_date' => '09/01/23', 'date_incorp' => '09/01/23', 'incorp_by' => 'KVM'),
            ),
            'empty_rows' => 8,
            'footer_notice' => 'RETAIN SHEET UNTIL REPLACED WITH NEW ISSUE',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultDistributionListPage(): array
    {
        return array(
            'rows' => array(
                array('copy_nr' => '0', 'issue_to' => 'Master Copy'),
                array('copy_nr' => '1', 'issue_to' => 'BCAA - Training Department (Signed Digital PDF file)'),
            ),
            'empty_rows' => 10,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultDefinitionsPage(): array
    {
        return array(
            'entries' => array(),
            'empty_rows' => 12,
        );
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveAmendmentListFromVersion(array $version): array
    {
        $meta = $this->decodeMeta($version);
        $raw = is_array($meta['part0_pages']['amendment_list'] ?? null)
            ? $meta['part0_pages']['amendment_list']
            : array();
        return $this->normalizeAmendmentListPage($raw);
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveDistributionListFromVersion(array $version): array
    {
        $meta = $this->decodeMeta($version);
        $raw = is_array($meta['part0_pages']['distribution_list'] ?? null)
            ? $meta['part0_pages']['distribution_list']
            : array();
        return $this->normalizeDistributionListPage($raw);
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveDefinitionsFromVersion(array $version): array
    {
        $meta = $this->decodeMeta($version);
        $raw = is_array($meta['part0_pages']['definitions'] ?? null)
            ? $meta['part0_pages']['definitions']
            : array();
        return $this->normalizeDefinitionsPage($raw);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveAmendmentListForVersion(int $versionId, array $payload, ?int $actorUserId = null): array
    {
        return $this->saveStructuredPage($versionId, 'amendment_list', $this->normalizeAmendmentListPage($payload));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveDistributionListForVersion(int $versionId, array $payload, ?int $actorUserId = null): array
    {
        return $this->saveStructuredPage($versionId, 'distribution_list', $this->normalizeDistributionListPage($payload));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveDefinitionsForVersion(int $versionId, array $payload, ?int $actorUserId = null): array
    {
        return $this->saveStructuredPage($versionId, 'definitions', $this->normalizeDefinitionsPage($payload));
    }

    /**
     * @param list<array{key:string,style:string,text:string}> $headings
     * @return list<array{key:string,style:string,text:string}>
     */
    public function saveHeadingsForSection(int $versionId, string $sectionKey, array $headings, ?int $actorUserId = null): array
    {
        if (!isset(self::PAGE_REGISTRY[$sectionKey])) {
            throw new RuntimeException('Unknown Part 0 section.');
        }
        $normalized = array();
        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }
            $key = (string)($heading['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $normalized[] = array(
                'key' => $key,
                'style' => (string)($heading['style'] ?? 'body'),
                'text' => $this->truncate(trim((string)($heading['text'] ?? '')), 500),
            );
        }
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        if (!is_array($meta['part0_pages'] ?? null)) {
            $meta['part0_pages'] = array();
        }
        if (!is_array($meta['part0_pages'][$sectionKey] ?? null)) {
            $meta['part0_pages'][$sectionKey] = array();
        }
        $meta['part0_pages'][$sectionKey]['headings'] = $normalized;
        $this->persistMeta($versionId, $meta);
        return $this->resolveHeadingsForSection($sectionKey, $this->requireVersion($versionId));
    }

    public function isStructuredPageKey(string $sectionKey): bool
    {
        return in_array($sectionKey, self::STRUCTURED_PAGE_KEYS, true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function collectOutlineTocEntries(int $versionId): array
    {
        $entries = array();
        $entries[] = array(
            'block_id' => 0,
            'section_id' => 0,
            'target_anchor' => '',
            'label' => self::OUTLINE_TITLE,
            'text' => self::OUTLINE_TITLE,
            'number' => '',
            'depth' => 0,
            'style' => 'title',
            'entry_type' => 'part0_outline',
            'page' => null,
        );

        $stmt = $this->pdo->prepare("
            SELECT id, section_key, stable_anchor, title
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id
              AND section_key IN ('lep','revision_system','amendment_list','distribution_list','abbreviations','definitions','highlights')
            ORDER BY sort_order, id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string)($row['section_key'] ?? '');
            $def = self::PAGE_REGISTRY[$key] ?? null;
            if ($def === null) {
                continue;
            }
            $entries[] = array(
                'block_id' => 0,
                'section_id' => (int)($row['id'] ?? 0),
                'target_anchor' => (string)($row['stable_anchor'] ?? ''),
                'label' => $def['number'] . ' ' . $def['label'],
                'text' => $def['label'],
                'number' => $def['number'],
                'depth' => 1,
                'style' => 'subtitle_1',
                'entry_type' => 'part0_section',
                'page' => null,
            );
        }
        return $entries;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveAbbreviationsPageFromVersion(array $version): array
    {
        $meta = $this->decodeMeta($version);
        $raw = is_array($meta['part0_pages']['abbreviations'] ?? null)
            ? $meta['part0_pages']['abbreviations']
            : array();
        return $this->normalizeAbbreviationsPage($raw);
    }

    /**
     * @return array{section_id:int,entries_count:int}
     */
    public function regenerateAbbreviationsIndex(int $versionId, ?int $actorUserId = null): array
    {
        $existing = $this->resolveAbbreviationsPageFromVersion($this->requireVersion($versionId));
        $definitionsByAbbr = array();
        foreach ($existing['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $abbr = strtoupper(trim((string)($entry['abbreviation'] ?? '')));
            if ($abbr === '') {
                continue;
            }
            $definitionsByAbbr[$abbr] = trim((string)($entry['definition'] ?? ''));
        }

        $found = $this->scanManualForAbbreviations($versionId);
        $entries = array();
        foreach ($found as $abbr) {
            $entries[] = array(
                'abbreviation' => $abbr,
                'definition' => $definitionsByAbbr[$abbr] ?? '',
            );
        }

        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        if (!is_array($meta['part0_pages'] ?? null)) {
            $meta['part0_pages'] = array();
        }
        $meta['part0_pages']['abbreviations'] = array(
            'entries' => $entries,
            'empty_rows' => (int)($existing['empty_rows'] ?? 10),
        );

        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));

        $this->syncSectionTitle($versionId, 'abbreviations', self::PAGE_REGISTRY['abbreviations']['sidebar_title']);

        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'abbreviations'),
            'entries_count' => count($entries),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveAbbreviationsPageForVersion(int $versionId, array $payload, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        if (!is_array($meta['part0_pages'] ?? null)) {
            $meta['part0_pages'] = array();
        }
        $meta['part0_pages']['abbreviations'] = $this->normalizeAbbreviationsPage($payload);
        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));
        return $this->resolveAbbreviationsPageFromVersion($this->requireVersion($versionId));
    }

    /**
     * @return list<string>
     */
    private function scanManualForAbbreviations(int $versionId): array
    {
        $blocksSvc = $this->blocks ?? new ControlledPublishingBlockService($this->pdo);
        $stmt = $this->pdo->prepare("
            SELECT b.payload_json, b.block_type, s.section_key
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key NOT IN ('cover','toc','highlights','abbreviations','lep')
            ORDER BY s.sort_order, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $counts = array();
        $stopwords = array(
            'THE', 'AND', 'FOR', 'ARE', 'BUT', 'NOT', 'YOU', 'ALL', 'CAN', 'HER', 'WAS', 'ONE',
            'OUR', 'OUT', 'DAY', 'HAD', 'HAS', 'HIS', 'HOW', 'ITS', 'MAY', 'NEW', 'NOW', 'OLD',
            'SEE', 'WAY', 'WHO', 'BOY', 'DID', 'GET', 'LET', 'PUT', 'SAY', 'SHE', 'TOO', 'USE',
            'PART', 'PAGE', 'DATE', 'REV', 'OM', 'OMM', 'PDF', 'HTML', 'HTTP', 'HTTPS', 'WWW',
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                continue;
            }
            $text = $this->extractTextFromBlock((string)($row['block_type'] ?? ''), $payload);
            if ($text === '') {
                continue;
            }
            if (preg_match_all('/\b[A-Z][A-Z0-9]{1,5}\b/u', $text, $matches)) {
                foreach ($matches[0] as $token) {
                    $token = strtoupper(trim((string)$token));
                    if ($token === '' || in_array($token, $stopwords, true)) {
                        continue;
                    }
                    if (!isset($counts[$token])) {
                        $counts[$token] = 0;
                    }
                    $counts[$token]++;
                }
            }
        }

        $found = array();
        foreach ($counts as $token => $count) {
            if ($count >= 1) {
                $found[] = $token;
            }
        }
        sort($found, SORT_STRING);
        return $found;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractTextFromBlock(string $blockType, array $payload): string
    {
        if ($blockType === 'heading') {
            return trim((string)($payload['text'] ?? ''));
        }
        if ($blockType === 'paragraph') {
            $html = (string)($payload['html'] ?? '');
            if ($html === '' && isset($payload['text'])) {
                $html = (string)$payload['text'];
            }
            return trim(strip_tags($html));
        }
        if ($blockType === 'list') {
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : array();
            $parts = array();
            foreach ($items as $item) {
                $parts[] = trim(strip_tags((string)$item));
            }
            return trim(implode(' ', $parts));
        }
        if ($blockType === 'table') {
            $parts = array();
            foreach (is_array($payload['headers'] ?? null) ? $payload['headers'] : array() as $cell) {
                $parts[] = trim(strip_tags((string)$cell));
            }
            foreach (is_array($payload['rows'] ?? null) ? $payload['rows'] : array() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $cell) {
                    $parts[] = trim(strip_tags((string)$cell));
                }
            }
            return trim(implode(' ', $parts));
        }
        return '';
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{entries:list<array{abbreviation:string,definition:string}>,empty_rows:int}
     */
    private function normalizeAbbreviationsPage(array $raw): array
    {
        $entries = array();
        foreach (is_array($raw['entries'] ?? null) ? $raw['entries'] : array() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $abbr = $this->truncate(strtoupper(trim((string)($row['abbreviation'] ?? ''))), 32);
            if ($abbr === '') {
                continue;
            }
            $entries[$abbr] = array(
                'abbreviation' => $abbr,
                'definition' => $this->truncate(trim((string)($row['definition'] ?? '')), 500),
            );
        }
        ksort($entries, SORT_STRING);
        return array(
            'entries' => array_values($entries),
            'empty_rows' => max(0, min(20, (int)($raw['empty_rows'] ?? 10))),
        );
    }

    private function syncSectionTitle(int $versionId, string $sectionKey, string $title): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_sections
            SET title = :title, updated_at = CURRENT_TIMESTAMP
            WHERE book_version_id = :version_id AND section_key = :section_key
        ");
        $stmt->execute(array(
            ':title' => $title,
            ':version_id' => $versionId,
            ':section_key' => $sectionKey,
        ));
    }

    private function sectionIdByKey(int $versionId, string $sectionKey): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId, ':section_key' => $sectionKey));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function requireVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ipca_publishing_book_versions WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $versionId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Version not found.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decodeMeta(array $version): array
    {
        $raw = $version['metadata_json'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeAmendmentListPage(array $raw): array
    {
        $defaults = $this->defaultAmendmentListPage();
        $rows = array();
        foreach (is_array($raw['rows'] ?? null) ? $raw['rows'] : $defaults['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = array(
                'revision_nr' => $this->truncate(trim((string)($row['revision_nr'] ?? '')), 64),
                'reason' => $this->truncate(trim((string)($row['reason'] ?? '')), 500),
                'revision_date' => $this->truncate(trim((string)($row['revision_date'] ?? '')), 32),
                'effective_date' => $this->truncate(trim((string)($row['effective_date'] ?? '')), 32),
                'date_incorp' => $this->truncate(trim((string)($row['date_incorp'] ?? '')), 32),
                'incorp_by' => $this->truncate(trim((string)($row['incorp_by'] ?? '')), 64),
            );
        }
        if ($rows === array()) {
            $rows = $defaults['rows'];
        }
        return array(
            'rows' => $rows,
            'empty_rows' => max(0, min(30, (int)($raw['empty_rows'] ?? $defaults['empty_rows']))),
            'footer_notice' => $this->truncate(
                trim((string)($raw['footer_notice'] ?? $defaults['footer_notice'])),
                500
            ),
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeDistributionListPage(array $raw): array
    {
        $defaults = $this->defaultDistributionListPage();
        $rows = array();
        foreach (is_array($raw['rows'] ?? null) ? $raw['rows'] : $defaults['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = array(
                'copy_nr' => $this->truncate(trim((string)($row['copy_nr'] ?? '')), 32),
                'issue_to' => $this->truncate(trim((string)($row['issue_to'] ?? '')), 500),
            );
        }
        if ($rows === array()) {
            $rows = $defaults['rows'];
        }
        return array(
            'rows' => $rows,
            'empty_rows' => max(0, min(30, (int)($raw['empty_rows'] ?? $defaults['empty_rows']))),
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeDefinitionsPage(array $raw): array
    {
        $defaults = $this->defaultDefinitionsPage();
        $entries = array();
        foreach (is_array($raw['entries'] ?? null) ? $raw['entries'] : array() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term = trim((string)($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $entries[] = array(
                'term' => $this->truncate($term, 200),
                'definition' => $this->truncate(trim((string)($row['definition'] ?? '')), 2000),
            );
        }
        return array(
            'entries' => $entries,
            'empty_rows' => max(0, min(30, (int)($raw['empty_rows'] ?? $defaults['empty_rows']))),
        );
    }

    /**
     * @param array<string,mixed> $pageData
     * @return array<string,mixed>
     */
    private function saveStructuredPage(int $versionId, string $sectionKey, array $pageData): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        if (!is_array($meta['part0_pages'] ?? null)) {
            $meta['part0_pages'] = array();
        }
        $meta['part0_pages'][$sectionKey] = $pageData;
        $this->persistMeta($versionId, $meta);
        $def = self::PAGE_REGISTRY[$sectionKey] ?? null;
        if ($def !== null) {
            $this->syncSectionTitle($versionId, $sectionKey, $def['sidebar_title']);
        }
        return match ($sectionKey) {
            'amendment_list' => $this->resolveAmendmentListFromVersion($this->requireVersion($versionId)),
            'distribution_list' => $this->resolveDistributionListFromVersion($this->requireVersion($versionId)),
            'definitions' => $this->resolveDefinitionsFromVersion($this->requireVersion($versionId)),
            'abbreviations' => $this->resolveAbbreviationsPageFromVersion($this->requireVersion($versionId)),
            default => $pageData,
        };
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function persistMeta(int $versionId, array $meta): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE ipca_publishing_book_versions
            SET metadata_json = :metadata_json, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(array(
            ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id' => $versionId,
        ));
    }
}
