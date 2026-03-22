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
$bannerUrl       = (string)($exportData['banner_url'] ?? '');

function pdf_status_label(string $reviewStatus): string
{
    if ($reviewStatus === 'acceptable') return 'Accepted';
    if ($reviewStatus === 'needs_revision' || $reviewStatus === 'rejected') return 'Student Action Required';
    return 'Pending';
}

function pdf_status_class(string $reviewStatus): string
{
    if ($reviewStatus === 'acceptable') return 'status-ok';
    if ($reviewStatus === 'needs_revision' || $reviewStatus === 'rejected') return 'status-warn';
    return 'status-pending';
}

function pdf_ui_date(string $value): string
{
    $value = trim($value);
    if ($value === '') return '—';

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

<style>
body{
  font-family: sans-serif;
  color:#1e293b;
  font-size:10.5pt;
  line-height:1.55;
}

.header img{
  width:100%;
  border-radius:6px;
  margin-bottom:18px;
}

.meta{
  margin-bottom:18px;
  line-height:1.7;
}

.meta strong{
  color:#0f172a;
}

.toc{
  margin-top:8px;
  padding:14px 0;
  border-top:1px solid #dbe4f0;
  border-bottom:1px solid #dbe4f0;
}

.toc-title{
  font-size:15pt;
  font-weight:bold;
  margin-bottom:12px;
}

.toc-course{
  margin-bottom:14px;
}

.toc-course-title{
  font-weight:bold;
  font-size:10.5pt;
}

.toc-lesson{
  margin-left:18px;
  font-size:9.5pt;
}

.divider{
  border-top:1px solid #cbd5e1;
  margin:18px 0;
}

.course-title{
  font-size:17pt;
  font-weight:bold;
  margin-top:18px;
}

.lesson-title{
  font-size:12.5pt;
  font-weight:bold;
  margin-top:10px;
}

.lesson-meta{
  font-size:9pt;
  color:#64748b;
}

.status-ok{color:#166534;}
.status-warn{color:#991b1b;}
.status-pending{color:#92400e;}

.summary{
  margin-top:6px;
  margin-bottom:12px;
}

.summary-empty{
  color:#64748b;
  font-style:italic;
}
</style>

</head>
<body>

<!-- ✅ Banner -->
<div class="header">
  <?php if ($bannerUrl !== ''): ?>
    <img src="<?= h($bannerUrl) ?>">
  <?php endif; ?>
</div>

<!-- ✅ Clean metadata -->
<div class="meta">
  <strong>Student:</strong> <?= h($studentName) ?><br>
  <strong>Program:</strong> <?= h($programTitle) ?><br>
  <strong>Scope / Cohort:</strong> <?= h($scopeLabel) ?><br>
  <strong>Export Version:</strong> <?= h($exportVersion) ?><br>
  <strong>Export Timestamp:</strong> <?= h($exportTimestamp) ?><br>
  <strong>Courses:</strong> <?= $courseCount ?><br>
  <strong>Lessons:</strong> <?= $lessonCount ?>
</div>

<!-- ✅ TOC -->
<div class="toc">
  <div class="toc-title">Table of Contents</div>

  <?php foreach ($courses as $course): ?>
    <div class="toc-course">
      <div class="toc-course-title">
        <?= h($course['course_number']) ?> <?= h($course['course_title']) ?>
      </div>

      <?php foreach ($course['lessons'] as $lesson): ?>
        <div class="toc-lesson">
          <?= h($lesson['lesson_number']) ?> <?= h($lesson['lesson_title']) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="divider"></div>

<!-- ✅ CONTENT -->
<?php foreach ($courses as $course): ?>

  <div class="course-title">
    <?= h($course['course_number']) ?> <?= h($course['course_title']) ?>
  </div>

  <?php foreach ($course['lessons'] as $lesson): ?>

    <div class="lesson-title">
      <?= h($lesson['lesson_number']) ?> <?= h($lesson['lesson_title']) ?>
    </div>

    <div class="lesson-meta">
      <?= pdf_status_label($lesson['review_status']) ?>
      <?php if ($lesson['word_count'] > 0): ?>
        · <?= (int)$lesson['word_count'] ?> words
      <?php endif; ?>
    </div>

    <?php if (!empty($lesson['summary_html'])): ?>
      <div class="summary"><?= $lesson['summary_html'] ?></div>
    <?php else: ?>
      <div class="summary-empty">No summary available</div>
    <?php endif; ?>

  <?php endforeach; ?>

<?php endforeach; ?>

</body>
</html>