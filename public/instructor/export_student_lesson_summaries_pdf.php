<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lesson_summary_service.php';
require_once __DIR__ . '/../../src/instructor_theory_training_report_ai.php';

$composerAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

cw_require_login();

try {
    $u = cw_current_user($pdo);
    $role = trim((string)($u['role'] ?? ''));
    $allowed = array('admin', 'supervisor', 'instructor', 'chief_instructor');
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($cohortId <= 0 || $studentId <= 0) {
        throw new RuntimeException('Missing cohort_id or student_id');
    }

    InstructorTheoryTrainingReportAi::verifyCohortStudent($pdo, $cohortId, $studentId);

    $nm = $pdo->prepare('
        SELECT name, first_name, last_name, email
        FROM users
        WHERE id = ?
        LIMIT 1
    ');
    $nm->execute([$studentId]);
    $urow = $nm->fetch(PDO::FETCH_ASSOC) ?: [];
    $studentName = trim((string)($urow['name'] ?? ''));
    if ($studentName === '') {
        $studentName = trim((string)($urow['first_name'] ?? '') . ' ' . (string)($urow['last_name'] ?? ''));
    }
    if ($studentName === '') {
        $studentName = trim((string)($urow['email'] ?? '')) ?: 'Student';
    }

    $exportVersion = gmdate('Y.m.d.Hi');
    $exportTimestamp = gmdate('D, M j, Y H:i:s') . ' UTC';

    $service = new LessonSummaryService($pdo);
    $exportData = $service->getNotebookExportData(
        $studentId,
        $cohortId,
        $studentName,
        $exportVersion,
        $exportTimestamp
    );

    $bannerPath = realpath(__DIR__ . '/../assets/pdf/ipca_header.jpg');
    if (!$bannerPath || !is_file($bannerPath)) {
        throw new RuntimeException('PDF banner not found at /public/assets/pdf/ipca_header.jpg');
    }
    $exportData['banner_url'] = 'file://' . $bannerPath;

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
    echo 'PDF export failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    exit;
}
