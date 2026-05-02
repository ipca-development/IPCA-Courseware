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
$bannerUrl       = (string)($exportData['banner_url'] ?? '');
$focusHtml       = (string)($exportData['focus_items_html'] ?? '');
$phakSections    = (array)($exportData['phak_sections'] ?? []);
$acsHtml         = (string)($exportData['acs_section_html'] ?? '');
$regHtml         = (string)($exportData['regulatory_notes_html'] ?? '');
$signoffHtml     = (string)($exportData['signoff_html'] ?? '');
$disclaimer      = (string)($exportData['disclaimer_html'] ?? '');
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
.divider{
  border-top:1px solid #ccc;
  margin:18px 0;
}
.course-title{
  font-size:14pt;
  font-weight:bold;
  margin-top:16px;
  color:#0f172a;
}
.lesson-meta{
  font-size:9pt;
  color:#64748b;
  margin-top:4px;
  margin-bottom:10px;
}
.phak-chapter{
  font-size:11pt;
  font-weight:bold;
  margin-top:12px;
  color:#0f172a;
}
.phak-ref{
  font-size:9pt;
  color:#475569;
  margin-bottom:6px;
}
.summary{
  margin-top:4px;
  margin-bottom:10px;
}
.ecfr-official{
  border:1px solid #cbd5e1;
  border-radius:8px;
  padding:10px 12px;
  margin:10px 0 16px 0;
  background:#f8fafc;
}
.ecfr-head{
  font-size:11pt;
  font-weight:bold;
  margin:0 0 8px 0;
  color:#0f172a;
}
.ecfr-p{
  margin:4px 0;
  line-height:1.45;
  font-size:9.5pt;
}
.ecfr-cita{
  margin-top:8px;
  font-size:8.5pt;
  color:#64748b;
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
  <strong>Report:</strong> Theory training — AI focus &amp; study summary (advisory)<br>
  <strong>Student:</strong> <?= h($studentName) ?><br>
  <strong>Program:</strong> <?= h($programTitle) ?><br>
  <strong>Scope / Cohort:</strong> <?= h($scopeLabel) ?><br>
  <strong>Export Version:</strong> <?= h($exportVersion) ?><br>
  <strong>Export Timestamp:</strong> <?= h($exportTimestamp) ?>
</div>

<?= $disclaimer ?>

<div class="divider"></div>

<h2 class="course-title">My personal focus items</h2>
<div class="summary"><?= $focusHtml ?></div>

<div class="divider"></div>

<h2 class="course-title">PHAK-aligned study narrative</h2>
<p class="lesson-meta">Section titles follow the Pilot&rsquo;s Handbook of Aeronautical Knowledge (PHAK) organization. Body text is an educational synthesis for study — verify technical and regulatory details against current FAA publications.</p>

<?php foreach ($phakSections as $sec): ?>
  <?php if (!is_array($sec)) { continue; } ?>
  <div class="phak-chapter"><?= h((string)($sec['chapter_title'] ?? '')) ?></div>
  <div class="phak-ref"><?= h((string)($sec['phak_reference'] ?? '')) ?></div>
  <div class="summary"><?= (string)($sec['body_html'] ?? '') ?></div>
<?php endforeach; ?>

<div class="divider"></div>

<h2 class="course-title">Private Pilot ACS references (where applicable)</h2>
<div class="summary"><?= $acsHtml ?></div>

<div class="divider"></div>

<h2 class="course-title">Regulatory references (verify at official sources)</h2>
<div class="summary"><?= $regHtml ?></div>

<?= $signoffHtml ?>

</body>
</html>
