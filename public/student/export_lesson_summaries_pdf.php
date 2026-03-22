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
    if (($u['role'] ?? '') !== 'student') {
        http_response_code(403);
        exit('Forbidden');
    }

    $userId = (int)$u['id'];
    $studentName = trim((string)$u['name'] ?: 'Student');

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if ($cohortId <= 0) {
        throw new RuntimeException('Missing cohort_id');
    }

    // Enrollment check
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

    // Export metadata
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
     * ✅ NEW: Use JPG banner instead of logo rendering
     */
    $bannerPath = realpath(__DIR__ . '/../assets/pdf/ipca_header.jpg');

    if (!$bannerPath || !is_file($bannerPath)) {
        throw new RuntimeException('PDF banner not found at /public/assets/pdf/ipca_header.jpg');
    }

    $exportData['banner_url'] = 'file://' . $bannerPath;

    // Template
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

    // Filename
    $safeProgram = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['program_title'] ?? 'Program'));
    $safeScope = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['scope_label'] ?? ('Cohort_' . $cohortId)));
    $filename = 'lesson_summaries_' . $safeProgram . '_' . $safeScope . '_' . $exportVersion . '.pdf';

    if (!class_exists('\Mpdf\Mpdf')) {
        throw new RuntimeException('mPDF not installed');
    }

    // Temp dir
    $tempDir = __DIR__ . '/../../storage/tmp/mpdf';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0775, true);
    }
    if (!is_writable($tempDir)) {
        throw new RuntimeException('mPDF temp directory not writable');
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 14,
        'margin_right' => 14,
        'margin_top' => 16,
        'margin_bottom' => 18,
        'tempDir' => $tempDir,
        'default_font' => 'sans',
    ]);

    $mpdf->SetTitle('Lesson Summaries');
    $mpdf->SetAuthor('IPCA Academy');
    $mpdf->SetCreator('IPCA Courseware');

    /*
     * ✅ FIXED FOOTER (always works)
     */
    $footerCenter = trim((string)($exportData['scope_label'] ?? ''));
    if ($footerCenter === '') {
        $footerCenter = trim((string)($exportData['program_title'] ?? ''));
    }

    $mpdf->SetFooter('IPCA Academy|' . $footerCenter . '|Page {PAGENO} of {nbpg}');

    $mpdf->WriteHTML($html);
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo 'PDF export failed: ' . $e->getMessage();
    exit;
}