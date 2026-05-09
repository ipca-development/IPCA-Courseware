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
    padding: 0;
  }
  .rl-easa-tree-list .rl-easa-tree-list {
    margin-left: 14px;
    padding-left: 0;
  }
  .rl-easa-tree-li {
    margin: 0;
    padding: 0;
  }
  .rl-easa-tree-li.rl-easa-tree-li-selected > .rl-easa-tree-row {
    background: #eff6ff;
    border-radius: 6px;
    margin-left: -4px;
    margin-right: -4px;
    padding-left: 4px;
    padding-right: 4px;
  }
  .rl-easa-tree-row {
    display: flex;
    align-items: flex-start;
    gap: 4px;
    margin: 1px 0;
    line-height: 1.4;
  }
  .rl-easa-tree-row--section .rl-easa-tree-section-title {
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.01em;
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
  /* Semantic API: rule + expandable=true (IR with AMC/GM children only) — slightly smaller disclosure */
  .rl-easa-tree-exp--rule-disclosure {
    font-size: 10px;
    line-height: 1.15;
    opacity: 0.95;
  }
  .rl-easa-tree-exp--gm {
    color: #15803d;
  }
  .rl-easa-tree-exp--gm:hover:not(:disabled) {
    color: #166534;
  }
  .rl-easa-tree-exp--amc {
    color: #c2410c;
  }
  .rl-easa-tree-exp--amc:hover:not(:disabled) {
    color: #9a3412;
  }
  .rl-easa-tree-exp:disabled {
    visibility: hidden;
    cursor: default;
  }
  /* GM / AMC navigational rows: extra inset so they read as under the preceding IR rule. */
  .rl-easa-tree-li.rl-easa-tree-li-supplement > .rl-easa-tree-row {
    padding-left: 1.35rem;
  }
  .rl-easa-tree-li.rl-easa-tree-li-supplement > ul.rl-easa-tree-list {
    margin-left: 1.1rem;
  }
  .rl-easa-tree-section-title.rl-easa-tree-section-title--gm-amc {
    font-style: italic;
    font-weight: 600;
    color: #334155;
  }
  .rl-easa-tree-section-title {
    flex: 1;
    min-width: 0;
    text-align: left;
    border: none;
    background: transparent;
    cursor: pointer;
    padding: 2px 0;
    font-size: 13px;
    font-family: inherit;
  }
  .rl-easa-tree-section-title:hover:not(:disabled) {
    color: #0369a1;
  }
  .rl-easa-tree-section-title:disabled {
    cursor: default;
    color: #64748b;
  }
  .rl-easa-tree-rule-title {
    flex: 1;
    min-width: 0;
    text-align: left;
    border: none;
    background: transparent;
    cursor: pointer;
    padding: 2px 0;
    color: #0f172a;
    font-size: 13px;
    font-family: inherit;
  }
  .rl-easa-tree-rule-title:hover {
    text-decoration: underline;
    color: #0369a1;
  }
  .rl-easa-tree-rule-supplement {
    font-style: italic;
    color: #334155;
  }
  .rl-easa-tree-rule-supplement:hover {
    color: #0369a1;
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

  /* —— EASA resource dashboard —— */
  .rl-easa-metrics {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }
  @media (max-width: 1280px) {
    .rl-easa-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (max-width: 860px) {
    .rl-easa-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 560px) {
    .rl-easa-metrics { grid-template-columns: 1fr; }
  }
  .rl-easa-metric-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  }
  .rl-easa-metric-card .rl-easa-metric-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    margin-bottom: 4px;
  }
  .rl-easa-metric-card .rl-easa-metric-value {
    font-size: 1.35rem;
    font-weight: 800;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
    line-height: 1.2;
  }
  .rl-easa-metric-card .rl-easa-metric-sub {
    margin-top: 4px;
    font-size: 11px;
    color: #94a3b8;
  }
  .rl-easa-source-scroll-wrap {
    margin-bottom: 16px;
    overflow: visible;
    padding-bottom: 0;
  }
  .rl-easa-source-row {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    align-items: stretch;
  }
  .rl-easa-source-card {
    width: 100%;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 12px 10px;
    cursor: pointer;
    text-align: left;
    font: inherit;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: border-color 0.15s, box-shadow 0.15s;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  }
  .rl-easa-source-card:hover {
    border-color: #93c5fd;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.08);
  }
  .rl-easa-source-card:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
  }
  .rl-easa-source-card--add {
    border-style: dashed;
    color: #0369a1;
    font-weight: 700;
    justify-content: center;
    align-items: center;
    background: #f8fafc;
  }
  @media (max-width: 1400px) {
    .rl-easa-source-row {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
  }
  @media (max-width: 1120px) {
    .rl-easa-source-row {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }
  @media (max-width: 860px) {
    .rl-easa-source-row {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 560px) {
    .rl-easa-source-row {
      grid-template-columns: 1fr;
    }
  }
  .rl-easa-source-card--add:hover {
    background: #eff6ff;
  }
  .rl-easa-source-card-title {
    font-weight: 800;
    font-size: 13px;
    color: #0f172a;
    line-height: 1.3;
    display: block;
    min-height: calc(1.3em * 2);
    max-height: calc(1.3em * 2);
    overflow: hidden;
    word-break: break-word;
  }
  .rl-easa-source-sublabel {
    display: block;
    font-weight: 700;
    letter-spacing: 0.04em;
  }
  .rl-easa-source-sublabel-gap {
    display: block;
    height: 10px;
  }
  .rl-easa-source-card-meta {
    font-size: 11px;
    color: #64748b;
    line-height: 1.35;
  }
  .rl-easa-pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: auto;
    padding-top: 4px;
  }
  .rl-easa-pill {
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 3px 7px;
    border-radius: 999px;
    border: none;
    line-height: 1.2;
  }
  .rl-easa-pill--ok { background: #dcfce7; color: #166534; }
  .rl-easa-pill--warn { background: #ffedd5; color: #9a3412; }
  .rl-easa-pill--bad { background: #fee2e2; color: #991b1b; }
  .rl-easa-pill--muted { background: #f1f5f9; color: #475569; }
  .rl-easa-pill--live { background: #dbeafe; color: #1d4ed8; }
  .rl-easa-pill--off { background: #e2e8f0; color: #475569; }

  .rl-easa-dash-panel h3 {
    margin: 0 0 6px;
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
  }
  .rl-easa-dash-panel .rl-easa-dash-lead {
    margin: 0 0 12px;
    font-size: 13px;
    color: #64748b;
    line-height: 1.45;
  }
  .rl-easa-ai-output {
    margin-top: 14px;
    padding: 12px;
    border-radius: 10px;
    background: #eef2f7;
    border: 1px solid #e2e8f0;
    font-size: 14px;
    line-height: 1.55;
    color: #1e293b;
    min-height: 3rem;
  }
  .rl-easa-chat-thread {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .rl-easa-chat-row {
    display: flex;
    width: 100%;
  }
  .rl-easa-chat-row-user {
    justify-content: flex-end;
  }
  .rl-easa-chat-row-system {
    justify-content: flex-start;
  }
  .rl-easa-chat-bubble {
    max-width: min(80%, 900px);
    border-radius: 16px;
    padding: 10px 12px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
  }
  .rl-easa-chat-bubble-user {
    background: #0b84ff;
    color: #fff;
    border-top-right-radius: 6px;
  }
  .rl-easa-chat-bubble-system {
    background: #fff;
    color: #0f172a;
    border: 1px solid #dbe3ee;
    border-top-left-radius: 6px;
  }
  .rl-easa-chat-meta {
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 4px;
    opacity: 0.8;
  }
  .rl-easa-chat-bubble-user .rl-easa-chat-meta {
    color: rgba(255,255,255,0.92);
  }
  .rl-easa-chat-bubble-system .rl-easa-chat-meta {
    color: #475569;
  }
  .rl-easa-ai-output p {
    margin: 0 0 0.65rem;
  }
  .rl-easa-ai-output p:last-child {
    margin-bottom: 0;
  }
  .rl-easa-ai-output ul {
    margin: 0.2rem 0 0.7rem 1.2rem;
    padding: 0;
  }
  .rl-easa-ai-output li {
    margin: 0.2rem 0;
  }
  .rl-easa-ai-output code {
    background: #e2e8f0;
    border-radius: 4px;
    padding: 0 3px;
    font-size: 0.93em;
  }
  .rl-easa-chat-bubble-user code {
    background: rgba(255,255,255,0.18);
    color: #fff;
  }
  .rl-easa-ai-output.is-empty {
    color: #94a3b8;
    font-style: italic;
  }
  .rl-easa-citation-cards {
    margin-top: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .rl-easa-cite-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    background: #fff;
  }
  .rl-easa-cite-card h4 {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
  }
  .rl-easa-cite-meta {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 8px;
    word-break: break-word;
  }
  .rl-easa-cite-excerpt {
    font-size: 12px;
    line-height: 1.5;
    color: #334155;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 160px;
    overflow: auto;
    padding: 8px 10px;
    background: #fefce8;
    border-radius: 6px;
    border: 1px solid #fef08a;
  }
  .rl-easa-cite-actions {
    margin-top: 10px;
  }
  .rl-easa-search-hits {
    margin-top: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    max-height: 280px;
    overflow-y: auto;
    background: #fff;
  }
  .rl-easa-search-hit {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 12px;
    border: none;
    border-bottom: 1px solid #f1f5f9;
    background: transparent;
    cursor: pointer;
    font: inherit;
  }
  .rl-easa-search-hit:last-child {
    border-bottom: none;
  }
  .rl-easa-search-hit:hover {
    background: #eff6ff;
  }
  .rl-easa-search-hit-title {
    font-weight: 700;
    font-size: 13px;
    color: #0f172a;
    margin-bottom: 4px;
  }
  .rl-easa-search-hit-snip {
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .rl-easa-kw-hl {
    background: #fef08a;
    color: inherit;
    padding: 0 1px;
    border-radius: 2px;
  }

  .rl-easa-modal-overlay[hidden] {
    display: none !important;
  }
  .rl-easa-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 12000;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 24px 16px;
    overflow-y: auto;
    box-sizing: border-box;
  }
  .rl-easa-modal-dialog {
    width: min(640px, 100%);
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 22px 50px rgba(15, 23, 42, 0.18);
    margin-bottom: 40px;
  }
  .rl-easa-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid #e2e8f0;
  }
  .rl-easa-modal-head h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
  }
  .rl-easa-modal-close {
    border: none;
    background: #f1f5f9;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1.25rem;
    line-height: 1;
    color: #475569;
  }
  .rl-easa-modal-close:hover {
    background: #e2e8f0;
  }
  .rl-easa-modal-body {
    padding: 16px 18px 20px;
    max-height: min(78vh, 900px);
    overflow-y: auto;
  }
  .rl-easa-modal-section {
    margin-bottom: 18px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f1f5f9;
  }
  .rl-easa-modal-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
  }
  .rl-easa-modal-section h3 {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
  }
  .rl-easa-dropzone {
    border: 2px dashed #cbd5e1;
    border-radius: 10px;
    padding: 18px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
    margin-bottom: 10px;
    transition: border-color 0.15s, background 0.15s;
  }
  .rl-easa-dropzone.rl-easa-dropzone--hover {
    border-color: #3b82f6;
    background: #eff6ff;
  }
  .rl-easa-dropzone p {
    margin: 0;
    font-size: 13px;
    color: #475569;
  }
  .rl-easa-parse-progress-wrap {
    margin-top: 10px;
  }
  .rl-easa-parse-progress-track {
    height: 8px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
  }
  .rl-easa-parse-progress-bar {
    height: 100%;
    width: 0%;
    border-radius: 999px;
    background: linear-gradient(90deg, #22c55e, #16a34a);
    transition: width 0.2s ease-out;
  }
  .rl-easa-parse-progress-track.is-indeterminate::after {
    content: '';
    display: block;
    height: 100%;
    width: 40%;
    border-radius: 999px;
    background: linear-gradient(90deg, #86efac, #22c55e);
    animation: rl-easa-parse-indet 1s ease-in-out infinite;
  }
  @keyframes rl-easa-parse-indet {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(280%); }
  }
  .rl-easa-modal-footnote {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 8px;
  }
  .rl-easa-advanced-details {
    margin-top: 14px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 14px 12px;
    background: #fff;
  }
  .rl-easa-advanced-details summary {
    cursor: pointer;
    font-weight: 700;
    font-size: 13px;
    color: #334155;
    padding: 12px 0;
  }
  .rl-easa-ecfr-fields[hidden] {
    display: none !important;
  }
  .rl-easa-ai-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    margin: 12px 0 6px;
  }
</style>

<div class="rl-wrap rl-tab-panel rl-easa-page" id="rlEasaPage" data-api="<?= h($easaApiHref) ?>">
  <div class="rl-easa-metrics" id="rlEasaMetrics" aria-live="polite">
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">XML batches</div><div class="rl-easa-metric-value" id="rlEasaMetricBatches">—</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Indexed nodes</div><div class="rl-easa-metric-value" id="rlEasaMetricNodes">—</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Monitored URLs</div><div class="rl-easa-metric-value" id="rlEasaMetricMon">—</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Updates flagged</div><div class="rl-easa-metric-value" id="rlEasaMetricUpdates">—</div><div class="rl-easa-metric-sub">Download page monitor</div></div>
    <div class="rl-easa-metric-card"><div class="rl-easa-metric-label">Last probe (UTC)</div><div class="rl-easa-metric-value" id="rlEasaMetricLastProbe" style="font-size:1rem;">—</div></div>
  </div>

  <div class="rl-easa-source-scroll-wrap">
    <div class="rl-easa-source-row" id="rlEasaSourceRow" aria-label="EASA sources"></div>
  </div>

  <section class="card rl-easa-dash-panel" style="padding:16px 18px; margin-bottom:14px;">
    <span class="rl-easa-badge">AI · staging-backed</span>
    <h3>Ask AI about official regulations</h3>
    <p class="rl-easa-dash-lead">
      The server loads <strong>matching staging excerpts</strong> (same matching rules as keyword search) and optional U.S. eCFR text, then asks the configured model.
      Cards below list traceable batch / node references — verify every quote on official EASA sources.
    </p>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaChatSource">EASA source scope</label>
      <select id="rlEasaChatSource" style="max-width:100%;min-width:280px;font-size:13px;">
        <option value="">All indexed EASA sources (all batches)</option>
      </select>
    </div>
    <div class="rl-field" style="margin-bottom:8px;">
      <label class="rl-check-row" style="display:flex;gap:8px;align-items:flex-start;">
        <input type="checkbox" id="rlEasaChatCompareEcfr" style="margin-top:3px;">
        <span class="rl-check-label">Compare with U.S. eCFR / 14 CFR (optional excerpt via versioner API)</span>
      </label>
    </div>
    <div class="rl-easa-split rl-easa-ecfr-fields" id="rlEasaChatEcfrFields" hidden>
      <div class="rl-field">
        <label for="rlEasaEcfrTitle">14 CFR Title</label>
        <input type="number" id="rlEasaEcfrTitle" value="14" min="1" step="1">
      </div>
      <div class="rl-field">
        <label for="rlEasaEcfrSec">Section (e.g. 61.57)</label>
        <input type="text" id="rlEasaEcfrSec" placeholder="61.57" autocomplete="off">
      </div>
    </div>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaChatQ">Your question</label>
      <textarea id="rlEasaChatQ" rows="3" placeholder="e.g. Summarise recent pilot recency requirements and cite the relevant Easy Access blocks." style="width:100%;max-width:640px;font-family:inherit;font-size:13px;"></textarea>
    </div>
    <div class="rl-field" style="margin-bottom:8px;">
      <label class="rl-check-row" style="display:flex;gap:8px;align-items:flex-start;">
        <input type="checkbox" id="rlEasaChatUseAi" checked style="margin-top:3px;">
        <span class="rl-check-label">Ask AI (OpenAI when configured). Uncheck to preview staging / eCFR context only.</span>
      </label>
    </div>
    <div class="rl-test-actions">
      <button type="button" class="btn btn-sm" id="rlEasaChatAskBtn">Ask</button>
    </div>
    <div class="rl-easa-ai-label">AI explanation (not a legal opinion; verify against official publications)</div>
    <div class="rl-easa-ai-output is-empty" id="rlEasaChatAnswer" aria-live="polite">No answer yet.</div>
    <div class="rl-easa-ai-label">Official references from this installation (staging excerpts fed to the model)</div>
    <div class="rl-easa-citation-cards" id="rlEasaChatCitations"></div>
    <p class="rl-drop-meta" id="rlEasaChatContextNote" style="margin-top:10px;display:none;"></p>
  </section>

  <section class="card rl-easa-dash-panel" style="padding:16px 18px; margin-bottom:14px;">
    <span class="rl-easa-badge">Database search</span>
    <h3>Search official EASA XML database</h3>
    <p class="rl-easa-dash-lead">Full-text search over parsed staging (titles, ERulesId, breadcrumb, path, body). Pick a result to open the rule tree and highlight your term.</p>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaDbSearchBatch">Source (batch)</label>
      <select id="rlEasaDbSearchBatch" style="max-width:100%;min-width:280px;font-size:13px;">
        <option value="">All batches</option>
      </select>
    </div>
    <div class="rl-field" style="margin-bottom:8px;">
      <label for="rlEasaDbSearchQ">Keyword</label>
      <input type="text" id="rlEasaDbSearchQ" placeholder="e.g. FCL.055, alternate aerodrome" autocomplete="off" style="width:100%;max-width:520px;">
    </div>
    <div class="rl-test-actions">
      <button type="button" class="btn btn-sm" id="rlEasaDbSearchBtn">Search</button>
    </div>
    <div class="rl-easa-search-hits" id="rlEasaDbSearchHits" hidden></div>
    <p class="rl-drop-meta" id="rlEasaDbSearchEmpty" style="margin-top:8px;display:none;">No results yet.</p>
  </section>

  <section class="card rl-easa-dash-panel rl-easa-tree-dash" id="rlEasaTreeSection" style="padding:16px 18px; margin-bottom:14px;">
    <span class="rl-easa-badge">Browse corpus</span>
    <h3>Rule tree &amp; full text</h3>
    <p class="rl-easa-dash-lead">
      Canonical regulation viewer: semantic <code>tree_children</code> / <code>node_detail</code> only (ui_kind, material_type, expandable, click_action, child_count, display_title).
    </p>
    <div class="rl-field" style="margin-bottom:10px;">
      <label for="rlEasaTreeBatch">Batch (required)</label>
      <select id="rlEasaTreeBatch" style="max-width:100%;min-width:260px;font-size:13px;">
        <option value="">Load batches from status…</option>
      </select>
      <button type="button" class="btn btn-sm" id="rlEasaTreeLoadRoots" style="margin-left:8px;">Load tree roots</button>
    </div>
    <div class="rl-easa-browse-single">
      <p class="rl-drop-meta" id="rlEasaTreeHint" style="margin:0 0 8px;">Choose a batch and load roots.</p>
      <div class="rl-easa-tree-panel" id="rlEasaTreeMount" aria-label="Rule tree"></div>
    </div>
  </section>

  <details class="rl-easa-advanced-details">
    <summary>Tables · migration hints · full batch list</summary>
    <p class="rl-drop-meta" id="rlEasaMigrateHint" style="margin-top:8px;"></p>
    <p class="rl-drop-meta" style="margin-top:10px;font-weight:700;">Monitored download pages</p>
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
    <p class="rl-drop-meta" style="margin-top:14px;font-weight:700;">Recent batches</p>
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
  </details>
</div>

<div class="rl-easa-modal-overlay" id="rlEasaSourceModal" hidden>
  <div class="rl-easa-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rlEasaModalTitle">
    <div class="rl-easa-modal-head">
      <h2 id="rlEasaModalTitle">EASA source</h2>
      <button type="button" class="rl-easa-modal-close" id="rlEasaModalClose" aria-label="Close">&times;</button>
    </div>
    <div class="rl-easa-modal-body">
      <div class="rl-easa-modal-section" id="rlEasaModalSectionIdentity">
        <h3>Source identity</h3>
        <div id="rlEasaModalIdentityBody" class="rl-drop-meta" style="margin:0;line-height:1.5;"></div>
      </div>
      <div class="rl-easa-modal-section">
        <h3>Upload XML</h3>
        <div class="rl-easa-dropzone" id="rlEasaDropzone" tabindex="0">
          <p><strong>Drop a file</strong> or click to choose official Easy Access export · stored under <code>storage/easa_erules/</code></p>
          <input type="file" id="rlEasaXmlFile" accept=".xml,application/xml,text/xml" style="position:absolute;width:0;height:0;opacity:0;pointer-events:none;">
        </div>
        <div class="rl-panel-actions">
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
      </div>
      <div class="rl-easa-modal-section" id="rlEasaModalSectionParse">
        <h3>Parse XML → staging</h3>
        <p class="rl-drop-meta" style="margin:0 0 8px;">Streams large files on the server; progress polls <code>batch_progress</code> when async.</p>
        <div class="rl-panel-actions">
          <button type="button" class="btn btn-sm" id="rlEasaModalParseBtn" disabled>Parse XML → staging</button>
        </div>
        <div class="rl-easa-parse-progress-wrap" id="rlEasaModalParseProgressWrap" hidden>
          <div id="rlEasaModalParseProgressTrack" class="rl-easa-parse-progress-track">
            <div id="rlEasaModalParseProgressBar" class="rl-easa-parse-progress-bar"></div>
          </div>
          <div id="rlEasaModalParseProgressLabel" class="rl-drop-meta" style="margin-top:6px;font-size:12px;"></div>
        </div>
        <pre class="rl-test-out" id="rlEasaParseProgress" aria-live="polite" style="margin-top:10px; max-height:100px; min-height:2rem;">—</pre>
      </div>
      <div class="rl-easa-modal-section">
        <h3>Monitoring</h3>
        <p class="rl-drop-meta" style="margin:0 0 8px;">Probe every registered EASA download URL (polite HEAD). Cron: <code>cli/cron_easa_download_monitor.php</code>.</p>
        <div class="rl-panel-actions">
          <button type="button" class="btn btn-sm" id="rlEasaProbeBtn">Check now</button>
        </div>
        <p class="rl-drop-meta rl-easa-modal-footnote">Per-source monitor frequency and auto/manual routing require a future API — today all rows share the same probe job.</p>
      </div>
      <div class="rl-easa-modal-section">
        <h3>Live availability</h3>
        <label class="rl-check-row" style="display:flex;gap:8px;align-items:flex-start;opacity:0.55;">
          <input type="checkbox" id="rlEasaModalLiveToggle" disabled style="margin-top:3px;">
          <span class="rl-check-label">Mark source live for the platform (requires backend support — UI placeholder)</span>
        </label>
        <p class="rl-drop-meta" style="margin:8px 0 0;">Only batches that finished parsing cleanly appear as <strong>Live</strong> in the dashboard strip. Platform-wide rollout still needs catalogue wiring.</p>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('rlEasaPage');
  if (!root) return;
  var api = root.getAttribute('data-api') || '';
  /** Effective max POST body (bytes) from last status; 0 = unknown. */
  var rlEasaMaxUploadBytes = 0;
  var rlEasaTreeSelectedLi = null;
  var rlEasaModalBatchId = 0;
  var rlEasaPendingTreeHighlight = '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] || c;
    });
  }

  function rlEasaFormatAiAnswerHtml(text) {
    var src = String(text || '').replace(/\r\n?/g, '\n').trim();
    if (!src) return '';
    var lines = src.split('\n');
    var html = [];
    var para = [];
    var list = [];

    function inlineFmt(s) {
      var h = esc(s);
      h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
      return h;
    }
    function flushPara() {
      if (!para.length) return;
      html.push('<p>' + inlineFmt(para.join(' ')) + '</p>');
      para = [];
    }
    function flushList() {
      if (!list.length) return;
      html.push('<ul>' + list.map(function (it) { return '<li>' + inlineFmt(it) + '</li>'; }).join('') + '</ul>');
      list = [];
    }

    lines.forEach(function (raw) {
      var line = raw.trim();
      var m = /^[-*]\s+(.+)$/.exec(line);
      if (m) {
        flushPara();
        list.push(m[1].trim());
        return;
      }
      if (line === '') {
        flushPara();
        flushList();
        return;
      }
      flushList();
      para.push(line);
    });
    flushPara();
    flushList();
    return html.join('');
  }

  function rlEasaRenderChatWindowHtml(userText, aiText, userFirstName) {
    var q = String(userText || '').trim();
    var a = String(aiText || '').trim();
    var fn = String(userFirstName || '').trim();
    var userLabel = fn ? ('You (' + fn + ')') : 'You';
    var aiHtml = rlEasaFormatAiAnswerHtml(a || '(No AI text returned.)');
    var qHtml = '<p>' + esc(q || '—') + '</p>';

    return '<div class="rl-easa-chat-thread">'
      + '<div class="rl-easa-chat-row rl-easa-chat-row-user"><div class="rl-easa-chat-bubble rl-easa-chat-bubble-user">'
      + '<div class="rl-easa-chat-meta">' + esc(userLabel) + '</div>'
      + qHtml
      + '</div></div>'
      + '<div class="rl-easa-chat-row rl-easa-chat-row-system"><div class="rl-easa-chat-bubble rl-easa-chat-bubble-system">'
      + '<div class="rl-easa-chat-meta">System reply</div>'
      + (aiHtml || '<p>(No AI text returned.)</p>')
      + '</div></div>'
      + '</div>';
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

  /** tree_children / node_detail: parse body as JSON with a clear error if PHP emitted HTML or warnings. */
  function rlEasaFetchJsonBody(r) {
    return r.text().then(function (t) {
      var j = null;
      try {
        j = t && t.length ? JSON.parse(t) : null;
      } catch (e0) {
        var clip = String(t || '').replace(/\s+/g, ' ').trim().slice(0, 360);
        throw new Error(
          'Server response was not valid JSON (HTTP ' + (r.status || '') + '). '
            + (e0 && e0.message ? e0.message + '. ' : '')
            + (clip ? clip : '(empty body)')
        );
      }
      if (!j || typeof j !== 'object') {
        throw new Error('Server returned an empty or non-object JSON payload (HTTP ' + (r.status || '') + ').');
      }
      return j;
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

  function rlEasaCssEscape(s) {
    s = String(s || '');
    if (typeof CSS !== 'undefined' && CSS.escape) return CSS.escape(s);
    return s.replace(/[^a-zA-Z0-9_-]/g, function (ch) {
      return '\\' + ch;
    });
  }

  function rlEasaBatchLabel(b) {
    var fn = String(b.original_filename || 'batch').replace(/\.xml$/i, '');
    return fn.length > 46 ? fn.slice(0, 43) + '…' : fn;
  }

  function rlEasaSourceDisplayName(b) {
    var raw = String(b.original_filename || '').replace(/\.xml$/i, '').trim();
    if (!raw) return 'Source';
    var s = raw.replace(/^easy access rules for\s+/i, '');
    s = s.replace(/\s*-\s*part.*$/i, '');
    s = s.replace(/\s+/g, ' ').trim();
    if (!s) s = raw;
    return s;
  }

  function rlEasaFormatUploadedUtc(raw) {
    var s = String(raw || '').trim();
    if (!s) return '—';
    var iso = s.replace(' ', 'T');
    if (!/Z$/i.test(iso)) iso += 'Z';
    var d = new Date(iso);
    if (!(d instanceof Date) || isNaN(d.getTime())) return s;
    var wd = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getUTCDay()];
    var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
    var day = d.getUTCDate();
    var yr = d.getUTCFullYear();
    var hh = String(d.getUTCHours()).padStart(2, '0');
    var mm = String(d.getUTCMinutes()).padStart(2, '0');
    return wd + ' ' + mon + ' ' + day + ', ' + yr + ' (' + hh + ':' + mm + ' UTC)';
  }

  function rlEasaPublicationHint(b) {
    var raw = b.publication_meta_json;
    if (raw == null || raw === '') return '';
    var o = raw;
    if (typeof raw === 'string') {
      try {
        o = JSON.parse(raw);
      } catch (e) {
        return '';
      }
    }
    if (!o || typeof o !== 'object') return '';
    var a = o.attributes;
    if (a && typeof a === 'object') {
      var bits = [];
      ['issueDate', 'IssueDate', 'publicationDate', 'PublicationDate', 'version'].forEach(function (k) {
        if (a[k]) bits.push(String(a[k]));
      });
      if (bits.length) return bits.join(' · ');
    }
    return '';
  }

  function rlEasaFillBatchSelects(j) {
    function fill(sel, placeholder, blankFirstOptText) {
      if (!sel) return;
      var prev = sel.value;
      sel.innerHTML = '';
      var ph = document.createElement('option');
      ph.value = '';
      ph.textContent = placeholder;
      sel.appendChild(ph);
      (j.batches || []).forEach(function (b) {
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
    fill(document.getElementById('rlEasaTreeBatch'), '— Select batch —');
    fill(document.getElementById('rlEasaChatSource'), 'All indexed EASA sources (all batches)');
    fill(document.getElementById('rlEasaDbSearchBatch'), 'All batches');
  }

  function rlEasaApplyMetricEl(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val != null ? String(val) : '—';
  }

  function rlEasaRebuildSourceRow(j) {
    var row = document.getElementById('rlEasaSourceRow');
    if (!row) return;
    row.innerHTML = '';
    (j.monitor || []).forEach(function (m) {
      var card = document.createElement('button');
      card.type = 'button';
      card.className = 'rl-easa-source-card rl-easa-source-card--monitor';
      var upd = !!(m.changed_flag === 1 || m.changed_flag === true);
      var httpOk = parseInt(String(m.http_status || ''), 10) >= 200 && parseInt(String(m.http_status || ''), 10) < 400;
      var statusPill = upd
        ? '<span class="rl-easa-pill rl-easa-pill--warn">Update suspected</span>'
        : '<span class="rl-easa-pill rl-easa-pill--ok">Page stable</span>';
      if (!(m.checked_at || m.http_status)) {
        statusPill = '<span class="rl-easa-pill rl-easa-pill--muted">Not probed</span>';
      }
      var metaHtml = '';
      metaHtml += (m.last_modified || m.checked_at) ? esc(String(m.last_modified || m.checked_at)) : '—';
      if (m.http_status != null) metaHtml += '<br>HTTP ' + esc(String(m.http_status));
      card.innerHTML = '<div class="rl-easa-source-card-title">' + esc(m.label || 'Monitor') + '</div>'
        + '<div class="rl-easa-source-card-meta">' + metaHtml + '</div>'
        + '<div class="rl-easa-pill-row">'
        + statusPill
        + '<span class="rl-easa-pill rl-easa-pill--muted">Auto</span>'
        + '<span class="rl-easa-pill rl-easa-pill--off">Not live</span>'
        + '</div>';
      card.addEventListener('click', function () {
        rlEasaOpenSourceModal('monitor', m);
      });
      row.appendChild(card);
    });

    (j.batches || []).slice(0, 18).forEach(function (b) {
      var card = document.createElement('button');
      card.type = 'button';
      card.className = 'rl-easa-source-card rl-easa-source-card--batch';
      var bid = parseInt(b.id, 10) || 0;
      var nodes = parseInt(b.staging_nodes, 10) || 0;
      var st = String(b.status || '');
      var live = st === 'ready_for_review' && nodes > 0;
      var statusPill = '';
      if (st === 'failed') statusPill = '<span class="rl-easa-pill rl-easa-pill--bad">Failed</span>';
      else if (st === 'staging') statusPill = '<span class="rl-easa-pill rl-easa-pill--warn">Parsing</span>';
      else if (live) statusPill = '<span class="rl-easa-pill rl-easa-pill--ok">Up to date</span>';
      else if (st === 'uploaded') statusPill = '<span class="rl-easa-pill rl-easa-pill--muted">Awaiting parse</span>';
      else statusPill = '<span class="rl-easa-pill rl-easa-pill--muted">' + esc(st || '—') + '</span>';
      var livePill = live
        ? '<span class="rl-easa-pill rl-easa-pill--live">Live</span>'
        : '<span class="rl-easa-pill rl-easa-pill--off">Not live</span>';
      var uploaded = rlEasaFormatUploadedUtc(b.created_at || '');
      var metaLine = '<span class="rl-easa-source-sublabel">EASA EASY ACCESS RULES</span>'
        + '<span class="rl-easa-source-sublabel-gap" aria-hidden="true"></span>'
        + 'Nodes: ' + esc(String(nodes))
        + '<br>Uploaded on: ' + esc(uploaded);
      card.innerHTML = '<div class="rl-easa-source-card-title">' + esc(rlEasaSourceDisplayName(b)) + '</div>'
        + '<div class="rl-easa-source-card-meta">' + metaLine + '</div>'
        + '<div class="rl-easa-pill-row">' + statusPill + livePill
        + '<span class="rl-easa-pill rl-easa-pill--muted">Manual</span></div>';
      card.addEventListener('click', function () {
        rlEasaOpenSourceModal('batch', b);
      });
      row.appendChild(card);
    });

    var add = document.createElement('button');
    add.type = 'button';
    add.className = 'rl-easa-source-card rl-easa-source-card--add';
    add.innerHTML = '<span>+ Add XML Source</span>';
    add.addEventListener('click', function () {
      rlEasaOpenSourceModal('new', null);
    });
    row.appendChild(add);
  }

  function rlEasaSetModalParseProgressUi(opts) {
    var wrap = document.getElementById('rlEasaModalParseProgressWrap');
    var track = document.getElementById('rlEasaModalParseProgressTrack');
    var bar = document.getElementById('rlEasaModalParseProgressBar');
    var lab = document.getElementById('rlEasaModalParseProgressLabel');
    if (!wrap || !track || !bar || !lab) return;
    if (!opts || !opts.show) {
      wrap.hidden = true;
      track.classList.remove('is-indeterminate');
      bar.style.width = '0%';
      lab.textContent = '';
      return;
    }
    wrap.hidden = false;
    if (opts.indeterminate) {
      track.classList.add('is-indeterminate');
      bar.style.width = '0%';
    } else {
      track.classList.remove('is-indeterminate');
      var p = opts.pct != null ? opts.pct : 0;
      bar.style.width = Math.min(100, Math.max(0, p)) + '%';
    }
    lab.textContent = opts.label || '';
  }

  function rlEasaStartParse(batchId, busyBtn) {
    var id = parseInt(batchId, 10) || 0;
    if (!id) return;
    var btn = busyBtn || document.getElementById('rlEasaModalParseBtn');
    var asyncPolling = false;
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    var prog = document.getElementById('rlEasaParseProgress');
    if (prog) prog.textContent = 'Starting parse… (large XML can take several minutes in synchronous mode)';
    rlEasaSetModalParseProgressUi({ show: true, indeterminate: true, label: 'Parse running…' });
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
          if (prog) prog.textContent = 'Import running on server (batch ' + id + '). Polling progress every 1.5s…';
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
                  if (prog) prog.textContent = 'Could not read batch progress: ' + errMsg + ' · retrying…';
                  return;
                }
                pollErrs = 0;
                var bb = pr.j.batch;
                var rowCount = bb.parse_rows_so_far;
                if (rowCount == null || rowCount === '') rowCount = bb.rows_detected;
                if (rowCount == null || rowCount === '') rowCount = '—';
                var rd = parseInt(bb.rows_detected, 10) || 0;
                var rsf = parseInt(bb.parse_rows_so_far, 10) || 0;
                var pct = rd > 0 ? Math.round((rsf / rd) * 1000) / 10 : null;
                var phase = bb.parse_phase;
                if (!phase && bb.status === 'ready_for_review') phase = 'completed';
                else if (!phase && bb.status === 'failed') phase = 'failed';
                else if (!phase && bb.status === 'staging') phase = 'running';
                if (!phase) phase = '—';
                var line = [
                  'status=' + (bb.status || ''),
                  'phase=' + phase,
                  'rows=' + rowCount,
                  (bb.parse_last_node_type ? 'last<' + bb.parse_last_node_type + '>' : ''),
                  (bb.parse_detail || '')
                ].filter(Boolean).join(' · ');
                if (prog) prog.textContent = line || '—';
                var labelLine = phase + ' · rows ' + rowCount + (pct != null && rd > 0 ? ' · ~' + pct + '%' : '');
                rlEasaSetModalParseProgressUi({
                  show: true,
                  indeterminate: rd <= 0,
                  pct: rd > 0 ? pct : 0,
                  label: labelLine
                });
                if (bb.status === 'ready_for_review' || bb.status === 'failed') {
                  if (timer) clearInterval(timer);
                  rlEasaSetModalParseProgressUi({ show: true, indeterminate: false, pct: 100, label: bb.status === 'failed' ? 'Failed' : 'Completed' });
                  if (btn) {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                  }
                  loadStatus();
                  var hint = document.getElementById('rlEasaMigrateHint');
                  if (hint && bb.status === 'ready_for_review') {
                    hint.textContent = 'Import finished: ' + (bb.rows_detected || bb.parse_rows_so_far || 0) + ' nodes staged.';
                  }
                  if (hint && bb.status === 'failed') {
                    hint.textContent = 'Import failed: ' + (bb.error_message || bb.parse_detail || 'see batch row');
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
              if (btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
              }
              if (prog) prog.textContent += '\nStopped polling after timeout; reload the page to see final status.';
            }
          }
          pollBatch();
          timer = setInterval(pollBatch, 1500);
          return;
        }
        rlEasaSetModalParseProgressUi({ show: true, indeterminate: false, pct: 100, label: 'Completed (sync)' });
        if (prog) prog.textContent = 'Done: ' + (x.j.imported || 0) + ' nodes.';
        var mh = document.getElementById('rlEasaMigrateHint');
        if (mh) mh.textContent = x.j.message || ('Imported ' + (x.j.imported || 0) + ' nodes.');
        loadStatus();
      })
      .catch(function (e) {
        var mh = document.getElementById('rlEasaMigrateHint');
        if (mh) mh.textContent = e.message || 'Parse failed';
        if (prog) prog.textContent = e.message || 'Parse failed';
        rlEasaSetModalParseProgressUi(null);
      })
      .finally(function () {
        if (!asyncPolling && btn) {
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
        }
      });
  }

  function rlEasaCloseSourceModal() {
    var m = document.getElementById('rlEasaSourceModal');
    if (m) m.hidden = true;
  }

  function rlEasaOpenSourceModal(kind, payload) {
    var m = document.getElementById('rlEasaSourceModal');
    var titleEl = document.getElementById('rlEasaModalTitle');
    var ident = document.getElementById('rlEasaModalIdentityBody');
    var parseBtn = document.getElementById('rlEasaModalParseBtn');
    if (!m || !titleEl || !ident || !parseBtn) return;
    m.hidden = false;
    rlEasaModalBatchId = 0;
    parseBtn.disabled = true;
    parseBtn.removeAttribute('data-batch-id');
    if (kind === 'new') {
      titleEl.textContent = 'Add XML source';
      ident.innerHTML = '<p style="margin:0;">Upload a new Easy Access export, then parse it into staging.</p>';
    } else if (kind === 'download_settings') {
      titleEl.textContent = 'Easy Access Download settings';
      ident.innerHTML = '<strong>Purpose</strong><br>Configure and monitor official EASA Easy Access download URLs.<br><br>'
        + '<strong>How it works</strong><br>Use “Check now” to run a probe immediately. The watch list and update flags are shown in the tables area below this dashboard.';
    } else if (kind === 'monitor' && payload) {
      titleEl.textContent = payload.label ? String(payload.label) : 'Monitored URL';
      var flag = !!(payload.changed_flag === 1 || payload.changed_flag === true);
      ident.innerHTML = '<strong>URL</strong><br>'
        + esc(String(payload.url || ''))
        + '<br><br><strong>Last check (UTC)</strong> ' + esc(String(payload.checked_at || '—'))
        + '<br><strong>Update suspected</strong> ' + (flag ? 'yes' : 'no');
    } else if (kind === 'batch' && payload) {
      rlEasaModalBatchId = parseInt(payload.id, 10) || 0;
      parseBtn.disabled = rlEasaModalBatchId <= 0;
      parseBtn.setAttribute('data-batch-id', String(rlEasaModalBatchId));
      titleEl.textContent = rlEasaBatchLabel(payload);
      var nodes = payload.staging_nodes != null ? String(payload.staging_nodes) : '?';
      var pub = rlEasaPublicationHint(payload);
      titleEl.textContent = titleEl.textContent + ' · #' + rlEasaModalBatchId;
      ident.innerHTML = '<strong>Batch ID</strong> ' + esc(String(payload.id))
        + '<br><strong>File</strong> ' + esc(String(payload.original_filename || ''))
        + '<br><strong>Status</strong> ' + esc(String(payload.status || ''))
        + '<br><strong>Nodes indexed</strong> ' + esc(nodes)
        + (pub ? '<br><strong>Publication hints</strong> ' + esc(pub) : '')
        + '<br><strong>SHA-256</strong> <span style="word-break:break-all;">' + esc(String(payload.file_sha256 || '')) + '</span>'
        + '<br><strong>EASA download URL</strong> Configure in monitored URLs table above (batch rows are linked operationally via Compliance process).';
    }
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
        rlEasaFillBatchSelects(x.j);
        var batches = x.j.batches || [];
        var monitors = x.j.monitor || [];
        var indexed = parseInt(String(x.j.indexed_nodes || 0), 10) || 0;
        var updates = 0;
        var lastProbe = '';
        monitors.forEach(function (r) {
          if (r.changed_flag === 1 || r.changed_flag === true) updates++;
          var ca = String(r.checked_at || '');
          if (ca && (!lastProbe || ca > lastProbe)) lastProbe = ca;
        });
        rlEasaApplyMetricEl('rlEasaMetricBatches', batches.length);
        rlEasaApplyMetricEl('rlEasaMetricNodes', indexed);
        rlEasaApplyMetricEl('rlEasaMetricMon', monitors.length);
        rlEasaApplyMetricEl('rlEasaMetricUpdates', updates);
        rlEasaApplyMetricEl('rlEasaMetricLastProbe', lastProbe || '—');
        rlEasaRebuildSourceRow(x.j);
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
              rlEasaStartParse(id, btn);
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

  var modalOv = document.getElementById('rlEasaSourceModal');
  var modalCloseBtn = document.getElementById('rlEasaModalClose');
  if (modalOv && modalCloseBtn) {
    modalCloseBtn.addEventListener('click', rlEasaCloseSourceModal);
    modalOv.addEventListener('click', function (ev) {
      if (ev.target === modalOv) rlEasaCloseSourceModal();
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !modalOv.hidden) rlEasaCloseSourceModal();
    });
  }
  var modalParseBtn = document.getElementById('rlEasaModalParseBtn');
  if (modalParseBtn) {
    modalParseBtn.addEventListener('click', function () {
      var bid = parseInt(modalParseBtn.getAttribute('data-batch-id') || String(rlEasaModalBatchId) || '0', 10);
      rlEasaStartParse(bid, modalParseBtn);
    });
  }
  var dz = document.getElementById('rlEasaDropzone');
  var fileInpGlob = document.getElementById('rlEasaXmlFile');
  if (dz && fileInpGlob) {
    function dzPick() {
      fileInpGlob.click();
    }
    dz.addEventListener('click', dzPick);
    dz.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        dzPick();
      }
    });
    ['dragenter', 'dragover'].forEach(function (evn) {
      dz.addEventListener(evn, function (e) {
        e.preventDefault();
        dz.classList.add('rl-easa-dropzone--hover');
      });
    });
    ['dragleave', 'dragend'].forEach(function (evn) {
      dz.addEventListener(evn, function () {
        dz.classList.remove('rl-easa-dropzone--hover');
      });
    });
    dz.addEventListener('drop', function (e) {
      e.preventDefault();
      dz.classList.remove('rl-easa-dropzone--hover');
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f && fileInpGlob) {
        try {
          fileInpGlob.files = e.dataTransfer.files;
        } catch (err) {
          /* assign may fail on some browsers */
        }
        if (!fileInpGlob.files || !fileInpGlob.files.length) {
          var dt = new DataTransfer();
          dt.items.add(f);
          fileInpGlob.files = dt.files;
        }
      }
    });
  }

  var chatEcfrCh = document.getElementById('rlEasaChatCompareEcfr');
  var chatEcfrFields = document.getElementById('rlEasaChatEcfrFields');
  if (chatEcfrCh && chatEcfrFields) {
    chatEcfrCh.addEventListener('change', function () {
      chatEcfrFields.hidden = !chatEcfrCh.checked;
    });
  }

  var heroSetBtn = document.getElementById('rlEasaHeroSettingsBtn');
  if (heroSetBtn) {
    heroSetBtn.addEventListener('click', function () {
      rlEasaOpenSourceModal('download_settings', null);
    });
  }

  function rlEasaHighlightInTextNodes(rootEl, needle) {
    if (!rootEl || !needle) return;
    var low = needle.toLowerCase();
    var walk = rootEl.ownerDocument.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT, null);
    var n;
    /* collect first — mutating expands NodeIterator */
    var batch = [];
    while ((n = walk.nextNode())) {
      batch.push(n);
    }
    batch.forEach(function (textNode) {
      var txt = textNode.nodeValue || '';
      if (!txt.trim()) return;
      var tl = txt.toLowerCase();
      var idx = tl.indexOf(low);
      if (idx < 0) return;
      var frag = textNode.ownerDocument.createDocumentFragment();
      var pos = 0;
      while (idx >= 0) {
        if (idx > pos) frag.appendChild(textNode.ownerDocument.createTextNode(txt.slice(pos, idx)));
        var mk = textNode.ownerDocument.createElement('mark');
        mk.className = 'rl-easa-kw-hl';
        mk.appendChild(textNode.ownerDocument.createTextNode(txt.slice(idx, idx + needle.length)));
        frag.appendChild(mk);
        pos = idx + needle.length;
        idx = tl.indexOf(low, pos);
      }
      if (pos < txt.length) frag.appendChild(textNode.ownerDocument.createTextNode(txt.slice(pos)));
      textNode.parentNode.replaceChild(frag, textNode);
    });
  }

  function rlEasaFetchNodeDetail(batchId, nodeUid) {
    return fetch(
      api + '?action=node_detail&batch_id=' + encodeURIComponent(String(batchId)) + '&node_uid=' + encodeURIComponent(nodeUid),
      { credentials: 'same-origin' }
    ).then(function (r) {
      return r.json();
    });
  }

  function rlEasaAncestorUidChain(batchId, nodeUid) {
    return new Promise(function (resolve, reject) {
      var chain = [];
      var depthGuard = 0;
      function step(uid) {
        if (depthGuard++ > 800) {
          reject(new Error('Ancestor walk depth exceeded'));
          return;
        }
        rlEasaFetchNodeDetail(batchId, uid).then(function (j) {
          if (!j.ok || !j.node) {
            reject(new Error((j && j.error) || 'node_detail failed'));
            return;
          }
          chain.push(uid);
          var p = (j.node.parent_node_uid || '').trim();
          if (!p) {
            resolve(chain.reverse());
            return;
          }
          step(p);
        }).catch(reject);
      }
      step(nodeUid);
    });
  }

  function rlEasaEnsureChildUlLoaded(li, batchId, treeResolveOptions) {
    return new Promise(function (resolve, reject) {
      var sub = li.querySelector(':scope > ul.rl-easa-tree-list');
      if (!sub) {
        resolve();
        return;
      }
      if (sub.getAttribute('data-loaded') === '1') {
        resolve();
        return;
      }
      sub.innerHTML = '';
      var uid = li.getAttribute('data-node-uid') || '';
      rlEasaTreeFetchTreeChildrenJson(batchId, uid)
        .then(function (j) {
          return rlEasaTreeResolveLegalRootNodes(batchId, j, treeResolveOptions);
        })
        .then(function (resolved) {
          resolved.nodes.forEach(function (c) {
            sub.appendChild(rlEasaCreateTreeLi(batchId, c));
          });
          sub.setAttribute('data-loaded', '1');
          sub.hidden = false;
          var exp = li.querySelector(':scope > .rl-easa-tree-row > .rl-easa-tree-exp');
          if (exp && !exp.disabled) {
            exp.textContent = '\u25bc';
            exp.setAttribute('aria-expanded', 'true');
          }
          resolve();
        })
        .catch(reject);
    });
  }

  function rlEasaRevealTreeNode(batchId, targetUid, highlightNeedle) {
    rlEasaPendingTreeHighlight = (highlightNeedle || '').trim();
    var mount = document.getElementById('rlEasaTreeMount');
    var sel = document.getElementById('rlEasaTreeBatch');
    if (!mount || !sel) return Promise.reject(new Error('Tree UI missing'));
    sel.value = String(batchId);
    mount.innerHTML = '<p class="rl-drop-meta" style="margin:0;">Loading tree path…</p>';
    return rlEasaAncestorUidChain(batchId, targetUid).then(function (chain) {
      /* chain: root ... target */
      if (!chain.length) throw new Error('Empty ancestor chain');
      var revealOpts = { chainUids: chain };
      return rlEasaTreeFetchTreeChildrenJson(batchId, '')
        .then(function (j) {
          return rlEasaTreeResolveLegalRootNodes(batchId, j, revealOpts);
        })
        .then(function (resolved) {
          rlEasaRenderTreeIntoMount(mount, batchId, resolved.nodes);
          var ul = mount.querySelector(':scope > ul.rl-easa-tree-list');
          if (!ul) throw new Error('Tree mount empty');
          var path = chain.slice();
          while (path.length > 0) {
            var head = path[0];
            if (ul.querySelector(':scope > li[data-node-uid="' + rlEasaCssEscape(head) + '"]')) break;
            path.shift();
          }
          if (!path.length) throw new Error('Tree roots do not contain this ancestor chain.');
          function descend(idx) {
            if (idx >= path.length) return Promise.resolve(null);
            var uid = path[idx];
            var li = ul.querySelector(':scope > li[data-node-uid="' + rlEasaCssEscape(uid) + '"]');
            if (!li) return Promise.reject(new Error('Could not find node ' + uid + ' in tree (try Load tree roots).'));
            if (idx === path.length - 1) return Promise.resolve(li);
            return rlEasaEnsureChildUlLoaded(li, batchId, revealOpts).then(function () {
              var nextUl = li.querySelector(':scope > ul.rl-easa-tree-list');
              if (!nextUl) return Promise.reject(new Error('No children container'));
              ul = nextUl;
              return descend(idx + 1);
            });
          }
          return descend(0).then(function (li) {
            if (!li) return;
            try {
              li.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (e0) {}
            rlEasaShowNodeDetail(batchId, targetUid, li, true);
          });
        });
    }).catch(function (e) {
      mount.innerHTML = '<p class="rl-drop-meta" style="margin:0;color:#991b1b;">' + esc(e.message || 'Tree navigation failed') + '</p>';
      throw e;
    });
  }

  var dbSearchBtn = document.getElementById('rlEasaDbSearchBtn');
  var dbHits = document.getElementById('rlEasaDbSearchHits');
  var dbEmpty = document.getElementById('rlEasaDbSearchEmpty');
  if (dbSearchBtn && dbHits) {
    dbSearchBtn.addEventListener('click', function () {
      var qEl = document.getElementById('rlEasaDbSearchQ');
      var q = qEl ? (qEl.value || '').trim() : '';
      dbHits.hidden = true;
      dbHits.innerHTML = '';
      if (dbEmpty) dbEmpty.style.display = 'none';
      if (!q) {
        if (dbEmpty) {
          dbEmpty.textContent = 'Enter a keyword.';
          dbEmpty.style.display = 'block';
        }
        return;
      }
      var batchEl = document.getElementById('rlEasaDbSearchBatch');
      var bid = batchEl && batchEl.value ? parseInt(batchEl.value, 10) : 0;
      var payload = { action: 'search', query: q, limit: 100 };
      if (bid > 0) payload.batch_id = bid;
      dbSearchBtn.disabled = true;
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Search failed');
          var hits = x.j.hits || [];
          if (hits.length === 0) {
            dbHits.hidden = true;
            if (dbEmpty) {
              dbEmpty.textContent = (x.j.note || 'No matches.') + '';
              dbEmpty.style.display = 'block';
            }
            return;
          }
          hits.forEach(function (h) {
            var bt = document.createElement('button');
            bt.type = 'button';
            bt.className = 'rl-easa-search-hit';
            var t = (h.source_erules_id || h.title || h.node_uid || 'Hit');
            var sn = (h.snippet || '').replace(/\s+/g, ' ').trim();
            bt.innerHTML = '<div class="rl-easa-search-hit-title">' + esc(String(t))
              + ' <span class="rl-drop-meta">· batch ' + esc(String(h.batch_id != null ? h.batch_id : '—')) + '</span></div>'
              + '<div class="rl-easa-search-hit-snip">' + esc(sn) + '</div>';
            bt.addEventListener('click', function () {
              var treeSec = document.getElementById('rlEasaTreeSection');
              if (treeSec && treeSec.scrollIntoView) treeSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
              var hBid = parseInt(h.batch_id, 10) || 0;
              var nuid = (h.node_uid || '').trim();
              if (!hBid || !nuid) return;
              rlEasaRevealTreeNode(hBid, nuid, q).catch(function () {});
            });
            dbHits.appendChild(bt);
          });
          dbHits.hidden = false;
        })
        .catch(function (e) {
          if (dbEmpty) {
            dbEmpty.textContent = e.message || 'Error';
            dbEmpty.style.display = 'block';
          }
        })
        .finally(function () { dbSearchBtn.disabled = false; });
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

  function rlEasaShowNodeDetail(batchId, uid, liElm, forceReload) {
    if (!liElm) return;
    var wrap = liElm.querySelector(':scope > .rl-easa-inline-detail');
    if (!wrap) return;
    var band = wrap.querySelector('.rl-easa-inline-band');
    var meta = wrap.querySelector('.rl-easa-inline-meta');
    var body = wrap.querySelector('.rl-easa-inline-body');
    if (!band || !meta || !body) return;

    // Second click on the same row closes this panel (open panels may stay open for side-by-side compare).
    if (!wrap.hidden && !forceReload) {
      var loading = wrap.getAttribute('data-loading') === '1';
      var loadedHere = wrap.getAttribute('data-loaded-uid') === uid;
      if (loading || loadedHere) {
        wrap.hidden = true;
        wrap.removeAttribute('data-loading');
        liElm.classList.remove('rl-easa-tree-li-selected');
        if (rlEasaTreeSelectedLi === liElm) rlEasaTreeSelectedLi = null;
        return;
      }
    }
    if (rlEasaTreeSelectedLi && rlEasaTreeSelectedLi !== liElm) {
      rlEasaTreeSelectedLi.classList.remove('rl-easa-tree-li-selected');
    }
    rlEasaTreeSelectedLi = liElm;
    liElm.classList.add('rl-easa-tree-li-selected');
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
        if (n.requested_node_uid && String(n.requested_node_uid).trim() !== ''
            && String(n.requested_node_uid) !== String(n.node_uid || '')) {
          bits.push('requested_node_uid=' + String(n.requested_node_uid));
          bits.push('effective_node_uid=' + String((n.effective_node_uid != null && String(n.effective_node_uid).trim() !== '')
            ? n.effective_node_uid : n.node_uid || ''));
        }
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
        if (rlEasaPendingTreeHighlight) {
          rlEasaHighlightInTextNodes(body, rlEasaPendingTreeHighlight);
          rlEasaPendingTreeHighlight = '';
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

  function rlEasaTreeMaterialDotClass(mt) {
    var m = String(mt || 'IR').toUpperCase();
    if (m === 'AMC') return 'rl-easa-tree-dot rl-easa-tree-dot-amc';
    if (m === 'GM') return 'rl-easa-tree-dot rl-easa-tree-dot-gm';
    return 'rl-easa-tree-dot rl-easa-tree-dot-ir';
  }

  /** Parse semantic API boolean (expandable). */
  function rlEasaSemanticBool(v) {
    if (v === true || v === 1) return true;
    if (typeof v === 'string') {
      var s = v.toLowerCase();
      return s === 'true' || s === '1';
    }
    return false;
  }

  /** GET tree_children (semantic nodes only). Omit parentUid for corpus roots. */
  function rlEasaTreeFetchTreeChildrenJson(batchId, parentUid) {
    var url = api + '?action=tree_children&batch_id=' + encodeURIComponent(String(batchId));
    if (parentUid != null && String(parentUid).trim() !== '') {
      url += '&parent_uid=' + encodeURIComponent(String(parentUid).trim());
    }
    return fetch(url, { credentials: 'same-origin' }).then(rlEasaFetchJsonBody);
  }

  /** First display line from API display_title (trusted semantic contract field). */
  function rlEasaSemanticDisplayTitleFirstLine(node) {
    var t = String(node && node.display_title != null ? node.display_title : '').trim();
    if (!t) return '';
    var parts = t.split(/\r\n|\n|\r/);
    return String(parts[0] || '').trim();
  }

  /** Non-empty trimmed lines from display_title (for annex filter when label wraps past first line). */
  function rlEasaSemanticDisplayTitleLines(node) {
    var t = String(node && node.display_title != null ? node.display_title : '').trim();
    if (!t) return [];
    return t.split(/\r\n|\n|\r/).map(function (ln) {
      return String(ln || '').trim();
    }).filter(function (s) { return s !== ''; });
  }

  /** Matches backend easa_erules_tree_title_is_structural_section keywords on display_title only. */
  function rlEasaSemanticDisplayTitleIsStructuralNavHeading(node) {
    var line = rlEasaSemanticDisplayTitleFirstLine(node);
    if (line === '') return false;
    // Align with backend: "Appendix 1 – …" is IR body text, not structural APPENDIX nav.
    if (/^\s*Appendix\s+([0-9]+|[IVXLCDM]+)\b/i.test(line)) {
      return false;
    }
    return (
      /^\s*(ANNEX|SUBPART|SECTION|APPENDIX|CHAPTER|TITLE|PART)\b/i.test(line)
      || /^\s*Appendices\s+to\s+Annex\b/i.test(line)
    );
  }

  /** True if any line of display_title is an ANNEX navigational row (sibling filter). */
  function rlEasaSemanticDisplayTitleIsAnnexRow(node) {
    var lines = rlEasaSemanticDisplayTitleLines(node);
    for (var i = 0; i < lines.length; i++) {
      if (/^\s*ANNEX\b/i.test(lines[i])) return true;
    }
    return false;
  }

  /** True if any display_title line is a SUBJECT syllabus row (Part-FCL / aircrew knowledge — must survive annex filtering). */
  function rlEasaSemanticDisplayTitleIsSubjectSyllabusRow(node) {
    var lines = rlEasaSemanticDisplayTitleLines(node);
    for (var i = 0; i < lines.length; i++) {
      if (/^\s*SUBJECT\b/i.test(lines[i])) return true;
    }
    return false;
  }

  /**
   * Document / editorial shell only: expandable section heading that is not legal nav (ANNEX/SUBPART/…).
   * Uses only ui_kind, material_type, expandable, click_action, child_count, display_title — never child_count heuristics across siblings.
   */
  function rlEasaSemanticNodeIsDocumentShellUnwrappable(node) {
    if (!node || typeof node !== 'object') return false;
    if (node.ui_kind !== 'section') return false;
    if (String(node.material_type || '').toUpperCase() !== 'HEADING') return false;
    if (!rlEasaSemanticBool(node.expandable)) return false;
    if (String(node.click_action || '') !== 'expand') return false;
    if ((parseInt(node.child_count, 10) || 0) <= 0) return false;
    if (rlEasaSemanticDisplayTitleIsStructuralNavHeading(node)) return false;
    return true;
  }

  /**
   * Strict ANNEX-level sibling policy (normal browsing). Do not broaden this for “content” exceptions — that reintroduces DTO/editorial pollution.
   * When any sibling is an ANNEX row: keep only ANNEX + SUBJECT syllabus rows; drop the rest.
   */
  function rlEasaTreeAnnexSiblingFilter(nodes) {
    if (!nodes || !nodes.length) return nodes ? nodes.slice() : [];
    var annex = [];
    for (var i = 0; i < nodes.length; i++) {
      if (rlEasaSemanticDisplayTitleIsAnnexRow(nodes[i])) annex.push(nodes[i]);
    }
    if (!annex.length) {
      return nodes.slice();
    }
    var kept = [];
    for (var j = 0; j < nodes.length; j++) {
      var n = nodes[j];
      if (rlEasaSemanticDisplayTitleIsAnnexRow(n) || rlEasaSemanticDisplayTitleIsSubjectSyllabusRow(n)) {
        kept.push(n);
      }
    }
    return kept.length ? kept : nodes.slice();
  }

  /**
   * Reveal / open-in-tree ONLY: apply strict rlEasaTreeAnnexSiblingFilter, then put back siblings whose uid is in chainUids
   * (in raw order). Never returns full rawNodes — avoids DTO.GEN / editorial branches reappearing at ANNEX levels during reveal.
   * Normal browse never passes chainUids; Load roots / manual expand stay strict.
   * @param {?Array<string>} chainUids ancestor chain from rlEasaAncestorUidChain (optional; reveal path only)
   */
  function rlEasaTreeApplyAnnexSiblingFilterPreservingChain(rawNodes, chainUids) {
    if (!rawNodes || !rawNodes.length) {
      return [];
    }
    var filtered = rlEasaTreeAnnexSiblingFilter(rawNodes);
    if (!Array.isArray(chainUids) || chainUids.length === 0) {
      return filtered;
    }
    var chainSet = {};
    for (var ci = 0; ci < chainUids.length; ci++) {
      var cx = String(chainUids[ci] || '').trim();
      if (cx) {
        chainSet[cx] = true;
      }
    }
    var filtByUid = {};
    for (var fi = 0; fi < filtered.length; fi++) {
      var fu = String(filtered[fi].id || filtered[fi].node_uid || '').trim();
      if (fu) {
        filtByUid[fu] = true;
      }
    }
    var out = [];
    for (var ri = 0; ri < rawNodes.length; ri++) {
      var n = rawNodes[ri];
      var uid = String(n.id || n.node_uid || '').trim();
      if (!uid) {
        continue;
      }
      if (filtByUid[uid] || chainSet[uid]) {
        out.push(n);
      }
    }
    return out.length ? out : filtered.slice();
  }

  /**
   * Legal-root shaping for corpus roots, reveal, and manual tree expand.
   * options.chainUids: set only for reveal — rlEasaTreeApplyAnnexSiblingFilterPreservingChain injects chain uids without dumping full raw siblings.
   *
   * @return Promise<{ nodes: array }>
   */
  function rlEasaTreeResolveLegalRootNodes(batchId, j, options) {
    if (!j || !j.ok || !Array.isArray(j.nodes)) {
      return Promise.reject(new Error((j && j.error) || 'Failed to load tree'));
    }
    var chainUids = (options && options.chainUids) || null;
    function revealChainHasUid(uid) {
      if (!chainUids || !chainUids.length || uid == null || uid === '') return false;
      var u = String(uid).trim();
      if (!u) return false;
      for (var ci = 0; ci < chainUids.length; ci++) {
        if (String(chainUids[ci] || '').trim() === u) return true;
      }
      return false;
    }
    function descend(nodes, depthGuard) {
      if (depthGuard > 8) {
        return Promise.resolve(nodes);
      }
      var level = rlEasaTreeApplyAnnexSiblingFilterPreservingChain(nodes, chainUids);
      if (level.length !== 1) {
        return Promise.resolve(level);
      }
      var sole = level[0];
      if (!rlEasaSemanticNodeIsDocumentShellUnwrappable(sole)) {
        return Promise.resolve(level);
      }
      var puid = String(sole.id || sole.node_uid || '').trim();
      if (!puid) {
        return Promise.resolve(level);
      }
      /* Reveal path: never unwrap a shell row that is on the ancestor chain — DOM must keep that li (open-in-tree / node_detail target). */
      if (revealChainHasUid(puid)) {
        return Promise.resolve(level);
      }
      return rlEasaTreeFetchTreeChildrenJson(batchId, puid).then(function (jInner) {
        if (!jInner || !jInner.ok || !Array.isArray(jInner.nodes)) {
          throw new Error((jInner && jInner.error) || 'Tree load failed');
        }
        return descend(jInner.nodes, depthGuard + 1);
      });
    }
    return descend(j.nodes, 0).then(function (finalNodes) {
      return { nodes: finalNodes };
    });
  }

  function rlEasaCreateTreeLi(batchId, n) {
    var li = document.createElement('li');
    li.className = 'rl-easa-tree-li';
    var uid = String(n.id || n.node_uid || '').trim();
    if (uid) li.setAttribute('data-node-uid', uid);
    var uiKind = n.ui_kind === 'section' ? 'section' : 'rule';
    var mtRaw = String(n.material_type || '').toUpperCase();
    var mt = uiKind === 'section' ? 'HEADING' : mtRaw;
    if (uiKind === 'rule' && (!mt || mt === 'HEADING')) {
      mt = 'IR';
    }
    var expandable = rlEasaSemanticBool(n.expandable);
    var opensRule = String(n.click_action || '') === 'open_rule';
    /** Section rows use click_action expand in the API even when child_count=0; title then opens node_detail. */
    var sectionTitleOpensDetail = uiKind === 'section' && (!expandable || opensRule);
    var sectionTitleTogglesExpand = uiKind === 'section' && expandable && !opensRule;
    var disp = (n.display_title != null && String(n.display_title).trim() !== '')
      ? String(n.display_title).trim()
      : (uid || '—');
    var isSupplement = mt === 'GM' || mt === 'AMC';
    var showTreeExpand = expandable;

    var row = document.createElement('div');
    row.className = 'rl-easa-tree-row' + (uiKind === 'section' ? ' rl-easa-tree-row--section' : ' rl-easa-tree-row--rule');

    var exp = document.createElement('button');
    exp.type = 'button';
    exp.className = 'rl-easa-tree-exp';
    exp.setAttribute('aria-expanded', 'false');
    if (!showTreeExpand) {
      exp.disabled = true;
      exp.textContent = '\u00a0';
      exp.style.visibility = 'hidden';
      exp.setAttribute('aria-hidden', 'true');
    } else {
      exp.textContent = '\u25b6';
      if (uiKind === 'section') {
        exp.setAttribute('aria-label', 'Expand section');
      } else {
        exp.classList.add('rl-easa-tree-exp--rule-disclosure');
        exp.setAttribute('aria-label', 'Show AMC and GM under this rule');
      }
    }
    if (isSupplement) {
      li.classList.add('rl-easa-tree-li-supplement');
    }

    var dot = document.createElement('span');
    if (uiKind === 'section') {
      dot.setAttribute('aria-hidden', 'true');
      dot.style.display = 'none';
    } else {
      dot.className = rlEasaTreeMaterialDotClass(mt);
      dot.setAttribute('aria-hidden', 'true');
    }

    var sectionBtn = null;
    var ruleBtn = null;
    if (uiKind === 'section') {
      sectionBtn = document.createElement('button');
      sectionBtn.type = 'button';
      sectionBtn.className = 'rl-easa-tree-section-title';
      if (isSupplement) {
        sectionBtn.classList.add('rl-easa-tree-section-title--gm-amc');
      }
      sectionBtn.textContent = disp;
      if (!showTreeExpand && !sectionTitleOpensDetail) sectionBtn.disabled = true;
    } else {
      ruleBtn = document.createElement('button');
      ruleBtn.type = 'button';
      ruleBtn.className = 'rl-easa-tree-rule-title';
      if (mt === 'AMC' || mt === 'GM') ruleBtn.classList.add('rl-easa-tree-rule-supplement');
      ruleBtn.textContent = disp;
    }

    row.appendChild(exp);
    if (uiKind === 'section') {
      row.appendChild(sectionBtn);
    } else {
      row.appendChild(dot);
      row.appendChild(ruleBtn);
    }
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

    if (showTreeExpand) {
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
          if (!exp.disabled) {
            exp.textContent = '\u25b6';
            exp.setAttribute('aria-expanded', 'false');
          }
          return;
        }
        if (sub.getAttribute('data-loaded') === '1' && sub.children.length > 0) {
          sub.hidden = false;
          if (!exp.disabled) {
            exp.textContent = '\u25bc';
            exp.setAttribute('aria-expanded', 'true');
          }
          return;
        }
        sub.innerHTML = '';
        exp.disabled = true;
        exp.style.visibility = 'visible';
        exp.removeAttribute('aria-hidden');
        rlEasaTreeFetchTreeChildrenJson(batchId, uid)
          .then(function (j) {
            return rlEasaTreeResolveLegalRootNodes(batchId, j);
          })
          .then(function (resolved) {
            exp.disabled = false;
            resolved.nodes.forEach(function (c) {
              sub.appendChild(rlEasaCreateTreeLi(batchId, c));
            });
            sub.setAttribute('data-loaded', '1');
            sub.hidden = false;
            exp.textContent = '\u25bc';
            exp.setAttribute('aria-expanded', 'true');
          })
          .catch(function (err) {
            exp.disabled = false;
            sub.textContent = err.message || 'Error';
          });
      };
      exp.addEventListener('click', toggleChildList);
      if (sectionTitleTogglesExpand && sectionBtn && !sectionBtn.disabled) {
        sectionBtn.addEventListener('click', toggleChildList);
      }
    }
    if (ruleBtn && opensRule) {
      ruleBtn.addEventListener('click', function () {
        rlEasaShowNodeDetail(batchId, uid, li);
      });
    }
    if (sectionTitleOpensDetail && sectionBtn && !sectionBtn.disabled) {
      sectionBtn.addEventListener('click', function () {
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
      rlEasaTreeFetchTreeChildrenJson(bid, '')
        .then(function (j) {
          return rlEasaTreeResolveLegalRootNodes(bid, j);
        })
        .then(function (resolved) {
          rlEasaRenderTreeIntoMount(rlEasaTreeMount, bid, resolved.nodes);
          if (rlEasaTreeHint) {
            var n = resolved.nodes.length;
            rlEasaTreeHint.textContent = 'Batch #' + bid + ' · ' + n + ' root entr' + (n === 1 ? 'y' : 'ies')
              + '. ▶ only when expandable=true; click rule titles or section rows without ▶ to read inline.';
          }
        })
        .catch(function (e) {
          rlEasaTreeMount.innerHTML = '<p class="rl-drop-meta" style="color:#991b1b;margin:0;">' + esc(e.message || 'Error') + '</p>';
        });
    });
  }

  function rlEasaAppendCitationCard(host, s, queryForHighlight) {
    if (!host) return;
    var card = document.createElement('div');
    card.className = 'rl-easa-cite-card';
    var bid = parseInt(s.batch_id, 10) || 0;
    var nuid = (s.node_uid || '').trim();
    var eid = (s.source_erules_id || '').trim();
    var title = (s.title || '').trim() || eid || nuid || 'Regulation node';
    card.innerHTML = '<h4>' + esc(title) + '</h4>'
      + '<div class="rl-easa-cite-meta">batch_id <strong>' + esc(String(bid || '—')) + '</strong>'
      + (eid ? ' · ERulesId <strong>' + esc(eid) + '</strong>' : '')
      + (nuid ? ' · node_uid <code>' + esc(nuid) + '</code>' : '')
      + '</div>'
      + '<div class="rl-easa-cite-excerpt">Loading official text…</div>'
      + '<div class="rl-easa-cite-actions"><button type="button" class="btn btn-sm rl-easa-cite-open">Open in rule tree</button></div>';
    host.appendChild(card);
    var exEl = card.querySelector('.rl-easa-cite-excerpt');
    var openBtn = card.querySelector('.rl-easa-cite-open');
    if (openBtn && bid && nuid) {
      openBtn.addEventListener('click', function () {
        var treeSec = document.getElementById('rlEasaTreeSection');
        if (treeSec && treeSec.scrollIntoView) treeSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        rlEasaRevealTreeNode(bid, nuid, queryForHighlight || '').catch(function () {});
      });
    } else if (openBtn) {
      openBtn.disabled = true;
    }
    if (bid && nuid && exEl) {
      rlEasaFetchNodeDetail(bid, nuid).then(function (j) {
        if (!j.ok || !j.node) {
          exEl.textContent = (j && j.error) ? j.error : 'Could not load node.';
          return;
        }
        var br = (j.node.body_reading || j.node.plain_text_display || j.node.plain_text || '').trim();
        if (br.length > 900) br = br.slice(0, 897) + '…';
        exEl.textContent = br || '[No body text on this row]';
        var bc = (j.node.breadcrumb || '').trim();
        if (bc) {
          var meta = card.querySelector('.rl-easa-cite-meta');
          if (meta) meta.innerHTML += '<br>breadcrumb: ' + esc(bc);
        }
        if (queryForHighlight) rlEasaHighlightInTextNodes(exEl, queryForHighlight);
      }).catch(function (e) {
        exEl.textContent = e.message || 'Load failed';
      });
    } else if (exEl) {
      exEl.textContent = 'Missing batch/node reference — citations must come from staging matches only.';
    }
  }

  var chatAskBtn = document.getElementById('rlEasaChatAskBtn');
  var chatAnswerEl = document.getElementById('rlEasaChatAnswer');
  var chatCitEl = document.getElementById('rlEasaChatCitations');
  var chatCtxNote = document.getElementById('rlEasaChatContextNote');
  if (chatAskBtn) {
    chatAskBtn.addEventListener('click', function () {
      var qEl = document.getElementById('rlEasaChatQ');
      var q = qEl ? (qEl.value || '').trim() : '';
      if (!q) {
        if (chatAnswerEl) {
          chatAnswerEl.textContent = 'Enter a question first.';
          chatAnswerEl.classList.add('is-empty');
        }
        return;
      }
      var titleEl = document.getElementById('rlEasaEcfrTitle');
      var secEl = document.getElementById('rlEasaEcfrSec');
      var incEcfr = document.getElementById('rlEasaChatCompareEcfr');
      var useAi = document.getElementById('rlEasaChatUseAi');
      var srcEl = document.getElementById('rlEasaChatSource');
      var srcBid = srcEl && srcEl.value ? parseInt(srcEl.value, 10) : 0;
      var payload = {
        action: 'regulatory_compare_ai',
        query: q,
        use_ai: !!(useAi && useAi.checked),
        include_ecfr: !!(incEcfr && incEcfr.checked),
        ecfr_title_number: titleEl ? parseInt(titleEl.value, 10) || 14 : 14,
        ecfr_section: secEl ? (secEl.value || '').trim() : ''
      };
      if (srcBid > 0) payload.batch_id = srcBid;
      if (chatCitEl) chatCitEl.innerHTML = '';
      if (chatCtxNote) {
        chatCtxNote.style.display = 'none';
        chatCtxNote.textContent = '';
      }
      if (chatAnswerEl) {
        chatAnswerEl.classList.remove('is-empty');
        chatAnswerEl.innerHTML = rlEasaRenderChatWindowHtml(q, 'Working…', '');
      }
      chatAskBtn.disabled = true;
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          if (!x.ok || !x.j || !x.j.ok) throw new Error((x.j && x.j.error) || 'Request failed');
          var citeQuery = '';
          var srcs = Array.isArray(x.j.easa_sources) ? x.j.easa_sources : [];
          if (srcs.length && q.length >= 3 && q.length < 160) citeQuery = q;
          if (chatCitEl) {
            srcs.slice(0, 16).forEach(function (s) {
              rlEasaAppendCitationCard(chatCitEl, s, citeQuery);
            });
          }
          var noteParts = [];
          if (x.j.easa_context_note) noteParts.push(x.j.easa_context_note);
          if (x.j.easa_staging_hits != null) noteParts.push('Staging rows fed to bundle: ' + x.j.easa_staging_hits + '.');
          if (x.j.ecfr_note) noteParts.push('eCFR: ' + x.j.ecfr_note);
          if (chatCtxNote && noteParts.length) {
            chatCtxNote.textContent = noteParts.join(' ');
            chatCtxNote.style.display = 'block';
          }
          var aiBlock = '';
          var userFirst = x.j.user_first_name || '';
          if (x.j.ai_error) aiBlock = 'AI error: ' + x.j.ai_error;
          else if (x.j.ai_answer) aiBlock = x.j.ai_answer;
          else aiBlock = (useAi && useAi.checked)
            ? '(No AI text returned.)'
            : 'AI skipped — inspect official reference cards and context line above.';
          if (chatAnswerEl) {
            chatAnswerEl.innerHTML = rlEasaRenderChatWindowHtml(q, aiBlock, userFirst);
          }
        })
        .catch(function (e) {
          if (chatAnswerEl) {
            chatAnswerEl.innerHTML = rlEasaRenderChatWindowHtml(q, e.message || 'Error', '');
          }
        })
        .finally(function () { chatAskBtn.disabled = false; });
    });
  }

  loadStatus();
})();
</script>
