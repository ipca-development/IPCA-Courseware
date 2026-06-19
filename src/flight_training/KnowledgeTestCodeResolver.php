<?php
declare(strict_types=1);

final class KnowledgeTestCodeResolver
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string,string>>
     */
    public function resolvePastedReport(string $raw): array
    {
        $codes = $this->extractCodes($raw);
        if ($codes === array()) {
            return array();
        }

        $catalog = $this->loadCatalog($codes);
        $historical = $this->loadHistoricalDeficiencies($codes);
        $acsAreas = $this->loadAcsAreas();
        $resolved = array();

        foreach ($codes as $code) {
            $upper = strtoupper($code);
            $row = $catalog[$upper] ?? $historical[$upper] ?? array();
            $section = trim((string)($row['relevant_section'] ?? ''));
            $acsAreaCode = trim((string)($row['acs_area_code'] ?? ''));
            if ($section === '' && $acsAreaCode !== '' && isset($acsAreas[$acsAreaCode])) {
                $section = 'ACS Area ' . $acsAreaCode . ' - ' . $acsAreas[$acsAreaCode];
            }
            if ($section === '') {
                $section = $this->fallbackSection($upper);
            }

            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $title = $this->fallbackTitle($upper);
            }

            $resolved[] = array(
                'code' => $upper,
                'title' => $title,
                'relevant_section' => $section,
                'acs_area_code' => $acsAreaCode,
                'acs_task_code' => trim((string)($row['acs_task_code'] ?? '')),
                'source' => (string)($row['source'] ?? 'fallback'),
            );
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function extractCodes(string $raw): array
    {
        $raw = strtoupper($raw);
        preg_match_all('/\b(?:PLT|PAR|PVT|PA|ACS)[A-Z0-9.-]*\d[A-Z0-9.-]*\b/', $raw, $matches);
        $codes = array();
        foreach (($matches[0] ?? array()) as $match) {
            $code = trim((string)$match, " \t\n\r\0\x0B,;:");
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * @param list<string> $codes
     * @return array<string,array<string,string>>
     */
    private function loadCatalog(array $codes): array
    {
        if (!$this->tableExists('ipca_faa_knowledge_test_code_catalog')) {
            return $this->staticCatalog($codes);
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->pdo->prepare("
            SELECT code, title, relevant_section, acs_area_code, acs_task_code
            FROM ipca_faa_knowledge_test_code_catalog
            WHERE code IN ($in)
              AND status = 'active'
        ");
        $stmt->execute($codes);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $out = $this->staticCatalog($codes);
        foreach ($rows as $row) {
            $code = strtoupper(trim((string)($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $out[$code] = array(
                'title' => (string)($row['title'] ?? ''),
                'relevant_section' => (string)($row['relevant_section'] ?? ''),
                'acs_area_code' => (string)($row['acs_area_code'] ?? ''),
                'acs_task_code' => (string)($row['acs_task_code'] ?? ''),
                'source' => 'catalog',
            );
        }
        return $out;
    }

    /**
     * @param list<string> $codes
     * @return array<string,array<string,string>>
     */
    private function loadHistoricalDeficiencies(array $codes): array
    {
        if (!$this->tableExists('faa_knowledge_test_deficiencies')) {
            return array();
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->pdo->prepare("
            SELECT
                d.deficiency_code,
                d.deficiency_label,
                d.question_topic,
                d.acs_task_code,
                a.area_code,
                a.title AS area_title
            FROM faa_knowledge_test_deficiencies d
            LEFT JOIN mock_oral_acs_areas a ON a.id = d.area_id
            WHERE UPPER(d.deficiency_code) IN ($in)
            ORDER BY d.review_status = 'confirmed' DESC, d.id DESC
        ");
        $stmt->execute($codes);
        $out = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $code = strtoupper(trim((string)($row['deficiency_code'] ?? '')));
            if ($code === '' || isset($out[$code])) {
                continue;
            }
            $areaCode = trim((string)($row['area_code'] ?? ''));
            $areaTitle = trim((string)($row['area_title'] ?? ''));
            $out[$code] = array(
                'title' => trim((string)($row['deficiency_label'] ?? $row['question_topic'] ?? '')),
                'relevant_section' => $areaCode !== '' || $areaTitle !== '' ? trim('ACS Area ' . $areaCode . ' - ' . $areaTitle, ' -') : '',
                'acs_area_code' => $areaCode,
                'acs_task_code' => (string)($row['acs_task_code'] ?? ''),
                'source' => 'historical_report',
            );
        }
        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function loadAcsAreas(): array
    {
        if (!$this->tableExists('mock_oral_acs_areas')) {
            return array();
        }
        $stmt = $this->pdo->query("SELECT area_code, title FROM mock_oral_acs_areas WHERE is_active = 1 ORDER BY sort_order ASC");
        $areas = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row) {
            $code = trim((string)($row['area_code'] ?? ''));
            if ($code !== '') {
                $areas[$code] = (string)($row['title'] ?? '');
            }
        }
        return $areas;
    }

    /**
     * @param list<string> $codes
     * @return array<string,array<string,string>>
     */
    private function staticCatalog(array $codes): array
    {
        $known = array(
            'PLT012' => array('title' => 'Certificates and documents', 'relevant_section' => 'ACS Area I - Pilot Qualifications', 'acs_area_code' => 'I'),
            'PLT064' => array('title' => 'Weather information and services', 'relevant_section' => 'ACS Area III - Weather Information', 'acs_area_code' => 'III'),
            'PLT083' => array('title' => 'Airspace and ATC procedures', 'relevant_section' => 'ACS Area V - National Airspace System', 'acs_area_code' => 'V'),
            'PLT124' => array('title' => 'Aircraft performance and limitations', 'relevant_section' => 'ACS Area VI - Performance and Limitations', 'acs_area_code' => 'VI'),
            'PLT141' => array('title' => 'Weight and balance', 'relevant_section' => 'ACS Area VI - Performance and Limitations', 'acs_area_code' => 'VI'),
            'PLT161' => array('title' => 'Navigation systems and cross-country planning', 'relevant_section' => 'ACS Area IV - Cross-Country Flight Planning', 'acs_area_code' => 'IV'),
            'PLT172' => array('title' => 'Aircraft systems', 'relevant_section' => 'ACS Area VII - Operation of Systems', 'acs_area_code' => 'VII'),
            'PLT310' => array('title' => 'Aeromedical factors and human factors', 'relevant_section' => 'ACS Area VIII - Human Factors', 'acs_area_code' => 'VIII'),
        );
        $out = array();
        foreach ($codes as $code) {
            $upper = strtoupper($code);
            if (isset($known[$upper])) {
                $out[$upper] = $known[$upper] + array('acs_task_code' => '', 'source' => 'static_catalog');
            }
        }
        return $out;
    }

    private function fallbackTitle(string $code): string
    {
        if (str_starts_with($code, 'PLT')) {
            return 'FAA knowledge test learning statement ' . $code;
        }
        return 'Knowledge test deficiency ' . $code;
    }

    private function fallbackSection(string $code): string
    {
        if (preg_match('/^PA[.\-]?(I|II|III|IV|V|VI|VII|VIII)\b/', $code, $m)) {
            return 'ACS Area ' . $m[1];
        }
        return 'Written test report deficiency section';
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
            ");
            $stmt->execute(array(':table' => $table));
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
