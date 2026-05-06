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
            <th>File</th>
            <th>SHA-256</th>
            <th>Created</th>
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
      Once chunks are published from approved imports, this box will search indexed text. Until then, results stay empty by design.
    </p>
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
    el.className = 'rl-msg rl-easa-msg' + (text ? (kind === 'ok' ? ' is-ok' : ' is-error') : '');
  }

  function loadStatus() {
    var hint = document.getElementById('rlEasaMigrateHint');
    fetch(api + '?action=status', { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Status failed');
        if (hint) {
          hint.textContent = x.j.migrate_hint || ('Indexed nodes (canonical): ' + (x.j.indexed_nodes || 0) + '. ' + (x.j.indexed_hint || ''));
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
            tr.innerHTML = '<td>' + esc(b.id) + '</td>'
              + '<td>' + esc(b.status) + '</td>'
              + '<td>' + esc(b.original_filename) + '</td>'
              + '<td title="' + esc(b.file_sha256 || '') + '">' + esc(sha) + '</td>'
              + '<td>' + esc(b.created_at || '') + '</td>';
            btbody.appendChild(tr);
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
      fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
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
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'search', query: q })
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Search failed');
          var lines = [];
          lines.push('Hits: ' + (x.j.hit_count || 0));
          if (x.j.note) lines.push(x.j.note);
          (x.j.hits || []).forEach(function (h, i) {
            lines.push('');
            lines.push(String(i + 1) + '. ' + JSON.stringify(h));
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
