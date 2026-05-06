<?php
declare(strict_types=1);

/** @var string $easaApiHref */

if (!isset($easaApiHref) || $easaApiHref === '') {
    $easaApiHref = '/admin/api/resource_library_easa_api.php';
}
?>
<style>
  .rl-easa-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 16px;
    align-items: start;
  }
  .rl-easa-panel h3 {
    margin: 0 0 8px;
    font-size: 15px;
    color: #102845;
  }
  .rl-easa-panel .rl-drop-meta { margin-top: 6px; }
  .rl-easa-badge {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 3px 8px;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0369a1;
    margin-bottom: 10px;
  }
  .rl-easa-table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    max-height: 280px;
    overflow-y: auto;
  }
  .rl-easa-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }
  .rl-easa-table th,
  .rl-easa-table td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
  }
  .rl-easa-table th {
    background: #f8fafc;
    font-weight: 700;
    color: #475569;
    position: sticky;
    top: 0;
    z-index: 1;
  }
  .rl-easa-flag {
    color: #b45309;
    font-weight: 800;
  }
  .rl-easa-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  @media (max-width: 900px) {
    .rl-easa-split { grid-template-columns: 1fr; }
  }
  .rl-msg.rl-easa-msg.is-info {
    display: block;
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
  }
</style>

<section class="card" style="padding:14px 16px;">
  <div class="tcc-muted">
    This tab is dedicated to <strong>EASA Easy Access Rules</strong> (official XML evidence, staging, and retrieval). It complements your broader
    <strong>Compliance &amp; Safety</strong> workflows and stays separate from PHAK JSON books, crawlers, and generic APIs.
    U.S. <strong>eCFR</strong> remains on the <a href="/admin/resource_library.php?tab=apis">APIs</a> tab for versioner configuration; you can still pull a section here for side‑by‑side comparison.
  </div>
</section>

<div class="rl-wrap rl-tab-panel rl-easa-page" id="rlEasaPage" data-api="<?= h($easaApiHref) ?>">
  <div class="rl-easa-grid">
    <section class="card rl-easa-panel" style="padding:16px 18px;">
      <span class="rl-easa-badge">Import center</span>
      <h3>Official XML upload</h3>
      <p class="rl-drop-meta" style="margin-top:0;">
        Download the current export from EASA manually when needed, then upload here. Files are stored immutably under
        <code>storage/easa_erules/</code> for audit. Parsing into staging / canonical tables follows in a separate pipeline step.
      </p>
      <div class="rl-panel-actions" style="margin-top:10px;">
        <input type="file" id="rlEasaXmlFile" accept=".xml,application/xml,text/xml" style="max-width:240px;font-size:13px;">
        <button type="button" class="btn btn-sm" id="rlEasaUploadBtn">Upload XML</button>
      </div>
      <p class="rl-msg rl-easa-msg" id="rlEasaUploadMsg" role="status" style="margin-top:12px;"></p>
    </section>

    <section class="card rl-easa-panel" style="padding:16px 18px;">
      <span class="rl-easa-badge">Official page monitor</span>
      <h3>EASA download page probe</h3>
      <p class="rl-drop-meta" style="margin-top:0;">
        Daily cron runs a polite HEAD check (see <code>cli/cron_easa_download_monitor.php</code>). When headers change, the row flags
        <strong>Update suspected</strong> so Compliance can fetch a fresh XML export.
      </p>
      <div class="rl-panel-actions" style="margin-top:10px;">
        <button type="button" class="btn btn-sm" id="rlEasaProbeBtn">Probe now</button>
      </div>
      <p class="rl-drop-meta" id="rlEasaMigrateHint" style="margin-top:10px;"></p>
    </section>
  </div>

  <section class="card rl-easa-panel" style="padding:14px 18px; margin-top:12px; border-left:4px solid #0369a1;">
    <span class="rl-easa-badge">Import activity</span>
    <h3 style="margin:0 0 6px; font-size:15px;">Parse status</h3>
    <p class="rl-drop-meta" style="margin:0;">Large XML files are processed as a <strong>stream</strong> (no whole-file DOM). Progress appears below while the server inserts rows; if PHP-FPM is available, the UI returns immediately and polls until completion.</p>
    <pre class="rl-test-out" id="rlEasaParseProgress" aria-live="polite" style="margin-top:10px; max-height:120px; min-height:2.5rem;">—</pre>
  </section>

  <section class="card rl-easa-panel" style="padding:16px 18px; margin-top:12px;">
    <span class="rl-easa-badge">Watch list</span>
    <h3>Monitored URLs</h3>
    <div class="rl-easa-table-wrap" id="rlEasaMonitorWrap">
      <table class="rl-easa-table" id="rlEasaMonitorTable">
        <thead>
          <tr>
            <th>Label</th>
            <th>Last check (UTC)</th>
            <th>HTTP</th>
            <th>Update?</th>
          </tr>
        </thead>
        <tbody id="rlEasaMonitorBody"></tbody>
      </table>
    </div>
  </section>

  <section class="card rl-easa-panel" style="padding:16px 18px; margin-top:12px;">
    <span class="rl-easa-badge">Upload history</span>
    <h3>Recent batches</h3>
    <div class="rl-easa-table-wrap">
      <table class="rl-easa-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Status</th>
            <th>Staging rows</th>
            <th>File</th>
            <th>SHA-256</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="rlEasaBatchBody"></tbody>
      </table>
    </div>
  </section>

  <section class="card rl-easa-panel" style="padding:16px 18px; margin-top:12px;">
    <span class="rl-easa-badge">Quick retrieval</span>
    <h3>Search &amp; AI (EASA corpus)</h3>
    <p class="rl-drop-meta" style="margin-top:0;">
      Searches <strong>staging</strong> plain text after you parse an upload (LIKE match, capped at 25 hits). Optional batch id limits scope.
    </p>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaSearchBatch">Batch ID (optional)</label>
      <input type="number" id="rlEasaSearchBatch" placeholder="Leave empty = all batches" min="1" step="1" style="max-width:160px;">
    </div>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaSearchQ">Topic or keyword</label>
      <input type="text" id="rlEasaSearchQ" placeholder="e.g. FCL.055, alternate aerodrome, continuing airworthiness" autocomplete="off" style="width:100%;max-width:520px;">
    </div>
    <div class="rl-test-actions">
      <button type="button" class="btn btn-sm" id="rlEasaSearchBtn">Search EASA index</button>
    </div>
    <pre class="rl-test-out" id="rlEasaSearchOut" aria-live="polite" style="margin-top:12px; max-height:200px;"></pre>
  </section>

  <section class="card rl-easa-panel" style="padding:16px 18px; margin-top:12px;">
    <span class="rl-easa-badge">Cross‑jurisdiction</span>
    <h3>Compare EASA concepts with U.S. eCFR (optional)</h3>
    <p class="rl-drop-meta" style="margin-top:0;">
      Uses your configured <strong>eCFR versioner API</strong> (same as training reports) when you tick “Include 14 CFR excerpt”.
      EASA indexed text appears here after ingest; until then the model is explicitly told the EU corpus is not loaded.
    </p>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaCompareQ">Question</label>
      <input type="text" id="rlEasaCompareQ" placeholder="e.g. How does pilot recency compare EU vs US?" autocomplete="off" style="width:100%;">
    </div>
    <div class="rl-easa-split">
      <div class="rl-field">
        <label for="rlEasaEcfrTitle">14 CFR Title number</label>
        <input type="number" id="rlEasaEcfrTitle" value="14" min="1" step="1">
      </div>
      <div class="rl-field">
        <label for="rlEasaEcfrSec">Section (e.g. 61.57)</label>
        <input type="text" id="rlEasaEcfrSec" placeholder="61.57" autocomplete="off">
      </div>
    </div>
    <div class="rl-field" style="margin-top:8px;">
      <label class="rl-check-row" style="display:flex;gap:8px;align-items:flex-start;">
        <input type="checkbox" id="rlEasaIncludeEcfr" style="margin-top:3px;">
        <span class="rl-check-label">Include live U.S. eCFR excerpt for comparison (requires API row on the APIs tab)</span>
      </label>
    </div>
    <div class="rl-field" style="margin-top:8px;">
      <label class="rl-check-row" style="display:flex;gap:8px;align-items:flex-start;">
        <input type="checkbox" id="rlEasaUseAi" checked style="margin-top:3px;">
        <span class="rl-check-label">Ask AI (uses OpenAI when configured)</span>
      </label>
    </div>
    <div class="rl-test-actions">
      <button type="button" class="btn btn-sm" id="rlEasaCompareBtn">Run comparison</button>
    </div>
    <pre class="rl-test-out" id="rlEasaCompareOut" aria-live="polite" style="margin-top:12px; max-height:320px;"></pre>
  </section>
</div>

<script>
(function () {
  var root = document.getElementById('rlEasaPage');
  if (!root) return;
  var api = root.getAttribute('data-api') || '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] || c;
    });
  }

  function setUploadMsg(text, kind) {
    var el = document.getElementById('rlEasaUploadMsg');
    if (!el) return;
    el.textContent = text || '';
    var suffix = '';
    if (text) {
      if (kind === 'ok') suffix = ' is-ok';
      else if (kind === 'info') suffix = ' is-info';
      else suffix = ' is-error';
    }
    el.className = 'rl-msg rl-easa-msg' + suffix;
  }

  function rlEasaParseUploadResponse(r) {
    return r.text().then(function (t) {
      var j = null;
      if (t) {
        try {
          j = JSON.parse(t);
        } catch (e) {
          /* fall through */
        }
      }
      if (!j || typeof j !== 'object') {
        var snippet = String(t || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 280);
        throw new Error(snippet || ('HTTP ' + r.status + ' ' + (r.statusText || '')));
      }
      return { ok: r.ok, j: j };
    });
  }

  function loadStatus() {
    var hint = document.getElementById('rlEasaMigrateHint');
    fetch(api + '?action=status', { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Status failed');
        if (hint) {
          var parts = [];
          if (x.j.migrate_hint) parts.push(x.j.migrate_hint);
          if (x.j.staging_migrate_hint) parts.push(x.j.staging_migrate_hint);
          if (x.j.progress_migrate_hint) parts.push(x.j.progress_migrate_hint);
          parts.push('Staging nodes: ' + (x.j.indexed_nodes || 0) + '. ' + (x.j.indexed_hint || ''));
          if (x.j.supports_async_parse) parts.push('Async parse after button click: enabled (PHP-FPM).');
          hint.textContent = parts.filter(Boolean).join(' ');
        }
        var tbody = document.getElementById('rlEasaMonitorBody');
        if (tbody) {
          tbody.innerHTML = '';
          (x.j.monitor || []).forEach(function (row) {
            var tr = document.createElement('tr');
            var lab = row.label || row.url || '—';
            var chk = row.checked_at || '—';
            var http = row.http_status != null ? String(row.http_status) : '—';
            var flag = row.changed_flag ? '<span class="rl-easa-flag">Yes — review</span>' : '—';
            tr.innerHTML = '<td>' + esc(lab) + '<div class="rl-drop-meta" style="margin-top:4px;word-break:break-all;">' + esc(row.url || '') + '</div></td>'
              + '<td>' + esc(chk) + '</td>'
              + '<td>' + esc(http) + '</td>'
              + '<td>' + flag + '</td>';
            tbody.appendChild(tr);
          });
        }
        var btbody = document.getElementById('rlEasaBatchBody');
        if (btbody) {
          btbody.innerHTML = '';
          (x.j.batches || []).forEach(function (b) {
            var tr = document.createElement('tr');
            var sha = (b.file_sha256 || '').substring(0, 16) + '…';
            var sn = b.staging_nodes != null ? String(b.staging_nodes) : '—';
            var bid = parseInt(b.id, 10) || 0;
            tr.innerHTML = '<td>' + esc(b.id) + '</td>'
              + '<td>' + esc(b.status) + '</td>'
              + '<td>' + esc(sn) + '</td>'
              + '<td>' + esc(b.original_filename) + '</td>'
              + '<td title="' + esc(b.file_sha256 || '') + '">' + esc(sha) + '</td>'
              + '<td>' + esc(b.created_at || '') + '</td>'
              + '<td><button type="button" class="btn btn-sm rl-easa-parse" data-batch-id="' + bid + '">Parse XML → staging</button></td>';
            btbody.appendChild(tr);
          });
          btbody.querySelectorAll('.rl-easa-parse').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var id = parseInt(btn.getAttribute('data-batch-id') || '0', 10);
              if (!id) return;
              btn.disabled = true;
              var prog = document.getElementById('rlEasaParseProgress');
              if (prog) prog.textContent = 'Starting parse…';
              fetch(api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'parse_batch', batch_id: id })
              })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
                .then(function (x) {
                  if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Parse failed');
                  if (x.j.async) {
                    if (prog) prog.textContent = 'Import running on server (batch ' + id + '). Polling status…';
                    var tries = 0;
                    var timer = setInterval(function () {
                      tries++;
                      fetch(api + '?action=batch_progress&batch_id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' })
                        .then(function (r2) { return r2.json(); })
                        .then(function (pj) {
                          if (!pj.ok || !pj.batch) return;
                          var b = pj.batch;
                          var line = [
                            'status=' + (b.status || ''),
                            'phase=' + (b.parse_phase || '—'),
                            'rows=' + (b.parse_rows_so_far != null ? b.parse_rows_so_far : '—'),
                            (b.parse_last_node_type ? 'last<' + b.parse_last_node_type + '>' : ''),
                            (b.parse_detail || '')
                          ].filter(Boolean).join(' · ');
                          if (prog) prog.textContent = line || '—';
                          if (b.status === 'ready_for_review' || b.status === 'failed') {
                            clearInterval(timer);
                            btn.disabled = false;
                            loadStatus();
                            var hint = document.getElementById('rlEasaMigrateHint');
                            if (hint && b.status === 'ready_for_review') {
                              hint.textContent = 'Import finished: ' + (b.rows_detected || b.parse_rows_so_far || 0) + ' nodes staged.';
                            }
                            if (hint && b.status === 'failed') {
                              hint.textContent = 'Import failed: ' + (b.error_message || b.parse_detail || 'see batch row');
                            }
                          }
                        })
                        .catch(function () { /* ignore transient */ });
                      if (tries > 800) {
                        clearInterval(timer);
                        btn.disabled = false;
                        if (prog) prog.textContent += '\nStopped polling after timeout; reload the page to see final status.';
                      }
                    }, 1500);
                    return;
                  }
                  if (prog) prog.textContent = 'Done: ' + (x.j.imported || 0) + ' nodes.';
                  var hint = document.getElementById('rlEasaMigrateHint');
                  if (hint) hint.textContent = x.j.message || ('Imported ' + (x.j.imported || 0) + ' nodes.');
                  loadStatus();
                })
                .catch(function (e) {
                  var hint = document.getElementById('rlEasaMigrateHint');
                  if (hint) hint.textContent = e.message || 'Parse failed';
                  if (prog) prog.textContent = e.message || 'Parse failed';
                })
                .finally(function () {
                  var progEl = document.getElementById('rlEasaParseProgress');
                  var asyncPoll = progEl && progEl.textContent.indexOf('Polling') >= 0;
                  if (!asyncPoll) btn.disabled = false;
                });
            });
          });
        }
      })
      .catch(function (e) {
        if (hint) hint.textContent = e.message || 'Could not load status';
      });
  }

  var uploadBtn = document.getElementById('rlEasaUploadBtn');
  var fileInp = document.getElementById('rlEasaXmlFile');
  if (uploadBtn && fileInp) {
    uploadBtn.addEventListener('click', function () {
      setUploadMsg('', '');
      if (!fileInp.files || !fileInp.files.length) {
        setUploadMsg('Choose an XML file first.', 'err');
        return;
      }
      var fd = new FormData();
      fd.append('erules_xml', fileInp.files[0]);
      uploadBtn.disabled = true;
      setUploadMsg('Uploading…', 'info');
      var msgEl = document.getElementById('rlEasaUploadMsg');
      if (msgEl && msgEl.scrollIntoView) msgEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(rlEasaParseUploadResponse)
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Upload failed');
          setUploadMsg(x.j.message || 'Uploaded.', 'ok');
          fileInp.value = '';
          loadStatus();
        })
        .catch(function (e) {
          setUploadMsg(e.message || 'Upload failed', 'err');
        })
        .finally(function () { uploadBtn.disabled = false; });
    });
  }

  var probeBtn = document.getElementById('rlEasaProbeBtn');
  if (probeBtn) {
    probeBtn.addEventListener('click', function () {
      probeBtn.disabled = true;
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'probe_monitor' })
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Probe failed');
          loadStatus();
        })
        .catch(function (e) {
          var hint = document.getElementById('rlEasaMigrateHint');
          if (hint) hint.textContent = e.message || 'Probe failed';
        })
        .finally(function () { probeBtn.disabled = false; });
    });
  }

  var searchBtn = document.getElementById('rlEasaSearchBtn');
  var searchOut = document.getElementById('rlEasaSearchOut');
  if (searchBtn) {
    searchBtn.addEventListener('click', function () {
      var qEl = document.getElementById('rlEasaSearchQ');
      var q = qEl ? (qEl.value || '').trim() : '';
      if (!q) {
        if (searchOut) searchOut.textContent = 'Enter a search query.';
        return;
      }
      if (searchOut) searchOut.textContent = 'Searching…';
      var batchEl = document.getElementById('rlEasaSearchBatch');
      var bid = batchEl && batchEl.value ? parseInt(batchEl.value, 10) : 0;
      var payload = { action: 'search', query: q };
      if (bid > 0) payload.batch_id = bid;
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Search failed');
          var lines = [];
          lines.push('Hits: ' + (x.j.hit_count || 0));
          if (x.j.note) lines.push(x.j.note);
          (x.j.hits || []).forEach(function (h, i) {
            lines.push('');
            lines.push(String(i + 1) + '. [' + (h.node_type || '') + '] ' + (h.source_erules_id || h.title || h.node_uid || ''));
            if (h.breadcrumb) lines.push('   Path: ' + h.breadcrumb);
            lines.push('   ' + (h.snippet || '').replace(/\s+/g, ' ').trim());
          });
          if (searchOut) searchOut.textContent = lines.join('\n');
        })
        .catch(function (e) {
          if (searchOut) searchOut.textContent = e.message || 'Error';
        });
    });
  }

  var cmpBtn = document.getElementById('rlEasaCompareBtn');
  var cmpOut = document.getElementById('rlEasaCompareOut');
  if (cmpBtn) {
    cmpBtn.addEventListener('click', function () {
      var qEl = document.getElementById('rlEasaCompareQ');
      var q = qEl ? (qEl.value || '').trim() : '';
      if (!q) {
        if (cmpOut) cmpOut.textContent = 'Enter a question.';
        return;
      }
      var titleEl = document.getElementById('rlEasaEcfrTitle');
      var secEl = document.getElementById('rlEasaEcfrSec');
      var incEcfr = document.getElementById('rlEasaIncludeEcfr');
      var useAi = document.getElementById('rlEasaUseAi');
      var body = {
        action: 'regulatory_compare_ai',
        query: q,
        use_ai: !!(useAi && useAi.checked),
        include_ecfr: !!(incEcfr && incEcfr.checked),
        ecfr_title_number: titleEl ? parseInt(titleEl.value, 10) || 14 : 14,
        ecfr_section: secEl ? (secEl.value || '').trim() : ''
      };
      if (cmpOut) cmpOut.textContent = 'Working…';
      cmpBtn.disabled = true;
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Request failed');
          var lines = [];
          if (x.j.easa_context_note) {
            lines.push('--- EASA side (index status) ---');
            lines.push(x.j.easa_context_note);
          }
          if (x.j.ecfr_note) {
            lines.push('');
            lines.push('--- U.S. eCFR ---');
            lines.push(x.j.ecfr_note);
          }
          if (x.j.ai_answer) {
            lines.push('');
            lines.push('--- AI comparison ---');
            lines.push(x.j.ai_answer);
          }
          if (x.j.ai_error) {
            lines.push('');
            lines.push('AI error: ' + x.j.ai_error);
          }
          if (cmpOut) cmpOut.textContent = lines.join('\n');
        })
        .catch(function (e) {
          if (cmpOut) cmpOut.textContent = e.message || 'Error';
        })
        .finally(function () { cmpBtn.disabled = false; });
    });
  }

  loadStatus();
})();
</script>
