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

    // Export metadata generated ONCE per request
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

    $templatePath = __DIR__ . '/../../templates/pdf/lesson_summaries_document.php';
    if (!file_exists($templatePath)) {
        throw new RuntimeException('Export template not found');
    }

    ob_start();
    require $templatePath;
    $html = ob_get_clean();

    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('Export template rendered empty output');
    }

    $safeProgram = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['program_title'] ?? 'Program'));
    $safeScope = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['scope_label'] ?? ('Cohort_' . $cohortId)));
    $filename = 'lesson_summaries_' . $safeProgram . '_' . $safeScope . '_' . $exportVersion . '.pdf';

    // mPDF renderer (preferred)
    if (class_exists('\Mpdf\Mpdf')) {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 18,
            'margin_bottom' => 18,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        $mpdf->SetTitle('Lesson Summaries');
        $mpdf->SetAuthor('IPCA Academy');
        $mpdf->SetCreator('IPCA Courseware');
        $mpdf->SetDisplayMode('fullpage');

        $footerLeft = 'IPCA Academy';
        $footerCenter = (string)($exportData['scope_label'] ?? '');
        $footerRight = 'Page {PAGENO}';
        $mpdf->SetHTMLFooter('
            <div style="font-size:9pt; color:#64748b; border-top:1px solid #e2e8f0; padding-top:6px;">
                <table width="100%" style="border-collapse:collapse;">
                    <tr>
                        <td width="33%" align="left">' . htmlspecialchars($footerLeft, ENT_QUOTES, 'UTF-8') . '</td>
                        <td width="34%" align="center">' . htmlspecialchars($footerCenter, ENT_QUOTES, 'UTF-8') . '</td>
                        <td width="33%" align="right">' . htmlspecialchars($footerRight, ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                </table>
            </div>
        ');

        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }

    // Pluggable seam: renderer not available yet
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF renderer not available.\n\n";
    echo "The export pipeline is in place, but mPDF is not currently available to this runtime.\n";
    echo "Expected Composer autoload path: ../../vendor/autoload.php\n";
    echo "Expected renderer: mpdf/mpdf\n";
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF export failed: ' . $e->getMessage();
    exit;
}