<?php
declare(strict_types=1);

/**
 * Async preparation of theory AI training report payload (OpenAI + eCFR merge, etc.)
 * for PDF export. PDF rendering reads cached exportData from MySQL (stored=1).
 */

require_once __DIR__ . '/lesson_summary_service.php';
require_once __DIR__ . '/courseware_progression_v2.php';
require_once __DIR__ . '/instructor_theory_training_report_ai.php';
require_once __DIR__ . '/ecfr_api_client.php';

function tatr_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS theory_ai_training_report_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cohort_id INT UNSIGNED NOT NULL,
                student_id INT UNSIGNED NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                status ENUM('pending','running','complete','failed') NOT NULL DEFAULT 'pending',
                progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
                result_json LONGTEXT NULL,
                error_text TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_tatr_cohort_student (cohort_id, student_id),
                KEY idx_tatr_status_updated (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('tatr_ensure_table: ' . $e->getMessage());
    }
}

function tatr_compute_fingerprint(PDO $pdo, int $cohortId, int $studentId): string
{
    $pt = $pdo->prepare("
        SELECT
            COUNT(*) AS pt_cnt,
            COALESCE(MAX(id), 0) AS pt_max_id,
            COALESCE(MAX(completed_at), '') AS pt_max_done
        FROM progress_tests_v2
        WHERE user_id = ? AND cohort_id = ?
    ");
    $pt->execute([$studentId, $cohortId]);
    $a = $pt->fetch(PDO::FETCH_ASSOC) ?: ['pt_cnt' => 0, 'pt_max_id' => 0, 'pt_max_done' => ''];

    $ls = $pdo->prepare("
        SELECT
            COUNT(*) AS ls_cnt,
            COALESCE(MAX(updated_at), '') AS ls_max_upd
        FROM lesson_summaries
        WHERE user_id = ? AND cohort_id = ?
    ");
    $ls->execute([$studentId, $cohortId]);
    $b = $ls->fetch(PDO::FETCH_ASSOC) ?: ['ls_cnt' => 0, 'ls_max_upd' => ''];

    $raw = implode('|', [
        (string)$cohortId,
        (string)$studentId,
        (string)($a['pt_cnt'] ?? 0),
        (string)($a['pt_max_id'] ?? 0),
        trim((string)($a['pt_max_done'] ?? '')),
        (string)($b['ls_cnt'] ?? 0),
        trim((string)($b['ls_max_upd'] ?? '')),
    ]);

    return hash('sha256', $raw);
}

/**
 * @param callable|null $onProgress fn(int $pct): void
 * @return array<string, mixed> exportData for templates/pdf/export_theory_ai_training_report_pdf.php
 */
function tatr_build_export_payload(PDO $pdo, int $cohortId, int $studentId, ?callable $onProgress = null): array
{
    $tick = static function (int $pct) use ($onProgress): void {
        if ($onProgress !== null) {
            $onProgress($pct);
        }
    };

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

    $tick(12);
    $service = new LessonSummaryService($pdo);
    $notebookMeta = $service->getNotebookExportData(
        $studentId,
        $cohortId,
        $studentName,
        $exportVersion,
        $exportTimestamp
    );

    $cohortTz = InstructorTheoryTrainingReportAi::cohortTimezone($pdo, $cohortId);

    $tick(22);
    $context = InstructorTheoryTrainingReportAi::collectContext($pdo, $cohortId, $studentId);
    $cohortTitle = (string)($context['cohort_name'] ?? ('Cohort ' . $cohortId));

    $tick(25);
    $phakLibraryPack = InstructorTheoryTrainingReportAi::collectPhakLibraryPack($pdo, $context);
    $phakHandbookLabel = $phakLibraryPack !== ''
        ? InstructorTheoryTrainingReportAi::liveResourceLibraryHandbookLabel($pdo)
        : '';

    $tick(28);
    $ai = InstructorTheoryTrainingReportAi::callOpenAiForReportJson(
        $context,
        $studentName,
        $cohortTitle,
        $phakLibraryPack,
        $phakHandbookLabel
    );

    $tick(48);
    $ecfrOfficialBlock = '';
    try {
        $ecfr = new EcfrApiClient();
        $snapshot = $ecfr->resolveTitleSnapshotDate(14);
        $xml61 = $ecfr->fetchSectionXml(14, '61.105', $snapshot);
        $html61 = $ecfr->sectionXmlToHtml($xml61);
        $browse = $ecfr->sectionBrowseUrl(14, '61.105');
        $apiPath = '/api/versioner/v1/full/' . rawurlencode($snapshot) . '/title-14.xml?section=61.105';
        $ecfrOfficialBlock = '<h3 class="course-title">Official text — 14 CFR § 61.105 (eCFR API)</h3>'
            . '<p class="lesson-meta">The following excerpt is retrieved from the U.S. Government <strong>eCFR</strong> versioner API (<a href="https://www.ecfr.gov/developers/documentation/api/v1">API documentation</a>). '
            . 'Snapshot date: <strong>' . htmlspecialchars($snapshot, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</strong>. '
            . 'Endpoint: <code>' . htmlspecialchars($apiPath, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code>. '
            . 'Browse current text: <a href="' . htmlspecialchars($browse, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . htmlspecialchars($browse, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a>.</p>'
            . '<div class="ecfr-official">' . $html61 . '</div>';
    } catch (Throwable $e) {
        $ecfrOfficialBlock = '<h3 class="course-title">Official text — 14 CFR § 61.105 (eCFR)</h3>'
            . '<p class="lesson-meta">Could not load live text from the eCFR API (' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '). '
            . 'Open the current section at <a href="https://www.ecfr.gov/current/title-14/section-61.105">https://www.ecfr.gov/current/title-14/section-61.105</a>.</p>';
    }

    $mergedRegulatory = $ecfrOfficialBlock . (string)($ai['regulatory_notes_html'] ?? '');

    $tick(62);
    $engine = new CoursewareProgressionV2($pdo);
    $chief = $engine->getChiefInstructorRecipient(['cohort_id' => $cohortId]);
    $chiefName = is_array($chief) ? trim((string)($chief['name'] ?? '')) : '';

    $signoffHtml = InstructorTheoryTrainingReportAi::renderSignoffTableHtml($ai, $cohortTz, $chiefName);

    $phakDisclaimerLine = $phakLibraryPack !== ''
        ? 'PHAK narrative paragraphs are grounded in indexed handbook text from your <strong>Resource Library</strong> when a <strong>Live</strong> edition is configured; cross-check critical items against the official FAA PHAK PDF before checkrides. '
        : 'PHAK-aligned sections are study aids and may paraphrase official materials; use the current FAA PHAK (FAA-H-8083-25) as authority. ';

    $disclaimer = '<div class="lesson-meta" style="padding:12px;background:#fff7ed;border:1px solid #fdba74;border-radius:10px;">'
        . '<strong>Advisory document.</strong> This PDF was generated with AI assistance from progression and summary records. '
        . 'It is not an FAA-approved curriculum document. Official <strong>14 CFR § 61.105</strong> text is loaded live from the <strong>eCFR versioner API</strong> (see <a href="https://www.ecfr.gov/developers/documentation/api/v1">eCFR API v1 documentation</a>). '
        . 'Other regulatory explanations must still be verified on <strong>ecfr.gov</strong> and relevant <strong>FAA.gov</strong> Advisory Circulars. '
        . $phakDisclaimerLine
        . 'ACS references are included where applicable for self-assessment context (e.g. ACS PA.II.A.K1). '
        . '61.105 sign-off timing and hours are model estimates from available timestamps — reconcile with the student logbook.'
        . '</div>';

    $defaultPhakIntro = 'Section titles follow the Pilot&rsquo;s Handbook of Aeronautical Knowledge (PHAK) organization. Body text is an educational synthesis for study — verify technical and regulatory details against current FAA publications.';
    if ($phakLibraryPack !== '') {
        if ($phakHandbookLabel !== '') {
            $phakSectionsIntro = '<p class="lesson-meta">PHAK explanations are synthesized for this student from indexed text in your Resource Library (<strong>'
                . htmlspecialchars($phakHandbookLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</strong>) together with progression evidence. Verify critical items against the official FAA PHAK before checkrides.</p>';
        } else {
            $phakSectionsIntro = '<p class="lesson-meta">PHAK explanations are synthesized from indexed Resource Library handbook text and progression evidence. Verify critical items against the official FAA PHAK before checkrides.</p>';
        }
    } else {
        $phakSectionsIntro = '<p class="lesson-meta">' . $defaultPhakIntro . '</p>';
    }

    $bannerPath = realpath(__DIR__ . '/../public/assets/pdf/ipca_header.jpg');
    if (!$bannerPath || !is_file($bannerPath)) {
        throw new RuntimeException('PDF banner not found at /public/assets/pdf/ipca_header.jpg');
    }

    $tick(88);

    return [
        'student_name' => $studentName,
        'program_title' => (string)($notebookMeta['program_title'] ?? 'Training Program'),
        'scope_label' => (string)($notebookMeta['scope_label'] ?? ''),
        'export_version' => $exportVersion,
        'export_timestamp' => $exportTimestamp,
        'banner_url' => 'file://' . $bannerPath,
        'focus_items_html' => (string)($ai['focus_items_html'] ?? ''),
        'phak_sections_intro_html' => $phakSectionsIntro,
        'phak_sections' => $ai['phak_sections'] ?? [],
        'acs_section_html' => (string)($ai['acs_section_html'] ?? ''),
        'regulatory_notes_html' => $mergedRegulatory,
        'signoff_html' => $signoffHtml,
        'disclaimer_html' => $disclaimer,
    ];
}

function tatr_update_job_progress(PDO $pdo, int $jobId, int $progress, ?string $status = null): void
{
    $progress = max(0, min(100, $progress));
    if ($status !== null) {
        $st = $pdo->prepare('UPDATE theory_ai_training_report_jobs SET progress = ?, status = ?, updated_at = NOW() WHERE id = ?');
        $st->execute([$progress, $status, $jobId]);
    } else {
        $st = $pdo->prepare('UPDATE theory_ai_training_report_jobs SET progress = ?, updated_at = NOW() WHERE id = ?');
        $st->execute([$progress, $jobId]);
    }
}

function tatr_reset_stale_running(PDO $pdo, int $cohortId, int $studentId): void
{
    $st = $pdo->prepare("
        UPDATE theory_ai_training_report_jobs
        SET status = 'pending',
            progress = 0,
            error_text = 'Previous run did not finish; you can retry.',
            started_at = NULL,
            updated_at = NOW()
        WHERE cohort_id = ?
          AND student_id = ?
          AND status = 'running'
          AND started_at IS NOT NULL
          AND started_at < DATE_SUB(NOW(), INTERVAL 50 MINUTE)
    ");
    $st->execute([$cohortId, $studentId]);
}

/**
 * @return array{ready: bool, job_id: int, worker_spawned: bool, fingerprint: string}
 */
function tatr_start_or_resume(PDO $pdo, int $cohortId, int $studentId): array
{
    tatr_ensure_table($pdo);
    InstructorTheoryTrainingReportAi::verifyCohortStudent($pdo, $cohortId, $studentId);

    tatr_reset_stale_running($pdo, $cohortId, $studentId);

    $fingerprint = tatr_compute_fingerprint($pdo, $cohortId, $studentId);

    $sel = $pdo->prepare('SELECT * FROM theory_ai_training_report_jobs WHERE cohort_id = ? AND student_id = ? LIMIT 1');
    $sel->execute([$cohortId, $studentId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    if ($row && (string)$row['status'] === 'complete' && (string)$row['fingerprint'] === $fingerprint) {
        return [
            'ready' => true,
            'job_id' => (int)$row['id'],
            'worker_spawned' => false,
            'fingerprint' => $fingerprint,
        ];
    }

    if ($row && (string)$row['status'] === 'running') {
        $jobId = (int)$row['id'];

        return [
            'ready' => false,
            'job_id' => $jobId,
            'worker_spawned' => tatr_spawn_cli_worker($jobId),
            'fingerprint' => $fingerprint,
        ];
    }

    $ins = $pdo->prepare("
        INSERT INTO theory_ai_training_report_jobs
            (cohort_id, student_id, fingerprint, status, progress, result_json, error_text, created_at, updated_at, started_at, completed_at)
        VALUES
            (?, ?, ?, 'pending', 0, NULL, NULL, NOW(), NOW(), NULL, NULL)
        ON DUPLICATE KEY UPDATE
            fingerprint = VALUES(fingerprint),
            status = 'pending',
            progress = 0,
            result_json = NULL,
            error_text = NULL,
            started_at = NULL,
            completed_at = NULL,
            updated_at = NOW()
    ");
    $ins->execute([$cohortId, $studentId, $fingerprint]);

    $sel->execute([$cohortId, $studentId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Could not create theory AI training report job');
    }

    $jobId = (int)$row['id'];
    $spawned = tatr_spawn_cli_worker($jobId);

    return [
        'ready' => false,
        'job_id' => $jobId,
        'worker_spawned' => $spawned,
        'fingerprint' => $fingerprint,
    ];
}

function tatr_spawn_cli_worker(int $jobId): bool
{
    if ($jobId <= 0) {
        return false;
    }
    if (!function_exists('exec')) {
        return false;
    }
    $php = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $script = realpath(__DIR__ . '/../public/instructor/theory_ai_training_report_worker.php');
    if ($script === false || !is_file($script)) {
        return false;
    }
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $jobId;
    if (stripos(PHP_OS, 'WIN') === 0) {
        @pclose(@popen('start /B ' . $cmd, 'r'));

        return true;
    }
    @exec($cmd . ' > /dev/null 2>&1 &');

    return true;
}

function tatr_process_job(PDO $pdo, int $jobId): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM theory_ai_training_report_jobs WHERE id = ? FOR UPDATE');
    $st->execute([$jobId]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        $pdo->rollBack();
        throw new RuntimeException('Job not found');
    }

    if ((string)$job['status'] === 'complete') {
        $pdo->commit();

        return;
    }

    if ((string)$job['status'] === 'running') {
        $pdo->commit();

        return;
    }

    $u = $pdo->prepare("
        UPDATE theory_ai_training_report_jobs
        SET status = 'running',
            progress = 5,
            started_at = COALESCE(started_at, NOW()),
            updated_at = NOW(),
            error_text = NULL
        WHERE id = ?
          AND status IN ('pending','failed')
    ");
    $u->execute([$jobId]);
    if ($u->rowCount() === 0) {
        $pdo->commit();

        return;
    }

    $cohortId = (int)$job['cohort_id'];
    $studentId = (int)$job['student_id'];
    $fingerprint = (string)$job['fingerprint'];

    $pdo->commit();

    try {
        $onProgress = static function (int $pct) use ($pdo, $jobId): void {
            tatr_update_job_progress($pdo, $jobId, $pct);
        };

        $exportData = tatr_build_export_payload($pdo, $cohortId, $studentId, $onProgress);

        $wrap = [
            'exportData' => $exportData,
            'fingerprint' => $fingerprint,
        ];
        $json = json_encode($wrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not serialize report payload');
        }

        $fin = $pdo->prepare("
            UPDATE theory_ai_training_report_jobs
            SET status = 'complete',
                progress = 100,
                result_json = ?,
                completed_at = NOW(),
                error_text = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $fin->execute([$json, $jobId]);
    } catch (Throwable $e) {
        error_log('tatr_process_job job=' . $jobId . ' ' . $e->getMessage());
        $err = $pdo->prepare("
            UPDATE theory_ai_training_report_jobs
            SET status = 'failed',
                progress = 0,
                error_text = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $err->execute([mb_substr($e->getMessage(), 0, 2000), $jobId]);
    }
}

/**
 * @return array<string, mixed>|null
 */
function tatr_get_job(PDO $pdo, int $jobId): ?array
{
    $st = $pdo->prepare('SELECT * FROM theory_ai_training_report_jobs WHERE id = ? LIMIT 1');
    $st->execute([$jobId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return $r ?: null;
}

/**
 * Stream PDF to browser (inline). Caller must exit after return.
 *
 * @param array<string, mixed> $exportData
 */
function tatr_stream_theory_ai_report_pdf(array $exportData): void
{
    $composerAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($composerAutoload)) {
        require_once $composerAutoload;
    }

    $templatePath = __DIR__ . '/../templates/pdf/export_theory_ai_training_report_pdf.php';
    if (!file_exists($templatePath)) {
        throw new RuntimeException('AI report PDF template not found');
    }

    ob_start();
    require $templatePath;
    $html = ob_get_clean();

    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('AI report template rendered empty output');
    }

    $safeProgram = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($exportData['program_title'] ?? 'program'));
    $scope = (string)($exportData['scope_label'] ?? '');
    $exportVersion = (string)($exportData['export_version'] ?? gmdate('Y.m.d.Hi'));
    $safeScope = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $scope !== '' ? $scope : 'scope');

    $filename = 'theory_ai_training_report_' . $safeProgram . '_' . $safeScope . '_' . $exportVersion . '.pdf';

    if (!class_exists('\Mpdf\Mpdf')) {
        throw new RuntimeException('mPDF not installed');
    }

    $tempDir = __DIR__ . '/../storage/tmp/mpdf';
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

    $footerCenter = trim((string)($exportData['scope_label'] ?? '')) !== ''
        ? trim((string)$exportData['scope_label'])
        : trim((string)($exportData['program_title'] ?? ''));
    $mpdf->SetFooter('IPCA Academy|' . $footerCenter . '|Page {PAGENO} of {nbpg}');

    $mpdf->WriteHTML($html);
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
}
