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

/**
 * Annex appendix *material* titles (Easy Access numbering), not block headings ("Appendices to Annex I", "Appendix to Annex V").
 * Must not be treated as structural APPENDIX nav in the browse tree (they are readable IR bodies).
 */
function easa_erules_tree_title_is_annex_appendix_body_line(?string $title): bool
{
    $line = easa_erules_tree_title_first_line((string) $title);
    if ($line === '') {
        return false;
    }

    return preg_match('/^\s*Appendix\s+([0-9]+|[IVXLCDM]+)\b/iu', $line) === 1;
}

function easa_erules_title_is_appendix_heading_row(string $title): bool
{
    if (easa_erules_tree_title_is_annex_appendix_body_line($title)) {
        return false;
    }
    $line = easa_erules_tree_title_first_line($title);
    if ($line === '') {
        return false;
    }
    if (preg_match('/^\s*APPENDIX\b/iu', $line) === 1) {
        return true;
    }

    // Easy Access often uses "Appendices to Annex I" (plural) as the appendix block heading.
    return preg_match('/^\s*Appendices\s+to\s+Annex\b/iu', $line) === 1;
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
 * True when a heading under an ANNEX nav &lt;toc&gt; looks like real annex outline (not only Part-FCL SUBPART blocks).
 * Annex II / III often use Articles or SECTION rows without any SUBPART; those must still lift out of the toc shell.
 */
function easa_erules_toc_heading_indicates_annex_interior_outline(string $headingTitle): bool
{
    $line = easa_erules_tree_title_first_line($headingTitle) ?: trim($headingTitle);
    if ($line === '') {
        return false;
    }
    if (easa_erules_title_is_subpart_heading_row($line)) {
        return true;
    }
    if (easa_erules_title_is_section_heading_row($line)) {
        return true;
    }
    if (easa_erules_title_is_appendix_heading_row($line)) {
        return true;
    }
    if (preg_match('/^\s*Article\s+/iu', $line) === 1) {
        return true;
    }
    if (preg_match('/^\s*CHAPTER\s+/iu', $line) === 1) {
        return true;
    }

    return false;
}

/**
 * True when &lt;toc&gt; acts as an empty navigation shell whose children look like annex outline (SUBPART and more).
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
    foreach ($tocDirectChildren as $c) {
        if (!is_array($c)) {
            continue;
        }
        $nt = strtolower(trim((string) ($c['node_type'] ?? '')));
        if ($nt === 'heading') {
            $t = trim((string) ($c['title'] ?? ''));
            if ($t !== '' && easa_erules_toc_heading_indicates_annex_interior_outline($t)) {
                return true;
            }

            continue;
        }
        if ($nt === 'topic') {
            $t = trim((string) ($c['title'] ?? ''));
            $line = easa_erules_tree_title_first_line($t) ?: $t;
            if ($line !== '' && preg_match('/^\s*Article\s+/iu', $line) === 1) {
                return true;
            }
        }
    }

    return false;
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
 * Major ANNEX … heading uid walking ancestors from any staging row (same annex subtree).
 */
function easa_erules_annex_major_heading_uid_for_row(array $byUid, string $startUid): ?string
{
    $u = $startUid;
    for ($guard = 0; $guard < 120; $guard++) {
        if ($u === '' || !isset($byUid[$u])) {
            return null;
        }
        $row = $byUid[$u];
        $nt = strtolower(trim((string) ($row['node_type'] ?? '')));
        $title = trim((string) ($row['title'] ?? ''));
        if ($nt === 'heading' && easa_erules_title_is_annex_heading_row($title)) {
            return $u;
        }
        $p = trim((string) ($row['parent_node_uid'] ?? ''));
        if ($p === '') {
            return null;
        }
        $u = $p;
    }

    return null;
}

/**
 * Count topic rows directly under a toc whose titles look like annex appendix bodies (mode-specific).
 *
 * @param 'plural_block'|'singular_appendix_to_annex' $mode
 */
function easa_erules_toc_direct_topic_appendix_score_for_mode(array $byParent, string $tocUid, string $mode): int
{
    $kids = $byParent[easa_erules_parent_sort_key($tocUid)] ?? [];
    $score = 0;
    foreach ($kids as $ch) {
        if (!is_array($ch)) {
            continue;
        }
        if (strtolower(trim((string) ($ch['node_type'] ?? ''))) !== 'topic') {
            continue;
        }
        $line = easa_erules_tree_title_first_line((string) ($ch['title'] ?? ''));
        if ($line === '') {
            continue;
        }
        if ($mode === 'plural_block') {
            if (preg_match('/^\s*Appendix\s+[0-9]+\b/u', $line) === 1) {
                ++$score;
            }
        } elseif ($mode === 'singular_appendix_to_annex') {
            if (preg_match('/^\s*Appendix\s+[0-9]+\s+to\b/iu', $line) === 1) {
                ++$score;
            }
        }
    }

    return $score;
}

/**
 * Plural "Appendices to Annex …" block heading vs singular "Appendix to Annex …".
 */
function easa_erules_heading_annex_appendix_bundle_mode(?string $title): ?string
{
    $line = easa_erules_tree_title_first_line((string) $title);
    if ($line === '') {
        return null;
    }
    if (preg_match('/^\s*Appendices\s+to\s+Annex\b/iu', $line) === 1) {
        return 'plural_block';
    }
    if (preg_match('/^\s*Appendix\s+to\s+Annex\b/iu', $line) === 1) {
        return 'singular_appendix_to_annex';
    }

    return null;
}

function easa_erules_heading_has_appendix_bundle_toc_child(array $byParent, string $headingUid, string $mode): bool
{
    $minScore = $mode === 'plural_block' ? 2 : 1;
    foreach ($byParent[easa_erules_parent_sort_key($headingUid)] ?? [] as $ch) {
        if (!is_array($ch)) {
            continue;
        }
        if (strtolower(trim((string) ($ch['node_type'] ?? ''))) !== 'toc') {
            continue;
        }
        $tUid = trim((string) ($ch['node_uid'] ?? ''));
        if ($tUid === '') {
            continue;
        }
        if (easa_erules_toc_direct_topic_appendix_score_for_mode($byParent, $tUid, $mode) >= $minScore) {
            return true;
        }
    }

    return false;
}

/**
 * Roman/digit annex marker parsed from topic titles under a toc ("Appendix 1 to Annex VIII …" → VIII).
 */
function easa_erules_toc_appendix_annex_token_from_topics(array $byParent, string $tocUid): ?string
{
    foreach ($byParent[easa_erules_parent_sort_key($tocUid)] ?? [] as $ch) {
        if (!is_array($ch)) {
            continue;
        }
        if (strtolower(trim((string) ($ch['node_type'] ?? ''))) !== 'topic') {
            continue;
        }
        $line = easa_erules_tree_title_first_line((string) ($ch['title'] ?? ''));
        if ($line === '') {
            continue;
        }
        if (preg_match('/\bAnnex\s+([IVXLCDM]+|\d+)\b/iu', $line, $m) === 1) {
            return strtoupper(trim($m[1]));
        }
    }

    return null;
}

function easa_erules_annex_heading_uid_for_annex_token(array $all, string $token): ?string
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    foreach ($all as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (strtolower(trim((string) ($r['node_type'] ?? ''))) !== 'heading') {
            continue;
        }
        $title = (string) ($r['title'] ?? '');
        if (!easa_erules_title_is_annex_heading_row($title)) {
            continue;
        }
        $line = easa_erules_tree_title_first_line($title) ?: $title;
        if ($line === '') {
            continue;
        }
        if (preg_match('/\bANNEX\s+' . preg_quote($token, '/') . '\b/iu', $line) === 1) {
            $u = trim((string) ($r['node_uid'] ?? ''));

            return $u !== '' ? $u : null;
        }
    }

    return null;
}

/**
 * Easy Access often emits "Appendices to Annex I" / "Appendix to Annex V" as headings while the actual
 * appendix topics sit under a toc wrongly nested under SUBPART/SECTION. Attach that toc under the block heading.
 */
function easa_erules_reparent_misnested_annex_appendix_bundle_tocs(PDO $pdo, int $batchId): void
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
    $assignedToc = [];
    foreach ($all as $hRow) {
        $hUid = trim((string) ($hRow['node_uid'] ?? ''));
        if ($hUid === '') {
            continue;
        }
        if (strtolower(trim((string) ($hRow['node_type'] ?? ''))) !== 'heading') {
            continue;
        }
        $mode = easa_erules_heading_annex_appendix_bundle_mode((string) ($hRow['title'] ?? ''));
        if ($mode === null) {
            continue;
        }
        if (easa_erules_heading_has_appendix_bundle_toc_child($byParent, $hUid, $mode)) {
            continue;
        }
        $annexH = easa_erules_annex_major_heading_uid_for_row($byUid, $hUid);
        if ($annexH === null) {
            continue;
        }
        $minScore = $mode === 'plural_block' ? 2 : 1;
        $bestT = '';
        $bestScore = -1;
        $bestId = PHP_INT_MAX;
        foreach ($all as $tRow) {
            if (!is_array($tRow)) {
                continue;
            }
            if (strtolower(trim((string) ($tRow['node_type'] ?? ''))) !== 'toc') {
                continue;
            }
            $tUid = trim((string) ($tRow['node_uid'] ?? ''));
            if ($tUid === '' || isset($assignedToc[$tUid])) {
                continue;
            }
            $score = easa_erules_toc_direct_topic_appendix_score_for_mode($byParent, $tUid, $mode);
            if ($score < $minScore) {
                continue;
            }
            $annexFromT = easa_erules_annex_major_heading_uid_for_row($byUid, $tUid);
            if ($annexFromT !== $annexH) {
                $token = easa_erules_toc_appendix_annex_token_from_topics($byParent, $tUid);
                $annexViaTitle = $token !== null ? easa_erules_annex_heading_uid_for_annex_token($all, $token) : null;
                if ($annexViaTitle !== $annexH) {
                    continue;
                }
            }
            if (trim((string) ($tRow['parent_node_uid'] ?? '')) === $hUid) {
                continue;
            }
            $candId = (int) ($tRow['id'] ?? 0);
            if ($score > $bestScore || ($score === $bestScore && $candId < $bestId)) {
                $bestScore = $score;
                $bestT = $tUid;
                $bestId = $candId;
            }
        }
        if ($bestT === '') {
            continue;
        }
        $assignedToc[$bestT] = true;
        $oldParent = trim((string) ($byUid[$bestT]['parent_node_uid'] ?? ''));
        $upd->execute([$hUid, $batchId, $bestT, $hUid]);
        $byUid[$bestT]['parent_node_uid'] = $hUid;
        if ($oldParent !== '') {
            $opk = easa_erules_parent_sort_key($oldParent);
            if (isset($byParent[$opk])) {
                $byParent[$opk] = array_values(array_filter(
                    $byParent[$opk],
                    static fn (array $x): bool => trim((string) ($x['node_uid'] ?? '')) !== $bestT
                ));
            }
        }
        $npk = easa_erules_parent_sort_key($hUid);
        if (!isset($byParent[$npk])) {
            $byParent[$npk] = [];
        }
        $byParent[$npk][] = $byUid[$bestT];
    }
}

/**
 * Easy Access often nests the next outline level under an empty &lt;toc&gt; (SUBPART → SECTION rows,
 * ANNEX → SUBPART rows, etc.). Lift those structural headings to the real parent so tree_children
 * lists SECTION 1…N directly under SUBPART C, not under a single toc shell.
 *
 * @return int Number of rows whose parent_node_uid was changed
 */
function easa_erules_reparent_structural_lift_empty_toc_outline_children_once(PDO $pdo, int $batchId): int
{
    if ($batchId <= 0) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, parent_node_uid, node_type, title, source_title, source_erules_id, plain_text, sort_order, id
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
    foreach ($byUid as $pRow) {
        $puid = trim((string) ($pRow['node_uid'] ?? ''));
        if ($puid === '') {
            continue;
        }
        if (strtolower(trim((string) ($pRow['node_type'] ?? ''))) !== 'heading') {
            continue;
        }
        $pk = easa_erules_structural_outline_parent_kind_from_heading_title((string) ($pRow['title'] ?? ''));
        if ($pk === null) {
            continue;
        }
        $direct = easa_erules_tree_sorted_direct_children_from_map($puid, $byParent);
        foreach ($direct as $w) {
            if (!is_array($w)) {
                continue;
            }
            if (strtolower(trim((string) ($w['node_type'] ?? ''))) !== 'toc') {
                continue;
            }
            if (!easa_erules_toc_row_is_empty_structural_nav_shell($w)) {
                continue;
            }
            $wuid = trim((string) ($w['node_uid'] ?? ''));
            if ($wuid === '') {
                continue;
            }
            $inner = easa_erules_tree_sorted_direct_children_from_map($wuid, $byParent);
            if ($inner === []) {
                continue;
            }
            foreach ($inner as $ch) {
                if (!is_array($ch) || !easa_erules_outline_heading_child_matches_expected($pk, $ch)) {
                    continue 2;
                }
            }
            foreach ($inner as $ch) {
                if (!is_array($ch)) {
                    continue;
                }
                $cuid = trim((string) ($ch['node_uid'] ?? ''));
                if ($cuid === '') {
                    continue;
                }
                $upd->execute([$puid, $batchId, $cuid, $puid]);
                $changed += $upd->rowCount();
            }
        }
    }

    return $changed;
}

/**
 * ANNEX → SUBPART / appendix outline; SUBPART → SECTION outline; SECTION → lower structural outline when applicable.
 */
function easa_erules_structural_outline_parent_kind_from_heading_title(string $headingTitle): ?string
{
    $headingTitle = trim($headingTitle);
    if ($headingTitle === '') {
        return null;
    }
    $line = easa_erules_tree_title_first_line($headingTitle) ?: $headingTitle;
    if (easa_erules_title_is_annex_heading_row($line)) {
        return 'annex';
    }
    if (easa_erules_title_is_subpart_heading_row($line)) {
        return 'subpart';
    }
    if (easa_erules_title_is_appendix_heading_row($line)) {
        return 'appendix';
    }
    if (easa_erules_title_is_section_heading_row($line)) {
        return 'section';
    }

    return null;
}

/**
 * Empty / thin &lt;toc&gt; shell suitable for structural outline lift (matches annex nav wrapper idea).
 *
 * @param array<string, mixed> $tocRow
 */
function easa_erules_toc_row_is_empty_structural_nav_shell(array $tocRow): bool
{
    $nt = strtolower(trim((string) ($tocRow['node_type'] ?? '')));
    if ($nt !== 'toc') {
        return false;
    }
    $plain = (string) ($tocRow['plain_text'] ?? '');
    if (strlen($plain) > 2048) {
        return false;
    }
    $title = trim((string) ($tocRow['title'] ?? ''));
    $src = trim((string) ($tocRow['source_title'] ?? ''));
    $er = trim((string) ($tocRow['source_erules_id'] ?? ''));
    if ($title === '' && $src === '' && $er === '') {
        return true;
    }
    $blob = trim($title . "\n" . $src);
    $one = trim((string) (preg_replace('/\s+/u', ' ', $blob) ?? $blob));

    return $one !== '' && preg_match('/^table\s+of\s+contents\b/iu', $one) === 1;
}

/**
 * Direct child under the toc is an outline heading for the tier below $parentKind.
 *
 * @param 'annex'|'subpart'|'appendix'|'section' $parentKind
 * @param array<string, mixed> $childRow
 */
function easa_erules_outline_heading_child_matches_expected(string $parentKind, array $childRow): bool
{
    $nt = strtolower(trim((string) ($childRow['node_type'] ?? '')));
    if ($nt !== 'heading') {
        return false;
    }
    $t = trim((string) ($childRow['title'] ?? ''));
    $line = easa_erules_tree_title_first_line($t) ?: $t;
    if ($line === '') {
        return false;
    }
    switch ($parentKind) {
        case 'annex':
            return easa_erules_title_is_subpart_heading_row($line)
                || easa_erules_title_is_appendix_heading_row($line)
                || easa_erules_title_is_section_heading_row($line);
        case 'subpart':
            return easa_erules_title_is_section_heading_row($line);
        case 'appendix':
            return easa_erules_title_is_section_heading_row($line);
        case 'section':
            return preg_match('/^\s*(SUBSECTION|CHAPTER|SECTION)\b/iu', $line) === 1;
        default:
            return false;
    }
}

function easa_erules_reparent_structural_lift_empty_toc_outline_children(PDO $pdo, int $batchId): void
{
    for ($i = 0; $i < 12; $i++) {
        if (easa_erules_reparent_structural_lift_empty_toc_outline_children_once($pdo, $batchId) === 0) {
            break;
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
 * Structural outline container (ANNEX, SUBPART, SECTION, APPENDIX): AMC/GM supplements attach under IR peers here.
 *
 * @param array<string, mixed> $parent
 */
function easa_erules_parent_row_is_structural_amc_cluster_parent(array $parent): bool
{
    $pNt = strtolower(trim((string) ($parent['node_type'] ?? '')));
    if (!in_array($pNt, ['heading', 'toc'], true)) {
        return false;
    }
    $pTitle = trim((string) ($parent['title'] ?? ''));
    if ($pTitle === '') {
        return false;
    }

    return easa_erules_structural_outline_parent_kind_from_heading_title($pTitle) !== null;
}

/**
 * After peer lift, AMC/GM may still sit directly under a structural section — attach under matching IR sibling by stub.
 * Applies under SUBPART, SECTION, ANNEX, APPENDIX (same stub map + multi-stub supplements: first cited stub that exists among siblings).
 */
function easa_erules_reparent_amc_gm_under_structural_ir_peers(PDO $pdo, int $batchId): void
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
        if (!easa_erules_parent_row_is_structural_amc_cluster_parent($parent)) {
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
            $target = easa_erules_tree_nav_rule_supplement_target_uid_from_stub_map($c, $stubMap);
            if ($target === null || $target === $cuid) {
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
                $target = easa_erules_tree_nav_rule_supplement_target_uid_from_stub_map($ch, $stubMap);
                if ($target === null || $target === $chUid) {
                    continue;
                }
                $upd->execute([$target, $batchId, $chUid, $target]);
            }
        }
    }
}

/** @deprecated Use {@see easa_erules_reparent_amc_gm_under_structural_ir_peers} */
function easa_erules_reparent_amc_gm_under_subpart_ir_peers(PDO $pdo, int $batchId): void
{
    easa_erules_reparent_amc_gm_under_structural_ir_peers($pdo, $batchId);
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
    easa_erules_reparent_amc_gm_under_structural_ir_peers($pdo, $batchId);
}

/**
 * Re-apply all staging parent-link passes (no XML re-parse). Safe for ops after deploying reparent fixes.
 */
function easa_erules_repair_batch_tree_parents(PDO $pdo, int $batchId): void
{
    easa_erules_reparent_structural_heading_children($pdo, $batchId);
    easa_erules_reparent_annex_lift_toc_wrapper_children($pdo, $batchId);
    easa_erules_reparent_annex_subpart_appendix_headings($pdo, $batchId);
    easa_erules_reparent_structural_lift_empty_toc_outline_children($pdo, $batchId);
    easa_erules_promote_misnested_fcl_peers($pdo, $batchId);
    easa_erules_reparent_rule_ref_toc_peer_lift($pdo, $batchId);
    easa_erules_reparent_misnested_annex_appendix_bundle_tocs($pdo, $batchId);
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
    easa_erules_reparent_structural_lift_empty_toc_outline_children($pdo, $batchId);
    easa_erules_promote_misnested_fcl_peers($pdo, $batchId);
    easa_erules_reparent_rule_ref_toc_peer_lift($pdo, $batchId);
    easa_erules_reparent_misnested_annex_appendix_bundle_tocs($pdo, $batchId);

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
 * Uses the same per-row precedence as node_detail: canonical_text (if column exists), then plain_text, then xml_fragment.
 */
function easa_erules_aggregate_descendant_plain_text(PDO $pdo, int $batchId, string $parentNodeUid, int $depth = 0): string
{
    if ($depth > 120) {
        return '';
    }
    $hasCanon = easa_erules_staging_has_canonical_column($pdo);
    $selectCols = 'node_uid, title, plain_text, xml_fragment, sort_order';
    if ($hasCanon) {
        $selectCols = 'node_uid, title, plain_text, canonical_text, xml_fragment, sort_order';
    }
    $st = $pdo->prepare('
        SELECT ' . $selectCols . '
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
        $plainTrim = trim((string) ($row['plain_text'] ?? ''));
        $canonTrim = $hasCanon ? trim((string) ($row['canonical_text'] ?? '')) : '';
        $own = $canonTrim !== '' ? $canonTrim : $plainTrim;
        if ($own === '') {
            $fragRaw = trim((string) ($row['xml_fragment'] ?? ''));
            if ($fragRaw !== '' && strlen($fragRaw) < 600000) {
                $own = trim(easa_erules_plain_text_from_stored_xml_fragment($fragRaw));
            }
        }
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
 * Staging helpers: node_detail has no enriched first_child_*; mirror tree graph fallbacks cheaply (single SQL).
 *
 * @return array<string, string>|null { title, source_title, source_erules_id } trimmed strings, or null when no child exists
 */
function easa_erules_staging_first_direct_child_label_fallback(PDO $pdo, int $batchId, string $parentNodeUid): ?array
{
    if ($batchId <= 0 || $parentNodeUid === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT title, source_title, source_erules_id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND parent_node_uid = ?
         ORDER BY sort_order ASC, id ASC
         LIMIT 1'
    );
    $st->execute([$batchId, $parentNodeUid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($r) ? $r : null;
}

/**
 * Empty-label AMC/GM supplement-bundle &lt;toc&gt; wrappers: authoritative body lives on numbered topic neighbours.
 * Returns first topic node's UID under that wrapper when the staging graph matches browse flatten rules, otherwise null.
 */
function easa_erules_staging_anonymous_supplement_bundle_primary_topic_uid(PDO $pdo, int $batchId, string $wrapperNodeUid): ?string
{
    if ($batchId <= 0 || $wrapperNodeUid === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT node_uid, node_type, title, source_title, source_erules_id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND node_uid = ?
         LIMIT 1'
    );
    $st->execute([$batchId, $wrapperNodeUid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($r)) {
        return null;
    }
    if (strtolower(trim((string) ($r['node_type'] ?? ''))) !== 'toc') {
        return null;
    }
    if (!easa_erules_tree_row_own_label_fields_empty($r)) {
        return null;
    }
    $stk = $pdo->prepare(
        'SELECT node_uid, node_type, title, source_title, source_erules_id
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND parent_node_uid = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stk->execute([$batchId, $wrapperNodeUid]);
    $kids = [];
    while ($c = $stk->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($c)) {
            $kids[] = $c;
        }
    }
    if ($kids === [] || !easa_erules_tree_children_all_topics_amcgm_supplement_bundle($kids)) {
        return null;
    }
    $u = trim((string) ($kids[0]['node_uid'] ?? ''));

    return $u !== '' ? $u : null;
}

/**
 * Normalised AMC/GM stub from first line ("AMC1", "GM2", "AMC" when digits omitted).
 * Returns null when the line does not lead with AMC{…}|GM{…}.
 */
function easa_erules_title_line_amc_gm_designator_key(?string $line): ?string
{
    $ln = easa_erules_tree_title_first_line((string) $line);
    if ($ln === '') {
        return null;
    }
    if (preg_match('/^\s*AMC\s*(\d*)\b/iu', $ln, $m) === 1) {
        $d = (string) ($m[1] ?? '');

        return 'AMC' . strtoupper(trim($d));
    }
    if (preg_match('/^\s*GM\s*(\d*)\b/iu', $ln, $m) === 1) {
        $d = (string) ($m[1] ?? '');

        return 'GM' . strtoupper(trim($d));
    }

    return null;
}

/**
 * Prefer designator stamped on structured title/source lines (first match wins).
 *
 * @param array<string, mixed> $r
 */
function easa_erules_row_title_blob_amc_gm_designator_key(array $r): ?string
{
    foreach (
        [
            trim((string) ($r['title'] ?? '')),
            trim((string) ($r['source_title'] ?? '')),
            trim((string) ($r['source_erules_id'] ?? '')),
        ] as $blob
    ) {
        if ($blob === '') {
            continue;
        }
        foreach (preg_split('/\R+/u', $blob) ?: [] as $ln) {
            $dk = easa_erules_title_line_amc_gm_designator_key((string) $ln);
            if ($dk !== null) {
                return $dk;
            }
        }
    }

    return null;
}

/**
 * True when leading AMCn / GMn designators denote the same block (digits omitted on one side = same family wildcard).
 *
 * @param non-empty-string $a
 * @param non-empty-string $b
 */
function easa_erules_amc_gm_designator_keys_equivalent(string $a, string $b): bool
{
    $a = strtoupper(trim($a));
    $b = strtoupper(trim($b));
    if ($a === $b) {
        return true;
    }
    $pa = preg_match('/^(AMC|GM)(\d*)$/', $a, $ma);
    $pb = preg_match('/^(AMC|GM)(\d*)$/', $b, $mb);
    if ($pa !== 1 || $pb !== 1 || strtoupper((string) ($ma[1] ?? '')) !== strtoupper((string) ($mb[1] ?? ''))) {
        return false;
    }
    $da = (string) ($ma[2] ?? '');
    $db = (string) ($mb[2] ?? '');

    return $da === '' || $db === '' || $da === $db;
}

/**
 * Probe AMC/GM designator from node staging fields (full lines) plus optional first direct child's labels.
 *
 * @param array<string, mixed>      $stagingRow
 * @param array<string, string>|null $firstChildTitles from {@see easa_erules_staging_first_direct_child_label_fallback}
 */
function easa_erules_node_detail_amc_gm_designator_key(array $stagingRow, ?array $firstChildTitles): ?string
{
    $try = [
        trim((string) ($stagingRow['title'] ?? '')),
        trim((string) ($stagingRow['source_title'] ?? '')),
        trim((string) ($stagingRow['source_erules_id'] ?? '')),
    ];
    if (is_array($firstChildTitles)) {
        $try[] = trim((string) ($firstChildTitles['title'] ?? ''));
        $try[] = trim((string) ($firstChildTitles['source_title'] ?? ''));
        $er = trim((string) ($firstChildTitles['source_erules_id'] ?? ''));
        if ($er !== '') {
            $try[] = $er;
        }
    }
    foreach ($try as $blob) {
        if ($blob === '') {
            continue;
        }
        foreach (preg_split('/\R+/u', $blob) ?: [] as $ln) {
            $dk = easa_erules_title_line_amc_gm_designator_key((string) $ln);
            if ($dk !== null) {
                return $dk;
            }
        }
    }

    return null;
}

/**
 * All numeric appendix ordinals mentioned in blobs (digits only — "Appendix 3").
 *
 * @return list<int>
 */
function easa_erules_nav_blob_appendix_numeric_ordinals(string ...$blobs): array
{
    $out = [];
    foreach ($blobs as $blob) {
        $blob = trim($blob);
        if ($blob === '') {
            continue;
        }
        if (preg_match_all('/\bAppendix\s+(\d+)\b/iu', $blob, $m) > 0) {
            foreach ((array) ($m[1] ?? []) as $d) {
                $n = (int) trim((string) $d);
                if ($n > 0) {
                    $out[] = $n;
                }
            }
        }
    }

    return $out === [] ? [] : array_values(array_unique($out));
}

/**
 * Annex appendix body heading ordinal on title first line ("Appendix 5 – …").
 */
function easa_erules_row_standalone_annex_appendix_heading_ordinal(?string $titleBlob): ?int
{
    $line = easa_erules_tree_title_first_line((string) $titleBlob);

    return easa_erules_standalone_annex_appendix_heading_ordinal_from_line($line);
}

function easa_erules_standalone_annex_appendix_heading_ordinal_from_line(string $line): ?int
{
    $line = trim($line);
    if ($line === '' || !easa_erules_tree_title_is_annex_appendix_body_line($line)) {
        return null;
    }
    if (preg_match('/^\s*Appendix\s+(\d+)\b/iu', $line, $m) === 1) {
        $n = (int) trim((string) ($m[1] ?? ''));

        return $n > 0 ? $n : null;
    }

    return null;
}

/**
 * @return array{fam: string, designator_key: string, appendix_nums: list<int>}
 */
function easa_erules_supplement_aggregate_fence_unpack(string $expectedDesignator, array $lockedAppendixOrdinals): array
{
    $d = strtoupper(trim($expectedDesignator));
    $fam = str_starts_with($d, 'GM') ? 'GM' : 'AMC';
    /** @var list<int> $nums */
    $nums = array_values(array_unique(array_map(static fn ($x): int => (int) $x, $lockedAppendixOrdinals)));

    return [
        'fam' => $fam,
        'designator_key' => $d,
        'appendix_nums' => $nums,
    ];
}

/**
 * True when direct sibling $childRow starts a navigational block that ends the current supplement run.
 *
 * @param array{fam: string, designator_key: string, appendix_nums: list<int>}|null $fence
 */
function easa_erules_supplement_aggregate_row_is_fence_boundary_sibling(?array $fence, array $childRow): bool
{
    if ($fence === null || trim((string) ($fence['designator_key'] ?? '')) === '') {
        return false;
    }

    $probeBits = [];
    foreach (['title', 'source_title'] as $k) {
        $b = trim((string) ($childRow[$k] ?? ''));
        if ($b === '') {
            continue;
        }
        $parts = preg_split('/\R+/u', $b) ?: [];
        foreach ($parts as $idx => $ln) {
            if ($idx >= 3) {
                break;
            }
            $ln = trim((string) $ln);
            if ($ln !== '') {
                $probeBits[] = $ln;
            }
        }
    }

    if ($probeBits === []) {
        return false;
    }

    $expectedKey = trim((string) $fence['designator_key']);
    $locked = array_map(static fn ($x): int => (int) $x, $fence['appendix_nums'] ?? []);

    $expFam = (string) ($fence['fam'] ?? 'AMC');

    $soloApp = easa_erules_row_standalone_annex_appendix_heading_ordinal((string) ($childRow['title'] ?? ''));
    if ($soloApp !== null && $locked !== [] && !in_array($soloApp, $locked, true)) {
        return true;
    }

    foreach ($probeBits as $ln) {
        $childDesk = easa_erules_title_line_amc_gm_designator_key($ln);
        if ($childDesk === null) {
            continue;
        }
        $cFam = str_starts_with(strtoupper(trim($childDesk)), 'GM') ? 'GM' : 'AMC';
        if ($cFam !== $expFam) {
            return true;
        }

        if ($locked !== []) {
            $lineNums = easa_erules_nav_blob_appendix_numeric_ordinals($ln);
            $inter = array_values(array_intersect($lineNums, $locked));
            if ($lineNums !== [] && $inter === []) {
                return true;
            }
        }

        if (!easa_erules_amc_gm_designator_keys_equivalent($expectedKey, $childDesk)) {
            return true;
        }

        return false;
    }

    if ($locked !== []) {
        $probeJoined = implode("\n", $probeBits);
        $floatingNums = easa_erules_nav_blob_appendix_numeric_ordinals($probeJoined);
        if ($floatingNums !== []) {
            $hit = array_values(array_intersect($floatingNums, $locked));
            if ($hit === []) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Prefer "to Appendix N" / leading-line appendix anchors from supplement titles (narrower lock than corpus-wide appendix union).
 *
 * @param array<string, mixed>        $stagingRow
 * @param array<string, string>|null $firstChildTitles
 *
 * @return list<int>
 */
function easa_erules_supplement_navigation_appendix_lock_ordinals(array $stagingRow, ?array $firstChildTitles): array
{
    $blobs = [
        trim((string) ($stagingRow['title'] ?? '')),
        trim((string) ($stagingRow['source_title'] ?? '')),
        trim((string) ($stagingRow['source_erules_id'] ?? '')),
    ];
    if (is_array($firstChildTitles)) {
        $blobs[] = trim((string) ($firstChildTitles['title'] ?? ''));
        $blobs[] = trim((string) ($firstChildTitles['source_title'] ?? ''));
    }
    foreach ($blobs as $blob) {
        $ln = easa_erules_tree_title_first_line($blob);
        if ($ln === '') {
            continue;
        }
        if (preg_match('/to\s+Appendix\s+(\d+)\b/iu', $ln, $m) === 1) {
            $n = (int) trim((string) ($m[1] ?? ''));

            return $n > 0 ? [$n] : [];
        }
        if (preg_match('/\bAppendix\s+(\d+)\b/iu', $ln, $m2) === 1) {
            $n2 = (int) trim((string) ($m2[1] ?? ''));

            return $n2 > 0 ? [$n2] : [];
        }
    }

    $unionMap = [];
    foreach ($blobs as $blob) {
        foreach (easa_erules_nav_blob_appendix_numeric_ordinals($blob) as $o) {
            $unionMap[(int) $o] = true;
        }
    }

    return $unionMap === [] ? [] : array_values(array_map(static fn ($k): int => (int) $k, array_keys($unionMap)));
}

/**
 * Appendix-aware AMC/GM body assembly: aggregates only the first contiguous run of direct descendants under each
 * parent, stopping before sibling peers whose titles target a competing supplement (different AMC number, AMC↔GM,
 * appendix ordinal mismatch versus $lockedAppendixOrdinals).
 *
 * @param non-empty-string                  $expectedDesignator e.g. "AMC1", "GM2"
 * @param list<int>                          $lockedAppendixOrdinals e.g. [3]; empty disables appendix-relative sibling boundaries
 */
function easa_erules_aggregate_descendant_plain_text_for_designator(
    PDO $pdo,
    int $batchId,
    string $parentNodeUid,
    string $expectedDesignator,
    int $depth = 0,
    array $lockedAppendixOrdinals = []
): string {
    return easa_erules_aggregate_supplement_fence_contiguous_plain(
        $pdo,
        $batchId,
        $parentNodeUid,
        $expectedDesignator,
        $lockedAppendixOrdinals,
        $depth
    );
}

/**
 * Same contract as legacy {@see easa_erules_aggregate_descendant_plain_text_for_designator} with appendix lock set.
 *
 * @param non-empty-string $designatorKey
 * @param list<int>        $lockedAppendixOrdinals
 */
function easa_erules_aggregate_supplement_fence_contiguous_plain(
    PDO $pdo,
    int $batchId,
    string $parentNodeUid,
    string $designatorKey,
    array $lockedAppendixOrdinals,
    int $depth = 0
): string {
    if ($depth > 120 || $batchId <= 0 || $parentNodeUid === '' || trim($designatorKey) === '') {
        return '';
    }
    $fence = easa_erules_supplement_aggregate_fence_unpack($designatorKey, $lockedAppendixOrdinals);
    $hasCanon = easa_erules_staging_has_canonical_column($pdo);
    $selectCols = 'node_uid, node_type, title, source_title, source_erules_id, plain_text, xml_fragment, sort_order';
    if ($hasCanon) {
        $selectCols = 'node_uid, node_type, title, source_title, source_erules_id, plain_text, canonical_text, xml_fragment, sort_order';
    }
    $st = $pdo->prepare(
        'SELECT ' . $selectCols . '
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND parent_node_uid = ?
         ORDER BY sort_order ASC, id ASC'
    );
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
        if (easa_erules_supplement_aggregate_row_is_fence_boundary_sibling($fence, $row)) {
            break;
        }
        $uid = (string) ($row['node_uid'] ?? '');
        $title = trim((string) ($row['title'] ?? ''));
        $plainTrim = trim((string) ($row['plain_text'] ?? ''));
        $canonTrim = $hasCanon ? trim((string) ($row['canonical_text'] ?? '')) : '';
        $own = $canonTrim !== '' ? $canonTrim : $plainTrim;
        if ($own === '') {
            $fragRaw = trim((string) ($row['xml_fragment'] ?? ''));
            if ($fragRaw !== '' && strlen($fragRaw) < 600000) {
                $own = trim(easa_erules_plain_text_from_stored_xml_fragment($fragRaw));
            }
        }
        if ($own === '' && $uid !== '') {
            $own = trim(
                easa_erules_aggregate_supplement_fence_contiguous_plain(
                    $pdo,
                    $batchId,
                    $uid,
                    $designatorKey,
                    $lockedAppendixOrdinals,
                    $depth + 1
                )
            );
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
 * First decoded structured_blocks array under contiguous supplement fence starting at wrapper $rootParentUid (depth-first).
 *
 * @return list<mixed>|null
 */
function easa_erules_node_detail_resolve_structured_blocks_under_supplement_fence(
    PDO $pdo,
    int $batchId,
    string $rootParentUid,
    string $designatorKey,
    array $lockedAppendixOrdinals
): ?array {
    if ($batchId <= 0 || $rootParentUid === '' || trim($designatorKey) === '' || !easa_erules_staging_has_structured_blocks_column($pdo)) {
        return null;
    }
    $fence = easa_erules_supplement_aggregate_fence_unpack($designatorKey, $lockedAppendixOrdinals);
    return easa_erules_staging_dfs_first_structured_blocks_child($pdo, $batchId, $rootParentUid, $fence);
}

/**
 * @param array{fam: string, designator_key: string, appendix_nums: list<int>} $fence
 *
 * @return list<mixed>|null
 */
function easa_erules_staging_dfs_first_structured_blocks_child(
    PDO $pdo,
    int $batchId,
    string $parentNodeUid,
    array $fence
): ?array {
    $stKids = $pdo->prepare(
        'SELECT node_uid, node_type, title, source_title, structured_blocks_json
         FROM easa_erules_import_nodes_staging
         WHERE batch_id = ? AND parent_node_uid = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stKids->execute([$batchId, $parentNodeUid]);
    $childCount = 0;
    while ($r = $stKids->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        if (++$childCount > 2000) {
            break;
        }
        if (easa_erules_supplement_aggregate_row_is_fence_boundary_sibling($fence, $r)) {
            break;
        }

        $uid = trim((string) ($r['node_uid'] ?? ''));
        if ($uid !== '') {
            $sbRaw = trim((string) ($r['structured_blocks_json'] ?? ''));
            if ($sbRaw !== '') {
                $dec = json_decode($sbRaw, true);
                if (is_array($dec) && $dec !== []) {
                    /** @var list<mixed> $outDec */
                    $outDec = $dec;

                    return $outDec;
                }
            }
        }

        if ($uid !== '') {
            $sub = easa_erules_staging_dfs_first_structured_blocks_child($pdo, $batchId, $uid, $fence);
            if ($sub !== null) {
                return $sub;
            }
        }
    }

    return null;
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
 * All codified rule stubs mentioned in a row (left-to-right, title then source then erules), deduped by first occurrence.
 * Used to attach supplements like "AMC1 FCL.125; FCL.235" to the first sibling IR stub that exists (e.g. only FCL.235).
 *
 * @return list<string>
 */
function easa_erules_tree_nav_rule_stub_candidates_from_row(array $r): array
{
    $order = [];
    $seen = [];
    foreach (
        [
            trim((string) ($r['title'] ?? '')),
            trim((string) ($r['source_title'] ?? '')),
            trim((string) ($r['source_erules_id'] ?? '')),
        ] as $blob
    ) {
        if ($blob === '') {
            continue;
        }
        if (preg_match_all(
            '/\b(FCL\.\d+[A-Z]?|(?:ORA|CAT|DTO|ARA|MED)(?:\.[A-Z0-9]+)+)\b/iu',
            $blob,
            $m
        )) {
            foreach ($m[1] as $tok) {
                $k = strtoupper((string) $tok);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $order[] = $k;
                }
            }
        }
    }

    return $order;
}

/**
 * First stub from the supplement row that exists in $stubMap (node_uid per stub).
 *
 * @param array<string, string> $stubMap stub → node_uid
 */
function easa_erules_tree_nav_rule_supplement_target_uid_from_stub_map(array $r, array $stubMap): ?string
{
    foreach (easa_erules_tree_nav_rule_stub_candidates_from_row($r) as $stub) {
        if (isset($stubMap[$stub])) {
            return $stubMap[$stub];
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

        /** Empty-label wrappers reuse first_child for display_title; classify AMC/GM from that label, not neutral TOC/editorial. */
        $fallbackLine = easa_erules_tree_title_first_line(easa_erules_short_tree_label($row));
        if ($fallbackLine !== '' && !easa_erules_tree_title_is_structural_section($fallbackLine)) {
            if (easa_erules_tree_title_starts_gm_or_amc($fallbackLine)) {
                $mtFb = (preg_match('/^\s*AMC\d*\b/iu', $fallbackLine) === 1) ? 'AMC' : 'GM';

                return ['ui_kind' => 'rule', 'material_type' => $mtFb];
            }
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
    if ($line === '') {
        return false;
    }
    if (easa_erules_tree_title_is_annex_appendix_body_line($title)) {
        return false;
    }
    if (easa_erules_title_is_appendix_heading_row($title)) {
        return true;
    }

    return preg_match(
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
 * True when direct staging children include several Annex-style "Appendix N …" topics (Part-FCL list block).
 *
 * @param list<array<string, mixed>> $childRows
 */
function easa_erules_tree_children_dominated_by_annex_appendix_topics(array $childRows): bool
{
    $n = 0;
    foreach ($childRows as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (strtolower(trim((string) ($c['node_type'] ?? ''))) !== 'topic') {
            continue;
        }
        $t = (string) ($c['title'] ?? '');
        if (easa_erules_tree_title_is_annex_appendix_body_line($t)) {
            ++$n;
        }
        if ($n >= 2) {
            return true;
        }
    }

    return false;
}

/**
 * True when direct children are exclusively topic rows and at least one is AMC/GM supplement-labelled.
 *
 * @param list<array<string, mixed>> $childRows
 */
function easa_erules_tree_children_all_topics_amcgm_supplement_bundle(array $childRows): bool
{
    if ($childRows === []) {
        return false;
    }
    $anySupp = false;
    foreach ($childRows as $c) {
        if (!is_array($c)) {
            return false;
        }
        if (strtolower(trim((string) ($c['node_type'] ?? ''))) !== 'topic') {
            return false;
        }
        if (easa_erules_row_own_fields_indicate_gm_amc_supplement($c)) {
            $anySupp = true;
        }
    }

    return $anySupp;
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
    /** Empty appendix-list &lt;toc&gt; under "Appendices to Annex …" — lift topics so Appendix N rows render as IR, not duplicate wrapper titles. */
    if (
        $nt === 'toc'
        && trim((string) ($row['title'] ?? '')) === ''
        && trim((string) ($row['source_title'] ?? '')) === ''
        && easa_erules_tree_children_dominated_by_annex_appendix_topics($childRows)
    ) {
        return true;
    }
    /** Anonymous &lt;toc&gt; shell whose children are AMC/GM &lt;topic&gt; neighbours — omit wrapper row; open topics directly. */
    if (
        $nt === 'toc'
        && easa_erules_tree_row_own_label_fields_empty($row)
        && easa_erules_tree_children_all_topics_amcgm_supplement_bundle($childRows)
    ) {
        return true;
    }
    $title = (string) ($row['title'] ?? '');
    if (easa_erules_tree_title_is_structural_section($title)) {
        return false;
    }
    if (easa_erules_tree_title_starts_gm_or_amc($title)) {
        /** Keep AMC/GM-titled wrappers in the tree unless they are a lone duplicate shell of the same-titled topic. */
        if (count($childRows) !== 1) {
            return false;
        }
        $c0 = $childRows[0];
        if (!is_array($c0)) {
            return false;
        }
        if (strtolower(trim((string) ($c0['node_type'] ?? ''))) !== 'topic') {
            return false;
        }
        $ct = easa_erules_tree_title_first_line((string) ($c0['title'] ?? ''));
        if ($ct === '') {
            $ct = easa_erules_tree_title_first_line((string) ($c0['source_title'] ?? ''));
        }
        $pt = easa_erules_tree_title_first_line($title);
        if ($ct !== '' && $pt !== '' && strcasecmp($ct, $pt) === 0) {
            return true;
        }

        return false;
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
            if ($pt === 'topic' && $nt === 'topic') {
                if (
                    easa_erules_tree_title_is_annex_appendix_body_line((string) ($prev['title'] ?? ''))
                    && easa_erules_tree_title_is_annex_appendix_body_line((string) ($r['title'] ?? ''))
                ) {
                    continue;
                }
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
/**
 * APCu memoization layer for the tree pipeline. These helpers are SSOT-safe:
 * they cache the *output* of the existing semantic adapter functions and are
 * invalidated by a (file_sha256, batch updated_at) token, so any new XML
 * import drops the cache automatically. When APCu is not available (CLI, some
 * shared hosts) every helper degrades to a passthrough — behavior is
 * identical to the uncached path, just slower.
 *
 * Schema-bump convention: bump _v1 → _v2 in cache key namespaces below
 * whenever the cached value's structure changes.
 */
function easa_erules_tree_cache_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    $enabled = function_exists('apcu_fetch')
        && function_exists('apcu_store')
        && (bool) ini_get('apc.enabled');

    return $enabled;
}

/**
 * Returns an opaque token that flips whenever the staging rows for this
 * batch would semantically change. We tie it to the batch row's file_sha256
 * (changes on re-upload) and updated_at (changes on re-parse / status flip).
 * The token itself is cached for 60s in APCu so callers don't hit the DB for
 * every tree_children request.
 */
function easa_erules_tree_cache_version_token(PDO $pdo, int $batchId): string
{
    if ($batchId <= 0) {
        return 'v0';
    }
    $verKey = 'easa_tree_ver_v1:' . $batchId;
    if (easa_erules_tree_cache_enabled()) {
        $okFetch = false;
        $cached = apcu_fetch($verKey, $okFetch);
        if ($okFetch && is_string($cached) && $cached !== '') {
            return $cached;
        }
    }
    try {
        $st = $pdo->prepare(
            'SELECT file_sha256, UNIX_TIMESTAMP(updated_at) AS upd
             FROM easa_erules_import_batches
             WHERE id = ?'
        );
        $st->execute([$batchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $token = 'missing_' . $batchId;
        } else {
            $sha = substr((string) ($row['file_sha256'] ?? ''), 0, 16);
            $upd = (int) ($row['upd'] ?? 0);
            $token = ($sha !== '' ? $sha : 'nosha') . '_' . $upd;
        }
    } catch (Throwable) {
        $token = 'err_' . $batchId;
    }
    if (easa_erules_tree_cache_enabled()) {
        apcu_store($verKey, $token, 60);
    }

    return $token;
}

/**
 * @return array{0: bool, 1: mixed} [hit, value]
 */
function easa_erules_tree_cache_fetch(string $key): array
{
    if (!easa_erules_tree_cache_enabled()) {
        return [false, null];
    }
    $ok = false;
    $val = apcu_fetch($key, $ok);
    if (!$ok) {
        return [false, null];
    }

    return [true, $val];
}

function easa_erules_tree_cache_store(string $key, mixed $value, int $ttlSeconds = 86400): void
{
    if (!easa_erules_tree_cache_enabled()) {
        return;
    }
    apcu_store($key, $value, $ttlSeconds);
}

function easa_erules_tree_batch_graph(PDO $pdo, int $batchId): array
{
    static $cache = [];
    if ($batchId <= 0) {
        return ['by_parent' => [], 'by_uid' => []];
    }
    if (isset($cache[$batchId])) {
        return $cache[$batchId];
    }

    /* Cross-request APCu cache. Survives PHP-FPM teardown so the entire
       staging set is only read from MySQL once per batch import. The version
       token flips whenever file_sha256 / updated_at changes, so a re-parse
       transparently invalidates every dependent cache key. */
    $ver = easa_erules_tree_cache_version_token($pdo, $batchId);
    $cacheKey = 'easa_tree_graph_v1:' . $batchId . ':' . $ver;
    [$hit, $cachedVal] = easa_erules_tree_cache_fetch($cacheKey);
    if ($hit
        && is_array($cachedVal)
        && isset($cachedVal['by_parent'], $cachedVal['by_uid'])
        && is_array($cachedVal['by_parent'])
        && is_array($cachedVal['by_uid'])
    ) {
        $cache[$batchId] = $cachedVal;

        return $cachedVal;
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
    $built = ['by_parent' => $byParent, 'by_uid' => $byUid];
    $cache[$batchId] = $built;
    easa_erules_tree_cache_store($cacheKey, $built, 86400);

    return $built;
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
 * Extract every rule key (FCL.\d+[A-Z]?[(letter)], or ORA/CAT/DTO/ARA/MED dotted) from a free-text blob.
 *
 * @return list<string>
 */
function easa_erules_tree_extract_all_rule_keys_from_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $out = [];
    if (preg_match_all('/\b(FCL\.\d+[A-Z]?)(\(([a-zA-Z])\))?\b/iu', $text, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $m) {
            $key = strtoupper((string) $m[1]);
            if (isset($m[3]) && $m[3] !== '') {
                $key .= '(' . strtolower((string) $m[3]) . ')';
            }
            $out[$key] = true;
        }
    }
    if (preg_match_all('/\b((?:ORA|CAT|DTO|ARA|MED)(?:\.[A-Z0-9]+)+)\b/iu', $text, $m2)) {
        foreach ($m2[1] as $key) {
            $out[strtoupper((string) $key)] = true;
        }
    }

    return array_keys($out);
}

/**
 * "Appendix to AMC1 FCL.310 …" → 'AMC'; "Appendix to GM1 …" → 'GM'; else null.
 */
function easa_erules_tree_appendix_target_prefix_family(string $appendixHeadingText): ?string
{
    if (preg_match('/^\s*Appendix\s+to\s+(AMC|GM)\d*\b/iu', $appendixHeadingText, $m) === 1) {
        return strtoupper((string) $m[1]);
    }

    return null;
}

/**
 * 'AMC' / 'GM' / null prefix family of a topic's first title line.
 */
function easa_erules_tree_topic_amc_or_gm_prefix_family(string $title): ?string
{
    $line = easa_erules_tree_title_first_line($title);
    if ($line === '') {
        return null;
    }
    if (preg_match('/^\s*AMC\d*\b/iu', $line) === 1) {
        return 'AMC';
    }
    if (preg_match('/^\s*GM\d*\b/iu', $line) === 1) {
        return 'GM';
    }

    return null;
}

/**
 * True when at least two children look like SUBJECT NNN topics (Part-FCL syllabus container heuristic).
 *
 * @param list<array<string, mixed>> $childRows
 */
function easa_erules_tree_children_dominantly_subject_topics(array $childRows): bool
{
    $hits = 0;
    foreach ($childRows as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (strtolower(trim((string) ($c['node_type'] ?? ''))) !== 'topic') {
            continue;
        }
        $t = trim((string) ($c['title'] ?? ''));
        if ($t === '') {
            $t = trim((string) ($c['source_title'] ?? ''));
        }
        if ($t !== '' && preg_match('/^\s*SUBJECT\s+/iu', $t) === 1) {
            ++$hits;
            if ($hits >= 2) {
                return true;
            }
        }
    }

    return false;
}

/**
 * DFS within an anonymous TOC branch for the inner toc whose direct children are SUBJECT NNN topics, sitting next
 * to a heading "Appendix to AMC/GM …" whose rule keys overlap $keys and whose prefix family matches $prefixFamily.
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 * @param list<string> $keys rule designator set the appendix must reference
 */
function easa_erules_tree_dfs_find_appendix_subjects_container(array $graph, string $rootUid, array $keys, ?string $prefixFamily, int $depth): ?string
{
    if ($depth > 6 || $rootUid === '') {
        return null;
    }
    foreach ($graph['by_parent'][$rootUid] ?? [] as $kid) {
        if (!is_array($kid)) {
            continue;
        }
        $kUid = trim((string) ($kid['node_uid'] ?? ''));
        if ($kUid === '') {
            continue;
        }
        $ktype = strtolower(trim((string) ($kid['node_type'] ?? '')));
        $ktitle = trim((string) ($kid['title'] ?? ''));
        if ($ktitle === '') {
            $ktitle = trim((string) ($kid['source_title'] ?? ''));
        }
        $isAppendixHeading = $ktype === 'heading'
            && $ktitle !== ''
            && stripos($ktitle, 'Appendix to ') === 0;
        if ($isAppendixHeading) {
            $tfam = easa_erules_tree_appendix_target_prefix_family($ktitle);
            if ($prefixFamily === null || $tfam === null || $tfam === $prefixFamily) {
                $rk = easa_erules_tree_extract_all_rule_keys_from_text($ktitle);
                $hit = false;
                foreach ($rk as $r) {
                    if (in_array($r, $keys, true)) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    foreach ($graph['by_parent'][$rootUid] ?? [] as $sib) {
                        if (!is_array($sib)) {
                            continue;
                        }
                        $sUid = trim((string) ($sib['node_uid'] ?? ''));
                        if ($sUid === '' || $sUid === $kUid) {
                            continue;
                        }
                        if (strtolower(trim((string) ($sib['node_type'] ?? ''))) !== 'toc') {
                            continue;
                        }
                        $kk = $graph['by_parent'][$sUid] ?? [];
                        if (easa_erules_tree_children_dominantly_subject_topics($kk)) {
                            return $sUid;
                        }
                    }
                }
            }
        }
        $rec = easa_erules_tree_dfs_find_appendix_subjects_container($graph, $kUid, $keys, $prefixFamily, $depth + 1);
        if ($rec !== null) {
            return $rec;
        }
    }

    return null;
}

/**
 * For an AMC/GM topic, find the sibling anonymous-TOC branch (under topic's grandparent) whose subtree carries
 * "Appendix to <SAME_FAMILY> …" matching designators, and return the inner SUBJECT container uid.
 * Memoized per topic uid (per request).
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 */
function easa_erules_tree_appendix_subjects_container_uid_for_topic(array $graph, array $topicRow): ?string
{
    static $memo = [];
    $tUid = trim((string) ($topicRow['node_uid'] ?? ''));
    if ($tUid === '') {
        return null;
    }
    if (\array_key_exists($tUid, $memo)) {
        return $memo[$tUid];
    }
    $memo[$tUid] = null;
    $title = trim((string) ($topicRow['title'] ?? ''));
    if ($title === '' || strtolower(trim((string) ($topicRow['node_type'] ?? ''))) !== 'topic') {
        return null;
    }
    $family = easa_erules_tree_topic_amc_or_gm_prefix_family($title);
    if ($family === null) {
        return null;
    }
    $keys = easa_erules_tree_extract_all_rule_keys_from_text($title);
    if ($keys === []) {
        return null;
    }
    $parentUid = trim((string) ($topicRow['parent_node_uid'] ?? ''));
    if ($parentUid === '') {
        return null;
    }
    $parentRow = $graph['by_uid'][$parentUid] ?? null;
    if (!is_array($parentRow)) {
        return null;
    }
    $grandUid = trim((string) ($parentRow['parent_node_uid'] ?? ''));
    if ($grandUid === '') {
        return null;
    }
    foreach ($graph['by_parent'][$grandUid] ?? [] as $sib) {
        if (!is_array($sib)) {
            continue;
        }
        $sUid = trim((string) ($sib['node_uid'] ?? ''));
        if ($sUid === '' || $sUid === $parentUid) {
            continue;
        }
        $stype = strtolower(trim((string) ($sib['node_type'] ?? '')));
        if (!in_array($stype, ['toc', 'heading'], true)) {
            continue;
        }
        $hit = easa_erules_tree_dfs_find_appendix_subjects_container($graph, $sUid, $keys, $family, 0);
        if ($hit !== null) {
            $memo[$tUid] = $hit;

            return $hit;
        }
    }

    return null;
}

/**
 * DFS for the first "Appendix to AMC/GM …" heading text in $rootUid's subtree (own row not included).
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 */
function easa_erules_tree_dfs_first_appendix_heading_text(array $graph, string $rootUid, int $depth): ?string
{
    if ($depth > 6 || $rootUid === '') {
        return null;
    }
    foreach ($graph['by_parent'][$rootUid] ?? [] as $kid) {
        if (!is_array($kid)) {
            continue;
        }
        $kUid = trim((string) ($kid['node_uid'] ?? ''));
        if ($kUid === '') {
            continue;
        }
        $ktype = strtolower(trim((string) ($kid['node_type'] ?? '')));
        $ktitle = trim((string) ($kid['title'] ?? ''));
        if ($ktitle === '') {
            $ktitle = trim((string) ($kid['source_title'] ?? ''));
        }
        if ($ktype === 'heading' && $ktitle !== '' && stripos($ktitle, 'Appendix to ') === 0) {
            return $ktitle;
        }
        $r = easa_erules_tree_dfs_first_appendix_heading_text($graph, $kUid, $depth + 1);
        if ($r !== null) {
            return $r;
        }
    }

    return null;
}

/**
 * True if some topic in $rootUid's subtree (excluding $excludeUid) is an AMC/GM topic of $prefixFamily whose
 * rule keys overlap $keys — used to skip a duplicate "Table of contents" branch when its subjects have been
 * hoisted under a real AMC topic.
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 * @param list<string> $keys
 */
function easa_erules_tree_subtree_has_amc_or_gm_topic_with_keys(
    array $graph,
    string $rootUid,
    array $keys,
    ?string $prefixFamily,
    string $excludeUid,
    int $depth
): bool {
    if ($depth > 6 || $rootUid === '') {
        return false;
    }
    foreach ($graph['by_parent'][$rootUid] ?? [] as $kid) {
        if (!is_array($kid)) {
            continue;
        }
        $kUid = trim((string) ($kid['node_uid'] ?? ''));
        if ($kUid === '' || $kUid === $excludeUid) {
            continue;
        }
        $ktype = strtolower(trim((string) ($kid['node_type'] ?? '')));
        $ktitle = trim((string) ($kid['title'] ?? ''));
        if ($ktype === 'topic' && $ktitle !== '') {
            $fam = easa_erules_tree_topic_amc_or_gm_prefix_family($ktitle);
            if ($fam !== null && ($prefixFamily === null || $fam === $prefixFamily)) {
                $rk = easa_erules_tree_extract_all_rule_keys_from_text($ktitle);
                foreach ($rk as $r) {
                    if (in_array($r, $keys, true)) {
                        return true;
                    }
                }
            }
        }
        if (easa_erules_tree_subtree_has_amc_or_gm_topic_with_keys($graph, $kUid, $keys, $prefixFamily, $excludeUid, $depth + 1)) {
            return true;
        }
    }

    return false;
}

/**
 * Anonymous-TOC sibling branch hides at $parentUid level when its subtree opens with "Appendix to AMC/GM …"
 * and a same-prefix AMC/GM topic with overlapping designators exists somewhere else under $parentUid:
 * its SUBJECT children are hoisted under that topic, so we drop the duplicate "Table of contents" branch.
 *
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 */
function easa_erules_tree_should_skip_appendix_branch_redirected_to_amc_descendant_topic(array $graph, array $row, ?string $parentUid): bool
{
    static $memo = [];
    $rowUid = trim((string) ($row['node_uid'] ?? ''));
    if ($rowUid === '' || $parentUid === null || $parentUid === '') {
        return false;
    }
    $key = $parentUid . "\0" . $rowUid;
    if (\array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    $memo[$key] = false;

    $nt = strtolower(trim((string) ($row['node_type'] ?? '')));
    if (!in_array($nt, ['toc', 'heading'], true)) {
        return false;
    }
    $titleRaw = trim((string) ($row['title'] ?? ''));
    $srcRaw = trim((string) ($row['source_title'] ?? ''));
    if ($titleRaw !== '' || $srcRaw !== '') {
        return false;
    }
    $appText = easa_erules_tree_dfs_first_appendix_heading_text($graph, $rowUid, 0);
    if ($appText === null) {
        return false;
    }
    $keys = easa_erules_tree_extract_all_rule_keys_from_text($appText);
    if ($keys === []) {
        return false;
    }
    $family = easa_erules_tree_appendix_target_prefix_family($appText);
    if (!easa_erules_tree_subtree_has_amc_or_gm_topic_with_keys($graph, $parentUid, $keys, $family, $rowUid, 0)) {
        return false;
    }

    $memo[$key] = true;

    return true;
}

/**
 * For node_detail: when $nodeUid is a SUBJECT topic whose graph parent is the redirected SUBJECT container under an
 * "Appendix to AMC/GM …" branch, return the AMC/GM topic uid that should appear as parent_node_uid in the response so
 * reveal/open-in-tree walks via the visible AMC topic. Returns null when no redirection applies.
 */
function easa_erules_tree_redirected_parent_uid_for_node(PDO $pdo, int $batchId, string $nodeUid): ?string
{
    $nodeUid = trim($nodeUid);
    if ($nodeUid === '' || $batchId <= 0) {
        return null;
    }
    $graph = easa_erules_tree_batch_graph($pdo, $batchId);
    $row = $graph['by_uid'][$nodeUid] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $pUid = trim((string) ($row['parent_node_uid'] ?? ''));
    if ($pUid === '') {
        return null;
    }
    $pRow = $graph['by_uid'][$pUid] ?? null;
    if (!is_array($pRow) || strtolower(trim((string) ($pRow['node_type'] ?? ''))) !== 'toc') {
        return null;
    }
    $branchRootUid = trim((string) ($pRow['parent_node_uid'] ?? ''));
    if ($branchRootUid === '') {
        return null;
    }
    $appText = null;
    foreach ($graph['by_parent'][$branchRootUid] ?? [] as $sib) {
        if (!is_array($sib)) {
            continue;
        }
        $st = strtolower(trim((string) ($sib['node_type'] ?? '')));
        $tt = trim((string) ($sib['title'] ?? ''));
        if ($tt === '') {
            $tt = trim((string) ($sib['source_title'] ?? ''));
        }
        if ($st === 'heading' && $tt !== '' && stripos($tt, 'Appendix to ') === 0) {
            $appText = $tt;
            break;
        }
    }
    if ($appText === null) {
        return null;
    }
    $keys = easa_erules_tree_extract_all_rule_keys_from_text($appText);
    if ($keys === []) {
        return null;
    }
    $family = easa_erules_tree_appendix_target_prefix_family($appText);
    /* Walk up: branchRoot.parent → branch root container (anonymous toc), then again to SECTION ancestor that hosts the AMC topic. */
    $branchRoot = $graph['by_uid'][$branchRootUid] ?? null;
    if (!is_array($branchRoot)) {
        return null;
    }
    $branchTopUid = trim((string) ($branchRoot['parent_node_uid'] ?? ''));
    if ($branchTopUid === '') {
        return null;
    }
    $branchTop = $graph['by_uid'][$branchTopUid] ?? null;
    if (!is_array($branchTop)) {
        return null;
    }
    $sectionUid = trim((string) ($branchTop['parent_node_uid'] ?? ''));
    if ($sectionUid === '') {
        return null;
    }

    return easa_erules_tree_first_amc_or_gm_topic_uid_with_keys_under($graph, $sectionUid, $keys, $family, $branchTopUid, 0);
}

/**
 * @param array{by_parent: array<string, list<array<string, mixed>>>, by_uid: array<string, array<string, mixed>>} $graph
 * @param list<string> $keys
 */
function easa_erules_tree_first_amc_or_gm_topic_uid_with_keys_under(
    array $graph,
    string $rootUid,
    array $keys,
    ?string $prefixFamily,
    string $excludeUid,
    int $depth
): ?string {
    if ($depth > 6 || $rootUid === '') {
        return null;
    }
    foreach ($graph['by_parent'][$rootUid] ?? [] as $kid) {
        if (!is_array($kid)) {
            continue;
        }
        $kUid = trim((string) ($kid['node_uid'] ?? ''));
        if ($kUid === '' || $kUid === $excludeUid) {
            continue;
        }
        $ktype = strtolower(trim((string) ($kid['node_type'] ?? '')));
        $ktitle = trim((string) ($kid['title'] ?? ''));
        if ($ktype === 'topic' && $ktitle !== '') {
            $fam = easa_erules_tree_topic_amc_or_gm_prefix_family($ktitle);
            if ($fam !== null && ($prefixFamily === null || $fam === $prefixFamily)) {
                $rk = easa_erules_tree_extract_all_rule_keys_from_text($ktitle);
                foreach ($rk as $r) {
                    if (in_array($r, $keys, true)) {
                        return $kUid;
                    }
                }
            }
        }
        $rec = easa_erules_tree_first_amc_or_gm_topic_uid_with_keys_under($graph, $kUid, $keys, $prefixFamily, $excludeUid, $depth + 1);
        if ($rec !== null) {
            return $rec;
        }
    }

    return null;
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
        if (easa_erules_tree_should_skip_appendix_branch_redirected_to_amc_descendant_topic($graph, $row, $parentUid)) {
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
    /* Hoist: when this parent is itself an AMC/GM topic with a sibling "Appendix to …" SUBJECT branch, surface the
       SUBJECT topics as direct children so the user clicks straight from the AMC topic into per-SUBJECT detail. */
    if (is_array($parentRow)) {
        $contUid = easa_erules_tree_appendix_subjects_container_uid_for_topic($graph, $parentRow);
        if ($contUid !== null) {
            $alreadyPresent = [];
            foreach ($out as $existing) {
                if (is_array($existing)) {
                    $eu = trim((string) ($existing['node_uid'] ?? ''));
                    if ($eu !== '') {
                        $alreadyPresent[$eu] = true;
                    }
                }
            }
            foreach (easa_erules_tree_graph_direct_children_enriched($graph, $contUid) as $hoist) {
                if (!is_array($hoist)) {
                    continue;
                }
                $hu = trim((string) ($hoist['node_uid'] ?? ''));
                if ($hu === '' || isset($alreadyPresent[$hu])) {
                    continue;
                }
                $out[] = $hoist;
                $alreadyPresent[$hu] = true;
            }
        }
    }
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
 * Parse synthetic tree/detail node uid "parentUid__blk_index" (index = position in structured_blocks array).
 *
 * @return array{parent:string, block_index:int}|null
 */
function easa_erules_tree_parse_synthetic_block_node_uid(string $nodeUid): ?array
{
    $nodeUid = trim($nodeUid);
    if ($nodeUid === '' || !preg_match('/^(.+)__blk_(\d+)$/u', $nodeUid, $m)) {
        return null;
    }

    return ['parent' => $m[1], 'block_index' => (int) $m[2]];
}

/**
 * Stable synthetic id for a structured_blocks heading row (no DB insert).
 */
function easa_erules_tree_synthetic_block_child_id(string $parentUid, int $blockIndex): string
{
    return $parentUid . '__blk_' . (string) $blockIndex;
}

/**
 * Load decoded structured blocks for a staging row (JSON column, else regenerated from xml_fragment).
 *
 * @return list<array<string, mixed>>|null
 */
function easa_erules_staging_node_structured_blocks_decoded(PDO $pdo, int $batchId, string $nodeUid): ?array
{
    $nodeUid = trim($nodeUid);
    if ($nodeUid === '') {
        return null;
    }
    $sbRaw = '';
    $frag = '';
    $canonCol = '';
    $plainCol = '';

    if (easa_erules_staging_has_structured_blocks_column($pdo)) {
        $baseCols = 'structured_blocks_json, xml_fragment, plain_text';
        if (easa_erules_staging_has_canonical_column($pdo)) {
            $baseCols = 'structured_blocks_json, xml_fragment, canonical_text, plain_text';
        }
        $st = $pdo->prepare(
            'SELECT ' . $baseCols . '
            FROM easa_erules_import_nodes_staging
            WHERE batch_id = ? AND node_uid = ?
            LIMIT 1'
        );
        $st->execute([$batchId, $nodeUid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            return null;
        }
        $sbRaw = trim((string) ($r['structured_blocks_json'] ?? ''));
        $frag = trim((string) ($r['xml_fragment'] ?? ''));
        $plainCol = trim((string) ($r['plain_text'] ?? ''));
        if (isset($r['canonical_text'])) {
            $canonCol = trim((string) $r['canonical_text']);
        }
    } else {
        $st = $pdo->prepare(
            'SELECT xml_fragment, plain_text FROM easa_erules_import_nodes_staging WHERE batch_id = ? AND node_uid = ? LIMIT 1'
        );
        $st->execute([$batchId, $nodeUid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            return null;
        }
        $frag = trim((string) ($r['xml_fragment'] ?? ''));
        $plainCol = trim((string) ($r['plain_text'] ?? ''));
    }
    if ($sbRaw !== '') {
        $dec = json_decode($sbRaw, true);
        if (is_array($dec) && $dec !== []) {
            /** @var list<array<string, mixed>> $dec */
            return $dec;
        }
    }
    if ($frag !== '' && strlen($frag) < 600000) {
        $gen = easa_erules_structured_blocks_json_from_outer_xml($frag);
        $dec2 = json_decode($gen, true);
        if (is_array($dec2) && $dec2 !== []) {
            /** @var list<array<string, mixed>> $dec2 */
            return $dec2;
        }
    }
    if ($canonCol !== '') {
        $fromCanon = easa_erules_structured_subject_blocks_from_plain_text($canonCol);
        if (is_array($fromCanon) && $fromCanon !== []) {
            return $fromCanon;
        }
    }
    if ($plainCol !== '') {
        $fromPlain = easa_erules_structured_subject_blocks_from_plain_text($plainCol);
        if (is_array($fromPlain) && $fromPlain !== []) {
            return $fromPlain;
        }
    }

    return null;
}

/**
 * True when a heading's first visible line opens a syllabus SUBJECT stanza (e.g. SUBJECT 010 – AIR LAW).
 */
function easa_erules_structured_heading_line_is_subject_syllabus(?string $text): bool
{
    $t = trim((string) $text);
    if ($t === '') {
        return false;
    }
    $parts = preg_split('/\R+/u', $t);
    $line = trim((string) (($parts !== false && isset($parts[0]) && $parts[0] !== '') ? $parts[0] : $t));

    return preg_match('/^\s*SUBJECT\s+[0-9A-Z]{3,}\b/iu', $line) === 1;
}

/**
 * Build pseudo structured_blocks alternating heading + paragraph from plain text SUBJECT headings (no XML parse).
 *
 * @return list<array<string, mixed>>|null
 */
function easa_erules_structured_subject_blocks_from_plain_text(?string $text): ?array
{
    $text = (string) $text;
    if (trim($text) === '') {
        return null;
    }
    if (
        preg_match_all(
            '/(?m)^\s*(SUBJECT\s+[0-9A-Z]{3,}\b.*)$/iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        ) === false || !isset($matches[0]) || count($matches[0]) < 1
    ) {
        return null;
    }
    $blocks = [];
    foreach ($matches[0] as $idx => $hit) {
        $lineRaw = isset($hit[0]) ? trim((string) $hit[0]) : '';
        $pos = (int) ($hit[1] ?? 0);
        if ($lineRaw === '') {
            continue;
        }
        $nextPos = isset($matches[0][$idx + 1]) ? (int) ($matches[0][$idx + 1][1] ?? strlen($text)) : strlen($text);
        $spanToNext = max(0, $nextPos - $pos - strlen($lineRaw));
        $suffix = substr($text, $pos + strlen($lineRaw), $spanToNext);
        $body = trim((string) $suffix);
        $blocks[] = ['type' => 'heading', 'level' => 3, 'text' => $lineRaw];
        if ($body !== '') {
            $blocks[] = ['type' => 'paragraph', 'text' => $body];
        }
    }

    return $blocks !== [] ? $blocks : null;
}

/**
 * Row json/fragment/canonical/subject-plain; if missing, descendant plain aggregated and split by SUBJECT — for
 * appendix/toc syllabus containers with flattened empty children only (tree synthetic path — can be costly).
 *
 * @return list<array<string, mixed>>|null
 */
function easa_erules_node_structured_blocks_or_subject_aggregate(PDO $pdo, int $batchId, string $nodeUid): ?array
{
    $nodeUid = trim($nodeUid);
    if ($nodeUid === '') {
        return null;
    }
    $direct = easa_erules_staging_node_structured_blocks_decoded($pdo, $batchId, $nodeUid);
    if (is_array($direct) && $direct !== []) {
        return $direct;
    }
    $composed = trim(easa_erules_aggregate_descendant_plain_text($pdo, $batchId, $nodeUid, 0));

    return easa_erules_structured_subject_blocks_from_plain_text($composed);
}

/**
 * Whether a heading block should appear as a synthetic browse-tree row under its parent topic.
 */
function easa_erules_structured_heading_qualifies_for_synthetic_tree(string $headingText, int $level): bool
{
    if (easa_erules_structured_heading_line_is_subject_syllabus($headingText)) {
        return true;
    }
    $tt = trim($headingText);
    $parts = preg_split('/\R+/u', $tt);
    if (!is_array($parts)) {
        $parts = [];
    }
    $line = trim((string) (($parts[0] ?? '') !== '' ? $parts[0] : $tt));
    if ($line === '') {
        return false;
    }
    $patterns = [
        '/\bLearning\s+objectives?\b/iu',
        '/\bTheoretical\s+knowledge\b/iu',
        '/\bGENERAL\b/u',
        '/\bCREDITING\b/iu',
        '/\bFLYING\s+TRAINING\b/iu',
        '/\bPRACTICAL\s+SKILL\b/iu',
        '/\bSKILL\s+TEST\b/iu',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $line) === 1) {
            return true;
        }
    }
    // Short AMC-style section title: one line, mostly uppercase letters / punctuation (GENERAL-style lists).
    if (strlen($line) >= 6 && strlen($line) <= 120 && $level >= 2 && $level <= 4) {
        if (preg_match('/^[A-Z0-9][A-Z0-9\s\-\(\),.:;\/]+$/u', $line) === 1 && preg_match('/[A-Z]{3,}/', $line) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Slice structured blocks from a heading index through content until the next heading of the same or higher outline level.
 *
 * @param list<array<string, mixed>> $blocks
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_structured_blocks_slice_from_heading_index(array $blocks, int $startIndex): array
{
    if ($startIndex < 0 || $startIndex >= count($blocks)) {
        return [];
    }
    $first = $blocks[$startIndex];
    $out = [$first];
    $lvl = 4;
    if (($first['type'] ?? '') === 'heading') {
        $lvl = max(1, min(6, (int) ($first['level'] ?? 3)));
    }
    for ($i = $startIndex + 1, $n = count($blocks); $i < $n; $i++) {
        $b = $blocks[$i];
        if (($b['type'] ?? '') === 'heading') {
            $l2 = max(1, min(6, (int) ($b['level'] ?? 3)));
            if ($l2 <= $lvl) {
                break;
            }
        }
        $out[] = $b;
    }

    return $out;
}

/**
 * Slice for synthetic detail: SUBJECT headings end at the next SUBJECT heading; otherwise use outline levels.
 *
 * @param list<array<string, mixed>> $blocks
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_structured_blocks_slice_for_synthetic_detail(array $blocks, int $startIndex): array
{
    $n = count($blocks);
    if ($startIndex < 0 || $startIndex >= $n) {
        return [];
    }
    $first = $blocks[$startIndex];
    if (($first['type'] ?? '') !== 'heading') {
        return easa_erules_structured_blocks_slice_from_heading_index($blocks, $startIndex);
    }
    $ft = trim((string) ($first['text'] ?? ''));
    if (!easa_erules_structured_heading_line_is_subject_syllabus($ft)) {
        return easa_erules_structured_blocks_slice_from_heading_index($blocks, $startIndex);
    }

    $out = [$first];
    for ($i = $startIndex + 1; $i < $n; $i++) {
        $b = $blocks[$i];
        if (($b['type'] ?? '') === 'heading') {
            $bt = trim((string) ($b['text'] ?? ''));
            $parts = preg_split('/\R+/u', $bt);
            $hline = trim((string) (($parts !== false && isset($parts[0]) && $parts[0] !== '') ? $parts[0] : $bt));
            if (easa_erules_structured_heading_line_is_subject_syllabus($hline)) {
                break;
            }
        }
        $out[] = $b;
    }

    return $out;
}

/**
 * Request-scoped memo for synthetic navigation children (expensive aggregate fallback).
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_synthetic_nav_children_memoized(PDO $pdo, int $batchId, string $parentUid): array
{
    static $memo = [];
    $pk = (string) $batchId . "\0" . trim($parentUid);
    if (\array_key_exists($pk, $memo)) {
        return $memo[$pk];
    }
    $memo[$pk] = easa_erules_tree_synthetic_block_children_for_parent($pdo, $batchId, trim($parentUid));

    return $memo[$pk];
}

/**
 * When a section/toc wrapper has zero staged flattened children but API will inject synthetic SUBJECT (or LO) rows on expand,
 * mark it expandable so the viewer uses disclosure + expand instead of opening the aggregated parent blob.
 *
 * Mutates &$node semantic tree_children shape (never widens ANNEX filters).
 */
function easa_erules_tree_semantic_adapter_apply_synthetic_parent_nav_hints(PDO $pdo, int $batchId, array &$node): void
{
    if (($node['synthetic'] ?? false) === true) {
        return;
    }
    if ((int) ($node['child_count'] ?? 0) > 0) {
        return;
    }
    $uid = trim((string) ($node['id'] ?? ''));
    if ($uid === '') {
        return;
    }
    $synth = easa_erules_tree_synthetic_nav_children_memoized($pdo, $batchId, $uid);
    if ($synth === []) {
        return;
    }
    $ntLc = strtolower(trim((string) ($node['node_type'] ?? '')));
    $sectionLike = (($node['ui_kind'] ?? '') === 'section') || in_array($ntLc, ['toc', 'heading'], true);
    if (!$sectionLike) {
        return;
    }
    if (($node['ui_kind'] ?? '') !== 'section' && in_array($ntLc, ['toc', 'heading'], true)) {
        $node['ui_kind'] = 'section';
        $node['material_type'] = 'HEADING';
    }
    $node['child_count'] = count($synth);
    $node['expandable'] = true;
    $node['click_action'] = 'expand';
}

/**
 * Synthetic tree rows derived from structured_blocks headings on a parent with no staging children.
 * Does not touch ANNEX-level browse: only invoked when the real flattened child list is empty.
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_synthetic_block_children_for_parent(PDO $pdo, int $batchId, string $parentUid): array
{
    $parentUid = trim($parentUid);
    if ($parentUid === '') {
        return [];
    }
    $blocks = easa_erules_node_structured_blocks_or_subject_aggregate($pdo, $batchId, $parentUid);
    if ($blocks === null || $blocks === []) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT depth, sort_order, node_type, title, source_title FROM easa_erules_import_nodes_staging WHERE batch_id = ? AND node_uid = ? LIMIT 1'
    );
    $st->execute([$batchId, $parentUid]);
    $pr = $st->fetch(PDO::FETCH_ASSOC);
    $baseDepth = is_array($pr) ? (int) ($pr['depth'] ?? 0) : 0;
    $parentSort = is_array($pr) ? (int) ($pr['sort_order'] ?? 0) : 0;
    $parentFirstLine = '';
    if (is_array($pr)) {
        $pt = trim((string) ($pr['title'] ?? ''));
        if ($pt === '') {
            $pt = trim((string) ($pr['source_title'] ?? ''));
        }
        if ($pt !== '') {
            $parentFirstLine = strtolower(easa_erules_tree_title_first_line($pt));
        }
    }

    $out = [];
    foreach ($blocks as $i => $b) {
        if (!is_array($b) || ($b['type'] ?? '') !== 'heading') {
            continue;
        }
        $text = trim((string) ($b['text'] ?? ''));
        $hl = max(1, min(6, (int) ($b['level'] ?? 3)));
        if (!easa_erules_structured_heading_qualifies_for_synthetic_tree($text, $hl)) {
            continue;
        }
        $disp = $text;
        if ($disp !== '' && str_contains($disp, "\n")) {
            $parts = preg_split('/\R+/u', $disp) ?: [];
            $disp = trim((string) ($parts[0] ?? $disp));
        }
        if ($parentFirstLine !== '') {
            $headFirst = strtolower(easa_erules_tree_title_first_line($disp));
            if ($headFirst !== '' && $headFirst === $parentFirstLine) {
                continue;
            }
        }
        $synthId = easa_erules_tree_synthetic_block_child_id($parentUid, $i);
        $isSubject = easa_erules_structured_heading_line_is_subject_syllabus($disp !== '' ? $disp : $text);
        $sectionLike = !$isSubject && $hl <= 3;
        $out[] = [
            'id' => $synthId,
            'parent_id' => $parentUid,
            'display_title' => $disp !== '' ? $disp : ('§ block ' . (string) $i),
            'ui_kind' => $isSubject ? 'rule' : ($sectionLike ? 'section' : 'rule'),
            'material_type' => $isSubject ? 'IR' : ($sectionLike ? 'HEADING' : 'IR'),
            'expandable' => false,
            'click_action' => 'open_rule',
            'depth' => $baseDepth + 1,
            'sort_order' => $parentSort * 1000 + $i,
            'child_count' => 0,
            'node_type' => 'topic',
            'synthetic' => true,
            'synthetic_parent_node_uid' => $parentUid,
            'synthetic_block_index' => $i,
        ];
    }

    return $out;
}

/**
 * Walk staging parent pointers from $nodeUid up to the corpus root (empty parent).
 *
 * @return list<string> Root-first … target (same order as the legacy client chain after reverse)
 */
function easa_erules_staging_ancestor_chain_root_to_target(PDO $pdo, int $batchId, string $nodeUid): array
{
    $nodeUid = trim($nodeUid);
    if ($nodeUid === '' || $batchId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT parent_node_uid FROM easa_erules_import_nodes_staging WHERE batch_id = ? AND node_uid = ? LIMIT 1');
    $forward = [];
    $uid = $nodeUid;
    for ($guard = 0; $guard < 800; $guard++) {
        $forward[] = $uid;
        $stmt->execute([$batchId, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        $p = trim((string) ($row['parent_node_uid'] ?? ''));
        if ($p === '') {
            return array_values(array_reverse($forward));
        }
        $uid = $p;
    }

    return [];
}

/**
 * Semantic browse children. When staging has no rows under $parentUid, optional synthetic rows from
 * structured_blocks headings on the parent are appended (same API shape + synthetic: true).
 *
 * @return list<array<string, mixed>>
 */
function easa_erules_tree_children_response_nodes(PDO $pdo, int $batchId, ?string $parentUid): array
{
    /* Cross-request APCu cache for the fully-adapted node list. The graph
       wrapper above already caches the staging rows; this layer also caches
       the final semantic-adapter output so identical (batch_id, parent_uid)
       requests skip the entire flatten + filter + synthetic-nav pass. Same
       version token, so any re-parse invalidates everything together. */
    $verToken = easa_erules_tree_cache_version_token($pdo, $batchId);
    $parentKey = ($parentUid !== null && $parentUid !== '') ? $parentUid : '';
    $cacheKey = 'easa_tree_kids_v1:' . $batchId . ':' . $verToken . ':' . md5($parentKey);
    [$hit, $cachedNodes] = easa_erules_tree_cache_fetch($cacheKey);
    if ($hit && is_array($cachedNodes)) {
        return $cachedNodes;
    }

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
        $sem = easa_erules_tree_semantic_adapter($row, $childStaging);
        easa_erules_tree_semantic_adapter_apply_synthetic_parent_nav_hints($pdo, $batchId, $sem);
        /* AMC/GM topics that have hoisted appendix-SUBJECT children must show the chevron: keep them clickable as
           rules (click_action stays open_rule, material_type stays AMC/GM), but advertise expandable=true so the
           frontend renders the disclosure triangle and expands to the 17 SUBJECT rows. */
        if (
            ($sem['ui_kind'] ?? '') === 'rule'
            && ($sem['expandable'] ?? false) === false
            && ((int) ($sem['child_count'] ?? 0)) > 0
            && easa_erules_tree_appendix_subjects_container_uid_for_topic($graph, $row) !== null
        ) {
            $sem['expandable'] = true;
        }
        /* Hoisted appendix-SUBJECT children must report the AMC topic as their semantic parent, not the original
           Table-of-contents container (which is hidden from normal browsing). */
        if ($parentUid !== null && $parentUid !== '') {
            $rowParentUid = trim((string) ($row['parent_node_uid'] ?? ''));
            if ($rowParentUid !== '' && $rowParentUid !== $parentUid) {
                $sem['parent_id'] = $parentUid;
            }
        }
        $nodes[] = $sem;
    }

    if ($nodes === [] && $parentUid !== null && $parentUid !== '') {
        $syn = easa_erules_tree_synthetic_nav_children_memoized($pdo, $batchId, $parentUid);
        if ($syn !== []) {
            easa_erules_tree_cache_store($cacheKey, $syn, 86400);

            return $syn;
        }
    }

    easa_erules_tree_cache_store($cacheKey, $nodes, 86400);

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
