<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceFindingEngine.php';
require_once __DIR__ . '/ComplianceRcaCapEngine.php';
require_once __DIR__ . '/ComplianceCapEngine.php';

/**
 * Stream a regulatory-style PDF bundle (finding + RCA + corrective actions).
 */
final class CompliancePdfExportService
{
    /**
     * @return array{
     *   finding: array<string,mixed>,
     *   rca: array<string,mixed>|null,
     *   steps: list<array{whyNumber:int,question:string,answer:string}>,
     *   caps: list<array<string,mixed>>,
     *   generated_at: string
     * }
     */
    public static function buildExportPayload(PDO $pdo, int $findingId): array
    {
        $finding = ComplianceFindingEngine::getById($pdo, $findingId);
        if ($finding === null) {
            throw new RuntimeException('Finding not found.');
        }

        $rca = ComplianceRcaCapEngine::getRcaForFinding($pdo, $findingId);
        $steps = array();
        if ($rca !== null && !empty($rca['steps_json'])) {
            $raw = $rca['steps_json'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $steps = ComplianceRcaCapEngine::normaliseSteps($decoded);
                }
            }
        }

        $caps = ComplianceCapEngine::listForFinding($pdo, $findingId);

        return array(
            'finding' => $finding,
            'rca' => $rca,
            'steps' => $steps,
            'caps' => $caps,
            'generated_at' => gmdate('Y-m-d H:i') . ' UTC',
        );
    }

    /**
     * Write PDF to stdout (inline download). Caller must exit.
     *
     * @param array<string,mixed> $exportData From buildExportPayload()
     */
    public static function streamInline(array $exportData): void
    {
        $composerAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            require_once $composerAutoload;
        }

        $templatePath = __DIR__ . '/../../templates/pdf/compliance_finding_rca_cap.php';
        if (!file_exists($templatePath)) {
            throw new RuntimeException('Compliance PDF template not found');
        }

        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        require $templatePath;
        $html = ob_get_clean();

        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException('PDF template rendered empty output');
        }

        $code = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['finding']['finding_code'] ?? 'finding'));
        $filename = 'compliance_rca_cap_' . $code . '_' . gmdate('Ymd_His') . '.pdf';

        if (!class_exists('\Mpdf\Mpdf')) {
            throw new RuntimeException('mPDF not installed');
        }

        $tempDir = __DIR__ . '/../../storage/tmp/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        if (!is_writable($tempDir)) {
            throw new RuntimeException('mPDF temp directory not writable');
        }

        $mpdf = new \Mpdf\Mpdf(array(
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 16,
            'margin_bottom' => 18,
            'tempDir' => $tempDir,
            'default_font' => 'sans',
        ));

        $mpdf->SetTitle('Finding / RCA / CAP — ' . (string)($exportData['finding']['finding_code'] ?? ''));
        $mpdf->SetAuthor('IPCA Academy');
        $mpdf->SetCreator('IPCA Courseware — Compliance OS');

        $foot = (string)($exportData['finding']['finding_code'] ?? '');
        $mpdf->SetFooter('IPCA Compliance|' . $foot . '|Page {PAGENO} of {nbpg}');

        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    }
}
