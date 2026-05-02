<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lesson_summary_service.php';
require_once __DIR__ . '/../../src/courseware_progression_v2.php';
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
    $notebookMeta = $service->getNotebookExportData(
        $studentId,
        $cohortId,
        $studentName,
        $exportVersion,
        $exportTimestamp
    );

    $cohortTz = InstructorTheoryTrainingReportAi::cohortTimezone($pdo, $cohortId);

    $context = InstructorTheoryTrainingReportAi::collectContext($pdo, $cohortId, $studentId);
    $cohortTitle = (string)($context['cohort_name'] ?? ('Cohort ' . $cohortId));

    $ai = InstructorTheoryTrainingReportAi::callOpenAiForReportJson($context, $studentName, $cohortTitle);

    $engine = new CoursewareProgressionV2($pdo);
    $chief = $engine->getChiefInstructorRecipient(['cohort_id' => $cohortId]);
    $chiefName = is_array($chief) ? trim((string)($chief['name'] ?? '')) : '';

    $signoffHtml = InstructorTheoryTrainingReportAi::renderSignoffTableHtml($ai, $cohortTz, $chiefName);

    $disclaimer = '<div class="lesson-meta" style="padding:12px;background:#fff7ed;border:1px solid #fdba74;border-radius:10px;">'
        . '<strong>Advisory document.</strong> This PDF was generated with AI assistance from progression and summary records. '
        . 'It is not an FAA-approved curriculum document. Regulatory explanations must be verified on '
        . '<strong>ecfr.gov</strong> and relevant <strong>FAA.gov</strong> Advisory Circulars. '
        . 'PHAK-aligned sections are study aids and may paraphrase official materials; use the current FAA PHAK (FAA-H-8083-25) as authority. '
        . 'ACS references are included where applicable for self-assessment context (e.g. ACS PA.II.A.K1). '
        . '61.105 sign-off timing and hours are model estimates from available timestamps — reconcile with the student logbook.'
        . '</div>';

    $bannerPath = realpath(__DIR__ . '/../assets/pdf/ipca_header.jpg');
    if (!$bannerPath || !is_file($bannerPath)) {
        throw new RuntimeException('PDF banner not found at /public/assets/pdf/ipca_header.jpg');
    }

    $exportData = [
        'student_name' => $studentName,
        'program_title' => (string)($notebookMeta['program_title'] ?? 'Training Program'),
        'scope_label' => (string)($notebookMeta['scope_label'] ?? ''),
        'export_version' => $exportVersion,
        'export_timestamp' => $exportTimestamp,
        'banner_url' => 'file://' . $bannerPath,
        'focus_items_html' => (string)($ai['focus_items_html'] ?? ''),
        'phak_sections' => $ai['phak_sections'] ?? [],
        'acs_section_html' => (string)($ai['acs_section_html'] ?? ''),
        'regulatory_notes_html' => (string)($ai['regulatory_notes_html'] ?? ''),
        'signoff_html' => $signoffHtml,
        'disclaimer_html' => $disclaimer,
    ];

    $templatePath = __DIR__ . '/../../templates/pdf/export_theory_ai_training_report_pdf.php';
    if (!file_exists($templatePath)) {
        throw new RuntimeException('AI report PDF template not found');
    }

    ob_start();
    require $templatePath;
    $html = ob_get_clean();

    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('AI report template rendered empty output');
    }

    $safeProgram = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $exportData['program_title']);
    $safeScope = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $exportData['scope_label'] !== '' ? $exportData['scope_label'] : ('Cohort_' . $cohortId));
    $filename = 'theory_ai_training_report_' . $safeProgram . '_' . $safeScope . '_' . $exportVersion . '.pdf';

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

    $mpdf->SetTitle('Theory AI Training Report');
    $mpdf->SetAuthor('IPCA Academy');
    $mpdf->SetCreator('IPCA Courseware');

    $footerCenter = trim($exportData['scope_label']) !== '' ? trim($exportData['scope_label']) : trim($exportData['program_title']);
    $mpdf->SetFooter('IPCA Academy|' . $footerCenter . '|Page {PAGENO} of {nbpg}');

    $mpdf->WriteHTML($html);
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo 'PDF export failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    exit;
}
