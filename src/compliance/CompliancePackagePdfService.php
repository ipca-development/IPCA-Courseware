<?php
declare(strict_types=1);

require_once __DIR__ . '/ComplianceManualControlEngine.php';

/**
 * Build, store, and stream PDFs for ipca_compliance_manual_release_packages.
 *
 * Stored files live under storage/compliance/manual_releases/ and are tracked
 * on the package row via pdf_storage_relpath + pdf_sha256. mPDF is reused (same
 * configuration as CompliancePdfExportService) so output style is consistent.
 */
final class CompliancePackagePdfService
{
    private const STORAGE_SUBDIR = 'storage/compliance/manual_releases';

    public static function projectRoot(): string
    {
        return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    /**
     * @return array{
     *   package: array<string,mixed>,
     *   approvals: list<array<string,mixed>>,
     *   drafts: list<array<string,mixed>>,
     *   generated_at: string
     * }
     */
    public static function buildPayload(PDO $pdo, int $packageId): array
    {
        $pkg = ComplianceManualControlEngine::getPackage($pdo, $packageId);
        if ($pkg === null) {
            throw new RuntimeException('Package not found.');
        }
        $approvals = ComplianceManualControlEngine::listPackageApprovals($pdo, $packageId);

        $draftIds = self::extractDraftIds($pkg['drafts_json'] ?? null);
        $drafts = $draftIds !== array() ? self::loadDrafts($pdo, $draftIds) : array();

        return array(
            'package' => $pkg,
            'approvals' => $approvals,
            'drafts' => $drafts,
            'generated_at' => gmdate('Y-m-d H:i') . ' UTC',
        );
    }

    /**
     * Generate the PDF, persist it on disk, and update the package row with
     * pdf_storage_relpath + pdf_sha256. Idempotent — overwrites any previous file.
     *
     * @return array{relpath:string,abspath:string,sha256:string,bytes:int}
     */
    public static function generateAndStore(PDO $pdo, int $packageId): array
    {
        $payload = self::buildPayload($pdo, $packageId);

        $bytes = self::renderPdfBytes($payload);
        if ($bytes === '') {
            throw new RuntimeException('PDF rendered empty');
        }
        $sha = hash('sha256', $bytes);

        $root = self::projectRoot();
        $dirAbs = $root . '/' . self::STORAGE_SUBDIR;
        if (!is_dir($dirAbs)) {
            mkdir($dirAbs, 0775, true);
        }
        if (!is_dir($dirAbs) || !is_writable($dirAbs)) {
            throw new RuntimeException('Release-package storage directory is not writable: ' . $dirAbs);
        }

        $pkg = $payload['package'];
        $code = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($pkg['package_code'] ?? 'package'));
        $rev = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', (string)($pkg['target_revision'] ?? 'rev'));
        $stamp = gmdate('Ymd_His');
        $filename = $code . '_' . $rev . '_' . $stamp . '.pdf';
        $relpath = self::STORAGE_SUBDIR . '/' . $filename;
        $abspath = $dirAbs . '/' . $filename;

        $written = file_put_contents($abspath, $bytes);
        if ($written === false) {
            throw new RuntimeException('Failed to write release-package PDF: ' . $abspath);
        }

        $pdo->prepare(
            'UPDATE ipca_compliance_manual_release_packages
                SET pdf_storage_relpath = ?, pdf_sha256 = ?
              WHERE id = ?'
        )->execute(array($relpath, $sha, $packageId));

        return array(
            'relpath' => $relpath,
            'abspath' => $abspath,
            'sha256' => $sha,
            'bytes' => strlen($bytes),
        );
    }

    /**
     * Read the previously generated PDF (regenerates it if missing on disk).
     *
     * @return array{relpath:string,abspath:string,sha256:string,bytes:string,filename:string}
     */
    public static function fetchOrRegenerate(PDO $pdo, int $packageId): array
    {
        $pkg = ComplianceManualControlEngine::getPackage($pdo, $packageId);
        if ($pkg === null) {
            throw new RuntimeException('Package not found.');
        }
        $rel = (string)($pkg['pdf_storage_relpath'] ?? '');
        $abs = $rel !== '' ? self::projectRoot() . '/' . $rel : '';
        if ($rel === '' || !is_file($abs)) {
            $stored = self::generateAndStore($pdo, $packageId);
            $rel = $stored['relpath'];
            $abs = $stored['abspath'];
        }
        $bytes = is_file($abs) ? (string)file_get_contents($abs) : '';
        $filename = basename($abs);

        return array(
            'relpath' => $rel,
            'abspath' => $abs,
            'sha256' => (string)($pkg['pdf_sha256'] ?? hash('sha256', $bytes)),
            'bytes' => $bytes,
            'filename' => $filename !== '' ? $filename : ('package_' . $packageId . '.pdf'),
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function renderPdfBytes(array $payload): string
    {
        $composerAutoload = self::projectRoot() . '/vendor/autoload.php';
        if (is_file($composerAutoload)) {
            require_once $composerAutoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) {
            throw new RuntimeException('mPDF not installed');
        }

        $templatePath = self::projectRoot() . '/templates/pdf/compliance_manual_release_package.php';
        if (!is_file($templatePath)) {
            throw new RuntimeException('Release-package PDF template not found');
        }

        $exportData = $payload;
        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        require $templatePath;
        $html = ob_get_clean();
        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException('Release-package PDF template rendered empty output');
        }

        $tempDir = self::projectRoot() . '/storage/tmp/mpdf';
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

        $pkg = $payload['package'];
        $mpdf->SetTitle('Manual release — ' . (string)($pkg['package_code'] ?? ''));
        $mpdf->SetAuthor('IPCA Academy');
        $mpdf->SetCreator('IPCA Courseware — Compliance');
        $mpdf->SetFooter('IPCA Compliance|' . (string)($pkg['package_code'] ?? '') . '|Page {PAGENO} of {nbpg}');

        $mpdf->WriteHTML($html);

        $out = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        return is_string($out) ? $out : '';
    }

    /**
     * @return list<int>
     */
    private static function extractDraftIds(mixed $rawJson): array
    {
        if ($rawJson === null || $rawJson === '') {
            return array();
        }
        $decoded = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
        if (!is_array($decoded)) {
            return array();
        }
        $ids = array();
        foreach ($decoded as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[] = $entry;
                continue;
            }
            if (is_array($entry)) {
                if (isset($entry['id']) && (int)$entry['id'] > 0) {
                    $ids[] = (int)$entry['id'];
                    continue;
                }
                if (isset($entry['draft_id']) && (int)$entry['draft_id'] > 0) {
                    $ids[] = (int)$entry['draft_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private static function loadDrafts(PDO $pdo, array $ids): array
    {
        if ($ids === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT * FROM ipca_compliance_manual_drafts WHERE id IN (' . $placeholders . ') ORDER BY draft_code ASC';
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : array();
    }
}
