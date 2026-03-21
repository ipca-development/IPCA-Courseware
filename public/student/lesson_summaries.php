<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/lesson_summary_service.php';

cw_require_login();

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');
if ($role !== 'student') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)$u['id'];
$studentName = (string)($u['name'] ?? 'Student');

$service = new LessonSummaryService($pdo);
$selectedCohortId = (int)($_GET['cohort_id'] ?? 0);
$scopes = $service->getAvailableNotebookScopes($userId);

if (!$scopes) {
    exit('No available training scopes.');
}

if ($selectedCohortId <= 0) {
    $selectedCohortId = (int)$scopes[0]['cohort_id'];
}

$validScope = null;
foreach ($scopes as $s) {
    if ((int)$s['cohort_id'] === $selectedCohortId) {
        $validScope = $s;
        break;
    }
}
if (!$validScope) {
    http_response_code(403);
    exit('Invalid scope');
}

$data = $service->getNotebookViewData($userId, $selectedCohortId, $studentName);

function nb_ui_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '—';

    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function nb_ui_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '—';

    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        return $dt->format('D, M j, Y H:i') . ' UTC';
    } catch (Throwable $e) {
        return $value;
    }
}

function nb_has_summary(array $lesson): bool
{
    return trim(strip_tags((string)($lesson['summary_html'] ?? ''))) !== '';
}

function nb_attention_meta(array $lesson): array
{
    $reviewStatus = (string)($lesson['review_status'] ?? '');
    $attentionReason = (string)($lesson['notebook_attention_reason'] ?? '');

    if (in_array($reviewStatus, ['needs_revision', 'rejected'], true)) {
        return ['show' => true, 'label' => 'Student Action Required', 'class' => 'warn'];
    }

    if ($attentionReason === 'training_suspended') {
        return ['show' => true, 'label' => 'Training Paused', 'class' => 'danger'];
    }

    if ($attentionReason === 'one_on_one_required') {
        return ['show' => true, 'label' => 'Instructor Session Required', 'class' => 'info'];
    }

    if ($reviewStatus === 'acceptable') {
        return ['show' => true, 'label' => 'Accepted', 'class' => 'ok'];
    }

    if ($reviewStatus === 'pending') {
        return ['show' => true, 'label' => 'Pending', 'class' => 'pending'];
    }

    return ['show' => true, 'label' => 'Pending', 'class' => 'pending'];
}

function nb_summary_edit_allowed(array $lesson): bool
{
    $reviewStatus = (string)($lesson['review_status'] ?? '');
    return nb_has_summary($lesson) || in_array($reviewStatus, ['needs_revision', 'rejected'], true);
}

function nb_action_button_meta(array $lesson): ?array
{
    $reviewStatus = (string)($lesson['review_status'] ?? '');
    $hasSummary = nb_has_summary($lesson);
    $canEdit = nb_summary_edit_allowed($lesson);

    if ($reviewStatus === 'acceptable' && $hasSummary) {
        return ['label' => 'Edit Summary', 'action' => 'unlock', 'class' => 'warn'];
    }

    if (in_array($reviewStatus, ['needs_revision', 'rejected'], true)) {
        return ['label' => 'Open Summary', 'action' => 'edit', 'class' => 'warn'];
    }

    if ($canEdit) {
        return ['label' => 'Edit', 'action' => 'edit', 'class' => 'ghost'];
    }

    return null;
}

$initialExportVersion = gmdate('Y.m.d.Hi');
$programTitle = trim((string)($data['cohort']['program_name'] ?? ''));
if ($programTitle === '') {
    $programTitle = trim((string)$data['cohort']['course_title']);
}

$serverRenderUtc = gmdate('Y-m-d H:i:s');

cw_header('My Lesson Summaries');
?>
<style>
.nb-shell{
  max-width:1120px;
  margin:0 auto;
}

.nb-banner{
  display:none;
  position:sticky;
  top:16px;
  z-index:60;
  padding:13px 15px;
  border-radius:14px;
  margin-bottom:18px;
  font-size:13px;
  font-weight:800;
  border:1px solid transparent;
  box-shadow:0 10px 28px rgba(15,23,42,0.08);
}
.nb-banner.ok{background:#f0fdf4;color:#166534;border-color:#86efac}
.nb-banner.warn{background:#fffbeb;color:#92400e;border-color:#fcd34d}
.nb-banner.danger{background:#fef2f2;color:#991b1b;border-color:#fca5a5}

.nb-doc{
  background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
  border:1px solid rgba(15,23,42,0.06);
  border-radius:24px;
  padding:34px 36px 38px 36px;
  box-shadow:0 12px 34px rgba(15,23,42,0.055);
}

.nb-scope-row{
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:14px;
  margin-bottom:22px;
}

.nb-scope-field{
  display:flex;
  flex-direction:column;
  gap:8px;
  min-width:310px;
}

.nb-scope-label{
  font-size:10px;
  text-transform:uppercase;
  letter-spacing:.12em;
  color:#64748b;
  font-weight:800;
}

.nb-scope-select{
  padding:10px 12px;
  min-width:310px;
  border-radius:12px;
  border:1px solid rgba(15,23,42,0.10);
  background:#fff;
  font-weight:700;
  color:#152235;
  box-shadow:0 2px 8px rgba(15,23,42,0.03);
}

.nb-btn{
  padding:9px 14px;
  border-radius:11px;
  border:none;
  cursor:pointer;
  font-weight:800;
  font-size:13px;
  letter-spacing:0.01em;
  transition:transform .06s ease, box-shadow .12s ease, opacity .12s ease;
}
.nb-btn:hover{opacity:.97}
.nb-btn:active{transform:translateY(1px)}
.nb-btn.primary{background:#12355f;color:#fff;box-shadow:0 10px 22px rgba(18,53,95,0.18)}
.nb-btn.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;box-shadow:0 4px 12px rgba(154,52,18,0.08)}
.nb-btn.ghost{background:#fff;border:1px solid rgba(15,23,42,0.10);color:#152235}
.nb-btn.ghost:hover{border-color:rgba(18,53,95,0.22);box-shadow:0 6px 14px rgba(15,23,42,0.05)}

.nb-header{
  padding:2px 0 8px 0;
  border-bottom:1px solid rgba(15,23,42,0.06);
}

.nb-overline{
  font-size:11px;
  font-weight:800;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:#718198;
  margin-bottom:10px;
}

.nb-title{
  margin:0;
  font-size:38px;
  line-height:1.02;
  font-weight:800;
  letter-spacing:-0.04em;
  color:#152235;
}

.nb-sub{
  margin-top:12px;
  max-width:760px;
  color:#5f7088;
  font-size:15px;
  line-height:1.6;
}

.nb-meta{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px;
  margin:22px 0 8px 0;
}

.nb-meta-box{
  background:#f8fafc;
  border:1px solid rgba(15,23,42,0.05);
  border-radius:16px;
  padding:13px 14px;
}

.nb-meta-label{
  font-size:10px;
  text-transform:uppercase;
  color:#64748b;
  font-weight:800;
  letter-spacing:.12em;
}

.nb-meta-value{
  font-size:14px;
  font-weight:800;
  margin-top:7px;
  color:#152235;
  line-height:1.35;
}

.nb-export-note{
  margin-top:14px;
  font-size:12px;
  color:#74849a;
  line-height:1.5;
}

.nb-print-only{display:none}

.nb-toc{
  margin:28px 0 0 0;
  padding:24px 0 24px 0;
  border-top:1px solid rgba(15,23,42,0.05);
  border-bottom:1px solid rgba(15,23,42,0.07);
}

.nb-toc-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:12px;
  margin-bottom:14px;
}

.nb-toc-title{
  margin:0;
  font-size:22px;
  line-height:1.08;
  font-weight:800;
  letter-spacing:-0.02em;
  color:#152235;
}

.nb-toc-sub{
  font-size:13px;
  color:#64748b;
  line-height:1.45;
}

.nb-toc ol{
  list-style:none;
  margin:0;
  padding:0;
}
.nb-toc li{
  list-style:none;
  margin:0;
  padding:0;
}
.nb-toc li::marker,
.nb-toc ol li::marker{
  content:'';
  font-size:0;
}

.nb-toc-root{
  display:flex;
  flex-direction:column;
  gap:12px;
}

.nb-toc-item-course{
  padding:10px 0 0 0;
}

.nb-toc-course-row .nb-toc-link{
  font-size:15px;
  font-weight:800;
  color:#102845;
}

.nb-toc-lesson-list{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-top:9px;
  padding-left:20px;
}

.nb-toc-item-lesson .nb-toc-link{
  font-size:13px;
  font-weight:700;
  color:#12355f;
}

.nb-toc-row{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  min-width:0;
}

.nb-toc-left{
  min-width:0;
  flex:1 1 auto;
}

.nb-toc-right{
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:8px;
  flex-wrap:wrap;
  min-width:0;
}

.nb-toc-link{
  text-decoration:none;
  line-height:1.45;
  word-break:break-word;
}
.nb-toc-link:hover{
  text-decoration:underline;
}

.nb-course{
  margin-top:34px;
}
.nb-course:first-child{
  margin-top:26px;
}

.nb-course-title{
  margin:0;
  font-size:27px;
  line-height:1.08;
  font-weight:800;
  letter-spacing:-0.03em;
  color:#152235;
}

.nb-lesson{
  margin-top:22px;
  padding-top:18px;
  border-top:1px solid rgba(15,23,42,0.08);
}

.nb-lesson-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  min-width:0;
}

.nb-lesson-head-left{
  min-width:0;
  flex:1 1 auto;
}

.nb-lesson-head-right{
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:8px;
  flex-wrap:wrap;
  min-width:0;
}

.nb-lesson-title{
  font-size:19px;
  line-height:1.25;
  font-weight:800;
  color:#152235;
  margin:0;
  letter-spacing:-0.01em;
  word-break:break-word;
}

.nb-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:22px;
  padding:0 8px;
  border-radius:999px;
  font-size:10px;
  font-weight:800;
  letter-spacing:.02em;
  border:1px solid transparent;
  white-space:nowrap;
}
.nb-pill.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.nb-pill.ok{background:#dcfce7;color:#166534;border-color:#86efac}
.nb-pill.warn{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.nb-pill.info{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd}
.nb-pill.danger{background:#fecaca;color:#991b1b;border-color:#f87171}

.nb-meta-chip{
  display:inline-flex;
  align-items:center;
  min-height:22px;
  padding:0 8px;
  border-radius:999px;
  background:#f8fafc;
  border:1px solid rgba(15,23,42,0.08);
  color:#64748b;
  font-size:10px;
  font-weight:700;
  white-space:nowrap;
}

.nb-content{
  margin-top:14px;
  color:#233246;
  line-height:1.82;
  font-size:15px;
}

.nb-content p{margin:0 0 12px 0}
.nb-content ul,
.nb-content ol{margin:0 0 12px 22px}
.nb-content li{margin:0 0 6px 0}
.nb-content b,
.nb-content strong{color:#16263c}

.nb-divider{
  border-top:1px solid rgba(15,23,42,0.07);
  margin-top:14px;
}

.nb-action-bar{
  margin-top:14px;
}

.nb-action-panel{
  display:none;
  margin-top:14px;
  border:1px solid rgba(15,23,42,0.10);
  border-radius:18px;
  padding:16px;
  background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
  box-shadow:0 10px 24px rgba(15,23,42,0.04);
}
.nb-action-panel.open{display:block}

.nb-editor{
  min-height:180px;
  border:1px solid rgba(15,23,42,0.12);
  border-radius:14px;
  padding:14px;
  margin-top:2px;
  background:#fff;
  font-size:15px;
  line-height:1.75;
  color:#1f2937;
  box-shadow:inset 0 1px 2px rgba(15,23,42,0.02);
}

.nb-editor:focus{
  outline:none;
  border-color:#7aa3d8;
  box-shadow:0 0 0 4px rgba(29,79,145,0.08);
}

.nb-panel-actions{
  margin-top:12px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

.nb-panel-context{
  margin-top:16px;
  display:flex;
  flex-direction:column;
  gap:10px;
}

.nb-panel-box{
  border:1px solid rgba(15,23,42,0.08);
  border-radius:14px;
  padding:13px 14px;
  background:#f9fbfd;
}

.nb-panel-label{
  font-size:10px;
  text-transform:uppercase;
  color:#64748b;
  font-weight:800;
  letter-spacing:.12em;
}

.nb-panel-body{
  margin-top:6px;
  color:#243446;
  line-height:1.65;
  font-size:14px;
}

.nb-confirm{
  margin-top:18px;
  padding:14px;
  border-radius:16px;
  background:#f8fafc;
  border:1px solid rgba(15,23,42,0.10);
  display:none;
}

.nb-confirm-text{
  color:#243446;
  font-size:14px;
  font-weight:700;
  line-height:1.5;
}

.nb-confirm-actions{
  margin-top:10px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

@media (max-width:980px){
  .nb-doc{padding:26px 24px 30px 24px}
  .nb-meta{grid-template-columns:1fr 1fr}
}

@media (max-width:760px){
  .nb-shell{max-width:none}
  .nb-doc{padding:22px 18px 26px 18px;border-radius:18px}
  .nb-scope-row{flex-direction:column;align-items:stretch}
  .nb-scope-field{min-width:0;width:100%}
  .nb-scope-select{min-width:0;width:100%}
  .nb-meta{grid-template-columns:1fr}
  .nb-title{font-size:32px}
  .nb-course-title{font-size:24px}
  .nb-lesson-title{font-size:18px}
  .nb-content{font-size:15px;line-height:1.78}

  .nb-toc-row,
  .nb-lesson-head{
    flex-direction:column;
    align-items:flex-start;
  }

  .nb-toc-right,
  .nb-lesson-head-right{
    width:100%;
    justify-content:flex-start;
  }
}

@media print{
  .nb-action-bar,
  .nb-action-panel,
  .nb-scope-row,
  .nb-confirm,
  .nb-export-note,
  .nb-banner{
    display:none !important;
  }

  .nb-print-only{
    display:block !important;
  }

  .nb-doc{
    box-shadow:none;
    border:none;
    padding:0;
    background:#fff;
  }

  .nb-title{
    font-size:30px;
  }

  .nb-course{
    break-inside:avoid;
    page-break-inside:avoid;
  }

  .nb-lesson{
    break-inside:avoid;
    page-break-inside:avoid;
  }
}
</style>

<div class="nb-shell">
  <div id="nbBanner" class="nb-banner"></div>

  <div class="nb-doc">

    <div class="nb-scope-row">
      <div class="nb-scope-field">
        <label class="nb-scope-label" for="scopeSelect">Training Scope</label>
        <select class="nb-scope-select" id="scopeSelect" data-current-scope="<?= (int)$selectedCohortId ?>">
          <?php foreach ($scopes as $s): ?>
            <option value="<?= (int)$s['cohort_id'] ?>" <?= ((int)$s['cohort_id'] === $selectedCohortId ? 'selected' : '') ?>>
              <?= h((string)$s['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <button class="nb-btn primary" id="exportBtn">Export PDF</button>
    </div>

    <div class="nb-header">
      <div class="nb-overline">Student Training Notebook</div>
      <h1 class="nb-title"><?= h($programTitle) ?></h1>
      <div class="nb-sub">
        A structured summary document of your current lesson understanding, organized by course and lesson within your active training scope.
      </div>
    </div>

    <div class="nb-meta">
      <div class="nb-meta-box">
        <div class="nb-meta-label">Student</div>
        <div class="nb-meta-value" id="printStudentName"><?= h($studentName) ?></div>
      </div>
      <div class="nb-meta-box">
        <div class="nb-meta-label">Program</div>
        <div class="nb-meta-value" id="printProgramName"><?= h($programTitle) ?></div>
      </div>
      <div class="nb-meta-box">
        <div class="nb-meta-label">Export Version</div>
        <div class="nb-meta-value" id="exportVersion"><?= h($initialExportVersion) ?></div>
      </div>
      <div class="nb-meta-box">
        <div class="nb-meta-label">Last Saved</div>
        <div class="nb-meta-value"><?= h(nb_ui_date((string)$data['last_saved_at'])) ?></div>
      </div>
    </div>

    <div class="nb-print-only" style="margin-top:6px;margin-bottom:20px;font-size:13px;line-height:1.7;color:#334155;">
      <div><strong>Student:</strong> <span id="printStudentNameCopy"><?= h($studentName) ?></span></div>
      <div><strong>Program:</strong> <span id="printProgramNameCopy"><?= h($programTitle) ?></span></div>
      <div><strong>Export Version:</strong> <span id="printExportVersion"><?= h($initialExportVersion) ?></span></div>
      <div><strong>Export Timestamp:</strong> <span id="printExportTimestamp"><?= h(nb_ui_datetime($serverRenderUtc)) ?></span></div>
    </div>

    <div class="nb-export-note">
      Export currently uses clean browser print-to-PDF rendering from read mode only.
    </div>

    <div class="nb-toc">
      <div class="nb-toc-head">
        <div>
          <h2 class="nb-toc-title">Table of Contents</h2>
          <div class="nb-toc-sub">Jump directly to any course or lesson section in your notebook.</div>
        </div>
      </div>

      <ol class="nb-toc-root">
        <?php foreach ($data['courses'] as $course): ?>
          <li class="nb-toc-item nb-toc-item-course">
            <div class="nb-toc-row nb-toc-course-row">
              <div class="nb-toc-left">
                <a class="nb-toc-link" href="#<?= h((string)$course['anchor_id']) ?>">
                  <?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?>
                </a>
              </div>
              <div class="nb-toc-right"></div>
            </div>

            <ol class="nb-toc-lesson-list">
              <?php foreach ($course['lessons'] as $lesson): ?>
                <?php
                  $hasSummary = nb_has_summary($lesson);
                  $attention = nb_attention_meta($lesson);
                ?>
                <li class="nb-toc-item nb-toc-item-lesson" id="toc-lesson-<?= (int)$lesson['lesson_id'] ?>">
                  <div class="nb-toc-row">
                    <div class="nb-toc-left">
                      <a class="nb-toc-link" href="#<?= h((string)$lesson['anchor_id']) ?>">
                        <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
                      </a>
                    </div>

                    <div class="nb-toc-right">
                      <?php if ($attention['show']): ?>
                        <span class="nb-pill <?= h($attention['class']) ?>" data-role="toc-status-pill"><?= h($attention['label']) ?></span>
                      <?php endif; ?>

                      <?php if ($hasSummary): ?>
                        <span class="nb-meta-chip" data-role="toc-word-meta"><?= (int)$lesson['word_count'] ?> words</span>
                        <?php if ((int)$lesson['version_count'] > 0): ?>
                          <span class="nb-meta-chip" data-role="toc-version-meta"><?= (int)$lesson['version_count'] ?> versions</span>
                        <?php endif; ?>
                        <?php if (trim((string)$lesson['updated_at']) !== ''): ?>
                          <span class="nb-meta-chip" data-role="toc-date-meta"><?= h(nb_ui_date((string)$lesson['updated_at'])) ?></span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>

    <div class="nb-body">
      <?php foreach ($data['courses'] as $course): ?>
        <section class="nb-course" id="<?= h((string)$course['anchor_id']) ?>">
          <h2 class="nb-course-title">
            <?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?>
          </h2>

          <?php foreach ($course['lessons'] as $lesson): ?>
            <?php
              $lessonId = (int)$lesson['lesson_id'];
              $hasSummary = nb_has_summary($lesson);
              $attention = nb_attention_meta($lesson);
              $button = nb_action_button_meta($lesson);
              $reviewStatus = (string)$lesson['review_status'];
              $showActionPanelMeta = in_array($reviewStatus, ['needs_revision', 'rejected'], true);
              $versionCount = (int)($lesson['version_count'] ?? 0);
              $latestVersionAt = trim((string)($lesson['latest_version_at'] ?? ''));
            ?>
            <section
              class="nb-lesson"
              id="<?= h((string)$lesson['anchor_id']) ?>"
              data-lesson-id="<?= $lessonId ?>"
              data-review-status="<?= h($reviewStatus) ?>"
              data-has-summary="<?= $hasSummary ? '1' : '0' ?>"
            >
              <div class="nb-lesson-head">
                <div class="nb-lesson-head-left">
                  <h3 class="nb-lesson-title">
                    <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
                  </h3>
                </div>

                <div class="nb-lesson-head-right" data-role="lesson-meta">
                  <?php if ($attention['show']): ?>
                    <span class="nb-pill <?= h($attention['class']) ?>" data-role="status-pill"><?= h($attention['label']) ?></span>
                  <?php endif; ?>

                  <?php if ($hasSummary): ?>
                    <span class="nb-meta-chip" data-role="word-meta"><?= (int)$lesson['word_count'] ?> words</span>
                    <?php if ((int)$lesson['version_count'] > 0): ?>
                      <span class="nb-meta-chip" data-role="version-meta"><?= (int)$lesson['version_count'] ?> versions</span>
                    <?php endif; ?>
                    <?php if (trim((string)$lesson['updated_at']) !== ''): ?>
                      <span class="nb-meta-chip" data-role="date-meta"><?= h(nb_ui_date((string)$lesson['updated_at'])) ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($hasSummary): ?>
                <div class="nb-content" data-view><?= (string)$lesson['summary_html'] ?></div>
              <?php else: ?>
                <div class="nb-divider" data-view></div>
              <?php endif; ?>

              <div class="nb-action-bar" data-role="action-bar">
                <?php if ($button !== null): ?>
                  <button
                    class="nb-btn <?= h($button['class']) ?>"
                    data-action="<?= h($button['action']) ?>"
                    data-lesson="<?= $lessonId ?>"
                  >
                    <?= h($button['label']) ?>
                  </button>
                <?php endif; ?>
              </div>

              <div class="nb-action-panel" id="panel-<?= $lessonId ?>">
                <div class="nb-editor" contenteditable="true" id="editor-<?= $lessonId ?>"><?= (string)$lesson['summary_html'] ?></div>

                <div class="nb-panel-actions">
                  <button class="nb-btn primary" data-save-lesson="<?= $lessonId ?>">Save</button>
                  <button class="nb-btn ghost" data-close-lesson="<?= $lessonId ?>">Close</button>
                </div>

                <div class="nb-panel-context">
                  <?php if ($showActionPanelMeta && trim((string)$lesson['instructor_feedback']) !== ''): ?>
                    <div class="nb-panel-box">
                      <div class="nb-panel-label">Instructor Feedback</div>
                      <div class="nb-panel-body"><?= nl2br(h((string)$lesson['instructor_feedback'])) ?></div>
                    </div>
                  <?php endif; ?>

                  <?php if ($showActionPanelMeta && trim((string)$lesson['instructor_notes']) !== ''): ?>
                    <div class="nb-panel-box">
                      <div class="nb-panel-label">Instructor Notes</div>
                      <div class="nb-panel-body"><?= nl2br(h((string)$lesson['instructor_notes'])) ?></div>
                    </div>
                  <?php endif; ?>

                  <?php if ($versionCount > 0 || $latestVersionAt !== ''): ?>
                    <div class="nb-panel-box">
                      <div class="nb-panel-label">Version Context</div>
                      <div class="nb-panel-body">
                        <?php if ($versionCount > 0): ?>
                          <div><?= (int)$versionCount ?> saved version<?= $versionCount === 1 ? '' : 's' ?> preserved for your summary history.</div>
                        <?php endif; ?>
                        <?php if ($latestVersionAt !== ''): ?>
                          <div>Latest saved version: <?= h(nb_ui_date($latestVersionAt)) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </section>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>

    <div id="confirmBar" class="nb-confirm">
      <div id="confirmText" class="nb-confirm-text">You have unsaved changes.</div>
      <div class="nb-confirm-actions">
        <button class="nb-btn primary" id="confirmSaveBtn">Save</button>
        <button class="nb-btn ghost" id="confirmDiscardBtn">Discard</button>
        <button class="nb-btn ghost" id="confirmContinueBtn">Continue Editing</button>
      </div>
    </div>

  </div>
</div>

<script>
const COHORT_ID = <?= (int)$selectedCohortId ?>;
const SAVE_URL = '/student/api/summary_save.php';
const exportBtn = document.getElementById('exportBtn');
const exportVersionEl = document.getElementById('exportVersion');
const printExportVersionEl = document.getElementById('printExportVersion');
const printExportTimestampEl = document.getElementById('printExportTimestamp');
const scopeSelect = document.getElementById('scopeSelect');
const bannerEl = document.getElementById('nbBanner');

let activeLessonId = null;
let originalHtml = '';
let pendingAction = null;
let currentExportVersion = null;
let currentExportTimestamp = null;

const confirmBar = document.getElementById('confirmBar');
const confirmText = document.getElementById('confirmText');
const confirmSaveBtn = document.getElementById('confirmSaveBtn');
const confirmDiscardBtn = document.getElementById('confirmDiscardBtn');
const confirmContinueBtn = document.getElementById('confirmContinueBtn');

function editorEl(lessonId) {
  return document.getElementById('editor-' + lessonId);
}

function panelEl(lessonId) {
  return document.getElementById('panel-' + lessonId);
}

function lessonNode(lessonId) {
  return document.querySelector('[data-lesson-id="' + lessonId + '"]');
}

function tocNode(lessonId) {
  return document.getElementById('toc-lesson-' + lessonId);
}

function hasUnsavedChanges(lessonId) {
  const ed = editorEl(lessonId);
  if (!ed) return false;
  return ed.innerHTML !== originalHtml;
}

function showBanner(message, kind) {
  bannerEl.textContent = message;
  bannerEl.className = 'nb-banner ' + (kind || 'ok');
  bannerEl.style.display = 'block';
  clearTimeout(showBanner._t);
  showBanner._t = setTimeout(() => {
    bannerEl.style.display = 'none';
  }, 3600);
}

function closeConfirmBar() {
  confirmBar.style.display = 'none';
  pendingAction = null;
}

function showConfirmBar(message, action) {
  pendingAction = action;
  confirmText.textContent = message;
  confirmBar.style.display = 'block';
  confirmBar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function setStatusPillOnNode(node, label, klass, selector) {
  if (!node) return;
  const pill = node.querySelector(selector);
  if (!pill) return;
  pill.textContent = label;
  pill.className = 'nb-pill ' + klass;
}

function computeStatusDisplay(reviewStatus, attentionReason) {
  if (reviewStatus === 'acceptable') {
    return { label: 'Accepted', klass: 'ok' };
  }
  if (reviewStatus === 'needs_revision' || reviewStatus === 'rejected') {
    return { label: 'Student Action Required', klass: 'warn' };
  }
  if (attentionReason === 'training_suspended') {
    return { label: 'Training Paused', klass: 'danger' };
  }
  if (attentionReason === 'one_on_one_required') {
    return { label: 'Instructor Session Required', klass: 'info' };
  }
  return { label: 'Pending', klass: 'pending' };
}

function countWordsFromHtml(html) {
  const tmp = document.createElement('div');
  tmp.innerHTML = html || '';
  const txt = (tmp.textContent || tmp.innerText || '').trim();
  if (!txt) return 0;
  return txt.split(/\s+/).length;
}

function buildActionButton(reviewStatus, hasSummary) {
  if (reviewStatus === 'acceptable' && hasSummary) {
    return { label: 'Edit Summary', action: 'unlock', className: 'nb-btn warn' };
  }
  if (reviewStatus === 'needs_revision' || reviewStatus === 'rejected') {
    return { label: 'Open Summary', action: 'edit', className: 'nb-btn warn' };
  }
  if (hasSummary) {
    return { label: 'Edit', action: 'edit', className: 'nb-btn ghost' };
  }
  return null;
}

function renderActionButton(lessonId, reviewStatus, hasSummary) {
  const node = lessonNode(lessonId);
  if (!node) return;
  const actionBar = node.querySelector('[data-role="action-bar"]');
  if (!actionBar) return;

  const meta = buildActionButton(reviewStatus, hasSummary);
  actionBar.innerHTML = '';
  if (!meta) return;

  const btn = document.createElement('button');
  btn.className = meta.className;
  btn.setAttribute('data-action', meta.action);
  btn.setAttribute('data-lesson', String(lessonId));
  btn.textContent = meta.label;
  btn.addEventListener('click', handleActionButtonClick);
  actionBar.appendChild(btn);
}

function ensureMetaChip(text, role) {
  const span = document.createElement('span');
  span.className = 'nb-meta-chip';
  span.setAttribute('data-role', role);
  span.textContent = text;
  return span;
}

function clearMetaChipsKeepPill(container) {
  if (!container) return;
  Array.from(container.children).forEach(function (child) {
    if (child.getAttribute('data-role') === 'status-pill') return;
    container.removeChild(child);
  });
}

function clearTocMetaKeepPill(container) {
  if (!container) return;
  Array.from(container.children).forEach(function (child) {
    if (child.getAttribute('data-role') === 'toc-status-pill') return;
    container.removeChild(child);
  });
}

function formatUtcDateLabel(now) {
  const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const weekNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  return weekNames[now.getUTCDay()] + ', ' + monthNames[now.getUTCMonth()] + ' ' + now.getUTCDate() + ', ' + now.getUTCFullYear();
}

function updateLessonAndTocMetadata(lessonId, reviewStatus, html) {
  const node = lessonNode(lessonId);
  const toc = tocNode(lessonId);
  if (!node) return;

  const hasSummary = countWordsFromHtml(html) > 0;
  const wordCount = countWordsFromHtml(html);

  node.setAttribute('data-review-status', reviewStatus);
  node.setAttribute('data-has-summary', hasSummary ? '1' : '0');

  const attentionReason = '';
  const statusMeta = computeStatusDisplay(reviewStatus, attentionReason);

  setStatusPillOnNode(node, statusMeta.label, statusMeta.klass, '[data-role="status-pill"]');
  setStatusPillOnNode(toc, statusMeta.label, statusMeta.klass, '[data-role="toc-status-pill"]');

  const view = node.querySelector('[data-view]');
  if (view) {
    if (hasSummary) {
      view.className = 'nb-content';
      view.innerHTML = html;
    } else {
      view.className = 'nb-divider';
      view.innerHTML = '';
    }
  }

  const now = new Date();
  const dateLabel = formatUtcDateLabel(now);

  const lessonMeta = node.querySelector('[data-role="lesson-meta"]');
  if (lessonMeta) {
    clearMetaChipsKeepPill(lessonMeta);
    if (hasSummary) {
      lessonMeta.appendChild(ensureMetaChip(wordCount + ' words', 'word-meta'));

      const versionMeta = node.querySelector('[data-role="version-meta"]');
      if (versionMeta) {
        const current = parseInt((versionMeta.textContent || '0').replace(/\D+/g, ''), 10) || 0;
        lessonMeta.appendChild(ensureMetaChip((current + 1) + ' versions', 'version-meta'));
      } else {
        lessonMeta.appendChild(ensureMetaChip('1 versions', 'version-meta'));
      }

      lessonMeta.appendChild(ensureMetaChip(dateLabel, 'date-meta'));
    }
  }

  const tocRight = toc ? toc.querySelector('.nb-toc-right') : null;
  if (tocRight) {
    clearTocMetaKeepPill(tocRight);
    if (hasSummary) {
      tocRight.appendChild(ensureMetaChip(wordCount + ' words', 'toc-word-meta'));

      const existingVersion = toc.querySelector('[data-role="toc-version-meta"]');
      if (existingVersion) {
        const current = parseInt((existingVersion.textContent || '0').replace(/\D+/g, ''), 10) || 0;
        tocRight.appendChild(ensureMetaChip((current + 1) + ' versions', 'toc-version-meta'));
      } else {
        tocRight.appendChild(ensureMetaChip('1 versions', 'toc-version-meta'));
      }

      tocRight.appendChild(ensureMetaChip(dateLabel, 'toc-date-meta'));
    }
  }

  renderActionButton(lessonId, reviewStatus, hasSummary);
}

function openEditor(lessonId) {
  if (activeLessonId !== null && activeLessonId !== lessonId) {
    if (hasUnsavedChanges(activeLessonId)) {
      showConfirmBar('You have unsaved changes in another section.', { type: 'switch-editor', lessonId: lessonId });
      return;
    }
    closeEditor(activeLessonId, true);
  }

  const panel = panelEl(lessonId);
  const ed = editorEl(lessonId);
  if (!panel || !ed) return;

  panel.classList.add('open');
  activeLessonId = lessonId;
  originalHtml = ed.innerHTML;
  ed.focus();
}

function closeEditor(lessonId, silent) {
  if (activeLessonId === lessonId && hasUnsavedChanges(lessonId) && !silent) {
    showConfirmBar('You have unsaved changes in this section.', { type: 'close-editor', lessonId: lessonId });
    return;
  }

  const panel = panelEl(lessonId);
  if (panel) panel.classList.remove('open');

  if (activeLessonId === lessonId) {
    activeLessonId = null;
    originalHtml = '';
  }

  if (!silent) {
    closeConfirmBar();
  }
}

async function postSummary(payload) {
  const res = await fetch(SAVE_URL, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    credentials: 'same-origin',
    body: JSON.stringify(payload)
  });

  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    json = { ok: false, error: 'Invalid server response' };
  }
  return json;
}

async function unlockAcceptedSummary(lessonId) {
  const result = await postSummary({
    action: 'unlock',
    cohort_id: COHORT_ID,
    lesson_id: lessonId
  });

  if (!result.ok) {
    showBanner(result.error || 'Unlock failed.', 'danger');
    return false;
  }

  updateLessonAndTocMetadata(lessonId, 'pending', editorEl(lessonId) ? editorEl(lessonId).innerHTML : '');
  showBanner('Accepted summary reopened. It is now pending review.', 'ok');
  return true;
}

async function saveSummary(lessonId) {
  const ed = editorEl(lessonId);
  if (!ed) return false;

  const result = await postSummary({
    action: 'save',
    cohort_id: COHORT_ID,
    lesson_id: lessonId,
    summary_html: ed.innerHTML || ''
  });

  if (!result.ok) {
    showBanner(result.error || 'Save failed.', 'danger');
    return false;
  }

  originalHtml = ed.innerHTML || '';
  updateLessonAndTocMetadata(lessonId, 'pending', originalHtml);
  closeEditor(lessonId, true);
  showBanner(result.skipped ? 'No meaningful changes to save.' : 'Summary saved.', 'ok');

  if (pendingAction) {
    const action = pendingAction;
    closeConfirmBar();
    await runPendingAction(action);
  }

  return true;
}

async function runPendingAction(action) {
  if (!action) return;

  if (action.type === 'switch-editor') {
    openEditor(action.lessonId);
    return;
  }

  if (action.type === 'unlock-then-edit') {
    const ok = await unlockAcceptedSummary(action.lessonId);
    if (ok) {
      openEditor(action.lessonId);
    }
    return;
  }

  if (action.type === 'close-editor') {
    const panel = panelEl(action.lessonId);
    if (panel) panel.classList.remove('open');
    if (activeLessonId === action.lessonId) {
      activeLessonId = null;
      originalHtml = '';
    }
    return;
  }

  if (action.type === 'switch-scope') {
    window.location.href = '?cohort_id=' + encodeURIComponent(action.cohortId);
  }
}

confirmSaveBtn.addEventListener('click', async function () {
  if (activeLessonId !== null) {
    await saveSummary(activeLessonId);
  }
});

confirmDiscardBtn.addEventListener('click', async function () {
  if (activeLessonId !== null) {
    const ed = editorEl(activeLessonId);
    if (ed) ed.innerHTML = originalHtml;
  }

  const action = pendingAction;
  const lessonId = activeLessonId;

  closeConfirmBar();

  if (lessonId !== null) {
    const panel = panelEl(lessonId);
    if (panel) panel.classList.remove('open');
    activeLessonId = null;
    originalHtml = '';
  }

  await runPendingAction(action);
});

confirmContinueBtn.addEventListener('click', function () {
  if (scopeSelect && pendingAction && pendingAction.type === 'switch-scope') {
    scopeSelect.value = scopeSelect.getAttribute('data-current-scope');
  }
  closeConfirmBar();
});

async function handleActionButtonClick() {
  const lessonId = parseInt(this.getAttribute('data-lesson'), 10);
  const action = this.getAttribute('data-action');

  if (action === 'edit') {
    openEditor(lessonId);
    return;
  }

  if (action === 'unlock') {
    if (activeLessonId !== null && activeLessonId !== lessonId && hasUnsavedChanges(activeLessonId)) {
      showConfirmBar('You have unsaved changes in another section.', { type: 'unlock-then-edit', lessonId: lessonId });
      return;
    }

    const ok = await unlockAcceptedSummary(lessonId);
    if (ok) openEditor(lessonId);
  }
}

document.querySelectorAll('[data-action]').forEach(function (btn) {
  btn.addEventListener('click', handleActionButtonClick);
});

document.querySelectorAll('[data-save-lesson]').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    const lessonId = parseInt(btn.getAttribute('data-save-lesson'), 10);
    await saveSummary(lessonId);
  });
});

document.querySelectorAll('[data-close-lesson]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    const lessonId = parseInt(btn.getAttribute('data-close-lesson'), 10);
    closeEditor(lessonId, false);
  });
});

scopeSelect.addEventListener('change', function () {
  const targetCohortId = parseInt(this.value, 10);

  if (activeLessonId !== null && hasUnsavedChanges(activeLessonId)) {
    showConfirmBar('You have unsaved changes before switching notebook scope.', { type: 'switch-scope', cohortId: targetCohortId });
    this.value = this.getAttribute('data-current-scope');
    return;
  }

  window.location.href = '?cohort_id=' + encodeURIComponent(targetCohortId);
});

document.querySelectorAll('.nb-editor').forEach(function (ed) {
  ed.addEventListener('paste', function () {
    console.log('Deterrence triggered: paste attempt detected');
  });
  ed.addEventListener('cut', function () {
    console.log('Deterrence triggered: cut attempt detected');
  });
  ed.addEventListener('contextmenu', function () {
    console.log('Deterrence triggered: context menu attempt detected');
  });
});

function generateExportVersionOnce() {
  if (currentExportVersion !== null && currentExportTimestamp !== null) {
    return { version: currentExportVersion, timestamp: currentExportTimestamp };
  }

  const now = new Date();
  const y = now.getUTCFullYear();
  const m = String(now.getUTCMonth() + 1).padStart(2, '0');
  const d = String(now.getUTCDate()).padStart(2, '0');
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const ss = String(now.getUTCSeconds()).padStart(2, '0');

  currentExportVersion = y + '.' + m + '.' + d + '.' + hh + mm;
  currentExportTimestamp =
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][now.getUTCDay()] + ', ' +
    ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][now.getUTCMonth()] + ' ' +
    now.getUTCDate() + ', ' +
    now.getUTCFullYear() + ' ' +
    hh + ':' + mm + ':' + ss + ' UTC';

  return { version: currentExportVersion, timestamp: currentExportTimestamp };
}

exportBtn.addEventListener('click', function () {
  const exportMeta = generateExportVersionOnce();
  exportVersionEl.textContent = exportMeta.version;
  printExportVersionEl.textContent = exportMeta.version;
  printExportTimestampEl.textContent = exportMeta.timestamp;
  window.print();
});
</script>

<?php cw_footer(); ?>