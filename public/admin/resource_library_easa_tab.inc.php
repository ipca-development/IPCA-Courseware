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
  .rl-easa-upload-progress {
    margin-top: 10px;
    max-width: 420px;
  }
  .rl-easa-upload-progress[hidden] {
    display: none !important;
  }
  .rl-easa-upload-progress-track {
    height: 8px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
    position: relative;
  }
  .rl-easa-upload-progress-track.is-indeterminate::after {
    content: '';
    position: absolute;
    inset: 0;
    width: 35%;
    border-radius: 999px;
    background: linear-gradient(90deg, #93c5fd, #2563eb, #93c5fd);
    animation: rl-easa-upload-indet 1.1s ease-in-out infinite;
  }
  @keyframes rl-easa-upload-indet {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(320%); }
  }
  .rl-easa-upload-progress-bar {
    height: 100%;
    width: 0%;
    border-radius: 999px;
    background: linear-gradient(90deg, #3b82f6, #2563eb);
    transition: width 0.12s ease-out;
  }
  .rl-easa-upload-progress-label {
    margin-top: 6px;
    font-size: 12px;
    color: #475569;
    font-variant-numeric: tabular-nums;
  }
  .rl-easa-browse-single {
    margin-top: 12px;
  }
  .rl-easa-tree-panel {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    max-height: min(88vh, 1400px);
    overflow: auto;
    background: #fafbfc;
    font-size: 13px;
    width: 100%;
    box-sizing: border-box;
  }
  /* Indented to match the topic title text (after dot + expand), with air above/below and right margin. */
  .rl-easa-inline-detail {
    flex: none;
    box-sizing: border-box;
    padding-top: 16px;
    padding-bottom: 20px;
    /* Same offset as .rl-easa-tree-row: dot (8+2) + gap + exp (1.25rem) + gap → label text. */
    padding-left: calc(8px + 2px + 4px + 1.25rem + 4px);
    padding-right: 2.75rem;
  }
  .rl-easa-inline-detail-inner {
    width: 100%;
    max-width: 100%;
    margin: 0;
    box-sizing: border-box;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
    box-shadow:
      0 4px 18px rgba(15, 23, 42, 0.07),
      0 2px 6px rgba(15, 23, 42, 0.05),
      0 1px 0 rgba(255, 255, 255, 0.9) inset;
  }
  .rl-easa-inline-detail .rl-easa-inline-band {
    border-radius: 0 !important;
  }
  .rl-easa-inline-detail .rl-easa-detail-meta-box {
    margin: 0;
    border: none;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 0;
  }
  .rl-easa-inline-detail .rl-easa-detail-body {
    max-height: none;
    border: none;
    border-radius: 0;
    border-top: none;
    overflow-x: visible;
  }
  .rl-easa-tree-list {
    list-style: none;
    margin: 0;
    padding: 0 0 0 2px;
  }
  .rl-easa-tree-list .rl-easa-tree-list {
    margin-left: 10px;
    padding-left: 8px;
    border-left: 1px solid #e2e8f0;
  }
  .rl-easa-tree-row {
    display: flex;
    align-items: flex-start;
    gap: 4px;
    margin: 2px 0;
    line-height: 1.35;
  }
  .rl-easa-tree-exp {
    flex: 0 0 auto;
    border: none;
    background: transparent;
    cursor: pointer;
    padding: 0 2px;
    color: #0369a1;
    font-size: 12px;
    width: 1.25rem;
  }
  .rl-easa-tree-exp:disabled {
    visibility: hidden;
    cursor: default;
  }
  .rl-easa-tree-label {
    flex: 1;
    min-width: 0;
    text-align: left;
    border: none;
    background: transparent;
    cursor: pointer;
    padding: 0;
    color: #0f172a;
    font-size: 13px;
  }
  .rl-easa-tree-label:hover { text-decoration: underline; color: #0369a1; }
  .rl-easa-tree-label.rl-easa-tree-heading {
    font-weight: 700;
    cursor: default;
    color: #0f172a;
  }
  .rl-easa-tree-label.rl-easa-tree-heading:hover {
    text-decoration: none;
    color: #0f172a;
  }
  .rl-easa-tree-label.rl-easa-tree-toc {
    font-style: italic;
    color: #334155;
  }
  .rl-easa-tree-label.rl-easa-tree-toc-empty {
    cursor: default;
    font-style: italic;
    color: #64748b;
  }
  .rl-easa-tree-label.rl-easa-tree-toc-empty:hover {
    text-decoration: none;
    color: #64748b;
  }
  .rl-easa-tree-type {
    flex: 0 0 auto;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    margin-left: 4px;
  }
  .rl-easa-tech summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 0.82rem;
    color: #334155;
    margin-bottom: 0.35rem;
  }
  .rl-easa-tech summary:hover { color: #0f172a; }
  .rl-easa-tech pre {
    margin: 0;
    padding: 8px 10px;
    background: #f8fafc;
    border-radius: 6px;
    font-size: 0.76rem;
    line-height: 1.35;
    max-height: 200px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    color: #475569;
  }
  .rl-easa-detail-meta {
    font-size: 12px;
    color: #475569;
    margin-bottom: 8px;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .rl-easa-detail-body {
    /* List markers like "(c)" must stay literal; UI fonts often map (c)→© via ligatures / calt. */
    font-variant-ligatures: none;
    font-feature-settings: "liga" 0, "clig" 0, "calt" 0, "dlig" 0;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
    font-size: 13px;
    line-height: 1.65;
    color: #1e293b;
    padding: 14px 16px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 10px 10px;
    max-height: min(65vh, 560px);
    overflow: auto;
  }
  .rl-easa-detail-body-structured {
    white-space: normal;
  }
  .rl-easa-bl-article {
    max-width: 100%;
    min-width: 0;
  }
  .rl-easa-bl-article .rl-easa-bl-h:first-child {
    margin-top: 0;
  }
  .rl-easa-bl-h {
    margin: 0.85rem 0 0.4rem;
    font-weight: 700;
    line-height: 1.35;
    color: #0f172a;
  }
  .rl-easa-bl-p {
    margin: 0.35rem 0 0;
  }
  .rl-easa-bl-li {
    margin: 0.35rem 0 0;
    display: flex;
    gap: 8px;
    align-items: baseline;
    max-width: 100%;
  }
  .rl-easa-bl-marker {
    flex: 0 0 auto;
    font-weight: 600;
    color: #334155;
    min-width: 2rem;
  }
  .rl-easa-bl-litext {
    flex: 1 1 auto;
    min-width: 0;
    word-break: break-word;
  }
  /* Auto column widths (no equal split); compact font so wide syllabus tables fit the panel. */
  .rl-easa-bl-tbl {
    table-layout: auto;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    border-collapse: collapse;
    margin: 0.6rem 0 0;
    font-size: 10.75px;
    line-height: 1.45;
  }
  .rl-easa-bl-tbl td,
  .rl-easa-bl-tbl th {
    border: 1px solid #cbd5e1;
    padding: 3px 5px;
    vertical-align: top;
    word-break: break-word;
    overflow-wrap: anywhere;
    font-size: inherit;
    font-weight: normal;
    -webkit-hyphens: auto;
    hyphens: auto;
  }
  .rl-easa-bl-tbl th {
    font-weight: 600;
  }
  .rl-easa-node-detail-wrap { margin-top: 0; }
  .rl-easa-tree-list li {
    display: flex;
    flex-direction: column;
    align-items: stretch;
  }
  .rl-easa-band {
    padding: 12px 16px;
    border-radius: 10px 10px 0 0;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    line-height: 1.35;
  }
  .rl-easa-band-crumb {
    display: block;
    font-size: 0.82rem;
    font-weight: normal;
    opacity: 0.92;
    margin-top: 0.35rem;
    line-height: 1.35;
    word-break: break-word;
  }
  .rl-easa-band small {
    display: block;
    margin-top: 6px;
    font-size: 11px;
    font-weight: 600;
    opacity: 0.92;
    letter-spacing: 0.02em;
  }
  .rl-easa-band-ir { background: linear-gradient(90deg, #1d4ed8, #2563eb); }
  .rl-easa-band-amc { background: linear-gradient(90deg, #b45309, #d97706); }
  .rl-easa-band-gm { background: linear-gradient(90deg, #166534, #15803d); }
  .rl-easa-band-neu { background: linear-gradient(90deg, #475569, #64748b); }
  .rl-easa-detail-meta-box {
    padding: 10px 14px;
    font-size: 12px;
    color: #475569;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-top: none;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .rl-easa-tree-dot {
    flex: 0 0 8px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 6px;
    margin-right: 2px;
  }
  .rl-easa-tree-dot-ir { background: #2563eb; }
  .rl-easa-tree-dot-amc { background: #d97706; }
  .rl-easa-tree-dot-gm { background: #16a34a; }
  .rl-easa-tree-dot-neu { background: #94a3b8; }
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
      <div id="rlEasaUploadProgressWrap" class="rl-easa-upload-progress" hidden aria-hidden="true" aria-live="polite">
        <div id="rlEasaUploadProgressTrack" class="rl-easa-upload-progress-track">
          <div id="rlEasaUploadProgressBar" class="rl-easa-upload-progress-bar"></div>
        </div>
        <div id="rlEasaUploadProgressLabel" class="rl-easa-upload-progress-label"></div>
      </div>
      <p class="rl-drop-meta" id="rlEasaUploadStallWarn" style="display:none;margin-top:8px;color:#b45309;font-weight:600;"></p>
      <p class="rl-msg rl-easa-msg" id="rlEasaUploadMsg" role="status" style="margin-top:12px;"></p>
      <p class="rl-drop-meta" id="rlEasaUploadLimitHint" style="margin-top:8px;"></p>
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
      Full-text style search over <strong>staging</strong> (plain text, titles, ERulesId, breadcrumb, path). Optional batch id narrows scope; default page size 50 (API max 200, use <code>limit</code>/<code>offset</code> for pagination).
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
    <pre class="rl-test-out" id="rlEasaSearchOut" aria-live="polite" style="margin-top:12px; max-height:220px;"></pre>
  </section>

  <section class="card rl-easa-panel" style="padding:16px 18px; margin-top:12px;">
    <span class="rl-easa-badge">Browse corpus</span>
    <h3>Rule tree &amp; full text</h3>
    <p class="rl-drop-meta" style="margin-top:0;">
      Full-width tree matching the XML (<strong>topic</strong> / <strong>heading</strong> / <strong>document</strong>). One root wrapper can auto-open inward so you reach articles quickly.
      Opening a topic shows <strong>the rule text immediately under that row</strong>, full width inside the coloured Easy Access band (blue IR, amber AMC, green GM — from titles).
      Word TOC field codes are stripped; tables scroll horizontally within the expanded panel when needed.
    </p>
    <div class="rl-field" style="margin-bottom:10px;">
      <label for="rlEasaTreeBatch">Batch (required)</label>
      <select id="rlEasaTreeBatch" style="max-width:100%;min-width:260px;font-size:13px;">
        <option value="">Load batches from status…</option>
      </select>
      <button type="button" class="btn btn-sm" id="rlEasaTreeLoadRoots" style="margin-left:8px;">Load tree roots</button>
    </div>
    <div class="rl-easa-browse-single">
      <p class="rl-drop-meta" id="rlEasaTreeHint" style="margin:0 0 8px;">Choose a batch and load roots (batch id matches the table above).</p>
      <div class="rl-easa-tree-panel" id="rlEasaTreeMount" aria-label="Rule tree"></div>
    </div>
  </section>

  <section class="card rl-easa-panel" style="padding:16px 18px; margin-top:12px;">
    <span class="rl-easa-badge">Cross‑jurisdiction</span>
    <h3>Compare EASA concepts with U.S. eCFR (optional)</h3>
    <p class="rl-drop-meta" style="margin-top:0;">
      The server loads **matching excerpts** from <code>easa_erules_import_nodes_staging</code> using the same logic as “Search EASA index”, then sends them to the model together with your question.
      Optional **batch** narrows EASA retrieval to one upload. Uses your <strong>eCFR versioner API</strong> when you include a 14 CFR section.
    </p>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaCompareQ">Question</label>
      <input type="text" id="rlEasaCompareQ" placeholder="e.g. How does pilot recency compare EU vs US?" autocomplete="off" style="width:100%;">
    </div>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaCompareBatch">EASA batch (optional)</label>
      <select id="rlEasaCompareBatch" style="max-width:100%;min-width:280px;font-size:13px;">
        <option value="">All batches — match staging across imports</option>
      </select>
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
  /** Effective max POST body (bytes) from last status; 0 = unknown. */
  var rlEasaMaxUploadBytes = 0;

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

  function rlEasaFormatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    var u = ['KB', 'MB', 'GB'];
    var i = -1;
    do {
      n /= 1024;
      i++;
    } while (n >= 1024 && i < u.length - 1);
    return (n >= 10 ? n.toFixed(0) : n.toFixed(1)) + ' ' + u[i];
  }

  function rlEasaParseUploadBody(text, httpOk, statusLine) {
    var j = null;
    if (text) {
      try {
        j = JSON.parse(text);
      } catch (e) {
        /* fall through */
      }
    }
    if (!j || typeof j !== 'object') {
      var snippet = String(text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 280);
      throw new Error(snippet || statusLine || 'Upload failed');
    }
    return { ok: httpOk, j: j };
  }

  function rlEasaParseJsonResponse(r) {
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
      return { ok: r.ok, status: r.status, j: j };
    });
  }

  function rlEasaSetUploadProgressUi(opts) {
    var wrap = document.getElementById('rlEasaUploadProgressWrap');
    var track = document.getElementById('rlEasaUploadProgressTrack');
    var bar = document.getElementById('rlEasaUploadProgressBar');
    var lab = document.getElementById('rlEasaUploadProgressLabel');
    if (!wrap || !track || !bar || !lab) return;
    if (!opts || !opts.show) {
      wrap.hidden = true;
      wrap.setAttribute('aria-hidden', 'true');
      track.classList.remove('is-indeterminate');
      bar.style.width = '0%';
      lab.textContent = '';
      return;
    }
    wrap.hidden = false;
    wrap.setAttribute('aria-hidden', 'false');
    if (opts.indeterminate) {
      track.classList.add('is-indeterminate');
      bar.style.width = '0%';
      lab.textContent = opts.label || 'Sending…';
      return;
    }
    track.classList.remove('is-indeterminate');
    var loaded = opts.loaded || 0;
    var total = opts.total || 0;
    var pct = total > 0 ? Math.min(100, Math.round((loaded / total) * 1000) / 10) : 0;
    bar.style.width = pct + '%';
    var line = rlEasaFormatBytes(loaded);
    if (total > 0) {
      line += ' / ' + rlEasaFormatBytes(total) + ' · ' + pct + '%';
    }
    if (opts.extra) line += ' · ' + opts.extra;
    lab.textContent = line;
  }

  function loadStatus() {
    var hint = document.getElementById('rlEasaMigrateHint');
    fetch(api + '?action=status', { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (x) {
        if (!x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Status failed');
        var limEl = document.getElementById('rlEasaUploadLimitHint');
        if (x.j.max_body_bytes != null && x.j.max_body_bytes > 0) {
          rlEasaMaxUploadBytes = parseInt(x.j.max_body_bytes, 10) || 0;
          if (limEl) {
            limEl.textContent = 'PHP upload cap (effective): ~' + rlEasaFormatBytes(rlEasaMaxUploadBytes)
              + ' (upload_max_filesize=' + (x.j.php_upload_max_filesize || '?')
              + ', post_max_size=' + (x.j.php_post_max_size || '?')
              + '). If uploads stall around ~25MB while this is high, nginx (or Traefik, CDN, load balancer) is still limiting body size — set client_max_body_size 128m; (see deploy/nginx/ipca_upload_limits.conf), reload the proxy, retry.';
          }
        } else if (limEl) {
          limEl.textContent = '';
        }
        var treeSel = document.getElementById('rlEasaTreeBatch');
        function rlEasaFillBatchSelect(sel, placeholder, prevVal) {
          if (!sel) return;
          var prev = prevVal != null ? prevVal : sel.value;
          sel.innerHTML = '';
          var ph = document.createElement('option');
          ph.value = '';
          ph.textContent = placeholder;
          sel.appendChild(ph);
          (x.j.batches || []).forEach(function (b) {
            var o = document.createElement('option');
            o.value = String(b.id);
            var sn = b.staging_nodes != null ? String(b.staging_nodes) : '?';
            o.textContent = '#' + b.id + ' — ' + (b.original_filename || 'batch') + ' (' + sn + ' nodes)';
            sel.appendChild(o);
          });
          if (prev) {
            for (var ti = 0; ti < sel.options.length; ti++) {
              if (sel.options[ti].value === prev) {
                sel.selectedIndex = ti;
                break;
              }
            }
          }
        }
        rlEasaFillBatchSelect(treeSel, '— Select batch —');
        rlEasaFillBatchSelect(document.getElementById('rlEasaCompareBatch'), 'All batches — match staging across imports');
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
              var asyncPolling = false;
              btn.disabled = true;
              btn.setAttribute('aria-busy', 'true');
              var prog = document.getElementById('rlEasaParseProgress');
              if (prog) {
                prog.textContent = 'Starting parse… (large XML can take several minutes in synchronous mode)';
              }
              fetch(api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'parse_batch', batch_id: id })
              })
                .then(rlEasaParseJsonResponse)
                .then(function (x) {
                  if (!x.j || !x.j.ok) {
                    throw new Error((x.j && x.j.error) || ('Parse failed (HTTP ' + (x.status || '') + ')'));
                  }
                  if (x.status === 202 || x.j.async) {
                    asyncPolling = true;
                    if (prog) {
                      prog.textContent = 'Import running on server (batch ' + id + '). Polling progress every 1.5s…';
                    }
                    var tries = 0;
                    var pollErrs = 0;
                    var timer = null;
                    function pollBatch() {
                      tries++;
                      fetch(api + '?action=batch_progress&batch_id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' })
                        .then(rlEasaParseJsonResponse)
                        .then(function (pr) {
                          if (!pr.j.ok || !pr.j.batch) {
                            pollErrs++;
                            var errMsg = (pr.j && pr.j.error) ? pr.j.error : ('HTTP ' + pr.status);
                            if (prog) {
                              prog.textContent = 'Could not read batch progress: ' + errMsg + ' · retrying…';
                            }
                            return;
                          }
                          pollErrs = 0;
                          var b = pr.j.batch;
                          var rowCount = b.parse_rows_so_far;
                          if (rowCount == null || rowCount === '') {
                            rowCount = b.rows_detected;
                          }
                          if (rowCount == null || rowCount === '') {
                            rowCount = '—';
                          }
                          var phase = b.parse_phase;
                          if (!phase && b.status === 'ready_for_review') {
                            phase = 'completed';
                          } else if (!phase && b.status === 'failed') {
                            phase = 'failed';
                          } else if (!phase && b.status === 'staging') {
                            phase = 'running';
                          }
                          if (!phase) {
                            phase = '—';
                          }
                          var line = [
                            'status=' + (b.status || ''),
                            'phase=' + phase,
                            'rows=' + rowCount,
                            (b.parse_last_node_type ? 'last<' + b.parse_last_node_type + '>' : ''),
                            (b.parse_detail || '')
                          ].filter(Boolean).join(' · ');
                          if (prog) prog.textContent = line || '—';
                          if (b.status === 'ready_for_review' || b.status === 'failed') {
                            if (timer) clearInterval(timer);
                            btn.disabled = false;
                            btn.removeAttribute('aria-busy');
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
                        .catch(function (e) {
                          pollErrs++;
                          if (prog) {
                            prog.textContent = 'Polling failed (' + pollErrs + '): ' + (e.message || 'network') + ' · retrying…';
                          }
                        });
                      if (tries > 800) {
                        if (timer) clearInterval(timer);
                        btn.disabled = false;
                        btn.removeAttribute('aria-busy');
                        if (prog) prog.textContent += '\nStopped polling after timeout; reload the page to see final status.';
                      }
                    }
                    pollBatch();
                    timer = setInterval(pollBatch, 1500);
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
                  if (!asyncPolling) {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                  }
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
      rlEasaSetUploadProgressUi(null);
      var stallEl = document.getElementById('rlEasaUploadStallWarn');
      if (stallEl) { stallEl.style.display = 'none'; stallEl.textContent = ''; }
      if (!fileInp.files || !fileInp.files.length) {
        setUploadMsg('Choose an XML file first.', 'err');
        return;
      }
      var file = fileInp.files[0];
      if (rlEasaMaxUploadBytes > 0 && file.size > rlEasaMaxUploadBytes) {
        setUploadMsg(
          'This file (' + rlEasaFormatBytes(file.size) + ') exceeds the server limit (~'
            + rlEasaFormatBytes(rlEasaMaxUploadBytes)
            + '). Raise PHP upload_max_filesize and post_max_size (and nginx client_max_body_size if applicable), then reload.',
          'err'
        );
        return;
      }
      var fd = new FormData();
      fd.append('erules_xml', file);
      uploadBtn.disabled = true;
      uploadBtn.setAttribute('aria-busy', 'true');
      setUploadMsg('Uploading…', 'info');
      if (file.size > 0) {
        rlEasaSetUploadProgressUi({ show: true, indeterminate: false, loaded: 0, total: file.size });
      } else {
        rlEasaSetUploadProgressUi({ show: true, indeterminate: true, label: 'Sending… (size unknown)' });
      }
      var wrap = document.getElementById('rlEasaUploadProgressWrap');
      if (wrap && wrap.scrollIntoView) wrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      var lastProgAt = Date.now();
      var lastLoadedAmt = 0;
      var stallIv = setInterval(function () {
        if (Date.now() - lastProgAt < 22000) return;
        if (lastLoadedAmt >= file.size && file.size > 0) return;
        if (stallEl) {
          stallEl.style.display = 'block';
          var phpAllowsFile = rlEasaMaxUploadBytes > 0 && file.size <= rlEasaMaxUploadBytes;
          if (phpAllowsFile) {
            stallEl.textContent = 'No progress for ~22s — PHP already allows this file size, so the limit is almost certainly in front of PHP: nginx client_max_body_size (often ~25m), Traefik, CDN, or load balancer. Raise client_max_body_size to 128m (include deploy/nginx/ipca_upload_limits.conf), reload nginx, retry.';
          } else {
            stallEl.textContent = 'No progress for ~22s — likely PHP post_max_size/upload_max_filesize or nginx client_max_body_size. '
              + (rlEasaMaxUploadBytes > 0 ? 'This page reports PHP max ~' + rlEasaFormatBytes(rlEasaMaxUploadBytes) + '. ' : '')
              + 'Fix limits on the server, then retry.';
          }
        }
      }, 4000);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', api);
      xhr.withCredentials = true;
      xhr.timeout = 900000;
      xhr.upload.addEventListener('progress', function (e) {
        lastProgAt = Date.now();
        lastLoadedAmt = e.loaded || 0;
        var total = 0;
        if (e.lengthComputable && e.total > 0) {
          total = e.total;
        } else if (file.size > 0) {
          total = file.size;
        } else if (e.total > 0) {
          total = e.total;
        }
        var loaded = e.loaded || 0;
        if (!(total > 0)) {
          rlEasaSetUploadProgressUi({
            show: true,
            indeterminate: true,
            label: 'Sending… ' + rlEasaFormatBytes(loaded)
          });
          return;
        }
        rlEasaSetUploadProgressUi({
          show: true,
          indeterminate: false,
          loaded: loaded,
          total: total
        });
      });
      function rlEasaClearUploadWatch() {
        if (stallIv) clearInterval(stallIv);
        stallIv = null;
      }

      xhr.addEventListener('load', function () {
        rlEasaClearUploadWatch();
        try {
          var text = xhr.responseText || '';
          var httpOk = xhr.status >= 200 && xhr.status < 300;
          var x = rlEasaParseUploadBody(text, httpOk, 'HTTP ' + xhr.status);
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Upload failed');
          setUploadMsg(x.j.message || 'Uploaded.', 'ok');
          fileInp.value = '';
          loadStatus();
        } catch (err) {
          setUploadMsg(err.message || 'Upload failed', 'err');
        } finally {
          rlEasaSetUploadProgressUi(null);
          uploadBtn.disabled = false;
          uploadBtn.removeAttribute('aria-busy');
        }
      });
      xhr.addEventListener('error', function () {
        rlEasaClearUploadWatch();
        setUploadMsg('Network error while uploading.', 'err');
        rlEasaSetUploadProgressUi(null);
        uploadBtn.disabled = false;
        uploadBtn.removeAttribute('aria-busy');
      });
      xhr.addEventListener('abort', function () {
        rlEasaClearUploadWatch();
        rlEasaSetUploadProgressUi(null);
        uploadBtn.disabled = false;
        uploadBtn.removeAttribute('aria-busy');
      });
      xhr.addEventListener('timeout', function () {
        rlEasaClearUploadWatch();
        setUploadMsg('Upload timed out after 15 minutes. Try again or split the file.', 'err');
        rlEasaSetUploadProgressUi(null);
        uploadBtn.disabled = false;
        uploadBtn.removeAttribute('aria-busy');
      });
      xhr.send(fd);
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
      var payload = { action: 'search', query: q, limit: 100 };
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
            lines.push(String(i + 1) + '. batch ' + (h.batch_id != null ? h.batch_id : '—')
              + ' · [' + (h.node_type || '') + '] ' + (h.source_erules_id || h.title || h.node_uid || ''));
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

  function rlEasaStructuredBlocksHtml(blocks) {
    if (!Array.isArray(blocks) || blocks.length < 1) return '';
    var bits = '';
    blocks.forEach(function (b) {
      if (!b || typeof b !== 'object') return;
      var ty = String(b.type || '');
      if (ty === 'heading') {
        var lvl = parseInt(String(b.level), 10);
        if (!(lvl >= 1 && lvl <= 6)) lvl = 3;
        bits += '<h' + lvl + ' class="rl-easa-bl-h">' + esc(b.text || '') + '</h' + lvl + '>';
      } else if (ty === 'paragraph') {
        bits += '<p class="rl-easa-bl-p">' + esc(b.text || '') + '</p>';
      } else if (ty === 'list_item') {
        bits += '<div class="rl-easa-bl-li"><span class="rl-easa-bl-marker">' + esc(b.marker != null ? b.marker : '') + '</span><span class="rl-easa-bl-litext">' + esc(b.text || '') + '</span></div>';
      } else if (ty === 'table') {
        bits += '<table class="rl-easa-bl-tbl">';
        var rows = b.rows || [];
        for (var r = 0; r < rows.length; r++) {
          bits += '<tr>';
          var row = rows[r];
          var cells = Array.isArray(row) ? row : [];
          for (var c = 0; c < cells.length; c++) {
            bits += '<td>' + esc(cells[c] != null ? String(cells[c]) : '').replace(/\n/g, '<br>') + '</td>';
          }
          bits += '</tr>';
        }
        bits += '</table>';
      }
    });
    return '<article class="rl-easa-bl-article" aria-label="Rule text">' + bits + '</article>';
  }

  function rlEasaBandLegend(band) {
    if (band === 'amc') return 'Acceptable means of compliance (AMC) — ED Decision style material in Easy Access.';
    if (band === 'gm') return 'Guidance material (GM) — ED Decision style material in Easy Access.';
    if (band === 'neu') return 'Cover / editorial / TOC wrapper — expand the tree to open topics and annexes.';
    return 'Implementing / delegated rule or annex text — EU regulation layer (blue band on easa.europa.eu).';
  }

  function rlEasaShowNodeDetail(batchId, uid, liElm) {
    if (!liElm) return;
    var wrap = liElm.querySelector(':scope > .rl-easa-inline-detail');
    if (!wrap) return;
    var band = wrap.querySelector('.rl-easa-inline-band');
    var meta = wrap.querySelector('.rl-easa-inline-meta');
    var body = wrap.querySelector('.rl-easa-inline-body');
    if (!band || !meta || !body) return;

    // Second click on the same row closes this panel (open panels may stay open for side-by-side compare).
    if (!wrap.hidden) {
      var loading = wrap.getAttribute('data-loading') === '1';
      var loadedHere = wrap.getAttribute('data-loaded-uid') === uid;
      if (loading || loadedHere) {
        wrap.hidden = true;
        wrap.removeAttribute('data-loading');
        return;
      }
    }
    wrap.hidden = false;
    wrap.setAttribute('data-loading', '1');
    wrap.removeAttribute('data-loaded-uid');
    band.className = 'rl-easa-inline-band rl-easa-band rl-easa-band-neu';
    band.innerHTML = esc('Loading…') + '<small></small>';
    meta.innerHTML = '';
    body.innerHTML = '';
    body.textContent = '';
    body.className = 'rl-easa-detail-body rl-easa-inline-body';
    fetch(api + '?action=node_detail&batch_id=' + encodeURIComponent(String(batchId)) + '&node_uid=' + encodeURIComponent(uid), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (wrap.hidden) return;
        if (!j.ok || !j.node) throw new Error((j && j.error) || 'Load failed');
        var n = j.node;
        var b = n.rule_band || 'ir';
        if (['ir', 'amc', 'gm', 'neu'].indexOf(b) < 0) b = 'ir';
        band.className = 'rl-easa-inline-band rl-easa-band rl-easa-band-' + b;
        var titleLine = n.title_display || n.title || n.source_erules_id || n.node_uid || '—';
        var crumb = (n.breadcrumb || '').trim();
        crumb = crumb.length > 320 ? crumb.slice(0, 317) + '…' : crumb;
        var crumbHtml = crumb ? '<span class="rl-easa-band-crumb">' + esc(crumb) + '</span>' : '';
        band.innerHTML = esc(titleLine) + crumbHtml + '<small>' + esc(rlEasaBandLegend(b)) + '</small>';
        var bits = [];
        bits.push('batch_id=' + (n.batch_id || ''));
        bits.push('node_uid=' + (n.node_uid || ''));
        bits.push('node_type=' + (n.node_type || ''));
        if (n.source_erules_id) bits.push('ERulesId=' + n.source_erules_id);
        if (n.plain_text_composed_from_descendants) bits.push('[Body assembled from child rows — parent row had no text in XML]');
        if (n.plain_text_effective_source === 'canonical') bits.push('[Rendered from canonical text — spaced for readability]');
        if (n.plain_text_effective_source === 'xml_fragment') bits.push('[Body from stored xml_fragment]');
        if (n.plain_text_effective_source === 'source_xml_erules') bits.push('[Body matched in source.xml by ERulesId]');
        if (n.plain_text_truncated) bits.push('[Body truncated at ~400k chars in payload]');
        if (Array.isArray(n.structured_blocks) && n.structured_blocks.length > 0) {
          bits.push('[Display: canonical structured_blocks]');
        }
        meta.innerHTML = '<details class="rl-easa-tech"><summary>Technical details</summary><pre>' + esc(bits.join('\n')) + '</pre></details>';
        var blkHtml = (Array.isArray(n.structured_blocks) && n.structured_blocks.length > 0)
          ? rlEasaStructuredBlocksHtml(n.structured_blocks)
          : '';
        var bodySrc = '';
        if (typeof n.body_reading === 'string' && (n.body_reading || '').trim() !== '') {
          bodySrc = n.body_reading;
        } else if (typeof n.plain_text_display === 'string' && n.plain_text_display.trim() !== '') {
          bodySrc = n.plain_text_display;
        } else if (typeof n.plain_text === 'string') {
          bodySrc = n.plain_text;
        }
        if (blkHtml) {
          body.className = 'rl-easa-detail-body rl-easa-detail-body-structured rl-easa-inline-body';
          body.innerHTML = blkHtml;
        } else {
          body.className = 'rl-easa-detail-body rl-easa-inline-body';
          body.innerHTML = '';
          body.textContent = bodySrc;
        }
        wrap.removeAttribute('data-loading');
        wrap.setAttribute('data-loaded-uid', uid);
        try {
          wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (e2) {}
      })
      .catch(function (e) {
        if (wrap.hidden) return;
        wrap.removeAttribute('data-loading');
        band.className = 'rl-easa-inline-band rl-easa-band rl-easa-band-neu';
        band.innerHTML = esc(e.message || 'Error') + '<small></small>';
        meta.innerHTML = '';
        body.innerHTML = '';
        body.textContent = '';
        body.className = 'rl-easa-detail-body rl-easa-inline-body';
      });
  }

  function rlEasaCreateTreeLi(batchId, n) {
    var li = document.createElement('li');
    var uid = n.node_uid || '';
    var kids = parseInt(n.child_count, 10) || 0;
    var rb = n.rule_band || 'ir';
    if (['ir', 'amc', 'gm', 'neu'].indexOf(rb) < 0) rb = 'ir';
    var row = document.createElement('div');
    row.className = 'rl-easa-tree-row';
    var dot = document.createElement('span');
    dot.className = 'rl-easa-tree-dot rl-easa-tree-dot-' + rb;
    dot.setAttribute('aria-hidden', 'true');
    var exp = document.createElement('button');
    exp.type = 'button';
    exp.className = 'rl-easa-tree-exp';
    exp.setAttribute('aria-expanded', 'false');
    if (kids < 1) {
      exp.disabled = true;
      exp.textContent = '·';
    } else {
      exp.textContent = '▶';
      exp.setAttribute('aria-label', 'Expand children');
    }
    var nodeTypeLc = String(n.node_type || '').toLowerCase();
    var isHeading = nodeTypeLc === 'heading';
    var isToc = nodeTypeLc === 'toc';
    var lab;
    if (isHeading) {
      lab = document.createElement('span');
      lab.className = 'rl-easa-tree-label rl-easa-tree-heading';
    } else if (isToc && kids < 1) {
      lab = document.createElement('span');
      lab.className = 'rl-easa-tree-label rl-easa-tree-toc-empty';
    } else {
      lab = document.createElement('button');
      lab.type = 'button';
      lab.className = 'rl-easa-tree-label' + (isToc ? ' rl-easa-tree-toc' : '');
      if (isToc) {
        lab.title = 'Show or hide entries under this table of contents';
      }
    }
    lab.textContent = n.label_short || n.title || n.source_erules_id || n.node_uid || n.node_type || '—';
    var ty = document.createElement('span');
    ty.className = 'rl-easa-tree-type';
    ty.textContent = n.node_type || '';
    row.appendChild(dot);
    row.appendChild(exp);
    row.appendChild(lab);
    row.appendChild(ty);
    li.appendChild(row);

    var inlineWrap = document.createElement('div');
    inlineWrap.className = 'rl-easa-inline-detail';
    inlineWrap.hidden = true;
    inlineWrap.innerHTML =
      '<div class="rl-easa-inline-detail-inner">'
      + '<div class="rl-easa-inline-band rl-easa-band rl-easa-band-neu"></div>'
      + '<div class="rl-easa-inline-meta rl-easa-detail-meta-box"></div>'
      + '<div class="rl-easa-inline-body rl-easa-detail-body"></div>'
      + '</div>';
    li.appendChild(inlineWrap);

    if (kids > 0) {
      var chUl = document.createElement('ul');
      chUl.className = 'rl-easa-tree-list';
      chUl.hidden = true;
      chUl.setAttribute('data-loaded', '0');
      li.appendChild(chUl);
      var toggleChildList = function (e) {
        if (e) {
          e.stopPropagation();
          e.preventDefault();
        }
        var sub = li.querySelector(':scope > ul.rl-easa-tree-list');
        if (!sub) return;
        if (sub.getAttribute('data-loaded') === '1' && !sub.hidden) {
          sub.hidden = true;
          exp.textContent = '▶';
          exp.setAttribute('aria-expanded', 'false');
          return;
        }
        if (sub.getAttribute('data-loaded') === '1' && sub.children.length > 0) {
          sub.hidden = false;
          exp.textContent = '▼';
          exp.setAttribute('aria-expanded', 'true');
          return;
        }
        sub.innerHTML = '';
        exp.disabled = true;
        fetch(api + '?action=tree_children&batch_id=' + encodeURIComponent(String(batchId)) + '&parent_uid=' + encodeURIComponent(uid), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            exp.disabled = false;
            if (!j.ok || !j.nodes) throw new Error((j && j.error) || 'Tree load failed');
            j.nodes.forEach(function (c) {
              sub.appendChild(rlEasaCreateTreeLi(batchId, c));
            });
            sub.setAttribute('data-loaded', '1');
            sub.hidden = false;
            exp.textContent = '▼';
            exp.setAttribute('aria-expanded', 'true');
          })
          .catch(function (err) {
            exp.disabled = false;
            sub.textContent = err.message || 'Error';
          });
      };
      exp.addEventListener('click', toggleChildList);
      if (isToc) {
        lab.addEventListener('click', toggleChildList);
      }
    }
    if (!isHeading && !isToc) {
      lab.addEventListener('click', function () {
        rlEasaShowNodeDetail(batchId, uid, li);
      });
    }
    return li;
  }

  function rlEasaRenderTreeIntoMount(mount, bid, nodes) {
    mount.innerHTML = '';
    var ul = document.createElement('ul');
    ul.className = 'rl-easa-tree-list';
    (nodes || []).forEach(function (n) {
      ul.appendChild(rlEasaCreateTreeLi(bid, n));
    });
    mount.appendChild(ul);
  }

  var rlEasaTreeLoadBtn = document.getElementById('rlEasaTreeLoadRoots');
  var rlEasaTreeMount = document.getElementById('rlEasaTreeMount');
  var rlEasaTreeHint = document.getElementById('rlEasaTreeHint');
  if (rlEasaTreeLoadBtn && rlEasaTreeMount) {
    rlEasaTreeLoadBtn.addEventListener('click', function () {
      var sel = document.getElementById('rlEasaTreeBatch');
      var bid = sel && sel.value ? parseInt(sel.value, 10) : 0;
      if (!bid) {
        if (rlEasaTreeHint) rlEasaTreeHint.textContent = 'Select a batch in the dropdown first.';
        return;
      }
      rlEasaTreeMount.innerHTML = '<p class="rl-drop-meta" style="margin:0;">Loading roots…</p>';
      fetch(api + '?action=tree_children&batch_id=' + encodeURIComponent(String(bid)), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok || !j.nodes) throw new Error((j && j.error) || 'Failed to load tree');
          var nodes = j.nodes || [];
          var wrapTypes = { document: 1, frontmatter: 1, toc: 1, backmatter: 1 };
          function rlEasaIsWrapperNode(n) {
            return !!wrapTypes[String(n.node_type || '').toLowerCase()];
          }
          var unwrapUid = null;
          var wrapNode = null;
          if (nodes.length && nodes.every(rlEasaIsWrapperNode)) {
            var bc = -1;
            nodes.forEach(function (n) {
              var c = parseInt(n.child_count, 10) || 0;
              if (c > bc) {
                bc = c;
                wrapNode = n;
              }
            });
            if (wrapNode && bc > 0) {
              unwrapUid = wrapNode.node_uid;
            }
          }
          if (unwrapUid) {
            return fetch(api + '?action=tree_children&batch_id=' + encodeURIComponent(String(bid)) + '&parent_uid=' + encodeURIComponent(unwrapUid), { credentials: 'same-origin' })
              .then(function (r2) { return r2.json(); })
              .then(function (j2) {
                if (!j2.ok || !j2.nodes) throw new Error((j2 && j2.error) || 'Failed to load inner tree');
                rlEasaRenderTreeIntoMount(rlEasaTreeMount, bid, j2.nodes);
                if (rlEasaTreeHint) {
                  rlEasaTreeHint.textContent = 'Batch #' + bid + ' · ' + j2.nodes.length + ' items under wrapper. Headings bold (not links); TOC (italic) expands subtree only; other rows open rule text under the row (full width, dots: IR / AMC / GM / cover).';
                }
              });
          }
          rlEasaRenderTreeIntoMount(rlEasaTreeMount, bid, nodes);
          if (rlEasaTreeHint) {
            rlEasaTreeHint.textContent = 'Batch #' + bid + ' · ' + nodes.length + ' root row(s). ▶ or TOC expands subtree; headings bold; click topics for rule text opened below the row (colour band).';
          }
        })
        .catch(function (e) {
          rlEasaTreeMount.innerHTML = '<p class="rl-drop-meta" style="color:#991b1b;margin:0;">' + esc(e.message || 'Error') + '</p>';
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
      var cmpBatchEl = document.getElementById('rlEasaCompareBatch');
      var cmpBid = cmpBatchEl && cmpBatchEl.value ? parseInt(cmpBatchEl.value, 10) : 0;
      var body = {
        action: 'regulatory_compare_ai',
        query: q,
        use_ai: !!(useAi && useAi.checked),
        include_ecfr: !!(incEcfr && incEcfr.checked),
        ecfr_title_number: titleEl ? parseInt(titleEl.value, 10) || 14 : 14,
        ecfr_section: secEl ? (secEl.value || '').trim() : ''
      };
      if (cmpBid > 0) body.batch_id = cmpBid;
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
            lines.push('--- EASA staging (fed to model) ---');
            lines.push(x.j.easa_context_note);
          }
          if (x.j.easa_staging_hits != null) {
            lines.push('Excerpt rows matched: ' + x.j.easa_staging_hits);
          }
          if (x.j.easa_sources && x.j.easa_sources.length) {
            lines.push('Source refs (batch · ERulesId / title):');
            x.j.easa_sources.slice(0, 12).forEach(function (s) {
              lines.push('  · batch ' + (s.batch_id || '—') + ' · ' + (s.source_erules_id || s.title || s.node_uid || '—'));
            });
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
