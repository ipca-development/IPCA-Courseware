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

    if ($reviewStatus === 'acceptable') {
        return ['show' => true, 'label' => 'Accepted', 'class' => 'ok'];
    }

    if (in_array($reviewStatus, ['needs_revision', 'rejected'], true)) {
        return ['show' => true, 'label' => 'Student Action Required', 'class' => 'warn'];
    }

    if ($attentionReason === 'training_suspended') {
        return ['show' => true, 'label' => 'Training Paused', 'class' => 'danger'];
    }

    if ($attentionReason === 'one_on_one_required') {
        return ['show' => true, 'label' => 'Instructor Session Required', 'class' => 'info'];
    }

    if ($reviewStatus === 'pending') {
        return ['show' => true, 'label' => 'Draft Not Yet Checked', 'class' => 'pending'];
    }

    return ['show' => true, 'label' => 'Draft Not Yet Checked', 'class' => 'pending'];
}

function nb_action_button_meta(array $lesson): ?array
{
    $reviewStatus = (string)($lesson['review_status'] ?? '');
    $hasSummary = nb_has_summary($lesson);
    $isLocked = ((int)($lesson['student_soft_locked'] ?? 0) === 1);

    if ($reviewStatus === 'acceptable' && $hasSummary && $isLocked) {
        return ['label' => 'Unlock for Editing', 'action' => 'unlock_edit', 'class' => 'warn'];
    }

    if ($reviewStatus === 'acceptable' && $hasSummary && !$isLocked) {
        return ['label' => 'Continue Editing', 'action' => 'edit', 'class' => 'ghost'];
    }

    if (in_array($reviewStatus, ['needs_revision', 'rejected'], true)) {
        return ['label' => 'Continue Editing', 'action' => 'edit', 'class' => 'warn'];
    }

    if ($reviewStatus === 'pending' && $hasSummary) {
        return ['label' => 'Check my Summary', 'action' => 'check', 'class' => 'primary'];
    }

    if (!$hasSummary) {
        return ['label' => 'Write Summary', 'action' => 'edit', 'class' => 'ghost'];
    }

    return ['label' => 'Open Summary', 'action' => 'edit', 'class' => 'ghost'];
}

$initialExportVersion = gmdate('Y.m.d.Hi');
$programTitle = trim((string)($data['cohort']['program_name'] ?? ''));
if ($programTitle === '') {
    $programTitle = trim((string)$data['cohort']['course_title']);
}

$serverRenderUtc = gmdate('Y-m-d H:i:s');

cw_header('My Notebook');
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
  z-index:120;
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
  transition:transform .06s ease, box-shadow .12s ease, opacity .12s ease, background .12s ease;
}
.nb-btn:hover{opacity:.97}
.nb-btn:active{transform:translateY(1px)}
.nb-btn.primary{background:#12355f;color:#fff;box-shadow:0 10px 22px rgba(18,53,95,0.18)}
.nb-btn.warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;box-shadow:0 4px 12px rgba(154,52,18,0.08)}
.nb-btn.ghost{background:#fff;border:1px solid rgba(15,23,42,0.10);color:#152235}
.nb-btn.ghost:hover{border-color:rgba(18,53,95,0.22);box-shadow:0 6px 14px rgba(15,23,42,0.05)}
.nb-btn.tool{
  padding:7px 11px;
  border:1px solid rgba(15,23,42,0.10);
  background:#fff;
  color:#152235;
  min-width:40px;
  border-radius:10px;
  font-size:12px;
  box-shadow:none;
}
.nb-btn.tool:hover{
  border-color:rgba(18,53,95,0.22);
  background:#f8fafc;
}
.nb-btn.tool.active{
  background:#eaf2ff;
  border-color:#93c5fd;
  color:#12355f;
}
.nb-btn.tool.size-btn{
  min-width:auto;
  padding:7px 12px;
}
.nb-btn.tool.highlight-on{
  background:#fff8c5;
  border-color:#facc15;
  color:#713f12;
}

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
  padding:10px 0 18px 0;
}

.nb-toc-course-row{
  margin-bottom:16px;
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
  margin-top:0;
  margin-bottom:20px;
}

.nb-toc-item-lesson{
  margin-left:0;
}

.nb-toc-item-lesson .nb-toc-row{
  padding-left:60px;
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
.nb-content mark{
  background:#fff59d;
  color:inherit;
  padding:0 .1em;
  border-radius:3px;
}

.nb-divider{
  border-top:1px solid rgba(15,23,42,0.07);
  margin-top:14px;
}

.nb-action-bar{
  margin-top:14px;
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

/* Modal editor */
.nb-editor-modal{
  position:fixed;
  inset:0;
  display:none;
  align-items:center;
  justify-content:center;
  background:rgba(15,23,42,0.52);
  z-index:200;
  padding:20px;
}

.nb-editor-modal.open{
  display:flex;
}

.nb-editor-dialog{
  width:min(1160px, 96vw);
  height:min(88vh, 930px);
  background:#ffffff;
  border-radius:24px;
  border:1px solid rgba(15,23,42,0.08);
  box-shadow:0 24px 80px rgba(15,23,42,0.28);
  display:flex;
  flex-direction:column;
  overflow:hidden;
}

.nb-editor-top{
  padding:18px 20px 14px 20px;
  border-bottom:1px solid rgba(15,23,42,0.07);
  background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
}

.nb-editor-topline{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
}

.nb-editor-title-wrap{
  min-width:0;
  flex:1 1 auto;
}

.nb-editor-kicker{
  font-size:10px;
  text-transform:uppercase;
  letter-spacing:.13em;
  font-weight:800;
  color:#6b7b92;
  margin-bottom:7px;
}

.nb-editor-title{
  margin:0;
  font-size:24px;
  line-height:1.15;
  font-weight:800;
  letter-spacing:-0.03em;
  color:#13263f;
  word-break:break-word;
}

.nb-editor-subtitle{
  margin-top:7px;
  font-size:13px;
  line-height:1.5;
  color:#607086;
}

.nb-editor-top-actions{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  justify-content:flex-end;
}

.nb-editor-statusbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-top:14px;
}

.nb-editor-status-left{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.nb-editor-status-right{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.nb-editor-status-text{
  font-size:12px;
  font-weight:700;
  color:#66768d;
}

.nb-editor-body{
  flex:1 1 auto;
  min-height:0;
  height:0;
  display:grid;
  grid-template-columns:minmax(0,1fr) 300px;
  gap:0;
  overflow:hidden;
}

.nb-editor-main{
  min-width:0;
  min-height:0;
  display:flex;
  flex-direction:column;
  border-right:1px solid rgba(15,23,42,0.07);
  overflow:hidden;
}

.nb-editor-toolbar{
  padding:12px 16px;
  border-bottom:1px solid rgba(15,23,42,0.06);
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  background:#fcfdff;
}

.nb-editor-canvas{
  flex:1 1 auto;
  min-height:0;
  background:#f3f6fb;
  padding:18px;
  overflow:auto;
}

.nb-editor-paper{
  min-height:100%;
  background:#ffffff;
  border:1px solid rgba(15,23,42,0.08);
  border-radius:18px;
  box-shadow:0 12px 30px rgba(15,23,42,0.08);
  padding:24px 26px;
}

.nb-editor{
  min-height:420px;
  border:none;
  outline:none;
  background:#fff;
  font-size:15px;
  line-height:1.8;
  color:#1f2937;
  white-space:normal;
  word-break:break-word;
}
.nb-editor p{margin:0 0 12px 0}
.nb-editor ul,
.nb-editor ol{margin:0 0 12px 22px}
.nb-editor li{margin:0 0 6px 0}
.nb-editor mark{
  background:#fff59d;
  color:inherit;
  padding:0 .1em;
  border-radius:3px;
}
.nb-editor.size-sm{font-size:14px}
.nb-editor.size-md{font-size:15px}
.nb-editor.size-lg{font-size:17px}

.nb-editor.locked{
  opacity:.74;
  cursor:default;
}

.nb-editor-locked-overlay{
  display:none;
  position:sticky;
  top:0;
  z-index:2;
  margin-bottom:12px;
  padding:12px 14px;
  border-radius:14px;
  background:rgba(248,250,252,0.92);
  border:1px solid rgba(148,163,184,0.35);
  color:#475569;
  font-size:13px;
  font-weight:700;
  backdrop-filter:blur(6px);
}
.nb-editor-locked-overlay.show{
  display:block;
}

.nb-editor-side{
  min-width:0;
  min-height:0;
  background:#fbfcfe;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}

.nb-editor-side-scroll{
  padding:16px;
  min-height:0;
  overflow-y:auto;
  overflow-x:hidden;
}

.nb-side-card{
  border:1px solid rgba(15,23,42,0.08);
  background:#fff;
  border-radius:16px;
  padding:14px;
  margin-bottom:12px;
}

.nb-side-label{
  font-size:10px;
  text-transform:uppercase;
  color:#64748b;
  font-weight:800;
  letter-spacing:.12em;
}

.nb-side-body{
  margin-top:7px;
  color:#243446;
  font-size:14px;
  line-height:1.6;
}

.nb-side-empty{
  color:#7b8798;
}

.nb-editor-footer{
  padding:14px 18px;
  border-top:1px solid rgba(15,23,42,0.07);
  background:#ffffff;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}

.nb-editor-footer-left{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.nb-editor-footer-right{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.nb-hidden{
  display:none !important;
}
	
.nb-editor .size-sm { font-size:14px; }
.nb-editor .size-md { font-size:15px; }
.nb-editor .size-lg { font-size:17px; }	
	
body.nb-modal-open{
  overflow:hidden;
}	

@media (max-width:980px){
  .nb-doc{padding:26px 24px 30px 24px}
  .nb-meta{grid-template-columns:1fr 1fr}
  .nb-editor-body{grid-template-columns:1fr}
  .nb-editor-main{border-right:none;border-bottom:1px solid rgba(15,23,42,0.07)}
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

  .nb-toc-lesson-list{
    padding-left:28px;
  }

  .nb-editor-modal{
    padding:10px;
  }

  .nb-editor-dialog{
    width:100%;
    height:94vh;
    border-radius:18px;
  }

  .nb-editor-paper{
    padding:18px 16px;
  }
}

@media print{
  .nb-action-bar,
  .nb-scope-row,
  .nb-confirm,
  .nb-export-note,
  .nb-banner,
  .nb-editor-modal{
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

      <a
        class="nb-btn primary"
        id="exportBtn"
        href="/student/export_lesson_summaries_pdf.php?cohort_id=<?= (int)$selectedCohortId ?>"
        target="_blank"
        rel="noopener"
      >
        Export PDF
      </a>
    </div>

    <div class="nb-header">
      <div class="nb-overline">Student Training Notebook</div>
      <h1 class="nb-title">My Notebook</h1>
      <div class="nb-sub">
        <?= h($programTitle) ?> · A structured summary document of your current lesson understanding, organized by course and lesson within your active training scope.
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
      Export opens a dedicated PDF document for the currently selected training scope.
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
              $versionCount = (int)($lesson['version_count'] ?? 0);
              $latestVersionAt = trim((string)($lesson['latest_version_at'] ?? ''));
              $showFeedback = trim((string)$lesson['instructor_feedback']) !== '';
              $showNotes = trim((string)$lesson['instructor_notes']) !== '';
              $isLocked = ((int)($lesson['student_soft_locked'] ?? 0) === 1);
            ?>
            <section
              class="nb-lesson"
              id="<?= h((string)$lesson['anchor_id']) ?>"
              data-lesson-id="<?= $lessonId ?>"
              data-review-status="<?= h($reviewStatus) ?>"
              data-has-summary="<?= $hasSummary ? '1' : '0' ?>"
              data-summary-locked="<?= $isLocked ? '1' : '0' ?>"
              data-lesson-title="<?= h((string)$lesson['lesson_number'] . ' ' . (string)$lesson['lesson_title']) ?>"
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

              <div class="nb-hidden">
                <div id="editor-<?= $lessonId ?>" class="nb-editor<?= $isLocked ? ' locked' : '' ?> size-md" contenteditable="<?= $isLocked ? 'false' : 'true' ?>"><?= (string)$lesson['summary_html'] ?></div>
                <div id="feedback-<?= $lessonId ?>"><?= h((string)$lesson['instructor_feedback']) ?></div>
                <div id="notes-<?= $lessonId ?>"><?= h((string)$lesson['instructor_notes']) ?></div>
                <div id="versionctx-<?= $lessonId ?>">
                  <?php if ($versionCount > 0): ?>
                    <div><?= (int)$versionCount ?> saved version<?= $versionCount === 1 ? '' : 's' ?> preserved for your summary history.</div>
                  <?php endif; ?>
                  <?php if ($latestVersionAt !== ''): ?>
                    <div>Latest saved version: <?= h(nb_ui_date($latestVersionAt)) ?></div>
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
        <button class="nb-btn primary" id="confirmSaveBtn">Save Draft</button>
        <button class="nb-btn ghost" id="confirmDiscardBtn">Discard</button>
        <button class="nb-btn ghost" id="confirmContinueBtn">Continue Editing</button>
      </div>
    </div>

  </div>
</div>

<div id="editorModal" class="nb-editor-modal" aria-hidden="true">
  <div class="nb-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="modalLessonTitle">
    <div class="nb-editor-top">
      <div class="nb-editor-topline">
        <div class="nb-editor-title-wrap">
          <div class="nb-editor-kicker">Lesson Summary Editor</div>
          <h2 class="nb-editor-title" id="modalLessonTitle">Summary Editor</h2>
          <div class="nb-editor-subtitle" id="modalLessonSubtitle">Write, refine, and check your lesson summary.</div>
        </div>

        <div class="nb-editor-top-actions">
          <span id="modalStatusPill" class="nb-pill pending">Draft Not Yet Checked</span>
          <button type="button" class="nb-btn ghost" id="modalCloseTopBtn">Close</button>
        </div>
      </div>

      <div class="nb-editor-statusbar">
        <div class="nb-editor-status-left">
          <span class="nb-editor-status-text" id="modalSaveStatus">Ready</span>
          <span class="nb-meta-chip" id="modalWordCount">0 words</span>
          <span class="nb-meta-chip" id="modalLockState">Unlocked</span>
        </div>

        <div class="nb-editor-status-right">
          <span class="nb-editor-status-text" id="modalHintText">Use clear structure and your own wording.</span>
        </div>
      </div>
    </div>

    <div class="nb-editor-body">
      <div class="nb-editor-main">
        <div class="nb-editor-toolbar">
          <button type="button" class="nb-btn tool" data-modal-cmd="bold"><strong>B</strong></button>
          <button type="button" class="nb-btn tool" data-modal-cmd="italic"><em>I</em></button>
          <button type="button" class="nb-btn tool" data-modal-cmd="underline"><u>U</u></button>
          <button type="button" class="nb-btn tool" data-modal-cmd="insertUnorderedList">•</button>

          <button type="button" class="nb-btn tool size-btn" data-size="sm">Small</button>
          <button type="button" class="nb-btn tool size-btn active" data-size="md">Medium</button>
          <button type="button" class="nb-btn tool size-btn" data-size="lg">Large</button>

          <button type="button" class="nb-btn tool" id="modalHighlightBtn">Highlight</button>
          <button type="button" class="nb-btn tool" id="modalClearFormatBtn">Clear</button>
        </div>

        <div class="nb-editor-canvas">
          <div class="nb-editor-paper">
            <div id="modalLockedOverlay" class="nb-editor-locked-overlay">
              This summary is soft locked. Unlock it to make changes. If you edit it, you will need to check it again.
            </div>
            <div id="modalEditor" class="nb-editor size-md" contenteditable="true"></div>
          </div>
        </div>
      </div>

      <aside class="nb-editor-side">
        <div class="nb-editor-side-scroll">
          <div class="nb-side-card">
            <div class="nb-side-label">Review Feedback</div>
            <div class="nb-side-body" id="modalFeedbackBox"><span class="nb-side-empty">No review feedback yet.</span></div>
          </div>

          <div class="nb-side-card">
            <div class="nb-side-label">Instructor Notes</div>
            <div class="nb-side-body" id="modalNotesBox"><span class="nb-side-empty">No instructor notes.</span></div>
          </div>

          <div class="nb-side-card">
            <div class="nb-side-label">Version Context</div>
            <div class="nb-side-body" id="modalVersionContext"><span class="nb-side-empty">No version context available.</span></div>
          </div>

          <div class="nb-side-card">
            <div class="nb-side-label">Quick Tips</div>
            <div class="nb-side-body">
              <div>Use your own words.</div>
              <div>Keep key aircraft concepts accurate.</div>
              <div>Use headings and bullets where useful.</div>
              <div>Yellow highlight will also appear in PDF if your export renderer keeps HTML styling.</div>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <div class="nb-editor-footer">
      <div class="nb-editor-footer-left">
        <button type="button" class="nb-btn ghost" id="modalSaveBtn">Save Draft</button>
        <button type="button" class="nb-btn primary" id="modalCheckBtn">Check my Summary</button>
      </div>

      <div class="nb-editor-footer-right">
        <button type="button" class="nb-btn warn" id="modalUnlockBtn">Unlock for Editing</button>
        <button type="button" class="nb-btn ghost" id="modalCloseBtn">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
const COHORT_ID = <?= (int)$selectedCohortId ?>;
const SAVE_URL = '/student/api/summary_save.php';
const scopeSelect = document.getElementById('scopeSelect');
const bannerEl = document.getElementById('nbBanner');

let activeLessonId = null;
let originalHtml = '';
let pendingAction = null;

const confirmBar = document.getElementById('confirmBar');
const confirmText = document.getElementById('confirmText');
const confirmSaveBtn = document.getElementById('confirmSaveBtn');
const confirmDiscardBtn = document.getElementById('confirmDiscardBtn');
const confirmContinueBtn = document.getElementById('confirmContinueBtn');

const editorModal = document.getElementById('editorModal');
const modalEditor = document.getElementById('modalEditor');
const modalLessonTitle = document.getElementById('modalLessonTitle');
const modalLessonSubtitle = document.getElementById('modalLessonSubtitle');
const modalSaveStatus = document.getElementById('modalSaveStatus');
const modalWordCount = document.getElementById('modalWordCount');
const modalLockState = document.getElementById('modalLockState');
const modalHintText = document.getElementById('modalHintText');
const modalStatusPill = document.getElementById('modalStatusPill');
const modalFeedbackBox = document.getElementById('modalFeedbackBox');
const modalNotesBox = document.getElementById('modalNotesBox');
const modalVersionContext = document.getElementById('modalVersionContext');
const modalLockedOverlay = document.getElementById('modalLockedOverlay');
const modalUnlockBtn = document.getElementById('modalUnlockBtn');
const modalCheckBtn = document.getElementById('modalCheckBtn');
const modalSaveBtn = document.getElementById('modalSaveBtn');
const modalCloseBtn = document.getElementById('modalCloseBtn');
const modalCloseTopBtn = document.getElementById('modalCloseTopBtn');
const modalHighlightBtn = document.getElementById('modalHighlightBtn');
const modalClearFormatBtn = document.getElementById('modalClearFormatBtn');
const modalSizeButtons = Array.from(document.querySelectorAll('[data-size]'));
const modalCmdButtons = Array.from(document.querySelectorAll('[data-modal-cmd]'));

let modalCurrentSize = 'md';
let modalHighlightOn = false;
	
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

function lessonIsLocked(lessonId) {
  const node = lessonNode(lessonId);
  if (!node) return false;
  return node.getAttribute('data-summary-locked') === '1';
}

function setLessonLockedState(lessonId, locked) {
  const node = lessonNode(lessonId);

  if (node) {
    node.setAttribute('data-summary-locked', locked ? '1' : '0');
  }

  if (activeLessonId === lessonId) {
    modalEditor.setAttribute('contenteditable', locked ? 'false' : 'true');
    modalEditor.classList.toggle('locked', !!locked);
    modalLockedOverlay.classList.toggle('show', !!locked);
    modalUnlockBtn.classList.toggle('nb-hidden', !locked);
    modalCheckBtn.classList.toggle('nb-hidden', !!locked);
    modalLockState.textContent = locked ? 'Locked' : 'Unlocked';
    modalHintText.textContent = locked
      ? 'Unlock to edit. Re-check will be required after changes.'
      : 'Use clear structure and your own wording.';
  }
}

function hasUnsavedChanges(lessonId) {
  if (activeLessonId !== lessonId) return false;
  return modalEditor.innerHTML !== originalHtml;
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

function setModalStatusPill(label, klass) {
  modalStatusPill.textContent = label;
  modalStatusPill.className = 'nb-pill ' + klass;
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
  return { label: 'Draft Not Yet Checked', klass: 'pending' };
}

function countWordsFromHtml(html) {
  const tmp = document.createElement('div');
  tmp.innerHTML = html || '';
  const txt = (tmp.textContent || tmp.innerText || '').trim();
  if (!txt) return 0;
  return txt.split(/\s+/).length;
}

function buildActionButton(reviewStatus, hasSummary, isLocked) {
  if (reviewStatus === 'acceptable' && hasSummary && isLocked) {
    return { label: 'Unlock for Editing', action: 'unlock_edit', className: 'nb-btn warn' };
  }

  if (reviewStatus === 'acceptable' && hasSummary && !isLocked) {
    return { label: 'Continue Editing', action: 'edit', className: 'nb-btn ghost' };
  }

  if (reviewStatus === 'needs_revision' || reviewStatus === 'rejected') {
    return { label: 'Continue Editing', action: 'edit', className: 'nb-btn warn' };
  }

  if (reviewStatus === 'pending' && hasSummary) {
    return { label: 'Check my Summary', action: 'check', className: 'nb-btn primary' };
  }

  if (!hasSummary) {
    return { label: 'Write Summary', action: 'edit', className: 'nb-btn ghost' };
  }

  return { label: 'Open Summary', action: 'edit', className: 'nb-btn ghost' };
}
	
function renderActionButton(lessonId, reviewStatus, hasSummary, isLocked) {
  const node = lessonNode(lessonId);
  if (!node) return;

  const actionBar = node.querySelector('[data-role="action-bar"]');
  if (!actionBar) return;

  const meta = buildActionButton(reviewStatus, hasSummary, isLocked);
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

function setPanelFeedback(lessonId, feedback) {
  const value = String(feedback || '').trim();
  if (value === '') {
    modalFeedbackBox.innerHTML = '<span class="nb-side-empty">No review feedback yet.</span>';
    return;
  }
  modalFeedbackBox.innerHTML = escapeHtml(value).replace(/\n/g, '<br>');
}

function setPanelNotes(lessonId) {
  const src = document.getElementById('notes-' + lessonId);
  const value = src ? String(src.textContent || '').trim() : '';
  if (value === '') {
    modalNotesBox.innerHTML = '<span class="nb-side-empty">No instructor notes.</span>';
    return;
  }
  modalNotesBox.innerHTML = escapeHtml(value).replace(/\n/g, '<br>');
}

function setPanelVersionContext(lessonId) {
  const src = document.getElementById('versionctx-' + lessonId);
  const value = src ? String(src.innerHTML || '').trim() : '';
  if (value === '') {
    modalVersionContext.innerHTML = '<span class="nb-side-empty">No version context available.</span>';
    return;
  }
  modalVersionContext.innerHTML = value;
}

function syncHiddenEditorFromModal(lessonId) {
  const hidden = editorEl(lessonId);
  if (hidden) {
    hidden.innerHTML = modalEditor.innerHTML;
    hidden.className = modalEditor.className;
    hidden.setAttribute('contenteditable', lessonIsLocked(lessonId) ? 'false' : 'true');
  }
}

function syncModalFromHidden(lessonId) {
  const hidden = editorEl(lessonId);
  if (!hidden) return;

  modalEditor.innerHTML = hidden.innerHTML;
  modalCurrentSize = 'md';
  if (hidden.classList.contains('size-sm')) modalCurrentSize = 'sm';
  if (hidden.classList.contains('size-lg')) modalCurrentSize = 'lg';

  modalEditor.className = 'nb-editor size-' + modalCurrentSize;
  modalEditor.classList.toggle('locked', lessonIsLocked(lessonId));
  updateSizeButtons();
  updateModalWordCount();
}

function updateModalWordCount() {
  modalWordCount.textContent = countWordsFromHtml(modalEditor.innerHTML) + ' words';
}

function updateSizeButtons() {
  modalSizeButtons.forEach(function(btn){
    btn.classList.toggle('active', btn.getAttribute('data-size') === modalCurrentSize);
  });
}

function applyModalSize(size) {
  modalCurrentSize = size;

  const selection = window.getSelection();
  const hasSelection = selection && selection.rangeCount > 0 && !selection.isCollapsed;

  // If user selected text → apply ONLY to selection
  if (hasSelection) {
    const range = selection.getRangeAt(0);

    const span = document.createElement('span');
    span.className = 'size-' + size;

    try {
      range.surroundContents(span);
    } catch (e) {
      // fallback for complex selections
      const frag = range.extractContents();
      span.appendChild(frag);
      range.insertNode(span);
    }

    // move cursor after styled content
    selection.removeAllRanges();
  } else {
    // No selection → change default typing size
    modalEditor.classList.remove('size-sm', 'size-md', 'size-lg');
    modalEditor.classList.add('size-' + size);
  }

  syncHiddenEditorFromModal(activeLessonId);
  updateSizeButtons();
}

function updateModalToolbarState() {
  modalCmdButtons.forEach(function(btn){
    const cmd = btn.getAttribute('data-modal-cmd');
    let active = false;
    try {
      active = document.queryCommandState(cmd);
    } catch(e){}
    btn.classList.toggle('active', !!active);
  });
  modalHighlightBtn.classList.toggle('highlight-on', modalHighlightOn);
}

function updateModalChromeForLesson(lessonId) {
  const node = lessonNode(lessonId);
  if (!node) return;

  const lessonTitle = String(node.getAttribute('data-lesson-title') || 'Summary Editor');
  const reviewStatus = String(node.getAttribute('data-review-status') || 'pending');
  const statusMeta = computeStatusDisplay(reviewStatus, '');

  modalLessonTitle.textContent = lessonTitle;
  modalLessonSubtitle.textContent = 'Write, refine, and check your lesson summary.';
  modalSaveStatus.textContent = 'Ready';
  setModalStatusPill(statusMeta.label, statusMeta.klass);
  modalLockState.textContent = lessonIsLocked(lessonId) ? 'Locked' : 'Unlocked';
  modalHintText.textContent = lessonIsLocked(lessonId)
    ? 'Unlock to edit. Re-check will be required after changes.'
    : 'Use clear structure and your own wording.';
  modalLockedOverlay.classList.toggle('show', lessonIsLocked(lessonId));
  modalUnlockBtn.classList.toggle('nb-hidden', !lessonIsLocked(lessonId));
  modalCheckBtn.classList.toggle('nb-hidden', lessonIsLocked(lessonId));
}

function updateLessonAndTocMetadata(lessonId, reviewStatus, html, reviewFeedback, studentSoftLocked) {
  const node = lessonNode(lessonId);
  const toc = tocNode(lessonId);
  if (!node) return;

  const hasSummary = countWordsFromHtml(html) > 0;
  const wordCount = countWordsFromHtml(html);
  const attentionReason = '';

  node.setAttribute('data-review-status', reviewStatus);
  node.setAttribute('data-has-summary', hasSummary ? '1' : '0');

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

  const hidden = editorEl(lessonId);
  if (hidden) {
    hidden.innerHTML = html;
  }

  const feedbackHolder = document.getElementById('feedback-' + lessonId);
  if (feedbackHolder) {
    feedbackHolder.textContent = reviewFeedback || '';
  }

  const now = new Date();
  const dateLabel = formatUtcDateLabel(now);

  const lessonMeta = node.querySelector('[data-role="lesson-meta"]');
  if (lessonMeta) {
    const existingVersionChip = lessonMeta.querySelector('[data-role="version-meta"]');
    const existingVersionCount = existingVersionChip
      ? (parseInt((existingVersionChip.textContent || '0').replace(/\D+/g, ''), 10) || 0)
      : 0;

    clearMetaChipsKeepPill(lessonMeta);

    if (hasSummary) {
      lessonMeta.appendChild(ensureMetaChip(wordCount + ' words', 'word-meta'));
      if (existingVersionCount > 0) {
        lessonMeta.appendChild(ensureMetaChip(existingVersionCount + ' versions', 'version-meta'));
      }
      lessonMeta.appendChild(ensureMetaChip(dateLabel, 'date-meta'));
    }
  }

  const tocRight = toc ? toc.querySelector('.nb-toc-right') : null;
  if (tocRight) {
    const existingVersionChip = tocRight.querySelector('[data-role="toc-version-meta"]');
    const existingVersionCount = existingVersionChip
      ? (parseInt((existingVersionChip.textContent || '0').replace(/\D+/g, ''), 10) || 0)
      : 0;

    clearTocMetaKeepPill(tocRight);

    if (hasSummary) {
      tocRight.appendChild(ensureMetaChip(wordCount + ' words', 'toc-word-meta'));
      if (existingVersionCount > 0) {
        tocRight.appendChild(ensureMetaChip(existingVersionCount + ' versions', 'toc-version-meta'));
      }
      tocRight.appendChild(ensureMetaChip(dateLabel, 'toc-date-meta'));
    }
  }

  const locked = Number(studentSoftLocked || 0) === 1;

  if (node) {
    node.setAttribute('data-summary-locked', locked ? '1' : '0');
  }

  setLessonLockedState(lessonId, locked);
  renderActionButton(lessonId, reviewStatus, hasSummary, locked);

  if (activeLessonId === lessonId) {
    setPanelFeedback(lessonId, reviewFeedback || '');
    setPanelNotes(lessonId);
    setPanelVersionContext(lessonId);
    syncModalFromHidden(lessonId);
    updateModalChromeForLesson(lessonId);
  }
}

function openEditor(lessonId) {
  if (activeLessonId !== null && activeLessonId !== lessonId) {
    if (hasUnsavedChanges(activeLessonId)) {
      showConfirmBar('You have unsaved changes in another section.', { type: 'switch-editor', lessonId: lessonId });
      return;
    }
    closeEditor(activeLessonId, true);
  }

  const hidden = editorEl(lessonId);
  if (!hidden) return;

  activeLessonId = lessonId;
  syncModalFromHidden(lessonId);
  originalHtml = modalEditor.innerHTML;
  setPanelFeedback(lessonId, (document.getElementById('feedback-' + lessonId) || {}).textContent || '');
  setPanelNotes(lessonId);
  setPanelVersionContext(lessonId);
  updateModalChromeForLesson(lessonId);
  updateModalToolbarState();
  editorModal.classList.add('open');
  editorModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('nb-modal-open');	

  if (!lessonIsLocked(lessonId)) {
    setTimeout(function(){ modalEditor.focus(); }, 60);
  }
}

function closeEditor(lessonId, silent) {
  if (activeLessonId === lessonId && hasUnsavedChanges(lessonId) && !silent) {
    showConfirmBar('You have unsaved changes in this section.', { type: 'close-editor', lessonId: lessonId });
    return;
  }

  editorModal.classList.remove('open');
  editorModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('nb-modal-open');	

  if (activeLessonId === lessonId) {
    syncHiddenEditorFromModal(lessonId);
    activeLessonId = null;
    originalHtml = '';
    modalSaveStatus.textContent = 'Ready';
  }

  if (!silent) {
    closeConfirmBar();
  }
}

async function postSummary(payload) {
  const res = await fetch(SAVE_URL, {
    method: 'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify(payload)
  });

  const raw = await res.text();
  try {
    return JSON.parse(raw);
  } catch (e) {
    console.error('Invalid summary API response:', raw);
    return { ok: false, error: 'Invalid server response' };
  }
}

async function unlockAcceptedSummary(lessonId) {
  const ok = window.confirm(
    'You are about to unlock your summary for editing.\n'
    + 'If you make changes, you will have to check your summary again.\n'
    + 'Are you sure?'
  );

  if (!ok) {
    return false;
  }

  const result = await postSummary({
    action: 'unlock',
    cohort_id: COHORT_ID,
    lesson_id: lessonId
  });

  if (!result.ok) {
    showBanner(result.error || 'Unable to unlock summary.', 'danger');
    return false;
  }

  setLessonLockedState(lessonId, false);
  updateLessonAndTocMetadata(
    lessonId,
    String(result.review_status || 'acceptable'),
    activeLessonId === lessonId ? modalEditor.innerHTML : (editorEl(lessonId) ? editorEl(lessonId).innerHTML : ''),
    '',
    Number(result.student_soft_locked || 0)
  );

  originalHtml = activeLessonId === lessonId ? modalEditor.innerHTML : originalHtml;
  modalSaveStatus.textContent = 'Unlocked';
  showBanner('Summary unlocked. If you make changes, you will have to check your summary again.', 'ok');
  return true;
}

async function saveSummary(lessonId) {
  if (activeLessonId !== lessonId) return false;

  if (lessonIsLocked(lessonId)) {
    showBanner('Accepted summaries must be reopened before editing.', 'warn');
    return false;
  }

  modalSaveStatus.textContent = 'Saving draft...';
  syncHiddenEditorFromModal(lessonId);

  const result = await postSummary({
    action: 'save',
    cohort_id: COHORT_ID,
    lesson_id: lessonId,
    summary_html: modalEditor.innerHTML || ''
  });

  if (!result.ok) {
    modalSaveStatus.textContent = 'Save failed';
    showBanner(result.error || 'Save failed.', 'danger');
    return false;
  }

  originalHtml = modalEditor.innerHTML || '';
  updateLessonAndTocMetadata(
    lessonId,
    String(result.review_status || 'pending'),
    originalHtml,
    '',
    Number(result.student_soft_locked || 0)
  );

  modalSaveStatus.textContent = result.skipped ? 'Draft unchanged' : 'Draft saved';
  showBanner(result.skipped ? 'Draft unchanged.' : 'Draft saved.', 'ok');

  if (pendingAction) {
    const action = pendingAction;
    closeConfirmBar();
    await runPendingAction(action);
  }

  return true;
}

async function checkSummary(lessonId) {
  if (activeLessonId !== lessonId) return false;

  if (!lessonIsLocked(lessonId) && hasUnsavedChanges(lessonId)) {
    const saved = await saveSummary(lessonId);
    if (!saved) {
      return false;
    }
  }

  modalSaveStatus.textContent = 'Checking summary...';

  const result = await postSummary({
    action: 'check',
    cohort_id: COHORT_ID,
    lesson_id: lessonId
  });

  if (!result.ok) {
    modalSaveStatus.textContent = 'Check failed';
    showBanner(result.error || 'Summary check failed.', 'danger');
    return false;
  }

  const currentHtml = modalEditor.innerHTML || '';
  updateLessonAndTocMetadata(
    lessonId,
    String(result.review_status || 'pending'),
    currentHtml,
    String(result.review_feedback || ''),
    Number(result.student_soft_locked || 0)
  );

  originalHtml = currentHtml;
  modalSaveStatus.textContent = String(result.review_status || '') === 'acceptable' ? 'Accepted' : 'Needs revision';

  if (String(result.review_status || '') === 'acceptable') {
    closeEditor(lessonId, true);
    showBanner('Accepted: Edit via Notebook if needed.', 'ok');
  } else {
    openEditor(lessonId);
    showBanner('Not accepted: Keep working on it and check again.', 'warn');
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

  if (action.type === 'check-after-save') {
    await checkSummary(action.lessonId);
    return;
  }

  if (action.type === 'close-editor') {
    editorModal.classList.remove('open');
    editorModal.setAttribute('aria-hidden', 'true');
    if (activeLessonId === action.lessonId) {
      syncHiddenEditorFromModal(action.lessonId);
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
    modalEditor.innerHTML = originalHtml;
    syncHiddenEditorFromModal(activeLessonId);
    updateModalWordCount();
  }

  const action = pendingAction;
  const lessonId = activeLessonId;

  closeConfirmBar();

if (lessonId !== null) {
  editorModal.classList.remove('open');
  editorModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('nb-modal-open');
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

  if (action === 'unlock_edit') {
    if (activeLessonId !== null && activeLessonId !== lessonId && hasUnsavedChanges(activeLessonId)) {
      showConfirmBar('You have unsaved changes in another section.', { type: 'unlock-then-edit', lessonId: lessonId });
      return;
    }

    const ok = await unlockAcceptedSummary(lessonId);
    if (ok) openEditor(lessonId);
    return;
  }

  if (action === 'check') {
    if (activeLessonId !== null && activeLessonId !== lessonId && hasUnsavedChanges(activeLessonId)) {
      showConfirmBar('You have unsaved changes in another section.', { type: 'switch-editor', lessonId: lessonId });
      return;
    }

    openEditor(lessonId);
    await checkSummary(lessonId);
  }
}

document.querySelectorAll('[data-action]').forEach(function (btn) {
  btn.addEventListener('click', handleActionButtonClick);
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

modalSaveBtn.addEventListener('click', async function () {
  if (activeLessonId !== null) {
    await saveSummary(activeLessonId);
  }
});

modalCheckBtn.addEventListener('click', async function () {
  if (activeLessonId !== null) {
    await checkSummary(activeLessonId);
  }
});

modalUnlockBtn.addEventListener('click', async function () {
  if (activeLessonId !== null) {
    await unlockAcceptedSummary(activeLessonId);
  }
});

modalCloseBtn.addEventListener('click', function () {
  if (activeLessonId !== null) {
    closeEditor(activeLessonId, false);
  }
});

modalCloseTopBtn.addEventListener('click', function () {
  if (activeLessonId !== null) {
    closeEditor(activeLessonId, false);
  }
});

editorModal.addEventListener('click', function(e){
  if (e.target === editorModal && activeLessonId !== null) {
    closeEditor(activeLessonId, false);
  }
});

document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' && editorModal.classList.contains('open') && activeLessonId !== null) {
    closeEditor(activeLessonId, false);
  }
});

modalCmdButtons.forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (activeLessonId === null || lessonIsLocked(activeLessonId)) return;

    const cmd = btn.getAttribute('data-modal-cmd');
    modalEditor.focus();
    document.execCommand(cmd, false, null);
    syncHiddenEditorFromModal(activeLessonId);
    updateModalToolbarState();
    updateModalWordCount();
  });
});

modalSizeButtons.forEach(function(btn){
  btn.addEventListener('click', function(){
    if (activeLessonId === null) return;
    applyModalSize(btn.getAttribute('data-size'));
    modalEditor.focus();
  });
});

modalHighlightBtn.addEventListener('click', function(){
  if (activeLessonId === null || lessonIsLocked(activeLessonId)) return;

  modalEditor.focus();

  try {
    if (modalHighlightOn) {
      document.execCommand('hiliteColor', false, 'transparent');
      modalHighlightOn = false;
    } else {
      document.execCommand('hiliteColor', false, '#fff59d');
      modalHighlightOn = true;
    }
  } catch(e) {}

  syncHiddenEditorFromModal(activeLessonId);
  updateModalToolbarState();
});

modalClearFormatBtn.addEventListener('click', function(){
  if (activeLessonId === null || lessonIsLocked(activeLessonId)) return;

  modalEditor.focus();
  try {
    document.execCommand('removeFormat', false, null);
  } catch(e) {}

  syncHiddenEditorFromModal(activeLessonId);
  updateModalToolbarState();
  updateModalWordCount();
});

modalEditor.addEventListener('input', function () {
  if (activeLessonId === null) return;
  if (lessonIsLocked(activeLessonId)) return;

  syncHiddenEditorFromModal(activeLessonId);
  updateModalWordCount();
  updateModalToolbarState();
  modalSaveStatus.textContent = 'Unsaved changes';
});

modalEditor.addEventListener('keyup', updateModalToolbarState);
modalEditor.addEventListener('mouseup', updateModalToolbarState);

modalEditor.addEventListener('paste', function () {
  console.log('Deterrence triggered: paste attempt detected');
});
modalEditor.addEventListener('cut', function () {
  console.log('Deterrence triggered: cut attempt detected');
});
modalEditor.addEventListener('contextmenu', function () {
  console.log('Deterrence triggered: context menu attempt detected');
});

modalEditor.addEventListener('keydown', function(e) {
  if (activeLessonId === null) return;
  if (lessonIsLocked(activeLessonId)) return;

  const isMod = e.metaKey || e.ctrlKey;
  const key = String(e.key || '').toLowerCase();

  if (isMod && key === 'b') {
    e.preventDefault();
    document.execCommand('bold', false, null);
    syncHiddenEditorFromModal(activeLessonId);
    updateModalToolbarState();
    updateModalWordCount();
    modalSaveStatus.textContent = 'Unsaved changes';
    return;
  }

  if (isMod && key === 'i') {
    e.preventDefault();
    document.execCommand('italic', false, null);
    syncHiddenEditorFromModal(activeLessonId);
    updateModalToolbarState();
    updateModalWordCount();
    modalSaveStatus.textContent = 'Unsaved changes';
    return;
  }

  if (isMod && key === 'u') {
    e.preventDefault();
    document.execCommand('underline', false, null);
    syncHiddenEditorFromModal(activeLessonId);
    updateModalToolbarState();
    updateModalWordCount();
    modalSaveStatus.textContent = 'Unsaved changes';
    return;
  }

  if (e.key === 'Tab') {
    e.preventDefault();

    try {
      if (e.shiftKey) {
        document.execCommand('outdent', false, null);
      } else {
        document.execCommand('indent', false, null);
      }
    } catch(err) {}

    syncHiddenEditorFromModal(activeLessonId);
    updateModalToolbarState();
    updateModalWordCount();
    modalSaveStatus.textContent = 'Unsaved changes';
    return;
  }
});	
	
	
function escapeHtml(s) {
  return String(s || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}
</script>

<?php cw_footer(); ?>	