<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lesson_summary_service.php';

$composerAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

cw_require_login();

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');
    if ($role !== 'student') {
        http_response_code(403);
        exit('Forbidden');
    }

    $userId = (int)($u['id'] ?? 0);
    $studentName = trim((string)($u['name'] ?? 'Student'));
    if ($studentName === '') {
        $studentName = 'Student';
    }

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if ($cohortId <= 0) {
        throw new RuntimeException('Missing cohort_id');
    }

    $chk = $pdo->prepare("
        SELECT 1
        FROM cohort_students
        WHERE cohort_id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $chk->execute([$cohortId, $userId]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        exit('Not enrolled in this cohort');
    }

    $exportVersion = gmdate('Y.m.d.Hi');
    $exportTimestamp = gmdate('D, M j, Y H:i:s') . ' UTC';

    $service = new LessonSummaryService($pdo);
    $exportData = $service->getNotebookExportData(
        $userId,
        $cohortId,
        $studentName,
        $exportVersion,
        $exportTimestamp
    );

    /*
     * More reliable logo resolution for mPDF:
     * - use realpath()
     * - pass file:// URL
     * Adjust the candidate list if your real logo filename differs.
     */
    $logoCandidates = [
        __DIR__ . '/../assets/logo/ipca_logo_white.png',
        __DIR__ . '/../assets/logo/ipca_logo.png',
        __DIR__ . '/../assets/ipca_logo_white.png',
        __DIR__ . '/../assets/ipca_logo.png',
    ];

    $logoRealPath = '';
    foreach ($logoCandidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_file($real)) {
            $logoRealPath = $real;
            break;
        }
    }

    $exportData['logo_file_url'] = ($logoRealPath !== '') ? ('file://' . $logoRealPath) : '';

    $templatePath = __DIR__ . '/../../templates/pdf/export_lesson_summaries_pdf.php';
    if (!file_exists($templatePath)) {
        throw new RuntimeException('PDF export template not found');
    }

    ob_start();
    require $templatePath;
    $html = ob_get_clean();

    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('PDF export template rendered empty output');
    }

    $safeProgram = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['program_title'] ?? 'Program'));
    $safeScope = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['scope_label'] ?? ('Cohort_' . $cohortId)));
    $filename = 'lesson_summaries_' . $safeProgram . '_' . $safeScope . '_' . $exportVersion . '.pdf';

    if (class_exists('\Mpdf\Mpdf')) {
        $tempDir = __DIR__ . '/../../storage/tmp/mpdf';

        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
                throw new RuntimeException('Could not create mPDF temp directory: ' . $tempDir);
            }
        }

        if (!is_writable($tempDir)) {
            throw new RuntimeException('mPDF temp directory is not writable: ' . $tempDir);
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 16,
            'margin_bottom' => 18,
            'margin_header' => 6,
            'margin_footer' => 8,
            'tempDir' => $tempDir,
            'default_font' => 'sans',
            'setAutoBottomMargin' => 'stretch',
        ]);

        $mpdf->SetTitle('Lesson Summaries');
        $mpdf->SetAuthor('IPCA Academy');
        $mpdf->SetCreator('IPCA Courseware');
        $mpdf->SetDisplayMode('fullpage');

        $footerCenter = trim((string)($exportData['scope_label'] ?? ''));
        if ($footerCenter === '') {
            $footerCenter = trim((string)($exportData['program_title'] ?? ''));
        }

        /*
         * Plain-text footer is more reliable in mPDF than styled HTML footer.
         * Format: left|center|right
         */
        $mpdf->SetFooter('IPCA Academy|' . $footerCenter . '|Page {PAGENO} of {nbpg}');

        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF renderer not available.\n\n";
    echo "The export pipeline is in place, but mPDF is not currently available to this runtime.\n";
    echo "Expected autoload path: ../../vendor/autoload.php\n";
    echo "Expected package: mpdf/mpdf\n";
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF export failed: ' . $e->getMessage();
    exit;
}