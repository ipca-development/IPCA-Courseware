<?php
declare(strict_types=1);
/** @var string $pdfApiHref */
$pdfApiHref = '/admin/api/resource_library_pdf_api.php';
$pdfTablesOk = rl_pdf_tables_ok($pdo);
$pdfPdftotext = rl_pdf_pdftotext_probe();
?>
<section class="card" style="padding:14px 16px;">
  <div class="tcc-muted">
    <strong>Online PDF Crawler</strong> — register official PDF sources (JUSTEL, BPPE, California codes, etc.), monitor file SHA-256 changes,
    parse articles into staging, review diffs, and publish to indexed blocks only after admin approval. Never auto-promotes to live AI use.
  </div>
  <?php if (!$pdfTablesOk): ?>
    <div class="rl-alert" style="margin-top:12px;margin-bottom:0;">
      Apply <code>scripts/sql/resource_library_pdf_crawler.sql</code> to enable PDF batches and staging.
    </div>
  <?php elseif (!$pdfPdftotext['available']): ?>
    <div class="rl-alert" style="margin-top:12px;margin-bottom:0;">
      <?= h(rl_pdf_pdftotext_required_error()) ?> On Debian/Ubuntu: <code>apt install poppler-utils</code>.
    </div>
  <?php endif; ?>
</section>

<div class="rl-wrap rl-tab-panel" id="rlPdfPage" data-pdf-api="<?= h($pdfApiHref) ?>">
  <div class="rl-hero-head" style="margin-bottom:16px;">
    <div class="rl-hero-head-main">
      <h2 class="rl-card-title" style="margin:0;font-size:20px;">Online PDF Crawler</h2>
      <p class="rl-intro" style="margin:8px 0 0;">
        Monitor official PDF sources, detect changes, parse articles, and approve them for AI retrieval.
      </p>
    </div>
    <button type="button" class="rl-hero-action" id="rlPdfAddBtn" style="flex-shrink:0;">+ Add PDF Source</button>
  </div>

  <div id="rlPdfGrid" class="rl-grid" aria-live="polite">
    <p class="rl-intro" id="rlPdfLoading">Loading PDF sources…</p>
  </div>
</div>

<style>
  .rl-pdf-pill {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 4px 9px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
  }
  .rl-pdf-pill--live { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
  .rl-pdf-pill--review { background: #fef3c7; color: #92400e; border-color: #fde68a; }
  .rl-pdf-pill--changed { background: #ffedd5; color: #9a3412; border-color: #fed7aa; }
  .rl-pdf-pill--monitor { background: #e0e7ff; color: #1e3a8a; border-color: #c7d2fe; }
  .rl-pdf-pill--failed { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
  .rl-pdf-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 12px 16px 16px;
    border-top: 1px solid #e2e8f0;
  }
  .rl-pdf-card-actions .btn { font-size: 12px; padding: 6px 10px; }
  .rl-pdf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .rl-pdf-table th, .rl-pdf-table td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
  .rl-pdf-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
  .rl-pdf-diff-grid { display: grid; gap: 12px; max-height: 60vh; overflow: auto; }
  .rl-pdf-diff-item { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; background: #f8fafc; }
  .rl-pdf-diff-item h4 { margin: 0 0 8px; font-size: 14px; }
  .rl-pdf-diff-excerpt { font-size: 12px; color: #334155; white-space: pre-wrap; line-height: 1.45; }
</style>

<div class="rl-backdrop" id="rlPdfSourceBackdrop" aria-hidden="true">
  <div class="rl-modal" role="dialog" aria-modal="true" aria-labelledby="rlPdfModalTitle" tabindex="-1" style="max-width:640px;">
    <div class="rl-modal-head">
      <div>
        <h2 class="rl-modal-title" id="rlPdfModalTitle">PDF source</h2>
        <p class="rl-modal-sub" id="rlPdfModalSub"></p>
      </div>
      <button type="button" class="rl-modal-close" id="rlPdfSourceClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-modal-body">
      <div id="rlPdfFormMsg" class="rl-drop-meta" style="margin-bottom:10px;"></div>
      <input type="hidden" id="rlPdfFieldId" value="">
      <div class="rl-field">
        <label for="rlPdfFieldTitle">Title</label>
        <input type="text" id="rlPdfFieldTitle" maxlength="512" required>
      </div>
      <div class="rl-row2">
        <div class="rl-field">
          <label for="rlPdfFieldAuthority">Source authority</label>
          <input type="text" id="rlPdfFieldAuthority" maxlength="128" placeholder="JUSTEL, BPPE, …">
        </div>
        <div class="rl-field">
          <label for="rlPdfFieldJurisdiction">Jurisdiction</label>
          <input type="text" id="rlPdfFieldJurisdiction" maxlength="128" placeholder="Belgium, California, …">
        </div>
      </div>
      <div class="rl-row2">
        <div class="rl-field">
          <label for="rlPdfFieldLanguage">Language</label>
          <input type="text" id="rlPdfFieldLanguage" maxlength="64" placeholder="nl, en, …">
        </div>
        <div class="rl-field">
          <label for="rlPdfFieldStatus">Edition status</label>
          <select id="rlPdfFieldStatus">
            <option value="draft">Draft</option>
            <option value="live">Live</option>
            <option value="archived">Archived</option>
          </select>
        </div>
      </div>
      <div class="rl-field">
        <label for="rlPdfFieldUrl">Official PDF URL</label>
        <input type="url" id="rlPdfFieldUrl" maxlength="2048" placeholder="https://…" autocomplete="off">
      </div>
      <div class="rl-field">
        <label for="rlPdfFieldInterval">Automatically check &amp; download</label>
        <select id="rlPdfFieldInterval">
          <option value="off">Off</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
      </div>
      <div class="rl-field">
        <label for="rlPdfFieldTags">Applicability tags (comma-separated)</label>
        <input type="text" id="rlPdfFieldTags" placeholder="student_contract, belgium, consumer_law">
        <p class="rl-drop-meta">Used in block metadata for compliance-aware retrieval filters later.</p>
      </div>
      <div class="rl-field">
        <label for="rlPdfFieldNotes">Notes</label>
        <textarea id="rlPdfFieldNotes" rows="3" style="width:100%;resize:vertical;"></textarea>
      </div>
      <div class="rl-field">
        <button type="button" class="btn btn-sm" id="rlPdfTestUrlBtn">Test URL</button>
        <pre id="rlPdfTestOut" class="rl-drop-meta" style="margin-top:8px;white-space:pre-wrap;"></pre>
      </div>
    </div>
    <div class="rl-modal-foot">
      <button type="button" class="btn" id="rlPdfSourceCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="rlPdfSourceSave">Save</button>
    </div>
  </div>
</div>

<div class="rl-backdrop" id="rlPdfBatchBackdrop" aria-hidden="true">
  <div class="rl-modal" role="dialog" aria-modal="true" style="max-width:900px;">
    <div class="rl-modal-head">
      <div>
        <h2 class="rl-modal-title">PDF batches</h2>
        <p class="rl-modal-sub" id="rlPdfBatchSub"></p>
      </div>
      <button type="button" class="rl-modal-close" id="rlPdfBatchClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-modal-body" style="overflow-x:auto;">
      <div id="rlPdfBatchMsg" class="rl-drop-meta"></div>
      <table class="rl-pdf-table" id="rlPdfBatchTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>Downloaded</th>
            <th>SHA-256</th>
            <th>Status</th>
            <th>Articles</th>
            <th>Diff</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="rlPdfBatchBody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="rl-backdrop" id="rlPdfDiffBackdrop" aria-hidden="true">
  <div class="rl-modal" role="dialog" aria-modal="true" style="max-width:900px;">
    <div class="rl-modal-head">
      <div>
        <h2 class="rl-modal-title">Article diff</h2>
        <p class="rl-modal-sub" id="rlPdfDiffSub"></p>
      </div>
      <button type="button" class="rl-modal-close" id="rlPdfDiffClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-modal-body">
      <div id="rlPdfDiffSummary" class="rl-drop-meta"></div>
      <div id="rlPdfDiffList" class="rl-pdf-diff-grid"></div>
    </div>
  </div>
</div>

<div class="rl-backdrop" id="rlPdfArticlesBackdrop" aria-hidden="true">
  <div class="rl-modal" role="dialog" aria-modal="true" style="max-width:900px;">
    <div class="rl-modal-head">
      <div>
        <h2 class="rl-modal-title">Extracted articles</h2>
        <p class="rl-modal-sub" id="rlPdfArticlesSub"></p>
      </div>
      <button type="button" class="rl-modal-close" id="rlPdfArticlesClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-modal-body" style="max-height:65vh;overflow:auto;">
      <table class="rl-pdf-table">
        <thead><tr><th>Key</th><th>Title</th><th>State</th><th>Excerpt</th></tr></thead>
        <tbody id="rlPdfArticlesBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  var page = document.getElementById('rlPdfPage');
  if (!page) return;
  var api = page.getAttribute('data-pdf-api') || '';
  var grid = document.getElementById('rlPdfGrid');
  var sources = [];

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function pdfApiGet(action, params) {
    var q = new URLSearchParams(params || {});
    q.set('action', action);
    return fetch(api + '?' + q.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
  }

  function pdfApiPost(body) {
    return fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
  }

  function pillClass(st) {
    if (st === 'live') return 'rl-pdf-pill rl-pdf-pill--live';
    if (st === 'ready_for_review') return 'rl-pdf-pill rl-pdf-pill--review';
    if (st === 'changed') return 'rl-pdf-pill rl-pdf-pill--changed';
    if (st === 'monitoring') return 'rl-pdf-pill rl-pdf-pill--monitor';
    if (st === 'failed') return 'rl-pdf-pill rl-pdf-pill--failed';
    return 'rl-pdf-pill';
  }

  function fmtUtc(iso) {
    if (!iso) return '—';
    try {
      var d = new Date(iso);
      return d.toLocaleString(undefined, { timeZone: 'UTC' }) + ' UTC';
    } catch (e) { return iso; }
  }

  function renderGrid() {
    if (!grid) return;
    if (!sources.length) {
      grid.innerHTML = '<p class="rl-intro">No PDF sources yet. Click <strong>+ Add PDF Source</strong> to register an official PDF URL.</p>';
      return;
    }
    var html = '';
    sources.forEach(function (row) {
      var s = row.source || {};
      var id = row.id;
      var st = row.display_status || 'draft';
      var tags = (s.applicability_tags || []).join(', ');
      var mon = s.pdf_monitor_state || {};
      var lastChk = mon.last_checked_at || (s.source_verify_state && s.source_verify_state.checked_at) || '';
      var lastDl = mon.last_downloaded_at || '';
      var sha = row.published_sha256 || s.published_file_sha256 || '—';
      if (sha !== '—' && sha.length > 16) sha = sha.substring(0, 16) + '…';
      html += '<article class="rl-card" style="cursor:default;min-height:auto;">';
      html += '<div class="rl-card-body">';
      html += '<span class="rl-type-pill">PDF</span>';
      html += '<h2 class="rl-card-title">' + esc(s.label || 'PDF source') + '</h2>';
      html += '<dl class="rl-meta">';
      html += '<dt>Jurisdiction</dt><dd>' + esc(s.jurisdiction || '—') + '</dd>';
      html += '<dt>Language</dt><dd>' + esc(s.language || '—') + '</dd>';
      html += '<dt>Authority</dt><dd>' + esc(s.source_authority || '—') + '</dd>';
      html += '<dt>Official URL</dt><dd style="word-break:break-all;font-size:12px;">' + esc(s.official_pdf_url || '—') + '</dd>';
      html += '<dt>Display status</dt><dd><span class="' + pillClass(st) + '">' + esc(st.replace(/_/g, ' ')) + '</span></dd>';
      html += '<dt>Last checked</dt><dd>' + esc(fmtUtc(lastChk)) + '</dd>';
      html += '<dt>Last downloaded</dt><dd>' + esc(fmtUtc(lastDl)) + '</dd>';
      html += '<dt>Published SHA-256</dt><dd>' + esc(sha) + '</dd>';
      html += '<dt>Articles</dt><dd>' + esc(String(row.article_count || 0)) + '</dd>';
      html += '<dt>Applicability</dt><dd>' + esc(tags || '—') + '</dd>';
      html += '</dl></div>';
      html += '<div class="rl-pdf-card-actions">';
      html += '<button type="button" class="btn btn-sm" data-pdf-act="edit" data-id="' + id + '">Edit</button>';
      html += '<button type="button" class="btn btn-sm" data-pdf-act="test" data-id="' + id + '">Test URL</button>';
      html += '<button type="button" class="btn btn-sm" data-pdf-act="check" data-id="' + id + '">Check Now</button>';
      html += '<button type="button" class="btn btn-sm" data-pdf-act="batches" data-id="' + id + '">View Batches</button>';
      if (row.ready_batch_id) {
        html += '<button type="button" class="btn btn-sm" data-pdf-act="diff" data-batch="' + row.ready_batch_id + '">View Diff</button>';
        html += '<button type="button" class="btn btn-primary btn-sm" data-pdf-act="publish" data-batch="' + row.ready_batch_id + '">Publish Approved Batch</button>';
      }
      html += '<button type="button" class="btn btn-sm" data-pdf-act="archive" data-id="' + id + '">Archive</button>';
      html += '</div></article>';
    });
    grid.innerHTML = html;
  }

  function loadSources() {
    return pdfApiGet('list_sources').then(function (x) {
      if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Could not load sources');
      sources = x.j.sources || [];
      renderGrid();
    }).catch(function (err) {
      if (grid) grid.innerHTML = '<div class="rl-alert">' + esc(err.message) + '</div>';
    });
  }

  function openBackdrop(el) {
    if (!el) return;
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }
  function closeBackdrop(el) {
    if (!el) return;
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }

  var srcBd = document.getElementById('rlPdfSourceBackdrop');
  function openSourceModal(row) {
    document.getElementById('rlPdfFormMsg').textContent = '';
    document.getElementById('rlPdfTestOut').textContent = '';
    var isNew = !row;
    document.getElementById('rlPdfModalTitle').textContent = isNew ? 'Add PDF source' : 'Edit PDF source';
    document.getElementById('rlPdfFieldId').value = isNew ? '' : String(row.id);
    var s = isNew ? {} : (row.source || {});
    document.getElementById('rlPdfFieldTitle').value = s.label || '';
    document.getElementById('rlPdfFieldAuthority').value = s.source_authority || '';
    document.getElementById('rlPdfFieldJurisdiction').value = s.jurisdiction || '';
    document.getElementById('rlPdfFieldLanguage').value = s.language || '';
    document.getElementById('rlPdfFieldUrl').value = s.official_pdf_url || '';
    document.getElementById('rlPdfFieldInterval').value = s.source_verify_interval || 'weekly';
    document.getElementById('rlPdfFieldTags').value = (s.applicability_tags || []).join(', ');
    document.getElementById('rlPdfFieldNotes').value = s.notes || '';
    document.getElementById('rlPdfFieldStatus').value = s.status || 'draft';
    openBackdrop(srcBd);
  }

  function saveSource() {
    var id = parseInt(document.getElementById('rlPdfFieldId').value, 10);
    var tagsRaw = (document.getElementById('rlPdfFieldTags').value || '').split(/[\s,;]+/).filter(Boolean);
    var body = {
      title: (document.getElementById('rlPdfFieldTitle').value || '').trim(),
      source_authority: (document.getElementById('rlPdfFieldAuthority').value || '').trim(),
      jurisdiction: (document.getElementById('rlPdfFieldJurisdiction').value || '').trim(),
      language: (document.getElementById('rlPdfFieldLanguage').value || '').trim(),
      official_pdf_url: (document.getElementById('rlPdfFieldUrl').value || '').trim(),
      source_verify_interval: document.getElementById('rlPdfFieldInterval').value,
      applicability_tags: tagsRaw,
      notes: (document.getElementById('rlPdfFieldNotes').value || '').trim(),
      status: document.getElementById('rlPdfFieldStatus').value
    };
    if (!body.title) {
      document.getElementById('rlPdfFormMsg').textContent = 'Title is required.';
      return;
    }
    body.action = id ? 'update_source' : 'create_source';
    if (id) body.id = id;
    pdfApiPost(body).then(function (x) {
      if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Save failed');
      closeBackdrop(srcBd);
      return loadSources();
    }).catch(function (err) {
      document.getElementById('rlPdfFormMsg').textContent = err.message;
    });
  }

  var batchBd = document.getElementById('rlPdfBatchBackdrop');
  var batchEditionId = 0;

  function openBatches(editionId, title) {
    batchEditionId = editionId;
    document.getElementById('rlPdfBatchSub').textContent = title || ('Edition #' + editionId);
    document.getElementById('rlPdfBatchMsg').textContent = 'Loading…';
    document.getElementById('rlPdfBatchBody').innerHTML = '';
    openBackdrop(batchBd);
    pdfApiGet('list_batches', { edition_id: editionId }).then(function (x) {
      if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Load failed');
      document.getElementById('rlPdfBatchMsg').textContent = '';
      var rows = x.j.batches || [];
      if (!rows.length) {
        document.getElementById('rlPdfBatchBody').innerHTML = '<tr><td colspan="7">No batches yet. Use Check Now to download a PDF.</td></tr>';
        return;
      }
      var tb = '';
      rows.forEach(function (b) {
        var sha = (b.file_sha256 || '').substring(0, 12) + '…';
        var diff = '+' + (b.new_article_count || 0) + ' ~' + (b.changed_article_count || 0) + ' -' + (b.removed_article_count || 0);
        tb += '<tr>';
        tb += '<td>' + esc(b.id) + '</td>';
        tb += '<td>' + esc(fmtUtc(b.downloaded_at)) + '</td>';
        tb += '<td title="' + esc(b.file_sha256) + '">' + esc(sha) + '</td>';
        tb += '<td>' + esc(b.status) + (b.error_message ? '<br><span style="color:#b91c1c">' + esc(b.error_message) + '</span>' : '') + '</td>';
        tb += '<td>' + esc(String(b.article_count || 0)) + '</td>';
        tb += '<td>' + esc(diff) + '</td>';
        tb += '<td style="white-space:nowrap">';
        tb += '<button type="button" class="btn btn-sm" data-batch-parse="' + b.id + '">Parse</button> ';
        tb += '<button type="button" class="btn btn-sm" data-batch-articles="' + b.id + '">Articles</button> ';
        tb += '<button type="button" class="btn btn-sm" data-batch-diff="' + b.id + '">Diff</button> ';
        if (b.status === 'ready_for_review') {
          tb += '<button type="button" class="btn btn-primary btn-sm" data-batch-publish="' + b.id + '">Publish</button> ';
          tb += '<button type="button" class="btn btn-sm" data-batch-reject="' + b.id + '">Reject</button>';
        }
        tb += '</td></tr>';
      });
      document.getElementById('rlPdfBatchBody').innerHTML = tb;
    }).catch(function (err) {
      document.getElementById('rlPdfBatchMsg').textContent = err.message;
    });
  }

  function openDiff(batchId) {
    document.getElementById('rlPdfDiffSub').textContent = 'Batch #' + batchId;
    document.getElementById('rlPdfDiffSummary').textContent = 'Loading…';
    document.getElementById('rlPdfDiffList').innerHTML = '';
    openBackdrop(document.getElementById('rlPdfDiffBackdrop'));
    pdfApiGet('view_diff', { batch_id: batchId }).then(function (x) {
      if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Diff failed');
      var sum = x.j.summary || {};
      document.getElementById('rlPdfDiffSummary').textContent =
        'New: ' + (sum.new || 0) + ' · Changed: ' + (sum.changed || 0) +
        ' · Removed: ' + (sum.removed || 0) + ' · Unchanged: ' + (sum.unchanged || 0);
      var diffs = x.j.diffs || [];
      if (!diffs.length) {
        document.getElementById('rlPdfDiffList').innerHTML = '<p class="rl-intro">No diff rows (parse batch first).</p>';
        return;
      }
      var html = '';
      diffs.forEach(function (d) {
        if (d.change_type === 'unchanged') return;
        html += '<div class="rl-pdf-diff-item">';
        html += '<h4>' + esc(d.article_key) + ' <span class="rl-pdf-pill">' + esc(d.change_type) + '</span></h4>';
        if (d.old_content_hash || d.new_content_hash) {
          html += '<p class="rl-drop-meta">Hash: ' + esc(d.old_content_hash || '—') + ' → ' + esc(d.new_content_hash || '—') + '</p>';
        }
        if (d.old_excerpt) {
          html += '<div><strong>Before</strong><div class="rl-pdf-diff-excerpt">' + esc(d.old_excerpt) + '</div></div>';
        }
        if (d.new_excerpt) {
          html += '<div style="margin-top:8px"><strong>After</strong><div class="rl-pdf-diff-excerpt">' + esc(d.new_excerpt) + '</div></div>';
        }
        html += '</div>';
      });
      document.getElementById('rlPdfDiffList').innerHTML = html || '<p class="rl-intro">No new/changed/removed articles in this diff.</p>';
    }).catch(function (err) {
      document.getElementById('rlPdfDiffSummary').textContent = err.message;
    });
  }

  function openArticles(batchId) {
    document.getElementById('rlPdfArticlesSub').textContent = 'Batch #' + batchId;
    document.getElementById('rlPdfArticlesBody').innerHTML = '<tr><td colspan="4">Loading…</td></tr>';
    openBackdrop(document.getElementById('rlPdfArticlesBackdrop'));
    pdfApiGet('list_articles', { batch_id: batchId }).then(function (x) {
      if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Load failed');
      var rows = x.j.articles || [];
      if (!rows.length) {
        document.getElementById('rlPdfArticlesBody').innerHTML = '<tr><td colspan="4">No articles (run Parse first).</td></tr>';
        return;
      }
      var tb = '';
      rows.forEach(function (a) {
        tb += '<tr><td>' + esc(a.article_key) + '</td><td>' + esc(a.article_title) + '</td><td>' + esc(a.legal_state) + '</td><td>' + esc(a.excerpt) + '</td></tr>';
      });
      document.getElementById('rlPdfArticlesBody').innerHTML = tb;
    }).catch(function (err) {
      document.getElementById('rlPdfArticlesBody').innerHTML = '<tr><td colspan="4">' + esc(err.message) + '</td></tr>';
    });
  }

  function publishBatch(batchId) {
    if (!confirm('Publish this batch to live searchable blocks? The edition will be set to Live.')) return;
    pdfApiPost({ action: 'publish_batch', batch_id: batchId, set_live: true }).then(function (x) {
      if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Publish failed');
      alert('Published ' + (x.j.blocks || 0) + ' blocks.');
      closeBackdrop(batchBd);
      return loadSources();
    }).catch(function (err) { alert(err.message); });
  }

  document.getElementById('rlPdfAddBtn').addEventListener('click', function () { openSourceModal(null); });
  document.getElementById('rlPdfSourceClose').addEventListener('click', function () { closeBackdrop(srcBd); });
  document.getElementById('rlPdfSourceCancel').addEventListener('click', function () { closeBackdrop(srcBd); });
  document.getElementById('rlPdfSourceSave').addEventListener('click', saveSource);
  document.getElementById('rlPdfTestUrlBtn').addEventListener('click', function () {
    var url = (document.getElementById('rlPdfFieldUrl').value || '').trim();
    var id = parseInt(document.getElementById('rlPdfFieldId').value, 10);
    var body = { action: 'test_url', url: url };
    if (id) body.id = id;
    document.getElementById('rlPdfTestOut').textContent = 'Testing…';
    pdfApiPost(body).then(function (x) {
      var j = x.j || {};
      if (!x.ok || !j.ok) {
        document.getElementById('rlPdfTestOut').textContent = j.error || 'Failed';
        return;
      }
      document.getElementById('rlPdfTestOut').textContent =
        'HTTP ' + j.http_code + '\nFinal: ' + (j.final_url || '') + '\n' + (j.reachable ? 'Reachable' : 'Not reachable') +
        (j.error ? '\n' + j.error : '');
    });
  });

  document.getElementById('rlPdfBatchClose').addEventListener('click', function () { closeBackdrop(batchBd); });
  document.getElementById('rlPdfDiffClose').addEventListener('click', function () { closeBackdrop(document.getElementById('rlPdfDiffBackdrop')); });
  document.getElementById('rlPdfArticlesClose').addEventListener('click', function () { closeBackdrop(document.getElementById('rlPdfArticlesBackdrop')); });

  if (grid) {
    grid.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-pdf-act]');
      if (!btn) return;
      var act = btn.getAttribute('data-pdf-act');
      var id = parseInt(btn.getAttribute('data-id'), 10);
      var batchId = parseInt(btn.getAttribute('data-batch'), 10);
      var row = sources.find(function (r) { return r.id === id; });
      if (act === 'edit' && row) openSourceModal(row);
      if (act === 'test' && row) {
        pdfApiPost({ action: 'test_url', id: id, url: row.source.official_pdf_url }).then(function (x) {
          alert((x.j && x.j.reachable) ? 'URL reachable (HTTP ' + x.j.http_code + ')' : ((x.j && x.j.error) || 'Not reachable'));
        });
      }
      if (act === 'check' && id) {
        btn.disabled = true;
        pdfApiPost({ action: 'check_now', id: id, force: true }).then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Check failed');
          alert(x.j.message || 'Done');
          return loadSources();
        }).catch(function (err) { alert(err.message); }).finally(function () { btn.disabled = false; });
      }
      if (act === 'batches' && row) openBatches(id, row.source.label);
      if (act === 'diff' && batchId) openDiff(batchId);
      if (act === 'publish' && batchId) publishBatch(batchId);
      if (act === 'archive' && id) {
        if (!confirm('Archive this PDF source?')) return;
        pdfApiPost({ action: 'update_source', id: id, title: row.source.label, status: 'archived',
          official_pdf_url: row.source.official_pdf_url }).then(function () { return loadSources(); });
      }
    });
  }

  document.getElementById('rlPdfBatchBody').addEventListener('click', function (e) {
    var parseId = e.target.getAttribute('data-batch-parse');
    var artId = e.target.getAttribute('data-batch-articles');
    var diffId = e.target.getAttribute('data-batch-diff');
    var pubId = e.target.getAttribute('data-batch-publish');
    var rejId = e.target.getAttribute('data-batch-reject');
    if (parseId) {
      pdfApiPost({ action: 'parse_batch', batch_id: parseInt(parseId, 10) }).then(function (x) {
        if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Parse failed');
        alert('Parsed ' + (x.j.articles || 0) + ' articles.');
        openBatches(batchEditionId, '');
      }).catch(function (err) { alert(err.message); });
    }
    if (artId) openArticles(parseInt(artId, 10));
    if (diffId) openDiff(parseInt(diffId, 10));
    if (pubId) publishBatch(parseInt(pubId, 10));
    if (rejId) {
      if (!confirm('Reject this batch?')) return;
      pdfApiPost({ action: 'reject_batch', batch_id: parseInt(rejId, 10) }).then(function () {
        openBatches(batchEditionId, '');
      }).catch(function (err) { alert(err.message); });
    }
  });

  loadSources();
})();
</script>
