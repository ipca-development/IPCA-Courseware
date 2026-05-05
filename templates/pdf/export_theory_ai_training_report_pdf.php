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
$phakOralQuiz       = (array)($exportData['phak_oral_quiz_items'] ?? []);
$phakSections       = (array)($exportData['phak_sections'] ?? []);
$phakSectionsIntro  = (string)($exportData['phak_sections_intro_html'] ?? '');
$acsHtml            = (string)($exportData['acs_section_html'] ?? '');
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
.oral-quiz-item{
  border:1px solid #cbd5e1;
  border-radius:8px;
  padding:10px 12px;
  margin:12px 0 14px 0;
  background:#fbfdff;
  page-break-inside:avoid;
}
.oral-quiz-head{
  display:flex;
  flex-wrap:wrap;
  gap:8px 12px;
  align-items:baseline;
  margin-bottom:8px;
  border-bottom:1px solid #e2e8f0;
  padding-bottom:6px;
}
.oral-quiz-num{
  font-weight:bold;
  font-size:11pt;
  color:#0f172a;
}
.oral-quiz-topic{
  font-weight:bold;
  font-size:10.5pt;
  color:#0f172a;
  flex:1;
  min-width:0;
}
.oral-depth-tag{
  font-size:8pt;
  font-weight:bold;
  text-transform:uppercase;
  letter-spacing:.04em;
  color:#1e40af;
  background:#e0e7ff;
  border:1px solid #c7d2fe;
  border-radius:999px;
  padding:3px 8px;
  white-space:nowrap;
}
.oral-label{
  font-size:8.5pt;
  font-weight:bold;
  text-transform:uppercase;
  letter-spacing:.06em;
  color:#64748b;
  margin:8px 0 3px 0;
}
.oral-body{
  font-size:9.5pt;
  line-height:1.48;
  color:#1e293b;
}
.oral-lookup{
  margin-top:8px;
  padding:8px 10px;
  background:#f0fdf4;
  border:1px solid #bbf7d0;
  border-radius:6px;
  font-size:9pt;
  line-height:1.45;
  color:#14532d;
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
  <strong>Report:</strong> Theory training — AI focus, PHAK oral prep bank &amp; study summary (advisory)<br>
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

<h2 class="course-title">PHAK oral preparation — instructor / student quiz bank</h2>
<?php if ($phakSectionsIntro !== ''): ?>
  <?= $phakSectionsIntro ?>
<?php else: ?>
  <p class="lesson-meta">Use this bank for oral quizzing: instructor asks; student reasons aloud; debrief with the answer key. Each item lists <strong>PHAK official lookup</strong> cues to find the passage quickly in the FAA PHAK PDF.</p>
<?php endif; ?>

<?php
$hasOralQuiz = false;
foreach ($phakOralQuiz as $_oq) {
    if (is_array($_oq) && trim((string)($_oq['question_html'] ?? '')) !== '') {
        $hasOralQuiz = true;
        break;
    }
}
?>
<?php if ($hasOralQuiz): ?>
  <?php $i = 0; ?>
  <?php foreach ($phakOralQuiz as $it): ?>
    <?php
    if (!is_array($it)) {
        continue;
    }
    $topic = trim((string)($it['topic_label'] ?? ''));
    $depth = trim((string)($it['depth_tag'] ?? ''));
    $scenario = trim((string)($it['scenario_html'] ?? ''));
    $question = trim((string)($it['question_html'] ?? ''));
    if ($question === '') {
        continue;
    }
    ++$i;
    ?>
    <div class="oral-quiz-item">
      <div class="oral-quiz-head">
        <span class="oral-quiz-num"><?= (int)$i ?>.</span>
        <span class="oral-quiz-topic"><?= h($topic !== '' ? $topic : 'Topic') ?></span>
        <?php if ($depth !== ''): ?>
          <span class="oral-depth-tag"><?= h($depth) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($scenario !== '' && strip_tags($scenario) !== ''): ?>
        <div class="oral-label">Scenario</div>
        <div class="oral-body"><?= $scenario ?></div>
      <?php endif; ?>
      <div class="oral-label">Instructor question</div>
      <div class="oral-body"><?= $question ?></div>
      <div class="oral-label">Answer key (instructor)</div>
      <div class="oral-body"><?= (string)($it['instructor_answer_key_html'] ?? '') ?></div>
      <div class="oral-label">PHAK official lookup</div>
      <div class="oral-lookup"><?= (string)($it['phak_official_lookup_html'] ?? '') ?></div>
    </div>
  <?php endforeach; ?>
<?php elseif (count($phakSections) > 0): ?>
  <p class="lesson-meta"><strong>Older export format:</strong> narrative PHAK sections (no oral quiz bank). Regenerate the AI training report for the new oral-prep layout.</p>
  <?php foreach ($phakSections as $sec): ?>
    <?php if (!is_array($sec)) { continue; } ?>
    <div class="phak-chapter"><?= h((string)($sec['chapter_title'] ?? '')) ?></div>
    <div class="phak-ref"><?= h((string)($sec['phak_reference'] ?? '')) ?></div>
    <div class="summary"><?= (string)($sec['body_html'] ?? '') ?></div>
  <?php endforeach; ?>
<?php else: ?>
  <p class="lesson-meta">No PHAK oral quiz items were included in this export.</p>
<?php endif; ?>

<div class="divider"></div>

<h2 class="course-title">Private Pilot ACS references (where applicable)</h2>
<div class="summary"><?= $acsHtml ?></div>

<div class="divider"></div>

<h2 class="course-title">Regulatory references (verify at official sources)</h2>
<div class="summary"><?= $regHtml ?></div>

<?= $signoffHtml ?>

</body>
</html>
