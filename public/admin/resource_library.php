<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/resource_library_ingest.php';

cw_require_admin();

$apiHref = '/admin/api/resource_library_api.php';
$searchTestHref = '/admin/api/resource_library_search_test.php';

/**
 * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
 */
function rl_fetch_editions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, created_at, updated_at
            FROM resource_library_editions
            ORDER BY FIELD(status, 'live', 'draft', 'archived'), sort_order ASC, title ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['ok' => true, 'rows' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
    }
}

function rl_table_missing_message(string $dbError): string
{
    if (stripos($dbError, "doesn't exist") !== false || stripos($dbError, 'Unknown table') !== false) {
        return 'The resource library table is not installed yet. Apply the migration '
            . '<code>scripts/sql/resource_library_editions.sql</code> to this database, then reload.';
    }

    return h($dbError);
}

function rl_thumb_src(?string $path): string
{
    $p = trim((string)$path);
    if ($p === '') {
        return '/assets/icons/documents.svg';
    }
    if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
        return $p;
    }
    if ($p[0] !== '/') {
        return '/' . $p;
    }

    return $p;
}

function rl_status_label(string $status): string
{
    return match ($status) {
        'live' => 'Live',
        'draft' => 'Draft',
        'archived' => 'Archived',
        default => $status,
    };
}

function rl_status_class(string $status): string
{
    return match ($status) {
        'live' => 'rl-status rl-status-live',
        'draft' => 'rl-status rl-status-draft',
        'archived' => 'rl-status rl-status-archived',
        default => 'rl-status',
    };
}

$result = rl_fetch_editions($pdo);
$rows = $result['rows'];
$tableError = (!$result['ok']) ? ($result['error'] ?? 'Unknown error') : '';
$blockCounts = $result['ok'] ? rl_block_counts_by_edition_map($pdo) : [];

cw_header('Resource Library');
?>
<style>
  .rl-wrap { max-width: 1100px; }
  .rl-intro {
    color: #64748b;
    font-size: 14px;
    line-height: 1.5;
    margin: 0 0 20px;
  }
  .rl-alert {
    border: 1px solid #f59e0b;
    background: #fffbeb;
    color: #92400e;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 14px;
  }
  .rl-alert code { font-size: 13px; background: rgba(255,255,255,.7); padding: 2px 6px; border-radius: 6px; }
  .rl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 18px;
    align-items: stretch;
  }
  .rl-card {
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    text-align: left;
    width: 100%;
    min-height: 440px;
    height: 100%;
    padding: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }
  .rl-card:hover {
    border-color: #94a3b8;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
  }
  .rl-card:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
  }
  .rl-card-hint {
    font-size: 12px;
    color: #64748b;
    padding: 0 16px 12px;
    margin-top: auto;
  }
  .rl-card-thumb {
    flex: 0 0 auto;
    height: 268px;
    min-height: 268px;
    background: linear-gradient(165deg, #eef2f7 0%, #e2e8f0 45%, #dce3ec 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px 16px;
    pointer-events: none;
  }
  .rl-card-thumb img.rl-card-cover {
    display: block;
    width: auto;
    height: auto;
    max-height: 228px;
    max-width: 82%;
    object-fit: contain;
    object-position: center;
    border-radius: 10px;
    box-shadow:
      0 1px 2px rgba(15, 23, 42, 0.06),
      0 10px 24px rgba(15, 23, 42, 0.16),
      0 24px 48px rgba(15, 23, 42, 0.12);
  }
  .rl-card-body { padding: 14px 16px 8px; flex: 1; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
  .rl-card-title {
    font-size: 16px;
    font-weight: 600;
    color: #0f172a;
    line-height: 1.35;
    margin: 0;
  }
  .rl-meta { font-size: 13px; color: #475569; display: flex; flex-direction: column; gap: 4px; }
  .rl-meta dt { float: left; clear: left; width: 7.5rem; color: #64748b; font-weight: 500; }
  .rl-meta dd { margin: 0 0 0 7.5rem; color: #0f172a; }
  .rl-status {
    display: inline-flex;
    align-items: center;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 4px 10px;
    border-radius: 999px;
    width: fit-content;
    margin-top: 4px;
  }
  .rl-status-live { background: #dcfce7; color: #166534; }
  .rl-status-draft { background: #f1f5f9; color: #475569; }
  .rl-status-archived { background: #fee2e2; color: #991b1b; }
  .rl-status-validated { background: #dbeafe; color: #1e40af; }
  .rl-status-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    margin: 0;
  }
  .rl-meta dd.rl-status-dd { margin-left: 7.5rem; }
  .rl-empty {
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    padding: 28px 20px;
    text-align: center;
    color: #64748b;
    font-size: 14px;
    background: #f8fafc;
  }

  .rl-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 500;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
  }
  .rl-backdrop.is-open { display: flex; }
  .rl-modal {
    background: #fff;
    border-radius: 16px;
    max-width: 720px;
    width: 100%;
    max-height: min(92vh, 900px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
  }
  .rl-modal-head {
    padding: 18px 20px 12px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }
  .rl-modal-title { margin: 0; font-size: 18px; font-weight: 600; color: #0f172a; line-height: 1.3; }
  .rl-modal-sub { margin: 6px 0 0; font-size: 13px; color: #64748b; }
  .rl-modal-close {
    border: none;
    background: #f1f5f9;
    color: #475569;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
    flex-shrink: 0;
  }
  .rl-modal-close:hover { background: #e2e8f0; }
  .rl-modal-body { padding: 16px 20px 20px; overflow-y: auto; flex: 1; }
  .rl-field { margin-bottom: 14px; }
  .rl-field label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #64748b;
    margin-bottom: 6px;
  }
  .rl-field input, .rl-field select {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
    font-family: inherit;
  }
  .rl-field input:focus, .rl-field select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
  }
  .rl-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 520px) { .rl-row2 { grid-template-columns: 1fr; } }
  .rl-panel {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    margin-top: 8px;
    background: #f8fafc;
  }
  .rl-panel h3 { margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #0f172a; }
  .rl-panel p { margin: 0 0 10px; font-size: 13px; color: #475569; line-height: 1.45; }
  .rl-panel-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
  .rl-msg {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    font-size: 13px;
    display: none;
  }
  .rl-msg.is-error { display: block; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .rl-msg.is-ok { display: block; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
  .rl-modal-foot {
    padding: 14px 20px 18px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
  }
  .btn-ghost {
    background: #f1f5f9;
    color: #334155;
    border: none;
    border-radius: 10px;
    padding: 10px 14px;
    cursor: pointer;
    font-size: 14px;
    font-family: inherit;
  }
  .btn-ghost:hover { background: #e2e8f0; }
  .btn-danger {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    border-radius: 10px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
  }
  .btn-danger:hover { background: #fee2e2; }

  .rl-drop-img {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 20px 16px;
    text-align: center;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
    margin-top: 6px;
  }
  .rl-drop-img:hover {
    border-color: #94a3b8;
    background: #f8fafc;
  }
  .rl-drop-img:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
  }
  .rl-drop-img.is-dragover {
    border-color: #2563eb;
    background: #eff6ff;
  }
  .rl-drop-img.is-uploading {
    pointer-events: none;
    opacity: 0.65;
  }
  .rl-drop-img p { margin: 0; font-size: 14px; color: #475569; line-height: 1.45; }
  .rl-drop-meta { margin: 8px 0 0 !important; font-size: 12px !important; color: #94a3b8 !important; }
  .rl-thumb-preview-wrap {
    margin-top: 12px;
    min-height: 0;
  }
  .rl-thumb-preview-wrap img {
    max-width: 100%;
    max-height: 160px;
    object-fit: contain;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
  }

  .rl-test-panel .rl-field { margin-bottom: 10px; }
  .rl-test-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
  .rl-test-out {
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 260px;
    overflow-y: auto;
    color: #0f172a;
    min-height: 3rem;
  }
</style>

<div class="rl-wrap" id="rlPage" data-api="<?= h($apiHref) ?>" data-search-api="<?= h($searchTestHref) ?>">
  <p class="rl-intro">
    Reference editions for the platform. Click a book to edit metadata or upload / replace the JSON used as the live resource body
    (stored under <code>storage/resource_library/{id}/source.json</code> on the server).
  </p>

  <?php if (!$result['ok']): ?>
    <div class="rl-alert"><?= rl_table_missing_message($tableError) ?></div>
  <?php elseif ($rows === []): ?>
    <div class="rl-empty">No editions yet. After running <code>scripts/sql/resource_library_editions.sql</code>, add rows in
      <code>resource_library_editions</code>, then use this page to manage them.</div>
  <?php else: ?>
    <div class="rl-grid" id="rlGrid">
      <?php foreach ($rows as $row): ?>
        <?php
          $eid = (int)($row['id'] ?? 0);
          $title = (string)($row['title'] ?? '');
          $revCode = (string)($row['revision_code'] ?? '');
          $revDate = (string)($row['revision_date'] ?? '');
          $status = (string)($row['status'] ?? 'draft');
          $thumb = rl_thumb_src(isset($row['thumbnail_path']) ? (string)$row['thumbnail_path'] : '');
          $ts = $revDate !== '' ? strtotime($revDate . 'T12:00:00') : false;
          $revDisplay = ($ts !== false) ? date('F j, Y', $ts) : '—';
          $blockN = (int)($blockCounts[$eid] ?? 0);
          $sourcePresent = rl_source_stat($eid)['present'] ?? false;
          $validated = $sourcePresent && $blockN > 0;
        ?>
        <button type="button" class="rl-card" data-id="<?= $eid ?>" aria-label="Edit <?= h($title) ?>">
          <div class="rl-card-thumb">
            <img class="rl-card-cover" src="<?= h($thumb) ?>" alt="" loading="lazy" width="180" height="240">
          </div>
          <div class="rl-card-body">
            <h2 class="rl-card-title"><?= h($title) ?></h2>
            <dl class="rl-meta">
              <dt>Version</dt>
              <dd><?= h($revCode) ?></dd>
              <dt>Revision date</dt>
              <dd><?= h($revDisplay) ?></dd>
              <dt>Status</dt>
              <dd class="rl-status-dd">
                <div class="rl-status-badges">
                  <span class="<?= h(rl_status_class($status)) ?>"><?= h(rl_status_label($status)) ?></span>
                  <?php if ($validated): ?>
                    <span class="rl-status rl-status-validated">Validated</span>
                  <?php endif; ?>
                </div>
              </dd>
            </dl>
          </div>
          <div class="rl-card-hint">Click to edit · sync · test search</div>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="rl-backdrop" id="rlBackdrop" aria-hidden="true">
  <div class="rl-modal" role="dialog" aria-modal="true" aria-labelledby="rlModalTitle" tabindex="-1">
    <div class="rl-modal-head">
      <div>
        <h2 class="rl-modal-title" id="rlModalTitle">Edition</h2>
        <p class="rl-modal-sub" id="rlModalSub"></p>
      </div>
      <button type="button" class="rl-modal-close" id="rlClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-modal-body">
      <form id="rlMetaForm">
        <input type="hidden" name="id" id="rlFieldId" value="">
        <div class="rl-field">
          <label for="rlFieldTitle">Book title</label>
          <input type="text" id="rlFieldTitle" name="title" required maxlength="512" autocomplete="off">
        </div>
        <div class="rl-row2">
          <div class="rl-field">
            <label for="rlFieldRevCode">Version (e.g. FAA-H-8083-25C)</label>
            <input type="text" id="rlFieldRevCode" name="revision_code" required maxlength="128" autocomplete="off">
          </div>
          <div class="rl-field">
            <label for="rlFieldRevDate">Revision date</label>
            <input type="date" id="rlFieldRevDate" name="revision_date" required>
          </div>
        </div>
        <div class="rl-row2">
          <div class="rl-field">
            <label for="rlFieldStatus">Status</label>
            <select id="rlFieldStatus" name="status">
              <option value="draft">Draft</option>
              <option value="live">Live</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div class="rl-field">
            <label for="rlFieldSort">Sort order</label>
            <input type="number" id="rlFieldSort" name="sort_order" value="0" step="1">
          </div>
        </div>
        <div class="rl-field">
          <label for="rlFieldWork">Work code (optional)</label>
          <input type="text" id="rlFieldWork" name="work_code" maxlength="64" placeholder="PHAK, ACS…" autocomplete="off">
        </div>
        <div class="rl-field">
          <label id="rlDropThumbLabel">Cover thumbnail</label>
          <div
            class="rl-drop-img"
            id="rlDropThumb"
            role="button"
            tabindex="0"
            aria-labelledby="rlDropThumbLabel">
            <input type="file" id="rlThumbFile" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" hidden>
            <p>Drag and drop a cover image here, or click to browse.</p>
            <p class="rl-drop-meta">JPG, PNG, or WEBP · max 10 MB · saved on the server for this edition</p>
          </div>
          <div class="rl-thumb-preview-wrap">
            <img id="rlThumbPreview" alt="Thumbnail preview" width="160" height="120" style="display:none;">
          </div>
        </div>
        <div class="rl-field">
          <label for="rlFieldThumb">Thumbnail URL or path (optional)</label>
          <input type="text" id="rlFieldThumb" name="thumbnail_path" maxlength="1024" placeholder="/admin/resource_library_thumb.php?id=… or https://…" autocomplete="off">
        </div>
      </form>

      <div class="rl-panel">
        <h3>Resource JSON</h3>
        <p id="rlSourceInfo">No file uploaded yet.</p>
        <div class="rl-panel-actions">
          <input type="file" id="rlJsonFile" accept=".json,application/json" style="max-width: 220px; font-size: 13px;">
          <button type="button" class="btn btn-sm" id="rlUploadJson">Upload JSON</button>
          <a class="btn btn-sm" id="rlDownloadJson" href="#" style="display:none;">Download</a>
          <button type="button" class="btn-danger" id="rlDeleteJson" style="display:none;">Remove file</button>
        </div>
      </div>

      <div class="rl-panel">
        <h3>AI database</h3>
        <p id="rlBlocksInfo">Blocks are not loaded into MySQL yet.</p>
        <p class="rl-drop-meta" style="margin-top:6px;">Applies <code>scripts/sql/resource_library_blocks.sql</code> first, then sync so AI features can query
          <code>resource_library_blocks</code> (FULLTEXT on body text).</p>
        <div class="rl-panel-actions">
          <button type="button" class="btn btn-sm" id="rlSyncBlocks">Sync JSON → database</button>
        </div>
      </div>

      <div class="rl-panel rl-test-panel">
        <h3>Test retrieval</h3>
        <p class="rl-drop-meta" style="margin-top:0;">Search indexed blocks for this edition, or ask the model using the top hits as context (requires <code>CW_OPENAI_API_KEY</code>).</p>
        <div class="rl-field" style="margin-bottom:8px;">
          <label for="rlTestQuery">Topic or question</label>
          <input type="text" id="rlTestQuery" placeholder="e.g. weight and balance, stall speed, ADM" autocomplete="off">
        </div>
        <div class="rl-test-actions">
          <button type="button" class="btn btn-sm" id="rlTestDb">Search database</button>
          <button type="button" class="btn btn-sm" id="rlTestAi">Ask AI (uses hits)</button>
        </div>
        <pre class="rl-test-out" id="rlTestOut" aria-live="polite"></pre>
      </div>

      <div class="rl-msg" id="rlMsg" role="status"></div>
    </div>
    <div class="rl-modal-foot">
      <button type="button" class="btn-ghost" id="rlCancel">Cancel</button>
      <button type="button" class="btn" id="rlSaveMeta">Save metadata</button>
    </div>
  </div>
</div>

<script>
(function () {
  var page = document.getElementById('rlPage');
  if (!page) return;
  var api = page.getAttribute('data-api') || '';
  var searchApi = page.getAttribute('data-search-api') || '';
  var grid = document.getElementById('rlGrid');
  var backdrop = document.getElementById('rlBackdrop');
  if (!grid || !backdrop) return;

  var closeBtn = document.getElementById('rlClose');
  var cancelBtn = document.getElementById('rlCancel');
  var saveBtn = document.getElementById('rlSaveMeta');
  var uploadBtn = document.getElementById('rlUploadJson');
  var delBtn = document.getElementById('rlDeleteJson');
  var dl = document.getElementById('rlDownloadJson');
  var fileInput = document.getElementById('rlJsonFile');
  var thumbFileInput = document.getElementById('rlThumbFile');
  var dropThumb = document.getElementById('rlDropThumb');
  var thumbPreview = document.getElementById('rlThumbPreview');
  var msg = document.getElementById('rlMsg');
  var sourceInfo = document.getElementById('rlSourceInfo');
  var blocksInfo = document.getElementById('rlBlocksInfo');
  var syncBlocksBtn = document.getElementById('rlSyncBlocks');
  var testQuery = document.getElementById('rlTestQuery');
  var testDbBtn = document.getElementById('rlTestDb');
  var testAiBtn = document.getElementById('rlTestAi');
  var testOut = document.getElementById('rlTestOut');

  function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function showMsg(text, kind) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.className = 'rl-msg';
    if (!text) return;
    msg.classList.add(kind === 'ok' ? 'is-ok' : 'is-error');
  }

  function clearMsg() {
    showMsg('', '');
  }

  function updateThumbPreview() {
    var inp = document.getElementById('rlFieldThumb');
    if (!inp || !thumbPreview) return;
    var v = (inp.value || '').trim();
    if (!v) {
      thumbPreview.style.display = 'none';
      thumbPreview.removeAttribute('src');
      return;
    }
    var url;
    if (v.indexOf('http://') === 0 || v.indexOf('https://') === 0) {
      url = v;
    } else {
      url = (v.charAt(0) === '/') ? (window.location.origin + v) : (window.location.origin + '/' + v);
    }
    if (v.indexOf('resource_library_thumb.php') !== -1) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'cb=' + Date.now();
    }
    thumbPreview.onload = function () {
      thumbPreview.style.display = 'block';
    };
    thumbPreview.onerror = function () {
      thumbPreview.style.display = 'none';
    };
    thumbPreview.src = url;
  }

  function uploadCoverImage(file) {
    clearMsg();
    if (!file) {
      showMsg('No image file selected.', 'err');
      return;
    }
    var t = (file.type || '').toLowerCase();
    if (t !== 'image/jpeg' && t !== 'image/png' && t !== 'image/webp') {
      showMsg('Please use a JPG, PNG, or WEBP image.', 'err');
      return;
    }
    var id = parseInt(document.getElementById('rlFieldId').value, 10);
    if (!id) return;
    var fd = new FormData();
    fd.append('edition_id', String(id));
    fd.append('thumbnail_image', file, file.name);
    if (dropThumb) dropThumb.classList.add('is-uploading');
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Upload failed');
        if (x.j.edition) fillForm(x.j.edition);
        if (x.j.blocks) setBlocksUI(x.j.blocks);
        updateThumbPreview();
        showMsg('Cover image saved.', 'ok');
      })
      .catch(function (e) {
        showMsg(e.message || 'Image upload failed', 'err');
      })
      .finally(function () {
        if (dropThumb) dropThumb.classList.remove('is-uploading');
        if (thumbFileInput) thumbFileInput.value = '';
      });
  }

  function setSourceUI(src) {
    src = src || {};
    var present = !!src.present;
    if (sourceInfo) {
      if (!present) {
        sourceInfo.textContent = 'No JSON file on disk yet. Upload a .json file to attach structured content to this edition.';
      } else {
        var when = src.modified_iso ? new Date(src.modified_iso).toLocaleString() : '—';
        sourceInfo.textContent = 'File: source.json · ' + formatBytes(src.size) + ' · last updated ' + when + ' (UTC label may vary by browser).';
      }
    }
    if (dl) {
      var idVal = document.getElementById('rlFieldId');
      var id = idVal ? idVal.value : '';
      dl.style.display = present ? 'inline-block' : 'none';
      if (present && id) dl.href = api + '?id=' + encodeURIComponent(id) + '&download=1';
    }
    if (delBtn) delBtn.style.display = present ? 'inline-block' : 'none';
  }

  function setBlocksUI(bl) {
    bl = bl || {};
    if (!blocksInfo) return;
    if (!bl.table_ok) {
      var err = bl.error || '';
      if (err === 'table_missing') {
        blocksInfo.textContent = 'Run scripts/sql/resource_library_blocks.sql on this database, then use “Sync JSON → database”.';
      } else if (err) {
        blocksInfo.textContent = 'Blocks table: ' + err;
      } else {
        blocksInfo.textContent = 'Blocks table not available.';
      }
      return;
    }
    var n = bl.row_count || 0;
    var c = bl.chapter_count || 0;
    blocksInfo.textContent = n + ' blocks in MySQL across ' + c + ' chapters (FULLTEXT on body_text for AI search).';
  }

  function trapFocus(e) {
    if (e.key === 'Escape') closeModal();
  }

  function openModal() {
    backdrop.classList.add('is-open');
    backdrop.setAttribute('aria-hidden', 'false');
    document.addEventListener('keydown', trapFocus);
    var t = document.getElementById('rlFieldTitle');
    if (t) setTimeout(function () { t.focus(); }, 50);
  }

  function closeModal() {
    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden', 'true');
    document.removeEventListener('keydown', trapFocus);
    clearMsg();
    if (fileInput) fileInput.value = '';
    if (thumbFileInput) thumbFileInput.value = '';
    if (dropThumb) dropThumb.classList.remove('is-dragover');
    if (testOut) testOut.textContent = '';
    if (testQuery) testQuery.value = '';
  }

  backdrop.addEventListener('click', function (e) {
    if (e.target === backdrop) closeModal();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

  function fillForm(ed) {
    document.getElementById('rlFieldId').value = String(ed.id || '');
    document.getElementById('rlFieldTitle').value = ed.title || '';
    document.getElementById('rlFieldRevCode').value = ed.revision_code || '';
    document.getElementById('rlFieldRevDate').value = (ed.revision_date || '').toString().slice(0, 10);
    document.getElementById('rlFieldStatus').value = ed.status || 'draft';
    document.getElementById('rlFieldSort').value = String(ed.sort_order != null ? ed.sort_order : 0);
    document.getElementById('rlFieldWork').value = ed.work_code || '';
    document.getElementById('rlFieldThumb').value = ed.thumbnail_path || '';
    var mt = document.getElementById('rlModalTitle');
    var ms = document.getElementById('rlModalSub');
    if (mt) mt.textContent = ed.title || 'Edition';
    if (ms) ms.textContent = (ed.revision_code || '') + (ed.work_code ? ' · ' + ed.work_code : '');
    updateThumbPreview();
  }

  var thumbPathInput = document.getElementById('rlFieldThumb');
  if (thumbPathInput) {
    thumbPathInput.addEventListener('input', updateThumbPreview);
    thumbPathInput.addEventListener('change', updateThumbPreview);
  }

  if (dropThumb && thumbFileInput) {
    dropThumb.addEventListener('click', function () {
      thumbFileInput.click();
    });
    dropThumb.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        thumbFileInput.click();
      }
    });
    thumbFileInput.addEventListener('change', function () {
      if (thumbFileInput.files && thumbFileInput.files[0]) {
        uploadCoverImage(thumbFileInput.files[0]);
      }
    });
    dropThumb.addEventListener('dragenter', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dropThumb.classList.add('is-dragover');
    });
    dropThumb.addEventListener('dragleave', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var rel = e.relatedTarget;
      if (!rel || !dropThumb.contains(rel)) {
        dropThumb.classList.remove('is-dragover');
      }
    });
    dropThumb.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.stopPropagation();
      try {
        e.dataTransfer.dropEffect = 'copy';
      } catch (ignore) {}
    });
    dropThumb.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dropThumb.classList.remove('is-dragover');
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) uploadCoverImage(f);
    });
  }

  function loadEdition(id) {
    clearMsg();
    return fetch(api + '?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Load failed');
        fillForm(x.j.edition);
        setSourceUI(x.j.source);
        setBlocksUI(x.j.blocks);
        openModal();
      })
      .catch(function (err) {
        showMsg(err.message || 'Could not load edition', 'err');
        openModal();
      });
  }

  grid.querySelectorAll('.rl-card[data-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-id');
      if (!id) return;
      loadEdition(id);
    });
  });

  if (saveBtn) saveBtn.addEventListener('click', function () {
    clearMsg();
    var id = parseInt(document.getElementById('rlFieldId').value, 10);
    if (!id) return;
    var body = {
      action: 'save',
      id: id,
      title: document.getElementById('rlFieldTitle').value,
      revision_code: document.getElementById('rlFieldRevCode').value,
      revision_date: document.getElementById('rlFieldRevDate').value,
      status: document.getElementById('rlFieldStatus').value,
      sort_order: parseInt(document.getElementById('rlFieldSort').value, 10) || 0,
      work_code: document.getElementById('rlFieldWork').value,
      thumbnail_path: document.getElementById('rlFieldThumb').value
    };
    saveBtn.disabled = true;
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Save failed');
        window.location.reload();
      })
      .catch(function (e) {
        showMsg(e.message || 'Save failed', 'err');
      })
      .finally(function () { saveBtn.disabled = false; });
  });

  if (uploadBtn) uploadBtn.addEventListener('click', function () {
    clearMsg();
    var id = parseInt(document.getElementById('rlFieldId').value, 10);
    if (!id) return;
    if (!fileInput || !fileInput.files || !fileInput.files.length) {
      showMsg('Choose a .json file first.', 'err');
      return;
    }
    var fd = new FormData();
    fd.append('edition_id', String(id));
    fd.append('source_json', fileInput.files[0]);
    uploadBtn.disabled = true;
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Upload failed');
        setSourceUI(x.j.source);
        if (x.j.blocks) setBlocksUI(x.j.blocks);
        fileInput.value = '';
        showMsg('JSON uploaded and validated.', 'ok');
      })
      .catch(function (e) {
        showMsg(e.message || 'Upload failed', 'err');
      })
      .finally(function () { uploadBtn.disabled = false; });
  });

  if (syncBlocksBtn) syncBlocksBtn.addEventListener('click', function () {
    clearMsg();
    var id = parseInt(document.getElementById('rlFieldId').value, 10);
    if (!id) return;
    syncBlocksBtn.disabled = true;
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'import_blocks', id: id })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Sync failed');
        window.location.reload();
      })
      .catch(function (e) {
        showMsg(e.message || 'Sync failed', 'err');
      })
      .finally(function () { syncBlocksBtn.disabled = false; });
  });

  function renderTestResults(data) {
    if (!testOut) return;
    if (!data || !data.ok) {
      testOut.textContent = (data && data.error) ? data.error : 'Request failed.';
      return;
    }
    var lines = [];
    lines.push('Database hits: ' + (data.hit_count != null ? data.hit_count : (data.hits || []).length));
    (data.hits || []).forEach(function (h, i) {
      lines.push('');
      lines.push(String(i + 1) + '. [' + (h.chapter || '') + ' / ' + (h.block_local_id || '') + ']');
      lines.push(h.snippet || '');
    });
    if (data.ai_answer) {
      lines.push('');
      lines.push('--- AI answer (from excerpts above) ---');
      lines.push(data.ai_answer);
    }
    if (data.ai_note) {
      lines.push('');
      lines.push(data.ai_note);
    }
    if (data.ai_error) {
      lines.push('');
      lines.push('AI error: ' + data.ai_error);
    }
    testOut.textContent = lines.join('\n');
  }

  function runTestSearch(useAi) {
    if (!searchApi) {
      if (testOut) testOut.textContent = 'Search API URL missing.';
      return;
    }
    var id = parseInt(document.getElementById('rlFieldId').value, 10);
    var q = testQuery ? (testQuery.value || '').trim() : '';
    if (!id) return;
    if (!q) {
      showMsg('Enter a topic or question first.', 'err');
      return;
    }
    if (testOut) testOut.textContent = useAi ? 'Calling AI…' : 'Searching…';
    var body = { edition_id: id, query: q, use_ai: !!useAi };
    fetch(searchApi, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j) {
          renderTestResults({ ok: false, error: (x.j && x.j.error) || 'Search failed' });
          return;
        }
        if (!x.j.ok) {
          renderTestResults({ ok: false, error: x.j.error || 'Search failed' });
          return;
        }
        renderTestResults(x.j);
      })
      .catch(function (e) {
        renderTestResults({ ok: false, error: e.message || 'Network error' });
      });
  }

  if (testDbBtn) testDbBtn.addEventListener('click', function () { clearMsg(); runTestSearch(false); });
  if (testAiBtn) testAiBtn.addEventListener('click', function () { clearMsg(); runTestSearch(true); });

  if (delBtn) delBtn.addEventListener('click', function () {
    clearMsg();
    var id = parseInt(document.getElementById('rlFieldId').value, 10);
    if (!id) return;
    if (!window.confirm('Remove the uploaded JSON file from the server for this edition?')) return;
    delBtn.disabled = true;
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'delete_source', id: id })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Remove failed');
        setSourceUI(x.j.source);
        if (x.j.blocks) setBlocksUI(x.j.blocks);
        showMsg('JSON file removed.', 'ok');
      })
      .catch(function (e) {
        showMsg(e.message || 'Remove failed', 'err');
      })
      .finally(function () { delBtn.disabled = false; });
  });
})();
</script>

<?php cw_footer(); ?>
