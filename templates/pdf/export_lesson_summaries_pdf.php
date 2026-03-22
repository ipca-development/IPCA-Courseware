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
$logoFilePath    = (string)($exportData['logo_file_path'] ?? '');

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
      margin: 18mm 14mm 18mm 14mm;
    }

    body{
      font-family: sans-serif;
      color:#1e293b;
      font-size:10.5pt;
      line-height:1.55;
      margin:0;
      padding:0;
      background:#ffffff;
    }

    .brand-header{
      background: linear-gradient(135deg, #12355f 0%, #1d4f91 100%);
      color:#ffffff;
      border-radius:10px;
      padding:16px 18px;
      margin-bottom:18px;
    }

    .brand-table{
      width:100%;
      border-collapse:collapse;
    }

    .brand-logo-cell{
      width:72px;
      vertical-align:middle;
    }

    .brand-logo-wrap{
      width:56px;
      height:56px;
      border-radius:10px;
      background:rgba(255,255,255,0.12);
      text-align:center;
      vertical-align:middle;
    }

    .brand-logo{
      width:38px;
      height:38px;
      display:block;
      margin:9px auto;
      object-fit:contain;
    }

    .brand-copy{
      vertical-align:middle;
    }

    .brand-title{
      font-size:19pt;
      font-weight:bold;
      line-height:1.05;
      margin:0 0 4px 0;
      color:#ffffff;
    }

    .brand-subtitle{
      font-size:10pt;
      color:rgba(255,255,255,0.90);
      margin:0;
    }

    .meta-grid{
      width:100%;
      border-collapse:separate;
      border-spacing:10px;
      margin:0 0 16px 0;
    }

    .meta-box{
      border:1px solid #dde6f2;
      background:#f8fbff;
      border-radius:11px;
      padding:11px 12px;
      vertical-align:top;
      width:25%;
    }

    .meta-label{
      font-size:8pt;
      text-transform:uppercase;
      letter-spacing:.12em;
      color:#64748b;
      font-weight:bold;
      margin-bottom:5px;
    }

    .meta-value{
      font-size:10pt;
      color:#0f172a;
      font-weight:bold;
      line-height:1.35;
    }

    .toc{
      margin-top:8px;
      padding:14px 0 6px 0;
      border-top:1px solid #dbe4f0;
      border-bottom:1px solid #dbe4f0;
    }

    .toc-title{
      font-size:15pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 12px 0;
    }

    .toc-course{
      margin:0 0 14px 0;
    }

    .toc-course-title{
      font-size:10.5pt;
      font-weight:bold;
      color:#102845;
      margin:0 0 7px 0;
      line-height:1.35;
    }

    .toc-lesson-list{
      margin:0 0 0 20px;
      padding:0;
    }

    .toc-lesson{
      margin:0 0 5px 0;
      color:#334155;
      font-size:9.4pt;
      line-height:1.4;
    }

    .toc-link{
      color:inherit;
      text-decoration:none;
    }

    .toc-meta{
      font-size:8.3pt;
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
      font-size:17pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 12px 0;
      line-height:1.12;
    }

    .lesson-section{
      margin:0 0 18px 0;
      padding-top:12px;
      border-top:1px solid #e2e8f0;
      page-break-inside:avoid;
    }

    .lesson-head{
      margin:0 0 8px 0;
    }

    .lesson-title{
      font-size:12.5pt;
      font-weight:bold;
      color:#0f172a;
      margin:0 0 5px 0;
      line-height:1.25;
    }

    .lesson-meta{
      font-size:8.8pt;
      color:#64748b;
      margin:0;
    }

    .status-pill{
      display:inline-block;
      padding:2px 8px;
      border-radius:999px;
      font-size:8pt;
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
      font-size:10.5pt;
      line-height:1.65;
    }

    .summary-body p{
      margin:0 0 10px 0;
    }

    .summary-body ul,
    .summary-body ol{
      margin:0 0 10px 20px;
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
      font-size:8.5pt;
      color:#64748b;
    }
  </style>
</head>
<body>

  <div class="brand-header">
    <table class="brand-table">
      <tr>
        <td class="brand-logo-cell">
          <div class="brand-logo-wrap">
            <?php if ($logoFilePath !== '' && file_exists($logoFilePath)): ?>
              <img class="brand-logo" src="<?= h($logoFilePath) ?>" alt="IPCA Academy">
            <?php endif; ?>
          </div>
        </td>
        <td class="brand-copy">
          <div class="brand-title">IPCA Academy</div>
          <div class="brand-subtitle">Student Training Summary Export</div>
        </td>
      </tr>
    </table>
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
            <?php $lessonAnchor = 'lesson-' . (int)($lesson['lesson_id'] ?? 0); ?>
            <div class="toc-lesson">
              <a class="toc-link" href="#<?= h($lessonAnchor) ?>">
                <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
              </a>
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
        <?php $lessonAnchor = 'lesson-' . (int)($lesson['lesson_id'] ?? 0); ?>
        <a name="<?= h($lessonAnchor) ?>"></a>
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
    This PDF is generated from IPCA.training V1.0
  </div>

</body>
</html>