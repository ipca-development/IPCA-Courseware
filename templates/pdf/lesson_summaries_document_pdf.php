<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($exportData) || !is_array($exportData)) {
    throw new RuntimeException('Missing export data');
}

$studentName     = (string)($exportData['student_name'] ?? 'Student');
$programTitle    = (string)($exportData['program_title'] ?? 'Training Program');
$scopeLabel      = (string)($exportData['scope_label'] ?? '');
$exportVersion   = (string)($exportData['export_version'] ?? '');
$exportTimestamp = (string)($exportData['export_timestamp'] ?? '');
$courseCount     = (int)($exportData['course_count'] ?? 0);
$lessonCount     = (int)($exportData['lesson_count'] ?? 0);
$courses         = (array)($exportData['courses'] ?? []);

function pdf_status_label(string $reviewStatus): string
{
    if ($reviewStatus === 'acceptable') {
        return 'Accepted';
    }
    if ($reviewStatus === 'needs_revision' || $reviewStatus === 'rejected') {
        return 'Student Action Required';
    }
    return 'Pending';
}

function pdf_status_class(string $reviewStatus): string
{
    if ($reviewStatus === 'acceptable') {
        return 'status-ok';
    }
    if ($reviewStatus === 'needs_revision' || $reviewStatus === 'rejected') {
        return 'status-warn';
    }
    return 'status-pending';
}

function pdf_ui_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '—';
    }

    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Lesson Summaries PDF Export</title>
  <style>
    @page {
      margin: 20mm 16mm 18mm 16mm;
    }

    body{
      font-family: sans-serif;
      color:#1e293b;
      font-size:11pt;
      line-height:1.55;
      margin:0;
      padding:0;
      background:#ffffff;
    }

    .doc-header{
      border-bottom:1px solid #cbd5e1;
      padding-bottom:14px;
      margin-bottom:18px;
    }

    .doc-overline{
      font-size:9pt;
      text-transform:uppercase;
      letter-spacing:.14em;
      color:#64748b;
      font-weight:bold;
      margin-bottom:8px;
    }

    .doc-title{
      font-size:24pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 8px 0;
      line-height:1.08;
    }

    .doc-subtitle{
      font-size:11pt;
      color:#475569;
      margin:0;
    }

    .meta-grid{
      width:100%;
      border-collapse:separate;
      border-spacing:8px;
      margin:16px 0 8px 0;
    }

    .meta-box{
      border:1px solid #e2e8f0;
      background:#f8fafc;
      border-radius:10px;
      padding:10px 12px;
      vertical-align:top;
      width:25%;
    }

    .meta-label{
      font-size:8.5pt;
      text-transform:uppercase;
      letter-spacing:.12em;
      color:#64748b;
      font-weight:bold;
      margin-bottom:5px;
    }

    .meta-value{
      font-size:10.5pt;
      color:#0f172a;
      font-weight:bold;
      line-height:1.35;
    }

    .toc{
      margin-top:18px;
      padding-top:10px;
      border-top:1px solid #e2e8f0;
    }

    .toc-title{
      font-size:16pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 12px 0;
    }

    .toc-course{
      margin:0 0 14px 0;
    }

    .toc-course-title{
      font-size:11pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 8px 0;
    }

    .toc-lesson-list{
      margin:0 0 0 24px;
      padding:0;
    }

    .toc-lesson{
      margin:0 0 6px 0;
      color:#334155;
    }

    .toc-meta{
      font-size:9pt;
      color:#64748b;
    }

    .divider{
      border-top:1px solid #cbd5e1;
      margin:18px 0;
    }

    .course-section{
      margin-top:22px;
      page-break-inside:avoid;
    }

    .course-title{
      font-size:18pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 14px 0;
      line-height:1.12;
    }

    .lesson-section{
      margin:0 0 20px 0;
      padding-top:12px;
      border-top:1px solid #e2e8f0;
      page-break-inside:avoid;
    }

    .lesson-head{
      margin:0 0 8px 0;
    }

    .lesson-title{
      font-size:13pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 5px 0;
      line-height:1.25;
    }

    .lesson-meta{
      font-size:9pt;
      color:#64748b;
      margin:0;
    }

    .status-pill{
      display:inline-block;
      padding:2px 8px;
      border-radius:999px;
      font-size:8.5pt;
      font-weight:bold;
      border:1px solid transparent;
    }

    .status-ok{
      background:#dcfce7;
      color:#166534;
      border-color:#86efac;
    }

    .status-warn{
      background:#fee2e2;
      color:#991b1b;
      border-color:#fca5a5;
    }

    .status-pending{
      background:#fef3c7;
      color:#92400e;
      border-color:#fde68a;
    }

    .summary-body{
      margin-top:8px;
      color:#1e293b;
      font-size:11pt;
      line-height:1.65;
    }

    .summary-body p{
      margin:0 0 10px 0;
    }

    .summary-body ul,
    .summary-body ol{
      margin:0 0 10px 22px;
      padding:0;
    }

    .summary-body li{
      margin:0 0 4px 0;
    }

    .summary-empty{
      font-style:italic;
      color:#64748b;
      margin-top:8px;
    }

    .footer-note{
      margin-top:18px;
      padding-top:10px;
      border-top:1px solid #e2e8f0;
      font-size:9pt;
      color:#64748b;
    }
  </style>
</head>
<body>

  <div class="doc-header">
    <div class="doc-overline">Student Training Notebook Export</div>
    <div class="doc-title"><?= h($programTitle) ?></div>
    <p class="doc-subtitle">
      Structured lesson summaries exported from the canonical lesson summary record for the selected training scope.
    </p>
  </div>

  <table class="meta-grid">
    <tr>
      <td class="meta-box">
        <div class="meta-label">Student</div>
        <div class="meta-value"><?= h($studentName) ?></div>
      </td>
      <td class="meta-box">
        <div class="meta-label">Program</div>
        <div class="meta-value"><?= h($programTitle) ?></div>
      </td>
      <td class="meta-box">
        <div class="meta-label">Scope / Cohort</div>
        <div class="meta-value"><?= h($scopeLabel) ?></div>
      </td>
      <td class="meta-box">
        <div class="meta-label">Export Version</div>
        <div class="meta-value"><?= h($exportVersion) ?></div>
      </td>
    </tr>
    <tr>
      <td class="meta-box">
        <div class="meta-label">Export Timestamp</div>
        <div class="meta-value"><?= h($exportTimestamp) ?></div>
      </td>
      <td class="meta-box">
        <div class="meta-label">Courses</div>
        <div class="meta-value"><?= (int)$courseCount ?></div>
      </td>
      <td class="meta-box">
        <div class="meta-label">Lessons</div>
        <div class="meta-value"><?= (int)$lessonCount ?></div>
      </td>
      <td class="meta-box">
        <div class="meta-label">Document Type</div>
        <div class="meta-value">Lesson Summary Export</div>
      </td>
    </tr>
  </table>

  <div class="toc">
    <div class="toc-title">Table of Contents</div>

    <?php foreach ($courses as $course): ?>
      <div class="toc-course">
        <div class="toc-course-title">
          <?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?>
        </div>

        <div class="toc-lesson-list">
          <?php foreach ((array)$course['lessons'] as $lesson): ?>
            <div class="toc-lesson">
              <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
              <span class="toc-meta">
                — <?= h(pdf_status_label((string)$lesson['review_status'])) ?>
                <?php if ((int)$lesson['word_count'] > 0): ?>
                  · <?= (int)$lesson['word_count'] ?> words
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="divider"></div>

  <?php foreach ($courses as $course): ?>
    <div class="course-section">
      <div class="course-title">
        <?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?>
      </div>

      <?php foreach ((array)$course['lessons'] as $lesson): ?>
        <div class="lesson-section">
          <div class="lesson-head">
            <div class="lesson-title">
              <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
            </div>
            <div class="lesson-meta">
              <span class="status-pill <?= h(pdf_status_class((string)$lesson['review_status'])) ?>">
                <?= h(pdf_status_label((string)$lesson['review_status'])) ?>
              </span>
              <?php if ((int)$lesson['word_count'] > 0): ?>
                &nbsp; · &nbsp; <?= (int)$lesson['word_count'] ?> words
              <?php endif; ?>
              <?php if ((int)$lesson['version_count'] > 0): ?>
                &nbsp; · &nbsp; <?= (int)$lesson['version_count'] ?> versions
              <?php endif; ?>
              <?php if (trim((string)$lesson['updated_at']) !== ''): ?>
                &nbsp; · &nbsp; Last saved <?= h(pdf_ui_date((string)$lesson['updated_at'])) ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if (trim((string)$lesson['summary_html']) !== ''): ?>
            <div class="summary-body">
              <?= (string)$lesson['summary_html'] ?>
            </div>
          <?php else: ?>
            <div class="summary-empty">No summary content available for this lesson.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="footer-note">
    This PDF is generated directly from canonical lesson summary data for the selected training scope. No second persistence layer is created.
  </div>

</body>
</html>