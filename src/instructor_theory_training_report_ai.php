<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/resource_library_enrich_context.php';

/**
 * Instructor-only: build theory training context + AI JSON for the PDF training report.
 */
final class InstructorTheoryTrainingReportAi
{
    /** @var list<array{ref:string,subject:string}> */
    public const SIGNOFF_SUBJECTS = [
        ['ref' => '61.105(b)(1)', 'subject' => 'FARs'],
        ['ref' => '61.105(b)(2)', 'subject' => 'Accident Reporting Requirements'],
        ['ref' => '61.105(b)(3)', 'subject' => 'Use of AIM and FAA ACs'],
        ['ref' => '61.105(b)(4)', 'subject' => 'Aeronautical VFR Charts'],
        ['ref' => '61.105(b)(5)', 'subject' => 'Radio Communication'],
        ['ref' => '61.105(b)(6)', 'subject' => 'Critical Weather Situations'],
        ['ref' => '61.105(b)(7)', 'subject' => 'Collision avoidance and wake turbulence'],
        ['ref' => '61.105(b)(8)', 'subject' => 'Effects of Density Altitude'],
        ['ref' => '61.105(b)(9)', 'subject' => 'Weight and Balance'],
        ['ref' => '61.105(b)(10)', 'subject' => 'Aerodynamics, powerplants, and aircraft systems'],
        ['ref' => '61.105(b)(11)', 'subject' => 'Stall and spin awareness and recovery'],
        ['ref' => '61.105(b)(12)', 'subject' => 'ADM and judgment'],
        ['ref' => '61.105(b)(13)(i)(ii)', 'subject' => 'Preflight action and planning'],
    ];

    public static function verifyCohortStudent(PDO $pdo, int $cohortId, int $studentId): void
    {
        if ($cohortId <= 0 || $studentId <= 0) {
            throw new RuntimeException('Missing cohort_id or student_id');
        }
        $st = $pdo->prepare('
            SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? LIMIT 1
        ');
        $st->execute([$cohortId, $studentId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Student is not enrolled in this cohort');
        }
    }

    public static function cohortTimezone(PDO $pdo, int $cohortId): string
    {
        try {
            $st = $pdo->prepare('SELECT timezone FROM cohorts WHERE id = ? LIMIT 1');
            $st->execute([$cohortId]);
            $tz = trim((string)$st->fetchColumn());
            return $tz !== '' ? $tz : 'UTC';
        } catch (Throwable $e) {
            return 'UTC';
        }
    }

    public static function formatUtcForTimezone(?string $utc, string $ianaTz): string
    {
        $utc = trim((string)$utc);
        if ($utc === '') {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            $dt = $dt->setTimezone(new DateTimeZone($ianaTz));

            return $dt->format('m/d/Y H:i') . ' (' . $ianaTz . ')';
        } catch (Throwable $e) {
            return $utc;
        }
    }

    /**
     * @return array{attempts:list<array<string,mixed>>,summaries:list<array<string,mixed>>,cohort_name:string,course_title_hint:string}
     */
    public static function collectContext(PDO $pdo, int $cohortId, int $studentId): array
    {
        $cohortName = 'Cohort ' . $cohortId;
        $courseTitle = '';
        try {
            $cst = $pdo->prepare('
                SELECT co.name AS cohort_name, c.title AS course_title
                FROM cohorts co
                JOIN courses c ON c.id = co.course_id
                WHERE co.id = ?
                LIMIT 1
            ');
            $cst->execute([$cohortId]);
            $crow = $cst->fetch(PDO::FETCH_ASSOC) ?: [];
            if (trim((string)($crow['cohort_name'] ?? '')) !== '') {
                $cohortName = trim((string)$crow['cohort_name']);
            }
            $courseTitle = trim((string)($crow['course_title'] ?? ''));
        } catch (Throwable $e) {
        }

        $attemptStmt = $pdo->prepare("
            SELECT
                pt.id,
                pt.lesson_id,
                l.title AS lesson_title,
                pt.attempt,
                pt.status,
                pt.score_pct,
                pt.completed_at,
                pt.created_at,
                pt.formal_result_code,
                pt.counts_as_unsat,
                pt.pass_gate_met,
                LEFT(pt.weak_areas, 4000) AS weak_areas
            FROM progress_tests_v2 pt
            INNER JOIN lessons l ON l.id = pt.lesson_id
            WHERE pt.user_id = ?
              AND pt.cohort_id = ?
            ORDER BY pt.completed_at DESC, pt.id DESC
            LIMIT 220
        ");
        $attemptStmt->execute([$studentId, $cohortId]);
        $attempts = $attemptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sumStmt = $pdo->prepare("
            SELECT
                ls.lesson_id,
                l.title AS lesson_title,
                ls.review_status,
                ls.review_score,
                LEFT(COALESCE(ls.review_feedback, ''), 4000) AS review_feedback,
                LEFT(COALESCE(ls.review_notes_by_instructor, ''), 4000) AS review_notes_by_instructor,
                ls.updated_at,
                LEFT(COALESCE(ls.summary_plain, ''), 3500) AS summary_plain_excerpt
            FROM lesson_summaries ls
            INNER JOIN lessons l ON l.id = ls.lesson_id
            WHERE ls.user_id = ?
              AND ls.cohort_id = ?
            ORDER BY ls.updated_at DESC, ls.lesson_id ASC
            LIMIT 220
        ");
        $sumStmt->execute([$studentId, $cohortId]);
        $summaries = $sumStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'attempts' => $attempts,
            'summaries' => $summaries,
            'cohort_name' => $cohortName,
            'course_title_hint' => $courseTitle,
        ];
    }

    /**
     * Human-readable label for the live Resource Library edition used for PHAK retrieval (empty if none).
     */
    public static function liveResourceLibraryHandbookLabel(PDO $pdo): string
    {
        $id = rl_enrich_resolve_edition_id($pdo, null);
        if ($id <= 0) {
            return '';
        }
        try {
            $st = $pdo->prepare('SELECT title, revision_code FROM resource_library_editions WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $title = trim((string)($r['title'] ?? ''));
            $rev = trim((string)($r['revision_code'] ?? ''));
            if ($title !== '' && $rev !== '') {
                return $title . ' (' . $rev . ')';
            }
            if ($title !== '') {
                return $title;
            }
            if ($rev !== '') {
                return 'PHAK ' . $rev;
            }

            return 'Resource Library edition #' . $id;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Pull ranked PHAK blocks for theory-report AI, using lesson titles and weak-area/summary text as search hints.
     *
     * @param array{attempts?:list<mixed>,summaries?:list<mixed>} $context
     */
    public static function collectPhakLibraryPack(PDO $pdo, array $context): string
    {
        $editionId = rl_enrich_resolve_edition_id($pdo, null);
        if ($editionId <= 0) {
            return '';
        }
        try {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM resource_library_blocks WHERE edition_id = ?');
            $chk->execute([$editionId]);
            if ((int)$chk->fetchColumn() <= 0) {
                return '';
            }
        } catch (Throwable $e) {
            return '';
        }

        $titleHints = [];
        $otherHints = [];
        $courseHint = trim((string)($context['course_title_hint'] ?? ''));
        if ($courseHint !== '') {
            $titleHints[] = $courseHint;
        }
        foreach ($context['attempts'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $t = trim((string)($row['lesson_title'] ?? ''));
            if ($t !== '') {
                $titleHints[] = $t;
            }
            $w = trim((string)($row['weak_areas'] ?? ''));
            if ($w !== '') {
                $otherHints[] = mb_substr($w, 0, 480);
            }
        }
        foreach ($context['summaries'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $t = trim((string)($row['lesson_title'] ?? ''));
            if ($t !== '') {
                $titleHints[] = $t;
            }
            $s = trim((string)($row['summary_plain_excerpt'] ?? ''));
            if ($s !== '') {
                $otherHints[] = mb_substr($s, 0, 400);
            }
        }
        $titleHints = array_values(array_unique(array_filter($titleHints)));
        $otherHints = array_values(array_unique(array_filter($otherHints)));
        $hints = $titleHints;
        foreach ($otherHints as $o) {
            if (count($hints) >= 18) {
                break;
            }
            $hints[] = $o;
        }
        $hints = array_slice($hints, 0, 15);

        $seen = [];
        $lines = ['--- Indexed PHAK excerpts (Resource Library; use this wording where it applies) ---'];
        $used = strlen($lines[0]) + 40;
        $maxTotal = 20000;

        foreach ($hints as $hint) {
            if ($used >= $maxTotal) {
                break;
            }
            if (mb_strlen($hint) < 3) {
                continue;
            }
            try {
                $hits = rl_ai_search_resource_blocks($pdo, $editionId, $hint, 6);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($hits as $h) {
                if (!is_array($h)) {
                    continue;
                }
                $bk = trim((string)($h['block_key'] ?? ''));
                $key = $bk !== '' ? $bk : ((string)($h['chapter'] ?? '') . '|' . (string)($h['block_local_id'] ?? ''));
                if ($key === '' || $key === '|') {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $body = (string)($h['body_text'] ?? '');
                if ($body === '') {
                    continue;
                }
                if (strlen($body) > 1100) {
                    $body = substr($body, 0, 1100) . '…';
                }
                $chunk = '[' . (string)($h['chapter'] ?? '') . ' / ' . (string)($h['block_local_id'] ?? '') . "]\n" . $body;
                if ($used + strlen($chunk) + 4 > $maxTotal) {
                    break 2;
                }
                $lines[] = $chunk;
                $used += strlen($chunk) + 4;
            }
        }

        if (count($lines) <= 1) {
            return '';
        }

        return implode("\n\n", $lines);
    }

    public static function sanitizeAiHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed)[^>]*>.*?</\\1>#is', '', $html) ?? '';
        $html = preg_replace('#</?(script|iframe|object|embed)[^>]*>#i', '', $html) ?? '';

        return $html;
    }

    /**
     * @param array{attempts:list,summaries:list,cohort_name:string,course_title_hint:string} $context
     * @return array<string,mixed>
     */
    public static function callOpenAiForReportJson(
        array $context,
        string $studentName,
        string $cohortTitle,
        string $phakLibraryPack = '',
        string $phakHandbookLabel = ''
    ): array {
        $subjectLines = [];
        foreach (self::SIGNOFF_SUBJECTS as $idx => $row) {
            $n = $idx + 1;
            $subjectLines[] = $n . '. ' . $row['ref'] . ' — ' . $row['subject'];
        }
        $subjectBlock = implode("\n", $subjectLines);

        $hasRlPhak = $phakLibraryPack !== '';
        $rules = [
            'Return valid JSON only. No markdown fences. No text outside JSON.',
            'Where regulations apply, cite 14 CFR parts/sections and applicable Advisory Circular identifiers. The PDF will prepend official 14 CFR § 61.105 text fetched live from the U.S. Government eCFR API; use your regulatory_notes_html for other parts (e.g. cross-references) and remind instructors to verify current wording on https://www.ecfr.gov/ and https://www.faa.gov/.',
            'Include Private Pilot ACS references when applicable (e.g. ACS PA.II.A.K1 — Pilot Self Assessment) with short applicability notes.',
            'signoff_rows MUST be an array of exactly 13 objects in the same order as the static subject list provided. Each object: {"sessions":[{"at_utc":"ISO-8601 UTC or MySQL UTC datetime string","hours_approx":0.5,"note":"short"}],"total_hours_approx":0.0}. Use lesson summary update times and progress test completion times from the supplied evidence to propose realistic study sessions; if uncertain, use conservative estimates and say so in note.',
            'course_total_hours_approx should approximate total ground-study time across the program from the same evidence.',
            'All HTML string fields must be simple tags (h2,h3,p,ul,ol,li,strong,em,br,table,tr,th,td) only — no attributes except colspan/rowspan on table cells if needed.',
            'phak_oral_quiz_items MUST be an array of 12 to 28 objects for instructor-led oral prep with the student. Aim for roughly 18–24 when evidence is rich; never more than 28.',
            'Each phak_oral_quiz_items element MUST be an object with keys: topic_label (string), depth_tag (string: one of recall, application, correlation, scenario), scenario_html (HTML string; use empty string if not scenario-based), question_html (HTML; sharp instructor oral question), instructor_answer_key_html (HTML; detailed model answer for grading and teaching points), phak_official_lookup_html (HTML; REQUIRED lookup aid — numbered steps listing official handbook identifier, chapter/section titles, and when excerpts provide them the exact [chapter / block_id] tags plus 1–3 short anchor phrases to search in the PDF so an answer can be found quickly).',
            'At least half of the items MUST use depth_tag scenario or correlation. Many questions should place the student in a realistic flight/ops context and require linking two or more concepts.',
            'Do NOT output phak_sections or broad PHAK narrative chapters; only phak_oral_quiz_items for the PHAK portion.',
        ];
        if ($hasRlPhak) {
            $rules[] = 'resource_library_phak_excerpts contains indexed PHAK plain text from this school\'s Resource Library. Every instructor_answer_key_html and phak_official_lookup_html MUST be traceable to those excerpts (and student evidence) when an excerpt applies; use exact bracket tags [chapter / block_id] from excerpts inside phak_official_lookup_html whenever possible so instructors can jump to the same block in the official PHAK PDF. Do not invent handbook claims absent from excerpts+evidence.';
            $rules[] = 'Prefer American English aviation terminology as in the excerpts. When resource_library_handbook_label is set, repeat that revision/title in phak_official_lookup_html for each item.';
        } else {
            $rules[] = 'Without indexed excerpts, still write phak_official_lookup_html with the best concrete PHAK chapter/section names and FAA handbook identifiers you can infer from the topic (e.g. FAA-H-8083-25) so instructors can find material in the official PDF; avoid vague references.';
        }

        $payload = [
            'task' => 'Create an instructor-facing theory training report for one student. The PHAK portion is an oral-prep quiz bank (not a broad narrative chapter summary). Return JSON only.',
            'rules' => $rules,
            'static_signoff_subject_order' => $subjectBlock,
            'required_top_level_json_keys' => [
                'focus_items_html',
                'phak_oral_quiz_items',
                'acs_section_html',
                'regulatory_notes_html',
                'signoff_rows',
                'course_total_hours_approx',
            ],
            'student_name' => $studentName,
            'cohort_title' => $cohortTitle,
            'evidence' => $context,
        ];
        if ($phakHandbookLabel !== '') {
            $payload['resource_library_handbook_label'] = $phakHandbookLabel;
        }
        if ($phakLibraryPack !== '') {
            $payload['resource_library_phak_excerpts'] = $phakLibraryPack;
        }

        $model = cw_openai_model();
        $resp = cw_openai_responses([
            'model' => $model,
            'input' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'max_output_tokens' => 18000,
        ], 240);

        $json = cw_openai_extract_json_text($resp);
        if (!is_array($json) || !$json) {
            throw new RuntimeException('AI returned no usable JSON for the training report');
        }

        foreach (['focus_items_html', 'phak_oral_quiz_items', 'acs_section_html', 'regulatory_notes_html', 'signoff_rows'] as $k) {
            if (!array_key_exists($k, $json)) {
                throw new RuntimeException('AI JSON missing key: ' . $k);
            }
        }

        $json['focus_items_html'] = self::sanitizeAiHtml((string)($json['focus_items_html'] ?? ''));
        $json['acs_section_html'] = self::sanitizeAiHtml((string)($json['acs_section_html'] ?? ''));
        $json['regulatory_notes_html'] = self::sanitizeAiHtml((string)($json['regulatory_notes_html'] ?? ''));

        $quiz = $json['phak_oral_quiz_items'] ?? [];
        if (!is_array($quiz)) {
            $quiz = [];
        }
        $cleanQuiz = [];
        foreach ($quiz as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cleanQuiz[] = [
                'topic_label' => (string)($row['topic_label'] ?? ''),
                'depth_tag' => (string)($row['depth_tag'] ?? ''),
                'scenario_html' => self::sanitizeAiHtml((string)($row['scenario_html'] ?? '')),
                'question_html' => self::sanitizeAiHtml((string)($row['question_html'] ?? '')),
                'instructor_answer_key_html' => self::sanitizeAiHtml((string)($row['instructor_answer_key_html'] ?? '')),
                'phak_official_lookup_html' => self::sanitizeAiHtml((string)($row['phak_official_lookup_html'] ?? '')),
            ];
        }
        $json['phak_oral_quiz_items'] = $cleanQuiz;
        if (count($cleanQuiz) < 12) {
            throw new RuntimeException('AI returned too few phak_oral_quiz_items (minimum 12); retry report generation');
        }

        return $json;
    }

    /**
     * @param array<string,mixed> $ai
     * @return string HTML fragment
     */
    public static function renderSignoffTableHtml(array $ai, string $cohortTz, string $chiefName): string
    {
        $rows = $ai['signoff_rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $courseTotal = isset($ai['course_total_hours_approx']) ? (float)$ai['course_total_hours_approx'] : 0.0;

        $html = '<div class="divider"></div>';
        $html .= '<h2 class="course-title">Instructor sign-off sheet (14 CFR 61.105(a) ground training)</h2>';
        $html .= '<p class="lesson-meta">Task: ensure requirements for 61.105(b)(1) through (13)(i) and (ii) are documented in the applicant\'s logbook as required by your principal operation. Times below are shown in cohort local time where an UTC instant was supplied.</p>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:9pt;margin-top:10px;" border="1" cellpadding="4">';
        $html .= '<thead><tr>'
            . '<th>61.105 reference + subject</th>'
            . '<th>Training performed (cohort local)</th>'
            . '<th>Approx. hours per session</th>'
            . '<th>Total (approx.)</th>'
            . '</tr></thead><tbody>';

        $sumTotals = 0.0;
        foreach (self::SIGNOFF_SUBJECTS as $idx => $def) {
            $cell = $rows[$idx] ?? [];
            $sessions = (is_array($cell) && isset($cell['sessions']) && is_array($cell['sessions'])) ? $cell['sessions'] : [];
            $lines = [];
            $hoursBits = [];
            $rowTotal = isset($cell['total_hours_approx']) ? (float)$cell['total_hours_approx'] : 0.0;
            if ($rowTotal <= 0 && $sessions) {
                foreach ($sessions as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $rowTotal += (float)($s['hours_approx'] ?? 0);
                }
            }
            $sumTotals += $rowTotal;

            foreach ($sessions as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $at = self::formatUtcForTimezone((string)($s['at_utc'] ?? ''), $cohortTz);
                $note = trim((string)($s['note'] ?? ''));
                $lines[] = $at . ($note !== '' ? (' — ' . htmlspecialchars($note, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '');
                $hoursBits[] = htmlspecialchars((string)($s['hours_approx'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            $refSub = htmlspecialchars($def['ref'] . ' — ' . $def['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= '<tr>';
            $html .= '<td style="vertical-align:top;">' . $refSub . '</td>';
            $html .= '<td style="vertical-align:top;">' . ($lines ? implode('<br>', $lines) : '—') . '</td>';
            $html .= '<td style="vertical-align:top;">' . ($hoursBits ? implode('<br>', $hoursBits) : '—') . '</td>';
            $html .= '<td style="vertical-align:top;text-align:right;">' . htmlspecialchars((string)round($rowTotal, 2), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr><th colspan="3" style="text-align:right;">Course total (approx., from evidence model)</th><td style="text-align:right;">'
            . htmlspecialchars((string)round($courseTotal > 0 ? $courseTotal : $sumTotals, 2), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</td></tr>';

        $html .= '</tbody></table>';

        $chief = trim($chiefName) !== '' ? htmlspecialchars($chiefName, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Chief Instructor';
        $html .= '<div style="margin-top:22px;padding:14px;border:1px solid #cbd5e1;border-radius:10px;">';
        $html .= '<div style="font-weight:bold;margin-bottom:8px;">Chief Instructor</div>';
        $html .= '<div style="margin-bottom:6px;">Name: ' . $chief . '</div>';
        $html .= '<div style="min-height:48px;border-bottom:1px solid #64748b;margin-top:18px;">Signature</div>';
        $html .= '<div class="lesson-meta" style="margin-top:8px;">This line is for the Chief Instructor (or delegated ground instructor) to certify the ground training record; keep originals per your school policy.</div>';
        $html .= '</div>';

        return $html;
    }
}
