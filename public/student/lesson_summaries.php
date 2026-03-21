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

$cohortId = (int)($_GET['cohort_id'] ?? 0);
if ($cohortId <= 0) {
    exit('Missing cohort_id');
}

$service = new LessonSummaryService($pdo);
$data = $service->getNotebookViewData($userId, $cohortId, $studentName);

function nb_ui_date(?string $value): string
{
    $value = trim((string)$value);
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

$programTitle = trim((string)($data['cohort']['program_name'] ?? ''));
if ($programTitle === '') {
    $programTitle = trim((string)($data['cohort']['course_title'] ?? 'Training Notebook'));
}

$programNumber = trim((string)($data['program_number'] ?? '1'));

cw_header('My Lesson Summaries');
?>
<style>
.nb-shell{max-width:1120px;margin:0 auto}
.nb-banner{display:none;position:sticky;top:14px;z-index:40;padding:12px 14px;border-radius:14px;border:1px solid #93c5fd;background:#eff6ff;color:#1d4ed8;font-size:13px;font-weight:700;box-shadow:0 10px 24px rgba(15,23,42,0.08);margin-bottom:16px}
.nb-banner.warn{border-color:#fcd34d;background:#fffbeb;color:#92400e}
.nb-banner.danger{border-color:#fca5a5;background:#fef2f2;color:#991b1b}
.nb-banner.ok{border-color:#86efac;background:#f0fdf4;color:#166534}

.nb-doc{background:#fff;border:1px solid rgba(15,23,42,0.06);border-radius:22px;box-shadow:0 10px 24px rgba(15,23,42,0.055);padding:34px 36px 40px 36px}
.nb-head{padding-bottom:24px;border-bottom:1px solid rgba(15,23,42,0.06)}
.nb-overline{font-size:11px;line-height:1;text-transform:uppercase;letter-spacing:.14em;color:#63758f;font-weight:800;margin-bottom:12px}
.nb-program-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.nb-title{margin:0;font-size:34px;line-height:1.02;color:#152235;letter-spacing:-0.04em;font-weight:800}
.nb-sub{margin-top:12px;font-size:15px;color:#5c6e86;line-height:1.6;max-width:780px}
.nb-meta{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:16px;margin-top:22px}
.nb-meta-box{padding:16px 18px;border-radius:16px;background:#f7fafe;border:1px solid rgba(29,79,145,0.08)}
.nb-meta-label{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:#6a7c95;font-weight:800;margin-bottom:8px}
.nb-meta-value{font-size:16px;font-weight:800;color:#152235;line-height:1.35}

.nb-selector{display:flex;align-items:center;gap:8px}
.nb-selector-label{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#6a7c95;font-weight:800}
.nb-selector-pill{display:inline-flex;align-items:center;min-height:38px;padding:0 12px;border-radius:999px;border:1px solid rgba(15,23,42,0.08);background:#f7fafe;color:#152235;font-size:13px;font-weight:800}

.nb-toc-wrap{padding:26px 0 24px 0;border-bottom:1px solid rgba(15,23,42,0.06)}
.nb-toc-title{margin:0 0 14px 0;font-size:18px;font-weight:800;color:#152235}
.nb-toc-list,.nb-toc-sublist{margin:0;padding-left:22px}
.nb-toc-list{display:flex;flex-direction:column;gap:10px}
.nb-toc-sublist{margin-top:8px;display:flex;flex-direction:column;gap:6px}
.nb-toc-link{color:#12355f;text-decoration:none;font-weight:800}
.nb-toc-link:hover{text-decoration:underline}
.nb-toc-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.nb-toc-main{min-width:0}
.nb-toc-meta{display:flex;flex-wrap:wrap;gap:6px 8px;justify-content:flex-end}
.nb-mini-pill{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;border:1px solid transparent;white-space:nowrap}
.nb-mini-pill.neutral{background:#eef2f7;border-color:#d7dee8;color:#475569}
.nb-mini-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}
.nb-mini-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
.nb-mini-pill.danger{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.nb-mini-pill.info{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}

.nb-body{padding-top:28px}
.nb-program-heading{margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:#687b94;font-weight:800}
.nb-course-section + .nb-course-section{margin-top:36px}
.nb-course-title{margin:0;font-size:28px;line-height:1.08;color:#152235;letter-spacing:-0.03em;font-weight:800}
.nb-course-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#687b94;font-weight:800;margin-bottom:10px}

.nb-lesson-section{margin-top:22px;padding-top:18px;border-top:1px solid rgba(15,23,42,0.06)}
.nb-lesson-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.nb-lesson-title{margin:0;font-size:21px;line-height:1.15;color:#152235;letter-spacing:-0.02em;font-weight:800}
.nb-lesson-meta{display:flex;flex-wrap:wrap;gap:8px 10px;margin-top:10px}
.nb-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid transparent;white-space:nowrap}
.nb-pill.neutral{background:#eef2f7;border-color:#d7dee8;color:#475569}
.nb-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}
.nb-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
.nb-pill.danger{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.nb-pill.info{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}

.nb-action-row{display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap}
.nb-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border-radius:12px;text-decoration:none;border:1px solid rgba(15,23,42,0.08);background:#f5f8fc;color:#152235;font-size:13px;font-weight:800;cursor:pointer}
.nb-btn:hover{opacity:.96}
.nb-btn.primary{background:#12355f;border-color:#12355f;color:#fff}
.nb-btn.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.nb-btn.ghost{background:#fff}
.nb-btn:disabled{opacity:.45;cursor:not-allowed}

.nb-content-view{margin-top:14px;font-size:15px;line-height:1.7;color:#243447}
.nb-content-view.is-empty{color:#8a98ad;font-style:italic}

.nb-editor-shell{display:none;margin-top:14px;border:1px solid rgba(15,23,42,0.08);border-radius:18px;background:#fff;overflow:hidden}
.nb-editor-shell.is-open{display:block}
.nb-toolbar{display:flex;gap:6px;flex-wrap:wrap;padding:10px 12px;border-bottom:1px solid rgba(15,23,42,0.06);background:#fbfdff}
.nb-tool{min-width:34px;height:34px;border-radius:10px;border:1px solid rgba(15,23,42,0.08);background:#fff;color:#152235;font-size:14px;font-weight:800;cursor:pointer}
.nb-tool:hover{background:#f7fafc}
.nb-editor{min-height:190px;padding:16px 16px 18px 16px;font-size:15px;line-height:1.68;color:#152235;outline:none}
.nb-editor:empty:before{content:attr(data-placeholder);color:#8a98ad}
.nb-editor-status{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-top:1px solid rgba(15,23,42,0.06);background:#fbfdff}
.nb-status-left,.nb-status-right{font-size:12px;color:#60718b;font-weight:700}
.nb-editor-actions{display:flex;gap:8px;flex-wrap:wrap;padding:12px 14px;border-top:1px solid rgba(15,23,42,0.06);background:#fff}

.nb-note-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
.nb-note-box{border:1px solid rgba(15,23,42,0.06);border-radius:16px;background:#fff;padding:14px 15px}
.nb-note-label{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:#6a7c95;font-weight:800;margin-bottom:10px}
.nb-note-body{font-size:14px;line-height:1.6;color:#3b4f68;min-height:54px}
.nb-note-empty{color:#8a98ad;font-style:italic}

.nb-history{margin-top:14px;border:1px solid rgba(15,23,42,0.06);border-radius:16px;background:#fff}
.nb-history summary{list-style:none;cursor:pointer;padding:12px 14px;font-size:13px;font-weight:800;color:#152235;display:flex;align-items:center;justify-content:space-between;gap:12px}
.nb-history summary::-webkit-details-marker{display:none}
.nb-history-body{padding:0 14px 14px 14px}
.nb-history-list{display:flex;flex-direction:column;gap:10px}
.nb-history-item{padding:12px;border:1px solid rgba(15,23,42,0.06);border-radius:14px;background:#fbfdff}
.nb-history-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.nb-history-title{font-size:13px;font-weight:800;color:#152235}
.nb-history-meta{margin-top:4px;font-size:12px;color:#64748b;line-height:1.45}
.nb-history-preview{margin-top:8px;font-size:13px;color:#3b4f68;line-height:1.55}
.nb-history-empty{font-size:13px;color:#64748b;padding:6px 0}

.nb-export-mode .nb-editor-shell,
.nb-export-mode .nb-action-row,
.nb-export-mode .nb-history{display:none !important}

@media (max-width: 980px){
  .nb-doc{padding:24px 20px 28px 20px}
  .nb-meta{grid-template-columns:1fr}
  .nb-program-row{flex-direction:column}
  .nb-toc-row{flex-direction:column}
  .nb-toc-meta{justify-content:flex-start}
  .nb-lesson-head{flex-direction:column}
  .nb-note-grid{grid-template-columns:1fr}
}
</style>

<div class="nb-shell">
  <div class="nb-banner" id="notebookBanner"></div>

  <div class="nb-doc" id="notebookDocument">
    <header class="nb-head">
      <div class="nb-overline">IPCA Academy · Training Notebook</div>

      <div class="nb-program-row">
        <div>
          <h2 class="nb-title"><?= h($programTitle) ?></h2>
          <div class="nb-sub">
            This notebook renders as one continuous training document. Edit one lesson section at a time while the live lesson summary remains the only canonical source of truth.
          </div>
        </div>

        <div class="nb-selector">
          <div class="nb-selector-label">Program</div>
          <div class="nb-selector-pill"><?= h($programTitle) ?></div>
        </div>
      </div>

      <div class="nb-meta">
        <div class="nb-meta-box">
          <div class="nb-meta-label">Summary by</div>
          <div class="nb-meta-value"><?= h($data['student_name']) ?></div>
        </div>
        <div class="nb-meta-box">
          <div class="nb-meta-label">Cohort</div>
          <div class="nb-meta-value"><?= h((string)$data['cohort']['name']) ?></div>
        </div>
        <div class="nb-meta-box">
          <div class="nb-meta-label">Last saved</div>
          <div class="nb-meta-value"><?= h(nb_ui_date($data['last_saved_at'] ?? '')) ?></div>
        </div>
      </div>
    </header>

    <nav class="nb-toc-wrap" aria-label="Notebook table of contents">
      <h3 class="nb-toc-title">Table of Contents</h3>

      <div class="nb-program-heading"><?= h($programNumber) ?> <?= h($programTitle) ?></div>

      <ol class="nb-toc-list">
        <?php foreach ($data['courses'] as $course): ?>
          <li>
            <div class="nb-toc-row">
              <div class="nb-toc-main">
                <a class="nb-toc-link" href="#<?= h((string)$course['anchor_id']) ?>">
                  <?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?>
                </a>
              </div>
            </div>

            <ol class="nb-toc-sublist">
              <?php foreach ($course['lessons'] as $lesson): ?>
                <li>
                  <div class="nb-toc-row">
                    <div class="nb-toc-main">
                      <a class="nb-toc-link" href="#<?= h((string)$lesson['anchor_id']) ?>">
                        <?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?>
                      </a>
                    </div>

                    <div class="nb-toc-meta">
                      <span class="nb-mini-pill <?= h((string)$lesson['review_ui_class']) ?>">
                        <?= h((string)$lesson['review_ui_label']) ?>
                      </span>
                      <span class="nb-mini-pill neutral"><?= (int)$lesson['word_count'] ?> words</span>
                      <span class="nb-mini-pill info"><?= (int)$lesson['version_count'] ?> versions</span>
                      <span class="nb-mini-pill neutral"><?= h(nb_ui_date((string)$lesson['updated_at'])) ?></span>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          </li>
        <?php endforeach; ?>
      </ol>
    </nav>

    <main class="nb-body">
      <?php foreach ($data['courses'] as $course): ?>
        <section class="nb-course-section" id="<?= h((string)$course['anchor_id']) ?>">
          <div class="nb-course-kicker">Program section <?= h((string)$course['course_number']) ?></div>
          <h3 class="nb-course-title"><?= h((string)$course['course_number']) ?> <?= h((string)$course['course_title']) ?></h3>

          <?php foreach ($course['lessons'] as $lesson): ?>
            <?php
              $lessonId = (int)$lesson['lesson_id'];
              $isReadOnly = !empty($lesson['read_only_by_default']);
              $summaryHtml = (string)($lesson['summary_html'] ?? '');
              $summaryEmpty = trim(strip_tags($summaryHtml)) === '';
            ?>
            <section
              class="nb-lesson-section"
              id="<?= h((string)$lesson['anchor_id']) ?>"
              data-lesson-id="<?= $lessonId ?>"
              data-review-status="<?= h((string)$lesson['review_status']) ?>"
              data-read-only="<?= $isReadOnly ? '1' : '0' ?>"
              data-has-unsaved="0"
            >
              <div class="nb-lesson-head">
                <div>
                  <h4 class="nb-lesson-title"><?= h((string)$lesson['lesson_number']) ?> <?= h((string)$lesson['lesson_title']) ?></h4>

                  <div class="nb-lesson-meta">
                    <span class="nb-pill <?= h((string)$lesson['review_ui_class']) ?>" data-role="review-pill">
                      <?= h((string)$lesson['review_ui_label']) ?>
                    </span>
                    <span class="nb-pill neutral" data-role="word-count"><?= (int)$lesson['word_count'] ?> words</span>
                    <span class="nb-pill info" data-role="version-count"><?= (int)$lesson['version_count'] ?> versions</span>
                    <span class="nb-pill neutral" data-role="updated-at"><?= h(nb_ui_date((string)$lesson['updated_at'])) ?></span>
                  </div>
                </div>

                <div class="nb-action-row">
                  <?php if ($isReadOnly): ?>
                    <button type="button" class="nb-btn warn" data-action="unlock">Edit Summary</button>
                  <?php else: ?>
                    <button type="button" class="nb-btn ghost" data-action="edit">Edit</button>
                  <?php endif; ?>
                </div>
              </div>

              <div class="nb-content-view<?= $summaryEmpty ? ' is-empty' : '' ?>" data-role="content-view">
                <?php if ($summaryEmpty): ?>
                  <em>No summary yet.</em>
                <?php else: ?>
                  <?= $summaryHtml ?>
                <?php endif; ?>
              </div>

              <div class="nb-editor-shell" data-role="editor-shell">
                <div class="nb-toolbar">
                  <button type="button" class="nb-tool" data-cmd="bold">B</button>
                  <button type="button" class="nb-tool" data-cmd="italic"><em>I</em></button>
                  <button type="button" class="nb-tool" data-cmd="underline"><u>U</u></button>
                  <button type="button" class="nb-tool" data-cmd="insertUnorderedList">•</button>
                </div>

                <div class="nb-editor" data-role="editor" contenteditable="true" spellcheck="true" data-placeholder="Write your lesson summary in your own words."><?= $summaryHtml ?></div>

                <div class="nb-editor-status">
                  <div class="nb-status-left" data-role="editor-status-left">Editing this section only</div>
                  <div class="nb-status-right" data-role="editor-status-right">Explicit save required</div>
                </div>

                <div class="nb-editor-actions">
                  <button type="button" class="nb-btn primary" data-action="save">Save</button>
                  <button type="button" class="nb-btn ghost" data-action="cancel">Close</button>
                </div>
              </div>

              <div class="nb-note-grid">
                <div class="nb-note-box" data-role="feedback-box">
                  <div class="nb-note-label">Instructor Feedback</div>
                  <div class="nb-note-body" data-role="feedback-body">
                    <?php if (trim((string)$lesson['instructor_feedback']) !== ''): ?>
                      <?= nl2br(h((string)$lesson['instructor_feedback'])) ?>
                    <?php else: ?>
                      <span class="nb-note-empty">No instructor feedback visible for this lesson.</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="nb-note-box" data-role="notes-box">
                  <div class="nb-note-label">Instructor Notes</div>
                  <div class="nb-note-body" data-role="notes-body">
                    <?php if (trim((string)$lesson['instructor_notes']) !== ''): ?>
                      <?= nl2br(h((string)$lesson['instructor_notes'])) ?>
                    <?php else: ?>
                      <span class="nb-note-empty">No instructor notes visible for this lesson.</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <details class="nb-history" data-role="history" data-loaded="0">
                <summary>
                  <span>Version history</span>
                  <span data-role="history-count"><?= (int)$lesson['version_count'] ?> available</span>
                </summary>
                <div class="nb-history-body">
                  <div class="nb-history-list" data-role="history-list">
                    <div class="nb-history-empty">Open to load compact version history for this lesson.</div>
                  </div>
                </div>
              </details>
            </section>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </main>
  </div>
</div>
<script>
const NB_COHORT_ID = <?= (int)$cohortId ?>;
const NB_SAVE_URL = '/student/api/summary_save.php';
const NB_GET_URL = '/student/api/lesson_summaries_get.php';
const nbBanner = document.getElementById('notebookBanner');
let nbActiveLessonId = null;
let nbActiveOriginalHtml = '';

function nbShowBanner(message, kind) {
  nbBanner.textContent = message;
  nbBanner.className = 'nb-banner' + (kind ? ' ' + kind : '');
  nbBanner.style.display = 'block';
  clearTimeout(nbShowBanner._t);
  nbShowBanner._t = setTimeout(() => {
    nbBanner.style.display = 'none';
  }, 3200);
}

function nbEscapeHtml(s) {
  return (s || '').toString()
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function nbWordCountFromHtml(html) {
  const div = document.createElement('div');
  div.innerHTML = html || '';
  const text = (div.textContent || div.innerText || '').trim();
  if (!text) return 0;
  return text.split(/\s+/).length;
}

function nbReviewMeta(status) {
  if (status === 'acceptable') return {label:'Accepted', klass:'ok'};
  if (status === 'needs_revision' || status === 'rejected') return {label:'Needs revision', klass:'danger'};
  if (status === 'pending') return {label:'Pending', klass:'warn'};
  return {label:'Draft', klass:'info'};
}

function nbSection(lessonId) {
  return document.querySelector('.nb-lesson-section[data-lesson-id="' + lessonId + '"]');
}

function nbEditor(section) {
  return section.querySelector('[data-role="editor"]');
}

function nbEditorShell(section) {
  return section.querySelector('[data-role="editor-shell"]');
}

function nbContentView(section) {
  return section.querySelector('[data-role="content-view"]');
}

function nbIsMeaningfullyChanged(section) {
  const editor = nbEditor(section);
  return !!(editor && editor.innerHTML !== nbActiveOriginalHtml);
}

function nbSetUnsaved(section, flag) {
  section.dataset.hasUnsaved = flag ? '1' : '0';
}

function nbUpdateMeta(section, html, reviewStatus) {
  const wc = nbWordCountFromHtml(html);
  const wcEl = section.querySelector('[data-role="word-count"]');
  if (wcEl) wcEl.textContent = wc + ' words';

  const updatedEl = section.querySelector('[data-role="updated-at"]');
  if (updatedEl) {
    const d = new Date();
    const txt = d.toLocaleDateString(undefined, {weekday:'short', month:'short', day:'numeric', year:'numeric'});
    updatedEl.textContent = txt;
  }

  const pill = section.querySelector('[data-role="review-pill"]');
  if (pill) {
    const meta = nbReviewMeta(reviewStatus);
    pill.className = 'nb-pill ' + meta.klass;
    pill.textContent = meta.label;
  }
}

function nbOpenEditor(section) {
  if (!section) return;

  const shell = nbEditorShell(section);
  const view = nbContentView(section);
  const editor = nbEditor(section);
  if (!shell || !view || !editor) return;

  view.style.display = 'none';
  shell.classList.add('is-open');
  nbActiveLessonId = section.dataset.lessonId;
  nbActiveOriginalHtml = editor.innerHTML;
  nbSetUnsaved(section, false);
  setTimeout(() => editor.focus(), 60);
  section.scrollIntoView({behavior:'smooth', block:'start'});
}

function nbCloseEditor(section, restoreOriginal) {
  if (!section) return;

  const shell = nbEditorShell(section);
  const view = nbContentView(section);
  const editor = nbEditor(section);
  if (!shell || !view || !editor) return;

  if (restoreOriginal) {
    editor.innerHTML = nbActiveOriginalHtml;
  }

  shell.classList.remove('is-open');
  view.style.display = '';
  nbSetUnsaved(section, false);

  if (nbActiveLessonId === section.dataset.lessonId) {
    nbActiveLessonId = null;
    nbActiveOriginalHtml = '';
  }
}

async function nbPost(payload) {
  const res = await fetch(NB_SAVE_URL, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    credentials: 'same-origin',
    body: JSON.stringify(payload)
  });

  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    json = {ok:false, error:'Invalid server response'};
  }
  return json;
}

async function nbEnsureSingleEditor(targetSection) {
  if (!nbActiveLessonId) return true;
  if (String(nbActiveLessonId) === String(targetSection.dataset.lessonId)) return true;

  const activeSection = nbSection(nbActiveLessonId);
  if (!activeSection) {
    nbActiveLessonId = null;
    nbActiveOriginalHtml = '';
    return true;
  }

  if (!nbIsMeaningfullyChanged(activeSection)) {
    nbCloseEditor(activeSection, true);
    return true;
  }

  const choice = window.prompt('You have unsaved changes in another section. Type SAVE to save and switch, DISCARD to discard, or anything else to continue editing.', 'SAVE');
  if (choice === null) return false;

  const normalized = String(choice).trim().toUpperCase();
  if (normalized === 'SAVE') {
    const ok = await nbSaveSection(activeSection, true);
    return !!ok;
  }
  if (normalized === 'DISCARD') {
    nbCloseEditor(activeSection, true);
    return true;
  }

  return false;
}

async function nbSaveSection(section, closeAfter) {
  const lessonId = Number(section.dataset.lessonId || 0);
  const editor = nbEditor(section);
  const view = nbContentView(section);
  if (!lessonId || !editor || !view) return false;

  const result = await nbPost({
    action: 'save',
    cohort_id: NB_COHORT_ID,
    lesson_id: lessonId,
    summary_html: editor.innerHTML || ''
  });

  if (!result.ok) {
    nbShowBanner(result.error || 'Save failed.', 'danger');
    return false;
  }

  const html = editor.innerHTML || '';
  view.innerHTML = html.trim() === '' ? '<em>No summary yet.</em>' : html;
  view.classList.toggle('is-empty', html.trim() === '');

  let reviewStatus = section.dataset.reviewStatus || 'pending';
  if (reviewStatus === 'needs_revision') {
    reviewStatus = 'pending';
    section.dataset.reviewStatus = 'pending';
  }

  nbUpdateMeta(section, html, reviewStatus);
  nbSetUnsaved(section, false);
  nbActiveOriginalHtml = html;

  const historyCount = section.querySelector('[data-role="history-count"]');
  const versionCountEl = section.querySelector('[data-role="version-count"]');
  if (historyCount) {
    const current = parseInt(historyCount.textContent, 10);
    if (!isNaN(current)) historyCount.textContent = (current + 1) + ' available';
  }
  if (versionCountEl) {
    const currentText = versionCountEl.textContent || '';
    const m = currentText.match(/^(\d+)/);
    if (m) versionCountEl.textContent = (parseInt(m[1], 10) + 1) + ' versions';
  }

  nbShowBanner(result.skipped ? 'No meaningful change.' : 'Summary saved.', 'ok');

  if (closeAfter) {
    nbCloseEditor(section, false);
  }

  return true;
}

async function nbUnlockAccepted(section, button) {
  const lessonId = Number(section.dataset.lessonId || 0);
  if (!lessonId) return;

  const result = await nbPost({
    action: 'unlock',
    cohort_id: NB_COHORT_ID,
    lesson_id: lessonId
  });

  if (!result.ok) {
    nbShowBanner(result.error || 'Unlock failed.', 'danger');
    if (button) button.disabled = false;
    return;
  }

  section.dataset.reviewStatus = 'pending';
  section.dataset.readOnly = '0';

  const pill = section.querySelector('[data-role="review-pill"]');
  if (pill) {
    pill.className = 'nb-pill warn';
    pill.textContent = 'Pending';
  }

  if (button) {
    button.textContent = 'Edit';
    button.className = 'nb-btn ghost';
    button.disabled = false;
    button.setAttribute('data-action', 'edit');
  }

  const versionCountEl = section.querySelector('[data-role="version-count"]');
  const historyCount = section.querySelector('[data-role="history-count"]');
  if (versionCountEl) {
    const m = (versionCountEl.textContent || '').match(/^(\d+)/);
    if (m) versionCountEl.textContent = (parseInt(m[1], 10) + 1) + ' versions';
  }
  if (historyCount) {
    const m = (historyCount.textContent || '').match(/^(\d+)/);
    if (m) historyCount.textContent = (parseInt(m[1], 10) + 1) + ' available';
  }

  nbShowBanner('Accepted summary reopened. It is now pending review.', 'ok');
  nbOpenEditor(section);
}

async function nbLoadHistory(section) {
  const details = section.querySelector('[data-role="history"]');
  const list = section.querySelector('[data-role="history-list"]');
  if (!details || !list || details.dataset.loaded === '1') return;

  list.innerHTML = '<div class="nb-history-empty">Loading version history…</div>';

  const lessonId = Number(section.dataset.lessonId || 0);

  try {
    const res = await fetch(
      NB_GET_URL + '?cohort_id=' + encodeURIComponent(NB_COHORT_ID) + '&lesson_id=' + encodeURIComponent(lessonId) + '&view=versions',
      {credentials:'same-origin'}
    );
    const json = await res.json();

    if (!json.ok) {
      list.innerHTML = '<div class="nb-history-empty">' + nbEscapeHtml(json.error || 'Could not load version history.') + '</div>';
      return;
    }

    const versions = Array.isArray(json.versions) ? json.versions : [];
    if (!versions.length) {
      list.innerHTML = '<div class="nb-history-empty">No version snapshots available yet for this lesson.</div>';
      details.dataset.loaded = '1';
      return;
    }

    let html = '';
    versions.forEach((v) => {
      html += ''
        + '<div class="nb-history-item">'
        + '<div class="nb-history-top">'
        + '<div>'
        + '<div class="nb-history-title">Version ' + Number(v.version_no) + '</div>'
        + '<div class="nb-history-meta">' + nbEscapeHtml(v.snapshot_reason_label || 'Snapshot') + ' · ' + nbEscapeHtml(v.created_at || '') + '</div>'
        + '</div>'
        + '</div>'
        + '<div class="nb-history-preview">' + nbEscapeHtml(v.preview || '') + '</div>'
        + '</div>';
    });

    list.innerHTML = html;
    details.dataset.loaded = '1';
  } catch (e) {
    list.innerHTML = '<div class="nb-history-empty">Could not load version history.</div>';
  }
}

document.querySelectorAll('.nb-lesson-section').forEach((section) => {
  const editor = nbEditor(section);
  const history = section.querySelector('[data-role="history"]');

  if (editor) {
    editor.addEventListener('input', () => {
      nbSetUnsaved(section, nbIsMeaningfullyChanged(section));
    });

    editor.addEventListener('paste', (e) => {
      e.preventDefault();
      nbShowBanner('Paste attempt detected. Deterrence triggered for this notebook section.', 'warn');
    });

    editor.addEventListener('copy', (e) => {
      e.preventDefault();
      nbShowBanner('Copy attempt detected. Deterrence triggered for this notebook section.', 'warn');
    });

    editor.addEventListener('cut', (e) => {
      e.preventDefault();
      nbShowBanner('Cut attempt detected. Deterrence triggered for this notebook section.', 'warn');
    });

    editor.addEventListener('drop', (e) => {
      e.preventDefault();
      nbShowBanner('Drag/drop insertion attempt detected. Deterrence triggered for this notebook section.', 'warn');
    });

    editor.addEventListener('dragover', (e) => {
      e.preventDefault();
    });

    editor.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      nbShowBanner('Context-menu attempt detected. Deterrence triggered for this notebook section.', 'warn');
    });
  }

  section.querySelectorAll('[data-cmd]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!editor) return;
      const cmd = btn.getAttribute('data-cmd');
      document.execCommand(cmd, false, null);
      editor.focus();
      nbSetUnsaved(section, nbIsMeaningfullyChanged(section));
    });
  });

  section.querySelectorAll('[data-action]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const action = btn.getAttribute('data-action');

      if (action === 'edit') {
        const ok = await nbEnsureSingleEditor(section);
        if (ok) nbOpenEditor(section);
      }

      if (action === 'unlock') {
        const ok = await nbEnsureSingleEditor(section);
        if (!ok) return;
        btn.disabled = true;
        await nbUnlockAccepted(section, btn);
      }

      if (action === 'save') {
        await nbSaveSection(section, true);
      }

      if (action === 'cancel') {
        if (!nbIsMeaningfullyChanged(section)) {
          nbCloseEditor(section, true);
          return;
        }

        const choice = window.prompt('Unsaved changes detected. Type SAVE to save, DISCARD to discard, or anything else to continue editing.', 'SAVE');
        if (choice === null) return;

        const normalized = String(choice).trim().toUpperCase();
        if (normalized === 'SAVE') {
          await nbSaveSection(section, true);
          return;
        }
        if (normalized === 'DISCARD') {
          nbCloseEditor(section, true);
          return;
        }
      }
    });
  });

  if (history) {
    history.addEventListener('toggle', () => {
      if (history.open) {
        nbLoadHistory(section);
      }
    });
  }
});
</script>

<?php cw_footer(); ?>

			