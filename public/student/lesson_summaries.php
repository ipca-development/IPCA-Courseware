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
$notebook = $service->getNotebookViewData($userId, $cohortId, $studentName);

function cw_ui_date_notebook(?string $value): string
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

cw_header('My Lesson Summaries');
?>
<style>
  .nb-wrap{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:18px}
  .nb-card{background:#fff;border:1px solid rgba(15,23,42,0.06);border-radius:22px;box-shadow:0 10px 24px rgba(15,23,42,0.055)}
  .nb-header{padding:28px 30px}
  .nb-eyebrow{font-size:11px;line-height:1;text-transform:uppercase;letter-spacing:.14em;color:#63758f;font-weight:800;margin-bottom:12px}
  .nb-title{margin:0;font-size:34px;line-height:1.02;color:#152235;letter-spacing:-0.04em;font-weight:800}
  .nb-sub{margin-top:12px;font-size:15px;color:#5c6e86;line-height:1.6;max-width:820px}
  .nb-meta{display:grid;grid-template-columns:repeat(3,minmax(200px,1fr));gap:16px;margin-top:22px}
  .nb-meta-box{padding:16px 18px;border-radius:16px;background:#f7fafe;border:1px solid rgba(29,79,145,0.08)}
  .nb-meta-label{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:#6a7c95;font-weight:800;margin-bottom:8px}
  .nb-meta-value{font-size:16px;font-weight:800;color:#152235;line-height:1.35}

  .nb-body{padding:22px 24px}
  .nb-intro{padding:0 6px 4px 6px;color:#5a6c84;font-size:14px;line-height:1.6}
  .nb-course{padding:20px 20px 10px 20px}
  .nb-course + .nb-course{border-top:1px solid rgba(15,23,42,0.05)}
  .nb-course-head{padding-bottom:14px}
  .nb-course-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#687b94;font-weight:800;margin-bottom:8px}
  .nb-course-title{margin:0;font-size:24px;line-height:1.08;color:#152235;letter-spacing:-0.02em;font-weight:800}
  .nb-course-sub{margin-top:8px;font-size:14px;color:#5d6f86;line-height:1.5}

  .nb-lesson{padding:18px;border:1px solid rgba(15,23,42,0.06);border-radius:18px;background:linear-gradient(180deg,#fff 0%,#fbfdff 100%)}
  .nb-lesson + .nb-lesson{margin-top:14px}
  .nb-lesson-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
  .nb-lesson-main{min-width:0}
  .nb-lesson-title{margin:0;font-size:20px;line-height:1.12;color:#152235;font-weight:800;letter-spacing:-0.02em}
  .nb-lesson-meta{display:flex;flex-wrap:wrap;gap:8px 10px;margin-top:10px}
  .nb-meta-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid transparent;white-space:nowrap}
  .nb-meta-pill.neutral{background:#eef2f7;border-color:#d7dee8;color:#475569}
  .nb-meta-pill.ok{background:#dcfce7;border-color:#86efac;color:#166534}
  .nb-meta-pill.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
  .nb-meta-pill.danger{background:#fee2e2;border-color:#fecaca;color:#991b1b}
  .nb-meta-pill.info{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}

  .nb-lesson-actions{display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap;justify-content:flex-end}
  .nb-btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border-radius:12px;text-decoration:none;border:1px solid rgba(15,23,42,0.08);background:#f5f8fc;color:#152235;font-size:13px;font-weight:800;cursor:pointer}
  .nb-btn:hover{opacity:.96}
  .nb-btn.primary{background:#12355f;border-color:#12355f;color:#fff}
  .nb-btn.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
  .nb-btn.ghost{background:#fff}
  .nb-btn:disabled{opacity:.45;cursor:not-allowed}

  .nb-editor-shell{border:1px solid rgba(15,23,42,0.08);border-radius:18px;background:#fff;overflow:hidden}
  .nb-toolbar{display:flex;gap:6px;flex-wrap:wrap;padding:10px 12px;border-bottom:1px solid rgba(15,23,42,0.06);background:#fbfdff}
  .nb-tool{min-width:34px;height:34px;border-radius:10px;border:1px solid rgba(15,23,42,0.08);background:#fff;color:#152235;font-size:14px;font-weight:800;cursor:pointer}
  .nb-tool:hover{background:#f7fafc}
  .nb-editor{min-height:190px;padding:16px 16px 18px 16px;font-size:15px;line-height:1.68;color:#152235;outline:none}
  .nb-editor[contenteditable="false"]{background:#fcfdff;color:#334155}
  .nb-editor.read-only{-webkit-user-select:none;user-select:none}
  .nb-editor:empty:before{content:attr(data-placeholder);color:#8a98ad}

  .nb-editor-status{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-top:1px solid rgba(15,23,42,0.06);background:#fbfdff}
  .nb-status-left{font-size:12px;color:#60718b;font-weight:700}
  .nb-status-right{font-size:12px;color:#60718b;font-weight:700}

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

  .nb-banner{display:none;position:sticky;top:14px;z-index:25;padding:12px 14px;border-radius:14px;border:1px solid #93c5fd;background:#eff6ff;color:#1d4ed8;font-size:13px;font-weight:700;box-shadow:0 10px 24px rgba(15,23,42,0.08)}
  .nb-banner.warn{border-color:#fcd34d;background:#fffbeb;color:#92400e}
  .nb-banner.danger{border-color:#fca5a5;background:#fef2f2;color:#991b1b}
  .nb-banner.ok{border-color:#86efac;background:#f0fdf4;color:#166534}

  .nb-empty{padding:24px;border:1px dashed rgba(15,23,42,0.10);border-radius:18px;background:#fff;color:#5f7088}

  @media (max-width: 980px){
    .nb-meta{grid-template-columns:1fr}
    .nb-note-grid{grid-template-columns:1fr}
    .nb-lesson-head{flex-direction:column}
    .nb-lesson-actions{justify-content:flex-start}
  }
</style>

<div class="nb-wrap">
  <div class="nb-banner" id="notebookBanner"></div>

  <section class="nb-card nb-header">
    <div class="nb-eyebrow">IPCA Academy · Training Notebook</div>
    <h2 class="nb-title"><?= h($notebook['cohort']['course_title']) ?></h2>
    <div class="nb-sub">
      Your lesson notebook keeps each summary anchored to the live lesson record. Each lesson block saves independently while the notebook gives you one structured place to revise and follow instructor feedback.
    </div>

    <div class="nb-meta">
      <div class="nb-meta-box">
        <div class="nb-meta-label">Summary by</div>
        <div class="nb-meta-value"><?= h($notebook['student_name']) ?></div>
      </div>
      <div class="nb-meta-box">
        <div class="nb-meta-label">Cohort</div>
        <div class="nb-meta-value"><?= h($notebook['cohort']['name']) ?></div>
      </div>
      <div class="nb-meta-box">
        <div class="nb-meta-label">Last saved</div>
        <div class="nb-meta-value"><?= h(cw_ui_date_notebook($notebook['last_saved_at'])) ?></div>
      </div>
    </div>
  </section>

  <section class="nb-card nb-body">
    <div class="nb-intro">
      Accepted summaries are read-only until you explicitly unlock them. Version snapshots are created only on meaningful transitions, and instructor feedback remains visible below each lesson when available.
    </div>

    <?php if (!$notebook['courses']): ?>
      <div class="nb-empty">No lesson summaries are available for this cohort yet.</div>
    <?php else: ?>
      <?php foreach ($notebook['courses'] as $courseIndex => $course): ?>
        <article class="nb-course">
          <header class="nb-course-head">
            <div class="nb-course-kicker">Course <?= (int)($courseIndex + 1) ?></div>
            <h3 class="nb-course-title"><?= h($course['course_title']) ?></h3>
            <div class="nb-course-sub">Each lesson below is tied directly to the canonical live lesson summary record.</div>
          </header>

          <?php foreach ($course['lessons'] as $lessonIndex => $lesson): ?>
            <?php
              $readOnly = !empty($lesson['read_only_by_default']);
              $editorId = 'editor-' . (int)$lesson['lesson_id'];
              $statusId = 'status-' . (int)$lesson['lesson_id'];
              $historyId = 'history-' . (int)$lesson['lesson_id'];
            ?>
            <section
              class="nb-lesson"
              data-lesson-id="<?= (int)$lesson['lesson_id'] ?>"
              data-updated-at="<?= h((string)$lesson['updated_at']) ?>"
              data-review-status="<?= h((string)$lesson['review_status']) ?>"
              data-unlocked="<?= $readOnly ? '0' : '1' ?>"
            >
              <div class="nb-lesson-head">
                <div class="nb-lesson-main">
                  <h4 class="nb-lesson-title">
                    <?= (int)($courseIndex + 1) ?>.<?= (int)($lessonIndex + 1) ?>
                    <?= h($lesson['lesson_title']) ?>
                  </h4>

                  <div class="nb-lesson-meta">
                    <span class="nb-meta-pill <?= h($lesson['status_meta']['class']) ?>" data-role="review-pill">
                      <?= h($lesson['review_ui_label']) ?>
                    </span>
                    <span class="nb-meta-pill neutral"><?= (int)$lesson['word_count'] ?> words</span>
                    <span class="nb-meta-pill info"><?= (int)$lesson['version_count'] ?> version<?= (int)$lesson['version_count'] === 1 ? '' : 's' ?></span>
                    <span class="nb-meta-pill neutral">Saved <?= h(cw_ui_date_notebook($lesson['updated_at'])) ?></span>
                  </div>
                </div>

                <div class="nb-lesson-actions">
                  <?php if ($readOnly): ?>
                    <button type="button" class="nb-btn warn" data-action="unlock">Edit Summary</button>
                  <?php endif; ?>
                </div>
              </div>

              <div class="nb-editor-shell">
                <div class="nb-toolbar" data-role="toolbar" <?= $readOnly ? 'style="display:none;"' : '' ?>>
                  <button type="button" class="nb-tool" data-cmd="bold">B</button>
                  <button type="button" class="nb-tool" data-cmd="italic"><em>I</em></button>
                  <button type="button" class="nb-tool" data-cmd="underline"><u>U</u></button>
                  <button type="button" class="nb-tool" data-cmd="insertUnorderedList">•</button>
                </div>

                <div
                  id="<?= h($editorId) ?>"
                  class="nb-editor <?= $readOnly ? 'read-only' : '' ?>"
                  data-placeholder="Write your lesson summary in your own words."
                  contenteditable="<?= $readOnly ? 'false' : 'true' ?>"
                  spellcheck="true"
                ><?= $lesson['summary_html'] ?></div>

                <div class="nb-editor-status">
                  <div class="nb-status-left" id="<?= h($statusId) ?>">
                    <?= $readOnly ? 'Accepted summary · read-only until unlocked' : 'Ready' ?>
                  </div>
                  <div class="nb-status-right"><?= h($lesson['status_meta']['label']) ?></div>
                </div>
              </div>

              <div class="nb-note-grid">
                <div class="nb-note-box">
                  <div class="nb-note-label">Instructor Feedback</div>
                  <div class="nb-note-body" data-role="feedback">
                    <?php if (trim((string)$lesson['instructor_feedback']) !== ''): ?>
                      <?= nl2br(h((string)$lesson['instructor_feedback'])) ?>
                    <?php else: ?>
                      <span class="nb-note-empty">No instructor feedback on this lesson summary yet.</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="nb-note-box">
                  <div class="nb-note-label">Instructor Notes</div>
                  <div class="nb-note-body" data-role="notes">
                    <?php if (trim((string)$lesson['instructor_notes']) !== ''): ?>
                      <?= nl2br(h((string)$lesson['instructor_notes'])) ?>
                    <?php else: ?>
                      <span class="nb-note-empty">No instructor notes are active for this lesson.</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <details class="nb-history" id="<?= h($historyId) ?>" data-loaded="0">
                <summary>
                  <span>Version history</span>
                  <span><?= (int)$lesson['version_count'] ?> available</span>
                </summary>
                <div class="nb-history-body">
                  <div class="nb-history-list" data-role="history-list">
                    <div class="nb-history-empty">Open to load version history for this lesson.</div>
                  </div>
                </div>
              </details>
            </section>
          <?php endforeach; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<script>
const COHORT_ID = <?= (int)$cohortId ?>;
const SAVE_URL = '/student/api/summary_save.php';
const VERSIONS_URL = '/student/api/lesson_summaries_get.php';
const banner = document.getElementById('notebookBanner');

function showBanner(message, kind) {
  banner.textContent = message;
  banner.className = 'nb-banner' + (kind ? ' ' + kind : '');
  banner.style.display = 'block';
  clearTimeout(showBanner._timer);
  showBanner._timer = setTimeout(() => {
    banner.style.display = 'none';
  }, 3200);
}

function reviewUiMeta(status) {
  if (status === 'acceptable') return {label:'Accepted', klass:'ok'};
  if (status === 'needs_revision' || status === 'rejected') return {label:'Needs revision', klass:'danger'};
  if (status === 'pending') return {label:'Pending', klass:'warn'};
  return {label:'Draft', klass:'info'};
}

async function postJson(payload) {
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
    json = {ok:false, error:'Invalid server response'};
  }

  return json;
}

function setLessonStatus(block, text) {
  const el = block.querySelector('.nb-status-left');
  if (el) el.textContent = text;
}

function setLessonReview(block, reviewStatus) {
  block.dataset.reviewStatus = reviewStatus;
  const pill = block.querySelector('[data-role="review-pill"]');
  if (!pill) return;
  const meta = reviewUiMeta(reviewStatus);
  pill.className = 'nb-meta-pill ' + meta.klass;
  pill.textContent = meta.label;
}

function updateFeedbackAndNotes(block, feedback, notes) {
  const feedbackEl = block.querySelector('[data-role="feedback"]');
  const notesEl = block.querySelector('[data-role="notes"]');

  if (feedbackEl) {
    feedbackEl.innerHTML = (feedback || '').trim() === ''
      ? '<span class="nb-note-empty">No instructor feedback on this lesson summary yet.</span>'
      : escapeHtml(feedback).replace(/\n/g, '<br>');
  }

  if (notesEl) {
    notesEl.innerHTML = (notes || '').trim() === ''
      ? '<span class="nb-note-empty">No instructor notes are active for this lesson.</span>'
      : escapeHtml(notes).replace(/\n/g, '<br>');
  }
}

function escapeHtml(s) {
  return (s || '').toString()
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function editorFor(block) {
  return block.querySelector('.nb-editor');
}

function toolbarFor(block) {
  return block.querySelector('[data-role="toolbar"]');
}

function unlockLessonUi(block) {
  const editor = editorFor(block);
  const toolbar = toolbarFor(block);
  if (editor) {
    editor.setAttribute('contenteditable', 'true');
    editor.classList.remove('read-only');
    setTimeout(() => editor.focus(), 80);
  }
  if (toolbar) {
    toolbar.style.display = 'flex';
  }
  block.dataset.unlocked = '1';
  setLessonStatus(block, 'Unlocked · autosave active');
}

async function unlockAcceptedLesson(block, button) {
  const lessonId = Number(block.dataset.lessonId || 0);

  setLessonStatus(block, 'Preparing safe unlock…');

  const result = await postJson({
    action: 'unlock',
    cohort_id: COHORT_ID,
    lesson_id: lessonId
  });

  if (!result.ok) {
    showBanner(result.error || 'Unlock attempt detected but could not be completed.', 'danger');
    setLessonStatus(block, 'Unlock not available');
    if (button) button.disabled = false;
    return;
  }

  unlockLessonUi(block);
  if (button) button.remove();
  setLessonReview(block, 'pending');
  showBanner('Edit unlock attempt detected. The summary is now reopened and pending review.', 'ok');
}

async function saveLesson(block) {
  const lessonId = Number(block.dataset.lessonId || 0);
  const editor = editorFor(block);
  if (!editor) return;

  const result = await postJson({
    action: 'save',
    cohort_id: COHORT_ID,
    lesson_id: lessonId,
    summary_html: editor.innerHTML || ''
  });

  if (!result.ok) {
    showBanner(result.error || 'Save attempt detected but could not be completed.', 'danger');
    setLessonStatus(block, 'Save failed');
    return;
  }

  setLessonStatus(block, result.skipped ? 'No meaningful change' : 'Saved');
}

const saveTimers = {};

function scheduleSave(block) {
  const lessonId = Number(block.dataset.lessonId || 0);
  if (lessonId <= 0) return;

  setLessonStatus(block, 'Saving…');
  clearTimeout(saveTimers[lessonId]);
  saveTimers[lessonId] = setTimeout(() => {
    saveLesson(block);
  }, 850);
}

async function loadVersionHistory(block) {
  const lessonId = Number(block.dataset.lessonId || 0);
  const history = block.querySelector('.nb-history');
  const list = block.querySelector('[data-role="history-list"]');
  if (!history || !list || history.dataset.loaded === '1') return;

  list.innerHTML = '<div class="nb-history-empty">Loading version history…</div>';

  try {
    const res = await fetch(
      VERSIONS_URL + '?cohort_id=' + encodeURIComponent(COHORT_ID) +
      '&lesson_id=' + encodeURIComponent(lessonId) +
      '&view=versions',
      {credentials:'same-origin'}
    );
    const json = await res.json();

    if (!json.ok) {
      list.innerHTML = '<div class="nb-history-empty">' + escapeHtml(json.error || 'Could not load version history.') + '</div>';
      return;
    }

    const versions = Array.isArray(json.versions) ? json.versions : [];
    if (!versions.length) {
      list.innerHTML = '<div class="nb-history-empty">No version snapshots available yet for this lesson.</div>';
      history.dataset.loaded = '1';
      return;
    }

    let html = '';
    versions.forEach((v) => {
      html += ''
        + '<div class="nb-history-item">'
        + '  <div class="nb-history-top">'
        + '    <div>'
        + '      <div class="nb-history-title">Version ' + Number(v.version_no) + '</div>'
        + '      <div class="nb-history-meta">' + escapeHtml(v.snapshot_reason_label || 'Snapshot') + ' · ' + escapeHtml(v.created_at || '') + '</div>'
        + '    </div>'
        + '  </div>'
        + '  <div class="nb-history-preview">' + escapeHtml(v.preview || '') + '</div>'
        + '</div>';
    });

    list.innerHTML = html;
    history.dataset.loaded = '1';
  } catch (e) {
    list.innerHTML = '<div class="nb-history-empty">Could not load version history.</div>';
  }
}

document.querySelectorAll('.nb-lesson').forEach((block) => {
  const editor = editorFor(block);
  const toolbar = toolbarFor(block);

  if (editor) {
    editor.addEventListener('input', () => {
      if (editor.getAttribute('contenteditable') !== 'true') return;
      scheduleSave(block);
    });

    editor.addEventListener('paste', (e) => {
      e.preventDefault();
      showBanner('Paste attempt detected. Deterrence triggered for this notebook block.', 'warn');
    });

    editor.addEventListener('copy', (e) => {
      e.preventDefault();
      showBanner('Copy attempt detected. Deterrence triggered for this notebook block.', 'warn');
    });

    editor.addEventListener('cut', (e) => {
      e.preventDefault();
      showBanner('Cut attempt detected. Deterrence triggered for this notebook block.', 'warn');
    });

    editor.addEventListener('drop', (e) => {
      e.preventDefault();
      showBanner('Drag/drop insertion attempt detected. Deterrence triggered for this notebook block.', 'warn');
    });

    editor.addEventListener('dragover', (e) => {
      e.preventDefault();
    });

    editor.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      showBanner('Context-menu attempt detected. Deterrence triggered for this notebook block.', 'warn');
    });
  }

  if (toolbar) {
    toolbar.querySelectorAll('[data-cmd]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!editor || editor.getAttribute('contenteditable') !== 'true') return;
        const cmd = btn.getAttribute('data-cmd');
        document.execCommand(cmd, false, null);
        editor.focus();
        scheduleSave(block);
      });
    });
  }

  const unlockBtn = block.querySelector('[data-action="unlock"]');
  if (unlockBtn) {
    unlockBtn.addEventListener('click', async () => {
      unlockBtn.disabled = true;
      await unlockAcceptedLesson(block, unlockBtn);
    });
  }

  const history = block.querySelector('.nb-history');
  if (history) {
    history.addEventListener('toggle', () => {
      if (history.open) {
        loadVersionHistory(block);
      }
    });
  }
});
</script>

<?php cw_footer(); ?>