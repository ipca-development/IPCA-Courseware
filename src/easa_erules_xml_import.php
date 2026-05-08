<?php
declare(strict_types=1);

require_once __DIR__ . '/easa_erules_storage.php';

/** Max stored outer XML fragment when we only store a synthetic opening tag (streaming mode). */
const EASA_ERULES_XML_FRAGMENT_MAX = 65535;

/** MySQL MEDIUMTEXT upper bound minus headroom for UPDATE safety. */
const EASA_ERULES_XML_FRAGMENT_STORE_MAX = 16777215;

const EASA_ERULES_PROGRESS_INTERVAL = 80;

/**
 * @return list<string>
 */
function easa_erules_row_candidate_local_names(): array
{
    return ['document', 'frontmatter', 'toc', 'heading', 'topic', 'backmatter'];
}

function easa_erules_normalize_ws(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;

    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * @return array<string, string>
 */
function easa_erules_dom_element_attributes(DOMElement $el): array
{
    $out = [];
    if ($el->hasAttributes()) {
        foreach ($el->attributes ?? [] as $a) {
            if ($a instanceof DOMAttr) {
                $out[$a->name] = $a->value;
            }
        }
    }

    return $out;
}

/**
 * @return array<string, string>
 */
function easa_erules_reader_collect_attributes(XMLReader $r): array
{
    $attrs = [];
    if ($r->attributeCount > 0) {
        while ($r->moveToNextAttribute()) {
            $attrs[$r->localName] = $r->value;
        }
        $r->moveToElement();
    }

    return $attrs;
}

function easa_erules_pick_title_attrs_only(array $attrs): string
{
    foreach (['title', 'source-title', 'source_title', 'Title', 'sourceTitle'] as $k) {
        if (isset($attrs[$k]) && trim((string) $attrs[$k]) !== '') {
            return mb_substr(trim((string) $attrs[$k]), 0, 2000);
        }
    }

    return '';
}

function easa_erules_pick_title(array $attrs, string $plain, ?DOMElement $el): string
{
    $t = easa_erules_pick_title_attrs_only($attrs);
    if ($t !== '') {
        return $t;
    }
    if ($el instanceof DOMElement) {
        $hn = $el->getElementsByTagNameNS('*', 'title')->item(0);
        if ($hn instanceof DOMElement) {
            $t2 = easa_erules_normalize_ws($hn->textContent);

            return mb_substr($t2, 0, 2000);
        }
    }
    $line = explode("\n", $plain, 2)[0];

    return mb_substr(easa_erules_normalize_ws($line), 0, 400);
}

function easa_erules_element_is_candidate_attrs(string $localName, array $attrs): bool
{
    $l = strtolower($localName);
    if (in_array($l, easa_erules_row_candidate_local_names(), true)) {
        return true;
    }

    return isset($attrs['ERulesId']) || isset($attrs['guid']);
}

function easa_erules_element_is_candidate(DOMElement $el): bool
{
    return easa_erules_element_is_candidate_attrs($el->localName, easa_erules_dom_element_attributes($el));
}

function easa_erules_batch_progress_available(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW COLUMNS FROM easa_erules_import_batches LIKE 'parse_phase'");

        return $st instanceof PDOStatement && $st->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

function easa_erules_staging_has_canonical_column(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW COLUMNS FROM easa_erules_import_nodes_staging LIKE 'canonical_text'");

        return $st instanceof PDOStatement && $st->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

function easa_erules_staging_has_structured_blocks_column(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW COLUMNS FROM easa_erules_import_nodes_staging LIKE 'structured_blocks_json'");

        return $st instanceof PDOStatement && $st->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

/** Stored JSON ceiling (UTF-8 bytes); keep below MEDIUMTEXT overhead. */
const EASA_ERULES_STRUCTURED_BLOCKS_JSON_BYTES_MAX = 400000;

/**
 * Normalised rule body for hashing / compare (single-line whitespace collapse).
 */
function easa_erules_body_canonical_for_hash(string $s): string
{
    if ($s === '') {
        return '';
    }
    $s = str_replace(["\xc2\xa0"], ' ', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * Stable content hash: canonical body + ids (align with enrich pass).
 */
function easa_erules_staging_content_hash(string $canonical, ?string $erulesId, string $nodeType): string
{
    return hash(
        'sha256',
        $canonical . "\n|\n" . ($erulesId ?? '') . "\n|\n" . $nodeType
    );
}

/**
 * Case-insensitive attribute getter by local-name (handles ERulesId variants).
 *
 * @param array<string,string> $attrs
 */
function easa_erules_attr_ci(array $attrs, string $name): ?string
{
    foreach ($attrs as $k => $v) {
        if (strcasecmp((string) $k, $name) === 0) {
            return (string) $v;
        }
    }

    return null;
}

/**
 * Normalised ERulesId for tolerant matching across punctuation/case variants.
 */
function easa_erules_id_match_key(string $id): string
{
    $id = strtoupper(trim($id));
    if ($id === '') {
        return '';
    }
    $id = preg_replace('/[^A-Z0-9]+/', '', $id) ?? $id;

    return $id;
}

/**
 * Extract ordered descendant text from a full element outer-XML fragment (Word OOXML-safe).
 *
 * @return array{plain: string, canonical: string}
 */
function easa_erules_plain_canonical_from_outer_xml(string $outerXml): array
{
    $outerXml = trim($outerXml);
    if ($outerXml === '') {
        return ['plain' => '', 'canonical' => ''];
    }
    if (strlen($outerXml) > EASA_ERULES_XML_FRAGMENT_STORE_MAX) {
        $outerXml = substr($outerXml, 0, EASA_ERULES_XML_FRAGMENT_STORE_MAX) . "\n<!-- … truncated -->";
    }

    $dom = new DOMDocument();
    $ok = @$dom->loadXML($outerXml, LIBXML_NONET | LIBXML_PARSEHUGE);
    if (!$ok) {
        $noTags = $outerXml;
        $noTags = str_replace(['<w:tab/>', '<w:tab />', '<w:br/>', '<w:br />'], ["\t", "\t", "\n", "\n"], $noTags);
        $noTags = strip_tags($noTags);
        $noTags = str_replace(["\xc2\xa0"], ' ', $noTags);
        $noTags = html_entity_decode($noTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = easa_erules_sanitize_rule_body_text($noTags);
        $canonical = easa_erules_body_canonical_for_hash($noTags);

        return ['plain' => $plain, 'canonical' => $canonical];
    }

    $xp = new DOMXPath($dom);
    $buf = '';
    foreach ($xp->query('//text()') ?? [] as $t) {
        if ($t instanceof DOMText) {
            $buf .= $t->data;
        }
    }
    $buf = str_replace(["\xc2\xa0"], ' ', $buf);
    $buf = html_entity_decode($buf, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = easa_erules_sanitize_rule_body_text($buf);
    $canonical = easa_erules_body_canonical_for_hash($buf);

    return ['plain' => $plain, 'canonical' => $canonical];
}

/**
 * Readable body from a stored xml_fragment row (fallback when plain_text was not filled).
 */
function easa_erules_plain_text_from_stored_xml_fragment(string $fragment): string
{
    $fragment = trim($fragment);
    if ($fragment === '') {
        return '';
    }
    $pc = easa_erules_plain_canonical_from_outer_xml($fragment);

    return $pc['plain'];
}

/**
 * Paragraph / table plaintext from OOXML runs inside a paragraph (excluding nested tables).
 *
 * Tables are rendered separately via structured_blocks; runs here skip descendant tbl subtrees.
 */
function easa_erules_word_runs_plain_excluding_tables(DOMElement $pOrRich): string
{
    $out = '';
    $walk = static function (DOMNode $n) use (&$walk, &$out): void {
        if ($n instanceof DOMText) {
            $out .= $n->data;

            return;
        }
        if (!($n instanceof DOMElement)) {
            return;
        }
        $ln = $n->localName;
        $ns = $n->namespaceURI ?? '';
        if ($ln === 'tbl' && easa_erules_is_wordprocessingml_namespace($ns)) {
            return;
        }
        if (easa_erules_is_wordprocessingml_namespace($ns)) {
            if ($ln === 'tab') {
                $out .= "\t";

                return;
            }
            if ($ln === 'br' || $ln === 'cr') {
                $out .= "\n";

                return;
            }
        }
        foreach ($n->childNodes ?? [] as $c) {
            $walk($c);
        }
    };
    foreach ($pOrRich->childNodes ?? [] as $c) {
        $walk($c);
    }

    return str_replace(["\xc2\xa0"], ' ', $out);
}

/**
 * OOXML paragraph style/outline → UI heading level, or null for body text.
 */
function easa_erules_word_paragraph_heading_level(DOMElement $p): ?int
{
    $doc = $p->ownerDocument;
    if ($doc === null) {
        return null;
    }
    $xp = new DOMXPath($doc);
    $outline = $xp->query('.//*[local-name()="pPr"]//*[local-name()="outlineLvl"]/@*[local-name()="val"]', $p)->item(0);
    if ($outline instanceof DOMAttr && is_numeric(trim($outline->value))) {
        // outlineLvl counts from 0; map to semantic heading tiers in the viewer.
        return max(2, min(6, ((int) $outline->value) + 2));
    }
    $pst = $xp->query('.//*[local-name()="pPr"]//*[local-name()="pStyle"]/@*[local-name()="val"]', $p)->item(0);
    if ($pst instanceof DOMAttr) {
        if (preg_match('/heading\s*(\d+)/i', (string) $pst->value, $m)) {
            return max(1, min(6, (int) $m[1]));
        }
        $pv = strtolower((string) $pst->value);
        if ($pv !== ''
            && (str_contains($pv, 'heading') || str_contains($pv, 'title') || str_contains($pv, 'toc'))) {
            return 3;
        }
    }

    return null;
}

/**
 * @return array{text: string, marker?: string}|null
 */
function easa_erules_structured_marker_prefix(string $t): ?array
{
    $t = trim($t);
    if ($t === '') {
        return null;
    }
    // (a), (i), (1)—legal list openers glued to prose.
    if (preg_match('/^\(\s*((?:[ivxlcdm]{1,8}|[a-z]|[1-9]\d{0,2}|0))\s*\)\s*(.*)$/su', $t, $m)) {
        return ['marker' => '(' . trim($m[1]) . ')', 'text' => trim((string) ($m[2] ?? ''))];
    }
    if (preg_match('/^(\d{1,2}\.)\s+(.+)/su', $t, $m)) {
        return ['marker' => $m[1], 'text' => trim((string) ($m[2] ?? ''))];
    }

    return null;
}

/**
 * Rows for structured_blocks_json "table"; each row is a list of cell strings.
 *
 * @return list<list<string>>
 */
function easa_erules_word_tc_own_paragraph(DOMElement $p, DOMElement $tc): bool
{
    for ($n = $p->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
        if ($n->isSameNode($tc)) {
            return true;
        }
        if (($n->localName ?? '') === 'tbl' && easa_erules_is_wordprocessingml_namespace($n->namespaceURI ?? '')) {
            return false;
        }
    }

    return false;
}

function easa_erules_word_tbl_to_cell_rows(DOMElement $tbl): array
{
    $rows = [];
    foreach ($tbl->getElementsByTagNameNS('*', 'tr') as $tr) {
        if (!$tr instanceof DOMElement) {
            continue;
        }
        $nearestTbl = null;
        for ($walk = $tr->parentNode; $walk instanceof DOMElement; $walk = $walk->parentNode) {
            if (($walk->localName ?? '') === 'tbl'
                && easa_erules_is_wordprocessingml_namespace($walk->namespaceURI ?? '')) {
                $nearestTbl = $walk;
                break;
            }
        }
        if ($nearestTbl === null || !$nearestTbl->isSameNode($tbl)) {
            continue;
        }

        /** @var list<string> $cellsOut */
        $cellsOut = [];
        foreach ($tr->childNodes ?? [] as $cellNode) {
            if (!$cellNode instanceof DOMElement || ($cellNode->localName ?? '') !== 'tc') {
                continue;
            }
            $cellParas = [];
            foreach ($cellNode->getElementsByTagNameNS('*', 'p') as $ccp) {
                if (!$ccp instanceof DOMElement || !easa_erules_word_tc_own_paragraph($ccp, $cellNode)) {
                    continue;
                }
                $line = trim(easa_erules_sanitize_rule_body_text(easa_erules_word_runs_plain_excluding_tables($ccp)));
                if ($line !== '') {
                    $cellParas[] = $line;
                }
            }
            if ($cellParas === []) {
                $fallback = trim(easa_erules_sanitize_rule_body_text($cellNode->textContent));
                $cellsOut[] = $fallback;
            } else {
                $cellsOut[] = implode("\n\n", $cellParas);
            }
        }
        if ($cellsOut !== []) {
            $rows[] = $cellsOut;
        }
    }

    return $rows;
}

/**
 * Build ordered display blocks from a regulation node outer-XML fragment (EASA markup + embedded Word OOXML).
 *
 * UI must render ONLY this structure (decoded JSON), never raw fragments.
 *
 * @return non-empty-string JSON array
 */
function easa_erules_structured_blocks_json_from_outer_xml(string $outerXml): string
{
    $outerXml = trim($outerXml);
    if ($outerXml === '') {
        return '[]';
    }

    $pcPeek = easa_erules_plain_canonical_from_outer_xml($outerXml);
    $fallbackPlain = trim((string) $pcPeek['plain']);

    if (strlen($outerXml) > EASA_ERULES_XML_FRAGMENT_STORE_MAX) {
        $outerXml = substr($outerXml, 0, EASA_ERULES_XML_FRAGMENT_STORE_MAX) . "\n<!-- … truncated -->";
    }

    $dom = new DOMDocument();
    $ok = @$dom->loadXML($outerXml, LIBXML_NONET | LIBXML_PARSEHUGE);

    /** @var list<array<string, mixed>> $blocks */
    $blocks = [];

    if ($ok && $dom->documentElement instanceof DOMElement) {
        $root = $dom->documentElement;
        $xp = new DOMXPath($dom);
        $matched = $xp->query(
            './/*[(local-name()="tbl") or (local-name()="p" and not(ancestor::*[local-name()="tbl"]))]',
            $root
        );

        if ($matched !== false && $matched->length > 0) {
            foreach ($matched as $el) {
                if (!$el instanceof DOMElement) {
                    continue;
                }
                $ln = $el->localName;
                if ($ln === 'tbl' && easa_erules_is_wordprocessingml_namespace($el->namespaceURI ?? '')) {
                    $rowGrid = easa_erules_word_tbl_to_cell_rows($el);
                    if ($rowGrid !== []) {
                        $blocks[] = ['type' => 'table', 'rows' => $rowGrid];
                    }
                    continue;
                }
                if ($ln !== 'p' || !easa_erules_is_wordprocessingml_namespace($el->namespaceURI ?? '')) {
                    continue;
                }
                $plain = trim(easa_erules_sanitize_rule_body_text(easa_erules_word_runs_plain_excluding_tables($el)));
                if ($plain === '') {
                    continue;
                }
                $hl = easa_erules_word_paragraph_heading_level($el);
                if ($hl !== null) {
                    $blocks[] = ['type' => 'heading', 'level' => $hl, 'text' => $plain];
                    continue;
                }
                $li = easa_erules_structured_marker_prefix($plain);
                if ($li !== null && $li['text'] !== '') {
                    $blocks[] = ['type' => 'list_item', 'marker' => $li['marker'] ?? '', 'text' => $li['text']];
                    continue;
                }
                $blocks[] = ['type' => 'paragraph', 'text' => $plain];
            }
        } else {
            foreach ($root->childNodes ?? [] as $ch) {
                if (!$ch instanceof DOMElement) {
                    continue;
                }
                $eln = strtolower((string) $ch->localName);
                $inner = trim(easa_erules_sanitize_rule_body_text($ch->textContent));
                if ($inner === '') {
                    continue;
                }
                if (in_array($eln, ['heading', 'articletitle', 'sectiontitle'], true)) {
                    $lvlRaw = isset($ch->attributes) ? ($ch->getAttribute('level') ?: '') : '';
                    $lvl = ctype_digit(trim($lvlRaw)) ? max(1, min(6, (int) trim($lvlRaw))) : 2;
                    $blocks[] = ['type' => 'heading', 'level' => $lvl, 'text' => $inner];
                    continue;
                }
                $blocks[] = ['type' => 'paragraph', 'text' => $inner];
            }
        }
    }

    if ($blocks === [] && $fallbackPlain !== '') {
        $blocks[] = ['type' => 'paragraph', 'text' => $fallbackPlain];
    }

    $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        $blocks = [['type' => 'paragraph', 'text' => mb_substr($fallbackPlain !== '' ? $fallbackPlain : '[encoding error]', 0, 200000)]];
        $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!is_string($json) || $json === '') {
        $json = '[]';
    }
    if (strlen((string) $json) > EASA_ERULES_STRUCTURED_BLOCKS_JSON_BYTES_MAX) {
        $trimmed = [['type' => 'paragraph', 'text' => mb_substr($fallbackPlain !== '' ? $fallbackPlain : (string) $json, 0, 120000)]];

        return json_encode($trimmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return (string) $json;
}

function easa_erules_is_wordprocessingml_namespace(?string $ns): bool
{
    return is_string($ns) && stripos($ns, 'wordprocessingml') !== false;
}

/**
 * OOXML exposes rule bodies under w:sdt, correlated to EASA <topic sdt-id="…"> placeholders.
 *
 * Build map: trimmed id val => [plain, canonical, outer_fragment] (longest wins on duplicate ids).
 *
 * @return array<string, array{plain: string, canonical: string, outer: string}>
 */
function easa_erules_word_sdt_plain_index(string $absoluteXmlPath, int $maxSdtOuterBytes = 4194304): array
{
    if (!is_file($absoluteXmlPath) || !is_readable($absoluteXmlPath)) {
        return [];
    }
    /** @var array<string, array{plain:string,canonical:string,outer:string}> */
    $index = [];

    $reader = new XMLReader();
    if (!$reader->open($absoluteXmlPath, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
        return [];
    }
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if (strtolower($reader->localName) !== 'sdt') {
                continue;
            }
            if (!easa_erules_is_wordprocessingml_namespace($reader->namespaceURI ?? null)) {
                continue;
            }
            $outer = $reader->readOuterXml();
            if (!is_string($outer) || $outer === '') {
                continue;
            }
            if (strlen($outer) > $maxSdtOuterBytes) {
                continue;
            }

            $dom = new DOMDocument();
            if (!@$dom->loadXML($outer, LIBXML_NONET)) {
                continue;
            }
            $xp = new DOMXPath($dom);
            $idElList = $xp->query('//*[local-name()="sdtPr"]//*[local-name()="id"]');
            if ($idElList === false || $idElList->length === 0) {
                continue;
            }

            /** @var DOMElement|false $firstId */
            $firstId = $idElList->item(0);
            if (!$firstId instanceof DOMElement) {
                continue;
            }

            $sdtNumericId = '';
            foreach ($firstId->attributes ?? [] as $a) {
                if (!$a instanceof DOMAttr) {
                    continue;
                }
                $nameLc = strtolower($a->localName ?: $a->name);
                if ($nameLc === 'val' || ($nameLc !== '' && str_ends_with($nameLc, ':val'))) {
                    $sdtNumericId = trim((string) $a->value);
                    break;
                }
            }

            if ($sdtNumericId === '') {
                foreach ($xp->query('//*[local-name()="id"]//@*[local-name()="val"]') ?: [] as $attr) {
                    if (!$attr instanceof DOMAttr) {
                        continue;
                    }
                    $sdtNumericId = trim((string) $attr->value);
                    break;
                }
            }
            if ($sdtNumericId === '') {
                continue;
            }

            $plainBody = '';
            $contentRoot = $xp->query('//*[local-name()="sdtContent"]')->item(0);
            if ($contentRoot instanceof DOMElement) {
                foreach ($xp->query('.//*[local-name()="t"]', $contentRoot) ?: [] as $t) {
                    if (!$t instanceof DOMElement) {
                        continue;
                    }
                    $plainBody .= $t->textContent;
                }
                $plainBody = trim($plainBody !== '' ? easa_erules_sanitize_rule_body_text($plainBody)
                    : easa_erules_sanitize_rule_body_text($contentRoot->textContent ?? ''));
            }
            $canonicalBody = easa_erules_body_canonical_for_hash($plainBody !== '' ? $plainBody : '');

            if ($plainBody === '' && $canonicalBody === '') {
                continue;
            }

            $prev = $index[$sdtNumericId]['plain'] ?? '';
            if (strlen(trim($plainBody)) < strlen(trim($prev))) {
                continue;
            }
            $index[$sdtNumericId] = [
                'plain' => $plainBody,
                'canonical' => $canonicalBody,
                'outer' => strlen($outer) <= EASA_ERULES_XML_FRAGMENT_STORE_MAX ? $outer : substr($outer, 0, EASA_ERULES_XML_FRAGMENT_STORE_MAX) . "\n<!-- … truncated -->",
            ];
        }
    } finally {
        $reader->close();
    }

    return $index;
}

/**
 * Merge Word SDT body text into hollow <topic …> staging rows whose metadata.attributes['sdt-id'] matches SDT ids.
 */
function easa_erules_enrich_topics_from_word_sdt_index(PDO $pdo, int $batchId, array $sdtPlainIndex): void
{
    if ($batchId <= 0 || $sdtPlainIndex === []) {
        return;
    }
    $hasCanon = easa_erules_staging_has_canonical_column($pdo);
    $hasStructuredBlocksCol = easa_erules_staging_has_structured_blocks_column($pdo);
    $updSets = ['plain_text = ?'];
    if ($hasCanon) {
        $updSets[] = 'canonical_text = ?';
    }
    if ($hasStructuredBlocksCol) {
        $updSets[] = 'structured_blocks_json = ?';
    }
    $updSets[] = 'xml_fragment = ?';
    $updSets[] = 'content_hash = ?';
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging SET '
        . implode(', ', $updSets)
        . ' WHERE batch_id = ? AND node_uid = ?'
    );

    $st = $pdo->prepare(
        'SELECT node_uid, source_erules_id, node_type, metadata_json FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND LOWER(node_type) = \'topic\' AND (plain_text IS NULL OR TRIM(plain_text) = \'\')'
    );
    $st->execute([$batchId]);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $uid = trim((string) ($r['node_uid'] ?? ''));
        $metaRaw = isset($r['metadata_json']) ? (string) $r['metadata_json'] : '';
        /** @var array<string,mixed>|null */
        $meta = json_decode($metaRaw, true);
        $attrs = is_array($meta) && isset($meta['attributes']) && is_array($meta['attributes']) ? $meta['attributes'] : [];
        $sid = '';
        foreach ($attrs as $k => $v) {
            if (strcasecmp((string) $k, 'sdt-id') === 0 || strcasecmp((string) $k, 'sdt_id') === 0) {
                $sid = trim((string) $v);
                break;
            }
        }
        if ($sid === '' || !isset($sdtPlainIndex[$sid])) {
            continue;
        }

        $hit = $sdtPlainIndex[$sid];
        $plain = trim((string) ($hit['plain'] ?? ''));
        if ($plain === '') {
            continue;
        }
        $canonical = (string) ($hit['canonical'] ?? easa_erules_body_canonical_for_hash($plain));
        $frag = (string) ($hit['outer'] ?? '');
        $erulesId = isset($r['source_erules_id']) ? trim((string) $r['source_erules_id']) : '';
        $erulesDb = ($erulesId !== '' ? $erulesId : null);
        $nodeType = strtolower(trim((string) ($r['node_type'] ?? 'topic')));
        if ($nodeType === '') {
            $nodeType = 'topic';
        }
        $hash = easa_erules_staging_content_hash($canonical, $erulesDb, $nodeType);

        $blocksJson = $hasStructuredBlocksCol ? easa_erules_structured_blocks_json_from_outer_xml($frag) : null;
        $exe = [$plain];
        if ($hasCanon) {
            $exe[] = $canonical;
        }
        if ($hasStructuredBlocksCol) {
            $exe[] = $blocksJson;
        }
        $exe[] = $frag;
        $exe[] = $hash;
        $exe[] = $batchId;
        $exe[] = $uid;
        $upd->execute($exe);
    }
}

/**
 * Ensure batch storage_relpath matches the canonical path for this batch_id and source.xml is readable.
 *
 * @throws RuntimeException when the file is missing
 */
function easa_erules_validate_batch_source_storage(int $batchId, string $storageRelFromDb, string $absolutePath): void
{
    if ($batchId <= 0) {
        return;
    }
    $expected = easa_erules_batch_relative_path($batchId);
    $normDb = str_replace('\\', '/', trim($storageRelFromDb));
    if ($normDb !== '' && $normDb !== $expected) {
        error_log('easa_erules: batch ' . $batchId . ' storage_relpath "' . $normDb . '" differs from expected "' . $expected . '"');
    }
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new RuntimeException(
            'EASA source XML not found at ' . $expected . ' (resolved: ' . $absolutePath . ')'
        );
    }
}

/**
 * Second pass: for each distinct ERulesId in staging, read full outer XML from source.xml and fill plain / canonical / fragment.
 */
function easa_erules_enrich_staging_from_source_outer_xml(PDO $pdo, int $batchId, string $absoluteXmlPath): void
{
    if ($batchId <= 0 || !is_file($absoluteXmlPath) || !is_readable($absoluteXmlPath)) {
        return;
    }

    $st = $pdo->prepare(
        'SELECT DISTINCT TRIM(source_erules_id) AS eid
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND source_erules_id IS NOT NULL AND TRIM(source_erules_id) != \'\''
    );
    $st->execute([$batchId]);
    $need = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $e = trim((string) ($r['eid'] ?? ''));
        if ($e !== '') {
            $need[$e] = true;
        }
    }
    if ($need === []) {
        return;
    }
    /** @var array<string, string> */
    $needByKey = [];
    foreach (array_keys($need) as $eid) {
        $k = easa_erules_id_match_key((string) $eid);
        if ($k !== '' && !isset($needByKey[$k])) {
            $needByKey[$k] = (string) $eid;
        }
    }
    if ($needByKey === []) {
        return;
    }

    $reader = new XMLReader();
    if (!$reader->open($absoluteXmlPath, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
        return;
    }

    /** @var array<string, array{outer:string,score:int}> $bestByEid */
    $bestByEid = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            $attrs = easa_erules_reader_collect_attributes($reader);
            $erRaw = easa_erules_attr_ci($attrs, 'ERulesId');
            $er = trim((string) ($erRaw ?? ''));
            $erKey = easa_erules_id_match_key($er);
            if ($erKey === '' || !isset($needByKey[$erKey])) {
                continue;
            }
            $dbEid = $needByKey[$erKey];
            $outer = $reader->readOuterXml();
            if (!is_string($outer) || $outer === '') {
                continue;
            }
            $pcPeek = easa_erules_plain_canonical_from_outer_xml($outer);
            $peekPlainLen = strlen(trim($pcPeek['plain']));
            if (strcasecmp((string) $reader->localName, 'topic') === 0
                && $peekPlainLen < 80 && strpos($outer, '</') === false) {
                continue;
            }
            if (strlen($outer) > EASA_ERULES_XML_FRAGMENT_STORE_MAX) {
                $outer = substr($outer, 0, EASA_ERULES_XML_FRAGMENT_STORE_MAX) . "\n<!-- … truncated -->";
            }
            $pc = easa_erules_plain_canonical_from_outer_xml($outer);
            $plainLen = strlen(trim($pc['plain']));
            $nameBonus = strcasecmp((string) $reader->localName, 'topic') === 0 ? 20000 : 0;
            $score = $nameBonus + min($plainLen, 18000);
            $existing = $bestByEid[$dbEid]['score'] ?? -1;
            if ($score > $existing) {
                $bestByEid[$dbEid] = ['outer' => $outer, 'score' => $score];
            }
        }
    } finally {
        $reader->close();
    }

    if ($bestByEid === []) {
        return;
    }

    $hasCanon = easa_erules_staging_has_canonical_column($pdo);
    $hasStructuredBlocksCol = easa_erules_staging_has_structured_blocks_column($pdo);
    $srcUpdSets = ['plain_text = ?'];
    if ($hasCanon) {
        $srcUpdSets[] = 'canonical_text = ?';
    }
    if ($hasStructuredBlocksCol) {
        $srcUpdSets[] = 'structured_blocks_json = ?';
    }
    $srcUpdSets[] = 'xml_fragment = ?';
    $srcUpdSets[] = 'content_hash = ?';
    $updCanon = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging SET '
        . implode(', ', $srcUpdSets)
        . ' WHERE batch_id = ? AND TRIM(source_erules_id) = ?'
    );

    $typeSt = $pdo->prepare(
        'SELECT node_type FROM easa_erules_import_nodes_staging WHERE batch_id = ? AND TRIM(source_erules_id) = ? LIMIT 1'
    );

    foreach ($bestByEid as $eid => $best) {
        $outer = $best['outer'];
        $pc = easa_erules_plain_canonical_from_outer_xml($outer);
        $typeSt->execute([$batchId, $eid]);
        $tr = $typeSt->fetch(PDO::FETCH_ASSOC);
        $nodeType = is_array($tr) ? trim((string) ($tr['node_type'] ?? 'topic')) : 'topic';
        if ($nodeType === '') {
            $nodeType = 'topic';
        }
        $hash = easa_erules_staging_content_hash($pc['canonical'], $eid, $nodeType);
        $blocksJson = $hasStructuredBlocksCol ? easa_erules_structured_blocks_json_from_outer_xml($outer) : null;
        $exe = [$pc['plain']];
        if ($hasCanon) {
            $exe[] = $pc['canonical'];
        }
        if ($hasStructuredBlocksCol) {
            $exe[] = $blocksJson;
        }
        $exe[] = $outer;
        $exe[] = $hash;
        $exe[] = $batchId;
        $exe[] = $eid;
        $updCanon->execute($exe);
    }
}

/**
 * @param array<string, string|null> $detailReplacements
 */
function easa_erules_batch_update_progress(
    PDO $pdo,
    int $batchId,
    int $rowsSoFar,
    string $phase,
    string $lastNodeType,
    string $detailTemplate,
    array $detailReplacements = []
): void {
    if (!easa_erules_batch_progress_available($pdo)) {
        return;
    }
    $detail = $detailTemplate;
    foreach ($detailReplacements as $k => $v) {
        $detail = str_replace($k, (string) $v, $detail);
    }
    if (strlen($detail) > 500) {
        $detail = substr($detail, 0, 497) . '…';
    }
    $stmt = $pdo->prepare('
        UPDATE easa_erules_import_batches SET
            parse_rows_so_far = ?,
            parse_phase = ?,
            parse_last_node_type = ?,
            parse_detail = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $stmt->execute([$rowsSoFar, $phase, mb_substr($lastNodeType, 0, 128), $detail, $batchId]);
}

function easa_erules_merge_text_chunks(array $chunks): string
{
    $parts = [];
    foreach ($chunks as $c) {
        $c = (string) $c;
        if ($c === '') {
            continue;
        }
        $parts[] = $c;
    }

    return easa_erules_normalize_ws(implode(' ', $parts));
}

function easa_erules_parent_sort_key(?string $parentCandidateUid): string
{
    return $parentCandidateUid ?? '';
}

function easa_erules_title_is_section_heading_row(string $title): bool
{
    return preg_match('/^\s*SECTION\s+/iu', $title) === 1;
}

function easa_erules_title_is_subpart_heading_row(string $title): bool
{
    return preg_match('/^\s*SUBPART\b/iu', $title) === 1;
}

function easa_erules_title_is_annex_heading_row(string $title): bool
{
    $line = easa_erules_tree_title_first_line($title);

    return $line !== '' && preg_match('/^\s*ANNEX\b/iu', $line) === 1;
}

function easa_erules_title_is_appendix_heading_row(string $title): bool
{
    $line = easa_erules_tree_title_first_line($title);

    return $line !== '' && preg_match('/^\s*APPENDIX\b/iu', $line) === 1;
}

/**
 * First EASA Part-FCL style rule id in a title (FCL.100, FCL.205.A). Used to detect peer rules wrongly
 * nested under another rule’s &lt;toc&gt; row.
 */
function easa_erules_title_first_fcl_ref(?string $title): ?string
{
    $title = trim((string) $title);
    if ($title === '') {
        return null;
    }
    if (preg_match('/\b(FCL\.\d+[A-Z]?)\b/i', $title, $m)) {
        return strtoupper((string) $m[1]);
    }

    return null;
}

/**
 * Some EASA Easy Access XML nests SUBPART / SECTION block headings as siblings of the following rows
 * so structural headings get child_count 0 and the disclosure lands on the first &lt;toc&gt; child.
 * Reparent direct toc/topic siblings so SUBPART and SECTION headings become parents (SECTION wins over
 * SUBPART when both apply in document order).
 */
function easa_erules_reparent_structural_heading_children(PDO $pdo, int $batchId): void
{
    if ($batchId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title, sort_order, id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
         ORDER BY id ASC'
    );
    $st->execute([$batchId]);
    $all = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($r)) {
            $all[] = $r;
        }
    }
    $byParent = [];
    foreach ($all as $r) {
        $p = $r['parent_node_uid'] ?? null;
        $p = ($p !== null && (string) $p !== '') ? (string) $p : null;
        $k = easa_erules_parent_sort_key($p);
        $byParent[$k][] = $r;
    }
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging
         SET parent_node_uid = ?
         WHERE batch_id = ?
           AND node_uid = ?
           AND NOT (parent_node_uid <=> ?)'
    );
    foreach ($byParent as $rows) {
        usort($rows, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $activeSubpartUid = null;
        $activeSectionUid = null;
        foreach ($rows as $r) {
            $uid = trim((string) ($r['node_uid'] ?? ''));
            $nt = strtolower(trim((string) ($r['node_type'] ?? '')));
            $title = trim((string) ($r['title'] ?? ''));
            if ($nt === 'heading' && easa_erules_title_is_subpart_heading_row($title)) {
                $activeSubpartUid = $uid !== '' ? $uid : null;
                $activeSectionUid = null;
                continue;
            }
            if ($nt === 'heading' && easa_erules_title_is_section_heading_row($title)) {
                $activeSectionUid = $uid !== '' ? $uid : null;
                continue;
            }
            $target = $activeSectionUid ?? $activeSubpartUid;
            if ($target === null || $uid === '') {
                continue;
            }
            if (!in_array($nt, ['toc', 'topic'], true)) {
                continue;
            }
            $upd->execute([$target, $batchId, $uid, $target]);
        }
    }
}

/**
 * When ANNEX is a sibling of an effectively empty &lt;toc&gt; nav shell, SUBPART/SECTION headings often sit
 * under the toc (not under the ANNEX). Lift **only** the toc’s direct children onto the ANNEX; leave the
 * toc row’s own parent/parent_uid unchanged so the shell stays in place but ends up with no children.
 */
function easa_erules_reparent_annex_lift_toc_wrapper_children(PDO $pdo, int $batchId): void
{
    if ($batchId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title, plain_text, sort_order, id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
         ORDER BY id ASC'
    );
    $st->execute([$batchId]);
    $all = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($r)) {
            $all[] = $r;
        }
    }
    $byParent = [];
    foreach ($all as $r) {
        $p = $r['parent_node_uid'] ?? null;
        $p = ($p !== null && (string) $p !== '') ? (string) $p : null;
        $k = easa_erules_parent_sort_key($p);
        $byParent[$k][] = $r;
    }
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging
         SET parent_node_uid = ?
         WHERE batch_id = ?
           AND node_uid = ?
           AND NOT (parent_node_uid <=> ?)'
    );
    foreach ($byParent as $rows) {
        usort($rows, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            $r = $rows[$i];
            $nt = strtolower(trim((string) ($r['node_type'] ?? '')));
            $title = trim((string) ($r['title'] ?? ''));
            if ($nt !== 'heading' || !easa_erules_title_is_annex_heading_row($title)) {
                continue;
            }
            $annexUid = trim((string) ($r['node_uid'] ?? ''));
            if ($annexUid === '' || $i + 1 >= $n) {
                continue;
            }
            $next = $rows[$i + 1];
            if (strtolower(trim((string) ($next['node_type'] ?? ''))) !== 'toc') {
                continue;
            }
            $tocUid = trim((string) ($next['node_uid'] ?? ''));
            if ($tocUid === '') {
                continue;
            }
            $tocPk = easa_erules_parent_sort_key($tocUid);
            $tocChildren = $byParent[$tocPk] ?? [];
            if ($tocChildren === []) {
                continue;
            }
            if (!easa_erules_toc_is_annex_nav_wrapper_candidate($next, $tocChildren)) {
                continue;
            }
            foreach ($tocChildren as $ch) {
                $cuid = trim((string) ($ch['node_uid'] ?? ''));
                if ($cuid === '') {
                    continue;
                }
                $upd->execute([$annexUid, $batchId, $cuid, $annexUid]);
            }
        }
    }
}

/**
 * True when &lt;toc&gt; acts as an empty navigation shell whose children include SUBPART headings.
 *
 * @param list<array<string, mixed>> $tocDirectChildren
 */
function easa_erules_toc_is_annex_nav_wrapper_candidate(array $tocRow, array $tocDirectChildren): bool
{
    $plain = trim((string) ($tocRow['plain_text'] ?? ''));
    /** Nav shells should not carry rule body text (allow minor whitespace / merge noise). */
    if (strlen($plain) > 2048) {
        return false;
    }
    $hasSubpartHeading = false;
    foreach ($tocDirectChildren as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (strtolower(trim((string) ($c['node_type'] ?? ''))) !== 'heading') {
            continue;
        }
        $t = trim((string) ($c['title'] ?? ''));
        $line = easa_erules_tree_title_first_line($t) ?: $t;
        if ($line !== '' && easa_erules_title_is_subpart_heading_row($line)) {
            $hasSubpartHeading = true;

            break;
        }
    }

    return $hasSubpartHeading;
}

/**
 * Easy Access XML often emits ANNEX I and SUBPART A–K as sibling &lt;heading&gt; rows under the same
 * parent (document/toc shell). Reparent SUBPART, SECTION, APPENDIX headings under the preceding ANNEX
 * heading in document order; SECTION headings attach to the current SUBPART when one is active.
 */
function easa_erules_reparent_annex_subpart_appendix_headings(PDO $pdo, int $batchId): void
{
    if ($batchId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title, sort_order, id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
         ORDER BY id ASC'
    );
    $st->execute([$batchId]);
    $all = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($r)) {
            $all[] = $r;
        }
    }
    $byParent = [];
    foreach ($all as $r) {
        $p = $r['parent_node_uid'] ?? null;
        $p = ($p !== null && (string) $p !== '') ? (string) $p : null;
        $k = easa_erules_parent_sort_key($p);
        $byParent[$k][] = $r;
    }
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging
         SET parent_node_uid = ?
         WHERE batch_id = ?
           AND node_uid = ?
           AND NOT (parent_node_uid <=> ?)'
    );
    foreach ($byParent as $rows) {
        usort($rows, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $majorUid = null;
        $currentSubpartUid = null;
        foreach ($rows as $r) {
            $uid = trim((string) ($r['node_uid'] ?? ''));
            $nt = strtolower(trim((string) ($r['node_type'] ?? '')));
            $title = trim((string) ($r['title'] ?? ''));
            if ($uid === '' || $nt !== 'heading') {
                continue;
            }
            if (easa_erules_title_is_annex_heading_row($title)) {
                $majorUid = $uid;
                $currentSubpartUid = null;

                continue;
            }
            if ($majorUid === null) {
                continue;
            }
            if (easa_erules_title_is_subpart_heading_row(easa_erules_tree_title_first_line($title) ?: $title)) {
                $upd->execute([$majorUid, $batchId, $uid, $majorUid]);
                $currentSubpartUid = $uid;

                continue;
            }
            if (easa_erules_title_is_appendix_heading_row($title)) {
                $upd->execute([$majorUid, $batchId, $uid, $majorUid]);
                $currentSubpartUid = null;

                continue;
            }
            $firstLine = easa_erules_tree_title_first_line($title);
            if ($firstLine !== '' && easa_erules_title_is_section_heading_row($firstLine)) {
                $target = $currentSubpartUid ?? $majorUid;
                $upd->execute([$target, $batchId, $uid, $target]);

                continue;
            }
        }
    }
}

/**
 * XML nesting sometimes hangs peer FCL rule rows (another FCL.&lt;n&gt; or AMC cross-ref) under the
 * first rule &lt;toc&gt; in a section. Promote those rows to the same parent as the enclosing rule toc.
 */
function easa_erules_promote_misnested_fcl_peers(PDO $pdo, int $batchId): void
{
    if ($batchId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?'
    );
    $st->execute([$batchId]);
    $byUid = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $u = trim((string) ($r['node_uid'] ?? ''));
        if ($u === '') {
            continue;
        }
        $byUid[$u] = $r;
    }
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging
         SET parent_node_uid = ?
         WHERE batch_id = ?
           AND node_uid = ?
           AND NOT (parent_node_uid <=> ?)'
    );
    for ($iter = 0; $iter < 30; $iter++) {
        $changed = 0;
        foreach ($byUid as $uid => $r) {
            $nt = strtolower(trim((string) ($r['node_type'] ?? '')));
            if (!in_array($nt, ['toc', 'topic'], true)) {
                continue;
            }
            $puid = trim((string) ($r['parent_node_uid'] ?? ''));
            if ($puid === '' || !isset($byUid[$puid])) {
                continue;
            }
            $p = $byUid[$puid];
            if (strtolower(trim((string) ($p['node_type'] ?? ''))) !== 'toc') {
                continue;
            }
            $pF = easa_erules_title_first_fcl_ref((string) ($p['title'] ?? ''));
            $cF = easa_erules_title_first_fcl_ref((string) ($r['title'] ?? ''));
            if ($pF === null || $cF === null || $pF === $cF) {
                continue;
            }
            $gp = trim((string) ($p['parent_node_uid'] ?? ''));
            if ($gp === '') {
                continue;
            }
            $upd->execute([$gp, $batchId, $uid, $gp]);
            if ($upd->rowCount() > 0) {
                $byUid[$uid]['parent_node_uid'] = $gp;
                $changed++;
            }
        }
        if ($changed === 0) {
            break;
        }
    }
}

/**
 * Label text for peer-lift when &lt;toc&gt;/heading row has no title (same precedence as short_tree_label’s first line: title, source_title, erules; else first direct child).
 *
 * @param list<array<string, mixed>> $sortedDirectChildren by sort_order, id
 *
 * @return array{0: string, 1: string} [blob, source_tag for diagnostics]
 */
function easa_erules_peer_lift_wrapper_label_blob(array $w, array $sortedDirectChildren): array
{
    $wTitle = trim((string) ($w['title'] ?? ''));
    $wSrc = trim((string) ($w['source_title'] ?? ''));
    $wEr = trim((string) ($w['source_erules_id'] ?? ''));
    if ($wTitle !== '') {
        return [$wTitle, 'own:title'];
    }
    if ($wSrc !== '') {
        return [$wSrc, 'own:source_title'];
    }
    if ($wEr !== '') {
        return [$wEr, 'own:source_erules_id'];
    }
    foreach ($sortedDirectChildren as $c) {
        if (!is_array($c)) {
            continue;
        }
        $uid = trim((string) ($c['node_uid'] ?? ''));
        $ct = trim((string) ($c['title'] ?? ''));
        $cs = trim((string) ($c['source_title'] ?? ''));
        $ce = trim((string) ($c['source_erules_id'] ?? ''));
        if ($ct === '' && $cs === '' && $ce === '') {
            continue;
        }
        if ($ct !== '') {
            return [$ct, 'first_child:' . $uid . ':title'];
        }
        if ($cs !== '') {
            return [$cs, 'first_child:' . $uid . ':source_title'];
        }

        return [$ce, 'first_child:' . $uid . ':source_erules_id'];
    }

    return ['', 'none'];
}

/**
 * Title / source_erules identity line derived from **this row only** (not first_child_* enrichment).
 *
 * @param array<string, mixed> $row
 */
function easa_erules_tree_row_own_primary_navigation_line(array $row): string
{
    foreach (
        [
            trim((string) ($row['title'] ?? '')),
            trim((string) ($row['source_title'] ?? '')),
            trim((string) ($row['source_erules_id'] ?? '')),
        ] as $blob
    ) {
        if ($blob !== '') {
            return easa_erules_tree_title_first_line($blob);
        }
    }

    return '';
}

/**
 * True when a **single logical line begins** with FCL/stub codified implementing rule (rules out "GM1 FCL.005…").
 *
 * Covers FCL.020(a) and analogous ORA/CAT/DTO/ARA/MED lead tokens.
 */
function easa_erules_tree_line_leads_codified_ir_rule(?string $line): bool
{
    $line = trim((string) $line);
    if ($line === '') {
        return false;
    }
    // FCL.010, FCL.010A, FCL.020(a) …
    if (preg_match('/^\s*FCL\.\d+[A-Za-z]?(?:\([^)]*\))*\b/iu', $line) === 1) {
        return true;
    }

    return preg_match('/^\s*(?:ORA|CAT|DTO|ARA|MED)(?:\.[A-Z0-9]+)+\b/iu', $line) === 1;
}

/**
 * @param array<string, mixed> $row
 */
function easa_erules_row_own_fields_any_line_leads_codified_ir(array $row): bool
{
    foreach (
        [
            trim((string) ($row['title'] ?? '')),
            trim((string) ($row['source_title'] ?? '')),
            trim((string) ($row['source_erules_id'] ?? '')),
        ] as $blob
    ) {
        if ($blob === '') {
            continue;
        }
        foreach (preg_split('/\R+/u', $blob) ?: [] as $ln) {
            $ln = trim((string) $ln);
            if ($ln !== '' && easa_erules_tree_line_leads_codified_ir_rule($ln)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * AMC/GM **supplement** row: own title/source/erules only; never inferred from child labels.
 * If any own line leads with a codified IR stub, this is **not** an AMC/GM nav row (parent IR topic wins).
 *
 * @param array<string, mixed> $c
 */
function easa_erules_row_own_fields_indicate_gm_amc_supplement(array $c): bool
{
    if (easa_erules_row_own_fields_any_line_leads_codified_ir($c)) {
        return false;
    }
    foreach (
        [
            trim((string) ($c['title'] ?? '')),
            trim((string) ($c['source_title'] ?? '')),
            trim((string) ($c['source_erules_id'] ?? '')),
        ] as $blob
    ) {
        if ($blob === '') {
            continue;
        }
        foreach (preg_split('/\R+/u', $blob) ?: [] as $ln) {
            $ln = trim((string) $ln);
            if ($ln !== '' && easa_erules_tree_title_starts_gm_or_amc($ln)) {
                return true;
            }
        }
    }
    $title = trim((string) ($c['title'] ?? ''));
    $src = trim((string) ($c['source_title'] ?? ''));
    $er = trim((string) ($c['source_erules_id'] ?? ''));
    $band = easa_erules_classify_display_band(
        $c['node_type'] ?? null,
        $title !== '' ? $title : null,
        $src !== '' ? $src : null,
        $er !== '' ? $er : null
    );

    return $band === 'amc' || $band === 'gm';
}

/**
 * True when toc/heading has no own textual label — safe to fall back to first_child_* for browse semantics only.
 *
 * @param array<string, mixed> $row
 */
function easa_erules_tree_row_own_label_fields_empty(array $row): bool
{
    return trim((string) ($row['title'] ?? '')) === ''
        && trim((string) ($row['source_title'] ?? '')) === ''
        && trim((string) ($row['source_erules_id'] ?? '')) === '';
}

/**
 * Direct children from an in-memory by-parent map (same order as import / peer lift).
 *
 * @param array<string, list<array<string, mixed>>> $byParent
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_sorted_direct_children_from_map(string $parentUid, array $byParent): array
{
    $kids = $byParent[easa_erules_parent_sort_key($parentUid)] ?? [];
    usort($kids, static function (array $a, array $b): int {
        $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($so !== 0) {
            return $so;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    return $kids;
}

/**
 * Display / reparent identity for **empty** toc/heading wrappers: same sources as
 * {@see easa_erules_short_tree_label()} fallback (first child's title / source_title / source_erules_id).
 *
 * @param array<string, mixed> $wrapper
 * @param list<array<string, mixed>> $sortedDirectChildren
 *
 * @return array<string, mixed>|null Row-shaped slice of the first child for stub / GM-AMC checks; null if not applicable.
 */
function easa_erules_tree_empty_nav_wrapper_display_identity_row(array $wrapper, array $sortedDirectChildren): ?array
{
    $nt = strtolower(trim((string) ($wrapper['node_type'] ?? '')));
    if (!in_array($nt, ['toc', 'heading'], true)) {
        return null;
    }
    if (!easa_erules_tree_row_own_label_fields_empty($wrapper)) {
        return null;
    }
    $fc = $sortedDirectChildren[0] ?? null;
    if (!is_array($fc)) {
        return null;
    }

    return [
        'node_uid' => trim((string) ($fc['node_uid'] ?? '')),
        'node_type' => $fc['node_type'] ?? '',
        'title' => $fc['title'] ?? '',
        'source_title' => $fc['source_title'] ?? '',
        'source_erules_id' => $fc['source_erules_id'] ?? '',
    ];
}

/**
 * True when an empty toc/heading wrapper’s **first** direct child is an AMC/GM supplement (own fields).
 *
 * @param array<string, mixed> $wrapper
 * @param list<array<string, mixed>> $sortedDirectChildren
 */
function easa_erules_tree_empty_nav_wrapper_first_child_is_gm_amc_supplement(array $wrapper, array $sortedDirectChildren): bool
{
    $id = easa_erules_tree_empty_nav_wrapper_display_identity_row($wrapper, $sortedDirectChildren);
    if ($id === null || trim((string) ($id['node_uid'] ?? '')) === '') {
        return false;
    }

    return easa_erules_row_own_fields_indicate_gm_amc_supplement($id);
}

/**
 * AMC/GM guidance rows (titles / bands), as distinct from implementing-rule peers (FCL/ORA/CAT/…).
 *
 * Uses **row identity only**. "GM1 FCL.005" is supplement; never an IR lift peer (even though it cites FCL).
 */
function easa_erules_peer_lift_row_is_amc_gm_nav(array $c): bool
{
    return easa_erules_row_own_fields_indicate_gm_amc_supplement($c);
}

/** Peer implementing-rule row eligible to sit under SUBPART (not AMC/GM). */
function easa_erules_peer_lift_row_is_liftable_ir_peer(array $c): bool
{
    if (easa_erules_peer_lift_row_is_amc_gm_nav($c)) {
        return false;
    }

    return easa_erules_row_own_fields_any_line_leads_codified_ir($c);
}

/**
 * After peer lift, AMC/GM may still be direct children of SUBPART — attach under matching IR sibling by stub (e.g. GM1 FCL.010 → FCL.010).
 */
function easa_erules_reparent_amc_gm_under_subpart_ir_peers(PDO $pdo, int $batchId): void
{
    if ($batchId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title, source_title, source_erules_id, sort_order, id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
         ORDER BY id ASC'
    );
    $st->execute([$batchId]);
    $all = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($r)) {
            $all[] = $r;
        }
    }
    $byUid = [];
    foreach ($all as $r) {
        $u = trim((string) ($r['node_uid'] ?? ''));
        if ($u !== '') {
            $byUid[$u] = $r;
        }
    }
    $byParent = [];
    foreach ($all as $r) {
        $p = $r['parent_node_uid'] ?? null;
        $p = ($p !== null && (string) $p !== '') ? (string) $p : null;
        $k = easa_erules_parent_sort_key($p);
        $byParent[$k][] = $r;
    }
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging
         SET parent_node_uid = ?
         WHERE batch_id = ?
           AND node_uid = ?
           AND NOT (parent_node_uid <=> ?)'
    );
    foreach ($byParent as $pKey => $siblings) {
        if ($pKey === '') {
            continue;
        }
        $parent = $byUid[$pKey] ?? null;
        if (!is_array($parent)) {
            continue;
        }
        $pNt = strtolower(trim((string) ($parent['node_type'] ?? '')));
        if (!in_array($pNt, ['heading', 'toc'], true)) {
            continue;
        }
        $pTitle = trim((string) ($parent['title'] ?? ''));
        if ($pTitle === '' || !easa_erules_tree_title_line_is_subpart_heading($pTitle)) {
            continue;
        }
        usort($siblings, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $stubMap = [];
        foreach ($siblings as $c) {
            if (!is_array($c) || !easa_erules_peer_lift_row_is_liftable_ir_peer($c)) {
                continue;
            }
            $k = easa_erules_tree_nav_rule_key_from_row($c);
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            if ($k !== null && $cuid !== '' && !isset($stubMap[$k])) {
                $stubMap[$k] = $cuid;
            }
        }
        if ($stubMap === []) {
            continue;
        }
        foreach ($siblings as $c) {
            if (!is_array($c) || !easa_erules_peer_lift_row_is_amc_gm_nav($c)) {
                continue;
            }
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            if ($cuid === '') {
                continue;
            }
            $tk = easa_erules_tree_nav_rule_key_from_row($c);
            if ($tk === null || !isset($stubMap[$tk])) {
                continue;
            }
            $target = $stubMap[$tk];
            if ($target === $cuid) {
                continue;
            }
            $upd->execute([$target, $batchId, $cuid, $target]);
        }

        // Empty toc/heading shells borrow label from first child (short_tree_label): promote supplement topics under IR peers.
        foreach ($siblings as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            if ($cuid === '') {
                continue;
            }
            $cnt = strtolower(trim((string) ($c['node_type'] ?? '')));
            if (!in_array($cnt, ['toc', 'heading'], true)) {
                continue;
            }
            if (!easa_erules_tree_row_own_label_fields_empty($c)) {
                continue;
            }
            $wrapKids = easa_erules_tree_sorted_direct_children_from_map($cuid, $byParent);
            if (!easa_erules_tree_empty_nav_wrapper_first_child_is_gm_amc_supplement($c, $wrapKids)) {
                continue;
            }
            foreach ($wrapKids as $ch) {
                if (!is_array($ch) || !easa_erules_row_own_fields_indicate_gm_amc_supplement($ch)) {
                    continue;
                }
                $chUid = trim((string) ($ch['node_uid'] ?? ''));
                if ($chUid === '') {
                    continue;
                }
                $tk = easa_erules_tree_nav_rule_key_from_row($ch);
                if ($tk === null || !isset($stubMap[$tk])) {
                    continue;
                }
                $target = $stubMap[$tk];
                if ($target === $chUid) {
                    continue;
                }
                $upd->execute([$target, $batchId, $chUid, $target]);
            }
        }
    }
}

/**
 * &lt;toc&gt;/heading whose title is a rule id (FCL.001, ORA…, CAT…, DTA…, ARA…, MED…) but whose
 * children are peer implementing-rule rows: lift **only IR peers** onto the SUBPART (wrapper parent).
 * AMC/GM rows stay nested: reparent each to the IR peer whose stub matches the reference in its title (e.g. GM1 FCL.010).
 * Rows that are neither remain under the wrapper. The wrapper may end empty and is hidden by tree_children skips.
 */
function easa_erules_reparent_rule_ref_toc_peer_lift_once(PDO $pdo, int $batchId): int
{
    if ($batchId <= 0) {
        return 0;
    }
    $diagUid = trim((string) (getenv('EASA_PEER_LIFT_DEBUG_UID') ?: ''));
    $peerLiftDiag = static function (bool $enabled, string $line): void {
        if (!$enabled) {
            return;
        }
        fwrite(STDERR, '[easa_peer_lift] ' . $line . "\n");
    };
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title, source_title, source_erules_id, sort_order, id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
         ORDER BY id ASC'
    );
    $st->execute([$batchId]);
    $all = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($r)) {
            $all[] = $r;
        }
    }
    $byParent = [];
    foreach ($all as $r) {
        $p = $r['parent_node_uid'] ?? null;
        $p = ($p !== null && (string) $p !== '') ? (string) $p : null;
        $k = easa_erules_parent_sort_key($p);
        $byParent[$k][] = $r;
    }
    $byUid = [];
    foreach ($all as $r) {
        $u = trim((string) ($r['node_uid'] ?? ''));
        if ($u !== '') {
            $byUid[$u] = $r;
        }
    }
    $upd = $pdo->prepare(
        'UPDATE easa_erules_import_nodes_staging
         SET parent_node_uid = ?
         WHERE batch_id = ?
           AND node_uid = ?
           AND NOT (parent_node_uid <=> ?)'
    );
    $changed = 0;
    foreach ($all as $w) {
        $wUid = trim((string) ($w['node_uid'] ?? ''));
        $diag = $diagUid !== '' && strcasecmp($wUid, $diagUid) === 0;
        if ($diag) {
            $peerLiftDiag(true, 'batch_id=' . $batchId . ' evaluating node_uid=' . $wUid);
        }
        $nt = strtolower(trim((string) ($w['node_type'] ?? '')));
        if (!in_array($nt, ['heading', 'toc'], true)) {
            $peerLiftDiag($diag, 'skip: node_type not heading/toc (got ' . $nt . ')');

            continue;
        }
        $wTitle = trim((string) ($w['title'] ?? ''));
        $wSrc = trim((string) ($w['source_title'] ?? ''));
        $wEr = trim((string) ($w['source_erules_id'] ?? ''));
        if ($diag) {
            $peerLiftDiag(
                true,
                'wrapper fields: title=' . json_encode(mb_substr($wTitle, 0, 200), JSON_UNESCAPED_UNICODE)
                . ' source_title=' . json_encode(mb_substr($wSrc, 0, 200), JSON_UNESCAPED_UNICODE)
                . ' source_erules_id=' . json_encode(mb_substr($wEr, 0, 120), JSON_UNESCAPED_UNICODE)
            );
        }
        if ($wUid === '') {
            $peerLiftDiag($diag, 'skip: empty node_uid');

            continue;
        }
        $pUid = trim((string) ($w['parent_node_uid'] ?? ''));
        if ($diag) {
            $peerLiftDiag(true, 'wrapper parent_node_uid=' . ($pUid !== '' ? $pUid : '(empty)'));
        }
        if ($pUid === '') {
            $peerLiftDiag($diag, 'skip: parent_node_uid empty');

            continue;
        }
        $kids = $byParent[easa_erules_parent_sort_key($wUid)] ?? [];
        usort($kids, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $nk = count($kids);
        if ($diag) {
            $peerLiftDiag(true, 'direct child count=' . $nk);
        }
        if ($nk < 2) {
            $peerLiftDiag($diag, 'skip: need >= 2 direct children');

            continue;
        }
        [$wBlob, $labelSrc] = easa_erules_peer_lift_wrapper_label_blob($w, $kids);
        if ($diag) {
            $peerLiftDiag(
                true,
                'label_candidate: source=' . $labelSrc . ' blob=' . json_encode(mb_substr($wBlob, 0, 280), JSON_UNESCAPED_UNICODE)
            );
        }
        if ($wBlob === '' || easa_erules_tree_title_is_structural_section($wBlob)) {
            $peerLiftDiag($diag, 'skip: empty label blob or structural section title');

            continue;
        }
        if (!easa_erules_tree_blob_has_ir_reference($wBlob)) {
            $peerLiftDiag($diag, 'skip: blob_has_ir_reference=false on label blob');

            continue;
        }
        $pRow = $byUid[$pUid] ?? null;
        $pTitle = is_array($pRow) ? trim((string) ($pRow['title'] ?? '')) : '';
        if ($diag) {
            $peerLiftDiag(true, 'parent title=' . json_encode(mb_substr($pTitle, 0, 240), JSON_UNESCAPED_UNICODE));
        }
        $stubSetIr = [];
        $stubLines = [];
        $hasIrPeer = false;
        foreach ($kids as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            $isGm = easa_erules_peer_lift_row_is_amc_gm_nav($c);
            $isIr = easa_erules_peer_lift_row_is_liftable_ir_peer($c);
            if ($isIr) {
                $hasIrPeer = true;
            }
            $key = easa_erules_tree_nav_rule_key_from_row($c);
            if ($isIr && $key !== null) {
                $stubSetIr[$key] = true;
            }
            if ($diag && $cuid !== '') {
                $tag = $isGm ? 'gm_amc' : ($isIr ? 'ir' : 'other');
                $stubLines[] = $cuid . ':' . $tag . ':' . ($key ?? 'null');
            }
        }
        if ($diag) {
            $peerLiftDiag(true, 'ir_peer stub keys: ' . implode(', ', array_keys($stubSetIr)));
            $peerLiftDiag(true, 'per-child [uid:kind:stub]: ' . implode(' | ', array_slice($stubLines, 0, 40))
                . (count($stubLines) > 40 ? ' … +' . (count($stubLines) - 40) . ' more' : ''));
        }
        $stubLift = count($stubSetIr) >= 2;
        $subpartFallback = false;
        if (
            !$stubLift
            && $hasIrPeer
            && in_array($nt, ['toc', 'heading'], true)
            && $pTitle !== ''
            && easa_erules_tree_title_line_is_subpart_heading($pTitle)
            && easa_erules_tree_blob_has_ir_reference($wBlob)
        ) {
            $subpartFallback = true;
        }
        $lift = $stubLift || $subpartFallback;
        if ($diag) {
            $peerLiftDiag(
                true,
                'lift decision: stubLift=' . ($stubLift ? 'true' : 'false')
                . ' subpartFallback=' . ($subpartFallback ? 'true' : 'false')
                . ' hasIrPeer=' . ($hasIrPeer ? 'true' : 'false')
                . ' subpart_line_match=' . ($pTitle !== '' && easa_erules_tree_title_line_is_subpart_heading($pTitle) ? 'true' : 'false')
                . ' lift=' . ($lift ? 'true' : 'false')
            );
        }
        if (!$lift) {
            $peerLiftDiag($diag, 'skip: lift=false');

            continue;
        }
        $irPeers = [];
        $supplements = [];
        foreach ($kids as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (easa_erules_peer_lift_row_is_liftable_ir_peer($c)) {
                $irPeers[] = $c;
            } elseif (easa_erules_peer_lift_row_is_amc_gm_nav($c)) {
                $supplements[] = $c;
            }
        }
        if ($diag) {
            $peerLiftDiag(true, 'partition: irPeers=' . count($irPeers) . ' supplements=' . count($supplements));
        }
        $stubMap = [];
        foreach ($irPeers as $c) {
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            if ($cuid === '') {
                continue;
            }
            $k = easa_erules_tree_nav_rule_key_from_row($c);
            if ($k !== null && !isset($stubMap[$k])) {
                $stubMap[$k] = $cuid;
            }
        }
        $updatesSqlRows = 0;
        $attempted = 0;
        $wrapperRowsAffected = 0;
        foreach ($irPeers as $c) {
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            if ($cuid === '') {
                continue;
            }
            $attempted++;
            $upd->execute([$pUid, $batchId, $cuid, $pUid]);
            $rc = $upd->rowCount();
            $updatesSqlRows += $rc;
            if ($diag) {
                $peerLiftDiag(true, 'LIFT IR peer ' . $cuid . ' → subpart ' . $pUid . ' rowCount=' . $rc);
            }
            if ($rc > 0) {
                $changed++;
                $wrapperRowsAffected++;
            }
        }
        foreach ($supplements as $c) {
            $cuid = trim((string) ($c['node_uid'] ?? ''));
            if ($cuid === '') {
                continue;
            }
            $tk = easa_erules_tree_nav_rule_key_from_row($c);
            if ($tk === null || !isset($stubMap[$tk])) {
                if ($diag) {
                    $peerLiftDiag(true, 'SKIP supplement ' . $cuid . ' (no stub map for ' . ($tk ?? 'null') . ')');
                }

                continue;
            }
            $target = $stubMap[$tk];
            if ($target === $cuid) {
                continue;
            }
            $attempted++;
            $upd->execute([$target, $batchId, $cuid, $target]);
            $rc = $upd->rowCount();
            $updatesSqlRows += $rc;
            if ($diag) {
                $peerLiftDiag(true, 'ATTACH GM/AMC ' . $cuid . ' → IR ' . $target . ' (stub ' . $tk . ') rowCount=' . $rc);
            }
            if ($rc > 0) {
                $changed++;
                $wrapperRowsAffected++;
            }
        }
        if ($diag) {
            $peerLiftDiag(
                true,
                'summary: operations attempted=' . $attempted . ' sum(rowCount)=' . $updatesSqlRows
                . ' this_wrapper_rows_changed=' . $wrapperRowsAffected
            );
        }
    }

    return $changed;
}

function easa_erules_reparent_rule_ref_toc_peer_lift(PDO $pdo, int $batchId): void
{
    for ($i = 0; $i < 12; $i++) {
        if (easa_erules_reparent_rule_ref_toc_peer_lift_once($pdo, $batchId) === 0) {
            break;
        }
    }
    easa_erules_reparent_amc_gm_under_subpart_ir_peers($pdo, $batchId);
}

/**
 * Re-apply all staging parent-link passes (no XML re-parse). Safe for ops after deploying reparent fixes.
 */
function easa_erules_repair_batch_tree_parents(PDO $pdo, int $batchId): void
{
    easa_erules_reparent_structural_heading_children($pdo, $batchId);
    easa_erules_reparent_annex_lift_toc_wrapper_children($pdo, $batchId);
    easa_erules_reparent_annex_subpart_appendix_headings($pdo, $batchId);
    easa_erules_promote_misnested_fcl_peers($pdo, $batchId);
    easa_erules_reparent_rule_ref_toc_peer_lift($pdo, $batchId);
}

/**
 * Streaming import: memory scales with tree depth + largest text buffers, not whole-file DOM.
 *
 * @return array{imported: int, publication_meta: array<string, mixed>|null}
 */
function easa_erules_import_batch_xml_to_staging(PDO $pdo, int $batchId): array
{
    if ($batchId <= 0) {
        throw new InvalidArgumentException('Invalid batch id');
    }

    $st = $pdo->prepare('SELECT id, storage_relpath, status FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
    $st->execute([$batchId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Batch not found');
    }

    $rel = trim((string) ($row['storage_relpath'] ?? ''));
    if ($rel === '') {
        throw new RuntimeException('Batch has no storage path');
    }

    $abs = rl_project_root() . '/' . str_replace('\\', '/', $rel);
    if (!is_file($abs) || !is_readable($abs)) {
        throw new RuntimeException('XML file missing on disk: ' . $rel);
    }

    $fi = @stat($abs);
    $size = is_array($fi) ? (int) ($fi['size'] ?? 0) : 0;
    if ($size > 450 * 1024 * 1024) {
        throw new RuntimeException('XML file exceeds 450 MB safety limit; split the export or raise the limit with ops approval.');
    }

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                parse_started_at = UTC_TIMESTAMP(),
                parse_finished_at = NULL,
                parse_rows_so_far = 0,
                parse_phase = \'streaming\',
                parse_last_node_type = NULL,
                parse_detail = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute(['Reading XML stream (file ' . round($size / 1048576, 1) . ' MiB)…', $batchId]);
    }

    easa_erules_validate_batch_source_storage($batchId, $rel, $abs);

    $pdo->prepare('DELETE FROM easa_erules_import_nodes_staging WHERE batch_id = ?')->execute([$batchId]);

    $hasCanonicalCol = easa_erules_staging_has_canonical_column($pdo);
    $hasStructuredBlocksCol = easa_erules_staging_has_structured_blocks_column($pdo);
    $insertCols = [
        'batch_id', 'node_uid', 'source_erules_id', 'node_type', 'parent_node_uid', 'sort_order', 'depth',
        'path', 'breadcrumb', 'title', 'source_title', 'plain_text',
    ];
    if ($hasCanonicalCol) {
        $insertCols[] = 'canonical_text';
    }
    if ($hasStructuredBlocksCol) {
        $insertCols[] = 'structured_blocks_json';
    }
    $insertCols[] = 'xml_fragment';
    $insertCols[] = 'metadata_json';
    $insertCols[] = 'content_hash';
    $ins = $pdo->prepare(
        'INSERT INTO easa_erules_import_nodes_staging (' . implode(', ', $insertCols) . ') VALUES ('
        . implode(', ', array_fill(0, count($insertCols), '?')) . ')'
    );

    $reader = new XMLReader();
    if (!$reader->open($abs, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('Could not open XML for streaming read');
    }

    /** Light stack frames (mixed content order preserved via chunks). */
    $stack = [];
    /** @var list<string> */
    $candidateUidStack = [];
    /** @var array<string, string> preliminary titles from attributes */
    $titleByUid = [];

    $siblingCount = [];
    $seq = 0;
    $imported = 0;
    $publicationMeta = null;

    $flushProgress = static function (string $lastType, string $detail) use ($pdo, $batchId, &$imported): void {
        if ($imported > 0 && ($imported % EASA_ERULES_PROGRESS_INTERVAL) === 0) {
            easa_erules_batch_update_progress($pdo, $batchId, $imported, 'inserting', $lastType, $detail);
        }
    };

    $popFrame = static function () use (&$stack, &$candidateUidStack, &$titleByUid, &$siblingCount, &$seq, &$imported, &$publicationMeta, $pdo, $batchId, $ins, $flushProgress, $hasCanonicalCol, $hasStructuredBlocksCol): void {
        /** @var array{frame: object, chunks: array} $p */
        $p = array_pop($stack);
        if (!is_array($p)) {
            return;
        }
        $frame = $p['frame'];
        $chunks = $p['chunks'];
        $plain = isset($frame->forcedPlain)
            ? (string) $frame->forcedPlain
            : easa_erules_merge_text_chunks($chunks);

        if ($frame->isCandidate && $frame->candidateUid !== null) {
            array_pop($candidateUidStack);

            $attrs = $frame->attrs;
            $local = $frame->localName;
            $uid = $frame->candidateUid;
            $parentUid = $frame->parentCandidateUid;

            $title = easa_erules_pick_title($attrs, $plain, null);
            if ($title !== '') {
                $titleByUid[$uid] = $title;
            }

            $sourceTitle = isset($attrs['source-title']) ? trim((string) $attrs['source-title']) : (isset($attrs['source_title']) ? trim((string) $attrs['source_title']) : null);

            $erulesId = isset($attrs['ERulesId']) ? trim((string) $attrs['ERulesId']) : null;
            if ($erulesId === '') {
                $erulesId = null;
            }

            $sortOrder = (int) ($frame->sortOrder ?? 0);

            $crumbParts = [];
            foreach ($candidateUidStack as $ancestorUid) {
                if (isset($titleByUid[$ancestorUid])) {
                    $crumbParts[] = mb_substr($titleByUid[$ancestorUid], 0, 180);
                }
            }
            $breadcrumb = implode(' › ', $crumbParts);
            if ($title !== '') {
                $breadcrumb = $breadcrumb !== '' ? ($breadcrumb . ' › ' . mb_substr($title, 0, 180)) : mb_substr($title, 0, 250);
            }

            $pathParts = $crumbParts;
            $pathParts[] = $erulesId ?? mb_substr(preg_replace('#\s+#u', '_', $title) ?: $title, 0, 120);
            $pathStr = implode('/', array_filter($pathParts, static function ($x) {
                return $x !== '';
            }));

            $forcedFragment = isset($frame->forcedFragment) ? trim((string) $frame->forcedFragment) : '';
            if ($forcedFragment !== '') {
                $frag = strlen($forcedFragment) > EASA_ERULES_XML_FRAGMENT_STORE_MAX
                    ? substr($forcedFragment, 0, EASA_ERULES_XML_FRAGMENT_STORE_MAX) . "\n<!-- … truncated -->"
                    : $forcedFragment;
            } else {
                $openTag = '<' . htmlspecialchars($local, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                foreach ($attrs as $ak => $av) {
                    $openTag .= ' ' . htmlspecialchars((string) $ak, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                        . '="' . htmlspecialchars((string) $av, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
                }
                $openTag .= '>';
                $frag = strlen($openTag) > EASA_ERULES_XML_FRAGMENT_MAX
                    ? substr($openTag, 0, EASA_ERULES_XML_FRAGMENT_MAX) . "\n<!-- … -->"
                    : $openTag;
            }

            $meta = [
                'localName' => $local,
                'namespaceURI' => $frame->namespaceUri,
                'attributes' => $attrs,
                'import_mode' => $forcedFragment !== '' ? 'xmlreader_stream_topic_outerxml' : 'xmlreader_stream',
            ];

            $structuredBlocksPayload = null;
            if ($hasStructuredBlocksCol) {
                if ($forcedFragment !== '') {
                    $structuredBlocksPayload = easa_erules_structured_blocks_json_from_outer_xml($forcedFragment);
                }
            }

            $canonicalInit = easa_erules_body_canonical_for_hash($plain);
            $hash = easa_erules_staging_content_hash($canonicalInit, $erulesId, $local);

            $vals = [
                $batchId,
                $uid,
                $erulesId,
                $local,
                $parentUid,
                $sortOrder,
                $frame->xmlDepth,
                $pathStr !== '' ? mb_substr($pathStr, 0, 4000) : null,
                $breadcrumb !== '' ? $breadcrumb : null,
                $title !== '' ? $title : null,
                $sourceTitle !== null && $sourceTitle !== '' ? mb_substr($sourceTitle, 0, 2000) : null,
                $plain,
            ];
            if ($hasCanonicalCol) {
                $vals[] = $canonicalInit !== '' ? $canonicalInit : null;
            }
            if ($hasStructuredBlocksCol) {
                $vals[] = $structuredBlocksPayload;
            }
            $vals[] = $frag;
            $vals[] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $vals[] = $hash;

            $ins->execute($vals);
            $imported++;
            $flushProgress($local, 'Inserted ' . $imported . ' nodes · last: <' . $local . '>');

            if (strtolower($local) === 'document') {
                $publicationMeta = [
                    'element' => 'document',
                    'attributes' => $attrs,
                    'namespaceURI' => $frame->namespaceUri,
                ];
            }

            if ($stack) {
                $top = count($stack) - 1;
                $stack[$top]['chunks'][] = $plain;
            }

            return;
        }

        if ($stack) {
            $top = count($stack) - 1;
            if ($plain !== '') {
                $stack[$top]['chunks'][] = $plain;
            }
        }
    };

    try {
        while ($reader->read()) {
            $type = $reader->nodeType;
            if ($type === XMLReader::ELEMENT) {
                $attrs = easa_erules_reader_collect_attributes($reader);
                $ln = $reader->localName;
                $ns = $reader->namespaceURI ?? '';
                $isCand = easa_erules_element_is_candidate_attrs($ln, $attrs);
                $depthIdx = count($stack);

                $parentCandUid = null;
                if ($isCand && count($candidateUidStack) > 0) {
                    $parentCandUid = $candidateUidStack[count($candidateUidStack) - 1];
                }

                $sortOrder = 0;
                if ($isCand) {
                    $pk = easa_erules_parent_sort_key($parentCandUid);
                    $sortOrder = $siblingCount[$pk] ?? 0;
                    $siblingCount[$pk] = $sortOrder + 1;
                    $seq++;
                    $candUid = 'b' . $batchId . '_n' . $seq;
                    $candidateUidStack[] = $candUid;
                    $pre = easa_erules_pick_title_attrs_only($attrs);
                    if ($pre !== '') {
                        $titleByUid[$candUid] = $pre;
                    }
                } else {
                    $candUid = null;
                }

                $frame = (object) [
                    'localName' => $ln,
                    'attrs' => $attrs,
                    'namespaceUri' => is_string($ns) ? $ns : '',
                    'isCandidate' => $isCand,
                    'candidateUid' => $candUid,
                    'parentCandidateUid' => $parentCandUid,
                    'sortOrder' => $sortOrder,
                    'xmlDepth' => $depthIdx + 1,
                ];

                $isTopicCandidate = $isCand && strtolower((string) $ln) === 'topic';
                if ($isTopicCandidate) {
                    $outer = $reader->readOuterXml();
                    if (is_string($outer) && trim($outer) !== '') {
                        $pc = easa_erules_plain_canonical_from_outer_xml($outer);
                        $frame->forcedPlain = $pc['plain'];
                        $frame->forcedFragment = $outer;
                    }
                    $stack[] = ['frame' => $frame, 'chunks' => []];
                    $popFrame();
                    continue;
                }

                $stack[] = ['frame' => $frame, 'chunks' => []];

                if ($reader->isEmptyElement) {
                    $popFrame();
                }
            } elseif ($type === XMLReader::TEXT || $type === XMLReader::CDATA) {
                $v = $reader->value;
                if ($v !== '' && count($stack) > 0) {
                    $ti = count($stack) - 1;
                    $stack[$ti]['chunks'][] = $v;
                }
            } elseif (defined('XMLReader::SIGNIFICANT_WHITESPACE') && $type === XMLReader::SIGNIFICANT_WHITESPACE) {
                $v = $reader->value;
                if (trim($v) !== '' && count($stack) > 0) {
                    $ti = count($stack) - 1;
                    $stack[$ti]['chunks'][] = $v;
                }
            } elseif ($type === XMLReader::END_ELEMENT) {
                $popFrame();
            }
        }
    } finally {
        $reader->close();
    }

    easa_erules_reparent_structural_heading_children($pdo, $batchId);
    easa_erules_reparent_annex_lift_toc_wrapper_children($pdo, $batchId);
    easa_erules_reparent_annex_subpart_appendix_headings($pdo, $batchId);
    easa_erules_promote_misnested_fcl_peers($pdo, $batchId);
    easa_erules_reparent_rule_ref_toc_peer_lift($pdo, $batchId);

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                parse_phase = ?,
                parse_detail = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute(['enriching', 'Merging full topic XML by ERulesId (readOuterXml pass)…', $batchId]);
    }

    easa_erules_enrich_staging_from_source_outer_xml($pdo, $batchId, $abs);

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                parse_phase = ?,
                parse_detail = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute(['sdt_merge', 'Merging OOXML <w:sdt> bodies keyed by topic sdt-id…', $batchId]);
    }

    easa_erules_enrich_topics_from_word_sdt_index($pdo, $batchId, easa_erules_word_sdt_plain_index($abs));

    return [
        'imported' => $imported,
        'publication_meta' => $publicationMeta,
    ];
}

/**
 * Persist successful parse outcome on the batch row (call after easa_erules_import_batch_xml_to_staging).
 *
 * @param array<string, mixed>|null $publicationMeta
 */
function easa_erules_import_finalize_success(PDO $pdo, int $batchId, int $imported, ?array $publicationMeta): void
{
    $pubJson = $publicationMeta !== null
        ? json_encode($publicationMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                status = \'ready_for_review\',
                rows_detected = ?,
                publication_meta_json = COALESCE(?, publication_meta_json),
                error_message = NULL,
                parse_finished_at = UTC_TIMESTAMP(),
                parse_phase = \'completed\',
                parse_rows_so_far = ?,
                parse_detail = ?,
                parse_last_node_type = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([
            $imported,
            $pubJson,
            $imported,
            'Completed: ' . $imported . ' regulatory nodes in staging.',
            $batchId,
        ]);

        return;
    }

    $pdo->prepare('
        UPDATE easa_erules_import_batches SET
            status = \'ready_for_review\',
            rows_detected = ?,
            publication_meta_json = COALESCE(?, publication_meta_json),
            error_message = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ')->execute([$imported, $pubJson, $batchId]);
}

function easa_erules_import_finalize_failure(PDO $pdo, int $batchId, string $message): void
{
    $detail = mb_substr($message, 0, 500);

    if (easa_erules_batch_progress_available($pdo)) {
        $pdo->prepare('
            UPDATE easa_erules_import_batches SET
                status = \'failed\',
                error_message = ?,
                parse_finished_at = UTC_TIMESTAMP(),
                parse_phase = \'failed\',
                parse_detail = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([$message, $detail, $batchId]);

        return;
    }

    $pdo->prepare('UPDATE easa_erules_import_batches SET status = \'failed\', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$message, $batchId]);
}

/**
 * Strip Word TOC / merge-field noise often embedded in Easy Access XML text nodes.
 */
function easa_erules_sanitize_display_text(string $s): string
{
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\\\\\*+\s*MERGEFORMAT\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/DATE\s*\\\\@[^"\s]*\s*"[^"]*"\s*(?:\\\\\*+\s*MERGEFORMAT\s*)?[^\s"\\\\]*/iu', '', $s) ?? $s;
    $s = preg_replace('/PAGEREF\s+_[A-Za-z0-9]+\s*\\\\[a-z]+\s*\d*/iu', '', $s) ?? $s;
    $s = preg_replace('/HYPERLINK\s+\\\\l\s+"[^"]*"\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/\\s{2,}/u', ' ', $s) ?? $s;

    return trim($s);
}

/**
 * Sanitize rule body for display but keep line breaks (Easy Access exports can be multi-paragraph).
 */
function easa_erules_sanitize_rule_body_text(string $s): string
{
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\\\\\*+\s*MERGEFORMAT\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/DATE\s*\\\\@[^"\s]*\s*"[^"]*"\s*(?:\\\\\*+\s*MERGEFORMAT\s*)?[^\s"\\\\]*/iu', '', $s) ?? $s;
    $s = preg_replace('/PAGEREF\s+_[A-Za-z0-9]+\s*\\\\[a-z]+\s*\d*/iu', '', $s) ?? $s;
    $s = preg_replace('/HYPERLINK\s+\\\\l\s+"[^"]*"\s*/iu', '', $s) ?? $s;
    $parts = preg_split('/\R/u', $s) ?: [];
    $out = [];
    foreach ($parts as $line) {
        $line = trim((string) preg_replace('/[ \t\f]+/u', ' ', $line) ?? '');
        if ($line !== '') {
            $out[] = $line;
        }
    }

    return implode("\n", $out);
}

/**
 * When a parent topic/heading row has empty plain_text (text stored only on child nodes), build body from descendants.
 */
function easa_erules_aggregate_descendant_plain_text(PDO $pdo, int $batchId, string $parentNodeUid, int $depth = 0): string
{
    if ($depth > 120) {
        return '';
    }
    $st = $pdo->prepare('
        SELECT node_uid, title, plain_text, sort_order
        FROM easa_erules_import_nodes_staging
        WHERE batch_id = ? AND parent_node_uid = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $st->execute([$batchId, $parentNodeUid]);
    $blocks = [];
    $childCount = 0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        if (++$childCount > 2000) {
            break;
        }
        $uid = (string) ($row['node_uid'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $own = trim((string) ($row['plain_text'] ?? ''));
        if ($own === '' && $uid !== '') {
            $own = trim(easa_erules_aggregate_descendant_plain_text($pdo, $batchId, $uid, $depth + 1));
        }
        if ($own === '') {
            continue;
        }
        if ($title !== '') {
            $blocks[] = $title . "\n\n" . $own;
        } else {
            $blocks[] = $own;
        }
    }

    return implode("\n\n", $blocks);
}

/**
 * When staging plain_text missed OOXML-in-topic content, pull text from the batch's stored source.xml
 * by locating the element whose ERulesId matches (first occurrence).
 */
function easa_erules_extract_plain_text_from_source_xml_by_erules_id(string $absoluteXmlPath, string $erulesId): string
{
    $want = trim($erulesId);
    if ($want === '' || !is_file($absoluteXmlPath) || !is_readable($absoluteXmlPath)) {
        return '';
    }
    $wantKey = easa_erules_id_match_key($want);
    if ($wantKey === '') {
        return '';
    }
    $reader = new XMLReader();
    if (!$reader->open($absoluteXmlPath, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
        return '';
    }
    $bestText = '';
    $bestScore = -1;
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            $attrs = easa_erules_reader_collect_attributes($reader);
            $erRaw = easa_erules_attr_ci($attrs, 'ERulesId');
            $er = trim((string) ($erRaw ?? ''));
            if (easa_erules_id_match_key($er) !== $wantKey) {
                continue;
            }
            $outer = $reader->readOuterXml();
            if (!is_string($outer) || $outer === '') {
                continue;
            }
            $pc = easa_erules_plain_canonical_from_outer_xml($outer);
            $plain = trim($pc['plain']);
            $score = strlen($plain) + (strcasecmp((string) $reader->localName, 'topic') === 0 ? 20000 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestText = $pc['plain'];
            }
        }
    } finally {
        $reader->close();
    }

    return $bestText;
}

/**
 * Runtime diagnostic: inspect source.xml candidates that match a given ERulesId.
 *
 * @return array<string,mixed>
 */
function easa_erules_probe_source_candidates_by_erules_id(string $absoluteXmlPath, string $erulesId, int $limit = 40): array
{
    $want = trim($erulesId);
    $wantKey = easa_erules_id_match_key($want);
    $out = [
        'xml_path' => $absoluteXmlPath,
        'xml_exists' => is_file($absoluteXmlPath),
        'xml_readable' => is_readable($absoluteXmlPath),
        'erules_id' => $want,
        'erules_key' => $wantKey,
        'matches' => [],
        'match_count' => 0,
    ];
    if ($wantKey === '' || !is_file($absoluteXmlPath) || !is_readable($absoluteXmlPath)) {
        return $out;
    }
    $limit = max(1, min(200, $limit));

    $reader = new XMLReader();
    if (!$reader->open($absoluteXmlPath, null, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT)) {
        return $out;
    }

    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            $attrs = easa_erules_reader_collect_attributes($reader);
            $erRaw = easa_erules_attr_ci($attrs, 'ERulesId');
            $er = trim((string) ($erRaw ?? ''));
            if (easa_erules_id_match_key($er) !== $wantKey) {
                continue;
            }
            $outer = $reader->readOuterXml();
            if (!is_string($outer) || $outer === '') {
                continue;
            }
            $pc = easa_erules_plain_canonical_from_outer_xml($outer);
            $trimPlain = trim($pc['plain']);
            $out['matches'][] = [
                'local_name' => (string) $reader->localName,
                'outer_len' => strlen($outer),
                'plain_len' => strlen($trimPlain),
                'plain_head' => substr($trimPlain, 0, 220),
                'has_closing_tag' => str_contains($outer, '</'),
                'sdt_id' => easa_erules_attr_ci($attrs, 'sdt-id'),
                'source_title' => easa_erules_attr_ci($attrs, 'source-title'),
            ];
            if (count($out['matches']) >= $limit) {
                break;
            }
        }
    } finally {
        $reader->close();
    }

    $out['match_count'] = count($out['matches']);

    return $out;
}

/**
 * Resolve absolute path to batch source.xml for on-demand extraction.
 */
function easa_erules_batch_source_xml_absolute_path(PDO $pdo, int $batchId): ?string
{
    if ($batchId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT storage_relpath FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
        $st->execute([$batchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    $rel = is_array($row) ? trim((string) ($row['storage_relpath'] ?? '')) : '';
    if ($rel !== '') {
        $abs = rl_project_root() . '/' . str_replace('\\', '/', $rel);
        if (is_file($abs)) {
            return $abs;
        }
    }
    $fallback = rl_project_root() . '/' . easa_erules_batch_relative_path($batchId);
    if (is_file($fallback)) {
        return $fallback;
    }

    return null;
}

/**
 * Insert paragraph breaks into collapsed rule text so the browse UI reads naturally.
 *
 * EU / EASA Easy Access exports often emit one long line (canonical) or tight glue; this pass
 * adds breaks before known structural markers and after regulation citations.
 */
function easa_erules_format_body_for_reading(string $text): string
{
    $s = trim($text);
    if ($s === '') {
        return '';
    }
    $s = str_replace("\xc2\xa0", ' ', $s);
    $hadNewlines = str_contains($s, "\n");
    if (!$hadNewlines) {
        $s = trim(preg_replace('/[ \t]+/u', ' ', $s) ?? $s);
    }

    $rules = [
        fn (string $x): string => preg_replace('/\)\s*\[/u', ")\n\n[", $x) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{L}\p{N}\)\]])(\s*|)(?=Article\s+\d+)/u',
            "\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{L}\p{N}\)\]])(\s*|)(?=Annex\s+[IVX\d]+)/iu',
            "\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{L}\p{N}\)\]])(\s*|)(?=SUBPART\s+[A-Z]|SECTION\s+\d+)/u',
            "\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{L}\p{N}\)\]])(\s*|)(?=(?:Commission\s+)?(?:Delegated\s+)?Regulation\s*\(\s*EU\s*\)|Regulation\s*\(\s*EU\s*\)\s*(?:No\s*)?\d+\/\d+|Decision\s*\(\s*EU\s*\)\s*\d+\/\d+|Implementing\s+Regulation)/iu',
            "\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/((?:Commission\s+)?(?:Delegated\s+)?Regulation\s*\(\s*EU\s*\)[^.\n]{0,160}|Regulation\s*\(\s*EU\s*\)\s*(?:No\s*)?\d+\/\d+[^.\n]{0,24}|Decision\s*\(\s*EU\s*\)\s*\d+\/\d+[^.\n]{0,24})\.(?!\d)/iu',
            '$1.' . "\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{Ll}\p{Lo}0-9\)\]])(\(([1-9]\d{0,2}|0)\))(?=\s|\(|\.|[\p{Lu}\p{Ll}])/u',
            "\n\n$1",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace('/;\s+(?=\([a-z]\))(?=\S)/u', ";\n\n", $x) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{Ll}\p{Lo}0-9\)\]])(\([a-z]\))(?=\S)/u',
            "\n\n$1",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\p{Ll}\p{Lo}\)\]])(?<!\d)\s*(\d{1,2})\.(?=\s+[\p{Lu}(])/u',
            "\n\n$1.",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/(?<=[\.\;\:\)\]])(\s*|)(?=AMC\d*\b|GM\s+\d|\bGM\s)/iu',
            "\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/([\.\;\:])(\s+|)(?=The\b|Applicants\b|Notwithstanding\b|Unless\b|Where\b|Whenever\b|However\b|Failure\b|Moreover\b|In\s+addition\b|Without\s+prejudice\b|For\s+the\s+purposes\b|Compliance\b|Demonstration\b|Demonstrate\b|The\s+[A-Za-z]+\s+(?:requirements?|authority|agency)\b)/u',
            "$1\n\n",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/([\.\;\)])(\(([1-9]\d{0,2}|0)\))(?=\s|\(|\.|[\p{Lu}\p{Ll}])/u',
            "$1\n\n$2",
            $x
        ) ?? $x,
        fn (string $x): string => preg_replace(
            '/([\.\;\)])(\([a-z]\))(?=\S)/u',
            "$1\n\n$2",
            $x
        ) ?? $x,
    ];

    foreach ($rules as $fn) {
        $s = $fn($s);
    }

    $s = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $s) ?? $s;
    $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;

    $lines = preg_split('/\R+/u', $s) ?: [];
    $acc = '';
    foreach ($lines as $line) {
        $line = trim(preg_replace('/[ \t]{2,}/u', ' ', (string) $line) ?? (string) $line);
        if ($line === '') {
            continue;
        }
        $acc .= ($acc === '' ? '' : "\n\n") . $line;
    }

    return $acc;
}

/**
 * Short label for tree rows (sanitized, max length).
 */
function easa_erules_short_tree_label(array $n): string
{
    $raw = trim((string) ($n['title'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($n['first_child_title'] ?? ''));
    }
    if ($raw === '') {
        $raw = trim((string) ($n['first_child_source_title'] ?? ''));
    }
    if ($raw === '') {
        $raw = trim((string) ($n['source_erules_id'] ?? ''));
    }
    if ($raw === '') {
        $raw = trim((string) ($n['source_title'] ?? ''));
    }
    $nt = strtolower(trim((string) ($n['node_type'] ?? '')));
    if ($raw === '') {
        if (in_array($nt, ['toc', 'document', 'frontmatter', 'backmatter'], true)) {
            $raw = ucfirst($nt === 'toc' ? 'Table of contents' : $nt);
        } else {
            $raw = (string) ($n['node_uid'] ?? '');
        }
    }
    $s = easa_erules_sanitize_display_text($raw);
    if ($s === '') {
        $s = (string) ($n['node_type'] ?? '—');
    }
    if (strlen($s) > 180) {
        $s = substr($s, 0, 177) . '…';
    }

    return $s;
}

/**
 * UI colour band aligned with EASA Easy Access: IR (blue), AMC (amber), GM (green), wrappers (slate).
 *
 * @return 'ir'|'amc'|'gm'|'neu'
 */
function easa_erules_classify_display_band(?string $nodeType, ?string $title, ?string $sourceTitle, ?string $erulesId): string
{
    $nt = strtolower(trim((string) $nodeType));
    if (in_array($nt, ['document', 'frontmatter', 'backmatter'], true)) {
        return 'neu';
    }
    $blob = trim((string) $title . "\n" . (string) $sourceTitle . "\n" . (string) $erulesId);
    $blobOneLine = preg_replace('/\s+/u', ' ', $blob) ?? $blob;
    // <toc> rows are often navigation wrappers; still classify GM/AMC from title (was wrongly forced to neu).
    if ($nt === 'toc') {
        if (preg_match('/^\s*AMC\d*\b/iu', $blobOneLine)) {
            return 'amc';
        }
        if (preg_match('/^\s*GM\d*\b/iu', $blobOneLine)) {
            return 'gm';
        }
        if (preg_match('/(?i)\bacceptable\s+means\s+of\s+compliance\b/', $blobOneLine)) {
            return 'amc';
        }
        if (preg_match('/(?i)\bguidance\s+material\b/', $blobOneLine) && preg_match('/(?i)\bGM\d*\b/', $blobOneLine)) {
            return 'gm';
        }

        return 'neu';
    }
    if (preg_match('/^\s*AMC\d*\b/iu', $blobOneLine)) {
        return 'amc';
    }
    if (preg_match('/^\s*GM\d*\b/iu', $blobOneLine)) {
        return 'gm';
    }
    if (preg_match('/(?i)\bacceptable\s+means\s+of\s+compliance\b/', $blobOneLine)) {
        return 'amc';
    }
    if (preg_match('/(?i)\bguidance\s+material\b/', $blobOneLine) && preg_match('/(?i)\bGM\d*\b/', $blobOneLine)) {
        return 'gm';
    }

    return 'ir';
}

/**
 * True when text mentions a codified rule id token (FCL / ORA / CAT / DTO / ARA / MED).
 */
function easa_erules_tree_blob_has_ir_reference(string $text): bool
{
    $text = trim($text);

    return $text !== '' && preg_match(
        '/\b(?:FCL\.\d+[A-Z]?|(?:ORA|CAT|DTO|ARA|MED)(?:\.[A-Z0-9]+)+)\b/iu',
        $text
    ) === 1;
}

/**
 * Primary rule key from a nav title for duplicate-toc / supplement grouping (FCL.010, ORA.GEN.200, CAT.x.y…).
 */
function easa_erules_tree_nav_rule_key(?string $title): ?string
{
    $line = easa_erules_tree_title_first_line((string) $title);
    if ($line === '') {
        return null;
    }
    if (preg_match('/\b(FCL\.\d+[A-Z]?)\b/iu', $line, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\b(CAT(?:\.[A-Z0-9]+)+)\b/iu', $line, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\b(ORA(?:\.[A-Z0-9]+)+)\b/iu', $line, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\b(DTO(?:\.[A-Z0-9]+)+)\b/iu', $line, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\b(ARA(?:\.[A-Z0-9]+)+)\b/iu', $line, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\b(MED(?:\.[A-Z0-9]+)+)\b/iu', $line, $m)) {
        return strtoupper($m[1]);
    }

    return null;
}

/**
 * Rule stub from any title/source/ERulesId field (multi-line titles, sparse toc rows).
 */
function easa_erules_tree_nav_rule_key_from_row(array $r): ?string
{
    $chunks = [
        trim((string) ($r['title'] ?? '')),
        trim((string) ($r['source_title'] ?? '')),
        trim((string) ($r['source_erules_id'] ?? '')),
    ];
    foreach ($chunks as $blob) {
        if ($blob === '') {
            continue;
        }
        $lines = preg_split('/\R+/u', $blob) ?: [];
        foreach ($lines as $ln) {
            $k = easa_erules_tree_nav_rule_key(trim($ln));
            if ($k !== null) {
                return $k;
            }
        }
        $k2 = easa_erules_tree_nav_rule_key($blob);
        if ($k2 !== null) {
            return $k2;
        }
    }

    return null;
}

/**
 * Legal navigation semantics for tree_children (title-led; independent of XML local name except wrappers).
 *
 * @return array{ui_kind: 'section'|'rule', material_type: 'IR'|'AMC'|'GM'|'HEADING'}
 */
function easa_erules_tree_semantic_nav_classify(array $row): array
{
    $title = trim((string) ($row['title'] ?? ''));
    $sourceTitle = trim((string) ($row['source_title'] ?? ''));
    $erules = trim((string) ($row['source_erules_id'] ?? ''));
    $nt = strtolower(trim((string) ($row['node_type'] ?? '')));

    if ($title !== '' && easa_erules_tree_title_is_structural_section($title)) {
        return ['ui_kind' => 'section', 'material_type' => 'HEADING'];
    }
    if ($title === '' && $sourceTitle !== '' && easa_erules_tree_title_is_structural_section($sourceTitle)) {
        return ['ui_kind' => 'section', 'material_type' => 'HEADING'];
    }

    // A — Row material type from **own** title/source/erules only: codified implementing rule beats GM/AMC.
    if (easa_erules_row_own_fields_any_line_leads_codified_ir($row)) {
        return ['ui_kind' => 'rule', 'material_type' => 'IR'];
    }

    // B — AMC/GM from own fields only (never from first_child_* on populated IR toc/topic rows).
    if (easa_erules_row_own_fields_indicate_gm_amc_supplement($row)) {
        $band = easa_erules_classify_display_band(
            $row['node_type'] ?? null,
            $title !== '' ? $title : null,
            $sourceTitle !== '' ? $sourceTitle : null,
            $erules !== '' ? $erules : null
        );
        if ($band !== 'amc' && $band !== 'gm') {
            $ln = easa_erules_tree_row_own_primary_navigation_line($row);
            $band = (preg_match('/^\s*AMC\d*\b/iu', $ln) === 1) ? 'amc' : 'gm';
        }

        return ['ui_kind' => 'rule', 'material_type' => $band === 'amc' ? 'AMC' : 'GM'];
    }

    // C — Bare toc/heading shells: classify from **first_child** labels only when own fields are empty.
    if (easa_erules_tree_row_own_label_fields_empty($row) && in_array($nt, ['toc', 'heading'], true)) {
        $fc = trim((string) ($row['first_child_title'] ?? ''));
        if ($fc === '') {
            $fc = trim((string) ($row['first_child_source_title'] ?? ''));
        }
        if ($fc !== '') {
            foreach (preg_split('/\R+/u', $fc) ?: [] as $fcLn) {
                $fcLn = trim((string) $fcLn);
                if ($fcLn !== '' && easa_erules_tree_line_leads_codified_ir_rule($fcLn)) {
                    return ['ui_kind' => 'rule', 'material_type' => 'IR'];
                }
                if ($fcLn !== '' && easa_erules_tree_title_starts_gm_or_amc($fcLn)) {
                    $mtFc = (preg_match('/^\s*AMC\d*\b/iu', $fcLn) === 1) ? 'AMC' : 'GM';

                    return ['ui_kind' => 'rule', 'material_type' => $mtFc];
                }
            }
        }
    }

    foreach ([$title, $sourceTitle, $erules] as $chunk) {
        if ($chunk !== '' && easa_erules_tree_blob_has_ir_reference($chunk)) {
            return ['ui_kind' => 'rule', 'material_type' => 'IR'];
        }
    }

    if (in_array($nt, ['document', 'frontmatter', 'backmatter'], true)) {
        return ['ui_kind' => 'section', 'material_type' => 'HEADING'];
    }

    if ($nt === 'topic') {
        return ['ui_kind' => 'rule', 'material_type' => 'IR'];
    }

    if (in_array($nt, ['heading', 'toc'], true)) {
        $probe = $title !== '' ? $title : $sourceTitle;
        if ($probe === '' && easa_erules_tree_row_own_label_fields_empty($row) && isset($row['first_child_title'])) {
            $probe = trim((string) $row['first_child_title']);
        }
        if ($probe === '' && easa_erules_tree_row_own_label_fields_empty($row) && isset($row['first_child_source_title'])) {
            $probe = trim((string) $row['first_child_source_title']);
        }
        if ($probe !== '' && easa_erules_tree_blob_has_ir_reference($probe)) {
            return ['ui_kind' => 'rule', 'material_type' => 'IR'];
        }

        return ['ui_kind' => 'section', 'material_type' => 'HEADING'];
    }

    return ['ui_kind' => 'rule', 'material_type' => 'IR'];
}

/**
 * IR browse rows expand only to reveal AMC/GM (not nested peer IR rule lines).
 *
 * @param list<array<string, mixed>> $directChildStagingRows Visible flattened children (staging shape).
 */
function easa_erules_tree_ir_expandable_for_amc_gm_children_only(array $directChildStagingRows): bool
{
    if ($directChildStagingRows === []) {
        return false;
    }
    foreach ($directChildStagingRows as $c) {
        if (!is_array($c)) {
            continue;
        }
        $cl = easa_erules_tree_semantic_nav_classify($c);
        if ($cl['material_type'] === 'AMC' || $cl['material_type'] === 'GM') {
            continue;
        }
        if ($cl['ui_kind'] === 'section') {
            return false;
        }
        if ($cl['ui_kind'] === 'rule' && $cl['material_type'] === 'IR') {
            return false;
        }
    }

    return true;
}

/**
 * Semantic contract for GET tree_children (no raw staging columns).
 *
 * @param array<string, mixed> $row Staging row plus computed child_count
 * @param list<array<string, mixed>> $directChildStagingRows Visible children for expandable inference
 *
 * @return array{
 *   id: string,
 *   parent_id: ?string,
 *   display_title: string,
 *   ui_kind: 'section'|'rule',
 *   material_type: 'IR'|'AMC'|'GM'|'HEADING',
 *   expandable: bool,
 *   click_action: 'expand'|'open_rule',
 *   depth: int,
 *   sort_order: int,
 *   child_count: int,
 *   node_type: string
 * }
 */
function easa_erules_tree_semantic_adapter(array $row, array $directChildStagingRows = []): array
{
    $uid = trim((string) ($row['node_uid'] ?? ''));
    $pp = $row['parent_node_uid'] ?? null;
    $parentId = ($pp !== null && trim((string) $pp) !== '') ? trim((string) $pp) : null;
    $childCount = (int) ($row['child_count'] ?? 0);
    $displayTitle = easa_erules_short_tree_label($row);

    $class = easa_erules_tree_semantic_nav_classify($row);
    $uiKind = $class['ui_kind'];
    $mt = $class['material_type'];

    if ($uiKind === 'section') {
        return [
            'id' => $uid,
            'parent_id' => $parentId,
            'display_title' => $displayTitle,
            'ui_kind' => 'section',
            'material_type' => 'HEADING',
            'expandable' => $childCount > 0,
            'click_action' => 'expand',
            'depth' => (int) ($row['depth'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'child_count' => $childCount,
            'node_type' => strtolower(trim((string) ($row['node_type'] ?? ''))),
        ];
    }

    $expandable = false;
    if ($childCount > 0) {
        if ($mt === 'IR') {
            $expandable = easa_erules_tree_ir_expandable_for_amc_gm_children_only($directChildStagingRows);
        } else {
            $expandable = !in_array($mt, ['GM', 'AMC'], true);
        }
    }

    return [
        'id' => $uid,
        'parent_id' => $parentId,
        'display_title' => $displayTitle,
        'ui_kind' => 'rule',
        'material_type' => $mt,
        'expandable' => $expandable,
        'click_action' => 'open_rule',
        'depth' => (int) ($row['depth'] ?? 0),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'child_count' => $childCount,
        'node_type' => strtolower(trim((string) ($row['node_type'] ?? ''))),
    ];
}

function easa_erules_tree_title_first_line(?string $title): string
{
    $title = trim((string) $title);
    if ($title === '') {
        return '';
    }
    $parts = preg_split('/\R+/u', $title) ?: [];

    return trim((string) ($parts[0] ?? $title));
}

/**
 * True for annex / subpart / section style headings (not FCL.xxx rule lines).
 */
function easa_erules_tree_title_is_structural_section(?string $title): bool
{
    $line = easa_erules_tree_title_first_line($title);

    return $line !== '' && preg_match(
        '/^\s*(ANNEX|SUBPART|SECTION|APPENDIX|CHAPTER|TITLE|PART)\b/iu',
        $line
    ) === 1;
}

function easa_erules_tree_title_starts_gm_or_amc(?string $title): bool
{
    $line = easa_erules_tree_title_first_line($title);
    if ($line === '') {
        return false;
    }

    return preg_match('/^\s*GM\d*\b/iu', $line) === 1 || preg_match('/^\s*AMC\d*\b/iu', $line) === 1;
}

/**
 * First (FCL|ORA|CAT).digits optional letter suffix in title, uppercased e.g. FCL.010, ORA.GEN.abc skip — keep simple: FCL.\d+[A-Z]?
 */
function easa_erules_tree_extract_rule_stub(?string $title): ?string
{
    $line = easa_erules_tree_title_first_line($title);
    if ($line === '') {
        return null;
    }
    if (preg_match('/\b(FCL|ORA|CAT)\.(\d+)([A-Z])?\b/i', $line, $m)) {
        return strtoupper($m[1] . '.' . $m[2] . ($m[3] ?? ''));
    }

    return null;
}

/**
 * True when every direct child is GM/AMC material or repeats the same primary rule stub as the parent
 * (typical FCL.xxx toc whose children are GM/AMC topics or a duplicate FCL.xxx topic).
 */
function easa_erules_tree_children_all_supplements_for_rule_stub(string $parentTitle, array $childRows): bool
{
    $ps = easa_erules_tree_nav_rule_key($parentTitle);
    if ($ps === null) {
        return false;
    }
    foreach ($childRows as $c) {
        if (!is_array($c)) {
            return false;
        }
        $ct = easa_erules_tree_title_first_line((string) ($c['title'] ?? ''));
        if ($ct === '' && isset($c['source_title'])) {
            $ct = easa_erules_tree_title_first_line((string) $c['source_title']);
        }
        if ($ct === '') {
            return false;
        }
        if (easa_erules_tree_title_starts_gm_or_amc($ct)) {
            continue;
        }
        $cs = easa_erules_tree_nav_rule_key($ct);
        if ($cs === null) {
            return false;
        }
        if ($cs !== $ps) {
            return false;
        }
    }

    return $childRows !== [];
}

/**
 * heading/toc rows that duplicate a rule nav shell (FCL.xxx / GMx / AMCx) should be omitted from the tree
 * when their children are lifted to the grandparent, except real structural sections (ANNEX, SUBPART, …).
 *
 * @param list<array<string, mixed>> $childRows direct children of $row (already loaded)
 */
function easa_erules_tree_should_flatten_nav_wrapper(array $row, array $childRows): bool
{
    $nt = strtolower(trim((string) ($row['node_type'] ?? '')));
    if (!in_array($nt, ['heading', 'toc'], true)) {
        return false;
    }
    if ($childRows === []) {
        return false;
    }
    $title = (string) ($row['title'] ?? '');
    if (easa_erules_tree_title_is_structural_section($title)) {
        return false;
    }
    if (easa_erules_tree_title_starts_gm_or_amc($title)) {
        return true;
    }
    // IR-style rule line (FCL / ORA / CAT) — flatten when children are not "only" supplements of this stub.
    $line = easa_erules_tree_title_first_line($title);
    if ($line === '' || !easa_erules_tree_blob_has_ir_reference($line)) {
        return false;
    }
    if (easa_erules_tree_children_all_supplements_for_rule_stub($title, $childRows)) {
        // Same-stub duplicate IR shell (toc + lone topic) — still omit wrapper so only the topic row shows.
        if (count($childRows) === 1) {
            $c0 = $childRows[0];
            if (!is_array($c0)) {
                return false;
            }
            if (strtolower(trim((string) ($c0['node_type'] ?? ''))) === 'topic') {
                $ct = easa_erules_tree_title_first_line((string) ($c0['title'] ?? ''));
                if (!easa_erules_tree_title_starts_gm_or_amc($ct) && !easa_erules_tree_title_starts_gm_or_amc($line)) {
                    $ps = easa_erules_tree_nav_rule_key($title);
                    $cs = easa_erules_tree_nav_rule_key($ct);
                    if ($ps !== null && $cs !== null && $ps === $cs) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    return true;
}

/**
 * @param list<array<string, mixed>> $rows
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_dedupe_adjacent_wrapper_topic(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        if ($out === []) {
            $out[] = $r;
            continue;
        }
        $prev = $out[count($out) - 1];
        $a = strtolower(trim(easa_erules_short_tree_label($prev)));
        $b = strtolower(trim(easa_erules_short_tree_label($r)));
        if ($a !== '' && $a === $b) {
            $pt = strtolower(trim((string) ($prev['node_type'] ?? '')));
            $nt = strtolower(trim((string) ($r['node_type'] ?? '')));
            if ($pt !== 'topic' && $nt === 'topic') {
                $out[count($out) - 1] = $r;

                continue;
            }
            if ($pt === 'topic' && $nt !== 'topic') {
                continue;
            }
        }
        $out[] = $r;
    }

    return $out;
}

/**
 * In-memory adjacency for one batch — avoids N+1 SQL during tree flatten (was freezing the UI on large imports).
 *
 * @return array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>}
 */
function easa_erules_tree_batch_graph(PDO $pdo, int $batchId): array
{
    static $cache = [];
    if ($batchId <= 0) {
        return ['by_parent' => [], 'by_uid' => []];
    }
    if (isset($cache[$batchId])) {
        return $cache[$batchId];
    }
    $st = $pdo->prepare(
        'SELECT batch_id, node_uid, parent_node_uid, node_type, sort_order, id, depth,
                source_erules_id, title, source_title, breadcrumb
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$batchId]);
    $byParent = [];
    $byUid = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $uid = trim((string) ($r['node_uid'] ?? ''));
        if ($uid === '') {
            continue;
        }
        $pp = $r['parent_node_uid'] ?? null;
        $pk = ($pp !== null && trim((string) $pp) !== '') ? trim((string) $pp) : '';
        $byParent[$pk][] = $r;
        $byUid[$uid] = $r;
    }
    $cache[$batchId] = ['by_parent' => $byParent, 'by_uid' => $byUid];

    return $cache[$batchId];
}

/**
 * Direct children with staging child_count + first-child titles for short labels (no subqueries).
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_graph_direct_children_enriched(array $graph, ?string $parentUid): array
{
    $pk = ($parentUid !== null && $parentUid !== '') ? $parentUid : '';
    $rows = $graph['by_parent'][$pk] ?? [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $uid = trim((string) ($r['node_uid'] ?? ''));
        if ($uid === '') {
            continue;
        }
        $ch = $graph['by_parent'][$uid] ?? [];
        $r2 = $r;
        $r2['child_count'] = count($ch);
        if ($ch !== []) {
            $r2['first_child_title'] = $ch[0]['title'] ?? null;
            $r2['first_child_source_title'] = $ch[0]['source_title'] ?? null;
        }
        $out[] = $r2;
    }

    return $out;
}

function easa_erules_tree_title_line_is_subpart_heading(?string $title): bool
{
    $line = easa_erules_tree_title_first_line($title);

    return $line !== '' && preg_match('/^\s*SUBPART\b/iu', $line) === 1;
}

/**
 * EASA XML often hangs a full "SUBPART A … SUBPART K" outline under SUBPART A; flattening would wrongly nest peers.
 * Drop that wrapper subtree when parent is itself a SUBPART.
 *
 * @param list<array<string, mixed>> $wrapperDirectChildren staging direct children of the wrapper row
 */
function easa_erules_tree_skip_peer_subpart_outline_under_subpart(?array $parentRow, array $wrapperRow, array $wrapperDirectChildren): bool
{
    if ($parentRow === null) {
        return false;
    }
    if (!easa_erules_tree_title_line_is_subpart_heading((string) ($parentRow['title'] ?? ''))) {
        return false;
    }
    $nt = strtolower(trim((string) ($wrapperRow['node_type'] ?? '')));
    if (!in_array($nt, ['heading', 'toc'], true)) {
        return false;
    }
    if (count($wrapperDirectChildren) < 2) {
        return false;
    }
    foreach ($wrapperDirectChildren as $c) {
        if (!is_array($c)) {
            return false;
        }
        $t = (string) ($c['title'] ?? '');
        if (easa_erules_tree_title_line_is_subpart_heading($t)) {
            continue;
        }
        $st = (string) ($c['source_title'] ?? '');
        if ($st !== '' && easa_erules_tree_title_line_is_subpart_heading($st)) {
            continue;
        }

        return false;
    }

    return true;
}

/**
 * Memo key for flattened visible children of a parent node.
 */
function easa_erules_tree_memo_key(int $batchId, ?string $parentUid): string
{
    return (string) $batchId . "\0" . (($parentUid !== null && $parentUid !== '') ? $parentUid : '');
}

/**
 * Visible browse-tree children after removing navigational GM/AMC / rule-line wrappers.
 *
 * @param array<string, list<array<string, mixed>>> $memo
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_children_rows_flattened(
    int $batchId,
    array $graph,
    ?string $parentUid,
    int $depthGuard,
    array &$memo,
    ?array $parentRow
): array {
    if ($depthGuard > 48) {
        return [];
    }
    $key = easa_erules_tree_memo_key($batchId, $parentUid);
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    $raw = easa_erules_tree_graph_direct_children_enriched($graph, $parentUid);
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = trim((string) ($row['node_uid'] ?? ''));
        if ($uid === '') {
            continue;
        }
        if (easa_erules_tree_skip_empty_rule_ref_nav_wrapper($graph, $row)) {
            continue;
        }
        if (easa_erules_tree_skip_empty_toc_placeholder($graph, $row)) {
            continue;
        }
        $kids = easa_erules_tree_graph_direct_children_enriched($graph, $uid);
        if (easa_erules_tree_skip_peer_subpart_outline_under_subpart($parentRow, $row, $kids)) {
            continue;
        }
        if (easa_erules_tree_should_flatten_nav_wrapper($row, $kids)) {
            $lifted = easa_erules_tree_children_rows_flattened($batchId, $graph, $uid, $depthGuard + 1, $memo, $row);
            foreach ($lifted as $L) {
                $out[] = $L;
            }
        } else {
            $out[] = $row;
        }
    }
    $out = easa_erules_tree_dedupe_adjacent_wrapper_topic($out);
    $memo[$key] = $out;

    return $out;
}

/**
 * After peer-lift reparent, rule-id &lt;toc&gt;/heading wrappers keep parent_node_uid but have no children.
 * Omit them from browse JSON (not structural sections).
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 */
function easa_erules_tree_skip_empty_rule_ref_nav_wrapper(array $graph, array $row): bool
{
    $uid = trim((string) ($row['node_uid'] ?? ''));
    if ($uid === '') {
        return false;
    }
    $ch = $graph['by_parent'][$uid] ?? [];
    if ($ch !== []) {
        return false;
    }
    $nt = strtolower(trim((string) ($row['node_type'] ?? '')));
    if (!in_array($nt, ['heading', 'toc'], true)) {
        return false;
    }
    $title = trim((string) ($row['title'] ?? ''));
    $src = trim((string) ($row['source_title'] ?? ''));
    $er = trim((string) ($row['source_erules_id'] ?? ''));
    foreach ([$title, $src, $er] as $chunk) {
        if ($chunk !== '' && easa_erules_tree_blob_has_ir_reference($chunk)) {
            return true;
        }
    }

    return false;
}

/**
 * Hide empty toc/heading shells (no title metadata, no children): UI would show plain "Table of contents" or uid-only noise.
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 */
function easa_erules_tree_skip_empty_toc_placeholder(array $graph, array $row): bool
{
    $nt = strtolower(trim((string) ($row['node_type'] ?? '')));
    if (!in_array($nt, ['toc', 'heading'], true)) {
        return false;
    }
    $uid = trim((string) ($row['node_uid'] ?? ''));
    if ($uid === '') {
        return false;
    }
    $ch = $graph['by_parent'][$uid] ?? [];
    if ($ch !== []) {
        return false;
    }
    $title = trim((string) ($row['title'] ?? ''));
    $src = trim((string) ($row['source_title'] ?? ''));
    $er = trim((string) ($row['source_erules_id'] ?? ''));

    return $title === '' && $src === '' && $er === '';
}

/**
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_children_response_nodes(PDO $pdo, int $batchId, ?string $parentUid): array
{
    $graph = easa_erules_tree_batch_graph($pdo, $batchId);
    $parentRow = null;
    if ($parentUid !== null && $parentUid !== '') {
        $parentRow = $graph['by_uid'][$parentUid] ?? null;
    }
    $memo = [];
    $flat = easa_erules_tree_children_rows_flattened($batchId, $graph, $parentUid, 0, $memo, $parentRow);
    $nodes = [];
    foreach ($flat as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = trim((string) ($row['node_uid'] ?? ''));
        if ($uid === '') {
            continue;
        }
        $vk = easa_erules_tree_memo_key($batchId, $uid);
        if (!isset($memo[$vk])) {
            easa_erules_tree_children_rows_flattened($batchId, $graph, $uid, 0, $memo, $row);
        }
        $row['child_count'] = isset($memo[$vk]) && is_array($memo[$vk]) ? count($memo[$vk]) : 0;
        $childStaging = isset($memo[$vk]) && is_array($memo[$vk]) ? $memo[$vk] : [];
        $nodes[] = easa_erules_tree_semantic_adapter($row, $childStaging);
    }

    return $nodes;
}

/**
 * @return bool
 */
function easa_erules_staging_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_erules_import_nodes_staging LIMIT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}
