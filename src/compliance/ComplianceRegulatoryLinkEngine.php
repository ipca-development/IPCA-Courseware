<?php
declare(strict_types=1);

require_once __DIR__ . '/../resource_library_aim.php';

/**
 * Phase 3c — Regulatory bridge: polymorphic links from findings to AIM, EASA eRules, or external URLs.
 *
 * source_id conventions:
 * - rl_aim_paragraph: decimal paragraph row id (resource_library_aim_paragraphs.id)
 * - easa_node: "{batch_id}:{node_uid}" (matches staging uniqueness)
 * - external_url: raw https URL when length ≤191, else sha1 hex (40 chars) with full URL in citation_url
 */
final class ComplianceRegulatoryLinkEngine
{
    public const KIND_AIM = 'rl_aim_paragraph';
    public const KIND_EASA = 'easa_node';
    public const KIND_EXTERNAL = 'external_url';

    /** @return list<string> */
    public static function allowedKinds(): array
    {
        return array(self::KIND_AIM, self::KIND_EASA, self::KIND_EXTERNAL);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForFinding(PDO $pdo, int $findingId): array
    {
        if ($findingId <= 0) {
            return array();
        }
        $st = $pdo->prepare(
            'SELECT * FROM ipca_compliance_finding_regulatory_links WHERE finding_id = ?
             ORDER BY FIELD(link_type, \'PRIMARY\', \'SUPPORTING\'), created_at ASC, id ASC'
        );
        $st->execute(array($findingId));

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolve human label + URL from the catalog row (when available).
     *
     * @return array{label:?string,url:?string}
     */
    public static function resolveDisplay(PDO $pdo, string $kind, string $sourceId): array
    {
        $out = array('label' => null, 'url' => null);
        $kind = trim($kind);
        $sourceId = trim($sourceId);
        if ($kind === self::KIND_AIM && ctype_digit($sourceId)) {
            if (!rl_aim_tables_present($pdo)) {
                return $out;
            }
            $st = $pdo->prepare(
                'SELECT paragraph_number, display_title, canonical_url
                 FROM resource_library_aim_paragraphs WHERE id = ? LIMIT 1'
            );
            $st->execute(array((int)$sourceId));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $pn = trim((string)($r['paragraph_number'] ?? ''));
                $dt = trim((string)($r['display_title'] ?? ''));
                $out['label'] = $pn !== '' ? ('AIM ' . $pn) : ($dt !== '' ? $dt : ('AIM paragraph #' . $sourceId));
                $cu = trim((string)($r['canonical_url'] ?? ''));
                $out['url'] = $cu !== '' ? $cu : null;
            }

            return $out;
        }
        if ($kind === self::KIND_EASA) {
            $parts = explode(':', $sourceId, 2);
            if (count($parts) !== 2 || !ctype_digit(trim($parts[0])) || trim($parts[1]) === '') {
                return $out;
            }
            $batchId = (int)$parts[0];
            $nodeUid = trim($parts[1]);
            if (!self::easaStagingPresent($pdo)) {
                return $out;
            }
            $st = $pdo->prepare(
                'SELECT source_erules_id, title, breadcrumb
                 FROM easa_erules_import_nodes_staging
                 WHERE batch_id = ? AND node_uid = ? LIMIT 1'
            );
            $st->execute(array($batchId, $nodeUid));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $er = trim((string)($r['source_erules_id'] ?? ''));
                $ti = trim((string)($r['title'] ?? ''));
                $out['label'] = $er !== '' ? $er : ($ti !== '' ? (function_exists('mb_substr') ? mb_substr($ti, 0, 220) : substr($ti, 0, 220)) : $nodeUid);
                $out['url'] = null;
            }

            return $out;
        }
        if ($kind === self::KIND_EXTERNAL) {
            // source_id may be sha1; label/url come from stored row or caller
            return $out;
        }

        return $out;
    }

    public static function easaStagingPresent(PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE 'easa_erules_import_nodes_staging'");

            return (bool)$st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalise external URL into source_id + always store full URL in citation_url.
     *
     * @return array{0:string,1:string} [sourceId, normalizedHttpsUrl]
     */
    public static function normaliseExternalSourceId(string $url): array
    {
        $url = trim($url);
        if (preg_match('#^http://#i', $url)) {
            $url = 'https://' . substr($url, 7);
        }
        if (!preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('External citation URL must be https://');
        }
        if (strlen($url) > 2048) {
            throw new InvalidArgumentException('URL is too long.');
        }
        if (strlen($url) <= 191) {
            return array($url, $url);
        }

        return array(sha1($url), $url);
    }

    /**
     * @return int New link id
     */
    public static function attach(
        PDO $pdo,
        int $findingId,
        string $kind,
        string $sourceId,
        ?string $citationLabel,
        ?string $citationUrl,
        string $linkType,
        string $confidence,
        ?string $notes,
        int $userId
    ): int {
        if ($findingId <= 0) {
            throw new InvalidArgumentException('Invalid finding.');
        }
        if (!in_array($kind, self::allowedKinds(), true)) {
            throw new InvalidArgumentException('Invalid source kind.');
        }
        $sourceId = trim($sourceId);
        if ($sourceId === '' || strlen($sourceId) > 191) {
            throw new InvalidArgumentException('Invalid source_id.');
        }
        $linkType = strtoupper(trim($linkType));
        if ($linkType !== 'PRIMARY' && $linkType !== 'SUPPORTING') {
            $linkType = 'PRIMARY';
        }
        $confidence = strtoupper(trim($confidence));
        if ($confidence !== 'VERIFIED' && $confidence !== 'MANUAL' && $confidence !== 'AUTO') {
            $confidence = 'MANUAL';
        }

        $chkF = $pdo->prepare('SELECT id FROM ipca_compliance_findings WHERE id = ? LIMIT 1');
        $chkF->execute(array($findingId));
        if (!$chkF->fetchColumn()) {
            throw new RuntimeException('Finding not found.');
        }

        $chkDup = $pdo->prepare(
            'SELECT id FROM ipca_compliance_finding_regulatory_links
             WHERE finding_id = ? AND source_kind = ? AND source_id = ? LIMIT 1'
        );
        $chkDup->execute(array($findingId, $kind, $sourceId));
        if ($chkDup->fetchColumn()) {
            throw new RuntimeException('This citation is already attached to the finding.');
        }

        $resolved = self::resolveDisplay($pdo, $kind, $sourceId);
        $label = $citationLabel !== null && trim($citationLabel) !== '' ? trim($citationLabel) : $resolved['label'];
        $url = $citationUrl !== null && trim($citationUrl) !== '' ? trim($citationUrl) : $resolved['url'];

        if ($kind === self::KIND_EXTERNAL && ($url === null || $url === '')) {
            $url = $sourceId;
            if (strlen($url) === 40 && ctype_xdigit($url)) {
                throw new InvalidArgumentException('Provide the full https URL for external citations.');
            }
        }

        $verifiedBy = $confidence === 'VERIFIED' ? $userId : null;
        $verifiedAt = $confidence === 'VERIFIED' ? date('Y-m-d H:i:s') : null;

        $ins = $pdo->prepare(
            'INSERT INTO ipca_compliance_finding_regulatory_links (
                finding_id, source_kind, source_id, citation_label, citation_url,
                link_type, confidence, verified_by, verified_at, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(array(
            $findingId,
            $kind,
            $sourceId,
            $label !== null && $label !== '' ? (function_exists('mb_substr') ? mb_substr($label, 0, 255) : substr($label, 0, 255)) : null,
            $url !== null && $url !== '' ? (function_exists('mb_substr') ? mb_substr($url, 0, 2048) : substr($url, 0, 2048)) : null,
            $linkType,
            $confidence,
            $verifiedBy,
            $verifiedAt,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $userId > 0 ? $userId : null,
        ));

        return (int)$pdo->lastInsertId();
    }

    public static function detach(PDO $pdo, int $linkId, int $findingId): void
    {
        if ($linkId <= 0 || $findingId <= 0) {
            throw new InvalidArgumentException('Invalid link or finding.');
        }
        $st = $pdo->prepare(
            'DELETE FROM ipca_compliance_finding_regulatory_links WHERE id = ? AND finding_id = ?'
        );
        $st->execute(array($linkId, $findingId));
        if ($st->rowCount() < 1) {
            throw new RuntimeException('Citation link not found.');
        }
    }
}
