<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
require_once __DIR__ . '/../../../src/ecfr_api_client.php';
require_once __DIR__ . '/../../../src/resource_library_catalog.php';
require_once __DIR__ . '/../../../src/easa_erules_storage.php';
require_once __DIR__ . '/../../../src/easa_download_monitor.php';
require_once __DIR__ . '/../../../src/easa_erules_xml_import.php';
require_once __DIR__ . '/../../../src/resource_library_easa_node_detail_build.php';

@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '128M');

cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function rl_easa_json_out(int $code, array $payload): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'json_encode failed: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo $json;
    exit;
}

/** @return int Parsed byte size from php.ini strings such as "32M" (0 if unknown). */
function rl_easa_ini_bytes(string $iniKey): int
{
    $raw = trim((string) ini_get($iniKey));
    if ($raw === '' || $raw === '0') {
        return 0;
    }
    if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*([kmg]?)\s*$/i', str_replace(' ', '', $raw), $m)) {
        return (int) $raw;
    }
    $num = (float) $m[1];
    $u = strtolower($m[2] ?? '');

    return match ($u) {
        'g' => (int) round($num * 1073741824),
        'm' => (int) round($num * 1048576),
        'k' => (int) round($num * 1024),
        default => (int) round($num),
    };
}

/**
 * LIKE patterns for staging search (escape % and _). Second pattern drops dots so FCL.055 also matches FCL055-style ids.
 *
 * @return array{0: string, 1: string|null}
 */
function rl_easa_search_like_patterns(string $q): array
{
    $esc = static function (string $s): string {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
    };
    $like = $esc($q);
    $noDots = str_replace(['.', '·', "\u{00B7}"], '', $q);
    if ($noDots === $q || strlen(trim($noDots)) < 2) {
        return [$like, null];
    }

    return [$like, $esc(trim($noDots))];
}

/**
 * Same WHERE clause as Search EASA index (titles, ids, paths, plain_text, optional canonical_text).
 *
 * @return array{where: string, bind: list<mixed>}
 */
function rl_easa_search_match_clause(PDO $pdo, string $q): array
{
    [$like, $likeNoDots] = rl_easa_search_like_patterns($q);
    $matchCols = ['plain_text', 'title', 'source_title', 'breadcrumb', 'source_erules_id', 'path'];
    if (easa_erules_staging_has_canonical_column($pdo)) {
        $matchCols[] = 'canonical_text';
    }
    $matchParts = [];
    $bind = [];
    foreach ($matchCols as $col) {
        $matchParts[] = "COALESCE({$col}, '') LIKE ? ESCAPE '\\\\'";
        $bind[] = $like;
    }
    if ($likeNoDots !== null) {
        foreach (['source_erules_id', 'path', 'title', 'breadcrumb'] as $col) {
            $matchParts[] = "COALESCE({$col}, '') LIKE ? ESCAPE '\\\\'";
            $bind[] = $likeNoDots;
        }
    }

    return ['where' => '(' . implode(' OR ', $matchParts) . ')', 'bind' => $bind];
}

/**
 * Rule-id and keyword fallbacks for natural-language compare questions.
 *
 * Order matters: needles are searched in order until the row budget is full, so we put
 * high-signal tokens (rule ids, EASA licence abbreviations, technical phrases) first and
 * push generic English words to the tail. Short domain abbreviations (FCL, CPL, PPL, ATPL,
 * MPL, LAPL, IR, BIR, SFCL, BFCL) are kept regardless of length — those are the tokens that
 * actually pick out the Part-FCL nodes a pilot is asking about.
 *
 * @return list<string>
 */
function rl_easa_compare_query_needles(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $highSignal = [];
    $domainPhrases = [];
    $generic = [];
    $pushUnique = static function (array &$bucket, string $value): void {
        $v = trim($value);
        if ($v === '') {
            return;
        }
        foreach ($bucket as $existing) {
            if (strcasecmp($existing, $v) === 0) {
                return;
            }
        }
        $bucket[] = $v;
    };

    if (preg_match_all('/\b(?:FCL\.\d+[A-Z]?|(?:ORA|CAT|DTO|ARA|MED|ARO|NCO|NCC|SPO|SPA|CC)(?:\.[A-Z0-9]+)+)\b/iu', $q, $m)) {
        foreach (($m[0] ?? []) as $id) {
            $pushUnique($highSignal, strtoupper((string) $id));
        }
    }

    /** For phrase synonym matching, collapse ALL non-letter/non-digit characters (including dots, commas, ?, !) to single spaces
        so "license?" / "license," / "license." / "Licence." all match. Token splitting below uses a different regex that preserves dots in rule ids. */
    $ql = ' ' . trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($q))) . ' ';
    /** Natural-language phrases mapped to the rule abbreviations / id families that actually appear in Part-FCL legal text. */
    $phraseSynonyms = [
        'commercial pilot licence' => ['CPL', 'FCL.300', 'commercial pilot'],
        'commercial pilot license' => ['CPL', 'FCL.300', 'commercial pilot'],
        'private pilot licence' => ['PPL', 'FCL.200', 'private pilot'],
        'private pilot license' => ['PPL', 'FCL.200', 'private pilot'],
        'airline transport pilot licence' => ['ATPL', 'FCL.500', 'airline transport pilot'],
        'airline transport pilot license' => ['ATPL', 'FCL.500', 'airline transport pilot'],
        'multi-crew pilot licence' => ['MPL', 'FCL.400'],
        'multi-crew pilot license' => ['MPL', 'FCL.400'],
        'multi crew pilot licence' => ['MPL', 'FCL.400'],
        'light aircraft pilot licence' => ['LAPL', 'FCL.100'],
        'light aircraft pilot license' => ['LAPL', 'FCL.100'],
        'sailplane pilot licence' => ['SFCL', 'sailplane'],
        'balloon pilot licence' => ['BFCL', 'balloon'],
        'instrument rating' => ['IR', 'FCL.600'],
        'basic instrument rating' => ['BIR', 'FCL.835'],
        'class rating' => ['class rating', 'FCL.700'],
        'type rating' => ['type rating', 'FCL.700'],
        'instructor rating' => ['instructor', 'FCL.900'],
        'examiner' => ['examiner', 'FCL.1000'],
        'theoretical knowledge examination' => ['theoretical knowledge', 'FCL.025'],
        'theory exam' => ['theoretical knowledge', 'FCL.025'],
        'theory examination' => ['theoretical knowledge', 'FCL.025'],
        'skill test' => ['skill test', 'FCL.030'],
        'medical certificate' => ['medical certificate', 'Part-MED', 'MED.A.030'],
    ];
    foreach ($phraseSynonyms as $phrase => $expansions) {
        if (str_contains($ql, ' ' . $phrase . ' ')) {
            foreach ($expansions as $exp) {
                $pushUnique($domainPhrases, $exp);
            }
        }
    }

    /** Standalone licence/rating abbreviations the user actually typed (keep even if short). */
    if (preg_match_all('/\b(FCL|CPL|PPL|ATPL|MPL|LAPL|SFCL|BFCL|BIR|IR|AMC|GM|ORA|DTO|ATO|FSTD|PART-FCL|PART-MED|PART-ARA|PART-ORA|PART-DTO|PART-IS|AIRCREW)\b/iu', $q, $m2)) {
        foreach (($m2[0] ?? []) as $tok) {
            $pushUnique($highSignal, strtoupper((string) $tok));
        }
    }

    $raw = preg_split('/[^\p{L}\p{N}\.\-]+/u', mb_strtolower($q)) ?: [];
    /** Generic chatter that drowns out signal in LIKE matches against legal text. Keep this list aggressive. */
    $stop = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'from' => true, 'that' => true, 'this' => true,
        'what' => true, 'when' => true, 'where' => true, 'which' => true, 'does' => true, 'about' => true,
        'under' => true, 'into' => true, 'are' => true, 'can' => true, 'should' => true, 'must' => true,
        'rule' => true, 'rules' => true, 'official' => true, 'regulation' => true, 'regulations' => true,
        'need' => true, 'needs' => true, 'more' => true, 'most' => true, 'detail' => true, 'details' => true,
        'detailed' => true, 'information' => true, 'info' => true, 'please' => true, 'thanks' => true,
        'thank' => true, 'help' => true, 'know' => true, 'tell' => true, 'show' => true, 'give' => true,
        'obtain' => true, 'obtaining' => true, 'get' => true, 'getting' => true,
        'license' => true, 'licence' => true, 'pilot' => true, 'pilots' => true,
        'requirement' => true, 'requirements' => true, 'required' => true, 'require' => true,
        'looking' => true, 'find' => true, 'finding' => true, 'want' => true, 'wants' => true,
        'how' => true, 'why' => true, 'who' => true, 'whom' => true, 'whose' => true,
        'have' => true, 'has' => true, 'had' => true, 'been' => true, 'being' => true,
        'will' => true, 'would' => true, 'could' => true, 'going' => true,
        'part' => true, 'section' => true, 'chapter' => true, 'subpart' => true, 'annex' => true,
    ];
    foreach ($raw as $tok) {
        $tok = trim((string) $tok, " \t\n\r\0\x0B.-");
        if ($tok === '') {
            continue;
        }
        /** Domain abbreviations: keep even at 2–3 chars (FCL/CPL/PPL/IR/etc.) — these are the most precise tokens. */
        $upper = strtoupper($tok);
        $isDomainAbbrev = in_array($upper, [
            'FCL', 'CPL', 'PPL', 'ATPL', 'MPL', 'LAPL', 'SFCL', 'BFCL', 'BIR', 'IR',
            'AMC', 'GM', 'ORA', 'DTO', 'ATO', 'FSTD', 'AIRCREW',
        ], true);
        if ($isDomainAbbrev) {
            $pushUnique($highSignal, $upper);
            continue;
        }
        if (strlen($tok) < 4 || isset($stop[$tok])) {
            continue;
        }
        $pushUnique($generic, $tok);
    }

    /** Bare licence-family abbreviations (FCL, CPL, PPL, ATPL, MPL, LAPL) match too broadly when the corpus is Aircrew:
        every FCL rule contains "FCL" in its source_erules_id, so LIKE '%FCL%' returns the lowest-id 8 intro rules
        (FCL.001/005/010/065/…) and starves the row budget. Demote those if we already have a specific rule id
        (FCL.300, FCL.025, …) or a domain phrase (commercial pilot, instrument rating, theoretical knowledge, …). */
    $haveSpecificRuleId = false;
    foreach (array_merge($highSignal, $domainPhrases) as $tok) {
        if (preg_match('/^(FCL|ORA|CAT|DTO|ARA|MED)\.\d/i', $tok) === 1) {
            $haveSpecificRuleId = true;
            break;
        }
    }
    /** Corpus-level tokens that match the entire Aircrew batch via source_erules_id LIKE '%FCL%'. Demote when more specific tokens exist. */
    $broadAbbrev = ['FCL' => true, 'AIRCREW' => true, 'PART-FCL' => true];
    $highRule = [];
    $highCarry = [];
    foreach ($highSignal as $tok) {
        $u = strtoupper($tok);
        if (preg_match('/^(FCL|ORA|CAT|DTO|ARA|MED)\.\d/i', $u) === 1) {
            $highRule[] = $tok;
        } elseif (isset($broadAbbrev[$u]) && $haveSpecificRuleId) {
            /* drop broad abbreviation — specific rule ids are enough and won't flood with intro rules. */
            continue;
        } else {
            $highCarry[] = $tok;
        }
    }
    /** Final order: specific rule ids first (FCL.300 etc.) → domain phrases (commercial pilot, CPL) → other high-signal tokens → generic words. */
    $needles = [];
    foreach (array_merge($highRule, $domainPhrases, $highCarry, $generic) as $cand) {
        $hit = false;
        foreach ($needles as $existing) {
            if (strcasecmp($existing, $cand) === 0) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $needles[] = $cand;
        }
    }

    return array_slice($needles, 0, 12);
}

/**
 * Snippet column: prefers canonical body when the column exists (aligned with search/compare).
 */
function rl_easa_staging_snippet_concat_body(PDO $pdo): string
{
    if (easa_erules_staging_has_canonical_column($pdo)) {
        return 'COALESCE(NULLIF(TRIM(canonical_text), \'\'), plain_text)';
    }

    return 'plain_text';
}

/**
 * Detect whether a free-text question is about pilot/FCL/Aircrew topics or about Part-IS / information security.
 *
 * @return array{is_fcl: bool, is_partis: bool}
 */
function rl_easa_query_topic_filter(string $q): array
{
    $ql = ' ' . strtolower($q) . ' ';
    $partisKw = [
        'part-is', 'part is ', 'information security', 'isms',
        'iso 27001', 'iso27001', 'cyber', 'cybersecurity',
        'security management', 'infosec',
    ];
    $isPartis = false;
    foreach ($partisKw as $kw) {
        if (str_contains($ql, $kw)) {
            $isPartis = true;
            break;
        }
    }
    $fclKw = [
        ' ppl ', ' cpl ', ' atpl ', ' lapl ', ' mpl ', ' sfcl ', ' bfcl ',
        ' bir ', ' ir ', ' fcl ', ' part-fcl ', 'part fcl',
        'theoretical knowledge', 'theory exam', 'theory examination',
        'learning objective', 'learning objectives',
        'air law', 'meteorology', 'principles of flight', 'navigation',
        'flight performance', 'human performance', 'communications',
        'aircraft general knowledge', 'operational procedures',
        'flight planning', 'mass and balance',
        'subject 010', 'subject 020', 'subject 021', 'subject 022', 'subject 030',
        'subject 031', 'subject 032', 'subject 033', 'subject 034', 'subject 040',
        'subject 050', 'subject 060', 'subject 061', 'subject 062', 'subject 070',
        'subject 080', 'subject 081', 'subject 082', 'subject 090', 'subject 091',
        'class rating', 'type rating', 'instructor rating', 'instrument rating',
        'pilot licence', 'pilot license', 'flight crew', 'aircrew',
        'commercial pilot', 'private pilot', 'airline transport pilot',
        'light aircraft pilot', 'multi-crew pilot', 'multi crew pilot',
        'sailplane pilot', 'balloon pilot',
        'student pilot', 'flight instructor', 'examiner',
        'medical certificate', ' medical class', ' part-med ', ' part-mc ',
    ];
    $isFcl = false;
    foreach ($fclKw as $kw) {
        if (str_contains($ql, $kw)) {
            $isFcl = true;
            break;
        }
    }

    return ['is_fcl' => $isFcl, 'is_partis' => $isPartis];
}

/**
 * Inspect the available batches and decide which to boost / hide based on the question topic.
 * Hard exclusion is only applied when at least one positive replacement batch exists, so we never
 * empty the result set just to enforce a topic.
 *
 * @return array{exclude_batch_ids: list<int>, boost_batch_ids: list<int>, intent: array{is_fcl: bool, is_partis: bool}}
 */
function rl_easa_query_batch_filtering(PDO $pdo, string $q): array
{
    $intent = rl_easa_query_topic_filter($q);
    $out = ['exclude_batch_ids' => [], 'boost_batch_ids' => [], 'intent' => $intent];
    if (!$intent['is_fcl'] && !$intent['is_partis']) {
        return $out;
    }
    try {
        $rows = $pdo->query("SELECT id, LOWER(COALESCE(original_filename, '')) AS f FROM easa_erules_import_batches")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return $out;
    }
    $partis = [];
    $fcl = [];
    $ops = [];
    $other = [];
    foreach ($rows as $r) {
        $bid = (int) ($r['id'] ?? 0);
        $f = (string) ($r['f'] ?? '');
        if ($bid <= 0) {
            continue;
        }
        if (str_contains($f, 'part-is') || str_contains($f, 'information security') || str_contains($f, 'partis')) {
            $partis[] = $bid;
            continue;
        }
        if (str_contains($f, 'aircrew') || str_contains($f, 'fcl') || str_contains($f, 'flight crew') || str_contains($f, 'flight-crew')) {
            $fcl[] = $bid;
            continue;
        }
        if (str_contains($f, 'air-ops') || str_contains($f, 'flight operations') || str_contains($f, 'flight-ops') || str_contains($f, 'air ops')) {
            $ops[] = $bid;
            continue;
        }
        $other[] = $bid;
    }
    if ($intent['is_fcl'] && !$intent['is_partis']) {
        $out['boost_batch_ids'] = $fcl;
        if ($fcl !== [] || $ops !== [] || $other !== []) {
            $out['exclude_batch_ids'] = $partis;
        }

        return $out;
    }
    if ($intent['is_partis'] && !$intent['is_fcl']) {
        $out['boost_batch_ids'] = $partis;
        if ($partis !== []) {
            $out['exclude_batch_ids'] = array_merge($fcl, $ops, $other);
        }

        return $out;
    }

    return $out;
}

/**
 * Pull staging excerpts for AI compare using the same matching rules as search.
 *
 * @return array{hit_count: int, bundle: string, sources: list<array<string, mixed>>, summary: string}
 */
function rl_easa_build_compare_staging_bundle(PDO $pdo, string $q, int $batchFilter): array
{
    $out = [
        'hit_count' => 0,
        'bundle' => '',
        'sources' => [],
        'summary' => '',
    ];
    if (!easa_erules_staging_tables_ok($pdo)) {
        $out['summary'] = 'EASA staging is not available (apply scripts/sql/resource_library_easa_erules_staging.sql).';

        return $out;
    }
    try {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM easa_erules_import_nodes_staging')->fetchColumn();
    } catch (Throwable) {
        $out['summary'] = 'Could not read easa_erules_import_nodes_staging.';

        return $out;
    }
    if ($total === 0) {
        $out['summary'] = 'No rows in staging yet. Upload official Easy Access XML and run “Parse XML → staging”.';

        return $out;
    }
    /** Document shells are useless for the model. Toc/heading nodes are nav scaffolding — keep them, but rank them below real bodies. */
    $wrapRank = '(CASE WHEN LOWER(node_type) IN (\'document\',\'frontmatter\',\'backmatter\') THEN 1 ELSE 0 END)';
    /** EASA staging node_type taxonomy is essentially: document / frontmatter / backmatter / toc / heading / topic.
        "topic" rows carry the actual rule body (FCL.300 etc.). Prefer them; demote toc/heading; require body presence
        (non-empty source_erules_id OR non-empty plain_text) to filter out empty nav rows that just contain a label. */
    $kindRank = "(CASE LOWER(COALESCE(node_type, ''))
        WHEN 'topic' THEN 0
        WHEN 'heading' THEN 2
        WHEN 'toc' THEN 3
        ELSE 1
    END)";
    /** Rule-body presence: rows with an ERulesId or plain_text are real rule/AMC/GM bodies; rows missing both are typically empty nav shells. */
    $bodyRank = "(CASE WHEN (COALESCE(source_erules_id, '') <> '' OR LENGTH(COALESCE(plain_text, '')) > 80) THEN 0 ELSE 1 END)";
    $maxRows = 18;
    $excerptLen = 4500;
    $excerptBody = rl_easa_staging_snippet_concat_body($pdo);
    $bf = rl_easa_query_batch_filtering($pdo, $q);
    $excludeIds = $bf['exclude_batch_ids'];
    $boostIds = $bf['boost_batch_ids'];
    $excludeSql = '';
    if ($excludeIds !== [] && $batchFilter <= 0) {
        $excludeSql = ' AND batch_id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ') ';
    }
    $boostRank = '0';
    if ($boostIds !== [] && $batchFilter <= 0) {
        $boostRank = '(CASE WHEN batch_id IN (' . implode(',', array_map('intval', $boostIds)) . ') THEN 0 ELSE 1 END)';
    }
    $fetchMatches = static function (string $needle, int $limit) use ($pdo, $batchFilter, $wrapRank, $kindRank, $bodyRank, $excerptBody, $excerptLen, $excludeSql, $boostRank): array {
        $m = rl_easa_search_match_clause($pdo, $needle);
        $whereMatch = $m['where'];
        $bind = $m['bind'];
        /** Match-location rank uses the same LIKE pattern as the WHERE clause but on title / id / breadcrumb only,
            so rows where the needle hits a real title or ERulesId outrank rows where it only hits anywhere in plain_text. */
        [$likeForRank] = rl_easa_search_like_patterns($needle);
        $titleRankSql = '(CASE
            WHEN COALESCE(source_erules_id, \'\') LIKE ? ESCAPE \'\\\\\' THEN 0
            WHEN COALESCE(title, \'\') LIKE ? ESCAPE \'\\\\\' THEN 1
            WHEN COALESCE(breadcrumb, \'\') LIKE ? ESCAPE \'\\\\\' THEN 2
            ELSE 3
        END)';
        $titleRankBind = [$likeForRank, $likeForRank, $likeForRank];
        if ($batchFilter > 0) {
            $sql = "
                SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                       SUBSTRING({$excerptBody}, 1, {$excerptLen}) AS excerpt
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ? AND {$whereMatch}
                ORDER BY {$wrapRank} ASC, {$bodyRank} ASC, {$kindRank} ASC, {$titleRankSql} ASC, id ASC
                LIMIT {$limit}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$batchFilter], $bind, $titleRankBind));
        } else {
            $sql = "
                SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                       SUBSTRING({$excerptBody}, 1, {$excerptLen}) AS excerpt
                FROM easa_erules_import_nodes_staging
                WHERE {$whereMatch} {$excludeSql}
                ORDER BY {$boostRank} ASC, {$wrapRank} ASC, {$bodyRank} ASC, {$kindRank} ASC, {$titleRankSql} ASC, id ASC
                LIMIT {$limit}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($bind, $titleRankBind));
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $rows = $fetchMatches($q, $maxRows);
    // Natural-language prompts rarely appear verbatim in legal text; fallback to ids + keywords.
    if ($rows === []) {
        $seen = [];
        foreach (rl_easa_compare_query_needles($q) as $needle) {
            $more = $fetchMatches($needle, 8);
            foreach ($more as $r) {
                $key = (string) ($r['batch_id'] ?? '') . '|' . (string) ($r['node_uid'] ?? '');
                if ($key === '|' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $r;
                if (count($rows) >= $maxRows) {
                    break 2;
                }
            }
        }
    }
    $out['hit_count'] = count($rows);
    foreach ($rows as $r) {
        $out['sources'][] = [
            'batch_id' => (int) ($r['batch_id'] ?? 0),
            'node_uid' => (string) ($r['node_uid'] ?? ''),
            'node_type' => (string) ($r['node_type'] ?? ''),
            'source_erules_id' => (string) ($r['source_erules_id'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
        ];
    }
    $maxBundle = 38000;
    $parts = [];
    $totalChars = 0;
    foreach ($rows as $i => $r) {
        $hdr = sprintf(
            "--- Source %d | batch_id=%s | node_uid=%s | type=%s | ERulesId=%s ---\nTitle: %s\nBreadcrumb: %s\nExcerpt:\n%s\n",
            $i + 1,
            (string) ($r['batch_id'] ?? ''),
            (string) ($r['node_uid'] ?? ''),
            (string) ($r['node_type'] ?? ''),
            (string) ($r['source_erules_id'] ?? ''),
            (string) ($r['title'] ?? ''),
            (string) ($r['breadcrumb'] ?? ''),
            (string) ($r['excerpt'] ?? '')
        );
        if (strlen($hdr) > 14000) {
            $hdr = substr($hdr, 0, 14000) . "\n… [truncated]";
        }
        if ($totalChars + strlen($hdr) > $maxBundle && $parts !== []) {
            $parts[] = '[Further matched rows omitted to stay within model context size.]';

            break;
        }
        $parts[] = $hdr;
        $totalChars += strlen($hdr);
    }
    $out['bundle'] = implode("\n", $parts);
    if ($out['hit_count'] > 0) {
        $out['summary'] = sprintf(
            'Loaded %d excerpt(s) from easa_erules_import_nodes_staging (parsed Easy Access XML). Quote with batch_id + node_uid and/or ERulesId; verify on official EASA sources.',
            $out['hit_count']
        );
    } else {
        $out['summary'] = 'No staging rows matched your question. Use keywords or rule ids (e.g. FCL.055), optional batch filter, or the rule tree. Always verify on EASA and national portals.';
    }

    return $out;
}

/**
 * Staging search → unique node_uids → full node_detail payloads for AI (canonical regulation text, not snippets only).
 *
 * @return array{
 *   summary: string,
 *   hit_count: int,
 *   sources: list<array<string, mixed>>,
 *   model_bundle: string,
 *   full_nodes: list<array{batch_id: int, node_uid: string, node: array<string, mixed>}>
 * }
 */
function rl_easa_build_ai_canonical_regulatory_bundle(PDO $pdo, string $q, int $batchFilter): array
{
    $stagingCompare = rl_easa_build_compare_staging_bundle($pdo, $q, $batchFilter);
    $out = [
        'summary' => (string) ($stagingCompare['summary'] ?? ''),
        'hit_count' => (int) ($stagingCompare['hit_count'] ?? 0),
        'sources' => is_array($stagingCompare['sources'] ?? null) ? $stagingCompare['sources'] : [],
        'model_bundle' => '',
        'full_nodes' => [],
    ];
    if (!easa_erules_staging_tables_ok($pdo) || $out['hit_count'] === 0) {
        $out['model_bundle'] = '(No staging matches — no full node payloads loaded.)';

        return $out;
    }
    $seen = [];
    $pairs = [];
    foreach ($out['sources'] as $src) {
        if (!is_array($src)) {
            continue;
        }
        $bid = (int) ($src['batch_id'] ?? 0);
        $nuid = trim((string) ($src['node_uid'] ?? ''));
        if ($bid <= 0 || $nuid === '') {
            continue;
        }
        $k = $bid . '|' . $nuid;
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $pairs[] = ['batch_id' => $bid, 'node_uid' => $nuid];
        if (count($pairs) >= 12) {
            break;
        }
    }
    $maxTotal = 220000;
    $total = 0;
    $parts = [];
    $idx = 0;
    foreach ($pairs as $pair) {
        $bid = $pair['batch_id'];
        $nuid = $pair['node_uid'];
        $det = rl_easa_api_node_detail_build($pdo, $bid, $nuid);
        if (!$det['ok'] || !is_array($det['node'] ?? null)) {
            continue;
        }
        $node = $det['node'];
        $out['full_nodes'][] = ['batch_id' => $bid, 'node_uid' => $nuid, 'node' => $node];
        $idx++;
        $sb = $node['structured_blocks'] ?? null;
        $sbJson = '';
        if (is_array($sb) && $sb !== []) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $enc = json_encode($sb, $flags);
            $sbJson = is_string($enc) ? $enc : '';
            if (strlen($sbJson) > 120000) {
                $sbJson = substr($sbJson, 0, 120000) . "\n… [structured_blocks_json truncated for model context]";
            }
        }
        $canon = trim((string) ($node['canonical_text'] ?? ''));
        $plain = (string) ($node['plain_text'] ?? '');
        if (strlen($plain) > 120000) {
            $plain = substr($plain, 0, 120000) . "\n… [plain_text truncated for model context]";
        }
        if (strlen($canon) > 120000) {
            $canon = substr($canon, 0, 120000) . "\n… [canonical_text truncated for model context]";
        }
        $hdr = sprintf(
            "--- CANONICAL NODE %d ---\nbatch_id=%d\nnode_uid=%s\nERulesId=%s\ntitle_display=%s\nbreadcrumb=%s\nstructured_blocks_json:\n%s\n\ncanonical_text:\n%s\n\nplain_text (effective body field from node_detail):\n%s\n",
            $idx,
            $bid,
            $nuid,
            trim((string) ($node['source_erules_id'] ?? '')),
            trim((string) ($node['title_display'] ?? '')),
            trim((string) ($node['breadcrumb'] ?? '')),
            $sbJson !== '' ? $sbJson : '(none — rely on canonical_text/plain_text)',
            $canon !== '' ? $canon : '(empty in staging row)',
            $plain !== '' ? $plain : '(empty)'
        );
        if ($total + strlen($hdr) > $maxTotal && $parts !== []) {
            $parts[] = '[Further canonical nodes omitted to stay within model context budget.]';
            break;
        }
        $parts[] = $hdr;
        $total += strlen($hdr);
    }
    if ($parts === []) {
        $out['model_bundle'] = 'Staging matched rows but full node_detail could not be loaded for any candidate (check staging integrity).';
    } else {
        $out['model_bundle'] = implode("\n", $parts);
        $out['summary'] .= sprintf(' Loaded %d full node_detail payload(s) for the model.', count($parts));
    }

    return $out;
}

function rl_easa_ai_chat_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_ai_chat_sessions LIMIT 1');
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** GET or POST: EASA AI chat bootstrap (sessions + messages). Supports `before_id` for lazy older-page loading. */
function rl_easa_ai_chat_bootstrap_output(PDO $pdo, int $wantSession, int $beforeId = 0, int $limit = 5): void
{
    $chatSupported = rl_easa_ai_chat_tables_ok($pdo);
    if (!$chatSupported) {
        rl_easa_json_out(200, [
            'ok' => true,
            'chat_supported' => false,
            'chat_migrate_hint' => 'Apply scripts/sql/resource_library_easa_ai_chat.sql to enable persistent EASA AI chat.',
            'sessions' => [],
            'messages' => [],
            'current_session_id' => null,
            'has_more' => false,
        ]);
    }
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    if ($userId <= 0) {
        rl_easa_json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 80) {
        $limit = 80;
    }
    try {
        $st = $pdo->prepare('SELECT id, title, created_at, updated_at FROM easa_ai_chat_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 40');
        $st->execute([$userId]);
        $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $current = $wantSession;
    if ($current <= 0 && $sessions !== []) {
        $current = (int) ($sessions[0]['id'] ?? 0);
    }
    $messages = [];
    $hasMore = false;
    if ($current > 0) {
        $chk = $pdo->prepare('SELECT id FROM easa_ai_chat_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$current, $userId]);
        if ($chk->fetchColumn()) {
            $fetchLimit = $limit + 1;
            if ($beforeId > 0) {
                $mst = $pdo->prepare('SELECT id, role, content, response_json, created_at FROM easa_ai_chat_messages WHERE session_id = ? AND id < ? ORDER BY id DESC LIMIT ' . (int) $fetchLimit);
                $mst->execute([$current, $beforeId]);
            } else {
                $mst = $pdo->prepare('SELECT id, role, content, response_json, created_at FROM easa_ai_chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT ' . (int) $fetchLimit);
                $mst->execute([$current]);
            }
            $rows = $mst->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) > $limit) {
                $hasMore = true;
                array_pop($rows);
            }
            $messages = array_reverse($rows);
        } else {
            $current = 0;
        }
    }
    foreach ($messages as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $caStr = trim((string) ($row['created_at'] ?? ''));
        if ($caStr === '') {
            $messages[$idx]['created_at_iso'] = null;
            continue;
        }
        try {
            if (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $caStr)) {
                $dt = new DateTimeImmutable($caStr);
            } else {
                $dt = new DateTimeImmutable(str_replace(' ', 'T', $caStr), new DateTimeZone('UTC'));
            }
            $messages[$idx]['created_at_iso'] = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            $messages[$idx]['created_at_iso'] = str_replace(' ', 'T', $caStr) . 'Z';
        }
    }
    rl_easa_json_out(200, [
        'ok' => true,
        'chat_supported' => true,
        'sessions' => $sessions,
        'messages' => $messages,
        'current_session_id' => $current > 0 ? $current : null,
        'has_more' => $hasMore,
    ]);
}

/** @return array{answer_markdown: string, primary_references: list<array<string, mixed>>, secondary_references: list<array<string, mixed>>, confidence: string} */
function rl_easa_normalize_ai_json_payload(array $j): array
{
    $md = trim((string) ($j['answer_markdown'] ?? ''));
    $conf = strtolower(trim((string) ($j['confidence'] ?? 'medium')));
    if (!in_array($conf, ['high', 'medium', 'low'], true)) {
        $conf = 'medium';
    }
    $prim = $j['primary_references'] ?? [];
    $sec = $j['secondary_references'] ?? [];
    if (!is_array($prim)) {
        $prim = [];
    }
    if (!is_array($sec)) {
        $sec = [];
    }
    $mapRef = static function (mixed $r): ?array {
        if (!is_array($r)) {
            return null;
        }
        $bid = (int) ($r['batch_id'] ?? 0);
        $nuid = trim((string) ($r['node_uid'] ?? ''));
        if ($bid <= 0 || $nuid === '') {
            return null;
        }
        $eid = trim((string) ($r['erules_id'] ?? $r['source_erules_id'] ?? ''));
        $title = trim((string) ($r['title'] ?? ''));
        $mt = $r['matched_terms'] ?? [];
        if (!is_array($mt)) {
            $mt = [];
        }
        $mt = array_values(array_filter(array_map(static fn($x) => trim((string) $x), $mt), static fn(string $x): bool => $x !== ''));
        $quote = trim((string) ($r['quote'] ?? ''));

        return [
            'title' => $title !== '' ? $title : $nuid,
            'batch_id' => $bid,
            'node_uid' => $nuid,
            'erules_id' => $eid,
            'matched_terms' => $mt,
            'quote' => $quote,
        ];
    };
    $primOut = [];
    foreach ($prim as $r) {
        $m = $mapRef($r);
        if ($m !== null) {
            $primOut[] = $m;
        }
    }
    $secOut = [];
    foreach ($sec as $r) {
        $m = $mapRef($r);
        if ($m !== null) {
            $secOut[] = $m;
        }
    }

    return [
        'answer_markdown' => $md,
        'primary_references' => $primOut,
        'secondary_references' => $secOut,
        'confidence' => $conf,
    ];
}

function rl_easa_parse_ai_compare_response(array $resp): array
{
    try {
        $j = cw_openai_extract_json_text($resp);
        if (!is_array($j)) {
            throw new RuntimeException('non-array');
        }

        return rl_easa_normalize_ai_json_payload($j);
    } catch (Throwable) {
        $t = rl_easa_extract_ai_text($resp);

        return [
            'answer_markdown' => $t,
            'primary_references' => [],
            'secondary_references' => [],
            'confidence' => 'low',
        ];
    }
}

function rl_easa_query_requests_us_ecfr_context(string $q): bool
{
    $ql = strtolower($q);
    if (str_contains($ql, 'faa')) {
        return true;
    }
    if (preg_match('/\b14\s*cfr\b/u', $q)) {
        return true;
    }
    if (str_contains($ql, 'ecfr')) {
        return true;
    }
    if (preg_match('/\bfar\b/', $ql)) {
        return true;
    }
    if (str_contains($ql, 'compare') && (str_contains($ql, ' u.s') || str_contains($ql, ' us ') || str_contains($ql, 'faa'))) {
        return true;
    }
    if (str_contains($ql, 'versus') && str_contains($ql, 'faa')) {
        return true;
    }

    return false;
}

/** Best-effort 14 CFR section token like 61.57 from natural language. */
function rl_easa_infer_ecfr_section_from_query(string $q): string
{
    if (preg_match('/\b(\d{1,2})\s*\.\s*([0-9]{1,4}[a-z]?)\b/iu', $q, $m)) {
        return strtolower($m[1] . '.' . $m[2]);
    }

    return '';
}

function rl_easa_extract_ai_text(array $resp): string
{
    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        return trim($resp['output_text']);
    }
    $out = $resp['output'] ?? [];
    if (!is_array($out)) {
        return '';
    }
    $text = '';
    foreach ($out as $item) {
        if (!is_array($item)) {
            continue;
        }
        $content = $item['content'] ?? [];
        if (!is_array($content)) {
            continue;
        }
        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                $text .= (string) ($c['text'] ?? '');
            }
        }
    }

    return trim($text);
}

function rl_easa_current_user_first_name(PDO $pdo): string
{
    $u = cw_current_user($pdo);
    if (!is_array($u)) {
        return '';
    }
    $first = trim((string) ($u['first_name'] ?? ''));
    if ($first !== '') {
        return $first;
    }
    $name = trim((string) ($u['name'] ?? ''));
    if ($name !== '') {
        $parts = preg_split('/\s+/u', $name) ?: [];
        if ($parts !== []) {
            return trim((string) $parts[0]);
        }
    }

    return '';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'status'));

    if ($action === 'storage_health') {
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        $health = easa_erules_storage_health($batchId > 0 ? $batchId : null);
        if ($batchId > 0) {
            try {
                $st = $pdo->prepare('SELECT id, storage_relpath, status, error_message, updated_at FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
                $st->execute([$batchId]);
                $health['batch_row'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {
                $health['batch_row'] = null;
            }
        }
        rl_easa_json_out(200, ['ok' => true, 'health' => $health]);
    }

    if ($action === 'batch_progress') {
        $bid = (int) ($_GET['batch_id'] ?? 0);
        if ($bid <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        try {
            $st = $pdo->prepare('SELECT * FROM easa_erules_import_batches WHERE id = ? LIMIT 1');
            $st->execute([$bid]);
            $batchRow = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'easa_erules_import_batches not available']);
        }
        if (!is_array($batchRow)) {
            rl_easa_json_out(404, ['ok' => false, 'error' => 'Batch not found']);
        }
        rl_easa_json_out(200, [
            'ok' => true,
            'batch' => $batchRow,
        ]);
    }

    if ($action === 'tree_children') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        if ($batchId <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        $parentRaw = isset($_GET['parent_uid']) ? trim((string) $_GET['parent_uid']) : null;
        $isRoot = $parentRaw === null || $parentRaw === '';
        try {
            $nodes = easa_erules_tree_children_response_nodes(
                $pdo,
                $batchId,
                $isRoot ? null : $parentRaw
            );
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }
        rl_easa_json_out(200, [
            'ok' => true,
            'batch_id' => $batchId,
            'parent_uid' => $isRoot ? null : $parentRaw,
            'nodes' => $nodes,
        ]);
    }

    if ($action === 'node_detail') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        $nodeUid = trim((string) ($_GET['node_uid'] ?? ''));
        if ($batchId <= 0 || $nodeUid === '') {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id and node_uid required']);
        }
        $res = rl_easa_api_node_detail_build($pdo, $batchId, $nodeUid);
        if (!$res['ok']) {
            rl_easa_json_out($res['http'], ['ok' => false, 'error' => $res['error']]);
        }
        rl_easa_json_out(200, ['ok' => true, 'node' => $res['node']]);
    }

    if ($action === 'easa_ai_chat_bootstrap') {
        rl_easa_ai_chat_bootstrap_output(
            $pdo,
            (int) ($_GET['session_id'] ?? 0),
            (int) ($_GET['before_id'] ?? 0),
            (int) ($_GET['limit'] ?? 5)
        );
    }

    // GET action=source_probe — diagnostic: ERulesId matches in batch source.xml (deploy must include this block).
    if ($action === 'source_probe') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $batchId = (int) ($_GET['batch_id'] ?? 0);
        $nodeUid = trim((string) ($_GET['node_uid'] ?? ''));
        $erulesParam = trim((string) ($_GET['erules_id'] ?? ''));
        if ($batchId <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
        }
        if ($erulesParam === '' && $nodeUid === '') {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'Provide node_uid or erules_id']);
        }
        $batchSummarySql = '
            SELECT id, storage_relpath, status, rows_detected, error_message, parse_phase, updated_at
            FROM easa_erules_import_batches
            WHERE id = ?
            LIMIT 1';
        try {
            $bst = $pdo->prepare($batchSummarySql);
            $bst->execute([$batchId]);
            $batchRow = $bst->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }
        $batchStorageRelpath = is_array($batchRow) ? trim((string) ($batchRow['storage_relpath'] ?? '')) : '';

        $stagingSummary = null;
        $probeId = $erulesParam;

        $stagingSelect = '
                SELECT batch_id, node_uid, node_type, source_erules_id, title, path, breadcrumb,
                       CHAR_LENGTH(COALESCE(plain_text, \'\')) AS plain_len,
                       CHAR_LENGTH(COALESCE(canonical_text, \'\')) AS canonical_len,
                       CHAR_LENGTH(COALESCE(xml_fragment, \'\')) AS fragment_len
                FROM easa_erules_import_nodes_staging
                WHERE batch_id = ?';

        try {
            if ($nodeUid !== '') {
                $st = $pdo->prepare($stagingSelect . ' AND node_uid = ? LIMIT 1');
                $st->execute([$batchId, $nodeUid]);
                $stagingSummary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($stagingSummary !== null && $probeId === '') {
                    $probeId = trim((string) ($stagingSummary['source_erules_id'] ?? ''));
                }
            } elseif ($probeId !== '') {
                $st = $pdo->prepare($stagingSelect . ' AND TRIM(source_erules_id) = ? LIMIT 1');
                $st->execute([$batchId, $probeId]);
                $stagingSummary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {
            rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
        }

        if ($probeId === '') {
            rl_easa_json_out(404, ['ok' => false, 'error' => 'No ERulesId resolved for probe (node missing erules id or staging row not found).']);
        }

        $resolvedAbs = easa_erules_batch_source_xml_absolute_path($pdo, $batchId);
        if ($resolvedAbs === null && $batchStorageRelpath !== '') {
            $cand = rl_project_root() . '/' . str_replace('\\', '/', $batchStorageRelpath);
            $resolvedAbs = is_file($cand) ? $cand : null;
        }

        $exists = $resolvedAbs !== null && is_file($resolvedAbs);
        $readable = $exists && is_readable($resolvedAbs);
        $sizeBytes = $exists ? (@filesize($resolvedAbs)) : false;
        $sha256 = $readable ? (@hash_file('sha256', $resolvedAbs)) : false;

        $matches = [];
        $matchCount = 0;
        if ($resolvedAbs !== null && $readable) {
            $probe = easa_erules_probe_source_candidates_by_erules_id($resolvedAbs, $probeId, 80);
            $matches = is_array($probe['matches'] ?? null) ? $probe['matches'] : [];
            $matchCount = (int) ($probe['match_count'] ?? count($matches));
        }

        rl_easa_json_out(200, [
            'ok' => true,
            'batch_id' => $batchId,
            'staging_summary' => $stagingSummary,
            'batch_row' => is_array($batchRow) ? $batchRow : null,
            'batch_storage_relpath' => $batchStorageRelpath !== '' ? $batchStorageRelpath : null,
            'resolved_source_xml_absolute_path' => $resolvedAbs,
            'source_file_exists' => $exists,
            'source_file_readable' => $readable,
            'source_file_size_bytes' => is_int($sizeBytes) ? $sizeBytes : null,
            'source_file_sha256' => is_string($sha256) ? $sha256 : null,
            'probe_erules_id' => $probeId,
            'match_count' => $matchCount,
            'matches' => $matches,
            'storage_health' => easa_erules_storage_health($batchId),
            'project_root' => rl_project_root(),
        ]);
    }

    if ($action !== 'status') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
    }

    $tablesOk = easa_download_monitor_tables_ok($pdo);
    $stagingOk = easa_erules_staging_tables_ok($pdo);
    $progressOk = easa_erules_batch_progress_available($pdo);
    $monitor = [];
    $batches = [];
    $stagingNodes = 0;
    if ($tablesOk) {
        $monitor = $pdo->query('SELECT id, url, label, checked_at, http_status, final_url, etag, last_modified, content_length, changed_flag, last_error FROM easa_download_monitor ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $batches = $pdo->query('SELECT * FROM easa_erules_import_batches ORDER BY id DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($stagingOk) {
            $stagingNodes = (int) $pdo->query('SELECT COUNT(*) FROM easa_erules_import_nodes_staging')->fetchColumn();
            $stmtN = $pdo->query('SELECT batch_id, COUNT(*) AS c FROM easa_erules_import_nodes_staging GROUP BY batch_id');
            $byBatch = [];
            if ($stmtN instanceof PDOStatement) {
                while ($r = $stmtN->fetch(PDO::FETCH_ASSOC)) {
                    $byBatch[(int) ($r['batch_id'] ?? 0)] = (int) ($r['c'] ?? 0);
                }
            }
            foreach ($batches as $k => $br) {
                $bid = (int) ($br['id'] ?? 0);
                $batches[$k]['staging_nodes'] = $byBatch[$bid] ?? 0;
            }
        }
    }

    $upBytes = rl_easa_ini_bytes('upload_max_filesize');
    $postBytes = rl_easa_ini_bytes('post_max_size');
    $maxBodyBytes = ($upBytes > 0 && $postBytes > 0) ? min($upBytes, $postBytes) : max($upBytes, $postBytes);

    rl_easa_json_out(200, [
        'ok' => true,
        'tables_ok' => $tablesOk,
        'staging_tables_ok' => $stagingOk,
        'progress_columns_ok' => $progressOk,
        'storage_health' => easa_erules_storage_health(null),
        'php_upload_max_filesize' => ini_get('upload_max_filesize'),
        'php_post_max_size' => ini_get('post_max_size'),
        'max_body_bytes' => $maxBodyBytes,
        'migrate_hint' => $tablesOk ? null : 'Apply scripts/sql/resource_library_easa_erules.sql',
        'staging_migrate_hint' => ($tablesOk && !$stagingOk) ? 'Apply scripts/sql/resource_library_easa_erules_staging.sql for XML node staging.' : null,
        'progress_migrate_hint' => ($tablesOk && !$progressOk) ? 'Apply scripts/sql/resource_library_easa_erules_batch_progress.sql for live import progress (parse_phase, heartbeat).' : null,
        'supports_async_parse' => function_exists('fastcgi_finish_request'),
        'indexed_nodes' => $stagingOk ? $stagingNodes : 0,
        'indexed_hint' => $stagingOk
            ? 'Staging rows hold parsed XML nodes (streaming import). Canonical publish + search chunks are a later step.'
            : 'Apply staging migration, then use Parse XML → staging on a batch.',
        'monitor' => $monitor,
        'batches' => $batches,
        'ecfr_configured' => rl_catalog_resolve_ecfr_training_report_edition($pdo) !== null,
    ]);
}

if ($method !== 'POST') {
    rl_easa_json_out(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

// Prefer $_FILES over Content-Type: some stacks omit or rewrite CONTENT_TYPE while still populating FILES.
$hasErulesUpload = isset($_FILES['erules_xml']) && is_array($_FILES['erules_xml']);
$multipartLike = str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'multipart/');

if ($hasErulesUpload) {
    if (!easa_download_monitor_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules.sql first']);
    }
    $err = (int) ($_FILES['erules_xml']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Upload error code ' . $err]);
    }
    $tmp = (string) ($_FILES['erules_xml']['tmp_name'] ?? '');
    $orig = (string) ($_FILES['erules_xml']['name'] ?? 'export.xml');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Invalid upload']);
    }
    $raw = file_get_contents($tmp);
    if ($raw === false) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'Could not read upload']);
    }
    $sha = hash('sha256', $raw);
    $uid = cw_current_user($pdo);
    $userId = is_array($uid) ? (int) ($uid['id'] ?? 0) : 0;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO easa_erules_import_batches (status, original_filename, file_sha256, storage_relpath, file_size, uploaded_by_user_id)
            VALUES (\'uploaded\', ?, ?, \'\', ?, ?)
        ');
        $ins->execute([$orig, $sha, strlen($raw), $userId > 0 ? $userId : null]);
        $batchId = (int) $pdo->lastInsertId();
        if ($batchId <= 0) {
            throw new RuntimeException('Could not create batch row');
        }
        $stored = easa_erules_store_batch_upload($batchId, $tmp);
        $rel = $stored['relpath'];
        $pdo->prepare('UPDATE easa_erules_import_batches SET storage_relpath = ?, file_size = ? WHERE id = ?')->execute([$rel, $stored['size'], $batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        rl_easa_json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    rl_easa_json_out(200, [
        'ok' => true,
        'batch_id' => $batchId,
        'sha256' => $sha,
        'message' => 'File stored as official evidence. Click “Parse XML → staging” for this batch (after applying staging SQL if needed).',
    ]);
}

if ($multipartLike) {
    rl_easa_json_out(400, [
        'ok' => false,
        'error' => 'Missing file field erules_xml, or the upload exceeded PHP limits (post_max_size / upload_max_filesize).',
    ]);
}

$rawIn = file_get_contents('php://input');
$data = json_decode((string) $rawIn, true);
if (!is_array($data)) {
    rl_easa_json_out(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$action = trim((string) ($data['action'] ?? ''));

if ($action === 'probe_monitor') {
    if (!easa_download_monitor_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules.sql']);
    }
    $res = easa_download_monitor_probe_all($pdo);
    rl_easa_json_out(200, ['ok' => true, 'probed' => $res['probed'], 'errors' => $res['errors']]);
}

if ($action === 'search') {
    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    if (!easa_erules_staging_tables_ok($pdo)) {
        rl_easa_json_out(200, [
            'ok' => true,
            'hits' => [],
            'hit_count' => 0,
            'note' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql and parse a batch first.',
        ]);
    }
    $batchFilter = (int) ($data['batch_id'] ?? 0);
    $limit = (int) ($data['limit'] ?? 50);
    $limit = min(200, max(1, $limit));
    $offset = max(0, (int) ($data['offset'] ?? 0));
    $mSearch = rl_easa_search_match_clause($pdo, $q);
    $whereMatch = $mSearch['where'];
    $bind = $mSearch['bind'];
    // Root <document> / frontmatter / toc / backmatter: sort after rows that look like real rules.
    $wrapRank = '(CASE WHEN LOWER(node_type) IN (\'document\',\'frontmatter\',\'toc\',\'backmatter\') THEN 1 ELSE 0 END)';
    $snippetBody = rl_easa_staging_snippet_concat_body($pdo);
    $snippet = "SUBSTRING(TRIM(CONCAT_WS(CHAR(10),
        NULLIF(TRIM(COALESCE(source_erules_id,'')), ''),
        NULLIF(TRIM(COALESCE(title,'')), ''),
        NULLIF(TRIM(COALESCE(source_title,'')), ''),
        NULLIF(TRIM(COALESCE(breadcrumb,'')), ''),
        {$snippetBody})), 1, 520)";
    if ($batchFilter > 0) {
        $sql = "
            SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                   {$snippet} AS snippet
            FROM easa_erules_import_nodes_staging
            WHERE batch_id = ? AND {$whereMatch}
            ORDER BY {$wrapRank} ASC, id ASC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$batchFilter], $bind));
    } else {
        $sql = "
            SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb,
                   {$snippet} AS snippet
            FROM easa_erules_import_nodes_staging
            WHERE {$whereMatch}
            ORDER BY {$wrapRank} ASC, id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
    }
    $hits = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    rl_easa_json_out(200, [
        'ok' => true,
        'hits' => $hits,
        'hit_count' => count($hits),
        'limit' => $limit,
        'offset' => $offset,
        'note' => $hits === []
            ? 'No matches in staging. Searches plain_text' . (easa_erules_staging_has_canonical_column($pdo) ? ', canonical_text' : '') . ', title, source_title, breadcrumb, source_erules_id, and path (with a dotless variant for ids like FCL.055 vs FCL055).'
            : null,
    ]);
}

if ($action === 'easa_ai_chat_bootstrap') {
    rl_easa_ai_chat_bootstrap_output(
        $pdo,
        (int) ($data['session_id'] ?? 0),
        (int) ($data['before_id'] ?? 0),
        (int) ($data['limit'] ?? 5)
    );
}

if ($action === 'easa_ai_chat_session_create') {
    if (!rl_easa_ai_chat_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_ai_chat.sql first']);
    }
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    if ($userId <= 0) {
        rl_easa_json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }
    $title = isset($data['title']) ? trim((string) $data['title']) : '';
    $title = $title !== '' ? substr($title, 0, 255) : null;
    try {
        $pdo->prepare('INSERT INTO easa_ai_chat_sessions (user_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$userId, $title]);
        $sid = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        rl_easa_json_out(503, ['ok' => false, 'error' => $e->getMessage()]);
    }
    rl_easa_json_out(200, ['ok' => true, 'session_id' => $sid]);
}

if ($action === 'parse_batch') {
    @ini_set('memory_limit', '768M');
    @set_time_limit(0);

    if (!easa_erules_staging_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
    }

    $batchId = (int) ($data['batch_id'] ?? 0);
    if ($batchId <= 0) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'batch_id required']);
    }

    $preflight = easa_erules_storage_health($batchId);
    $batchHealth = is_array($preflight['batch'] ?? null) ? $preflight['batch'] : [];
    $sourceReadable = !empty($batchHealth['source_exists']) && !empty($batchHealth['source_readable']);
    if (!$sourceReadable) {
        $expectedRel = (string) ($batchHealth['expected_relpath'] ?? easa_erules_batch_relative_path($batchId));
        $msg = 'Parser refused to start: source.xml missing/unreadable for batch '
            . $batchId . ' at ' . $expectedRel
            . '. Ensure storage/easa_erules/batches exists/writable, then upload again.';
        $pdo->prepare('UPDATE easa_erules_import_batches SET status = \'failed\', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$msg, $batchId]);
        rl_easa_json_out(409, ['ok' => false, 'error' => $msg, 'storage_health' => $preflight]);
    }

    $syncWait = !empty($data['sync_wait']) || !empty($data['sync']);
    $useAsync = !$syncWait && function_exists('fastcgi_finish_request');

    $pdo->prepare('UPDATE easa_erules_import_batches SET status = \'staging\', error_message = NULL WHERE id = ?')->execute([$batchId]);

    $runImport = function () use ($pdo, $batchId): array {
        return easa_erules_import_batch_xml_to_staging($pdo, $batchId);
    };

    $finishOk = function (array $result) use ($pdo, $batchId): void {
        easa_erules_import_finalize_success($pdo, $batchId, (int) $result['imported'], $result['publication_meta'] ?? null);
    };

    $finishErr = function (Throwable $e) use ($pdo, $batchId): void {
        easa_erules_import_finalize_failure($pdo, $batchId, $e->getMessage());
    };

    if ($useAsync) {
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        http_response_code(202);
        echo json_encode([
            'ok' => true,
            'async' => true,
            'batch_id' => $batchId,
            'message' => 'Import started on the server. Status updates every few seconds in the batch row (poll batch_progress).',
            'poll_hint' => 'GET ?action=batch_progress&batch_id=' . $batchId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }

        try {
            $result = $runImport();
            $finishOk($result);
        } catch (Throwable $e) {
            $finishErr($e);
        }
        exit(0);
    }

    try {
        $result = $runImport();
        $finishOk($result);
        rl_easa_json_out(200, [
            'ok' => true,
            'async' => false,
            'imported' => (int) $result['imported'],
            'batch_id' => $batchId,
            'publication_meta' => $result['publication_meta'],
            'message' => 'Parsed into easa_erules_import_nodes_staging. Review rows before any canonical publish.',
        ]);
    } catch (Throwable $e) {
        $finishErr($e);
        rl_easa_json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'regulatory_compare_ai') {
    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    $useAi = !empty($data['use_ai']);
    $includeEcfr = !empty($data['include_ecfr']) || rl_easa_query_requests_us_ecfr_context($q);
    $titleNum = (int) ($data['ecfr_title_number'] ?? 14);
    $section = trim((string) ($data['ecfr_section'] ?? ''));
    if ($includeEcfr && $section === '') {
        $section = rl_easa_infer_ecfr_section_from_query($q);
    }
    $compareBatchId = (int) ($data['batch_id'] ?? 0);
    $userFirstName = rl_easa_current_user_first_name($pdo);

    $canon = rl_easa_build_ai_canonical_regulatory_bundle($pdo, $q, $compareBatchId);
    $easaCtx = (string) ($canon['summary'] ?? '');
    $easaModelBundle = trim((string) ($canon['model_bundle'] ?? ''));

    $ecfrHtml = '';
    $ecfrNote = '';
    if ($includeEcfr) {
        if ($section === '') {
            $ecfrNote = 'U.S. comparison was requested but no 14 CFR section (e.g. 61.57) could be inferred — mention that in your reply and invite the user to name a section.';
        } else {
            try {
                $cfg = rl_catalog_ecfr_runtime_config($pdo);
                $client = new EcfrApiClient($cfg['api_base_url']);
                $snap = $client->resolveTitleSnapshotDate($titleNum > 0 ? $titleNum : 14);
                $xml = $client->fetchSectionXml($titleNum > 0 ? $titleNum : 14, $section, $snap);
                $ecfrHtml = $client->sectionXmlToHtml($xml);
                $browse = $client->sectionBrowseUrl($titleNum > 0 ? $titleNum : 14, $section);
                $ecfrNote = 'Official excerpt via eCFR versioner API · snapshot ' . $snap . ' · Browse: ' . $browse;
            } catch (Throwable $e) {
                $ecfrNote = 'eCFR fetch failed: ' . $e->getMessage();
            }
        }
    }

    $chatSupported = rl_easa_ai_chat_tables_ok($pdo);
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    $sessionId = (int) ($data['session_id'] ?? 0);
    if ($chatSupported && $userId > 0) {
        try {
            if ($sessionId <= 0) {
                $pdo->prepare('INSERT INTO easa_ai_chat_sessions (user_id, title, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())')->execute([$userId]);
                $sessionId = (int) $pdo->lastInsertId();
            } else {
                $chk = $pdo->prepare('SELECT id FROM easa_ai_chat_sessions WHERE id = ? AND user_id = ? LIMIT 1');
                $chk->execute([$sessionId, $userId]);
                if (!$chk->fetchColumn()) {
                    $pdo->prepare('INSERT INTO easa_ai_chat_sessions (user_id, title, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())')->execute([$userId]);
                    $sessionId = (int) $pdo->lastInsertId();
                }
            }
            $pdo->prepare('INSERT INTO easa_ai_chat_messages (session_id, role, content, response_json, created_at) VALUES (?, \'user\', ?, NULL, NOW())')->execute([$sessionId, $q]);
            $pdo->prepare('UPDATE easa_ai_chat_sessions SET updated_at = NOW(), title = COALESCE(title, ?) WHERE id = ? AND user_id = ?')->execute([substr($q, 0, 255), $sessionId, $userId]);
        } catch (Throwable) {
            $sessionId = 0;
        }
    } else {
        $sessionId = 0;
    }

    $payload = [
        'ok' => true,
        'user_first_name' => $userFirstName !== '' ? $userFirstName : null,
        'easa_context_note' => $easaCtx,
        'easa_staging_hits' => (int) ($canon['hit_count'] ?? 0),
        'easa_sources' => is_array($canon['sources'] ?? null) ? $canon['sources'] : [],
        'canonical_nodes_loaded' => is_array($canon['full_nodes'] ?? null) ? count($canon['full_nodes']) : 0,
        'ecfr_html' => $ecfrHtml !== '' ? $ecfrHtml : null,
        'ecfr_note' => $ecfrNote !== '' ? $ecfrNote : null,
        'ai_answer' => '',
        'ai_error' => null,
        'answer_markdown' => '',
        'primary_references' => [],
        'secondary_references' => [],
        'confidence' => 'medium',
        'session_id' => $sessionId > 0 ? $sessionId : null,
        'chat_supported' => $chatSupported && $userId > 0,
    ];

    if (!$useAi) {
        rl_easa_json_out(200, $payload);
    }

    $loadedNodeCount = is_array($canon['full_nodes'] ?? null) ? count($canon['full_nodes']) : 0;
    $bundle = "EU / EASA — FULL canonical regulation payloads from this installation (same resolver as GET node_detail). "
        . "Each CANONICAL NODE block includes structured_blocks_json, canonical_text, plain_text, title_display, breadcrumb, batch_id, node_uid, ERulesId. "
        . "Answer ONLY from this material; quote accurately. If blocks are empty or truncated, say so.\n\n";
    if ($easaModelBundle !== '') {
        $bundle .= sprintf(
            "*** %d CANONICAL NODE block(s) follow. These were selected by the server retrieval pipeline to be the most relevant material for the user's question. ***\n"
            . "*** You MUST treat this list as the available regulatory material. If even one block is about the topic asked, answer FROM IT — do not claim the bundle 'does not include' the topic. ***\n\n",
            $loadedNodeCount
        );
        $bundle .= $easaModelBundle . "\n\n";
    } else {
        $bundle .= "(No canonical node payloads loaded — refer to easa_context_note above.)\n\n";
    }
    if ($ecfrHtml !== '') {
        $strip = preg_replace('/\s+/', ' ', strip_tags($ecfrHtml));
        $strip = is_string($strip) ? trim($strip) : '';
        if (strlen($strip) > 12000) {
            $strip = substr($strip, 0, 12000) . '…';
        }
        $bundle .= "U.S. 14 CFR excerpt (eCFR API, for comparison only):\n" . $strip . "\n\n";
    }

    $jsonInstructions = <<<'TXT'
You are "Maya", a warm, professional EASA regulations mentor — like a friendly flight instructor, NOT a legal memo or database UI.

Your entire model output MUST be a single JSON object (no markdown fences, no preface) with exactly these keys:

- "answer_markdown": string. Tight, friendly Markdown for a pilot or instructor.

  STRICT STYLE (this is what makes the reply useful — don't drift from it):
    * Open with a single short greeting line: "Hi <FirstName>," when a first name is provided, otherwise "Hi there,".
    * Second line: state the location of the topic in the tree, e.g. "to get more details on the EASA Commercial Pilot Licence, the following rules can be found under Aircrew, Annex I (Part-FCL), Subpart D – Commercial Pilot Licence."
    * Then a compact bulleted outline organised by SECTION (Section 1, Section 2, …) when the bundle shows that structure. Each bullet lists rule TITLES with their ID in parentheses, e.g.
        - Common Requirements: Minimum Age (FCL.300), Privileges and Conditions (FCL.305), Theoretical Knowledge Examinations (FCL.310), Training Course (FCL.315), Skill Test (FCL.320) — Section 1.
        - Section 2 – Aeroplane category: Training Course (FCL.315.A), Specific Requirements for MPL holders (FCL.325.A).
    * End with ONE short focused offer to go deeper, e.g. "What would you like me to check more in depth for you here?". Do not list multiple numbered options unless the user explicitly asked you to narrow.
    * NEVER include disclaimers, hedges, or commentary about the dataset. Do NOT say "the indexed material does not include", "the bundle does not contain", "in this installation slice", "the available material is mostly", or any equivalent phrasing. Do NOT mention "batches", "staging", "canonical", "node_uid", "ERulesId", "retrieval", or how the back-end works.
    * Do NOT add boilerplate like "always verify against the official EASA publication".
    * Keep total length around 90–180 words for a straight overview question. Do not pad.
    * Use FRIENDLY corpus names only ("EAR Flight Crew", "EAR Flight Operations", "EAR Part-IS", "EAR CS-FSTD"). Never raw .xml file names. Never internal IDs in prose — rule ids like FCL.300 ARE fine in prose because they're the natural way pilots cite rules.

  CONTENT RULES:
    * Build the answer from the CANONICAL NODE blocks in the bundle. The bundle's breadcrumb/title fields tell you which Subpart and Section each rule lives under — use those to assemble the structured outline.
    * If the user's question is already specific (mentions a licence type or rule id), answer directly. Don't ask narrowing questions before giving the overview.
    * If multiple licence categories are present (e.g. Subpart D has Section 2 for aeroplanes, Section 3 for helicopters, etc.), summarise the structure and only expand the category the user asked about (or list available categories and offer to expand one at the end).
    * If, AND ONLY IF, after scanning the bundle there is genuinely no Part-FCL node about the asked topic, say in one short line "I don't have those specific rules indexed here yet — want me to point you to where they live in the regulation?" and stop.

- "primary_references": array of objects (the UI shows these as chips inside your bubble — they are how the user clicks through). Each object MUST have:
    * "title" (string, human section title — e.g. "FCL.300 CPL — Minimum age". Never raw .xml filenames, never internal IDs).
    * "batch_id" (int)
    * "node_uid" (string)
    * "erules_id" (string or empty)
    * "matched_terms" (array of short strings to highlight)
    * "quote" (short verbatim excerpt copied from the bundle for that node)
  Limit primary_references to the 4–8 rules you actually named in the outline. Prefer specific rule / AMC / GM nodes over TOC / wrapper / document / frontmatter nodes when both are available.

- "secondary_references": same object shape (optional).

- "confidence": "high" | "medium" | "low"

Only cite batch_id/node_uid pairs that actually appear in the CANONICAL NODE blocks of the bundle. Do not invent ERulesIds.
TXT;

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "You are Maya, an EASA Easy Access mentor. Use ONLY the canonical EASA bundles provided plus any optional U.S. eCFR excerpt in the bundle. "
                                . $jsonInstructions
                                . " When U.S. text is present, clearly label it as U.S. 14 CFR for comparison. Greet by first name when provided (e.g. \"Hi {name},\"). Do not add any boilerplate verification footer; the style rules above are the final word on tone.",
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "User first name:\n" . ($userFirstName !== '' ? $userFirstName : '(unknown)') . "\n\nQuestion:\n" . $q . "\n\nReference bundle:\n" . $bundle,
                        ],
                    ],
                ],
            ],
        ], 180);
        $parsed = rl_easa_parse_ai_compare_response($resp);
        $payload['answer_markdown'] = $parsed['answer_markdown'];
        $payload['primary_references'] = $parsed['primary_references'];
        $payload['secondary_references'] = $parsed['secondary_references'];
        $payload['confidence'] = $parsed['confidence'];
        $payload['ai_answer'] = $parsed['answer_markdown'];
        if ($userFirstName !== '' && $payload['ai_answer'] !== '' && stripos($payload['ai_answer'], $userFirstName) === false) {
            $payload['ai_answer'] = $userFirstName . ', ' . $payload['ai_answer'];
            $payload['answer_markdown'] = $payload['ai_answer'];
        }
        if ($chatSupported && $userId > 0 && $sessionId > 0) {
            $persist = [
                'ok' => true,
                'answer_markdown' => $payload['answer_markdown'],
                'primary_references' => $payload['primary_references'],
                'secondary_references' => $payload['secondary_references'],
                'confidence' => $payload['confidence'],
            ];
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $pj = json_encode($persist, $flags);
            if ($pj === false) {
                $pj = '{"ok":false}';
            }
            try {
                $pdo->prepare('INSERT INTO easa_ai_chat_messages (session_id, role, content, response_json, created_at) VALUES (?, \'assistant\', ?, ?, NOW())')->execute([$sessionId, $payload['answer_markdown'], $pj]);
                $pdo->prepare('UPDATE easa_ai_chat_sessions SET updated_at = NOW() WHERE id = ?')->execute([$sessionId]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable $e) {
        $payload['ai_error'] = $e->getMessage();
    }

    rl_easa_json_out(200, $payload);
}

rl_easa_json_out(400, ['ok' => false, 'error' => 'Unknown action']);
