<?php
declare(strict_types=1);

/**
 * One-time import: legacy `ipca_compliance` TablePlus dump → Courseware `ipca_compliance_*` tables.
 *
 * Reads INSERT blocks for: audits, compliance_domains, findings, finding_rca, finding_actions,
 * finding_mccf_links (+ mccf_items / mccf_manuals / mccf_requirements to resolve requirement_key),
 * and ai_finding_runs (included by default; pass --no-ai to skip).
 *
 * User / account linkage: Courseware user rows are never imported. Inserts use NULL for
 * lead_auditor_id, created_by, updated_by, corrective-action responsible_user_id/name,
 * ai_runs.created_by, and RCA approved fields (approved_by_name/approved_at from legacy are not stored).
 *
 * Does NOT import manual_excerpts / mccf_* catalog rows (different product surface). Re-run is blocked if
 * the target audit_code already exists (override with --force).
 *
 * Usage:
 *   Requires Phase 1 `ipca_compliance_*` tables. CW_DB_* env vars must be set (same as app), except for --parse-only.
 *   Repo seed (after deploy): `scripts/run_compliance_legacy_seed.sh` or pass
 *   `scripts/sql/seeds/legacy_compliance_tableplus_dump.sql` explicitly.
 *   php scripts/compliance_import_legacy_tableplus_dump.php /path/to/compliance_DB.sql
 *   php scripts/compliance_import_legacy_tableplus_dump.php /path/to/compliance_DB.sql --no-ai
 *   php scripts/compliance_import_legacy_tableplus_dump.php /path/to/compliance_DB.sql --force
 *   php scripts/compliance_import_legacy_tableplus_dump.php /path/to/compliance_DB.sql --parse-only
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$path = $argv[1] ?? '';
$withAi = !in_array('--no-ai', $argv, true);
$force = in_array('--force', $argv, true);
$parseOnly = in_array('--parse-only', $argv, true);

if ($path === '' || str_starts_with($path, '--')) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " /path/to/compliance_DB.sql [--no-ai] [--force] [--parse-only]\n");
    exit(1);
}

if (!is_readable($path)) {
    fwrite(STDERR, "File not readable: {$path}\n");
    exit(1);
}

$dump = file_get_contents($path);
if ($dump === false) {
    fwrite(STDERR, "Could not read file.\n");
    exit(1);
}

/**
 * @return list<list<mixed>> each value is string|null|array{0:'blob',1:string} for X'hex'
 */
function legacy_parse_insert_rows(string $dump, string $table): array
{
    $needle = 'INSERT INTO `' . $table . '`';
    $p = strpos($dump, $needle);
    if ($p === false) {
        return array();
    }
    $vp = stripos($dump, 'VALUES', $p);
    if ($vp === false) {
        return array();
    }
    $bodyStart = strpos($dump, '(', $vp);
    if ($bodyStart === false) {
        return array();
    }
    $semi = strpos($dump, ";\n", $bodyStart);
    if ($semi === false) {
        $semi = strpos($dump, ';', $bodyStart);
    }
    if ($semi === false) {
        return array();
    }
    $section = substr($dump, $bodyStart, $semi - $bodyStart);

    $rows = array();
    $len = strlen($section);
    $i = 0;

    while ($i < $len) {
        while ($i < $len && ctype_space($section[$i])) {
            $i++;
        }
        if ($i >= $len || $section[$i] !== '(') {
            break;
        }

        $depth = 0;
        $rowStart = $i;
        $inString = false;

        for ($j = $i; $j < $len; $j++) {
            $c = $section[$j];

            if ($inString) {
                if ($c === '\\' && $j + 1 < $len) {
                    $j++;
                    continue;
                }
                if ($c === "'" && $j + 1 < $len && $section[$j + 1] === "'") {
                    $j++;
                    continue;
                }
                if ($c === "'") {
                    $inString = false;
                }
                continue;
            }

            if (($c === 'x' || $c === 'X') && $j + 1 < $len && $section[$j + 1] === "'") {
                $end = strpos($section, "'", $j + 2);
                if ($end === false) {
                    break 2;
                }
                $j = $end;
                continue;
            }

            if ($c === "'") {
                $inString = true;
                continue;
            }

            if ($c === '(') {
                $depth++;
                continue;
            }

            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $inner = substr($section, $rowStart + 1, $j - $rowStart - 1);
                    $rows[] = legacy_parse_row_values($inner);
                    $i = $j + 1;
                    while ($i < $len && ctype_space($section[$i])) {
                        $i++;
                    }
                    if ($i < $len && $section[$i] === ',') {
                        $i++;
                    }
                    break;
                }
            }
        }
    }

    return $rows;
}

/**
 * @return list<mixed>
 */
function legacy_parse_row_values(string $inner): array
{
    $vals = array();
    $len = strlen($inner);
    $i = 0;

    while ($i < $len) {
        while ($i < $len && ctype_space($inner[$i])) {
            $i++;
        }
        if ($i >= $len) {
            break;
        }

        if (strncasecmp(substr($inner, $i, 4), 'NULL', 4) === 0
            && ($i + 4 >= $len || !ctype_alnum($inner[$i + 4]))) {
            $vals[] = null;
            $i += 4;
            while ($i < $len && ctype_space($inner[$i])) {
                $i++;
            }
            if ($i < $len && $inner[$i] === ',') {
                $i++;
            }
            continue;
        }

        if (($inner[$i] === 'X' || $inner[$i] === 'x') && isset($inner[$i + 1]) && $inner[$i + 1] === "'") {
            $end = strpos($inner, "'", $i + 2);
            if ($end === false) {
                break;
            }
            $hex = strtolower(substr($inner, $i + 2, $end - ($i + 2)));
            $vals[] = array('blob', $hex);
            $i = $end + 1;
            while ($i < $len && ctype_space($inner[$i])) {
                $i++;
            }
            if ($i < $len && $inner[$i] === ',') {
                $i++;
            }
            continue;
        }

        if ($inner[$i] === "'") {
            $sb = '';
            $i++;
            while ($i < $len) {
                if ($inner[$i] === "'" && $i + 1 < $len && $inner[$i + 1] === "'") {
                    $sb .= "'";
                    $i += 2;
                    continue;
                }
                if ($inner[$i] === '\\' && $i + 1 < $len) {
                    $sb .= $inner[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($inner[$i] === "'") {
                    $i++;
                    break;
                }
                $sb .= $inner[$i];
                $i++;
            }
            $vals[] = $sb;
            while ($i < $len && ctype_space($inner[$i])) {
                $i++;
            }
            if ($i < $len && $inner[$i] === ',') {
                $i++;
            }
            continue;
        }

        $start = $i;
        while ($i < $len && $inner[$i] !== ',') {
            $i++;
        }
        $vals[] = trim(substr($inner, $start, $i - $start));
        if ($i < $len && $inner[$i] === ',') {
            $i++;
        }
    }

    return $vals;
}

/**
 * @param mixed $v
 */
function legacy_uuid_from_value($v): string
{
    if (is_array($v) && ($v[0] ?? '') === 'blob') {
        $hex = (string)($v[1] ?? '');
        if (strlen($hex) !== 32) {
            return '';
        }
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
    return '';
}

function legacy_finding_code_from_reference(string $ref): string
{
    if (preg_match('/NC\.(\d+)/i', $ref, $m)) {
        return 'NCR-2026-' . str_pad($m[1], 5, '0', STR_PAD_LEFT);
    }
    $h = substr(sha1($ref), 0, 8);
    return 'NCR-2026-LEG' . strtoupper($h);
}

function legacy_map_authority(string $legacyAuth, string $entity): string
{
    $u = strtoupper(trim($legacyAuth));
    if ($entity !== '' && stripos($entity, 'BCAA') !== false) {
        return 'BCAA';
    }
    return in_array($u, array('BCAA', 'FAA', 'EASA', 'INTERNAL', 'OTHER'), true) ? $u : 'INTERNAL';
}

function legacy_map_audit_category(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $u = strtoupper(trim($raw));
    if ($u === 'CAA') {
        return 'COMPLIANCE';
    }
    return strlen($u) > 32 ? substr($u, 0, 32) : $u;
}

function legacy_trunc_subject(mixed $v): ?string
{
    if (!is_string($v) || trim($v) === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($v, 0, 255);
    }
    return substr($v, 0, 255);
}

function legacy_normalize_mccf_manual_part(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'P0';
    }
    if (preg_match('/^P(\d+)$/i', $raw, $m)) {
        return 'P' . $m[1];
    }
    if (preg_match('/PART\s*(\d+)/i', $raw, $m)) {
        return 'P' . $m[1];
    }
    if (ctype_digit($raw)) {
        return 'P' . $raw;
    }
    return 'P0';
}

function legacy_manual_tag_from_requirement_key(string $requirementKey): ?string
{
    $parts = explode('|', $requirementKey, 2);
    $seg = trim($parts[0] ?? '');
    if ($seg === '') {
        return null;
    }
    if (preg_match('/^([A-Za-z_]+)/', $seg, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

/**
 * Build ipca_compliance_finding_mccf_links from legacy finding_mccf_links + findings.requirement_key.
 *
 * Resolves mccf_item_id → requirement_key via mccf_items + mccf_manuals tuple match into mccf_requirements.
 *
 * @param array<string,int> $uuidToFindingId
 * @param list<list<mixed>> $findingRows
 * @return array{from_junction:int,from_finding:int,skipped_junction:int}
 */
function legacy_second_pass_mccf(PDO $pdo, string $dump, array $uuidToFindingId, array $findingRows): array
{
    $stats = array('from_junction' => 0, 'from_finding' => 0, 'skipped_junction' => 0);

    $tupleToMeta = array();
    $keyToSubject = array();
    foreach (legacy_parse_insert_rows($dump, 'mccf_requirements') as $r) {
        if (count($r) < 11) {
            continue;
        }
        $rk = trim((string)$r[2]);
        if ($rk === '') {
            continue;
        }
        $mc = strtoupper(trim((string)$r[1]));
        $part = legacy_normalize_mccf_manual_part((string)$r[7]);
        $ino = trim((string)$r[8]);
        $sno = trim((string)$r[9]);
        $tupleKey = $mc . '|' . $part . '|' . $ino . '|' . $sno;
        $tupleToMeta[$tupleKey] = array(
            'requirement_key' => $rk,
            'manual_code' => $mc,
            'subject' => legacy_trunc_subject($r[10] ?? null),
        );
        $keyToSubject[$rk] = legacy_trunc_subject($r[10] ?? null);
    }

    $manualById = array();
    foreach (legacy_parse_insert_rows($dump, 'mccf_manuals') as $r) {
        if (count($r) >= 4) {
            $manualById[(int)$r[0]] = array(
                'code' => trim((string)$r[1]),
                'revision' => trim((string)$r[3]),
            );
        }
    }

    $itemIdToResolved = array();
    foreach (legacy_parse_insert_rows($dump, 'mccf_items') as $r) {
        if (count($r) < 5) {
            continue;
        }
        $itemId = (int)$r[0];
        $mid = (int)$r[1];
        $manual = $manualById[$mid] ?? null;
        if ($manual === null) {
            continue;
        }
        $mc = strtoupper($manual['code']);
        $partNorm = legacy_normalize_mccf_manual_part((string)$r[2]);
        $ino = trim((string)$r[3]);
        $sno = trim((string)$r[4]);
        $tupleKey = $mc . '|' . $partNorm . '|' . $ino . '|' . $sno;
        if (isset($tupleToMeta[$tupleKey])) {
            $itemIdToResolved[$itemId] = $tupleToMeta[$tupleKey];
        }
    }

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO ipca_compliance_finding_mccf_links (
            finding_id, requirement_key, manual_code, mccf_subject, link_type, notes
        ) VALUES (?, ?, ?, ?, \'PRIMARY\', ?)'
    );

    foreach (legacy_parse_insert_rows($dump, 'finding_mccf_links') as $lr) {
        $legacyFid = legacy_uuid_from_value($lr[0] ?? null);
        $itemId = (int)($lr[1] ?? 0);
        if ($legacyFid === '' || empty($uuidToFindingId[$legacyFid]) || $itemId <= 0) {
            $stats['skipped_junction']++;
            continue;
        }
        $resolved = $itemIdToResolved[$itemId] ?? null;
        if ($resolved === null || ($resolved['requirement_key'] ?? '') === '') {
            $stats['skipped_junction']++;
            continue;
        }
        $newFid = $uuidToFindingId[$legacyFid];
        $rk = (string)$resolved['requirement_key'];
        $ins->execute(array(
            $newFid,
            $rk,
            $resolved['manual_code'] ?? legacy_manual_tag_from_requirement_key($rk),
            $resolved['subject'] ?? ($keyToSubject[$rk] ?? null),
            'legacy finding_mccf_links; mccf_item_id=' . $itemId,
        ));
        if ($ins->rowCount() > 0) {
            $stats['from_junction']++;
        }
    }

    foreach ($findingRows as $fr) {
        $legacyFid = legacy_uuid_from_value($fr[0] ?? null);
        if ($legacyFid === '' || empty($uuidToFindingId[$legacyFid])) {
            continue;
        }
        $reqKey = isset($fr[9]) && is_string($fr[9]) && trim($fr[9]) !== '' ? trim($fr[9]) : null;
        if ($reqKey === null) {
            continue;
        }
        $newFid = $uuidToFindingId[$legacyFid];
        $mc = legacy_manual_tag_from_requirement_key($reqKey);
        $subj = $keyToSubject[$reqKey] ?? null;
        $ins->execute(array(
            $newFid,
            $reqKey,
            $mc,
            $subj,
            'legacy findings.requirement_key',
        ));
        if ($ins->rowCount() > 0) {
            $stats['from_finding']++;
        }
    }

    return $stats;
}

if ($parseOnly) {
    fwrite(STDOUT, sprintf(
        "Parse OK (no DB):\n  audits=%d\n  compliance_domains=%d\n  findings=%d\n  finding_rca=%d\n  finding_actions=%d\n  ai_finding_runs=%d\n  finding_mccf_links=%d\n  mccf_items=%d\n  mccf_requirements=%d\n",
        count(legacy_parse_insert_rows($dump, 'audits')),
        count(legacy_parse_insert_rows($dump, 'compliance_domains')),
        count(legacy_parse_insert_rows($dump, 'findings')),
        count(legacy_parse_insert_rows($dump, 'finding_rca')),
        count(legacy_parse_insert_rows($dump, 'finding_actions')),
        count(legacy_parse_insert_rows($dump, 'ai_finding_runs')),
        count(legacy_parse_insert_rows($dump, 'finding_mccf_links')),
        count(legacy_parse_insert_rows($dump, 'mccf_items')),
        count(legacy_parse_insert_rows($dump, 'mccf_requirements')),
    ));
    exit(0);
}

require_once __DIR__ . '/../src/db.php';

/** @var array<int,string> */
$domainIdToCode = array();
$domainRows = legacy_parse_insert_rows($dump, 'compliance_domains');
foreach ($domainRows as $r) {
    if (count($r) >= 3 && is_string($r[1])) {
        $id = (int)$r[0];
        $domainIdToCode[$id] = (string)$r[1];
    }
}

$auditRows = legacy_parse_insert_rows($dump, 'audits');
if (count($auditRows) !== 1) {
    fwrite(STDERR, 'Expected exactly one row in legacy `audits`, found ' . count($auditRows) . ".\n");
    exit(1);
}

$auditVals = $auditRows[0];
$legacyAuditUuid = legacy_uuid_from_value($auditVals[0] ?? null);
$auditCategory = legacy_map_audit_category(is_string($auditVals[1] ?? null) ? $auditVals[1] : null);
$auditEntity = is_string($auditVals[2] ?? null) ? trim($auditVals[2]) : '';
$externalRef = is_string($auditVals[3] ?? null) ? trim($auditVals[3]) : '';
$auditTitle = is_string($auditVals[4] ?? null) ? $auditVals[4] : 'Imported audit';
$legacyAuthority = is_string($auditVals[5] ?? null) ? $auditVals[5] : 'INTERNAL';
$auditType = is_string($auditVals[7] ?? null) ? substr($auditVals[7], 0, 64) : 'IMPORT';
$auditStatus = is_string($auditVals[8] ?? null) ? strtoupper($auditVals[8]) : 'IN_PROGRESS';
$startDate = is_string($auditVals[9] ?? null) && $auditVals[9] !== '' ? substr($auditVals[9], 0, 10) : null;
$endDate = is_string($auditVals[10] ?? null) && $auditVals[10] !== '' ? substr($auditVals[10], 0, 10) : null;
$closedDate = is_string($auditVals[11] ?? null) && $auditVals[11] !== '' ? substr($auditVals[11], 0, 10) : null;
$subject = is_string($auditVals[12] ?? null) ? $auditVals[12] : null;
$auditorsNote = is_string($auditVals[16] ?? null) ? trim($auditVals[16]) : '';
$attendeesNote = is_string($auditVals[17] ?? null) ? trim($auditVals[17]) : '';
$extraSubject = '';
if ($auditorsNote !== '') {
    $extraSubject .= "\n\nAuditors (legacy):\n" . $auditorsNote;
}
if ($attendeesNote !== '') {
    $extraSubject .= "\n\nAttendees (legacy):\n" . $attendeesNote;
}
if ($extraSubject !== '') {
    $subject = ($subject ?? '') . $extraSubject;
}

$authority = legacy_map_authority($legacyAuthority, $auditEntity);
$auditCode = 'LEGACY-AUD-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($externalRef !== '' ? $externalRef : 'IMPORT'));
$auditCode = trim($auditCode, '-');
if (strlen($auditCode) > 64) {
    $auditCode = substr($auditCode, 0, 64);
}

$pdo = cw_db();

if (!$force) {
    $chk = $pdo->prepare('SELECT id FROM ipca_compliance_audits WHERE audit_code = ? LIMIT 1');
    $chk->execute(array($auditCode));
    if ($chk->fetchColumn()) {
        fwrite(STDERR, "Import already present (audit_code={$auditCode}). Use --force to import again.\n");
        exit(2);
    }
}

$findingRows = legacy_parse_insert_rows($dump, 'findings');
if ($findingRows === array()) {
    fwrite(STDERR, "No rows in legacy `findings`.\n");
    exit(1);
}

$rcaByFindingUuid = array();
foreach (legacy_parse_insert_rows($dump, 'finding_rca') as $r) {
    $fid = legacy_uuid_from_value($r[0] ?? null);
    if ($fid !== '') {
        $rcaByFindingUuid[$fid] = $r;
    }
}

/** @var array<string,list<list<mixed>>> */
$actionsByFindingUuid = array();
foreach (legacy_parse_insert_rows($dump, 'finding_actions') as $r) {
    $fid = legacy_uuid_from_value($r[1] ?? null);
    if ($fid === '') {
        continue;
    }
    if (!isset($actionsByFindingUuid[$fid])) {
        $actionsByFindingUuid[$fid] = array();
    }
    $actionsByFindingUuid[$fid][] = $r;
}

usort($findingRows, static function (array $a, array $b): int {
    $refA = is_string($a[2] ?? null) ? $a[2] : '';
    $refB = is_string($b[2] ?? null) ? $b[2] : '';
    preg_match('/(\d+)/', $refA, $ma);
    preg_match('/(\d+)/', $refB, $mb);
    return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
});

$pdo->beginTransaction();

try {
    $insAudit = $pdo->prepare(
        'INSERT INTO ipca_compliance_audits (
            case_id, audit_code, title, authority, audit_category, audit_type, audit_entity,
            external_ref, status, subject, start_date, end_date, closed_date,
            lead_auditor_id, created_by, updated_by, created_at, updated_at
        ) VALUES (
            NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            NULL, NULL, NULL, NOW(), NOW()
        )'
    );
    $insAudit->execute(array(
        $auditCode,
        $auditTitle,
        $authority,
        $auditCategory,
        $auditType,
        $auditEntity !== '' ? $auditEntity : null,
        $externalRef !== '' ? $externalRef : null,
        $auditStatus,
        $subject,
        $startDate,
        $endDate,
        $closedDate,
    ));

    $newAuditId = (int)$pdo->lastInsertId();

    $insFinding = $pdo->prepare(
        'INSERT INTO ipca_compliance_findings (
            audit_id, case_id, finding_code, reference, title, description,
            classification, severity, status, domain_code, requirement_key,
            regulation_summary, raised_date, target_date, closed_date,
            cap_selected_option, cap_selected_effort, notes,
            created_by, updated_by, created_at, updated_at
        ) VALUES (
            ?, NULL, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            NULL, NULL, ?, ?
        )'
    );

    $insRca = $pdo->prepare(
        'INSERT INTO ipca_compliance_finding_rca (
            finding_id, method, steps_json, root_cause_text, ai_assisted, ai_run_id,
            approved_by, approved_by_name, approved_at,
            locked_at, locked_by, lock_reason,
            created_by, updated_by, created_at, updated_at
        ) VALUES (
            ?, \'FIVE_WHYS\', CAST(? AS JSON), ?, 0, NULL,
            NULL, ?, ?,
            ?, NULL, ?,
            NULL, NULL, ?, ?
        )'
    );

    $insCap = $pdo->prepare(
        'INSERT INTO ipca_compliance_corrective_actions (
            finding_id, case_id, action_code, action_type, title, description,
            status, effort, responsible_user_id, responsible_name, due_date,
            started_at, completed_at, verified_at, verified_by,
            ai_assisted, ai_run_id, created_by, updated_by, created_at, updated_at
        ) VALUES (
            ?, NULL, ?, ?, ?, ?,
            ?, NULL, NULL, NULL, ?,
            NULL, ?, NULL, NULL,
            0, NULL, NULL, NULL, ?, NOW()
        )'
    );

    /** @var array<string,int> */
    $uuidToFindingId = array();

    foreach ($findingRows as $fr) {
        $legacyFid = legacy_uuid_from_value($fr[0] ?? null);
        if ($legacyFid === '') {
            throw new RuntimeException('Finding row missing UUID.');
        }

        $reference = is_string($fr[2] ?? null) ? $fr[2] : '';
        $title = is_string($fr[3] ?? null) ? $fr[3] : '';
        $classification = is_string($fr[4] ?? null) ? strtoupper($fr[4]) : 'LEVEL_3';
        $status = is_string($fr[5] ?? null) ? strtoupper($fr[5]) : 'OPEN';
        $severity = is_string($fr[6] ?? null) ? strtoupper($fr[6]) : 'MEDIUM';
        $description = is_string($fr[7] ?? null) ? $fr[7] : '';
        $regulationSummary = isset($fr[8]) && is_string($fr[8]) && $fr[8] !== '' ? $fr[8] : null;
        $reqKey = isset($fr[9]) && is_string($fr[9]) && $fr[9] !== '' ? $fr[9] : null;
        $raised = is_string($fr[10] ?? null) ? substr($fr[10], 0, 10) : date('Y-m-d');
        $target = isset($fr[11]) && is_string($fr[11]) && $fr[11] !== '' ? substr($fr[11], 0, 10) : null;
        $closed = isset($fr[12]) && is_string($fr[12]) && $fr[12] !== '' ? substr($fr[12], 0, 10) : null;
        $domainId = isset($fr[13]) ? (int)$fr[13] : 0;
        $createdAt = (is_string($fr[14] ?? null) && trim($fr[14]) !== '') ? $fr[14] : date('Y-m-d H:i:s');
        $updatedAt = (is_string($fr[15] ?? null) && trim($fr[15]) !== '') ? $fr[15] : $createdAt;
        $capOpt = isset($fr[16]) && is_string($fr[16]) && $fr[16] !== '' ? substr($fr[16], 0, 16) : null;
        $capEff = isset($fr[17]) && is_string($fr[17]) && $fr[17] !== '' ? substr($fr[17], 0, 32) : null;
        $notes = isset($fr[18]) && is_string($fr[18]) && $fr[18] !== '' ? $fr[18] : null;

        $domainCode = $domainId > 0 && isset($domainIdToCode[$domainId]) ? $domainIdToCode[$domainId] : null;

        $findingCode = legacy_finding_code_from_reference($reference);

        $insFinding->execute(array(
            $newAuditId,
            $findingCode,
            $reference !== '' ? $reference : null,
            $title,
            $description,
            $classification,
            $severity,
            $status,
            $domainCode,
            $reqKey,
            $regulationSummary,
            $raised,
            $target,
            $closed,
            $capOpt,
            $capEff,
            $notes,
            $createdAt,
            $updatedAt,
        ));

        $newFid = (int)$pdo->lastInsertId();
        $uuidToFindingId[$legacyFid] = $newFid;

        if (isset($rcaByFindingUuid[$legacyFid])) {
            $rr = $rcaByFindingUuid[$legacyFid];
            $stepsJson = is_string($rr[1] ?? null) ? $rr[1] : '[]';
            $rootCause = isset($rr[5]) && is_string($rr[5]) && $rr[5] !== '' ? $rr[5] : null;
            $lockedAt = isset($rr[6]) && is_string($rr[6]) && $rr[6] !== '' ? $rr[6] : null;
            $lockReason = isset($rr[8]) && is_string($rr[8]) && $rr[8] !== '' ? $rr[8] : null;
            $rcaCreated = (is_string($rr[3] ?? null) && trim($rr[3]) !== '') ? $rr[3] : $createdAt;
            $rcaUpdated = (is_string($rr[4] ?? null) && trim($rr[4]) !== '') ? $rr[4] : $rcaCreated;
            $insRca->execute(array(
                $newFid,
                $stepsJson,
                $rootCause,
                null,
                null,
                $lockedAt,
                $lockReason,
                $rcaCreated,
                $rcaUpdated,
            ));
        }

        if (isset($actionsByFindingUuid[$legacyFid])) {
            $capSeq = 0;
            foreach ($actionsByFindingUuid[$legacyFid] as $ar) {
                $actionType = is_string($ar[2] ?? null) ? strtoupper($ar[2]) : 'CORRECTIVE';
                $desc = is_string($ar[3] ?? null) ? $ar[3] : '';
                if (trim($desc) === '') {
                    continue;
                }
                $due = isset($ar[5]) && is_string($ar[5]) && $ar[5] !== '' ? substr($ar[5], 0, 10) : null;
                $completedAt = isset($ar[6]) && is_string($ar[6]) && $ar[6] !== '' ? $ar[6] : null;
                $actionCreated = (is_string($ar[8] ?? null) && trim($ar[8]) !== '') ? $ar[8] : date('Y-m-d H:i:s');
                $capStatus = $completedAt !== null ? 'COMPLETED' : 'PROPOSED';

                $titleCap = $desc;
                if (function_exists('mb_substr')) {
                    $titleCap = mb_substr($titleCap, 0, 255);
                } else {
                    $titleCap = substr($titleCap, 0, 255);
                }

                $capSeq++;
                $actionCode = sprintf('CAP-IMP-%d-%02d', $newFid, $capSeq);
                if (strlen($actionCode) > 64) {
                    $actionCode = substr($actionCode, 0, 64);
                }

                $insCap->execute(array(
                    $newFid,
                    $actionCode,
                    $actionType,
                    $titleCap,
                    $desc,
                    $capStatus,
                    $due,
                    $completedAt,
                    $actionCreated,
                ));
            }
        }
    }

    $mccfStats = legacy_second_pass_mccf($pdo, $dump, $uuidToFindingId, $findingRows);

    if ($withAi) {
        $aiRows = legacy_parse_insert_rows($dump, 'ai_finding_runs');
        $insAi = $pdo->prepare(
            'INSERT INTO ipca_compliance_ai_runs (
                source_object_type, source_object_id, run_type, status, model,
                prompt_text, prompt_hash, evidence_snapshot_json, response_json, response_text,
                latency_ms, error_message, created_by
            ) VALUES (
                \'finding\', ?, ?, ?, ?,
                NULL, NULL, NULL, CAST(? AS JSON), NULL,
                NULL, NULL, NULL
            )'
        );
        foreach ($aiRows as $ai) {
            $legacyFid = legacy_uuid_from_value($ai[1] ?? null);
            if ($legacyFid === '' || empty($uuidToFindingId[$legacyFid])) {
                continue;
            }
            $newFid = $uuidToFindingId[$legacyFid];
            $runTypeLegacy = is_string($ai[2] ?? null) ? strtoupper($ai[2]) : 'RCA';
            $runType = 'OTHER';
            if ($runTypeLegacy === 'RCA') {
                $runType = 'RCA_SUGGEST';
            } elseif ($runTypeLegacy === 'CAP') {
                $runType = 'CAP_SUGGEST';
            } elseif ($runTypeLegacy === 'RCA_AND_CAP') {
                $runType = 'RCA_AND_CAP';
            }
            $model = isset($ai[3]) && is_string($ai[3]) ? substr($ai[3], 0, 64) : null;
            $respJson = is_string($ai[6] ?? null) ? $ai[6] : '{}';
            json_decode($respJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $respJson = json_encode(array('raw' => $respJson), JSON_UNESCAPED_UNICODE);
            }

            $status = 'OK';
            $dec = json_decode($respJson, true);
            if (is_array($dec) && (($dec['status'] ?? '') === 'incomplete')) {
                $status = 'ERROR';
            }

            $insAi->execute(array($newFid, $runType, $status, $model, $respJson));
        }
    }

    $pdo->commit();
    echo 'Import OK: audit_id=' . $newAuditId . ', audit_code=' . $auditCode
        . ', findings=' . count($uuidToFindingId)
        . ', mccf_links junction=' . (int)$mccfStats['from_junction']
        . ' finding_key=' . (int)$mccfStats['from_finding']
        . ' junction_skipped=' . (int)$mccfStats['skipped_junction']
        . ($withAi ? ', ai_runs imported' : ', ai_runs skipped (--no-ai)') . "\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
