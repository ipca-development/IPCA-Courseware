<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/mock_oral_bootstrap.php';

final class FaaKnowledgeTestParserService
{
    public function __construct(private readonly PDO $pdo)
    {
        mo_ensure_tables($this->pdo);
    }

    public function storeUpload(int $userId, int $cohortId, int $catalogId, int $adminUserId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $orig = trim((string)($file['name'] ?? 'report.pdf'));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }

        $mime = mime_content_type($tmp) ?: 'application/pdf';
        if (!in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
            throw new RuntimeException('File must be a PDF.');
        }

        $binary = (string)file_get_contents($tmp);
        if ($binary === '') {
            throw new RuntimeException('Empty file.');
        }
        if (strlen($binary) > 12 * 1024 * 1024) {
            throw new RuntimeException('PDF too large (max 12 MB).');
        }

        $hash = hash('sha256', $binary);
        $dir = mo_faa_report_storage_dir();
        $rel = 'report_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $abs = $dir . '/' . $rel;
        if (@file_put_contents($abs, $binary) === false) {
            throw new RuntimeException('Unable to store PDF.');
        }
        @chmod($abs, 0640);

        $ins = $this->pdo->prepare('
            INSERT INTO faa_knowledge_test_reports
              (user_id, cohort_id, catalog_id, uploaded_by_user_id, original_filename, storage_path, file_hash,
               parse_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ');
        $ins->execute([$userId, $cohortId, $catalogId ?: null, $adminUserId, $orig, $rel, $hash]);
        $reportId = (int)$this->pdo->lastInsertId();

        return ['report_id' => $reportId, 'parse_status' => 'pending'];
    }

    public function parseReport(int $reportId): array
    {
        $report = $this->loadReport($reportId);
        if (!$report) {
            throw new RuntimeException('Report not found.');
        }

        $abs = mo_faa_report_storage_dir() . '/' . basename((string)$report['storage_path']);
        if (!is_file($abs)) {
            throw new RuntimeException('Stored PDF missing.');
        }

        $binary = (string)file_get_contents($abs);
        $b64 = base64_encode($binary);

        $systemPrompt = 'You extract structured deficiency data from FAA PSI/CATS airman knowledge test report PDFs. Return JSON only.';
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'test_date' => ['type' => 'string'],
                'test_code' => ['type' => 'string'],
                'overall_score_pct' => ['type' => 'number'],
                'deficiencies' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'deficiency_code' => ['type' => 'string'],
                            'deficiency_label' => ['type' => 'string'],
                            'question_topic' => ['type' => 'string'],
                            'acs_task_code' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'source_page' => ['type' => 'integer'],
                        ],
                        'required' => ['deficiency_code', 'deficiency_label', 'question_topic', 'acs_task_code', 'confidence', 'source_page'],
                    ],
                ],
            ],
            'required' => ['deficiencies'],
        ];

        $payload = [
            'model' => cw_openai_model(),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'Extract all knowledge test deficiencies from this PSI/CATS report PDF.'],
                        ['type' => 'input_file', 'filename' => (string)$report['original_filename'], 'file_data' => 'data:application/pdf;base64,' . $b64],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'faa_knowledge_test_parse',
                    'schema' => $schema,
                ],
            ],
        ];

        try {
            $resp = cw_openai_responses($payload);
            $decoded = cw_openai_extract_json_text($resp);
            if ($decoded === []) {
                throw new RuntimeException('Parser returned invalid JSON.');
            }
        } catch (Throwable $e) {
            $this->pdo->prepare("
                UPDATE faa_knowledge_test_reports
                SET parse_status = 'failed', parse_raw_json = ?, updated_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([mo_json_encode(['error' => $e->getMessage()]), $reportId]);
            throw $e;
        }

        $this->pdo->prepare('DELETE FROM faa_knowledge_test_deficiencies WHERE report_id = ?')->execute([$reportId]);

        $ins = $this->pdo->prepare('
            INSERT INTO faa_knowledge_test_deficiencies
              (report_id, deficiency_code, deficiency_label, question_topic, acs_task_code, confidence, source_page, review_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, \'auto\', UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ');

        foreach ((array)($decoded['deficiencies'] ?? []) as $def) {
            if (!is_array($def)) {
                continue;
            }
            $label = trim((string)($def['deficiency_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $ins->execute([
                $reportId,
                trim((string)($def['deficiency_code'] ?? '')) ?: null,
                $label,
                trim((string)($def['question_topic'] ?? '')) ?: null,
                trim((string)($def['acs_task_code'] ?? '')) ?: null,
                isset($def['confidence']) ? (float)$def['confidence'] : null,
                isset($def['source_page']) ? (int)$def['source_page'] : null,
            ]);
        }

        $testDate = trim((string)($decoded['test_date'] ?? ''));
        $parsedDate = null;
        if ($testDate !== '') {
            $ts = strtotime($testDate);
            $parsedDate = $ts ? gmdate('Y-m-d', $ts) : null;
        }

        $this->pdo->prepare("
            UPDATE faa_knowledge_test_reports
            SET parse_status = 'needs_review',
                test_date = ?,
                test_code = ?,
                overall_score_pct = ?,
                parse_model = ?,
                parse_raw_json = ?,
                parsed_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([
            $parsedDate,
            trim((string)($decoded['test_code'] ?? '')) ?: null,
            isset($decoded['overall_score_pct']) ? (float)$decoded['overall_score_pct'] : null,
            cw_openai_model(),
            mo_json_encode($decoded),
            $reportId,
        ]);

        return [
            'report_id' => $reportId,
            'parse_status' => 'needs_review',
            'deficiency_count' => count((array)($decoded['deficiencies'] ?? [])),
        ];
    }

    public function reviewDeficiency(int $deficiencyId, string $reviewStatus, ?int $areaId, int $reviewerUserId): void
    {
        if (!in_array($reviewStatus, ['confirmed', 'rejected'], true)) {
            throw new RuntimeException('Invalid review status.');
        }
        $this->pdo->prepare('
            UPDATE faa_knowledge_test_deficiencies
            SET review_status = ?, area_id = ?, reviewed_by_user_id = ?, reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ')->execute([$reviewStatus, $areaId ?: null, $reviewerUserId, $deficiencyId]);
    }

    /** @return list<array<string,mixed>> */
    public function listReportsForUser(int $userId, ?int $cohortId = null): array
    {
        $sql = 'SELECT * FROM faa_knowledge_test_reports WHERE user_id = ?';
        $params = [$userId];
        if ($cohortId !== null && $cohortId > 0) {
            $sql .= ' AND cohort_id = ?';
            $params[] = $cohortId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 50';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listDeficienciesForReport(int $reportId): array
    {
        $st = $this->pdo->prepare('
            SELECT d.*, a.title AS area_title, a.area_code
            FROM faa_knowledge_test_deficiencies d
            LEFT JOIN mock_oral_acs_areas a ON a.id = d.area_id
            WHERE d.report_id = ?
            ORDER BY d.id ASC
        ');
        $st->execute([$reportId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadReport(int $reportId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM faa_knowledge_test_reports WHERE id = ? LIMIT 1');
        $st->execute([$reportId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
