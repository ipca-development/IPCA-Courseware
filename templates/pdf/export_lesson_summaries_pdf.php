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
$courses         = (array)($exportData['courses'] ?? []);
$bannerUrl       = (string)($exportData['banner_url'] ?? '');

function pdf_status_label(string $reviewStatus): string
{
    if ($reviewStatus === 'acceptable') return 'Accepted';
    if ($reviewStatus === 'needs_revision' || $reviewStatus === 'rejected') return 'Student Action Required';
    return 'Pending';
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">

<style>
body{
  font-family: sans-serif;
  font-size:10.5pt;
  color:#1e293b;
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

.toc{
  border-top:1px solid #ccc;
  border-bottom:1px solid #ccc;
  padding:12px 0;
  margin-bottom:20px;
}

.toc-title{
  font-size:14pt;
  font-weight:bold;
  margin-bottom:10px;
}

.toc-course{
  margin-bottom:12px;
}

.toc-lesson{
  margin-left:15px;
  font-size:9.5pt;
}

.divider{
  border-top:1px solid #ccc;
  margin:18px 0;
}

.course-title{
  font-size:16pt;
  font-weight:bold;
  margin-top:18px;
}

.lesson-title{
  font-size:12pt;
  font-weight:bold;
  margin-top:10px;
}

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

<!-- ✅ Metadata -->
<div class="meta">
  <strong>Student:</strong> <?= h($studentName) ?><br>
  <strong>Program:</strong> <?= h($programTitle) ?><br>
  <strong>Scope:</strong> <?= h($scopeLabel) ?><br>
  <strong>Export Version:</strong> <?= h($exportVersion) ?><br>
  <strong>Export Timestamp:</strong> <?= h($exportTimestamp) ?>
</div>

<!-- ✅ TOC -->
<div class="toc">
  <div class="toc-title">Table of Contents</div>

  <?php foreach ($courses as $course): ?>
    <div class="toc-course">
      <strong><?= h($course['course_number']) ?> <?= h($course['course_title']) ?></strong>

      <?php foreach ($course['lessons'] as $lesson): ?>
        <?php $anchor = 'lesson_' . (int)$lesson['lesson_id']; ?>
        <div class="toc-lesson">
          <a href="#<?= h($anchor) ?>">
            <?= h($lesson['lesson_number']) ?> <?= h($lesson['lesson_title']) ?>
          </a>
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

    <?php $anchor = 'lesson_' . (int)$lesson['lesson_id']; ?>

    <!-- ✅ CORRECT MPDF ANCHOR (THIS FIXES YOUR ISSUE) -->
    <a name="<?= h($anchor) ?>"></a>

    <div class="lesson-title">
      <?= h($lesson['lesson_number']) ?> <?= h($lesson['lesson_title']) ?>
    </div>

    <div>
      <?= pdf_status_label($lesson['review_status']) ?>
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