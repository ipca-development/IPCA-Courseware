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
require_once __DIR__ . '/../../../src/easa_semantic_map.php';

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

    /** For phrase synonym matching: lowercase, strip punctuation, normalise US↔UK spellings and basic plurals so the corpus
        (which uses EASA / British spelling) is reachable from common natural-language US wording. */
    $qlBase = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($q)));
    /** US ↔ EASA spelling: EASA uses British spellings throughout (aeroplane, licence, manoeuvre, …). Map US forms to the EASA form first. */
    $qlBase = str_replace(
        [
            'airplanes', 'airplane',
            'licenses', 'license',
            'maneuvers', 'maneuver',
            'organizations', 'organization',
            'authorizes', 'authorize',
            'recognizes', 'recognize',
        ],
        [
            'aeroplanes', 'aeroplane',
            'licences', 'licence',
            'manoeuvres', 'manoeuvre',
            'organisations', 'organisation',
            'authorises', 'authorise',
            'recognises', 'recognise',
        ],
        $qlBase
    );
    /** Lightweight plural fold for phrase lookup: collapse trailing "s" on common EASA domain nouns so plural queries match singular synonyms. */
    $qlBase = preg_replace_callback(
        '/\b(licences|aeroplanes|helicopters|balloons|sailplanes|gliders|ratings|examinations|tests|courses|operators|operations)\b/u',
        static fn(array $m): string => rtrim($m[1], 's'),
        $qlBase
    ) ?? $qlBase;
    $ql = ' ' . $qlBase . ' ';
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
function rl_easa_query_topic_filter(string $q, array $intent = []): array
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

    /** Intent-driven override: when the AI intent step says corpus="aircrew", treat the question
        as Aircrew/Part-FCL even if the user used wording the keyword list didn't anticipate
        (e.g. "I want to fly commercially for a living" → CPL → corpus aircrew). Same for Part-IS. */
    $intentCorpus = strtolower((string) ($intent['corpus'] ?? ''));
    if ($intentCorpus === 'aircrew') {
        $isFcl = true;
    } elseif ($intentCorpus === 'part_is') {
        $isPartis = true;
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
function rl_easa_query_batch_filtering(PDO $pdo, string $q, array $aiIntent = []): array
{
    $intent = rl_easa_query_topic_filter($q, $aiIntent);
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
 * STEP 1 of the two-step pipeline: ask the model to read the user's free-text question
 * and return a structured "intent" object. This is what makes Maya robust to phrasing
 * variations (plurals, US/UK spelling, abbreviation vs phrase, indirect topic references)
 * without us hand-coding every synonym.
 *
 * On success returns:
 *   ['ok' => true, 'fallback' => false, 'intent' => [...], 'error' => null]
 * On any failure (API error, timeout, model returned non-JSON) returns:
 *   ['ok' => false, 'fallback' => true, 'intent' => [...empty defaults...], 'error' => string]
 *
 * The caller MUST always be able to keep going with the empty defaults — retrieval falls
 * back to the keyword-only pipeline in that case. A user-visible "(This was a fall-back)"
 * note is appended to the final answer by the calling action when fallback is true.
 *
 * @param list<array{role?: string, content?: string}> $history Optional recent conversation
 *        turns (oldest first). Used so short follow-ups like "What are the requirements?"
 *        are interpreted in context of the previous turn.
 * @return array{ok: bool, fallback: bool, intent: array<string, mixed>, error: ?string}
 */
function rl_easa_ai_extract_query_intent(string $q, array $history = []): array
{
    $empty = [
        'corpus' => null,
        'licence' => null,
        'category' => null,
        'topics' => [],
        'rule_ids' => [],
        'keywords' => [],
        'summary' => '',
        'wants_us_compare' => false,
        'cfr_section_hints' => [],
    ];
    $q = trim($q);
    if ($q === '') {
        return ['ok' => true, 'fallback' => false, 'intent' => $empty, 'error' => null];
    }

    $instructions = <<<'TXT'
You are a query analyser for an EASA Easy Access regulations retrieval engine. Read the
user's free-text question and return a SINGLE JSON object (no markdown fences, no prose,
no commentary). The JSON must have exactly these keys (in addition to the EASA fields)
when the user wants a U.S. comparison:

  - "wants_us_compare": true | false. True when the user mentions FAA, 14 CFR, eCFR,
    "FAR", "the US", "in the United States", or otherwise asks for a comparison with
    U.S. regulations. False for pure EASA questions.
  - "cfr_section_hints": array of explicit 14 CFR section ids mentioned in the question
    (e.g. ["61.57", "91.205"]). Format: "TT.NNN" where TT is the title number and
    NNN is the section. Empty array if none.

For EASA fields, the keys + allowed values are:

- "corpus":
    one of "aircrew" | "air_ops" | "part_is" | "cs_fstd" | null.
    Use:
      * "aircrew" for anything about pilot licensing, Part-FCL, Part-MED, Part-ARA-FCL,
        Part-ORA-FCL, Part-DTO, language proficiency, theoretical knowledge, skill tests,
        instructor / examiner certificates, PPL / CPL / ATPL / MPL / LAPL / SFCL / BFCL /
        IR / BIR / class & type ratings.
      * "air_ops" for Part-CAT, Part-NCO, Part-NCC, Part-SPO, Part-ORO, cabin crew (Part-CC),
        commercial operations, flight time limitations.
      * "part_is" for Part-IS information security, ISMS, cyber, infosec.
      * "cs_fstd" for flight simulation training device certification specifications.
      * null only if the question is greeting / off-topic / cannot be classified.

- "licence":
    one EASA licence/rating abbreviation when the question is clearly about a specific
    licence or rating, otherwise null. Allowed values:
    "PPL" | "CPL" | "ATPL" | "MPL" | "LAPL" | "SFCL" | "BFCL" | "IR" | "BIR" |
    "FI" | "TRI" | "CRI" | "IRI" | "EXAMINER" | null.
    US "license" and UK "licence" map to the same value.

- "category":
    aircraft category when the question is clearly limited to one, otherwise null.
    Allowed values: "aeroplane" | "helicopter" | "balloon" | "sailplane" | "airship" |
    "powered_lift" | null.
    Always use EASA British spelling. US "airplane" / "airplanes" maps to "aeroplane".

- "topics":
    array of zero or more topic tags chosen ONLY from this fixed vocabulary:
    "overview", "minimum_age", "privileges", "theoretical_knowledge", "training",
    "skill_test", "proficiency_check", "medical", "language_proficiency",
    "experience", "credit", "validation", "conversion", "renewal",
    "instructor_certificate", "examiner_certificate".
    Use "overview" when the user wants the general requirements / structure for a licence
    and isn't drilling into one sub-area.

- "rule_ids":
    array of explicit EASA rule ids the user already mentioned in the question
    (e.g. "FCL.300", "MED.A.030", "ARA.FCL.300"). Empty array if none.
    Do NOT invent or expand rule ids here — that happens server-side.

- "keywords":
    up to 8 extra natural-language keywords or short phrases (EASA British spelling) that
    should be searched in addition to the structured fields. Prefer phrases that appear in
    EASA legal text (e.g. "theoretical knowledge examination", "skill test",
    "privileges and conditions"). Do NOT include generic chatter
    (need / details / more / regulations / requirements / etc.).

- "summary":
    one short sentence (≤ 20 words) restating what the user is actually asking, in plain
    English. Used for logging + to focus the answering model.

Examples:

Q: "What is required to obtain a private pilot license?"
A: {"corpus":"aircrew","licence":"PPL","category":null,"topics":["overview","training","theoretical_knowledge","skill_test","minimum_age"],"rule_ids":[],"keywords":["private pilot licence"],"summary":"User wants the EASA Part-FCL requirements to obtain a Private Pilot Licence (PPL)."}

Q: "I would like to learn more about the Regulations on Private Pilot Licenses for Airplanes?"
A: {"corpus":"aircrew","licence":"PPL","category":"aeroplane","topics":["overview","training","skill_test","privileges"],"rule_ids":[],"keywords":["private pilot licence","aeroplane"],"summary":"User wants the EASA Part-FCL rules for the PPL(A) aeroplane category."}

Q: "I need to get more details on the regulations under Part FCL for the Commercial Pilot License."
A: {"corpus":"aircrew","licence":"CPL","category":null,"topics":["overview","training","theoretical_knowledge","skill_test","privileges","minimum_age"],"rule_ids":[],"keywords":["commercial pilot licence"],"summary":"User wants the EASA Part-FCL Commercial Pilot Licence (CPL) rules overview."}

Q: "What does FCL.055 say about language proficiency?"
A: {"corpus":"aircrew","licence":null,"category":null,"topics":["language_proficiency"],"rule_ids":["FCL.055"],"keywords":["language proficiency"],"summary":"User wants the text of FCL.055 on language proficiency."}

Q: "Tell me about information security rules"
A: {"corpus":"part_is","licence":null,"category":null,"topics":[],"rule_ids":[],"keywords":["information security","ISMS"],"summary":"User wants an overview of EASA Part-IS information security requirements."}

Q: "Hi"
A: {"corpus":null,"licence":null,"category":null,"topics":[],"rule_ids":[],"keywords":[],"summary":"Greeting only; no regulatory topic identified."}

Q: "Compare the EASA PPL with the FAA Private Pilot Licence."
A: {"corpus":"aircrew","licence":"PPL","category":null,"topics":["overview","training","theoretical_knowledge","skill_test","privileges","minimum_age"],"rule_ids":[],"keywords":["private pilot licence","Part-FCL"],"summary":"User wants a side-by-side comparison of the EASA PPL with the FAA Private Pilot Certificate.","wants_us_compare":true,"cfr_section_hints":[]}

Q: "What does 14 CFR 61.57 say compared to FCL.060?"
A: {"corpus":"aircrew","licence":null,"category":null,"topics":["experience"],"rule_ids":["FCL.060"],"keywords":["recent flight experience","passenger carrying"],"summary":"User wants 14 CFR 61.57 (recency of experience) compared to FCL.060.","wants_us_compare":true,"cfr_section_hints":["61.57"]}

Q: "How does EASA Part-FCL theoretical knowledge for the ATPL compare with the FAA ATP?"
A: {"corpus":"aircrew","licence":"ATPL","category":null,"topics":["theoretical_knowledge"],"rule_ids":[],"keywords":["airline transport pilot","theoretical knowledge"],"summary":"User wants the EASA ATPL theoretical knowledge subjects compared with FAA ATP knowledge requirements.","wants_us_compare":true,"cfr_section_hints":[]}
TXT;

    /** Render up to the last 6 turns of conversation as a compact transcript so short
        follow-ups ("What are those requirements?") inherit the previous topic. Each turn
        is hard-capped at 600 chars so a long answer body can't blow up the prompt. */
    $historyBlock = '';
    if ($history !== []) {
        $lines = [];
        $start = max(0, count($history) - 6);
        for ($i = $start; $i < count($history); $i++) {
            $row = $history[$i];
            if (!is_array($row)) {
                continue;
            }
            $role = strtolower((string) ($row['role'] ?? ''));
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if (mb_strlen($content) > 600) {
                $content = mb_substr($content, 0, 600) . '…';
            }
            $lines[] = ($role === 'user' ? 'User:' : 'Maya:') . ' ' . $content;
        }
        if ($lines !== []) {
            $historyBlock = "Recent conversation (oldest first, last 6 turns):\n" . implode("\n", $lines) . "\n\n";
        }
    }

    try {
        $resp = cw_openai_responses([
            'model' => cw_openai_model(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $instructions],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $historyBlock . 'New user question:' . "\n" . $q],
                    ],
                ],
            ],
            'max_output_tokens' => 700,
        ], 45);
    } catch (Throwable $e) {
        return ['ok' => false, 'fallback' => true, 'intent' => $empty, 'error' => $e->getMessage()];
    }

    try {
        $j = cw_openai_extract_json_text($resp);
    } catch (Throwable $e) {
        return ['ok' => false, 'fallback' => true, 'intent' => $empty, 'error' => 'intent JSON parse: ' . $e->getMessage()];
    }
    if (!is_array($j)) {
        return ['ok' => false, 'fallback' => true, 'intent' => $empty, 'error' => 'intent JSON not an object'];
    }

    /** Normalise + clamp into the strict shape, regardless of what the model returns. */
    $allowedCorpus = ['aircrew' => true, 'air_ops' => true, 'part_is' => true, 'cs_fstd' => true];
    $corpus = strtolower(trim((string) ($j['corpus'] ?? '')));
    $corpus = isset($allowedCorpus[$corpus]) ? $corpus : null;

    $allowedLicence = ['PPL' => true, 'CPL' => true, 'ATPL' => true, 'MPL' => true, 'LAPL' => true,
        'SFCL' => true, 'BFCL' => true, 'IR' => true, 'BIR' => true, 'FI' => true,
        'TRI' => true, 'CRI' => true, 'IRI' => true, 'EXAMINER' => true];
    $licence = strtoupper(trim((string) ($j['licence'] ?? '')));
    $licence = isset($allowedLicence[$licence]) ? $licence : null;

    $allowedCategory = ['aeroplane' => true, 'helicopter' => true, 'balloon' => true,
        'sailplane' => true, 'airship' => true, 'powered_lift' => true];
    $category = strtolower(trim((string) ($j['category'] ?? '')));
    $category = isset($allowedCategory[$category]) ? $category : null;

    $allowedTopics = ['overview' => true, 'minimum_age' => true, 'privileges' => true,
        'theoretical_knowledge' => true, 'training' => true, 'skill_test' => true,
        'proficiency_check' => true, 'medical' => true, 'language_proficiency' => true,
        'experience' => true, 'credit' => true, 'validation' => true, 'conversion' => true,
        'renewal' => true, 'instructor_certificate' => true, 'examiner_certificate' => true];
    $topics = [];
    foreach ((array) ($j['topics'] ?? []) as $t) {
        $tn = strtolower(trim((string) $t));
        if ($tn !== '' && isset($allowedTopics[$tn]) && !in_array($tn, $topics, true)) {
            $topics[] = $tn;
        }
    }

    $ruleIds = [];
    foreach ((array) ($j['rule_ids'] ?? []) as $r) {
        $rn = strtoupper(trim((string) $r));
        /** Accept anything that looks like a real EASA rule id (FCL.300, MED.A.030, ARA.FCL.300, ORO.GEN.110, …). */
        if ($rn !== '' && preg_match('/^[A-Z]{2,5}(?:\.[A-Z0-9]+)+$/u', $rn) === 1 && !in_array($rn, $ruleIds, true)) {
            $ruleIds[] = $rn;
        }
    }

    $keywords = [];
    foreach ((array) ($j['keywords'] ?? []) as $k) {
        $kn = trim((string) $k);
        if ($kn !== '' && mb_strlen($kn) <= 80 && !in_array($kn, $keywords, true)) {
            $keywords[] = $kn;
            if (count($keywords) >= 8) {
                break;
            }
        }
    }

    $summary = trim((string) ($j['summary'] ?? ''));
    if (mb_strlen($summary) > 240) {
        $summary = mb_substr($summary, 0, 240) . '…';
    }

    /** U.S. comparison fields. Default false / empty so existing EASA-only callers are unaffected. */
    $wantsUsCompare = !empty($j['wants_us_compare']);
    $cfrHints = [];
    foreach ((array) ($j['cfr_section_hints'] ?? []) as $h) {
        $hs = trim((string) $h);
        /** Accept "61.57" / "91.205c" / "121.155" form. Reject anything that looks like an EASA rule id. */
        if ($hs !== '' && preg_match('/^\d{1,2}\.\d{1,4}[a-z]?$/i', $hs)) {
            $hs = strtolower($hs);
            if (!in_array($hs, $cfrHints, true)) {
                $cfrHints[] = $hs;
                if (count($cfrHints) >= 6) {
                    break;
                }
            }
        }
    }

    return [
        'ok' => true,
        'fallback' => false,
        'intent' => [
            'corpus' => $corpus,
            'licence' => $licence,
            'category' => $category,
            'topics' => $topics,
            'rule_ids' => $ruleIds,
            'keywords' => $keywords,
            'summary' => $summary,
            'wants_us_compare' => $wantsUsCompare,
            'cfr_section_hints' => $cfrHints,
        ],
        'error' => null,
    ];
}

/**
 * Map the structured intent from rl_easa_ai_extract_query_intent into extra search needles
 * for the staging bundle. Needles are returned high-signal first so the retrieval row
 * budget is spent on actually relevant rules.
 *
 * @param array<string, mixed> $intent
 * @return list<string>
 */
function rl_easa_intent_to_extra_needles(array $intent): array
{
    $out = [];
    $push = static function (array &$bucket, string $value): void {
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

    /** 1. Explicit rule ids the user mentioned — strongest signal. */
    foreach ((array) ($intent['rule_ids'] ?? []) as $rid) {
        if (is_string($rid) && $rid !== '') {
            $push($out, strtoupper(trim($rid)));
        }
    }

    /** 2. Licence-family rule id expansions. These are the canonical sub-rule lists that
        actually live under each Part-FCL subpart in EASA Aircrew. */
    $licence = strtoupper((string) ($intent['licence'] ?? ''));
    $licenceRuleFamilies = [
        'LAPL' => ['FCL.100', 'FCL.105', 'FCL.110', 'FCL.115', 'FCL.120', 'FCL.125', 'FCL.130', 'FCL.135'],
        'PPL'  => ['FCL.200', 'FCL.205', 'FCL.210', 'FCL.215', 'FCL.220', 'FCL.225', 'FCL.230', 'FCL.235'],
        'CPL'  => ['FCL.300', 'FCL.305', 'FCL.310', 'FCL.315', 'FCL.320', 'FCL.325'],
        'MPL'  => ['FCL.400', 'FCL.405', 'FCL.410', 'FCL.415', 'FCL.420', 'FCL.425', 'FCL.430', 'FCL.435'],
        'ATPL' => ['FCL.500', 'FCL.505', 'FCL.510', 'FCL.515', 'FCL.520', 'FCL.525'],
        'IR'   => ['FCL.600', 'FCL.605', 'FCL.610', 'FCL.615', 'FCL.620', 'FCL.625', 'FCL.630'],
        'BIR'  => ['FCL.835'],
        'SFCL' => ['SFCL.115', 'SFCL.125', 'SFCL.130', 'SFCL.135', 'SFCL.140', 'SFCL.145', 'SFCL.150', 'SFCL.155'],
        'BFCL' => ['BFCL.115', 'BFCL.125', 'BFCL.130', 'BFCL.135', 'BFCL.140', 'BFCL.145', 'BFCL.150', 'BFCL.155'],
        'FI'   => ['FCL.905', 'FCL.910', 'FCL.915', 'FCL.920', 'FCL.930', 'FCL.940'],
        'TRI'  => ['FCL.905.TRI', 'FCL.910.TRI', 'FCL.915.TRI', 'FCL.920.TRI', 'FCL.930.TRI'],
        'CRI'  => ['FCL.905.CRI', 'FCL.910.CRI', 'FCL.915.CRI', 'FCL.920.CRI', 'FCL.930.CRI'],
        'IRI'  => ['FCL.905.IRI', 'FCL.915.IRI', 'FCL.920.IRI', 'FCL.930.IRI'],
        'EXAMINER' => ['FCL.1000', 'FCL.1005', 'FCL.1010', 'FCL.1015', 'FCL.1020', 'FCL.1025'],
    ];
    if ($licence !== '' && isset($licenceRuleFamilies[$licence])) {
        foreach ($licenceRuleFamilies[$licence] as $rid) {
            $push($out, $rid);
        }
        /** Keep the licence abbreviation as a needle too (LIKE %CPL% matches titles like "FCL.300 CPL – Minimum age"). */
        $push($out, $licence);
    }

    /** Licence-specific EASA Part-FCL appendices. These cross-reference the training-course
        layouts, skill-test schedules and credit tables and are essential when the user asks
        about course structure / pre-entry / experience. */
    $licenceAppendices = [
        'PPL'  => ['Appendix 9'],
        'LAPL' => ['Appendix 9'],
        'CPL'  => ['Appendix 3', 'Appendix 4'],
        'ATPL' => ['Appendix 3', 'Appendix 9'],
        'MPL'  => ['Appendix 5'],
        'IR'   => ['Appendix 6', 'Appendix 7', 'Appendix 9'],
        'BIR'  => ['Appendix 8'],
    ];
    if ($licence !== '' && isset($licenceAppendices[$licence])) {
        foreach ($licenceAppendices[$licence] as $appx) {
            $push($out, $appx);
        }
    }

    /** 3. Topic-specific RULE IDs first (highest signal — FCL.025/030/055 etc.). These are
        intentionally pushed before the broader phrase needles so even when the 16-needle cap
        clips the tail, the precise rule ids survive. */
    $topicRuleIds = [
        'theoretical_knowledge'  => ['FCL.025'],
        'skill_test'             => ['FCL.030'],
        'language_proficiency'   => ['FCL.055'],
        'medical'                => ['MED.A.030'],
        'instructor_certificate' => ['FCL.900'],
        'examiner_certificate'   => ['FCL.1000'],
    ];
    $topicsIn = (array) ($intent['topics'] ?? []);
    foreach ($topicsIn as $topic) {
        $t = is_string($topic) ? strtolower(trim($topic)) : '';
        if ($t !== '' && isset($topicRuleIds[$t])) {
            foreach ($topicRuleIds[$t] as $tn) {
                $push($out, $tn);
            }
        }
    }

    /** 4. Category-specific suffix expansion. EASA uses .A (aeroplane), .H (helicopter),
        .S (sailplane), .B (balloon), .As (airship), .PL (powered-lift). Only added when
        the user constrained to a single category — and only for licences that actually
        have category-specific sub-rules. The IR / BIR rule set is category-agnostic so the
        .A variants don't exist there; skip them to avoid wasting needle budget. */
    $category = strtolower((string) ($intent['category'] ?? ''));
    $categorySuffix = [
        'aeroplane' => 'A', 'helicopter' => 'H', 'balloon' => 'B',
        'sailplane' => 'S', 'airship' => 'As', 'powered_lift' => 'PL',
    ];
    $licencesWithCategorySuffix = ['LAPL' => true, 'PPL' => true, 'CPL' => true, 'ATPL' => true];
    if ($licence !== ''
        && isset($licenceRuleFamilies[$licence])
        && isset($categorySuffix[$category])
        && isset($licencesWithCategorySuffix[$licence])) {
        $suf = $categorySuffix[$category];
        foreach ($licenceRuleFamilies[$licence] as $rid) {
            $push($out, $rid . '.' . $suf);
        }
    }

    /** 5. Topic phrases — broader LIKE matches against legal text. */
    $topicPhrases = [
        'minimum_age'            => ['minimum age'],
        'privileges'             => ['privileges and conditions'],
        'theoretical_knowledge'  => ['theoretical knowledge', 'theoretical knowledge examination'],
        'training'               => ['training course'],
        'skill_test'             => ['skill test'],
        'proficiency_check'      => ['proficiency check'],
        'medical'                => ['medical certificate', 'Part-MED'],
        'language_proficiency'   => ['language proficiency'],
        'experience'             => ['flight experience'],
        'credit'                 => ['crediting'],
        'validation'             => ['validation'],
        'conversion'             => ['conversion'],
        'renewal'                => ['renewal', 'revalidation'],
        'instructor_certificate' => ['instructor'],
        'examiner_certificate'   => ['examiner'],
    ];
    foreach ($topicsIn as $topic) {
        $t = is_string($topic) ? strtolower(trim($topic)) : '';
        if ($t !== '' && isset($topicPhrases[$t])) {
            foreach ($topicPhrases[$t] as $tn) {
                $push($out, $tn);
            }
        }
    }

    /** 6. Free-text keywords from the model — last so they don't hog the budget. */
    foreach ((array) ($intent['keywords'] ?? []) as $kw) {
        if (is_string($kw) && trim($kw) !== '') {
            $push($out, trim($kw));
        }
    }

    return $out;
}

/**
 * Pull staging excerpts for AI compare using the same matching rules as search.
 *
 * @param list<string> $extraNeedles Optional high-signal needles from the intent step. These are tried
 *        in order BEFORE the regex-derived ones so intent-driven rule ids (FCL.200…) win the budget.
 * @param array<string, mixed> $intent Optional structured intent from rl_easa_ai_extract_query_intent.
 *        Used here only to nudge corpus selection in batch filtering.
 * @return array{hit_count: int, bundle: string, sources: list<array<string, mixed>>, summary: string}
 */
function rl_easa_build_compare_staging_bundle(PDO $pdo, string $q, int $batchFilter, array $extraNeedles = [], array $intent = []): array
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
    /** AMC/GM demotion: in EASA staging the *original* rule body (e.g. "FCL.600 IR – General")
        and its acceptable means of compliance / guidance material rows (e.g. "AMC1 FCL.600 …",
        "GM1 FCL.600 …", "AMC1 FCL.600(b) …") are ALL stored as node_type='topic'. Without help,
        a needle for "FCL.600" can fill the row budget with 6–8 AMC/GM variants before sibling
        rules (FCL.605, FCL.610, …) get a single slot. We demote anything whose TITLE starts
        with "AMC<num>" or "GM<num>" so the actual rule body always ranks first. */
    $amcGmRank = "(CASE
        WHEN UPPER(COALESCE(title, '')) REGEXP '^[[:space:]]*(AMC|GM)[0-9]+' THEN 1
        ELSE 0
    END)";
    $maxRows = 24;
    $excerptLen = 4500;
    $excerptBody = rl_easa_staging_snippet_concat_body($pdo);
    $bf = rl_easa_query_batch_filtering($pdo, $q, $intent);
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
    $fetchMatches = static function (string $needle, int $limit) use ($pdo, $batchFilter, $wrapRank, $kindRank, $bodyRank, $amcGmRank, $excerptBody, $excerptLen, $excludeSql, $boostRank): array {
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
                ORDER BY {$wrapRank} ASC, {$bodyRank} ASC, {$kindRank} ASC, {$amcGmRank} ASC, {$titleRankSql} ASC, id ASC
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
                ORDER BY {$boostRank} ASC, {$wrapRank} ASC, {$bodyRank} ASC, {$kindRank} ASC, {$amcGmRank} ASC, {$titleRankSql} ASC, id ASC
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
        /** Combine intent-derived needles (rule ids + topic-precise phrases — strongest signal)
            with the regex-derived ones. De-dupe case-insensitively. */
        $combined = [];
        $pushNeedle = static function (string $n) use (&$combined): void {
            $n = trim($n);
            if ($n === '') {
                return;
            }
            foreach ($combined as $existing) {
                if (strcasecmp($existing, $n) === 0) {
                    return;
                }
            }
            $combined[] = $n;
        };
        foreach ($extraNeedles as $n) {
            if (is_string($n)) {
                $pushNeedle($n);
            }
        }
        foreach (rl_easa_compare_query_needles($q) as $n) {
            $pushNeedle($n);
        }
        /** Bound how many distinct needles we try so a noisy intent payload can't blow up
            staging query count. The first ~16 are always the highest-signal ones. */
        $combined = array_slice($combined, 0, 16);

        /** Pre-fetch up to $perNeedle candidates per needle, then interleave round-robin.
            This guarantees that needle FCL.600 contributes its top row (the actual rule),
            then needle FCL.605 contributes its top row, etc. — BEFORE any one needle gets
            to add a second row (typically its first AMC/GM). Combined with the amc_gm_rank
            SQL demotion above, this fills the bundle with distinct rule bodies first. */
        $perNeedle = 6;
        $rowsByNeedle = [];
        foreach ($combined as $needle) {
            $rowsByNeedle[] = $fetchMatches($needle, $perNeedle);
        }
        $maxRound = 0;
        foreach ($rowsByNeedle as $rb) {
            if (count($rb) > $maxRound) {
                $maxRound = count($rb);
            }
        }
        for ($round = 0; $round < $maxRound; $round++) {
            foreach ($rowsByNeedle as $needleRows) {
                if (!isset($needleRows[$round])) {
                    continue;
                }
                $r = $needleRows[$round];
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
function rl_easa_build_ai_canonical_regulatory_bundle(PDO $pdo, string $q, int $batchFilter, array $extraNeedles = [], array $intent = []): array
{
    $stagingCompare = rl_easa_build_compare_staging_bundle($pdo, $q, $batchFilter, $extraNeedles, $intent);
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
        if (count($pairs) >= 14) {
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

/**
 * Map structured intent to a prioritised list of 14 CFR sections worth fetching for a comparison.
 * Explicit hints from the user (intent.cfr_section_hints) win. Then per-licence defaults from
 * Title 14 Part 61 (Certification of pilots) — these are the sections that line up best with
 * the EASA Part-FCL families a pilot would naturally want to compare. Finally, a handful of
 * topic-driven additions (medical, language, IFR, recency).
 *
 * @return list<array{title: int, section: string, why: string}>
 */
function rl_easa_intent_to_cfr_sections(array $intent, int $maxSections = 5): array
{
    $out = [];
    $push = static function (int $title, string $section, string $why) use (&$out): void {
        $section = strtolower(trim($section));
        if ($section === '' || $title <= 0) {
            return;
        }
        foreach ($out as $existing) {
            if ($existing['title'] === $title && $existing['section'] === $section) {
                return;
            }
        }
        $out[] = ['title' => $title, 'section' => $section, 'why' => $why];
    };

    /** 1. Explicit hints from the question (user typed "61.57" / "91.205" / …). */
    foreach ((array) ($intent['cfr_section_hints'] ?? []) as $h) {
        if (!is_string($h) || $h === '') {
            continue;
        }
        $hs = strtolower(trim($h));
        if (preg_match('/^(\d{1,2})\.(\d{1,4}[a-z]?)$/', $hs, $m)) {
            $push((int) $m[1], $m[1] . '.' . $m[2], 'explicit-section-hint');
        }
    }

    /** 2. Licence-driven Part 61 mappings. These are the FAA sections that pilots and
        instructors actually cite when comparing across the Atlantic. We do NOT try to
        cover every FAR subpart — just the headline rules per licence/rating. */
    $licence = strtoupper((string) ($intent['licence'] ?? ''));
    $licenceMap = [
        'PPL' => [
            ['61.103', 'PPL — eligibility'],
            ['61.105', 'PPL — aeronautical knowledge'],
            ['61.107', 'PPL — flight proficiency'],
            ['61.109', 'PPL — aeronautical experience'],
            ['61.113', 'PPL — privileges and limitations'],
        ],
        'CPL' => [
            ['61.121', 'CPL — applicability'],
            ['61.123', 'CPL — eligibility'],
            ['61.125', 'CPL — aeronautical knowledge'],
            ['61.127', 'CPL — flight proficiency'],
            ['61.129', 'CPL — aeronautical experience'],
            ['61.133', 'CPL — privileges and limitations'],
        ],
        'ATPL' => [
            ['61.151', 'ATP — eligibility'],
            ['61.153', 'ATP — eligibility (cont.)'],
            ['61.155', 'ATP — aeronautical knowledge'],
            ['61.157', 'ATP — flight proficiency'],
            ['61.159', 'ATP — aeronautical experience (aeroplane)'],
        ],
        'MPL' => [
            ['61.153', 'ATP eligibility — closest US analogue to MPL is the ATP'],
        ],
        'LAPL' => [
            ['61.96', 'Recreational/Sport pilot — closest US analogue to LAPL'],
            ['61.99', 'Recreational pilot — aeronautical knowledge'],
            ['61.101', 'Recreational pilot — privileges'],
        ],
        'IR' => [
            ['61.65', 'Instrument rating — requirements'],
        ],
        'BIR' => [
            ['61.65', 'Instrument rating — no direct US analogue to BIR; comparing to IR'],
        ],
        'FI' => [
            ['61.183', 'CFI — eligibility'],
            ['61.185', 'CFI — aeronautical knowledge'],
            ['61.187', 'CFI — flight proficiency'],
            ['61.193', 'CFI — privileges'],
            ['61.195', 'CFI — limitations'],
        ],
        'TRI' => [
            ['61.187', 'CFI — flight proficiency (closest US analogue)'],
        ],
        'CRI' => [
            ['61.187', 'CFI — flight proficiency (closest US analogue)'],
        ],
        'IRI' => [
            ['61.183', 'CFI-I — covered under §61.183/§61.187'],
            ['61.187', 'CFI-I — flight proficiency'],
        ],
        'EXAMINER' => [
            ['61.46', 'DPE — language test'],
            ['61.47', 'DPE — status of an examiner'],
        ],
    ];
    if (isset($licenceMap[$licence])) {
        foreach ($licenceMap[$licence] as $pair) {
            $push(14, $pair[0], 'licence:' . $licence . ' — ' . $pair[1]);
        }
    }

    /** 3. Topic-driven additions. Independent of licence, so they apply even on rule-only queries. */
    $topics = (array) ($intent['topics'] ?? []);
    if (in_array('medical', $topics, true)) {
        $push(14, '61.23', 'topic:medical — medical certificates and validity periods');
    }
    if (in_array('language_proficiency', $topics, true)) {
        $push(14, '61.13', 'topic:language_proficiency — eligibility incl. English proficiency');
    }
    if (in_array('experience', $topics, true)) {
        $push(14, '61.57', 'topic:experience — recent flight experience (currency)');
    }
    if (in_array('renewal', $topics, true)) {
        $push(14, '61.56', 'topic:renewal — flight review');
        $push(14, '61.57', 'topic:renewal — recent flight experience');
    }
    if (in_array('skill_test', $topics, true)) {
        $push(14, '61.43', 'topic:skill_test — practical test');
    }
    if (in_array('theoretical_knowledge', $topics, true)) {
        $push(14, '61.35', 'topic:theoretical_knowledge — knowledge test prerequisites');
        $push(14, '61.39', 'topic:theoretical_knowledge — knowledge test eligibility');
    }

    return array_slice($out, 0, $maxSections);
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

    /** GET ?action=ai_semantic_map_get
     *  Returns the curated overlay + a fresh preview of the auto-derived tree. Used by the
     *  "AI Chat Settings" modal to populate the editor.  */
    if ($action === 'ai_semantic_map_get') {
        $loaded = easa_semantic_map_load($pdo);
        $auto = easa_semantic_map_build_auto_tree($pdo);
        rl_easa_json_out(200, [
            'ok' => true,
            'tables_ok' => easa_semantic_map_tables_ok($pdo),
            'migrate_hint' => easa_semantic_map_tables_ok($pdo) ? null : 'Apply scripts/sql/resource_library_easa_semantic_map.sql to enable persistent semantic map.',
            'overlay' => $loaded['json'],
            'overlay_persisted' => $loaded['persisted'],
            'overlay_updated_at' => $loaded['updated_at'],
            'overlay_updated_by' => $loaded['updated_by'],
            'auto_tree' => $auto,
            'auto_tree_batch_count' => count($auto),
            'defaults' => easa_semantic_map_default_overlay(),
        ]);
    }

    /** GET ?action=ai_semantic_map_autoderive_preview
     *  Re-runs the auto-derivation only. Used by the "Refresh from corpus" button in the modal. */
    if ($action === 'ai_semantic_map_autoderive_preview') {
        $auto = easa_semantic_map_build_auto_tree($pdo);
        rl_easa_json_out(200, [
            'ok' => true,
            'auto_tree' => $auto,
            'auto_tree_batch_count' => count($auto),
        ]);
    }

    /**
     * GET ?action=ai_retrieval_probe&q=...&batch_id=N
     * Diagnostic: runs the same needle expansion + ranking + canonical-bundle build that the chat uses,
     * but returns the candidates JSON instead of calling OpenAI. Use to verify CPL/PPL/etc. rules are being matched.
     */
    if ($action === 'ai_retrieval_probe') {
        if (!easa_erules_staging_tables_ok($pdo)) {
            rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_erules_staging.sql first']);
        }
        $probeQ = trim((string) ($_GET['q'] ?? ''));
        if ($probeQ === '') {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'q (query) required']);
        }
        $probeBatch = (int) ($_GET['batch_id'] ?? 0);
        $probeNeedles = rl_easa_compare_query_needles($probeQ);
        /** Optional: skip the AI intent call when ?ai_intent=0 (diagnostic shortcut). */
        $wantAiIntent = !isset($_GET['ai_intent']) || (string) $_GET['ai_intent'] !== '0';
        $probeAiIntentRes = $wantAiIntent
            ? rl_easa_ai_extract_query_intent($probeQ)
            : ['ok' => true, 'fallback' => false, 'intent' => [
                'corpus' => null, 'licence' => null, 'category' => null,
                'topics' => [], 'rule_ids' => [], 'keywords' => [], 'summary' => '',
                'wants_us_compare' => false, 'cfr_section_hints' => [],
            ], 'error' => null];
        $probeAiIntent = is_array($probeAiIntentRes['intent'] ?? null) ? $probeAiIntentRes['intent'] : [];
        $probeExtraNeedles = $wantAiIntent && empty($probeAiIntentRes['fallback'])
            ? rl_easa_intent_to_extra_needles($probeAiIntent)
            : [];
        $probeIntent = rl_easa_query_topic_filter($probeQ, $probeAiIntent);
        $probeBf = rl_easa_query_batch_filtering($pdo, $probeQ, $probeAiIntent);
        $probeBundle = rl_easa_build_compare_staging_bundle($pdo, $probeQ, $probeBatch, $probeExtraNeedles, $probeAiIntent);
        $probeCanon = rl_easa_build_ai_canonical_regulatory_bundle($pdo, $probeQ, $probeBatch, $probeExtraNeedles, $probeAiIntent);
        /** Per-needle row counts in the FCL batch only (when boost is active), to expose retrieval coverage. */
        $boostFcl = $probeBf['boost_batch_ids'] ?? [];
        $perNeedle = [];
        foreach ($probeNeedles as $n) {
            $m = rl_easa_search_match_clause($pdo, $n);
            try {
                if ($probeBatch > 0) {
                    $sql = 'SELECT COUNT(*) FROM easa_erules_import_nodes_staging WHERE batch_id = ? AND ' . $m['where'];
                    $st = $pdo->prepare($sql);
                    $st->execute(array_merge([$probeBatch], $m['bind']));
                    $perNeedle[$n] = ['all' => (int) $st->fetchColumn()];
                } elseif ($boostFcl !== []) {
                    $sql = 'SELECT COUNT(*) FROM easa_erules_import_nodes_staging WHERE batch_id IN ('
                        . implode(',', array_map('intval', $boostFcl)) . ') AND ' . $m['where'];
                    $st = $pdo->prepare($sql);
                    $st->execute($m['bind']);
                    $perNeedle[$n] = ['in_fcl_batches' => (int) $st->fetchColumn()];
                } else {
                    $sql = 'SELECT COUNT(*) FROM easa_erules_import_nodes_staging WHERE ' . $m['where'];
                    $st = $pdo->prepare($sql);
                    $st->execute($m['bind']);
                    $perNeedle[$n] = ['all' => (int) $st->fetchColumn()];
                }
            } catch (Throwable $e) {
                $perNeedle[$n] = ['error' => $e->getMessage()];
            }
        }
        /** Show whether the corpus actually contains canonical FCL.300-series CPL rows, regardless of needles. */
        $cplCoverage = [];
        try {
            $sqlCov = "SELECT batch_id, node_uid, node_type, source_erules_id, title, breadcrumb
                       FROM easa_erules_import_nodes_staging
                       WHERE (COALESCE(source_erules_id, '') LIKE 'FCL.30%'
                              OR COALESCE(source_erules_id, '') LIKE 'FCL.31%'
                              OR COALESCE(source_erules_id, '') LIKE 'FCL.32%'
                              OR COALESCE(title, '') LIKE '%Commercial Pilot%'
                              OR COALESCE(breadcrumb, '') LIKE '%Commercial Pilot%')
                       ORDER BY batch_id, source_erules_id, id
                       LIMIT 40";
            $cplCoverage = $pdo->query($sqlCov)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $cplCoverage = ['error' => $e->getMessage()];
        }
        rl_easa_json_out(200, [
            'ok' => true,
            'query' => $probeQ,
            'batch_id' => $probeBatch,
            'ai_intent' => $probeAiIntent,
            'ai_intent_fallback' => !empty($probeAiIntentRes['fallback']),
            'ai_intent_error' => $probeAiIntentRes['error'] ?? null,
            'ai_intent_extra_needles' => $probeExtraNeedles,
            'topic_intent' => $probeIntent,
            'batch_filtering' => $probeBf,
            'needles' => $probeNeedles,
            'needle_row_counts' => $perNeedle,
            'compare_summary' => $probeBundle['summary'] ?? '',
            'compare_hit_count' => $probeBundle['hit_count'] ?? 0,
            'compare_sources' => $probeBundle['sources'] ?? [],
            'canonical_loaded_count' => is_array($probeCanon['full_nodes'] ?? null) ? count($probeCanon['full_nodes']) : 0,
            'canonical_sources' => $probeCanon['sources'] ?? [],
            'canonical_nodes_summary' => array_map(static function (array $fn): array {
                $n = is_array($fn['node'] ?? null) ? $fn['node'] : [];
                return [
                    'batch_id' => $fn['batch_id'] ?? 0,
                    'node_uid' => $fn['node_uid'] ?? '',
                    'node_type' => (string) ($n['node_type'] ?? ''),
                    'source_erules_id' => (string) ($n['source_erules_id'] ?? ''),
                    'title' => (string) ($n['title'] ?? ''),
                    'breadcrumb' => (string) ($n['breadcrumb'] ?? ''),
                    'plain_text_len' => strlen((string) ($n['plain_text'] ?? '')),
                    'has_structured_blocks' => is_array($n['structured_blocks'] ?? null) && !empty($n['structured_blocks']),
                ];
            }, is_array($probeCanon['full_nodes'] ?? null) ? $probeCanon['full_nodes'] : []),
            'cpl_corpus_coverage' => $cplCoverage,
        ]);
    }

    /**
     * GET ?action=ai_ecfr_probe&q=...
     * Diagnostic for the "compare with FAA / 14 CFR" path. Runs detection + section inference
     * + intent extraction + the EASA→CFR section map + an actual multi-section eCFR fetch,
     * and returns everything as JSON without calling the answering OpenAI model. Useful to
     * verify the comparison pipeline end-to-end without burning credits.
     * Query params:
     *   q             — natural language question (required).
     *   skip_fetch=1  — do not actually call eCFR; just return the section list.
     *   ai_intent=0   — skip the intent extractor OpenAI call (keyword path only).
     */
    if ($action === 'ai_ecfr_probe') {
        $probeQ = trim((string) ($_GET['q'] ?? ''));
        if ($probeQ === '') {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'q (query) required']);
        }
        $skipFetch = (string) ($_GET['skip_fetch'] ?? '') === '1';
        $wantAiIntent = !isset($_GET['ai_intent']) || (string) $_GET['ai_intent'] !== '0';

        $keywordWantsCompare = rl_easa_query_requests_us_ecfr_context($probeQ);
        $explicitSection = rl_easa_infer_ecfr_section_from_query($probeQ);

        $intentRes = $wantAiIntent
            ? rl_easa_ai_extract_query_intent($probeQ)
            : ['ok' => true, 'fallback' => true, 'intent' => [
                'corpus' => null, 'licence' => null, 'category' => null,
                'topics' => [], 'rule_ids' => [], 'keywords' => [], 'summary' => '',
                'wants_us_compare' => false, 'cfr_section_hints' => [],
            ], 'error' => 'ai_intent=0 — skipped'];
        $intent = is_array($intentRes['intent'] ?? null) ? $intentRes['intent'] : [];
        $intentWasFallback = !empty($intentRes['fallback']);

        $includeEcfr = $keywordWantsCompare || !empty($intent['wants_us_compare']);

        $cfrSections = $intentWasFallback ? [] : rl_easa_intent_to_cfr_sections($intent, 5);
        $sectionsToFetch = [];
        $pushSec = static function (int $t, string $s, string $why) use (&$sectionsToFetch): void {
            $s = strtolower(trim($s));
            if ($t <= 0 || $s === '') {
                return;
            }
            foreach ($sectionsToFetch as $existing) {
                if ($existing['title'] === $t && $existing['section'] === $s) {
                    return;
                }
            }
            $sectionsToFetch[] = ['title' => $t, 'section' => $s, 'why' => $why];
        };
        if ($explicitSection !== '') {
            $pushSec(14, $explicitSection, 'explicit-from-query');
        }
        foreach ($cfrSections as $is) {
            $pushSec($is['title'], $is['section'], $is['why']);
        }
        $sectionsToFetch = array_slice($sectionsToFetch, 0, 5);

        $fetched = [];
        $snapshot = '';
        $fetchErrors = [];
        $fetchSkipped = $skipFetch;
        if (!$skipFetch && $includeEcfr && $sectionsToFetch !== []) {
            try {
                $cfg = rl_catalog_ecfr_runtime_config($pdo);
                $client = new EcfrApiClient($cfg['api_base_url']);
                $snapshot = $client->resolveTitleSnapshotDate($sectionsToFetch[0]['title']);
                foreach ($sectionsToFetch as $sec) {
                    try {
                        $xml = $client->fetchSectionXml($sec['title'], $sec['section'], $snapshot);
                        $html = $client->sectionXmlToHtml($xml);
                        $strip = preg_replace('/\s+/', ' ', strip_tags($html));
                        $strip = is_string($strip) ? trim($strip) : '';
                        $fetched[] = [
                            'title_number' => $sec['title'],
                            'section' => $sec['section'],
                            'snapshot' => $snapshot,
                            'browse_url' => $client->sectionBrowseUrl($sec['title'], $sec['section']),
                            'why' => $sec['why'],
                            'text_chars' => strlen($strip),
                            'text_preview' => mb_substr($strip, 0, 320),
                        ];
                    } catch (Throwable $e) {
                        $fetchErrors[] = sprintf('§%s — %s', $sec['section'], $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $fetchErrors[] = 'snapshot resolve failed — ' . $e->getMessage();
            }
        }

        rl_easa_json_out(200, [
            'ok' => true,
            'query' => $probeQ,
            'detection' => [
                'keyword_compare' => $keywordWantsCompare,
                'intent_compare' => !empty($intent['wants_us_compare']),
                'include_ecfr_effective' => $includeEcfr,
                'explicit_section_inferred' => $explicitSection,
            ],
            'ai_intent' => $intent,
            'ai_intent_fallback' => $intentWasFallback,
            'ai_intent_error' => $intentRes['error'] ?? null,
            'cfr_sections_planned' => $sectionsToFetch,
            'cfr_sections_fetched' => $fetched,
            'cfr_snapshot' => $snapshot,
            'fetch_skipped' => $fetchSkipped,
            'fetch_errors' => $fetchErrors,
        ]);
    }

    /**
     * GET ?action=ai_ecfr_fetch_section&title=14&section=61.103[&snapshot=YYYY-MM-DD]
     * Lazy-fetch a single eCFR section for the in-chat modal. Used when the assistant
     * row was reloaded from DB (where the per-section HTML was stripped to keep the
     * row small). Returns: {ok, html, snapshot, browse_url, title_number, section}.
     */
    if ($action === 'ai_ecfr_fetch_section') {
        $title = (int) ($_GET['title'] ?? 14);
        $section = strtolower(trim((string) ($_GET['section'] ?? '')));
        $snapshot = trim((string) ($_GET['snapshot'] ?? ''));
        if ($title <= 0) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'title required']);
        }
        /** Reject anything that isn't the canonical "NN.NNNN[a]" CFR section form. */
        if (!preg_match('/^\d{1,2}\.\d{1,4}[a-z]?$/', $section)) {
            rl_easa_json_out(400, ['ok' => false, 'error' => 'section must look like 61.103']);
        }
        try {
            $cfg = rl_catalog_ecfr_runtime_config($pdo);
            $client = new EcfrApiClient($cfg['api_base_url']);
            if ($snapshot === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot)) {
                $snapshot = $client->resolveTitleSnapshotDate($title);
            }
            $xml = $client->fetchSectionXml($title, $section, $snapshot);
            $html = $client->sectionXmlToHtml($xml);
            rl_easa_json_out(200, [
                'ok' => true,
                'title_number' => $title,
                'section' => $section,
                'snapshot' => $snapshot,
                'browse_url' => $client->sectionBrowseUrl($title, $section),
                'html' => $html,
            ]);
        } catch (Throwable $e) {
            rl_easa_json_out(502, ['ok' => false, 'error' => $e->getMessage()]);
        }
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

if ($action === 'ai_semantic_map_save') {
    if (!easa_semantic_map_tables_ok($pdo)) {
        rl_easa_json_out(503, ['ok' => false, 'error' => 'Apply scripts/sql/resource_library_easa_semantic_map.sql first']);
    }
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    if ($userId <= 0) {
        rl_easa_json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }
    $payload = $data['overlay'] ?? null;
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            rl_easa_json_out(400, [
                'ok' => false,
                'error' => 'overlay JSON did not parse: ' . json_last_error_msg(),
            ]);
        }
        $payload = $decoded;
    }
    if (!is_array($payload)) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'overlay (JSON object) is required']);
    }
    $v = easa_semantic_map_validate($payload);
    if (!$v['ok']) {
        /** Soft-fail validation: keep the warnings but still save the normalised payload, so
            unknown keys are dropped rather than blocking the save. The UI surfaces the warnings. */
    }
    try {
        $saved = easa_semantic_map_save($pdo, $v['normalised'], $userId);
    } catch (Throwable $e) {
        rl_easa_json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    rl_easa_json_out(200, [
        'ok' => true,
        'validation_warnings' => $v['errors'],
        'overlay' => $saved['json'],
        'overlay_updated_at' => $saved['updated_at'],
        'overlay_updated_by' => $saved['updated_by'],
        'overlay_persisted' => $saved['persisted'],
    ]);
}

if ($action === 'ai_semantic_map_validate') {
    /** Validate-only — no save, no auth needed beyond the admin gate already enforced at the top. */
    $payload = $data['overlay'] ?? null;
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            rl_easa_json_out(200, [
                'ok' => false,
                'parse_error' => json_last_error_msg(),
                'warnings' => [],
                'normalised' => null,
            ]);
        }
        $payload = $decoded;
    }
    if (!is_array($payload)) {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'overlay (JSON object) is required']);
    }
    $v = easa_semantic_map_validate($payload);
    rl_easa_json_out(200, [
        'ok' => $v['ok'],
        'warnings' => $v['errors'],
        'normalised' => $v['normalised'],
    ]);
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
    /** This action triggers two OpenAI calls (intent extraction + answer). Together they can
        approach ~3 minutes worst-case. Raise the script time budget so a slow second call
        doesn't get killed mid-flight by PHP, which would leave the client with a truncated
        body and a WebKit "did not match the expected pattern" JSON.parse error. */
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    $q = trim((string) ($data['query'] ?? ''));
    if ($q === '') {
        rl_easa_json_out(400, ['ok' => false, 'error' => 'query required']);
    }
    $useAi = !empty($data['use_ai']);
    /** Comparison can be triggered three ways:
        - explicit POST flag `include_ecfr=true` from a future UI toggle,
        - keyword detector against the raw question text,
        - intent extractor (see below) flagging wants_us_compare:true.
        We OR the first two here; the intent fold-in happens after step 1 runs. */
    $includeEcfr = !empty($data['include_ecfr']) || rl_easa_query_requests_us_ecfr_context($q);
    $titleNum = (int) ($data['ecfr_title_number'] ?? 14);
    $section = trim((string) ($data['ecfr_section'] ?? ''));
    if ($includeEcfr && $section === '') {
        $section = rl_easa_infer_ecfr_section_from_query($q);
    }
    $compareBatchId = (int) ($data['batch_id'] ?? 0);
    $userFirstName = rl_easa_current_user_first_name($pdo);

    /** Load recent conversation history from this session (if it exists) BEFORE the new user
        message is inserted, so the intent + answer calls can interpret follow-ups in context.
        We load the last 8 turns (capped); both calls then see the last 6. */
    $sessionIdRequested = (int) ($data['session_id'] ?? 0);
    $historyForAi = [];
    $chatSupported = rl_easa_ai_chat_tables_ok($pdo);
    $u = cw_current_user($pdo);
    $userId = is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    if ($chatSupported && $userId > 0 && $sessionIdRequested > 0) {
        try {
            $chk = $pdo->prepare('SELECT id FROM easa_ai_chat_sessions WHERE id = ? AND user_id = ? LIMIT 1');
            $chk->execute([$sessionIdRequested, $userId]);
            if ($chk->fetchColumn()) {
                $hst = $pdo->prepare('SELECT role, content FROM easa_ai_chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 8');
                $hst->execute([$sessionIdRequested]);
                $historyForAi = array_reverse($hst->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }
        } catch (Throwable) {
            $historyForAi = [];
        }
    }

    /** STEP 1 of the two-step pipeline: ask the model to classify the question into a
        structured intent (corpus / licence / category / topics / rule_ids / keywords / summary).
        With prior history attached, follow-ups like "What are those requirements?" resolve
        against the previous turn instead of producing an empty intent.
        If this call fails (timeout, network, non-JSON), $intentRes['fallback'] becomes true and
        we proceed with the keyword-only pipeline + append a small "(This was a fall-back)" note
        on the final answer so the user knows quality may be reduced. */
    $intentRes = rl_easa_ai_extract_query_intent($q, $historyForAi);
    $intent = is_array($intentRes['intent'] ?? null) ? $intentRes['intent'] : [];
    $intentWasFallback = !empty($intentRes['fallback']);
    $intentError = isset($intentRes['error']) ? (string) $intentRes['error'] : null;
    $extraNeedles = $intentWasFallback ? [] : rl_easa_intent_to_extra_needles($intent);

    /** Fold the intent's wants_us_compare hint into $includeEcfr. The intent extractor
        catches phrases the keyword detector misses ("in the United States", "the FAR side",
        "what about American rules", etc.) — but the keyword detector is still useful for
        short / one-word follow-ups where the model returned an empty intent. */
    if (!$includeEcfr && !empty($intent['wants_us_compare'])) {
        $includeEcfr = true;
    }

    /** STEP 2: build the regulatory bundle using the intent-derived needles (plus the regex
        keyword fallback inside the bundle builder). */
    $canon = rl_easa_build_ai_canonical_regulatory_bundle($pdo, $q, $compareBatchId, $extraNeedles, $intent);

    /** STEP 2b: load the curated overlay + auto-derived tree and render them into a
        compact prompt block. This is Maya's authoritative outline — pasted into the
        system prompt so she always knows the Subpart/Section shape, cross-references,
        "do not confuse" warnings and editorial overrides regardless of which rows the
        retrieval step happened to surface. */
    $semanticMapLoaded = easa_semantic_map_load($pdo);
    $semanticAutoTree = easa_semantic_map_build_auto_tree($pdo);
    $semanticMapBlock = easa_semantic_map_render_for_ai($semanticMapLoaded['json'] ?? [], $semanticAutoTree);
    $easaCtx = (string) ($canon['summary'] ?? '');
    $easaModelBundle = trim((string) ($canon['model_bundle'] ?? ''));

    /** Comparison mode: when a U.S. comparison is requested we now fetch UP TO FIVE relevant
        14 CFR sections from the eCFR versioner API. The priority is:
          1. Explicit section in $section (from POST or regex over the question).
          2. Intent-derived sections (intent.cfr_section_hints + licence/topic mapping).
          3. Cap at $maxEcfrSections to keep latency bounded — each fetch is ~1s.
        Every successful fetch contributes a stripped-text excerpt to $ecfrFetched and a
        provenance row to $ecfrSources. Failures are collected into per-section notes so the
        model can apologise instead of hallucinating an answer. */
    $ecfrHtml = '';       /** retained for legacy field in payload (first section's HTML) */
    $ecfrNote = '';
    $ecfrFetched = [];    /** list<array{title_number:int,section:string,snapshot:string,browse_url:string,text:string}> */
    $ecfrSources = [];    /** list of provenance rows shipped to the UI */
    $ecfrSnapshot = '';
    $maxEcfrSections = 5;

    if ($includeEcfr) {
        /** Build the prioritised section list. */
        $sectionsToFetch = [];
        $pushSec = static function (int $t, string $s, string $why) use (&$sectionsToFetch): void {
            $s = strtolower(trim($s));
            if ($t <= 0 || $s === '') {
                return;
            }
            foreach ($sectionsToFetch as $existing) {
                if ($existing['title'] === $t && $existing['section'] === $s) {
                    return;
                }
            }
            $sectionsToFetch[] = ['title' => $t, 'section' => $s, 'why' => $why];
        };
        if ($section !== '') {
            $pushSec($titleNum > 0 ? $titleNum : 14, $section, 'explicit-from-query');
        }
        if (!$intentWasFallback) {
            foreach (rl_easa_intent_to_cfr_sections($intent, $maxEcfrSections) as $is) {
                $pushSec($is['title'], $is['section'], $is['why']);
            }
        }
        $sectionsToFetch = array_slice($sectionsToFetch, 0, $maxEcfrSections);

        if ($sectionsToFetch === []) {
            $ecfrNote = 'U.S. comparison was requested but no 14 CFR section could be inferred from the question. The model should still answer the EASA side fully and offer a high-level comparison from general FAA knowledge, while inviting the user to name a specific 14 CFR section for an authoritative excerpt.';
        } else {
            try {
                $cfg = rl_catalog_ecfr_runtime_config($pdo);
                $client = new EcfrApiClient($cfg['api_base_url']);
                /** Resolve the title snapshot date ONCE per request — all subsequent section
                    fetches reuse the same snapshot so the comparison is internally consistent. */
                $primaryTitle = $sectionsToFetch[0]['title'];
                $snap = $client->resolveTitleSnapshotDate($primaryTitle);
                $ecfrSnapshot = $snap;
                $errors = [];

                foreach ($sectionsToFetch as $sec) {
                    try {
                        $xml = $client->fetchSectionXml($sec['title'], $sec['section'], $snap);
                        $html = $client->sectionXmlToHtml($xml);
                        if ($ecfrHtml === '') {
                            $ecfrHtml = $html; /** keep first section as legacy ecfr_html */
                        }
                        $strip = preg_replace('/\s+/', ' ', strip_tags($html));
                        $strip = is_string($strip) ? trim($strip) : '';
                        if ($strip === '') {
                            $errors[] = sprintf('14 CFR §%s returned no text', $sec['section']);
                            continue;
                        }
                        $browseUrl = $client->sectionBrowseUrl($sec['title'], $sec['section']);
                        $ecfrFetched[] = [
                            'title_number' => $sec['title'],
                            'section' => $sec['section'],
                            'snapshot' => $snap,
                            'browse_url' => $browseUrl,
                            'text' => $strip,
                            'why' => $sec['why'],
                        ];
                        /** `html` is included in the LIVE response so the chip-modal can
                            open instantly without another roundtrip. It gets stripped
                            before we persist to easa_ai_chat_messages.response_json (see
                            $persist below) to keep DB rows small; reloaded chats lazy-fetch
                            through ?action=ai_ecfr_fetch_section. */
                        $ecfrSources[] = [
                            'title_number' => $sec['title'],
                            'section' => $sec['section'],
                            'snapshot' => $snap,
                            'browse_url' => $browseUrl,
                            'label' => sprintf('14 CFR §%s', $sec['section']),
                            'why' => $sec['why'],
                            'html' => $html,
                        ];
                    } catch (Throwable $e) {
                        $errors[] = sprintf('14 CFR §%s fetch failed (%s)', $sec['section'], $e->getMessage());
                    }
                }

                if ($ecfrFetched === []) {
                    $ecfrNote = 'eCFR fetch returned no usable text for the inferred sections. '
                        . 'Maya must mention that the live U.S. excerpt could not be loaded and offer to compare based on general FAA knowledge.'
                        . ($errors !== [] ? ' Details: ' . implode('; ', $errors) : '');
                } else {
                    $ecfrNote = sprintf(
                        'Loaded %d official 14 CFR section(s) via eCFR versioner API · snapshot %s',
                        count($ecfrFetched),
                        $snap
                    );
                    if ($errors !== []) {
                        $ecfrNote .= ' · partial errors: ' . implode('; ', $errors);
                    }
                }
            } catch (Throwable $e) {
                $ecfrNote = 'eCFR fetch failed: ' . $e->getMessage()
                    . ' — Maya must not invent FAA citations; fall back to a high-level comparison and apologise for the missing live excerpt.';
            }
        }
    }

    $sessionId = $sessionIdRequested;
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
        'ecfr_sources' => $ecfrSources,
        'ecfr_snapshot' => $ecfrSnapshot !== '' ? $ecfrSnapshot : null,
        'compare_mode' => $includeEcfr,
        'ai_answer' => '',
        'ai_error' => null,
        'answer_markdown' => '',
        'primary_references' => [],
        'secondary_references' => [],
        'confidence' => 'medium',
        'session_id' => $sessionId > 0 ? $sessionId : null,
        'chat_supported' => $chatSupported && $userId > 0,
        'intent' => $intent,
        'intent_fallback' => $intentWasFallback,
        'intent_error' => $intentError,
        'semantic_map' => [
            'overlay_persisted' => (bool) ($semanticMapLoaded['persisted'] ?? false),
            'overlay_updated_at' => $semanticMapLoaded['updated_at'] ?? null,
            'auto_tree_batch_count' => count($semanticAutoTree),
            'prompt_block_chars' => strlen($semanticMapBlock),
        ],
    ];

    if (!$useAi) {
        rl_easa_json_out(200, $payload);
    }

    $loadedNodeCount = is_array($canon['full_nodes'] ?? null) ? count($canon['full_nodes']) : 0;
    /** Build a short, model-facing index of titles + FCL ids so the model cannot pretend the rules are absent. */
    $bundleTitleIndex = '';
    if (is_array($canon['full_nodes'] ?? null) && $canon['full_nodes'] !== []) {
        $idxLines = [];
        foreach ($canon['full_nodes'] as $fn) {
            if (!is_array($fn) || !is_array($fn['node'] ?? null)) {
                continue;
            }
            $title = trim((string) ($fn['node']['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $idxLines[] = '  • ' . $title;
            if (count($idxLines) >= 16) {
                break;
            }
        }
        if ($idxLines !== []) {
            $bundleTitleIndex = "Rule titles present in this bundle (use these verbatim in your outline):\n" . implode("\n", $idxLines) . "\n\n";
        }
    }
    $bundle = "EU / EASA — FULL canonical regulation payloads from this installation. "
        . "Each CANONICAL NODE block below is a real Part-FCL rule that the server has already matched to the user's question. "
        . "Build your answer FROM the listed blocks. Never claim a topic is missing — if there are nodes, you have material.\n\n";
    if ($easaModelBundle !== '') {
        $bundle .= sprintf(
            "*** %d CANONICAL NODE block(s) follow. These ARE the relevant rules for this question. ***\n",
            $loadedNodeCount
        );
        if ($bundleTitleIndex !== '') {
            $bundle .= "\n" . $bundleTitleIndex;
        }
        $bundle .= $easaModelBundle . "\n\n";
    } else {
        $bundle .= "(No canonical node payloads loaded — say so plainly and ask which regulation the user means.)\n\n";
    }
    /** Comparison mode bundle injection. When live eCFR text is available we add a clearly
        delimited U.S. block with one paragraph per section. When the fetch failed we still
        pass the operational note so the model knows to apologise instead of inventing FAA
        citations. */
    if ($ecfrFetched !== []) {
        $bundle .= sprintf(
            "U.S. 14 CFR excerpts (eCFR versioner API · snapshot %s · %d section(s) loaded):\n\n",
            $ecfrSnapshot !== '' ? $ecfrSnapshot : 'current',
            count($ecfrFetched)
        );
        /** Budget the U.S. block so a long Part 61 section can't crowd out the EASA bundle. */
        $perSectionCap = 4000;
        $totalCap = 14000;
        $usedChars = 0;
        foreach ($ecfrFetched as $f) {
            $body = (string) ($f['text'] ?? '');
            if (strlen($body) > $perSectionCap) {
                $body = substr($body, 0, $perSectionCap) . '…';
            }
            $headline = sprintf(
                "--- 14 CFR §%s (Title %d, snapshot %s) — Browse: %s ---\n",
                $f['section'],
                (int) $f['title_number'],
                (string) $f['snapshot'],
                (string) $f['browse_url']
            );
            $chunk = $headline . $body . "\n\n";
            if ($usedChars + strlen($chunk) > $totalCap) {
                $bundle .= sprintf(
                    "(…remaining U.S. sections truncated for budget — %d total fetched.)\n\n",
                    count($ecfrFetched)
                );
                break;
            }
            $bundle .= $chunk;
            $usedChars += strlen($chunk);
        }
    }
    if ($includeEcfr && $ecfrNote !== '') {
        /** Always echo the operational note into the bundle so the model can act on it. */
        $bundle .= "U.S. comparison status note (read before writing the U.S. side):\n"
            . $ecfrNote . "\n\n";
    }

    $jsonInstructions = <<<'TXT'
You are "Maya", a warm, professional EASA regulations mentor — like a friendly flight instructor, NOT a legal memo or database UI.

Your entire model output MUST be a single JSON object (no markdown fences, no preface) with exactly these keys:

- "answer_markdown": string. Tight, friendly Markdown for a pilot or instructor.

  ABSOLUTE RULE (read this first, then follow it):
    The reference bundle below ALWAYS contains rule nodes that the server has already matched to the user's question. Each block's title (e.g. "FCL.300 CPL – Minimum age") and breadcrumb tell you what the rule is and where it lives in the tree. You MUST build the answer FROM those blocks. You are NOT allowed to say the rules are missing, not indexed, not present, not in the bundle, not in the installation, or anything equivalent. You are NOT allowed to ask the user to confirm which regulation set when their question already names one (Part-FCL, Aircrew, CPL, PPL, ATPL, etc.). Refusal phrasing is forbidden — there is always something to say from the listed CANONICAL NODE blocks.

  STRICT STYLE (this is what makes the reply useful — don't drift from it):
    * Open with a single short greeting line: "Hi <FirstName>," when a first name is provided, otherwise "Hi there,".
    * Second line: state the location of the topic in the tree, e.g. "to get more details on the EASA Commercial Pilot Licence, the following rules can be found under Aircrew, Annex I (Part-FCL), Subpart D – Commercial Pilot Licence."
    * Then a compact bulleted outline grouped by Section / Subpart where the bundle shows that structure. Each bullet lists rule TITLES with their FCL id in parentheses, e.g.
        - Section 1 – Common requirements: Minimum age (FCL.300), Privileges and conditions (FCL.305), Theoretical knowledge examinations (FCL.310), Training course (FCL.315), Skill test (FCL.320).
        - Section 2 – Aeroplane category: Training course (FCL.315.A), Specific requirements for applicants who hold an MPL (FCL.325.A).
        - Cross-references: Training courses for the issue of a CPL and an ATPL (Appendix 3).
    * End with ONE short focused offer to go deeper, e.g. "What would you like me to check more in depth for you here?". Do not list multiple numbered options unless the user explicitly asked you to narrow.
    * NEVER include disclaimers, hedges, or commentary about the dataset. Do NOT say "the indexed material does not include", "the bundle does not contain", "in this installation slice", "the available material is mostly", "those specific rules", "not indexed here yet", or any equivalent phrasing. Do NOT mention "batches", "staging", "canonical", "node_uid", "ERulesId", "retrieval", or how the back-end works.
    * Do NOT add boilerplate like "always verify against the official EASA publication".
    * Keep total length around 90–180 words for a straight overview question. Do not pad.
    * Use FRIENDLY corpus names only ("EAR Flight Crew", "EAR Flight Operations", "EAR Part-IS", "EAR CS-FSTD"). Never raw .xml file names. Never internal IDs in prose — rule ids like FCL.300 ARE fine in prose because they're the natural way pilots cite rules.

  CONTENT RULES:
    * Use the CANONICAL NODE titles and FCL ids verbatim. Do not invent new rule ids.
    * If multiple licence categories appear (Subpart D has Section 2 for aeroplanes, Section 3 for helicopters, etc.), summarise the structure and only expand the category the user asked about (or list available categories and offer to expand one in the closing line).
    * ARA.FCL.300 nodes (Air Operations Authority Requirements – Examination procedures) are about the COMPETENT AUTHORITY's exam procedures, NOT about pilot CPL licensing — mention them only if the user explicitly asked about authority/examiner procedures; otherwise focus on the FCL.300–FCL.325 (and FCL.300.A–FCL.325.A) pilot rules.

  CONVERSATION-HISTORY RULES:
    * Recent conversation turns are provided above. Treat them as memory — short follow-ups like "What are those requirements?", "Tell me more.", "And for the modular course?" continue the previous topic and should be answered against that same Part-FCL area.
    * NEVER repeat a previous Maya turn verbatim. If the user's new message is a refinement or follow-up, answer the NEW question with the EXTRA detail they asked for (e.g. previous turn introduced FCL.600; follow-up "what are those requirements" → answer with FCL.610 pre-requisites, FCL.615 theoretical knowledge & flight instruction, FCL.620 skill test from the bundle).
    * If the new question is clearly a new topic (different licence, different corpus), just start fresh — don't try to thread the old topic in.
    * DRILL-DOWN follow-ups ("break it down", "in simple steps", "step by step", "walk me through", "explain in detail", "go deeper"): DO NOT re-print the high-level rule list you already gave in the previous turn. Skip that intro completely. Open with one short line ("Hi Kay, here's the modular IR(A) path step-by-step:") then go STRAIGHT to the numbered or bulleted steps with rule ids in parentheses for citation. The reader already saw the rule list — your job now is the substance, not the table of contents.

  COMPARISON MODE — engage ONLY when "U.S. 14 CFR excerpts" or a "U.S. comparison status note" appear in the bundle:
    * After the standard EASA outline (greeting + tree location + Section/Subpart bullets), add a new section titled exactly "## Comparison with U.S. 14 CFR".
    * Format that section as a short table or paired bullets. Each pair shows the EASA rule on the left and the U.S. analogue on the right, e.g.:
        - **EASA — Eligibility (FCL.210 PPL)** ↔ **U.S. — Eligibility (14 CFR §61.103)**: short side-by-side comment (4–8 lines max).
        - **EASA — Aeronautical experience (FCL.210 PPL)** ↔ **U.S. — Aeronautical experience (14 CFR §61.109)**: list the headline numerical differences (hours, dual, solo, cross-country).
    * Quote the eCFR text MINIMALLY (a phrase or short sentence). Always cite the U.S. section as "14 CFR §XX.YY" (no other format). Do NOT cite any 14 CFR section that is NOT listed under "U.S. 14 CFR excerpts" in the bundle.
    * If a "U.S. comparison status note" is present and there are NO U.S. excerpts, write a short paragraph like: "I couldn't pull the current 14 CFR text from eCFR just now, but at a high level the FAA analogues for what you're asking about are §… — would you like me to focus on a specific U.S. section so I can quote it precisely?" Do NOT invent verbatim FAA quotes when there are no excerpts.
    * Close the comparison subsection with ONE focused offer that mentions BOTH sides, e.g. "Want me to dig into the EASA solo cross-country requirement or the FAA dual-instruction minimum next?".
    * Total length when comparison mode is engaged may go up to ~250 words. Stay disciplined — pilots want side-by-side, not essays.

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
                            'text' => "You are Maya, an EASA Easy Access mentor. Use ONLY the canonical EASA bundles provided plus any optional U.S. eCFR excerpts / status note in the bundle. "
                                . $jsonInstructions
                                . " When U.S. text or a U.S. comparison status note is present in the bundle, engage COMPARISON MODE as defined above. Greet by first name when provided (e.g. \"Hi {name},\"). Do not add any boilerplate verification footer; the style rules above are the final word on tone."
                                . ($semanticMapBlock !== '' ? "\n\n" . $semanticMapBlock : ''),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "User first name:\n" . ($userFirstName !== '' ? $userFirstName : '(unknown)')
                                . "\n\n" . (function (array $hist): string {
                                    if ($hist === []) {
                                        return "Recent conversation (oldest first): (none — this is the first turn)\n";
                                    }
                                    $lines = [];
                                    $start = max(0, count($hist) - 6);
                                    for ($i = $start; $i < count($hist); $i++) {
                                        $row = is_array($hist[$i]) ? $hist[$i] : [];
                                        $role = strtolower((string) ($row['role'] ?? ''));
                                        if ($role !== 'user' && $role !== 'assistant') {
                                            continue;
                                        }
                                        $content = trim((string) ($row['content'] ?? ''));
                                        if ($content === '') {
                                            continue;
                                        }
                                        if (mb_strlen($content) > 800) {
                                            $content = mb_substr($content, 0, 800) . '…';
                                        }
                                        $lines[] = ($role === 'user' ? 'User:' : 'Maya:') . ' ' . $content;
                                    }

                                    return "Recent conversation (oldest first, last 6 turns):\n" . implode("\n\n", $lines) . "\n";
                                })($historyForAi)
                                . "\nCurrent question:\n" . $q
                                . "\n\nQuery intent (machine-derived, step 1 of the pipeline — use this to focus your answer; do NOT discuss it in the reply):\n"
                                . (function (array $i): string {
                                    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
                                    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                                        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
                                    }
                                    $enc = json_encode($i, $flags);

                                    return is_string($enc) ? $enc : '(intent unavailable)';
                                })($intent)
                                . "\n\nReference bundle:\n" . $bundle,
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
        /** When intent extraction (step 1) failed, the answer was produced from the keyword-only
            retrieval path. Make that visible to the user as a small italic note at the end so
            they know quality may be reduced for this turn. */
        if ($intentWasFallback && $payload['answer_markdown'] !== '') {
            $fallbackNote = "\n\n_(This was a fall-back — the question analyser was unavailable, so I answered from the keyword-search pipeline only.)_";
            $payload['answer_markdown'] .= $fallbackNote;
            $payload['ai_answer'] = $payload['answer_markdown'];
        }
        if ($chatSupported && $userId > 0 && $sessionId > 0) {
            /** Strip the per-section `html` blob before persisting — modal will lazy-fetch on click. */
            $persistedEcfrSources = [];
            if (is_array($payload['ecfr_sources'] ?? null)) {
                foreach ($payload['ecfr_sources'] as $src) {
                    if (!is_array($src)) {
                        continue;
                    }
                    $slim = $src;
                    unset($slim['html']);
                    $persistedEcfrSources[] = $slim;
                }
            }
            $persist = [
                'ok' => true,
                'answer_markdown' => $payload['answer_markdown'],
                'primary_references' => $payload['primary_references'],
                'secondary_references' => $payload['secondary_references'],
                'confidence' => $payload['confidence'],
                /** Carry the comparison-mode artefacts so re-rendering this row on chat
                    reload also shows the eCFR footer with snapshot date + browse chips. */
                'compare_mode' => (bool) ($payload['compare_mode'] ?? false),
                'ecfr_sources' => $persistedEcfrSources,
                'ecfr_snapshot' => $payload['ecfr_snapshot'] ?? null,
                'ecfr_note' => $payload['ecfr_note'] ?? null,
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
