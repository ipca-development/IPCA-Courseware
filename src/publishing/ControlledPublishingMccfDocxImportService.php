<?php
declare(strict_types=1);

/**
 * Import BCAA MCCF checklist rows from a Word DOCX export into canonical requirements.
 */
final class ControlledPublishingMccfDocxImportService
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   rows_parsed:int,
     *   matched:int,
     *   updated:int,
     *   unmatched:int,
     *   warnings:list<string>,
     *   unmatched_samples:list<string>
     * }
     */
    public function importFile(string $path, int $sourceSetId, string $manualCode, bool $apply = false): array
    {
        $result = array(
            'rows_parsed' => 0,
            'matched' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'warnings' => array(),
            'unmatched_samples' => array(),
        );

        if ($sourceSetId <= 0) {
            $result['warnings'][] = 'Invalid source set id.';

            return $result;
        }

        $parsedRows = $this->parseBcaaTables($path);
        $result['rows_parsed'] = count($parsedRows);
        if ($parsedRows === array()) {
            $result['warnings'][] = 'No BCAA checklist table rows found in DOCX.';

            return $result;
        }

        $existing = $this->loadRequirementsIndex($sourceSetId);
        $update = $this->pdo->prepare("
            UPDATE ipca_canonical_requirements
            SET subject = :subject,
                requirement_text = :requirement_text,
                manual_section_ref = :manual_section_ref,
                applicable = :applicable,
                remarks = :remarks,
                finding_ref = :finding_ref,
                regulation_ref = COALESCE(NULLIF(:regulation_ref, ''), regulation_ref),
                source_status = 'active',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        foreach ($parsedRows as $parsed) {
            $key = $this->matchKey($manualCode, $parsed);
            $row = $existing[$key] ?? null;
            if (!is_array($row)) {
                $result['unmatched']++;
                if (count($result['unmatched_samples']) < 12) {
                    $result['unmatched_samples'][] = $key . ' — ' . mb_substr((string)($parsed['subject'] ?? ''), 0, 60);
                }
                continue;
            }

            $result['matched']++;
            if (!$apply) {
                continue;
            }

            $update->execute(array(
                ':id' => (int)$row['id'],
                ':subject' => (string)($parsed['subject'] ?? $row['subject'] ?? ''),
                ':requirement_text' => (string)($parsed['requirement_text'] ?? $row['requirement_text'] ?? ''),
                ':manual_section_ref' => (string)($parsed['manual_section_ref'] ?? $row['manual_section_ref'] ?? ''),
                ':applicable' => (string)($parsed['applicable'] ?? $row['applicable'] ?? ''),
                ':remarks' => (string)($parsed['remarks'] ?? $row['remarks'] ?? ''),
                ':finding_ref' => (string)($parsed['finding_ref'] ?? $row['finding_ref'] ?? ''),
                ':regulation_ref' => (string)($parsed['regulation_ref'] ?? ''),
            ));
            $result['updated']++;
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parseBcaaTables(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('DOCX file is not readable: ' . $path);
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open DOCX: ' . basename($path));
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!is_string($documentXml) || $documentXml === '') {
            throw new RuntimeException('Missing word/document.xml in DOCX.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (@$dom->loadXML($documentXml) !== true) {
            throw new RuntimeException('Invalid document.xml in DOCX.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $rows = array();
        $currentPart = '';
        $carryItem = '';
        $carrySubject = '';

        foreach ($xpath->query('//w:tbl') ?: array() as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $tableRows = $xpath->query('.//w:tr', $table);
            if ($tableRows === false || $tableRows->length === 0) {
                continue;
            }

            $headerCells = $this->rowCellTexts($xpath, $tableRows->item(0));
            if (!$this->looksLikeBcaaHeader($headerCells)) {
                continue;
            }

            $colMap = $this->mapHeaderColumns($headerCells);
            for ($i = 1; $i < $tableRows->length; $i++) {
                $cells = $this->rowCellTexts($xpath, $tableRows->item($i));
                if ($cells === array()) {
                    continue;
                }

                $item = trim((string)($cells[$colMap['item']] ?? ''));
                $subject = trim((string)($cells[$colMap['subject']] ?? ''));
                if ($item !== '') {
                    $carryItem = preg_replace('/\D+/u', '', $item) ?: $item;
                }
                if ($subject !== '') {
                    $carrySubject = $subject;
                }

                $subRaw = trim((string)($cells[$colMap['sub']] ?? ''));
                $sub = $subRaw;
                if (preg_match('/(\d+)\.(\d+)/u', $subRaw, $m)) {
                    $carryItem = $m[1];
                    $sub = $m[2];
                } elseif (preg_match('/^\d+$/u', $subRaw)) {
                    $sub = $subRaw;
                }

                if ($carryItem === '' && $carrySubject === '' && trim((string)($cells[$colMap['description']] ?? '')) === '') {
                    continue;
                }

                $partHint = trim((string)($cells[$colMap['part_hint']] ?? ''));
                if ($partHint !== '' && preg_match('/PART\s*\d+/iu', $partHint)) {
                    $currentPart = strtoupper($partHint);
                }

                $rows[] = array(
                    'manual_part' => $currentPart,
                    'item_no' => $carryItem,
                    'sub_item_no' => $sub,
                    'subject' => $carrySubject,
                    'requirement_text' => trim((string)($cells[$colMap['description']] ?? '')),
                    'manual_section_ref' => trim((string)($cells[$colMap['location']] ?? '')),
                    'applicable' => trim((string)($cells[$colMap['applicable']] ?? '')),
                    'remarks' => trim((string)($cells[$colMap['remarks']] ?? '')),
                    'finding_ref' => trim((string)($cells[$colMap['finding']] ?? '')),
                    'regulation_ref' => trim((string)($cells[$colMap['regulation']] ?? '')),
                );
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $headerCells
     * @return array<string,int>
     */
    private function mapHeaderColumns(array $headerCells): array
    {
        $map = array(
            'item' => 0,
            'subject' => 1,
            'sub' => 2,
            'description' => 3,
            'location' => 4,
            'applicable' => 5,
            'remarks' => 6,
            'bcaa_check' => 7,
            'finding' => 8,
            'regulation' => 9,
            'part_hint' => 10,
        );

        foreach ($headerCells as $idx => $label) {
            $label = strtolower(trim($label));
            if ($label === '') {
                continue;
            }
            if (str_contains($label, 'item') && !str_contains($label, 'sub')) {
                $map['item'] = $idx;
            } elseif (str_contains($label, 'subject')) {
                $map['subject'] = $idx;
            } elseif (str_contains($label, 'sub')) {
                $map['sub'] = $idx;
            } elseif (str_contains($label, 'description') || str_contains($label, 'supplementary')) {
                $map['description'] = $idx;
            } elseif (str_contains($label, 'location') || str_contains($label, 'section')) {
                $map['location'] = $idx;
            } elseif (str_contains($label, 'applicable')) {
                $map['applicable'] = $idx;
            } elseif (str_contains($label, 'revision') || str_contains($label, 'abstract')) {
                $map['remarks'] = $idx;
            } elseif (str_contains($label, 'finding')) {
                $map['finding'] = $idx;
            } elseif (str_contains($label, 'regulation')) {
                $map['regulation'] = $idx;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $headerCells
     */
    private function looksLikeBcaaHeader(array $headerCells): bool
    {
        $joined = strtolower(implode(' ', $headerCells));

        return str_contains($joined, 'item')
            && (str_contains($joined, 'description') || str_contains($joined, 'supplementary'))
            && str_contains($joined, 'location');
    }

    /**
     * @return list<string>
     */
    private function rowCellTexts(DOMXPath $xpath, ?DOMNode $row): array
    {
        if (!$row instanceof DOMNode) {
            return array();
        }

        $cells = array();
        foreach ($xpath->query('.//w:tc', $row) ?: array() as $cell) {
            $parts = array();
            foreach ($xpath->query('.//w:t', $cell) ?: array() as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $cells[] = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
        }

        return $cells;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadRequirementsIndex(int $sourceSetId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, requirement_key, manual_code, manual_part, item_no, sub_item_no,
                   subject, requirement_text, manual_section_ref, applicable, remarks, finding_ref, regulation_ref
            FROM ipca_canonical_requirements
            WHERE source_set_id = :source_set_id
              AND source_status = 'active'
        ");
        $stmt->execute(array(':source_set_id' => $sourceSetId));

        $index = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $index[$this->matchKey((string)$row['manual_code'], $row)] = $row;
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function matchKey(string $manualCode, array $row): string
    {
        $manualCode = strtoupper(trim($manualCode));
        $part = strtoupper(trim((string)($row['manual_part'] ?? '')));
        $item = trim((string)($row['item_no'] ?? ''));
        $sub = trim((string)($row['sub_item_no'] ?? ''));

        return $manualCode . '|' . $part . '|' . $item . '|' . $sub;
    }
}
