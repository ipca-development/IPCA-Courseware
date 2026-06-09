<?php
declare(strict_types=1);

require_once __DIR__ . '/ControlledPublishingBlockService.php';

/**
 * Part 0 — Manual Administration outline pages (0.1–0.7).
 */
final class ControlledPublishingPart0PageService
{
    public const PART_TITLE = 'PART 0 – Manual Administration';

    /** Tokens that are plain English words / navaid ids, not manual abbreviations. */
    private const ABBREVIATION_FALSE_POSITIVES = array(
        'ABOVE', 'AFTER', 'AGAIN', 'AIDS', 'AIR', 'ALSO', 'AMONG', 'BEEN', 'BOTH', 'CENTER', 'CENTRE',
        'CHILD', 'CLASS', 'CLOUD', 'CREW', 'DATA', 'DATE', 'DUTY', 'EACH', 'ENGINE', 'FAIL', 'FEMALE',
        'FIRST', 'FLIGHT', 'FLYING', 'FROM', 'FUEL', 'GIVEN', 'HAVE', 'HEIGHT', 'INTO', 'LIST', 'LOWEST',
        'MALE', 'MASTER', 'METEO', 'MINIMA', 'MORE', 'MUST', 'ONLY', 'ON', 'OFF', 'OF', 'OIL', 'OP',
        'OTHER', 'OVER', 'PART', 'PAGE', 'QUINTA', 'REST', 'REV', 'SAFETY', 'SAME', 'SOME', 'START',
        'STYL', 'SUCH', 'SYSTEM', 'TABLE', 'TAKE', 'TAXI', 'THAN', 'THAT', 'THEIR', 'THEM', 'THEN',
        'THESE', 'THEY', 'THIS', 'THOSE', 'TIME', 'TRIP', 'UNDER', 'UP', 'USED', 'VERY', 'WHEN',
        'WHERE', 'WHICH', 'WHILE', 'WITH', 'YOUR', 'ABOUT', 'COULD', 'SHALL', 'SHOULD', 'WOULD',
        'BEING', 'DOING', 'GOING', 'HAVING', 'MAKING', 'TAKING', 'USING', 'WORKING', 'DURING',
        'BEFORE', 'BETWEEN', 'WITHIN', 'WITHOUT', 'AGAINST', 'ACROSS', 'BEHIND', 'BELOW', 'ABOVE',
        'VIS', 'FUEL', 'TRIP', 'PDF', 'USG', 'MSL', 'AMSL', 'AGL', 'RWY', 'FT', 'NM', 'KT', 'KG',
        'BE', 'US', 'EU', 'EC', 'BY', 'NA', 'OM', 'FI', 'MAM', 'EPC', 'KAY', 'CAA', 'USA', 'NIL',
        'ASSUMED', 'ENG', 'HOLD', 'STOP', 'GO', 'DUE', 'REF', 'CAT', 'GEN', 'SERA', 'NIGHT', 'NOTAM',
    );

    /** Minimum times an abbreviation must appear in the manual to be auto-discovered. */
    private const ABBREVIATION_MIN_OCCURRENCES = 4;

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
     * Sync amendment list from canonical / prior version and append a row for the current draft revision.
     *
     * @return array{section_id:int,rows_count:int,source:string,appended:bool}
     */
    public function ensureAmendmentListForVersion(int $versionId, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $page = $this->resolveAmendmentListFromVersion($version);
        $source = 'existing';
        $changed = false;

        if ($this->amendmentListNeedsCanonicalSync($page)) {
            $canonicalRows = $this->loadAmendmentListFromCanonical($versionId);
            if ($canonicalRows !== array()) {
                $page = $this->normalizeAmendmentListPage(array(
                    'rows' => $canonicalRows,
                    'empty_rows' => (int)($page['empty_rows'] ?? 8),
                    'synced_from' => 'canonical',
                ));
                $source = 'canonical';
                $changed = true;
            }
        }

        if (($page['rows'] ?? array()) === array()) {
            $inheritedRows = $this->loadAmendmentListFromPriorVersion($versionId);
            if ($inheritedRows !== array()) {
                $page = $this->normalizeAmendmentListPage(array(
                    'rows' => $inheritedRows,
                    'empty_rows' => (int)($page['empty_rows'] ?? 8),
                    'synced_from' => 'prior_version',
                ));
                $source = 'prior_version';
                $changed = true;
            }
        }

        $withDraftRow = $this->appendDraftRevisionRowIfNeeded($page, $version);
        $appended = ($withDraftRow['draft_revision_appended'] ?? false) === true;
        unset($withDraftRow['draft_revision_appended']);
        if ($appended) {
            $page = $withDraftRow;
            $changed = true;
            if ($source === 'existing') {
                $source = 'draft_revision';
            }
        }

        if (!$changed) {
            return array(
                'section_id' => $this->sectionIdByKey($versionId, 'amendment_list'),
                'rows_count' => count($page['rows'] ?? array()),
                'source' => $source,
                'appended' => false,
            );
        }

        $this->saveStructuredPage($versionId, 'amendment_list', $page);
        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'amendment_list'),
            'rows_count' => count($page['rows'] ?? array()),
            'source' => $source,
            'appended' => $appended,
        );
    }

    /**
     * @return array{section_id:int,rows_count:int,source:string}
     */
    public function importAmendmentListFromCanonical(int $versionId, ?int $actorUserId = null): array
    {
        $existing = $this->resolveAmendmentListFromVersion($this->requireVersion($versionId));
        $rows = $this->loadAmendmentListFromCanonical($versionId);
        if ($rows === array()) {
            throw new RuntimeException('No amendment list found in the linked manual source set.');
        }

        $page = $this->normalizeAmendmentListPage(array(
            'rows' => $rows,
            'empty_rows' => (int)($existing['empty_rows'] ?? 8),
            'synced_from' => 'canonical',
        ));
        $page = $this->appendDraftRevisionRowIfNeeded($page, $this->requireVersion($versionId));
        unset($page['draft_revision_appended']);
        $this->saveStructuredPage($versionId, 'amendment_list', $page);

        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'amendment_list'),
            'rows_count' => count($page['rows'] ?? array()),
            'source' => 'canonical',
        );
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
     * @return array{section_id:int,entries_count:int,source:string}
     */
    public function ensureAbbreviationsForVersion(int $versionId, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $page = $this->resolveAbbreviationsPageFromVersion($version);
        if ($this->abbreviationsListNeedsCanonicalSync($page)) {
            return $this->importAbbreviationsFromCanonical($versionId, $actorUserId);
        }

        if ($this->abbreviationsNeedDefinitionCompletion($page)) {
            $completed = $this->completeAbbreviationDefinitions($versionId, $page, $actorUserId);
            $this->saveAbbreviationsPageForVersion($versionId, $completed, $actorUserId);
            return array(
                'section_id' => $this->sectionIdByKey($versionId, 'abbreviations'),
                'entries_count' => count($completed['entries'] ?? array()),
                'source' => 'completed',
                'needs_review_count' => $this->countAbbreviationsNeedingReview($completed),
            );
        }

        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'abbreviations'),
            'entries_count' => count($page['entries'] ?? array()),
            'source' => 'existing',
            'needs_review_count' => $this->countAbbreviationsNeedingReview($page),
        );
    }

    /**
     * @return array{section_id:int,entries_count:int,source:string}
     */
    public function importAbbreviationsFromCanonical(int $versionId, ?int $actorUserId = null): array
    {
        $existing = $this->resolveAbbreviationsPageFromVersion($this->requireVersion($versionId));

        $canonicalEntries = $this->loadAbbreviationsFromCanonical($versionId);
        if ($canonicalEntries === array()) {
            throw new RuntimeException('No abbreviations found in the linked manual source set.');
        }

        $merged = array();
        foreach ($canonicalEntries as $entry) {
            $abbr = strtoupper(trim((string)($entry['abbreviation'] ?? '')));
            if ($abbr === '') {
                continue;
            }
            $merged[$abbr] = array(
                'abbreviation' => $abbr,
                'definition' => trim((string)($entry['definition'] ?? '')),
                'definition_status' => trim((string)($entry['definition'] ?? '')) !== '' ? 'confirmed' : '',
            );
        }

        $pageData = $this->normalizeAbbreviationsPage(array(
            'entries' => array_values($merged),
            'empty_rows' => (int)($existing['empty_rows'] ?? 10),
            'synced_from' => 'canonical',
        ));
        $pageData = $this->completeAbbreviationDefinitions($versionId, $pageData, $actorUserId);
        $this->saveAbbreviationsPageForVersion($versionId, $pageData, $actorUserId);

        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'abbreviations'),
            'entries_count' => count($pageData['entries']),
            'source' => 'canonical',
            'needs_review_count' => $this->countAbbreviationsNeedingReview($pageData),
        );
    }

    /**
     * Fill missing abbreviation meanings from manual context and AI; flag uncertain rows.
     *
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    public function completeAbbreviationDefinitions(int $versionId, array $page, ?int $actorUserId = null): array
    {
        $entries = is_array($page['entries'] ?? null) ? $page['entries'] : array();
        $byAbbr = array();
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $abbr = strtoupper(trim((string)($entry['abbreviation'] ?? '')));
            if ($abbr === '') {
                continue;
            }
            $byAbbr[$abbr] = array(
                'abbreviation' => $abbr,
                'definition' => trim((string)($entry['definition'] ?? '')),
                'definition_status' => trim((string)($entry['definition_status'] ?? '')),
            );
        }

        foreach ($this->discoverAbbreviationsFromManual($versionId) as $abbr => $discovered) {
            if (!isset($byAbbr[$abbr])) {
                $byAbbr[$abbr] = array(
                    'abbreviation' => $abbr,
                    'definition' => trim((string)($discovered['definition'] ?? '')),
                    'definition_status' => trim((string)($discovered['definition'] ?? '')) !== '' ? 'confirmed' : '',
                );
                continue;
            }
            if ($byAbbr[$abbr]['definition'] === '' && trim((string)($discovered['definition'] ?? '')) !== '') {
                $byAbbr[$abbr]['definition'] = trim((string)$discovered['definition']);
                $byAbbr[$abbr]['definition_status'] = 'confirmed';
            }
        }

        foreach ($this->scanManualForAbbreviationExpansions($versionId, array_keys($byAbbr)) as $abbr => $expansion) {
            if (!isset($byAbbr[$abbr])) {
                continue;
            }
            if ($byAbbr[$abbr]['definition'] === '' && ($expansion['definition'] ?? '') !== '') {
                $byAbbr[$abbr]['definition'] = $expansion['definition'];
                $byAbbr[$abbr]['definition_status'] = 'confirmed';
            }
        }

        $byAbbr = $this->fillAbbreviationDefinitionsWithAi($versionId, $byAbbr);

        foreach ($byAbbr as $abbr => $entry) {
            $def = trim((string)($entry['definition'] ?? ''));
            if ($def !== '' && !$this->looksLikeDefinitionPhrase($def)) {
                $byAbbr[$abbr]['definition'] = '';
                $byAbbr[$abbr]['definition_status'] = 'needs_review';
            }
        }

        foreach ($byAbbr as $abbr => $entry) {
            if (trim((string)($entry['definition'] ?? '')) === '') {
                $byAbbr[$abbr]['definition_status'] = 'needs_review';
            } elseif (($entry['definition_status'] ?? '') === '') {
                $byAbbr[$abbr]['definition_status'] = 'confirmed';
            }
        }

        ksort($byAbbr, SORT_STRING);
        $page['entries'] = array_values($byAbbr);
        return $this->normalizeAbbreviationsPage($page);
    }

    /**
     * @param array<string,mixed> $page
     */
    private function abbreviationsNeedDefinitionCompletion(array $page): bool
    {
        foreach (is_array($page['entries'] ?? null) ? $page['entries'] : array() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['definition'] ?? '')) === '') {
                return true;
            }
            if ((string)($entry['definition_status'] ?? '') === 'needs_review') {
                return true;
            }
            if ((string)($entry['definition_status'] ?? '') === '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $page
     */
    private function countAbbreviationsNeedingReview(array $page): int
    {
        $count = 0;
        foreach (is_array($page['entries'] ?? null) ? $page['entries'] : array() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string)($entry['definition_status'] ?? '') === 'needs_review'
                || trim((string)($entry['definition'] ?? '')) === '') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param list<string> $onlyAbbreviations Only return expansions for these abbreviations (never invent new rows).
     * @return array<string,array{definition:string,status:string}>
     */
    private function scanManualForAbbreviationExpansions(int $versionId, array $onlyAbbreviations = array()): array
    {
        $allowed = array();
        foreach ($onlyAbbreviations as $abbr) {
            $abbr = strtoupper(trim((string)$abbr));
            if ($abbr !== '') {
                $allowed[$abbr] = true;
            }
        }
        if ($allowed === array()) {
            return array();
        }

        $found = array();
        foreach ($this->loadAbbreviationsFromCanonical($versionId) as $entry) {
            $abbr = strtoupper(trim((string)($entry['abbreviation'] ?? '')));
            $definition = trim((string)($entry['definition'] ?? ''));
            if ($abbr === '' || $definition === '' || !isset($allowed[$abbr])) {
                continue;
            }
            $found[$abbr] = array(
                'definition' => $definition,
                'status' => 'confirmed',
            );
        }

        $text = $this->collectFullManualPlainText($versionId, 500000);
        if ($text === '') {
            return $found;
        }

        if (preg_match_all(
            '/\b([A-Z][A-Z0-9\/\.]{1,11})\s*[–\-]\s*([A-Za-z][A-Za-z0-9\s,\-\/\(\)]{2,120})/u',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $abbr = strtoupper(trim($match[1]));
                if (!isset($allowed[$abbr])) {
                    continue;
                }
                $definition = $this->cleanExpansionPhrase((string)$match[2]);
                if (!$this->isPlausibleAbbreviation($abbr) || !$this->looksLikeDefinitionPhrase($definition)) {
                    continue;
                }
                if (!isset($found[$abbr])) {
                    $found[$abbr] = array(
                        'definition' => $definition,
                        'status' => 'confirmed',
                    );
                }
            }
        }

        if (preg_match_all(
            '/\b([A-Z][A-Z0-9\/\.]{1,11})\s*\(([A-Za-z][^)]{3,120})\)/u',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $abbr = strtoupper(trim($match[1]));
                if (!isset($allowed[$abbr])) {
                    continue;
                }
                $definition = $this->cleanExpansionPhrase((string)$match[2]);
                if (!$this->isPlausibleAbbreviation($abbr) || !$this->looksLikeDefinitionPhrase($definition)) {
                    continue;
                }
                if (!isset($found[$abbr])) {
                    $found[$abbr] = array(
                        'definition' => $definition,
                        'status' => 'confirmed',
                    );
                }
            }
        }

        if (preg_match_all(
            '/\b([A-Za-z][A-Za-z\s\-]{3,80})\s*\(([A-Z][A-Z0-9\/\.]{1,11})\)/u',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $abbr = strtoupper(trim($match[2]));
                if (!isset($allowed[$abbr])) {
                    continue;
                }
                $definition = $this->cleanExpansionPhrase((string)$match[1]);
                if (!$this->isPlausibleAbbreviation($abbr) || !$this->looksLikeDefinitionPhrase($definition)) {
                    continue;
                }
                if (!isset($found[$abbr])) {
                    $found[$abbr] = array(
                        'definition' => $definition,
                        'status' => 'confirmed',
                    );
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string,array{abbreviation:string,definition:string,definition_status:string}> $byAbbr
     * @return array<string,array{abbreviation:string,definition:string,definition_status:string}>
     */
    private function fillAbbreviationDefinitionsWithAi(int $versionId, array $byAbbr): array
    {
        $missing = array();
        foreach ($byAbbr as $abbr => $entry) {
            if (trim((string)($entry['definition'] ?? '')) === '') {
                $missing[] = $abbr;
            }
        }
        if ($missing === array()) {
            return $byAbbr;
        }

        $manualText = $this->collectFullManualPlainText($versionId, 60000);
        if ($manualText === '') {
            return $byAbbr;
        }

        require_once __DIR__ . '/../openai.php';

        $chunks = array_chunk($missing, 25);
        foreach ($chunks as $batch) {
            try {
                $resp = cw_openai_responses(array(
                    'model' => cw_openai_model(),
                    'input' => array(
                        array(
                            'role' => 'system',
                            'content' => array(array(
                                'type' => 'input_text',
                                'text' => 'You expand aviation manual abbreviations. Return ONLY valid JSON: '
                                    . '{"entries":[{"abbreviation":"AMC","definition":"Acceptable Means of Compliance","confidence":"high"}]}. '
                                    . 'Use confidence "high" only when the manual clearly supports the expansion; otherwise "low". '
                                    . 'Keep definitions concise and operational. Use title case. '
                                    . 'Do not include airport ICAO codes, aircraft type designators, or regulation references.',
                            )),
                        ),
                        array(
                            'role' => 'user',
                            'content' => array(array(
                                'type' => 'input_text',
                                'text' => 'Abbreviations needing expansions:' . "\n"
                                    . json_encode($batch, JSON_UNESCAPED_UNICODE)
                                    . "\n\nManual content:\n" . $manualText,
                            )),
                        ),
                    ),
                ), 120);

                $text = trim((string)($resp['output_text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
                    $text = $m[0];
                }
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $rows = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : array();
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $abbr = strtoupper(trim((string)($row['abbreviation'] ?? '')));
                    if ($abbr === '' || !isset($byAbbr[$abbr])) {
                        continue;
                    }
                    if (trim((string)($byAbbr[$abbr]['definition'] ?? '')) !== '') {
                        continue;
                    }
                    $definition = trim((string)($row['definition'] ?? ''));
                    if ($definition === '' || !$this->looksLikeDefinitionPhrase($definition)) {
                        continue;
                    }
                    $confidence = strtolower(trim((string)($row['confidence'] ?? 'high')));
                    $byAbbr[$abbr]['definition'] = $definition;
                    $byAbbr[$abbr]['definition_status'] = $confidence === 'high' ? 'ai_suggested' : 'needs_review';
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $byAbbr;
    }

    /**
     * Discover abbreviations used in the manual beyond the canonical 0.5 index.
     *
     * @return array<string,array{definition:string}>
     */
    private function discoverAbbreviationsFromManual(int $versionId): array
    {
        $text = $this->collectFullManualPlainText($versionId, 500000);
        if ($text === '') {
            return array();
        }

        $found = array();
        foreach ($this->extractStrictParentheticalExpansions($text) as $abbr => $definition) {
            $found[$abbr] = array('definition' => $definition);
        }

        $counts = array();
        if (preg_match_all('/\b([A-Z][A-Z0-9\/\.]{1,11})\b/u', $text, $matches)) {
            foreach ($matches[1] as $rawToken) {
                $token = $this->normalizeAbbreviationToken($rawToken);
                if ($token === '') {
                    continue;
                }
                $counts[$token] = ($counts[$token] ?? 0) + 1;
            }
        }

        foreach ($counts as $token => $count) {
            if ($count < self::ABBREVIATION_MIN_OCCURRENCES) {
                continue;
            }
            if (!$this->isAviationAbbreviationCandidate($token)) {
                continue;
            }
            if (!isset($found[$token])) {
                $found[$token] = array('definition' => '');
            }
        }

        return $found;
    }

    /**
     * @return array<string,string> abbreviation => definition
     */
    private function extractStrictParentheticalExpansions(string $text): array
    {
        $found = array();
        if (!preg_match_all(
            '/\b([A-Z][A-Za-z]+(?:\s+[A-Za-z]+){1,8})\s*\(([A-Z][A-Z0-9\/\.]{1,11})\)/u',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            return $found;
        }

        foreach ($matches as $match) {
            $definition = $this->cleanExpansionPhrase((string)$match[1]);
            $abbr = $this->normalizeAbbreviationToken((string)$match[2]);
            if ($abbr === '' || !$this->isAviationAbbreviationCandidate($abbr)) {
                continue;
            }
            if (!$this->looksLikeDefinitionPhrase($definition)) {
                continue;
            }
            if (!isset($found[$abbr])) {
                $found[$abbr] = $definition;
            }
        }

        return $found;
    }

    private function normalizeAbbreviationToken(string $token): string
    {
        $token = strtoupper(trim($token));
        $token = rtrim($token, '.,;:');
        if ($token === '' || strlen($token) > 12) {
            return '';
        }
        if (str_contains($token, '/')) {
            $parts = explode('/', $token);
            $token = trim($parts[0]);
        }
        return $token;
    }

    private function isAviationAbbreviationCandidate(string $token): bool
    {
        $token = strtoupper(trim($token));
        if ($token === '' || !$this->isPlausibleAbbreviation($token)) {
            return false;
        }
        if (strlen($token) <= 2) {
            return false;
        }
        if (preg_match('/[\.]/', $token)) {
            return false;
        }
        if (preg_match('/\d{2,}/', $token)) {
            return false;
        }
        if (preg_match('/^(DA|C|R|G|B)[0-9]/', $token)) {
            return false;
        }
        if (preg_match('/^G[0-9]{3,}/', $token)) {
            return false;
        }
        if (preg_match('/^K[A-Z0-9]{3}$/', $token)) {
            return false;
        }
        if (preg_match('/^EB[A-Z]{2}$/', $token)) {
            return false;
        }
        if (preg_match('/^L[0-9]{2}$/', $token)) {
            return false;
        }
        if (preg_match('/^AMC[0-9]+$/', $token)) {
            return false;
        }
        if (preg_match('/^(NCO|FCL|IDE|ORA|SERA|CS|GM|AMC|CAT|GEN)\./', $token)) {
            return false;
        }
        if (preg_match('/^VOR[A-Z]{2,}$/', $token)) {
            return false;
        }
        if (preg_match('/^NDB[A-Z]{2,}$/', $token) && $token !== 'NDB') {
            return false;
        }
        if (preg_match('/^NOTAMS?$/', $token)) {
            return false;
        }

        return true;
    }

    private function collectFullManualPlainText(int $versionId, int $maxChars = 500000): string
    {
        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT body_text
                FROM ipca_canonical_excerpts
                WHERE source_set_id = :source_set_id
                ORDER BY id
            ");
            $stmt->execute(array(':source_set_id' => $sourceSetId));
            $parts = array();
            $length = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $text = trim((string)($row['body_text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $parts[] = $text;
                $length += strlen($text);
                if ($maxChars > 0 && $length >= $maxChars) {
                    break;
                }
            }
            $combined = trim(implode("\n\n", $parts));
            if ($combined !== '') {
                if ($maxChars > 0 && strlen($combined) > $maxChars) {
                    $combined = substr($combined, 0, $maxChars);
                }
                return $combined;
            }
        }

        return $this->collectManualPlainText($versionId, $maxChars > 0 ? $maxChars : 500000);
    }

    private function cleanExpansionPhrase(string $phrase): string
    {
        $phrase = trim($phrase);
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? $phrase;
        return trim($phrase, " \t\n\r\0\x0B.,;");
    }

    private function looksLikeDefinitionPhrase(string $phrase): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '' || strlen($phrase) < 4 || strlen($phrase) > 90) {
            return false;
        }
        if (!preg_match('/^[A-Z]/', $phrase) || !preg_match('/[a-z]/', $phrase)) {
            return false;
        }
        if (preg_match('/^(For|The aircraft shall|The decision|The minimum|The obstacle|Reference is made)\b/i', $phrase)) {
            return false;
        }
        if (preg_match('/^[A-Z0-9\/\.\-\s]{2,10}$/', $phrase)) {
            return false;
        }

        $lower = strtolower($phrase);
        $rejectFragments = array(
            ' shall ', ' may ', ' must ', ' will ', ' should ', ' the ', ' and the ',
            ' not exceed', ' not installed', ' only commence', ' under visual',
            ' approach and', ' repair station', ' deputy head', ' head of training',
            ' head of training', ' deputy head', ' one attitude', ' and dme',
            ' manager are described', ' california usa', ' aerodrome operating minima',
            ' afis only', ' portable electronic device a portable', 'function display',
        );
        foreach ($rejectFragments as $fragment) {
            if (str_contains($lower, $fragment)) {
                return false;
            }
        }

        $words = preg_split('/\s+/u', $phrase) ?: array();
        if (count($words) < 2 && !preg_match('/\-/u', $phrase)) {
            if (!preg_match('/^[A-Z][a-z]{3,}$/u', $phrase)) {
                return false;
            }
        }
        if (preg_match('/[\(\)]/u', $phrase) && !preg_match('/\([^)]{2,80}\)$/u', $phrase)) {
            return false;
        }
        if (preg_match('/\b(and|or|the|a|an|in|on|at|to|of|for|with|under|may|shall|only)$/iu', $phrase)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{section_id:int,entries_count:int,source:string}
     */
    public function regenerateAbbreviationsIndex(int $versionId, ?int $actorUserId = null): array
    {
        return $this->importAbbreviationsFromCanonical($versionId, $actorUserId);
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
     * @param array<string,mixed> $page
     */
    private function abbreviationsListNeedsCanonicalSync(array $page): bool
    {
        $entries = is_array($page['entries'] ?? null) ? $page['entries'] : array();
        if ($entries === array()) {
            return true;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $abbr = strtoupper(trim((string)($entry['abbreviation'] ?? '')));
            if (!$this->isPlausibleAbbreviation($abbr)) {
                return true;
            }
            $def = trim((string)($entry['definition'] ?? ''));
            if ($def !== '' && !$this->looksLikeDefinitionPhrase($def)) {
                return true;
            }
        }

        return trim((string)($page['synced_from'] ?? '')) !== 'canonical';
    }

    /**
     * @return list<array{abbreviation:string,definition:string}>
     */
    private function loadAbbreviationsFromCanonical(int $versionId): array
    {
        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId <= 0) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT body_text
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND section_ref IN ('0.5', '0.5.0')
            ORDER BY CASE section_ref WHEN '0.5' THEN 0 ELSE 1 END, id
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $body = trim((string)$stmt->fetchColumn());
        if ($body === '') {
            $fallback = $this->pdo->prepare("
                SELECT body_text
                FROM ipca_canonical_excerpts
                WHERE source_set_id = :source_set_id
                  AND (
                    UPPER(title) LIKE '%ABBREVIATION%'
                    OR UPPER(body_text) LIKE '%INDEX OF ABBREVIATIONS%'
                  )
                ORDER BY id
                LIMIT 1
            ");
            $fallback->execute(array(':source_set_id' => $sourceSetId));
            $body = trim((string)$fallback->fetchColumn());
        }

        return $this->parseAbbreviationsBodyText($body);
    }

    /**
     * @return list<array{abbreviation:string,definition:string}>
     */
    private function parseAbbreviationsBodyText(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return array();
        }

        $entries = array();
        $lines = preg_split('/\r?\n/', $body) ?: array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^0\.5\b/i', $line) || preg_match('/^INDEX OF ABBREVIATIONS$/i', $line)) {
                continue;
            }
            if (!preg_match('/^([A-Z0-9][A-Z0-9\/\.\-]{0,11})\s*[–\-]\s*(.+)$/u', $line, $match)) {
                continue;
            }
            $abbr = strtoupper(trim($match[1]));
            $definition = trim($match[2]);
            if ($abbr === '' || $definition === '') {
                continue;
            }
            $entries[] = array(
                'abbreviation' => $abbr,
                'definition' => $definition,
            );
        }

        return $entries;
    }

    private function isPlausibleAbbreviation(string $token): bool
    {
        $token = strtoupper(trim($token));
        if ($token === '' || strlen($token) > 12) {
            return false;
        }

        $stopwords = array_merge(self::ABBREVIATION_FALSE_POSITIVES, array(
            'THE', 'AND', 'FOR', 'ARE', 'BUT', 'NOT', 'YOU', 'ALL', 'CAN', 'HER', 'WAS', 'ONE',
            'OUR', 'OUT', 'DAY', 'HAD', 'HAS', 'HIS', 'HOW', 'ITS', 'MAY', 'NEW', 'NOW', 'OLD',
            'SEE', 'WAY', 'WHO', 'BOY', 'DID', 'GET', 'LET', 'PUT', 'SAY', 'SHE', 'TOO', 'USE',
            'PART', 'PAGE', 'DATE', 'REV', 'OM', 'OMM', 'PDF', 'HTML', 'HTTP', 'HTTPS', 'WWW',
        ));
        if (in_array($token, $stopwords, true)) {
            return false;
        }

        if (preg_match('/^VOR[A-Z]{2,8}$/', $token)) {
            return false;
        }
        if (preg_match('/^NDB[A-Z]{2,8}$/', $token) && $token !== 'NDB') {
            return false;
        }
        if (preg_match('/^[A-Z]{6,}$/', $token) && !preg_match('/[AEIOUY]/', $token)) {
            return false;
        }
        if (preg_match('/^[A-Z]{7,}$/', $token) && !preg_match('/[0-9\/\.]/', $token)) {
            return false;
        }

        if (strlen($token) >= 5 && !preg_match('/[0-9\/\.]/', $token) && preg_match('/^[A-Z]+$/', $token)) {
            if (preg_match('/[AEIOUY]/', $token) && in_array($token, self::ABBREVIATION_FALSE_POSITIVES, true)) {
                return false;
            }
        }

        return true;
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
                'definition_status' => $this->normalizeAbbreviationDefinitionStatus(
                    (string)($row['definition_status'] ?? ''),
                    trim((string)($row['definition'] ?? ''))
                ),
            );
        }
        ksort($entries, SORT_STRING);
        $out = array(
            'entries' => array_values($entries),
            'empty_rows' => max(0, min(20, (int)($raw['empty_rows'] ?? 10))),
        );
        $syncedFrom = trim((string)($raw['synced_from'] ?? ''));
        if ($syncedFrom !== '') {
            $out['synced_from'] = $this->truncate($syncedFrom, 32);
        }
        $needsReview = $this->countAbbreviationsNeedingReview($out);
        if ($needsReview > 0) {
            $out['needs_review_count'] = $needsReview;
        }
        return $out;
    }

    private function normalizeAbbreviationDefinitionStatus(string $status, string $definition): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, array('confirmed', 'ai_suggested', 'needs_review'), true)) {
            $status = $definition !== '' ? 'confirmed' : 'needs_review';
        }
        if ($definition === '' && $status !== 'needs_review') {
            return 'needs_review';
        }
        return $status;
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

    /**
     * @return array{section_id:int,entries_count:int,source:string}
     */
    public function importDefinitionsFromCanonical(int $versionId, ?int $actorUserId = null): array
    {
        $existing = $this->resolveDefinitionsFromVersion($this->requireVersion($versionId));
        $canonicalEntries = $this->loadDefinitionsFromCanonical($versionId);
        if ($canonicalEntries === array()) {
            throw new RuntimeException('No definitions found in the linked manual source set.');
        }

        $pageData = $this->normalizeDefinitionsPage(array(
            'entries' => $canonicalEntries,
            'empty_rows' => (int)($existing['empty_rows'] ?? 12),
        ));
        $this->saveStructuredPage($versionId, 'definitions', $pageData);

        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'definitions'),
            'entries_count' => count($pageData['entries']),
            'source' => 'canonical',
        );
    }

    /**
     * @return array{section_id:int,entries_count:int,source?:string}
     */
    public function regenerateDefinitionsFromManual(int $versionId, ?int $actorUserId = null): array
    {
        $canonicalEntries = $this->loadDefinitionsFromCanonical($versionId);
        if ($canonicalEntries !== array()) {
            return $this->importDefinitionsFromCanonical($versionId, $actorUserId);
        }

        require_once __DIR__ . '/../openai.php';

        $existing = $this->resolveDefinitionsFromVersion($this->requireVersion($versionId));
        $definitionsByTerm = array();
        foreach ($existing['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $term = trim((string)($entry['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $definitionsByTerm[strtolower($term)] = trim((string)($entry['definition'] ?? ''));
        }

        $manualText = $this->collectManualPlainText($versionId, 14000);
        if ($manualText === '') {
            throw new RuntimeException('No manual content found to suggest definitions from.');
        }

        $resp = cw_openai_responses(array(
            'model' => cw_openai_model(),
            'input' => array(
                array(
                    'role' => 'system',
                    'content' => array(array(
                        'type' => 'input_text',
                        'text' => 'You extract aviation manual definitions. Return ONLY valid JSON: {"entries":[{"term":"...","definition":"..."}]}. '
                            . 'Include terms that are defined or clearly used with specific meaning in the manual. '
                            . 'Keep definitions concise and operational. Preserve existing definitions when the term matches.',
                    )),
                ),
                array(
                    'role' => 'user',
                    'content' => array(array(
                        'type' => 'input_text',
                        'text' => "Existing definitions (preserve when still valid):\n"
                            . json_encode($existing['entries'], JSON_UNESCAPED_UNICODE)
                            . "\n\nManual content:\n" . $manualText,
                    )),
                ),
            ),
        ), 120);

        $text = trim((string)($resp['output_text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('AI returned empty definitions.');
        }
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI definitions response was not valid JSON.');
        }
        $rawEntries = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : array();
        $merged = array();
        foreach ($rawEntries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term = trim((string)($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $key = strtolower($term);
            $definition = trim((string)($row['definition'] ?? ''));
            if ($definition === '' && isset($definitionsByTerm[$key])) {
                $definition = $definitionsByTerm[$key];
            }
            $merged[$key] = array('term' => $term, 'definition' => $definition);
        }
        foreach ($definitionsByTerm as $key => $definition) {
            if (!isset($merged[$key]) && $definition !== '') {
                $merged[$key] = array(
                    'term' => ucfirst($key),
                    'definition' => $definition,
                );
            }
        }

        $pageData = $this->normalizeDefinitionsPage(array(
            'entries' => array_values($merged),
            'empty_rows' => (int)($existing['empty_rows'] ?? 12),
        ));
        $this->saveStructuredPage($versionId, 'definitions', $pageData);

        return array(
            'section_id' => $this->sectionIdByKey($versionId, 'definitions'),
            'entries_count' => count($pageData['entries']),
            'source' => 'ai',
        );
    }

    /**
     * @return list<array{term:string,definition:string}>
     */
    private function loadDefinitionsFromCanonical(int $versionId): array
    {
        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId <= 0) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT body_text
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND section_ref IN ('0.6', '0.6.0')
            ORDER BY CASE section_ref WHEN '0.6' THEN 0 ELSE 1 END, id
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $body = trim((string)$stmt->fetchColumn());
        if ($body === '') {
            $fallback = $this->pdo->prepare("
                SELECT body_text
                FROM ipca_canonical_excerpts
                WHERE source_set_id = :source_set_id
                  AND (
                    UPPER(title) LIKE '%DEFINITION%'
                    OR UPPER(body_text) LIKE '%DEFINITIONS AND TERMS%'
                  )
                ORDER BY id
                LIMIT 1
            ");
            $fallback->execute(array(':source_set_id' => $sourceSetId));
            $body = trim((string)$fallback->fetchColumn());
        }

        return $this->parseDefinitionsBodyText($body);
    }

    /**
     * @return list<array{term:string,definition:string}>
     */
    private function parseDefinitionsBodyText(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return array();
        }

        $entries = array();
        $lines = preg_split('/\R/u', $body) ?: array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^0\.6\b/i', $line) || preg_match('/^DEFINITIONS AND TERMS$/i', $line)) {
                continue;
            }
            if (!preg_match('/^([^:]+):\s*(.+)$/u', $line, $m)) {
                continue;
            }
            $term = trim($m[1]);
            $definition = trim($m[2]);
            if ($term === '' || $definition === '') {
                continue;
            }
            $entries[] = array(
                'term' => $term,
                'definition' => $definition,
            );
        }

        return $entries;
    }

    private function resolveManualSourceSetId(int $versionId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT ss.id
            FROM ipca_publishing_book_version_source_sets vss
            INNER JOIN ipca_canonical_source_sets ss ON ss.id = vss.source_set_id
            WHERE vss.book_version_id = :version_id
              AND vss.selection_role = 'manual_source'
            ORDER BY vss.id
            LIMIT 1
        ");
        $stmt->execute(array(':version_id' => $versionId));
        return (int)$stmt->fetchColumn();
    }

    private function collectManualPlainText(int $versionId, int $maxChars = 14000): string
    {
        $stmt = $this->pdo->prepare("
            SELECT b.payload_json, b.block_type, s.section_key
            FROM ipca_publishing_book_blocks b
            INNER JOIN ipca_publishing_book_sections s ON s.id = b.section_id
            WHERE b.book_version_id = :version_id
              AND s.section_key NOT IN ('cover','toc','highlights','abbreviations','definitions','lep')
            ORDER BY s.sort_order, b.sort_order, b.id
        ");
        $stmt->execute(array(':version_id' => $versionId));
        $parts = array();
        $length = 0;
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
            $parts[] = $text;
            $length += strlen($text);
            if ($length >= $maxChars) {
                break;
            }
        }
        $combined = trim(implode("\n\n", $parts));
        if (strlen($combined) > $maxChars) {
            $combined = substr($combined, 0, $maxChars);
        }
        return $combined;
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
        $out = array(
            'rows' => $rows,
            'empty_rows' => max(0, min(30, (int)($raw['empty_rows'] ?? $defaults['empty_rows']))),
        );
        $syncedFrom = trim((string)($raw['synced_from'] ?? ''));
        if ($syncedFrom !== '') {
            $out['synced_from'] = $this->truncate($syncedFrom, 32);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $page
     */
    private function amendmentListNeedsCanonicalSync(array $page): bool
    {
        if (trim((string)($page['synced_from'] ?? '')) === 'canonical') {
            return false;
        }
        $rows = is_array($page['rows'] ?? null) ? $page['rows'] : array();
        if ($rows === array()) {
            return true;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['revision_nr'] ?? '') === 'Original' && ($row['revision_date'] ?? '') === '10/02/08') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array<string,string>>
     */
    private function loadAmendmentListFromCanonical(int $versionId): array
    {
        $sourceSetId = $this->resolveManualSourceSetId($versionId);
        if ($sourceSetId <= 0) {
            return array();
        }

        $stmt = $this->pdo->prepare("
            SELECT body_text
            FROM ipca_canonical_excerpts
            WHERE source_set_id = :source_set_id
              AND section_ref IN ('0.3', '0.3.0')
            ORDER BY CASE section_ref WHEN '0.3' THEN 0 ELSE 1 END, id
            LIMIT 1
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));
        $body = trim((string)$stmt->fetchColumn());
        if ($body === '') {
            $fallback = $this->pdo->prepare("
                SELECT body_text
                FROM ipca_canonical_excerpts
                WHERE source_set_id = :source_set_id
                  AND (
                    UPPER(title) LIKE '%AMENDMENT%'
                    OR UPPER(body_text) LIKE '%AMENDMENT LIST%'
                  )
                ORDER BY id
                LIMIT 1
            ");
            $fallback->execute(array(':source_set_id' => $sourceSetId));
            $body = trim((string)$fallback->fetchColumn());
        }

        return $this->parseAmendmentListBodyText($body);
    }

    /**
     * @return list<array<string,string>>
     */
    private function loadAmendmentListFromPriorVersion(int $versionId): array
    {
        $version = $this->requireVersion($versionId);
        $stmt = $this->pdo->prepare("
            SELECT metadata_json
            FROM ipca_publishing_book_versions
            WHERE book_id = :book_id
              AND id < :version_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(array(
            ':book_id' => (int)$version['book_id'],
            ':version_id' => $versionId,
        ));
        $rawMeta = $stmt->fetchColumn();
        if ($rawMeta === false || $rawMeta === null) {
            return array();
        }
        $meta = json_decode((string)$rawMeta, true);
        if (!is_array($meta)) {
            return array();
        }
        $rows = is_array($meta['part0_pages']['amendment_list']['rows'] ?? null)
            ? $meta['part0_pages']['amendment_list']['rows']
            : array();
        $normalized = $this->normalizeAmendmentListPage(array('rows' => $rows));
        return is_array($normalized['rows'] ?? null) ? $normalized['rows'] : array();
    }

    /**
     * @return list<array<string,string>>
     */
    private function parseAmendmentListBodyText(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return array();
        }

        $body = preg_replace('/^0\.3[^\r\n]*\r?\n/iu', '', $body) ?? $body;
        $body = preg_replace('/^RETAIN SHEET[^\r\n]*\r?\n/iu', '', $body) ?? $body;
        $chunks = preg_split('/\R(?=Revision:\s*)/iu', trim($body)) ?: array();
        $rows = array();

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (!preg_match('/^Revision:\s*(.+)$/imu', $chunk, $revisionMatch)) {
                continue;
            }
            $row = array(
                'revision_nr' => trim($revisionMatch[1]),
                'reason' => $this->extractAmendmentField($chunk, 'Reason'),
                'revision_date' => $this->extractAmendmentField($chunk, 'Revision Date'),
                'effective_date' => $this->extractAmendmentField($chunk, 'Effective Date'),
                'date_incorp' => $this->extractAmendmentField($chunk, 'Date Incorporated'),
                'incorp_by' => $this->extractAmendmentField($chunk, 'Incorporated By'),
            );
            if ($row['revision_nr'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function extractAmendmentField(string $chunk, string $label): string
    {
        $pattern = '/^' . preg_quote($label, '/') . ':\s*(.+)$/imu';
        if (!preg_match($pattern, $chunk, $match)) {
            return '';
        }
        return trim($match[1]);
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function appendDraftRevisionRowIfNeeded(array $page, array $version): array
    {
        $versionRev = $this->versionRevisionNumber($version);
        if ($versionRev <= 0) {
            $page['draft_revision_appended'] = false;
            return $page;
        }

        $rows = is_array($page['rows'] ?? null) ? $page['rows'] : array();
        $lastRev = $this->lastRevisionNumberInAmendmentList($rows);
        if ($versionRev <= $lastRev) {
            $page['draft_revision_appended'] = false;
            return $page;
        }

        $lastIncorpBy = '';
        if ($rows !== array()) {
            $last = $rows[count($rows) - 1];
            if (is_array($last)) {
                $lastIncorpBy = trim((string)($last['incorp_by'] ?? ''));
            }
        }

        $rows[] = array(
            'revision_nr' => 'Rev ' . $versionRev,
            'reason' => '',
            'revision_date' => date('d-m-Y'),
            'effective_date' => '',
            'date_incorp' => '',
            'incorp_by' => $lastIncorpBy,
        );
        $page['rows'] = $rows;
        $page['draft_revision_appended'] = true;
        return $page;
    }

    /**
     * @param array<string,mixed> $version
     */
    private function versionRevisionNumber(array $version): int
    {
        $label = trim((string)($version['version_label'] ?? ''));
        if (preg_match('/^(\d+)/', $label, $match)) {
            return (int)$match[1];
        }
        return 0;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function lastRevisionNumberInAmendmentList(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $nr = trim((string)($row['revision_nr'] ?? ''));
            if (preg_match('/^rev\s*(\d+)/i', $nr, $match)) {
                $max = max($max, (int)$match[1]);
            }
        }
        return $max;
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
