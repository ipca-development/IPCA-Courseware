<?php
declare(strict_types=1);

/**
 * List of Effective Parts (LEP) layout, signatories, and auto-generated part rows.
 */
final class ControlledPublishingLepService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultLepPage(): array
    {
        return array(
            'certification_text' => 'EuroPilot Center\'s below nominated persons certify that the content of this Operations Manual complies with all applicable EASA PART-FCL and PART NCO requirements.',
            'on_behalf_text' => 'On behalf of EuroPilot Center: (name and function)',
            'table_title' => 'Effective Parts',
            'empty_rows' => 10,
            'signatories' => $this->defaultSignatories(),
            'effective_parts' => array(),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function defaultSignatories(): array
    {
        return array(
            array(
                'slot_key' => 'accountable_manager',
                'name' => 'Maria A. Paz',
                'title' => 'Accountable Manager',
                'date' => '',
                'signature_url' => '',
                'signed_at' => null,
                'signed_by_user_id' => null,
                'signer_type' => 'internal',
            ),
            array(
                'slot_key' => 'compliance_manager',
                'name' => 'Koen Maes',
                'title' => 'Compliance Monitoring Manager',
                'date' => '',
                'signature_url' => '',
                'signed_at' => null,
                'signed_by_user_id' => null,
                'signer_type' => 'internal',
            ),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function resolveFromMetadata(array $metadata): array
    {
        $defaults = $this->defaultLepPage();
        $raw = is_array($metadata['lep_page'] ?? null) ? $metadata['lep_page'] : array();
        return $this->normalizeLepPage(array_merge($defaults, $raw), $defaults);
    }

    /**
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    public function resolveFromVersion(array $version): array
    {
        return $this->resolveFromMetadata($this->decodeMeta($version));
    }

    /**
     * @param array<string,mixed> $lepPage
     * @return array<string,mixed>
     */
    public function saveLepPageForVersion(int $versionId, array $lepPage, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $existing = $this->resolveFromMetadata($meta);
        $merged = array_merge($existing, $lepPage);
        if (is_array($lepPage['signatories'] ?? null)) {
            $merged['signatories'] = $this->mergeSignatories(
                $existing['signatories'],
                $lepPage['signatories']
            );
        }
        $normalized = $this->normalizeLepPage($merged, $this->defaultLepPage());
        $meta['lep_page'] = $normalized;

        $this->persistMeta($versionId, $meta);
        return $normalized;
    }

    /**
     * @return array{lep_page:array<string,mixed>,parts_count:int}
     */
    public function regenerateEffectiveParts(int $versionId, ?int $actorUserId = null): array
    {
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $lep = $this->resolveFromMetadata($meta);
        $parts = $this->computeEffectiveParts($versionId, $version);
        $lep['effective_parts'] = $parts;
        $meta['lep_page'] = $lep;
        $this->persistMeta($versionId, $meta);

        return array(
            'lep_page' => $lep,
            'parts_count' => count($parts),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function updateSignatory(
        int $versionId,
        string $slotKey,
        array $fields,
        ?int $actorUserId = null
    ): ?array {
        $slotKey = trim($slotKey);
        if ($slotKey === '') {
            return null;
        }
        $version = $this->requireVersion($versionId);
        $meta = $this->decodeMeta($version);
        $lep = $this->resolveFromMetadata($meta);
        $updated = null;
        foreach ($lep['signatories'] as $idx => $slot) {
            if ((string)($slot['slot_key'] ?? '') !== $slotKey) {
                continue;
            }
            if ((string)($slot['signer_type'] ?? '') === 'authority') {
                throw new RuntimeException('Authority signatures must be applied through the Approval page.');
            }
            foreach (array('name', 'title', 'date', 'signature_url') as $key) {
                if (array_key_exists($key, $fields)) {
                    $lep['signatories'][$idx][$key] = (string)$fields[$key];
                }
            }
            if (!empty($fields['signature_url'])) {
                $lep['signatories'][$idx]['signed_at'] = date('c');
                $lep['signatories'][$idx]['signed_by_user_id'] = $actorUserId;
                if (trim((string)($lep['signatories'][$idx]['date'] ?? '')) === '') {
                    $lep['signatories'][$idx]['date'] = date('d-m-Y');
                }
            }
            $updated = $lep['signatories'][$idx];
            break;
        }
        if ($updated === null) {
            return null;
        }
        $lep = $this->normalizeLepPage($lep, $this->defaultLepPage());
        $meta['lep_page'] = $lep;
        $this->persistMeta($versionId, $meta);
        return $updated;
    }

    /**
     * @param array<string,mixed> $version
     * @return list<array<string,mixed>>
     */
    public function computeEffectiveParts(int $versionId, array $version): array
    {
        $revision = $this->formatRevisionLabel($version);
        $date = $this->formatPartDate($version);
        $parts = array();

        $mainId = $this->sectionIdByKey($versionId, 'main_content');
        if ($mainId > 0) {
            $children = $this->listChildSections($versionId, $mainId);
            if ($children === array()) {
                $parts[] = array(
                    'part' => '1',
                    'label' => 'Part 1',
                    'pages' => '—',
                    'date' => $date,
                    'revision' => $revision,
                    'section_id' => $mainId,
                );
            } else {
                foreach ($children as $index => $child) {
                    $title = trim((string)($child['title'] ?? ''));
                    $partNum = $this->extractPartNumber($title, $index);
                    $parts[] = array(
                        'part' => (string)$partNum,
                        'label' => $title !== '' ? $title : 'Part ' . $partNum,
                        'pages' => '—',
                        'date' => $date,
                        'revision' => $revision,
                        'section_id' => (int)($child['id'] ?? 0),
                    );
                }
            }
        }

        $annexId = $this->sectionIdByKey($versionId, 'annexes');
        if ($annexId > 0) {
            foreach ($this->listChildSections($versionId, $annexId) as $index => $child) {
                $title = trim((string)($child['title'] ?? ''));
                $parts[] = array(
                    'part' => 'A' . ($index + 1),
                    'label' => $title !== '' ? $title : 'Annex ' . ($index + 1),
                    'pages' => '—',
                    'date' => $date,
                    'revision' => $revision,
                    'section_id' => (int)($child['id'] ?? 0),
                );
            }
        }

        return $parts;
    }

    /**
     * @param array<string,mixed> $version
     */
    public function formatPartDate(array $version): string
    {
        $raw = (string)($version['effective_date'] ?? '');
        if ($raw === '' && !empty($version['released_at'])) {
            $raw = (string)$version['released_at'];
        }
        if ($raw === '') {
            return date('d-m-Y');
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return date('d-m-Y', $ts);
    }

    /**
     * @param array<string,mixed> $version
     */
    public function formatRevisionLabel(array $version): string
    {
        $label = trim((string)($version['version_label'] ?? ''));
        if ($label === '') {
            return '';
        }
        if (preg_match('/^rev\s/i', $label)) {
            return $label;
        }
        return 'Rev ' . $label;
    }

    private function extractPartNumber(string $title, int $index): int|string
    {
        if (preg_match('/\bpart\s*(\d+)\b/i', $title, $m)) {
            return (int)$m[1];
        }
        return $index;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listChildSections(int $versionId, int $parentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, title, section_key, sort_order
            FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND parent_section_id = :parent_id
            ORDER BY sort_order, id
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':parent_id' => $parentId,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function sectionIdByKey(int $versionId, string $sectionKey): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM ipca_publishing_book_sections
            WHERE book_version_id = :version_id AND section_key = :section_key
            LIMIT 1
        ");
        $stmt->execute(array(
            ':version_id' => $versionId,
            ':section_key' => $sectionKey,
        ));
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $existing
     * @param list<array<string,mixed>> $incoming
     * @return list<array<string,mixed>>
     */
    private function mergeSignatories(array $existing, array $incoming): array
    {
        $byKey = array();
        foreach ($existing as $slot) {
            $key = (string)($slot['slot_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $slot;
            }
        }
        foreach ($incoming as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $key = (string)($slot['slot_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $base = $byKey[$key] ?? array('slot_key' => $key, 'signer_type' => 'internal');
            if ((string)($base['signer_type'] ?? '') === 'authority') {
                continue;
            }
            foreach (array('name', 'title', 'date', 'signature_url', 'signed_at', 'signed_by_user_id') as $field) {
                if (array_key_exists($field, $slot)) {
                    $base[$field] = $slot[$field];
                }
            }
            $byKey[$key] = $base;
        }
        return array_values($byKey);
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private function normalizeLepPage(array $raw, array $defaults): array
    {
        $signatories = is_array($raw['signatories'] ?? null) ? $raw['signatories'] : $defaults['signatories'];
        $normalizedSignatories = array();
        foreach ($signatories as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $normalizedSignatories[] = array(
                'slot_key' => $this->truncate(trim((string)($slot['slot_key'] ?? '')), 64),
                'name' => $this->truncate(trim((string)($slot['name'] ?? '')), 200),
                'title' => $this->truncate(trim((string)($slot['title'] ?? '')), 200),
                'date' => $this->truncate(trim((string)($slot['date'] ?? '')), 32),
                'signature_url' => $this->normalizeImageUrl((string)($slot['signature_url'] ?? '')),
                'signed_at' => $slot['signed_at'] ?? null,
                'signed_by_user_id' => isset($slot['signed_by_user_id']) ? (int)$slot['signed_by_user_id'] : null,
                'signer_type' => (string)($slot['signer_type'] ?? 'internal') === 'authority' ? 'authority' : 'internal',
            );
        }
        if ($normalizedSignatories === array()) {
            $normalizedSignatories = $defaults['signatories'];
        }

        $parts = is_array($raw['effective_parts'] ?? null) ? $raw['effective_parts'] : array();
        $normalizedParts = array();
        foreach ($parts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedParts[] = array(
                'part' => $this->truncate(trim((string)($row['part'] ?? '')), 32),
                'label' => $this->truncate(trim((string)($row['label'] ?? '')), 255),
                'pages' => $this->truncate(trim((string)($row['pages'] ?? '—')), 64),
                'date' => $this->truncate(trim((string)($row['date'] ?? '')), 32),
                'revision' => $this->truncate(trim((string)($row['revision'] ?? '')), 64),
                'section_id' => (int)($row['section_id'] ?? 0),
            );
        }

        return array(
            'certification_text' => $this->truncate(trim((string)($raw['certification_text'] ?? $defaults['certification_text'])), 2000),
            'on_behalf_text' => $this->truncate(trim((string)($raw['on_behalf_text'] ?? $defaults['on_behalf_text'])), 500),
            'table_title' => $this->truncate(trim((string)($raw['table_title'] ?? $defaults['table_title'])), 200),
            'empty_rows' => max(0, min(20, (int)($raw['empty_rows'] ?? $defaults['empty_rows']))),
            'signatories' => $normalizedSignatories,
            'effective_parts' => $normalizedParts,
        );
    }

    private function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/')) {
            return $url;
        }
        return '';
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
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
}
