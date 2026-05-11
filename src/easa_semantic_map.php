<?php
declare(strict_types=1);

/**
 * EASA Maya — semantic map storage + rendering.
 *
 * Two layers feed Maya's "regulatory map" prompt block:
 *
 *  1) AUTO tree, derived from `easa_erules_import_nodes_staging` on the fly. This is what
 *     guarantees the map is always in sync with whatever XML has been imported.
 *  2) CURATED overlay, stored in `easa_semantic_map` (single row keyed by slot 'default').
 *     Admins edit it through the "AI Chat Settings" modal on the EASA tab. The overlay
 *     carries the things the auto-derivation cannot infer — cross-references between
 *     rules and appendices, "do-not-confuse" warnings, editorial overrides for tone.
 *
 * Combined, they are rendered into a compact text block that goes into the answering
 * model's system prompt at every chat turn.
 */

/** Default overlay shipped when no row exists yet — also restored by "Restore defaults" in the UI. */
function easa_semantic_map_default_overlay(): array
{
    return [
        '$schema_version' => '1.0',
        '$comment' => 'Curated overlay for Maya. The auto-derived tree from the staging table is combined with this at runtime — you do not need to mirror the whole regulation tree here. Use this file for cross-references, warnings and editorial overrides.',
        'cross_references' => [
            'FCL.610' => ['Appendix 6 (modular IR(A) course)'],
            'FCL.615' => ['Appendix 6'],
            'FCL.620' => ['Appendix 7 (IR skill test)'],
            'FCL.300' => ['Appendix 3 (CPL / ATPL training courses)'],
            'FCL.500' => ['Appendix 3'],
            'FCL.400' => ['Appendix 5 (MPL integrated course)'],
            'FCL.200' => ['Appendix 9 (LAPL / PPL training)'],
            'FCL.835' => ['Appendix 8 (BIR)'],
        ],
        'do_not_confuse' => [
            [
                'rule' => 'ARA.FCL.300',
                'note' => 'Authority Requirements (Part-ARA) examination procedures. NOT the FCL.300 CPL minimum-age rule. Mention ARA.FCL.300 only when the user explicitly asked about competent-authority / examiner procedures.',
            ],
            [
                'rule' => 'FCL.055',
                'note' => 'Language proficiency. Often confused with the medical language requirement; FCL.055 is the licence-side rule, separate from any Part-MED checks.',
            ],
            [
                'rule' => 'Appendix 6',
                'note' => 'Modular flying training course for the IR — applies to the IR(A) modular path. Distinct from Appendix 3 (CPL/ATPL courses) and Appendix 9 (LAPL/PPL training).',
            ],
        ],
        'editorial_overrides' => [
            'Use EASA British spelling: aeroplane (not airplane), licence (not license), manoeuvre, organisation, authorise, recognise.',
            'When citing a rule in prose, put the rule id in parentheses immediately after the rule name: "Pre-requisites and crediting (FCL.610)".',
            'For licence overviews, group rules by Subpart and Section (e.g. Section 1 – Common requirements, Section 2 – Aeroplane category). If the bundle does not cover one of the sections, simply omit it instead of saying it is missing.',
            'For aeroplane-specific licence questions, drop the generic helicopter / sailplane / balloon variants from the answer unless the user explicitly asked to compare categories.',
        ],
        'regulatory_map_overrides' => [
            '$comment' => 'Optional: only fill in if you want to relabel a subpart/section or add a node that the staging tree is missing. Leave empty for normal operation.',
        ],
    ];
}

/** True when the `easa_semantic_map` table exists. Used to gracefully skip if the migration hasn't been applied. */
function easa_semantic_map_tables_ok(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM easa_semantic_map LIMIT 1');
    } catch (Throwable) {
        return false;
    }

    return true;
}

/**
 * Load the curated overlay row. Returns the default overlay (in-memory only) if the row is missing.
 *
 * @return array{slot_key: string, json: array<string, mixed>, updated_at: ?string, updated_by: ?int, persisted: bool}
 */
function easa_semantic_map_load(PDO $pdo, string $slotKey = 'default'): array
{
    $out = [
        'slot_key' => $slotKey,
        'json' => easa_semantic_map_default_overlay(),
        'updated_at' => null,
        'updated_by' => null,
        'persisted' => false,
    ];
    if (!easa_semantic_map_tables_ok($pdo)) {
        return $out;
    }
    try {
        $st = $pdo->prepare('SELECT json_payload, updated_at, updated_by FROM easa_semantic_map WHERE slot_key = ? LIMIT 1');
        $st->execute([$slotKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $out;
    }
    if (!is_array($row)) {
        return $out;
    }
    $raw = (string) ($row['json_payload'] ?? '');
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return $out;
    }
    $out['json'] = $parsed;
    $out['updated_at'] = isset($row['updated_at']) ? (string) $row['updated_at'] : null;
    $ub = $row['updated_by'] ?? null;
    $out['updated_by'] = ($ub === null || $ub === '') ? null : (int) $ub;
    $out['persisted'] = true;

    return $out;
}

/**
 * Validate an overlay payload. Returns ['ok' => bool, 'errors' => list<string>, 'normalised' => array].
 * Strict-ish: rejects non-array roots, enforces known top-level keys, trims/strips obvious junk.
 *
 * @return array{ok: bool, errors: list<string>, normalised: array<string, mixed>}
 */
function easa_semantic_map_validate(mixed $payload): array
{
    $errors = [];
    if (!is_array($payload)) {
        return ['ok' => false, 'errors' => ['Top-level payload must be a JSON object.'], 'normalised' => []];
    }
    $known = [
        '$schema_version', '$comment',
        'cross_references', 'do_not_confuse', 'editorial_overrides', 'regulatory_map_overrides',
    ];
    $normalised = [];
    foreach ($known as $k) {
        if (array_key_exists($k, $payload)) {
            $normalised[$k] = $payload[$k];
        }
    }
    foreach (array_keys($payload) as $key) {
        if (!in_array($key, $known, true)) {
            $errors[] = sprintf('Unknown top-level key "%s" — ignored.', (string) $key);
        }
    }
    if (isset($normalised['cross_references']) && !is_array($normalised['cross_references'])) {
        $errors[] = 'cross_references must be an object mapping "RULE_ID" → array of strings.';
        unset($normalised['cross_references']);
    }
    if (isset($normalised['do_not_confuse'])) {
        if (!is_array($normalised['do_not_confuse'])) {
            $errors[] = 'do_not_confuse must be an array of {rule, note} objects.';
            unset($normalised['do_not_confuse']);
        } else {
            foreach ($normalised['do_not_confuse'] as $i => $item) {
                if (!is_array($item) || !isset($item['rule']) || !isset($item['note'])) {
                    $errors[] = sprintf('do_not_confuse[%d] must have "rule" and "note" strings.', (int) $i);
                }
            }
        }
    }
    if (isset($normalised['editorial_overrides']) && !is_array($normalised['editorial_overrides'])) {
        $errors[] = 'editorial_overrides must be an array of strings.';
        unset($normalised['editorial_overrides']);
    }
    if (isset($normalised['regulatory_map_overrides']) && !is_array($normalised['regulatory_map_overrides'])) {
        $errors[] = 'regulatory_map_overrides must be an object.';
        unset($normalised['regulatory_map_overrides']);
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'normalised' => $normalised];
}

/**
 * Upsert the overlay row. Returns the persisted load() result.
 *
 * @param array<string, mixed> $payload
 * @return array{slot_key: string, json: array<string, mixed>, updated_at: ?string, updated_by: ?int, persisted: bool}
 */
function easa_semantic_map_save(PDO $pdo, array $payload, ?int $userId = null, string $slotKey = 'default'): array
{
    if (!easa_semantic_map_tables_ok($pdo)) {
        throw new RuntimeException('Apply scripts/sql/resource_library_easa_semantic_map.sql first.');
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $encoded = json_encode($payload, $flags);
    if (!is_string($encoded)) {
        throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    }
    $stmt = $pdo->prepare(
        'INSERT INTO easa_semantic_map (slot_key, json_payload, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE json_payload = VALUES(json_payload), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$slotKey, $encoded, $userId]);

    return easa_semantic_map_load($pdo, $slotKey);
}

/**
 * Derive a hierarchical regulatory tree from the staging table, on the fly.
 *
 * The staging breadcrumb looks like:
 *   "Easy Access Rules for Aircrew (Regulation (EU) No 1178/2011) › ANNEX I (Part-FCL) › Subpart D – Commercial Pilot Licence – CPL › Section 1 – Common requirements › FCL.300 CPL – Minimum age"
 *
 * We split on " › " or "›" and keep up to 5 levels: [corpus] → [annex] → [subpart] → [section] → [rule].
 *
 * Output is structured to be easy for the model to read AND to render in the modal preview:
 *   $tree[$batchId] = [
 *     'batch_label' => 'EAR Flight Crew',
 *     'children' => [
 *       'ANNEX I (Part-FCL)' => [
 *         'Subpart D – Commercial Pilot Licence – CPL' => [
 *           'Section 1 – Common requirements' => [
 *             ['id' => 'FCL.300', 'title' => 'FCL.300 CPL – Minimum age', 'batch_id' => 6, 'node_uid' => '...'],
 *             ...
 *           ]
 *         ]
 *       ]
 *     ]
 *   ];
 *
 * @return array<int, array<string, mixed>>
 */
function easa_semantic_map_build_auto_tree(PDO $pdo, int $maxNodesPerBatch = 1200): array
{
    if (!easa_erules_staging_tables_ok($pdo)) {
        return [];
    }
    try {
        $batches = $pdo->query("SELECT id, COALESCE(original_filename, '') AS f FROM easa_erules_import_batches")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
    if (!$batches) {
        return [];
    }
    $tree = [];
    foreach ($batches as $b) {
        $bid = (int) ($b['id'] ?? 0);
        if ($bid <= 0) {
            continue;
        }
        try {
            $st = $pdo->prepare(
                "SELECT node_uid, source_erules_id, title, breadcrumb
                 FROM easa_erules_import_nodes_staging
                 WHERE batch_id = ?
                   AND LOWER(COALESCE(node_type, '')) = 'topic'
                   AND COALESCE(source_erules_id, '') <> ''
                   AND COALESCE(breadcrumb, '') <> ''
                   AND UPPER(COALESCE(title, '')) NOT REGEXP '^[[:space:]]*(AMC|GM)[0-9]+'
                 ORDER BY id ASC
                 LIMIT {$maxNodesPerBatch}"
            );
            $st->execute([$bid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $rows = [];
        }
        if (!$rows) {
            continue;
        }
        $batchLabel = easa_semantic_map_friendly_batch_label((string) ($b['f'] ?? ''), $bid);
        $children = [];
        foreach ($rows as $r) {
            $crumb = (string) ($r['breadcrumb'] ?? '');
            $parts = easa_semantic_map_split_breadcrumb($crumb);
            if (count($parts) < 2) {
                continue;
            }
            // parts[0] = corpus label, drop it (we already have $batchLabel).
            $annex = $parts[1] ?? '';
            $subpart = $parts[2] ?? '';
            $section = $parts[3] ?? '';
            if ($annex === '') {
                $annex = '(unlabelled)';
            }
            if (!isset($children[$annex])) {
                $children[$annex] = [];
            }
            $sp = $subpart !== '' ? $subpart : '(no subpart)';
            if (!isset($children[$annex][$sp])) {
                $children[$annex][$sp] = [];
            }
            $sec = $section !== '' ? $section : '(no section)';
            if (!isset($children[$annex][$sp][$sec])) {
                $children[$annex][$sp][$sec] = [];
            }
            $children[$annex][$sp][$sec][] = [
                'id' => trim((string) ($r['source_erules_id'] ?? '')),
                'title' => trim((string) ($r['title'] ?? '')),
                'batch_id' => $bid,
                'node_uid' => (string) ($r['node_uid'] ?? ''),
            ];
        }
        $tree[$bid] = [
            'batch_id' => $bid,
            'batch_label' => $batchLabel,
            'children' => $children,
        ];
    }

    return $tree;
}

/**
 * Split an EASA breadcrumb string into its level parts, tolerant of " › " / "›" / " > " variants.
 *
 * @return list<string>
 */
function easa_semantic_map_split_breadcrumb(string $crumb): array
{
    $crumb = trim($crumb);
    if ($crumb === '') {
        return [];
    }
    /** Normalise the various separators EASA breadcrumbs use into a single " › ". */
    $norm = str_replace(["\u{203A}", '>', ' / '], ' › ', $crumb);
    $parts = preg_split('/\s*›\s*/u', $norm) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
}

/** Friendly batch label — mirrors the JS rlEasaMayaFriendlyBatchName mapping. */
function easa_semantic_map_friendly_batch_label(string $rawFilename, int $batchId): string
{
    $raw = strtolower($rawFilename);
    if ($raw === '') {
        return 'EAR batch ' . $batchId;
    }
    $map = [
        '/aircrew|\bfcl\b|part-fcl|flight\s*crew/' => 'EAR Flight Crew',
        '/\bair[-\s]*ops\b|flight\s*operations|\bops\b/' => 'EAR Flight Operations',
        '/\bpart[-\s]*is\b|information\s*security/' => 'EAR Part-IS',
        '/cs[-\s]*fstd|\bfstd\b|simulator/' => 'EAR CS-FSTD',
        '/\bmed\b|medical/' => 'EAR Aircrew Medical',
        '/\bcat\b|commercial\s*air\s*transport/' => 'EAR Air Operations (CAT)',
        '/\bnco\b|\bspo\b/' => 'EAR Air Operations',
        '/balloon/' => 'EAR Balloons',
        '/sailplane/' => 'EAR Sailplanes',
    ];
    foreach ($map as $pattern => $label) {
        if (preg_match($pattern, $raw)) {
            return $label;
        }
    }

    return 'EAR batch ' . $batchId;
}

/**
 * Render the combined semantic map into a compact prompt block for Maya's system prompt.
 *
 * @param array<string, mixed> $overlay      Curated overlay payload from easa_semantic_map_load.
 * @param array<int, array<string, mixed>> $autoTree Result of easa_semantic_map_build_auto_tree.
 * @param int $maxAutoLines Hard cap on auto-tree lines to keep the prompt token-bounded.
 */
function easa_semantic_map_render_for_ai(array $overlay, array $autoTree, int $maxAutoLines = 220): string
{
    $out = [];
    $out[] = '## REGULATORY MAP (authoritative outline — use this verbatim for structure)';
    $out[] = '';

    /** AUTO tree first — gives Maya the canonical hierarchy as it actually exists on this server. */
    if ($autoTree !== []) {
        $out[] = '### Tree shape (auto-derived from the indexed corpus)';
        $lines = 0;
        foreach ($autoTree as $batch) {
            if ($lines >= $maxAutoLines) {
                $out[] = '  … (more in corpus; truncated for prompt size)';
                break;
            }
            $out[] = '- ' . (string) ($batch['batch_label'] ?? 'EAR batch');
            $lines++;
            $children = is_array($batch['children'] ?? null) ? $batch['children'] : [];
            foreach ($children as $annex => $subparts) {
                if ($lines >= $maxAutoLines) {
                    break;
                }
                $out[] = '  - ' . (string) $annex;
                $lines++;
                foreach ((array) $subparts as $subpart => $sections) {
                    if ($lines >= $maxAutoLines) {
                        break;
                    }
                    $out[] = '    - ' . (string) $subpart;
                    $lines++;
                    foreach ((array) $sections as $section => $rules) {
                        if ($lines >= $maxAutoLines) {
                            break;
                        }
                        $ruleIds = [];
                        foreach ((array) $rules as $rule) {
                            $rid = is_array($rule) ? trim((string) ($rule['id'] ?? '')) : '';
                            if ($rid !== '' && !in_array($rid, $ruleIds, true)) {
                                $ruleIds[] = $rid;
                                if (count($ruleIds) >= 14) {
                                    break;
                                }
                            }
                        }
                        $idsTxt = $ruleIds !== [] ? (' — ' . implode(', ', $ruleIds)) : '';
                        $out[] = '      - ' . (string) $section . $idsTxt;
                        $lines++;
                    }
                }
            }
        }
        $out[] = '';
    }

    /** Curated overlay — these are the editorial bits the auto-tree can't infer. */
    $xrefs = is_array($overlay['cross_references'] ?? null) ? $overlay['cross_references'] : [];
    if ($xrefs !== []) {
        $out[] = '### Cross-references (curated)';
        $count = 0;
        foreach ($xrefs as $rule => $refs) {
            if (!is_array($refs)) {
                continue;
            }
            $cleanRefs = [];
            foreach ($refs as $r) {
                $rTxt = trim((string) $r);
                if ($rTxt !== '') {
                    $cleanRefs[] = $rTxt;
                }
            }
            if ($cleanRefs === []) {
                continue;
            }
            $out[] = '- ' . trim((string) $rule) . ' → ' . implode('; ', $cleanRefs);
            $count++;
            if ($count >= 30) {
                break;
            }
        }
        $out[] = '';
    }

    $dnc = is_array($overlay['do_not_confuse'] ?? null) ? $overlay['do_not_confuse'] : [];
    if ($dnc !== []) {
        $out[] = '### Do-not-confuse (curated warnings)';
        $count = 0;
        foreach ($dnc as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rule = trim((string) ($item['rule'] ?? ''));
            $note = trim((string) ($item['note'] ?? ''));
            if ($rule === '' || $note === '') {
                continue;
            }
            $out[] = '- ' . $rule . ': ' . $note;
            $count++;
            if ($count >= 20) {
                break;
            }
        }
        $out[] = '';
    }

    $eds = is_array($overlay['editorial_overrides'] ?? null) ? $overlay['editorial_overrides'] : [];
    if ($eds !== []) {
        $out[] = '### Editorial overrides (curated tone & style notes — follow these)';
        $count = 0;
        foreach ($eds as $line) {
            $lineTxt = trim((string) $line);
            if ($lineTxt === '') {
                continue;
            }
            $out[] = '- ' . $lineTxt;
            $count++;
            if ($count >= 20) {
                break;
            }
        }
        $out[] = '';
    }

    $patches = is_array($overlay['regulatory_map_overrides'] ?? null) ? $overlay['regulatory_map_overrides'] : [];
    $patchesClean = [];
    foreach ($patches as $k => $v) {
        $kStr = (string) $k;
        if ($kStr !== '' && $kStr[0] === '$') {
            continue;
        }
        $patchesClean[$kStr] = $v;
    }
    if ($patchesClean !== []) {
        $out[] = '### Regulatory-map overrides (curated patches to the tree shape above)';
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $enc = json_encode($patchesClean, $flags);
        if (is_string($enc)) {
            $out[] = $enc;
        }
        $out[] = '';
    }

    return implode("\n", $out);
}
