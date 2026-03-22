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
    if ($reviewStatus === 'acceptable') {
        return 'Accepted';
    }
    if ($reviewStatus === 'needs_revision' || $reviewStatus === 'rejected') {
        return 'Student Action Required';
    }
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

.meta strong{
  color:#0f172a;
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

.toc a{
  color:#1e293b;
  text-decoration:none;
  font-weight:inherit;
}

.divider{
  border-top:1px solid #ccc;
  margin:18px 0;
}

.course-title{
  font-size:16pt;
  font-weight:bold;
  margin-top:18px;
  color:#0f172a;
}

.lesson-title{
  font-size:12pt;
  font-weight:bold;
  margin-top:10px;
  color:#0f172a;
}

.lesson-meta{
  font-size:9pt;
  color:#64748b;
  margin-top:2px;
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

<div class="header">
  <?php if ($bannerUrl !== ''): ?>
    <img src="<?= h($bannerUrl) ?>" alt="IPCA Academy">
  <?php endif; ?>
</div>

<div class="meta">
  <strong>Student:</strong> <?= h($studentName) ?><br>
  <strong>Program:</strong> <?= h($programTitle) ?><br>
  <strong>Scope / Cohort:</strong> <?= h($scopeLabel) ?><br>
  <strong>Export Version:</strong> <?= h($exportVersion) ?><br>
  <strong>Export Timestamp:</strong> <?= h($exportTimestamp) ?>
</div>

<div class="toc">
  <div class="toc-title">Table of Contents</div>

  <?php foreach ($courses as $course): ?>
    <div class="toc-course">
      <strong><?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?></strong>

      <?php foreach ((array)$course['lessons'] as $lesson): ?>
        <?php $anchor = 'lesson_' . (int)($lesson['lesson_id'] ?? 0); ?>
        <div class="toc-lesson">
          <a href="#<?= h($anchor) ?>">
            <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="divider"></div>

<?php foreach ($courses as $course): ?>
  <div class="course-title">
    <?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?>
  </div>

  <?php foreach ((array)$course['lessons'] as $lesson): ?>
    <?php $anchor = 'lesson_' . (int)($lesson['lesson_id'] ?? 0); ?>

    <a name="<?= h($anchor) ?>"></a>

    <div class="lesson-title">
      <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
    </div>

    <div class="lesson-meta">
      <?= h(pdf_status_label((string)($lesson['review_status'] ?? 'pending'))) ?>
      <?php if ((int)($lesson['word_count'] ?? 0) > 0): ?>
        · <?= (int)$lesson['word_count'] ?> words
      <?php endif; ?>
      <?php if ((int)($lesson['version_count'] ?? 0) > 0): ?>
        · <?= (int)$lesson['version_count'] ?> versions
      <?php endif; ?>
      <?php if (!empty($lesson['updated_at'])): ?>
        · Last saved <?= h((string)$lesson['updated_at']) ?>
      <?php endif; ?>
    </div>

    <?php if (!empty($lesson['summary_html'])): ?>
      <div class="summary"><?= (string)$lesson['summary_html'] ?></div>
    <?php else: ?>
      <div class="summary-empty">No summary available</div>
    <?php endif; ?>
  <?php endforeach; ?>
<?php endforeach; ?>

</body>
</html>